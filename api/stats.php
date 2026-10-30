<?php
// Read-only concept view stats for Claude's reports. Needs the report key (only its hash is stored here).
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (!hash_equals('4733fc20bc20231e8fcf46f6ca77f38081cdc8df499b3bc9af734a80b34298a9', hash('sha256', (string)($_GET['k'] ?? '')))) { http_response_code(404); echo '{}'; exit; }
$out = [];
foreach (views_all() as $v) $out[] = [$v['at'], $v['slug'], $v['device'], (string)$v['ref']];
echo json_encode(['generated' => gmdate('c'), 'columns' => ['at', 'slug', 'device', 'ref'], 'views' => $out], JSON_UNESCAPED_SLASHES);
