<?php
// Records that a concept page was opened and emails the team (at most once per concept every 12 hours).
declare(strict_types=1);

const TEAM_TO   = 'hello@sitesbyms.com, m.and.s.design.develop@gmail.com';
const FROM_ADDR = 'hello@sitesbyms.com';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$slug = (string)($_GET['s'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) { http_response_code(204); exit; }
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
if ($ua === '' || preg_match('/bot|crawl|spider|preview|fetch|monitor|curl|wget/i', $ua)) { http_response_code(204); exit; }

$home    = getenv('HOME') ?: dirname(__DIR__, 2);
$dataDir = $home . '/sitesbyms_data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0700, true);

$ref = substr((string)($_GET['r'] ?? ''), 0, 200);
$device = preg_match('/Mobile|Android|iPhone/i', $ua) ? 'phone' : 'computer';
$entry = ['at' => gmdate('c'), 'slug' => $slug, 'device' => $device, 'ref' => $ref, 'ip' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $slug), 0, 12)];
file_put_contents($dataDir . '/views.jsonl', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

$stateFile = $dataDir . '/view_alerts.json';
$state = is_file($stateFile) ? (array)json_decode((string)file_get_contents($stateFile), true) : [];
$last = (int)($state[$slug] ?? 0);
if (time() - $last > 12 * 3600) {
    $state[$slug] = time();
    file_put_contents($stateFile, json_encode($state), LOCK_EX);
    $london = new DateTime('now', new DateTimeZone('Europe/London'));
    $subject = "Concept opened: $slug";
    $body = "Someone just opened the concept for \"$slug\" on a $device.\n\n"
          . "https://sitesbyms.com/$slug/\n"
          . 'Time: ' . $london->format('D j M, H:i') . " (London)\n"
          . ($ref ? "Came from: $ref\n" : '')
          . "\nGood moment for a friendly call or WhatsApp if you have their number.\n";
    $headers = 'From: Sites By MS <' . FROM_ADDR . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    @mail(TEAM_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . FROM_ADDR);
}
http_response_code(204);
