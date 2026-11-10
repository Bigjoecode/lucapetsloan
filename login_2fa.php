<?php
/* Second sign-in step for staff who use an authenticator app. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/totp.php';

$pending = $_SESSION['2fa_uid'] ?? null;
if (!$pending || time() - (int)($_SESSION['2fa_at'] ?? 0) > 300) {
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_at']);
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
    $tries = ($_SESSION['2fa_tries'] ?? 0) + 1;
    $_SESSION['2fa_tries'] = $tries;
    if (totp_verify((string)$user['totp_secret'], (string)post('code'))) {
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        login_user($user);
        redirect(home_for($user));
    }
    if ($tries >= 5) {
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        audit('staff.2fa_failed', 'User #' . $user['id'], '5 wrong codes', (int)$user['id']);
        flash('error', 'Too many wrong codes. Sign in again.');
        redirect('login.php');
    }
    $error = 'That code is not right. Use the newest code in your authenticator app.';
}

public_header('Two-step sign-in');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Enter your code</h1>
    <p class="muted">Open your authenticator app and type the 6-digit code for <?= e(APP_NAME) ?>.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>6-digit code<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required autofocus></label>
      <button class="btn btn-primary btn-block">Verify</button>
    </form>
    <p class="muted small" style="margin-top:1rem;text-align:center">Lost your phone? Ask a super admin to reset your two-step sign-in.</p>
  </div>
</main>
<?php public_footer();
