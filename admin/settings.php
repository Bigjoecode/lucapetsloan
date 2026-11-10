<?php
require __DIR__ . '/../includes/bootstrap.php';
require_super();

const TENURE_OPTIONS = [1, 2, 3, 4, 6];   // in 30-day months

if (is_post()) {
    verify_csrf();
    $num = fn($k) => is_numeric(post($k)) ? (float)post($k) : -1;
    $tenures = array_values(array_intersect(TENURE_OPTIONS, array_map('intval', (array)($_POST['loan_tenures'] ?? []))));
    $new = [
        'loan_monthly_rate'   => $num('loan_monthly_rate'),
        'loan_late_fee_rate'  => $num('loan_late_fee_rate'),
        'savings_daily_rate'  => $num('savings_daily_rate'),
        'min_deposit'         => (int)$num('min_deposit'),
        'min_withdrawal'      => (int)$num('min_withdrawal'),
        'loan_tenures'        => implode(',', $tenures),
        'manual_deposits'     => (int)(bool)post('manual_deposits'),
        'flw_deposits'        => (int)(bool)post('flw_deposits'),
        'flw_payouts'         => (int)(bool)post('flw_payouts'),
        'auto_payouts'        => (int)(bool)post('auto_payouts'),
        'bank_name'           => mb_substr(post('bank_name'), 0, 80),
        'bank_account_name'   => mb_substr(post('bank_account_name'), 0, 120),
        'bank_account_number' => mb_substr(post('bank_account_number'), 0, 20),
    ];
    $errors = [];
    if ($new['loan_monthly_rate'] <= 0 || $new['loan_monthly_rate'] > 100) $errors[] = 'Loan interest must be between 0 and 100% per 30 days.';
    if ($new['loan_late_fee_rate'] < 0 || $new['loan_late_fee_rate'] > 10) $errors[] = 'Late penalty must be between 0 and 10% per day.';
    if ($new['savings_daily_rate'] < 0 || $new['savings_daily_rate'] > 5) $errors[] = 'Savings interest must be between 0 and 5% per day.';
    if ($new['min_deposit'] < 1 || $new['min_withdrawal'] < 1) $errors[] = 'Minimum deposit and withdrawal must be at least 1.';
    if (!$tenures) $errors[] = 'Allow at least one loan term.';
    if (!$new['manual_deposits'] && !($new['flw_deposits'] && flw_configured())) $errors[] = 'Keep at least one way for customers to pay in.';
    if ($new['manual_deposits'] && ($new['bank_name'] === '' || !preg_match('/^\d{10}$/', $new['bank_account_number']))) $errors[] = 'Bank transfers need a bank name and a 10-digit account number.';

    if ($errors) {
        foreach ($errors as $er) flash('error', $er);
    } else {
        if ((float)setting('savings_daily_rate') !== $new['savings_daily_rate']) {
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
          <label>Interest % per day<input type="number" name="savings_daily_rate" step="0.001" min="0" required value="<?= setting('savings_daily_rate') ?>"></label>
          <label>Minimum deposit<input type="number" name="min_deposit" min="1" required value="<?= setting('min_deposit') ?>"></label>
          <label>Minimum withdrawal<input type="number" name="min_withdrawal" min="1" required value="<?= setting('min_withdrawal') ?>"></label>
        </div>
        <p class="muted small" style="margin:0">At <?= setting('savings_daily_rate') ?>% a day, credited daily, ₦10,000 grows to <?= money(10000 * (1 + setting('savings_daily_rate') / 100) ** 365) ?> in a year.</p>
      </div>
    </div>

    <div class="card">
      <h2>Flutterwave</h2>
      <?php if (!flw_configured()): ?>
        <div class="alert alert-warn">Not connected. Add <code>FLW_SECRET_KEY</code> and <code>FLW_SECRET_HASH</code> to the hosting environment variables, then set the webhook URL below in your Flutterwave dashboard.</div>
      <?php else: ?>
        <div class="alert alert-success" style="margin-bottom:.8rem">Connected<?= str_contains(FLW_SECRET_KEY, '_TEST') ? ' in <strong>test mode</strong>; no real money moves' : ' with a <strong>live</strong> key' ?>.<?= FLW_SECRET_HASH === '' ? ' Webhook secret hash is missing, so payments only confirm when customers return to the app.' : '' ?></div>
      <?php endif; ?>
      <div class="form">
        <label class="check"><input type="checkbox" name="flw_deposits" value="1" <?= $on('flw_deposits') ?>><span class="small">Customers can pay deposits and repayments online (card, transfer, USSD)</span></label>
        <label class="check"><input type="checkbox" name="flw_payouts" value="1" <?= $on('flw_payouts') ?>><span class="small">Pay withdrawals through Flutterwave when staff press Send</span></label>
        <label class="check"><input type="checkbox" name="auto_payouts" value="1" <?= $on('auto_payouts') ?>><span class="small"><strong>Instant withdrawals:</strong> send them as soon as the customer asks, with no staff check</span></label>
        <label>Webhook URL <span class="hint">Flutterwave dashboard → Settings → Webhooks</span><input readonly value="<?= e(absolute_url('flw_webhook.php')) ?>" onclick="this.select()"></label>
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
<?php app_footer();
