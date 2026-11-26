<?php
/* API keys and test/live switches for Flutterwave, NOWPayments, Prembly and email (super admin only). */
require __DIR__ . '/../includes/bootstrap.php';
$me = require_super();

/** Fields per service: name => [label, secret?, check]. */
const INT_FORMS = [
    'flutterwave' => [
        'flw_test_public' => ['Test public key', false, '/^FLWPUBK_TEST-/'],
        'flw_test_secret' => ['Test secret key', true, '/^FLWSECK_TEST-/'],
        'flw_test_hash'   => ['Test webhook secret hash', true, '/^.{8,}$/'],
        'flw_live_public' => ['Live public key', false, '/^FLWPUBK-/'],
        'flw_live_secret' => ['Live secret key', true, '/^FLWSECK-/'],
        'flw_live_hash'   => ['Live webhook secret hash', true, '/^.{8,}$/'],
    ],
    'nowpayments' => [
        'np_api_key'    => ['API key', true, '/^[A-Za-z0-9-]{20,}$/'],
        'np_ipn_secret' => ['IPN secret key', true, '/^.{10,}$/'],
        'np_email'      => ['Account email', false, '/@/'],
        'np_password'   => ['Account password', true, '/^.{6,}$/'],
        'np_2fa_secret' => ['2FA secret key (for automatic payouts)', true, '/^[A-Za-z2-7 \-]{16,}$/'],
    ],
    'prembly' => ['prembly_app_id' => ['App ID (optional)', false, '/^.{4,}$/'], 'prembly_secret_key' => ['Secret key (x-api-key)', true, '/^.{10,}$/']],
    'email'  => [
        'smtp_host'      => ['Outgoing server (SMTP)', false, '/^((?=.*[A-Za-z])[A-Za-z0-9.-]{3,}|\d{1,3}(\.\d{1,3}){3})$/'],
        'smtp_port'      => ['Port', false, '/^(465|587|25|2525)$/'],
        'smtp_user'      => ['Mailbox (username)', false, '/^.{3,}$/'],
        'smtp_pass'      => ['Mailbox password', true, '/^.{4,}$/'],
        'mail_from'      => ['Send from (optional)', false, '/@/'],
        'resend_api_key' => ['Resend API key', true, '/^re_/'],
    ],
];

if (is_post()) {
    verify_csrf();
    $service = post('service');
    try {
        if (!isset(INT_FORMS[$service])) throw new UserError('Unknown service.');
        if (post('action') === 'test') {
            flash('info', integration_test($service));
            redirect('admin/integrations.php#' . $service);
        }
        if (!password_verify((string)post('password'), $me['password_hash'])) throw new UserError('Enter your password to change keys.');
        $changed = [];
        foreach (INT_FORMS[$service] as $name => [$label, , $pattern]) {
            $val = trim((string)post($name));
            if (post('clear_' . $name)) {
                save_int_key($name, null);
                $changed[] = $label . ' removed';
            } elseif ($val !== '') {
                if (!preg_match($pattern, $val)) throw new UserError($label . ' doesn\'t look right. Copy it again from the provider\'s dashboard.');
                save_int_key($name, $val);
                $changed[] = $label . ' set (' . mask_key($val) . ')';
            }
        }
        if ($service === 'flutterwave') {
            $mode = post('flw_mode') === 'live' ? 'live' : 'test';
            if ($mode === 'live' && (int_key('flw_live_secret') === '' || int_key('flw_live_hash') === '')) {
                throw new UserError('Add the live secret key and live webhook hash before switching to live.');
            }
            if ($mode !== flw_mode()) {
                save_setting('flw_mode', $mode);
                $changed[] = 'mode → ' . strtoupper($mode);
                notify_staff('payments', 'Flutterwave switched to ' . strtoupper($mode), $mode === 'live' ? 'Real money now moves through Flutterwave.' : 'Payments now use test mode; no real money moves.', 'admin/integrations.php');
            }
        }
        if ($service === 'email') {
            $srv = post('mail_server') ? 1 : 0;
            if ($srv !== setting('mail_server')) {
                save_setting('mail_server', $srv);
                $changed[] = $srv ? 'sending with the server\'s built-in mail' : 'server mail off';
            }
        }
        if ($service === 'nowpayments') {
            $mode = post('np_mode') === 'live' ? 'live' : 'sandbox';
            if ($mode !== np_mode()) {
                $hasWallets = (bool)q_val('SELECT 1 FROM users WHERE np_sub_id IS NOT NULL LIMIT 1');
                if ($hasWallets && !post('confirm_switch')) throw new UserError('Customers already have wallets in ' . np_mode() . ' mode. Tick the box to confirm the switch.');
                save_setting('np_mode', $mode);
                if ($hasWallets) {
                    q('UPDATE users SET np_sub_id = NULL');   // wallets belong to one mode; new ones open on the next visit
                    q('UPDATE crypto_deposits SET status = "expired" WHERE status = "waiting"');
                }
                q('DELETE FROM settings WHERE k LIKE "cache\_np\_%"');
                $changed[] = 'mode → ' . strtoupper($mode);
                notify_staff('payments', 'NOWPayments switched to ' . strtoupper($mode), $mode === 'live' ? 'Crypto deposits are now real.' : 'Crypto now uses the sandbox; nothing is real.', 'admin/crypto.php');
            }
            foreach (['crypto_auto_payouts' => (int)(bool)post('crypto_auto_payouts'), 'crypto_auto_daily_usd' => max(0, (float)post('crypto_auto_daily_usd'))] as $k => $val) {
                if (($k === 'crypto_auto_daily_usd' && post($k) === '') || $val == setting($k)) continue;
                save_setting($k, $val);
                $changed[] = $k === 'crypto_auto_payouts' ? 'automatic crypto payouts ' . ($val ? 'on' : 'off') : 'automatic limit $' . $val;
            }
            $on = post('crypto_savings') ? 1 : 0;
            if ($on !== setting('crypto_savings')) {
                save_setting('crypto_savings', $on);
                $changed[] = 'crypto savings ' . ($on ? 'on' : 'off');
            }
        }
        if ($service === 'prembly') {
            foreach (['prembly_base_url' => '#^https?://[^\s]+$#', 'prembly_bvn_path' => '#^/[\w/.-]+$#', 'prembly_nin_path' => '#^/[\w/.-]+$#'] as $k => $re) {
                $val = trim((string)post($k));
                if ($val !== '' && $val !== setting($k)) {
                    if (!preg_match($re, $val)) throw new UserError('Check the Prembly address and paths.');
                    save_setting($k, $val);
                    $changed[] = $k . ' → ' . $val;
                }
            }
        }
        if ($changed) {
            audit('integrations.' . $service, ucfirst($service), implode('; ', $changed));
        }
        flash('success', $changed ? ucfirst($service) . ' updated: ' . implode(', ', $changed) . '.' : 'Nothing changed.');
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/integrations.php#' . $service);
}

/** Make a harmless read-only call to check the keys work. */
function integration_test(string $service): string
{
    $get = function (string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
    };
    switch ($service) {
        case 'flutterwave':
            if (!flw_configured()) return 'Flutterwave: no ' . flw_mode() . ' secret key saved yet.';
            $res = flw_request('GET', '/balances/NGN');
            if (!$res['ok']) return 'Flutterwave (' . flw_mode() . '): keys rejected · ' . flw_message($res, 'HTTP ' . $res['http']);
            return 'Flutterwave (' . flw_mode() . ') is working. NGN balance: ' . money($res['body']['data']['available_balance'] ?? 0) . '.';
        case 'nowpayments':
            if (int_key('np_api_key') === '') return 'NOWPayments: no API key saved yet.';
            q('DELETE FROM settings WHERE k LIKE "cache\_np\_%"');
            $ip = server_public_ip();
            $ipHint = $ip !== '' ? ' If IP whitelisting is on in NOWPayments (Settings → Whitelist), add this server\'s address: ' . $ip . '.' : '';
            $res = np_request('GET', '/merchant/coins');
            if (!$res['ok']) return 'NOWPayments (' . np_mode() . '): API key refused · ' . np_message($res, 'HTTP ' . $res['http']) . '. Check the key is from your ' . (np_mode() === 'live' ? 'live' : 'sandbox') . ' account.' . $ipHint;
            $coins = count((array)($res['body']['selectedCurrencies'] ?? $res['body']['currencies'] ?? []));
            if ($coins === 0) return 'NOWPayments key works, but no coins are switched on. Tick coins in NOWPayments → Settings → Coins.';
            if (!np_configured()) return 'NOWPayments API key works (' . $coins . ' coins). Add the account email and password so customer wallets can be opened.';
            try {
                np_jwt();
            } catch (UserError) {
                return 'NOWPayments API key works, but the account sign-in was refused (' . np_last_error() . '). Check the email and password; if your NOWPayments login uses two-factor codes, ask NOWPayments support whether API sign-in works with it.';
            }
            // Customer wallets: open (or reuse) one for this admin and read its balance, the same calls customers make.
            try {
                $sub = np_wallet_id((int)$GLOBALS['me']['id']);
            } catch (UserError $e) {
                return 'NOWPayments sign-in works, but a customer wallet could not be opened (' . np_last_error() . '). Make sure Custody is enabled in NOWPayments → Settings → Payments.' . $ipHint;
            }
            $bal = np_request('GET', '/sub-partner/balance/' . rawurlencode($sub));
            if (!$bal['ok']) return 'NOWPayments: wallets open, but balances are refused (' . np_last_error() . ').' . $ipHint;
            return 'NOWPayments (' . np_mode() . ') is working: ' . $coins . ' coins switched on, sign-in OK, customer wallets and balances OK' . ($ip !== '' ? ' (server address ' . $ip . ')' : '') . '.';
        case 'prembly':
            if (!prembly_on()) return 'Prembly: no secret key saved yet.';
            // A lookup of an impossible number: a "not found" answer proves the key is accepted without costing a real check.
            [$http, $b] = prembly_post(setting('prembly_bvn_path'), '00000000000');
            $ok = $http >= 200 && $http < 300 && isset($b['response_code']);
            return $ok ? 'Prembly is working' . (!empty($b['is_sandbox']) || str_starts_with((string)($b['verification']['reference'] ?? ''), 'SBX') ? ' (sandbox)' : '') . '. Response: ' . ($b['detail'] ?? $b['response_code']) . '.'
                : 'Prembly: request refused · ' . ($b['detail'] ?? $b['message'] ?? 'HTTP ' . $http) . '. Check the key and the address below.';
        default:
            if (!mail_enabled()) return 'Email: add your mailbox details (or a Resend key) first.';
            $to = (string)$GLOBALS['me']['email'];
            $ok = send_mail($to, 'Test email · ' . APP_NAME, email_html('Email is working 🎉', "This is a test from " . APP_NAME . ".\n\nCustomers will now get their approvals, payments, reminders and password resets by email.", absolute_url('dashboard.php'), 'Open ' . APP_NAME));
            if ($ok && ($GLOBALS['mail_fallback'] ?? '') !== '') {
                return 'Test email sent to ' . $to . ' via the server\'s built-in mail, because your mailbox was refused (' . $GLOBALS['mail_fallback'] . '). Emails keep working this way; to stop this message, tick "Send with the server\'s built-in mail" or fix the mailbox password.';
            }
            return $ok ? 'Test email sent to ' . $to . ' via ' . ['server' => 'the server\'s built-in mail (from ' . smtp_user() . ')', 'mailbox' => 'your mailbox (' . smtp_user() . ' on ' . smtp_host() . ':' . smtp_port() . ')', 'resend' => 'Resend'][mail_method()] . '. Check the inbox (and spam folder).' : 'Email not sent · ' . mail_last_error();
    }
}

/** One key field: masked current value, blank keeps it. */
$field = function (string $name, array $spec): void {
    [$label, $secret] = $spec;
    $current = int_key($name);
    $fromEnv = $current !== '' && !q_val('SELECT 1 FROM settings WHERE k = ?', ['sec_' . $name]);
    ?>
    <label><?= e($label) ?> <span class="hint"><?= $current === '' ? 'Not set' : ($secret ? 'Saved ' . e(mask_key($current)) : 'Saved: ' . e($current)) . ($fromEnv ? ' (from hosting settings)' : '') ?></span>
      <input name="<?= $name ?>" <?= $secret ? 'type="password" autocomplete="new-password"' : '' ?> placeholder="<?= $current === '' ? 'Paste here' : 'Leave blank to keep' ?>">
    </label>
    <?php if ($current !== '' && !$fromEnv): ?><label class="check small"><input type="checkbox" name="clear_<?= $name ?>" value="1"><span>Remove</span></label><?php endif;
};
$pw = '<label>Your password <span class="hint">Needed to change keys</span><input type="password" name="password" autocomplete="current-password" required></label>';

app_header('Integrations', 'integrations', 'admin');
?>
<h1 class="mobile-title">Integrations</h1>
<p class="muted small">Keys are stored encrypted. Only the last four characters are ever shown. Every change is recorded in the audit log (without the keys).</p>

<div class="card" id="flutterwave">
  <div class="card-head"><h2>Flutterwave</h2><span class="badge badge-<?= flw_mode() === 'live' ? 'bad' : 'info' ?>"><?= flw_mode() === 'live' ? 'LIVE: real money' : 'TEST mode' ?></span></div>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="service" value="flutterwave">
    <div class="row">
      <label class="check"><input type="radio" name="flw_mode" value="test" <?= flw_mode() === 'test' ? 'checked' : '' ?>><span><strong>Test</strong>: test cards and accounts, no real money</span></label>
      <label class="check"><input type="radio" name="flw_mode" value="live" <?= flw_mode() === 'live' ? 'checked' : '' ?> data-live><span><strong>Live</strong>: real customers, real money</span></label>
    </div>
    <div class="grid grid-2" style="align-items:start">
      <div class="form"><h3 style="margin:0">Test keys</h3><?php foreach (['flw_test_public', 'flw_test_secret', 'flw_test_hash'] as $n) $field($n, INT_FORMS['flutterwave'][$n]); ?></div>
      <div class="form"><h3 style="margin:0">Live keys</h3><?php foreach (['flw_live_public', 'flw_live_secret', 'flw_live_hash'] as $n) $field($n, INT_FORMS['flutterwave'][$n]); ?></div>
    </div>
    <p class="small muted" style="margin:0">Keys: Flutterwave dashboard → Settings → API keys. Webhook: Settings → Webhooks, URL <code><?= e(absolute_url('flw_webhook.php')) ?></code>, with the same secret hash you enter here (any long random text). Use the matching test or live dashboard.</p>
    <div class="alert alert-info small" style="margin:0"><strong>Payouts (withdrawals and loan pay-outs)</strong> only work from server addresses whitelisted in Flutterwave (Settings → Whitelisted IP addresses). Until that's set up, Flutterwave refuses transfers, nothing is sent, and the request waits in Payments for staff to pay manually. Card, transfer and USSD payments <em>in</em> are not affected.</div>
    <?= $pw ?>
    <div class="row"><button class="btn btn-primary">Save Flutterwave</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Test connection</button></div>
  </form>
</div>

<div class="card" id="nowpayments">
  <div class="card-head"><h2>Crypto savings · NOWPayments</h2><span class="badge badge-<?= np_mode() === 'live' ? 'bad' : 'info' ?>"><?= np_mode() === 'live' ? 'LIVE: real crypto' : 'SANDBOX' ?></span></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="service" value="nowpayments">
    <div class="row">
      <label class="check"><input type="radio" name="np_mode" value="sandbox" <?= np_mode() === 'sandbox' ? 'checked' : '' ?>><span><strong>Sandbox</strong>: test coins (account at account-sandbox.nowpayments.io)</span></label>
      <label class="check"><input type="radio" name="np_mode" value="live" <?= np_mode() === 'live' ? 'checked' : '' ?>><span><strong>Live</strong>: real crypto</span></label>
    </div>
    <label class="check small"><input type="checkbox" name="confirm_switch" value="1"><span>I understand switching mode gives every customer a new wallet in the other mode</span></label>
    <div class="grid grid-2" style="align-items:start">
      <div class="form"><?php foreach (['np_api_key', 'np_ipn_secret'] as $n) $field($n, INT_FORMS['nowpayments'][$n]); ?></div>
      <div class="form"><?php foreach (['np_email', 'np_password', 'np_2fa_secret'] as $n) $field($n, INT_FORMS['nowpayments'][$n]); ?></div>
    </div>
    <label class="check"><input type="checkbox" name="crypto_savings" value="1" <?= setting('crypto_savings') ? 'checked' : '' ?>><span>Offer crypto savings to customers</span></label>
    <div class="form-grid">
      <label class="check"><input type="checkbox" name="crypto_auto_payouts" value="1" <?= setting('crypto_auto_payouts') ? 'checked' : '' ?>><span>Send crypto withdrawals automatically <span class="hint">needs the 2FA secret key</span></span></label>
      <label>Automatic limit per customer per day (US$)<input type="number" name="crypto_auto_daily_usd" min="0" step="10" value="<?= setting('crypto_auto_daily_usd') ?>"></label>
    </div>
    <p class="small muted" style="margin:0"><strong>For automatic payouts:</strong> in NOWPayments turn on 2-step sign-in with an <em>authenticator app</em> and paste the <strong>secret key</strong> it shows here; and under Settings → Whitelist switch off <em>wallet address</em> whitelisting (keep the IP whitelist on). The first withdrawal to a new address and anything over the daily limit still wait for staff in Admin → Crypto.</p>
    <p class="small muted" style="margin:0">In the NOWPayments dashboard: <strong>Settings → Payments → API keys</strong> for the API key; <strong>Instant payment notifications</strong> for the IPN secret (the address is set per deposit automatically: <code><?= e(absolute_url('np_webhook.php')) ?></code>); <strong>Settings → Coins</strong> to choose which coins customers can save in. Customer wallets need the <strong>Custody</strong> feature: ask NOWPayments support to switch it on. The email and password are the ones you sign in to NOWPayments with (needed to open wallets and move coins for withdrawals).</p>
    <?= $pw ?>
    <div class="row"><button class="btn btn-primary">Save NOWPayments</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Test connection</button></div>
  </form>
</div>

<div class="grid grid-2" style="align-items:start">
  <div class="card" id="prembly">
    <h2>BVN &amp; NIN checks · Prembly</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="service" value="prembly">
      <?php foreach (INT_FORMS['prembly'] as $n => $spec) $field($n, $spec); ?>
      <p class="small muted" style="margin:0">Use your sandbox key while testing and your live key for real customers (Prembly dashboard → Developers → API keys).</p>
      <details><summary class="small">Advanced: API address and endpoints</summary>
        <div class="form" style="margin-top:.6rem">
          <label>API address<input name="prembly_base_url" value="<?= e(setting('prembly_base_url')) ?>"></label>
          <label>BVN endpoint<input name="prembly_bvn_path" value="<?= e(setting('prembly_bvn_path')) ?>"></label>
          <label>NIN endpoint<input name="prembly_nin_path" value="<?= e(setting('prembly_nin_path')) ?>"></label>
          <p class="small muted" style="margin:0">Only change these if Prembly's docs or support give you different ones.</p>
        </div>
      </details>
      <?= $pw ?>
      <div class="row"><button class="btn btn-primary">Save Prembly</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Test connection</button></div>
    </form>
  </div>
  <div class="card" id="email">
    <div class="card-head"><h2>Email</h2><?= mail_enabled() ? '<span class="badge badge-ok">Connected · ' . ['server' => 'server mail', 'mailbox' => 'mailbox', 'resend' => 'Resend'][mail_method()] . '</span>' : '<span class="badge badge-warn">Not set up</span>' ?></div>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="service" value="email">
      <div class="alert alert-info small" style="margin:0">Ready for your hosting mailbox <strong><?= e(smtp_user()) ?></strong> on <strong><?= e(smtp_host()) ?></strong>, port <strong><?= smtp_port() ?></strong> (SSL). <strong>Just enter the mailbox password below</strong>, then press <em>Send test email to me</em>.</div>
      <label class="check" style="padding:.7rem .8rem;border:1px solid var(--line);border-radius:12px;background:var(--surface-2)"><input type="checkbox" name="mail_server" value="1" <?= setting('mail_server') ? 'checked' : '' ?>><span><strong>Send with the server's built-in mail</strong> (no password needed)<br><span class="small muted">Sends from <?= e(smtp_user()) ?> through your hosting. Use this if the mailbox password keeps being refused.</span></span></label>
      <?php $field('smtp_pass', INT_FORMS['email']['smtp_pass']); ?>
      <details><summary class="small">Use a different server or mailbox</summary>
        <div class="form" style="margin-top:.6rem">
          <div class="form-grid"><?php foreach (['smtp_host', 'smtp_port'] as $n) $field($n, INT_FORMS['email'][$n]); ?></div>
          <?php $field('smtp_user', INT_FORMS['email']['smtp_user']); ?>
          <p class="small muted" style="margin:0">Leave blank to use the values above. They come from cPanel → Email Accounts → Connect Devices → "Secure SSL/TLS settings".</p>
        </div>
      </details>
      <?php $field('mail_from', INT_FORMS['email']['mail_from']); ?>
      <p class="small muted" style="margin:0">Leave "Send from" blank to use the mailbox address, or write it like <code><?= e(APP_NAME) ?> &lt;no-reply@yourdomain.com&gt;</code> (same address as the mailbox).</p>
      <details><summary class="small">Or use Resend instead of a mailbox</summary>
        <div class="form" style="margin-top:.6rem"><?php $field('resend_api_key', INT_FORMS['email']['resend_api_key']); ?><p class="small muted" style="margin:0">Only used when no mailbox is filled in. Needs your domain verified in Resend.</p></div>
      </details>
      <?= $pw ?>
      <div class="row"><button class="btn btn-primary">Save email</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Send test email to me</button></div>
    </form>
  </div>
</div>
<script>
  document.querySelectorAll('input[data-live]').forEach((r) => r.addEventListener('change', () => {
    if (r.checked && !confirm('Switch Flutterwave to LIVE? Real customers will be charged and real money will be sent.')) {
      r.form.querySelector('input[name=flw_mode][value=test]').checked = true;
    }
  }));
</script>
<?php app_footer();
