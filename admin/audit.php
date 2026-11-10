<?php
require __DIR__ . '/../includes/bootstrap.php';
require_super();

$actor = $_GET['actor'] ?? '';
$area = preg_replace('/[^a-z_]/', '', $_GET['area'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = ['1=1'];
$params = [];
if ($actor === 'system') {
    $where[] = 'a.actor_id IS NULL';
} elseif (ctype_digit((string)$actor)) {
    $where[] = 'a.actor_id = ?';
    $params[] = (int)$actor;
}
if ($area !== '') {
    $where[] = 'a.action LIKE ?';
    $params[] = $area . '.%';
}
$w = implode(' AND ', $where);
$total = (int)q_val("SELECT COUNT(*) FROM audit_log a WHERE $w", $params);
$rows = q("SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id WHERE $w ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();
$staff = q('SELECT DISTINCT u.id, u.full_name FROM audit_log a JOIN users u ON u.id = a.actor_id ORDER BY u.full_name')->fetchAll(PDO::FETCH_KEY_PAIR);
$areas = ['' => 'Everything', 'loan' => 'Loans & waivers', 'payment' => 'Payments', 'withdrawal' => 'Withdrawals', 'kyc' => 'Verifications', 'customer' => 'Customers', 'staff' => 'Staff', 'settings' => 'Settings', 'product' => 'Products'];
$qs = fn(array $extra) => '?' . http_build_query(array_merge(['actor' => $actor, 'area' => $area], $extra));

app_header('Audit log', 'audit', 'admin');
?>
<h1 class="mobile-title">Audit log</h1>
<form class="row" method="get">
  <select name="actor" style="width:auto">
    <option value="">Everyone</option>
    <option value="system" <?= $actor === 'system' ? 'selected' : '' ?>>System (Flutterwave)</option>
    <?php foreach ($staff as $id => $name): ?><option value="<?= $id ?>" <?= (string)$id === $actor ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
  </select>
  <select name="area" style="width:auto"><?php foreach ($areas as $k => $t): ?><option value="<?= $k ?>" <?= $k === $area ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost btn-sm">Filter</button>
</form>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('list', 32) ?><p>Nothing recorded yet.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>On</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted small" style="white-space:nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td><?= $r['actor_id'] ? e($r['full_name'] ?? 'User #' . $r['actor_id']) : '<span class="muted">System</span>' ?></td>
          <td><code><?= e($r['action']) ?></code></td>
          <td><?= e($r['target']) ?></td>
          <td class="small"><?= e($r['details']) ?><?= $r['ip'] ? '<div class="muted">' . e($r['ip']) . '</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($total > $per): ?>
    <div class="row between" style="margin-top:1rem">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>">← Newer</a><?php else: ?><span></span><?php endif; ?>
      <span class="muted small">Page <?= $page ?> of <?= (int)ceil($total / $per) ?></span>
      <?php if ($page * $per < $total): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>">Older →</a><?php else: ?><span></span><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php app_footer();
