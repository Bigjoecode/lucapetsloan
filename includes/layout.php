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
        'phone'   => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
        'gift'    => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v9H5v-9M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
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
        'sun'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'    => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'eye'     => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'headset' => '<path d="M4 15v-3a8 8 0 0 1 16 0v3"/><rect x="2" y="14" width="5" height="7" rx="2"/><rect x="17" y="14" width="5" height="7" rx="2"/>',
        'wallet'  => '<path d="M19 7V5a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V6"/><circle cx="16.5" cy="13.5" r="1.2" fill="currentColor"/>',
        'coin'    => '<circle cx="12" cy="12" r="9"/><path d="M9.5 8h4a2 2 0 0 1 0 4h-4V8Zm0 4h4.5a2 2 0 0 1 0 4H9.5v-8M11 6.5V8M11 16v1.5"/>',
        'send'    => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/>',
        'bank'    => '<path d="M3 10 12 4l9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** The LucaPet Loan logo (LP monogram with a gold coin), as inline SVG. */
function logo_svg(int $size = 36): string
{
    static $n = 0;
    $n++;   // unique gradient ids when the logo appears more than once on a page
    return '<svg class="logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<defs><linearGradient id="lbg' . $n . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0e7a55"/><stop offset="1" stop-color="#0b4f3c"/></linearGradient>'
        . '<linearGradient id="lgd' . $n . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbd77e"/><stop offset="1" stop-color="#e9a923"/></linearGradient></defs>'
        . '<rect width="64" height="64" rx="15" fill="url(#lbg' . $n . ')"/>'
        . '<path d="M17 13 V47 H33" fill="none" stroke="#fff" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M27 51 V18" fill="none" stroke="#6ee7b7" stroke-width="7" stroke-linecap="round"/>'
        . '<circle cx="37" cy="26" r="11" fill="url(#lgd' . $n . ')"/><circle cx="37" cy="26" r="4.2" fill="#0b4f3c"/></svg>';
}

function brand_mark(): string
{
    return '<span class="brand-mark">' . logo_svg(36) . '</span><span class="brand-name">' . e(APP_NAME) . '</span>';
}

/** One transaction as a list item. */
function tx_row(array $t, bool $showUser = false): string
{
    $in = in_array($t['type'], ['deposit', 'loan_disbursement', 'interest', 'bonus', 'ajo_payout'], true);
    [$cls, $ic] = match ($t['type']) {
        'deposit'           => ['tx-in', 'down'],
        'interest'          => ['tx-in', 'chart'],
        'bonus'             => ['tx-in', 'gift'],
        'ajo_payout'        => ['tx-in', 'users'],
        'ajo_contribution'  => ['tx-out', 'users'],
        'withdrawal'        => ['tx-out', 'up'],
        'loan_disbursement' => ['tx-loan', 'loan'],
        default             => ['tx-loan', 'check'],
    };
    $titles = ['deposit' => 'Savings deposit', 'withdrawal' => 'Withdrawal', 'loan_disbursement' => 'Loan paid out', 'loan_repayment' => 'Loan repayment', 'interest' => 'Savings interest', 'bonus' => 'Referral bonus', 'ajo_contribution' => 'Group savings contribution', 'ajo_payout' => 'Group savings pot'];
    $sub = fdatetime($t['created_at']) . ' · ' . (in_array($t['type'], ['interest', 'bonus', 'ajo_contribution', 'ajo_payout'], true) ? ($t['note'] ?? '') : (payment_methods()[$t['method']] ?? label($t['method'])));
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

/** The customer's credit level, limit and what unlocks the next level. */
function credit_card_html(array $c): string
{
    $steps = '';
    foreach (CREDIT_LEVELS as $i => [$name]) {
        $steps .= '<span class="lvl' . ($i <= $c['level'] ? ' on' : '') . '" title="' . e($name) . '"></span>';
    }
    if ($c['blocked']) {
        $hint = 'Borrowing is paused after a defaulted loan. Talk to us to fix it.';
    } elseif ($c['override']) {
        $hint = 'Limit set by our team.';
    } elseif ($c['next_name']) {
        $hint = 'Repay ' . $c['needed'] . ' more loan' . ($c['needed'] === 1 ? '' : 's') . ' on time to reach <strong>' . e($c['next_name']) . '</strong> (' . e(money0(setting('limit_' . strtolower($c['next_name'])))) . ' base).';
    } else {
        $hint = 'Top level. Thank you for being a great customer.';
    }
    return '<div class="card credit-card">'
        . '<div class="row between"><div class="stat"><span class="k">' . icon('shield', 16) . ' Your borrowing limit</span><span class="v">' . e(money0($c['limit'])) . '</span>'
        . '<span class="s">' . e($c['name']) . ' level · ' . $c['on_time'] . ' on-time repayment' . ($c['on_time'] === 1 ? '' : 's') . ($c['savings_boost'] > 0 && !$c['override'] ? ' · +' . e(money0($c['savings_boost'])) . ' from your savings' : '') . '</span></div>'
        . '<div class="levels">' . $steps . '</div></div>'
        . '<p class="small muted" style="margin:.5rem 0 0">' . $hint . ' Your savings raise your limit too, up to the base amount again.' . ($c['trusted'] && setting('auto_approve_repeat') ? ' <strong>You qualify for instant approval.</strong>' : '') . '</p>'
        . '</div>';
}

function render_flashes(): string
{
    $html = '';
    foreach (take_flashes() as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
    return $html;
}

function html_head(string $title, array $extraCss = [], ?string $theme = null): void
{
    ?><!doctype html>
<html lang="en" data-currency="<?= e(CURRENCY) ?>" data-base="<?= e(BASE_URL) ?>"<?= $theme ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<?php if ($theme): ?><script>try { var t = localStorage.getItem('lpl-theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; if (localStorage.getItem('lpl-hide') === '1') document.documentElement.classList.add('amounts-hidden'); } catch (e) {}</script>
<?php endif; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= $theme === 'dark' ? '#08120e' : '#0b4f3c' ?>">
<link rel="manifest" href="<?= url('manifest.php') ?>">
<link rel="icon" type="image/svg+xml" href="<?= url('assets/icons/logo.svg') ?>?v=2">
<link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/icons/favicon-32.png') ?>?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="<?= url('assets/icons/icon-192.png') ?>?v=2">
<link rel="apple-touch-icon" href="<?= url('assets/icons/apple-touch-icon.png') ?>?v=2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=10">
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
        <a class="btn btn-primary btn-sm" href="<?= url(home_for($u)) ?>">Open app</a>
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
<script src="<?= url('assets/js/app.js') ?>?v=9"></script>
</body>
</html>
<?php
}

/** Signed-in app shell for customers ($area = 'app', dark by default) or staff ($area = 'admin', light by default). */
function app_header(string $title, string $active = '', string $area = 'app', string $subtitle = ''): void
{
    $u = current_user();
    html_head($title, [], $area === 'admin' ? 'light' : 'dark');
    if ($area === 'admin') {
        $groups = [(is_super($u) ? 'Super admin' : 'Staff console') => array_filter([
            'dashboard' => ['admin/index.php', 'Overview', 'chart'],
            'kyc'       => can('kyc') ? ['admin/kyc.php', 'Verifications', 'shield'] : null,
            'loans'     => can('loans') || can('waivers') || can('collections') ? ['admin/loans.php', 'Loans', 'loan'] : null,
            'collections' => can('collections') ? ['admin/collections.php', 'Collections', 'phone'] : null,
            'visits'    => can('visits') ? ['admin/visits.php', 'Home visits', 'map'] : null,
            'payments'  => can('payments') ? ['admin/payments.php', 'Payments', 'cash'] : null,
            'crypto'    => can('payments') ? ['admin/crypto.php', 'Crypto', 'coin'] : null,
            'users'     => can('customers') ? ['admin/users.php', 'Customers', 'users'] : null,
            'products'  => can('products') ? ['admin/products.php', 'Loan products', 'cog'] : null,
            'reports'   => can('reports') ? ['admin/reports.php', 'Reports', 'chart'] : null,
            'ajo'       => can('customers') ? ['admin/ajo.php', 'Group savings', 'users'] : null,
            'staff'     => is_super($u) ? ['admin/staff.php', 'Staff & roles', 'shield'] : null,
            'settings'  => is_super($u) ? ['admin/settings.php', 'Settings', 'cog'] : null,
            'integrations' => is_super($u) ? ['admin/integrations.php', 'Integrations', 'shield'] : null,
            'audit'     => is_super($u) ? ['admin/audit.php', 'Audit log', 'list'] : null,
        ])];
        $nav = $groups[array_key_first($groups)];
        $mobile = array_slice(array_keys($nav), 0, 5);
    } else {
        $groups = [
            'Main' => [
                'dashboard'    => ['dashboard.php', 'Dashboard', 'home'],
                'transactions' => ['transactions.php', 'Transactions', 'chart'],
            ],
            'Save' => array_filter([
                'savings' => ['savings.php', 'Savings', 'piggy'],
                'crypto'  => ['crypto.php', 'Crypto savings', 'coin'],
                'ajo'     => ['ajo.php', 'Group savings', 'users'],
            ]),
            'Borrow' => [
                'loans'        => ['loans.php', 'Loans', 'loan'],
                'verification' => ['verification.php', 'Verification', 'shield'],
            ],
            'Account' => [
                'notifications' => ['notifications.php', 'Notifications', 'bell'],
                'profile'       => ['profile.php', 'Settings', 'cog'],
            ],
        ];
        $nav = array_merge(...array_values($groups));
        $nav['transactions'][1] = 'Activity';   // shorter label for the phone bar
        $nav['dashboard'][1] = 'Home';
        $mobile = ['dashboard', 'savings', 'loans', 'verification', 'transactions'];
    }
    $unread = unread_count((int)$u['id']);
    $home = url($area === 'admin' ? 'admin/index.php' : 'dashboard.php');
    $first = explode(' ', trim($u['full_name']))[0];
    ?>
<body class="app area-<?= e($area) ?>">
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="<?= $home ?>"><?= brand_mark() ?></a>
    <nav class="side-nav">
      <?php foreach ($groups as $label => $items): if (!$items) continue; ?>
        <div class="side-label"><?= e($label) ?></div>
        <?php foreach ($items as $key => [$href, $text, $ic]): ?>
          <a href="<?= url($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><span class="nav-ic"><?= icon($ic, 18) ?></span><span class="grow"><?= e($text) ?></span><?php if ($key === 'notifications' && $unread): ?><span class="nav-count"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (is_staff($u)): ?>
        <div class="side-label">Switch</div>
        <?php if ($area === 'admin'): ?><a href="<?= url('admin/security.php') ?>"><span class="nav-ic"><?= icon('shield', 18) ?></span><span class="grow">Sign-in security</span></a><?php endif; ?>
        <a href="<?= url($area === 'admin' ? 'dashboard.php' : 'admin/index.php') ?>"><span class="nav-ic"><?= icon('arrow', 18) ?></span><span class="grow"><?= $area === 'admin' ? 'Customer view' : 'Staff console' ?></span></a>
      <?php endif; ?>
    </nav>
    <div class="side-user">
      <a class="avatar" href="<?= url('profile.php') ?>"><?= e(initials($u['full_name'])) ?><i class="online"></i></a>
      <div class="who"><strong><?= e($first) ?></strong><span><?= e($u['email']) ?></span></div>
      <a class="side-out" href="<?= url('logout.php') ?>" title="Sign out" aria-label="Sign out"><?= icon('logout', 18) ?></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <a class="brand brand-mobile" href="<?= $home ?>"><?= brand_mark() ?></a>
      <div class="page-title"><h1><?= e($title) ?></h1><?php if ($subtitle !== ''): ?><span><?= e($subtitle) ?></span><?php endif; ?></div>
      <div class="top-actions">
        <button type="button" class="btn btn-accent btn-sm" data-install hidden><?= icon('down', 16) ?> Install app</button>
        <button type="button" class="icon-btn theme-btn" data-theme-toggle aria-label="Switch between light and dark"><span class="when-dark"><?= icon('sun') ?></span><span class="when-light"><?= icon('moon') ?></span></button>
        <a class="icon-btn" href="<?= url('notifications.php') ?>" aria-label="Notifications"><?= icon('bell') ?><?php if ($unread): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></a>
        <a class="me" href="<?= url('profile.php') ?>" title="<?= e($u['full_name']) ?>"><span class="avatar"><?= e(initials($u['full_name'])) ?></span><span class="who"><strong><?= e($first) ?></strong><span><?= e($u['email']) ?></span></span></a>
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
<script src="<?= url('assets/js/app.js') ?>?v=9"></script>
</body>
</html>
<?php
}
