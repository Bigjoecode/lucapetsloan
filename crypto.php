<?php
/* Crypto savings: wallet ID, a permanent address per coin (with QR), balances, deposits and withdrawals. */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

if (is_post()) {
    verify_csrf();
    $action = post('action');
    $back = 'crypto.php';
    try {
        if (!crypto_on()) throw new UserError('Crypto savings are not available yet.');
        if ($action === 'address') {
            $w = crypto_wallet_address($uid, strtolower(post('coin')));
            redirect('crypto.php?coin=' . urlencode($w['coin']) . '#receive');
        }
        if ($action === 'withdraw') {
            $coin = strtolower(post('coin'));
            $amount = (float)post('amount');
            $address = trim((string)post('address'));
            $memo = trim((string)post('extra_id'));
            check_pin($u, (string)post('pin'));
            if ($amount <= 0) throw new UserError('Enter the amount to withdraw.');
            if (!preg_match('/^[A-Za-z0-9:_.\-]{20,120}$/', $address)) throw new UserError('That wallet address doesn\'t look right. Copy it again from the wallet you\'re sending to.');
            if (q_val('SELECT 1 FROM crypto_wallets WHERE address = ?', [$address])) throw new UserError('That is a ' . APP_NAME . ' deposit address. Send to your own outside wallet instead.');
            $available = crypto_available($uid, $coin, crypto_balances($uid));
            if ($amount > $available + 1e-12) throw new UserError('You can withdraw up to ' . coin_amount($available) . ' ' . strtoupper($coin) . '.');
            q('INSERT INTO crypto_withdrawals (user_id, coin, amount, address, extra_id) VALUES (?,?,?,?,?)', [$uid, $coin, $amount, $address, $memo ?: null]);
            notify($uid, 'Crypto withdrawal requested', 'You asked to send ' . coin_amount($amount) . ' ' . strtoupper($coin) . ' to ' . substr($address, 0, 8) . '…' . substr($address, -6) . '. We check and send crypto withdrawals within 24 hours. If this was not you, call ' . SUPPORT_PHONE . '.', 'crypto.php', true);
            notify_staff('payments', 'Crypto withdrawal to send', $u['full_name'] . ' asked to withdraw ' . coin_amount($amount) . ' ' . strtoupper($coin) . '.', 'admin/crypto.php');
            flash('success', 'Withdrawal of ' . coin_amount($amount) . ' ' . strtoupper($coin) . ' requested. We check and send crypto withdrawals within 24 hours.');
        }
        if ($action === 'refresh') {
            crypto_sync($uid);
            $_SESSION['crypto_synced'] = time();
            unset($_SESSION['crypto_usd']);
            flash('success', 'Your crypto savings are up to date.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage() . (is_super($u) && np_last_error() !== '' ? ' [Admin: ' . np_last_error() . ']' : ''));
    }
    redirect($back);
}

$on = crypto_on();
$problems = [];        // what went wrong, in customer words
$adminDetail = [];     // NOWPayments' own answers, for super admins
$wallet = '';
$balances = [];
$balanceOk = true;
$coins = [];
if ($on) {
    // Each step on its own, so one failing call never hides the rest of the page.
    try {
        $coins = np_coins();
    } catch (Throwable $e) {
        $coins = [];
    }
    if (!$coins) {
        $problems[] = 'The list of coins could not be loaded.';
        $adminDetail[] = np_last_error() ?: 'No coins are switched on in NOWPayments → Settings → Coins.';
    }
    try {
        $wallet = np_wallet_id($uid);
    } catch (UserError $e) {
        $problems[] = 'Your crypto wallet could not be opened yet.';
        $adminDetail[] = np_last_error() ?: $e->getMessage();
    }
    if ($wallet !== '') {
        try {
            if (time() - (int)($_SESSION['crypto_synced'] ?? 0) > 120) {   // catch deposits a webhook may have missed
                crypto_sync($uid);
                $_SESSION['crypto_synced'] = time();
            }
        } catch (Throwable $e) {
            // syncing is a bonus; the webhook still records deposits
        }
        try {
            $balances = crypto_balances($uid);
        } catch (UserError $e) {
            $balanceOk = false;
            $problems[] = 'Your balance could not be loaded just now. You can still get your address and deposit.';
            $adminDetail[] = np_last_error();
        }
    }
}
if (!$balanceOk) {
    // Show what we know from recorded deposits and withdrawals until NOWPayments answers again.
    foreach (q('SELECT coin, SUM(actually_paid) s FROM crypto_deposits WHERE user_id = ? AND credited = 1 GROUP BY coin', [$uid])->fetchAll() as $r) {
        $balances[$r['coin']] = [(float)$r['s'], 0.0];
    }
    foreach (q('SELECT coin, SUM(amount) s FROM crypto_withdrawals WHERE user_id = ? AND status IN ("processing","sent") GROUP BY coin', [$uid])->fetchAll() as $r) {
        if (isset($balances[$r['coin']])) $balances[$r['coin']][0] = max(0, $balances[$r['coin']][0] - (float)$r['s']);
    }
    $balances = array_filter($balances, fn($b) => $b[0] > 0);
}
$adminDetail = array_values(array_unique(array_filter($adminDetail)));
$addresses = q('SELECT * FROM crypto_wallets WHERE user_id = ? ORDER BY funded DESC, id', [$uid])->fetchAll();
$byCoin = array_column($addresses, null, 'coin');
$pick = strtolower((string)($_GET['coin'] ?? ''));
$current = $byCoin[$pick] ?? null;
$deposits = q('SELECT * FROM crypto_deposits WHERE user_id = ? AND status <> "expired" AND NOT (status = "waiting" AND credited = 0) ORDER BY id DESC LIMIT 15', [$uid])->fetchAll();
$withdrawals = q('SELECT * FROM crypto_withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid])->fetchAll();
$totalUsd = 0.0;
foreach ($balances as $code => [$amt]) {
    $totalUsd += $amt * (coin_usd($code) ?? 0);
}
$ngn = $balances ? usd_to_ngn($totalUsd) : null;
$btcPx = $on && $totalUsd > 0 ? coin_usd('btc') : null;
$btcEq = $btcPx ? round($totalUsd / $btcPx, 8) : null;
$statusText = ['waiting' => 'Waiting', 'confirming' => 'On the blockchain', 'confirmed' => 'Confirmed', 'sending' => 'Arriving', 'partially_paid' => 'Received',
    'finished' => 'Received', 'failed' => 'Failed', 'refunded' => 'Refunded', 'expired' => 'Expired'];
$statusBadge = ['waiting' => 'muted', 'confirming' => 'info', 'confirmed' => 'info', 'sending' => 'info', 'partially_paid' => 'ok', 'finished' => 'ok', 'failed' => 'bad', 'refunded' => 'warn', 'expired' => 'muted',
    'pending' => 'warn', 'processing' => 'info', 'sent' => 'ok', 'rejected' => 'bad'];
$coinBadge = function (string $code, int $size = 40) use ($coins): string {
    $logo = $coins ? coin_logo($code) : '';
    $t = strtoupper(substr(preg_replace('/(trc20|erc20|bsc|sol|matic|arb|op|base|ton)$/', '', $code) ?: $code, 0, 4));
    return '<span class="coin-badge" style="width:' . $size . 'px;height:' . $size . 'px">' . ($logo ? '<img src="' . e($logo) . '" alt="" loading="lazy" onerror="this.remove()">' : '') . '<b>' . e($t) . '</b></span>';
};

app_header('Crypto savings', 'crypto', 'app', $on ? 'Your coins, your permanent addresses' : 'Save in dollars and crypto');
?>
<h1 class="mobile-title">Crypto savings</h1>

<?php if (!$on): ?>
  <section class="acct crypto-hero">
    <div class="head"><div class="bank"><span class="b-ic"><?= icon('coin', 22) ?></span><div><strong>Crypto savings</strong><span>Coming to <?= e(APP_NAME) ?></span></div></div><span class="soon-pill">Coming soon</span></div>
    <div class="big" style="margin:1.4rem 0 .4rem">Save in USDT, Bitcoin &amp; more</div>
    <p style="color:rgba(255,255,255,.82);max-width:36rem;margin:0">Protect your savings from naira swings. You'll get your own wallet ID and a permanent address for every coin: copy it once, fund it any time from any wallet or exchange.</p>
    <div class="coin-row">
      <?php foreach (['USDT', 'BTC', 'ETH', 'USDC', 'SOL', 'BNB', 'TRX'] as $t): ?><span class="coin-chip"><?= $t ?></span><?php endforeach; ?>
    </div>
  </section>
  <div class="grid grid-3">
    <div class="card feature"><span class="sq sq-green"><?= icon('wallet', 22) ?></span><h3>Your own wallet ID</h3><p class="muted small">Opened for you automatically. It never changes.</p></div>
    <div class="card feature"><span class="sq sq-gold"><?= icon('map', 22) ?></span><h3>Permanent addresses</h3><p class="muted small">One address per coin, with a QR code. Save it in your exchange app and send any time.</p></div>
    <div class="card feature"><span class="sq sq-teal"><?= icon('shield', 22) ?></span><h3>PIN-protected withdrawals</h3><p class="muted small">Every withdrawal needs your PIN and is checked by our team before it's sent.</p></div>
  </div>
  <?php if (is_super($u)): ?>
    <div class="alert alert-info row between" style="margin:0"><span><strong>Admin:</strong> connect NOWPayments to switch this on for customers.</span><a class="btn btn-primary btn-sm" href="<?= url('admin/integrations.php#nowpayments') ?>">Open Integrations</a></div>
  <?php endif; ?>
<?php app_footer(); exit; endif; ?>

<?php if ($problems): ?>
  <div class="alert alert-warn" style="margin:0">
    <?= e(implode(' ', $problems)) ?>
    <?php if (is_super($u) && $adminDetail): $ip = server_public_ip(); ?>
      <div class="small" style="margin-top:.5rem;font-weight:500"><strong>Admin details</strong> (only super admins see this): NOWPayments said <code><?= e(implode(' | ', $adminDetail)) ?></code>.
        <?php if ($ip !== ''): ?>If IP whitelisting is on in NOWPayments (Settings → Whitelist), add this server's address <code><?= e($ip) ?></code>.<?php endif; ?>
        <?php if (IS_VERCEL): ?>This copy runs on Vercel, which has no fixed address, so whitelisted calls can fail here; use <a href="https://lucapetsloan.com/crypto.php">lucapetsloan.com</a>.<?php endif; ?>
        <a href="<?= url('admin/integrations.php#nowpayments') ?>">Run Test connection</a>.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$addresses && $coins): ?>
  <section class="card start-crypto">
    <div class="row" style="gap:.9rem;flex-wrap:nowrap;align-items:flex-start"><span class="sq sq-gold"><?= icon('coin', 22) ?></span>
      <div><h2 style="margin:0">Start saving in crypto</h2><p class="muted small" style="margin:.2rem 0 0">Three steps: choose a coin, copy your address, send from any wallet or exchange (Binance, Bybit, Trust Wallet…).</p></div></div>
    <form method="post" class="row" style="margin-top:1rem;flex-wrap:nowrap">
      <?= csrf_field() ?><input type="hidden" name="action" value="address">
      <select name="coin" required style="flex:1;min-width:0"><?php foreach ($coins as $code => $c): ?><option value="<?= e($code) ?>"><?= e(coin_label($code)) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary"><?= icon('down', 16) ?> Get my address</button>
    </form>
  </section>
<?php endif; ?>

<section class="acct crypto-hero">
  <div class="head">
    <div class="bank"><span class="b-ic"><?= icon('coin', 22) ?></span><div><strong>Crypto savings</strong><span><?= np_mode() === 'sandbox' ? 'Test mode · not real coins' : 'Your digital dollar &amp; coin savings' ?></span></div></div>
    <?php if ($wallet !== ''): ?><button type="button" class="acno wallet-id" data-copy="<?= e($wallet) ?>" title="Copy wallet ID"><small>Wallet ID</small><b><?= e($wallet) ?></b></button><?php endif; ?>
  </div>
  <div class="mid" style="grid-template-columns:1fr auto">
    <div>
      <div class="lbl">Total value</div>
      <div class="big"><span class="amt">$<?= number_format($totalUsd, 2) ?></span><?php if ($btcEq !== null): ?><span class="eq amt">≈ <?= e(coin_amount($btcEq)) ?> BTC</span><?php endif; ?><button type="button" class="eye-btn" data-hide-amounts aria-label="Hide or show amounts"><?= icon('eye', 20) ?></button></div>
      <div class="sub amt"><?= $ngn !== null ? '≈ ' . money0($ngn) . ' · ' : '' ?><?= count($balances) ?> coin<?= count($balances) === 1 ? '' : 's' ?> · no interest is paid on crypto</div>
    </div>
  </div>
  <div class="foot">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn btn-glass btn-sm"><?= icon('clock', 16) ?> Check for deposits</button></form>
    <div class="actions">
      <?php if ($balances): ?><button type="button" class="btn btn-glass" data-open="dlg-cwithdraw"><?= icon('send', 18) ?> Send</button><?php endif; ?>
      <a class="btn btn-white" href="#receive"><?= icon('down', 18) ?> Receive</a>
    </div>
  </div>
</section>

<?php if ($balances): ?>
<section class="coin-grid" aria-label="Your coins">
  <?php foreach ($balances as $code => [$amt, $pend]): $px = coin_usd($code); $c = $coins[$code] ?? null; ?>
    <div class="coin-card">
      <div class="row" style="gap:.7rem;flex-wrap:nowrap"><?= $coinBadge($code) ?><div style="min-width:0"><strong><?= e($c[0] ?? strtoupper($code)) ?></strong><div class="muted small"><?= e(strtoupper($code)) ?><?= $c && $c[1] ? ' · ' . e($c[1]) : '' ?></div></div></div>
      <div class="coin-amt amt"><?= e(coin_amount($amt)) ?> <small><?= e(strtoupper(preg_replace('/(trc20|erc20|bsc|sol|matic|arb|op|base|ton)$/', '', $code) ?: $code)) ?></small><?php if ($px !== null): ?><span class="usd">$<?= number_format($amt * $px, 2) ?></span><?php endif; ?></div>
      <div class="muted small amt"><?= $px !== null ? '1 ' . e(strtoupper($code)) . ' ≈ $' . e($px >= 1 ? number_format($px, 2) : coin_amount($px)) : '' ?><?= $pend > 0 ? ' · ' . e(coin_amount($pend)) . ' arriving' : '' ?></div>
      <div class="row" style="gap:.5rem;margin-top:.4rem">
        <a class="btn btn-ghost btn-sm" href="<?= url('crypto.php?coin=' . urlencode($code)) ?>#receive"><?= icon('down', 14) ?> Receive</a>
        <button type="button" class="btn btn-ghost btn-sm" data-open="dlg-cwithdraw" data-coin="<?= e($code) ?>"><?= icon('send', 14) ?> Send</button>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="market" id="market" data-src="<?= url('crypto_market.php') ?>" aria-label="Live crypto prices">
  <?php if ($balances): ?>
  <div class="card mk-value">
    <div class="card-head"><h2><?= icon('chart', 18) ?> Your crypto value</h2><span class="live-pill"><i></i> Live</span></div>
    <div class="mk-now"><span class="mk-big amt" data-mk="now">—</span><span class="mk-chg" data-mk="chg"></span></div>
    <div class="muted small" style="margin-bottom:.6rem">Last 7 days, at today's holdings</div>
    <div class="mk-chart" data-mk="chart" role="img" aria-label="Your crypto value over the last 7 days"><div class="mk-skel"></div></div>
    <details class="mk-table"><summary class="small">Show as table</summary><div data-mk="table"></div></details>
  </div>
  <?php endif; ?>
  <div class="card mk-list">
    <div class="card-head"><h2><?= icon('coin', 18) ?> Live market</h2><span class="live-pill"><i></i> <span data-mk="time">Live</span></span></div>
    <ul class="list" data-mk="coins"><li class="muted small">Loading prices…</li></ul>
    <p class="small muted" style="margin:.6rem 0 0">Prices in US dollars · 24-hour change · 7-day trend · updates every minute.</p>
  </div>
</section>

<div class="dash">
  <div class="col">
    <section class="card" id="receive">
      <div class="card-head"><h2><?= icon('down', 18) ?> Receive crypto</h2><?php if ($current): ?><span class="badge badge-<?= $current['funded'] ? 'ok' : 'info' ?>"><?= $current['funded'] ? 'Permanent address' : 'New address' ?></span><?php endif; ?></div>
      <form method="post" class="row" style="flex-wrap:nowrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="address">
        <select name="coin" required style="flex:1;min-width:0">
          <?php foreach ($coins as $code => $c): ?><option value="<?= e($code) ?>" <?= $code === ($current['coin'] ?? 'usdttrc20') ? 'selected' : '' ?>><?= e(coin_label($code)) ?><?= isset($byCoin[$code]) ? ' ✓' : '' ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary"><?= $current ? 'Show' : 'Get my address' ?></button>
      </form>

      <?php if ($current): $c = $coins[$current['coin']] ?? null; ?>
        <div class="receive">
          <div class="qr-box"><div class="qr" data-qr="<?= e($current['address']) ?>"></div><div class="qr-coin"><?= $coinBadge($current['coin'], 30) ?></div></div>
          <div class="receive-info">
            <div class="muted small">Your <?= e(coin_label($current['coin'])) ?> address</div>
            <div class="addr"><?= e($current['address']) ?></div>
            <div class="row" style="gap:.5rem"><button type="button" class="btn btn-primary btn-sm" data-copy="<?= e($current['address']) ?>"><?= icon('doc', 15) ?> Copy address</button>
              <?php if ($c && $c[1]): ?><span class="badge badge-info"><?= e($c[1]) ?> network</span><?php endif; ?></div>
            <?php if ($current['extra_id']): ?>
              <div class="memo"><span class="muted small"><strong>Memo / tag (required)</strong></span><div class="row" style="gap:.5rem;flex-wrap:nowrap"><code class="addr" style="margin:0"><?= e($current['extra_id']) ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($current['extra_id']) ?>">Copy</button></div></div>
            <?php endif; ?>
            <ul class="tips">
              <li>Send only <strong><?= e(strtoupper($current['coin'])) ?></strong><?= $c && $c[1] ? ' on the <strong>' . e($c[1]) . '</strong> network' : '' ?>. Other coins or networks can be lost.</li>
              <?php if ((float)$current['min_amount'] > 0): ?><li>Smallest deposit: <strong><?= e(coin_amount($current['min_amount'])) ?> <?= e(strtoupper($current['coin'])) ?></strong>.</li><?php endif; ?>
              <?php if ($current['funded']): ?>
                <li>This is your permanent address. Save it and send any time.</li>
              <?php else: ?>
                <li>Becomes your permanent address once your first deposit arrives. Send within <?= e(max(1, (int)ceil((strtotime($current['expires_at']) - time()) / 86400))) ?> day(s); if it isn't used by then we'll give you a fresh one.</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      <?php else: ?>
        <p class="muted small" style="margin:.8rem 0 0">Choose a coin to see your address. You keep the same address for each coin.</p>
      <?php endif; ?>

      <?php if ($addresses): ?>
        <div class="addr-chips">
          <span class="muted small">Your addresses:</span>
          <?php foreach ($addresses as $a): ?><a class="chip <?= $current && $current['coin'] === $a['coin'] ? 'on' : '' ?>" href="<?= url('crypto.php?coin=' . urlencode($a['coin'])) ?>#receive"><?= e(strtoupper($a['coin'])) ?><?= $a['funded'] ? ' ' . icon('check', 13) : '' ?></a><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="col">
    <section class="card">
      <div class="card-head"><h2>Deposits</h2></div>
      <?php if (!$deposits): ?><div class="empty"><?= icon('coin', 32) ?><p>Deposits you make show here.</p></div><?php else: ?>
        <ul class="list">
          <?php foreach ($deposits as $d): ?>
            <li><?= $coinBadge($d['coin'], 34) ?><div class="grow"><div class="title amt">+<?= e(coin_amount((float)$d['actually_paid'] ?: $d['pay_amount'])) ?> <?= e(strtoupper($d['coin'])) ?></div>
              <div class="sub"><?= e(fdatetime($d['updated_at'])) ?></div></div>
              <span class="badge badge-<?= $statusBadge[$d['status']] ?? 'muted' ?>"><?= e($statusText[$d['status']] ?? label($d['status'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($withdrawals): ?>
    <section class="card">
      <div class="card-head"><h2>Withdrawals</h2></div>
      <ul class="list">
        <?php foreach ($withdrawals as $w): ?>
          <li><?= $coinBadge($w['coin'], 34) ?><div class="grow"><div class="title amt">−<?= e(coin_amount($w['amount'])) ?> <?= e(strtoupper($w['coin'])) ?></div>
            <div class="sub">To <?= e(substr($w['address'], 0, 6) . '…' . substr($w['address'], -4)) ?> · <?= e(fdatetime($w['created_at'])) ?><?= $w['status'] === 'rejected' && $w['note'] ? ' · ' . e($w['note']) : '' ?></div></div>
            <span class="badge badge-<?= $statusBadge[$w['status']] ?>"><?= e(label($w['status'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($balances): ?>
<dialog id="dlg-cwithdraw">
  <div class="dlg-head"><h2>Send crypto</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
      <label>Coin<select name="coin"><?php foreach ($balances as $code => [$amt]): ?><option value="<?= e($code) ?>"><?= e(strtoupper($code)) ?> (<?= e(coin_amount(crypto_available($uid, $code, $balances))) ?> available)</option><?php endforeach; ?></select></label>
      <label>Amount<input type="number" name="amount" min="0" step="any" required></label>
      <label>To wallet address <span class="hint">Same coin and network</span><input name="address" required autocomplete="off" style="font-family:monospace"></label>
      <label>Memo / tag <span class="hint">Only if the receiving wallet asks for one</span><input name="extra_id" autocomplete="off"></label>
      <label>PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></label>
      <p class="small muted" style="margin:0">Our team checks every crypto withdrawal and sends it within 24 hours. Network fees come out of the amount sent.</p>
      <button class="btn btn-primary btn-block">Request withdrawal</button>
    </form>
  </div>
</dialog>
<?php endif; ?>

<script>
(function () {
  var box = document.getElementById('market');
  if (!box) return;
  var $ = function (k) { return box.querySelector('[data-mk="' + k + '"]'); };
  var usd = function (v) { return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: v < 1 ? 6 : 2 }); };
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var arrow = function (pct) { return (pct >= 0 ? '▲ +' : '▼ ') + pct.toFixed(2) + '%'; };
  var last = null;

  function spark(values, up) {
    if (!values || values.length < 2) return '';
    var w = 96, h = 30, min = Math.min.apply(null, values), max = Math.max.apply(null, values), r = (max - min) || 1;
    var pts = values.map(function (v, i) { return (i * w / (values.length - 1)).toFixed(1) + ',' + (h - 2 - (v - min) / r * (h - 4)).toFixed(1); }).join(' ');
    return '<svg class="spark ' + (up ? 'up' : 'down') + '" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" aria-hidden="true"><polyline points="' + pts + '" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  function renderCoins(coins) {
    var ul = $('coins');
    if (!coins.length) { ul.innerHTML = '<li class="muted small">Prices are unavailable right now.</li>'; return; }
    ul.innerHTML = coins.map(function (c) {
      var up = c.change >= 0;
      return '<li class="mk-row"><span class="coin-badge" style="width:34px;height:34px"><b>' + esc(c.symbol.slice(0, 4)) + '</b></span>'
        + '<div class="grow"><div class="title">' + esc(c.name) + (c.held > 0 ? ' <span class="badge badge-ok">You hold</span>' : '') + '</div><div class="sub">' + esc(c.symbol) + '</div></div>'
        + spark(c.spark, up)
        + '<div class="mk-px"><b>' + usd(c.price) + '</b><span class="mk-c ' + (up ? 'up' : 'down') + '">' + arrow(c.change) + '</span></div></li>';
    }).join('');
  }

  function renderValue(pts, hours) {
    var el = $('chart');
    if (!el) return;
    if (!pts || pts.length < 2) { el.innerHTML = '<p class="muted small" style="margin:1rem 0">The chart appears once prices load.</p>'; return; }
    var now = pts[pts.length - 1], first = pts[0], diff = now - first, pct = first ? diff / first * 100 : 0, up = diff >= 0;
    $('now').textContent = usd(now);
    $('chg').className = 'mk-chg ' + (up ? 'up' : 'down');
    $('chg').textContent = (up ? '▲ +' : '▼ −') + usd(Math.abs(diff)).slice(0) + ' (' + (up ? '+' : '') + pct.toFixed(2) + '%) this week';
    var step = hours / (pts.length - 1), t0 = Date.now() - hours * 3600e3;
    var when = function (i) { return new Date(t0 + i * step * 3600e3); };
    var fmt = function (d) { return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); };

    var W = Math.max(260, el.clientWidth), H = 190, L = 52, R = 8, T = 10, B = 24;
    var min = Math.min.apply(null, pts), max = Math.max.apply(null, pts);
    if (max - min < max * 0.002) { var pad = Math.max(max * 0.01, 0.01); min -= pad; max += pad; }
    var X = function (i) { return L + i * (W - L - R) / (pts.length - 1); };
    var Y = function (v) { return T + (max - v) / (max - min) * (H - T - B); };
    var line = pts.map(function (v, i) { return (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1); }).join(' ');
    var area = line + ' L' + X(pts.length - 1).toFixed(1) + ' ' + (H - B) + ' L' + X(0).toFixed(1) + ' ' + (H - B) + ' Z';
    var grid = '', ticks = [min, (min + max) / 2, max];
    ticks.forEach(function (v) {
      var y = Y(v).toFixed(1);
      grid += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '" class="mk-grid"/><text x="' + (L - 8) + '" y="' + y + '" class="mk-axis" text-anchor="end" dominant-baseline="middle">' + usd(v).replace(/\.\d+$/, '') + '</text>';
    });
    var days = '';
    [0, 2, 4, 7].forEach(function (d) {
      var i = Math.min(pts.length - 1, Math.round(d * 24 / step));
      days += '<text x="' + X(i).toFixed(1) + '" y="' + (H - 6) + '" class="mk-axis" text-anchor="' + (d === 0 ? 'start' : d === 7 ? 'end' : 'middle') + '">' + (d === 7 ? 'Now' : when(i).toLocaleDateString(undefined, { weekday: 'short' })) + '</text>';
    });
    el.innerHTML = '<svg width="' + W + '" height="' + H + '" class="mk-svg ' + (up ? 'up' : 'down') + '">'
      + '<defs><linearGradient id="mkfill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" class="mk-stop1"/><stop offset="1" class="mk-stop2"/></linearGradient></defs>'
      + grid + '<path d="' + area + '" fill="url(#mkfill)"/><path d="' + line + '" class="mk-line"/>' + days
      + '<line class="mk-cross" y1="' + T + '" y2="' + (H - B) + '" style="display:none"/><circle class="mk-dot" r="5" style="display:none"/>'
      + '<rect x="' + L + '" y="0" width="' + (W - L - R) + '" height="' + H + '" fill="transparent" class="mk-hit"/></svg><div class="mk-tip" style="display:none"></div>';
    var svg = el.querySelector('svg'), cross = svg.querySelector('.mk-cross'), dot = svg.querySelector('.mk-dot'), tip = el.querySelector('.mk-tip');
    function show(evt) {
      var r = svg.getBoundingClientRect(), cx = (evt.touches ? evt.touches[0].clientX : evt.clientX) - r.left;
      var i = Math.round((cx - L) / (W - L - R) * (pts.length - 1)); i = Math.max(0, Math.min(pts.length - 1, i));
      var x = X(i), y = Y(pts[i]);
      cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.style.display = '';
      dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.style.display = '';
      tip.innerHTML = '<b>' + usd(pts[i]) + '</b><span>' + fmt(when(i)) + '</span>';
      tip.style.display = '';
      tip.style.left = Math.min(Math.max(x - tip.offsetWidth / 2, 0), W - tip.offsetWidth) + 'px';
      tip.style.top = Math.max(y - tip.offsetHeight - 12, 0) + 'px';
    }
    function hide() { cross.style.display = 'none'; dot.style.display = 'none'; tip.style.display = 'none'; }
    var hit = svg.querySelector('.mk-hit');
    hit.addEventListener('mousemove', show); hit.addEventListener('touchstart', show, { passive: true }); hit.addEventListener('touchmove', show, { passive: true });
    hit.addEventListener('mouseleave', hide); hit.addEventListener('touchend', hide);
    var rows = '';
    for (var k = pts.length - 1; k >= 0; k -= 6) rows += '<tr><td>' + fmt(when(k)) + '</td><td class="num">' + usd(pts[k]) + '</td></tr>';
    $('table').innerHTML = '<div class="table-wrap"><table><thead><tr><th>Time</th><th class="num">Value</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  function load() {
    fetch(box.dataset.src, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (d) {
        last = d;
        renderCoins(d.coins || []);
        renderValue(d.portfolio || [], d.hours || 168);
        $('time').textContent = 'Live · ' + new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
      })
      .catch(function () { $('time').textContent = 'Reconnecting…'; });
  }
  load();
  setInterval(function () { if (!document.hidden) load(); }, 60000);
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { if (last) renderValue(last.portfolio || [], last.hours || 168); }, 200); });
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    if (window.QRCode) new QRCode(el, { text: el.dataset.qr, width: 176, height: 176, colorDark: '#0b4f3c', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
  });
  document.querySelectorAll('[data-coin][data-open="dlg-cwithdraw"]').forEach(function (b) {
    b.addEventListener('click', function () { var s = document.querySelector('#dlg-cwithdraw select[name=coin]'); if (s) s.value = b.dataset.coin; });
  });
</script>
<?php app_footer();
