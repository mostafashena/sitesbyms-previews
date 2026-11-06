<?php
// Records that a concept page was opened and emails the team (at most once per concept every 12 hours).
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$slug = (string)($_GET['s'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) { http_response_code(204); exit; }
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
if ($ua === '' || preg_match('/bot|crawl|spider|preview|fetch|monitor|curl|wget/i', $ua)) { http_response_code(204); exit; }
// Team visits never count: panel cookie, team network, or a click that came from the panel.
if (is_team_visit() || preg_match('#/admin#i', (string)($_GET['r'] ?? '') . ' ' . (string)($_SERVER['HTTP_REFERER'] ?? ''))) { http_response_code(204); exit; }
if (!rate_limit('view', 30, 3600)) { http_response_code(204); exit; }

$from = preg_match('/^[a-z0-9-]{1,20}$/', (string)($_GET['f'] ?? '')) ? (string)$_GET['f'] : '';
$ref = $from ? 'via ' . $from : substr((string)($_GET['r'] ?? ''), 0, 200);
$device = preg_match('/Mobile|Android|iPhone/i', $ua) ? 'phone' : 'computer';
if (is_meta_bot($device, (string)($_GET['r'] ?? ''))) { http_response_code(204); exit; }   // Meta's DM link preview, not a person
view_add(['at' => gmdate('c'), 'slug' => $slug, 'device' => $device, 'ref' => $ref, 'ip' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $slug), 0, 12)]);

$state = read_json('view_alerts.json', []);
if (time() - (int)($state[$slug] ?? 0) > 12 * 3600) {
    $state[$slug] = time(); write_json('view_alerts.json', $state);
    $london = new DateTime('now', new DateTimeZone(TZ));
    $body = "Someone just opened the concept for \"$slug\" on a $device.\n\nhttps://sitesbyms.com/$slug/\n"
          . 'Time: ' . $london->format('D j M, H:i') . " (London)\n" . ($ref ? 'Came from: ' . ($from === 'email' ? 'your email' : ($from === 'ig' ? 'your Instagram DM' : ($from === 'wa' ? 'your WhatsApp message' : $ref))) . "\n" : '')
          . "\nGood moment for a friendly call or WhatsApp if you have their number.\n";
    // Their row in the sheet: business name, and a ready WhatsApp message when the number is a UK mobile.
    $lead = null; $name = $slug;
    try { require_once __DIR__ . '/_google.php'; if (google_key()) $lead = lead_by_slug($slug); } catch (Throwable $e) { $lead = null; }
    if ($lead) {
        $name = $lead['company'];
        $wa = lead_wa($lead);
        if ($wa) {
            $msg = lead_greeting($lead) . ", it's Mostafa from Sites By MS. Just checking you got the homepage idea we made for " . $lead['company'] . ': ' . tracked_links('https://sitesbyms.com/' . $slug . '/', 'wa')
                 . "\nHappy to tweak anything or answer any questions.";
            $body .= "\nWhatsApp " . $lead['company'] . " with a ready message (" . $lead['phone'] . "):\nhttps://wa.me/$wa?text=" . rawurlencode($msg) . "\n\nThe message it opens with:\n" . $msg . "\n";
        } elseif ($lead['phone'] !== '') $body .= "\nTheir number looks like a landline (" . $lead['phone'] . "), so a call is the way, not WhatsApp.\n";
        else $body .= "\nNo phone number on their row.\n";
    }
    $body .= "\nTeam panel: https://sitesbyms.com/admin/?p=views\n";
    send_mail(TEAM_TO, "Concept opened: $name", $body);
}
http_response_code(204);
