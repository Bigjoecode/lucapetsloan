<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_staff('visits');

$mine = !isset($_GET['all']) || !can('kyc');
$rows = q('SELECT k.*, u.full_name, u.phone FROM kyc k JOIN users u ON u.id = k.user_id
           WHERE u.kyc_status = "visit_scheduled"' . ($mine ? ' AND k.officer_id = ' . (int)$me['id'] : '') . '
           ORDER BY k.visit_date, k.visit_time')->fetchAll();
$today = date('Y-m-d');
$groups = ['Overdue' => [], 'Today' => [], 'Coming up' => []];
foreach ($rows as $r) {
    $groups[$r['visit_date'] < $today ? 'Overdue' : ($r['visit_date'] === $today ? 'Today' : 'Coming up')][] = $r;
}
$directions = fn(array $r) => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($r['latitude'] ? $r['latitude'] . ',' . $r['longitude'] : $r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ', Nigeria');

app_header('Home visits', 'visits', 'admin');
?>
<h1 class="mobile-title">Home visits</h1>
<div class="row between">
  <span class="muted small"><?= $mine ? 'Visits assigned to you.' : 'All scheduled visits.' ?> Check in when you arrive, take a photo, then record the outcome.</span>
  <?php if (can('kyc')): ?><a class="btn btn-ghost btn-sm" href="<?= $mine ? '?all=1' : 'visits.php' ?>"><?= $mine ? 'Show everyone\'s' : 'Only mine' ?></a><?php endif; ?>
</div>
<?php if (!$rows): ?>
  <div class="card"><div class="empty"><?= icon('map', 32) ?><p>No visits scheduled<?= $mine ? ' for you' : '' ?>.</p></div></div>
<?php endif; ?>
<?php foreach ($groups as $title => $list): if (!$list) continue; ?>
  <h2 class="section-title"><?= e($title) ?> · <?= count($list) ?></h2>
  <div class="grid grid-2">
    <?php foreach ($list as $r): ?>
      <div class="card stack">
        <div class="row between">
          <div><h2 style="margin:0"><?= e($r['full_name']) ?></h2><span class="muted small"><?= fdate($r['visit_date'], 'D, M j') ?> · <?= e($r['visit_time']) ?><?= $mine ? '' : ' · ' . e($r['officer_name']) ?></span></div>
          <?= $r['checkin_at'] ? '<span class="badge badge-ok">Checked in</span>' : badge($r['visit_date'] < $today ? 'overdue' : 'upcoming') ?>
        </div>
        <div class="small"><?= icon('map', 14) ?> <?= e($r['address']) ?>, <?= e($r['city']) ?>, <?= e($r['state']) ?><div class="muted">Landmark: <?= e($r['landmark']) ?><?= $r['visit_instructions'] ? ' · ' . e($r['visit_instructions']) : '' ?></div></div>
        <div class="row">
          <a class="btn btn-primary btn-sm" href="<?= e($directions($r)) ?>" target="_blank" rel="noopener">Directions</a>
          <a class="btn btn-ghost btn-sm" href="tel:<?= e($r['phone']) ?>"><?= icon('phone', 14) ?> Call</a>
          <a class="btn btn-ghost btn-sm" href="kyc.php?id=<?= $r['user_id'] ?>">Open &amp; check in</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php app_footer();
