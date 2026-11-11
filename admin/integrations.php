<?php
/* API keys and test/live switches for Flutterwave, Termii, Prembly and Resend (super admin only). */
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
    'termii' => ['termii_api_key' => ['API key', true, '/^.{10,}$/'], 'termii_sender_id' => ['Sender ID', false, '/^[A-Za-z0-9 ]{3,11}$/']],
    'prembly' => ['prembly_app_id' => ['App ID (optional)', false, '/^.{4,}$/'], 'prembly_secret_key' => ['Secret key (x-api-key)', true, '/^.{10,}$/']],
    'email'  => ['resend_api_key' => ['Resend API key', true, '/^re_/'], 'mail_from' => ['Send from', false, '/@/']],
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
        case 'termii':
            if (termii_key() === '') return 'Termii: no API key saved yet.';
            [$http, $b] = $get(TERMII_BASE_URL . '/get-balance?api_key=' . rawurlencode(termii_key()), ['Accept: application/json']);
            return $http === 200 && isset($b['balance']) ? 'Termii is working. Balance: ' . ($b['currency'] ?? '') . ' ' . $b['balance'] . '.' : 'Termii: key rejected (HTTP ' . $http . ').';
        case 'prembly':
            if (!prembly_on()) return 'Prembly: no secret key saved yet.';
            // A lookup of an impossible number: a "not found" answer proves the key is accepted without costing a real check.
            [$http, $b] = prembly_post(setting('prembly_bvn_path'), '00000000000');
            $ok = $http >= 200 && $http < 300 && isset($b['response_code']);
            return $ok ? 'Prembly is working' . (!empty($b['is_sandbox']) || str_starts_with((string)($b['verification']['reference'] ?? ''), 'SBX') ? ' (sandbox)' : '') . '. Response: ' . ($b['detail'] ?? $b['response_code']) . '.'
                : 'Prembly: request refused · ' . ($b['detail'] ?? $b['message'] ?? 'HTTP ' . $http) . '. Check the key and the address below.';
        default:
            return mail_enabled() ? 'Resend key saved. Try "Forgot password" with your own email to see a real message.' : 'Email: no Resend key saved yet.';
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

<div class="grid grid-2" style="align-items:start">
  <div class="card" id="termii">
    <h2>SMS · Termii</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="service" value="termii">
      <?php foreach (INT_FORMS['termii'] as $n => $spec) $field($n, $spec); ?>
      <?= $pw ?>
      <div class="row"><button class="btn btn-primary">Save Termii</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Test connection</button></div>
    </form>
  </div>
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
    <h2>Email · Resend</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="service" value="email">
      <?php foreach (INT_FORMS['email'] as $n => $spec) $field($n, $spec); ?>
      <p class="small muted" style="margin:0">"Send from" looks like <code><?= e(APP_NAME) ?> &lt;no-reply@yourdomain.com&gt;</code> on a domain verified in Resend.</p>
      <?= $pw ?>
      <div class="row"><button class="btn btn-primary">Save email</button><button class="btn btn-ghost" name="action" value="test" formnovalidate>Check</button></div>
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
