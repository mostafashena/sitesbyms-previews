<?php
// Receives behaviour events from concept pages (sendBeacon, JSON). Team visits and bots are ignored.
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Cache-Control: no-store');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|preview|fetch|monitor|curl|wget|headless/i', $ua)) exit;
if (is_team_visit() || preg_match('#/admin#i', (string)($_SERVER['HTTP_REFERER'] ?? ''))) exit;
if (!rate_limit('event', 600, 3600)) exit;
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) exit;
$id = fn($v) => preg_match('/^[a-z0-9]{16}$/', (string)$v) ? (string)$v : '';
$sid = $id($in['sid'] ?? ''); $vid = $id($in['vid'] ?? '');
$slug = preg_match('/^[a-z0-9-]{1,60}$/', (string)($in['slug'] ?? '')) ? (string)$in['slug'] : '';
if (!$sid || !$vid || !$slug) exit;
$page = in_array($in['page'] ?? '', ['concept', 'report'], true) ? $in['page'] : 'concept';
$src = preg_match('/^[a-z0-9-]{1,20}$/', (string)($in['src'] ?? '')) ? (string)$in['src'] : '';
$device = preg_match('/Mobile|Android|iPhone/i', $ua) ? 'phone' : 'computer';
$allowed = ['about_click', 'open', 'scroll', 'time', 'section', 'click', 'call', 'email', 'bar_hide', 'bar_show', 'nudge_shown', 'nudge_yes', 'nudge_no',
            'report_open', 'report_submit', 'report_error', 'book_open', 'book_slot', 'book_submit', 'ask_click', 'leave'];
$rows = [];
foreach (array_slice((array)($in['e'] ?? []), 0, 40) as $e) {
    if (!is_array($e) || !in_array($e[0] ?? '', $allowed, true)) continue;
    $rows[] = ['sid' => $sid, 'vid' => $vid, 'slug' => $slug, 'page' => $page, 'ev' => $e[0], 'val' => mb_substr(one_line((string)($e[1] ?? '')), 0, 160),
               'src' => $src, 'device' => $device, 't' => max(0, min(86400000, (int)($e[2] ?? 0)))];
}
events_add($rows);
