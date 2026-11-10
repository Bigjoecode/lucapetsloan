<?php
/* Staff two-step sign-in setup. */
define('SKIP_2FA_GATE', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/totp.php';
$me = require_staff();

if (is_post()) {
    verify_csrf();
    if (post('action') === 'enable') {
        $secret = $_SESSION['totp_setup'] ?? '';
        if (!totp_verify($secret, (string)post('code'))) {
            flash('error', 'That code didn\'t match. Scan the QR code again and enter the newest code.');
        } else {
            q('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $me['id']]);
            unset($_SESSION['totp_setup']);
            audit('staff.2fa_on', 'User #' . $me['id']);
            flash('success', 'Two-step sign-in is on. You\'ll need a code from your app each time you sign in.');
        }
    } elseif (post('action') === 'disable') {
        if (setting('require_staff_2fa')) {
            flash('error', 'Two-step sign-in is required for all staff.');
        } elseif (!totp_verify((string)$me['totp_secret'], (string)post('code'))) {
            flash('error', 'Enter a current code from your app to turn it off.');
        } else {
            q('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$me['id']]);
            audit('staff.2fa_off', 'User #' . $me['id']);
            flash('success', 'Two-step sign-in turned off.');
        }
    }
    redirect('admin/security.php');
}

if (!$me['totp_enabled'] && empty($_SESSION['totp_setup'])) {
    $_SESSION['totp_setup'] = totp_new_secret();
}
$secret = $_SESSION['totp_setup'] ?? '';

app_header('Sign-in security', '', 'admin');
?>
<h1 class="mobile-title">Sign-in security</h1>
<div class="card" style="max-width:560px">
  <?php if ($me['totp_enabled']): ?>
    <h2>Two-step sign-in is on</h2>
    <p class="muted">Each sign-in asks for a 6-digit code from your authenticator app.</p>
    <?php if (!setting('require_staff_2fa')): ?>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
        <label>Current code<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required></label>
        <button class="btn btn-ghost btn-sm" data-confirm="Turn off two-step sign-in?">Turn off</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <h2>Turn on two-step sign-in</h2>
    <?php if (setting('require_staff_2fa')): ?><div class="alert alert-warn">All staff must use two-step sign-in. Set it up to continue.</div><?php endif; ?>
    <ol class="small" style="padding-left:1.1rem">
      <li>Install Google Authenticator, Microsoft Authenticator or Authy on your phone.</li>
      <li>In the app, add an account and scan this code:</li>
    </ol>
    <div id="qr" style="background:#fff;padding:12px;display:inline-block;border-radius:8px" data-uri="<?= e(totp_uri($secret, $me['email'])) ?>"></div>
    <p class="small muted">Can't scan? Enter this key: <code style="word-break:break-all"><?= e(chunk_split($secret, 4, ' ')) ?></code></p>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="enable">
      <label>3. Enter the 6-digit code the app shows<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required></label>
      <button class="btn btn-primary">Turn on</button>
    </form>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>(() => { const el = document.getElementById('qr'); if (window.QRCode) new QRCode(el, { text: el.dataset.uri, width: 180, height: 180 }); })();</script>
  <?php endif; ?>
</div>
<?php app_footer();
