<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

$products = q('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY min_amount')->fetchAll();
$open = has_open_loan($uid);
$canApply = $u['kyc_status'] === 'verified' && !$open && $products;

if (is_post()) {
    verify_csrf();
    try {
        if ($u['kyc_status'] !== 'verified') throw new UserError('Complete your verification and home visit before applying.');
        if (has_open_loan($uid)) throw new UserError('You already have a loan in progress.');
        $p = q_one('SELECT * FROM loan_products WHERE id = ? AND is_active = 1', [(int)post('product_id')]);
        if (!$p) throw new UserError('Choose a loan product.');
        $amount = round((float)post('amount'), 2);
        $tenure = (int)post('tenure_months');
        if ($amount < (float)$p['min_amount'] || $amount > (float)$p['max_amount']) {
            throw new UserError('Amount for ' . $p['name'] . ' must be between ' . money0($p['min_amount']) . ' and ' . money0($p['max_amount']) . '.');
        }
        if ($tenure < (int)$p['min_tenure'] || $tenure > (int)$p['max_tenure']) throw new UserError('Choose a valid tenure.');
        $purpose = mb_substr(post('purpose'), 0, 255);
        if (mb_strlen($purpose) < 5) throw new UserError('Tell us briefly what the loan is for.');
        if (!post('agree')) throw new UserError('Please accept the loan terms.');

        q('INSERT INTO loans (user_id, product_id, amount, interest_rate, late_fee_rate, tenure_months, purpose) VALUES (?,?,?,?,?,?,?)',
            [$uid, $p['id'], $amount, $p['interest_rate'], $p['late_fee_rate'], $tenure, $purpose]);
        $id = (int)db()->lastInsertId();
        foreach (q('SELECT id FROM users WHERE role = "admin"')->fetchAll() as $a) {
            notify((int)$a['id'], 'New loan application', $u['full_name'] . ' applied for ' . money0($amount) . '.', 'admin/loans.php?id=' . $id);
        }
        flash('success', 'Loan application submitted. We will review it and notify you.');
        redirect('loan.php?id=' . $id);
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        redirect('loans.php#apply');
    }
}

$loans = q('SELECT l.*, p.name product FROM loans l JOIN loan_products p ON p.id = l.product_id WHERE l.user_id = ? ORDER BY l.id DESC', [$uid])->fetchAll();
$savings = savings_total($uid);

app_header('Loans', 'loans');
?>
<h1 class="mobile-title">Loans</h1>

<div class="card">
  <div class="card-head"><h2>My loans</h2></div>
  <?php if (!$loans): ?>
    <div class="empty"><?= icon('loan', 32) ?><p>You haven't taken any loans yet.</p></div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($loans as $l): $s = loan_snapshot($l); ?>
        <li>
          <span class="tx-ic tx-loan"><?= icon('loan', 18) ?></span>
          <div class="grow">
            <div class="title"><?= e($l['product']) ?> · <?= money0($l['amount']) ?></div>
            <div class="sub">
              <?php if ($l['status'] === 'active'): ?>Clear today: <strong><?= money($s['payoff']) ?></strong> · interest so far <?= money($s['accrued_interest']) ?>
              <?php else: ?><?= (float)$l['interest_rate'] ?>%/month · <?= (int)$l['tenure_months'] ?> mo · applied <?= fdate($l['applied_at']) ?><?php endif; ?>
            </div>
          </div>
          <?= badge($s['display_status']) ?>
          <a class="btn btn-ghost btn-sm" href="<?= url('loan.php?id=' . $l['id']) ?>">Open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card" id="apply">
  <div class="card-head"><h2>Apply for a loan</h2></div>
  <?php if ($u['kyc_status'] !== 'verified'): ?>
    <div class="alert alert-warn" style="margin:0">
      Loans are only available after your verification and <strong>physical home visit</strong> are complete. Current status: <?= badge($u['kyc_status']) ?>
      <div style="margin-top:.6rem"><a class="btn btn-primary btn-sm" href="<?= url('verification.php') ?>">Go to verification</a></div>
    </div>
  <?php elseif ($open): ?>
    <div class="alert alert-info" style="margin:0">You already have a loan in progress (<?= label($open['status']) ?>). You can apply again once it is fully repaid.</div>
  <?php elseif (!$products): ?>
    <p class="muted">No loan products are available right now.</p>
  <?php else: ?>
    <div class="grid grid-2" style="align-items:start">
      <form method="post" class="form" data-calc="#loan-calc">
        <?= csrf_field() ?>
        <label>Loan product
          <select name="product_id">
            <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>" data-rate="<?= (float)$p['interest_rate'] ?>" data-min="<?= (float)$p['min_amount'] ?>" data-max="<?= (float)$p['max_amount'] ?>" data-tmin="<?= (int)$p['min_tenure'] ?>" data-tmax="<?= (int)$p['max_tenure'] ?>"><?= e($p['name']) ?> · <?= (float)$p['interest_rate'] ?>%/month</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Amount <span class="hint" data-range></span><input type="number" name="amount" required step="500" value="<?= (float)$products[0]['min_amount'] ?>"></label>
        <label>Repay over<select name="tenure_months"></select></label>
        <label>What is the loan for?<textarea name="purpose" required maxlength="255" placeholder="e.g. Buy stock for my provisions shop"></textarea></label>
        <label class="check"><input type="checkbox" name="agree" value="1"><span class="small">I understand interest builds up daily from the day the loan is paid to me, and a late fee applies each day after the due date.</span></label>
        <button class="btn btn-primary">Submit application</button>
      </form>
      <div class="calc-out" id="loan-calc">
        <span class="muted small">Total to repay at maturity</span>
        <span class="big tnum" data-o="total">—</span>
        <dl class="kv small" style="margin-top:.5rem">
          <dt>Interest rate</dt><dd data-o="rate">—</dd>
          <dt>Total interest</dt><dd class="tnum" data-o="interest">—</dd>
          <dt>Interest per day</dt><dd class="tnum" data-o="daily">—</dd>
          <dt>Monthly installment</dt><dd class="tnum" data-o="monthly">—</dd>
        </dl>
        <p class="muted small" style="margin:.6rem 0 0">Pay early and you only pay the interest built up so far. Your savings (<?= money0($savings) ?>) can also be used to repay.</p>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php app_footer();
