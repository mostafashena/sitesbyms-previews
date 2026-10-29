<?php
// Sites By MS — team panel.
// Before MySQL is connected: one shared panel password (admin.json).
// After: personal accounts (email + password). The first account is the owner.
declare(strict_types=1);
require __DIR__ . '/../api/_lib.php';
require __DIR__ . '/../api/_google.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('sbm_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function london(int $ts, string $f = 'D j M, H:i'): string { return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(TZ))->format($f); }
function ago(?string $iso): string {
    if (!$iso) return '';
    $d = time() - strtotime($iso);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    $n = (int)floor($d / 86400); return $n . ($n === 1 ? ' day ago' : ' days ago');
}
function go(string $p = 'home', string $flash = '', bool $bad = false): void { if ($flash) { $_SESSION['flash'] = $flash; $_SESSION['flash_bad'] = $bad; } header('Location: /admin/?p=' . $p); exit; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrfOk = $isPost && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
$page = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';
$err = '';

$pdo = db();
if (!$pdo && !empty(db_conf()['name'])) auth_page('Database not reachable', '<p class="err">The panel could not connect to MySQL, so nothing can be shown right now. Bookings and messages from the website are still saved and will appear here once it is back.</p><p class="hint">' . h($GLOBALS['db_error'] ?? '') . '</p><p><a class="btn" href="/admin/">Try again</a></p>');
// Anything saved to files while the database was unreachable is moved in.
if ($pdo) foreach (['bookings.jsonl', 'submissions.jsonl', 'views.jsonl'] as $f) if (is_file(data_dir() . '/' . $f)) { db_import_files($pdo); break; }
$legacy = read_json('admin.json', []);
$hasUsers = $pdo ? count(users_all()) > 0 : false;

if ($page === 'logout') { $_SESSION = []; session_destroy(); header('Location: /admin/'); exit; }

/* ---------- who is logged in ---------- */
$me = null;
if (!empty($_SESSION['uid']) && $pdo) $me = user_by_id((int)$_SESSION['uid']);
elseif (!empty($_SESSION['legacy']) && !$hasUsers) $me = ['id' => 0, 'name' => 'Team', 'email' => '', 'role' => 'owner', 'legacy' => true];

/* ---------- first run without anything: create the shared password ---------- */
if (!$me && !$pdo && !$legacy) {
    if ($csrfOk) {
        $p1 = (string)($_POST['p1'] ?? '');
        if (strlen($p1) < 10) $err = 'The password needs at least 10 characters.';
        elseif ($p1 !== (string)($_POST['p2'] ?? '')) $err = "The two passwords don't match.";
        else { write_json('admin.json', ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => gmdate('c')]); session_regenerate_id(true); $_SESSION['legacy'] = true; go(); }
    }
    auth_page('Create the panel password', '<p class="sub">This is the first time the panel is opened. Choose a strong password.</p>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<form method="post">' . csrf_field() . '<label>New password<input type="password" name="p1" autocomplete="new-password" required minlength="10"></label><label>Repeat password<input type="password" name="p2" autocomplete="new-password" required></label><button class="btn">Create and log in</button></form>');
}

/* ---------- MySQL connected but no accounts yet: create the owner ---------- */
if ($pdo && !$hasUsers && (!$legacy || !empty($_SESSION['legacy']))) {
    if ($csrfOk && ($_POST['a'] ?? '') === 'owner') {
        $name = one_line((string)$_POST['name']); $email = strtolower(one_line((string)$_POST['email'])); $pw = (string)$_POST['pw'];
        if ($name === '') $err = 'Please add your name.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please check the email address.';
        elseif (strlen($pw) < 10) $err = 'The password needs at least 10 characters.';
        else { user_add($email, $name, 'owner', $pw); $u = user_by_email($email); session_regenerate_id(true); unset($_SESSION['legacy']); $_SESSION['uid'] = (int)$u['id']; go('home', 'Your admin account is ready. From now on you log in with your email.'); }
    }
    auth_page('Create your admin account', '<p class="sub">The database is connected. Create your own account. You log in with this email from now on, and you can add your team in Team.</p>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="owner"><label>Your name<input name="name" autocomplete="name" required></label><label>Email<input type="email" name="email" autocomplete="email" required></label>'
        . '<label>Password <span class="hint">at least 10 characters</span><input type="password" name="pw" autocomplete="new-password" minlength="10" required></label><button class="btn">Create my account</button></form>');
}

/* ---------- login ---------- */
if (!$me) {
    if ($isPost) {
        if (!rate_limit('admin', 8, 900)) $err = 'Too many attempts. Please try again in 15 minutes.';
        elseif (!$csrfOk) $err = 'Your session expired. Please try again.';
        elseif ($hasUsers) {
            $u = user_by_email((string)($_POST['email'] ?? ''));
            if ($u && password_verify((string)($_POST['p'] ?? ''), $u['password_hash'])) {
                session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
                $pdo->prepare('UPDATE users SET last_login=UTC_TIMESTAMP() WHERE id=?')->execute([$u['id']]); go();
            }
            $err = "That email and password don't match.";
        } else {
            if ($legacy && password_verify((string)($_POST['p'] ?? ''), $legacy['hash'])) { session_regenerate_id(true); $_SESSION['legacy'] = true; go(); }
            $err = "That password isn't right.";
        }
    }
    auth_page('Log in to the team panel', ($err ? '<p class="err">' . h($err) . '</p>' : '') . '<form method="post">' . csrf_field()
        . ($hasUsers ? '<label>Email<input type="email" name="email" autocomplete="email" required autofocus></label>' : '')
        . '<label>Password<input type="password" name="p" autocomplete="current-password" required' . ($hasUsers ? '' : ' autofocus') . '></label><button class="btn">Log in</button></form>');
}
$isOwner = ($me['role'] ?? '') === 'owner';

/* ---------- actions ---------- */
if ($isPost && !$csrfOk) go($page, 'Your session expired. Please try again.', true);
if ($csrfOk) {
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'done' || $a === 'undone') { message_handled((string)$_POST['k'], $a === 'done'); go($page); }
    if ($a === 'cancel') { booking_update((string)$_POST['id'], ['status' => 'cancelled']); go($page, 'Call cancelled. Remember to let the client know.'); }
    if ($a === 'meet') {
        $b = booking_get((string)$_POST['id']); $link = trim((string)$_POST['meet']);
        if (!$b) go($page, 'That call no longer exists.', true);
        if (!preg_match('#^https://[^\s<>"]+$#', $link)) go($page, 'Please paste the full link, starting with https://', true);
        booking_update($b['id'], ['meet' => $link, 'meet_sent_at' => gmdate('Y-m-d H:i:s')]);
        $b['meet'] = $link; $b['how'] = 'Video call (Google Meet)';
        $when = london($b['start_ts'], 'l j F, H:i');
        $txt = "Hi {$b['name']},\n\nHere's the link for our call on $when (London time):\n\n$link\n\nJust click it a minute before the call. No app needed on a computer; on a phone, Google Meet may ask you to open its app.\n\nThe attached invite updates your calendar.\n\nSee you soon,\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048";
        $ics = ['name' => 'invite.ics', 'type' => 'text/calendar; charset=UTF-8; method=REQUEST', 'data' => ics_invite($b, 1)];
        $ok = send_mail($b['email'], "Your call link: $when", $txt, FROM_ADDR, [$ics]);
        go($page, $ok ? 'Meet link sent to ' . $b['email'] . '.' : 'Saved, but the email could not be sent. Please send the link yourself.', !$ok);
    }
    if (in_array($a, ['osend', 'omark', 'oskip'], true)) {
        $row = (int)$_POST['row']; $kind = $_POST['kind'] === 'followup' ? 'followup' : 'first';
        $business = one_line((string)$_POST['business']); $slug = preg_replace('/[^a-z0-9-]/', '', (string)$_POST['slug']);
        $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d');
        try {
            if ($a === 'oskip') { sheet_update_row($row, ['status' => 'Skip']); go('outreach', "$business moved to Skip."); }
            $body = str_replace("\r", '', (string)$_POST['body']);
            if (trim($body) === '') go('outreach', 'The message is empty.', true);
            if ($a === 'osend') {
                $to = strtolower(one_line((string)$_POST['to'])); $subject = one_line((string)$_POST['subject']);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) go('outreach', 'Please check the email address for ' . $business . '.', true);
                if ($subject === '') go('outreach', 'Please add a subject for ' . $business . '.', true);
                $reply = null;
                if ($kind === 'followup') {
                    foreach (outreach_all() as $o) if ((int)$o['row'] === $row && $o['kind'] === 'first' && !empty($o['mid'])) { $reply = $o['mid']; break; }
                    if (stripos($subject, 're:') !== 0) $subject = 'Re: ' . $subject;
                }
                $mid = send_outreach($to, $subject, tracked_links($body, 'email'), $reply);
                if (!$mid) go('outreach', 'The email to ' . $to . ' could not be sent. Nothing was changed.', true);
                outreach_log(['row' => $row, 'slug' => $slug, 'business' => $business, 'kind' => $kind, 'channel' => 'Email', 'to' => $to, 'subject' => $subject, 'body' => $body, 'mid' => $mid, 'by' => (string)($me['email'] ?? 'team')]);
                if ($kind === 'first') sheet_update_row($row, ['status' => 'Sent', 'sent' => $today, 'subject' => $subject, 'message' => $body, 'email' => $to]);
                else sheet_update_row($row, ['reply' => 'Follow-up sent ' . $today, 'followup' => $body]);
                go('outreach', ($kind === 'first' ? 'Sent to ' : 'Follow-up sent to ') . $to . '. The sheet is updated.');
            }
            // Instagram: the team sends it in the app, the panel records it.
            outreach_log(['row' => $row, 'slug' => $slug, 'business' => $business, 'kind' => $kind, 'channel' => 'Instagram DM', 'to' => one_line((string)($_POST['to'] ?? '')), 'subject' => '', 'body' => $body, 'mid' => null, 'by' => (string)($me['email'] ?? 'team')]);
            if ($kind === 'first') sheet_update_row($row, ['status' => 'Sent', 'sent' => $today, 'message' => $body]);
            else sheet_update_row($row, ['reply' => 'Follow-up sent ' . $today, 'followup' => $body]);
            go('outreach', "$business marked as sent. The sheet is updated.");
        } catch (Throwable $e) { go('outreach', 'Sheet error: ' . $e->getMessage(), true); }
    }
    if ($a === 'gkey' && $isOwner) {
        $raw = (string)($_POST['json'] ?? '');
        if (!empty($_FILES['keyfile']['tmp_name']) && is_uploaded_file($_FILES['keyfile']['tmp_name'])) $raw = (string)file_get_contents($_FILES['keyfile']['tmp_name']);
        $k = json_decode($raw, true);
        if (!is_array($k) || empty($k['private_key']) || empty($k['client_email'])) go('settings', 'That is not a service account key file. Download the JSON key again and upload it.', true);
        write_json('google-sa.json', ['client_email' => $k['client_email'], 'private_key' => $k['private_key'], 'project_id' => $k['project_id'] ?? '']);
        @chmod(data_dir() . '/google-sa.json', 0600); @unlink(data_dir() . '/google-token.json');
        try { $n = count(sheet_rows()); go('settings', "Connected to the leads sheet: $n rows read."); }
        catch (Throwable $e) { go('settings', 'Key saved, but the sheet could not be read yet: ' . $e->getMessage() . ' Share the sheet with ' . $k['client_email'] . ' as Editor, then press Test.', true); }
    }
    if ($a === 'gtest') {
        @unlink(data_dir() . '/google-token.json');
        try { $n = count(sheet_rows()); go('settings', "Connected to the leads sheet: $n rows read."); }
        catch (Throwable $e) { go('settings', 'Could not read the sheet: ' . $e->getMessage(), true); }
    }
    if ($a === 'settings') {
        $c = config();
        $c['ical_url'] = trim((string)$_POST['ical_url']);
        $c['days_ahead'] = max(1, min(14, (int)$_POST['days_ahead']));
        $c['start'] = preg_match('/^\d{2}:\d{2}$/', (string)$_POST['start']) ? $_POST['start'] : '10:00';
        $c['end'] = preg_match('/^\d{2}:\d{2}$/', (string)$_POST['end']) ? $_POST['end'] : '17:00';
        $c['weekdays'] = array_values(array_map('intval', (array)($_POST['wd'] ?? [1, 2, 3, 4, 5])));
        setting_set('config', $c); @unlink(data_dir() . '/ical_cache.json');
        go('settings', 'Settings saved.');
    }
    if ($a === 'db' && $isOwner && !$pdo) {
        $c = ['host' => one_line((string)$_POST['host']) ?: 'localhost', 'name' => one_line((string)$_POST['name']), 'user' => one_line((string)$_POST['user']), 'pass' => (string)$_POST['pass']];
        try {
            $p = db_connect($c); db_migrate($p);
            write_json('db.json', $c); @chmod(data_dir() . '/db.json', 0600);
            $n = db_import_files($p);
            go('home', "Database connected. Moved {$n['bookings']} calls, {$n['messages']} messages and {$n['views']} views into it. Now create your admin account.");
        } catch (Throwable $e) { $err = 'Could not connect: ' . preg_replace('/\s+/', ' ', $e->getMessage()); }
    }
    if ($a === 'adduser' && $isOwner && $pdo) {
        $name = one_line((string)$_POST['name']); $email = strtolower(one_line((string)$_POST['email'])); $pw = (string)$_POST['pw'];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please add a name and a valid email.';
        elseif (strlen($pw) < 10) $err = 'The password needs at least 10 characters.';
        elseif (user_by_email($email)) $err = 'There is already an account with that email.';
        else { user_add($email, $name, 'member', $pw); go('team', "$name can now log in with $email."); }
    }
    if ($a === 'deluser' && $isOwner && $pdo) {
        $id = (int)$_POST['id'];
        if ($id === (int)$me['id']) go('team', "You can't remove your own account.", true);
        $pdo->prepare("DELETE FROM users WHERE id=? AND role<>'owner'")->execute([$id]); go('team', 'Account removed.');
    }
    if ($a === 'password') {
        if (!empty($me['legacy'])) {
            if (!password_verify((string)$_POST['old'], $legacy['hash'])) $err = "Your current password isn't right.";
            elseif (strlen((string)$_POST['new']) < 10) $err = 'The new password needs at least 10 characters.';
            else { $legacy['hash'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT); write_json('admin.json', $legacy); go('settings', 'Password changed.'); }
        } else {
            $u = user_by_id((int)$me['id']);
            if (!password_verify((string)$_POST['old'], $u['password_hash'])) $err = "Your current password isn't right.";
            elseif (strlen((string)$_POST['new']) < 10) $err = 'The new password needs at least 10 characters.';
            else { $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $u['id']]); go('settings', 'Password changed.'); }
        }
    }
}

/* ---------- data ---------- */
$bookings = bookings_all();
$upcoming = array_values(array_filter($bookings, fn($b) => $b['status'] !== 'cancelled' && $b['start_ts'] > time() - 1800));
$past = array_reverse(array_values(array_filter($bookings, fn($b) => !($b['status'] !== 'cancelled' && $b['start_ts'] > time() - 1800))));
$messages = messages_all();
$open = array_values(array_filter($messages, fn($m) => !$m['handled']));
$views = views_all();
$week = array_filter($views, fn($v) => strtotime($v['at']) > time() - 7 * 86400);
$bySlug = [];
foreach ($views as $v) { $s = $v['slug']; $bySlug[$s] ??= ['n' => 0, 'last' => null, 'device' => '', 'from' => '']; $bySlug[$s]['n']++; if (!$bySlug[$s]['last'] || strtotime($v['at']) > strtotime($bySlug[$s]['last'])) { $bySlug[$s]['last'] = $v['at']; $bySlug[$s]['device'] = $v['device']; } if (!$bySlug[$s]['from'] && str_starts_with((string)$v['ref'], 'via ')) $bySlug[$s]['from'] = substr($v['ref'], 4); }
uasort($bySlug, fn($x, $y) => strtotime((string)$y['last']) <=> strtotime((string)$x['last']));
$cfg = config();
$typeLabel = ['booking' => 'Call request', 'question' => 'Question', 'concept' => 'Free concept request'];
$howLabel = ['Video call (Google Meet)' => 'Video call', 'WhatsApp call' => 'WhatsApp call', 'Phone call' => 'Phone call'];

/* ---------- pieces ---------- */
function booking_row(array $b, array $howLabel, bool $full = false): string {
    $past = $b['start_ts'] < time() - 1800; $cancelled = $b['status'] === 'cancelled';
    $video = $b['how'] === 'Video call (Google Meet)';
    $out = '<div class="row"><div class="date"><b>' . h(london($b['start_ts'], 'j')) . '</b><span>' . h(london($b['start_ts'], 'M')) . '</span></div>'
        . '<div class="grow"><b>' . h($b['business']) . '</b> · ' . h($b['name'])
        . '<small>' . h(london($b['start_ts'], 'D H:i')) . ' London · ' . h($howLabel[$b['how']] ?? $b['how']) . ' · ' . h($b['email']) . ($b['phone'] ? ' · ' . h($b['phone']) : '') . ($b['slug'] ? ' · <a href="/' . h($b['slug']) . '/?team=1" target="_blank" rel="noopener">Concept</a>' : '') . '</small>';
    if (!$past && !$cancelled) {
        if ($b['meet']) {
            $out .= '<div class="meet ok">✓ Meet link ' . (!empty($b['meet_sent_at']) ? 'sent' : 'set') . ': <a href="' . h($b['meet']) . '" target="_blank" rel="noopener">' . h(preg_replace('#^https://#', '', $b['meet'])) . '</a></div>';
        } elseif ($full || $video) {
            $out .= '<form class="meet" method="post">' . csrf_field() . '<input type="hidden" name="a" value="meet"><input type="hidden" name="id" value="' . h($b['id']) . '">'
                . '<a class="btn line sm" href="https://meet.google.com/new" target="_blank" rel="noopener">1 · Create Meet link</a>'
                . '<label class="sr"><span class="vh">Meet link</span><input name="meet" type="url" placeholder="2 · Paste it here: https://meet.google.com/…" required></label>'
                . '<button class="btn sm">3 · Send to client</button></form>';
        }
    }
    $out .= '</div>';
    if ($cancelled) $out .= '<span class="pill grey">Cancelled</span>';
    elseif ($past) $out .= '<span class="pill grey">Past</span>';
    else $out .= '<form method="post" onsubmit="return confirm(\'Cancel this call?\')">' . csrf_field() . '<input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="' . h($b['id']) . '"><button class="btn line sm">Cancel</button></form>';
    return $out . '</div>';
}
function msg_row(array $m, array $typeLabel): string {
    return '<div class="row' . ($m['handled'] ? ' muted' : '') . '"><div class="grow"><span class="pill">' . h($typeLabel[$m['type']] ?? $m['type']) . '</span> <b>' . h($m['business']) . '</b> · ' . h($m['name'])
        . '<small>' . h($m['email']) . (!empty($m['phone']) ? ' · ' . h($m['phone']) : '') . (!empty($m['site']) ? ' · ' . h($m['site']) : '') . (!empty($m['slug']) ? ' · <a href="/' . h($m['slug']) . '/?team=1" target="_blank" rel="noopener">Concept</a>' : '') . '</small>'
        . (!empty($m['slot']) ? '<small>Asked for: ' . h($m['slot']) . '</small>' : '')
        . (!empty($m['message']) ? '<p class="msg">' . nl2br(h($m['message'])) . '</p>' : '')
        . '<small>' . h(ago($m['at'])) . (!empty($m['file']) ? ' · file attached in the email' : '') . '</small></div>'
        . '<div class="acts"><a class="btn line sm" href="mailto:' . h($m['email']) . '">Reply</a><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="' . ($m['handled'] ? 'undone' : 'done') . '"><input type="hidden" name="k" value="' . h($m['id']) . '"><button class="btn ' . ($m['handled'] ? 'line ' : '') . 'sm">' . ($m['handled'] ? 'Reopen' : 'Done') . '</button></form></div></div>';
}

/* ---------- pages ---------- */
$nav = [['home', 'Overview'], ['outreach', 'Outreach'], ['calls', 'Calls'], ['messages', 'Messages'], ['views', 'Concept views']];
if ($pdo && $isOwner) $nav[] = ['team', 'Team'];
$nav[] = ['settings', 'Settings'];

ob_start();
if (!$pdo && $isOwner && $page !== 'settings') echo '<div class="banner"><div><b>Connect the database</b><br><span>Calls and messages are saved in files for now. Connect MySQL to get personal logins for you and your team.</span></div><a class="btn" href="?p=settings#db">Connect</a></div>';

if ($page === 'home') {
    echo '<h1>Hello' . (!empty($me['name']) && $me['name'] !== 'Team' ? ', ' . h(explode(' ', $me['name'])[0]) : '') . ' 👋</h1><p class="sub">Here\'s where Sites By MS stands today.</p>';
    echo '<div class="stats"><a href="?p=calls" class="stat"><b>' . count($upcoming) . '</b><span>Upcoming calls</span></a><a href="?p=messages" class="stat"><b>' . count($open) . '</b><span>Unanswered messages</span></a><a href="?p=views" class="stat"><b>' . count($week) . '</b><span>Concept views, last 7 days</span></a><a href="?p=views" class="stat"><b>' . count($bySlug) . '</b><span>Concepts opened</span></a></div>';
    echo '<div class="g2"><section class="card"><div class="ch"><h2>Next calls</h2><a href="?p=calls">All calls</a></div>' . ($upcoming ? implode('', array_map(fn($b) => booking_row($b, $howLabel), array_slice($upcoming, 0, 4))) : '<div class="empty">No calls booked yet.</div>') . '</section>';
    echo '<section class="card"><div class="ch"><h2>Latest concept views</h2><a href="?p=views">All views</a></div>';
    if ($views) foreach (array_slice($views, 0, 6) as $v) echo '<div class="row"><div class="grow"><b>' . h($v['slug']) . '</b><small>' . h(ago($v['at'])) . ' · ' . h($v['device']) . (str_starts_with((string)$v['ref'], 'via ') ? ' · from ' . h(substr($v['ref'], 4) === 'ig' ? 'Instagram DM' : substr($v['ref'], 4)) : '') . '</small></div><a class="btn line sm" href="/' . h($v['slug']) . '/?team=1" target="_blank" rel="noopener">Open</a></div>';
    else echo '<div class="empty">No one has opened a concept yet. When a client opens their link, you\'ll see it here and get an email.</div>';
    echo '</section></div>';
    echo '<section class="card"><div class="ch"><h2>Unanswered messages</h2><a href="?p=messages">All messages</a></div>' . ($open ? implode('', array_map(fn($m) => msg_row($m, $typeLabel), array_slice($open, 0, 3))) : '<div class="empty">All caught up.</div>') . '</section>';
    echo '<section class="card"><div class="ch"><h2>Outreach</h2><a href="?p=outreach">Open</a></div><p class="hint">Review the nightly messages and send them from here. The sheet updates itself.</p></section>';
    echo '<section class="card"><h2>Useful links</h2><div class="links"><a class="btn line" href="https://docs.google.com/spreadsheets/d/1NZFcaeoG7fR8--nepcmujsFJAZzfb4r1sCh-_3Lbs-s/edit" target="_blank" rel="noopener">Leads sheet</a><a class="btn line" href="/demo/" target="_blank">Client portal demo</a><a class="btn line" href="/" target="_blank">Website</a></div></section>';
}
if ($page === 'outreach') {
    echo '<h1>Outreach</h1><p class="sub">Check each message, edit it if you like, and send. Emails go from hello@sitesbyms.com with a tracked link, and the leads sheet updates itself.</p>';
    $rows = null; $gerr = '';
    if (!google_key()) $gerr = 'setup';
    else { try { $rows = sheet_rows(); } catch (Throwable $e) { $gerr = $e->getMessage(); } }
    if ($gerr === 'setup') echo '<section class="card"><h2>Connect the leads sheet first</h2><p>The panel needs a Google key to read and update the sheet. It takes about 10 minutes, once.</p><a class="btn" href="?p=settings#google">Set it up</a></section>';
    elseif ($gerr) echo '<section class="card"><h2>Could not read the sheet</h2><p class="err">' . h($gerr) . '</p><a class="btn line" href="?p=settings#google">Check the Google settings</a></section>';
    else {
        $seen = [];
        foreach ($views as $v) { $sl = $v['slug']; if (!isset($seen[$sl])) $seen[$sl] = ['at' => $v['at'], 'from' => str_starts_with((string)$v['ref'], 'via ') ? substr($v['ref'], 4) : '', 'n' => 0]; $seen[$sl]['n']++; }
        $tz = new DateTimeZone(TZ); $todayD = new DateTime('today', $tz);
        $parseDate = function (string $d) use ($tz): ?DateTime {
            if ($d === '') return null;
            foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'j M Y', 'd.m.Y'] as $f) { $x = DateTime::createFromFormat('!' . $f, $d, $tz); if ($x) return $x; }
            $t = strtotime($d); return $t ? (new DateTime('@' . $t))->setTimezone($tz) : null;
        };
        $first = array_values(array_filter($rows, fn($r) => strcasecmp($r['status'], 'Review') === 0));
        $follow = array_values(array_filter($rows, function ($r) use ($parseDate, $todayD) { if (strcasecmp($r['status'], 'Sent') !== 0 || $r['reply'] !== '') return false; $d = $parseDate($r['followup_date']); return $d && $d <= $todayD; }));
        echo '<div class="tabs2"><a href="#ready">Ready to send <i>' . count($first) . '</i></a><a href="#followups">Follow-ups due <i>' . count($follow) . '</i></a><a href="#sent">Sent</a></div>';
        $card = function (array $r, string $kind, int $i) use ($seen) {
            $isMail = stripos($r['channel'], 'mail') !== false && $r['email'] !== '';
            $slug = $r['slug']; $sv = $seen[$slug] ?? null;
            $text = $kind === 'first' ? $r['message'] : $r['followup'];
            $text = tracked_links($text, $isMail ? 'email' : 'ig');
            $ig = preg_replace('#^@|^https?://(www\.)?instagram\.com/#', '', rtrim($r['instagram'], '/'));
            $o = '<details class="card lead"' . ($i === 0 ? ' open' : '') . '><summary><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($r['location']) . '</small></div>'
                . ($sv ? '<span class="pill ok">Opened ' . h(ago($sv['at'])) . '</span>' : ($kind === 'followup' ? '<span class="pill grey">Not opened yet</span>' : ''))
                . '<span class="pill' . ($isMail ? '' : ' grey') . '">' . ($isMail ? 'Email' : 'Instagram DM') . '</span></summary>'
                . '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="row" value="' . (int)$r['_row'] . '"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="business" value="' . h($r['company']) . '"><input type="hidden" name="slug" value="' . h($slug) . '">';
            if ($isMail) $o .= '<div class="f2x"><label>To<input type="email" name="to" value="' . h($r['email']) . '" required></label><label>Subject<input name="subject" value="' . h($kind === 'followup' ? 'Re: ' . $r['subject'] : $r['subject']) . '" required></label></div>';
            else $o .= '<input type="hidden" name="to" value="' . h($ig) . '"><p class="hint">Send this in Instagram, then press Mark as sent. ' . ($ig ? 'Account: <a href="https://instagram.com/' . h($ig) . '" target="_blank" rel="noopener">@' . h($ig) . '</a>' : 'No Instagram handle in the sheet.') . '</p>';
            $o .= '<label>' . ($kind === 'first' ? 'Message' : 'Follow-up') . '<textarea name="body" rows="' . max(6, min(18, substr_count($text, "\n") + 3)) . '">' . h($text) . '</textarea></label>'
                . ($r['issues'] ? '<p class="hint"><b>Issues we found:</b> ' . nl2br(h($r['issues'])) . '</p>' : '')
                . '<div class="acts">'
                . ($slug ? '<a class="btn line sm" href="/' . h($slug) . '/?team=1" target="_blank" rel="noopener">Open concept</a>' : '')
                . ($isMail ? '<button class="btn sm" name="a" value="osend" onclick="return confirm(\'Send this email to ' . h($r['email']) . '?\')">' . ($kind === 'first' ? 'Send email' : 'Send follow-up') . '</button>'
                           : '<button type="button" class="btn line sm" data-copy>Copy message</button>' . ($ig ? '<a class="btn line sm" href="https://ig.me/m/' . h($ig) . '" target="_blank" rel="noopener">Open Instagram chat</a>' : '') . '<button class="btn sm" name="a" value="omark">Mark as sent</button>')
                . ($kind === 'first' ? '<button class="btn line sm subtle" name="a" value="oskip" onclick="return confirm(\'Move ' . h($r['company']) . ' to Skip?\')">Skip lead</button>' : '')
                . '</div></form></details>';
            return $o;
        };
        echo '<h2 id="ready" class="sec-h">Ready to send</h2>';
        echo $first ? implode('', array_map($card, $first, array_fill(0, count($first), 'first'), array_keys($first))) : '<div class="card"><div class="empty">Nothing waiting. New messages appear here after the nightly run.</div></div>';
        echo '<h2 id="followups" class="sec-h">Follow-ups due</h2>';
        echo $follow ? implode('', array_map($card, $follow, array_fill(0, count($follow), 'followup'), array_keys($follow))) : '<div class="card"><div class="empty">No follow-ups due today.</div></div>';
        echo '<h2 id="sent" class="sec-h">Sent from the panel</h2><section class="card">';
        $log = outreach_all();
        if ($log) foreach (array_slice($log, 0, 40) as $l) { $sv = $seen[$l['slug']] ?? null;
            echo '<div class="row"><div class="grow"><b>' . h($l['business']) . '</b> · ' . h($l['kind'] === 'first' ? 'first message' : 'follow-up') . '<small>' . h($l['channel']) . ($l['to'] ? ' · ' . h($l['to']) : '') . ' · ' . h(ago($l['at'] ?? $l['sent_at'] ?? null)) . ($l['sent_by'] ?? '' ? ' · by ' . h($l['sent_by'] ?? '') : '') . '</small></div>'
                . ($sv && strtotime($sv['at']) >= strtotime((string)($l['at'] ?? '')) ? '<span class="pill ok">Opened ' . h(ago($sv['at'])) . ($sv['from'] ? ' · ' . h($sv['from'] === 'ig' ? 'Instagram' : $sv['from']) : '') . '</span>' : '<span class="pill grey">Not opened yet</span>') . '</div>'; }
        else echo '<div class="empty">Nothing sent from the panel yet.</div>';
        echo '</section>';
        echo '<script>document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){var t=b.closest("form").querySelector("textarea");var done=function(){b.textContent="Copied ✓";setTimeout(function(){b.textContent="Copy message"},2000)};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done,function(){t.select();document.execCommand("copy");done()})}else{t.select();document.execCommand("copy");done()}})})</script>';
    }
}
if ($page === 'calls') {
    echo '<h1>Calls</h1><p class="sub">Every call clients have booked. Times are London time. For video calls: create a Meet link, paste it, and we email it to the client with an updated calendar invite.</p>';
    echo '<section class="card"><h2>Upcoming</h2>' . ($upcoming ? implode('', array_map(fn($b) => booking_row($b, $howLabel, true), $upcoming)) : '<div class="empty">No upcoming calls.</div>') . '</section>';
    if ($past) echo '<section class="card"><h2>Past and cancelled</h2>' . implode('', array_map(fn($b) => booking_row($b, $howLabel), array_slice($past, 0, 40))) . '</section>';
}
if ($page === 'messages') {
    echo '<h1>Messages</h1><p class="sub">Questions and free concept requests from the website forms.</p>';
    echo '<section class="card">' . ($messages ? implode('', array_map(fn($m) => msg_row($m, $typeLabel), $messages)) : '<div class="empty">No messages yet.</div>') . '</section>';
}
if ($page === 'views') {
    echo '<h1>Concept views</h1><p class="sub">Who has opened their concept. The first few hours are the best time to follow up.</p><section class="card">';
    if ($bySlug) foreach ($bySlug as $s => $v) echo '<div class="row"><div class="grow"><b>' . h($s) . '</b><small>Last opened ' . h(ago($v['last'])) . ' · ' . h($v['device']) . ($v['from'] ? ' · clicked from ' . h($v['from'] === 'ig' ? 'Instagram DM' : $v['from']) : '') . '</small></div><span class="pill">' . $v['n'] . ($v['n'] === 1 ? ' view' : ' views') . '</span><a class="btn line sm" href="/' . h($s) . '/?team=1" target="_blank" rel="noopener">Open</a></div>';
    else echo '<div class="empty">No views recorded yet.</div>';
    echo '</section><p class="sub">Your own visits are not counted once you have opened any concept with ?team=1 on that device.</p>';
}
if ($page === 'team' && $pdo && $isOwner) {
    echo '<h1>Team</h1><p class="sub">Everyone who can log in to this panel. Members can do everything except manage the team and the database.</p><section class="card">';
    foreach (users_all() as $u) echo '<div class="row"><div class="ava">' . h(mb_substr($u['name'], 0, 1)) . '</div><div class="grow"><b>' . h($u['name']) . '</b><small>' . h($u['email']) . ' · ' . ($u['last_login'] ? 'last login ' . h(ago(iso($u['last_login']))) : 'never logged in') . '</small></div><span class="pill' . ($u['role'] === 'owner' ? '' : ' grey') . '">' . h(ucfirst($u['role'])) . '</span>'
        . ($u['role'] !== 'owner' ? '<form method="post" onsubmit="return confirm(\'Remove this account?\')">' . csrf_field() . '<input type="hidden" name="a" value="deluser"><input type="hidden" name="id" value="' . (int)$u['id'] . '"><button class="btn line sm">Remove</button></form>' : '') . '</div>';
    echo '</section><form class="card" method="post">' . csrf_field() . '<input type="hidden" name="a" value="adduser"><h2>Add a team member</h2><div class="f3"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Temporary password <span class="hint">10+ characters</span><input name="pw" minlength="10" required autocomplete="off"></label></div><p class="hint">Share the password with them privately. They can change it in Settings after logging in.</p><button class="btn">Add member</button></form>';
}
if ($page === 'settings') {
    $wd = $cfg['weekdays']; $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $ical = $cfg['ical_url'] ? count(ical_busy()) : null;
    echo '<h1>Settings</h1>';
    if ($isOwner) {
        echo '<section class="card" id="db"><h2>Database</h2>';
        if ($pdo) echo '<p>✓ Connected to MySQL database <b>' . h(db_conf()['name']) . '</b>. Calls, messages, views and accounts are saved there.</p>';
        else echo '<p class="hint">Create the database first in cPanel › MySQL® Database Wizard (database <b>portal</b>, user <b>app</b>, all privileges). Then enter the details here. They are saved on the server outside the website folder.</p>'
            . '<form method="post" class="stack">' . csrf_field() . '<input type="hidden" name="a" value="db"><div class="f3"><label>Database name<input name="name" placeholder="sitesb_portal" required autocomplete="off"></label><label>Database user<input name="user" placeholder="sitesb_app" required autocomplete="off"></label><label>Password<input type="password" name="pass" required autocomplete="new-password"></label></div>'
            . '<label>Host <span class="hint">almost always localhost</span><input name="host" value="localhost"></label><button class="btn">Connect and set up</button></form>';
        echo '</section>';
    }
    $gk = google_key();
    echo '<section class="card" id="google"><h2>Leads sheet (Google)</h2>';
    if ($gk) echo '<p>✓ Google key saved for <b>' . h($gk['client_email']) . '</b>. The leads sheet must be shared with this address as <b>Editor</b>.</p><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="gtest"><button class="btn line">Test connection</button></form>';
    else echo '<p class="hint">Lets the Outreach page read the leads sheet and mark rows as Sent. One-time setup:</p><ol class="steps"><li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud</a> with the m.and.s.design.develop account and create a project called <b>sitesbyms</b>.</li><li>Turn on the <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener">Google Sheets API</a> for that project.</li><li>Go to <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service accounts</a> › Create service account › name <b>panel</b> › Done.</li><li>Open it › Keys › Add key › Create new key › <b>JSON</b>. A file downloads.</li><li>Upload that file below.</li><li>In the leads sheet, press Share and add the service account email (shown here after upload) as <b>Editor</b>.</li></ol>';
    if ($isOwner) echo '<form method="post" enctype="multipart/form-data" class="stack">' . csrf_field() . '<input type="hidden" name="a" value="gkey"><label>' . ($gk ? 'Replace the key file' : 'Key file (.json)') . '<input type="file" name="keyfile" accept=".json,application/json"></label><button class="btn' . ($gk ? ' line' : '') . '">Upload key</button></form>';
    echo '</section>';
    echo '<form class="card" method="post">' . csrf_field() . '<input type="hidden" name="a" value="settings"><h2>Calendar &amp; booking</h2>'
        . '<label>Google Calendar secret address (iCal)<input name="ical_url" value="' . h($cfg['ical_url']) . '" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"><span class="hint">With this, times that are busy in your Google Calendar show as Taken on the booking page.' . ($ical !== null ? ' ' . $ical . ' events read from the calendar just now.' : '') . '</span></label>'
        . '<div class="f3"><label>First bookable day<select name="days_ahead">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n == $cfg['days_ahead'] ? ' selected' : '') . '>' . $n . ($n === 1 ? ' day' : ' days') . ' from today</option>', range(1, 7))) . '</select></label>'
        . '<label>Day starts (London)<input name="start" type="time" value="' . h($cfg['start']) . '"></label><label>Day ends (London)<input name="end" type="time" value="' . h($cfg['end']) . '"></label></div>'
        . '<fieldset><legend>Working days</legend><div class="wd">' . implode('', array_map(fn($n) => '<label class="chk"><input type="checkbox" name="wd[]" value="' . $n . '"' . (in_array($n, $wd, true) ? ' checked' : '') . '>' . $days[$n] . '</label>', array_keys($days))) . '</div></fieldset>'
        . '<button class="btn">Save settings</button></form>';
    echo '<form class="card" method="post">' . csrf_field() . '<input type="hidden" name="a" value="password"><h2>Change ' . (!empty($me['legacy']) ? 'panel' : 'your') . ' password</h2><div class="f3"><label>Current password<input type="password" name="old" autocomplete="current-password"></label><label>New password<input type="password" name="new" autocomplete="new-password" minlength="10"></label></div><button class="btn line">Change password</button></form>';
}
$body = ob_get_clean();
$flash = $_SESSION['flash'] ?? ''; $flashBad = !empty($_SESSION['flash_bad']); unset($_SESSION['flash'], $_SESSION['flash_bad']);
layout($page, $nav, $body, $err ?: $flash, $err ? true : $flashBad, count($open), $me);

/* ---------- layout ---------- */
function head(string $title): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' · Sites By MS team panel</title>'
        . '<link rel="icon" href="/assets/brand/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/admin/admin.css?v=3"></head><body>';
}
function auth_page(string $title, string $inner): void {
    echo head($title) . '<main class="auth"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS" class="logo"><div class="box"><h1>' . h($title) . '</h1>' . $inner . '</div></main></body></html>';
    exit;
}
function layout(string $page, array $nav, string $body, string $msg, bool $isErr, int $openCount, array $me): void {
    echo head('Team panel') . '<header class="top"><a class="brand" href="/admin/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span><em>Team panel</em></a>'
        . '<nav aria-label="Panel">' . implode('', array_map(fn($n) => '<a href="?p=' . $n[0] . '"' . ($page === $n[0] ? ' aria-current="page"' : '') . '>' . $n[1] . ($n[0] === 'messages' && $openCount ? ' <i>' . $openCount . '</i>' : '') . '</a>', $nav))
        . '<a href="?p=logout" class="out">Log out' . (!empty($me['email']) ? ' <small>' . h($me['email']) . '</small>' : '') . '</a></nav></header><main class="wrap">'
        . ($msg ? '<div class="note' . ($isErr ? ' bad' : '') . '" role="status">' . h($msg) . '</div>' : '') . $body . '</main></body></html>';
}
