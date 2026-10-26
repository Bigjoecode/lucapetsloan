<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
$id = (int)($_GET['id'] ?? $_POST['loan_id'] ?? 0);
$loan = q_one('SELECT l.*, p.name product FROM loans l JOIN loan_products p ON p.id = l.product_id WHERE l.id = ? AND l.user_id = ?', [$id, $uid]);
if (!$loan) {
    flash('error', 'Loan not found.');
    redirect('loans.php');
}

if (is_post()) {
    verify_csrf();
    $amount = round((float)post('amount'), 2);
    try {
        if ($loan['status'] !== 'active') throw new UserError('This loan is not open for repayment.');
        if ($amount <= 0) throw new UserError('Enter an amount.');
        $snap = loan_snapshot($loan);
        $amount = min($amount, $snap['payoff']);

        if (post('source') === 'savings') {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $plan = q_one('SELECT * FROM savings_plans WHERE id = ? AND user_id = ? AND status <> "closed" FOR UPDATE', [(int)post('plan_id'), $uid]);
                if (!$plan) throw new UserError('Choose a savings plan.');
                if ($amount > (float)$plan['balance']) throw new UserError('That plan only has ' . money($plan['balance']) . '.');
                q('UPDATE savings_plans SET balance = balance - ? WHERE id = ?', [$amount, $plan['id']]);
                q('INSERT INTO transactions (user_id, type, amount, method, status, savings_plan_id, loan_id, note, processed_at) VALUES (?, "loan_repayment", ?, "savings", "approved", ?, ?, ?, NOW())',
                    [$uid, $amount, $plan['id'], $loan['id'], 'Paid from ' . $plan['name']]);
                $after = apply_loan_payment((int)$loan['id'], $amount);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash('success', money($amount) . ' paid from your savings.' . ($after['status'] === 'repaid' ? ' Your loan is fully repaid 🎉' : ''));
        } else {
            $method = post('method');
            if (!isset(payment_methods()[$method])) throw new UserError('Choose a payment method.');
            if (post('reference') === '') throw new UserError('Enter your payment reference.');
            q('INSERT INTO transactions (user_id, type, amount, method, reference, loan_id) VALUES (?, "loan_repayment", ?, ?, ?, ?)',
                [$uid, $amount, $method, mb_substr(post('reference'), 0, 80), $loan['id']]);
            flash('success', 'Repayment of ' . money($amount) . ' submitted. It will be applied to your loan once we confirm it.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('loan.php?id=' . $loan['id']);
}

$s = loan_snapshot($loan);
$schedule = loan_schedule($loan);
$series = loan_series($loan);
$txs = q('SELECT * FROM transactions WHERE loan_id = ? ORDER BY id DESC', [$loan['id']])->fetchAll();
$plans = q('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" AND balance > 0', [$uid])->fetchAll();
$hasPendingRepay = (bool)array_filter($txs, fn($t) => $t['status'] === 'pending' && $t['type'] === 'loan_repayment');
$nextDue = null;
foreach ($schedule as $row) {
    if ($row['state'] !== 'paid') { $nextDue = $row; break; }
}

app_header('Loan #' . $loan['id'], 'loans');
?>
<div class="row between">
  <div><a class="small" href="<?= url('loans.php') ?>">← All loans</a><h2 style="margin:.2rem 0 0"><?= e($loan['product']) ?> · <?= money0($loan['amount']) ?></h2></div>
  <?= badge($s['display_status']) ?>
</div>

<?php if ($loan['status'] === 'pending'): ?>
  <div class="alert alert-info">Your application is under review. We'll let you know as soon as there's a decision.</div>
<?php elseif ($loan['status'] === 'approved'): ?>
  <div class="alert alert-success">Approved! The money will be paid into your bank account shortly. Interest only starts from the day you receive it.</div>
<?php elseif ($loan['status'] === 'rejected'): ?>
  <div class="alert alert-error">This application was declined<?= $loan['admin_note'] ? ': ' . e($loan['admin_note']) : '.' ?></div>
<?php elseif ($s['is_overdue']): ?>
  <div class="alert alert-error"><strong>Overdue by <?= $s['late_days'] ?> day<?= $s['late_days'] === 1 ? '' : 's' ?>.</strong> A late fee of <?= (float)$loan['late_fee_rate'] ?>% per day is being added to the unpaid balance. Please repay as soon as you can.</div>
<?php elseif ($loan['status'] === 'repaid'): ?>
  <div class="alert alert-success">Fully repaid on <?= fdate($loan['closed_at']) ?>. Well done!</div>
<?php endif; ?>

<?php if (in_array($loan['status'], ['active', 'repaid', 'defaulted'], true)): ?>
<div class="grid grid-4">
  <div class="card hero-card stat"><span class="k">Amount to clear today</span><span class="v"><?= money($s['payoff']) ?></span><span class="s">Principal + interest so far + late fees − paid</span></div>
  <div class="card stat"><span class="k"><?= icon('chart', 16) ?> Interest so far</span><span class="v"><?= money($s['accrued_interest']) ?></span><span class="s">+<?= money($s['daily_interest']) ?> every day, up to <?= money($s['total_interest']) ?></span></div>
  <div class="card stat"><span class="k"><?= icon('check', 16) ?> Paid so far</span><span class="v"><?= money($s['paid']) ?></span><span class="s"><?= money($s['balance_at_maturity']) ?> left to pay by the due date</span></div>
  <div class="card stat"><span class="k"><?= icon('calendar', 16) ?> Next payment</span><span class="v"><?= $nextDue ? money($nextDue['amount'] - $nextDue['covered']) : '—' ?></span><span class="s"><?= $nextDue ? 'Due ' . fdate($nextDue['due']) : 'Nothing due' ?></span></div>
</div>

<div class="card stack">
  <div class="row between"><h2 style="margin:0">Loan timeline</h2><span class="muted small">Day <?= min($s['days_elapsed'], $s['term_days']) ?> of <?= $s['term_days'] ?><?= $s['late_days'] ? ' · ' . $s['late_days'] . ' days late' : '' ?></span></div>
  <div class="progress <?= $s['is_overdue'] ? 'bad' : '' ?>"><span style="width:<?= $s['progress'] ?>%"></span></div>
  <div class="row between small muted"><span>Paid out <?= fdate($loan['disbursed_at']) ?></span><span>Due <?= fdate($loan['due_date']) ?></span></div>
  <div style="position:relative;height:260px"><canvas id="interestChart" aria-label="Loan balance and interest over time"></canvas></div>
  <p class="muted small" style="margin:0">The <strong>solid line</strong> is what you owed each day after payments. The <strong>dotted line</strong> is the full amount if you pay nothing until the due date. The <strong>gold area</strong> is interest building up.</p>
</div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h2>Loan details</h2>
    <dl class="kv">
      <dt>Amount borrowed</dt><dd><?= money($loan['amount']) ?></dd>
      <dt>Interest rate</dt><dd><?= (float)$loan['interest_rate'] ?>% per month (<?= money($s['daily_interest']) ?>/day)</dd>
      <dt>Tenure</dt><dd><?= (int)$loan['tenure_months'] ?> month<?= $loan['tenure_months'] == 1 ? '' : 's' ?> (<?= $s['term_days'] ?> days)</dd>
      <dt>Total interest at maturity</dt><dd><?= money($s['total_interest']) ?></dd>
      <dt>Total repayable</dt><dd><?= money($s['total_repayable']) ?></dd>
      <dt>Late fee</dt><dd><?= (float)$loan['late_fee_rate'] ?>% of unpaid balance per day<?= $s['penalty'] > 0 ? ' · now ' . money($s['penalty']) : '' ?></dd>
      <dt>Purpose</dt><dd><?= e($loan['purpose']) ?></dd>
      <dt>Applied</dt><dd><?= fdatetime($loan['applied_at']) ?></dd>
    </dl>
  </div>

  <div class="card">
    <h2>Repayment schedule</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>#</th><th>Due date</th><th class="num">Amount</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($schedule as $r): ?>
          <tr><td><?= $r['no'] ?></td><td><?= $loan['disbursed_at'] ? fdate($r['due']) : '+' . ($r['no'] * DAYS_PER_MONTH) . ' days' ?></td><td class="num"><?= money($r['amount']) ?></td><td class="right"><?= badge($r['state']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php if ($loan['status'] === 'active'): ?>
<div class="card" id="repay">
  <h2>Make a repayment</h2>
  <?php if ($hasPendingRepay): ?><div class="alert alert-info">You have a repayment waiting to be confirmed.</div><?php endif; ?>
  <div class="grid grid-2" style="align-items:start">
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
      <input type="hidden" name="source" value="savings">
      <h3>From your savings <span class="badge badge-ok">instant</span></h3>
      <?php if (!$plans): ?>
        <p class="muted small">You have no savings available.</p>
      <?php else: ?>
        <label>Plan<select name="plan_id"><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= money($p['balance']) ?>)</option><?php endforeach; ?></select></label>
        <label>Amount<input type="number" name="amount" min="1" step="0.01" max="<?= $s['payoff'] ?>" value="<?= min($s['payoff'], (float)$plans[0]['balance']) ?>" required></label>
        <button class="btn btn-primary" data-confirm="Pay this amount from your savings now?">Pay from savings</button>
      <?php endif; ?>
    </form>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
      <input type="hidden" name="source" value="external">
      <h3>Bank transfer, card or agent</h3>
      <div class="bank-box">Pay to <strong><?= e(BANK_ACCOUNT_NUMBER) ?></strong> · <?= e(BANK_NAME) ?> · <?= e(BANK_ACCOUNT_NAME) ?></div>
      <label>Amount<input type="number" name="amount" min="1" step="0.01" value="<?= $nextDue ? round($nextDue['amount'] - $nextDue['covered'], 2) : $s['payoff'] ?>" required></label>
      <label>Method<select name="method"><?php foreach (payment_methods() as $k => $vv): ?><option value="<?= $k ?>"><?= e($vv) ?></option><?php endforeach; ?></select></label>
      <label>Payment reference<input name="reference" required maxlength="80"></label>
      <button class="btn btn-ghost">Submit repayment</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($txs): ?>
<div class="card">
  <h2>Payments on this loan</h2>
  <ul class="list"><?php foreach ($txs as $t) echo tx_row($t); ?></ul>
</div>
<?php endif; ?>

<?php if ($series): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function () {
  const data = <?= json_encode($series) ?>;
  const cur = <?= json_encode(CURRENCY) ?>;
  const fmt = v => cur + Number(v).toLocaleString(undefined, {maximumFractionDigits: 0});
  new Chart(document.getElementById('interestChart'), {
    type: 'line',
    data: {
      labels: data.map(d => new Date(d.date + 'T00:00').toLocaleDateString(undefined, {month: 'short', day: 'numeric'})),
      datasets: [
        {label: 'You owed', data: data.map(d => d.owed), borderColor: '#0e6a50', backgroundColor: '#0e6a50', borderWidth: 2.5, pointRadius: 0, tension: .2, spanGaps: false},
        {label: 'If unpaid', data: data.map(d => d.projected), borderColor: '#9aa8a2', borderDash: [5, 5], borderWidth: 1.5, pointRadius: 0},
        {label: 'Interest', data: data.map(d => d.interest), borderColor: '#f2b33d', backgroundColor: 'rgba(242,179,61,.22)', fill: true, borderWidth: 1.5, pointRadius: 0}
      ]
    },
    options: {
      maintainAspectRatio: false,
      animation: false,
      interaction: {mode: 'index', intersect: false},
      plugins: {legend: {position: 'bottom', labels: {boxWidth: 12, usePointStyle: true}}, tooltip: {callbacks: {label: c => c.dataset.label + ': ' + (c.raw == null ? '—' : fmt(c.raw))}}},
      scales: {x: {grid: {display: false}, ticks: {maxTicksLimit: 6}}, y: {ticks: {callback: fmt, maxTicksLimit: 5}, grid: {color: '#eef2ef'}}}
    }
  });
})();
</script>
<?php endif; ?>
<?php app_footer();
