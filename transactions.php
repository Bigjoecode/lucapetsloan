<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
accrue_user_interest((int)$u['id']);

$types = ['' => 'All', 'deposit' => 'Deposits', 'withdrawal' => 'Withdrawals', 'loan_disbursement' => 'Loans paid out', 'loan_repayment' => 'Repayments', 'interest' => 'Interest', 'bonus' => 'Bonuses', 'ajo_contribution' => 'Group savings'];
$type = array_key_exists($_GET['type'] ?? '', $types) ? ($_GET['type'] ?? '') : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;

$where = 'user_id = ?';
$params = [$u['id']];
if ($type !== '') {
    $where .= ' AND type = ?';
    $params[] = $type;
}
$total = (int)q_val("SELECT COUNT(*) FROM transactions WHERE $where", $params);
$rows = q("SELECT * FROM transactions WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();

$sum = q('SELECT
    COALESCE(SUM(CASE WHEN type="deposit" AND status="approved" THEN amount END),0) dep,
    COALESCE(SUM(CASE WHEN type="withdrawal" AND status="approved" THEN amount END),0) wd,
    COALESCE(SUM(CASE WHEN type="loan_repayment" AND status="approved" THEN amount END),0) rep,
    COALESCE(SUM(CASE WHEN type="interest" THEN amount END),0) intr
  FROM transactions WHERE user_id = ?', [$u['id']])->fetch();

app_header('Activity', 'transactions');
?>
<h1 class="mobile-title">Activity</h1>
<form class="card row between" method="get" action="<?= url('statement.php') ?>" target="_blank">
  <div><h2 style="margin:0">Account statement</h2><span class="muted small">Download a PDF of your savings and loans for any dates.</span></div>
  <div class="row">
    <input type="date" name="from" value="<?= date('Y-m-d', strtotime('-29 days')) ?>" style="width:auto" required>
    <input type="date" name="to" value="<?= date('Y-m-d') ?>" style="width:auto" required>
    <button class="btn btn-primary btn-sm"><?= icon('doc', 16) ?> Get statement</button>
  </div>
</form>
<div class="grid grid-4">
  <div class="card stat"><span class="k">Total deposited</span><span class="v"><?= money($sum['dep']) ?></span></div>
  <div class="card stat"><span class="k">Total withdrawn</span><span class="v"><?= money($sum['wd']) ?></span></div>
  <div class="card stat"><span class="k">Loan repayments</span><span class="v"><?= money($sum['rep']) ?></span></div>
  <div class="card stat"><span class="k">Savings interest earned</span><span class="v"><?= money($sum['intr']) ?></span></div>
</div>
<div class="card">
  <div class="row" style="margin-bottom:.6rem">
    <?php foreach ($types as $k => $t): ?>
      <a class="btn btn-sm <?= $k === $type ? 'btn-primary' : 'btn-ghost' ?>" href="?type=<?= e($k) ?>"><?= e($t) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('list', 32) ?><p>Nothing here yet.</p></div>
  <?php else: ?>
    <ul class="list"><?php foreach ($rows as $t) echo tx_row($t); ?></ul>
    <?php if ($total > $per): ?>
      <div class="row between" style="margin-top:1rem">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="?type=<?= e($type) ?>&page=<?= $page - 1 ?>">← Newer</a><?php else: ?><span></span><?php endif; ?>
        <span class="muted small">Page <?= $page ?> of <?= (int)ceil($total / $per) ?></span>
        <?php if ($page * $per < $total): ?><a class="btn btn-ghost btn-sm" href="?type=<?= e($type) ?>&page=<?= $page + 1 ?>">Older →</a><?php else: ?><span></span><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php app_footer();
