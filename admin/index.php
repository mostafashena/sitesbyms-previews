<?php
// Sites By MS — team dashboard.
// First visit: create the admin password. Data lives in ~/sitesbyms_data, never in public_html.
declare(strict_types=1);
require __DIR__ . '/../api/_lib.php';

session_name('sbm_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$admin = read_json('admin.json', []);
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$page = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';
$msg = '';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post_ok(string $csrf): bool { return $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? '')); }
function london(int $ts, string $f = 'D j M, H:i'): string { return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(TZ))->format($f); }
function ago(int $ts): string {
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    $n = (int)floor($d / 86400); return $n . ($n === 1 ? ' day ago' : ' days ago');
}
function fa_num($s): string { return (string)$s; }

/* ---------- first run: create password ---------- */
if (!$admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (strlen($p1) < 10) $msg = 'The password needs at least 10 characters.';
        elseif ($p1 !== $p2) $msg = 'The two passwords don\'t match.';
        else { write_json('admin.json', ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => gmdate('c')]); session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: /admin/'); exit; }
    }
    layout_auth('Create the panel password', '<p class="sub">This is the first time the panel is opened. Choose a strong password and share it only with your team.</p>'
        . ($msg ? '<p class="err">' . h($msg) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><label>New password<input type="password" name="p1" autocomplete="new-password" required minlength="10"></label>'
        . '<label>Repeat password<input type="password" name="p2" autocomplete="new-password" required></label><button class="btn">Create and log in</button></form>');
    exit;
}

/* ---------- login / logout ---------- */
if ($page === 'logout') { $_SESSION = []; session_destroy(); header('Location: /admin/'); exit; }
if (empty($_SESSION['ok'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rate_limit('admin', 8, 900)) $msg = 'Too many attempts. Please try again in 15 minutes.';
        elseif (hash_equals($csrf, (string)($_POST['csrf'] ?? '')) && password_verify((string)($_POST['p'] ?? ''), $admin['hash'])) { session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: /admin/'); exit; }
        else $msg = 'That password isn\'t right.';
    }
    layout_auth('Log in to the team panel', ($msg ? '<p class="err">' . h($msg) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><label>Password<input type="password" name="p" autocomplete="current-password" required autofocus></label><button class="btn">Log in</button></form>');
    exit;
}

/* ---------- actions ---------- */
if (post_ok($csrf)) {
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'done' || $a === 'undone') {
        $hd = read_json('handled.json', []); $k = (string)$_POST['k'];
        if ($a === 'done') $hd[$k] = time(); else unset($hd[$k]);
        write_json('handled.json', $hd);
    }
    if ($a === 'cancel') {
        $id = (string)$_POST['id']; $rows = read_lines('bookings.jsonl'); $out = '';
        foreach ($rows as $r) { if ($r['id'] === $id) $r['status'] = 'cancelled'; $out .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
        file_put_contents(data_dir() . '/bookings.jsonl', $out, LOCK_EX);
        $msg = 'Call cancelled. Remember to let the client know.';
    }
    if ($a === 'settings') {
        $c = read_json('config.json', []);
        $c['ical_url'] = trim((string)$_POST['ical_url']);
        $c['meet_link'] = trim((string)$_POST['meet_link']);
        $c['days_ahead'] = max(0, min(14, (int)$_POST['days_ahead']));
        $c['start'] = preg_match('/^\d{2}:\d{2}$/', (string)$_POST['start']) ? $_POST['start'] : '10:00';
        $c['end'] = preg_match('/^\d{2}:\d{2}$/', (string)$_POST['end']) ? $_POST['end'] : '17:00';
        $c['weekdays'] = array_values(array_map('intval', (array)($_POST['wd'] ?? [1, 2, 3, 4, 5])));
        write_json('config.json', $c); @unlink(data_dir() . '/ical_cache.json');
        $msg = 'Settings saved.';
    }
    if ($a === 'password') {
        if (!password_verify((string)$_POST['old'], $admin['hash'])) $msg = 'Your current password isn\'t right.';
        elseif (strlen((string)$_POST['new']) < 10) $msg = 'The new password needs at least 10 characters.';
        else { $admin['hash'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT); write_json('admin.json', $admin); $msg = 'Password changed.'; }
    }
    if (!$msg) { header('Location: /admin/?p=' . $page); exit; }
}

/* ---------- data ---------- */
$bookings = read_lines('bookings.jsonl');
usort($bookings, fn($x, $y) => $x['start_ts'] <=> $y['start_ts']);
$upcoming = array_values(array_filter($bookings, fn($b) => $b['status'] !== 'cancelled' && $b['start_ts'] > time() - 1800));
$messages = array_reverse(read_lines('submissions.jsonl'));
$handled = read_json('handled.json', []);
$open = array_values(array_filter($messages, fn($m) => !isset($handled[$m['at'] . $m['email']])));
$views = array_reverse(read_lines('views.jsonl'));
$week = array_filter($views, fn($v) => strtotime($v['at']) > time() - 7 * 86400);
$bySlug = [];
foreach ($views as $v) { $s = $v['slug']; $bySlug[$s] ??= ['n' => 0, 'last' => 0, 'device' => '']; $bySlug[$s]['n']++; $t = strtotime($v['at']); if ($t > $bySlug[$s]['last']) { $bySlug[$s]['last'] = $t; $bySlug[$s]['device'] = $v['device']; } }
uasort($bySlug, fn($x, $y) => $y['last'] <=> $x['last']);
$cfg = config();
$typeFa = ['booking' => 'Call request', 'question' => 'Question', 'concept' => 'Free concept request'];
$howFa = ['Video call (Google Meet)' => 'Video call', 'WhatsApp call' => 'WhatsApp call', 'Phone call' => 'Phone call'];

/* ---------- views ---------- */
function bookingRow(array $b, string $csrf, array $howFa): string {
    $past = $b['start_ts'] < time();
    return '<div class="row"><div class="date"><b>' . h(london($b['start_ts'], 'j')) . '</b><span>' . h(london($b['start_ts'], 'M')) . '</span></div>'
        . '<div class="grow"><b>' . h($b['business']) . '</b> · ' . h($b['name']) . '<small>' . h(london($b['start_ts'], 'D H:i')) . ' London · ' . h($b['email']) . ($b['phone'] ? ' · ' . h($b['phone']) : '') . '</small>'
        . '<small>' . h($howFa[$b['how']] ?? $b['how']) . ($b['slug'] ? ' · <a href="/' . h($b['slug']) . '/?team=1" target="_blank">Concept</a>' : '') . '</small></div>'
        . ($b['status'] === 'cancelled' ? '<span class="pill grey">Cancelled</span>' : ($past ? '<span class="pill grey">Past</span>' : '<form method="post" onsubmit="return confirm(\'Cancel this call?\')"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="' . h($b['id']) . '"><button class="btn line sm">Cancel</button></form>'))
        . '</div>';
}
function msgRow(array $m, string $csrf, array $handled, array $typeFa): string {
    $k = $m['at'] . $m['email']; $done = isset($handled[$k]);
    return '<div class="row' . ($done ? ' muted' : '') . '"><div class="grow"><span class="pill">' . h($typeFa[$m['type']] ?? $m['type']) . '</span> <b>' . h($m['business']) . '</b> · ' . h($m['name'])
        . '<small>' . h($m['email']) . (!empty($m['phone']) ? ' · ' . h($m['phone']) : '') . (!empty($m['site']) ? ' · ' . h($m['site']) : '') . '</small>'
        . (!empty($m['slot']) ? '<small>' . h($m['slot']) . '</small>' : '')
        . (!empty($m['message']) ? '<p class="msg">' . nl2br(h($m['message'])) . '</p>' : '')
        . '<small>' . h(ago(strtotime($m['at']))) . (!empty($m['file']) ? ' · file attached in the email' : '') . '</small></div>'
        . '<div class="acts"><a class="btn line sm" href="mailto:' . h($m['email']) . '">Reply</a><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="' . ($done ? 'undone' : 'done') . '"><input type="hidden" name="k" value="' . h($k) . '"><button class="btn ' . ($done ? 'line ' : '') . 'sm">' . ($done ? 'Reopen' : 'Done') . '</button></form></div></div>';
}

$nav = [['home', 'Overview'], ['calls', 'Calls'], ['messages', 'Messages'], ['views', 'Concept views'], ['settings', 'Settings']];
ob_start();
if ($page === 'home') {
    echo '<h1>Hello 👋</h1><p class="sub">Here\'s where Sites By MS stands today.</p>';
    echo '<div class="stats"><a href="?p=calls" class="stat"><b>' . fa_num(count($upcoming)) . '</b><span>Upcoming calls</span></a><a href="?p=messages" class="stat"><b>' . fa_num(count($open)) . '</b><span>Unanswered messages</span></a><a href="?p=views" class="stat"><b>' . fa_num(count($week)) . '</b><span>Concept views, last 7 days</span></a><a href="?p=views" class="stat"><b>' . fa_num(count($bySlug)) . '</b><span>Concepts opened</span></a></div>';
    echo '<div class="g2"><section class="card"><div class="ch"><h2>Next calls</h2><a href="?p=calls">All calls</a></div>' . ($upcoming ? implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), array_slice($upcoming, 0, 4))) : '<div class="empty">No calls booked yet.</div>') . '</section>';
    echo '<section class="card"><div class="ch"><h2>Latest concept views</h2><a href="?p=views">All views</a></div>';
    if ($views) foreach (array_slice($views, 0, 6) as $v) echo '<div class="row"><div class="grow"><b>' . h($v['slug']) . '</b><small>' . h(ago(strtotime($v['at']))) . ' · ' . ($v['device'] === 'phone' ? 'phone' : 'computer') . '</small></div><a class="btn line sm" href="/' . h($v['slug']) . '/?team=1" target="_blank">Open</a></div>';
    else echo '<div class="empty">No one has opened a concept yet. When a client opens their link, you\'ll see it here and get an email.</div>';
    echo '</section></div>';
    echo '<section class="card"><div class="ch"><h2>Unanswered messages</h2><a href="?p=messages">All messages</a></div>' . ($open ? implode('', array_map(fn($m) => msgRow($m, $csrf, $handled, $typeFa), array_slice($open, 0, 3))) : '<div class="empty">All caught up.</div>') . '</section>';
    echo '<section class="card"><h2>Useful links</h2><div class="links"><a class="btn line" href="https://docs.google.com/spreadsheets/d/1NZFcaeoG7fR8--nepcmujsFJAZzfb4r1sCh-_3Lbs-s/edit" target="_blank" rel="noopener">Leads sheet</a><a class="btn line" href="/demo/" target="_blank">Client portal demo</a><a class="btn line" href="/" target="_blank">Website</a></div></section>';
}
if ($page === 'calls') {
    echo '<h1>Calls</h1><p class="sub">Every call clients have booked. Times are London time.</p>';
    echo '<section class="card"><h2>Upcoming</h2>' . ($upcoming ? implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), $upcoming)) : '<div class="empty">No upcoming calls.</div>') . '</section>';
    $past = array_reverse(array_values(array_filter($bookings, fn($b) => !in_array($b, $upcoming, true))));
    if ($past) echo '<section class="card"><h2>Past and cancelled</h2>' . implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), array_slice($past, 0, 30))) . '</section>';
}
if ($page === 'messages') {
    echo '<h1>Messages</h1><p class="sub">Questions and free concept requests from the website forms.</p>';
    echo '<section class="card">' . ($messages ? implode('', array_map(fn($m) => msgRow($m, $csrf, $handled, $typeFa), $messages)) : '<div class="empty">No messages yet.</div>') . '</section>';
}
if ($page === 'views') {
    echo '<h1>Concept views</h1><p class="sub">Who has opened their concept. The first few hours are the best time to follow up.</p><section class="card">';
    if ($bySlug) foreach ($bySlug as $s => $v) echo '<div class="row"><div class="grow"><b>' . h($s) . '</b><small>Last opened ' . h(ago($v['last'])) . ' · ' . ($v['device'] === 'phone' ? 'phone' : 'computer') . '</small></div><span class="pill">' . $v['n'] . ($v['n'] === 1 ? ' view' : ' views') . '</span><a class="btn line sm" href="/' . h($s) . '/?team=1" target="_blank">Open</a></div>';
    else echo '<div class="empty">No views recorded yet.</div>';
    echo '</section><p class="sub">Your own visits are not counted once you have opened any concept with ?team=1 on that device.</p>';
}
if ($page === 'settings') {
    $wd = $cfg['weekdays']; $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $ical = $cfg['ical_url'] ? count(ical_busy()) : null;
    echo '<h1>Settings</h1>';
    echo '<form class="card" method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="settings"><h2>Calendar &amp; booking</h2>'
        . '<label>Google Calendar secret address (iCal)<input name="ical_url" value="' . h($cfg['ical_url']) . '" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"><span class="hint">With this, times that are busy in your Google Calendar show as Taken on the booking page.'
        . ($ical !== null ? ' ' . $ical . ' events read from the calendar just now.' : '') . '</span></label>'
        . '<label>Fixed Google Meet link (optional)<input name="meet_link" value="' . h($cfg['meet_link']) . '" placeholder="https://meet.google.com/abc-defg-hij"><span class="hint">If set, it is sent to the client in the calendar invite.</span></label>'
        . '<div class="f3"><label>First bookable day<select name="days_ahead">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n == $cfg['days_ahead'] ? ' selected' : '') . '>' . $n . ($n === 1 ? ' day' : ' days') . ' from today</option>', range(1, 7))) . '</select></label>'
        . '<label>Day starts (London)<input name="start" type="time" value="' . h($cfg['start']) . '"></label><label>Day ends (London)<input name="end" type="time" value="' . h($cfg['end']) . '"></label></div>'
        . '<fieldset><legend>Working days</legend><div class="wd">' . implode('', array_map(fn($n) => '<label class="chk"><input type="checkbox" name="wd[]" value="' . $n . '"' . (in_array($n, $wd, true) ? ' checked' : '') . '>' . $days[$n] . '</label>', array_keys($days))) . '</div></fieldset>'
        . '<button class="btn">Save settings</button></form>';
    echo '<form class="card" method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="password"><h2>Change password</h2><div class="f3"><label>Current password<input type="password" name="old" autocomplete="current-password"></label><label>New password<input type="password" name="new" autocomplete="new-password" minlength="10"></label></div><button class="btn line">Change password</button></form>';
}
$body = ob_get_clean();
layout($page, $nav, $body, $msg, count($open));

/* ---------- layout ---------- */
function head(string $title): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' · Sites By MS team panel</title>'
        . '<link rel="icon" href="/assets/brand/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/admin/admin.css"></head><body>';
}
function layout_auth(string $title, string $inner): void {
    echo head($title) . '<main class="auth"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS" class="logo"><div class="box"><h1>' . h($title) . '</h1>' . $inner . '</div></main></body></html>';
}
function layout(string $page, array $nav, string $body, string $msg, int $openCount): void {
    echo head('Team panel') . '<header class="top"><a class="brand" href="/admin/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span><em>Team panel</em></a>'
        . '<nav aria-label="Panel">' . implode('', array_map(fn($n) => '<a href="?p=' . $n[0] . '"' . ($page === $n[0] ? ' aria-current="page"' : '') . '>' . $n[1] . ($n[0] === 'messages' && $openCount ? ' <i>' . fa_num($openCount) . '</i>' : '') . '</a>', $nav))
        . '<a href="?p=logout" class="out">Log out</a></nav></header><main class="wrap">' . ($msg ? '<div class="note" role="status">' . h($msg) . '</div>' : '') . $body . '</main></body></html>';
}
