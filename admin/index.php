<?php
// Sites By MS — team dashboard (Persian, right-to-left).
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
    if ($d < 60) return 'همین الان';
    if ($d < 3600) return floor($d / 60) . ' دقیقه پیش';
    if ($d < 86400) return floor($d / 3600) . ' ساعت پیش';
    return floor($d / 86400) . ' روز پیش';
}
function fa_num($s): string { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

/* ---------- first run: create password ---------- */
if (!$admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (strlen($p1) < 10) $msg = 'رمز باید حداقل ۱۰ حرف باشد.';
        elseif ($p1 !== $p2) $msg = 'دو رمز یکی نیستند.';
        else { write_json('admin.json', ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => gmdate('c')]); session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: /admin/'); exit; }
    }
    layout_auth('ساختن رمز پنل', '<p class="sub">اولین بار است که پنل باز می‌شود. یک رمز قوی بگذارید. این رمز را با خواهرتان به اشتراک بگذارید.</p>'
        . ($msg ? '<p class="err">' . h($msg) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><label>رمز جدید<input type="password" name="p1" autocomplete="new-password" required minlength="10" dir="ltr"></label>'
        . '<label>تکرار رمز<input type="password" name="p2" autocomplete="new-password" required dir="ltr"></label><button class="btn">ساختن و ورود</button></form>');
    exit;
}

/* ---------- login / logout ---------- */
if ($page === 'logout') { $_SESSION = []; session_destroy(); header('Location: /admin/'); exit; }
if (empty($_SESSION['ok'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rate_limit('admin', 8, 900)) $msg = 'تلاش زیاد بود. ۱۵ دقیقه بعد دوباره امتحان کنید.';
        elseif (hash_equals($csrf, (string)($_POST['csrf'] ?? '')) && password_verify((string)($_POST['p'] ?? ''), $admin['hash'])) { session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: /admin/'); exit; }
        else $msg = 'رمز درست نیست.';
    }
    layout_auth('ورود به پنل', ($msg ? '<p class="err">' . h($msg) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><label>رمز<input type="password" name="p" autocomplete="current-password" required autofocus dir="ltr"></label><button class="btn">ورود</button></form>');
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
        $msg = 'تماس لغو شد. به مشتری خبر بدهید.';
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
        $msg = 'تنظیمات ذخیره شد.';
    }
    if ($a === 'password') {
        if (!password_verify((string)$_POST['old'], $admin['hash'])) $msg = 'رمز فعلی درست نیست.';
        elseif (strlen((string)$_POST['new']) < 10) $msg = 'رمز جدید باید حداقل ۱۰ حرف باشد.';
        else { $admin['hash'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT); write_json('admin.json', $admin); $msg = 'رمز عوض شد.'; }
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
$typeFa = ['booking' => 'درخواست تماس', 'question' => 'سؤال', 'concept' => 'درخواست طرح رایگان'];
$howFa = ['Video call (Google Meet)' => 'تماس تصویری', 'WhatsApp call' => 'واتس‌اپ', 'Phone call' => 'تلفن'];

/* ---------- views ---------- */
function bookingRow(array $b, string $csrf, array $howFa): string {
    $past = $b['start_ts'] < time();
    return '<div class="row"><div class="date"><b>' . h(london($b['start_ts'], 'j')) . '</b><span>' . h(london($b['start_ts'], 'M')) . '</span></div>'
        . '<div class="grow"><b>' . h($b['business']) . '</b> · ' . h($b['name']) . '<small dir="ltr" style="text-align:right">' . h(london($b['start_ts'], 'D H:i')) . ' London · ' . h($b['email']) . ($b['phone'] ? ' · ' . h($b['phone']) : '') . '</small>'
        . '<small>' . h($howFa[$b['how']] ?? $b['how']) . ($b['slug'] ? ' · <a href="/' . h($b['slug']) . '/?team=1" target="_blank">طرح</a>' : '') . '</small></div>'
        . ($b['status'] === 'cancelled' ? '<span class="pill grey">لغو شده</span>' : ($past ? '<span class="pill grey">گذشته</span>' : '<form method="post" onsubmit="return confirm(\'این تماس لغو شود؟\')"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="' . h($b['id']) . '"><button class="btn line sm">لغو</button></form>'))
        . '</div>';
}
function msgRow(array $m, string $csrf, array $handled, array $typeFa): string {
    $k = $m['at'] . $m['email']; $done = isset($handled[$k]);
    return '<div class="row' . ($done ? ' muted' : '') . '"><div class="grow"><span class="pill">' . h($typeFa[$m['type']] ?? $m['type']) . '</span> <b>' . h($m['business']) . '</b> · ' . h($m['name'])
        . '<small dir="ltr" style="text-align:right">' . h($m['email']) . (!empty($m['phone']) ? ' · ' . h($m['phone']) : '') . (!empty($m['site']) ? ' · ' . h($m['site']) : '') . '</small>'
        . (!empty($m['slot']) ? '<small dir="ltr" style="text-align:right">' . h($m['slot']) . '</small>' : '')
        . (!empty($m['message']) ? '<p class="msg" dir="auto">' . nl2br(h($m['message'])) . '</p>' : '')
        . '<small>' . h(ago(strtotime($m['at']))) . (!empty($m['file']) ? ' · فایل پیوست در ایمیل' : '') . '</small></div>'
        . '<div class="acts"><a class="btn line sm" href="mailto:' . h($m['email']) . '">جواب</a><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="' . ($done ? 'undone' : 'done') . '"><input type="hidden" name="k" value="' . h($k) . '"><button class="btn ' . ($done ? 'line ' : '') . 'sm">' . ($done ? 'برگرداندن' : 'انجام شد') . '</button></form></div></div>';
}

$nav = [['home', 'خلاصه'], ['calls', 'تماس‌ها'], ['messages', 'پیام‌ها'], ['views', 'بازدید طرح‌ها'], ['settings', 'تنظیمات']];
ob_start();
if ($page === 'home') {
    echo '<h1>سلام 👋</h1><p class="sub">وضعیت امروز Sites By MS</p>';
    echo '<div class="stats"><a href="?p=calls" class="stat"><b>' . fa_num(count($upcoming)) . '</b><span>تماس پیش رو</span></a><a href="?p=messages" class="stat"><b>' . fa_num(count($open)) . '</b><span>پیام بی‌جواب</span></a><a href="?p=views" class="stat"><b>' . fa_num(count($week)) . '</b><span>بازدید طرح در ۷ روز</span></a><a href="?p=views" class="stat"><b>' . fa_num(count($bySlug)) . '</b><span>طرح دیده‌شده</span></a></div>';
    echo '<div class="g2"><section class="card"><div class="ch"><h2>تماس‌های بعدی</h2><a href="?p=calls">همه</a></div>' . ($upcoming ? implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), array_slice($upcoming, 0, 4))) : '<div class="empty">هنوز تماسی رزرو نشده.</div>') . '</section>';
    echo '<section class="card"><div class="ch"><h2>آخرین بازدیدها</h2><a href="?p=views">همه</a></div>';
    if ($views) foreach (array_slice($views, 0, 6) as $v) echo '<div class="row"><div class="grow"><b dir="ltr">' . h($v['slug']) . '</b><small>' . h(ago(strtotime($v['at']))) . ' · ' . ($v['device'] === 'phone' ? 'موبایل' : 'کامپیوتر') . '</small></div><a class="btn line sm" href="/' . h($v['slug']) . '/?team=1" target="_blank">طرح</a></div>';
    else echo '<div class="empty">هنوز کسی طرحی را باز نکرده. وقتی مشتری لینکش را باز کند، اینجا و در ایمیل خبر می‌دهیم.</div>';
    echo '</section></div>';
    echo '<section class="card"><div class="ch"><h2>پیام‌های بی‌جواب</h2><a href="?p=messages">همه</a></div>' . ($open ? implode('', array_map(fn($m) => msgRow($m, $csrf, $handled, $typeFa), array_slice($open, 0, 3))) : '<div class="empty">همه جواب داده شده‌اند.</div>') . '</section>';
    echo '<section class="card"><h2>لینک‌های مفید</h2><div class="links"><a class="btn line" href="https://docs.google.com/spreadsheets/d/1NZFcaeoG7fR8--nepcmujsFJAZzfb4r1sCh-_3Lbs-s/edit" target="_blank" rel="noopener">شیت سرنخ‌ها</a><a class="btn line" href="/demo/" target="_blank">دموی پورتال مشتری</a><a class="btn line" href="/" target="_blank">سایت</a></div></section>';
}
if ($page === 'calls') {
    echo '<h1>تماس‌ها</h1><p class="sub">همهٔ تماس‌هایی که مشتری‌ها رزرو کرده‌اند. زمان‌ها به وقت لندن است.</p>';
    echo '<section class="card"><h2>پیش رو</h2>' . ($upcoming ? implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), $upcoming)) : '<div class="empty">تماسی در پیش نیست.</div>') . '</section>';
    $past = array_reverse(array_values(array_filter($bookings, fn($b) => !in_array($b, $upcoming, true))));
    if ($past) echo '<section class="card"><h2>گذشته و لغو شده</h2>' . implode('', array_map(fn($b) => bookingRow($b, $csrf, $howFa), array_slice($past, 0, 30))) . '</section>';
}
if ($page === 'messages') {
    echo '<h1>پیام‌ها</h1><p class="sub">سؤال‌ها، درخواست‌های طرح رایگان و درخواست‌های تماس از فرم‌های سایت.</p>';
    echo '<section class="card">' . ($messages ? implode('', array_map(fn($m) => msgRow($m, $csrf, $handled, $typeFa), $messages)) : '<div class="empty">هنوز پیامی نیامده.</div>') . '</section>';
}
if ($page === 'views') {
    echo '<h1>بازدید طرح‌ها</h1><p class="sub">چه کسی طرحش را باز کرده. بهترین زمان برای پیگیری همان ساعات اول است.</p><section class="card">';
    if ($bySlug) foreach ($bySlug as $s => $v) echo '<div class="row"><div class="grow"><b dir="ltr">' . h($s) . '</b><small>آخرین بار ' . h(ago($v['last'])) . ' · ' . ($v['device'] === 'phone' ? 'موبایل' : 'کامپیوتر') . '</small></div><span class="pill">' . fa_num($v['n']) . ' بار</span><a class="btn line sm" href="/' . h($s) . '/?team=1" target="_blank">طرح</a></div>';
    else echo '<div class="empty">هنوز بازدیدی ثبت نشده.</div>';
    echo '</section><p class="sub">بازدیدهای خودتان ثبت نمی‌شود، به شرط اینکه هر طرح را یک بار با <span dir="ltr">?team=1</span> باز کرده باشید.</p>';
}
if ($page === 'settings') {
    $wd = $cfg['weekdays']; $days = [1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه'];
    $ical = $cfg['ical_url'] ? count(ical_busy()) : null;
    echo '<h1>تنظیمات</h1>';
    echo '<form class="card" method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="settings"><h2>تقویم و رزرو</h2>'
        . '<label>آدرس مخفی تقویم گوگل (iCal)<input name="ical_url" dir="ltr" value="' . h($cfg['ical_url']) . '" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"><span class="hint">با این آدرس، زمان‌هایی که در تقویم گوگل‌تان پر است، روی صفحهٔ رزرو «Taken» نشان داده می‌شوند.'
        . ($ical !== null ? ' الان ' . fa_num($ical) . ' رویداد از تقویم خوانده شد.' : '') . '</span></label>'
        . '<label>لینک ثابت Google Meet (اختیاری)<input name="meet_link" dir="ltr" value="' . h($cfg['meet_link']) . '" placeholder="https://meet.google.com/abc-defg-hij"><span class="hint">اگر بگذارید، در دعوت‌نامهٔ تقویم برای مشتری فرستاده می‌شود.</span></label>'
        . '<div class="f3"><label>اولین روز قابل رزرو<select name="days_ahead">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . ($n == $cfg['days_ahead'] ? ' selected' : '') . '>' . fa_num($n) . ' روز بعد</option>', range(1, 7))) . '</select></label>'
        . '<label>شروع ساعت کاری (لندن)<input name="start" type="time" value="' . h($cfg['start']) . '" dir="ltr"></label><label>پایان ساعت کاری<input name="end" type="time" value="' . h($cfg['end']) . '" dir="ltr"></label></div>'
        . '<fieldset><legend>روزهای کاری</legend><div class="wd">' . implode('', array_map(fn($n) => '<label class="chk"><input type="checkbox" name="wd[]" value="' . $n . '"' . (in_array($n, $wd, true) ? ' checked' : '') . '>' . $days[$n] . '</label>', array_keys($days))) . '</div></fieldset>'
        . '<button class="btn">ذخیره</button></form>';
    echo '<form class="card" method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="a" value="password"><h2>تغییر رمز</h2><div class="f3"><label>رمز فعلی<input type="password" name="old" dir="ltr" autocomplete="current-password"></label><label>رمز جدید<input type="password" name="new" dir="ltr" autocomplete="new-password" minlength="10"></label></div><button class="btn line">عوض کن</button></form>';
}
$body = ob_get_clean();
layout($page, $nav, $body, $msg, count($open));

/* ---------- layout ---------- */
function head(string $title): string {
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' · پنل Sites By MS</title>'
        . '<link rel="icon" href="/assets/brand/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700;800&family=Plus+Jakarta+Sans:wght@600;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/admin/admin.css"></head><body>';
}
function layout_auth(string $title, string $inner): void {
    echo head($title) . '<main class="auth"><img src="/assets/brand/logo-on-dark.png" alt="Sites By MS" class="logo"><div class="box"><h1>' . h($title) . '</h1>' . $inner . '</div></main></body></html>';
}
function layout(string $page, array $nav, string $body, string $msg, int $openCount): void {
    echo head('پنل') . '<header class="top"><a class="brand" href="/admin/"><img src="/assets/brand/mark-sm-dark.png" alt=""><span dir="ltr">Sites By <b>MS</b></span><em>پنل تیم</em></a>'
        . '<nav aria-label="پنل">' . implode('', array_map(fn($n) => '<a href="?p=' . $n[0] . '"' . ($page === $n[0] ? ' aria-current="page"' : '') . '>' . $n[1] . ($n[0] === 'messages' && $openCount ? ' <i>' . fa_num($openCount) . '</i>' : '') . '</a>', $nav))
        . '<a href="?p=logout" class="out">خروج</a></nav></header><main class="wrap">' . ($msg ? '<div class="note" role="status">' . h($msg) . '</div>' : '') . $body . '</main></body></html>';
}
