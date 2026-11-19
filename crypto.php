<?php
/* Crypto savings: the customer's wallet ID, balances per coin, deposit addresses and withdrawals. */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        if (!crypto_on()) throw new UserError('Crypto savings are not available right now.');
        if ($action === 'address') {
            $coin = strtolower(post('coin'));
            $amount = (float)post('amount');
            $dep = crypto_deposit_address($uid, $coin, max(0, $amount));
            redirect('crypto.php?show=' . urlencode($dep['payment_id']) . '#fund');
        }
        if ($action === 'withdraw') {
            $coin = strtolower(post('coin'));
            $amount = (float)post('amount');
            $address = trim((string)post('address'));
            $memo = trim((string)post('extra_id'));
            check_pin($u, (string)post('pin'));
            if ($amount <= 0) throw new UserError('Enter the amount to withdraw.');
            if (!preg_match('/^[A-Za-z0-9:_.\-]{20,120}$/', $address)) throw new UserError('That wallet address doesn\'t look right. Copy it again from the wallet you\'re sending to.');
            $available = crypto_available($uid, $coin, crypto_balances($uid));
            if ($amount > $available + 1e-12) throw new UserError('You can withdraw up to ' . coin_amount($available) . ' ' . strtoupper($coin) . '.');
            q('INSERT INTO crypto_withdrawals (user_id, coin, amount, address, extra_id) VALUES (?,?,?,?,?)', [$uid, $coin, $amount, $address, $memo ?: null]);
            notify_staff('payments', 'Crypto withdrawal to send', $u['full_name'] . ' asked to withdraw ' . coin_amount($amount) . ' ' . strtoupper($coin) . '.', 'admin/crypto.php');
            flash('success', 'Withdrawal of ' . coin_amount($amount) . ' ' . strtoupper($coin) . ' requested. We check and send crypto withdrawals within 24 hours.');
        }
        if ($action === 'refresh') {
            foreach (q('SELECT payment_id FROM crypto_deposits WHERE user_id = ? AND status IN ("waiting","confirming","confirmed","sending") AND created_at > NOW() - INTERVAL 8 DAY', [$uid])->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                crypto_refresh_deposit((string)$pid);
            }
            flash('success', 'Up to date.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('crypto.php');
}

$error = null;
$wallet = '';
$balances = [];
$coins = [];
if (crypto_on()) {
    try {
        $wallet = np_wallet_id($uid);
        $coins = np_coins();
        $balances = crypto_balances($uid);
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}
$show = isset($_GET['show']) ? q_one('SELECT * FROM crypto_deposits WHERE user_id = ? AND payment_id = ?', [$uid, (string)$_GET['show']]) : null;
$deposits = q('SELECT * FROM crypto_deposits WHERE user_id = ? AND (status <> "waiting" OR expires_at > NOW()) ORDER BY id DESC LIMIT 15', [$uid])->fetchAll();
$withdrawals = q('SELECT * FROM crypto_withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid])->fetchAll();
$totalUsd = 0.0;
$priced = true;
foreach ($balances as $code => [$amt, $pend]) {
    $px = coin_usd($code);
    $px === null ? $priced = false : $totalUsd += $amt * $px;
}
$ngn = $balances ? usd_to_ngn($totalUsd) : null;
$statusText = ['waiting' => 'Waiting for coins', 'confirming' => 'On the blockchain', 'confirmed' => 'Confirmed', 'sending' => 'Arriving', 'partially_paid' => 'Received (less than asked)',
    'finished' => 'Received', 'failed' => 'Failed', 'refunded' => 'Refunded', 'expired' => 'Expired'];
$statusBadge = ['waiting' => 'muted', 'confirming' => 'info', 'confirmed' => 'info', 'sending' => 'info', 'partially_paid' => 'ok', 'finished' => 'ok', 'failed' => 'bad', 'refunded' => 'warn', 'expired' => 'muted',
    'pending' => 'warn', 'processing' => 'info', 'sent' => 'ok', 'rejected' => 'bad'];

app_header('Crypto savings', 'crypto');
?>
<h1 class="mobile-title">Crypto savings</h1>

<?php if (!crypto_on()): ?>
  <div class="card"><h2>Coming soon</h2><p class="muted">Saving in crypto isn't switched on yet. Your naira savings are on the <a href="<?= url('savings.php') ?>">Savings</a> page.</p></div>
<?php app_footer(); exit; endif; ?>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card hero-card row between">
  <div class="stat">
    <span class="k">Crypto savings<?= np_mode() === 'sandbox' ? ' · test mode' : '' ?></span>
    <span class="v">$<?= number_format($totalUsd, 2) ?><?= $priced ? '' : '+' ?></span>
    <span class="s"><?= $ngn !== null ? 'About ' . money0($ngn) . ' · ' : '' ?>held for you by NOWPayments · no interest is paid on crypto</span>
  </div>
  <div class="row">
    <button class="btn btn-accent btn-sm" data-open="dlg-fund"><?= icon('plus', 16) ?> Fund</button>
    <?php if ($balances): ?><button class="btn btn-light btn-sm" data-open="dlg-cwithdraw"><?= icon('up', 16) ?> Withdraw</button><?php endif; ?>
  </div>
</div>

<?php if ($wallet !== ''): ?>
<div class="card row between">
  <div><span class="muted small">Your wallet ID</span><div style="font-size:1.15rem;font-weight:700;letter-spacing:.04em"><?= e($wallet) ?></div></div>
  <button class="btn btn-ghost btn-sm" data-copy="<?= e($wallet) ?>">Copy ID</button>
</div>
<?php endif; ?>

<?php if ($show && $show['status'] === 'waiting'): $c = $coins[$show['coin']] ?? null; ?>
<div class="card" id="fund" style="border:2px solid var(--brand, #0b6b4f)">
  <div class="card-head"><h2>Send <?= e(strtoupper($show['coin'])) ?> to this address</h2><?= $c && $c[1] ? '<span class="badge badge-info">' . e($c[1]) . ' network</span>' : '' ?></div>
  <div class="stack">
    <div><span class="muted small">Deposit address</span>
      <div class="row" style="flex-wrap:nowrap"><input readonly value="<?= e($show['pay_address']) ?>" onclick="this.select()" style="font-family:monospace;flex:1;min-width:0"><button class="btn btn-primary btn-sm" data-copy="<?= e($show['pay_address']) ?>">Copy</button></div></div>
    <?php if ($show['extra_id']): ?>
      <div><span class="muted small"><strong>Memo / tag: you must include it</strong></span>
        <div class="row" style="flex-wrap:nowrap"><input readonly value="<?= e($show['extra_id']) ?>" style="font-family:monospace;flex:1;min-width:0"><button class="btn btn-primary btn-sm" data-copy="<?= e($show['extra_id']) ?>">Copy</button></div></div>
    <?php endif; ?>
    <p class="small" style="margin:0">Send at least <strong><?= e(coin_amount($show['pay_amount'])) ?> <?= e(strtoupper($show['coin'])) ?></strong><?= $c && $c[1] ? ' on the <strong>' . e($c[1]) . '</strong> network' : '' ?>. Sending another coin or network can lose the money.</p>
    <p class="small muted" style="margin:0">Use this address before <?= e(fdatetime($show['expires_at'])) ?>. After that, make a new one here. Your balance updates once the blockchain confirms the transfer.</p>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h2>Your coins</h2></div>
    <?php if (!$balances): ?>
      <p class="muted">No crypto yet. Press <strong>Fund</strong>, choose a coin and send it to the address we give you.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($balances as $code => [$amt, $pend]): $px = coin_usd($code); ?>
          <li><span class="tx-ic tx-in"><?= icon('cash', 18) ?></span>
            <div class="grow"><div class="title"><?= e(coin_amount($amt)) ?> <?= e(strtoupper($code)) ?></div>
              <div class="sub"><?= e(coin_label($code)) ?><?= $px !== null ? ' · ≈ $' . number_format($amt * $px, 2) : '' ?><?= $pend > 0 ? ' · ' . e(coin_amount($pend)) . ' arriving' : '' ?></div></div></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Deposits</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn btn-ghost btn-sm">Refresh</button></form></div>
    <?php if (!$deposits): ?><p class="muted small">Deposits you make show here.</p><?php else: ?>
      <ul class="list">
        <?php foreach ($deposits as $d): ?>
          <li><div class="grow"><div class="title"><?= (float)$d['actually_paid'] > 0 ? e(coin_amount($d['actually_paid'])) : e(coin_amount($d['pay_amount'])) ?> <?= e(strtoupper($d['coin'])) ?></div>
            <div class="sub"><?= e(fdatetime($d['created_at'])) ?><?= $d['status'] === 'waiting' ? ' · <a href="' . url('crypto.php?show=' . urlencode($d['payment_id'])) . '#fund">show address</a>' : '' ?></div></div>
            <span class="badge badge-<?= $statusBadge[$d['status']] ?? 'muted' ?>"><?= e($statusText[$d['status']] ?? label($d['status'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php if ($withdrawals): ?>
<div class="card">
  <h2>Withdrawals</h2>
  <ul class="list">
    <?php foreach ($withdrawals as $w): ?>
      <li><div class="grow"><div class="title"><?= e(coin_amount($w['amount'])) ?> <?= e(strtoupper($w['coin'])) ?></div>
        <div class="sub">To <?= e(substr($w['address'], 0, 8) . '…' . substr($w['address'], -6)) ?> · <?= e(fdatetime($w['created_at'])) ?><?= $w['tx_hash'] ? ' · tx ' . e(substr($w['tx_hash'], 0, 10)) . '…' : '' ?><?= $w['status'] === 'rejected' && $w['note'] ? ' · ' . e($w['note']) : '' ?></div></div>
        <span class="badge badge-<?= $statusBadge[$w['status']] ?>"><?= e(label($w['status'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<dialog id="dlg-fund">
  <div class="dlg-head"><h2>Fund crypto savings</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <?php if (!$coins): ?><p class="muted">No coins are available right now. Please try again later.</p><?php else: ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="address">
      <label>Coin<select name="coin" required><?php foreach ($coins as $code => $c): ?><option value="<?= e($code) ?>"><?= e(coin_label($code)) ?></option><?php endforeach; ?></select></label>
      <label>Amount <span class="hint">Optional: in the coin you chose. Blank uses the smallest amount.</span><input type="number" name="amount" min="0" step="any" placeholder="e.g. 20"></label>
      <p class="small muted" style="margin:0">You'll get an address to copy into your wallet or exchange app. Only send the coin and network shown.</p>
      <button class="btn btn-primary btn-block">Get deposit address</button>
    </form>
    <?php endif; ?>
  </div>
</dialog>

<?php if ($balances): ?>
<dialog id="dlg-cwithdraw">
  <div class="dlg-head"><h2>Withdraw crypto</h2><button class="icon-x" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="dlg-body">
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
      <label>Coin<select name="coin"><?php foreach ($balances as $code => [$amt]): ?><option value="<?= e($code) ?>"><?= e(strtoupper($code)) ?> (<?= e(coin_amount(crypto_available($uid, $code, $balances))) ?> available)</option><?php endforeach; ?></select></label>
      <label>Amount<input type="number" name="amount" min="0" step="any" required></label>
      <label>Send to wallet address <span class="hint">Same coin and network</span><input name="address" required autocomplete="off" style="font-family:monospace"></label>
      <label>Memo / tag <span class="hint">Only if the receiving wallet asks for one</span><input name="extra_id" autocomplete="off"></label>
      <?php if (has_pin($u)): ?><label>PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></label><?php endif; ?>
      <p class="small muted" style="margin:0">Staff check every crypto withdrawal and send it within 24 hours. Network fees are taken from the amount sent.</p>
      <button class="btn btn-primary btn-block">Request withdrawal</button>
    </form>
  </div>
</dialog>
<?php endif; ?>
<?php app_footer();
