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
$page = in_array($in['page'] ?? '', ['concept', 'report', 'site'], true) ? $in['page'] : 'concept';
$src = preg_match('/^[a-z0-9-]{1,20}$/', (string)($in['src'] ?? '')) ? (string)$in['src'] : '';
$device = preg_match('/Mobile|Android|iPhone/i', $ua) ? 'phone' : 'computer';
$allowed = ['about_click', 'visit', 'page', 'dwell', 'tap', 'rage', 'copy', 'back', 'form_start', 'form_submit', 'form_abandon', 'book_day', 'open', 'scroll', 'time', 'section', 'click', 'call', 'email', 'bar_hide', 'bar_show', 'nudge_shown', 'nudge_yes', 'nudge_no',
            'report_open', 'report_submit', 'report_error', 'book_open', 'book_slot', 'book_submit', 'ask_click', 'leave'];
// Meta's DM link preview: drop its whole visit (its first batch carries the 'open' event with the facebook.com referrer).
$bots = read_json('bot_sids.json', []);
if (isset($bots[$sid])) exit;
foreach ((array)($in['e'] ?? []) as $e) if (is_array($e) && ($e[0] ?? '') === 'open' && is_meta_bot($device, (string)($e[1] ?? ''))) {
    $bots[$sid] = time(); $bots = array_filter($bots, fn($t) => $t > time() - 86400); write_json('bot_sids.json', $bots); exit;
}
$rows = [];
foreach (array_slice((array)($in['e'] ?? []), 0, 40) as $e) {
    if (!is_array($e) || !in_array($e[0] ?? '', $allowed, true)) continue;
    $rows[] = ['sid' => $sid, 'vid' => $vid, 'slug' => $slug, 'page' => $page, 'ev' => $e[0], 'val' => mb_substr(one_line((string)($e[1] ?? '')), 0, 160),
               'src' => $src, 'device' => $device, 't' => max(0, min(86400000, (int)($e[2] ?? 0)))];
}
events_add($rows);

// Warm lead alert (once per concept): this visit is their 2nd+, or they have read for 30+ seconds.
if ($rows && $page === 'concept') {
    $warm = false;
    foreach ($rows as $r) {
        if ($r['ev'] === 'visit' && (int)$r['val'] >= 2) $warm = 'came back for visit ' . (int)$r['val'];
        if ($r['ev'] === 'time' && (int)$r['val'] >= 30) $warm = $warm ?: 'read the page for ' . (int)$r['val'] . ' seconds';
    }
    $wa_state = read_json('warm_alerts.json', []);
    if ($warm && empty($wa_state[$slug])) {
        $wa_state[$slug] = time(); write_json('warm_alerts.json', $wa_state);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        $name = $slug; $body = "Warm lead: someone at \"$slug\" $warm on a $device.\n\nhttps://sitesbyms.com/$slug/\n";
        try {
            require_once __DIR__ . '/_google.php';
            if (leads_ready() && ($lead = lead_by_slug($slug))) {
                $name = $lead['company'];
                $body = "Warm lead: someone at " . $lead['company'] . " $warm (on a $device).\nThis is the best moment to reach out personally.\n\nhttps://sitesbyms.com/$slug/\n";
                if ($wa = lead_wa($lead)) {
                    $msg = lead_greeting($lead) . ", it's Mostafa from Sites By MS. Thanks for taking a look at the homepage idea for " . $lead['company'] . "! Would a free competitive analysis be useful too? It shows how you compare with 4–5 similar businesses nearby, and three clear steps to win more customers. Happy to send it over, or answer any questions here.";
                    $body .= "\nWhatsApp them now (" . $lead['phone'] . "):\nhttps://wa.me/$wa?text=" . rawurlencode($msg) . "\n\nThe message it opens with:\n$msg\n";
                } elseif ($lead['phone'] !== '') $body .= "\nTheir number (" . $lead['phone'] . ") looks like a landline, so a short friendly call works best.\n";
                if ($lead['email'] !== '') $body .= "\nEmail: " . $lead['email'] . "\n";
            }
        } catch (Throwable $e) {}
        send_mail(TEAM_TO, "🔥 Warm lead: $name", $body . "\nTeam panel: https://sitesbyms.com/admin/?p=outreach#warm\n");
    }
}

// Every 30 minutes at most: copy the visits into the "Visits" tab of the leads sheet (after the visitor already has the response).
if ($rows && function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if ($rows && (read_json('visits_sync.json', [])['at'] ?? 0) < time() - 1800) {
    try { require_once __DIR__ . '/_google.php'; if (google_key()) visits_sync(); } catch (Throwable $e) { write_json('visits_sync.json', ['at' => time(), 'error' => $e->getMessage()]); }
}
