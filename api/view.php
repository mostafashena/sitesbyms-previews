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
view_add(['at' => gmdate('c'), 'slug' => $slug, 'device' => $device, 'ref' => $ref, 'ip' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $slug), 0, 12)]);

$state = read_json('view_alerts.json', []);
if (time() - (int)($state[$slug] ?? 0) > 12 * 3600) {
    $state[$slug] = time(); write_json('view_alerts.json', $state);
    $london = new DateTime('now', new DateTimeZone(TZ));
    $body = "Someone just opened the concept for \"$slug\" on a $device.\n\nhttps://sitesbyms.com/$slug/\n"
          . 'Time: ' . $london->format('D j M, H:i') . " (London)\n" . ($ref ? 'Came from: ' . ($from === 'email' ? 'your email' : ($from === 'ig' ? 'your Instagram DM' : $ref)) . "\n" : '')
          . "\nGood moment for a friendly call or WhatsApp if you have their number.\n\nTeam panel: https://sitesbyms.com/admin/?p=views\n";
    send_mail(TEAM_TO, "Concept opened: $slug", $body);
}
http_response_code(204);
