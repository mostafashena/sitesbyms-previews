<?php
// cPanel cron job, every 10 minutes:  php ~/public_html/api/_cron.php
// Sends the day's emails at the time set in the panel (default 08:00 London) and emails the daily send report.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/_autosend.php';
$force = in_array('--now', $argv, true);
try { echo date('c') . ' ' . autosend_tick($force) . "\n"; }
catch (Throwable $e) { fwrite(STDERR, 'Auto-send error: ' . $e->getMessage() . "\n"); write_json('cron_heartbeat.json', ['at' => time(), 'error' => $e->getMessage()]); exit(1); }
