<?php
// Sites By MS — team panel.
// Before MySQL is connected: one shared panel password (admin.json).
// After: personal accounts (email + password). The first account is the owner.
declare(strict_types=1);
require __DIR__ . '/../api/_lib.php';
require __DIR__ . '/../api/_google.php';
require __DIR__ . '/../api/_autosend.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('sbm_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');   // concept pages see the panel as referrer and skip counting
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
$GLOBALS['sbm_actor'] = (string)($me['email'] ?? '') ?: 'team';   // who changed a lead (for the change history)

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

/* ---------- download: the whole leads list as CSV (opens in Excel / Google Sheets) ---------- */
if ($page === 'leadscsv' && leads_ready()) {
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="sitesbyms-leads-' . gmdate('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF" . leads_csv(sheet_rows(true)); exit;
}

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
    if ($a === 'autosend' && leads_ready()) {
        try { $kv = panel_settings() + AUTO_DEFAULTS; } catch (Throwable $e) { $kv = PANEL_DEFAULTS + AUTO_DEFAULTS; }
        $kv['Auto-send emails'] = ($_POST['on'] ?? '') === 'Yes' ? 'Yes' : 'No';
        $kv['Auto-send time (London)'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['time'] ?? '')) ? (string)$_POST['time'] : '08:00';
        $kv['Max automatic emails per day'] = (string)max(0, min(100, (int)($_POST['max'] ?? 40)));
        try { panel_settings_save($kv); go('settings', 'Automatic email settings saved.'); } catch (Throwable $e) { go('settings', 'Could not save: ' . $e->getMessage(), true); }
    }
    if ($a === 'nightly' && leads_ready()) {
        try { $kv = panel_settings() + PANEL_DEFAULTS; } catch (Throwable $e) { $kv = PANEL_DEFAULTS; }
        $kv['New concepts per night'] = (string)max(0, min(20, (int)($_POST['n_concepts'] ?? 5)));
        $kv['If nothing is Ready, build the best new candidates'] = ($_POST['auto'] ?? '') === 'Yes' ? 'Yes' : 'No';
        $kv['New candidates per night'] = (string)max(0, min(40, (int)($_POST['n_cand'] ?? 20)));
        try { panel_settings_save($kv); go('settings', 'Nightly settings saved. The next run uses them.'); } catch (Throwable $e) { go('settings', 'Could not save: ' . $e->getMessage(), true); }
    }
    if ($a === 'pe_send' || $a === 'pe_skip') {
        try {
            $q = (array)setting_get('personal_emails', []); $id = (string)$_POST['id']; $it = null;
            foreach ($q as $x) if ($x['id'] === $id) $it = $x;
            if (!$it) go('outreach', 'That email is no longer in the list.', true);
            $rest = array_values(array_filter($q, fn($x) => $x['id'] !== $id));
            if ($a === 'pe_skip') { setting_set('personal_emails', $rest); go('outreach', 'Email to ' . $it['company'] . ' skipped.'); }
            $to = strtolower(one_line((string)$_POST['to'])); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) go('outreach', 'Please check the email address.', true);
            $subject = one_line((string)$_POST['subject']); $body = trim(str_replace("\r", '', (string)$_POST['body']));
            if ($subject === '' || $body === '') go('outreach', 'Subject and message are needed.', true);
            $prev = null; foreach (outreach_all() as $o) if ((int)$o['row'] === (int)$it['row'] && !empty($o['mid']) && $o['channel'] === 'Email') { $prev = $o['mid']; break; }   // reply in their latest thread
            $mid = send_outreach($to, $subject, $body, $prev, (string)$it['slug'], 'personal');
            if (!$mid) go('outreach', 'The email could not be sent. Nothing was changed.', true);
            outreach_log(['row' => (int)$it['row'], 'slug' => (string)$it['slug'], 'business' => $it['company'], 'kind' => 'personal', 'channel' => 'Email', 'to' => $to, 'subject' => $subject, 'body' => $body, 'mid' => $mid, 'by' => (string)($me['email'] ?? 'team')]);
            setting_set('personal_emails', $rest);
            try { $l = lead_get((int)$it['row'], $it['company']); $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d'); sheet_update_row((int)$it['row'], ['notes' => trim($l['notes'] . " | Personal email sent $today: $subject")]); } catch (Throwable $e) {}
            go('outreach', 'Email sent to ' . $it['company'] . ' from hello@sitesbyms.com.');
        } catch (Throwable $e) { go('outreach', $e->getMessage(), true); }
    }
    if ($a === 'rep_skip') {
        try {
            $row = (int)$_POST['row']; $lead = lead_get($row, (string)$_POST['orig']);
            $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d');
            sheet_update_row($row, ['stage' => "Report skipped $today"]);
            go('outreach', 'Competitive analysis for ' . $lead['company'] . ' skipped.');
        } catch (Throwable $e) { go('outreach', $e->getMessage(), true); }
    }
    if ($a === 'rep_send' || $a === 'rep_mark') {
        try {
            $row = (int)$_POST['row']; $lead = lead_get($row, (string)$_POST['orig']); $tok = report_token($lead);
            if (!$tok) go('outreach', 'This report is not ready yet.', true);
            $body = trim(str_replace("\r", '', (string)$_POST['body'])); if ($body === '') go('outreach', 'The message is empty.', true);
            $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d'); $by = (string)($me['email'] ?? 'team');
            if ($a === 'rep_send') {
                $to = strtolower(one_line((string)$_POST['to'])); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) go('outreach', 'Please check the email address.', true);
                $mid = send_outreach($to, 'Your free competitive analysis: ' . $lead['company'], $body, null, $lead['slug'], 'report');
                if (!$mid) go('outreach', 'The email could not be sent. Nothing was changed.', true);
                $ch = 'Email';
            } else { $to = one_line((string)($_POST['to'] ?? '')); $mid = null; $ch = one_line((string)($_POST['ch'] ?? 'Instagram DM')); }
            outreach_log(['row' => $row, 'slug' => $lead['slug'], 'business' => $lead['company'], 'kind' => 'report', 'channel' => $ch, 'to' => $to, 'subject' => '', 'body' => $body, 'mid' => $mid, 'by' => $by]);
            sheet_update_row($row, ['stage' => "Report sent $today · $tok"]);
            go('outreach', 'Report sent to ' . $lead['company'] . '. You get an email when they open it.');
        } catch (Throwable $e) { go('outreach', $e->getMessage(), true); }
    }
    if (in_array($a, ['lead_save', 'lead_status', 'lead_add'], true)) {
        $editable = ['company', 'country', 'location', 'site', 'email', 'phone', 'instagram', 'owner', 'notes', 'status', 'language', 'issues', 'preview', 'channel', 'subject', 'message', 'followup', 'sent', 'reply', 'slug', 'stage', 'payment', 'ig_message', 'ig_followup'];
        $clean = function (string $k, $v): string { $v = str_replace("\r", '', (string)$v); return in_array($k, ['notes', 'issues', 'message', 'followup', 'ig_message', 'ig_followup', 'reply'], true) ? trim($v) : one_line($v); };
        try {
            if ($a === 'lead_add') {
                $cells = []; foreach ($editable as $k) if (isset($_POST['f'][$k])) $cells[$k] = $clean($k, $_POST['f'][$k]);
                if (($cells['company'] ?? '') === '') go('leads', 'Please add the business name.', true);
                foreach (sheet_rows(true) as $r) if (strcasecmp($r['company'], $cells['company']) === 0 && strcasecmp($r['location'], $cells['location'] ?? '') === 0) go('leads', $cells['company'] . ' is already in the sheet (row ' . $r['_row'] . ').', true);
                $cells['status'] = in_array($cells['status'] ?? '', LEAD_STATUSES, true) ? $cells['status'] : 'Candidate';
                $row = sheet_append_lead($cells);
                go('lead&r=' . $row, $cells['company'] . ' added to the sheet (row ' . $row . ').');
            }
            $row = (int)$_POST['row']; $lead = lead_get($row, (string)$_POST['orig']);
            if ($a === 'lead_status') {
                $st = (string)$_POST['status']; if (!in_array($st, LEAD_STATUSES, true)) go('leads', 'Unknown status.', true);
                sheet_update_row($row, ['status' => $st]);
                $back = preg_match('/^[a-z0-9&=_%+.-]{1,120}$/i', (string)($_POST['back'] ?? '')) ? (string)$_POST['back'] : 'leads';
                go($back, $lead['company'] . " moved to $st.");
            }
            $diff = [];
            foreach ($editable as $k) if (isset($_POST['f'][$k])) { $v = $clean($k, $_POST['f'][$k]); if ($v !== $lead[$k]) $diff[$k] = $v; }
            if (isset($diff['status']) && !in_array($diff['status'], LEAD_STATUSES, true)) unset($diff['status']);
            if ($diff) sheet_update_row($row, $diff);
            go('lead&r=' . $row, $diff ? 'Saved to the sheet (' . count($diff) . ' ' . (count($diff) === 1 ? 'field' : 'fields') . ').' : 'Nothing changed.');
        } catch (Throwable $e) { go('leads', $e->getMessage(), true); }
    }
    if ($a === 'oseen' || $a === 'ounseen') {
        $slug = preg_replace('/[^a-z0-9-]/', '', (string)$_POST['slug']);
        if ($slug !== '' && $a === 'oseen') seen_add($slug, 'first', 'Instagram DM', 'manual');
        if ($slug !== '' && $a === 'ounseen') { if ($pdo) { seen_migrate($pdo); $pdo->prepare("DELETE FROM message_seen WHERE slug=? AND how='manual'")->execute([$slug]); } }
        go('results', $a === 'oseen' ? 'Marked as seen.' : 'Seen mark removed.');
    }
    if (in_array($a, ['owa', 'onowa', 'owarm', 'owaskip', 'owarmskip'], true)) {   // WhatsApp step and warm leads: the team sends in WhatsApp, the panel records it
        $row = (int)$_POST['row']; $by = (string)($me['email'] ?? 'team');
        try {
            $lead = null; foreach (sheet_rows() as $x) if ($x['_row'] === $row) { $lead = $x; break; }
            if (!$lead) go('outreach', 'Could not find that business in the sheet any more.', true);
            $body = str_replace("\r", '', (string)($_POST['body'] ?? ''));
            $kind = ['owa' => 'wa', 'onowa' => 'wa-none', 'owarm' => 'warm', 'owaskip' => 'wa-skip', 'owarmskip' => 'warm'][$a];
            if ($a === 'owaskip' || $a === 'owarmskip') $body = '(skipped)';
            outreach_log(['row' => $row, 'slug' => $lead['slug'], 'business' => $lead['company'], 'kind' => $kind, 'channel' => 'WhatsApp', 'to' => $lead['phone'], 'subject' => '', 'body' => $a === 'onowa' ? '(no WhatsApp)' : $body, 'mid' => null, 'by' => $by]);
            if ($a === 'owa' && strcasecmp($lead['status'], 'Review') === 0) {   // WhatsApp was the first touch
                $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d');
                sheet_update_row($row, ['status' => 'Sent', 'sent' => $today, 'message' => $body]);
            }
            go('outreach', ['owa' => 'WhatsApp to ' . $lead['company'] . ' marked as sent.', 'onowa' => $lead['company'] . ': noted, this number has no WhatsApp.', 'owarm' => $lead['company'] . ' marked as contacted.', 'owaskip' => 'WhatsApp for ' . $lead['company'] . ' skipped.', 'owarmskip' => $lead['company'] . ' skipped (no personal contact).'][$a]);
        } catch (Throwable $e) { go('outreach', 'Sheet error: ' . $e->getMessage(), true); }
    }
    if (in_array($a, ['osend', 'omark', 'oskip', 'oskipch'], true)) {
        $row = (int)$_POST['row']; $kind = isset(FOLLOWUP_KINDS[$_POST['kind'] ?? '']) ? (string)$_POST['kind'] : 'first';
        $business = one_line((string)$_POST['business']); $slug = preg_replace('/[^a-z0-9-]/', '', (string)$_POST['slug']);
        $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d');
        $by = (string)($me['email'] ?? 'team');
        try {
            if ($a === 'oskip') { sheet_update_row($row, ['status' => 'Skip']); go('outreach', "$business moved to Skip. Nothing more will be sent to them."); }
            $lead = null; foreach (sheet_rows() as $x) if ($x['_row'] === $row) { $lead = $x; break; }
            if (!$lead) go('outreach', "Could not find $business in the sheet any more. Nothing was sent.", true);
            if ($a !== 'oskip' && ($why = lead_mismatch($lead))) go('outreach', "Not sent: $why", true);
            $slug = $lead['slug'];
            $channels = lead_channels($lead); $both = count($channels) > 1;
            $body = str_replace("\r", '', (string)($_POST['body'] ?? ''));
            if ($a !== 'oskipch' && trim($body) === '') go('outreach', 'The message is empty.', true);
            $cells = []; $did = '';
            if ($a === 'osend') {
                $to = strtolower(one_line((string)$_POST['to'])); $subject = one_line((string)$_POST['subject']);
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) go('outreach', 'Please check the email address for ' . $business . '.', true);
                if ($subject === '') go('outreach', 'Please add a subject for ' . $business . '.', true);
                $reply = null;
                if ($kind !== 'first') {
                    foreach (outreach_all() as $o) if ((int)$o['row'] === $row && $o['kind'] === 'first' && $o['channel'] === 'Email' && !empty($o['mid'])) { $reply = $o['mid']; break; }
                    if (stripos($subject, 're:') !== 0) $subject = 'Re: ' . $subject;
                }
                if (isset(lead_done($row, $kind, null, $slug)['Email'])) go('outreach', $business . ': this email was already sent (probably by the automatic sending a moment ago). Nothing was sent again.', true);
                $mid = send_outreach($to, $subject, tracked_links($body, 'email'), $reply, $slug, $kind);
                if (!$mid) go('outreach', 'The email to ' . $to . ' could not be sent. Nothing was changed.', true);
                outreach_log(['row' => $row, 'slug' => $slug, 'business' => $business, 'kind' => $kind, 'channel' => 'Email', 'to' => $to, 'subject' => $subject, 'body' => $body, 'mid' => $mid, 'by' => $by]);
                if ($kind === 'first') $cells += ['subject' => $subject, 'message' => $body, 'email' => $to]; elseif ($kind === 'followup') $cells['followup'] = $body;
                $did = ($kind === 'first' ? 'Email sent to ' : FOLLOWUP_KINDS[$kind] . ' email sent to ') . $to . '.';
            } elseif ($a === 'omark') {   // Instagram: the team sends it in the app, the panel records it
                outreach_log(['row' => $row, 'slug' => $slug, 'business' => $business, 'kind' => $kind, 'channel' => 'Instagram DM', 'to' => one_line((string)($_POST['to'] ?? '')), 'subject' => '', 'body' => $body, 'mid' => null, 'by' => $by]);
                if ($kind === 'first' || $kind === 'followup') $cells[$both ? ($kind === 'first' ? 'ig_message' : 'ig_followup') : ($kind === 'first' ? 'message' : 'followup')] = $body;
                $did = "Instagram DM to $business marked as sent.";
            } else {
                $ch = $_POST['channel'] === 'Email' ? 'Email' : 'Instagram DM';
                outreach_log(['row' => $row, 'slug' => $slug, 'business' => $business, 'kind' => $kind . '-skip', 'channel' => $ch, 'to' => '', 'subject' => '', 'body' => '(skipped)', 'mid' => null, 'by' => $by]);
                $did = "$ch skipped for $business.";
            }
            $done = lead_done($row, $kind, null, $lead['slug']);
            $left = array_values(array_diff($channels, array_keys($done)));
            $anySent = in_array('sent', $done, true);
            if ($kind === 'first') {
                if ($anySent && $lead['sent'] === '') $cells['sent'] = $today;
                if (!$left) $cells['status'] = $anySent ? 'Sent' : 'Skip';
            } elseif (!$left) $cells['reply'] = FOLLOWUP_KINDS[$kind] . ($anySent ? ' sent ' : ' skipped ') . $today;   // skipping a follow-up moves on to the next one
            if ($cells) sheet_update_row($row, $cells);
            go('outreach', $did . ($left ? ' Still to do: ' . implode(' and ', $left) . '.' : ' All done.'));
        } catch (Throwable $e) { go('outreach', 'Sheet error: ' . $e->getMessage(), true); }
    }
    if ($a === 'vsync') {
        try { $n = visits_sync(); go('activity', "Copied $n visits to the Visits tab of the leads sheet."); }
        catch (Throwable $e) { write_json('visits_sync.json', ['at' => time(), 'error' => $e->getMessage()]); go('activity', 'Could not copy the visits: ' . $e->getMessage(), true); }
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
    if ($a === 'leads_import' && $isOwner) {
        try { $m = leads_import_from_sheet(); go('settings', ucfirst($m) . '. From now on everything uses the database.'); }
        catch (Throwable $e) { go('settings', 'Nothing was changed: ' . $e->getMessage(), true); }
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
        $c['held_pct'] = max(0, min(80, (int)($_POST['held_pct'] ?? 45)));
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

team_mark();

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
$typeLabel = ['booking' => 'Call request', 'question' => 'Question', 'concept' => 'Free concept request', 'report' => 'Report request'];
$howLabel = ['Video call (Google Meet)' => 'Video call', 'WhatsApp call' => 'WhatsApp call', 'Phone call' => 'Phone call'];

/* ---------- numbers for the sidebar badges ---------- */
$badge = ['outreach' => 0, 'leads' => 0, 'calls' => count($upcoming), 'messages' => count($open), 'activity' => 0, 'outreach_hint' => ''];
if (leads_ready()) { try {
    $tzL = new DateTimeZone(TZ); $todayL = new DateTime('today', $tzL);
    $pd = function (string $d) use ($tzL): ?DateTime { if ($d === '') return null; foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'j M Y', 'd.m.Y'] as $f) { $x = DateTime::createFromFormat('!' . $f, $d, $tzL); if ($x) return $x; } return null; };
    $nReady = $nFollow = $nRep = 0; $allRows = sheet_rows(); $olog = outreach_all();
    $nWa = min(count(wa_due($allRows, $olog)), WA_PER_DAY);
    $warmDone = []; foreach ($olog as $o) if ($o['kind'] === 'warm') $warmDone[$o['slug']] = true;
    $nWarm = 0; $ws = warm_slugs(); foreach ($allRows as $r) if ($r['slug'] !== '' && isset($ws[$r['slug']]) && !isset($warmDone[$r['slug']])) $nWarm++;
    foreach ($allRows as $r) {
        if ($r['status'] === 'Candidate') $badge['leads']++;
        if ($r['status'] === 'Review') $nReady++;
        if (str_starts_with($r['stage'], 'Report ready')) $nRep++;
        if (($fu = lead_followup($r)) && $fu['due'] <= $todayL) $nFollow++;
    }
    $badge['outreach'] = $nReady + $nFollow + $nRep + $nWa + $nWarm;
    $badge['outreach_hint'] = "$nWarm warm · $nReady to send · $nWa WhatsApp · $nFollow follow-ups · $nRep analyses";
} catch (Throwable $e) {} }
try { $badge['activity'] = count(array_filter(sessions_from(events_all(2)), fn($x) => strtotime($x['start']) > time() - 86400)); } catch (Throwable $e) {}

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
$nav = [
    'Work' => [['home', 'Overview', 'home'], ['outreach', 'Outreach', 'send'], ['leads', 'Leads', 'list']],
    'Clients' => [['calls', 'Calls', 'phone'], ['messages', 'Messages', 'chat']],
    'Insights' => [['daily', 'Daily report', 'send'], ['results', 'Results', 'chart'], ['activity', 'Activity', 'pulse'], ['views', 'Concept views', 'eye']],
    'Admin' => [],
];
if ($pdo && $isOwner) $nav['Admin'][] = ['team', 'Team', 'users'];
$nav['Admin'][] = ['settings', 'Settings', 'cog'];

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
    echo '<section class="card"><h2>Useful links</h2><div class="links">' . (leads_store() === 'db' ? '<a class="btn line" href="?p=leads">Leads</a><a class="btn line" href="?p=leadscsv">Download leads (CSV)</a>' : '<a class="btn line" href="https://docs.google.com/spreadsheets/d/1NZFcaeoG7fR8--nepcmujsFJAZzfb4r1sCh-_3Lbs-s/edit" target="_blank" rel="noopener">Leads sheet</a>') . '<a class="btn line" href="/demo/" target="_blank">Client portal demo</a><a class="btn line" href="/" target="_blank">Website</a></div></section>';
}
if ($page === 'outreach') {
    echo '<h1>Outreach</h1><p class="sub">Check each message, edit it if you like, and send. Emails go from hello@sitesbyms.com with a tracked link, and the leads sheet updates itself.</p>';
    $rows = null; $gerr = '';
    if (!leads_ready()) $gerr = 'setup';
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
        $follow = []; $followKind = [];
        foreach ($rows as $r) if (($fu = lead_followup($r)) && $fu['due'] <= $todayD) { $follow[] = $r; $followKind[] = $fu['kind']; }
        $log = outreach_all();
        $waAll = wa_due($rows, $log);
        $waToday = count(array_filter($log, fn($o) => $o['kind'] === 'wa' && substr((string)($o['at'] ?? $o['sent_at'] ?? ''), 0, 10) === gmdate('Y-m-d')));
        $waList = array_slice($waAll, 0, max(0, WA_PER_DAY - $waToday));
        $ws = warm_slugs(); $warmDone = []; foreach ($log as $o) if ($o['kind'] === 'warm') $warmDone[$o['slug']] = true;
        $warmList = array_values(array_filter($rows, fn($r) => $r['slug'] !== '' && isset($ws[$r['slug']]) && !isset($warmDone[$r['slug']])));
        usort($warmList, fn($x, $y) => strtotime($ws[$y['slug']]['last']) <=> strtotime($ws[$x['slug']]['last']));
        echo '<div class="tabs2"><a href="#warm">Warm <i>' . count($warmList) . '</i></a><a href="#ready">Ready to send <i>' . count($first) . '</i></a><a href="#wa">WhatsApp <i>' . count($waList) . '</i></a><a href="#followups">Follow-ups due <i>' . count($follow) . '</i></a><a href="#sent">Sent</a></div>';
        $card = function (array $r, string $kind, int $i) use ($seen, $log) {
            $slug = $r['slug']; $sv = $seen[$slug] ?? null;
            $channels = lead_channels($r); $done = lead_done($r['_row'], $kind, $log, $slug); $both = count($channels) > 1; $bad = lead_mismatch($r);
            $ig = preg_replace('#^@|^https?://(www\.)?instagram\.com/#', '', rtrim($r['instagram'], '/'));
            $grp = preg_match('/\[Group ([AB])\]/', $r['notes'], $gm) ? $gm[1] : '';
            $o = '<details class="card lead"' . ($i === 0 ? ' open' : '') . '><summary><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($r['location']) . '</small></div>'
                . ($grp ? '<span class="pill" style="background:' . ($grp === 'A' ? '#EDE7FF;color:#5B2FE0' : '#E3F4EC;color:#147A4B') . '" title="' . ($grp === 'A' ? 'Test group A: competitive analysis sent first' : 'Test group B: concept first, analysis with one tap') . '">' . ($grp === 'A' ? 'Group A · report first' : 'Group B · concept first') . '</span>' : '')
                . ($kind !== 'first' ? '<span class="pill">' . (['followup' => '1st follow-up · day 3', 'followup2' => '2nd follow-up · day 7', 'followup3' => 'Last follow-up · day 14'][$kind]) . '</span>' : '')
                . (isset($GLOBALS['ws'][$slug]) ? '<span class="pill warm">Warm</span>' : '')
                . ($sv ? '<span class="pill ok">Opened ' . h(ago($sv['at'])) . '</span>' : ($kind !== 'first' ? '<span class="pill grey">Not opened yet</span>' : ''));
            foreach ($channels as $c) $o .= '<span class="pill' . (isset($done[$c]) ? ' ok' : ($c === 'Email' ? '' : ' grey')) . '">' . (isset($done[$c]) ? '✓ ' : '') . ($c === 'Email' ? 'Email' : 'Instagram') . '</span>';
            $o .= '</summary>';
            if ($bad) return $o . '</summary><p class="err" style="margin:14px 20px 18px">⚠ ' . h($bad) . '</p></details>';
            if ($r['issues']) $o .= '<p class="hint"><b>Issues we found:</b> ' . nl2br(h($r['issues'])) . '</p>';
            foreach ($channels as $c) {
                $isMail = $c === 'Email';
                if ($both) $o .= '<h3 class="chh">' . ($isMail ? 'Email' : 'Instagram DM') . '</h3>';
                if (isset($done[$c])) { $o .= '<p class="hint">' . ($done[$c] === 'sent' ? '✓ Sent' : 'Skipped') . '.</p>'; continue; }
                $text = tracked_links(lead_text($r, $c, $kind), $isMail ? 'email' : 'ig');
                $o .= '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="row" value="' . (int)$r['_row'] . '"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="business" value="' . h($r['company']) . '"><input type="hidden" name="slug" value="' . h($slug) . '"><input type="hidden" name="channel" value="' . h($c) . '">';
                if ($isMail) $o .= '<div class="f2x"><label>To<input type="email" name="to" value="' . h($r['email']) . '" required></label><label>Subject<input name="subject" value="' . h($kind !== 'first' && stripos($r['subject'], 're:') !== 0 ? 'Re: ' . $r['subject'] : $r['subject']) . '" required></label></div>';
                else $o .= '<input type="hidden" name="to" value="' . h($ig) . '"><p class="hint">Send this in Instagram, then press Mark as sent. ' . ($ig ? 'Account: <a href="https://instagram.com/' . h($ig) . '" target="_blank" rel="noopener">@' . h($ig) . '</a>' : 'No Instagram handle in the sheet.') . '</p>';
                $o .= '<label>' . ($kind === 'first' ? 'Message' : FOLLOWUP_KINDS[$kind]) . '<textarea name="body" rows="' . max(6, min(18, substr_count($text, "\n") + 3)) . '">' . h($text) . '</textarea></label><div class="acts">'
                    . ($slug ? '<a class="btn line sm" href="/' . h($slug) . '/?team=1" target="_blank" rel="noopener">Open concept</a>' : '')
                    . ($isMail ? '<button class="btn sm" name="a" value="osend" onclick="return confirm(\'Send this email to ' . h($r['email']) . '?\')">' . ($kind === 'first' ? 'Send email' : 'Send ' . strtolower(FOLLOWUP_KINDS[$kind])) . '</button>'
                               : '<button type="button" class="btn line sm" data-copy>Copy message</button>' . ($ig ? '<a class="btn line sm" href="https://ig.me/m/' . h($ig) . '" target="_blank" rel="noopener">Open Instagram chat</a>' : '') . '<button class="btn sm" name="a" value="omark">Mark as sent</button>')
                    . '<button class="btn line sm subtle" name="a" value="oskipch" formnovalidate onclick="return confirm(\'Skip ' . ($isMail ? 'the email' : 'Instagram') . ($kind === 'first' ? '' : ' ' . strtolower(FOLLOWUP_KINDS[$kind])) . ' for ' . h($r['company']) . '?\')">Skip ' . ($kind === 'first' ? '' : 'this ') . ($isMail ? 'email' : 'Instagram') . '</button>'
                    . ($c === end($channels) ? '<button class="btn line sm subtle" name="a" value="oskip" formnovalidate onclick="return confirm(\'' . ($kind === 'first' ? 'Move ' . h($r['company']) . ' to Skip?' : 'Stop contacting ' . h($r['company']) . '? No more follow-ups will be sent.') . '\')">' . ($kind === 'first' ? 'Skip lead' : 'Stop all follow-ups') . '</button>' : '')
                    . '</div></form>';
            }
            return $o . '</details>';
        };
        $pe = (array)setting_get('personal_emails', []);
        if ($pe) {
            echo '<h2 id="personal" class="sec-h">Personal emails (written by Claude)</h2>';
            foreach ($pe as $it) {
                echo '<details class="card lead" open><summary><div class="grow"><b>' . h($it['company']) . '</b><small>' . h($it['why'] ?: 'Personal follow-up') . '</small></div><span class="pill ok">Ready to send</span></summary>'
                    . '<div class="links" style="margin:14px 20px 0">' . ($it['slug'] ? '<a class="btn line sm" href="/' . h($it['slug']) . '/?team=1" target="_blank" rel="noopener">Open concept</a> ' : '') . '<a class="btn line sm" href="?p=lead&amp;r=' . (int)$it['row'] . '">Lead details</a></div>'
                    . '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="id" value="' . h($it['id']) . '">'
                    . '<label>Email to<input type="email" name="to" value="' . h($it['to']) . '"></label>'
                    . '<label>Subject<input name="subject" value="' . h($it['subject']) . '"></label>'
                    . '<label>Message<textarea name="body" rows="14">' . h($it['body']) . '</textarea></label>'
                    . '<p class="hint">Sent from hello@sitesbyms.com, as a reply in their latest email thread with us. You can edit it first.</p><div class="acts">'
                    . '<button class="btn sm" name="a" value="pe_send" onclick="return confirm(\'Send this email to ' . h($it['to']) . '?\')">Send email</button>'
                    . '<button class="btn line sm subtle" name="a" value="pe_skip" formnovalidate onclick="return confirm(\'Skip this email?\')">Skip</button></div></form></details>';
            }
        }
        $reps = array_values(array_filter($rows, fn($r) => str_starts_with($r['stage'], 'Report requested') || str_starts_with($r['stage'], 'Report ready')));
        if ($reps) {
            echo '<h2 id="reports" class="sec-h">Competitive analyses</h2>';
            foreach ($reps as $r) {
                $tok = report_token($r); $sendTo = preg_match('/send to: (.+)$/', $r['stage'], $m) ? trim($m[1]) : 'same channel';
                echo '<details class="card lead" open><summary><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($r['stage']) . '</small></div><span class="pill' . ($tok ? ' ok' : ' grey') . '">' . ($tok ? 'Ready to check' : 'Requested · built in the next nightly run') . '</span></summary>';
                if ($tok) {
                    $link = 'https://sitesbyms.com/report/?k=' . $tok;
                    $email = filter_var($sendTo, FILTER_VALIDATE_EMAIL) ? $sendTo : $r['email'];
                    $phone = preg_match('/^[0-9 +().-]{7,20}$/', $sendTo) ? $sendTo : '';
                    $greet = $r['owner'] !== '' ? 'Hi ' . explode(' ', $r['owner'])[0] : 'Hi ' . $r['company'] . ' team';
                    $txt = "$greet,\n\nAs promised, here is the free competitive analysis our team prepared for " . $r['company'] . ":\n$link\n\nIt shows where you lead your local competitors, where you may be losing customers, and three priority actions. Your homepage concept is on the same page.\n\nWe can do more than point these out: as your growth team we can take care of all of it for you, from the website to customer data, marketing automation, reminders and promotions, and monthly reports like this one, so you keep an edge over competitors.\n\nIf you'd like, we can walk you through it in a short call. You can book a time straight from the page.\n\nBest regards,\nMostafa\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048";
                    echo '<div class="links" style="margin:14px 20px 0"><a class="btn sm" href="/report/?k=' . h($tok) . '&amp;team=1" target="_blank" rel="noopener">Open report</a>' . ($r['slug'] ? ' <a class="btn line sm" href="/' . h($r['slug']) . '/?team=1" target="_blank" rel="noopener">Open concept</a>' : '') . '</div><p class="hint" style="margin:8px 20px 0"><b>1.</b> Check the report first (your visit is not counted). If anything is wrong, tell Claude before sending.</p>';
                    echo '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="row" value="' . $r['_row'] . '"><input type="hidden" name="orig" value="' . h($r['company']) . '">'
                        . '<p class="hint"><b>2.</b> Send it. They asked to receive it: ' . h($sendTo) . '</p>'
                        . ($email ? '<label>Email to<input type="email" name="to" value="' . h($email) . '"></label>' : '<input type="hidden" name="to" value="' . h($phone ?: $r['instagram']) . '">')
                        . '<label>Message<textarea name="body" rows="10">' . h($txt) . '</textarea></label><div class="acts">'
                        . ($email ? '<button class="btn sm" name="a" value="rep_send" onclick="return confirm(\'Send the report to ' . h($email) . '?\')">Send report by email</button>' : '')
                        . ($phone ? '<a class="btn line sm" href="https://wa.me/' . h(wa_number($phone, lead_cc($r['country'])) ?: preg_replace('/\D/', '', $phone)) . '?text=' . rawurlencode($txt) . '" target="_blank" rel="noopener">Open WhatsApp</a>' : '')
                        . ($r['instagram'] ? '<a class="btn line sm" href="https://ig.me/m/' . h(ltrim($r['instagram'], '@')) . '" target="_blank" rel="noopener">Open Instagram chat</a>' : '')
                        . '<input type="hidden" name="ch" value="' . ($phone ? 'WhatsApp' : 'Instagram DM') . '"><button class="btn line sm" name="a" value="rep_mark">I sent it myself: mark as sent</button>'
                        . '<button class="btn line sm subtle" name="a" value="rep_skip" formnovalidate onclick="return confirm(\'Skip the analysis for ' . h($r['company']) . '?\')">Skip</button></div></form>';
                } else echo '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="row" value="' . $r['_row'] . '"><input type="hidden" name="orig" value="' . h($r['company']) . '"><div class="acts"><button class="btn line sm subtle" name="a" value="rep_skip" onclick="return confirm(\'Skip the analysis for ' . h($r['company']) . '? It will not be built.\')">Skip</button></div></form>';
                echo '</details>';
            }
        }
        $waForm = function (array $r, string $text, string $mainAct, string $mainLabel, bool $noWa) {
            $wa = lead_wa($r);
            return '<form method="post" class="leadform">' . csrf_field() . '<input type="hidden" name="row" value="' . (int)$r['_row'] . '">'
                . '<label>Message<textarea name="body" rows="' . max(5, substr_count($text, "\n") + 3) . '">' . h($text) . '</textarea></label><div class="acts">'
                . ($wa ? '<a class="btn sm" data-wa="' . h($wa) . '" href="https://wa.me/' . h($wa) . '?text=' . rawurlencode($text) . '" target="_blank" rel="noopener">Open WhatsApp</a>' : '')
                . ($r['phone'] !== '' ? '<a class="btn line sm" href="tel:' . h(preg_replace('/[^0-9+]/', '', $r['phone'])) . '">Call ' . h($r['phone']) . '</a>' : '')
                . '<a class="btn line sm" href="/' . h($r['slug']) . '/?team=1" target="_blank" rel="noopener">Open concept</a>'
                . '<button class="btn line sm" name="a" value="' . $mainAct . '">' . $mainLabel . '</button>'
                . ($noWa ? '<button class="btn line sm subtle" name="a" value="onowa" formnovalidate>No WhatsApp on this number</button>' : '')
                . '<button class="btn line sm subtle" name="a" value="' . ($mainAct === 'owarm' ? 'owarmskip' : 'owaskip') . '" formnovalidate>Skip</button>'
                . '</div></form>';
        };
        echo '<h2 id="warm" class="sec-h">Warm leads</h2><p class="hint">They came back to their concept or read it for 30+ seconds. Reach out personally today: WhatsApp if they have a mobile, otherwise a short call.</p>';
        if ($warmList) foreach ($warmList as $r) { $w = $ws[$r['slug']];
            $txt = lead_greeting($r) . ", it's Mostafa from Sites By MS. Thanks for taking a look at the homepage idea for " . $r['company'] . "! Would a free competitive analysis be useful too? It shows how you compare with 4–5 similar businesses nearby, and three clear steps to win more customers. Happy to send it over, or answer any questions here.";
            echo '<details class="card lead" open><summary><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($r['location']) . ' · ' . $w['visits'] . ' visit' . ($w['visits'] > 1 ? 's' : '') . ' · up to ' . (int)$w['active'] . ' s reading · last ' . h(ago($w['last'])) . '</small></div><span class="pill warm">Warm</span>'
                . (lead_wa($r) ? '<span class="pill">WhatsApp</span>' : ($r['phone'] !== '' ? '<span class="pill grey">Landline: call</span>' : '<span class="pill grey">No phone</span>')) . '</summary>'
                . $waForm($r, $txt, 'owarm', 'Contacted: mark as done', false) . '</details>';
        } else echo '<div class="card"><div class="empty">No warm leads right now. When someone comes back or reads their concept for a while, they show up here and you get an email.</div></div>';
        echo '<h2 id="ready" class="sec-h">Ready to send</h2>';
        echo $first ? implode('', array_map($card, $first, array_fill(0, count($first), 'first'), array_keys($first))) : '<div class="card"><div class="empty">Nothing waiting. New messages appear here after the nightly run.</div></div>';
        echo '<h2 id="wa" class="sec-h">WhatsApp</h2><p class="hint">Day 2 after the first email (only mobiles, no reply yet), and first messages for businesses with no email or Instagram. Send by hand in WhatsApp, up to ' . WA_PER_DAY . ' a day' . ($waToday ? " ($waToday sent today)" : '') . '. If WhatsApp says the number is not on WhatsApp, press "No WhatsApp".</p>';
        if ($waList) foreach ($waList as $i => $r)
            echo '<details class="card lead"' . ($i === 0 ? ' open' : '') . '><summary><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($r['location']) . ' · ' . h($r['phone']) . '</small></div>'
                . (isset($ws[$r['slug']]) ? '<span class="pill warm">Warm</span>' : '') . '<span class="pill' . ($r['_wa_first'] ? '' : ' grey') . '">' . ($r['_wa_first'] ? 'First message' : 'Day 2 after email') . '</span>'
                . (($sv = $seen[$r['slug']] ?? null) ? '<span class="pill ok">Opened ' . h(ago($sv['at'])) . '</span>' : '') . '</summary>'
                . $waForm($r, lead_wa_text($r, $r['_wa_first']), 'owa', 'Sent: mark as sent', true) . '</details>';
        else echo '<div class="card"><div class="empty">' . ($waAll ? 'Daily limit reached. The rest show up tomorrow.' : 'No WhatsApp messages due.') . '</div></div>';
        echo '<h2 id="followups" class="sec-h">Follow-ups due</h2>';
        echo $follow ? implode('', array_map($card, $follow, $followKind, array_keys($follow))) : '<div class="card"><div class="empty">No follow-ups due today.</div></div>';
        echo '<h2 id="sent" class="sec-h">Sent from the panel</h2><section class="card">';
        $log = outreach_all();
        if ($log) foreach (array_slice($log, 0, 40) as $l) { $sv = $seen[$l['slug']] ?? null;
            echo '<div class="row"><div class="grow"><b>' . h($l['business']) . '</b> · ' . h(['first' => 'first message', 'report' => 'competitive analysis', 'wa' => 'WhatsApp message', 'wa-none' => 'no WhatsApp on this number', 'wa-skip' => 'WhatsApp skipped', 'warm' => ($l['body'] ?? '') === '(skipped)' ? 'warm lead skipped' : 'warm lead contacted'][$l['kind']] ?? (str_ends_with($l['kind'], '-skip') ? 'skipped' : 'follow-up')) . '<small>' . h($l['channel']) . ($l['to'] ? ' · ' . h($l['to']) : '') . ' · ' . h(ago($l['at'] ?? $l['sent_at'] ?? null)) . ($l['sent_by'] ?? '' ? ' · by ' . h($l['sent_by'] ?? '') : '') . '</small></div>'
                . ($sv && strtotime($sv['at']) >= strtotime((string)($l['at'] ?? '')) ? '<span class="pill ok">Opened ' . h(ago($sv['at'])) . ($sv['from'] ? ' · ' . h($sv['from'] === 'ig' ? 'Instagram' : $sv['from']) : '') . '</span>' : '<span class="pill grey">Not opened yet</span>') . '</div>'; }
        else echo '<div class="empty">Nothing sent from the panel yet.</div>';
        echo '</section>';
        echo '<script>document.querySelectorAll("[data-wa]").forEach(function(a){a.addEventListener("click",function(){a.href="https://wa.me/"+a.dataset.wa+"?text="+encodeURIComponent(a.closest("form").querySelector("textarea").value)})})</script>';
        echo '<script>document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){var t=b.closest("form").querySelector("textarea");var done=function(){b.textContent="Copied ✓";setTimeout(function(){b.textContent="Copy message"},2000)};if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done,function(){t.select();document.execCommand("copy");done()})}else{t.select();document.execCommand("copy");done()}})})</script>';
    }
}
if ($page === 'calls') {
    echo '<h1>Calls</h1><p class="sub">Every call clients have booked. Times are London time. For video calls: create a Meet link, paste it, and we email it to the client with an updated calendar invite.</p>';
    echo '<section class="card"><h2>Upcoming</h2>' . ($upcoming ? implode('', array_map(fn($b) => booking_row($b, $howLabel, true), $upcoming)) : '<div class="empty">No upcoming calls.</div>') . '</section>';
    if ($past) echo '<section class="card"><h2>Past and cancelled</h2>' . implode('', array_map(fn($b) => booking_row($b, $howLabel), array_slice($past, 0, 40))) . '</section>';
}
if ($page === 'results') {
    echo '<h1>Results</h1><p class="sub">How each channel is doing: messages sent, how many saw them, how many opened their concept, replies and calls. Your own team\'s clicks are not counted.</p>';
    $rows = null; $gerr = '';
    if (!leads_ready()) $gerr = 'The leads sheet is not connected yet (Settings › Leads sheet).';
    else { try { $rows = sheet_rows(); } catch (Throwable $e) { $gerr = $e->getMessage(); } }
    if ($gerr) echo '<section class="card"><p class="err">' . h($gerr) . '</p></section>';
    else {
        $sent = array_values(array_filter($rows, fn($r) => $r['sent'] !== ''));
        $chan = fn($r) => stripos($r['channel'], 'mail') !== false ? 'Email' : (stripos($r['channel'], 'insta') !== false ? 'Instagram DM' : ($r['channel'] ?: 'Other'));
        $vs = []; foreach (array_reverse($views) as $v) $vs[$v['slug']][] = $v;      // oldest first
        $calls = []; foreach ($bookings as $b) if ($b['slug'] && $b['status'] !== 'cancelled') $calls[$b['slug']] = true;
        $isReply = fn($r) => $r['reply'] !== '' && !preg_match('/^follow-up( \d)? (sent|skipped)/i', $r['reply']);
        $seenAt = seen_map();
        $stat = []; $list = [];
        $olog = outreach_all();
        foreach ($sent as $r) {
            $done = array_keys(array_filter(lead_done($r['_row'], 'first', $olog, $r['slug']), fn($x) => $x === 'sent'));
            $chs = $done ?: lead_channels($r);
            $sv = $vs[$r['slug']] ?? [];
            foreach ($chs as $c) {
                $stat[$c] ??= ['sent' => 0, 'seen' => 0, 'opened' => 0, 'tracked' => 0, 'reply' => 0, 'call' => 0]; $st = &$stat[$c];
                $tag = 'via ' . ($c === 'Email' ? 'email' : 'ig');
                $mine = array_filter($sv, fn($v) => $v['ref'] === $tag);
                $opened = $mine || (count($chs) === 1 && $sv);
                $st['sent']++; if ($opened) $st['opened']++; if ($mine) $st['tracked']++;
                if ($opened || isset($seenAt[$r['slug'] . '|' . $c]) || (count($chs) === 1 && isset($seenAt[$r['slug']]))) $st['seen']++;
                if ($isReply($r)) $st['reply']++; if (isset($calls[$r['slug']])) $st['call']++;
                unset($st);
            }
            $list[] = ['r' => $r, 'c' => implode(' + ', $chs), 'v' => $sv];
        }
        $pct = fn($a, $b) => $b ? round(100 * $a / $b) . '%' : '–';
        ksort($stat);
        $tot = ['sent' => 0, 'seen' => 0, 'opened' => 0, 'reply' => 0, 'call' => 0]; foreach ($stat as $st) foreach ($tot as $k => $_) $tot[$k] += $st[$k];
        echo '<div class="stats"><div class="stat"><b>' . $tot['sent'] . '</b><span>Messages sent</span></div><div class="stat"><b>' . $pct($tot['seen'], $tot['sent']) . '</b><span>View rate: saw the message (' . $tot['seen'] . ')</span></div><div class="stat"><b>' . $pct($tot['opened'], $tot['sent']) . '</b><span>Click rate: opened the concept (' . $tot['opened'] . ')</span></div><div class="stat"><b>' . $tot['reply'] . '</b><span>Replies</span></div><div class="stat"><b>' . $tot['call'] . '</b><span>Calls booked</span></div></div>';
        echo '<section class="card"><h2>By channel</h2><div class="tbl"><table><thead><tr><th>Channel</th><th>Sent</th><th>Saw message</th><th>View rate</th><th>Opened concept</th><th>Click rate</th><th>Replies</th><th>Calls</th></tr></thead><tbody>';
        foreach ($stat as $c => $st) echo '<tr><td><b>' . h($c) . '</b></td><td>' . $st['sent'] . '</td><td>' . $st['seen'] . '</td><td><b>' . $pct($st['seen'], $st['sent']) . '</b></td><td>' . $st['opened'] . '</td><td><b>' . $pct($st['opened'], $st['sent']) . '</b></td><td>' . $st['reply'] . '</td><td>' . $st['call'] . '</td></tr>';
        echo '</tbody></table></div><p class="hint"><b>View rate</b> = saw the message ÷ sent. Email: counted when the email is opened (it carries a tiny image; some apps block images, so the real number can be a bit higher). Instagram: press <b>Mark seen</b> below when Instagram shows “Seen”. Anyone who opened the concept also counts as seen.<br><b>Click rate</b> = opened the concept ÷ sent. Visits from the panel, or from any browser or network where the panel is used, are never counted.</p></section>';
        usort($list, fn($a, $b) => [(int)!$a['v'], $b['r']['sent']] <=> [(int)!$b['v'], $a['r']['sent']]);
        echo '<section class="card"><h2>Every lead</h2>';
        foreach ($list as $x) { $r = $x['r']; $v = $x['v']; $n = count($v); $last = $v ? end($v) : null; $isIg = str_contains($x['c'], 'Instagram'); $sa = $seenAt[$r['slug']] ?? null;
            echo '<div class="row"><div class="grow"><b>' . h($r['company']) . '</b><small>' . h($x['c']) . ' · sent ' . h($r['sent']) . ($isReply($r) ? ' · <b>replied:</b> ' . h(mb_substr($r['reply'], 0, 80)) : '') . '</small></div>'
                . ($v ? '<span class="pill ok">Opened concept ' . h(ago($last['at'])) . ' · ' . $n . ($n === 1 ? ' view' : ' views') . '</span>'
                      : ($sa ? '<span class="pill">Saw message ' . h(ago($sa)) . '</span>' : '<span class="pill grey">' . ($isIg ? 'Not seen yet' : 'Email not opened yet') . '</span>'))
                . ($isIg && !$v && $r['slug'] ? '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="' . ($sa ? 'ounseen' : 'oseen') . '"><input type="hidden" name="slug" value="' . h($r['slug']) . '"><button class="btn line sm">' . ($sa ? 'Undo seen' : 'Mark seen') . '</button></form>' : '')
                . ($r['slug'] ? '<a class="btn line sm" href="/' . h($r['slug']) . '/?team=1" target="_blank" rel="noopener">Concept</a>' : '') . '</div>'; }
        echo '</section>';
    }
}
if ($page === 'activity') {
    $days = in_array((int)($_GET['d'] ?? 30), [7, 30, 60], true) ? (int)($_GET['d'] ?? 30) : 30;
    $only = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['s'] ?? ''));
    $ss = sessions_from(events_all($days));
    if ($only) $ss = array_values(array_filter($ss, fn($x) => $x['slug'] === $only));
    $mmss = fn(int $s) => floor($s / 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
    $label = ['open' => 'Opened the page', 'scroll' => 'Scrolled to %s%%', 'section' => 'Saw “%s”', 'click' => 'Clicked “%s”', 'call' => 'Tapped call: %s', 'email' => 'Tapped email: %s',
        'bar_hide' => 'Hid our bar', 'bar_show' => 'Brought our bar back', 'nudge_shown' => 'Saw the report offer', 'nudge_yes' => 'Said yes to the report offer', 'nudge_no' => 'Closed the report offer',
        'report_open' => 'Opened the report form (%s)', 'report_submit' => '★ Requested the competitive analysis (%s)', 'report_error' => 'Report form error: %s',
        'book_open' => 'Opened call booking (%s)', 'book_slot' => 'Picked a time: %s', 'book_submit' => '★ Booked a call (%s)', 'ask_click' => 'Went to ask a question', 'leave' => 'Left',
        'visit' => 'Visit no. %s (screen)', 'page' => 'Page: %s', 'dwell' => 'Most time on: %s', 'tap' => 'Tapped something that is not a link: %s', 'rage' => '⚠ Tapped repeatedly: %s',
        'copy' => 'Copied text: %s', 'back' => 'Came back after %s', 'form_start' => 'Started filling: %s', 'form_submit' => 'Sent: %s', 'form_abandon' => 'Left a form unfinished: %s', 'book_day' => 'Picked a day: %s', 'about_click' => 'Opened “Meet the team”'];
    $srcName = fn($s) => $s === 'email' ? 'Email' : ($s === 'ig' ? 'Instagram' : ($s === '' ? 'Direct / unknown' : $s));
    // funnel per source
    $steps = ['Visits', 'Engaged (20s+ or half the page)', 'Saw the report offer', 'Opened the report form', 'Requested the report', 'Booked a call'];
    $fun = [];
    foreach ($ss as $x) {
        if (($x['page'] ?? 'concept') !== 'concept') continue;
        $c = $srcName($x['src']); $f = $x['flags'];
        $vals = [true, $x['active'] >= 20 || $x['scroll'] >= 50, !empty($f['nudge_shown']) || !empty($f['report_open']), !empty($f['report_open']), !empty($f['report_submit']), !empty($f['book_submit'])];
        foreach (['All visits', $c] as $k) { $fun[$k] ??= array_fill(0, count($steps), 0); foreach ($vals as $i => $v) if ($v) $fun[$k][$i]++; }
    }
    $vs = read_json('visits_sync.json', []);
    echo '<h1>Activity</h1><p class="sub">What visitors do on concept pages, report pages and our own site: time, scroll, sections, clicks, taps, forms and every step of booking. Team visits are never counted.</p>';
    if (leads_store() !== 'db') echo '<form method="post" class="hint" style="margin:-6px 0 16px">' . csrf_field() . 'Every visit is also copied to the <b>Visits</b> tab of the leads sheet (every 30 minutes)' . (!empty($vs['at']) ? ': last copy ' . h(ago(gmdate('c', (int)$vs['at']))) . (isset($vs['rows']) ? ', ' . (int)$vs['rows'] . ' visits' : '') . (!empty($vs['error']) ? ' · <span class="err">' . h($vs['error']) . '</span>' : '') : '') . '. <button class="btn line sm" name="a" value="vsync">Copy now</button></form>';
    echo '<div class="tabs2">'; foreach ([7, 30, 60] as $d) echo '<a href="?p=activity&amp;d=' . $d . ($only ? '&amp;s=' . h($only) : '') . '"' . ($d === $days ? ' style="font-weight:800"' : '') . '>Last ' . $d . ' days</a>'; if ($only) echo '<a href="?p=activity&amp;d=' . $days . '">Show all businesses</a>'; echo '</div>';
    echo '<section class="card"><h2>Funnel' . ($only ? ' for ' . h($only) : '') . '</h2>';
    if (!$ss) echo '<div class="empty">No visits recorded yet. They appear here as soon as someone opens a concept.</div>';
    else {
        echo '<div class="tbl"><table><thead><tr><th>Step</th>'; foreach (array_keys($fun) as $k) echo '<th>' . h($k) . '</th>'; echo '</tr></thead><tbody>';
        foreach ($steps as $i => $st) { echo '<tr><td>' . h($st) . '</td>'; foreach ($fun as $row) echo '<td><b>' . $row[$i] . '</b>' . ($i && $row[0] ? ' <small>' . round(100 * $row[$i] / $row[0]) . '%</small>' : '') . '</td>'; echo '</tr>'; }
        echo '</tbody></table></div><p class="hint">One visit = one visitor on one page until they leave. Percentages are of all visits in that column.</p>';
    }
    echo '</section><section class="card"><h2>Visits</h2>';
    foreach (array_slice($ss, 0, 150) as $x) {
        $f = $x['flags']; $chips = '';
        if (!empty($f['report_submit'])) $chips .= '<span class="pill ok">Requested report</span>';
        if (!empty($f['book_submit'])) $chips .= '<span class="pill ok">Booked a call</span>';
        if (!empty($f['call'])) $chips .= '<span class="pill">Tapped call</span>';
        if (!empty($f['report_open']) && empty($f['report_submit'])) $chips .= '<span class="pill">Opened report form</span>';
        echo '<details class="card lead visit"><summary><div class="grow"><b>' . h(($x['page'] ?? '') === 'site' ? 'Our site: ' . implode(' → ', $x['pages'] ?: ['page']) : (($x['page'] ?? '') === 'report' ? 'Report: ' . $x['slug'] : $x['slug'])) . '</b><small>' . h(ago($x['start'])) . ' · ' . h($srcName($x['src'])) . ' · ' . h($x['device']) . ' · active ' . $mmss($x['active']) . ' · scrolled ' . (int)$x['scroll'] . '%</small></div>' . $chips . '</summary><ol class="tl">';
        foreach ($x['events'] as $e) { $t = (int)floor($e['t'] / 1000); $l = $label[$e['ev']] ?? $e['ev']; $v = $e['val'] !== '' ? $e['val'] : '—';
            echo '<li><span>' . $mmss($t) . '</span>' . h(str_contains($l, '%s') ? sprintf($l, $v) : $l) . '</li>'; }
        if ($x['scroll']) echo '<li><span>·</span>Deepest scroll: ' . (int)$x['scroll'] . '% · active time ' . $mmss($x['active']) . '</li>';
        echo '</ol><p class="hint" style="margin:0 20px 16px"><a href="?p=activity&amp;d=' . $days . '&amp;s=' . h($x['slug']) . '">All visits for ' . h($x['slug']) . '</a> · <a href="/' . h($x['slug']) . '/?team=1" target="_blank" rel="noopener">Open concept</a></p></details>';
    }
    if (!$ss) echo '<div class="empty">Nothing yet.</div>';
    echo '</section>';
}
if (in_array($page, ['leads', 'lead', 'leadnew'], true)) {
    $rows = null; $gerr = '';
    if (!leads_ready()) $gerr = 'The leads sheet is not connected yet (Settings › Leads sheet).';
    else { try { $rows = sheet_rows(); } catch (Throwable $e) { $gerr = $e->getMessage(); } }
    $stClass = ['Candidate' => 'grey', 'Ready' => '', 'Rebuild' => '', 'Review' => '', 'Sent' => 'ok', 'Replied' => 'ok', 'Won' => 'ok', 'Lost' => 'grey', 'Skip' => 'grey'];
    $fieldDefs = [
        'Business' => ['company' => 'Business name', 'location' => 'Address', 'country' => 'Country', 'site' => 'Website'],
        'Contact' => ['owner' => 'Owner name', 'email' => 'Email', 'phone' => 'Phone / WhatsApp', 'instagram' => 'Instagram'],
        'Pipeline' => ['status' => 'Status', 'notes' => 'Notes', 'reply' => 'Reply / result', 'stage' => 'Project stage', 'payment' => 'Payment link'],
        'Pitch' => ['slug' => 'Concept folder (slug)', 'preview' => 'Concept link', 'language' => 'Language', 'channel' => 'Channel', 'issues' => 'Issues we found', 'subject' => 'Email subject', 'message' => 'First message (email, or DM if Instagram only)', 'followup' => 'Follow-up', 'ig_message' => 'Instagram message (when both channels)', 'ig_followup' => 'Instagram follow-up', 'sent' => 'Sent date'],
    ];
    $long = ['notes', 'issues', 'message', 'followup', 'ig_message', 'ig_followup', 'reply'];
    $input = function (string $k, string $label, string $v) use ($long) {
        if ($k === 'status') return '<label>' . h($label) . '<select name="f[status]">' . implode('', array_map(fn($s) => '<option' . ($s === $v ? ' selected' : '') . '>' . $s . '</option>', array_unique(array_merge(LEAD_STATUSES, $v !== '' ? [$v] : [])))) . '</select></label>';
        if ($k === 'channel') return '<label>' . h($label) . '<select name="f[channel]">' . implode('', array_map(fn($s) => '<option' . ($s === $v ? ' selected' : '') . '>' . $s . '</option>', array_unique(array_merge(['', 'Email', 'Instagram DM', 'Email + Instagram DM'], [$v])))) . '</select></label>';
        if (in_array($k, $long, true)) return '<label class="wide">' . h($label) . '<textarea name="f[' . $k . ']" rows="' . max(3, min(16, substr_count($v, "\n") + 2 + intdiv(strlen($v), 90))) . '">' . h($v) . '</textarea></label>';
        return '<label>' . h($label) . '<input name="f[' . $k . ']" value="' . h($v) . '"' . ($k === 'email' ? ' type="email"' : '') . '></label>';
    };
    if ($gerr) echo '<h1>Leads</h1><section class="card"><p class="err">' . h($gerr) . '</p></section>';
    elseif ($page === 'leads') {
        $st = (string)($_GET['st'] ?? 'active'); $qq = trim((string)($_GET['q'] ?? ''));
        $count = ['active' => 0, 'all' => count($rows)]; foreach (LEAD_STATUSES as $s) $count[$s] = 0;
        foreach ($rows as $r) { $count[$r['status']] = ($count[$r['status']] ?? 0) + 1; if (!in_array($r['status'], ['Skip', 'Lost'], true)) $count['active']++; }
        $list = array_values(array_filter($rows, function ($r) use ($st, $qq) {
            if ($st === 'active' && in_array($r['status'], ['Skip', 'Lost'], true)) return false;
            if ($st !== 'active' && $st !== 'all' && $r['status'] !== $st) return false;
            if ($qq !== '') { $hay = strtolower(implode(' ', [$r['company'], $r['location'], $r['email'], $r['instagram'], $r['notes'], $r['owner'], $r['slug']])); if (!str_contains($hay, strtolower($qq))) return false; }
            return true;
        }));
        usort($list, fn($a, $b) => $b['_row'] <=> $a['_row']);
        $viewsBy = []; foreach ($views as $v) $viewsBy[$v['slug']] = ($viewsBy[$v['slug']] ?? 0) + 1;
        echo '<div class="ch"><h1>Leads</h1><div class="acts"><a class="btn line" href="?p=history">Change history</a><a class="btn line" href="?p=leadscsv">Download CSV</a><a class="btn" href="?p=leadnew">Add a lead</a></div></div><p class="sub">' . (leads_store() === 'db' ? 'Every business we work with, stored in the site database. Every change is recorded (who, what, when) and the whole list is backed up every day.' : 'Every business in the leads sheet. Changes you make here are saved straight to the sheet.') . '</p>';
        echo '<div class="tabs2">';
        foreach (array_merge(['active' => 'Active', 'all' => 'All'], array_combine(LEAD_STATUSES, LEAD_STATUSES)) as $k => $lab) echo '<a href="?p=leads&amp;st=' . rawurlencode($k) . '"' . ($k === $st ? ' aria-current="page"' : '') . '>' . h($lab) . ' <i>' . ($count[$k] ?? 0) . '</i></a>';
        echo '</div><form class="search" method="get"><input type="hidden" name="p" value="leads"><input type="hidden" name="st" value="' . h($st) . '"><input name="q" value="' . h($qq) . '" placeholder="Search name, area, email, Instagram, notes…" aria-label="Search leads"><button class="btn line sm">Search</button></form>';
        echo '<section class="card"><div class="tbl"><table class="leads"><thead><tr><th>Business</th><th>Contact</th><th>Status</th><th>Notes</th><th>Concept</th><th></th></tr></thead><tbody>';
        $back = 'leads&st=' . rawurlencode($st) . ($qq !== '' ? '&q=' . rawurlencode($qq) : '');
        foreach ($list as $r) {
            $quick = '';
            if ($r['status'] === 'Candidate') foreach (['Ready' => 'Approve', 'Skip' => 'Skip'] as $to => $lab) $quick .= '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="lead_status"><input type="hidden" name="row" value="' . $r['_row'] . '"><input type="hidden" name="orig" value="' . h($r['company']) . '"><input type="hidden" name="status" value="' . $to . '"><input type="hidden" name="back" value="' . h($back) . '"><button class="btn sm' . ($to === 'Skip' ? ' line' : '') . '">' . $lab . '</button></form>';
            $nv = $viewsBy[$r['slug']] ?? 0;
            echo '<tr><td><a class="lname" href="?p=lead&amp;r=' . $r['_row'] . '"><b>' . h($r['company']) . '</b></a><small>' . h(mb_strimwidth($r['location'], 0, 48, '…')) . '</small></td>'
                . '<td class="ct">' . ($r['email'] !== '' ? '<span title="' . h($r['email']) . '">✉</span>' : '') . ($r['instagram'] !== '' ? '<span title="@' . h(ltrim($r['instagram'], '@')) . '">IG</span>' : '') . ($r['phone'] !== '' ? '<span title="' . h($r['phone']) . '">☎</span>' : '') . '</td>'
                . '<td><span class="pill ' . ($stClass[$r['status']] ?? 'grey') . '">' . h($r['status'] ?: '—') . '</span>' . ($r['sent'] ? '<small>sent ' . h($r['sent']) . '</small>' : '') . '</td>'
                . '<td class="nt">' . h(mb_strimwidth($r['notes'], 0, 110, '…')) . '</td>'
                . '<td>' . ($r['slug'] ? '<a href="/' . h($r['slug']) . '/?team=1" target="_blank" rel="noopener">View</a>' . ($nv ? '<small>' . $nv . ' ' . ($nv === 1 ? 'open' : 'opens') . '</small>' : '') : '—') . '</td>'
                . '<td class="qa">' . $quick . '<a class="btn line sm" href="?p=lead&amp;r=' . $r['_row'] . '">Open</a></td></tr>';
        }
        if (!$list) echo '<tr><td colspan="6"><div class="empty">No leads here.</div></td></tr>';
        echo '</tbody></table></div></section>';
    } elseif ($page === 'leadnew') {
        echo '<h1>Add a lead</h1><p class="sub">It is added to the end of the leads list. The nightly run picks up leads with status Ready.</p><form class="card leadedit" method="post">' . csrf_field() . '<input type="hidden" name="a" value="lead_add">';
        foreach (['Business', 'Contact'] as $grp) { echo '<h2>' . $grp . '</h2><div class="fgrid">'; foreach ($fieldDefs[$grp] as $k => $lab) echo $input($k, $lab, ''); echo '</div>'; }
        echo '<h2>Pipeline</h2><div class="fgrid">' . $input('status', 'Status', 'Candidate') . $input('notes', 'Notes (sector, rating, why it is a good lead)', '') . '</div><div class="acts"><button class="btn">Add lead</button><a class="btn line" href="?p=leads">Cancel</a></div></form>';
    } else {
        $rn = (int)($_GET['r'] ?? 0); $r = null; foreach ($rows as $x) if ($x['_row'] === $rn) $r = $x;
        if (!$r) echo '<h1>Lead not found</h1><section class="card"><p>That row is empty or no longer exists.</p><a class="btn line" href="?p=leads">Back to leads</a></section>';
        else {
            $slug = $r['slug'];
            echo '<p class="crumb"><a href="?p=leads">← Leads</a></p><div class="ch"><div><h1>' . h($r['company']) . ' <span class="pill ' . ($stClass[$r['status']] ?? 'grey') . '">' . h($r['status']) . '</span></h1><p class="sub">' . h($r['location']) . ' · sheet row ' . $r['_row'] . '</p></div>'
                . '<form method="post" class="acts">' . csrf_field() . '<input type="hidden" name="a" value="lead_status"><input type="hidden" name="row" value="' . $r['_row'] . '"><input type="hidden" name="orig" value="' . h($r['company']) . '"><input type="hidden" name="back" value="lead&amp;r=' . $r['_row'] . '">'
                . '<select name="status" aria-label="New status">' . implode('', array_map(fn($s) => '<option' . ($s === $r['status'] ? ' selected' : '') . '>' . $s . '</option>', LEAD_STATUSES)) . '</select><button class="btn sm">Change status</button></form></div>';
            // activity for this business
            $vv = array_values(array_filter($views, fn($v) => $v['slug'] === $slug && $slug !== ''));
            $ol = array_values(array_filter(outreach_all(), fn($o) => (int)$o['row'] === $r['_row']));
            $mm = array_values(array_filter($messages, fn($m) => $slug !== '' && ($m['slug'] ?? '') === $slug));
            $bb = array_values(array_filter($bookings, fn($b) => $slug !== '' && $b['slug'] === $slug));
            echo '<section class="card"><h2>Activity</h2><ul class="act">';
            foreach ($bb as $b) echo '<li><b>Call booked</b> for ' . h(london($b['start_ts'], 'D j M, H:i')) . ' London · ' . h($b['how']) . '</li>';
            foreach ($mm as $m) echo '<li><b>' . h($typeLabel[$m['type']] ?? $m['type']) . '</b> · ' . h(ago($m['at'])) . ($m['email'] ? ' · ' . h($m['email']) : '') . ($m['phone'] ? ' · ' . h($m['phone']) : '') . '</li>';
            foreach ($ol as $o) echo '<li><b>' . h($o['kind'] === 'first' ? 'First message' : (isset(FOLLOWUP_KINDS[$o['kind']]) ? FOLLOWUP_KINDS[$o['kind']] : ucfirst(str_replace('-', ' ', $o['kind'])))) . '</b> · ' . h($o['channel']) . ' · ' . h(ago($o['at'] ?? null)) . (!empty($o['sent_by']) ? ' · by ' . h($o['sent_by']) : '') . '</li>';
            if ($vv) echo '<li><b>Concept opened ' . count($vv) . ' ' . (count($vv) === 1 ? 'time' : 'times') . '</b> · last ' . h(ago($vv[0]['at'])) . ' · <a href="?p=activity&amp;s=' . h($slug) . '">see what they did</a></li>';
            if (!$bb && !$mm && !$ol && !$vv) echo '<li class="muted">Nothing yet.</li>';
            echo '</ul><div class="links">' . ($slug ? '<a class="btn line sm" href="/' . h($slug) . '/?team=1" target="_blank" rel="noopener">Open concept</a>' : '') . (($rt = report_token($r)) ? ' <a class="btn line sm" href="/report/?k=' . h($rt) . '&amp;team=1" target="_blank" rel="noopener">Open report</a>' : '') . ($r['site'] ? ' <a class="btn line sm" href="' . h(preg_match('#^https?://#', $r['site']) ? $r['site'] : 'https://' . $r['site']) . '" target="_blank" rel="noopener">Their website</a>' : '') . '</div></section>';
            echo '<form class="card leadedit" method="post">' . csrf_field() . '<input type="hidden" name="a" value="lead_save"><input type="hidden" name="row" value="' . $r['_row'] . '"><input type="hidden" name="orig" value="' . h($r['company']) . '">';
            foreach ($fieldDefs as $grp => $fs) { echo '<h2>' . $grp . '</h2><div class="fgrid">'; foreach ($fs as $k => $lab) echo $input($k, $lab, $r[$k]); echo '</div>'; }
            $hist = leads_store() === 'db' ? leads_history($r['_row'], 30) : [];
            if ($hist) { echo '<section class="card"><h2>Change history</h2><ul class="tl">'; foreach ($hist as $hx) echo '<li><b>' . h($hx['field']) . '</b> · ' . h(mb_strimwidth((string)$hx['old'], 0, 40, '…') ?: '—') . ' → ' . h(mb_strimwidth((string)$hx['new'], 0, 60, '…') ?: '—') . '<small> · ' . h($hx['by']) . ' · ' . h(ago($hx['at'])) . '</small></li>'; echo '</ul></section>'; }
            echo '<p class="hint">First follow-up date: ' . h($r['followup_date'] ?: '3 days after sending') . '</p><div class="acts sticky"><button class="btn">Save changes</button><a class="btn line" href="?p=leads">Back</a></div></form>';
        }
    }
}
if ($page === 'history') {
    $hist = leads_store() === 'db' ? leads_history(null, 400) : [];
    $names = []; try { foreach (sheet_rows() as $x) $names[$x['_row']] = $x['company']; } catch (Throwable $e) {}
    echo '<p class="crumb"><a href="?p=leads">← Leads</a></p><h1>Change history</h1><p class="sub">Every change to a lead or a setting: by a team member, by the automatic sending, or by Claude in the nightly run. Nothing is ever deleted from this list.</p><section class="card">';
    if (!$hist) echo '<div class="empty">' . (leads_store() === 'db' ? 'No changes yet.' : 'The history starts once the leads are moved into the database (Settings).') . '</div>';
    else { echo '<div class="tbl"><table><thead><tr><th>When</th><th>Lead</th><th>Field</th><th>Before</th><th>After</th><th>By</th></tr></thead><tbody>';
        foreach ($hist as $hx) echo '<tr><td>' . h(ago($hx['at'])) . '</td><td>' . ($hx['row'] ? '<a href="?p=lead&amp;r=' . (int)$hx['row'] . '">' . h($names[$hx['row']] ?? '#' . $hx['row']) . '</a>' : '—') . '</td><td>' . h($hx['field']) . '</td><td class="nt">' . h(mb_strimwidth((string)$hx['old'], 0, 60, '…')) . '</td><td class="nt">' . h(mb_strimwidth((string)$hx['new'], 0, 80, '…')) . '</td><td>' . h($hx['by']) . '</td></tr>';
        echo '</tbody></table></div>'; }
    echo '</section>';
}
if ($page === 'messages') {
    echo '<h1>Messages</h1><p class="sub">Questions and free concept requests from the website forms.</p>';
    echo '<section class="card">' . ($messages ? implode('', array_map(fn($m) => msg_row($m, $typeLabel), $messages)) : '<div class="empty">No messages yet.</div>') . '</section>';
}
if ($page === 'daily') {
    $files = glob(data_dir() . '/autosend-*.json') ?: []; rsort($files);
    echo '<h1>Daily report</h1><p class="sub">What was sent automatically each morning, what could not be sent and why, and the Instagram DMs left to do by hand. The same report is emailed to you after each run.</p>';
    if (!$files) echo '<section class="card"><div class="empty">No automatic run yet. The first report appears after the next morning run (Settings › Automatic emails).</div></section>';
    foreach (array_slice($files, 0, 21) as $i => $f) {
        $rep = json_decode((string)file_get_contents($f), true) ?: []; if (!$rep) continue;
        $nNew = count(array_filter($rep['sent'] ?? [], fn($x) => $x['kind'] === 'first'));
        echo '<details class="card lead"' . ($i === 0 ? ' open' : '') . '><summary><div class="grow"><b>' . h((new DateTime($rep['date']))->format('l j F')) . '</b><small>' . (!empty($rep['paused']) ? 'Paused, nothing sent' : 'Run ' . h($rep['started'] ?? '') . '–' . h($rep['finished'] ?? '…')) . '</small></div>'
            . '<span class="pill ok">' . $nNew . ' new</span><span class="pill ok">' . (count($rep['sent'] ?? []) - $nNew) . ' follow-ups</span>' . (!empty($rep['failed']) ? '<span class="pill warn">' . count($rep['failed']) . ' not sent</span>' : '') . '</summary>';
        echo '<div class="tbl" style="margin:0 20px"><table><thead><tr><th>Time</th><th>What</th><th>Business</th><th>To</th><th></th></tr></thead><tbody>';
        foreach ($rep['sent'] ?? [] as $x) echo '<tr><td>' . h($x['at']) . '</td><td>' . h(auto_kind_name($x['kind'])) . '</td><td><b>' . h($x['business']) . '</b>' . (!empty($x['note']) ? '<br><small>' . h($x['note']) . '</small>' : '') . '</td><td>' . h($x['to']) . '</td><td><a href="/' . h($x['slug']) . '/?team=1" target="_blank" rel="noopener">Concept</a></td></tr>';
        if (empty($rep['sent'])) echo '<tr><td colspan="5">Nothing sent.</td></tr>';
        echo '</tbody></table></div>';
        if (!empty($rep['failed'])) { echo '<h3 class="chh">Not sent</h3><ul class="tl">'; foreach ($rep['failed'] as $x) echo '<li><span>✗</span><b>' . h($x['business']) . '</b> · ' . h(auto_kind_name($x['kind'])) . ': ' . h($x['why']) . '</li>'; echo '</ul>'; }
        $man = $rep['manual'] ?? []; echo '<h3 class="chh">Instagram DMs to send by hand (' . count($man) . ')</h3>';
        if ($man) { echo '<ul class="tl">'; foreach ($man as $x) echo '<li><span>•</span>' . h(str_replace('New email', 'First DM', auto_kind_name($x['kind']))) . ' · <b>' . h($x['business']) . '</b>' . ($x['ig'] ? ' · <a href="https://ig.me/m/' . h($x['ig']) . '" target="_blank" rel="noopener">@' . h($x['ig']) . '</a>' : '') . '</li>'; echo '</ul>'; }
        $y = $rep['yesterday'] ?? [];
        echo '<p class="hint" style="margin:10px 20px 18px"><b>Previous 24 hours:</b> ' . (int)($y['mail_opens'] ?? 0) . ' emails opened · ' . count($y['opens'] ?? []) . ' concept opens' . (($y['opens'] ?? []) ? ' (' . h(implode(', ', $y['opens'])) . ')' : '') . ' · reports requested: ' . h(($y['reports'] ?? []) ? implode(', ', $y['reports']) : 'none') . ' · calls booked: ' . h(($y['bookings'] ?? []) ? implode(', ', $y['bookings']) : 'none') . '</p></details>';
    }
}
if ($page === 'views') {
    echo '<h1>Concept views</h1><p class="sub">Who has opened their concept. The first few hours are the best time to follow up.</p><section class="card">';
    if ($bySlug) foreach ($bySlug as $s => $v) echo '<div class="row"><div class="grow"><b>' . h($s) . '</b><small>Last opened ' . h(ago($v['last'])) . ' · ' . h($v['device']) . ($v['from'] ? ' · clicked from ' . h($v['from'] === 'ig' ? 'Instagram DM' : $v['from']) : '') . '</small></div><span class="pill">' . $v['n'] . ($v['n'] === 1 ? ' view' : ' views') . '</span><a class="btn line sm" href="/' . h($s) . '/?team=1" target="_blank" rel="noopener">Open</a></div>';
    else echo '<div class="empty">No views recorded yet.</div>';
    echo '</section><p class="sub">Visits from any browser or network where someone is logged in to this panel are not counted.</p>';
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
    echo '<section class="card" id="leadsdb"><h2>Leads database</h2>';
    if (leads_store() === 'db') {
        $bk = read_json('leads_backup.json', []); $nl = count(sheet_rows());
        echo '<p>✓ ' . $nl . ' leads are stored in the site database. Every change is recorded in the <a href="?p=history">change history</a>. A backup file is saved on the server every day, and the whole list is emailed to the team as a CSV whenever it changed' . (!empty($bk['at']) ? ' (last backup ' . h(ago(gmdate('c', (int)$bk['at']))) . ')' : '') . '.</p><div class="links"><a class="btn line" href="?p=leadscsv">Download all leads (CSV)</a></div>';
    } else {
        echo '<p>The leads are still read from the Google Sheet. Moving them into the site database keeps every row number, adds a full change history and daily backups, and the sheet is then no longer used (it stays as an old copy).</p>';
        if ($isOwner && $gk) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="leads_import"><button class="btn" onclick="return confirm(\'Move all leads from the Google Sheet into the database now?\')">Move the leads into the database</button></form>';
    }
    echo '</section>';
    if (leads_store() !== 'db') {
    echo '<section class="card" id="google"><h2>Leads sheet (Google)</h2>';
    if ($gk) echo '<p>✓ Google key saved for <b>' . h($gk['client_email']) . '</b>. The leads sheet must be shared with this address as <b>Editor</b>.</p><form method="post">' . csrf_field() . '<input type="hidden" name="a" value="gtest"><button class="btn line">Test connection</button></form>';
    else echo '<p class="hint">Lets the Outreach page read the leads sheet and mark rows as Sent. One-time setup:</p><ol class="steps"><li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud</a> with the m.and.s.design.develop account and create a project called <b>sitesbyms</b>.</li><li>Turn on the <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener">Google Sheets API</a> for that project.</li><li>Go to <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service accounts</a> › Create service account › name <b>panel</b> › Done.</li><li>Open it › Keys › Add key › Create new key › <b>JSON</b>. A file downloads.</li><li>Upload that file below.</li><li>In the leads sheet, press Share and add the service account email (shown here after upload) as <b>Editor</b>.</li></ol>';
    if ($isOwner) echo '<form method="post" enctype="multipart/form-data" class="stack">' . csrf_field() . '<input type="hidden" name="a" value="gkey"><label>' . ($gk ? 'Replace the key file' : 'Key file (.json)') . '<input type="file" name="keyfile" accept=".json,application/json"></label><button class="btn' . ($gk ? ' line' : '') . '">Upload key</button></form>';
    echo '</section>';
    }
    if (leads_ready()) { try { $ns = panel_settings(); } catch (Throwable $e) { $ns = PANEL_DEFAULTS; }
        echo '<form class="card" method="post" id="nightly">' . csrf_field() . '<input type="hidden" name="a" value="nightly"><h2>Nightly run</h2><p class="hint">The nightly run reads these from the leads sheet (tab “Panel”) every night.</p><div class="f3">'
            . '<label>New concepts per night<input type="number" name="n_concepts" min="0" max="20" value="' . h($ns['New concepts per night']) . '"></label>'
            . '<label>New candidates per night, per country (UK, USA, Canada, Australia, UAE)<input type="number" name="n_cand" min="0" max="40" value="' . h($ns['New candidates per night']) . '"></label>'
            . '<label>If nothing is Ready, build the best new candidates<select name="auto"><option' . ($ns['If nothing is Ready, build the best new candidates'] === 'Yes' ? ' selected' : '') . '>Yes</option><option' . ($ns['If nothing is Ready, build the best new candidates'] !== 'Yes' ? ' selected' : '') . '>No</option></select></label>'
            . '</div><button class="btn">Save</button></form>';
        $as = $ns + AUTO_DEFAULTS; $hb = read_json('cron_heartbeat.json', []);
        $hbOk = ($hb['at'] ?? 0) > time() - 1800;
        echo '<form class="card" method="post" id="autosend">' . csrf_field() . '<input type="hidden" name="a" value="autosend"><h2>Automatic emails</h2>'
            . '<p class="hint">Every day at this time in each business&#39;s own time zone (08:00 London, 08:00 Sydney, 08:00 New York, 08:00 Dubai...) the panel sends the new emails (rows in Review) and the follow-ups that are due (day 3, 7 and 14). After the London batch it emails you the daily send report for the last 24 hours, every country. Instagram DMs stay manual. Emails are never sent when the concept page belongs to another business, the address is invalid or the text has a placeholder.</p><div class="f3">'
            . '<label>Send automatically<select name="on"><option' . (strcasecmp($as['Auto-send emails'], 'Yes') === 0 ? ' selected' : '') . '>Yes</option><option' . (strcasecmp($as['Auto-send emails'], 'Yes') !== 0 ? ' selected' : '') . '>No</option></select></label>'
            . '<label>Send time (local time of each business)<input type="time" name="time" value="' . h($as['Auto-send time (London)']) . '"></label>'
            . '<label>Max emails per day, per country<input type="number" name="max" min="0" max="100" value="' . h($as['Max automatic emails per day']) . '"></label></div>'
            . '<p class="' . ($hbOk && empty($hb['error']) ? 'hint' : 'err') . '">'
                . (!empty($hb['error']) ? '⚠ The scheduler ran ' . h(ago(gmdate('c', (int)$hb['at']))) . ' but failed: ' . h($hb['error'])
                  : ($hbOk ? '✓ The scheduler is running (last check ' . h(ago(gmdate('c', (int)$hb['at']))) . (!empty($hb['php']) ? ', PHP ' . h($hb['php']) : '') . (!empty($hb['result']) ? ': ' . h($hb['result']) : '') . ').'
                  : (!empty($hb['at']) ? '⚠ The scheduler last ran ' . h(ago(gmdate('c', (int)$hb['at']))) . ' and has stopped. Check the cron job in cPanel.'
                  : '⚠ The scheduler has not run yet. In cPanel › Cron Jobs, add a job every 10 minutes (*/10 * * * *) with the command: <code>/usr/local/bin/php /home/sitesb/public_html/api/_cron.php</code>. This message changes within 10 minutes of the first run.'))) . '</p>'
            . '<button class="btn">Save</button></form>'; }
    echo '<form class="card" method="post">' . csrf_field() . '<input type="hidden" name="a" value="settings"><h2>Calendar &amp; booking</h2>'
        . '<label>Google Calendar secret address (iCal)<input name="ical_url" value="' . h($cfg['ical_url']) . '" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"><span class="hint">With this, times that are busy in your Google Calendar show as Taken on the booking page.' . ($ical !== null ? ' ' . $ical . ' events read from the calendar just now.' : '') . '</span></label>'
        . '<div class="f3"><label>First bookable day<select name="days_ahead">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n == $cfg['days_ahead'] ? ' selected' : '') . '>' . $n . ($n === 1 ? ' day' : ' days') . ' from today</option>', range(1, 7))) . '</select></label>'
        . '<label>Day starts (London)<input name="start" type="time" value="' . h($cfg['start']) . '"></label><label>Day ends (London)<input name="end" type="time" value="' . h($cfg['end']) . '"></label></div>'
        . '<label>Times shown as already booked<select name="held_pct">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n == ($cfg['held_pct'] ?? 45) ? ' selected' : '') . '>' . ($n ? $n . '% of free times' : 'None') . '</option>', [0, 25, 35, 45, 55, 65])) . '</select><span class="hint">Makes the calendar look like a busy studio. The same times stay taken for every visitor, a different pattern each day, and at least 3 times a day always stay open. These times are never bookable.</span></label>'
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
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/admin/admin.css?v=5"></head><body>';
}
function auth_page(string $title, string $inner): void {
    echo head($title) . '<main class="auth"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS" class="logo"><div class="box"><h1>' . h($title) . '</h1>' . $inner . '</div></main></body></html>';
    exit;
}
function layout(string $page, array $nav, string $body, string $msg, bool $isErr, int $openCount, array $me): void {
    global $badge;
    $ic = ['home' => '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>', 'send' => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>', 'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 'chart' => '<path d="M3 3v18h18M7 15l4-4 3 3 5-6"/>', 'pulse' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>', 'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'];
    $tips = ['outreach' => $badge['outreach_hint'] ?? '', 'leads' => 'candidates to approve', 'calls' => 'upcoming calls', 'messages' => 'unanswered', 'activity' => 'visits in the last 24 hours'];
    $info = ['activity' => true, 'calls' => true];
    $cur = in_array($page, ['lead', 'leadnew'], true) ? 'leads' : $page;
    $side = '';
    foreach ($nav as $group => $items) {
        if (!$items) continue;
        $side .= '<p class="sg">' . h($group) . '</p>';
        foreach ($items as [$k, $label, $icon]) {
            $n = (int)($badge[$k] ?? 0);
            $side .= '<a href="?p=' . $k . '"' . ($cur === $k ? ' aria-current="page"' : '') . ($n && !empty($tips[$k]) ? ' title="' . h($tips[$k]) . '"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true">' . $ic[$icon] . '</svg><span>' . h($label) . '</span>'
                . ($n ? '<i class="' . (!empty($info[$k]) ? 'info' : 'act') . '" aria-label="' . $n . ' ' . h($tips[$k] ?? '') . '">' . $n . '</i>' : '') . '</a>';
        }
    }
    echo head('Team panel') . '<div class="app"><aside class="side" id="side"><a class="brand" href="/admin/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span></a><nav aria-label="Panel">' . $side . '</nav>'
        . '<div class="me">' . (!empty($me['name']) ? '<b>' . h($me['name']) . '</b>' : '') . (!empty($me['email']) ? '<small>' . h($me['email']) . '</small>' : '') . '<a href="?p=logout">Log out</a></div></aside>'
        . '<div class="scrim" onclick="document.body.classList.remove(\'nav-open\')"></div>'
        . '<div class="mainwrap"><header class="mtop"><button type="button" class="burger" aria-label="Menu" aria-controls="side" onclick="document.body.classList.toggle(\'nav-open\')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>'
        . ($badge['outreach'] + $badge['messages'] ? '<i>' . ($badge['outreach'] + $badge['messages']) . '</i>' : '') . '</button><a class="brand" href="/admin/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span>Sites By <b>MS</b></span></a></header>'
        . '<main class="wrap">' . ($msg ? '<div class="note' . ($isErr ? ' bad' : '') . '" role="status">' . h($msg) . '</div>' : '') . $body . '</main></div></div></body></html>';
}
