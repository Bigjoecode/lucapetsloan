<?php /* The naira ⇄ USDT swap form. Needs $swapRates, $swapPlans, $swapUsdt, $swapLeft. */ ?>
    <div class="swap-tabs" role="tablist">
      <button type="button" class="on" data-swap-tab="to_crypto">Naira → USDT</button>
      <button type="button" data-swap-tab="to_naira">USDT → Naira</button>
    </div>
    <?php if (!$swapPlans): ?><p class="muted">Open a savings plan first; swaps move money between your savings and USDT.</p><?php else: ?>
    <form method="post" class="form" data-swap data-buy="<?= $swapRates['buy'] ?>" data-sell="<?= $swapRates['sell'] ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="swap"><input type="hidden" name="direction" value="to_crypto"><input type="hidden" name="rate" value="<?= $swapRates['buy'] ?>">
      <div class="swap-rate"><span data-swap-ratetext>1 USDT = <?= money($swapRates['buy']) ?></span><small>Rate held while this page is open · updates every few minutes</small></div>
      <label><span data-swap-plan-label>From savings plan</span>
        <select name="plan_id"><?php foreach ($swapPlans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= money($p['balance']) ?>)</option><?php endforeach; ?></select></label>
      <label data-swap-in="to_crypto">You pay (₦)<input type="number" name="ngn" min="1000" step="100" placeholder="e.g. 50000"></label>
      <label data-swap-in="to_naira" hidden>You swap (USDT) <span class="hint"><?= e(coin_amount($swapUsdt)) ?> USDT available</span><input type="number" name="usdt" min="1" step="any" max="<?= $swapUsdt ?>" placeholder="e.g. 20"></label>
      <div class="swap-get"><small>You get</small><b data-swap-out>—</b></div>
      <label>PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required></label>
      <p class="small muted" style="margin:0">Limit: <?= money0($swapLeft) ?> left today. Swaps are instant and can't be reversed. No withdrawal fee applies.</p>
      <button class="btn btn-primary btn-block">Swap now</button>
    </form>
    <?php endif; ?>
<script>
  (function () {
    var f = document.querySelector('[data-swap]');
    if (!f) return;
    var buy = +f.dataset.buy, sell = +f.dataset.sell, dir = 'to_crypto';
    var out = f.querySelector('[data-swap-out]'), rt = f.querySelector('[data-swap-ratetext]');
    var naira = function (v) { return '₦' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    function calc() {
      if (dir === 'to_crypto') { var n = +f.ngn.value; out.textContent = n > 0 ? (Math.floor(n / buy * 1e6) / 1e6) + ' USDT' : '—'; }
      else { var u = +f.usdt.value; out.textContent = u > 0 ? naira(Math.round(u * sell * 100) / 100) : '—'; }
    }
    document.querySelectorAll('[data-swap-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        dir = b.dataset.swapTab;
        document.querySelectorAll('[data-swap-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
        f.direction.value = dir; f.rate.value = dir === 'to_crypto' ? buy : sell;
        rt.textContent = '1 USDT = ' + naira(dir === 'to_crypto' ? buy : sell);
        f.querySelector('[data-swap-plan-label]').textContent = dir === 'to_crypto' ? 'From savings plan' : 'Into savings plan';
        f.querySelectorAll('[data-swap-in]').forEach(function (l) { var on = l.dataset.swapIn === dir; l.hidden = !on; l.querySelector('input').required = on; });
        calc();
      });
    });
    f.ngn.required = true;
    f.addEventListener('input', calc);
  })();
</script>
