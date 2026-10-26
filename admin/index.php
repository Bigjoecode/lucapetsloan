<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$stats = [
    'customers'   => (int)q_val('SELECT COUNT(*) FROM users WHERE role = "customer"'),
    'kyc_new'     => (int)q_val('SELECT COUNT(*) FROM users WHERE kyc_status = "submitted"'),
    'visits'      => (int)q_val('SELECT COUNT(*) FROM users WHERE kyc_status = "visit_scheduled"'),
    'loans_new'   => (int)q_val('SELECT COUNT(*) FROM loans WHERE status = "pending"'),
    'to_disburse' => (int)q_val('SELECT COUNT(*) FROM loans WHERE status = "approved"'),
    'payments'    => (int)q_val('SELECT COUNT(*) FROM transactions WHERE status = "pending"'),
    'savings'     => (float)q_val('SELECT COALESCE(SUM(balance),0) FROM savings_plans WHERE status <> "closed"'),
    'saved_today' => (float)q_val('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type="deposit" AND status="approved" AND DATE(created_at)=CURDATE()'),
];

$active = q('SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status = "active"')->fetchAll();
$book = ['principal' => 0, 'payoff' => 0, 'interest' => 0, 'overdue' => 0, 'overdue_amt' => 0];
$overdueList = [];
foreach ($active as $l) {
    $s = loan_snapshot($l);
    $book['principal'] += $s['principal'];
    $book['payoff'] += $s['payoff'];
    $book['interest'] += $s['accrued_interest'];
    if ($s['is_overdue']) {
        $book['overdue']++;
        $book['overdue_amt'] += $s['payoff'];
        $overdueList[] = $l + ['snap' => $s];
    }
}
usort($overdueList, fn($a, $b) => $b['snap']['late_days'] <=> $a['snap']['late_days']);

$upcomingVisits = q('SELECT k.*, u.full_name, u.phone FROM kyc k JOIN users u ON u.id = k.user_id WHERE u.kyc_status = "visit_scheduled" ORDER BY k.visit_date LIMIT 8')->fetchAll();
$recentTx = q('SELECT t.*, u.full_name FROM transactions t JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 8')->fetchAll();

app_header('Overview', 'dashboard', 'admin');
?>
<h1 class="mobile-title">Overview</h1>
<div class="grid grid-4">
  <a class="card stat" href="kyc.php?status=submitted"><span class="k"><?= icon('shield', 16) ?> Verifications to review</span><span class="v"><?= $stats['kyc_new'] ?></span><span class="s"><?= $stats['visits'] ?> home visits scheduled</span></a>
  <a class="card stat" href="loans.php?status=pending"><span class="k"><?= icon('loan', 16) ?> Loan applications</span><span class="v"><?= $stats['loans_new'] ?></span><span class="s"><?= $stats['to_disburse'] ?> approved, waiting to be paid out</span></a>
  <a class="card stat" href="payments.php"><span class="k"><?= icon('cash', 16) ?> Payments to confirm</span><span class="v"><?= $stats['payments'] ?></span><span class="s">Deposits, withdrawals &amp; repayments</span></a>
  <a class="card stat" href="users.php"><span class="k"><?= icon('users', 16) ?> Customers</span><span class="v"><?= number_format($stats['customers']) ?></span><span class="s">&nbsp;</span></a>
</div>

<div class="grid grid-4">
  <div class="card hero-card stat"><span class="k">Loans outstanding (to clear today)</span><span class="v"><?= money0($book['payoff']) ?></span><span class="s"><?= count($active) ?> active · <?= money0($book['principal']) ?> lent out</span></div>
  <div class="card stat"><span class="k">Interest earned so far</span><span class="v"><?= money0($book['interest']) ?></span><span class="s">On active loans</span></div>
  <div class="card stat"><span class="k">Overdue</span><span class="v" style="color:var(--bad)"><?= money0($book['overdue_amt']) ?></span><span class="s"><?= $book['overdue'] ?> loan<?= $book['overdue'] === 1 ? '' : 's' ?> past due date</span></div>
  <div class="card stat"><span class="k">Customer savings held</span><span class="v"><?= money0($stats['savings']) ?></span><span class="s"><?= money0($stats['saved_today']) ?> saved today</span></div>
</div>

<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>Upcoming home visits</h2><a class="small" href="kyc.php?status=visit_scheduled">All</a></div>
    <?php if (!$upcomingVisits): ?><div class="empty"><?= icon('map', 32) ?><p>No visits scheduled.</p></div>
    <?php else: ?><ul class="list">
      <?php foreach ($upcomingVisits as $k): ?>
        <li><span class="tx-ic tx-loan"><?= icon('map', 18) ?></span>
          <div class="grow"><div class="title"><?= e($k['full_name']) ?></div><div class="sub"><?= fdate($k['visit_date'], 'D, M j') ?> · <?= e($k['visit_time']) ?> · <?= e($k['city']) ?> · Officer <?= e($k['officer_name']) ?></div></div>
          <a class="btn btn-ghost btn-sm" href="kyc.php?id=<?= $k['user_id'] ?>">Open</a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Overdue loans</h2><a class="small" href="loans.php?status=active">All active</a></div>
    <?php if (!$overdueList): ?><div class="empty"><?= icon('check', 32) ?><p>No overdue loans.</p></div>
    <?php else: ?><ul class="list">
      <?php foreach (array_slice($overdueList, 0, 8) as $l): ?>
        <li><span class="tx-ic tx-out"><?= icon('clock', 18) ?></span>
          <div class="grow"><div class="title"><?= e($l['full_name']) ?></div><div class="sub"><?= $l['snap']['late_days'] ?> days late · owes <?= money($l['snap']['payoff']) ?></div></div>
          <a class="btn btn-ghost btn-sm" href="loans.php?id=<?= $l['id'] ?>">Open</a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Latest transactions</h2><a class="small" href="payments.php?status=all">All</a></div>
  <?php if (!$recentTx): ?><div class="empty"><p>No transactions yet.</p></div>
  <?php else: ?><ul class="list"><?php foreach ($recentTx as $t) echo tx_row($t, true); ?></ul><?php endif; ?>
</div>
<?php app_footer();
