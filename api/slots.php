<?php
// Returns bookable days and slots as JSON. Busy slots come from bookings and the team calendar.
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['tz' => TZ, 'length' => (int)config()['length'], 'days' => build_slots()]);
