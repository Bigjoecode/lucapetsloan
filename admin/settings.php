<?php
require __DIR__ . '/../includes/bootstrap.php';
require_super();

const TENURE_OPTIONS = [1, 2, 3, 4, 6];   // in 30-day months

if (is_post() && post('action') === 'run_jobs') {
    verify_csrf();
    require_once __DIR__ . '/../includes/jobs.php';
    $done = run_daily_jobs(45);
    audit('settings.run_jobs', 'Daily job', json_encode($done));
    flash('success', 'Daily job finished: ' . $done['auto_save'] . ' auto-saves, ' . $done['auto_repay'] . ' auto-repayments, ' . $done['failed'] . ' failed, ' . $done['reminders'] . ' reminders.');
    redirect('admin/settings.php');
}

if (is_post()) {
    verify_csrf();
    $num = fn($k) => is_numeric(post($k)) ? (float)post($k) : -1;
    $tenures = array_values(array_intersect(TENURE_OPTIONS, array_map('intval', (array)($_POST['loan_tenures'] ?? []))));
    $new = [
        'loan_monthly_rate'   => $num('loan_monthly_rate'),
        'loan_late_fee_rate'  => $num('loan_late_fee_rate'),
        'savings_annual_rate' => $num('savings_annual_rate'),
        'min_deposit'         => (int)$num('min_deposit'),
        'min_withdrawal'      => (int)$num('min_withdrawal'),
        'loan_tenures'        => implode(',', $tenures),
        'manual_deposits'     => (int)(bool)post('manual_deposits'),
        'flw_deposits'        => (int)(bool)post('flw_deposits'),
        'flw_payouts'         => (int)(bool)post('flw_payouts'),
        'auto_payouts'        => (int)(bool)post('auto_payouts'),
        'auto_payout_daily_limit' => (int)$num('auto_payout_daily_limit'),
        'auto_approve_repeat' => (int)(bool)post('auto_approve_repeat'),
        'auto_disburse'       => (int)(bool)post('auto_disburse'),
        'sms_enabled'         => (int)(bool)post('sms_enabled'),
        'savings_sms_reminders' => (int)(bool)post('savings_sms_reminders'),
        'limit_starter'       => (int)$num('limit_starter'),
        'limit_bronze'        => (int)$num('limit_bronze'),
        'limit_silver'        => (int)$num('limit_silver'),
        'limit_gold'          => (int)$num('limit_gold'),
        'limit_platinum'      => (int)$num('limit_platinum'),
        'dual_approval_amount'=> (int)$num('dual_approval_amount'),
        'require_staff_2fa'   => (int)(bool)post('require_staff_2fa'),
        'referral_bonus'      => (int)$num('referral_bonus'),
        'referral_min_savings'=> (int)$num('referral_min_savings'),
        'visit_radius_m'      => (int)$num('visit_radius_m'),
        'bank_name'           => mb_substr(post('bank_name'), 0, 80),
        'bank_account_name'   => mb_substr(post('bank_account_name'), 0, 120),
        'bank_account_number' => mb_substr(post('bank_account_number'), 0, 20),
    ];
    $errors = [];
    if ($new['loan_monthly_rate'] <= 0 || $new['loan_monthly_rate'] > 100) $errors[] = 'Loan interest must be between 0 and 100% per 30 days.';
    if ($new['loan_late_fee_rate'] < 0 || $new['loan_late_fee_rate'] > 10) $errors[] = 'Late penalty must be between 0 and 10% per day.';
    if ($new['savings_annual_rate'] < 0 || $new['savings_annual_rate'] > 50) $errors[] = 'Savings interest must be between 0 and 50% a year.';
    if ($new['auto_payout_daily_limit'] < 0) $errors[] = 'The instant withdrawal limit cannot be negative (use 0 for no limit).';
    $levels = [$new['limit_starter'], $new['limit_bronze'], $new['limit_silver'], $new['limit_gold'], $new['limit_platinum']];
    $sorted = $levels;
    sort($sorted);
    if (min($levels) < 0 || $sorted !== $levels) $errors[] = 'Level limits must not be negative and must go up from Starter to Platinum.';
    if ($new['dual_approval_amount'] < 0 || $new['referral_bonus'] < 0 || $new['referral_min_savings'] < 0) $errors[] = 'Amounts cannot be negative.';
    if ($new['visit_radius_m'] < 50) $errors[] = 'Visit check-in distance must be at least 50 m.';
    if ($new['require_staff_2fa'] && !current_user()['totp_enabled']) $errors[] = 'Turn on two-step sign-in for yourself first (Sign-in security), so you do not lock yourself out.';
    if ($new['min_deposit'] < 1 || $new['min_withdrawal'] < 1) $errors[] = 'Minimum deposit and withdrawal must be at least 1.';
    if (!$tenures) $errors[] = 'Allow at least one loan term.';
    if (!$new['manual_deposits'] && !($new['flw_deposits'] && flw_configured())) $errors[] = 'Keep at least one way for customers to pay in.';
    if ($new['manual_deposits'] && ($new['bank_name'] === '' || !preg_match('/^\d{10}$/', $new['bank_account_number']))) $errors[] = 'Bank transfers need a bank name and a 10-digit account number.';

    if ($errors) {
        foreach ($errors as $er) flash('error', $er);
    } else {
        if ((float)setting('savings_annual_rate') !== $new['savings_annual_rate']) {
            // Settle every plan at the old rate first, so the new one only counts from today.
            foreach (q('SELECT id FROM savings_plans WHERE status <> "closed" AND balance > 0')->fetchAll() as $p) {
                accrue_plan_interest((int)$p['id']);
            }
        }
        $changes = [];
        foreach ($new as $k => $v) {
            if ((string)setting($k) !== (string)$v) {
                $changes[] = $k . ': ' . setting($k) . ' → ' . $v;
            }
            save_setting($k, $v);
        }
        if ($changes) {
            audit('settings.update', 'Settings', implode('; ', $changes));
        }
        flash('success', $changes ? 'Settings saved. New loan terms apply to new applications; savings interest changes apply from today.' : 'Nothing changed.');
    }
    redirect('admin/settings.php');
}

$on = fn($k) => setting($k) ? 'checked' : '';
app_header('Settings', 'settings', 'admin');
?>
<h1 class="mobile-title">Settings</h1>
<form method="post" class="stack">
  <?= csrf_field() ?>
  <div class="grid grid-2" style="align-items:start">
    <div class="card">
      <h2>Loans</h2>
      <div class="form">
        <div class="form-grid">
          <label>Interest % per 30 days<input type="number" name="loan_monthly_rate" step="0.01" min="0" required value="<?= setting('loan_monthly_rate') ?>"></label>
          <label>Late penalty % per day<input type="number" name="loan_late_fee_rate" step="0.001" min="0" required value="<?= setting('loan_late_fee_rate') ?>"></label>
        </div>
        <div>
          <div class="small" style="font-weight:600;margin-bottom:.3rem">Loan terms customers can choose</div>
          <div class="row">
            <?php foreach (TENURE_OPTIONS as $m): ?>
              <label class="check"><input type="checkbox" name="loan_tenures[]" value="<?= $m ?>" <?= in_array($m, loan_tenures(), true) ? 'checked' : '' ?>><span class="small"><?= tenure_label($m) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="muted small" style="margin:0">Each loan keeps the rate it was applied for, so changes only affect new applications.</p>
      </div>
    </div>

    <div class="card">
      <h2>Savings</h2>
      <div class="form">
        <div class="form-grid">
          <label>Interest % a year <span class="hint">Earned daily</span><input type="number" name="savings_annual_rate" step="0.1" min="0" max="50" required value="<?= setting('savings_annual_rate') ?>"></label>
          <label>Minimum deposit<input type="number" name="min_deposit" min="1" required value="<?= setting('min_deposit') ?>"></label>
          <label>Minimum withdrawal<input type="number" name="min_withdrawal" min="1" required value="<?= setting('min_withdrawal') ?>"></label>
        </div>
        <p class="muted small" style="margin:0">At <?= setting('savings_annual_rate') ?>% a year (<?= round(savings_daily_rate(), 4) ?>% a day), ₦10,000 kept for a year grows to <?= money(10000 * (1 + savings_daily_rate() / 100) ** 365) ?>. For comparison, flexible savings on PiggyVest and Cowrywise pay roughly 10–15% a year.</p>
      </div>
    </div>

    <div class="card">
      <h2>Flutterwave</h2>
      <div class="alert alert-<?= flw_configured() ? 'success' : 'warn' ?>" style="margin-bottom:.8rem"><?= flw_configured() ? 'Connected in <strong>' . (flw_mode() === 'live' ? 'LIVE mode: real money moves' : 'test mode: no real money moves') . '</strong>.' : 'Not connected.' ?> <a href="<?= url('admin/integrations.php') ?>">Manage keys &amp; test/live</a></div>
      <div class="form">
        <label class="check"><input type="checkbox" name="flw_deposits" value="1" <?= $on('flw_deposits') ?>><span class="small">Customers can pay deposits and repayments online (card, transfer, USSD)</span></label>
        <label class="check"><input type="checkbox" name="flw_payouts" value="1" <?= $on('flw_payouts') ?>><span class="small">Pay withdrawals through Flutterwave when staff press Send</span></label>
        <label class="check"><input type="checkbox" name="auto_payouts" value="1" <?= $on('auto_payouts') ?>><span class="small"><strong>Instant withdrawals:</strong> send them as soon as the customer asks, with no staff check</span></label>
        <label>Instant limit per customer per day <span class="hint">Above this, or within 24 hours of a payout account change, staff approve. 0 = no limit</span><input type="number" name="auto_payout_daily_limit" min="0" step="1000" value="<?= setting('auto_payout_daily_limit') ?>"></label>
        <label>Webhook URL <span class="hint">Flutterwave dashboard → Settings → Webhooks</span><input readonly value="<?= e(absolute_url('flw_webhook.php')) ?>" onclick="this.select()"></label>
      </div>
    </div>

    <div class="card">
      <h2>Credit levels &amp; instant loans</h2>
      <div class="form">
        <p class="muted small" style="margin:0">Customers move up a level with each on-time repayment (Bronze 1, Silver 2, Gold 4, Platinum 6) and down one for each late one. Their limit is the level amount plus their savings, up to the level amount again.</p>
        <div class="form-grid">
          <?php foreach (CREDIT_LEVELS as [$name]): $k = 'limit_' . strtolower($name); ?>
            <label><?= e($name) ?><input type="number" name="<?= $k ?>" min="0" step="1000" required value="<?= setting($k) ?>"></label>
          <?php endforeach; ?>
        </div>
        <label class="check"><input type="checkbox" name="auto_approve_repeat" value="1" <?= $on('auto_approve_repeat') ?>><span class="small"><strong>Instant approval</strong> for customers whose last loan was repaid on time (within their limit)</span></label>
        <label class="check"><input type="checkbox" name="auto_disburse" value="1" <?= $on('auto_disburse') ?>><span class="small">Pay instantly approved loans straight to the customer's bank through Flutterwave</span></label>
      </div>
    </div>

    <div class="card">
      <h2>Staff security &amp; checks</h2>
      <div class="form">
        <label>Two staff approvals for loans of<input type="number" name="dual_approval_amount" min="0" step="10000" value="<?= setting('dual_approval_amount') ?>"><span class="hint">0 = one approval is enough. Instant approval never applies at this size.</span></label>
        <label class="check"><input type="checkbox" name="require_staff_2fa" value="1" <?= $on('require_staff_2fa') ?>><span class="small">All staff must sign in with an authenticator app (two-step sign-in)</span></label>
        <label>Flag home-visit check-ins further than (metres)<input type="number" name="visit_radius_m" min="50" step="50" value="<?= setting('visit_radius_m') ?>"></label>
        <p class="small muted" style="margin:0">BVN &amp; NIN checks: <?= prembly_on() ? '<strong>on</strong> through Prembly' : 'off. Add your Prembly key under <a href="' . url('admin/integrations.php') . '">Integrations</a> to check every BVN and NIN before a visit is booked' ?>.</p>
      </div>
    </div>

    <div class="card">
      <h2>Referrals</h2>
      <div class="form">
        <div class="form-grid">
          <label>Bonus to the inviter<input type="number" name="referral_bonus" min="0" step="100" value="<?= setting('referral_bonus') ?>"><span class="hint">0 = referrals off</span></label>
          <label>Paid once the friend has saved<input type="number" name="referral_min_savings" min="0" step="500" value="<?= setting('referral_min_savings') ?>"></label>
        </div>
        <p class="small muted" style="margin:0"><?= (int)q_val('SELECT COUNT(*) FROM referrals') ?> referrals so far · <?= money0(q_val('SELECT COALESCE(SUM(amount),0) FROM referrals WHERE status = "rewarded"')) ?> paid in bonuses.</p>
      </div>
    </div>

    <div class="card">
      <h2>SMS</h2>
      <div class="alert alert-<?= termii_key() ? 'success' : 'warn' ?>" style="margin-bottom:.8rem"><?= termii_key() ? 'Termii connected · sender <strong>' . e(termii_sender()) . '</strong> · ' . (int)q_val('SELECT COUNT(*) FROM messages_log WHERE status = "sent" AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)') . ' sent in the last 30 days.' : 'Not connected.' ?> <a href="<?= url('admin/integrations.php') ?>">Manage keys</a></div>
      <div class="form">
        <label class="check"><input type="checkbox" name="sms_enabled" value="1" <?= $on('sms_enabled') ?>><span class="small">Text customers about approvals, pay-outs, payments, due dates and security changes</span></label>
        <label class="check"><input type="checkbox" name="savings_sms_reminders" value="1" <?= $on('savings_sms_reminders') ?>><span class="small">Also text a daily savings reminder when a plan hasn't been paid (one SMS per customer per day)</span></label>
      </div>
    </div>

    <div class="card">
      <h2>Bank transfer details</h2>
      <div class="form">
        <label class="check"><input type="checkbox" name="manual_deposits" value="1" <?= $on('manual_deposits') ?>><span class="small">Customers can also pay by bank transfer or agent and enter a reference for staff to confirm</span></label>
        <label>Bank name<input name="bank_name" value="<?= e(setting('bank_name')) ?>"></label>
        <div class="form-grid">
          <label>Account number<input name="bank_account_number" inputmode="numeric" maxlength="10" value="<?= e(setting('bank_account_number')) ?>"></label>
          <label>Account name<input name="bank_account_name" value="<?= e(setting('bank_account_name')) ?>"></label>
        </div>
      </div>
    </div>
  </div>
  <div><button class="btn btn-primary" data-confirm="Save these settings? Every change is recorded in the audit log.">Save settings</button></div>
</form>

<div class="card row between" style="margin-top:1rem">
  <div><h2 style="margin:0">Daily job</h2><span class="muted small">Collects auto-save and auto-repay payments, sends reminders and re-checks stuck payments every morning. Last run: <?= e(setting('last_cron_run') ?: 'never') ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_jobs"><button class="btn btn-ghost btn-sm" data-confirm="Run the daily job now? It charges saved cards that are due today.">Run now</button></form>
</div>
<?php app_footer();
