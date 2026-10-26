<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect($u['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
}

$error = '';
if (is_post()) {
    verify_csrf();
    $id = post('login');
    $pass = (string)post('password');

    // Basic throttling: lock out for 5 minutes after 5 failed attempts in this session.
    $fails = $_SESSION['login_fails'] ?? ['n' => 0, 't' => 0];
    if ($fails['n'] >= 5 && time() - $fails['t'] < 300) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $user = q_one('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1', [strtolower($id), $id]);
        if ($user && password_verify($pass, $user['password_hash'])) {
            if ($user['status'] !== 'active') {
                $error = 'This account is suspended. Please contact support.';
            } else {
                unset($_SESSION['login_fails']);
                login_user($user);
                redirect($user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
            }
        } else {
            $_SESSION['login_fails'] = ['n' => $fails['n'] + 1, 't' => time()];
            $error = 'Incorrect email/phone or password.';
        }
    }
}

public_header('Sign in');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Welcome back</h1>
    <p class="muted">Sign in to save, borrow and track your loan.</p>
    <?= render_flashes() ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Email or phone<input name="login" required autocomplete="username" value="<?= e(post('login')) ?>"></label>
      <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="muted small" style="margin-top:1rem;text-align:center">New here? <a href="<?= url('register.php') ?>">Create an account</a></p>
  </div>
</main>
<?php public_footer();
