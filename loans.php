<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

$products = q('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY min_amount')->fetchAll();
$open = has_open_loan($uid);
$canApply = $u['kyc_status'] === 'verified' && !$open && $products;
$credit = credit_profile($u);
$cards = flw_deposits_on() ? user_cards($uid) : [];

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
        if (!in_array($tenure, loan_tenures(), true)) throw new UserError('Choose ' . tenure_choices() . '.');
        if ($credit['blocked']) throw new UserError('New loans are unavailable after a defaulted loan. Please contact us on ' . SUPPORT_PHONE . '.');
        if ($amount > $credit['limit']) throw new UserError('Your borrowing limit is ' . money0($credit['limit']) . ' right now. Repay on time and keep saving to raise it.');
        $cardId = (int)post('auto_card_id');
        if ($cardId && !q_val('SELECT 1 FROM payment_cards WHERE id = ? AND user_id = ? AND removed_at IS NULL', [$cardId, $uid])) $cardId = 0;
        $purpose = mb_substr(post('purpose'), 0, 255);
        if (mb_strlen($purpose) < 5) throw new UserError('Tell us briefly what the loan is for.');
        if (!post('agree')) throw new UserError('Please accept the loan terms.');

        q('INSERT INTO loans (user_id, product_id, amount, interest_rate, late_fee_rate, tenure_months, purpose, auto_repay, auto_card_id, agreement_accepted_at, agreement_ip) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)',
            [$uid, $p['id'], $amount, setting('loan_monthly_rate'), setting('loan_late_fee_rate'), $tenure, $purpose, (int)(bool)$cardId, $cardId ?: null,
             mb_substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $id = (int)db()->lastInsertId();

        // Repeat customers who paid their last loan on time don't wait for a person.
        $dual = setting('dual_approval_amount');
        if (setting('auto_approve_repeat') && $credit['trusted'] && !($dual > 0 && $amount >= $dual)) {
            q('UPDATE loans SET status = "approved", approved_at = NOW(), auto_approved = 1, admin_note = ? WHERE id = ?', ['Approved instantly · ' . $credit['name'] . ' level', $id]);
            audit('loan.auto_approve', 'Loan #' . $id, money0($amount) . ' for ' . $u['full_name'] . ' (' . $credit['name'] . ', ' . $credit['on_time'] . ' on-time)', null);
            $sent = false;
            if (setting('auto_disburse') && flw_payouts_on()) {
                try {
                    flw_send_loan($id, null);
                    $sent = true;
                } catch (UserError $e) {
                    error_log('[lucapetloan] instant loan #' . $id . ' not sent: ' . $e->getMessage());
                }
            }
            notify($uid, 'Loan approved 🎉', 'Your ' . money0($amount) . ' loan was approved instantly' . ($sent ? ' and is on its way to your bank.' : ' and will be paid out shortly.'), 'loan.php?id=' . $id, true);
            if (!$sent) {
                notify_staff('loans', 'Instant loan to pay out', $u['full_name'] . "'s " . money0($amount) . ' loan was approved automatically. Please pay it out.', 'admin/loans.php?id=' . $id);
            }
            flash('success', $sent ? 'Approved instantly! ' . money0($amount) . ' is on its way to your bank account.' : 'Approved instantly! We will pay ' . money0($amount) . ' into your bank account shortly.');
            redirect('loan.php?id=' . $id);
        }
        notify_staff('loans', 'New loan application', $u['full_name'] . ' applied for ' . money0($amount) . '.', 'admin/loans.php?id=' . $id);
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
              <?php else: ?><?= (float)$l['interest_rate'] ?>% per 30 days · <?= tenure_label((int)$l['tenure_months']) ?> · applied <?= fdate($l['applied_at']) ?><?php endif; ?>
            </div>
          </div>
          <?= badge($s['display_status']) ?>
          <a class="btn btn-ghost btn-sm" href="<?= url('loan.php?id=' . $l['id']) ?>">Open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($u['kyc_status'] === 'verified') echo credit_card_html($credit); ?>

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
              <option value="<?= $p['id'] ?>" data-rate="<?= setting('loan_monthly_rate') ?>" data-min="<?= (float)$p['min_amount'] ?>" data-max="<?= (float)$p['max_amount'] ?>" data-tenures="<?= implode(',', loan_tenures()) ?>"><?= e($p['name']) ?> · <?= money0($p['min_amount']) ?>–<?= money0($p['max_amount']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Amount <span class="hint" data-range></span><input type="number" name="amount" required step="500" value="<?= (float)$products[0]['min_amount'] ?>"></label>
        <label>Repay over<select name="tenure_months"></select></label>
        <label>What is the loan for?<textarea name="purpose" required maxlength="255" placeholder="e.g. Buy stock for my provisions shop"></textarea></label>
        <?php if ($cards): ?>
          <label>Repay automatically <span class="hint">Collected from your card on each due date</span>
            <select name="auto_card_id"><option value="0">No, I'll pay myself</option><?php foreach ($cards as $c): ?><option value="<?= $c['id'] ?>" selected><?= e(($c['brand'] ?: 'Card') . ' •••• ' . $c['last4']) ?></option><?php endforeach; ?></select>
          </label>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="agree" value="1"><span class="small">I have read and accept the <a href="<?= url('agreement.php') ?>" target="_blank" data-agreement-link>loan agreement and key facts</a>: interest of <?= setting('loan_monthly_rate') ?>% per 30 days builds up daily from the day the loan is paid to me, and a penalty of <?= setting('loan_late_fee_rate') ?>% of the unpaid balance is added every day after the due date.</span></label>
        <button class="btn btn-primary">Submit application</button>
      </form>
      <div class="calc-out" id="loan-calc">
        <span class="muted small">Total to repay at maturity</span>
        <span class="big tnum" data-o="total">—</span>
        <dl class="kv small" style="margin-top:.5rem">
          <dt>Interest rate</dt><dd data-o="rate">—</dd>
          <dt>Total interest</dt><dd class="tnum" data-o="interest">—</dd>
          <dt>Interest per day</dt><dd class="tnum" data-o="daily">—</dd>
          <dt>Due every 30 days</dt><dd class="tnum" data-o="monthly">—</dd>
        </dl>
        <p class="muted small" style="margin:.6rem 0 0">Late? A <?= setting('loan_late_fee_rate') ?>% penalty on the unpaid balance is added each day after the due date. Pay early and you only pay the interest built up so far. Your savings (<?= money0($savings) ?>) can also be used to repay.</p>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php app_footer();
