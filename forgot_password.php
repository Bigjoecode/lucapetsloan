<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('profile.php');
}

$sent = false;
$error = '';
if (is_post()) {
    verify_csrf();
    $id = trim((string)post('login'));
    if ($id === '') {
        $error = 'Enter your email or phone number.';
    } else {
        $phone = preg_replace('/[^\d+]/', '', $id);
        $user = q_one('SELECT * FROM users WHERE email = ? OR (phone = ? AND ? <> "") LIMIT 1', [strtolower($id), $phone, $phone]);
        // Same answer whether or not the account exists, so this page can't be used to look up customers.
        $sent = true;
        $recent = $user ? (int)q_val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$user['id']]) : 0;
        if ($user && $user['status'] === 'active' && $recent < 3) {
            $token = create_reset_token((int)$user['id'], 60);
            $link = absolute_url('reset_password.php?token=' . $token);
            $mailed = send_mail($user['email'], 'Reset your ' . APP_NAME . ' password',
                '<p>Hi ' . e(explode(' ', $user['full_name'])[0]) . ',</p>'
                . '<p>We received a request to reset your password. This link works for 1 hour:</p>'
                . '<p><a href="' . e($link) . '">Choose a new password</a></p>'
                . '<p>If you didn\'t ask for this, you can ignore this email; your password stays the same.</p>');
            if (!$mailed) {
                // No email service: staff confirm it's really the customer, then send them a link.
                notify_staff('customers', 'Password reset requested', $user['full_name'] . ' (' . $user['phone'] . ') asked to reset their password.',
                    'admin/users.php?s=' . rawurlencode($user['phone']));
            }
        }
    }
}

public_header('Reset password');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <?php if ($sent): ?>
      <h1>Check your messages</h1>
      <?php if (mail_enabled()): ?>
        <p class="muted">If an account matches what you entered, we've emailed a link to reset your password. It works for 1 hour. Check your spam folder too.</p>
      <?php else: ?>
        <p class="muted">If an account matches what you entered, our team will call or message you on your registered phone number with a link to reset your password.</p>
      <?php endif; ?>
      <p class="muted small">Need help sooner? Call <?= e(SUPPORT_PHONE) ?>.</p>
      <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Back to sign in</a>
    <?php else: ?>
      <h1>Forgot your password?</h1>
      <p class="muted">Enter the email or phone number you signed up with.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label>Email or phone<input name="login" required autocomplete="username" value="<?= e(post('login')) ?>"></label>
        <button class="btn btn-primary btn-block">Send reset link</button>
      </form>
      <p class="muted small" style="margin-top:1rem;text-align:center">Remembered it? <a href="<?= url('login.php') ?>">Sign in</a></p>
    <?php endif; ?>
  </div>
</main>
<?php public_footer();
