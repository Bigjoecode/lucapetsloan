<?php
require __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? post('token'));
$reset = find_reset($token);

$errors = [];
if ($reset && is_post()) {
    verify_csrf();
    $pass = (string)post('password');
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== (string)post('password2')) $errors[] = 'Passwords do not match.';
    if (!$errors) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $reset['id']]);
        // Every outstanding link for this account stops working once one is used.
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$reset['id']]);
        notify((int)$reset['id'], 'Password changed', 'Your password was reset. If this wasn\'t you, call ' . SUPPORT_PHONE . ' right away.');
        unset($_SESSION['uid'], $_SESSION['login_fails']);
        flash('success', 'Password updated. Sign in with your new password.');
        redirect('login.php');
    }
}

public_header('Choose a new password');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <?php if (!$reset): ?>
      <h1>Link expired</h1>
      <p class="muted">This reset link is invalid, has expired or was already used. Request a new one.</p>
      <a class="btn btn-primary btn-block" href="<?= url('forgot_password.php') ?>">Request a new link</a>
    <?php else: ?>
      <h1>Choose a new password</h1>
      <p class="muted">For <?= e($reset['email']) ?></p>
      <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>New password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
        <button class="btn btn-primary btn-block">Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php public_footer();
