<?php
/* Every account creates a 4-digit PIN right after signing up (or at the next sign-in for older accounts). */
define('SKIP_PIN_GATE', true);
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if (has_pin($u)) {
    redirect(home_for($u));
}

$error = '';
if (is_post()) {
    verify_csrf();
    $pin = (string)post('pin');
    if (!preg_match('/^\d{4}$/', $pin) || $pin !== (string)post('pin2')) {
        $error = 'Enter the same 4 digits twice.';
    } elseif (weak_pin($pin)) {
        $error = 'That PIN is too easy to guess. Avoid repeated or consecutive digits like 1111 or 1234.';
    } else {
        q('UPDATE users SET pin_hash = ?, pin_fails = 0, pin_locked_until = NULL, pin_set_at = NOW() WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
        flash('success', 'PIN created. You\'ll enter it each time you sign in and before money leaves your account.');
        redirect(home_for($u));
    }
}

public_header('Create your PIN');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Create your 4-digit PIN</h1>
    <p class="muted">You'll enter this PIN after your password every time you sign in, and before any withdrawal. Don't share it with anyone, including our staff.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>New PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required autofocus class="pin-input"></label>
      <label>Confirm PIN<input type="password" name="pin2" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required class="pin-input"></label>
      <button class="btn btn-primary btn-block">Save PIN</button>
    </form>
    <p class="muted small" style="margin-top:1rem;text-align:center"><a href="<?= url('logout.php') ?>">Sign out</a></p>
  </div>
</main>
<?php public_footer();
