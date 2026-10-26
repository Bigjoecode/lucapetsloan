<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];

$plans = q('SELECT * FROM savings_plans WHERE user_id = ? AND status <> "closed" ORDER BY id', [$uid])->fetchAll();
$totalSavings = array_sum(array_map(fn($p) => (float)$p['balance'], $plans));
$loan = has_open_loan($uid);
$snap = $loan ? loan_snapshot($loan) : null;
$recent = q('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 6', [$uid])->fetchAll();
$pendingCount = (int)q_val('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND status = "pending"', [$uid]);
$kyc = q_one('SELECT visit_date, visit_time, officer_name, officer_phone, rejection_reason FROM kyc WHERE user_id = ?', [$uid]);
$today = date('Y-m-d');

$bestStreak = 0;
$savedToday = [];
foreach ($plans as $p) {
    $bestStreak = max($bestStreak, plan_streak((int)$p['id']));
    $days = plan_contribution_days((int)$p['id'], 1);
    $savedToday[$p['id']] = isset($days[$today]);
}

$firstName = explode(' ', $u['full_name'])[0];
app_header('Home', 'dashboard');
?>
<h1 class="mobile-title">Hi, <?= e($firstName) ?> 👋</h1>

<?php if ($u['kyc_status'] === 'none'): ?>
  <div class="alert alert-info row between"><span><strong>Unlock loans:</strong> complete your verification so we can schedule your home visit.</span><a class="btn btn-primary btn-sm" href="<?= url('verification.php') ?>">Start verification</a></div>
<?php elseif ($u['kyc_status'] === 'submitted'): ?>
  <div class="alert alert-info">Your verification has been submitted. We'll schedule your physical visit shortly.</div>
<?php elseif ($u['kyc_status'] === 'visit_scheduled' && $kyc): ?>
  <div class="alert alert-warn"><strong>Home visit scheduled:</strong> <?= fdate($kyc['visit_date'], 'l, M j') ?> (<?= e($kyc['visit_time']) ?>) · Officer <?= e($kyc['officer_name']) ?> <?= $kyc['officer_phone'] ? '· ' . e($kyc['officer_phone']) : '' ?>. Please have your original ID ready.</div>
<?php elseif ($u['kyc_status'] === 'rejected'): ?>
  <div class="alert alert-error row between"><span>Verification was not successful<?= $kyc && $kyc['rejection_reason'] ? ': ' . e($kyc['rejection_reason']) : '' ?>.</span><a class="btn btn-danger btn-sm" href="<?= url('verification.php') ?>">Update details</a></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card hero-card stack">
    <div class="stat">
      <span class="k"><?= icon('piggy', 16) ?> Total savings</span>
      <span class="v"><?= money($totalSavings) ?></span>
      <span class="s"><?php if ($bestStreak): ?>🔥 <?= $bestStreak ?>-day saving streak<?php else: ?>Make your first deposit today<?php endif; ?><?= $pendingCount ? ' · ' . $pendingCount . ' pending' : '' ?></span>
    </div>
    <div class="row">
      <button class="btn btn-accent btn-sm" data-open="dlg-deposit"><?= icon('plus', 16) ?> Save today</button>
      <a class="btn btn-light btn-sm" href="<?= url('savings.php') ?>">My plans</a>
    </div>
  </div>

  <div class="card stack">
    <?php if ($loan && $loan['status'] === 'active'): ?>
      <div class="row between"><span class="stat"><span class="k"><?= icon('loan', 16) ?> Amount to clear your loan today</span></span><?= badge($snap['display_status']) ?></div>
      <div class="stat"><span class="v"><?= money($snap['payoff']) ?></span>
        <span class="s">Interest so far <strong><?= money($snap['accrued_interest']) ?></strong> · +<?= money($snap['daily_interest']) ?>/day<?= $snap['penalty'] > 0 ? ' · Late fee ' . money($snap['penalty']) : '' ?></span></div>
      <div>
        <div class="progress <?= $snap['is_overdue'] ? 'bad' : '' ?>"><span style="width:<?= $snap['progress'] ?>%"></span></div>
        <div class="row between small muted" style="margin-top:.35rem"><span>Day <?= $snap['days_elapsed'] ?> of <?= $snap['term_days'] ?></span><span>Due <?= fdate($loan['due_date']) ?></span></div>
      </div>
      <a class="btn btn-primary btn-sm" href="<?= url('loan.php?id=' . $loan['id']) ?>"><?= icon('chart', 16) ?> Monitor interest &amp; repay</a>
    <?php elseif ($loan): ?>
      <div class="row between"><span class="stat"><span class="k"><?= icon('loan', 16) ?> Loan application</span></span><?= badge($loan['status']) ?></div>
      <div class="stat"><span class="v"><?= money($loan['amount']) ?></span><span class="s"><?= $loan['status'] === 'pending' ? 'Under review. We\'ll notify you once a decision is made.' : 'Approved and waiting to be paid into your bank account.' ?></span></div>
      <a class="btn btn-ghost btn-sm" href="<?= url('loan.php?id=' . $loan['id']) ?>">View application</a>
    <?php else: ?>
      <div class="stat"><span class="k"><?= icon('loan', 16) ?> Loans</span><span class="v">No active loan</span>
        <span class="s"><?= $u['kyc_status'] === 'verified' ? 'You are verified and can apply for a loan now.' : 'Loans unlock after your verification and home visit.' ?></span></div>
      <a class="btn <?= $u['kyc_status'] === 'verified' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="<?= url($u['kyc_status'] === 'verified' ? 'loans.php#apply' : 'verification.php') ?>"><?= $u['kyc_status'] === 'verified' ? 'Apply for a loan' : 'Check verification' ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="quick">
  <button data-open="dlg-deposit"><span class="qi"><?= icon('down') ?></span>Deposit</button>
  <a href="<?= url('savings.php#withdraw') ?>"><span class="qi"><?= icon('up') ?></span>Withdraw</a>
  <a href="<?= url($loan && $loan['status'] === 'active' ? 'loan.php?id=' . $loan['id'] . '#repay' : 'loans.php') ?>"><span class="qi"><?= icon('loan') ?></span><?= $loan && $loan['status'] === 'active' ? 'Repay' : 'Loans' ?></a>
  <a href="<?= url('verification.php') ?>"><span class="qi"><?= icon('shield') ?></span>Verify</a>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2>Today's savings</h2><a class="small" href="<?= url('savings.php') ?>">All plans</a></div>
    <?php if (!$plans): ?>
      <div class="empty"><?= icon('piggy', 32) ?><p>No savings plan yet.</p><a class="btn btn-primary btn-sm" href="<?= url('savings.php') ?>">Create a plan</a></div>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($plans as $p): ?>
          <li>
            <span class="tx-ic <?= $savedToday[$p['id']] ? 'tx-in' : 'tx-loan' ?>"><?= icon($savedToday[$p['id']] ? 'check' : 'clock', 18) ?></span>
            <div class="grow"><div class="title"><?= e($p['name']) ?></div><div class="sub"><?= money0($p['daily_amount']) ?>/day · Balance <?= money($p['balance']) ?></div></div>
            <?php if ($savedToday[$p['id']]): ?><span class="badge badge-ok">Saved today</span>
            <?php elseif ($p['status'] === 'active'): ?><button class="btn btn-primary btn-sm" data-open="dlg-deposit" data-plan="<?= $p['id'] ?>" data-amount="<?= (float)$p['daily_amount'] ?>">Pay <?= money0($p['daily_amount']) ?></button>
            <?php else: ?><?= badge($p['status']) ?><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent activity</h2><a class="small" href="<?= url('transactions.php') ?>">See all</a></div>
    <?php if (!$recent): ?>
      <div class="empty"><?= icon('list', 32) ?><p>No transactions yet.</p></div>
    <?php else: ?>
      <ul class="list"><?php foreach ($recent as $t) echo tx_row($t); ?></ul>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/deposit_dialog.php'; ?>
<?php app_footer();
