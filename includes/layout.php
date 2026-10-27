<?php
declare(strict_types=1);

function icon(string $name, int $size = 20): string
{
    $paths = [
        'home'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'piggy'   => '<path d="M19 9c1 0 2 .5 2 2s-1 2-2 2"/><path d="M4 11a7 6 0 0 1 13-3l2-1v4l1 2-2 1v3h-3v-2H9v2H6v-3a6 6 0 0 1-2-3Z"/><circle cx="14" cy="10" r=".8" fill="currentColor"/>',
        'loan'    => '<rect x="2" y="6" width="20" height="13" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6 10v5M18 10v5"/>',
        'shield'  => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4.5-6 8-6s7 2 8 6"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.5-5 6.5-5s5.7 1.5 6.5 5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 15c2 .5 3.2 2.2 3.5 5"/>',
        'bell'    => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4Z"/><path d="M10 21h4"/>',
        'logout'  => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
        'chart'   => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 6-7"/>',
        'cash'    => '<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'map'     => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/>',
        'check'   => '<path d="m5 12 5 5L20 7"/>',
        'x'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'up'      => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down'    => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'doc'     => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'calendar'=> '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'fire'    => '<path d="M12 22c4 0 7-2.7 7-7 0-4-3-6-4-10-2 2-3 4-3 6-1-1-2-2-2-4-2 2-5 5-5 8 0 4.3 3 7 7 7Z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function brand_mark(): string
{
    return '<span class="brand-mark">' . icon('cash', 18) . '</span><span class="brand-name">' . e(APP_NAME) . '</span>';
}

/** One transaction as a list item. */
function tx_row(array $t, bool $showUser = false): string
{
    $in = in_array($t['type'], ['deposit', 'loan_disbursement'], true);
    [$cls, $ic] = match ($t['type']) {
        'deposit'           => ['tx-in', 'down'],
        'withdrawal'        => ['tx-out', 'up'],
        'loan_disbursement' => ['tx-loan', 'loan'],
        default             => ['tx-loan', 'check'],
    };
    $titles = ['deposit' => 'Savings deposit', 'withdrawal' => 'Withdrawal', 'loan_disbursement' => 'Loan paid out', 'loan_repayment' => 'Loan repayment'];
    $sub = fdatetime($t['created_at']) . ' · ' . (payment_methods()[$t['method']] ?? label($t['method']));
    if ($showUser && isset($t['full_name'])) {
        $sub = e($t['full_name']) . ' · ' . $sub;
    } else {
        $sub = e($sub);
    }
    return '<li><span class="tx-ic ' . $cls . '">' . icon($ic, 18) . '</span>'
        . '<div class="grow"><div class="title">' . e($titles[$t['type']] ?? label($t['type'])) . '</div><div class="sub">' . $sub . '</div></div>'
        . '<div class="right"><div class="tnum" style="font-weight:700">' . ($in ? '+' : '−') . e(money($t['amount'])) . '</div>'
        . ($t['status'] !== 'approved' ? badge($t['status']) : '') . '</div></li>';
}

function render_flashes(): string
{
    $html = '';
    foreach (take_flashes() as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
    return $html;
}

function html_head(string $title, array $extraCss = []): void
{
    ?><!doctype html>
<html lang="en" data-currency="<?= e(CURRENCY) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b4f3c">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=1">
<?php foreach ($extraCss as $css): ?><link rel="stylesheet" href="<?= url('assets/css/' . $css) ?>?v=1">
<?php endforeach; ?>
</head>
<?php
}

/** Public pages: landing, login, register. */
function public_header(string $title): void
{
    html_head($title);
    $u = current_user();
    ?>
<body class="public">
<header class="pub-nav">
  <div class="container pub-nav-inner">
    <a class="brand" href="<?= url('index.php') ?>"><?= brand_mark() ?></a>
    <nav>
      <?php if ($u): ?>
        <a class="btn btn-primary btn-sm" href="<?= url($u['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php') ?>">Open app</a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= url('login.php') ?>">Sign in</a>
        <a class="btn btn-primary btn-sm" href="<?= url('register.php') ?>">Create account</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php
}

function public_footer(): void
{
    ?>
<footer class="pub-foot">
  <div class="container">
    <div><?= brand_mark() ?></div>
    <p class="muted">Need help? <?= e(SUPPORT_PHONE) ?> · <?= e(SUPPORT_EMAIL) ?></p>
    <p class="muted small">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All loans are subject to verification and approval.</p>
  </div>
</footer>
<script src="<?= url('assets/js/app.js') ?>?v=1"></script>
</body>
</html>
<?php
}

/** Signed-in app shell for customers ($area = 'app') or staff ($area = 'admin'). */
function app_header(string $title, string $active = '', string $area = 'app'): void
{
    $u = current_user();
    html_head($title);
    if ($area === 'admin') {
        $nav = [
            'dashboard' => ['admin/index.php', 'Overview', 'chart'],
            'kyc'       => ['admin/kyc.php', 'Verifications', 'shield'],
            'loans'     => ['admin/loans.php', 'Loans', 'loan'],
            'payments'  => ['admin/payments.php', 'Payments', 'cash'],
            'users'     => ['admin/users.php', 'Customers', 'users'],
            'products'  => ['admin/products.php', 'Loan products', 'cog'],
        ];
        $mobile = ['dashboard', 'kyc', 'loans', 'payments', 'users'];
    } else {
        $nav = [
            'dashboard'    => ['dashboard.php', 'Home', 'home'],
            'savings'      => ['savings.php', 'Savings', 'piggy'],
            'loans'        => ['loans.php', 'Loans', 'loan'],
            'verification' => ['verification.php', 'Verification', 'shield'],
            'transactions' => ['transactions.php', 'Activity', 'list'],
            'profile'      => ['profile.php', 'Profile', 'user'],
        ];
        $mobile = ['dashboard', 'savings', 'loans', 'verification', 'transactions'];
    }
    $unread = unread_count((int)$u['id']);
    ?>
<body class="app area-<?= e($area) ?>">
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="<?= url($area === 'admin' ? 'admin/index.php' : 'dashboard.php') ?>"><?= brand_mark() ?></a>
    <?php if ($area === 'admin'): ?><div class="side-label">Staff console</div><?php endif; ?>
    <nav class="side-nav">
      <?php foreach ($nav as $key => [$href, $text, $ic]): ?>
        <a href="<?= url($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($text) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <?php if ($u['role'] === 'admin'): ?>
        <a href="<?= url($area === 'admin' ? 'dashboard.php' : 'admin/index.php') ?>" class="side-switch"><?= icon('arrow', 16) ?><?= $area === 'admin' ? 'Customer view' : 'Staff console' ?></a>
      <?php endif; ?>
      <a href="<?= url('logout.php') ?>"><?= icon('logout') ?><span>Sign out</span></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <a class="brand brand-mobile" href="<?= url($area === 'admin' ? 'admin/index.php' : 'dashboard.php') ?>"><?= brand_mark() ?></a>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="top-actions">
        <a class="icon-btn" href="<?= url('notifications.php') ?>" aria-label="Notifications"><?= icon('bell') ?><?php if ($unread): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></a>
        <a class="avatar" href="<?= url('profile.php') ?>" title="<?= e($u['full_name']) ?>"><?= e(initials($u['full_name'])) ?></a>
      </div>
    </header>
    <main class="content">
      <?= render_flashes() ?>
<?php
    $GLOBALS['__mobile_nav'] = array_map(fn($k) => array_merge([$k], $nav[$k]), $mobile);
    $GLOBALS['__active'] = $active;
}

function app_footer(): void
{
    $items = $GLOBALS['__mobile_nav'] ?? [];
    $active = $GLOBALS['__active'] ?? '';
    ?>
    </main>
  </div>
</div>
<nav class="bottom-nav">
  <?php foreach ($items as [$key, $href, $text, $ic]): ?>
    <a href="<?= url($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= icon($ic, 22) ?><span><?= e($text) ?></span></a>
  <?php endforeach; ?>
</nav>
<script src="<?= url('assets/js/app.js') ?>?v=1"></script>
</body>
</html>
<?php
}
