<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$id = (int)($_GET['id'] ?? 0);

if (is_post()) {
    verify_csrf();
    $lid = (int)post('loan_id');
    $action = post('action');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $loan = q_one('SELECT * FROM loans WHERE id = ? FOR UPDATE', [$lid]);
        if (!$loan) throw new UserError('Loan not found.');
        $uid = (int)$loan['user_id'];
        $note = mb_substr(post('note'), 0, 255);

        switch ($action) {
            case 'approve':
                if ($loan['status'] !== 'pending') throw new UserError('Only pending loans can be approved.');
                $kyc = q_val('SELECT kyc_status FROM users WHERE id = ?', [$uid]);
                if ($kyc !== 'verified') throw new UserError('Customer is not verified. A physical visit must be completed first.');
                q('UPDATE loans SET status = "approved", approved_at = NOW(), admin_note = ? WHERE id = ?', [$note ?: null, $lid]);
                notify($uid, 'Loan approved 🎉', 'Your ' . money0($loan['amount']) . ' loan was approved and will be paid out shortly.', 'loan.php?id=' . $lid);
                $msg = 'Loan approved.';
                break;

            case 'reject':
                if (!in_array($loan['status'], ['pending', 'approved'], true)) throw new UserError('This loan can no longer be rejected.');
                if ($note === '') throw new UserError('Enter a reason for the customer.');
                q('UPDATE loans SET status = "rejected", admin_note = ?, closed_at = NOW() WHERE id = ?', [$note, $lid]);
                notify($uid, 'Loan application declined', $note, 'loan.php?id=' . $lid);
                $msg = 'Loan rejected.';
                break;

            case 'disburse':
                if ($loan['status'] !== 'approved') throw new UserError('Approve the loan before paying it out.');
                $ref = mb_substr(post('reference'), 0, 80);
                if ($ref === '') throw new UserError('Enter the transfer reference.');
                $date = post('disbursed_on') ?: date('Y-m-d');
                if (strtotime($date) > time()) throw new UserError('Pay-out date cannot be in the future.');
                $due = date('Y-m-d', strtotime($date . ' +' . ((int)$loan['tenure_months'] * DAYS_PER_MONTH) . ' days'));
                q('UPDATE loans SET status = "active", disbursed_at = ?, due_date = ? WHERE id = ?', [$date . ' ' . date('H:i:s'), $due, $lid]);
                q('INSERT INTO transactions (user_id, type, amount, method, reference, status, loan_id, note, processed_by, processed_at, created_at) VALUES (?, "loan_disbursement", ?, "bank_transfer", ?, "approved", ?, ?, ?, NOW(), ?)',
                    [$uid, $loan['amount'], $ref, $lid, 'Paid to customer bank account', $admin['id'], $date . ' ' . date('H:i:s')]);
                notify($uid, 'Loan paid out 💸', money0($loan['amount']) . ' has been sent to your bank account. Interest starts today; track it in the app.', 'loan.php?id=' . $lid);
                $msg = 'Loan marked as paid out. Interest now accrues daily.';
                break;

            case 'record_payment':
                if ($loan['status'] !== 'active' && $loan['status'] !== 'defaulted') throw new UserError('Loan is not open.');
                $amount = round((float)post('amount'), 2);
                if ($amount <= 0) throw new UserError('Enter an amount.');
                $method = isset(payment_methods()[post('method')]) ? post('method') : 'agent_cash';
                q('INSERT INTO transactions (user_id, type, amount, method, reference, status, loan_id, note, processed_by, processed_at) VALUES (?, "loan_repayment", ?, ?, ?, "approved", ?, ?, ?, NOW())',
                    [$uid, $amount, $method, mb_substr(post('reference'), 0, 80) ?: null, $lid, 'Recorded by staff', $admin['id']]);
                if ($loan['status'] === 'defaulted') {
                    q('UPDATE loans SET status = "active" WHERE id = ?', [$lid]);
                }
                apply_loan_payment($lid, $amount);
                notify($uid, 'Repayment received', money($amount) . ' was applied to loan #' . $lid . '.', 'loan.php?id=' . $lid);
                $msg = 'Repayment recorded.';
                break;

            case 'default':
                if ($loan['status'] !== 'active') throw new UserError('Only active loans can be marked as defaulted.');
                q('UPDATE loans SET status = "defaulted", admin_note = ? WHERE id = ?', [$note ?: 'Marked as defaulted', $lid]);
                $msg = 'Loan marked as defaulted.';
                break;

            default:
                throw new UserError('Unknown action.');
        }
        $pdo->commit();
        flash('success', $msg);
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('error', $e instanceof UserError ? $e->getMessage() : 'Something went wrong.');
    }
    redirect('admin/loans.php?id=' . $lid);
}

/* ---------- Detail ---------- */
if ($id) {
    $loan = q_one('SELECT l.*, p.name product, u.full_name, u.phone, u.email, u.kyc_status FROM loans l JOIN loan_products p ON p.id = l.product_id JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$id]);
    if (!$loan) {
        flash('error', 'Loan not found.');
        redirect('admin/loans.php');
    }
    $s = loan_snapshot($loan);
    $kyc = q_one('SELECT * FROM kyc WHERE user_id = ?', [$loan['user_id']]);
    $savings = savings_total((int)$loan['user_id']);
    $history = q('SELECT status, COUNT(*) c FROM loans WHERE user_id = ? AND id <> ? GROUP BY status', [$loan['user_id'], $id])->fetchAll(PDO::FETCH_KEY_PAIR);
    $daysSaved = (int)q_val('SELECT COUNT(DISTINCT DATE(created_at)) FROM transactions WHERE user_id = ? AND type = "deposit" AND status = "approved"', [$loan['user_id']]);
    $txs = q('SELECT * FROM transactions WHERE loan_id = ? ORDER BY id DESC', [$id])->fetchAll();
    $dti = $kyc && (float)$kyc['monthly_income'] > 0 ? $s['installment'] / (float)$kyc['monthly_income'] * 100 : null;

    app_header('Loan #' . $id, 'loans', 'admin');
    ?>
    <div class="row between">
      <div><a class="small" href="loans.php">← Loans</a><h2 style="margin:.2rem 0 0"><?= e($loan['full_name']) ?> · <?= money0($loan['amount']) ?></h2><span class="muted small"><?= e($loan['product']) ?> · <?= e($loan['phone']) ?></span></div>
      <?= badge($s['display_status']) ?>
    </div>

    <?php if (in_array($loan['status'], ['active', 'repaid', 'defaulted'], true)): ?>
    <div class="grid grid-4">
      <div class="card hero-card stat"><span class="k">To clear today</span><span class="v"><?= money($s['payoff']) ?></span><span class="s">Day <?= $s['days_elapsed'] ?> of <?= $s['term_days'] ?></span></div>
      <div class="card stat"><span class="k">Interest so far</span><span class="v"><?= money($s['accrued_interest']) ?></span><span class="s">+<?= money($s['daily_interest']) ?>/day</span></div>
      <div class="card stat"><span class="k">Paid</span><span class="v"><?= money($s['paid']) ?></span><span class="s">of <?= money($s['total_repayable']) ?></span></div>
      <div class="card stat"><span class="k">Late fees</span><span class="v" style="<?= $s['penalty'] ? 'color:var(--bad)' : '' ?>"><?= money($s['penalty']) ?></span><span class="s"><?= $s['late_days'] ?> days late · due <?= fdate($loan['due_date']) ?></span></div>
    </div>
    <?php endif; ?>

    <div class="grid grid-2" style="align-items:start">
      <div class="stack">
        <div class="card">
          <h2>Application</h2>
          <dl class="kv">
            <dt>Amount</dt><dd><?= money($loan['amount']) ?></dd>
            <dt>Rate</dt><dd><?= (float)$loan['interest_rate'] ?>%/month · late fee <?= (float)$loan['late_fee_rate'] ?>%/day</dd>
            <dt>Tenure</dt><dd><?= (int)$loan['tenure_months'] ?> months</dd>
            <dt>Total repayable</dt><dd><?= money($s['total_repayable']) ?> (<?= money($s['installment']) ?>/month)</dd>
            <dt>Purpose</dt><dd><?= e($loan['purpose']) ?></dd>
            <dt>Applied</dt><dd><?= fdatetime($loan['applied_at']) ?></dd>
            <?php if ($loan['approved_at']): ?><dt>Approved</dt><dd><?= fdatetime($loan['approved_at']) ?></dd><?php endif; ?>
            <?php if ($loan['disbursed_at']): ?><dt>Paid out</dt><dd><?= fdatetime($loan['disbursed_at']) ?></dd><?php endif; ?>
            <?php if ($loan['admin_note']): ?><dt>Staff note</dt><dd><?= e($loan['admin_note']) ?></dd><?php endif; ?>
          </dl>
        </div>
        <div class="card">
          <div class="card-head"><h2>Customer profile</h2><a class="small" href="kyc.php?id=<?= $loan['user_id'] ?>">Full verification</a></div>
          <dl class="kv">
            <dt>Verification</dt><dd><?= badge($loan['kyc_status']) ?></dd>
            <?php if ($kyc): ?>
              <dt>Visit</dt><dd><?= $kyc['visit_date'] ? fdate($kyc['visit_date']) . ' by ' . e($kyc['officer_name']) : '—' ?></dd>
              <dt>Occupation</dt><dd><?= e($kyc['occupation']) ?> · <?= e($kyc['employer_name']) ?></dd>
              <dt>Monthly income</dt><dd><?= money($kyc['monthly_income']) ?></dd>
              <dt>Installment / income</dt><dd><?= $dti !== null ? '<span style="color:' . ($dti > 40 ? 'var(--bad)' : 'var(--ok)') . '">' . number_format($dti, 1) . '%</span>' : '—' ?></dd>
              <dt>Pay out to</dt><dd><?= e($kyc['bank_name']) ?> · <?= e($kyc['account_number']) ?> · <?= e($kyc['account_name']) ?></dd>
              <dt>Guarantor</dt><dd><?= e($kyc['guarantor_name']) ?> · <?= e($kyc['guarantor_phone']) ?></dd>
            <?php endif; ?>
            <dt>Savings</dt><dd><?= money($savings) ?> · saved on <?= $daysSaved ?> days</dd>
            <dt>Past loans</dt><dd><?= $history ? implode(', ', array_map(fn($k, $c) => $c . ' ' . $k, array_keys($history), $history)) : 'None' ?></dd>
          </dl>
        </div>
      </div>

      <div class="stack">
        <?php if ($loan['status'] === 'pending'): ?>
          <div class="card">
            <h2>Decision</h2>
            <?php if ($loan['kyc_status'] !== 'verified'): ?><div class="alert alert-warn">Customer is not verified. Approval is blocked until the physical visit is complete.</div><?php endif; ?>
            <form method="post" class="form">
              <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>">
              <label>Note <span class="hint">Required when rejecting; shown to the customer</span><input name="note" maxlength="255"></label>
              <div class="row">
                <button class="btn btn-primary" name="action" value="approve" <?= $loan['kyc_status'] !== 'verified' ? 'disabled' : '' ?>><?= icon('check', 16) ?> Approve</button>
                <button class="btn btn-danger" name="action" value="reject" data-confirm="Reject this application?"><?= icon('x', 16) ?> Reject</button>
              </div>
            </form>
          </div>
        <?php elseif ($loan['status'] === 'approved'): ?>
          <div class="card">
            <h2>Pay out the loan</h2>
            <p class="muted small">Send <?= money($loan['amount']) ?> to <?= $kyc ? e($kyc['bank_name'] . ' ' . $kyc['account_number'] . ' (' . $kyc['account_name'] . ')') : 'the customer' ?>, then record it here. Interest starts on the pay-out date.</p>
            <form method="post" class="form">
              <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="disburse">
              <div class="form-grid">
                <label>Transfer reference<input name="reference" required maxlength="80"></label>
                <label>Pay-out date<input type="date" name="disbursed_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
              </div>
              <button class="btn btn-primary"><?= icon('cash', 16) ?> Mark as paid out</button>
            </form>
            <form method="post" class="form" style="margin-top:1rem">
              <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
              <label>Cancel with reason<input name="note" required maxlength="255"></label>
              <button class="btn btn-ghost btn-sm" data-confirm="Cancel this approved loan?">Cancel loan</button>
            </form>
          </div>
        <?php elseif (in_array($loan['status'], ['active', 'defaulted'], true)): ?>
          <div class="card">
            <h2>Record a repayment</h2>
            <p class="muted small">For cash collected by a field agent or a transfer the customer didn't log in the app.</p>
            <form method="post" class="form">
              <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="record_payment">
              <div class="form-grid">
                <label>Amount<input type="number" name="amount" step="0.01" min="1" required value="<?= $s['payoff'] ?>"></label>
                <label>Method<select name="method"><?php foreach (payment_methods() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'agent_cash' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
                <label>Reference<input name="reference" maxlength="80"></label>
              </div>
              <button class="btn btn-primary">Record repayment</button>
            </form>
            <?php if ($loan['status'] === 'active' && $s['is_overdue']): ?>
              <form method="post" class="form" style="margin-top:1rem">
                <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="default">
                <label>Default note<input name="note" maxlength="255" placeholder="e.g. Unreachable after 3 visits"></label>
                <button class="btn btn-danger btn-sm" data-confirm="Mark this loan as defaulted?">Mark as defaulted</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <h2>Schedule</h2>
          <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Due</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody><?php foreach (loan_schedule($loan) as $r): ?>
              <tr><td><?= $r['no'] ?></td><td><?= $loan['disbursed_at'] ? fdate($r['due']) : '+' . ($r['no'] * DAYS_PER_MONTH) . 'd' ?></td><td class="num"><?= money($r['amount']) ?></td><td class="right"><?= badge($r['state']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        </div>

        <?php if ($txs): ?>
          <div class="card"><h2>Transactions</h2><ul class="list"><?php foreach ($txs as $t) echo tx_row($t); ?></ul></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    app_footer();
    exit;
}

/* ---------- List ---------- */
$tabs = ['pending' => 'Pending', 'approved' => 'To pay out', 'active' => 'Active', 'repaid' => 'Repaid', 'defaulted' => 'Defaulted', 'rejected' => 'Rejected'];
$status = array_key_exists($_GET['status'] ?? '', $tabs) ? $_GET['status'] : 'pending';
$counts = q('SELECT status, COUNT(*) c FROM loans GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$rows = q('SELECT l.*, p.name product, u.full_name, u.phone FROM loans l JOIN loan_products p ON p.id = l.product_id JOIN users u ON u.id = l.user_id WHERE l.status = ? ORDER BY l.id DESC LIMIT 300', [$status])->fetchAll();

app_header('Loans', 'loans', 'admin');
?>
<h1 class="mobile-title">Loans</h1>
<div class="row">
  <?php foreach ($tabs as $key => $t): ?>
    <a class="btn btn-sm <?= $key === $status ? 'btn-primary' : 'btn-ghost' ?>" href="?status=<?= $key ?>"><?= e($t) ?> (<?= (int)($counts[$key] ?? 0) ?>)</a>
  <?php endforeach; ?>
</div>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('loan', 32) ?><p>No loans here.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Customer</th><th>Product</th><th class="num">Amount</th>
      <?php if (in_array($status, ['active', 'defaulted'], true)): ?><th class="num">Interest so far</th><th class="num">To clear</th><th>Due</th><?php else: ?><th>Tenure</th><th>Applied</th><?php endif; ?>
      <th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $l): $s = loan_snapshot($l); ?>
        <tr>
          <td><?= $l['id'] ?></td>
          <td><strong><?= e($l['full_name']) ?></strong><div class="muted small"><?= e($l['phone']) ?></div></td>
          <td><?= e($l['product']) ?></td>
          <td class="num"><?= money0($l['amount']) ?></td>
          <?php if (in_array($status, ['active', 'defaulted'], true)): ?>
            <td class="num"><?= money($s['accrued_interest']) ?></td>
            <td class="num"><strong><?= money($s['payoff']) ?></strong></td>
            <td><?= fdate($l['due_date']) ?> <?= $s['is_overdue'] ? badge('overdue') : '' ?></td>
          <?php else: ?>
            <td><?= (int)$l['tenure_months'] ?> mo @ <?= (float)$l['interest_rate'] ?>%</td>
            <td class="muted small"><?= fdate($l['applied_at']) ?></td>
          <?php endif; ?>
          <td class="right"><a class="btn btn-ghost btn-sm" href="?id=<?= $l['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php app_footer();
