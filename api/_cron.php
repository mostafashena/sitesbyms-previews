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
$force = in_array('--now', $argv, true);
try { $res = autosend_tick($force); write_json('cron_heartbeat.json', $hb + ['result' => $res, 'at' => time()]); echo date('c') . ' ' . $res . "\n"; }
catch (Throwable $e) { fwrite(STDERR, 'Auto-send error: ' . $e->getMessage() . "\n"); write_json('cron_heartbeat.json', $hb + ['error' => $e->getMessage()]); exit(1); }
