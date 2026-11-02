<?php
// "Yes, send me the competitor report" from a concept page. JSON in, JSON out.
declare(strict_types=1);
require __DIR__ . '/_google.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$fail = function (string $m, int $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $m]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('POST only', 405);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (($in['website_url'] ?? '') !== '') { echo json_encode(['ok' => true]); exit; }   // spam trap
if (!rate_limit('report', 5, 3600)) $fail('Too many requests from this connection. Please try again later or message us on WhatsApp.', 429);

$slug = preg_match('/^[a-z0-9-]{1,60}$/', (string)($in['slug'] ?? '')) ? (string)$in['slug'] : '';
$business = one_line(mb_substr((string)($in['business'] ?? ''), 0, 120));
$contact = one_line(mb_substr(trim((string)($in['contact'] ?? '')), 0, 160));
$name = one_line(mb_substr(trim((string)($in['name'] ?? '')), 0, 80));
if ($slug === '' || $business === '') $fail('Something went wrong. Please refresh the page and try again.');
$isEmail = (bool)filter_var($contact, FILTER_VALIDATE_EMAIL);
$isPhone = (bool)preg_match('/^[0-9 +().-]{7,20}$/', $contact);
if ($contact !== '' && !$isEmail && !$isPhone) $fail('Please enter an email address or a WhatsApp number, or leave it empty.');

$team = !empty($_COOKIE['sbm_team']) || is_team_visit();
message_add(['at' => gmdate('c'), 'type' => 'report', 'name' => $name ?: '(not given)', 'business' => $business, 'email' => $isEmail ? $contact : '',
    'phone' => $isPhone ? $contact : '', 'slot' => '', 'how' => '', 'reply' => $isEmail ? 'Email' : ($isPhone ? 'WhatsApp' : 'Same channel as our message'),
    'site' => '', 'slug' => $slug, 'message' => ($team ? '[TEST from the team] ' : '') . 'Asked for the free competitor report from the concept page.', 'file' => '', 'mailed' => true]);

// Mark the lead in the sheet so the nightly run builds the report (column W, Project Stage).
if (!$team && google_key()) {
    try { foreach (sheet_rows() as $r) if ($r['slug'] === $slug) {
        if (!str_starts_with($r['stage'], 'Report')) sheet_update_row($r['_row'], ['stage' => 'Report requested ' . gmdate('Y-m-d') . ' · send to: ' . ($contact !== '' ? $contact : 'same channel')]);
        break; } } catch (Throwable $e) {}
}
if (!$team) {
    $where = $contact !== '' ? $contact : 'not given (reply on the channel we used: email or Instagram)';
    send_mail(TEAM_TO, "Report requested: $business",
        "$business asked for the free competitor report on their concept page.\n\nSend it to: $where" . ($name ? "\nName: $name" : '') .
        "\nConcept: https://sitesbyms.com/$slug/\n\nThe report is prepared in the next nightly run. Then send it from the team panel:\nhttps://sitesbyms.com/admin/?p=messages\n");
}
echo json_encode(['ok' => true]);
