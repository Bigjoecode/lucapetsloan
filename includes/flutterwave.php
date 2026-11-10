<?php
declare(strict_types=1);

/*
 * Flutterwave v3: hosted checkout for money coming in (savings deposits,
 * loan repayments) and bank transfers for money going out (withdrawals).
 * A payment only counts once it has been re-checked with Flutterwave's
 * verify endpoint; redirects and webhooks are never trusted on their own.
 */

function flw_configured(): bool
{
    return FLW_SECRET_KEY !== '';
}

function flw_deposits_on(): bool
{
    return flw_configured() && setting('flw_deposits');
}

function flw_payouts_on(): bool
{
    return flw_configured() && setting('flw_payouts');
}

/** Call the API. Returns ['ok' => bool, 'http' => int, 'body' => array]. */
function flw_request(string $method, string $path, ?array $payload = null): array
{
    $headers = ['Authorization: Bearer ' . FLW_SECRET_KEY, 'Content-Type: application/json', 'Accept: application/json'];
    $body = $payload === null ? null : json_encode($payload);
    if (function_exists('curl_init')) {
        $ch = curl_init(FLW_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'timeout' => 25, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(FLW_BASE_URL . $path, false, $ctx);
        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        $http = (int)($m[1] ?? 0);
        $err = $raw === false ? 'no response' : '';
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $ok = $http >= 200 && $http < 300 && is_array($data) && ($data['status'] ?? '') === 'success';
    if (!$ok) {
        error_log('[lucapetloan] flutterwave ' . $method . ' ' . $path . ' failed: HTTP ' . $http . ' ' . $err . ' ' . substr((string)$raw, 0, 300));
    }
    return ['ok' => $ok, 'http' => $http, 'body' => is_array($data) ? $data : []];
}

function flw_message(array $res, string $fallback): string
{
    return (string)($res['body']['message'] ?? '') ?: $fallback;
}

/** Unique reference we give Flutterwave for a transaction. */
function flw_reference(string $prefix): string
{
    return $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Start a hosted checkout for a pending transaction and return the payment page URL.
 * Throws UserError when Flutterwave can't start the payment.
 */
function flw_checkout(array $tx, array $user, string $title): string
{
    $res = flw_request('POST', '/payments', [
        'tx_ref'         => $tx['reference'],
        'amount'         => round((float)$tx['amount'], 2),
        'currency'       => 'NGN',
        'redirect_url'   => absolute_url('flw_return.php'),
        'payment_options'=> 'card,banktransfer,ussd',
        'customer'       => ['email' => $user['email'], 'phonenumber' => $user['phone'], 'name' => $user['full_name']],
        'customizations' => ['title' => APP_NAME, 'description' => $title],
        'meta'           => ['transaction_id' => (int)$tx['id'], 'user_id' => (int)$user['id']],
    ]);
    $link = $res['body']['data']['link'] ?? '';
    if (!$res['ok'] || $link === '') {
        throw new UserError('Online payment is unavailable right now: ' . flw_message($res, 'please try again shortly.'));
    }
    return $link;
}

/** Verified charge details from Flutterwave, or null. */
function flw_verify(string $flwId): ?array
{
    if (!preg_match('/^\d+$/', $flwId)) {
        return null;
    }
    $res = flw_request('GET', '/transactions/' . $flwId . '/verify');
    return $res['ok'] ? ($res['body']['data'] ?? null) : null;
}

function flw_verify_reference(string $txRef): ?array
{
    $res = flw_request('GET', '/transactions/verify_by_reference?tx_ref=' . rawurlencode($txRef));
    return $res['ok'] ? ($res['body']['data'] ?? null) : null;
}

/**
 * Apply a verified Flutterwave charge to our pending transaction: credit it when
 * the payment succeeded for the full amount in naira, decline it when it failed.
 * Safe to call more than once (redirect and webhook both land here).
 * Returns the transaction after the update, or null if it isn't ours.
 */
function flw_settle_charge(array $data): ?array
{
    $tx = q_one('SELECT * FROM transactions WHERE reference = ? AND method = "flutterwave" AND type IN ("deposit","loan_repayment")', [(string)($data['tx_ref'] ?? '')]);
    if (!$tx) {
        return null;
    }
    if ($tx['status'] !== 'pending') {
        return $tx;
    }
    $status = strtolower((string)($data['status'] ?? ''));
    $paidOk = $status === 'successful' && ($data['currency'] ?? '') === 'NGN' && (float)($data['amount'] ?? 0) >= (float)$tx['amount'] - 0.009;
    q('UPDATE transactions SET provider_ref = ? WHERE id = ?', [(string)($data['id'] ?? ''), $tx['id']]);
    try {
        if ($paidOk) {
            process_transaction((int)$tx['id'], true, null, 'Paid online · Flutterwave #' . ($data['id'] ?? ''));
            flw_save_card($tx, $data);
            audit('payment.flutterwave', ucfirst(label($tx['type'])) . ' #' . $tx['id'], money($tx['amount']) . ' confirmed by Flutterwave', null);
        } elseif ($status === 'successful') {
            // Paid, but not what we asked for: leave it for staff to check.
            q('UPDATE transactions SET note = ? WHERE id = ?', ['Flutterwave reported ' . ($data['currency'] ?? '') . ' ' . ($data['amount'] ?? '') . '; please check', $tx['id']]);
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            process_transaction((int)$tx['id'], false, null, 'Online payment ' . $status);
        }
    } catch (UserError) {
        // Already processed by the other path (redirect vs webhook).
    }
    return q_one('SELECT * FROM transactions WHERE id = ?', [$tx['id']]);
}

/** Nigerian banks [code => name], cached for a day. */
function flw_banks(): array
{
    $cache = json_decode((string)(q_val('SELECT v FROM settings WHERE k = "flw_banks_cache" AND updated_at > DATE_SUB(NOW(), INTERVAL 1 DAY)') ?: ''), true);
    if (is_array($cache) && $cache) {
        return $cache;
    }
    $res = flw_request('GET', '/banks/NG');
    $banks = [];
    foreach ($res['body']['data'] ?? [] as $b) {
        if (!empty($b['code']) && !empty($b['name'])) {
            $banks[(string)$b['code']] = (string)$b['name'];
        }
    }
    asort($banks);
    if ($banks) {
        q('REPLACE INTO settings (k, v) VALUES ("flw_banks_cache", ?)', [json_encode($banks)]);
    }
    return $banks;
}

/** Account holder's name for a bank account, or null if Flutterwave can't confirm it. */
function flw_resolve_account(string $accountNumber, string $bankCode): ?string
{
    $res = flw_request('POST', '/accounts/resolve', ['account_number' => $accountNumber, 'account_bank' => $bankCode]);
    return $res['ok'] ? ($res['body']['data']['account_name'] ?? null) : null;
}

/**
 * Send a pending withdrawal to the customer's bank. On success the
 * transaction becomes "processing" until Flutterwave reports the result.
 */
function flw_send_withdrawal(int $txId, ?int $staffId): string
{
    $tx = q_one('SELECT * FROM transactions WHERE id = ? AND type = "withdrawal"', [$txId]);
    if (!$tx || $tx['status'] !== 'pending') {
        throw new UserError('That withdrawal has already been processed.');
    }
    $kyc = q_one('SELECT bank_code, account_number, account_name FROM kyc WHERE user_id = ?', [$tx['user_id']]);
    if (!$kyc || empty($kyc['bank_code'])) {
        throw new UserError('The customer has no payout bank selected. Ask them to choose their bank under Profile → Payout account, or pay manually.');
    }
    // Claim the row first so a double click can't send the money twice.
    $ref = flw_reference('LPL-WD');
    $claimed = q('UPDATE transactions SET status = "processing", reference = ?, processed_by = ? WHERE id = ? AND status = "pending"', [$ref, $staffId, $txId])->rowCount();
    if (!$claimed) {
        throw new UserError('That withdrawal has already been processed.');
    }
    $res = flw_request('POST', '/transfers', [
        'account_bank'   => $kyc['bank_code'],
        'account_number' => $kyc['account_number'],
        'amount'         => round((float)$tx['amount'], 2),
        'currency'       => 'NGN',
        'debit_currency' => 'NGN',
        'narration'      => APP_NAME . ' savings withdrawal',
        'reference'      => $ref,
        'callback_url'   => absolute_url('flw_webhook.php'),
    ]);
    if (!$res['ok']) {
        q('UPDATE transactions SET status = "pending" WHERE id = ?', [$txId]);
        throw new UserError('Flutterwave did not accept the transfer: ' . flw_message($res, 'no response') . '. Nothing was sent; the request is still pending.');
    }
    q('UPDATE transactions SET method = "flutterwave", provider_ref = ?, note = ? WHERE id = ?',
        [(string)($res['body']['data']['id'] ?? ''), 'To ' . $kyc['account_number'] . ' (' . $kyc['account_name'] . ')', $txId]);
    audit('withdrawal.sent', 'Withdrawal #' . $txId, money($tx['amount']) . ' sent via Flutterwave, ref ' . $ref, $staffId);
    return 'Transfer of ' . money($tx['amount']) . ' sent to Flutterwave. It completes once the bank confirms.';
}

/**
 * Why a withdrawal must wait for staff instead of going out instantly, or null
 * if it may be sent. Only applies when instant payouts are switched on.
 */
function auto_payout_hold(int $userId, int $txId): ?string
{
    if (!setting('auto_payouts') || !flw_payouts_on()) {
        return null;
    }
    $limit = setting('auto_payout_daily_limit');
    $today = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id = ? AND type = "withdrawal" AND status <> "rejected" AND DATE(created_at) = CURDATE()', [$userId]);
    if ($limit > 0 && $today > $limit) {
        return 'over the ' . money0($limit) . ' daily instant limit (' . money0($today) . ' today)';
    }
    // A freshly changed payout account is the classic sign of a stolen login.
    if (q_val('SELECT 1 FROM audit_log WHERE action = "customer.payout_account" AND target = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1', ['Customer #' . $userId])) {
        return 'payout account changed in the last 24 hours';
    }
    return null;
}

/**
 * Pay an approved loan to the customer's bank. The loan starts (interest,
 * due date) only when Flutterwave confirms the transfer landed.
 */
function flw_send_loan(int $loanId, ?int $staffId): string
{
    $loan = q_one('SELECT * FROM loans WHERE id = ?', [$loanId]);
    if (!$loan || $loan['status'] !== 'approved') {
        throw new UserError('Only approved loans can be paid out.');
    }
    if (q_val('SELECT 1 FROM transactions WHERE loan_id = ? AND type = "loan_disbursement" AND status IN ("processing","approved")', [$loanId])) {
        throw new UserError('This loan has already been sent.');
    }
    $kyc = q_one('SELECT bank_code, account_number, account_name FROM kyc WHERE user_id = ?', [$loan['user_id']]);
    if (!$kyc || empty($kyc['bank_code'])) {
        throw new UserError('The customer has no payout bank selected, so the loan has to be paid manually.');
    }
    $ref = flw_reference('LPL-LN');
    q('INSERT INTO transactions (user_id, type, amount, method, reference, status, loan_id, note, processed_by) VALUES (?, "loan_disbursement", ?, "flutterwave", ?, "processing", ?, ?, ?)',
        [$loan['user_id'], $loan['amount'], $ref, $loanId, 'To ' . $kyc['account_number'] . ' (' . $kyc['account_name'] . ')', $staffId]);
    $txId = (int)db()->lastInsertId();
    $res = flw_request('POST', '/transfers', [
        'account_bank'   => $kyc['bank_code'],
        'account_number' => $kyc['account_number'],
        'amount'         => round((float)$loan['amount'], 2),
        'currency'       => 'NGN',
        'debit_currency' => 'NGN',
        'narration'      => APP_NAME . ' loan #' . $loanId,
        'reference'      => $ref,
        'callback_url'   => absolute_url('flw_webhook.php'),
    ]);
    if (!$res['ok']) {
        q('UPDATE transactions SET status = "rejected", admin_note = ? WHERE id = ?', ['Transfer not accepted: ' . mb_substr(flw_message($res, 'no response'), 0, 200), $txId]);
        throw new UserError('Flutterwave did not accept the transfer: ' . flw_message($res, 'no response') . '. Nothing was sent.');
    }
    q('UPDATE transactions SET provider_ref = ? WHERE id = ?', [(string)($res['body']['data']['id'] ?? ''), $txId]);
    audit('loan.flw_payout', 'Loan #' . $loanId, money($loan['amount']) . ' sent via Flutterwave, ref ' . $ref, $staffId);
    return 'Loan of ' . money($loan['amount']) . ' sent through Flutterwave. It starts as soon as the bank confirms.';
}

/** Apply a transfer result (withdrawal or loan pay-out) from the webhook or a status check. */
function flw_settle_transfer(array $data): ?array
{
    $tx = q_one('SELECT * FROM transactions WHERE type IN ("withdrawal","loan_disbursement") AND (reference = ? OR (provider_ref = ? AND provider_ref <> ""))',
        [(string)($data['reference'] ?? ''), (string)($data['id'] ?? '')]);
    if (!$tx || $tx['status'] !== 'processing') {
        return $tx;
    }
    $status = strtoupper((string)($data['status'] ?? ''));
    $reason = mb_substr((string)($data['complete_message'] ?? 'no reason given'), 0, 150);
    $staffId = $tx['processed_by'] ? (int)$tx['processed_by'] : null;

    if ($tx['type'] === 'loan_disbursement') {
        // Claim the row so the webhook and a status check can't both start the loan.
        if ($status === 'SUCCESSFUL' && q('UPDATE transactions SET status = "pending" WHERE id = ? AND status = "processing"', [$tx['id']])->rowCount()) {
            activate_loan((int)$tx['loan_id'], date('Y-m-d'), $staffId, (string)$tx['reference'], 'flutterwave', (int)$tx['id']);
        } elseif ($status === 'FAILED') {
            q('UPDATE transactions SET status = "rejected", admin_note = ?, processed_at = NOW() WHERE id = ? AND status = "processing"', ['Bank transfer failed: ' . $reason, $tx['id']]);
            notify_staff('loans', 'Loan pay-out failed', 'Loan #' . $tx['loan_id'] . ': ' . $reason . '. Send it again or pay manually.', 'admin/loans.php?id=' . $tx['loan_id']);
        }
    } else {
        q('UPDATE transactions SET status = "pending" WHERE id = ? AND status = "processing"', [$tx['id']]);   // process_transaction settles pending rows
        try {
            if ($status === 'SUCCESSFUL') {
                process_transaction((int)$tx['id'], true, $staffId, 'Paid via Flutterwave');
            } elseif ($status === 'FAILED') {
                process_transaction((int)$tx['id'], false, null, 'Bank transfer failed: ' . $reason);
            } else {
                q('UPDATE transactions SET status = "processing" WHERE id = ?', [$tx['id']]);   // still in flight
            }
        } catch (UserError) {
        }
    }
    if (in_array($status, ['SUCCESSFUL', 'FAILED'], true)) {
        $isWd = $tx['type'] === 'withdrawal';
        audit(($isWd ? 'withdrawal.' : 'loan.payout_') . strtolower($status), $isWd ? 'Withdrawal #' . $tx['id'] : 'Loan #' . $tx['loan_id'], 'Flutterwave transfer ' . ($data['id'] ?? ''), null);
    }
    return q_one('SELECT * FROM transactions WHERE id = ?', [$tx['id']]);
}

/** Ask Flutterwave for a transfer's current status and apply it. */
function flw_check_transfer(int $txId): string
{
    $tx = q_one('SELECT * FROM transactions WHERE id = ? AND type IN ("withdrawal","loan_disbursement")', [$txId]);
    if (!$tx || $tx['status'] !== 'processing' || !$tx['provider_ref']) {
        throw new UserError('Nothing to check for that transfer.');
    }
    $res = flw_request('GET', '/transfers/' . rawurlencode($tx['provider_ref']));
    if (!$res['ok']) {
        throw new UserError('Could not reach Flutterwave: ' . flw_message($res, 'try again shortly.'));
    }
    $after = flw_settle_transfer($res['body']['data'] ?? []);
    return label($tx['type']) . ' #' . $txId . ' is ' . label($after['status'] ?? 'processing') . '.';
}

/* ------------------------------------------------------------------ */
/*  Saved cards: automatic savings and repayments                      */
/* ------------------------------------------------------------------ */

/** Keep the card from a verified charge if the customer asked us to. */
function flw_save_card(array $tx, array $data): void
{
    $card = $data['card'] ?? null;
    if (!$tx['save_card'] || !is_array($card) || empty($card['token'])) {
        return;
    }
    if (q_val('SELECT 1 FROM payment_cards WHERE user_id = ? AND token = ? AND removed_at IS NULL', [$tx['user_id'], $card['token']])) {
        return;
    }
    $last4 = substr((string)($card['last_4digits'] ?? ''), -4);
    q('INSERT INTO payment_cards (user_id, token, email, brand, last4, expiry) VALUES (?,?,?,?,?,?)', [
        $tx['user_id'], $card['token'], (string)($data['customer']['email'] ?? ''),
        mb_substr((string)($card['type'] ?? 'Card'), 0, 40), $last4, mb_substr((string)($card['expiry'] ?? ''), 0, 7),
    ]);
    notify((int)$tx['user_id'], 'Card saved', 'Your card ending ' . $last4 . ' can now pay your daily savings and loan automatically. Turn it on from Savings or your loan page.', 'savings.php');
}

function user_cards(int $userId): array
{
    return q('SELECT * FROM payment_cards WHERE user_id = ? AND removed_at IS NULL ORDER BY id DESC', [$userId])->fetchAll();
}

/**
 * Charge a saved card for a new transaction (deposit or repayment). Returns
 * the transaction afterwards: approved, rejected, or still pending when the
 * bank answers later (the webhook settles it then).
 */
function flw_charge_card(array $card, int $userId, string $type, float $amount, ?int $planId, ?int $loanId, string $note): array
{
    $ref = flw_reference($type === 'deposit' ? 'LPL-AUTO' : 'LPL-AREP');
    q('INSERT INTO transactions (user_id, type, amount, method, reference, savings_plan_id, loan_id, note) VALUES (?, ?, ?, "flutterwave", ?, ?, ?, ?)',
        [$userId, $type, round($amount, 2), $ref, $planId, $loanId, $note]);
    $txId = (int)db()->lastInsertId();
    $res = flw_request('POST', '/tokenized-charges', [
        'token'     => $card['token'],
        'currency'  => 'NGN',
        'country'   => 'NG',
        'amount'    => round($amount, 2),
        'email'     => $card['email'],
        'tx_ref'    => $ref,
        'narration' => APP_NAME . ' ' . $note,
    ]);
    $data = $res['body']['data'] ?? [];
    if ($res['ok'] && !empty($data['id']) && ($verified = flw_verify((string)$data['id']))) {
        flw_settle_charge($verified);
    } elseif (!$res['ok']) {
        q('UPDATE transactions SET status = "rejected", admin_note = ?, processed_at = NOW() WHERE id = ? AND status = "pending"',
            [mb_substr('Card charge failed: ' . flw_message($res, 'no response'), 0, 255), $txId]);
    }
    return q_one('SELECT * FROM transactions WHERE id = ?', [$txId]);
}
