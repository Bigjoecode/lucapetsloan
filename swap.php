<?php
/* Swap naira savings ⇄ USDT, as its own page (also in the menu). */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

if (is_post()) {
    verify_csrf();
    try {
        if (post('direction') === 'to_crypto') {
            $got = swap_naira_to_usdt($u, (int)post('plan_id'), (float)post('ngn'), (float)post('rate'), (string)post('pin'));
            flash('success', 'Swapped! ' . coin_amount($got) . ' USDT is now in your crypto savings.');
        } else {
            $got = swap_usdt_to_naira($u, (int)post('plan_id'), (float)post('usdt'), (float)post('rate'), (string)post('pin'));
            flash('success', 'Swapped! ' . money($got) . ' has been added to your savings.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('swap.php');
}

$problem = '';
$swapRates = null;
$swapPlans = [];
$swapUsdt = 0.0;
$swapLeft = 0.0;
if (!swap_on()) {
    $problem = 'Swaps are not available right now.';
} else {
    try {
        np_wallet_id($uid);
        $swapRates = swap_rates();
        if (!$swapRates) {
            $problem = 'We can\'t get a price right now. Please try again in a minute.';
        }
        $swapPlans = q('SELECT * FROM savings_plans WHERE user_id = ? AND status = "active" ORDER BY id', [$uid])->fetchAll();
        $swapUsdt = crypto_available($uid, SWAP_COIN, crypto_balances($uid));
        $swapLeft = max(0, (float)setting('swap_daily_limit_ngn') - swap_today_ngn($uid));
    } catch (UserError $e) {
        $problem = $e->getMessage();
    }
}
$history = q('SELECT * FROM crypto_swaps WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid])->fetchAll();

app_header('Swap', 'swap', 'app', 'Naira ⇄ USDT, instantly');
?>
<h1 class="mobile-title">Swap</h1>

<div class="dash">
  <div class="col">
    <section class="card">
      <div class="card-head"><h2><?= icon('arrow', 18) ?> Swap naira ⇄ USDT</h2><?php if ($swapRates): ?><span class="live-pill"><i></i> Live rate</span><?php endif; ?></div>
      <?php if ($problem !== ''): ?>
        <p class="muted"><?= e($problem) ?></p>
      <?php else: ?>
        <?php include __DIR__ . '/includes/swap_form.php'; ?>
      <?php endif; ?>
    </section>
  </div>
  <div class="col">
    <section class="card">
      <h2>How swaps work</h2>
      <ul class="tips" style="font-size:.92rem">
        <li><strong>Naira → USDT:</strong> money leaves the savings plan you choose and USDT (a digital dollar) lands in your crypto savings.</li>
        <li><strong>USDT → Naira:</strong> USDT leaves your crypto savings and naira lands in your savings plan, ready to save or withdraw.</li>
        <li>You see the exact rate and amount before you confirm with your PIN. Swaps are instant and can't be reversed.</li>
        <li>Up to <?= money0(setting('swap_daily_limit_ngn')) ?> a day. No withdrawal fee applies to swaps.</li>
      </ul>
    </section>
    <section class="card">
      <h2>Your swaps</h2>
      <?php if (!$history): ?><p class="muted small" style="margin:0">Swaps you make show here.</p><?php else: ?>
      <ul class="list"><?php foreach ($history as $h): ?>
        <li><span class="tx-ic <?= $h['direction'] === 'to_naira' ? 'tx-in' : 'tx-out' ?>"><?= icon('arrow', 18) ?></span>
          <div class="grow"><div class="title"><?= $h['direction'] === 'to_crypto' ? money0($h['ngn']) . ' → ' . e(coin_amount($h['coins'])) . ' USDT' : e(coin_amount($h['coins'])) . ' USDT → ' . money0($h['ngn']) ?></div>
          <div class="sub"><?= e(fdatetime($h['created_at'])) ?> · 1 USDT = <?= money($h['rate']) ?></div></div></li>
      <?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php app_footer();
