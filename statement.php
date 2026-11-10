<?php
/* Account statement for a date range, printable as PDF. Staff can open any customer's with ?user=. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/print_layout.php';
$u = require_login();

$uid = (int)$u['id'];
if (!empty($_GET['user']) && (int)$_GET['user'] !== $uid) {
    if (!can('customers') && !can('reports')) {
        http_response_code(403);
        exit('You don\'t have access to this statement.');
    }
    $uid = (int)$_GET['user'];
}
$cust = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
if (!$cust) {
    http_response_code(404);
    exit('Customer not found.');
}
accrue_user_interest($uid);
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
$to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];

// How each kind of transaction moves the savings balance.
$effect = function (array $t): float {
    $a = (float)$t['amount'];
    return match ($t['type']) {
        'deposit', 'interest', 'bonus', 'ajo_payout' => $t['status'] === 'approved' ? $a : 0,
        'withdrawal' => in_array($t['status'], ['approved', 'pending', 'processing'], true) ? -$a : 0,   // held as soon as requested
        'ajo_contribution' => $t['status'] === 'approved' ? -$a : 0,
        'loan_repayment' => $t['status'] === 'approved' && $t['method'] === 'savings' ? -$a : 0,
        default => 0,
    };
};
$after = q('SELECT * FROM transactions WHERE user_id = ? AND created_at >= ?', [$uid, $from . ' 00:00:00'])->fetchAll();
$closing = savings_total($uid) - array_sum(array_map($effect, array_filter($after, fn($t) => $t['created_at'] > $to . ' 23:59:59')));
$opening = $closing - array_sum(array_map($effect, array_filter($after, fn($t) => $t['created_at'] <= $to . ' 23:59:59')));
$rows = array_values(array_filter($after, fn($t) => $t['created_at'] <= $to . ' 23:59:59'));
usort($rows, fn($a, $b) => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
$loans = q('SELECT * FROM loans WHERE user_id = ? AND status IN ("active","defaulted","repaid") AND (closed_at IS NULL OR closed_at >= ?) ORDER BY id', [$uid, $from . ' 00:00:00'])->fetchAll();
$titles = ['deposit' => 'Savings deposit', 'withdrawal' => 'Withdrawal', 'interest' => 'Savings interest', 'bonus' => 'Referral bonus', 'loan_disbursement' => 'Loan paid out',
    'loan_repayment' => 'Loan repayment', 'ajo_contribution' => 'Group savings contribution', 'ajo_payout' => 'Group savings payout'];

print_head('Statement ' . $from . ' to ' . $to);
print_bar((int)$cust['id'] === (int)$u['id'] ? 'transactions.php' : 'admin/users.php');
?>
<div class="sheet">
  <header class="doc">
    <div><h1>Account statement</h1><div class="muted"><?= fdate($from) ?> – <?= fdate($to) ?> · generated <?= fdatetime(date('Y-m-d H:i:s')) ?></div></div>
    <div class="muted" style="text-align:right"><strong style="color:var(--ink)"><?= e($cust['full_name']) ?></strong><br><?= e($cust['phone']) ?><br><?= e($cust['email']) ?></div>
  </header>
  <table class="facts" style="max-width:420px">
    <tr><td>Savings at start</td><td class="num"><?= money($opening) ?></td></tr>
    <tr><td>Savings at end</td><td class="num"><strong><?= money($closing) ?></strong></td></tr>
  </table>
  <h2>Transactions</h2>
  <?php if (!$rows): ?><p class="muted">No transactions in this period.</p><?php else: ?>
  <table>
    <thead><tr><th>Date</th><th>Details</th><th>Status</th><th class="num">Amount</th><th class="num">Savings balance</th></tr></thead>
    <tbody>
      <?php $bal = $opening; foreach ($rows as $t): $bal += $effect($t); $in = in_array($t['type'], ['deposit', 'interest', 'bonus', 'ajo_payout', 'loan_disbursement'], true); ?>
        <tr>
          <td><?= fdate($t['created_at'], 'M j, Y') ?></td>
          <td><?= e($titles[$t['type']] ?? label($t['type'])) ?><?= $t['loan_id'] ? ' · loan #' . (int)$t['loan_id'] : '' ?><div class="muted"><?= e($t['reference'] ?: ($t['note'] ?? '')) ?></div></td>
          <td><?= e(label($t['status'])) ?></td>
          <td class="num"><?= $in ? '+' : '−' ?><?= money($t['amount']) ?></td>
          <td class="num"><?= money($bal) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($loans): ?>
    <h2>Loans</h2>
    <table>
      <thead><tr><th>Loan</th><th class="num">Borrowed</th><th>Due</th><th class="num">Paid</th><th class="num">Owed now</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($loans as $l): $s = loan_snapshot($l); ?>
        <tr><td>#<?= $l['id'] ?></td><td class="num"><?= money($l['amount']) ?></td><td><?= fdate($l['due_date']) ?></td><td class="num"><?= money($l['amount_paid']) ?></td><td class="num"><?= money($s['payoff']) ?></td><td><?= e(label($s['display_status'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
  <p class="muted" style="margin-top:1.5rem;font-size:.8rem">Withdrawals count from the moment they are requested, because the money is set aside then. Questions about this statement: <?= e(SUPPORT_PHONE) ?> · <?= e(SUPPORT_EMAIL) ?>.</p>
</div>
</body>
</html>
