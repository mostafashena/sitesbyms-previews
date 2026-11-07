<?php
// Automatic email sending (new emails and follow-ups) once a day, and the daily send report.
// Run by api/_cron.php from a cPanel cron job. Not reachable from the web (.htaccess blocks /api/_*).
declare(strict_types=1);
require_once __DIR__ . '/_google.php';

const AUTO_DEFAULTS = ['Auto-send emails' => 'Yes', 'Auto-send time (London)' => '08:00', 'Max automatic emails per day' => '40'];

function auto_settings(): array {
    $s = panel_settings() + AUTO_DEFAULTS;
    $t = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($s['Auto-send time (London)']), $m) ? sprintf('%02d:%s', $m[1], $m[2]) : '08:00';
    return ['on' => strcasecmp(trim($s['Auto-send emails']), 'Yes') === 0, 'time' => $t, 'max' => max(0, min(100, (int)$s['Max automatic emails per day']))];
}

/** Why an email must not go out automatically, or null when it is fine. */
function auto_problem(array $r, string $text, string $to, string $subject): ?string {
    if ($why = lead_mismatch($r)) return $why;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'No valid email address in column F.';
    if (trim($subject) === '') return 'No subject in column P.';
    if (mb_strlen(trim($text)) < 60) return 'The message is empty or too short.';
    if (preg_match('/\[(ADD|TODO|NAME|INSERT)|\{\{|\bTODO\b|lorem ipsum/i', $text)) return 'The message still has a placeholder.';
    if (!str_contains($text, 'sitesbyms.com/' . $r['slug'])) return 'The message does not contain this business\'s concept link.';
    return null;
}

/** The emails due today: new emails (status Review) and follow-ups that are due. */
function auto_queue(?string $tz = null): array {
    $today = new DateTime('today', new DateTimeZone(TZ)); $log = outreach_all(); $q = [];
    foreach (sheet_rows(true) as $r) {
        if (!in_array('Email', lead_channels($r), true)) continue;
        if ($tz !== null && lead_tz($r) !== $tz) continue;
        if (strcasecmp($r['status'], 'Review') === 0) $kind = 'first';
        elseif (($fu = lead_followup($r)) && $fu['due'] <= $today) $kind = $fu['kind'];
        else continue;
        if (isset(lead_done($r['_row'], $kind, $log, $r['slug'])['Email'])) continue;
        $q[] = ['r' => $r, 'kind' => $kind];
    }
    usort($q, fn($a, $b) => ($b['kind'] === 'first') <=> ($a['kind'] === 'first'));   // new emails first (fresh concepts are the best chance of a reply); a follow-up can wait a day if the daily limit is reached
    return $q;
}

/** Sends one email for a queued lead and updates the sheet like the panel does. Returns [ok, note]. */
function auto_send_one(array $r, string $kind, string $today): array {
    $to = strtolower(trim($r['email'])); $subject = $r['subject'];
    if (isset(lead_done($r['_row'], $kind, null, $r['slug'])['Email'])) return [false, 'Already sent (by the team from the panel) a moment ago; skipped so it is not sent twice.'];
    $text = lead_text($r, 'Email', $kind);
    if ($why = auto_problem($r, $text, $to, $subject)) return [false, $why];
    $reply = null;
    if ($kind !== 'first') {
        foreach (outreach_all() as $o) if ((int)$o['row'] === $r['_row'] && $o['kind'] === 'first' && $o['channel'] === 'Email' && !empty($o['mid'])) { $reply = $o['mid']; break; }
        if (stripos($subject, 're:') !== 0) $subject = 'Re: ' . $subject;
    }
    $mid = send_outreach($to, $subject, tracked_links($text, 'email'), $reply, $r['slug'], $kind);
    if (!$mid) return [false, 'The mail server did not accept the email.'];
    outreach_log(['row' => $r['_row'], 'slug' => $r['slug'], 'business' => $r['company'], 'kind' => $kind, 'channel' => 'Email', 'to' => $to, 'subject' => $subject, 'body' => $text, 'mid' => $mid, 'by' => 'auto']);
    $cells = [];
    $done = lead_done($r['_row'], $kind, null, $r['slug']);
    $left = array_values(array_diff(lead_channels($r), array_keys($done)));
    if ($kind === 'first') { if ($r['sent'] === '') $cells['sent'] = $today; if (!$left) $cells['status'] = 'Sent'; }
    elseif (!$left) $cells['reply'] = FOLLOWUP_KINDS[$kind] . ' sent ' . $today;
    // the sheet row must still be the same business before we write
    $now = null; foreach (sheet_rows(true) as $x) if ($x['_row'] === $r['_row']) { $now = $x; break; }
    if ($cells && $now && $now['company'] === $r['company']) sheet_update_row($r['_row'], $cells);
    return [true, $left ? 'Instagram DM still to send by hand' : ''];
}

/** Runs the daily send if it is time. Safe to call every few minutes. */
/** Sends each business's emails at the set time in ITS OWN time zone (08:00 in London, Sydney, New York, Dubai...).
 *  One batch per time zone per local day. The daily report email goes out after the London batch and covers the last 24 hours of all batches. */
function autosend_tick(bool $force = false): string {
    $tzL = new DateTimeZone(TZ); $nowL = new DateTime('now', $tzL); $todayL = $nowL->format('Y-m-d');
    $state = read_json('autosend_state.json', []);
    $done = is_array($state['done'] ?? null) ? $state['done'] : (isset($state['done']) ? [TZ => $state['done']] : []);
    $set = auto_settings();
    [$h, $m] = array_map('intval', explode(':', $set['time']));
    $zones = []; try { foreach (sheet_rows(true) as $r) $zones[lead_tz($r)] = true; } catch (Throwable $e) { return 'sheet error: ' . $e->getMessage(); }
    $zones[TZ] = true;
    $due = [];
    foreach (array_keys($zones) as $z) {
        $now = new DateTime('now', new DateTimeZone($z)); $local = $now->format('Y-m-d');
        if (($done[$z] ?? '') === $local && !$force) continue;
        $start = (clone $now)->setTime($h, $m); $end = (clone $start)->modify('+4 hours');
        if ($force || ($now >= $start && $now <= $end)) $due[$z] = $local;
    }
    if (!$due) return 'nothing due now (' . $set['time'] . ' local time in each country)';

    $lock = fopen(data_dir() . '/autosend.lock', 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'another run is busy';
    @set_time_limit(0);
    $out = [];
    foreach ($due as $z => $local) {
        $rep = read_json("autosend-$todayL.json", []) + ['date' => $todayL, 'started' => $nowL->format('H:i'), 'sent' => [], 'failed' => [], 'paused' => false];
        $place = auto_zone_name($z);
        if (!$set['on']) $rep['paused'] = true;
        else {
            $count = count(array_filter($rep['sent'], fn($x) => ($x['tz'] ?? TZ) === $z));
            foreach (auto_queue($z) as $it) {
                $r = $it['r'];
                $row = ['business' => $r['company'], 'kind' => $it['kind'], 'to' => $r['email'], 'slug' => $r['slug'], 'tz' => $z, 'place' => $place, 'ts' => time(),
                        'at' => (new DateTime('now', $tzL))->format('H:i'), 'local' => (new DateTime('now', new DateTimeZone($z)))->format('H:i')];
                if ($count >= $set['max']) { $rep['failed'][] = $row + ['why' => 'Daily limit of ' . $set['max'] . " emails for $place reached; it goes out tomorrow."]; continue; }
                try { [$ok, $note] = auto_send_one($r, $it['kind'], $local); } catch (Throwable $e) { $ok = false; $note = 'Error: ' . $e->getMessage(); }
                if ($ok) { $rep['sent'][] = $row + ['note' => $note]; $count++; if (!getenv('SBM_SHEETS_BASE')) sleep(random_int(20, 45)); }   // spaced out, like a person sending
                else $rep['failed'][] = $row + ['why' => $note];
                write_json("autosend-$todayL.json", $rep);
            }
        }
        $rep['finished'] = (new DateTime('now', $tzL))->format('H:i');
        $rep['batches'][$z] = $rep['finished'];
        write_json("autosend-$todayL.json", $rep);
        $done[$z] = $local; write_json('autosend_state.json', ['done' => $done, 'at' => time()]);
        $out[] = $place . ': ' . count(array_filter($rep['sent'], fn($x) => ($x['tz'] ?? TZ) === $z)) . ' sent';
    }
    if (isset($due[TZ])) {   // one report a day, after the London batch: everything sent in the last 24 hours, all countries
        $since = time() - 86400; $all = ['date' => $todayL, 'started' => '', 'sent' => [], 'failed' => [], 'paused' => !$set['on']];
        foreach ([(clone $nowL)->modify('-1 day')->format('Y-m-d'), $todayL] as $d) { $x = read_json("autosend-$d.json", []);
            foreach (['sent', 'failed'] as $k) foreach ($x[$k] ?? [] as $e) if (($e['ts'] ?? ($d === $todayL ? time() : 0)) >= $since) $all[$k][] = $e; }
        $rep = read_json("autosend-$todayL.json", []);
        $rep['manual'] = $all['manual'] = auto_manual_todo();
        $rep['yesterday'] = $all['yesterday'] = auto_yesterday();
        write_json("autosend-$todayL.json", $rep);
        send_mail(TEAM_TO, auto_subject($all), auto_report_text($all));
    }
    flock($lock, LOCK_UN);
    return implode('; ', $out);
}

/** Short place name for a time zone. */
function auto_zone_name(string $z): string {
    return ['Europe/London' => 'UK', 'Europe/Dublin' => 'Ireland', 'Asia/Dubai' => 'UAE', 'Pacific/Auckland' => 'New Zealand', 'America/New_York' => 'US East', 'America/Chicago' => 'US Central',
            'America/Denver' => 'US Mountain', 'America/Phoenix' => 'Arizona', 'America/Los_Angeles' => 'US West', 'America/Toronto' => 'Canada East', 'America/Vancouver' => 'Canada West',
            'America/Edmonton' => 'Alberta', 'Australia/Sydney' => 'Australia East', 'Australia/Brisbane' => 'Queensland', 'Australia/Adelaide' => 'Adelaide', 'Australia/Perth' => 'Perth'][$z] ?? $z;
}

/** Instagram DMs the team still has to send by hand today. */
function auto_manual_todo(): array {
    $today = new DateTime('today', new DateTimeZone(TZ)); $log = outreach_all(); $out = [];
    foreach (sheet_rows(true) as $r) {
        if (!in_array('Instagram DM', lead_channels($r), true)) continue;
        $kind = strcasecmp($r['status'], 'Review') === 0 ? 'first' : ((($fu = lead_followup($r)) && $fu['due'] <= $today) ? $fu['kind'] : null);
        if (!$kind || isset(lead_done($r['_row'], $kind, $log, $r['slug'])['Instagram DM'])) continue;
        $out[] = ['business' => $r['company'], 'kind' => $kind, 'ig' => ltrim(preg_replace('#^https?://(www\.)?instagram\.com/#', '', rtrim($r['instagram'], '/')), '@')];
    }
    return $out;
}

/** What happened in the last 24 hours: concept opens by source, report requests, bookings, emails opened. */
function auto_yesterday(): array {
    $since = time() - 86400; $names = []; foreach (sheet_rows() as $r) if ($r['slug'] !== '') $names[$r['slug']] = $r['company'];
    $opens = []; foreach (views_all() as $v) if (strtotime($v['at']) >= $since) { $src = str_starts_with((string)$v['ref'], 'via ') ? substr($v['ref'], 4) : 'other'; $opens[] = ($names[$v['slug']] ?? $v['slug']) . ' (' . $src . ', ' . $v['device'] . ')'; }
    $rep = $book = [];
    foreach (sessions_from(events_all(2)) as $x) if (strtotime($x['start']) >= $since) {
        if (!empty($x['flags']['report_submit'])) $rep[] = $names[$x['slug']] ?? $x['slug'];
        if (!empty($x['flags']['book_submit'])) $book[] = $names[$x['slug']] ?? $x['slug'];
    }
    $mailOpens = 0; try { foreach (seen_map() as $k => $s) if (str_contains($k, '|Email') && strtotime((string)$s) >= $since) $mailOpens++; } catch (Throwable $e) {}
    return ['opens' => array_values(array_unique($opens)), 'reports' => array_values(array_unique($rep)), 'bookings' => array_values(array_unique($book)), 'mail_opens' => $mailOpens];
}

function auto_kind_name(string $k): string { return ['first' => 'New email', 'followup' => '1st follow-up', 'followup2' => '2nd follow-up', 'followup3' => 'Last follow-up'][$k] ?? $k; }

function auto_subject(array $rep): string {
    $d = (new DateTime($rep['date'], new DateTimeZone(TZ)))->format('D j M');
    if (!empty($rep['paused'])) return "Daily send report · $d: automatic sending is paused";
    $n = count(array_filter($rep['sent'], fn($x) => $x['kind'] === 'first')); $f = count($rep['sent']) - $n;
    return "Daily send report · $d: $n new emails, $f follow-ups" . ($rep['failed'] ? ', ' . count($rep['failed']) . ' not sent' : '');
}

function auto_report_text(array $rep): string {
    $L = [];
    $L[] = 'Daily send report for ' . (new DateTime($rep['date'], new DateTimeZone(TZ)))->format('l j F Y') . ': the last 24 hours, every country. Each email goes out at the set time in the business\'s own time zone.';
    $L[] = '';
    if (!empty($rep['paused'])) $L[] = 'Automatic sending is paused (panel › Settings › Automatic emails). Nothing was sent.';
    else {
        $L[] = 'SENT AUTOMATICALLY (' . count($rep['sent']) . '):';
        foreach ($rep['sent'] as $x) $L[] = '  ✓ ' . $x['at'] . ' London' . (!empty($x['place']) ? ' (' . $x['local'] . ' ' . $x['place'] . ')' : '') . '  ' . auto_kind_name($x['kind']) . ' · ' . $x['business'] . ' · ' . $x['to'] . ' · https://sitesbyms.com/' . $x['slug'] . '/' . (!empty($x['note']) ? ' (' . $x['note'] . ')' : '');
        if (!$rep['sent']) $L[] = '  Nothing was due today.';
        if ($rep['failed']) { $L[] = ''; $L[] = 'NOT SENT (' . count($rep['failed']) . '), please check in the panel:'; foreach ($rep['failed'] as $x) $L[] = '  ✗ ' . auto_kind_name($x['kind']) . ' · ' . $x['business'] . (!empty($x['place']) ? ' (' . $x['place'] . ')' : '') . ': ' . $x['why']; }
    }
    $L[] = '';
    $man = $rep['manual'] ?? [];
    $L[] = 'TO DO BY HAND TODAY: Instagram DMs (' . count($man) . ')';
    foreach ($man as $x) $L[] = '  • ' . str_replace('New email', 'First DM', auto_kind_name($x['kind'])) . ' · ' . $x['business'] . ($x['ig'] ? ' · instagram.com/' . $x['ig'] : '');
    if (!$man) $L[] = '  None.';
    $y = $rep['yesterday'] ?? [];
    $L[] = '';
    $L[] = 'LAST 24 HOURS:';
    $L[] = '  Emails opened: ' . (int)($y['mail_opens'] ?? 0);
    $L[] = '  Concepts opened (' . count($y['opens'] ?? []) . '): ' . (($y['opens'] ?? []) ? implode(', ', $y['opens']) : 'none');
    $L[] = '  Competitive analyses requested: ' . (($y['reports'] ?? []) ? implode(', ', $y['reports']) : 'none');
    $L[] = '  Calls booked: ' . (($y['bookings'] ?? []) ? implode(', ', $y['bookings']) : 'none');
    $L[] = '';
    $L[] = 'Full report: https://sitesbyms.com/admin/?p=daily';
    $L[] = 'Instagram DMs: https://sitesbyms.com/admin/?p=outreach';
    return implode("\n", $L) . "\n";
}
