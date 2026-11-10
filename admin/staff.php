<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_super();

if (is_post()) {
    verify_csrf();
    try {
        $action = post('action');
        $perms = array_values(array_intersect(array_keys(STAFF_PERMISSIONS), (array)($_POST['perms'] ?? [])));
        $role = post('role') === 'super_admin' ? 'super_admin' : 'admin';

        if ($action === 'add') {
            $email = strtolower(post('email'));
            $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
            if (!$user) {
                // New staff account; they set their own password through a reset link.
                $name = post('full_name');
                $phone = preg_replace('/[^\d+]/', '', post('phone'));
                if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 10) {
                    throw new UserError('No account uses that email. To create one, enter a full name, valid email and phone number.');
                }
                if (q_one('SELECT id FROM users WHERE phone = ?', [$phone])) throw new UserError('That phone number belongs to another account.');
                q('INSERT INTO users (full_name, email, phone, password_hash, kyc_status) VALUES (?,?,?,?, "verified")',
                    [$name, $email, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
                $user = q_one('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()]);
                $_SESSION['staff_link'] = [
                    'name' => $name,
                    'url'  => absolute_url('reset_password.php?token=' . create_reset_token((int)$user['id'], 72 * 60)),
                ];
            } elseif (is_staff($user)) {
                throw new UserError($user['full_name'] . ' is already staff.');
            }
            if ($role === 'admin' && !$perms) throw new UserError('Give the new staff member at least one permission.');
            q('UPDATE users SET role = ?, permissions = ? WHERE id = ?', [$role, $role === 'admin' ? implode(',', $perms) : null, $user['id']]);
            notify((int)$user['id'], 'You now have staff access', 'Open the staff console from the menu.', 'admin/index.php');
            audit('staff.add', 'User #' . $user['id'], $user['full_name'] . ' as ' . $role . ($role === 'admin' ? ': ' . implode(', ', $perms) : ''));
            flash('success', $user['full_name'] . ' added as ' . label($role) . '.');
        } else {
            $uid = (int)post('user_id');
            $user = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
            if (!$user || !is_staff($user)) throw new UserError('Staff member not found.');
            if ($uid === (int)$me['id']) throw new UserError('Ask another super admin to change your own access.');
            if ($action === 'update') {
                if ($role === 'admin' && !$perms) throw new UserError('Choose at least one permission, or remove their staff access.');
                q('UPDATE users SET role = ?, permissions = ? WHERE id = ?', [$role, $role === 'admin' ? implode(',', $perms) : null, $uid]);
                audit('staff.update', 'User #' . $uid, $user['full_name'] . ': ' . $role . ($role === 'admin' ? ' · ' . implode(', ', $perms) : ''));
                flash('success', 'Access updated for ' . $user['full_name'] . '.');
            } elseif ($action === 'remove') {
                q('UPDATE users SET role = "customer", permissions = NULL WHERE id = ?', [$uid]);
                audit('staff.remove', 'User #' . $uid, $user['full_name']);
                flash('success', $user['full_name'] . ' no longer has staff access.');
            } elseif ($action === 'toggle_status') {
                q('UPDATE users SET status = IF(status = "active", "suspended", "active") WHERE id = ?', [$uid]);
                audit($user['status'] === 'active' ? 'staff.suspend' : 'staff.activate', 'User #' . $uid, $user['full_name']);
                flash('success', 'Account status updated.');
            }
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/staff.php');
}

$staff = q('SELECT u.*, (SELECT MAX(created_at) FROM audit_log a WHERE a.actor_id = u.id) last_action
            FROM users u WHERE role IN ("admin","super_admin") ORDER BY role = "super_admin" DESC, full_name')->fetchAll();
$link = $_SESSION['staff_link'] ?? null;
unset($_SESSION['staff_link']);

$permBoxes = function (?array $u) { ?>
  <div class="perm-grid">
    <?php foreach (STAFF_PERMISSIONS as $k => $text): ?>
      <label class="check"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= $u === null || in_array($k, staff_permissions($u), true) ? 'checked' : '' ?>><span class="small"><?= e($text) ?></span></label>
    <?php endforeach; ?>
  </div>
<?php };

app_header('Staff & roles', 'staff', 'admin');
?>
<h1 class="mobile-title">Staff & roles</h1>
<?php if ($link): ?>
  <div class="alert alert-info">
    <strong>Send this link to <?= e($link['name']) ?></strong> so they can set their password. It works once, for 3 days, and won't be shown again.
    <input readonly value="<?= e($link['url']) ?>" onclick="this.select()" style="margin-top:.5rem;width:100%">
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Team</h2><span class="muted small"><?= count($staff) ?> people</span></div>
  <ul class="list">
    <?php foreach ($staff as $s): ?>
      <li style="align-items:flex-start;flex-wrap:wrap">
        <span class="avatar"><?= e(initials($s['full_name'])) ?></span>
        <div class="grow" style="min-width:220px">
          <div class="title"><?= e($s['full_name']) ?> <span class="badge badge-<?= is_super($s) ? 'ok' : 'info' ?>"><?= e(label($s['role'])) ?></span> <?= $s['status'] === 'suspended' ? badge('suspended') : '' ?></div>
          <div class="sub"><?= e($s['email']) ?> · <?= e($s['phone']) ?> · last action <?= $s['last_action'] ? fdatetime($s['last_action']) : 'never' ?></div>
          <?php if ((int)$s['id'] === (int)$me['id']): ?>
            <div class="sub">That's you.</div>
          <?php else: ?>
            <details style="margin-top:.4rem">
              <summary class="small" style="cursor:pointer">Change access</summary>
              <form method="post" class="form" style="margin-top:.6rem">
                <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                <label>Role<select name="role" data-role><option value="admin" <?= is_super($s) ? '' : 'selected' ?>>Staff (chosen permissions)</option><option value="super_admin" <?= is_super($s) ? 'selected' : '' ?>>Super admin (everything)</option></select></label>
                <?php $permBoxes($s); ?>
                <div class="row">
                  <button class="btn btn-primary btn-sm" name="action" value="update">Save</button>
                  <button class="btn btn-ghost btn-sm" name="action" value="toggle_status" data-confirm="<?= $s['status'] === 'active' ? 'Suspend' : 'Reactivate' ?> this staff account?"><?= $s['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                  <button class="btn btn-danger btn-sm" name="action" value="remove" data-confirm="Remove all staff access for <?= e($s['full_name']) ?>?">Remove staff access</button>
                </div>
              </form>
            </details>
          <?php endif; ?>
        </div>
        <?php if (!is_super($s)): ?>
          <div class="small muted" style="max-width:280px"><?= e(implode(' · ', array_map(fn($p) => STAFF_PERMISSIONS[$p], staff_permissions($s)))) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="card">
  <h2>Add staff</h2>
  <p class="muted small">Enter the email of an existing account to give it staff access. For someone new, also enter their name and phone; you'll get a link to send them so they can set a password.</p>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
      <label>Email<input type="email" name="email" required></label>
      <label>Full name <span class="hint">New accounts only</span><input name="full_name"></label>
      <label>Phone <span class="hint">New accounts only</span><input type="tel" name="phone"></label>
      <label>Role<select name="role" data-role><option value="admin">Staff (chosen permissions)</option><option value="super_admin">Super admin (everything)</option></select></label>
    </div>
    <?php $permBoxes(null); ?>
    <button class="btn btn-primary"><?= icon('plus', 16) ?> Add staff</button>
  </form>
</div>
<p class="muted small">Super admins can do everything, including managing staff, changing settings and reading the audit log. Staff see only the pages their permissions allow.</p>
<?php app_footer();
