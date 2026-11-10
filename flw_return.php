<?php
/* Flutterwave sends the customer back here after checkout. */
require __DIR__ . '/includes/bootstrap.php';

$ref = (string)($_GET['tx_ref'] ?? '');
$tx = $ref !== '' ? q_one('SELECT * FROM transactions WHERE reference = ? AND method = "flutterwave"', [$ref]) : null;
if (!$tx) {
    flash('error', 'We could not find that payment.');
    redirect(current_user() ? 'dashboard.php' : 'login.php');
}
$back = $tx['type'] === 'loan_repayment' ? 'loan.php?id=' . $tx['loan_id'] : 'savings.php';

if ($tx['status'] === 'pending') {
    $flwId = (string)($_GET['transaction_id'] ?? '');
    $data = $flwId !== '' ? flw_verify($flwId) : null;
    if ($data && ($data['tx_ref'] ?? '') === $ref) {
        $tx = flw_settle_charge($data) ?? $tx;
    } elseif (($_GET['status'] ?? '') === 'cancelled' && (int)(current_user()['id'] ?? 0) === (int)$tx['user_id']) {
        q('UPDATE transactions SET status = "rejected", admin_note = "Cancelled at checkout", processed_at = NOW() WHERE id = ? AND status = "pending"', [$tx['id']]);
        $tx['status'] = 'rejected';
    } elseif ($data = flw_verify_reference($ref)) {
        $tx = flw_settle_charge($data) ?? $tx;
    }
}

match ($tx['status']) {
    'approved' => flash('success', money($tx['amount']) . ($tx['type'] === 'loan_repayment' ? ' was applied to your loan.' : ' was added to your savings.') . ' Thank you!'),
    'rejected' => flash('error', 'The payment of ' . money($tx['amount']) . ' did not go through. No money was taken; you can try again.'),
    default    => flash('info', 'Your payment of ' . money($tx['amount']) . ' is being confirmed. We\'ll notify you as soon as it\'s done.'),
};
redirect(current_user() ? $back : 'login.php');
