<?php
// Books a call: re-checks the slot is free, saves it, emails the team and the client a calendar invite.
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
if (!empty($in['website_url'])) { echo json_encode(['ok' => true]); exit; }          // spam trap
if (!rate_limit('book', 5, 3600)) fail('Too many bookings from this connection. Please message us on WhatsApp.', 429);

$g = fn(string $k, int $max = 160) => mb_substr(one_line((string)($in[$k] ?? '')), 0, $max);
$name = $g('name', 80); $business = $g('business', 120); $email = $g('email'); $phone = $g('phone', 40);
$how = in_array($g('how'), ['Video call (Google Meet)', 'WhatsApp call', 'Phone call'], true) ? $g('how') : 'Video call (Google Meet)';
$slug = preg_match('/^[a-z0-9-]{1,60}$/', $g('slug')) ? $g('slug') : '';
$ts = (int)($in['ts'] ?? 0);

if ($name === '' || $business === '') fail('Please add your name and business.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please check your email address.');
if ($how !== 'Video call (Google Meet)' && $phone === '') fail('Please add a phone or WhatsApp number so we can call you.');

// The slot must still be in the list and free.
$ok = false;
foreach (build_slots() as $d) foreach ($d['slots'] as $s) if ($s['ts'] === $ts && $s['free']) $ok = true;
if (!$ok) fail('Sorry, that time was just taken. Please pick another one.', 409);

$c = config();
$b = ['id' => 'b' . date('ymd') . bin2hex(random_bytes(3)), 'created' => gmdate('c'), 'start_ts' => $ts, 'length' => (int)$c['length'],
      'name' => $name, 'business' => $business, 'email' => $email, 'phone' => $phone, 'how' => $how, 'slug' => $slug,
      'meet' => $how === 'Video call (Google Meet)' ? (string)$c['meet_link'] : '', 'status' => 'booked'];
booking_add($b);
@unlink(data_dir() . '/ical_cache.json');

$when = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(TZ))->format('l j F, H:i');
$ics = ['name' => 'invite.ics', 'type' => 'text/calendar; charset=UTF-8; method=REQUEST', 'data' => ics_invite($b)];

$team = "New call booked\n\n$when (London), {$b['length']} min\n$how\n\nName: $name\nBusiness: $business\nEmail: $email\n"
      . ($phone ? "Phone: $phone\n" : '') . ($slug ? "Concept: https://sitesbyms.com/$slug/\n" : '')
      . ($how === 'Video call (Google Meet)' && !$b['meet'] ? "\nNo fixed Meet link is set, so please send them one before the call.\n" : '')
      . "\nThe attached invite adds it to your calendar.";
send_mail(TEAM_TO, "Call booked: $business · $when", $team, "$name <$email>", [$ics]);

$client = "Hi $name,\n\nYou're booked for a 15-minute call with Sites By MS:\n\n$when (London time)\n$how\n"
        . ($b['meet'] ? "Join: {$b['meet']}\n" : ($how === 'Video call (Google Meet)' ? "We'll email you the video link before the call.\n" : "We'll call you on $phone.\n"))
        . "\nThe attached invite adds it to your calendar.\nNeed to change the time? Just reply to this email.\n\nSee you soon,\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048";
send_mail($email, "You're booked: $when", $client, FROM_ADDR, [$ics]);

echo json_encode(['ok' => true, 'when' => $when, 'how' => $how, 'id' => $b['id']]);
