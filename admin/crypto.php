<?php
/* Crypto withdrawals to send, and recent crypto deposits (staff with the payments permission). */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_staff('payments');

if (is_post()) {
    verify_csrf();
    $wid = (int)post('wid');
    $decision = post('decision');
    try {
        $w = q_one('SELECT w.*, u.full_name, u.id uid FROM crypto_withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$wid]);
        if (!$w) throw new UserError('Request not found.');
        $what = coin_amount($w['amount']) . ' ' . strtoupper($w['coin']);
        $sendNet = coin_amount((float)$w['amount'] - (float)$w['fee']) . ' ' . strtoupper($w['coin']);
        if ($decision === 'auto') {
            $why = crypto_auto_payout($wid, true);   // staff approved: limits don't apply
            flash($why === '' ? 'success' : 'error', $why === '' ? 'Sent automatically: ' . $sendNet . ' is on its way.' : 'Not sent automatically: ' . $why . '.');
            redirect('admin/crypto.php');
        }
        if ($decision === 'check') {
            crypto_refresh_payout($wid);
            flash('info', 'Status updated from NOWPayments.');
            redirect('admin/crypto.php');
        }
        if ($decision === 'take') {
            crypto_take_for_payout($wid, (int)$admin['id']);
            audit('crypto.withdraw_take', 'Crypto withdrawal #' . $wid, $what . ' moved to the main NOWPayments balance');
            flash('success', $what . ' moved from ' . $w['full_name'] . '\'s wallet to your main NOWPayments balance. Now send ' . $sendNet . ' (after the fee) from the NOWPayments dashboard (Payouts) and record the transaction hash here.');
        } elseif ($decision === 'sent') {
            if ($w['status'] !== 'processing') throw new UserError('Move the coins first.');
            $hash = trim((string)post('tx_hash'));
            if (!preg_match('/^[A-Za-z0-9]{20,120}$/', $hash)) throw new UserError('Paste the transaction hash (ID) from the NOWPayments payout.');
            q('UPDATE crypto_withdrawals SET status = "sent", tx_hash = ?, staff_id = ? WHERE id = ?', [$hash, $admin['id'], $wid]);
            notify((int)$w['uid'], 'Crypto sent', $sendNet . ' has been sent to your wallet' . ((float)$w['fee'] > 0 ? ' (' . $what . ' less the ' . coin_amount($w['fee']) . ' withdrawal fee).' : '.'), 'crypto.php', true);
            audit('crypto.withdraw_sent', 'Crypto withdrawal #' . $wid, $what . ' · tx ' . $hash);
            flash('success', 'Marked as sent. ' . $w['full_name'] . ' has been told.');
        } elseif ($decision === 'reject') {
            if ($w['status'] !== 'pending') throw new UserError('Only requests whose coins haven\'t been moved can be declined here.');
            $note = mb_substr(trim((string)post('note')), 0, 255) ?: 'Declined by staff';
            q('UPDATE crypto_withdrawals SET status = "rejected", note = ?, staff_id = ? WHERE id = ?', [$note, $admin['id'], $wid]);
            notify((int)$w['uid'], 'Crypto withdrawal declined', $what . ': ' . $note . '. The coins are still in your crypto savings.', 'crypto.php', true);
            audit('crypto.withdraw_reject', 'Crypto withdrawal #' . $wid, $what . ' · ' . $note);
            flash('success', 'Declined. The coins stay in the customer\'s wallet.');
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/crypto.php');
}

if (crypto_on()) {
    try {
        crypto_refresh_payouts();
    } catch (Throwable $e) {
    }
}
$open = q('SELECT w.*, u.full_name, u.phone, u.np_sub_id FROM crypto_withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status IN ("pending","processing") ORDER BY w.id')->fetchAll();
$done = q('SELECT w.*, u.full_name FROM crypto_withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status IN ("sent","rejected") ORDER BY w.id DESC LIMIT 20')->fetchAll();
$deposits = q('SELECT d.*, u.full_name FROM crypto_deposits d JOIN users u ON u.id = d.user_id WHERE d.status <> "waiting" ORDER BY d.id DESC LIMIT 30')->fetchAll();
$wallets = (int)q_val('SELECT COUNT(*) FROM users WHERE np_sub_id IS NOT NULL');

app_header('Crypto', 'crypto', 'admin');
?>
<h1 class="mobile-title">Crypto savings</h1>
<?php if (!crypto_on()): ?>
  <div class="alert alert-warn">Crypto savings are off: <?= is_super($admin) ? '<a href="' . url('admin/integrations.php#nowpayments') . '">connect NOWPayments</a>' : 'a super admin needs to connect NOWPayments' ?>.</div>
<?php endif; ?>
<p class="muted small"><?= $wallets ?> customer wallet<?= $wallets === 1 ? '' : 's' ?> · NOWPayments <?= np_mode() === 'live' ? '<strong>LIVE</strong>' : 'sandbox (test)' ?>. Coins sit in each customer's NOWPayments account until they withdraw.</p>

<div class="card">
  <h2>Withdrawals to send</h2>
  <?php if (!$open): ?><p class="muted">Nothing waiting.</p><?php else: ?>
  <p class="small muted"><?= crypto_auto_on() ? '<strong>Automatic sending is on</strong> (up to $' . number_format(setting('crypto_auto_daily_usd')) . ' per customer per day; first withdrawal to a new address waits here). Press <strong>Approve &amp; send automatically</strong>, or send manually: ' : 'Automatic sending is off (add the NOWPayments 2FA secret under Integrations). Manual steps: ' ?></p>
  <p class="small muted">1. <strong>Move coins</strong> takes them from the customer's wallet to your main NOWPayments balance. 2. Send them from the NOWPayments dashboard → Payouts to the address shown. 3. Paste the transaction hash and press <strong>Mark sent</strong>.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Amount</th><th>To address</th><th>Asked</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($open as $w): ?>
      <tr>
        <td><?= e($w['full_name']) ?><div class="small muted"><?= e($w['phone']) ?> · wallet <?= e($w['np_sub_id']) ?></div></td>
        <td><strong><?= e(coin_amount($w['amount'])) ?> <?= e(strtoupper($w['coin'])) ?></strong><?php if ((float)$w['fee'] > 0): ?><div class="small">send <strong><?= e(coin_amount((float)$w['amount'] - (float)$w['fee'])) ?></strong> · fee <?= e(coin_amount($w['fee'])) ?></div><?php endif; ?></td>
        <td style="max-width:260px;word-break:break-all;font-family:monospace;font-size:.85em"><?= e($w['address']) ?><?= $w['extra_id'] ? '<div>memo: ' . e($w['extra_id']) . '</div>' : '' ?>
          <button class="btn btn-ghost btn-sm" data-copy="<?= e($w['address']) ?>">Copy</button></td>
        <td class="small"><?= e(fdatetime($w['created_at'])) ?></td>
        <td><?= badge($w['status']) ?></td>
        <td>
          <?php if ($w['status'] === 'processing' && $w['auto']): ?>
            <span class="badge badge-info">Sending automatically</span><div class="small muted">NOWPayments: <?= e($w['payout_status'] ?: 'creating') ?><?= $w['note'] ? ' · ' . e($w['note']) : '' ?></div>
            <form method="post" class="row" style="margin-top:.3rem"><?= csrf_field() ?><input type="hidden" name="wid" value="<?= $w['id'] ?>"><button class="btn btn-ghost btn-sm" name="decision" value="check">Check status</button></form>
          <?php elseif ($w['status'] === 'pending'): ?>
            <?php if (crypto_auto_on()): ?><form method="post" style="margin-bottom:.3rem"><?= csrf_field() ?><input type="hidden" name="wid" value="<?= $w['id'] ?>"><button class="btn btn-primary btn-sm" name="decision" value="auto" data-confirm="Send <?= e(coin_amount((float)$w['amount'] - (float)$w['fee']) . ' ' . strtoupper($w['coin'])) ?> automatically through NOWPayments now?">Approve &amp; send automatically</button></form><?php endif; ?>
            <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="wid" value="<?= $w['id'] ?>">
              <button class="btn btn-primary btn-sm" name="decision" value="take" data-confirm="Move <?= e(coin_amount($w['amount']) . ' ' . strtoupper($w['coin'])) ?> out of this customer's wallet?">Move coins</button>
              <input name="note" placeholder="Reason to decline" style="width:auto"><button class="btn btn-ghost btn-sm" name="decision" value="reject">Decline</button></form>
          <?php else: ?>
            <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="wid" value="<?= $w['id'] ?>">
              <input name="tx_hash" placeholder="Transaction hash" required style="width:auto;font-family:monospace"><button class="btn btn-primary btn-sm" name="decision" value="sent">Mark sent</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h2>Recent deposits</h2>
    <?php if (!$deposits): ?><p class="muted small">None yet.</p><?php else: ?>
    <ul class="list"><?php foreach ($deposits as $d): ?>
      <li><div class="grow"><div class="title"><?= e(coin_amount((float)$d['actually_paid'] ?: $d['pay_amount'])) ?> <?= e(strtoupper($d['coin'])) ?> · <?= e($d['full_name']) ?></div><div class="sub"><?= e(fdatetime($d['updated_at'])) ?> · payment <?= e($d['payment_id']) ?></div></div><?= badge(in_array($d['status'], ['finished', 'partially_paid'], true) ? 'completed' : ($d['status'] === 'failed' ? 'rejected' : 'processing')) ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Handled withdrawals</h2>
    <?php if (!$done): ?><p class="muted small">None yet.</p><?php else: ?>
    <ul class="list"><?php foreach ($done as $w): ?>
      <li><div class="grow"><div class="title"><?= e(coin_amount($w['amount'])) ?> <?= e(strtoupper($w['coin'])) ?> · <?= e($w['full_name']) ?></div><div class="sub"><?= e(fdatetime($w['updated_at'])) ?><?= $w['tx_hash'] ? ' · tx ' . e($w['tx_hash']) : ' · ' . e($w['note']) ?></div></div><?= badge($w['status'] === 'sent' ? 'completed' : 'rejected') ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</div>
<?php app_footer();
