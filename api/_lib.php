<?php
// Shared helpers for the Sites By MS server scripts. Not reachable from the web (.htaccess blocks files starting with "_").
declare(strict_types=1);

const TEAM_TO   = 'hello@sitesbyms.com, m.and.s.design.develop@gmail.com';
const FROM_ADDR = 'hello@sitesbyms.com';
const TZ        = 'Europe/London';

function data_dir(): string {
    $home = getenv('HOME') ?: dirname(__DIR__, 2);
    $d = $home . '/sitesbyms_data';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function read_json(string $name, $default = []) {
    $f = data_dir() . '/' . $name;
    if (!is_file($f)) return $default;
    $v = json_decode((string)file_get_contents($f), true);
    return $v === null ? $default : $v;
}
function write_json(string $name, $value): void {
    $f = data_dir() . '/' . $name;
    file_put_contents($f . '.tmp', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($f . '.tmp', $f);
}
function read_lines(string $name): array {
    $f = data_dir() . '/' . $name;
    if (!is_file($f)) return [];
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $j = json_decode($l, true); if (is_array($j)) $out[] = $j; }
    return $out;
}
function append_line(string $name, array $row): void {
    file_put_contents(data_dir() . '/' . $name, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/** Settings edited in the admin panel. */
function config(): array {
    return array_merge([
        'ical_url'   => '',            // Google Calendar "secret address in iCal format"
        'days_ahead' => 2,             // first bookable day = today + this
        'days_shown' => 14,
        'start'      => '10:00',
        'end'        => '17:00',
        'step'       => 30,            // minutes between slot starts
        'length'     => 15,            // call length in minutes
        'weekdays'   => [1, 2, 3, 4, 5],
        'meet_link'  => '',            // optional fixed Google Meet link sent in invites
    ], read_json('config.json', []));
}

function one_line(string $v): string { return trim(preg_replace('/[\r\n]+/', ' ', $v)); }

function send_mail(string $to, string $subject, string $text, string $replyTo = FROM_ADDR, array $attachments = []): bool {
    $headers = 'From: Sites By MS <' . FROM_ADDR . ">\r\nReply-To: " . one_line($replyTo) . "\r\nMIME-Version: 1.0\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (!$attachments) {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        return @mail($to, $subj, $text, $headers, '-f' . FROM_ADDR);
    }
    $b = 'sbm' . bin2hex(random_bytes(8));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"";
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n";
    foreach ($attachments as $a) {
        $type = $a['type'] ?? 'application/octet-stream';
        $msg .= "--$b\r\nContent-Type: $type; name=\"{$a['name']}\"\r\nContent-Transfer-Encoding: base64\r\n"
              . "Content-Disposition: attachment; filename=\"{$a['name']}\"\r\n\r\n" . chunk_split(base64_encode($a['data']));
    }
    $msg .= "--$b--";
    return @mail($to, $subj, $msg, $headers, '-f' . FROM_ADDR);
}

/* ---------- calendar ---------- */

/** Busy periods [startTs, endTs] from saved bookings and the team's Google Calendar feed. */
function busy_periods(): array {
    $busy = [];
    foreach (read_lines('bookings.jsonl') as $b) {
        if (($b['status'] ?? 'booked') === 'cancelled') continue;
        $s = (int)$b['start_ts']; $busy[] = [$s, $s + 60 * (int)($b['length'] ?? 15)];
    }
    return array_merge($busy, ical_busy());
}

/** Reads the iCal feed (cached 10 minutes). Handles UTC, TZID and all-day events; recurring events count on their first date only. */
function ical_busy(): array {
    $url = config()['ical_url'];
    if (!$url || !preg_match('#^https://#', $url)) return [];
    $cache = data_dir() . '/ical_cache.json';
    if (is_file($cache) && filemtime($cache) > time() - 600) return (array)json_decode((string)file_get_contents($cache), true);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: sitesbyms\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return is_file($cache) ? (array)json_decode((string)file_get_contents($cache), true) : [];
    $raw = preg_replace("/\r?\n[ \t]/", '', $raw);           // unfold lines
    $out = [];
    $from = time() - 86400; $to = time() + 60 * 86400;
    foreach (explode('BEGIN:VEVENT', $raw) as $i => $ev) {
        if ($i === 0) continue;
        if (preg_match('/^TRANSP:TRANSPARENT/m', $ev)) continue;  // "free" events
        if (preg_match('/^STATUS:CANCELLED/m', $ev)) continue;
        $s = ical_time($ev, 'DTSTART'); $e = ical_time($ev, 'DTEND');
        if (!$s) continue;
        if (!$e) $e = $s + 3600;
        if ($e < $from || $s > $to) continue;
        $out[] = [$s, $e];
    }
    file_put_contents($cache, json_encode($out));
    return $out;
}
function ical_time(string $ev, string $prop): ?int {
    if (!preg_match('/^' . $prop . '([^:\r\n]*):([0-9T]+Z?)/m', $ev, $m)) return null;
    $params = $m[1]; $v = $m[2];
    try {
        if (strlen($v) === 8) { $d = new DateTime($v, new DateTimeZone(TZ)); return $d->getTimestamp(); }   // all-day
        if (substr($v, -1) === 'Z') return (new DateTime($v, new DateTimeZone('UTC')))->getTimestamp();
        $tz = TZ;
        if (preg_match('/TZID=([^;:]+)/', $params, $t)) $tz = $t[1];
        try { $zone = new DateTimeZone($tz); } catch (Exception $x) { $zone = new DateTimeZone(TZ); }
        return (new DateTime($v, $zone))->getTimestamp();
    } catch (Exception $x) { return null; }
}

/** Days with slots; each slot is free or busy. */
function build_slots(): array {
    $c = config(); $tz = new DateTimeZone(TZ); $busy = busy_periods();
    $len = 60 * (int)$c['length'];
    $day = new DateTime('today', $tz); $day->modify('+' . (int)$c['days_ahead'] . ' days');
    $days = [];
    for ($i = 0; $i < (int)$c['days_shown'] + 10 && count($days) < 10; $i++) {
        $d = clone $day; $d->modify("+$i days");
        if (!in_array((int)$d->format('N'), $c['weekdays'], true)) continue;
        [$sh, $sm] = array_map('intval', explode(':', $c['start'])); [$eh, $em] = array_map('intval', explode(':', $c['end']));
        $t = (clone $d)->setTime($sh, $sm); $end = (clone $d)->setTime($eh, $em);
        $slots = [];
        while ($t < $end) {
            $s = $t->getTimestamp(); $free = true;
            foreach ($busy as $p) { if ($s < $p[1] && $s + $len > $p[0]) { $free = false; break; } }
            $slots[] = ['t' => $t->format('H:i'), 'ts' => $s, 'free' => $free];
            $t->modify('+' . (int)$c['step'] . ' minutes');
        }
        $days[] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D j M'), 'slots' => $slots];
    }
    return $days;
}

function ics_invite(array $b): string {
    $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    $esc = fn(string $s) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
    $desc = "15-minute call about your website with Sites By MS.\n"
          . ($b['meet'] ? "Join: {$b['meet']}\n" : "We'll call you by {$b['how']}.\n")
          . ($b['slug'] ? "Your concept: https://sitesbyms.com/{$b['slug']}/\n" : '')
          . "Need to change it? Reply to this email or WhatsApp +44 7405 760048.";
    return implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Sites By MS//Booking//EN', 'METHOD:REQUEST', 'BEGIN:VEVENT',
        'UID:' . $b['id'] . '@sitesbyms.com', 'DTSTAMP:' . $fmt(time()), 'DTSTART:' . $fmt($b['start_ts']), 'DTEND:' . $fmt($b['start_ts'] + 60 * $b['length']),
        'SUMMARY:' . $esc('Website call: ' . $b['business'] . ' × Sites By MS'),
        'DESCRIPTION:' . $esc($desc), 'LOCATION:' . $esc($b['meet'] ?: $b['how']),
        'ORGANIZER;CN=Sites By MS:mailto:' . FROM_ADDR,
        'ATTENDEE;CN=' . $esc($b['name']) . ';RSVP=TRUE:mailto:' . $b['email'],
        'STATUS:CONFIRMED', 'BEGIN:VALARM', 'TRIGGER:-PT30M', 'ACTION:DISPLAY', 'DESCRIPTION:Call with Sites By MS', 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR', '']);
}

function rate_limit(string $bucket, int $max, int $window): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $f = data_dir() . '/rate_' . $bucket . '_' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? array_values(array_filter((array)json_decode((string)file_get_contents($f), true), fn($t) => $t > $now - $window)) : [];
    if (count($hits) >= $max) return false;
    $hits[] = $now; file_put_contents($f, json_encode($hits));
    return true;
}
