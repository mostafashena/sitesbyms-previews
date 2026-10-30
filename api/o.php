<?php
// 1x1 image inside outreach emails: records that the email was opened.
declare(strict_types=1);
require __DIR__ . '/_google.php';
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$slug = (string)($_GET['s'] ?? ''); $kind = ($_GET['k'] ?? '') === 'followup' ? 'followup' : 'first';
if (preg_match('/^[a-z0-9-]{1,60}$/', $slug) && !is_team_visit() && rate_limit('pixel', 120, 3600)) {
    try { seen_add($slug, $kind, 'Email', 'pixel'); } catch (Throwable $e) {}
}
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
