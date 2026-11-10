<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/*  Referrals                                                          */
/* ------------------------------------------------------------------ */

/** The customer's invite code, created the first time it's needed. */
function referral_code(int $userId): string
{
    $code = q_val('SELECT referral_code FROM users WHERE id = ?', [$userId]);
    while (!$code) {
        $try = 'LP' . substr(str_shuffle(str_repeat('ABCDEFGHJKMNPQRSTUVWXYZ23456789', 2)), 0, 6);
        if (!q_val('SELECT 1 FROM users WHERE referral_code = ?', [$try])) {
            q('UPDATE users SET referral_code = ? WHERE id = ?', [$try, $userId]);
            $code = $try;
        }
    }
    return (string)$code;
}

/** Link a new customer to whoever invited them. */
function record_referral(int $newUserId, string $code): void
{
    $referrer = q_val('SELECT id FROM users WHERE referral_code = ? AND id <> ? AND status = "active"', [strtoupper(trim($code)), $newUserId]);
    if ($referrer) {
        q('UPDATE users SET referred_by = ? WHERE id = ?', [$referrer, $newUserId]);
        q('INSERT IGNORE INTO referrals (referrer_id, referee_id) VALUES (?, ?)', [$referrer, $newUserId]);
    }
}

/**
 * Pay the inviter once the invited friend has saved enough. Called after a
 * deposit is confirmed, inside that transaction.
 */
function check_referral_reward(int $refereeId): void
{
    $bonus = (float)setting('referral_bonus');
    $ref = q_one('SELECT * FROM referrals WHERE referee_id = ? AND status = "pending" FOR UPDATE', [$refereeId]);
    if (!$ref || $bonus <= 0) {
        return;
    }
    $saved = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id = ? AND type = "deposit" AND status = "approved"', [$refereeId]);
    if ($saved < setting('referral_min_savings')) {
        return;
    }
    $plan = savings_plan_for((int)$ref['referrer_id']);
    accrue_plan_interest((int)$plan['id']);
    q('UPDATE savings_plans SET balance = balance + ? WHERE id = ?', [$bonus, $plan['id']]);
    q('INSERT INTO transactions (user_id, type, amount, method, status, savings_plan_id, note, processed_at) VALUES (?, "bonus", ?, "system", "approved", ?, ?, NOW())',
        [$ref['referrer_id'], $bonus, $plan['id'], 'Referral bonus']);
    q('UPDATE referrals SET status = "rewarded", amount = ?, rewarded_at = NOW() WHERE id = ?', [$bonus, $ref['id']]);
    $friend = q_val('SELECT full_name FROM users WHERE id = ?', [$refereeId]);
    notify((int)$ref['referrer_id'], 'You earned a referral bonus 🎁', money0($bonus) . ' was added to your savings because ' . explode(' ', (string)$friend)[0] . ' started saving with ' . APP_NAME . '.', 'savings.php', true);
}

/** The customer's first open savings plan, creating one if they have none. */
function savings_plan_for(int $userId, string $name = 'Daily Savings'): array
{
    $plan = q_one('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" ORDER BY status = "active" DESC, id LIMIT 1', [$userId]);
    if (!$plan) {
        q('INSERT INTO savings_plans (user_id, name, daily_amount, interest_accrued_on) VALUES (?, ?, 500, CURDATE())', [$userId, $name]);
        $plan = q_one('SELECT * FROM savings_plans WHERE id = ?', [db()->lastInsertId()]);
    }
    return $plan;
}

/* ------------------------------------------------------------------ */
/*  Group savings (ajo / esusu)                                        */
/* ------------------------------------------------------------------ */

const AJO_FREQUENCIES = ['daily' => ['Daily', '+1 day'], 'weekly' => ['Weekly', '+1 week'], 'monthly' => ['Monthly', '+1 month']];

function ajo_members(int $groupId): array
{
    return q('SELECT m.*, u.full_name, u.phone FROM ajo_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY COALESCE(m.position, 99), m.id', [$groupId])->fetchAll();
}

/** Start a full group: draw the payout order and open round 1. */
function ajo_start(array $g): void
{
    $members = ajo_members((int)$g['id']);
    if (count($members) < (int)$g['size']) {
        throw new UserError('The group needs ' . $g['size'] . ' members before it can start.');
    }
    shuffle($members);
    foreach ($members as $i => $m) {
        q('UPDATE ajo_members SET position = ? WHERE id = ?', [$i + 1, $m['id']]);
    }
    q('UPDATE ajo_groups SET status = "active", current_round = 1, started_at = NOW(), round_due = ? WHERE id = ?',
        [date('Y-m-d', strtotime(AJO_FREQUENCIES[$g['frequency']][1])), $g['id']]);
    foreach ($members as $i => $m) {
        notify((int)$m['user_id'], 'Group savings started', '"' . $g['name'] . '" has started. You collect the pot in round ' . ($i + 1) . ' of ' . $g['size'] . '. Pay ' . money0($g['contribution']) . ' each round.', 'ajo.php?id=' . $g['id'], true);
    }
}

/**
 * Pay this round's contribution from the member's savings, and pay out the
 * pot once everyone has paid. The page checks the member's PIN first; the
 * daily job pays without one, as members agree to when they join.
 */
function ajo_contribute(int $groupId, int $userId): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $g = q_one('SELECT * FROM ajo_groups WHERE id = ? FOR UPDATE', [$groupId]);
        if (!$g || $g['status'] !== 'active') throw new UserError('This group is not collecting contributions.');
        if (!q_val('SELECT 1 FROM ajo_members WHERE group_id = ? AND user_id = ?', [$groupId, $userId])) throw new UserError('You are not in this group.');
        $round = (int)$g['current_round'];
        if (q_val('SELECT 1 FROM ajo_contributions WHERE group_id = ? AND user_id = ? AND round = ?', [$groupId, $userId, $round])) {
            throw new UserError('You have already paid for round ' . $round . '.');
        }
        $amount = (float)$g['contribution'];
        $plan = q_one('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" AND balance >= ? ORDER BY balance DESC LIMIT 1 FOR UPDATE', [$userId, $amount]);
        if ($plan) {
            accrue_plan_interest((int)$plan['id']);
            $plan = q_one('SELECT * FROM savings_plans WHERE id = ?', [$plan['id']]);
        }
        if (!$plan || (float)$plan['balance'] < $amount) throw new UserError('You need ' . money0($amount) . ' in one of your savings plans to pay this round. Deposit first.');
        q('UPDATE savings_plans SET balance = balance - ? WHERE id = ?', [$amount, $plan['id']]);
        q('INSERT INTO transactions (user_id, type, amount, method, status, savings_plan_id, note, processed_at) VALUES (?, "ajo_contribution", ?, "savings", "approved", ?, ?, NOW())',
            [$userId, $amount, $plan['id'], $g['name'] . ' · round ' . $round]);
        q('INSERT INTO ajo_contributions (group_id, user_id, round, amount) VALUES (?,?,?,?)', [$groupId, $userId, $round, $amount]);
        $msg = money0($amount) . ' paid for round ' . $round . '.';

        $paid = (int)q_val('SELECT COUNT(*) FROM ajo_contributions WHERE group_id = ? AND round = ?', [$groupId, $round]);
        if ($paid >= (int)$g['size']) {
            $winner = q_one('SELECT * FROM ajo_members WHERE group_id = ? AND position = ?', [$groupId, $round]);
            $pot = $amount * (int)$g['size'];
            $wPlan = savings_plan_for((int)$winner['user_id']);
            accrue_plan_interest((int)$wPlan['id']);
            q('UPDATE savings_plans SET balance = balance + ? WHERE id = ?', [$pot, $wPlan['id']]);
            q('INSERT INTO transactions (user_id, type, amount, method, status, savings_plan_id, note, processed_at) VALUES (?, "ajo_payout", ?, "savings", "approved", ?, ?, NOW())',
                [$winner['user_id'], $pot, $wPlan['id'], $g['name'] . ' · round ' . $round . ' pot']);
            q('UPDATE ajo_members SET paid_out_at = NOW() WHERE id = ?', [$winner['id']]);
            notify((int)$winner['user_id'], 'You collected the pot 🎉', money0($pot) . ' from "' . $g['name'] . '" is now in your savings.', 'ajo.php?id=' . $groupId, true);
            if ($round >= (int)$g['size']) {
                q('UPDATE ajo_groups SET status = "completed", round_due = NULL WHERE id = ?', [$groupId]);
            } else {
                q('UPDATE ajo_groups SET current_round = current_round + 1, round_due = ? WHERE id = ?',
                    [date('Y-m-d', strtotime($g['round_due'] . ' ' . AJO_FREQUENCIES[$g['frequency']][1])), $groupId]);
            }
            $msg .= ' Everyone has paid, so ' . explode(' ', q_val('SELECT full_name FROM users WHERE id = ?', [$winner['user_id']]))[0] . ' received the ' . money0($pot) . ' pot.';
        }
        $pdo->commit();
        return $msg;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
