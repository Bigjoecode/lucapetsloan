<?php
declare(strict_types=1);

/*
 * The daily job: collect auto-save and auto-repay payments from saved cards,
 * send due-date reminders, and tidy up payments a webhook may have missed.
 * Run by Vercel Cron through /cron.php, or by a super admin from Settings.
 * Safe to run more than once a day: every step checks what was already done.
 */

/** In-app notice plus SMS, at most once per (kind, ref). */
function remind(int $userId, string $kind, string $ref, string $title, string $body, string $link): bool
{
    if (message_sent($kind, $ref)) {
        return false;
    }
    notify($userId, $title, $body, $link);
    if (!sms_user($userId, $title, $body, $kind, $ref)) {
        q('INSERT INTO messages_log (user_id, channel, recipient, kind, ref, body, status) VALUES (?, "app", "", ?, ?, ?, "sent")',
            [$userId, $kind, $ref, mb_substr($title . '. ' . $body, 0, 500)]);
    }
    return true;
}

/** What a customer should have paid on a loan by today. */
function loan_amount_due(array $loan): float
{
    $snap = loan_snapshot($loan);
    if ($snap['is_overdue'] || date('Y-m-d') >= $loan['due_date']) {
        return $snap['payoff'];
    }
    $due = 0.0;
    foreach (loan_schedule($loan) as $row) {
        if ($row['due'] <= date('Y-m-d')) {
            $due += $row['amount'] - $row['covered'];
        }
    }
    return round(min($due, $snap['payoff']), 2);
}

/** Record a failed automatic charge; three in a row switches it off. */
function auto_charge_failed(string $table, array $row, string $what): void
{
    $fails = (int)$row['auto_fails'] + 1;
    q("UPDATE $table SET auto_fails = ?" . ($fails >= 3 ? ', ' . ($table === 'loans' ? 'auto_repay' : 'auto_save') . ' = 0' : '') . ' WHERE id = ?', [$fails, $row['id']]);
    $link = $table === 'loans' ? 'loan.php?id=' . $row['id'] : 'savings.php';
    notify((int)$row['user_id'], 'Automatic payment failed', 'We could not collect ' . $what . ' from your card.'
        . ($fails >= 3 ? ' Automatic payments are now off; turn them on again once your card is ready.' : ' Please make sure it has funds, or pay manually.'), $link, true);
}

function run_daily_jobs(int $budgetSeconds = 50): array
{
    $stop = time() + $budgetSeconds;
    $out = ['auto_save' => 0, 'auto_repay' => 0, 'ajo_paid' => 0, 'failed' => 0, 'reminders' => 0, 'transfers_checked' => 0, 'payments_checked' => 0, 'expired' => 0];
    $today = date('Y-m-d');

    if (flw_deposits_on()) {
        // 1. Auto-save: plans not paid yet today.
        $plans = q('SELECT p.*, c.token, c.email card_email FROM savings_plans p
                    JOIN payment_cards c ON c.id = p.auto_card_id AND c.removed_at IS NULL
                    JOIN users u ON u.id = p.user_id AND u.status = "active"
                    WHERE p.auto_save = 1 AND p.status = "active"')->fetchAll();
        foreach ($plans as $p) {
            if (time() > $stop) break;
            $paidToday = q_val('SELECT 1 FROM transactions WHERE savings_plan_id = ? AND type = "deposit" AND status IN ("approved","pending") AND DATE(created_at) = CURDATE() LIMIT 1', [$p['id']]);
            if ($paidToday) continue;
            $tx = flw_charge_card(['token' => $p['token'], 'email' => $p['card_email']], (int)$p['user_id'], 'deposit', (float)$p['daily_amount'], (int)$p['id'], null, 'Auto-save · ' . $p['name']);
            if ($tx['status'] === 'rejected') {
                auto_charge_failed('savings_plans', $p, money0($p['daily_amount']) . ' for ' . $p['name']);
                $out['failed']++;
            } else {
                q('UPDATE savings_plans SET auto_fails = 0 WHERE id = ?', [$p['id']]);
                $out['auto_save']++;
            }
        }

        // 2. Auto-repay: whatever is due on loans with a saved card.
        $loans = q('SELECT l.*, c.token, c.email card_email FROM loans l
                    JOIN payment_cards c ON c.id = l.auto_card_id AND c.removed_at IS NULL
                    WHERE l.auto_repay = 1 AND l.status = "active"')->fetchAll();
        foreach ($loans as $l) {
            if (time() > $stop) break;
            $tried = q_val('SELECT 1 FROM transactions WHERE loan_id = ? AND type = "loan_repayment" AND reference LIKE "LPL-AREP-%" AND DATE(created_at) = CURDATE() LIMIT 1', [$l['id']]);
            $amount = loan_amount_due($l);
            if ($tried || $amount < 1) continue;
            $tx = flw_charge_card(['token' => $l['token'], 'email' => $l['card_email']], (int)$l['user_id'], 'loan_repayment', $amount, null, (int)$l['id'], 'Auto-repay · loan #' . $l['id']);
            if ($tx['status'] === 'rejected') {
                auto_charge_failed('loans', $l, money($amount) . ' for loan #' . $l['id']);
                $out['failed']++;
            } else {
                q('UPDATE loans SET auto_fails = 0 WHERE id = ?', [$l['id']]);
                $out['auto_repay']++;
            }
        }
    }

    // 2b. Group savings: on the due date, take unpaid contributions from members' savings.
    foreach (q('SELECT * FROM ajo_groups WHERE status = "active" AND round_due <= CURDATE()')->fetchAll() as $g) {
        if (time() > $stop) break;
        $unpaid = q('SELECT m.user_id FROM ajo_members m WHERE m.group_id = ? AND NOT EXISTS (SELECT 1 FROM ajo_contributions c WHERE c.group_id = m.group_id AND c.user_id = m.user_id AND c.round = ?)', [$g['id'], $g['current_round']])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($unpaid as $memberId) {
            try {
                ajo_contribute((int)$g['id'], (int)$memberId);
                $out['ajo_paid']++;
            } catch (UserError) {
                $out['reminders'] += (int)remind((int)$memberId, 'ajo_due', $g['id'] . '-' . $g['current_round'] . '-' . $memberId . '-' . $today, 'Group savings payment due',
                    'Your ' . money0($g['contribution']) . ' for "' . $g['name'] . '" round ' . $g['current_round'] . ' is due. Add money to your savings so the group can pay out.', 'ajo.php?id=' . $g['id']);
            }
        }
    }

    // 3. Loan reminders: 3 days before, on the day, and 1, 3, 7 and 14 days late.
    foreach (q('SELECT * FROM loans WHERE status = "active"')->fetchAll() as $l) {
        if (time() > $stop) break;
        $snap = loan_snapshot($l);
        $auto = $l['auto_repay'] ? ' We will collect it from your card.' : '';
        $link = 'loan.php?id=' . $l['id'];
        if ($snap['is_overdue']) {
            if (in_array($snap['late_days'], [1, 3, 7, 14], true)) {
                $out['reminders'] += (int)remind((int)$l['user_id'], 'loan_overdue', $l['id'] . '-' . $snap['late_days'], 'Loan overdue',
                    'Loan #' . $l['id'] . ' is ' . $snap['late_days'] . ' day' . ($snap['late_days'] === 1 ? '' : 's') . ' late. Pay ' . money($snap['payoff']) . ' now'
                    . ($snap['penalty_stopped'] ? '.' : ' to stop the ' . (float)$l['late_fee_rate'] . '% daily penalty.'), $link);
            }
            continue;
        }
        foreach (loan_schedule($l) as $row) {
            if ($row['state'] === 'paid') continue;
            $left = money($row['amount'] - $row['covered']);
            if ($row['due'] === date('Y-m-d', strtotime('+3 days'))) {
                $out['reminders'] += (int)remind((int)$l['user_id'], 'loan_due_soon', $l['id'] . '-' . $row['due'], 'Payment due in 3 days', $left . ' is due on ' . fdate($row['due']) . '.' . $auto, $link);
            } elseif ($row['due'] === $today) {
                $out['reminders'] += (int)remind((int)$l['user_id'], 'loan_due_today', $l['id'] . '-' . $row['due'], 'Payment due today', $left . ' is due today.' . ($auto ?: ' Pay in the app to avoid late fees.'), $link);
            }
        }
    }

    // 4. Optional daily savings nudge, one per customer.
    if (setting('savings_sms_reminders')) {
        $rows = q('SELECT p.user_id, MIN(p.daily_amount) amt FROM savings_plans p JOIN users u ON u.id = p.user_id AND u.status = "active"
                   WHERE p.status = "active" AND p.auto_save = 0
                     AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.savings_plan_id = p.id AND t.type = "deposit" AND DATE(t.created_at) = CURDATE())
                   GROUP BY p.user_id')->fetchAll();
        foreach ($rows as $r) {
            if (time() > $stop) break;
            $out['reminders'] += (int)remind((int)$r['user_id'], 'save_today', $r['user_id'] . '-' . $today, 'Time to save', 'Keep your streak going: save ' . money0($r['amt']) . ' today.', 'savings.php');
        }
    }

    if (flw_configured()) {
        // 5. Transfers still "sending" after 15 minutes: ask Flutterwave.
        foreach (q('SELECT id FROM transactions WHERE status = "processing" AND provider_ref IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)')->fetchAll() as $t) {
            if (time() > $stop) break;
            try {
                flw_check_transfer((int)$t['id']);
            } catch (UserError) {
            }
            $out['transfers_checked']++;
        }
        // 6. Online payments with no answer after 30 minutes: verify; give up after a day.
        foreach (q('SELECT * FROM transactions WHERE status = "pending" AND method = "flutterwave" AND type IN ("deposit","loan_repayment") AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)')->fetchAll() as $t) {
            if (time() > $stop) break;
            $data = flw_verify_reference((string)$t['reference']);
            if ($data) {
                flw_settle_charge($data);
                $out['payments_checked']++;
            } elseif (strtotime($t['created_at']) < time() - 86400) {
                q('UPDATE transactions SET status = "rejected", admin_note = "Payment was not completed", processed_at = NOW() WHERE id = ? AND status = "pending"', [$t['id']]);
                $out['expired']++;
            }
        }
    }

    // 7. Bring savings interest up to date so staff totals are current.
    foreach (q('SELECT id FROM savings_plans WHERE status <> "closed" AND balance > 0 AND interest_accrued_on < CURDATE()')->fetchAll() as $p) {
        if (time() > $stop) break;
        accrue_plan_interest((int)$p['id']);
    }

    save_setting('last_cron_run', date('Y-m-d H:i:s') . ' · ' . json_encode($out));
    return $out;
}
