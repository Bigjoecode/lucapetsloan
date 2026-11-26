<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_staff('customers');

if (is_post()) {
    verify_csrf();
    $uid = (int)post('user_id');
    $target = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$target || $uid === (int)$admin['id']) {
        flash('error', 'You cannot change your own account here.');
    } elseif (is_staff($target) && !is_super($admin)) {
        flash('error', 'Only a super admin can change staff accounts.');
    } elseif (post('action') === 'toggle_status') {
        q('UPDATE users SET status = IF(status = "active", "suspended", "active") WHERE id = ?', [$uid]);
        audit($target['status'] === 'active' ? 'customer.suspend' : 'customer.activate', 'User #' . $uid, $target['full_name']);
        flash('success', 'Account status updated.');
    } elseif (post('action') === 'reset_payout') {
        if (!is_super($admin)) {
            flash('error', 'Only a super admin can reset a payout account.');
        } elseif ($target) {
            $old = payout_account($uid);
            q('UPDATE users SET payout_bank_code = NULL, payout_bank_name = NULL, payout_account_number = NULL, payout_account_name = NULL, payout_set_at = NULL WHERE id = ?', [$uid]);
            q('UPDATE kyc SET bank_code = NULL, account_number = "", account_name = "" WHERE user_id = ?', [$uid]);
            audit('customer.payout_reset', 'User #' . $uid, $target['full_name'] . ($old ? ' · was ' . $old['bank_name'] . ' ' . $old['account_number'] : ''));
            notify($uid, 'Payout account removed', 'Our team removed your payout bank account so you can add the correct one. Add it under Savings → Withdraw. If you did not ask for this, call ' . SUPPORT_PHONE . '.', 'savings.php', true);
            flash('success', 'Payout account cleared for ' . $target['full_name'] . '. They can add a new one (checked with their bank and PIN).');
        }
    } elseif (post('action') === 'reset_link') {
        if ($target) {
            audit('customer.reset_link', 'User #' . $uid, $target['full_name']);
            // Emailed to the customer when email is set up; also shown to staff once, to send by WhatsApp after confirming who they are.
            $link = absolute_url('reset_password.php?token=' . create_reset_token($uid, 24 * 60));
            $mailed = send_mail($target['email'], 'Reset your ' . APP_NAME . ' password', email_html('Reset your password',
                'Hi ' . explode(' ', $target['full_name'])[0] . ",

Our team created a password reset link for you. The button below works once, for 24 hours.

If you didn't ask for this, call " . SUPPORT_PHONE . '.', $link, 'Choose a new password'));
            $_SESSION['reset_link'] = ['name' => $target['full_name'], 'phone' => $target['phone'], 'email' => $target['email'], 'mailed' => $mailed, 'url' => $link];
        }
    }
    redirect('admin/users.php?' . http_build_query(['s' => $_GET['s'] ?? '']));
}

$search = trim($_GET['s'] ?? '');
$params = [];
$where = '1=1';
if ($search !== '') {
    $where = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params = array_fill(0, 3, '%' . $search . '%');
}
$rows = q("SELECT u.*,
    (SELECT COALESCE(SUM(balance),0) FROM savings_plans sp WHERE sp.user_id = u.id AND sp.status <> 'closed') savings,
    (SELECT status FROM loans l WHERE l.user_id = u.id ORDER BY l.id DESC LIMIT 1) last_loan
  FROM users u WHERE $where ORDER BY u.id DESC LIMIT 300", $params)->fetchAll();

$resetLink = $_SESSION['reset_link'] ?? null;
unset($_SESSION['reset_link']);

app_header('Customers', 'users', 'admin');
?>
<h1 class="mobile-title">Customers</h1>
<?php if ($resetLink): ?>
  <div class="alert alert-info">
    <strong>Password reset link for <?= e($resetLink['name']) ?></strong> (<?= e($resetLink['phone']) ?>). <?= !empty($resetLink['mailed']) ? 'We emailed it to ' . e($resetLink['email']) . '. You can also send it by WhatsApp.' : 'Send it to them by WhatsApp.' ?> It works once, for 24 hours, and won't be shown again.
    <input readonly value="<?= e($resetLink['url']) ?>" onclick="this.select()" style="margin-top:.5rem;width:100%">
  </div>
<?php endif; ?>
<form class="row" method="get">
  <input name="s" value="<?= e($search) ?>" placeholder="Search name, email or phone" style="max-width:320px">
  <button class="btn btn-ghost btn-sm">Search</button>
</form>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= icon('users', 32) ?><p>No customers found.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Contact</th><th>Verification</th><th class="num">Savings</th><th>Latest loan</th><th>Joined</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['full_name']) ?></strong> <?= is_staff($r) ? '<span class="badge badge-info">' . e(label($r['role'])) . '</span>' : '' ?> <?= $r['status'] === 'suspended' ? badge('suspended') : '' ?></td>
          <td class="small"><?= e($r['phone']) ?><div class="muted"><?= e($r['email']) ?></div></td>
          <td><?php if ($r['role'] === 'customer'): ?><a href="kyc?id=<?= $r['id'] ?>"><?= badge($r['kyc_status']) ?></a><?php if ($r['kyc_status'] !== 'verified' && can('kyc')): ?> <a class="btn btn-ghost btn-sm" href="kyc?id=<?= $r['id'] ?><?= $r['kyc_status'] === 'none' ? '' : '#decide' ?>">Verify</a><?php endif; ?><?php else: ?><span class="muted small">Staff</span><?php endif; ?></td>
          <td class="num"><?= money($r['savings']) ?></td>
          <td><?= $r['last_loan'] ? badge($r['last_loan']) : '<span class="muted small">—</span>' ?></td>
          <td class="muted small"><?= fdate($r['created_at']) ?></td>
          <td class="right">
            <?php if ((int)$r['id'] !== (int)$admin['id'] && (!is_staff($r) || is_super($admin))): ?>
            <form method="post" class="row" style="gap:.3rem;flex-wrap:nowrap;justify-content:flex-end">
              <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $r['id'] ?>">
              <?php if (is_super($admin) && $r['role'] === 'customer' && !empty($r['payout_account_number'])): ?><button class="btn btn-ghost btn-sm" name="action" value="reset_payout" data-confirm="Remove this customer's payout bank account (<?= e($r['payout_bank_name'] . ' ' . $r['payout_account_number']) ?>)? They will be emailed and can add a new one.">Reset bank</button><?php endif; ?>
              <button class="btn btn-ghost btn-sm" name="action" value="toggle_status" data-confirm="<?= $r['status'] === 'active' ? 'Suspend' : 'Reactivate' ?> this account?"><?= $r['status'] === 'active' ? 'Suspend' : 'Activate' ?></button>
              <a class="btn btn-ghost btn-sm" href="<?= url('statement.php?user=' . $r['id']) ?>" target="_blank">Statement</a>
              <button class="btn btn-ghost btn-sm" name="action" value="reset_link" data-confirm="Create a password reset link for this customer? Only send it after confirming it's really them.">Reset link</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php app_footer();
