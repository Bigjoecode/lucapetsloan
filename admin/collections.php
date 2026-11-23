<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_staff('collections');

if (is_post()) {
    verify_csrf();
    $lid = (int)post('loan_id');
    $loan = q_one('SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id WHERE l.id = ? AND l.status IN ("active","defaulted")', [$lid]);
    if (!$loan) {
        flash('error', 'Loan not found or already closed.');
    } elseif (post('action') === 'remind') {
        $s = loan_snapshot($loan);
        $sent = message_user((int)$loan['user_id'], 'Payment reminder', 'Loan #' . $lid . ' is ' . $s['late_days'] . ' days late. Please pay ' . money($s['payoff'])
            . ' in the app or call ' . SUPPORT_PHONE . ' to agree a plan.', 'loan.php?id=' . $lid, 'collect_email', $lid . '-' . date('Y-m-d'));
        q('INSERT INTO collection_notes (loan_id, staff_id, outcome, note) VALUES (?, ?, "email", ?)', [$lid, $me['id'], $sent ? 'Reminder email sent' : 'Reminder email not sent (already sent today or email is off)']);
        flash($sent ? 'success' : 'info', $sent ? 'Reminder emailed to ' . $loan['full_name'] . '.' : 'No email sent: one was already sent today, or email is not set up.');
    } elseif (post('action') === 'collateral_retrieved') {
        if (!collateral_due($loan)) {
            flash('error', 'This loan\'s collateral is not due for retrieval: the term has not ended or the interest has been paid.');
        } else {
            $note = mb_substr(trim((string)post('note')), 0, 255);
            q('UPDATE loans SET collateral_status = "retrieved", collateral_retrieved_at = NOW(), collateral_retrieved_by = ?, collateral_note = ? WHERE id = ?', [$me['id'], $note ?: null, $lid]);
            q('INSERT INTO collection_notes (loan_id, staff_id, outcome, note) VALUES (?, ?, "visit", ?)', [$lid, $me['id'], 'Collateral retrieved' . ($note !== '' ? ': ' . $note : '')]);
            audit('collateral.retrieved', 'Loan #' . $lid, $note);
            notify((int)$loan['user_id'], 'Collateral retrieved', 'Our field officer has retrieved the collateral for loan #' . $lid . ' because the interest for the loan term was not paid. '
                . 'Pay ' . money(loan_snapshot($loan)['payoff']) . ' to settle the loan; otherwise the collateral will be sold to recover what you owe. Call ' . SUPPORT_PHONE . ' to discuss.', 'loan.php?id=' . $lid, true);
            flash('success', 'Collateral marked as retrieved for loan #' . $lid . '. ' . $loan['full_name'] . ' has been told.');
        }
    } elseif (post('action') === 'collateral_sold') {
        $amount = round((float)post('sale_amount'), 2);
        if ($loan['collateral_status'] !== 'retrieved') {
            flash('error', 'Mark the collateral as retrieved first.');
        } elseif ($amount <= 0) {
            flash('error', 'Enter the amount the collateral was sold for.');
        } else {
            $note = mb_substr(trim((string)post('note')), 0, 255);
            $payoff = loan_snapshot($loan)['payoff'];
            $applied = min($amount, $payoff);
            $surplus = round($amount - $applied, 2);
            $pdo = db();
            $pdo->beginTransaction();
            try {
                q('INSERT INTO transactions (user_id, type, amount, method, status, loan_id, note, processed_at, processed_by) VALUES (?, "loan_repayment", ?, "collateral_sale", "approved", ?, ?, NOW(), ?)',
                    [$loan['user_id'], $applied, $lid, 'Collateral sold for ' . money($amount) . ($note !== '' ? ' · ' . $note : ''), $me['id']]);
                $after = apply_loan_payment($lid, $applied);
                q('UPDATE loans SET collateral_status = "sold", collateral_sold_at = NOW(), collateral_sale_amount = ?, collateral_note = ? WHERE id = ?', [$amount, $note ?: $loan['collateral_note'], $lid]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $left = $after['status'] === 'repaid' ? 0 : loan_snapshot(q_one('SELECT * FROM loans WHERE id = ?', [$lid]))['payoff'];
            audit('collateral.sold', 'Loan #' . $lid, money($amount) . ' · applied ' . money($applied) . ($surplus > 0 ? ' · surplus ' . money($surplus) : '') . ($note !== '' ? ' · ' . $note : ''));
            notify((int)$loan['user_id'], 'Collateral sold', 'The collateral for loan #' . $lid . ' was sold for ' . money($amount) . '. ' . money($applied) . ' was used to pay your loan. '
                . ($left > 0.009 ? money($left) . ' is still owed.' : 'Your loan is now settled.') . ($surplus > 0 ? ' Our team will contact you about the ' . money($surplus) . ' left over.' : '') . ' Call ' . SUPPORT_PHONE . ' with any questions.', 'loan.php?id=' . $lid, true);
            flash('success', 'Sale recorded: ' . money($applied) . ' applied to loan #' . $lid . '.' . ($left > 0.009 ? ' ' . money($left) . ' is still owed.' : ' The loan is settled.') . ($surplus > 0 ? ' ' . money($surplus) . ' is left over and belongs to the customer.' : ''));
        }
    } elseif (post('action') === 'assign') {
        q('UPDATE loans SET collector_id = ? WHERE id = ?', [(int)post('collector_id') ?: null, $lid]);
        audit('collections.assign', 'Loan #' . $lid, (string)post('collector_id'));
        flash('success', 'Collector updated.');
    }
    redirect('admin/collections.php?' . http_build_query(['bucket' => $_GET['bucket'] ?? 'all', 'mine' => $_GET['mine'] ?? '']));
}

$collateralDue = array_values(array_filter(
    q('SELECT l.*, u.full_name, u.phone, k.address, k.city, k.collateral_type, k.collateral_description, k.collateral_value FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN kyc k ON k.user_id = l.user_id
       WHERE l.status IN ("active","defaulted") AND l.due_date < CURDATE() AND (l.collateral_status IS NULL OR l.collateral_status = "retrieved") ORDER BY l.due_date')->fetchAll(),
    fn($l) => $l['collateral_status'] === 'retrieved' || collateral_due($l)
));
const BUCKETS = ['all' => 'All overdue', '1-7' => '1–7 days', '8-30' => '8–30 days', '31-60' => '31–60 days', '61+' => '61+ days', 'promises' => 'Promises due', 'broken' => 'Broken promises'];
$bucket = array_key_exists($_GET['bucket'] ?? '', BUCKETS) ? $_GET['bucket'] : 'all';
$mine = !empty($_GET['mine']);

$rows = q('SELECT l.*, u.full_name, u.phone, c.full_name collector,
             (SELECT MAX(created_at) FROM collection_notes n WHERE n.loan_id = l.id) last_contact,
             (SELECT CONCAT(n.promise_date, "|", COALESCE(n.promise_amount, 0), "|", n.created_at) FROM collection_notes n WHERE n.loan_id = l.id AND n.promise_date IS NOT NULL ORDER BY n.id DESC LIMIT 1) promise
           FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN users c ON c.id = l.collector_id
           WHERE l.status IN ("active","defaulted") AND l.due_date < CURDATE()' . ($mine ? ' AND l.collector_id = ' . (int)$me['id'] : '') . ' ORDER BY l.due_date')->fetchAll();

$today = date('Y-m-d');
$counts = array_fill_keys(array_keys(BUCKETS), 0);
$totals = array_fill_keys(array_keys(BUCKETS), 0.0);
$list = [];
foreach ($rows as $r) {
    $r['snap'] = loan_snapshot($r);
    $d = $r['snap']['late_days'];
    $r['bucket'] = $d <= 7 ? '1-7' : ($d <= 30 ? '8-30' : ($d <= 60 ? '31-60' : '61+'));
    $r['promise_state'] = null;
    if ($r['promise']) {
        [$pDate, $pAmt, $pMade] = explode('|', $r['promise']);
        $paidSince = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE loan_id = ? AND type = "loan_repayment" AND status = "approved" AND created_at >= ?', [$r['id'], $pMade]);
        $r['promise_date'] = $pDate;
        $r['promise_amount'] = (float)$pAmt;
        if ($paidSince < max(1, (float)$pAmt) - 0.009) {
            $r['promise_state'] = $pDate === $today ? 'promises' : ($pDate < $today ? 'broken' : 'upcoming');
        } else {
            $r['promise_state'] = 'kept';
        }
    }
    foreach (['all', $r['bucket'], $r['promise_state']] as $k) {
        if ($k && isset($counts[$k])) {
            $counts[$k]++;
            $totals[$k] += $r['snap']['payoff'];
        }
    }
    if ($bucket === 'all' || $bucket === $r['bucket'] || $bucket === $r['promise_state']) {
        $list[] = $r;
    }
}
$collectors = array_filter(q('SELECT * FROM users WHERE role IN ("admin","super_admin") AND status = "active" ORDER BY full_name')->fetchAll(), fn($s) => can('collections', $s));

app_header('Collections', 'collections', 'admin');
?>
<h1 class="mobile-title">Collections</h1>
<div class="grid grid-4">
  <?php foreach (['all', '8-30', '61+', 'broken'] as $k): ?>
    <a class="card stat" href="?bucket=<?= $k ?>"><span class="k"><?= e(BUCKETS[$k]) ?></span><span class="v"><?= $counts[$k] ?></span><span class="s"><?= money0($totals[$k]) ?> owed</span></a>
  <?php endforeach; ?>
</div>
<div class="row">
  <?php foreach (BUCKETS as $k => $t): ?>
    <a class="btn btn-sm <?= $k === $bucket ? 'btn-primary' : 'btn-ghost' ?>" href="?bucket=<?= $k ?><?= $mine ? '&mine=1' : '' ?>"><?= e($t) ?> · <?= $counts[$k] ?></a>
  <?php endforeach; ?>
  <a class="btn btn-sm <?= $mine ? 'btn-primary' : 'btn-ghost' ?>" href="?bucket=<?= $bucket ?><?= $mine ? '' : '&mine=1' ?>">Only mine</a>
</div>
<div class="card" id="collateral">
  <div class="card-head"><h2><?= icon('shield', 18) ?> Collateral</h2><span class="badge badge-<?= $collateralDue ? 'warn' : 'ok' ?>"><?= count($collateralDue) ?></span></div>
  <p class="muted small" style="margin-top:0">Collateral stays with the customer. It is retrieved only when the loan term has ended and the interest for the whole term is still unpaid. If the loan is still not settled after that, the collateral is sold and the money is applied to the loan.</p>
  <?php if (!$collateralDue): ?><p class="muted small" style="margin:0">No collateral to retrieve or sell.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Collateral</th><th class="num">Interest unpaid</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($collateralDue as $c): $cs = loan_snapshot($c); ?>
        <tr>
          <td><a href="loans?id=<?= $c['id'] ?>"><strong><?= e($c['full_name']) ?></strong></a><div class="small"><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a> · loan #<?= $c['id'] ?> · ended <?= fdate($c['due_date']) ?></div><div class="small muted"><?= e(trim(($c['address'] ?? '') . ', ' . ($c['city'] ?? ''), ', ')) ?></div></td>
          <td class="small"><?= $c['collateral_type'] ? '<strong>' . e($c['collateral_type']) . '</strong><div>' . e((string)$c['collateral_description']) . '</div><div class="muted">est. ' . money0($c['collateral_value']) . '</div>' : '<span class="muted">Not recorded</span>' ?></td>
          <td class="num"><?= money(max(0, term_interest($c) - (float)$c['amount_paid'])) ?><div class="muted small">owed now <?= money($cs['payoff']) ?></div></td>
          <td><?= $c['collateral_status'] === 'retrieved' ? '<span class="badge badge-info">Retrieved</span><div class="small muted">' . fdate($c['collateral_retrieved_at']) . '</div>' : '<span class="badge badge-warn">To retrieve</span>' ?></td>
          <td class="right">
            <form method="post" class="row" style="gap:.3rem;justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $c['id'] ?>">
              <?php if ($c['collateral_status'] === 'retrieved'): ?>
                <input type="number" name="sale_amount" min="1" step="100" placeholder="Sold for ₦" required style="width:120px">
                <input name="note" placeholder="Buyer / note" style="width:130px">
                <button class="btn btn-primary btn-sm" name="action" value="collateral_sold" data-confirm="Record this sale? The money will be applied to the loan.">Record sale</button>
              <?php else: ?>
                <input name="note" placeholder="Officer note" style="width:150px">
                <button class="btn btn-primary btn-sm" name="action" value="collateral_retrieved" data-confirm="Mark the collateral as retrieved? The customer will be emailed.">Mark retrieved</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$list): ?><div class="empty"><?= icon('check', 32) ?><p>Nothing here. Every loan in this group is on track.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th class="num">Owed now</th><th>Late</th><th>Promise</th><th>Last contact</th><th>Collector</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): $s = $r['snap']; ?>
        <tr>
          <td><a href="loans?id=<?= $r['id'] ?>#collect"><strong><?= e($r['full_name']) ?></strong></a><div class="small"><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a> · loan #<?= $r['id'] ?></div></td>
          <td class="num"><strong><?= money($s['payoff']) ?></strong><div class="muted small">+<?= money($s['penalty']) ?> penalty</div></td>
          <td><span class="badge badge-<?= $s['late_days'] > 30 ? 'bad' : 'warn' ?>"><?= $s['late_days'] ?> days</span><?= $r['status'] === 'defaulted' ? ' ' . badge('defaulted') : '' ?></td>
          <td class="small"><?php if ($r['promise_state']): ?><?= money0($r['promise_amount']) ?> by <?= fdate($r['promise_date']) ?><div><?= badge(['promises' => 'pending', 'broken' => 'overdue', 'upcoming' => 'upcoming', 'kept' => 'paid'][$r['promise_state']]) ?></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="small muted"><?= $r['last_contact'] ? fdatetime($r['last_contact']) : 'Never' ?></td>
          <td>
            <form method="post" class="row" style="gap:.3rem;flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="assign">
              <select name="collector_id" onchange="this.form.submit()" style="min-width:120px;min-height:34px;padding:.2rem .4rem"><option value="0">Unassigned</option><?php foreach ($collectors as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$r['collector_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select>
            </form>
          </td>
          <td class="right">
            <form method="post" class="row" style="gap:.3rem;flex-wrap:nowrap;justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="loan_id" value="<?= $r['id'] ?>">
              <button class="btn btn-ghost btn-sm" name="action" value="remind" title="Send a polite reminder email">Email</button>
              <a class="btn btn-primary btn-sm" href="loans?id=<?= $r['id'] ?>#collect">Log call</a>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<p class="muted small"><strong>Fair collection rules:</strong> contact only the customer and their named guarantor, between 8am and 6pm, without threats, insults or messages to their other contacts. Every contact is recorded here.</p>
<?php app_footer();
