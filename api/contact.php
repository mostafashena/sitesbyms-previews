<?php
// Handles the booking, question and free-concept forms.
// Emails the team, sends the visitor a short receipt, logs the submission outside public_html,
// then redirects to /thanks/.
declare(strict_types=1);
require __DIR__ . '/_lib.php';

const MAX_FILE  = 5 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }


function field(string $k, int $max = 200): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
}
function oneLine(string $v): string { return one_line($v); }
function back(string $msg): void {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<body style="font-family:system-ui;max-width:520px;margin:80px auto;padding:0 20px;line-height:1.6">'
       . '<h1 style="font-size:24px">Something is missing</h1><p>' . htmlspecialchars($msg) . '</p>'
       . '<p><a href="javascript:history.back()">Go back and fix it</a></p></body>';
    exit;
}

// Spam trap: real visitors never fill this.
if (field('website_url') !== '') { header('Location: /thanks/'); exit; }

if (!rate_limit('contact', 6, 3600)) back('Too many messages from this connection. Please try again in an hour, or message us on WhatsApp.');

$type     = in_array(field('type'), ['booking', 'question', 'concept'], true) ? field('type') : 'question';
$name     = oneLine(field('name', 80));
$business = oneLine(field('business', 120));
$email    = oneLine(field('email', 160));
$phone    = oneLine(field('phone', 40));
$slug     = preg_match('/^[a-z0-9-]{1,60}$/', field('slug')) ? field('slug') : '';
$slot     = oneLine(field('slot', 80));
$how      = oneLine(field('how', 40));
$reply    = oneLine(field('reply', 40));
$site     = oneLine(field('site', 200));
$message  = field('message', 5000);

if ($name === '' || $business === '') back('Please add your name and business.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) back('Please check your email address.');
if ($type === 'booking' && $slot === '') back('Please pick a day and time for the call.');
if ($type === 'question' && $message === '') back('Please write your question.');

// Optional attachment.
$att = null;
if (!empty($_FILES['file']['name']) && ($_FILES['file']['error'] ?? 4) === UPLOAD_ERR_OK) {
    $f = $_FILES['file'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($f['size'] > MAX_FILE) back('That file is larger than 5 MB.');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) back('Please attach a JPG, PNG, WEBP or PDF file.');
    $att = ['name' => preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']), 'data' => file_get_contents($f['tmp_name'])];
}

$labels = ['booking' => 'Call request', 'question' => 'Question', 'concept' => 'Free concept request'];
$subject = $labels[$type] . ': ' . $business . ($slot ? " · $slot" : '');

$lines = [
    $labels[$type],
    '',
    "Name: $name",
    "Business: $business",
    "Email: $email",
];
if ($phone)  $lines[] = "Phone: $phone";
if ($slot)   $lines[] = "Requested time: $slot";
if ($how)    $lines[] = "Call by: $how";
if ($reply)  $lines[] = "Reply by: $reply";
if ($site)   $lines[] = "Website / listing: $site";
if ($slug)   $lines[] = "Concept: https://sitesbyms.com/$slug/";
if ($message !== '') { $lines[] = ''; $lines[] = 'Message:'; $lines[] = $message; }
$lines[] = '';
$lines[] = 'Sent ' . gmdate('D j M Y, H:i') . ' UTC';
$body = implode("\n", $lines);

$ok = send_mail(TEAM_TO, $subject, $body, "$name <$email>", $att ? [$att] : []);

// Short receipt to the visitor.
$receipt = $type === 'booking'
    ? "Hi $name,\n\nThanks for booking a call with Sites By MS. You asked for:\n$slot ($how)\n\nWe'll confirm by email within a few hours, with the call link.\n\nBest regards,\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048"
    : "Hi $name,\n\nThanks for your message. A real person will reply within one working day.\n\nBest regards,\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048";
send_mail($email, $type === 'booking' ? 'We got your call request' : 'We got your message', $receipt);

// Save it for the team panel.
message_add(['at' => gmdate('c'), 'type' => $type, 'name' => $name, 'business' => $business, 'email' => $email, 'phone' => $phone,
    'slot' => $slot, 'how' => $how, 'reply' => $reply, 'site' => $site, 'slug' => $slug, 'message' => $message,
    'file' => $att['name'] ?? null, 'mailed' => $ok]);

$q = ['t' => $type, 'n' => $name];
if ($type === 'booking') { $q['slot'] = $slot; $q['how'] = $how; }
header('Location: /thanks/?' . http_build_query($q), true, 303);
