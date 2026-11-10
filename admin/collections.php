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
        $sent = sms_user((int)$loan['user_id'], 'Payment reminder', 'Loan #' . $lid . ' is ' . $s['late_days'] . ' days late. Please pay ' . money($s['payoff'])
            . ' in the app or call ' . SUPPORT_PHONE . ' to agree a plan.', 'collect_sms', $lid . '-' . date('Y-m-d'));
        q('INSERT INTO collection_notes (loan_id, staff_id, outcome, note) VALUES (?, ?, "sms", ?)', [$lid, $me['id'], $sent ? 'Reminder SMS sent' : 'Reminder SMS not sent (already sent today or SMS is off)']);
        flash($sent ? 'success' : 'info', $sent ? 'Reminder sent to ' . $loan['full_name'] . '.' : 'No SMS sent: one was already sent today, or SMS is switched off.');
    } elseif (post('action') === 'assign') {
        q('UPDATE loans SET collector_id = ? WHERE id = ?', [(int)post('collector_id') ?: null, $lid]);
        audit('collections.assign', 'Loan #' . $lid, (string)post('collector_id'));
        flash('success', 'Collector updated.');
    }
    redirect('admin/collections.php?' . http_build_query(['bucket' => $_GET['bucket'] ?? 'all', 'mine' => $_GET['mine'] ?? '']));
}

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
<div class="card">
  <?php if (!$list): ?><div class="empty"><?= icon('check', 32) ?><p>Nothing here. Every loan in this group is on track.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th class="num">Owed now</th><th>Late</th><th>Promise</th><th>Last contact</th><th>Collector</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $r): $s = $r['snap']; ?>
        <tr>
          <td><a href="loans.php?id=<?= $r['id'] ?>#collect"><strong><?= e($r['full_name']) ?></strong></a><div class="small"><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a> · loan #<?= $r['id'] ?></div></td>
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
              <button class="btn btn-ghost btn-sm" name="action" value="remind" title="Send a polite reminder SMS">SMS</button>
              <a class="btn btn-primary btn-sm" href="loans.php?id=<?= $r['id'] ?>#collect">Log call</a>
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
