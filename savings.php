<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
accrue_user_interest($uid);

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $back = in_array(post('back'), ['dashboard.php', 'savings.php'], true) ? post('back') : 'savings.php';

    try {
        if ($action === 'create_plan') {
            $name = post('name') ?: 'Savings plan';
            $daily = (float)post('daily_amount');
            $target = (float)post('target_amount');
            if ($daily < setting('min_deposit')) throw new UserError('Daily amount must be at least ' . money0(setting('min_deposit')) . '.');
            if ((int)q_val('SELECT COUNT(*) FROM savings_plans WHERE user_id = ? AND status <> "closed"', [$uid]) >= 5) {
                throw new UserError('You can have up to 5 open savings plans.');
            }
            q('INSERT INTO savings_plans (user_id, name, daily_amount, target_amount) VALUES (?,?,?,?)', [$uid, mb_substr($name, 0, 80), $daily, max(0, $target)]);
            flash('success', 'Savings plan "' . $name . '" created.');
        }

        elseif ($action === 'deposit') {
            $plan = q_one('SELECT * FROM savings_plans WHERE id = ? AND user_id = ? AND status = "active"', [(int)post('plan_id'), $uid]);
            $amount = round((float)post('amount'), 2);
            $method = post('method');
            if (!$plan) throw new UserError('Choose an active savings plan.');
            if ($amount < setting('min_deposit')) throw new UserError('Minimum deposit is ' . money0(setting('min_deposit')) . '.');
            if ($method === 'flutterwave') {
                if (!flw_deposits_on()) throw new UserError('Online payment is not available right now.');
                q('INSERT INTO transactions (user_id, type, amount, method, reference, savings_plan_id) VALUES (?, "deposit", ?, "flutterwave", ?, ?)',
                    [$uid, $amount, flw_reference('LPL-DEP'), $plan['id']]);
                $tx = q_one('SELECT * FROM transactions WHERE id = ?', [db()->lastInsertId()]);
                try {
                    redirect_external(flw_checkout($tx, $u, 'Savings deposit · ' . $plan['name']));
                } catch (UserError $e) {
                    q('UPDATE transactions SET status = "rejected", admin_note = "Payment could not start" WHERE id = ?', [$tx['id']]);
                    throw $e;
                }
            }
            if (!setting('manual_deposits')) throw new UserError('Please use the online payment option.');
            if (!isset(payment_methods()[$method])) throw new UserError('Choose a payment method.');
            if (post('reference') === '') throw new UserError('Enter your payment reference.');
            q('INSERT INTO transactions (user_id, type, amount, method, reference, savings_plan_id) VALUES (?, "deposit", ?, ?, ?, ?)',
                [$uid, $amount, $method, mb_substr(post('reference'), 0, 80), $plan['id']]);
            notify_staff('payments', 'Deposit to confirm', $u['full_name'] . ' says they paid ' . money($amount) . ' (' . (payment_methods()[$method] ?? $method) . ').', 'admin/payments.php?type=deposit');
            flash('success', 'Deposit of ' . money($amount) . ' submitted. Your savings will go up once we confirm the payment.');
        }

        elseif ($action === 'withdraw') {
            $amount = round((float)post('amount'), 2);
            if ($amount < setting('min_withdrawal')) throw new UserError('Minimum withdrawal is ' . money0(setting('min_withdrawal')) . '.');
            $kyc = q_one('SELECT bank_name, account_number, account_name FROM kyc WHERE user_id = ?', [$uid]);
            if (!$kyc) throw new UserError('Add your bank details in Verification before withdrawing.');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $plan = q_one('SELECT * FROM savings_plans WHERE id = ? AND user_id = ? AND status <> "closed" FOR UPDATE', [(int)post('plan_id'), $uid]);
                if (!$plan) throw new UserError('Choose a savings plan.');
                if (accrue_plan_interest((int)$plan['id']) > 0) {
                    $plan = q_one('SELECT * FROM savings_plans WHERE id = ?', [$plan['id']]);
                }
                if ($amount > (float)$plan['balance']) throw new UserError('You only have ' . money($plan['balance']) . ' in that plan.');
                // Hold the funds now so they can't be spent twice; they are returned if the request is declined.
                q('UPDATE savings_plans SET balance = balance - ? WHERE id = ?', [$amount, $plan['id']]);
                q('INSERT INTO transactions (user_id, type, amount, method, savings_plan_id, note) VALUES (?, "withdrawal", ?, "bank_transfer", ?, ?)',
                    [$uid, $amount, $plan['id'], 'To ' . $kyc['bank_name'] . ' ' . $kyc['account_number'] . ' (' . $kyc['account_name'] . ')']);
                $txId = (int)db()->lastInsertId();
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $hold = auto_payout_hold($uid, $txId);
            if ($hold !== null) {
                notify_staff('payments', 'Withdrawal to check', $u['full_name'] . ' asked to withdraw ' . money($amount) . '. Not sent automatically: ' . $hold . '.', 'admin/payments.php?type=withdrawal');
                flash('success', 'Withdrawal request for ' . money($amount) . ' sent. For your safety a member of staff will check it first; we pay within 24 hours.');
                redirect($back);
            }
            if (setting('auto_payouts') && flw_payouts_on()) {
                try {
                    flw_send_withdrawal($txId, null);
                    flash('success', money($amount) . ' is on its way to your bank account.');
                    redirect($back);
                } catch (UserError $e) {
                    error_log('[lucapetloan] auto payout #' . $txId . ' not sent: ' . $e->getMessage());
                }
            }
            notify_staff('payments', 'Withdrawal to pay', $u['full_name'] . ' asked to withdraw ' . money($amount) . '.', 'admin/payments.php?type=withdrawal');
            flash('success', 'Withdrawal request for ' . money($amount) . ' sent. We pay withdrawals into your bank within 24 hours.');
        }

        elseif ($action === 'close_plan') {
            $plan = q_one('SELECT * FROM savings_plans WHERE id = ? AND user_id = ?', [(int)post('plan_id'), $uid]);
            if (!$plan) throw new UserError('Plan not found.');
            if ((float)$plan['balance'] > 0) throw new UserError('Withdraw the balance before closing this plan.');
            q('UPDATE savings_plans SET status = "closed" WHERE id = ?', [$plan['id']]);
            flash('success', 'Plan closed.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$plans = q('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" ORDER BY id', [$uid])->fetchAll();
$withdrawable = array_values(array_filter($plans, fn($p) => (float)$p['balance'] > 0));
$total = array_sum(array_map(fn($p) => (float)$p['balance'], $plans));
$earned = array_sum(array_map(fn($p) => (float)$p['interest_earned'], $plans));
$pending = q('SELECT * FROM transactions WHERE user_id = ? AND status IN ("pending","processing") AND type IN ("deposit","withdrawal") ORDER BY id DESC', [$uid])->fetchAll();
$payout = q_one('SELECT bank_name, bank_code, account_number, account_name FROM kyc WHERE user_id = ?', [$uid]);
$hasBank = (bool)$payout;

app_header('Savings', 'savings');
?>
<h1 class="mobile-title">Savings</h1>

<div class="card hero-card row between">
  <div class="stat"><span class="k">Total saved across <?= count($plans) ?> plan<?= count($plans) === 1 ? '' : 's' ?></span><span class="v"><?= money($total) ?></span><span class="s">Earning <?= setting('savings_daily_rate') ?>% interest every day · <?= money($earned) ?> earned so far</span></div>
  <div class="row">
    <button class="btn btn-accent btn-sm" data-open="dlg-deposit"><?= icon('plus', 16) ?> Deposit</button>
    <button class="btn btn-light btn-sm" data-open="dlg-withdraw" id="withdraw"><?= icon('up', 16) ?> Withdraw</button>
  </div>
</div>

<?php if ($pending): ?>
<div class="card">
  <div class="card-head"><h2>Waiting for confirmation</h2></div>
  <ul class="list"><?php foreach ($pending as $t) echo tx_row($t); ?></ul>
</div>
<?php endif; ?>

<div class="grid grid-2">
  <?php foreach ($plans as $p):
      $days = plan_contribution_days((int)$p['id'], 30);
      $streak = plan_streak((int)$p['id']);
      $pct = (float)$p['target_amount'] > 0 ? min(100, (int)round((float)$p['balance'] / (float)$p['target_amount'] * 100)) : null;
  ?>
  <div class="card stack">
    <div class="row between">
      <div><h2 style="margin:0"><?= e($p['name']) ?></h2><span class="muted small"><?= money0($p['daily_amount']) ?> per day · since <?= fdate($p['created_at']) ?></span></div>
      <?= badge($p['status']) ?>
    </div>
    <div class="row between">
      <div class="stat"><span class="k">Balance</span><span class="v"><?= money($p['balance']) ?></span><span class="s">+<?= money($p['interest_earned']) ?> interest · about <?= money((float)$p['balance'] * setting('savings_daily_rate') / 100) ?> tomorrow</span></div>
      <?php if ($streak): ?><span class="streak"><?= icon('fire', 16) ?><?= $streak ?>-day streak</span><?php endif; ?>
    </div>
    <?php if ($pct !== null): ?>
      <div><div class="progress"><span style="width:<?= $pct ?>%"></span></div>
      <div class="row between small muted" style="margin-top:.3rem"><span><?= $pct ?>% of target</span><span><?= money0($p['target_amount']) ?></span></div></div>
    <?php endif; ?>
    <div>
      <div class="small muted" style="margin-bottom:.4rem">Last 30 days (<?= count($days) ?> days saved)</div>
      <div class="days" title="Green = day you saved">
        <?php for ($i = 29; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i day")); ?>
          <span class="<?= isset($days[$d]) ? 'on' : '' ?> <?= $i === 0 ? 'today' : '' ?>" title="<?= fdate($d) ?><?= isset($days[$d]) ? ' · ' . money0($days[$d]) : '' ?>"></span>
        <?php endfor; ?>
      </div>
    </div>
    <div class="row">
      <?php if ($p['status'] === 'active'): ?>
        <button class="btn btn-primary btn-sm" data-open="dlg-deposit" data-plan="<?= $p['id'] ?>" data-amount="<?= (float)$p['daily_amount'] ?>">Pay today's <?= money0($p['daily_amount']) ?></button>
      <?php endif; ?>
      <?php if ((float)$p['balance'] <= 0): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="close_plan"><input type="hidden" name="plan_id" value="<?= $p['id'] ?>"><button class="btn btn-ghost btn-sm" data-confirm="Close this plan?">Close plan</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="card" id="new">
    <h2>New savings plan</h2>
    <p class="muted small">Set a daily amount you can keep up with. Every plan earns <?= setting('savings_daily_rate') ?>% interest per day on its balance, and a steady saving record helps your loan application.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_plan">
      <label>Plan name<input name="name" maxlength="80" placeholder="e.g. Shop rent, School fees" required></label>
      <div class="form-grid">
        <label>Daily amount<input type="number" name="daily_amount" min="<?= setting('min_deposit') ?>" step="50" value="1000" required></label>
        <label>Target <span class="hint">optional</span><input type="number" name="target_amount" min="0" step="1000" placeholder="0"></label>
      </div>
      <button class="btn btn-primary"><?= icon('plus', 16) ?> Create plan</button>
    </form>
  </div>
</div>

<dialog id="dlg-withdraw">
  <div class="dlg-head"><h2>Withdraw savings</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <?php if (!$hasBank): ?>
      <p class="muted">We need your bank details first. Add them in the verification form.</p>
      <a class="btn btn-primary btn-block" href="<?= url('verification.php') ?>">Go to verification</a>
    <?php elseif (!$withdrawable): ?>
      <p class="muted">You have no savings to withdraw yet.</p>
    <?php else: ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="withdraw">
        <label>From plan
          <select name="plan_id"><?php foreach ($withdrawable as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= money($p['balance']) ?>)</option><?php endforeach; ?></select>
        </label>
        <label>Amount<input type="number" name="amount" min="<?= setting('min_withdrawal') ?>" step="50" required></label>
        <p class="muted small"><?= setting('auto_payouts') && flw_payouts_on() && $payout['bank_code'] ? 'Sent instantly to' : 'Paid to' ?> <?= e($payout['bank_name'] . ' · ' . $payout['account_number']) ?>.<?php if (flw_payouts_on() && !$payout['bank_code']): ?> <a href="<?= url('profile.php#payout') ?>">Confirm your bank</a> for faster payouts.<?php endif; ?></p>
        <button class="btn btn-primary btn-block">Request withdrawal</button>
      </form>
    <?php endif; ?>
  </div>
</dialog>

<?php include __DIR__ . '/includes/deposit_dialog.php'; ?>
<?php app_footer();
