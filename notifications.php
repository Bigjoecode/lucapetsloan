<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

$rows = q('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$u['id']])->fetchAll();
q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$u['id']]);

$area = ($u['role'] === 'admin' && !empty($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], '/admin/')) ? 'admin' : 'app';
app_header('Notifications', '', $area);
?>
<h1 class="mobile-title">Notifications</h1>
<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('bell', 32) ?><p>You're all caught up.</p></div>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($rows as $n): ?>
        <li>
          <span class="tx-ic <?= $n['is_read'] ? 'tx-loan' : 'tx-in' ?>"><?= icon('bell', 18) ?></span>
          <div class="grow">
            <div class="title"><?= e($n['title']) ?><?= !$n['is_read'] ? ' <span class="badge badge-ok">new</span>' : '' ?></div>
            <div class="sub"><?= e($n['body']) ?> · <?= fdatetime($n['created_at']) ?></div>
          </div>
          <?php if ($n['link']): ?><a class="btn btn-ghost btn-sm" href="<?= url($n['link']) ?>">View</a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php app_footer();
