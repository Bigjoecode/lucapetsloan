<?php
/** @var array $plans active savings plans of the current user */
$openPlans = array_values(array_filter($plans, fn($p) => $p['status'] === 'active'));
$back = e(basename($_SERVER['SCRIPT_NAME']));
$planSelect = function () use ($openPlans): void { ?>
  <label>Savings plan
    <select name="plan_id" data-fill-amount>
      <?php foreach ($openPlans as $p): ?><option value="<?= $p['id'] ?>" data-amount="<?= (float)$p['daily_amount'] ?>"><?= e($p['name']) ?> (<?= money0($p['daily_amount']) ?>/day)</option><?php endforeach; ?>
    </select>
  </label>
  <label>Amount<input type="number" name="amount" min="<?= setting('min_deposit') ?>" step="50" required value="<?= (float)$openPlans[0]['daily_amount'] ?>"></label>
<?php };
?>
<dialog id="dlg-deposit">
  <div class="dlg-head"><h2>Save money</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <?php if (!$openPlans): ?>
      <p class="muted">You don't have an active savings plan yet.</p>
      <a class="btn btn-primary btn-block" href="<?= url('savings.php#new') ?>">Create a plan</a>
    <?php elseif (!flw_deposits_on() && !setting('manual_deposits')): ?>
      <p class="muted">Deposits are paused right now. Please try again later or call <?= e(SUPPORT_PHONE) ?>.</p>
    <?php else: ?>
      <?php if (flw_deposits_on()): ?>
        <form method="post" action="<?= url('savings.php') ?>" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="deposit">
          <input type="hidden" name="method" value="flutterwave">
          <input type="hidden" name="back" value="<?= $back ?>">
          <?php $planSelect(); ?>
          <button class="btn btn-primary btn-block"><?= icon('cash', 16) ?> Pay now with card, transfer or USSD</button>
          <p class="muted small" style="margin:0;text-align:center">Secured by Flutterwave. Your savings go up as soon as the payment succeeds.</p>
        </form>
      <?php endif; ?>
      <?php if (setting('manual_deposits')): ?>
        <details class="pay-alt" <?= flw_deposits_on() ? '' : 'open' ?>>
          <summary><?= flw_deposits_on() ? 'Already paid by bank transfer or to an agent?' : 'Pay by bank transfer' ?></summary>
          <div class="bank-box" style="margin:.75rem 0 1rem">
            Transfer to <strong><?= e(setting('bank_account_number')) ?></strong><br><?= e(setting('bank_name')) ?> · <?= e(setting('bank_account_name')) ?><br>
            <span class="muted">Then enter the reference below. Your savings go up once we confirm the payment.</span>
          </div>
          <form method="post" action="<?= url('savings.php') ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deposit">
            <input type="hidden" name="back" value="<?= $back ?>">
            <?php $planSelect(); ?>
            <label>Payment method
              <select name="method"><?php foreach (payment_methods() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
            </label>
            <label>Payment reference <span class="hint">Transfer ID, receipt or agent slip number</span><input name="reference" maxlength="80" required></label>
            <button class="btn btn-ghost btn-block">Submit for confirmation</button>
          </form>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</dialog>
