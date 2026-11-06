<?php
// Private JSON API for the nightly pipeline and the reviews (used by Claude instead of the old Google Sheet).
// Auth: "Authorization: Bearer <key>". Only the key's SHA-256 is stored (api/_apikey.json); the key itself never sits on the server.
declare(strict_types=1);
require __DIR__ . '/_google.php';
require_once __DIR__ . '/_autosend.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($hdr === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = (string)$v;
$key = preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/', trim($hdr), $m) ? $m[1] : '';
$want = (array)json_decode((string)@file_get_contents(__DIR__ . '/_apikey.json'), true);
$ok = false; foreach ((array)($want['sha256'] ?? []) as $h) if ($key !== '' && is_string($h) && hash_equals($h, hash('sha256', $key))) $ok = true;
if (!$ok) { rate_limit('leadsapi_bad', 20, 3600) || out(['error' => 'too many attempts'], 429); out(['error' => 'unauthorized'], 401); }

$GLOBALS['sbm_actor'] = 'claude';
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$a = (string)($in['a'] ?? 'rows');

try {
    switch ($a) {
        case 'ping':
            out(['ok' => true, 'store' => leads_store(), 'rows' => count(sheet_rows()), 'time' => gmdate('c')]);
        case 'rows':     // all leads; optional status=Review,Sent and fields=company,email
            $rows = sheet_rows(true);
            if (!empty($in['status'])) { $want = array_map('trim', explode(',', (string)$in['status'])); $rows = array_values(array_filter($rows, fn($r) => in_array($r['status'], $want, true))); }
            if (!empty($in['fields'])) { $f = array_flip(array_merge(['_row'], array_map('trim', explode(',', (string)$in['fields'])))); $rows = array_map(fn($r) => array_intersect_key($r, $f), $rows); }
            out(['store' => leads_store(), 'count' => count($rows), 'rows' => $rows]);
        case 'row':
            $r = null; foreach (sheet_rows(true) as $x) if ($x['_row'] === (int)$in['row']) $r = $x;
            $r ? out(['row' => $r, 'history' => leads_history((int)$in['row'], 50)]) : out(['error' => 'no such row'], 404);
        case 'update':   // {row, cells:{status:...}, company:"<expected name>"}
            $row = (int)($in['row'] ?? 0); $cells = (array)($in['cells'] ?? []);
            $cur = null; foreach (sheet_rows(true) as $x) if ($x['_row'] === $row) $cur = $x;
            if (!$cur) out(['error' => "row $row does not exist"], 404);
            if (isset($in['company']) && trim((string)$in['company']) !== $cur['company']) out(['error' => "row $row is “{$cur['company']}”, not “{$in['company']}”"], 409);
            foreach ($cells as $k => $v) if (!in_array($k, LEAD_FIELDS, true)) out(['error' => "unknown field $k"], 400);
            sheet_update_row($row, $cells);
            out(['ok' => true, 'row' => $row]);
        case 'append':   // {leads:[{company:..., ...}, ...]}
            $done = [];
            foreach ((array)($in['leads'] ?? []) as $l) { $done[] = sheet_append_lead((array)$l); }
            out(['ok' => true, 'rows' => $done]);
        case 'settings':
            if (isset($in['kv']) && is_array($in['kv'])) { panel_settings_save(array_map('strval', (array)$in['kv'] + panel_settings())); }
            out(['settings' => panel_settings()]);
        case 'history':
            out(['history' => leads_history(isset($in['row']) ? (int)$in['row'] : null, min(1000, (int)($in['limit'] ?? 200)))]);
        case 'sessions': // visits with behaviour, for the reviews (team visits and bots are never recorded)
            $days = max(1, min(60, (int)($in['days'] ?? 7)));
            $s = array_map(function ($x) { $x['events'] = array_map(fn($e) => [$e['ev'], $e['val'], $e['t'] ?? 0], $x['events']); unset($x['vid']); return $x; }, sessions_from(events_all($days)));
            out(['days' => $days, 'sessions' => $s, 'warm' => warm_slugs()]);
        case 'views':
            out(['views' => array_slice(views_all(), 0, 2000)]);
        case 'outreach':
            out(['outreach' => array_map(fn($o) => array_diff_key($o, ['body' => 1]) + ['body_start' => mb_substr((string)($o['body'] ?? ''), 0, 160)], outreach_all())]);
        case 'autosend':
            $files = glob(data_dir() . '/autosend-*.json') ?: []; rsort($files); $rep = [];
            foreach (array_slice($files, 0, (int)($in['days'] ?? 7)) as $f) $rep[] = json_decode((string)file_get_contents($f), true);
            out(['reports' => $rep, 'state' => read_json('autosend_state.json', []), 'heartbeat' => read_json('cron_heartbeat.json', [])]);
        case 'sendnow':  // ask the next cron run (within 10 minutes) to send everything that is due now
            setting_set('autosend_now', ['day' => (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d'), 'at' => time()]);
            out(['ok' => true, 'note' => 'the next cron run sends what is due']);
        case 'messages':
            out(['messages' => array_slice(messages_all(), 0, 200), 'bookings' => bookings_all()]);
        default:
            out(['error' => 'unknown action'], 400);
    }
} catch (Throwable $e) { out(['error' => $e->getMessage()], 500); }
