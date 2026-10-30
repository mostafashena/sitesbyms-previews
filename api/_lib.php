<?php
// Shared helpers for the Sites By MS server scripts. Not reachable from the web (.htaccess blocks files starting with "_").
declare(strict_types=1);

const TEAM_TO   = 'hello@sitesbyms.com, m.and.s.design.develop@gmail.com';
const FROM_ADDR = 'hello@sitesbyms.com';
const TZ        = 'Europe/London';

function data_dir(): string {
    $home = getenv('HOME') ?: dirname(__DIR__, 2);
    $d = $home . '/sitesbyms_data';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function read_json(string $name, $default = []) {
    $f = data_dir() . '/' . $name;
    if (!is_file($f)) return $default;
    $v = json_decode((string)file_get_contents($f), true);
    return $v === null ? $default : $v;
}
function write_json(string $name, $value): void {
    $f = data_dir() . '/' . $name;
    file_put_contents($f . '.tmp', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($f . '.tmp', $f);
}
function read_lines(string $name): array {
    $f = data_dir() . '/' . $name;
    if (!is_file($f)) return [];
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $j = json_decode($l, true); if (is_array($j)) $out[] = $j; }
    return $out;
}
function append_line(string $name, array $row): void {
    file_put_contents(data_dir() . '/' . $name, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** Settings edited in the admin panel. */
function config(): array {
    return array_merge([
        'ical_url'   => '',            // Google Calendar "secret address in iCal format"
        'days_ahead' => 2,             // first bookable day = today + this
        'days_shown' => 14,
        'start'      => '10:00',
        'end'        => '17:00',
        'step'       => 30,            // minutes between slot starts
        'length'     => 15,            // call length in minutes
        'weekdays'   => [1, 2, 3, 4, 5],
        'meet_link'  => '',            // optional fixed Google Meet link sent in invites
    ], (array)setting_get('config', read_json('config.json', [])));
}

function one_line(string $v): string { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }

function send_mail(string $to, string $subject, string $text, string $replyTo = FROM_ADDR, array $attachments = []): bool {
    $headers = 'From: Sites By MS <' . FROM_ADDR . ">\r\nReply-To: " . one_line($replyTo) . "\r\nMIME-Version: 1.0\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (!$attachments) {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        return @mail($to, $subj, $text, $headers, '-f' . FROM_ADDR);
    }
    $b = 'sbm' . bin2hex(random_bytes(8));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"";
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n";
    foreach ($attachments as $a) {
        $type = $a['type'] ?? 'application/octet-stream';
        $msg .= "--$b\r\nContent-Type: $type; name=\"{$a['name']}\"\r\nContent-Transfer-Encoding: base64\r\n"
              . "Content-Disposition: attachment; filename=\"{$a['name']}\"\r\n\r\n" . chunk_split(base64_encode($a['data']));
    }
    $msg .= "--$b--";
    return @mail($to, $subj, $msg, $headers, '-f' . FROM_ADDR);
}

/* ---------- calendar ---------- */

/** Busy periods [startTs, endTs] from saved bookings and the team's Google Calendar feed. */
function busy_periods(): array {
    $busy = [];
    foreach (bookings_all() as $b) {
        if (($b['status'] ?? 'booked') === 'cancelled') continue;
        $s = (int)$b['start_ts']; $busy[] = [$s, $s + 60 * (int)($b['length'] ?? 15)];
    }
    return array_merge($busy, ical_busy());
}

/** Reads the iCal feed (cached 10 minutes). Handles UTC, TZID and all-day events; recurring events count on their first date only. */
function ical_busy(): array {
    $url = config()['ical_url'];
    if (!$url || !preg_match('#^https://#', $url)) return [];
    $cache = data_dir() . '/ical_cache.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) return (array)json_decode((string)file_get_contents($cache), true);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: sitesbyms\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return is_file($cache) ? (array)json_decode((string)file_get_contents($cache), true) : [];
    $raw = preg_replace("/\r?\n[ \t]/", '', $raw);           // unfold lines
    $out = [];
    $from = time() - 86400; $to = time() + 60 * 86400;
    foreach (explode('BEGIN:VEVENT', $raw) as $i => $ev) {
        if ($i === 0) continue;
        if (preg_match('/^TRANSP:TRANSPARENT/m', $ev)) continue;  // "free" events
        if (preg_match('/^STATUS:CANCELLED/m', $ev)) continue;
        $s = ical_time($ev, 'DTSTART'); $e = ical_time($ev, 'DTEND');
        if (!$s) continue;
        if (!$e) $e = $s + 3600;
        if ($e < $from || $s > $to) continue;
        $out[] = [$s, $e];
    }
    file_put_contents($cache, json_encode($out));
    return $out;
}
function ical_time(string $ev, string $prop): ?int {
    if (!preg_match('/^' . $prop . '([^:\r\n]*):([0-9T]+Z?)/m', $ev, $m)) return null;
    $params = $m[1]; $v = $m[2];
    try {
        if (strlen($v) === 8) { $d = new DateTime($v, new DateTimeZone(TZ)); return $d->getTimestamp(); }   // all-day
        if (substr($v, -1) === 'Z') return (new DateTime($v, new DateTimeZone('UTC')))->getTimestamp();
        $tz = TZ;
        if (preg_match('/TZID=([^;:]+)/', $params, $t)) $tz = $t[1];
        try { $zone = new DateTimeZone($tz); } catch (Exception $x) { $zone = new DateTimeZone(TZ); }
        return (new DateTime($v, $zone))->getTimestamp();
    } catch (Exception $x) { return null; }
}

/** Days with slots; each slot is free or busy. */
function build_slots(): array {
    $c = config(); $tz = new DateTimeZone(TZ); $busy = busy_periods();
    $len = 60 * (int)$c['length'];
    $day = new DateTime('today', $tz); $day->modify('+' . (int)$c['days_ahead'] . ' days');
    $days = [];
    for ($i = 0; $i < (int)$c['days_shown'] + 10 && count($days) < 10; $i++) {
        $d = clone $day; $d->modify("+$i days");
        if (!in_array((int)$d->format('N'), $c['weekdays'], true)) continue;
        [$sh, $sm] = array_map('intval', explode(':', $c['start'])); [$eh, $em] = array_map('intval', explode(':', $c['end']));
        $t = (clone $d)->setTime($sh, $sm); $end = (clone $d)->setTime($eh, $em);
        $slots = [];
        while ($t < $end) {
            $s = $t->getTimestamp(); $free = true;
            foreach ($busy as $p) { if ($s < $p[1] && $s + $len > $p[0]) { $free = false; break; } }
            $slots[] = ['t' => $t->format('H:i'), 'ts' => $s, 'free' => $free];
            $t->modify('+' . (int)$c['step'] . ' minutes');
        }
        $days[] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D j M'), 'slots' => $slots];
    }
    return $days;
}

function ics_invite(array $b, int $seq = 0): string {
    $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    $esc = fn(string $s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
    $desc = "15-minute call about your website with Sites By MS.\n"
          . ($b['meet'] ? "Join: {$b['meet']}\n" : "We'll call you by {$b['how']}.\n")
          . ($b['slug'] ? "Your concept: https://sitesbyms.com/{$b['slug']}/\n" : '')
          . "Need to change it? Reply to this email or WhatsApp +44 7405 760048.";
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sites By MS//Booking//EN', 'METHOD:REQUEST', 'BEGIN:VEVENT',
        'UID:' . $b['id'] . '@sitesbyms.com', 'SEQUENCE:' . $seq, 'DTSTAMP:' . $fmt(time()), 'DTSTART:' . $fmt($b['start_ts']), 'DTEND:' . $fmt($b['start_ts'] + 60 * $b['length']),
        'SUMMARY:' . $esc('Website call: ' . $b['business'] . ' × Sites By MS'),
        'DESCRIPTION:' . $esc($desc), 'LOCATION:' . $esc($b['meet'] ?: $b['how']),
        'ORGANIZER;CN=Sites By MS:mailto:' . FROM_ADDR,
        'ATTENDEE;CN=' . $esc($b['name']) . ';RSVP=TRUE:mailto:' . $b['email'],
        'STATUS:CONFIRMED', 'BEGIN:VALARM', 'TRIGGER:-PT30M', 'ACTION:DISPLAY', 'DESCRIPTION:Call with Sites By MS', 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR', '']);
}

function rate_limit(string $bucket, int $max, int $window): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $f = data_dir() . '/rate_' . $bucket . '_' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? array_values(array_filter((array)json_decode((string)file_get_contents($f), true), fn($t) => $t > $now - $window)) : [];
    if (count($hits) >= $max) return false;
    $hits[] = $now; file_put_contents($f, json_encode($hits));
    return true;
}

/* =====================================================================
   Storage: MySQL when connected (Settings › Database), files until then.
   ===================================================================== */

function db_conf(): array { return read_json('db.json', []); }

function db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $c = db_conf();
    if (empty($c['name'])) return $pdo = null;
    try { $pdo = db_connect($c); } catch (Throwable $e) { $pdo = null; $GLOBALS['db_error'] = $e->getMessage(); }
    return $pdo;
}
function db_connect(array $c): PDO {
    $dsn = 'mysql:host=' . ($c['host'] ?: 'localhost') . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

function db_migrate(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member', password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL, last_login DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS bookings (
        id VARCHAR(40) PRIMARY KEY, start_ts INT NOT NULL, length INT NOT NULL DEFAULT 15,
        name VARCHAR(120) NOT NULL, business VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(60) NOT NULL DEFAULT '',
        how VARCHAR(40) NOT NULL, slug VARCHAR(80) NOT NULL DEFAULT '', meet VARCHAR(255) NOT NULL DEFAULT '',
        meet_sent_at DATETIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'booked', created_at DATETIME NOT NULL,
        INDEX (start_ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL, business VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL, phone VARCHAR(60) NOT NULL DEFAULT '', slot VARCHAR(120) NOT NULL DEFAULT '', how VARCHAR(40) NOT NULL DEFAULT '',
        reply VARCHAR(40) NOT NULL DEFAULT '', site VARCHAR(255) NOT NULL DEFAULT '', slug VARCHAR(80) NOT NULL DEFAULT '',
        message TEXT NULL, file VARCHAR(200) NULL, mailed TINYINT NOT NULL DEFAULT 0, handled_at DATETIME NULL, created_at DATETIME NOT NULL,
        INDEX (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS concept_views (
        id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, device VARCHAR(20) NOT NULL, ref VARCHAR(255) NOT NULL DEFAULT '',
        ip_hash VARCHAR(20) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (slug), INDEX (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $p->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(60) PRIMARY KEY, v MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dt(?string $iso): string { return gmdate('Y-m-d H:i:s', $iso ? strtotime($iso) : time()); }
function iso(?string $dt): ?string { return $dt ? gmdate('c', strtotime($dt . ' UTC')) : null; }

/** Copies the file-based records into MySQL once. Returns counts. */
function db_import_files(PDO $p): array {
    $n = ['bookings' => 0, 'messages' => 0, 'views' => 0];
    $handled = read_json('handled.json', []);
    foreach (read_lines('bookings.jsonl') as $b) {
        $q = $p->prepare('INSERT IGNORE INTO bookings (id,start_ts,length,name,business,email,phone,how,slug,meet,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$b['id'], $b['start_ts'], $b['length'] ?? 15, $b['name'], $b['business'], $b['email'], $b['phone'] ?? '', $b['how'], $b['slug'] ?? '', $b['meet'] ?? '', $b['status'] ?? 'booked', dt($b['created'] ?? null)]);
        $n['bookings'] += $q->rowCount();
    }
    foreach (read_lines('submissions.jsonl') as $m) {
        $k = $m['at'] . $m['email'];
        $q = $p->prepare('INSERT INTO messages (type,name,business,email,phone,slot,how,reply,site,slug,message,file,mailed,handled_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$m['type'], $m['name'], $m['business'], $m['email'], $m['phone'] ?? '', $m['slot'] ?? '', $m['how'] ?? '', $m['reply'] ?? '', $m['site'] ?? '', $m['slug'] ?? '', $m['message'] ?? '', $m['file'] ?? null, !empty($m['mailed']) ? 1 : 0, isset($handled[$k]) ? gmdate('Y-m-d H:i:s', $handled[$k]) : null, dt($m['at'])]);
        $n['messages']++;
    }
    foreach (read_lines('views.jsonl') as $v) {
        $p->prepare('INSERT INTO concept_views (slug,device,ref,ip_hash,created_at) VALUES (?,?,?,?,?)')->execute([$v['slug'], $v['device'], $v['ref'] ?? '', $v['ip'] ?? '', dt($v['at'])]);
        $n['views']++;
    }
    foreach (['bookings.jsonl', 'submissions.jsonl', 'views.jsonl'] as $f) { $path = data_dir() . '/' . $f; if (is_file($path)) rename($path, $path . '.imported-' . date('Ymd-His')); }
    $cfg = read_json('config.json', null);
    if ($cfg) setting_set('config', $cfg);
    return $n;
}

/* ---------- settings ---------- */
function setting_get(string $k, $default = null) {
    if ($p = db()) { $q = $p->prepare('SELECT v FROM settings WHERE k=?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $default : json_decode($v, true); }
    return read_json('setting_' . $k . '.json', $default);
}
function setting_set(string $k, $v): void {
    if ($p = db()) { $p->prepare('REPLACE INTO settings (k,v) VALUES (?,?)')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]); return; }
    if ($k === 'config') { write_json('config.json', $v); return; }
    write_json('setting_' . $k . '.json', $v);
}

/* ---------- bookings ---------- */
function bookings_all(): array {
    if ($p = db()) return array_map(function ($r) { $r['start_ts'] = (int)$r['start_ts']; $r['length'] = (int)$r['length']; $r['created'] = iso($r['created_at']); return $r; }, $p->query('SELECT * FROM bookings ORDER BY start_ts')->fetchAll());
    $rows = read_lines('bookings.jsonl'); usort($rows, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']); return $rows;
}
function booking_get(string $id): ?array { foreach (bookings_all() as $b) if ($b['id'] === $id) return $b; return null; }
function booking_add(array $b): void {
    if ($p = db()) {
        $p->prepare('INSERT INTO bookings (id,start_ts,length,name,business,email,phone,how,slug,meet,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$b['id'], $b['start_ts'], $b['length'], $b['name'], $b['business'], $b['email'], $b['phone'], $b['how'], $b['slug'], $b['meet'], $b['status'], dt($b['created'])]);
        return;
    }
    append_line('bookings.jsonl', $b);
}
function booking_update(string $id, array $f): void {
    if ($p = db()) {
        $set = []; $vals = [];
        foreach ($f as $k => $v) { if (!in_array($k, ['meet', 'status', 'meet_sent_at'], true)) continue; $set[] = "$k=?"; $vals[] = $v; }
        if (!$set) return; $vals[] = $id;
        $p->prepare('UPDATE bookings SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals); return;
    }
    $out = '';
    foreach (read_lines('bookings.jsonl') as $r) { if ($r['id'] === $id) $r = array_merge($r, $f); $out .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
    file_put_contents(data_dir() . '/bookings.jsonl', $out, LOCK_EX);
}

/* ---------- messages ---------- */
function messages_all(): array {
    if ($p = db()) return array_map(function ($r) { $r['at'] = iso($r['created_at']); $r['handled'] = $r['handled_at'] !== null; return $r; }, $p->query('SELECT * FROM messages ORDER BY created_at DESC, id DESC')->fetchAll());
    $handled = read_json('handled.json', []);
    return array_map(function ($m) use ($handled) { $m['id'] = $m['at'] . $m['email']; $m['handled'] = isset($handled[$m['id']]); return $m; }, array_reverse(read_lines('submissions.jsonl')));
}
function message_add(array $m): void {
    if ($p = db()) {
        $p->prepare('INSERT INTO messages (type,name,business,email,phone,slot,how,reply,site,slug,message,file,mailed,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$m['type'], $m['name'], $m['business'], $m['email'], $m['phone'], $m['slot'], $m['how'], $m['reply'], $m['site'], $m['slug'], $m['message'], $m['file'], $m['mailed'] ? 1 : 0, dt($m['at'])]);
        return;
    }
    append_line('submissions.jsonl', $m);
}
function message_handled(string $id, bool $done): void {
    if ($p = db()) { $p->prepare('UPDATE messages SET handled_at=' . ($done ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id=?')->execute([(int)$id]); return; }
    $hd = read_json('handled.json', []); if ($done) $hd[$id] = time(); else unset($hd[$id]); write_json('handled.json', $hd);
}

/* ---------- concept views ---------- */
function views_all(): array {
    if ($p = db()) return array_map(fn($r) => ['slug' => $r['slug'], 'device' => $r['device'], 'ref' => $r['ref'], 'at' => iso($r['created_at'])], $p->query('SELECT * FROM concept_views ORDER BY created_at DESC, id DESC LIMIT 2000')->fetchAll());
    return array_reverse(read_lines('views.jsonl'));
}
function view_add(array $v): void {
    if ($p = db()) { $p->prepare('INSERT INTO concept_views (slug,device,ref,ip_hash,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$v['slug'], $v['device'], $v['ref'], $v['ip']]); return; }
    append_line('views.jsonl', $v);
}

/* ---------- team devices (their visits are not counted as client views) ---------- */
function ip_key(): string { return substr(hash('sha256', 'team|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16); }
/** Called on every panel page: remembers this browser (cookie) and this network (hashed IP, 30 days). */
function team_mark(): void {
    if (($_COOKIE['sbm_team'] ?? '') !== '1') setcookie('sbm_team', '1', ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    $ips = read_json('team_ips.json', []); $k = ip_key();
    if (time() - (int)($ips[$k] ?? 0) > 3600) { $ips[$k] = time(); $ips = array_filter($ips, fn($t) => $t > time() - 30 * 86400); write_json('team_ips.json', $ips); }
}
function is_team_visit(): bool {
    if (($_COOKIE['sbm_team'] ?? '') === '1') return true;
    $ips = read_json('team_ips.json', []); return (int)($ips[ip_key()] ?? 0) > time() - 30 * 86400;
}

/* ---------- users (need MySQL) ---------- */
function users_all(): array { return ($p = db()) ? $p->query('SELECT id,email,name,role,created_at,last_login FROM users ORDER BY id')->fetchAll() : []; }
function user_by_email(string $e): ?array { if (!($p = db())) return null; $q = $p->prepare('SELECT * FROM users WHERE email=?'); $q->execute([strtolower(trim($e))]); return $q->fetch() ?: null; }
function user_by_id(int $id): ?array { if (!($p = db())) return null; $q = $p->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch() ?: null; }
function user_add(string $email, string $name, string $role, string $password): void {
    db()->prepare('INSERT INTO users (email,name,role,password_hash,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([strtolower(trim($email)), $name, $role, password_hash($password, PASSWORD_DEFAULT)]);
}

/* ---------- stats copy for Claude's reports: emailed to the team inbox at most every 3 hours, when the panel is opened ---------- */
function stats_mail_maybe(): void {
    $st = read_json('stats_mail.json', []);
    if (time() - (int)($st['at'] ?? 0) < 3 * 3600) return;
    write_json('stats_mail.json', ['at' => time()]);
    $rows = [];
    foreach (views_all() as $v) $rows[] = [$v['at'], $v['slug'], $v['device'], (string)$v['ref']];
    $csv = implode("\n", array_map(fn($r) => implode(',', $r), $rows));
    send_mail('m.and.s.design.develop@gmail.com', 'SBM stats ' . gmdate('Y-m-d H:i'), "Concept view data for the reports (" . count($rows) . " views, team visits excluded). No action needed.\n\nat,slug,device,ref\n" . $csv . "\n");
}
