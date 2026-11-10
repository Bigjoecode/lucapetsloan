<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_staff();
if (!can('loans') && !can('waivers') && !can('collections')) {
    http_response_code(403);
    exit("You don't have access to this page.");
}

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
        if (!can(['waive' => 'waivers', 'collect_note' => 'collections'][$action] ?? 'loans')) throw new UserError("You don't have permission to do that.");
        $detail = $note;

        switch ($action) {
            case 'approve':
                if ($loan['status'] !== 'pending') throw new UserError('Only pending loans can be approved.');
                $kyc = q_val('SELECT kyc_status FROM users WHERE id = ?', [$uid]);
                if ($kyc !== 'verified') throw new UserError('Customer is not verified. A physical visit must be completed first.');
                $dual = setting('dual_approval_amount');
                if ($dual > 0 && (float)$loan['amount'] >= $dual) {
                    if (!$loan['first_approver_id']) {
                        q('UPDATE loans SET first_approver_id = ?, admin_note = ? WHERE id = ?', [$admin['id'], $note ?: null, $lid]);
                        notify_staff('loans', 'Second approval needed', money0($loan['amount']) . ' loan #' . $lid . ' has one approval and needs another staff member.', 'admin/loans.php?id=' . $lid);
                        $detail = 'first approval' . ($note !== '' ? ' · ' . $note : '');
                        $msg = 'First approval recorded. Loans of ' . money0($dual) . ' or more need a second approval from another staff member.';
                        break;
                    }
                    if ((int)$loan['first_approver_id'] === (int)$admin['id']) throw new UserError('You gave the first approval. A different staff member must give the second.');
                    $detail = 'second approval' . ($note !== '' ? ' · ' . $note : '');
                }
                q('UPDATE loans SET status = "approved", approved_at = NOW(), admin_note = COALESCE(?, admin_note) WHERE id = ?', [$note ?: null, $lid]);
                notify($uid, 'Loan approved 🎉', 'Your ' . money0($loan['amount']) . ' loan was approved and will be paid out shortly.', 'loan.php?id=' . $lid, true);
                $msg = 'Loan approved.';
                break;

            case 'reject':
                if (!in_array($loan['status'], ['pending', 'approved'], true)) throw new UserError('This loan can no longer be rejected.');
                if ($note === '') throw new UserError('Enter a reason for the customer.');
                q('UPDATE loans SET status = "rejected", admin_note = ?, closed_at = NOW() WHERE id = ?', [$note, $lid]);
                notify($uid, 'Loan application declined', $note, 'loan.php?id=' . $lid, true);
                $msg = 'Loan rejected.';
                break;

            case 'disburse':
                if ($loan['status'] !== 'approved') throw new UserError('Approve the loan before paying it out.');
                $ref = mb_substr(post('reference'), 0, 80);
                if ($ref === '') throw new UserError('Enter the transfer reference.');
                $date = post('disbursed_on') ?: date('Y-m-d');
                if (strtotime($date) > time()) throw new UserError('Pay-out date cannot be in the future.');
                if (q_val('SELECT 1 FROM transactions WHERE loan_id = ? AND type = "loan_disbursement" AND status = "processing"', [$lid])) throw new UserError('A Flutterwave pay-out for this loan is already in progress.');
                activate_loan($lid, $date, (int)$admin['id'], $ref, 'bank_transfer');
                $msg = 'Loan marked as paid out. Interest now accrues daily.';
                break;

            case 'flw_payout':
                // Commit the row lock first: the transfer call talks to Flutterwave.
                $pdo->commit();
                $pdo->beginTransaction();
                $msg = flw_send_loan($lid, (int)$admin['id']);
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
                notify($uid, 'Repayment received', money($amount) . ' was applied to loan #' . $lid . '.', 'loan.php?id=' . $lid, true);
                $msg = 'Repayment recorded.';
                break;

            case 'waive':
                if (!in_array($loan['status'], ['active', 'defaulted'], true)) throw new UserError('Only open loans have penalties to waive.');
                $s = loan_snapshot($loan);
                $amount = round((float)post('amount'), 2);
                $stop = (bool)post('stop_penalty') && empty($loan['penalty_stopped_on']);
                if ($note === '') throw new UserError('Enter the reason for the waiver.');
                if ($amount < 0 || $amount > $s['penalty'] + 0.001) throw new UserError('You can waive up to ' . money($s['penalty']) . ', the penalty owed now.');
                if ($amount <= 0 && !$stop) throw new UserError('Enter an amount to waive, or stop further penalties.');
                q('UPDATE loans SET penalty_waived = penalty_waived + ?, penalty_stopped_on = IF(?, CURDATE(), penalty_stopped_on) WHERE id = ?', [$amount, (int)$stop, $lid]);
                q('INSERT INTO loan_waivers (loan_id, amount, stopped_penalty, reason, admin_id) VALUES (?,?,?,?,?)', [$lid, $amount, (int)$stop, $note, $admin['id']]);
                $after = loan_snapshot(q_one('SELECT * FROM loans WHERE id = ?', [$lid]));
                if ($after['payoff'] <= 0.009) {
                    q('UPDATE loans SET status = "repaid", closed_at = NOW() WHERE id = ?', [$lid]);
                }
                notify($uid, 'Late penalty waived', trim(($amount > 0 ? money($amount) . ' of late penalty was waived on loan #' . $lid . '. ' : '') . ($stop ? 'No further late penalties will be added. ' : '') . 'You now owe ' . money($after['payoff']) . '.'), 'loan.php?id=' . $lid, true);
                $detail = money($amount) . ($stop ? ', penalties stopped' : '') . ' · ' . $note;
                $msg = 'Waiver applied. The customer now owes ' . money($after['payoff']) . '.';
                break;

            case 'collect_note':
                $outcome = post('outcome');
                if (!array_key_exists($outcome, COLLECTION_OUTCOMES)) throw new UserError('Choose what happened.');
                $promiseDate = post('promise_date') ?: null;
                $promiseAmount = $promiseDate ? round((float)post('promise_amount'), 2) : null;
                if ($outcome === 'promised' && (!$promiseDate || $promiseDate < date('Y-m-d') || $promiseAmount <= 0)) throw new UserError('Enter the promised amount and a date from today on.');
                q('INSERT INTO collection_notes (loan_id, staff_id, outcome, note, promise_date, promise_amount) VALUES (?,?,?,?,?,?)',
                    [$lid, $admin['id'], $outcome, mb_substr(post('note'), 0, 500), $outcome === 'promised' ? $promiseDate : null, $outcome === 'promised' ? $promiseAmount : null]);
                if (!$loan['collector_id']) {
                    q('UPDATE loans SET collector_id = ? WHERE id = ?', [$admin['id'], $lid]);
                }
                $detail = COLLECTION_OUTCOMES[$outcome] . ($outcome === 'promised' ? ' ' . money0($promiseAmount) . ' by ' . $promiseDate : '');
                $msg = 'Contact logged.';
                break;

            case 'default':
                if ($loan['status'] !== 'active') throw new UserError('Only active loans can be marked as defaulted.');
                q('UPDATE loans SET status = "defaulted", admin_note = ? WHERE id = ?', [$note ?: 'Marked as defaulted', $lid]);
                $msg = 'Loan marked as defaulted.';
                break;

            default:
                throw new UserError('Unknown action.');
        }
        audit('loan.' . $action, 'Loan #' . $lid, $detail);
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
    $payoutBusy = (bool)q_val('SELECT 1 FROM transactions WHERE loan_id = ? AND type = "loan_disbursement" AND status = "processing"', [$id]);
    $flwReady = $loan['status'] === 'approved' && flw_payouts_on() && !empty($kyc['bank_code']) && !$payoutBusy;
    $notes = q('SELECT n.*, u.full_name staff FROM collection_notes n LEFT JOIN users u ON u.id = n.staff_id WHERE n.loan_id = ? ORDER BY n.id DESC', [$id])->fetchAll();
    $waivers = q('SELECT w.*, u.full_name staff FROM loan_waivers w LEFT JOIN users u ON u.id = w.admin_id WHERE w.loan_id = ? ORDER BY w.id DESC', [$id])->fetchAll();
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
      <div class="card stat"><span class="k">Late fees</span><span class="v" style="<?= $s['penalty'] ? 'color:var(--bad)' : '' ?>"><?= money($s['penalty']) ?></span><span class="s"><?= $s['late_days'] ?> days late · due <?= fdate($loan['due_date']) ?><?= $s['penalty_waived'] > 0 ? ' · ' . money($s['penalty_waived']) . ' waived' : '' ?><?= $s['penalty_stopped'] ? ' · stopped ' . fdate($loan['penalty_stopped_on']) : '' ?></span></div>
    </div>
    <?php endif; ?>

    <div class="grid grid-2" style="align-items:start">
      <div class="stack">
        <div class="card">
          <h2>Application</h2>
          <dl class="kv">
            <dt>Amount</dt><dd><?= money($loan['amount']) ?></dd>
            <dt>Rate</dt><dd><?= (float)$loan['interest_rate'] ?>% per 30 days · late fee <?= (float)$loan['late_fee_rate'] ?>%/day</dd>
            <dt>Tenure</dt><dd><?= tenure_label((int)$loan['tenure_months']) ?></dd>
            <dt>Total repayable</dt><dd><?= money($s['total_repayable']) ?> (<?= money($s['installment']) ?> every 30 days)</dd>
            <dt>Purpose</dt><dd><?= e($loan['purpose']) ?></dd>
            <dt>Applied</dt><dd><?= fdatetime($loan['applied_at']) ?></dd>
            <dt>Agreement</dt><dd><a href="<?= url('agreement.php?id=' . $loan['id']) ?>" target="_blank">View</a><?= $loan['agreement_accepted_at'] ? ' · accepted ' . fdatetime($loan['agreement_accepted_at']) . ' (' . e($loan['agreement_ip']) . ')' : ' · not recorded' ?></dd>
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
        <?php if (!can('loans')): ?>
        <?php elseif ($loan['status'] === 'pending'): ?>
          <div class="card">
            <h2>Decision</h2>
            <?php if ($loan['kyc_status'] !== 'verified'): ?><div class="alert alert-warn">Customer is not verified. Approval is blocked until the physical visit is complete.</div><?php endif; ?>
            <?php if ($loan['first_approver_id']): ?><div class="alert alert-info">First approval given by <?= e(q_val('SELECT full_name FROM users WHERE id = ?', [$loan['first_approver_id']]) ?: 'staff') ?>. <?= (int)$loan['first_approver_id'] === (int)$admin['id'] ? 'Another staff member must give the second approval.' : 'Your approval completes it.' ?></div>
            <?php elseif (setting('dual_approval_amount') > 0 && (float)$loan['amount'] >= setting('dual_approval_amount')): ?><div class="alert alert-info">This loan needs two staff approvals (<?= money0(setting('dual_approval_amount')) ?> or more).</div><?php endif; ?>
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
              <button class="btn btn-<?= $flwReady ? 'ghost' : 'primary' ?>"><?= icon('cash', 16) ?> Mark as paid out</button>
            </form>
            <?php if ($flwReady): ?>
              <form method="post" class="form" style="margin-top:.6rem">
                <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="flw_payout">
                <button class="btn btn-primary" data-confirm="Send <?= e(money($loan['amount'])) ?> to <?= e($kyc['bank_name'] . ' ' . $kyc['account_number']) ?> through Flutterwave now?"><?= icon('cash', 16) ?> Pay out via Flutterwave</button>
              </form>
            <?php elseif ($payoutBusy): ?>
              <div class="alert alert-info" style="margin-top:.6rem">A Flutterwave pay-out is in progress. The loan starts when the bank confirms.</div>
            <?php endif; ?>
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

        <?php if (can('waivers') && in_array($loan['status'], ['active', 'defaulted'], true) && ($s['penalty'] > 0 || ($s['is_overdue'] && !$s['penalty_stopped']))): ?>
          <div class="card">
            <h2>Waive late penalty</h2>
            <p class="muted small">Penalty owed now: <strong><?= money($s['penalty']) ?></strong><?= $s['penalty_waived'] > 0 ? ' (after ' . money($s['penalty_waived']) . ' already waived)' : '' ?>. Interest and principal can't be waived here.</p>
            <form method="post" class="form">
              <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="waive">
              <label>Amount to waive<input type="number" name="amount" step="0.01" min="0" max="<?= $s['penalty'] ?>" value="<?= $s['penalty'] ?>"></label>
              <?php if (!$s['penalty_stopped']): ?>
                <label class="check"><input type="checkbox" name="stop_penalty" value="1"><span class="small">Also stop new penalties from today</span></label>
              <?php endif; ?>
              <label>Reason <span class="hint">Shown in the audit log</span><input name="note" required maxlength="255" placeholder="e.g. Hospital stay, agreed repayment plan"></label>
              <button class="btn btn-primary" data-confirm="Apply this waiver?">Apply waiver</button>
            </form>
          </div>
        <?php endif; ?>

        <?php if (can('collections') && (in_array($loan['status'], ['active', 'defaulted'], true) && $s['late_days'] > 0 || $notes)): ?>
          <div class="card" id="collect">
            <h2>Collections</h2>
            <?php if (in_array($loan['status'], ['active', 'defaulted'], true)): ?>
              <p class="small muted">Call <a href="tel:<?= e($loan['phone']) ?>"><?= e($loan['phone']) ?></a><?= $kyc ? ' or guarantor ' . e($kyc['guarantor_name']) . ' <a href="tel:' . e($kyc['guarantor_phone']) . '">' . e($kyc['guarantor_phone']) . '</a>' : '' ?>. Contact only these two, 8am–6pm, politely.</p>
              <form method="post" class="form">
                <?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $id ?>"><input type="hidden" name="action" value="collect_note">
                <label>What happened<select name="outcome" data-outcome><?php foreach (COLLECTION_OUTCOMES as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></label>
                <div class="form-grid" data-promise>
                  <label>Promised amount<input type="number" name="promise_amount" step="0.01" min="1" value="<?= $s['payoff'] ?>"></label>
                  <label>Promised by<input type="date" name="promise_date" min="<?= date('Y-m-d') ?>"></label>
                </div>
                <label>Notes<input name="note" maxlength="500" placeholder="e.g. Shop was closed last week; will pay Friday"></label>
                <button class="btn btn-primary btn-sm">Log contact</button>
              </form>
            <?php endif; ?>
            <?php if ($notes): ?>
              <ul class="list" style="margin-top:.8rem"><?php foreach ($notes as $n): ?>
                <li><div class="grow"><div class="title"><?= e(COLLECTION_OUTCOMES[$n['outcome']] ?? label($n['outcome'])) ?><?= $n['promise_date'] ? ' · ' . money0($n['promise_amount']) . ' by ' . fdate($n['promise_date']) : '' ?></div><div class="sub"><?= e($n['note']) ?><?= $n['note'] ? ' · ' : '' ?><?= e($n['staff'] ?? 'Staff') ?> · <?= fdatetime($n['created_at']) ?></div></div></li>
              <?php endforeach; ?></ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($waivers): ?>
          <div class="card">
            <h2>Waivers</h2>
            <ul class="list"><?php foreach ($waivers as $w): ?>
              <li><div class="grow"><div class="title"><?= money($w['amount']) ?> waived<?= $w['stopped_penalty'] ? ' · penalties stopped' : '' ?></div><div class="sub"><?= e($w['reason']) ?> · <?= e($w['staff'] ?? 'Staff') ?> · <?= fdatetime($w['created_at']) ?></div></div></li>
            <?php endforeach; ?></ul>
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
            <td><?= tenure_label((int)$l['tenure_months']) ?> @ <?= (float)$l['interest_rate'] ?>%</td>
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
