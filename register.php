<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    $name = post('full_name');
    $email = strtolower(post('email'));
    $phone = preg_replace('/[^\d+]/', '', post('phone'));
    $pass = (string)post('password');

    if (mb_strlen($name) < 3) $errors[] = 'Enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (strlen($phone) < 10) $errors[] = 'Enter a valid phone number.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== (string)post('password2')) $errors[] = 'Passwords do not match.';
    if (!post('terms')) $errors[] = 'Please accept the terms to continue.';
    if (!$errors && q_one('SELECT id FROM users WHERE email = ? OR phone = ?', [$email, $phone])) {
        $errors[] = 'An account with that email or phone already exists.';
    }

    if (!$errors) {
        q('INSERT INTO users (full_name, email, phone, password_hash) VALUES (?,?,?,?)', [$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId();
        q('INSERT INTO savings_plans (user_id, name, daily_amount) VALUES (?, "Daily Savings", 500)', [$id]);
        notify($id, 'Welcome to ' . APP_NAME . '!', 'Start saving daily, then complete your verification to unlock loans.', 'verification.php');
        login_user(['id' => $id]);
        flash('success', 'Account created. We opened a Daily Savings plan for you.');
        redirect('dashboard.php');
    }
}

public_header('Create account');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <h1>Create your account</h1>
    <p class="muted">Start saving today. Loans unlock after your verification and home visit.</p>
    <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Full name <span class="hint">As written on your ID</span><input name="full_name" required autocomplete="name" value="<?= e(post('full_name')) ?>"></label>
      <label>Phone number<input type="tel" name="phone" required autocomplete="tel" placeholder="080..." value="<?= e(post('phone')) ?>"></label>
      <label>Email<input type="email" name="email" required autocomplete="email" value="<?= e(post('email')) ?>"></label>
      <label>Password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
      <label>Confirm password<input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
      <label class="check"><input type="checkbox" name="terms" value="1" <?= post('terms') ? 'checked' : '' ?>><span class="small">I agree that <?= e(APP_NAME) ?> may verify my identity and visit my address before approving a loan.</span></label>
      <button class="btn btn-primary btn-block">Create account</button>
    </form>
    <p class="muted small" style="margin-top:1rem;text-align:center">Already have an account? <a href="<?= url('login.php') ?>">Sign in</a></p>
  </div>
</main>
<?php public_footer();
