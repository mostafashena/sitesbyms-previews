<?php
// Copies new emails that arrive in the hello@sitesbyms.com mailbox to the team's Gmail, and keeps a log the API can read.
// Why: a plain cPanel forwarder passes the original sender on, and Gmail often refuses or silently drops such forwarded
// mail (the sender's own SPF/DMARC fails when it comes from our server). Here every copy is a NEW email from our own domain,
// with Reply-To set to the original sender and the original attached, so it always passes and "Reply" in Gmail answers them.
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

const INBOX_USER = 'hello';
const INBOX_DOMAIN = 'sitesbyms.com';

/** Maildir folders of hello@ (inbox and spam), as cPanel/Dovecot keeps them. */
function inbox_dirs(): array {
    $home = getenv('HOME') ?: dirname(data_dir());
    $base = rtrim($home, '/') . '/mail/' . INBOX_DOMAIN . '/' . INBOX_USER;
    $out = [];
    foreach (['' => 'inbox', '/.spam' => 'spam', '/.Junk' => 'spam'] as $sub => $kind)
        foreach (['new', 'cur'] as $d) if (is_dir("$base$sub/$d")) $out[] = ["$base$sub/$d", $kind];
    return $out;
}

function mime_header(string $h): string { $d = @iconv_mime_decode($h, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'); return trim($d !== false ? $d : $h); }
function mime_split(string $raw): array {
    $p = preg_split("/\r?\n\r?\n/", $raw, 2); $head = preg_replace("/\r?\n[ \t]+/", ' ', $p[0] ?? ''); $h = [];
    foreach (preg_split("/\r?\n/", $head) as $line) if (preg_match('/^([\w-]+):\s*(.*)$/', $line, $m)) $h[strtolower($m[1])] ??= $m[2];
    return [$h, $p[1] ?? ''];
}
/** Best-effort plain text of a message (first text/plain part, else text/html without tags). */
function mime_text(string $raw, int $depth = 0): string {
    [$h, $body] = mime_split($raw);
    $ct = strtolower($h['content-type'] ?? 'text/plain');
    if ($depth < 5 && str_starts_with($ct, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $h['content-type'], $m)) {
        $parts = explode('--' . $m[1], $body); $html = '';
        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n"); if ($part === '' || str_starts_with($part, '--')) continue;
            [$ph] = mime_split($part); $pct = strtolower($ph['content-type'] ?? 'text/plain');
            if (str_starts_with($pct, 'text/plain')) return mime_text($part, $depth + 1);
            if (str_starts_with($pct, 'multipart/')) { $t = mime_text($part, $depth + 1); if ($t !== '') return $t; }
            if ($html === '' && str_starts_with($pct, 'text/html')) $html = mime_text($part, $depth + 1);
        }
        return $html;
    }
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($enc === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
    elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
    if (preg_match('/charset="?([\w-]+)/i', $ct, $m) && strtolower($m[1]) !== 'utf-8') $body = (string)@mb_convert_encoding($body, 'UTF-8', $m[1]);
    if (str_starts_with($ct, 'text/html')) $body = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/tr)[^>]*>#i', "\n", preg_replace('#<(style|script)[^>]*>.*?</\1>#is', '', $body))), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body)));
}

/** Relays new messages. First run: relays the last 3 days, marks older ones as already seen. */
function inbox_relay(int $max = 25): string {
    $dirs = inbox_dirs(); if (!$dirs) return 'mailbox folder not found';
    $seen = read_json('inbox_seen.json', []); $first = !$seen; $n = 0; $since = time() - 3 * 86400;
    $files = [];
    foreach ($dirs as [$d, $kind]) foreach (glob($d . '/*') ?: [] as $f) if (is_file($f)) $files[] = [$f, $kind, (int)@filemtime($f)];
    usort($files, fn($a, $b) => $a[2] <=> $b[2]);
    foreach ($files as [$f, $kind, $mt]) {
        $id = explode(':', basename($f))[0];
        if (isset($seen[$id])) continue;
        if ($first && $mt < $since) { $seen[$id] = $mt; continue; }
        if ($n >= $max) break;
        $raw = (string)@file_get_contents($f, false, null, 0, 8 * 1024 * 1024); [$h] = mime_split($raw);
        $from = mime_header($h['from'] ?? ''); $subject = mime_header($h['subject'] ?? '(no subject)');
        $seen[$id] = $mt;
        if (stripos($from, INBOX_USER . '@' . INBOX_DOMAIN) !== false) continue;     // our own alerts and copies
        preg_match('/[\w.+\'-]+@[\w-]+(\.[\w-]+)+/', $from, $em); $addr = strtolower($em[0] ?? '');
        $text = mime_text($raw);
        if ($text === '') { [, $bodyRaw] = mime_split($raw); $text = trim(html_entity_decode(strip_tags((string)(preg_match('/^[A-Za-z0-9+\/=\s]+$/', trim(substr($bodyRaw, 0, 2000))) ? base64_decode(preg_replace('/\s+/', '', $bodyRaw)) : quoted_printable_decode($bodyRaw))), ENT_QUOTES, 'UTF-8')); $text = mb_substr(preg_replace("/\n{3,}/", "\n\n", $text), 0, 6000); }
        $entry = ['at' => gmdate('c', $mt ?: time()), 'from' => $from, 'email' => $addr, 'subject' => $subject, 'date' => mime_header($h['date'] ?? ''), 'folder' => $kind, 'text' => mb_substr($text, 0, 6000)];
        append_line('inbox.jsonl', $entry);
        $body = "New email to hello@sitesbyms.com" . ($kind === 'spam' ? " (it landed in the SPAM folder)" : '') . ".\n\nFrom: $from\nDate: " . $entry['date'] . "\nSubject: $subject\n\nPress Reply in Gmail to answer them directly (the reply goes to $addr).\n\n----------------------------------------\n\n" . mb_substr($text, 0, 20000) . "\n";
        // Plain text only: Gmail silently dropped the first copies that carried the original message as an attachment.
        $ok = send_mail(TEAM_TO, 'Reply from ' . ($addr ?: 'someone') . ': ' . $subject, $body, $addr ?: FROM_ADDR);
        $log = read_json('inbox_mailed.json', []); $log[$id] = ['ok' => $ok, 'at' => time()]; write_json('inbox_mailed.json', array_slice($log, -300, null, true));
        $n++;
    }
    if (count($seen) > 5000) $seen = array_slice($seen, -4000, null, true);
    write_json('inbox_seen.json', $seen);
    return $n ? "$n new email(s) copied to Gmail" : 'no new email';
}
/** Forget the last $days days so the next run copies those emails to Gmail again. */
function inbox_resend(int $days = 3): int {
    $seen = read_json('inbox_seen.json', []); $cut = time() - $days * 86400; $n = 0;
    foreach ($seen as $id => $t) if ((int)$t >= $cut) { unset($seen[$id]); $n++; }
    write_json('inbox_seen.json', $seen);
    $keep = array_values(array_filter(read_lines("inbox.jsonl"), fn($x) => strtotime($x["at"]) < $cut)); file_put_contents(data_dir() . "/inbox.jsonl", implode("", array_map(fn($x) => json_encode($x, JSON_UNESCAPED_UNICODE) . "\n", $keep)));
    return $n;
}
function inbox_recent(int $limit = 100): array { return array_slice(array_reverse(read_lines('inbox.jsonl')), 0, $limit); }
