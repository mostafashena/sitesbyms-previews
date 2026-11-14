<?php
// cPanel cron job, every 10 minutes:  /usr/local/bin/php /home/sitesb/public_html/api/_cron.php
// Sends the day's emails at the time set in the panel (default 08:00 London) and emails the daily send report.
// This first part uses only old PHP syntax, so that even on an old PHP version the panel can show what went wrong.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$home = getenv('HOME') ? getenv('HOME') : dirname(dirname(__DIR__));
$dir = $home . '/sitesbyms_data'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
$hbFile = $dir . '/cron_heartbeat.json';
$hb = array('at' => time(), 'php' => PHP_VERSION, 'started' => true);
@file_put_contents($hbFile, json_encode($hb));
register_shutdown_function(function () use ($hbFile, $hb) {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR), true)) {
        $hb['error'] = $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')';
        @file_put_contents($hbFile, json_encode($hb));
    }
});
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $hb['error'] = 'This cron uses PHP ' . PHP_VERSION . '; it needs PHP 8. Use the PHP 8 path in the cron command (e.g. /usr/local/bin/ea-php81 or /opt/cpanel/ea-php81/root/usr/bin/php).';
    @file_put_contents($hbFile, json_encode($hb)); fwrite(STDERR, $hb['error'] . "\n"); exit(1);
}
require __DIR__ . '/_autosend.php';
// One-off deliverability test, requested by committing api/_mailtest.json ({"id": "...", "to": "...@srv1.mail-tester.com"}).
// Sends one realistic outreach email (same path, headers, tracking image and link as the real ones), once per id.
try {
    $mt = is_file(__DIR__ . '/_mailtest.json') ? json_decode((string)file_get_contents(__DIR__ . '/_mailtest.json'), true) : null;
    if ($mt && !empty($mt['id']) && (preg_match('/^[a-z0-9-]+@srv\d*\.mail-tester\.com$/i', (string)($mt['to'] ?? '')) || strcasecmp((string)($mt['to'] ?? ''), TEAM_TO) === 0)) {
        $done = read_json('mailtest_done.json', []);
        if (empty($done[$mt['id']])) {
            $text = !empty($mt['text']) ? (string)$mt['text'] : "Hi Gjulnara,\n\nYour guests say it best: 4.9 from 425 reviews, and one Lebanese diner wrote that Annab feels like home. When I searched for Annab, though, I mostly found OpenTable and delivery listings, and none of them tell your story.\n\nSo our team made a homepage idea for you: https://sitesbyms.com/\nIt brings your story, menu, private parties and booking together on one page that works well on a phone.\n\nOur team can also put together a free competitive analysis for Annab: how you compare with similar restaurants nearby and where you may be losing customers. Would you like us to send it over? Just reply \"yes\".\n\nBest regards,\nMostafa\nSites By MS\nhello@sitesbyms.com · sitesbyms.com\nWhatsApp / Telegram: +44 7405 760048\n\nIf you'd rather not hear from us again, just reply and let me know.";
            $ok = send_outreach($mt['to'], (string)($mt['subject'] ?? 'A homepage idea for Annab Brasserie'), tracked_links($text, 'email'), null, 'deliverability-test', 'first');
            $done[$mt['id']] = ['at' => gmdate('c'), 'to' => $mt['to'], 'ok' => (bool)$ok];
            write_json('mailtest_done.json', $done);
            echo date('c') . ' mail test ' . ($ok ? 'sent' : 'FAILED') . ' to ' . $mt['to'] . "\n";
        }
    }
} catch (Throwable $e) { fwrite(STDERR, 'Mail test error: ' . $e->getMessage() . "\n"); }
$force = in_array('--now', $argv, true);
// "Send now" from the API / panel: one extra run today (e.g. the owner raised the daily limit and wants the rest out now).
$sn = setting_get('autosend_now', null);
if (is_array($sn) && ($sn['day'] ?? '') === (new DateTime('now', new DateTimeZone(TZ)))->format('Y-m-d')) { setting_set('autosend_now', null); $force = true; }
// Concept pages taken down (opt-outs): the deploy only adds files, so delete these folders from the live site here.
foreach ((array)json_decode((string)@file_get_contents(__DIR__ . '/_removed.json'), true) as $gone) {
    $dir = dirname(__DIR__) . '/' . $gone;
    if (!is_string($gone) || !preg_match('/^[a-z0-9-]{2,60}$/', $gone) || in_array($gone, ['api', 'admin', 'assets', 'about', 'book', 'ask', 'report', 'reports', 'privacy', 'thanks', 'demo'], true) || !is_dir($dir)) continue;
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    if (@rmdir($dir)) echo date('c') . " removed /$gone/\n";
}
// One-time move of the leads from the Google Sheet into the database (requested by the owner on 6 Nov 2026).
if (is_file(__DIR__ . '/_migrate_leads.json') && leads_store() !== 'db') {
    try { $mr = leads_import_from_sheet(); send_mail(TEAM_TO, 'Leads moved into the database', "$mr.\n\nFrom now on the panel and the nightly run use the database. The Google Sheet is left as it was, as an old copy; changes there are no longer read.\nA backup of the whole list is emailed to you every day it changes.\n"); echo date('c') . " $mr\n"; }
    catch (Throwable $e) { echo date('c') . ' leads import failed: ' . $e->getMessage() . "\n"; write_json('leads_import_error.json', ['at' => time(), 'error' => $e->getMessage()]); }
}
// hello@ mailbox -> Gmail (a fresh copy from our own domain; plain forwarding gets dropped by Gmail).
require_once __DIR__ . '/_inbox.php';
try { $ir = inbox_relay(); if ($ir !== 'no new email') echo date('c') . " inbox: $ir\n"; } catch (Throwable $e) { echo date('c') . ' inbox failed: ' . $e->getMessage() . "\n"; }
try { $br = leads_daily_backup(); if ($br !== 'backup already done today') echo date('c') . " $br\n"; } catch (Throwable $e) { echo date('c') . ' backup failed: ' . $e->getMessage() . "\n"; }
$GLOBALS['sbm_actor'] = 'auto-send';
try { require_once __DIR__ . '/_autopersonal.php'; $pr = auto_personal_tick(); if ($pr !== 'nothing to send') echo date('c') . " personal/report: $pr\n"; } catch (Throwable $e) { echo date('c') . ' personal/report failed: ' . $e->getMessage() . "\n"; }
try { $res = autosend_tick($force); write_json('cron_heartbeat.json', $hb + ['result' => $res, 'at' => time()]); echo date('c') . ' ' . $res . "\n"; }
catch (Throwable $e) { fwrite(STDERR, 'Auto-send error: ' . $e->getMessage() . "\n"); write_json('cron_heartbeat.json', $hb + ['error' => $e->getMessage()]); exit(1); }
