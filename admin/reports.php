<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff('reports');

const PERIODS = ['month' => 'This month', 'last_month' => 'Last month', '90d' => 'Last 90 days', 'year' => 'This year', 'all' => 'All time', 'custom' => 'Custom'];
$period = array_key_exists($_GET['period'] ?? '', PERIODS) ? $_GET['period'] : 'month';
[$from, $to] = match ($period) {
    'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90d'        => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'year'       => [date('Y-01-01'), date('Y-m-d')],
    'all'        => ['2000-01-01', date('Y-m-d')],
    'custom'     => [preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01'), preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d')],
    default      => [date('Y-m-01'), date('Y-m-d')],
};
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];
$sumTx = fn(string $type) => (float)q_val('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = ? AND status = "approved" AND created_at BETWEEN ? AND ?', [$type, ...$range]);

/* ---------- CSV exports ---------- */
if (isset($_GET['export'])) {
    $what = $_GET['export'];
    audit('reports.export', $what, $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z_]/', '', $what) . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    if ($what === 'loans') {
        fputcsv($out, ['Loan', 'Customer', 'Phone', 'Amount', 'Rate %/30d', 'Days', 'Status', 'Applied', 'Paid out', 'Due', 'Paid', 'Interest so far', 'Penalty', 'Waived', 'Owed now', 'Days late']);
        foreach (q('SELECT l.*, u.full_name, u.phone FROM loans l JOIN users u ON u.id = l.user_id WHERE l.applied_at BETWEEN ? AND ? ORDER BY l.id', $range)->fetchAll() as $l) {
            $s = loan_snapshot($l);
            fputcsv($out, [$l['id'], $l['full_name'], $l['phone'], $l['amount'], $l['interest_rate'], $s['term_days'], $s['display_status'], $l['applied_at'], $l['disbursed_at'], $l['due_date'], $l['amount_paid'], $s['accrued_interest'], $s['penalty'], $s['penalty_waived'], $s['payoff'], $s['late_days']]);
        }
    } elseif ($what === 'transactions') {
        fputcsv($out, ['ID', 'Date', 'Customer', 'Phone', 'Type', 'Amount', 'Method', 'Reference', 'Status', 'Plan', 'Loan', 'Note']);
        foreach (q('SELECT t.*, u.full_name, u.phone, p.name plan FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN savings_plans p ON p.id = t.savings_plan_id WHERE t.created_at BETWEEN ? AND ? ORDER BY t.id', $range)->fetchAll() as $t) {
            fputcsv($out, [$t['id'], $t['created_at'], $t['full_name'], $t['phone'], $t['type'], $t['amount'], $t['method'], $t['reference'], $t['status'], $t['plan'], $t['loan_id'], $t['note']]);
        }
    } elseif ($what === 'customers') {
        fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Verification', 'Status', 'Joined', 'Savings', 'Loans', 'Last login']);
        foreach (q('SELECT u.*, (SELECT COALESCE(SUM(balance),0) FROM savings_plans p WHERE p.user_id = u.id AND p.status <> "closed") savings, (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id) loans FROM users u WHERE u.role = "customer" AND u.created_at BETWEEN ? AND ? ORDER BY u.id', $range)->fetchAll() as $c) {
            fputcsv($out, [$c['id'], $c['full_name'], $c['email'], $c['phone'], $c['kyc_status'], $c['status'], $c['created_at'], $c['savings'], $c['loans'], $c['last_login_at']]);
        }
    }
    exit;
}

/* ---------- Period figures ---------- */
$disbursed = $sumTx('loan_disbursement');
$disbursedCount = (int)q_val('SELECT COUNT(*) FROM transactions WHERE type = "loan_disbursement" AND status = "approved" AND created_at BETWEEN ? AND ?', $range);
$collected = $sumTx('loan_repayment');
$closedInPeriod = q('SELECT * FROM loans WHERE status = "repaid" AND closed_at BETWEEN ? AND ?', $range)->fetchAll();
$income = array_sum(array_map(fn($l) => max(0, (float)$l['amount_paid'] - (float)$l['amount']), $closedInPeriod));
$onTime = count(array_filter($closedInPeriod, fn($l) => $l['due_date'] && substr($l['closed_at'], 0, 10) <= $l['due_date']));
$waived = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM loan_waivers WHERE created_at BETWEEN ? AND ?', $range);
$deposits = $sumTx('deposit');
$withdrawals = $sumTx('withdrawal');
$interestPaid = $sumTx('interest');
$bonuses = $sumTx('bonus');
$newCustomers = (int)q_val('SELECT COUNT(*) FROM users WHERE role = "customer" AND created_at BETWEEN ? AND ?', $range);
$newVerified = (int)q_val('SELECT COUNT(*) FROM kyc k JOIN users u ON u.id = k.user_id WHERE u.kyc_status = "verified" AND k.reviewed_at BETWEEN ? AND ?', $range);
$applications = (int)q_val('SELECT COUNT(*) FROM loans WHERE applied_at BETWEEN ? AND ?', $range);
$approvedApps = (int)q_val('SELECT COUNT(*) FROM loans WHERE applied_at BETWEEN ? AND ? AND status NOT IN ("pending","rejected")', $range);

/* ---------- Portfolio right now ---------- */
$outstanding = $par1 = $par30 = 0.0;
$late = 0;
foreach (q('SELECT * FROM loans WHERE status = "active"')->fetchAll() as $l) {
    $s = loan_snapshot($l);
    $outstanding += $s['payoff'];
    if ($s['late_days'] > 0) { $par1 += $s['payoff']; $late++; }
    if ($s['late_days'] > 30) $par30 += $s['payoff'];
}
$defaultedOwed = array_sum(array_map(fn($l) => loan_snapshot($l)['payoff'], q('SELECT * FROM loans WHERE status = "defaulted"')->fetchAll()));
$closedAll = q('SELECT status, COUNT(*) c, SUM(amount) amt FROM loans WHERE status IN ("repaid","defaulted") GROUP BY status')->fetchAll(PDO::FETCH_UNIQUE);
$closedAmt = (float)($closedAll['repaid']['amt'] ?? 0) + (float)($closedAll['defaulted']['amt'] ?? 0);
$defaultRate = $closedAmt > 0 ? (float)($closedAll['defaulted']['amt'] ?? 0) / $closedAmt * 100 : 0;
$savingsNow = (float)q_val('SELECT COALESCE(SUM(balance),0) FROM savings_plans WHERE status <> "closed"');
$pct = fn($a, $b) => $b > 0 ? number_format($a / $b * 100, 1) . '%' : '—';

/* ---------- 12-month trend ---------- */
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("first day of -$i month"))] = ['loan_disbursement' => 0, 'loan_repayment' => 0, 'deposit' => 0, 'withdrawal' => 0];
}
foreach (q('SELECT DATE_FORMAT(created_at, "%Y-%m") m, type, SUM(amount) amt FROM transactions WHERE status = "approved" AND type IN ("loan_disbursement","loan_repayment","deposit","withdrawal") AND created_at >= ? GROUP BY m, type', [array_key_first($months) . '-01'])->fetchAll() as $r) {
    if (isset($months[$r['m']])) $months[$r['m']][$r['type']] = (float)$r['amt'];
}
$qs = fn(array $extra) => '?' . http_build_query(array_merge(['period' => $period, 'from' => $from, 'to' => $to], $extra));

app_header('Reports', 'reports', 'admin');
?>
<h1 class="mobile-title">Reports</h1>
<form class="row" method="get">
  <select name="period" style="width:auto" onchange="this.form.querySelectorAll('[data-custom]').forEach(x => x.hidden = this.value !== 'custom')"><?php foreach (PERIODS as $k => $t): ?><option value="<?= $k ?>" <?= $k === $period ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($from) ?>" style="width:auto" data-custom <?= $period === 'custom' ? '' : 'hidden' ?>>
  <input type="date" name="to" value="<?= e($to) ?>" style="width:auto" data-custom <?= $period === 'custom' ? '' : 'hidden' ?>>
  <button class="btn btn-ghost btn-sm">Show</button>
  <span class="muted small"><?= fdate($from) ?> – <?= fdate($to) ?></span>
</form>

<h2 class="section-title">Lending · <?= e(PERIODS[$period]) ?></h2>
<div class="grid grid-4">
  <div class="card stat"><span class="k">Paid out</span><span class="v"><?= money0($disbursed) ?></span><span class="s"><?= $disbursedCount ?> loans</span></div>
  <div class="card stat"><span class="k">Collected</span><span class="v"><?= money0($collected) ?></span><span class="s">repayments received</span></div>
  <div class="card stat"><span class="k">Interest &amp; fees earned</span><span class="v"><?= money0($income) ?></span><span class="s">on <?= count($closedInPeriod) ?> loans closed · <?= money0($waived) ?> waived</span></div>
  <div class="card stat"><span class="k">Repaid on time</span><span class="v"><?= $pct($onTime, count($closedInPeriod)) ?></span><span class="s"><?= $onTime ?> of <?= count($closedInPeriod) ?> loans closed</span></div>
  <div class="card stat"><span class="k">Applications</span><span class="v"><?= $applications ?></span><span class="s"><?= $pct($approvedApps, $applications) ?> approved</span></div>
  <div class="card stat"><span class="k">New customers</span><span class="v"><?= $newCustomers ?></span><span class="s"><?= $newVerified ?> verified after a visit</span></div>
</div>

<h2 class="section-title">Loan book · right now</h2>
<div class="grid grid-4">
  <div class="card hero-card stat"><span class="k">Outstanding</span><span class="v"><?= money0($outstanding) ?></span><span class="s">owed on active loans today</span></div>
  <div class="card stat"><span class="k">Late (PAR 1)</span><span class="v" style="<?= $par1 ? 'color:var(--bad)' : '' ?>"><?= $pct($par1, $outstanding) ?></span><span class="s"><?= $late ?> loans · <?= money0($par1) ?></span></div>
  <div class="card stat"><span class="k">Over 30 days late (PAR 30)</span><span class="v" style="<?= $par30 ? 'color:var(--bad)' : '' ?>"><?= $pct($par30, $outstanding) ?></span><span class="s"><?= money0($par30) ?></span></div>
  <div class="card stat"><span class="k">Default rate</span><span class="v"><?= number_format($defaultRate, 1) ?>%</span><span class="s">of closed loans by amount · <?= money0($defaultedOwed) ?> unrecovered</span></div>
</div>

<h2 class="section-title">Savings · <?= e(PERIODS[$period]) ?></h2>
<div class="grid grid-4">
  <div class="card stat"><span class="k">Saved in total (now)</span><span class="v"><?= money0($savingsNow) ?></span></div>
  <div class="card stat"><span class="k">Deposits</span><span class="v"><?= money0($deposits) ?></span></div>
  <div class="card stat"><span class="k">Withdrawals</span><span class="v"><?= money0($withdrawals) ?></span><span class="s">net <?= money0($deposits - $withdrawals) ?></span></div>
  <div class="card stat"><span class="k">Interest &amp; bonuses paid</span><span class="v"><?= money0($interestPaid + $bonuses) ?></span><span class="s"><?= money0($interestPaid) ?> interest · <?= money0($bonuses) ?> referral</span></div>
</div>

<div class="grid grid-2">
  <div class="card"><h2>Lending, last 12 months</h2><div style="position:relative;height:260px"><canvas id="chartLoans"></canvas></div></div>
  <div class="card"><h2>Savings, last 12 months</h2><div style="position:relative;height:260px"><canvas id="chartSavings"></canvas></div></div>
</div>

<div class="card row between">
  <div><h2 style="margin:0">Export to Excel (CSV)</h2><span class="muted small">For <?= fdate($from) ?> – <?= fdate($to) ?>. Exports are recorded in the audit log.</span></div>
  <div class="row">
    <a class="btn btn-ghost btn-sm" href="<?= e($qs(['export' => 'loans'])) ?>">Loans</a>
    <a class="btn btn-ghost btn-sm" href="<?= e($qs(['export' => 'transactions'])) ?>">Transactions</a>
    <a class="btn btn-ghost btn-sm" href="<?= e($qs(['export' => 'customers'])) ?>">Customers</a>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(() => {
  const months = <?= json_encode(array_map(fn($m) => date('M y', strtotime($m . '-01')), array_keys($months))) ?>;
  const data = <?= json_encode(array_values($months)) ?>;
  const css = getComputedStyle(document.documentElement);
  const brand = css.getPropertyValue('--brand').trim() || '#0b4f3c', accent = css.getPropertyValue('--accent').trim() || '#f2b33d';
  const naira = (v) => '₦' + Number(v).toLocaleString();
  const opts = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + naira(c.raw) } } }, scales: { y: { ticks: { callback: naira } } } };
  const bar = (id, a, b, la, lb) => new Chart(document.getElementById(id), { type: 'bar', data: { labels: months, datasets: [
    { label: la, data: data.map((d) => d[a]), backgroundColor: brand, borderRadius: 4 },
    { label: lb, data: data.map((d) => d[b]), backgroundColor: accent, borderRadius: 4 } ] }, options: opts });
  bar('chartLoans', 'loan_disbursement', 'loan_repayment', 'Paid out', 'Collected');
  bar('chartSavings', 'deposit', 'withdrawal', 'Deposits', 'Withdrawals');
})();
</script>
<?php app_footer();
