<?php
declare(strict_types=1);
define('SKIP_INSTALL_CHECK', true);
require __DIR__ . '/includes/bootstrap.php';

if (app_installed()) {
    // Safe to re-run: creates any tables added since the first install, never touches data.
    try {
        run_schema();
    } catch (Throwable) {
    }
    exit('Already installed. <a href="' . e(url('login.php')) . '">Sign in</a>');
}

$errors = [];
$done = false;

if (is_post()) {
    verify_csrf();
    $name = post('full_name');
    $email = strtolower(post('email'));
    $phone = post('phone');
    $pass = (string)post('password');

    if (SETUP_KEY !== '' && !hash_equals(SETUP_KEY, (string)post('setup_key'))) {
        $errors[] = 'Setup key is incorrect.';
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
        $errors[] = 'Enter the admin name, a valid email and a phone number.';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }

    if (!$errors) {
        try {
            // Hosted databases usually already exist and may not allow CREATE DATABASE.
            try {
                $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS, db_options());
                $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (PDOException) {
            }
            run_schema();
            if (!(int)q_val('SELECT COUNT(*) FROM loan_products')) {
                $ins = db()->prepare('INSERT INTO loan_products (name, description, min_amount, max_amount, interest_rate, min_tenure, max_tenure, late_fee_rate) VALUES (?,?,?,?,?,?,?,?)');
                $ins->execute(['Quick Cash', 'Small emergency loan, repaid within 1–3 months.', 5000, 50000, 5.00, 1, 3, 0.50]);
                $ins->execute(['Trader Boost', 'Working capital for market traders and small shops.', 50000, 500000, 4.00, 2, 6, 0.40]);
                $ins->execute(['Business Growth', 'Bigger loans for established businesses with strong savings history.', 300000, 2000000, 3.50, 3, 12, 0.30]);
            }
            if (q_one('SELECT id FROM users WHERE email = ? OR phone = ?', [$email, $phone])) {
                q('UPDATE users SET role = "admin" WHERE email = ? OR phone = ?', [$email, $phone]);
            } else {
                q('INSERT INTO users (full_name, email, phone, password_hash, role, kyc_status) VALUES (?,?,?,?, "admin", "verified")',
                    [$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT)]);
            }
            @file_put_contents(__DIR__ . '/install.lock', date('c'));   // read-only on Vercel; the admin row marks the install there
            $done = true;
        } catch (Throwable $ex) {
            $errors[] = 'Database error: ' . $ex->getMessage() . ' — check the database settings and that MySQL is running.';
        }
    }
}

public_header('Install');
?>
<main class="auth-wrap">
  <div class="auth-card">
    <?php if ($done): ?>
      <h1>Installed ✓</h1>
      <p class="muted">The database is ready, three starter loan products were added, and your admin account was created.</p>
      <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Sign in as admin</a>
    <?php else: ?>
      <h1>Set up <?= e(APP_NAME) ?></h1>
      <p class="muted">This creates the database tables on <code><?= e(DB_HOST) ?></code> and your first admin account.</p>
      <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <?php if (SETUP_KEY !== ''): ?>
          <label>Setup key <span class="hint">The SETUP_KEY value from your hosting settings</span><input type="password" name="setup_key" required></label>
        <?php endif; ?>
        <label>Admin full name<input name="full_name" required value="<?= e(post('full_name')) ?>"></label>
        <label>Admin email<input type="email" name="email" required value="<?= e(post('email')) ?>"></label>
        <label>Admin phone<input name="phone" required value="<?= e(post('phone')) ?>"></label>
        <label>Password<input type="password" name="password" minlength="8" required></label>
        <button class="btn btn-primary btn-block">Install</button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php public_footer();
