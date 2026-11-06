<?php
// Leads, stored in the site's MySQL database (table "leads"), with a full change history (table "leads_log")
// and a daily backup. Until the one-time import has run, the old Google Sheet is still used.
// The functions keep their old names (sheet_rows, sheet_update_row, ...) so the rest of the panel did not have to change.
// Not reachable from the web (.htaccess blocks /api/_*).
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

const LEAD_FIELDS = ['row', 'company', 'country', 'location', 'site', 'email', 'phone', 'instagram', 'owner', 'notes', 'status', 'language', 'issues', 'preview',
                     'channel', 'subject', 'message', 'followup', 'sent', 'followup_date', 'reply', 'slug', 'stage', 'payment', 'ig_message', 'ig_followup'];

/** 'db' once the leads were moved into the database, otherwise 'sheet'. */
function leads_store(): string { return $GLOBALS['sbm_store'] ??= (setting_get('leads_store', 'sheet') === 'db' ? 'db' : 'sheet'); }
/** True when the panel can read leads (database, or the old sheet with its Google key). */
function leads_ready(): bool { return leads_store() === 'db' || (function_exists('google_key') && google_key() !== null); }
function leads_actor(): string { return (string)($GLOBALS['sbm_actor'] ?? 'system'); }

function leads_migrate(PDO $p): void {
    static $done = false; if ($done) return; $done = true;
    $cols = implode(', ', array_map(fn($k) => "`$k` " . (in_array($k, ['message', 'followup', 'issues', 'notes', 'ig_message', 'ig_followup', 'stage'], true) ? 'TEXT' : 'VARCHAR(500)') . " NOT NULL DEFAULT ''", LEAD_FIELDS));
    $p->exec("CREATE TABLE IF NOT EXISTS leads (row_no INT PRIMARY KEY, $cols, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
              INDEX (status), INDEX (slug), INDEX (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS leads_log (id INT AUTO_INCREMENT PRIMARY KEY, row_no INT NOT NULL, field VARCHAR(40) NOT NULL,
              old_val TEXT NULL, new_val TEXT NULL, by_who VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (row_no), INDEX (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Every lead, oldest row first: ['_row' => n, 'company' => ..., ...]. */
function leads_all(): array {
    if ($p = db()) {
        leads_migrate($p);
        $out = [];
        foreach ($p->query('SELECT * FROM leads ORDER BY row_no')->fetchAll() as $r) {
            $x = ['_row' => (int)$r['row_no']]; foreach (LEAD_FIELDS as $k) $x[$k] = (string)$r[$k]; $out[] = $x;
        }
        return $out;
    }
    $f = read_json('leads.json', ['rows' => []]); $out = [];
    foreach ($f['rows'] as $n => $r) { $x = ['_row' => (int)$n]; foreach (LEAD_FIELDS as $k) $x[$k] = (string)($r[$k] ?? ''); $out[] = $x; }
    usort($out, fn($a, $b) => $a['_row'] <=> $b['_row']);
    return $out;
}
function lead_by_row(int $row): ?array { foreach (leads_all() as $r) if ($r['_row'] === $row) return $r; return null; }

/** Writes fields of one lead and records every change in the history. */
function leads_update(int $row, array $cells, ?string $by = null): void {
    $by ??= leads_actor();
    $cells = array_intersect_key(array_map(fn($v) => (string)$v, $cells), array_flip(LEAD_FIELDS));
    if (!$cells) return;
    $old = lead_by_row($row); if (!$old) throw new RuntimeException("Lead row $row does not exist.");
    $changed = array_filter($cells, fn($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH);
    if (!$changed) return;
    if ($p = db()) {
        $p->beginTransaction();
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($changed)));
        $p->prepare("UPDATE leads SET $set, updated_at=UTC_TIMESTAMP() WHERE row_no=?")->execute(array_merge(array_values($changed), [$row]));
        $lg = $p->prepare('INSERT INTO leads_log (row_no,field,old_val,new_val,by_who,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
        foreach ($changed as $k => $v) $lg->execute([$row, $k, $old[$k], $v, $by]);
        $p->commit();
        return;
    }
    $f = read_json('leads.json', ['rows' => []]);
    foreach ($changed as $k => $v) { $f['rows'][$row][$k] = $v; append_line('leads_log.jsonl', ['row' => $row, 'field' => $k, 'old' => $old[$k], 'new' => $v, 'by' => $by, 'at' => gmdate('c')]); }
    write_json('leads.json', $f);
}

/** Adds a lead at the end. Returns its row number. */
function leads_append(array $cells, ?string $by = null): int {
    $by ??= leads_actor();
    $all = leads_all(); $row = 2; $num = 1;
    foreach ($all as $r) { $row = max($row, $r['_row'] + 1); $num = max($num, (int)$r['row'] + 1); }
    $x = []; foreach (LEAD_FIELDS as $k) $x[$k] = (string)($cells[$k] ?? '');
    $x['row'] = (string)$num;
    if (trim($x['company']) === '') throw new RuntimeException('A lead needs a business name.');
    leads_insert($row, $x);
    leads_log_line($row, 'created', '', $x['company'], $by);
    return $row;
}
function leads_insert(int $row, array $x): void {
    if ($p = db()) {
        leads_migrate($p);
        $keys = LEAD_FIELDS;
        $p->prepare('REPLACE INTO leads (row_no, `' . implode('`, `', $keys) . '`, created_at, updated_at) VALUES (?' . str_repeat(', ?', count($keys)) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')
          ->execute(array_merge([$row], array_map(fn($k) => (string)($x[$k] ?? ''), $keys)));
        return;
    }
    $f = read_json('leads.json', ['rows' => []]); $f['rows'][$row] = array_intersect_key($x, array_flip(LEAD_FIELDS)); write_json('leads.json', $f);
}
function leads_log_line(int $row, string $field, string $old, string $new, string $by): void {
    if ($p = db()) { leads_migrate($p); $p->prepare('INSERT INTO leads_log (row_no,field,old_val,new_val,by_who,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())')->execute([$row, $field, $old, $new, $by]); return; }
    append_line('leads_log.jsonl', ['row' => $row, 'field' => $field, 'old' => $old, 'new' => $new, 'by' => $by, 'at' => gmdate('c')]);
}
/** Change history, newest first (optionally for one row). */
function leads_history(?int $row = null, int $limit = 200): array {
    if ($p = db()) {
        leads_migrate($p);
        $q = $p->prepare('SELECT * FROM leads_log' . ($row ? ' WHERE row_no=?' : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit); $q->execute($row ? [$row] : []);
        return array_map(fn($r) => ['row' => (int)$r['row_no'], 'field' => $r['field'], 'old' => $r['old_val'], 'new' => $r['new_val'], 'by' => $r['by_who'], 'at' => iso($r['created_at'])], $q->fetchAll());
    }
    $l = array_reverse(read_lines('leads_log.jsonl'));
    if ($row) $l = array_values(array_filter($l, fn($x) => (int)$x['row'] === $row));
    return array_slice($l, 0, $limit);
}

/* ---------- the old function names, now routed to the database ---------- */
function sheet_rows(bool $fresh = false): array {
    if (leads_store() !== 'db') return gsheet_rows($fresh);
    static $cache = null; if ($cache !== null && !$fresh) return $cache;
    return $cache = array_values(array_filter(leads_all(), fn($r) => $r['company'] !== ''));
}
function sheet_update_row(int $row, array $cells): void {
    if (leads_store() !== 'db') { gsheet_update_row($row, $cells); return; }
    leads_update($row, $cells);
}
function sheet_append_lead(array $cells): int { return leads_store() === 'db' ? leads_append($cells) : gsheet_append_lead($cells); }

const PANEL_DEFAULTS = ['New concepts per night' => '5', 'If nothing is Ready, build the best new candidates' => 'Yes', 'New candidates per night' => '5'];
function panel_settings(): array { return leads_store() === 'db' ? (array)setting_get('panel', []) + PANEL_DEFAULTS : gpanel_settings(); }
function panel_settings_save(array $kv): void {
    if (leads_store() !== 'db') { gpanel_settings_save($kv); return; }
    $old = (array)setting_get('panel', []);
    setting_set('panel', $kv);
    foreach ($kv as $k => $v) if ((string)($old[$k] ?? '') !== (string)$v) leads_log_line(0, 'setting: ' . $k, (string)($old[$k] ?? ''), (string)$v, leads_actor());
}

/* ---------- one-time move from the Google Sheet ---------- */
/** Copies every row and the Panel settings from the sheet into the database (keeping the row numbers), checks the count, then switches over. */
function leads_import_from_sheet(): string {
    if (leads_store() === 'db') return 'already in the database';
    $rows = gsheet_rows(true); $settings = gpanel_settings();
    if (count($rows) < 1) throw new RuntimeException('The sheet returned no rows; nothing was moved.');
    leads_backup_file($rows, 'before-import');
    foreach ($rows as $r) { $x = []; foreach (LEAD_FIELDS as $k) $x[$k] = (string)($r[$k] ?? ''); leads_insert((int)$r['_row'], $x); }
    $n = count(array_filter(leads_all(), fn($r) => $r['company'] !== ''));
    if ($n !== count($rows)) throw new RuntimeException("Only $n of " . count($rows) . ' rows arrived in the database. Still using the sheet.');
    setting_set('panel', $settings);
    leads_log_line(0, 'import', '', "$n leads moved from the Google Sheet", 'system');
    setting_set('leads_store', 'db');
    $GLOBALS['sbm_store'] = 'db';
    return "$n leads moved into the database";
}

/* ---------- backups ---------- */
function leads_csv(array $rows): string {
    $h = fopen('php://temp', 'w+'); fputcsv($h, array_merge(['sheet_row'], LEAD_FIELDS));
    foreach ($rows as $r) { $line = [$r['_row']]; foreach (LEAD_FIELDS as $k) { $v = (string)($r[$k] ?? ''); $line[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; } fputcsv($h, $line); }
    rewind($h); $csv = stream_get_contents($h); fclose($h); return $csv;
}
function leads_backup_file(array $rows, string $tag): string {
    $dir = data_dir() . '/backups'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = $dir . '/leads-' . gmdate('Y-m-d') . ($tag ? "-$tag" : '') . '.json';
    file_put_contents($f, json_encode(['at' => gmdate('c'), 'rows' => $rows, 'panel' => setting_get('panel', [])], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    foreach (array_slice(glob($dir . '/leads-*.json') ?: [], 0, -45) as $old) @unlink($old);   // keep the newest 45
    return $f;
}
/** Once a day: a backup file on the server, and the whole list as a CSV emailed to the team (only when something changed). */
function leads_daily_backup(): string {
    if (leads_store() !== 'db') return 'not in the database yet';
    $today = gmdate('Y-m-d'); $st = read_json('leads_backup.json', []);
    if (($st['day'] ?? '') === $today) return 'backup already done today';
    $rows = leads_all(); leads_backup_file($rows, '');
    $csv = leads_csv($rows); $hash = md5($csv);
    $mailed = false;
    if (($st['hash'] ?? '') !== $hash) {
        $mailed = send_mail(TEAM_TO, '[Backup] Sites By MS leads · ' . $today . ' · ' . count($rows) . ' leads',
            "The full leads list as of $today (UTC), " . count($rows) . " leads.\n\nKeep these emails: any of them can restore the list. The panel also keeps a backup file on the server every day and logs every change (who, what, when).\n",
            FROM_ADDR, [['name' => "sitesbyms-leads-$today.csv", 'type' => 'text/csv', 'data' => $csv]]);
    }
    write_json('leads_backup.json', ['day' => $today, 'hash' => $mailed || ($st['hash'] ?? '') === $hash ? $hash : ($st['hash'] ?? ''), 'at' => time()]);
    return 'backup saved' . ($mailed ? ' and emailed' : '');
}
