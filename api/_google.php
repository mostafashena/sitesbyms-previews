<?php
// Google Sheets access for the team panel, using a service account key saved outside public_html.
// Not reachable from the web (.htaccess blocks /api/_*).
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

const SHEET_ID  = '1NZFcaeoG7fR8--nepcmujsFJAZzfb4r1sCh-_3Lbs-s';
const SHEET_TAB = 'Sheet1';
// Column letters in Sheet1.
const COL = ['row' => 'A', 'company' => 'B', 'country' => 'C', 'location' => 'D', 'site' => 'E', 'email' => 'F', 'phone' => 'G', 'instagram' => 'H',
             'owner' => 'I', 'notes' => 'J', 'status' => 'K', 'language' => 'L', 'issues' => 'M', 'preview' => 'N', 'channel' => 'O', 'subject' => 'P',
             'message' => 'Q', 'followup' => 'R', 'sent' => 'S', 'followup_date' => 'T', 'reply' => 'U', 'slug' => 'V',
             'stage' => 'W', 'payment' => 'X', 'ig_message' => 'Y', 'ig_followup' => 'Z'];

function google_key(): ?array { $k = read_json('google-sa.json', null); return (is_array($k) && !empty($k['private_key']) && !empty($k['client_email'])) ? $k : null; }

function google_api_base(): string { return getenv('SBM_SHEETS_BASE') ?: 'https://sheets.googleapis.com'; }   // overridable for local tests

function google_token(): string {
    $cache = read_json('google-token.json', []);
    if (!empty($cache['token']) && ($cache['exp'] ?? 0) > time() + 60) return $cache['token'];
    $k = google_key(); if (!$k) throw new RuntimeException('The Google key is not set up yet.');
    if (getenv('SBM_SHEETS_BASE')) return 'test-token';
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = $b64(json_encode(['iss' => $k['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign("$head.$claim", $sig, $k['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign with the Google key.');
    $res = http_json('POST', 'https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claim." . $b64($sig)], true);
    if (empty($res['access_token'])) throw new RuntimeException('Google refused the key: ' . ($res['error_description'] ?? $res['error'] ?? 'unknown error'));
    write_json('google-token.json', ['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3600)]);
    return $res['access_token'];
}

function http_json(string $method, string $url, $body = null, bool $form = false, array $headers = []): array {
    $ch = curl_init($url);
    $h = $headers;
    if ($body !== null) {
        if ($form) { $payload = http_build_query($body); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
        else { $payload = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h]);
    $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($out === false) throw new RuntimeException('Network error: ' . $err);
    $j = json_decode((string)$out, true) ?: [];
    if ($code >= 400 && !$form) throw new RuntimeException('Google Sheets said: ' . ($j['error']['message'] ?? "HTTP $code"));
    return $j;
}

function sheet_rows(): array {
    $t = google_token();
    $j = http_json('GET', google_api_base() . '/v4/spreadsheets/' . SHEET_ID . '/values/' . rawurlencode(SHEET_TAB . '!A1:Z1000') . '?valueRenderOption=FORMATTED_VALUE', null, false, ["Authorization: Bearer $t"]);
    $vals = $j['values'] ?? []; $rows = [];
    $keys = array_keys(COL);
    foreach ($vals as $i => $r) {
        if ($i === 0) continue;                       // header row
        $row = ['_row' => $i + 1];
        foreach ($keys as $n => $k) $row[$k] = trim((string)($r[$n] ?? ''));
        if ($row['company'] === '') continue;
        $rows[] = $row;
    }
    return $rows;
}

/** Writes cells of one sheet row. $cells: ['status' => 'Sent', ...]. Dates use USER_ENTERED so formulas keep working. */
function sheet_update_row(int $row, array $cells): void {
    $t = google_token(); $raw = []; $typed = [];
    foreach ($cells as $k => $v) {
        if (!isset(COL[$k])) continue;
        $v = (string)$v;
        $item = ['range' => SHEET_TAB . '!' . COL[$k] . $row, 'values' => [[$v]]];
        if ($k === 'sent') $typed[] = $item; else $raw[] = $item;
    }
    $url = google_api_base() . '/v4/spreadsheets/' . SHEET_ID . '/values:batchUpdate';
    if ($raw) http_json('POST', $url, ['valueInputOption' => 'RAW', 'data' => $raw], false, ["Authorization: Bearer $t"]);
    if ($typed) http_json('POST', $url, ['valueInputOption' => 'USER_ENTERED', 'data' => $typed], false, ["Authorization: Bearer $t"]);
}

/* ---------- channels per lead ---------- */

/** Channels this lead should get: Email and/or Instagram DM (column O, if the contact exists). */
function lead_channels(array $r): array {
    $o = strtolower($r['channel']); $ch = [];
    if (str_contains($o, 'mail') && $r['email'] !== '') $ch[] = 'Email';
    if (str_contains($o, 'insta') && $r['instagram'] !== '') $ch[] = 'Instagram DM';
    if (!$ch) $ch[] = $r['email'] !== '' && !str_contains($o, 'insta') ? 'Email' : 'Instagram DM';
    return $ch;
}
/** Text for one channel. With both channels, Q/R are the email and Y/Z the Instagram DM; with one channel Q/R are used. */
function lead_text(array $r, string $channel, string $kind): string {
    if ($channel === 'Instagram DM' && count(lead_channels($r)) > 1) {
        $t = $kind === 'first' ? $r['ig_message'] : $r['ig_followup'];
        if ($t !== '') return $t;
    }
    return $kind === 'first' ? $r['message'] : $r['followup'];
}
/** channel => 'sent'|'skipped' for one sheet row and kind, from the panel's log.
 *  Only entries for the row's current concept (slug) count, so a row whose content was corrected can be sent again. */
function lead_done(int $row, string $kind, ?array $log = null, string $slug = ''): array {
    $d = [];
    foreach ($log ?? outreach_all() as $o) {
        if ((int)$o['row'] !== $row) continue;
        if ($slug !== '' && ($o['slug'] ?? '') !== '' && $o['slug'] !== $slug) continue;
        if ($o['kind'] === $kind) $d[$o['channel']] ??= 'sent';
        elseif ($o['kind'] === $kind . '-skip') $d[$o['channel']] ??= 'skipped';
    }
    return $d;
}

/** Safety check before sending: the concept page in /<slug>/ must belong to the business on this row.
 *  Returns null when it matches, otherwise a short reason. */
function lead_mismatch(array $r): ?string {
    $slug = $r['slug'];
    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) return 'This row has no concept page (column V is empty).';
    $f = dirname(__DIR__) . '/' . $slug . '/index.html';
    if (!is_file($f)) return "The concept page /$slug/ does not exist yet.";
    if (!preg_match('/concept-bar\.js"[^>]*data-name="([^"]*)"/', (string)file_get_contents($f, false, null, 0, 400000), $m)) return null;
    $n = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', html_entity_decode($s, ENT_QUOTES, 'UTF-8')) : '') ?: html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
    $page = $n($m[1]); $row = $n($r['company']);
    if ($page === '' || $row === '' || str_contains($page, $row) || str_contains($row, $page)) return null;
    similar_text($page, $row, $pct);
    return $pct >= 75 ? null : 'The concept /' . $slug . '/ is for “' . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') . '”, not for ' . $r['company'] . '. The message on this row probably belongs to another business. Do not send; fix the row in the sheet first.';
}

/* ---------- outreach email ---------- */

/** Adds ?from=email to sitesbyms.com concept links. */
function tracked_links(string $text, string $from = 'email'): string {
    return preg_replace_callback('#(?:https?://)?sitesbyms\.com/([a-z0-9-]+)/?(\?from=[a-z0-9-]+)?#i', fn($m) => 'https://sitesbyms.com/' . strtolower($m[1]) . '/?from=' . $from, $text);
}
function text_to_html(string $text, string $pixel = ''): string {
    $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $h = preg_replace_callback('#https?://[^\s<]+#', function ($m) {
        $url = html_entity_decode($m[0]); $label = preg_replace(['#^https?://#', '#/?\?from=[a-z0-9-]+$#', '#/$#'], '', $url);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="color:#5B2FE0;font-weight:600">' . htmlspecialchars($label) . '</a>';
    }, $h);
    $h = preg_replace('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '<a href="mailto:$1" style="color:#5B2FE0">$1</a>', $h);
    return '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff"><div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1b1f33;max-width:600px">'
        . nl2br($h, false) . '</div>' . ($pixel ? '<img src="' . htmlspecialchars($pixel, ENT_QUOTES) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px">' : '') . '</body></html>';
}
/** Sends a plain + HTML email from hello@ with a 1px image that tells us it was opened.
 *  The team copy is a separate email without that image, so our own reading is never counted. Returns the Message-ID or null. */
function send_outreach(string $to, string $subject, string $text, ?string $inReplyTo = null, string $slug = '', string $kind = 'first'): ?string {
    $mid = '<' . bin2hex(random_bytes(12)) . '@sitesbyms.com>';
    $pixel = $slug ? 'https://sitesbyms.com/api/o.php?s=' . rawurlencode($slug) . '&k=' . $kind : '';
    $build = function (string $html) use ($text, &$b) {
        $b = 'sbm' . bin2hex(random_bytes(8));
        return "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
             . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--";
    };
    $head = fn(string $id, string $boundary) => 'From: Sites By MS <' . FROM_ADDR . ">\r\nReply-To: " . FROM_ADDR . "\r\nMessage-ID: $id\r\nMIME-Version: 1.0\r\n"
             . ($inReplyTo ? "In-Reply-To: $inReplyTo\r\nReferences: $inReplyTo\r\n" : '') . "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body = $build(text_to_html($text, $pixel));
    $ok = @mail($to, $subj, $body, $head($mid, $b), '-f' . FROM_ADDR);
    if ($ok) {   // copy for the team inbox, no tracking image
        $copy = $build(text_to_html("Sent to $to\n\n" . $text));
        @mail('m.and.s.design.develop@gmail.com', '=?UTF-8?B?' . base64_encode('[Sent] ' . $subject) . '?=', $copy, $head('<' . bin2hex(random_bytes(12)) . '@sitesbyms.com>', $b), '-f' . FROM_ADDR);
    }
    return $ok ? $mid : null;
}

/* ---------- "seen": email opened (tracking image) or Instagram marked Seen by the team ---------- */
function seen_migrate(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS message_seen (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'first',
        channel VARCHAR(30) NOT NULL, how VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, INDEX (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function seen_add(string $slug, string $kind, string $channel, string $how): void {
    $p = db(); if (!$p) { append_line('seen.jsonl', ['slug' => $slug, 'kind' => $kind, 'channel' => $channel, 'how' => $how, 'at' => gmdate('c')]); return; }
    seen_migrate($p);
    $p->prepare('INSERT INTO message_seen (slug,kind,channel,how,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$slug, $kind, $channel, $how]);
}
/** slug and "slug|channel" => first time the message was seen (ISO). */
function seen_map(): array {
    $p = db();
    $rows = $p ? (function () use ($p) { seen_migrate($p); return array_map(fn($r) => ['slug' => $r['slug'], 'channel' => $r['channel'], 'at' => iso($r['created_at'])], $p->query('SELECT slug, channel, MIN(created_at) created_at FROM message_seen GROUP BY slug, channel')->fetchAll()); })() : read_lines('seen.jsonl');
    $m = []; foreach ($rows as $r) foreach ([$r['slug'], $r['slug'] . '|' . ($r['channel'] ?? '')] as $k) if (!isset($m[$k]) || strtotime($r['at']) < strtotime($m[$k])) $m[$k] = $r['at'];
    return $m;
}

function outreach_migrate(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS outreach (
        id INT AUTO_INCREMENT PRIMARY KEY, sheet_row INT NOT NULL, slug VARCHAR(80) NOT NULL DEFAULT '', business VARCHAR(160) NOT NULL,
        kind VARCHAR(20) NOT NULL, channel VARCHAR(30) NOT NULL, to_email VARCHAR(190) NOT NULL DEFAULT '', subject VARCHAR(255) NOT NULL DEFAULT '',
        body MEDIUMTEXT NOT NULL, message_id VARCHAR(120) NULL, sent_by VARCHAR(190) NOT NULL DEFAULT '', sent_at DATETIME NOT NULL,
        INDEX (slug), INDEX (sheet_row)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function outreach_log(array $o): void {
    $p = db(); if (!$p) { append_line('outreach.jsonl', $o + ['sent_at' => gmdate('c')]); return; }
    outreach_migrate($p);
    $p->prepare('INSERT INTO outreach (sheet_row,slug,business,kind,channel,to_email,subject,body,message_id,sent_by,sent_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')
      ->execute([$o['row'], $o['slug'], $o['business'], $o['kind'], $o['channel'], $o['to'], $o['subject'], $o['body'], $o['mid'], $o['by']]);
}
function outreach_all(): array {
    $p = db(); if (!$p) return array_reverse(read_lines('outreach.jsonl'));
    outreach_migrate($p);
    return array_map(fn($r) => $r + ['row' => (int)$r['sheet_row'], 'to' => $r['to_email'], 'mid' => $r['message_id'], 'at' => iso($r['sent_at'])], $p->query('SELECT * FROM outreach ORDER BY sent_at DESC, id DESC LIMIT 500')->fetchAll());
}
