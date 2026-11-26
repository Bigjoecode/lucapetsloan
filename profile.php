<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$payout = payout_account((int)$u['id']);
$banks = !$payout && flw_configured() ? flw_banks() : [];

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
            if ($email !== strtolower($u['email'])) {   // tell the old address too, in case someone else made the change
                send_mail($u['email'], 'Your email address was changed · ' . APP_NAME, email_html('Your email address was changed', 'Your ' . APP_NAME . ' account now uses ' . $email . ".\n\nIf you did not make this change, call " . SUPPORT_PHONE . ' right away.'));
            }
            notify((int)$u['id'], 'Contact details updated', 'Your phone number is now ' . $phone . ' and your email is ' . $email . '. If this was not you, call ' . SUPPORT_PHONE . '.', 'profile.php', true);
            flash('success', 'Contact details updated.');
        }
    } elseif (post('action') === 'payout') {
        try {
            $acct = add_payout_account($u, (string)post('bank_code'), preg_replace('/\D/', '', (string)post('account_number')), (string)post('pin'));
            flash('success', 'Payout account saved: ' . $acct['account_name'] . '.');
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }
    } elseif (post('action') === 'pin') {
        $pin = (string)post('pin');
        if (!password_verify((string)post('current'), $u['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (!preg_match('/^\d{4}$/', $pin) || $pin !== (string)post('pin2')) {
            flash('error', 'Enter the same 4 digits twice.');
        } elseif (weak_pin($pin)) {
            flash('error', 'That PIN is too easy to guess. Avoid repeated or consecutive digits.');
        } else {
            q('UPDATE users SET pin_hash = ?, pin_fails = 0, pin_locked_until = NULL WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $u['id']]);
            notify((int)$u['id'], has_pin($u) ? 'PIN changed' : 'PIN set', 'Your payment PIN was ' . (has_pin($u) ? 'changed' : 'set') . '. If this was not you, call ' . SUPPORT_PHONE . '.', 'profile.php', true);
            flash('success', 'PIN saved. You will use it to withdraw and pay from savings.');
        }
    } elseif (post('action') === 'password') {
        if (!password_verify((string)post('current'), $u['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen((string)post('new')) < 8 || post('new') !== post('new2')) {
            flash('error', 'New password must be at least 8 characters and match the confirmation.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)post('new'), PASSWORD_DEFAULT), $u['id']]);
            forget_devices((int)$u['id']);
            notify((int)$u['id'], 'Password changed', 'Your password was changed from your profile. If this was not you, call ' . SUPPORT_PHONE . ' right away.', 'profile.php', true);
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
  <div class="card" id="pin">
    <h2><?= has_pin($u) ? 'Change your PIN' : 'Set your payment PIN' ?></h2>
    <p class="muted small">You'll enter this 4-digit PIN to withdraw or pay from your savings, so nobody else can move your money even if they open your phone.</p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="pin">
      <div class="form-grid">
        <label>New PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" required autocomplete="off"></label>
        <label>Confirm PIN<input type="password" name="pin2" inputmode="numeric" pattern="\d{4}" maxlength="4" required autocomplete="off"></label>
      </div>
      <label>Account password<input type="password" name="current" required autocomplete="current-password"></label>
      <button class="btn btn-primary"><?= has_pin($u) ? 'Change PIN' : 'Set PIN' ?></button>
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
<div class="card" id="payout">
  <h2>Payout bank account</h2>
  <?php if ($payout): ?>
    <div class="bank-box"><strong><?= e($payout['account_name']) ?></strong><br><?= e($payout['bank_name'] . ' · ' . $payout['account_number']) ?></div>
    <p class="muted small" style="margin:.6rem 0 0">Withdrawals and loans are paid here. For your safety it can't be changed in the app; call <?= e(SUPPORT_PHONE) ?> if it needs to change.</p>
  <?php elseif ($banks): ?>
    <p class="muted small">Withdrawals and loans are paid to this account. It must be in your own name, and once saved it can't be changed in the app.</p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="payout">
      <div class="form-grid">
        <label>Bank<select name="bank_code" required><option value="">Choose your bank</option><?php foreach ($banks as $code => $name): ?><option value="<?= e($code) ?>"><?= e($name) ?></option><?php endforeach; ?></select></label>
        <label>Account number<input name="account_number" inputmode="numeric" pattern="\d{10}" maxlength="10" required autocomplete="off"></label>
      </div>
      <label>PIN <span class="hint">Your 4-digit PIN</span><input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></label>
      <button class="btn btn-primary">Check &amp; save bank account</button>
    </form>
  <?php else: ?>
    <p class="muted small" style="margin:0">Adding a bank account isn't available right now. Please call <?= e(SUPPORT_PHONE) ?>.</p>
  <?php endif; ?>
</div>
<div class="card row between">
  <span class="muted small">Help: <?= e(SUPPORT_PHONE) ?> · <?= e(SUPPORT_EMAIL) ?></span>
  <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>"><?= icon('logout', 16) ?> Sign out</a>
</div>
<?php app_footer();
