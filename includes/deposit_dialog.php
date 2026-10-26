<?php
/** @var array $plans active savings plans of the current user */
$openPlans = array_values(array_filter($plans, fn($p) => $p['status'] === 'active'));
?>
<dialog id="dlg-deposit">
  <div class="dlg-head"><h2>Save money</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <?php if (!$openPlans): ?>
      <p class="muted">You don't have an active savings plan yet.</p>
      <a class="btn btn-primary btn-block" href="<?= url('savings.php#new') ?>">Create a plan</a>
    <?php else: ?>
      <div class="bank-box" style="margin-bottom:1rem">
        Transfer to <strong><?= e(BANK_ACCOUNT_NUMBER) ?></strong><br><?= e(BANK_NAME) ?> · <?= e(BANK_ACCOUNT_NAME) ?><br>
        <span class="muted">Then enter the reference below. Your savings go up once we confirm the payment.</span>
      </div>
      <form method="post" action="<?= url('savings.php') ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="deposit">
        <input type="hidden" name="back" value="<?= e(basename($_SERVER['SCRIPT_NAME'])) ?>">
        <label>Savings plan
          <select name="plan_id" data-fill-amount>
            <?php foreach ($openPlans as $p): ?><option value="<?= $p['id'] ?>" data-amount="<?= (float)$p['daily_amount'] ?>"><?= e($p['name']) ?> (<?= money0($p['daily_amount']) ?>/day)</option><?php endforeach; ?>
          </select>
        </label>
        <label>Amount<input type="number" name="amount" min="<?= MIN_DEPOSIT ?>" step="50" required value="<?= (float)$openPlans[0]['daily_amount'] ?>"></label>
        <label>Payment method
          <select name="method"><?php foreach (payment_methods() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
        </label>
        <label>Payment reference <span class="hint">Transfer ID, receipt or agent slip number</span><input name="reference" maxlength="80" required></label>
        <button class="btn btn-primary btn-block">Submit deposit</button>
      </form>
    <?php endif; ?>
  </div>
</dialog>
