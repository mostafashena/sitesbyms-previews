<?php
// Owner's decision 14 Nov 2026: the team no longer sends anything by hand except Instagram DMs.
// So personal replies queued by Claude and finished competitive analyses are sent automatically,
// during the business's own working hours (Mon–Sat 08:00–19:00 local), from hello@sitesbyms.com.
// An item can be held back with "hold": true (it then waits in Outreach › Personal emails as before).

function ap_open_now(array $lead): bool {
    $now = new DateTime('now', new DateTimeZone(lead_tz($lead)));
    $h = (int)$now->format('G'); $dow = (int)$now->format('N');
    return $dow <= 6 && $h >= 8 && $h < 19;
}
function ap_prev_mid(int $row): ?string {
    foreach (outreach_all() as $o) if ((int)$o['row'] === $row && !empty($o['mid']) && $o['channel'] === 'Email') return $o['mid'];
    return null;
}
function ap_report_text(array $r, string $link): string {
    $greet = trim($r['owner']) !== '' ? 'Hi ' . explode(' ', trim($r['owner']))[0] : 'Hi ' . $r['company'] . ' team';
    return "$greet,\n\nAs promised, here is the competitive analysis our team prepared for " . $r['company'] . ":\n$link\n\n"
        . "It shows where you lead your local competitors, where you may be losing patients, and three priority actions. Your homepage concept is on the same page.\n\n"
        . "We can take care of all of it for you as your growth team: the website, patient data, automatic reminders and review requests, promotions and a monthly report like this one. "
        . "There is no upfront cost: we build and run it, and you only pay for new patients who actually book through it.\n\n"
        . "Would a 15-minute call to walk you through it be useful? You can book a time straight from the page.\n\n"
        . "Best regards,\nMostafa\nSites By MS\nhello@sitesbyms.com · WhatsApp +44 7405 760048";
}
function auto_personal_tick(): string {
    $done = [];
    $today = (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d');
    // 1) Personal emails queued by Claude.
    $q = (array)setting_get('personal_emails', []); $keep = [];
    foreach ($q as $it) {
        try {
            if (!empty($it['hold'])) { $keep[] = $it; continue; }
            $lead = lead_get((int)$it['row'], (string)$it['company']);
            if (in_array($lead['status'], ['Lost', 'Won'], true) || $lead['status'] === 'Skip') { continue; }
            if (!ap_open_now($lead)) { $keep[] = $it; continue; }
            $to = strtolower(trim((string)$it['to']));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL) || trim((string)$it['body']) === '') { $it['hold'] = true; $keep[] = $it; continue; }
            $mid = send_outreach($to, (string)$it['subject'], (string)$it['body'], ap_prev_mid((int)$it['row']), (string)$it['slug'], 'personal');
            if (!$mid) { $keep[] = $it; continue; }
            outreach_log(['row' => (int)$it['row'], 'slug' => (string)$it['slug'], 'business' => $it['company'], 'kind' => 'personal', 'channel' => 'Email', 'to' => $to, 'subject' => $it['subject'], 'body' => $it['body'], 'mid' => $mid, 'by' => 'auto-send']);
            sheet_update_row((int)$it['row'], ['notes' => trim($lead['notes'] . " | Personal email sent $today (auto): " . $it['subject'])]);
            $done[] = 'personal: ' . $it['company'];
        } catch (Throwable $e) { $keep[] = $it; }
    }
    if (count($keep) !== count($q)) setting_set('personal_emails', $keep);
    // 2) Finished competitive analyses ("Report ready: <token> · send to: <email>").
    foreach (sheet_rows(true) as $r) {
        try {
            if (!str_starts_with((string)$r['stage'], 'Report ready')) continue;
            $tok = report_token($r); if (!$tok) continue;
            $sendTo = preg_match('/send to: (.+)$/', $r['stage'], $m) ? trim($m[1]) : '';
            $to = filter_var($sendTo, FILTER_VALIDATE_EMAIL) ? strtolower($sendTo) : strtolower(trim((string)$r['email']));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !ap_open_now($r)) continue;
            $body = ap_report_text($r, 'https://sitesbyms.com/report/?k=' . $tok);
            $mid = send_outreach($to, 'Your competitive analysis: ' . $r['company'], $body, ap_prev_mid((int)$r['_row']), (string)$r['slug'], 'report');
            if (!$mid) continue;
            outreach_log(['row' => (int)$r['_row'], 'slug' => $r['slug'], 'business' => $r['company'], 'kind' => 'report', 'channel' => 'Email', 'to' => $to, 'subject' => '', 'body' => $body, 'mid' => $mid, 'by' => 'auto-send']);
            sheet_update_row((int)$r['_row'], ['stage' => "Report sent $today · $tok"]);
            $done[] = 'report: ' . $r['company'];
        } catch (Throwable $e) {}
    }
    return $done ? implode('; ', $done) : 'nothing to send';
}
