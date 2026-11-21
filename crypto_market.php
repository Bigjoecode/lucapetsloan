<?php
/* JSON for the live market panel on the Crypto savings page (refreshed by the page every minute). */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');

// The customer's balances, read at most once a minute.
$cached = $_SESSION['crypto_bal'] ?? null;
if ($cached && $cached[0] > time() - 60) {
    $balances = $cached[1];
} else {
    $balances = [];
    if (crypto_on() && !empty($u['np_sub_id'])) {
        try {
            $balances = crypto_balances((int)$u['id']);
        } catch (Throwable $e) {
            $balances = $cached[1] ?? [];
        }
    }
    $_SESSION['crypto_bal'] = [time(), $balances];
}
session_write_close();

echo json_encode(crypto_market_view($balances));
