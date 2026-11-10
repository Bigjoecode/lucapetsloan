<?php
/* Daily job, called by Vercel Cron (see vercel.json). */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/jobs.php';

header('Content-Type: application/json');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$key = (string)($_GET['key'] ?? '');
$allowed = (CRON_SECRET !== '' && hash_equals('Bearer ' . CRON_SECRET, $auth))
    || (SETUP_KEY !== '' && hash_equals(SETUP_KEY, $key));
if (!$allowed) {
    http_response_code(401);
    exit('{"status":"unauthorized"}');
}
echo json_encode(['status' => 'ok', 'done' => run_daily_jobs()]);
