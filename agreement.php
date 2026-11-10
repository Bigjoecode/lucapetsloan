<?php
/*
 * The loan agreement and key facts. With ?id= it is the signed agreement for
 * one loan (the customer's own, or any loan for loan staff). Without an id it
 * shows the standard terms for the amount and term being applied for.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/print_layout.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $loan = q_one('SELECT l.*, p.name product, u.full_name, u.phone, u.email FROM loans l JOIN loan_products p ON p.id = l.product_id JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$id]);
    if (!$loan || ((int)$loan['user_id'] !== (int)$u['id'] && !can('loans') && !can('collections'))) {
        http_response_code(404);
        exit('Agreement not found.');
    }
} else {
    $tenure = in_array((int)($_GET['tenure'] ?? 0), loan_tenures(), true) ? (int)$_GET['tenure'] : min(loan_tenures());
    $loan = [
        'id' => null, 'amount' => max(0, (float)($_GET['amount'] ?? 0)) ?: 20000, 'interest_rate' => setting('loan_monthly_rate'),
        'late_fee_rate' => setting('loan_late_fee_rate'), 'tenure_months' => $tenure, 'product' => 'Loan',
        'full_name' => $u['full_name'], 'phone' => $u['phone'], 'email' => $u['email'], 'status' => 'pending',
        'amount_paid' => 0, 'disbursed_at' => null, 'due_date' => null, 'agreement_accepted_at' => null, 'agreement_ip' => null, 'applied_at' => null,
    ];
}
$kyc = q_one('SELECT * FROM kyc WHERE user_id = ?', [$loan['user_id'] ?? $u['id']]);
$P = (float)$loan['amount'];
$days = (int)$loan['tenure_months'] * DAYS_PER_MONTH;
$interest = round($P * (float)$loan['interest_rate'] / 100 * (int)$loan['tenure_months'], 2);
$total = $P + $interest;
$apr = (float)$loan['interest_rate'] * 365 / DAYS_PER_MONTH;   // simple yearly equivalent
$dueText = $loan['due_date'] ? fdate($loan['due_date']) : $days . ' days after the money reaches your account';

print_head('Loan agreement');
print_bar($id ? ((int)$loan['user_id'] === (int)$u['id'] ? 'loan.php?id=' . $id : 'admin/loans.php?id=' . $id) : 'loans.php#apply');
?>
<div class="sheet">
  <header class="doc">
    <div><h1>Loan agreement &amp; key facts</h1><div class="muted"><?= $id ? 'Loan #' . $id . ' · ' . e($loan['product']) : 'Standard terms for your application' ?></div></div>
    <div class="muted" style="text-align:right"><strong style="color:var(--ink)"><?= e(APP_NAME) ?></strong><br><?= e(SUPPORT_PHONE) ?><br><?= e(SUPPORT_EMAIL) ?></div>
  </header>

  <h2>1. Key facts</h2>
  <table class="facts">
    <tr><td>Borrower</td><td><strong><?= e($loan['full_name']) ?></strong> · <?= e($loan['phone']) ?></td></tr>
    <tr><td>Amount borrowed</td><td><strong><?= money($P) ?></strong></td></tr>
    <tr><td>Term</td><td><?= $days ?> days</td></tr>
    <tr><td>Interest</td><td><?= (float)$loan['interest_rate'] ?>% per 30 days, added daily (about <?= number_format($apr, 1) ?>% a year)</td></tr>
    <tr><td>Total interest if repaid on the due date</td><td><?= money($interest) ?></td></tr>
    <tr><td>Total to repay</td><td><strong><?= money($total) ?></strong> by <?= e($dueText) ?><?= (int)$loan['tenure_months'] > 1 ? ' (' . money($total / (int)$loan['tenure_months']) . ' every 30 days)' : '' ?></td></tr>
    <tr><td>Paying early</td><td>Allowed at any time with no fee; you pay only the interest built up so far</td></tr>
    <tr><td>Late payment</td><td><?= (float)$loan['late_fee_rate'] ?>% of the unpaid balance for each day after the due date</td></tr>
    <tr><td>Other fees</td><td>None</td></tr>
    <?php if ($kyc): ?><tr><td>Paid to</td><td><?= e($kyc['bank_name'] . ' · ' . $kyc['account_number'] . ' · ' . $kyc['account_name']) ?></td></tr><?php endif; ?>
  </table>

  <h2>2. Terms</h2>
  <ol>
    <li>The lender will pay the amount above into the borrower's bank account after approval. Interest starts on the day the money is paid.</li>
    <li>Interest builds up daily at the rate above until the loan is repaid or the term ends. If the loan is repaid early, only the interest built up to that day is charged.</li>
    <li>Repayments can be made in the app (card, bank transfer, USSD, from savings) or to an authorised agent with a receipt. Automatic repayment from a saved card happens only if the borrower turns it on, and can be turned off at any time.</li>
    <li>If any amount is unpaid after the due date, a late charge of <?= (float)$loan['late_fee_rate'] ?>% of the unpaid balance is added each day. The lender may waive some or all late charges.</li>
    <li>The borrower confirms the information given during verification and the home visit is true. The lender may decline or cancel a loan that relied on false information.</li>
    <li>To collect an overdue loan the lender will contact only the borrower and the guarantor named in the verification, between 8am and 6pm, and will never contact the borrower's other contacts or use threats or insults. Repayment history may be shared with licensed credit bureaus.</li>
    <li>The borrower's personal data is used only to provide the loan and savings services, verify identity and meet legal duties, and is protected under the Nigeria Data Protection Act.</li>
    <li>Questions or complaints: call <?= e(SUPPORT_PHONE) ?> or email <?= e(SUPPORT_EMAIL) ?>. If not resolved, the borrower may contact the Federal Competition and Consumer Protection Commission (FCCPC).</li>
  </ol>

  <h2>3. Acceptance</h2>
  <div class="box">
    <?php if ($loan['agreement_accepted_at']): ?>
      Accepted electronically by <strong><?= e($loan['full_name']) ?></strong> on <?= fdatetime($loan['agreement_accepted_at']) ?><?= $loan['agreement_ip'] ? ' from ' . e($loan['agreement_ip']) : '' ?>.
    <?php elseif ($id): ?>
      This application was made before electronic acceptance was recorded (applied <?= fdatetime($loan['applied_at']) ?>).
    <?php else: ?>
      You accept these terms by ticking the box on the application form. The final figures are fixed on the day of approval and shown in your signed agreement.
    <?php endif; ?>
  </div>
</div>
</body>
</html>
