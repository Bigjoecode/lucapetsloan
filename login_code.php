<?php
/* Second sign-in step: the 6-digit code we emailed. Then the PIN. */
require __DIR__ . '/includes/bootstrap.php';

$pending = $_SESSION['code_uid'] ?? null;
if (!$pending) {
    redirect('login.php');
}
if (time() - (int)($_SESSION['code_at'] ?? 0) > LOGIN_CODE_MINUTES * 60 + 120) {
    unset($_SESSION['code_uid'], $_SESSION['code_at']);
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
    if (post('action') === 'resend') {
        $sent = send_login_code($user);
        if ($sent === '') {
            $_SESSION['code_at'] = time();
            flash('success', 'A new code is on its way to ' . mask_email($user['email']) . '.');
        } else {
            flash('error', $sent === 'send-failed' ? 'We could not send the email just now. Please try again in a minute.' : $sent);
        }
        redirect('login_code.php');
    }
    $error = check_login_code((int)$user['id'], preg_replace('/\D/', '', (string)post('code')));
    if ($error === '') {
        unset($_SESSION['code_uid'], $_SESSION['code_at']);
        redirect(after_email_code($user));
    }
}

public_header('Check your email');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Check your email</h1>
    <p class="muted">We sent a 6-digit code to <strong><?= e(mask_email($user['email'])) ?></strong>. Enter it to continue. It works for <?= LOGIN_CODE_MINUTES ?> minutes.</p>
    <?= render_flashes() ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Code from your email<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required autofocus class="pin-input" placeholder="••••••"></label>
      <button class="btn btn-primary btn-block">Continue</button>
    </form>
    <form method="post" style="margin-top:.8rem;text-align:center"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn btn-ghost btn-sm">Send a new code</button></form>
    <p class="muted small" style="margin-top:1rem;text-align:center">Not in your inbox? Check spam. Wrong account? <a href="<?= url('login.php') ?>">Sign in again</a>.</p>
  </div>
</main>
<?php public_footer();
