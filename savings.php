<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $back = in_array(post('back'), ['dashboard.php', 'savings.php'], true) ? post('back') : 'savings.php';

    try {
        if ($action === 'create_plan') {
            $name = post('name') ?: 'Savings plan';
            $daily = (float)post('daily_amount');
            $target = (float)post('target_amount');
            if ($daily < MIN_DEPOSIT) throw new UserError('Daily amount must be at least ' . money0(MIN_DEPOSIT) . '.');
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
            if ($amount < MIN_DEPOSIT) throw new UserError('Minimum deposit is ' . money0(MIN_DEPOSIT) . '.');
            if (!isset(payment_methods()[$method])) throw new UserError('Choose a payment method.');
            if (post('reference') === '') throw new UserError('Enter your payment reference.');
            q('INSERT INTO transactions (user_id, type, amount, method, reference, savings_plan_id) VALUES (?, "deposit", ?, ?, ?, ?)',
                [$uid, $amount, $method, mb_substr(post('reference'), 0, 80), $plan['id']]);
            flash('success', 'Deposit of ' . money($amount) . ' submitted. Your savings will go up once we confirm the payment.');
        }

        elseif ($action === 'withdraw') {
            $amount = round((float)post('amount'), 2);
            if ($amount < MIN_WITHDRAWAL) throw new UserError('Minimum withdrawal is ' . money0(MIN_WITHDRAWAL) . '.');
            $kyc = q_one('SELECT bank_name, account_number, account_name FROM kyc WHERE user_id = ?', [$uid]);
            if (!$kyc) throw new UserError('Add your bank details in Verification before withdrawing.');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $plan = q_one('SELECT * FROM savings_plans WHERE id = ? AND user_id = ? AND status <> "closed" FOR UPDATE', [(int)post('plan_id'), $uid]);
                if (!$plan) throw new UserError('Choose a savings plan.');
                if ($amount > (float)$plan['balance']) throw new UserError('You only have ' . money($plan['balance']) . ' in that plan.');
                // Hold the funds now so they can't be spent twice; they are returned if the request is declined.
                q('UPDATE savings_plans SET balance = balance - ? WHERE id = ?', [$amount, $plan['id']]);
                q('INSERT INTO transactions (user_id, type, amount, method, savings_plan_id, note) VALUES (?, "withdrawal", ?, "bank_transfer", ?, ?)',
                    [$uid, $amount, $plan['id'], 'To ' . $kyc['bank_name'] . ' ' . $kyc['account_number'] . ' (' . $kyc['account_name'] . ')']);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
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
$pending = q('SELECT * FROM transactions WHERE user_id = ? AND status = "pending" AND type IN ("deposit","withdrawal") ORDER BY id DESC', [$uid])->fetchAll();
$hasBank = (bool)q_val('SELECT COUNT(*) FROM kyc WHERE user_id = ?', [$uid]);

app_header('Savings', 'savings');
?>
<h1 class="mobile-title">Savings</h1>

<div class="card hero-card row between">
  <div class="stat"><span class="k">Total saved across <?= count($plans) ?> plan<?= count($plans) === 1 ? '' : 's' ?></span><span class="v"><?= money($total) ?></span></div>
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
      <div class="stat"><span class="k">Balance</span><span class="v"><?= money($p['balance']) ?></span></div>
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
    <p class="muted small">Set a daily amount you can keep up with. A steady saving record helps your loan application.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_plan">
      <label>Plan name<input name="name" maxlength="80" placeholder="e.g. Shop rent, School fees" required></label>
      <div class="form-grid">
        <label>Daily amount<input type="number" name="daily_amount" min="<?= MIN_DEPOSIT ?>" step="50" value="1000" required></label>
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
        <label>Amount<input type="number" name="amount" min="<?= MIN_WITHDRAWAL ?>" step="50" required></label>
        <p class="muted small">Paid to the bank account in your verification profile.</p>
        <button class="btn btn-primary btn-block">Request withdrawal</button>
      </form>
    <?php endif; ?>
  </div>
</dialog>

<?php include __DIR__ . '/includes/deposit_dialog.php'; ?>
<?php app_footer();
