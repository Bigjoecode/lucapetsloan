<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    verify_csrf();
    try {
        flash('success', process_transaction((int)post('tx_id'), post('decision') === 'approve', (int)$admin['id'], mb_substr(post('note'), 0, 255)));
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/payments.php?' . http_build_query(['type' => $_GET['type'] ?? '', 'status' => $_GET['status'] ?? 'pending']));
}

$types = ['' => 'All types', 'deposit' => 'Deposits', 'withdrawal' => 'Withdrawals', 'loan_repayment' => 'Repayments', 'loan_disbursement' => 'Pay-outs'];
$type = array_key_exists($_GET['type'] ?? '', $types) ? ($_GET['type'] ?? '') : '';
$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected', 'all'], true) ? $_GET['status'] : 'pending';

$where = ['1=1'];
$params = [];
if ($type !== '') { $where[] = 't.type = ?'; $params[] = $type; }
if ($status !== 'all') { $where[] = 't.status = ?'; $params[] = $status; }
$rows = q('SELECT t.*, u.full_name, u.phone, sp.name plan_name FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN savings_plans sp ON sp.id = t.savings_plan_id
           WHERE ' . implode(' AND ', $where) . ' ORDER BY t.status = "pending" DESC, t.id ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 300', $params)->fetchAll();
$pendingTotal = q('SELECT type, COUNT(*) c, SUM(amount) s FROM transactions WHERE status = "pending" GROUP BY type')->fetchAll(PDO::FETCH_UNIQUE);

app_header('Payments', 'payments', 'admin');
?>
<h1 class="mobile-title">Payments</h1>
<div class="grid grid-3">
  <?php foreach (['deposit' => 'Deposits to confirm', 'withdrawal' => 'Withdrawals to pay', 'loan_repayment' => 'Repayments to confirm'] as $k => $t): ?>
    <a class="card stat" href="?type=<?= $k ?>&status=pending"><span class="k"><?= e($t) ?></span><span class="v"><?= (int)($pendingTotal[$k]['c'] ?? 0) ?></span><span class="s"><?= money0($pendingTotal[$k]['s'] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>
<form class="row" method="get">
  <select name="type" style="width:auto"><?php foreach ($types as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === $type ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <select name="status" style="width:auto"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All statuses'] as $k => $t): ?><option value="<?= $k ?>" <?= $k === $status ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost btn-sm">Filter</button>
</form>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('cash', 32) ?><p>Nothing to show.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Customer</th><th>Type</th><th class="num">Amount</th><th>Method / reference</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $t): ?>
        <tr>
          <td><?= $t['id'] ?></td>
          <td><strong><?= e($t['full_name']) ?></strong><div class="muted small"><?= e($t['phone']) ?></div></td>
          <td><?= e(label($t['type'])) ?><div class="muted small"><?= $t['plan_name'] ? e($t['plan_name']) : ($t['loan_id'] ? '<a href="loans.php?id=' . $t['loan_id'] . '">Loan #' . $t['loan_id'] . '</a>' : '') ?></div></td>
          <td class="num"><strong><?= money($t['amount']) ?></strong></td>
          <td><?= e(payment_methods()[$t['method']] ?? label($t['method'])) ?><div class="muted small"><?= e($t['reference'] ?: '') ?><?= $t['note'] ? ' ' . e($t['note']) : '' ?></div></td>
          <td class="muted small"><?= fdatetime($t['created_at']) ?></td>
          <td><?= badge($t['status']) ?><?= $t['admin_note'] ? '<div class="muted small">' . e($t['admin_note']) . '</div>' : '' ?></td>
          <td class="right">
            <?php if ($t['status'] === 'pending'): ?>
              <form method="post" class="row" style="gap:.3rem;flex-wrap:nowrap;justify-content:flex-end">
                <?= csrf_field() ?><input type="hidden" name="tx_id" value="<?= $t['id'] ?>">
                <input name="note" placeholder="Note" style="width:110px;min-height:34px;padding:.3rem .5rem">
                <button class="btn btn-primary btn-sm" name="decision" value="approve" title="<?= $t['type'] === 'withdrawal' ? 'Confirm you have sent the money' : 'Confirm the money was received' ?>"><?= icon('check', 14) ?></button>
                <button class="btn btn-danger btn-sm" name="decision" value="reject" data-confirm="Reject this transaction?"><?= icon('x', 14) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<p class="muted small">Approving a <strong>deposit</strong> adds it to the customer's savings. Approving a <strong>withdrawal</strong> means you've sent the money (it was already taken from their savings when they asked for it; rejecting puts it back). Approving a <strong>repayment</strong> takes it off their loan balance.</p>
<?php app_footer();
