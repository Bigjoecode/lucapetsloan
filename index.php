<?php
require __DIR__ . '/includes/bootstrap.php';

$products = q('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY min_amount')->fetchAll();
$u = current_user();
$appUrl = $u ? url($u['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php') : url('register.php');

// Sample figures for the illustrative app previews
$demoP = 100000;
$demoRate = $products[1]['interest_rate'] ?? 4;
$demoDaily = $demoP * $demoRate / 100 / DAYS_PER_MONTH;

html_head(APP_TAGLINE, ['landing.css']);
?>
<body class="landing">

<header class="l-nav">
  <a class="brand" href="<?= url('index.php') ?>"><?= brand_mark() ?></a>
  <nav class="l-pill l-links" aria-label="Main">
    <a href="#how">How it works</a>
    <a href="#features">Features</a>
    <a href="#products">Loans</a>
    <a href="#faq">FAQ</a>
  </nav>
  <div class="l-actions">
    <?php if ($u): ?>
      <a class="l-btn l-btn-light" href="<?= $appUrl ?>">Open app</a>
    <?php else: ?>
      <a class="l-btn l-btn-ghost l-hide-sm" href="<?= url('login.php') ?>"><?= icon('user', 16) ?> Sign in</a>
      <a class="l-btn l-btn-light" href="<?= url('register.php') ?>">Get started</a>
    <?php endif; ?>
  </div>
</header>

<main>
  <!-- Hero -->
  <section class="l-hero">
    <div class="l-hero-bg" aria-hidden="true"></div>
    <div class="l-hero-inner">
      <span class="l-chip">Digital daily savings · Loans verified in person</span>
      <h1>Save every day, <span class="dim">borrow when it matters</span></h1>
      <p class="l-lead">Your daily contribution, now on your phone. Once a field officer has verified you at home, you can borrow and watch every naira of interest build up day by day.</p>
      <div class="l-cta-row">
        <a class="l-btn l-btn-cta" href="<?= $appUrl ?>"><?= $u ? 'Go to my dashboard' : 'Start saving free' ?> <span class="l-btn-ic"><?= icon('arrow', 16) ?></span></a>
        <a class="l-btn l-btn-ghost" href="#calculator">Loan calculator</a>
      </div>
      <ul class="l-trust">
        <li><?= icon('shield', 16) ?> ID &amp; BVN checks</li>
        <li><?= icon('map', 16) ?> Home-visit verification</li>
        <li><?= icon('chart', 16) ?> Interest shown live</li>
      </ul>
    </div>
  </section>

  <!-- App preview grid -->
  <section class="l-section" id="how">
    <div class="l-head">
      <div>
        <span class="l-chip l-chip-sm">How it works</span>
        <h2>Four steps, all inside the app</h2>
      </div>
      <a class="l-link" href="<?= url('register.php') ?>">Create an account <?= icon('arrow', 14) ?></a>
    </div>
    <div class="l-grid4">
      <article class="l-card">
        <div class="l-shot l-shot-green">
          <div class="mini">
            <div class="mini-k">Total savings</div>
            <div class="mini-v"><?= money0(48500) ?></div>
            <div class="mini-s">🔥 23-day streak</div>
            <div class="mini-days"><?php for ($i = 0; $i < 21; $i++): ?><i class="<?= $i % 6 === 4 ? '' : 'on' ?>"></i><?php endfor; ?></div>
          </div>
        </div>
        <div class="l-meta"><span><b>01</b> Save daily</span><code>Savings</code></div>
      </article>
      <article class="l-card">
        <div class="l-shot l-shot-amber">
          <div class="mini">
            <div class="mini-k">Verification</div>
            <ul class="mini-checks">
              <li class="ok">ID &amp; BVN</li>
              <li class="ok">Home address</li>
              <li class="ok">Guarantor</li>
              <li class="now">Home visit · Fri 10am</li>
            </ul>
          </div>
        </div>
        <div class="l-meta"><span><b>02</b> Get verified</span><code>KYC / Visit</code></div>
      </article>
      <article class="l-card">
        <div class="l-shot l-shot-blue">
          <div class="mini">
            <div class="mini-k">Interest so far</div>
            <div class="mini-v"><?= money0($demoDaily * 21) ?></div>
            <div class="mini-s">+<?= money0($demoDaily) ?> every day</div>
            <svg class="mini-chart" viewBox="0 0 120 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 38 L120 6" /><path class="fill" d="M0 38 L120 6 L120 40 L0 40Z" /></svg>
          </div>
        </div>
        <div class="l-meta"><span><b>03</b> Borrow &amp; monitor</span><code>Loans</code></div>
      </article>
      <article class="l-card">
        <div class="l-shot l-shot-violet">
          <div class="mini">
            <div class="mini-k">Clear your loan today</div>
            <div class="mini-v"><?= money0(82800) ?></div>
            <div class="mini-bar"><span style="width:62%"></span></div>
            <div class="mini-pill">Pay from savings · instant</div>
          </div>
        </div>
        <div class="l-meta"><span><b>04</b> Repay your way</span><code>Repayments</code></div>
      </article>
    </div>
  </section>

  <!-- Bento features -->
  <section class="l-section" id="features">
    <div class="l-head">
      <div>
        <span class="l-chip l-chip-sm">Before you ask</span>
        <h2>Built for trust, not surprises</h2>
      </div>
    </div>
    <div class="l-bento">
      <article class="l-tile">
        <div class="l-tile-copy">
          <span class="l-ic"><?= icon('chart', 18) ?></span>
          <div>
            <h3>Interest you can actually see</h3>
            <p>Simple monthly rates, added daily. Pay early and you only pay the interest built up so far.</p>
          </div>
        </div>
        <div class="l-panel">
          <div class="rings">
            <div class="ring" style="--p:<?= min(100, round(21 / 90 * 100)) ?>"><b>21</b><small>day of 90</small></div>
            <div class="ring ring-gold" style="--p:<?= round($demoRate * 10) ?>"><b><?= (float)$demoRate ?>%</b><small>per month</small></div>
          </div>
          <div class="stats">
            <div><b><?= money0($demoDaily) ?></b><small>per day</small></div>
            <div><b><?= money0($demoP * $demoRate / 100 * 3) ?></b><small>max interest</small></div>
            <div><b>0</b><small>hidden fees</small></div>
          </div>
        </div>
      </article>

      <article class="l-tile">
        <div class="l-tile-copy">
          <span class="l-ic"><?= icon('shield', 18) ?></span>
          <div>
            <h3>Verified in person</h3>
            <p>Every borrower is checked by a field officer at their home or shop before a loan is approved.</p>
          </div>
        </div>
        <div class="l-panel l-panel-center">
          <div class="big-ring"><?= icon('check', 30) ?></div>
          <div class="ticks">
            <?php foreach (['Government ID', 'BVN', 'Passport photo', 'Utility bill', 'Home address', 'GPS location', 'Next of kin', 'Guarantor', 'Officer visit'] as $t): ?>
              <span><?= icon('check', 12) ?> <?= e($t) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </article>

      <article class="l-tile">
        <div class="l-tile-copy">
          <span class="l-ic"><?= icon('piggy', 18) ?></span>
          <div>
            <h3>Daily savings, digitally</h3>
            <p>Pay in by transfer, card, USSD or a field agent. Watch your streak grow and hit your targets.</p>
          </div>
        </div>
        <div class="l-panel">
          <div class="cal">
            <?php for ($i = 0; $i < 35; $i++): ?><i class="<?= in_array($i % 7, [6], true) || $i > 30 ? '' : 'on' ?> <?= $i === 30 ? 'today' : '' ?>"></i><?php endfor; ?>
          </div>
          <div class="stats">
            <div><b>28</b><small>days saved</small></div>
            <div><b><?= money0(1500) ?></b><small>per day</small></div>
            <div><b>78%</b><small>of target</small></div>
          </div>
        </div>
      </article>

      <article class="l-tile">
        <div class="l-tile-copy">
          <span class="l-ic"><?= icon('loan', 18) ?></span>
          <div>
            <h3>Loans that grow with you</h3>
            <p>Start small, repay on time, and move up to bigger loans as your savings history builds.</p>
          </div>
        </div>
        <div class="l-panel l-tree">
          <div class="tree-h"><?= icon('list', 14) ?> loan_products/</div>
          <?php foreach ($products as $p): ?>
            <div class="tree-row"><span><?= e($p['name']) ?></span><code><?= (float)$p['interest_rate'] ?>%/mo · <?= (int)$p['min_tenure'] ?>–<?= (int)$p['max_tenure'] ?> mo</code></div>
          <?php endforeach; ?>
          <div class="tree-foot"><span>From <?= $products ? money0(min(array_column($products, 'min_amount'))) : '—' ?></span><span>Up to <?= $products ? money0(max(array_column($products, 'max_amount'))) : '—' ?></span></div>
        </div>
      </article>
    </div>
  </section>

  <!-- Products + calculator -->
  <section class="l-section" id="products">
    <div class="l-head">
      <div>
        <span class="l-chip l-chip-sm">Loans</span>
        <h2>Pick a loan, see the cost upfront</h2>
      </div>
    </div>
    <div class="l-split" id="calculator">
      <div class="l-prod-list">
        <?php foreach ($products as $p): ?>
          <article class="l-prod">
            <div>
              <h3><?= e($p['name']) ?></h3>
              <p><?= e($p['description']) ?></p>
              <code><?= money0($p['min_amount']) ?> – <?= money0($p['max_amount']) ?> · <?= (int)$p['min_tenure'] ?>–<?= (int)$p['max_tenure'] ?> months</code>
            </div>
            <div class="l-rate"><?= (float)$p['interest_rate'] ?>%<small>/month</small></div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($products): ?>
      <div class="l-tile l-calc">
        <h3>Estimate your loan</h3>
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
        <div class="l-calc-out" id="calc-out">
          <small>Total to repay</small>
          <span class="big" data-o="total">—</span>
          <dl>
            <dt>Interest rate</dt><dd data-o="rate">—</dd>
            <dt>Total interest</dt><dd data-o="interest">—</dd>
            <dt>Interest per day</dt><dd data-o="daily">—</dd>
            <dt>Monthly installment</dt><dd data-o="monthly">—</dd>
          </dl>
        </div>
        <a class="l-btn l-btn-cta l-btn-block" href="<?= $appUrl ?>">Apply after verification <span class="l-btn-ic"><?= icon('arrow', 16) ?></span></a>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- FAQ -->
  <section class="l-section l-narrow" id="faq">
    <div class="l-head l-head-center">
      <div>
        <span class="l-chip l-chip-sm">FAQ</span>
        <h2>Questions, answered</h2>
      </div>
    </div>
    <div class="l-faq">
      <details>
        <summary>Why do you visit my home before giving a loan?</summary>
        <p>A short in-person visit confirms who you are and where you live or work. It protects you from identity fraud and lets us lend responsibly. You choose a preferred date and time, and the officer's name and phone number appear in your app.</p>
      </details>
      <details>
        <summary>How is interest calculated?</summary>
        <p>Each loan has a monthly rate. The interest is added a little each day from the day the money reaches your account. If you clear the loan early, you only pay the interest built up so far. After the due date, a small daily late fee applies to the unpaid balance.</p>
      </details>
      <details>
        <summary>How do daily savings work?</summary>
        <p>Open a plan with a daily amount, for example <?= money0(500) ?>, then pay in each day by bank transfer, card, USSD or through a field agent. Your balance goes up once the payment is confirmed, and you can withdraw to your bank account.</p>
      </details>
      <details>
        <summary>Can I use my savings to repay my loan?</summary>
        <p>Yes. On the loan page, choose "Pay from savings" and the amount is taken off your loan instantly.</p>
      </details>
      <details>
        <summary>What do I need to sign up?</summary>
        <p>A phone number and email to open an account. To borrow, you'll also need a government ID, your BVN, a utility bill, a guarantor and a next of kin.</p>
      </details>
    </div>
  </section>

  <!-- CTA band -->
  <section class="l-band">
    <div class="l-hero-bg" aria-hidden="true"></div>
    <div class="l-band-inner">
      <h2>Start your saving streak today</h2>
      <p>It's free to open an account. Loans unlock after your home visit.</p>
      <div class="l-cta-row">
        <a class="l-btn l-btn-cta" href="<?= $appUrl ?>"><?= $u ? 'Open the app' : 'Create free account' ?> <span class="l-btn-ic"><?= icon('arrow', 16) ?></span></a>
        <a class="l-btn l-btn-ghost" href="#products">See loan rates</a>
      </div>
    </div>
  </section>
</main>

<footer class="l-foot">
  <div class="l-foot-grid">
    <div>
      <a class="brand" href="<?= url('index.php') ?>"><?= brand_mark() ?></a>
      <p><?= e(APP_TAGLINE) ?> Digital daily savings and loans, verified in person.</p>
    </div>
    <div>
      <h4>Product</h4>
      <a href="#how">How it works</a><a href="#features">Features</a><a href="#products">Loan rates</a><a href="#calculator">Calculator</a>
    </div>
    <div>
      <h4>Account</h4>
      <a href="<?= url('register.php') ?>">Create account</a><a href="<?= url('login.php') ?>">Sign in</a><a href="#faq">FAQ</a>
    </div>
    <div>
      <h4>Contact</h4>
      <a href="tel:<?= e(preg_replace('/\s+/', '', SUPPORT_PHONE)) ?>"><?= e(SUPPORT_PHONE) ?></a><a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a>
    </div>
  </div>
  <div class="l-foot-bottom">
    <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
    <span>All loans are subject to verification and approval.</span>
  </div>
</footer>

<script src="<?= url('assets/js/app.js') ?>?v=2"></script>
</body>
</html>
