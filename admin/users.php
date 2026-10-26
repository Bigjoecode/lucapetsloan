<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    verify_csrf();
    $uid = (int)post('user_id');
    if ($uid === (int)$admin['id']) {
        flash('error', 'You cannot change your own account here.');
    } elseif (post('action') === 'toggle_status') {
        q('UPDATE users SET status = IF(status = "active", "suspended", "active") WHERE id = ?', [$uid]);
        flash('success', 'Account status updated.');
    } elseif (post('action') === 'toggle_admin') {
        q('UPDATE users SET role = IF(role = "admin", "customer", "admin") WHERE id = ?', [$uid]);
        flash('success', 'Role updated.');
    }
    redirect('admin/users.php?' . http_build_query(['s' => $_GET['s'] ?? '']));
}

$search = trim($_GET['s'] ?? '');
$params = [];
$where = '1=1';
if ($search !== '') {
    $where = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params = array_fill(0, 3, '%' . $search . '%');
}
$rows = q("SELECT u.*,
    (SELECT COALESCE(SUM(balance),0) FROM savings_plans sp WHERE sp.user_id = u.id AND sp.status <> 'closed') savings,
    (SELECT status FROM loans l WHERE l.user_id = u.id ORDER BY l.id DESC LIMIT 1) last_loan
  FROM users u WHERE $where ORDER BY u.id DESC LIMIT 300", $params)->fetchAll();

app_header('Customers', 'users', 'admin');
?>
<h1 class="mobile-title">Customers</h1>
<form class="row" method="get">
  <input name="s" value="<?= e($search) ?>" placeholder="Search name, email or phone" style="max-width:320px">
  <button class="btn btn-ghost btn-sm">Search</button>
</form>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('users', 32) ?><p>No customers found.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Contact</th><th>Verification</th><th class="num">Savings</th><th>Latest loan</th><th>Joined</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['full_name']) ?></strong> <?= $r['role'] === 'admin' ? '<span class="badge badge-info">admin</span>' : '' ?> <?= $r['status'] === 'suspended' ? badge('suspended') : '' ?></td>
          <td class="small"><?= e($r['phone']) ?><div class="muted"><?= e($r['email']) ?></div></td>
          <td><?php if ($r['kyc_status'] !== 'none'): ?><a href="kyc.php?id=<?= $r['id'] ?>"><?= badge($r['kyc_status']) ?></a><?php else: ?><?= badge('none') ?><?php endif; ?></td>
          <td class="num"><?= money($r['savings']) ?></td>
          <td><?= $r['last_loan'] ? badge($r['last_loan']) : '<span class="muted small">—</span>' ?></td>
          <td class="muted small"><?= fdate($r['created_at']) ?></td>
          <td class="right">
            <?php if ((int)$r['id'] !== (int)$admin['id']): ?>
            <form method="post" class="row" style="gap:.3rem;flex-wrap:nowrap;justify-content:flex-end">
              <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $r['id'] ?>">
              <button class="btn btn-ghost btn-sm" name="action" value="toggle_status" data-confirm="<?= $r['status'] === 'active' ? 'Suspend' : 'Reactivate' ?> this account?"><?= $r['status'] === 'active' ? 'Suspend' : 'Activate' ?></button>
              <button class="btn btn-ghost btn-sm" name="action" value="toggle_admin" data-confirm="Change this user's staff access?"><?= $r['role'] === 'admin' ? 'Remove admin' : 'Make admin' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php app_footer();
