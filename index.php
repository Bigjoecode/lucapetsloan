<?php
require __DIR__ . '/includes/bootstrap.php';

$products = q('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY min_amount')->fetchAll();

public_header(APP_TAGLINE);
?>
<section class="landing-hero">
  <div class="container">
    <div>
      <span class="eyebrow"><?= icon('fire', 16) ?> Digital daily savings + loans</span>
      <h1>Save a little <em>every day</em>.<br>Borrow when it matters.</h1>
      <p class="lead">Contribute daily to your savings, the same way you would with a daily collector, but on your phone. Once you've been verified and we've visited your home, you can apply for a loan and watch your interest add up day by day in the app.</p>
      <div class="row" style="margin-top:1.4rem">
        <a class="btn btn-primary" href="<?= url('register.php') ?>">Start saving free <?= icon('arrow', 18) ?></a>
        <a class="btn btn-ghost" href="#calculator">Loan calculator</a>
      </div>
      <p class="muted small" style="margin-top:1rem">No hidden charges · Interest shown live · Physical verification for your safety</p>
    </div>
    <div class="phone-mock" aria-hidden="true">
      <div class="card hero-card stat">
        <span class="k">Total savings</span>
        <span class="v"><?= money0(48500) ?></span>
        <span class="s">🔥 23-day streak · <?= money0(1500) ?>/day</span>
      </div>
      <div class="card">
        <div class="row between"><strong>Trader Boost loan</strong><?= badge('active') ?></div>
        <div class="row between small muted" style="margin:.6rem 0 .3rem"><span>Interest accrued today</span><strong class="tnum" style="color:var(--text)"><?= money(6400) ?></strong></div>
        <div class="progress"><span style="width:42%"></span></div>
        <div class="row between small muted" style="margin-top:.4rem"><span>Day 38 of 90</span><span>Due Dec 30</span></div>
      </div>
      <div class="days"><?php for ($i = 0; $i < 30; $i++): ?><span class="<?= $i % 7 !== 3 ? 'on' : '' ?>"></span><?php endfor; ?></div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>How it works</h2>
    <p class="muted">Four simple steps, all tracked inside the app.</p>
    <div class="how">
      <div class="card"><div class="n">1</div><h3>Create an account</h3><p class="muted small">Sign up with your phone number and email in under a minute.</p></div>
      <div class="card"><div class="n">2</div><h3>Save daily</h3><p class="muted small">Open a savings plan and contribute every day by transfer, card, USSD or a field agent.</p></div>
      <div class="card"><div class="n">3</div><h3>Get verified</h3><p class="muted small">Submit your ID, address, guarantor and next of kin. One of our officers visits your home or shop to confirm.</p></div>
      <div class="card"><div class="n">4</div><h3>Borrow &amp; monitor</h3><p class="muted small">Apply for a loan, receive it in your bank account, and track your interest and repayment every day.</p></div>
    </div>
  </div>
</section>

<section class="section" id="calculator">
  <div class="container grid grid-2" style="align-items:start">
    <div>
      <h2>Loan products</h2>
      <p class="muted">Simple monthly interest that builds up daily. If you repay early, you only pay the interest built up so far.</p>
      <div class="grid" style="margin-top:1rem">
        <?php foreach ($products as $p): ?>
          <div class="card product">
            <div class="row between"><h3 style="margin:0"><?= e($p['name']) ?></h3><span class="rate"><?= (float)$p['interest_rate'] ?>%<small class="muted" style="font-size:.8rem;font-weight:600"> /month</small></span></div>
            <p class="muted small" style="margin:0"><?= e($p['description']) ?></p>
            <div class="small"><strong><?= money0($p['min_amount']) ?> – <?= money0($p['max_amount']) ?></strong> · <?= (int)$p['min_tenure'] ?>–<?= (int)$p['max_tenure'] ?> months</div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($products): ?>
    <div class="card">
      <h2>Estimate your loan</h2>
      <form class="form" data-calc="#calc-out" onsubmit="return false">
        <label>Product
          <select name="product_id">
            <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>" data-rate="<?= (float)$p['interest_rate'] ?>" data-min="<?= (float)$p['min_amount'] ?>" data-max="<?= (float)$p['max_amount'] ?>" data-tmin="<?= (int)$p['min_tenure'] ?>" data-tmax="<?= (int)$p['max_tenure'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Amount <span class="hint" data-range></span><input type="number" name="amount" value="<?= (float)$products[0]['min_amount'] * 2 ?>" step="500"></label>
        <label>Tenure<select name="tenure_months"></select></label>
      </form>
      <div class="calc-out" id="calc-out" style="margin-top:1rem">
        <span class="muted small">Total to repay</span>
        <span class="big tnum" data-o="total">—</span>
        <dl class="kv small" style="margin-top:.5rem">
          <dt>Interest rate</dt><dd data-o="rate">—</dd>
          <dt>Total interest</dt><dd class="tnum" data-o="interest">—</dd>
          <dt>Interest per day</dt><dd class="tnum" data-o="daily">—</dd>
          <dt>Monthly installment</dt><dd class="tnum" data-o="monthly">—</dd>
        </dl>
      </div>
      <a class="btn btn-primary btn-block" style="margin-top:1rem" href="<?= url('register.php') ?>">Get started</a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php public_footer();
