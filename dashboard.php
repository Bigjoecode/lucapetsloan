<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
accrue_user_interest($uid);

$plans = q('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" ORDER BY id', [$uid])->fetchAll();
$totalSavings = array_sum(array_map(fn($p) => (float)$p['balance'], $plans));
$interestEarned = array_sum(array_map(fn($p) => (float)$p['interest_earned'], $plans));
$loan = has_open_loan($uid);
$snap = $loan ? loan_snapshot($loan) : null;
$recent = q('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$uid])->fetchAll();
$kyc = q_one('SELECT visit_date, visit_time, officer_name, officer_phone, rejection_reason FROM kyc WHERE user_id = ?', [$uid]);
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$sum = fn(string $where, array $args) => (float)q_val('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND ' . $where, array_merge([$uid], $args));
$monthIn = $sum('type IN ("deposit","bonus","ajo_payout") AND status = "approved" AND created_at >= ?', [$monthStart]);
$monthOut = $sum('type = "withdrawal" AND status IN ("approved","processing") AND created_at >= ?', [$monthStart]);
$pending = q_one('SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total FROM transactions WHERE user_id = ? AND status IN ("pending","processing")', [$uid]);
$volume = $sum('status = "approved" AND type <> "interest"', []);

$bestStreak = 0;
$savedToday = [];
foreach ($plans as $p) {
    $bestStreak = max($bestStreak, plan_streak((int)$p['id']));
    $savedToday[$p['id']] = isset(plan_contribution_days((int)$p['id'], 1)[$today]);
}
$credit = credit_profile($u);

// Crypto total in dollars (kept for a minute so the dashboard stays quick).
$cryptoUsd = null;
if (crypto_on() && !empty($u['np_sub_id'])) {
    $cached = $_SESSION['crypto_usd'] ?? null;
    if ($cached && $cached[0] > time() - 60) {
        $cryptoUsd = $cached[1];
    } else {
        try {
            $cryptoUsd = 0.0;
            foreach (crypto_balances($uid) as $code => [$amt]) {
                $cryptoUsd += $amt * (coin_usd($code) ?? 0);
            }
            $_SESSION['crypto_usd'] = [time(), $cryptoUsd];
        } catch (Throwable $e) {
            $cryptoUsd = null;
        }
    }
}
$cryptoNgn = $cryptoUsd ? usd_to_ngn($cryptoUsd) : null;
$cryptoBtc = $cryptoUsd && ($px = coin_usd('btc')) ? round($cryptoUsd / $px, 8) : null;
$portfolio = $totalSavings + (float)($cryptoNgn ?? 0);

$firstName = explode(' ', trim($u['full_name']))[0];
$memberNo = str_pad((string)$uid, 8, '0', STR_PAD_LEFT);
$verified = $u['kyc_status'] === 'verified';
$repayHref = $loan && $loan['status'] === 'active' ? url('loan.php?id=' . $loan['id'] . '#repay') : url('loans.php');
$supportDigits = preg_replace('/\D/', '', SUPPORT_PHONE);

app_header('Dashboard', 'dashboard', 'app', 'Welcome back, ' . $firstName);
?>
<h1 class="mobile-title">Hi, <?= e($firstName) ?> 👋</h1>

<?php if ($u['kyc_status'] === 'none'): ?>
  <div class="alert alert-info row between" style="margin:0"><span><strong>Unlock loans:</strong> complete your verification so we can schedule your home visit.</span><a class="btn btn-primary btn-sm" href="<?= url('verification.php') ?>">Start verification</a></div>
<?php elseif ($u['kyc_status'] === 'submitted'): ?>
  <div class="alert alert-info" style="margin:0">Your verification has been submitted. We'll schedule your physical visit shortly.</div>
<?php elseif ($u['kyc_status'] === 'visit_scheduled' && $kyc): ?>
  <div class="alert alert-warn" style="margin:0"><strong>Home visit scheduled:</strong> <?= fdate($kyc['visit_date'], 'l, M j') ?> (<?= e($kyc['visit_time']) ?>) · Officer <?= e($kyc['officer_name']) ?> <?= $kyc['officer_phone'] ? '· ' . e($kyc['officer_phone']) : '' ?>. Please have your original ID ready.</div>
<?php elseif ($u['kyc_status'] === 'rejected'): ?>
  <div class="alert alert-error row between" style="margin:0"><span>Verification was not successful<?= $kyc && $kyc['rejection_reason'] ? ': ' . e($kyc['rejection_reason']) : '' ?>.</span><a class="btn btn-danger btn-sm" href="<?= url('verification.php') ?>">Update details</a></div>
<?php endif; ?>

<section class="tiles" aria-label="Summary">
  <div class="tile">
    <div class="top"><span class="sq sq-green"><?= icon('wallet', 22) ?></span><span class="when">Available</span></div>
    <span class="v amt"><?= money($totalSavings) ?></span><span class="k">Savings balance</span>
  </div>
  <div class="tile">
    <div class="top"><span class="sq sq-teal"><?= icon('down', 22) ?></span><span class="when">This month</span></div>
    <span class="v amt"><?= money($monthIn) ?></span><span class="k">Money in</span>
  </div>
  <div class="tile">
    <div class="top"><span class="sq sq-red"><?= icon('up', 22) ?></span><span class="when">This month</span></div>
    <span class="v amt"><?= money($monthOut) ?></span><span class="k">Withdrawals</span>
  </div>
  <div class="tile">
    <div class="top"><span class="sq sq-gold"><?= icon('chart', 22) ?></span><span class="when">All time</span></div>
    <span class="v amt"><?= money($interestEarned) ?></span><span class="k">Interest earned</span>
  </div>
</section>

<div class="dash">
  <div class="col">
    <section class="acct" aria-label="Your account">
      <div class="head">
        <div class="bank"><span class="b-ic"><?= icon('bank', 20) ?></span><div><strong><?= e(APP_NAME) ?></strong><span>Savings account</span></div></div>
        <div class="acno"><small>Member number</small><b>•••• <?= e(substr($memberNo, -4)) ?></b></div>
      </div>
      <div class="mid">
        <div>
          <div class="lbl">Account holder</div>
          <div class="holder"><?= e($u['full_name']) ?></div>
          <div class="flag"><i></i> Account active</div>
          <div class="flag <?= $verified ? '' : 'warn' ?>"><?= icon('shield', 16) ?> <?= $verified ? 'Verified &amp; secured' : 'Verification ' . e(strtolower(label($u['kyc_status'] === 'none' ? 'not started' : $u['kyc_status']))) ?></div>
        </div>
        <div class="money">
          <div class="lbl">Savings balance</div>
          <div class="big"><span class="amt"><?= money($totalSavings) ?></span><button type="button" class="eye-btn" data-hide-amounts aria-label="Hide or show amounts"><?= icon('eye', 20) ?></button></div>
          <div class="sub"><?= $bestStreak ? '🔥 ' . $bestStreak . '-day saving streak' : 'Earning ' . setting('savings_annual_rate') . '% a year' ?></div>
        </div>
        <div class="side-val">
          <?php if ($cryptoUsd !== null): ?>
            <div class="lbl">Crypto savings</div>
            <div class="val amt">$<?= number_format($cryptoUsd, 2) ?></div>
            <div class="sub amt"><?= $cryptoNgn ? '≈ ' . money0($cryptoNgn) : 'Digital dollars &amp; coins' ?><?= $cryptoBtc ? ' · ' . e(coin_amount($cryptoBtc)) . ' BTC' : '' ?></div>
          <?php elseif ($loan && $loan['status'] === 'active'): ?>
            <div class="lbl">Loan to clear today</div>
            <div class="val amt"><?= money($snap['payoff']) ?></div>
            <div class="rate"><i></i> Due <?= fdate($loan['due_date'], 'M j') ?></div>
          <?php else: ?>
            <div class="lbl">Loan limit</div>
            <div class="val amt"><?= $verified ? money0($credit['limit']) : '—' ?></div>
            <div class="rate"><i></i> <?= $verified ? e($credit['name']) . ' level' : 'Unlocks after verification' ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="foot">
        <div class="total"><small>Total portfolio</small><b class="amt"><?= money($portfolio) ?></b></div>
        <div class="actions">
          <a class="btn btn-glass" href="<?= url('savings.php#withdraw') ?>"><?= icon('send', 18) ?> Withdraw</a>
          <?php if (swap_on()): ?><a class="btn btn-glass" href="<?= url('swap.php') ?>"><?= icon('arrow', 18) ?> Swap</a><?php endif; ?>
          <button type="button" class="btn btn-white" data-open="dlg-deposit"><?= icon('plus', 18) ?> Add money</button>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Quick actions</h2></div>
      <div class="qa">
        <button type="button" data-open="dlg-deposit"><span class="sq sq-green"><?= icon('plus', 24) ?></span>Deposit</button>
        <a href="<?= $repayHref ?>"><span class="sq sq-blue"><?= icon('loan', 24) ?></span><?= $loan && $loan['status'] === 'active' ? 'Repay loan' : 'Get a loan' ?></a>
        <a href="<?= url('crypto.php') ?>"><span class="sq sq-rose"><?= icon('coin', 24) ?></span>Crypto</a>
        <?php if (swap_on()): ?><a href="<?= url('swap.php') ?>"><span class="sq sq-gold"><?= icon('arrow', 24) ?></span>Swap ₦⇄USDT</a><?php endif; ?>
        <a href="<?= url('statement.php') ?>"><span class="sq sq-teal"><?= icon('doc', 24) ?></span>Statement</a>
      </div>
    </section>

    <?php if ($loan): ?>
    <section class="card stack">
      <?php if ($loan['status'] === 'active'): ?>
        <div class="card-head" style="margin:0"><h2><?= icon('loan', 18) ?> Your loan</h2><?= badge($snap['display_status']) ?></div>
        <div class="stat"><span class="k">Amount to clear your loan today</span><span class="v amt"><?= money($snap['payoff']) ?></span>
          <span class="s">Interest so far <strong><?= money($snap['accrued_interest']) ?></strong> · +<?= money($snap['daily_interest']) ?>/day<?= $snap['penalty'] > 0 ? ' · Late fee ' . money($snap['penalty']) : '' ?></span></div>
        <div>
          <div class="progress <?= $snap['is_overdue'] ? 'bad' : '' ?>"><span style="width:<?= $snap['progress'] ?>%"></span></div>
          <div class="row between small muted" style="margin-top:.35rem"><span>Day <?= $snap['days_elapsed'] ?> of <?= $snap['term_days'] ?></span><span>Due <?= fdate($loan['due_date']) ?></span></div>
        </div>
        <a class="btn btn-primary btn-sm" style="justify-self:start" href="<?= url('loan.php?id=' . $loan['id']) ?>"><?= icon('chart', 16) ?> Monitor interest &amp; repay</a>
      <?php else: ?>
        <div class="card-head" style="margin:0"><h2><?= icon('loan', 18) ?> Loan application</h2><?= badge($loan['status']) ?></div>
        <div class="stat"><span class="v amt"><?= money($loan['amount']) ?></span><span class="s"><?= $loan['status'] === 'pending' ? 'Under review. We\'ll notify you once a decision is made.' : 'Approved and waiting to be paid into your bank account.' ?></span></div>
        <a class="btn btn-ghost btn-sm" style="justify-self:start" href="<?= url('loan.php?id=' . $loan['id']) ?>">View application</a>
      <?php endif; ?>
    </section>
    <?php elseif ($verified): ?>
      <?= credit_card_html($credit) ?>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Today's savings</h2><a class="link" href="<?= url('savings.php') ?>">All plans <?= icon('arrow', 14) ?></a></div>
      <?php if (!$plans): ?>
        <div class="empty"><?= icon('piggy', 32) ?><p>No savings plan yet.</p><a class="btn btn-primary btn-sm" href="<?= url('savings.php') ?>">Create a plan</a></div>
      <?php else: ?>
        <ul class="list">
          <?php foreach ($plans as $p): ?>
            <li class="plan-row">
              <span class="tx-ic <?= $savedToday[$p['id']] ? 'tx-in' : 'tx-loan' ?>"><?= icon($savedToday[$p['id']] ? 'check' : 'clock', 18) ?></span>
              <div class="grow"><div class="title"><?= e($p['name']) ?></div><div class="sub"><?= money0($p['daily_amount']) ?>/day · Balance <span class="amt"><?= money($p['balance']) ?></span></div></div>
              <?php if ($savedToday[$p['id']]): ?><span class="badge badge-ok">Saved today</span>
              <?php elseif ($p['status'] === 'active'): ?><button class="btn btn-primary btn-sm" data-open="dlg-deposit" data-plan="<?= $p['id'] ?>" data-amount="<?= (float)$p['daily_amount'] ?>">Pay <?= money0($p['daily_amount']) ?></button>
              <?php else: ?><?= badge($p['status']) ?><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <div class="col">
    <section class="card">
      <div class="card-head"><h2>Recent transactions</h2><a class="link" href="<?= url('transactions.php') ?>">View all</a></div>
      <?php if (!$recent): ?>
        <div class="empty"><?= icon('doc', 34) ?><p>No recent transactions</p></div>
      <?php else: ?>
        <ul class="list"><?php foreach ($recent as $t) echo tx_row($t); ?></ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><?= icon('chart', 18) ?> Account statistics</h2></div>
      <div class="stat-list">
        <div class="stat-row"><span class="sq sq-blue"><?= icon('loan', 20) ?></span><div class="t"><small>Loan limit</small><b class="amt"><?= $verified ? money($credit['limit']) : '—' ?></b><span><?= $verified ? e($credit['name']) . ' level · ' . $credit['on_time'] . ' on-time repayment' . ($credit['on_time'] === 1 ? '' : 's') : 'Complete verification to unlock' ?></span></div><i class="pip" style="background:var(--c-blue)"></i></div>
        <div class="stat-row"><span class="sq sq-gold"><?= icon('clock', 20) ?></span><div class="t"><small>Pending transactions</small><b class="amt"><?= money($pending['total']) ?></b><span><?= (int)$pending['n'] ? (int)$pending['n'] . ' awaiting processing' : 'Nothing waiting' ?></span></div><i class="pip" style="background:var(--c-gold)"></i></div>
        <div class="stat-row"><span class="sq sq-green"><?= icon('chart', 20) ?></span><div class="t"><small>Total volume</small><b class="amt"><?= money($volume) ?></b><span>All-time transactions</span></div><i class="pip" style="background:var(--c-green)"></i></div>
      </div>
      <div class="live-note"><?= icon('clock', 15) ?> Updated each time you open the app</div>
    </section>

    <?php if (setting('referral_bonus') > 0):
        $inviteUrl = absolute_url('register.php?ref=' . referral_code($uid));
        $refStats = q_one('SELECT COUNT(*) invited, COALESCE(SUM(amount),0) earned FROM referrals WHERE referrer_id = ?', [$uid]);
        $shareText = 'I save daily and borrow smart with ' . APP_NAME . '. Join with my link: ' . $inviteUrl;
    ?>
    <section class="card stack">
      <div class="row" style="gap:.8rem;flex-wrap:nowrap"><span class="sq sq-gold"><?= icon('gift', 22) ?></span><div><h2 style="margin:0">Invite friends, earn <?= money0(setting('referral_bonus')) ?></h2><span class="muted small"><?= (int)$refStats['invited'] ?> invited · <?= money0($refStats['earned']) ?> earned</span></div></div>
      <p class="muted small" style="margin:0">You get <?= money0(setting('referral_bonus')) ?> in your savings when a friend you invite saves <?= money0(setting('referral_min_savings')) ?>.</p>
      <div class="row" style="flex-wrap:nowrap"><input readonly value="<?= e($inviteUrl) ?>" onclick="this.select()" style="flex:1;min-width:0"><button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($inviteUrl) ?>">Copy</button></div>
      <a class="btn btn-primary btn-sm" href="https://wa.me/?text=<?= rawurlencode($shareText) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
    </section>
    <?php endif; ?>

    <section class="card help-card">
      <span class="sq sq-teal"><?= icon('headset', 26) ?></span>
      <h2>Need help?</h2>
      <p class="muted small" style="margin:0">Our team is here for savings, loans and visits.</p>
      <div class="pills">
        <div class="pill"><?= icon('clock', 18) ?><div><b>Quick replies</b><span>Mon–Sat</span></div></div>
        <div class="pill"><?= icon('shield', 18) ?><div><b>Never share</b><span>your PIN</span></div></div>
      </div>
      <?php if ($supportDigits !== ''): ?>
        <a class="btn btn-primary" href="https://wa.me/<?= e(ltrim($supportDigits, '0')) ?>" target="_blank" rel="noopener"><?= icon('phone', 18) ?> Chat on WhatsApp</a>
        <a class="small" href="tel:<?= e(preg_replace('/[^\d+]/', '', SUPPORT_PHONE)) ?>">Or call <?= e(SUPPORT_PHONE) ?></a>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php include __DIR__ . '/includes/deposit_dialog.php'; ?>
<?php app_footer();
