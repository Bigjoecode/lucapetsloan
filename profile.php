<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$kyc = q_one('SELECT bank_name, bank_code, account_number, account_name FROM kyc WHERE user_id = ?', [$u['id']]);
$banks = $kyc && flw_configured() ? flw_banks() : [];

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
    } elseif (post('action') === 'payout' && $banks) {
        $code = (string)post('bank_code');
        $number = (string)post('account_number');
        if (!password_verify((string)post('current'), $u['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (!isset($banks[$code]) || !preg_match('/^\d{10}$/', $number)) {
            flash('error', 'Choose your bank and enter your 10-digit account number.');
        } elseif (!($name = flw_resolve_account($number, $code))) {
            flash('error', "We couldn't confirm that account with the bank. Check the number and bank.");
        } else {
            // Payouts only go to the customer's own account: the bank's name must share a name with theirs.
            $words = fn($s) => array_filter(preg_split('/[^a-z]+/', strtolower($s)), fn($w) => strlen($w) >= 3);
            if (!array_intersect($words($name), $words($u['full_name']))) {
                flash('error', 'That account belongs to ' . $name . '. Withdrawals can only go to an account in your own name.');
            } else {
                q('UPDATE kyc SET bank_code = ?, bank_name = ?, account_number = ?, account_name = ? WHERE user_id = ?', [$code, $banks[$code], $number, $name, $u['id']]);
                audit('customer.payout_account', 'Customer #' . $u['id'], $banks[$code] . ' ' . $number . ' (' . $name . ')');
                notify((int)$u['id'], 'Payout account changed', 'Withdrawals now go to ' . $banks[$code] . ' ' . $number . '. If this was not you, call ' . SUPPORT_PHONE . '.');
                flash('success', 'Payout account saved: ' . $name . '.');
            }
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
<?php if ($banks): ?>
<div class="card" id="payout">
  <h2>Payout account</h2>
  <p class="muted small">Withdrawals are sent here. <?= $kyc['bank_code'] ? 'Now: <strong>' . e($kyc['bank_name'] . ' · ' . $kyc['account_number'] . ' · ' . $kyc['account_name']) . '</strong>' : 'Choose your bank so withdrawals can be paid automatically.' ?></p>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="payout">
    <div class="form-grid">
      <label>Bank<select name="bank_code" required><option value="">Choose your bank</option><?php foreach ($banks as $code => $name): ?><option value="<?= e($code) ?>" <?= $kyc['bank_code'] === (string)$code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
      <label>Account number<input name="account_number" inputmode="numeric" pattern="\d{10}" maxlength="10" required value="<?= e($kyc['account_number']) ?>"></label>
    </div>
    <label>Current password <span class="hint">To protect your money</span><input type="password" name="current" required autocomplete="current-password"></label>
    <button class="btn btn-primary">Save payout account</button>
  </form>
</div>
<?php endif; ?>
<div class="card row between">
  <span class="muted small">Help: <?= e(SUPPORT_PHONE) ?> · <?= e(SUPPORT_EMAIL) ?></span>
  <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>"><?= icon('logout', 16) ?> Sign out</a>
</div>
<?php app_footer();
