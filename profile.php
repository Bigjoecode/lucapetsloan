<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

if (is_post()) {
    verify_csrf();
    if (post('action') === 'profile') {
        $phone = preg_replace('/[^\d+]/', '', post('phone'));
        $email = strtolower(post('email'));
        if (strlen($phone) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid phone number and email.');
        } elseif (q_one('SELECT id FROM users WHERE (email = ? OR phone = ?) AND id <> ?', [$email, $phone, $u['id']])) {
            flash('error', 'That email or phone is already used by another account.');
        } else {
            q('UPDATE users SET phone = ?, email = ? WHERE id = ?', [$phone, $email, $u['id']]);
            flash('success', 'Contact details updated.');
        }
    } elseif (post('action') === 'password') {
        if (!password_verify((string)post('current'), $u['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen((string)post('new')) < 8 || post('new') !== post('new2')) {
            flash('error', 'New password must be at least 8 characters and match the confirmation.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)post('new'), PASSWORD_DEFAULT), $u['id']]);
            flash('success', 'Password changed.');
        }
    }
    redirect('profile.php');
}

app_header('Profile', 'profile');
?>
<h1 class="mobile-title">Profile</h1>
<div class="card row">
  <span class="avatar" style="width:56px;height:56px;font-size:1.2rem"><?= e(initials($u['full_name'])) ?></span>
  <div class="grow"><h2 style="margin:0"><?= e($u['full_name']) ?></h2><span class="muted small">Member since <?= fdate($u['created_at']) ?></span></div>
  <?= badge($u['kyc_status']) ?>
</div>
<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h2>Contact details</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <label>Full name <span class="hint">Contact support to change your name</span><input value="<?= e($u['full_name']) ?>" disabled></label>
      <label>Phone<input type="tel" name="phone" required value="<?= e($u['phone']) ?>"></label>
      <label>Email<input type="email" name="email" required value="<?= e($u['email']) ?>"></label>
      <button class="btn btn-primary">Save</button>
    </form>
  </div>
  <div class="card">
    <h2>Change password</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password<input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
      <label>Confirm new password<input type="password" name="new2" minlength="8" required autocomplete="new-password"></label>
      <button class="btn btn-primary">Update password</button>
    </form>
  </div>
</div>
<div class="card row between">
  <span class="muted small">Help: <?= e(SUPPORT_PHONE) ?> · <?= e(SUPPORT_EMAIL) ?></span>
  <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>"><?= icon('logout', 16) ?> Sign out</a>
</div>
<?php app_footer();
