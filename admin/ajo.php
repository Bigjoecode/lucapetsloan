<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff('customers');

if (is_post()) {
    verify_csrf();
    $g = q_one('SELECT * FROM ajo_groups WHERE id = ?', [(int)post('group_id')]);
    if ($g && post('action') === 'cancel' && $g['status'] === 'forming') {
        q('UPDATE ajo_groups SET status = "cancelled" WHERE id = ?', [$g['id']]);
        audit('ajo.cancel', 'Group #' . $g['id'], $g['name']);
        flash('success', 'Group cancelled.');
    }
    redirect('admin/ajo.php');
}

$groups = q('SELECT g.*, u.full_name owner,
               (SELECT COUNT(*) FROM ajo_members m WHERE m.group_id = g.id) members,
               (SELECT COUNT(*) FROM ajo_contributions c WHERE c.group_id = g.id AND c.round = g.current_round) paid,
               (SELECT COALESCE(SUM(amount),0) FROM ajo_contributions c WHERE c.group_id = g.id) collected
             FROM ajo_groups g JOIN users u ON u.id = g.owner_id WHERE g.status <> "cancelled" ORDER BY g.status = "completed", g.round_due IS NULL, g.round_due')->fetchAll();
$totals = ['active' => 0, 'forming' => 0, 'late' => 0];
foreach ($groups as $g) {
    if (isset($totals[$g['status']])) $totals[$g['status']]++;
    if ($g['status'] === 'active' && $g['round_due'] < date('Y-m-d') && $g['paid'] < $g['size']) $totals['late']++;
}

app_header('Group savings', 'ajo', 'admin');
?>
<h1 class="mobile-title">Group savings</h1>
<div class="grid grid-3">
  <div class="card stat"><span class="k">Active groups</span><span class="v"><?= $totals['active'] ?></span></div>
  <div class="card stat"><span class="k">Waiting for members</span><span class="v"><?= $totals['forming'] ?></span></div>
  <div class="card stat"><span class="k">Rounds overdue</span><span class="v" style="<?= $totals['late'] ? 'color:var(--bad)' : '' ?>"><?= $totals['late'] ?></span><span class="s">a member hasn't paid by the due date</span></div>
</div>
<div class="card">
  <?php if (!$groups): ?><div class="empty"><?= icon('users', 32) ?><p>No groups yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Group</th><th>Creator</th><th class="num">Contribution</th><th>Members</th><th>Round</th><th class="num">Collected</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($groups as $g): $late = $g['status'] === 'active' && $g['round_due'] < date('Y-m-d') && $g['paid'] < $g['size']; ?>
        <tr>
          <td><strong><?= e($g['name']) ?></strong><div class="muted small">Code <?= e($g['invite_code']) ?> · <?= e(AJO_FREQUENCIES[$g['frequency']][0]) ?></div></td>
          <td><?= e($g['owner']) ?></td>
          <td class="num"><?= money0($g['contribution']) ?></td>
          <td><?= $g['members'] ?>/<?= $g['size'] ?></td>
          <td><?= $g['status'] === 'active' ? $g['current_round'] . ' · ' . $g['paid'] . '/' . $g['size'] . ' paid · due ' . fdate($g['round_due']) : '—' ?><?= $late ? ' ' . badge('overdue') : '' ?></td>
          <td class="num"><?= money0($g['collected']) ?></td>
          <td><?= badge($g['status'] === 'forming' ? 'pending' : $g['status']) ?></td>
          <td class="right"><?php if ($g['status'] === 'forming'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= $g['id'] ?>"><button class="btn btn-ghost btn-sm" name="action" value="cancel" data-confirm="Cancel this group?">Cancel</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php app_footer();
