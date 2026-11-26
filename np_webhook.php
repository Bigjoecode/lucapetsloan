<?php
/*
 * NOWPayments IPN (crypto deposits). Set the IPN secret in the NOWPayments
 * dashboard (Settings → Payments → Instant payment notifications) and the same
 * secret under Admin → Integrations. Each deposit address already tells
 * NOWPayments to call this page. The status is always re-read from NOWPayments.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');
$raw = (string)file_get_contents('php://input');
if (!np_ipn_valid($raw, (string)($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? ''))) {
    http_response_code(401);
    exit('{"status":"unauthorized"}');
}
$data = json_decode($raw, true) ?: [];
if (!empty($data['batch_withdrawal_id']) || (!empty($data['id']) && empty($data['payment_id']))) {
    $wid = (int)q_val('SELECT id FROM crypto_withdrawals WHERE payout_id = ? OR payout_batch_id = ? LIMIT 1', [(string)($data['id'] ?? ''), (string)($data['batch_withdrawal_id'] ?? '')]);
    if ($wid) {
        crypto_refresh_payout($wid);   // the status is re-read from NOWPayments, never taken from the webhook itself
    }
} elseif (!empty($data['payment_id'])) {
    crypto_adopt_deposit($data);   // a repeat deposit to a customer's saved address has a new payment id
    crypto_refresh_deposit((string)$data['payment_id']);
}
echo '{"status":"ok"}';
