<?php
/* Second sign-in step: the account's 4-digit PIN. */
require __DIR__ . '/includes/bootstrap.php';

$pending = $_SESSION['pin_uid'] ?? null;
if (!$pending || time() - (int)($_SESSION['pin_at'] ?? 0) > 300) {
    unset($_SESSION['pin_uid'], $_SESSION['pin_at']);
    flash('info', 'Please sign in again.');
    redirect('login.php');
}
$user = q_one('SELECT * FROM users WHERE id = ? AND status = "active"', [$pending]);
if (!$user) {
    redirect('login.php');
}

$error = '';
if (is_post()) {
    verify_csrf();
    try {
        check_pin($user, (string)post('pin'));
        unset($_SESSION['pin_uid'], $_SESSION['pin_at']);
        login_user($user);
        redirect(home_for($user));
    } catch (UserError $e) {
        $locked = (bool)q_val('SELECT pin_locked_until > NOW() FROM users WHERE id = ?', [$user['id']]);
        if ($locked) {
            unset($_SESSION['pin_uid'], $_SESSION['pin_at']);
            flash('error', $e->getMessage() . ' You can also reset your password, which resets your PIN.');
            redirect('login.php');
        }
        $error = $e->getMessage();
    }
}

public_header('Enter your PIN');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Enter your PIN</h1>
    <p class="muted">Hi <?= e(explode(' ', $user['full_name'])[0]) ?>, enter your 4-digit PIN to finish signing in.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required autofocus class="pin-input"></label>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="muted small" style="margin-top:1rem;text-align:center">Forgot your PIN? <a href="<?= url('forgot_password.php') ?>">Reset your password</a> and you'll create a new PIN.</p>
  </div>
</main>
<?php public_footer();
