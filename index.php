<?php
require __DIR__ . '/includes/bootstrap.php';

$products = q('SELECT * FROM loan_products WHERE is_active = 1 ORDER BY min_amount')->fetchAll();
$u = current_user();
$appUrl = $u ? url(home_for($u)) : url('register.php');
$rate = (float)setting('savings_annual_rate');
$loanRate = (float)setting('loan_monthly_rate');
$minDaily = (int)setting('min_daily_saving');
$wdFee = (float)setting('withdrawal_fee_percent');

html_head('Save daily, borrow smart', ['landing.css']);
?>
<body class="landing">

<header class="lp-nav" id="top">
  <div class="lp-wrap lp-nav-in">
    <a class="brand" href="<?= url('index.php') ?>" aria-label="<?= e(APP_NAME) ?> home"><?= brand_mark() ?></a>
    <nav class="lp-menu" aria-label="Main">
      <div class="lp-drop">
        <button type="button" class="lp-drop-btn" aria-expanded="false">Save <?= icon('down', 14) ?></button>
        <div class="lp-mega">
          <a href="#save"><span class="lp-tile t-mint"><?= icon('piggy', 20) ?></span><span><b>Daily Savings</b><small>Save a little every day, from <?= money0($minDaily) ?></small></span></a>
          <a href="#save"><span class="lp-tile t-gold"><?= icon('calendar', 20) ?></span><span><b>Target Savings</b><small>Rent, school fees, a new shop</small></span></a>
          <a href="#save"><span class="lp-tile t-lilac"><?= icon('users', 20) ?></span><span><b>Group Savings</b><small>Ajo with people you trust</small></span></a>
          <a href="#save"><span class="lp-tile t-sky"><?= icon('coin', 20) ?></span><span><b>Crypto Savings</b><small>Hold USDT, Bitcoin and more</small></span></a>
        </div>
      </div>
      <a href="#borrow">Borrow</a>
      <a href="#security">Security</a>
      <a href="#faq">FAQs</a>
    </nav>
    <div class="lp-actions">
      <?php if ($u): ?>
        <a class="lp-btn lp-btn-green" href="<?= $appUrl ?>">Open the app</a>
      <?php else: ?>
        <a class="lp-signin" href="<?= url('login.php') ?>">Sign in</a>
        <a class="lp-btn lp-btn-green" href="<?= url('register.php') ?>"><span class="lp-lg">Create free account</span><span class="lp-sm">Sign up</span></a>
      <?php endif; ?>
      <button type="button" class="lp-burger" aria-label="Menu" data-lp-menu><?= icon('list', 22) ?></button>
    </div>
  </div>
</header>

<main>
  <!-- Hero -->
  <section class="lp-hero">
    <div class="lp-wrap lp-hero-in">
      <div class="lp-hero-copy">
        <span class="lp-kicker"><?= icon('check', 14) ?> Savings from <?= money0($minDaily) ?> a day · <?= rtrim(rtrim(number_format($rate, 1), '0'), '.') ?>% a year</span>
        <h1>Save daily.<br>Watch it <span class="lp-hl">grow</span>.<br>Borrow when it matters.</h1>
        <p class="lp-lead">The simple way to build your savings one day at a time, earn interest every single day, and get a loan you can afford when life calls.</p>
        <div class="lp-cta">
          <a class="lp-btn lp-btn-green lp-btn-lg" href="<?= $appUrl ?>"><?= $u ? 'Go to my dashboard' : 'Start saving free' ?> <?= icon('arrow', 18) ?></a>
          <button type="button" class="lp-btn lp-btn-soft lp-btn-lg" data-install hidden><?= icon('down', 18) ?> Install the app</button>
          <a class="lp-btn lp-btn-soft lp-btn-lg" href="#calculator" data-install-alt>See what you could save</a>
        </div>
        <ul class="lp-points">
          <li><?= icon('shield', 16) ?> PIN &amp; email code at every sign-in</li>
          <li><?= icon('chart', 16) ?> Interest added daily</li>
          <li><?= icon('wallet', 16) ?> No paperwork to start</li>
        </ul>
      </div>

      <div class="lp-hero-art" aria-hidden="true">
        <div class="lp-blob"></div>
        <div class="lp-phone">
          <div class="lp-screen">
            <div class="ph-top"><span class="ph-logo"><?= logo_svg(22) ?></span><span>Hi, Amaka 👋</span><span class="ph-bell"><?= icon('bell', 14) ?></span></div>
            <div class="ph-card">
              <small>Total savings</small>
              <b><?= money(284250.40) ?></b>
              <span class="ph-up">+<?= money(93.45) ?> interest today</span>
              <div class="ph-row"><span class="ph-chip">Add money</span><span class="ph-chip ph-chip-o">Withdraw</span></div>
            </div>
            <div class="ph-list">
              <div class="ph-item"><span class="ph-ic t-mint"><?= icon('piggy', 14) ?></span><span>Daily Savings<small>🔥 23-day streak</small></span><b><?= money0(184250) ?></b></div>
              <div class="ph-item"><span class="ph-ic t-gold"><?= icon('calendar', 14) ?></span><span>Shop rent<small>64% of target</small></span><b><?= money0(96000) ?></b></div>
              <div class="ph-item"><span class="ph-ic t-sky"><?= icon('coin', 14) ?></span><span>Crypto<small>USDT · BTC</small></span><b>$436</b></div>
            </div>
          </div>
        </div>
        <div class="lp-float f1"><span class="ph-ic t-mint"><?= icon('check', 14) ?></span><span><b>Saved today</b><small><?= money0(1000) ?> · Daily Savings</small></span></div>
        <div class="lp-float f2"><span class="ph-ic t-gold"><?= icon('chart', 14) ?></span><span><b><?= rtrim(rtrim(number_format($rate, 1), '0'), '.') ?>% a year</b><small>added every day</small></span></div>
        <div class="lp-float f3"><span class="ph-ic t-lilac"><?= icon('loan', 14) ?></span><span><b>Loan approved</b><small><?= money0(50000) ?> · 30 days</small></span></div>
      </div>
    </div>
  </section>

  <!-- Products -->
  <section class="lp-sec" id="save">
    <div class="lp-wrap">
      <div class="lp-head">
        <h2>How would you like to grow your money?</h2>
        <p>Pick a way to save. Mix and match as many as you like, all in one app.</p>
      </div>
      <div class="lp-cards">
        <a class="lp-card c-mint" href="<?= $appUrl ?>">
          <span class="lp-tile t-mint"><?= icon('piggy', 24) ?></span>
          <h3>Daily Savings</h3>
          <p>Save from <?= money0($minDaily) ?> a day by card, transfer or USSD. Turn on auto-save and never miss a day.</p>
          <span class="lp-more">Start a daily plan <?= icon('arrow', 14) ?></span>
        </a>
        <a class="lp-card c-gold" href="<?= $appUrl ?>">
          <span class="lp-tile t-gold"><?= icon('calendar', 24) ?></span>
          <h3>Target Savings</h3>
          <p>Name your goal (rent, school fees, new stock) and watch a progress bar fill up as you save towards it.</p>
          <span class="lp-more">Set a target <?= icon('arrow', 14) ?></span>
        </a>
        <a class="lp-card c-lilac" href="<?= $appUrl ?>">
          <span class="lp-tile t-lilac"><?= icon('users', 24) ?></span>
          <h3>Group Savings</h3>
          <p>Ajo, made digital. Save with family or colleagues and collect the pot when it's your turn.</p>
          <span class="lp-more">Start a group <?= icon('arrow', 14) ?></span>
        </a>
        <a class="lp-card c-sky" href="<?= $appUrl ?>">
          <span class="lp-tile t-sky"><?= icon('coin', 24) ?></span>
          <h3>Crypto Savings</h3>
          <p>Keep part of your savings in digital dollars (USDT) or Bitcoin, with your own permanent wallet address.</p>
          <span class="lp-more">Save in crypto <?= icon('arrow', 14) ?></span>
        </a>
      </div>
    </div>
  </section>

  <!-- Savings calculator -->
  <section class="lp-sec lp-sec-tint" id="calculator">
    <div class="lp-wrap lp-split">
      <div>
        <h2>Small amounts, big difference</h2>
        <p class="lp-sub">See how a little every day adds up, with <?= rtrim(rtrim(number_format($rate, 1), '0'), '.') ?>% interest a year added daily on top.</p>
        <div class="lp-calc" data-savecalc data-rate="<?= $rate ?>">
          <div class="lp-calc-q">I can save every day</div>
          <div class="lp-chips" data-group="daily">
            <?php foreach (array_unique([$minDaily, 1000, 2000, 5000]) as $i => $amt): ?>
              <button type="button" class="<?= $amt === 1000 ? 'on' : '' ?>" data-v="<?= $amt ?>"><?= money0($amt) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="lp-calc-q">for</div>
          <div class="lp-chips" data-group="months">
            <?php foreach ([3 => '3 months', 6 => '6 months', 12 => '1 year', 24 => '2 years'] as $m => $t): ?>
              <button type="button" class="<?= $m === 12 ? 'on' : '' ?>" data-v="<?= $m ?>"><?= $t ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="lp-result">
        <small>You'd have about</small>
        <div class="lp-result-big" data-out="total">—</div>
        <div class="lp-result-row"><span>You put in</span><b data-out="put">—</b></div>
        <div class="lp-result-row"><span>Interest earned</span><b class="lp-green" data-out="interest">—</b></div>
        <a class="lp-btn lp-btn-green lp-btn-block" href="<?= $appUrl ?>">Start this plan <?= icon('arrow', 16) ?></a>
        <p class="lp-note">An estimate, assuming you save every day and today's rate stays the same. Rates can change.</p>
      </div>
    </div>
  </section>

  <!-- Goals -->
  <section class="lp-sec">
    <div class="lp-wrap">
      <div class="lp-head">
        <h2>Whatever you're saving for, we've got a plan</h2>
      </div>
      <div class="lp-goals">
        <?php foreach ([['home', 'Next year\'s rent'], ['doc', 'School fees'], ['cash', 'Stock for my shop'], ['shield', 'An emergency fund'], ['gift', 'Festive season'], ['users', 'Ajo with my colleagues']] as [$ic, $t]): ?>
          <a href="<?= $appUrl ?>"><span class="lp-goal-ic"><?= icon($ic, 18) ?></span><?= e($t) ?><?= icon('arrow', 14) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Loans -->
  <section class="lp-sec lp-sec-tint" id="borrow">
    <div class="lp-wrap lp-split lp-split-top">
      <div>
        <span class="lp-kicker"><?= icon('loan', 14) ?> Loans</span>
        <h2>Borrow without the guesswork</h2>
        <p class="lp-sub"><?= $loanRate ?>% per 30 days, for <?= tenure_choices() ?>. You see every naira of interest as it builds up, and paying early means paying less.</p>
        <ol class="lp-steps">
          <li><span>1</span><div><b>Save with us</b><small>Your savings history raises your loan limit.</small></div></li>
          <li><span>2</span><div><b>Get verified</b><small>ID, BVN, NIN, a guarantor and collateral, all in the app.</small></div></li>
          <li><span>3</span><div><b>A quick home visit</b><small>A field officer confirms where you live or work.</small></div></li>
          <li><span>4</span><div><b>Borrow &amp; repay your way</b><small>Money straight to your bank. Repay by card, transfer or from your savings.</small></div></li>
        </ol>
      </div>
      <?php if ($products): ?>
      <div class="lp-result lp-loancalc">
        <h3>Estimate a loan</h3>
        <form class="form" data-calc="#calc-out" onsubmit="return false">
          <label>Loan type
            <select name="product_id">
              <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>" data-rate="<?= $loanRate ?>" data-min="<?= (float)$p['min_amount'] ?>" data-max="<?= (float)$p['max_amount'] ?>" data-tenures="<?= implode(',', loan_tenures()) ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Amount <span class="hint" data-range></span><input type="number" name="amount" value="<?= (float)$products[0]['min_amount'] * 2 ?>" step="500"></label>
          <label>For<select name="tenure_months"></select></label>
        </form>
        <div class="lp-loan-out" id="calc-out">
          <small>Total to repay</small>
          <span class="lp-result-big" data-o="total">—</span>
          <div class="lp-result-row"><span>Interest rate</span><b data-o="rate">—</b></div>
          <div class="lp-result-row"><span>Total interest</span><b data-o="interest">—</b></div>
          <div class="lp-result-row"><span>Interest per day</span><b data-o="daily">—</b></div>
          <div class="lp-result-row"><span>Due every 30 days</span><b data-o="monthly">—</b></div>
        </div>
        <a class="lp-btn lp-btn-green lp-btn-block" href="<?= $appUrl ?>">Get started <?= icon('arrow', 16) ?></a>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Security -->
  <section class="lp-sec" id="security">
    <div class="lp-wrap">
      <div class="lp-secure">
        <div class="lp-secure-copy">
          <span class="lp-shield"><?= icon('shield', 30) ?></span>
          <h2>Your money is protected, every step</h2>
          <p>We built LucaPet Loan so that only you can move your money, and so that you always know what's happening to it.</p>
        </div>
        <ul class="lp-secure-list">
          <li><?= icon('check', 16) ?><div><b>Three locks on your account</b><small>Your password, a 6-digit code sent to your email, then your PIN, at every sign-in.</small></div></li>
          <li><?= icon('check', 16) ?><div><b>Withdrawals only to your own bank</b><small>We check the account name with your bank. Once added, it can't be changed in the app.</small></div></li>
          <li><?= icon('check', 16) ?><div><b>Encrypted from end to end</b><small>Every page is served over a secure, encrypted connection.</small></div></li>
          <li><?= icon('check', 16) ?><div><b>You're told about everything</b><small>An email for every sign-in, deposit, withdrawal and loan update.</small></div></li>
        </ul>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="lp-sec lp-sec-tint" id="faq">
    <div class="lp-wrap lp-narrow">
      <div class="lp-head"><h2>Questions? We've got answers</h2></div>
      <div class="lp-faq">
        <details open>
          <summary>How much do I need to start saving?</summary>
          <p>You can open a daily plan from <?= money0($minDaily) ?> a day. Opening an account is free and takes a couple of minutes: just your name, phone number and email.</p>
        </details>
        <details>
          <summary>How much interest will my savings earn?</summary>
          <p><?= rtrim(rtrim(number_format($rate, 1), '0'), '.') ?>% a year, worked out on your balance and added to it every day, so your interest earns interest too.</p>
        </details>
        <details>
          <summary>Can I withdraw my money anytime?</summary>
          <p>Yes. Add your own bank account once (we check the name with your bank), confirm with your PIN, and the money is sent to you.<?= $wdFee > 0 ? ' A withdrawal fee of ' . rtrim(rtrim(number_format($wdFee, 1), '0'), '.') . '% is taken from the amount sent, and you see it before you confirm.' : '' ?> You don't need to be verified to withdraw your savings.</p>
        </details>
        <details>
          <summary>How do loans work?</summary>
          <p>Loans run for <?= tenure_choices() ?> at <?= $loanRate ?>% interest per 30 days, added a little each day from when the money reaches you. Pay early and you only pay the interest built up so far. Late payments attract <?= (float)setting('loan_late_fee_rate') ?>% of the unpaid balance per day.</p>
        </details>
        <details>
          <summary>Why is there a home visit before a loan?</summary>
          <p>A short visit from one of our field officers confirms who you are and where you live or work. It protects you from identity fraud and lets us lend responsibly. You pick a convenient day, and the officer's name and phone number appear in your app.</p>
        </details>
        <details>
          <summary>What is collateral used for?</summary>
          <p>You keep and use your collateral throughout the loan. It is only retrieved if the interest for the whole loan term goes unpaid, and sold only if the loan still isn't settled after that. You are told in writing before either happens.</p>
        </details>
        <details>
          <summary>How do I contact you?</summary>
          <p>Call or WhatsApp <?= e(SUPPORT_PHONE) ?>, or email <?= e(SUPPORT_EMAIL) ?>.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- Closing CTA -->
  <section class="lp-sec">
    <div class="lp-wrap">
      <div class="lp-band">
        <div>
          <h2>Your future can start with <?= money0($minDaily) ?></h2>
          <p>Open a free account in minutes. Save today, earn interest tonight.</p>
        </div>
        <div class="lp-cta">
          <a class="lp-btn lp-btn-gold lp-btn-lg" href="<?= $appUrl ?>"><?= $u ? 'Open the app' : 'Create free account' ?> <?= icon('arrow', 18) ?></a>
          <button type="button" class="lp-btn lp-btn-glass lp-btn-lg" data-install hidden><?= icon('down', 18) ?> Install the app</button>
        </div>
      </div>
    </div>
  </section>
</main>

<footer class="lp-foot">
  <div class="lp-wrap">
    <div class="lp-foot-grid">
      <div class="lp-foot-brand">
        <a class="brand" href="<?= url('index.php') ?>"><?= brand_mark() ?></a>
        <p>Digital daily savings and fair loans, verified in person. Save a little every day and watch it grow.</p>
        <div class="lp-contact">
          <a href="tel:<?= e(preg_replace('/\s+/', '', SUPPORT_PHONE)) ?>"><?= icon('phone', 16) ?> <?= e(SUPPORT_PHONE) ?></a>
          <a href="https://wa.me/<?= e(preg_replace('/\D/', '', SUPPORT_PHONE)) ?>" target="_blank" rel="noopener"><?= icon('phone', 16) ?> WhatsApp us</a>
          <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= icon('send', 16) ?> <?= e(SUPPORT_EMAIL) ?></a>
        </div>
      </div>
      <div><h4>Save</h4><a href="#save">Daily Savings</a><a href="#save">Target Savings</a><a href="#save">Group Savings</a><a href="#save">Crypto Savings</a><a href="#calculator">Savings calculator</a></div>
      <div><h4>Borrow</h4><a href="#borrow">How loans work</a><a href="#borrow">Loan calculator</a><a href="#faq">Loan FAQs</a></div>
      <div><h4>Account</h4><a href="<?= url('register.php') ?>">Create account</a><a href="<?= url('login.php') ?>">Sign in</a><a href="<?= url('forgot_password.php') ?>">Forgot password</a><a href="#security">Security</a></div>
    </div>
    <div class="lp-legal">
      <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
      <span>Loans are subject to verification and approval. Savings interest rates may change. Crypto values go up and down.</span>
    </div>
  </div>
</footer>

<script>
(function () {
  // Mobile menu and the "Save" dropdown
  var nav = document.querySelector('.lp-nav');
  var burger = document.querySelector('[data-lp-menu]');
  if (burger) burger.addEventListener('click', function () { nav.classList.toggle('open'); });
  document.querySelectorAll('.lp-drop-btn').forEach(function (b) {
    b.addEventListener('click', function (e) { e.stopPropagation(); var d = b.parentNode; var open = d.classList.toggle('open'); b.setAttribute('aria-expanded', open); });
  });
  document.addEventListener('click', function () { document.querySelectorAll('.lp-drop.open').forEach(function (d) { d.classList.remove('open'); }); });
  document.querySelectorAll('.lp-menu a').forEach(function (a) { a.addEventListener('click', function () { nav.classList.remove('open'); }); });
  window.addEventListener('scroll', function () { nav.classList.toggle('scrolled', window.scrollY > 8); }, { passive: true });

  // Savings calculator: daily amount × days, with daily compounding at the yearly rate
  var calc = document.querySelector('[data-savecalc]');
  if (calc) {
    var rate = parseFloat(calc.dataset.rate) / 100 / 365;
    var naira = function (n) { return '₦' + Math.round(n).toLocaleString(); };
    var pick = function (g) { var on = calc.querySelector('[data-group="' + g + '"] .on'); return on ? parseFloat(on.dataset.v) : 0; };
    var run = function () {
      var daily = pick('daily'), days = Math.round(pick('months') * 30.4), bal = 0;
      for (var i = 0; i < days; i++) { bal = bal * (1 + rate) + daily; }
      var put = daily * days;
      document.querySelector('[data-out="total"]').textContent = naira(bal);
      document.querySelector('[data-out="put"]').textContent = naira(put);
      document.querySelector('[data-out="interest"]').textContent = '+' + naira(bal - put);
    };
    calc.querySelectorAll('.lp-chips button').forEach(function (b) {
      b.addEventListener('click', function () { b.parentNode.querySelectorAll('button').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); run(); });
    });
    run();
  }
})();
</script>
<script src="<?= url('assets/js/app.js') ?>?v=9"></script>
</body>
</html>
