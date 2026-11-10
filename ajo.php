<?php
/* Group savings (ajo / esusu): friends contribute each round and take turns collecting the pot. */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
$id = (int)($_GET['id'] ?? post('group_id'));

if (is_post()) {
    verify_csrf();
    $action = post('action');
    try {
        if (in_array($action, ['create', 'join'], true) && $u['kyc_status'] !== 'verified') {
            throw new UserError('Complete your verification before joining group savings, so everyone in the group is protected.');
        }
        if ($action === 'create') {
            $amount = round((float)post('contribution'), 2);
            $size = (int)post('size');
            $freq = post('frequency');
            $name = mb_substr(trim(post('name')), 0, 80);
            if ($name === '' || $amount < 500 || $size < 2 || $size > 20 || !isset(AJO_FREQUENCIES[$freq])) {
                throw new UserError('Give the group a name, a contribution of at least ' . money0(500) . ' and 2–20 members.');
            }
            if (!post('consent')) throw new UserError('Please accept the group rules.');
            $code = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789'), 0, 6));
            q('INSERT INTO ajo_groups (name, owner_id, contribution, frequency, size, invite_code) VALUES (?,?,?,?,?,?)', [$name, $uid, $amount, $freq, $size, $code]);
            $id = (int)db()->lastInsertId();
            q('INSERT INTO ajo_members (group_id, user_id) VALUES (?, ?)', [$id, $uid]);
            flash('success', 'Group created. Share the invite code ' . $code . ' with ' . ($size - 1) . ' more people.');
        } elseif ($action === 'join') {
            $g = q_one('SELECT * FROM ajo_groups WHERE invite_code = ?', [strtoupper(trim(post('code')))]);
            if (!$g || $g['status'] !== 'forming') throw new UserError('No open group has that code.');
            if (!post('consent')) throw new UserError('Please accept the group rules.');
            $count = (int)q_val('SELECT COUNT(*) FROM ajo_members WHERE group_id = ?', [$g['id']]);
            if ($count >= (int)$g['size']) throw new UserError('That group is already full.');
            q('INSERT IGNORE INTO ajo_members (group_id, user_id) VALUES (?, ?)', [$g['id'], $uid]);
            notify((int)$g['owner_id'], 'New group member', $u['full_name'] . ' joined "' . $g['name'] . '" (' . ($count + 1) . ' of ' . $g['size'] . ').', 'ajo.php?id=' . $g['id']);
            $id = (int)$g['id'];
            flash('success', 'You joined "' . $g['name'] . '".');
        } else {
            $g = q_one('SELECT * FROM ajo_groups WHERE id = ?', [$id]);
            if (!$g || !q_val('SELECT 1 FROM ajo_members WHERE group_id = ? AND user_id = ?', [$id, $uid])) throw new UserError('Group not found.');
            if ($action === 'start') {
                if ((int)$g['owner_id'] !== $uid || $g['status'] !== 'forming') throw new UserError('Only the group creator can start it.');
                ajo_start($g);
                flash('success', 'The group has started and the payout order has been drawn.');
            } elseif ($action === 'leave') {
                if ($g['status'] !== 'forming') throw new UserError('You can only leave before the group starts.');
                if ((int)$g['owner_id'] === $uid) {
                    q('UPDATE ajo_groups SET status = "cancelled" WHERE id = ?', [$id]);
                    flash('success', 'Group cancelled.');
                } else {
                    q('DELETE FROM ajo_members WHERE group_id = ? AND user_id = ?', [$id, $uid]);
                    flash('success', 'You left the group.');
                }
                redirect('ajo.php');
            } elseif ($action === 'pay') {
                check_pin($u, (string)post('pin'));
                flash('success', ajo_contribute($id, $uid));
            }
        }
    } catch (UserError $e) {
        flash('error', $e->getMessage());
    }
    redirect('ajo.php' . ($id ? '?id=' . $id : ''));
}

$freqName = fn($f) => AJO_FREQUENCIES[$f][0];

/* ---------- One group ---------- */
if ($id) {
    $g = q_one('SELECT * FROM ajo_groups WHERE id = ?', [$id]);
    $members = $g ? ajo_members($id) : [];
    $meRow = array_values(array_filter($members, fn($m) => (int)$m['user_id'] === $uid))[0] ?? null;
    if (!$g || !$meRow) {
        flash('error', 'Group not found.');
        redirect('ajo.php');
    }
    $paidThisRound = q('SELECT user_id FROM ajo_contributions WHERE group_id = ? AND round = ?', [$id, $g['current_round']])->fetchAll(PDO::FETCH_COLUMN);
    $pot = (float)$g['contribution'] * (int)$g['size'];
    app_header($g['name'], 'ajo');
    ?>
    <div class="row between">
      <div><a class="small" href="<?= url('ajo.php') ?>">← Group savings</a><h2 style="margin:.2rem 0 0"><?= e($g['name']) ?></h2>
        <span class="muted small"><?= money0($g['contribution']) ?> <?= strtolower($freqName($g['frequency'])) ?> · <?= $g['size'] ?> members · pot <?= money0($pot) ?></span></div>
      <?= badge($g['status'] === 'forming' ? 'pending' : $g['status']) ?>
    </div>

    <?php if ($g['status'] === 'forming'): ?>
      <div class="card row between">
        <div><h2 style="margin:0">Invite code: <code style="font-size:1.2rem;letter-spacing:.1em"><?= e($g['invite_code']) ?></code></h2>
          <span class="muted small"><?= count($members) ?> of <?= $g['size'] ?> joined. Friends join under Group savings → Join with a code.</span></div>
        <div class="row">
          <a class="btn btn-ghost btn-sm" href="https://wa.me/?text=<?= rawurlencode('Join my group savings "' . $g['name'] . '" on ' . APP_NAME . '. Code: ' . $g['invite_code'] . ' ' . absolute_url('ajo.php')) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= $id ?>">
            <?php if ((int)$g['owner_id'] === $uid): ?><button class="btn btn-primary btn-sm" name="action" value="start" <?= count($members) < (int)$g['size'] ? 'disabled title="Waiting for everyone to join"' : '' ?>>Start group</button><?php endif; ?>
            <button class="btn btn-ghost btn-sm" name="action" value="leave" data-confirm="<?= (int)$g['owner_id'] === $uid ? 'Cancel this group?' : 'Leave this group?' ?>"><?= (int)$g['owner_id'] === $uid ? 'Cancel group' : 'Leave' ?></button>
          </form>
        </div>
      </div>
    <?php elseif ($g['status'] === 'active'): $iPaid = in_array($uid, array_map('intval', $paidThisRound), true); ?>
      <div class="card hero-card row between">
        <div class="stat"><span class="k">Round <?= $g['current_round'] ?> of <?= $g['size'] ?> · due <?= fdate($g['round_due']) ?></span>
          <span class="v"><?= count($paidThisRound) ?>/<?= $g['size'] ?> paid</span>
          <span class="s">This round's pot goes to <?= e(array_values(array_filter($members, fn($m) => (int)$m['position'] === (int)$g['current_round']))[0]['full_name'] ?? '—') ?>. You collect in round <?= (int)$meRow['position'] ?>.</span></div>
        <?php if (!$iPaid): ?>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="group_id" value="<?= $id ?>"><input type="hidden" name="action" value="pay">
            <input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="PIN" required style="width:90px">
            <button class="btn btn-accent btn-sm">Pay <?= money0($g['contribution']) ?> from savings</button></form>
        <?php else: ?><span class="badge badge-ok">You've paid this round</span><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Members &amp; payout order</h2>
      <ul class="list">
        <?php foreach ($members as $m): $paid = in_array((int)$m['user_id'], array_map('intval', $paidThisRound), true); ?>
          <li><span class="avatar"><?= e(initials($m['full_name'])) ?></span>
            <div class="grow"><div class="title"><?= e($m['full_name']) ?><?= (int)$m['user_id'] === $uid ? ' (you)' : '' ?><?= (int)$m['user_id'] === (int)$g['owner_id'] ? ' · creator' : '' ?></div>
              <div class="sub"><?= $m['position'] ? 'Collects in round ' . $m['position'] : 'Order is drawn when the group starts' ?><?= $m['paid_out_at'] ? ' · received ' . money0($pot) . ' on ' . fdate($m['paid_out_at']) : '' ?></div></div>
            <?php if ($g['status'] === 'active'): ?><?= $paid ? badge('paid') : badge('pending') ?><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
    app_footer();
    exit;
}

/* ---------- My groups ---------- */
$groups = q('SELECT g.*, (SELECT COUNT(*) FROM ajo_members x WHERE x.group_id = g.id) members FROM ajo_groups g JOIN ajo_members m ON m.group_id = g.id AND m.user_id = ? WHERE g.status <> "cancelled" ORDER BY g.status = "completed", g.id DESC', [$uid])->fetchAll();
$rules = 'I agree to pay every round on time. On the due date the contribution is taken from my savings automatically if I haven\'t paid, and the order of payouts is drawn at random when the group starts.';
app_header('Group savings', 'ajo');
?>
<h1 class="mobile-title">Group savings</h1>
<div class="card">
  <h2>Save together, collect in turns</h2>
  <p class="muted small" style="margin:0">Like ajo or esusu, but digital: everyone pays the same amount each round, and each round one member collects the whole pot into their savings. No one can run away with the money, because contributions and payouts happen inside the app.</p>
</div>
<?php if ($groups): ?>
  <div class="grid grid-2">
    <?php foreach ($groups as $g): ?>
      <a class="card stack" href="?id=<?= $g['id'] ?>">
        <div class="row between"><h2 style="margin:0"><?= e($g['name']) ?></h2><?= badge($g['status'] === 'forming' ? 'pending' : $g['status']) ?></div>
        <span class="muted small"><?= money0($g['contribution']) ?> <?= strtolower($freqName($g['frequency'])) ?> · <?= $g['members'] ?>/<?= $g['size'] ?> members · pot <?= money0((float)$g['contribution'] * (int)$g['size']) ?><?= $g['status'] === 'active' ? ' · round ' . $g['current_round'] . ' due ' . fdate($g['round_due']) : '' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<div class="grid grid-2" style="align-items:start">
  <div class="card">
    <h2>Start a group</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <label>Group name<input name="name" maxlength="80" required placeholder="e.g. Market women ajo"></label>
      <div class="form-grid">
        <label>Each person pays<input type="number" name="contribution" min="500" step="500" value="5000" required></label>
        <label>How often<select name="frequency"><?php foreach (AJO_FREQUENCIES as $k => [$t]): ?><option value="<?= $k ?>" <?= $k === 'weekly' ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
        <label>Members<input type="number" name="size" min="2" max="20" value="5" required></label>
      </div>
      <label class="check"><input type="checkbox" name="consent" value="1" required><span class="small"><?= e($rules) ?></span></label>
      <button class="btn btn-primary"><?= icon('plus', 16) ?> Create group</button>
    </form>
  </div>
  <div class="card">
    <h2>Join with a code</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="join">
      <label>Invite code<input name="code" maxlength="10" required style="text-transform:uppercase"></label>
      <label class="check"><input type="checkbox" name="consent" value="1" required><span class="small"><?= e($rules) ?></span></label>
      <button class="btn btn-primary">Join group</button>
    </form>
  </div>
</div>
<?php app_footer();
