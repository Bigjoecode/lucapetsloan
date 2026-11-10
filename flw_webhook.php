<?php
/*
 * Flutterwave webhook. Set it in the Flutterwave dashboard (Settings → Webhooks)
 * to https://<your-site>/flw_webhook.php with the same secret hash as FLW_SECRET_HASH.
 * Events are only trusted after re-checking them with Flutterwave's API.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');
$hash = (string)($_SERVER['HTTP_VERIF_HASH'] ?? '');
if (FLW_SECRET_HASH === '' || !hash_equals(FLW_SECRET_HASH, $hash)) {
    http_response_code(401);
    exit('{"status":"unauthorized"}');
}

$event = json_decode((string)file_get_contents('php://input'), true) ?: [];
$type = (string)($event['event'] ?? $event['event.type'] ?? '');
$data = (array)($event['data'] ?? []);

if ($type === 'charge.completed' && !empty($data['id'])) {
    if ($verified = flw_verify((string)$data['id'])) {
        flw_settle_charge($verified);
    }
} elseif ($type === 'transfer.completed' && !empty($data['id'])) {
    $res = flw_request('GET', '/transfers/' . rawurlencode((string)$data['id']));
    if ($res['ok']) {
        flw_settle_transfer($res['body']['data'] ?? []);
    }
}
echo '{"status":"ok"}';
