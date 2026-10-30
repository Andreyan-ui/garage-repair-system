<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';
$headerAdmin = current_admin();
$navCounts = db_one(
    "SELECT
        (SELECT COUNT(*) FROM breakdown_requests WHERE status = 'new') AS breakdowns,
        (SELECT COUNT(*) FROM bookings WHERE status = 'pending') AS bookings,
        (SELECT COUNT(*) FROM jobs WHERE status = 'ready') AS jobs,
        (SELECT COUNT(*) FROM invoices i WHERE i.status = 'issued'
            AND i.total > COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0)) AS invoices,
        (SELECT COUNT(*) FROM parts WHERE is_active = 1 AND stock_quantity <= reorder_level) AS parts"
);
$newBreakdowns = (int) $navCounts['breakdowns'];
$adminNavGroups = [
    'Front desk' => [
        'index.php' => ['label' => 'Dashboard', 'icon' => 'dashboard', 'count' => 0, 'tone' => ''],
        'breakdowns.php' => ['label' => 'Breakdowns', 'icon' => 'alert', 'count' => $newBreakdowns, 'tone' => 'red'],
        'bookings.php' => ['label' => 'Bookings', 'icon' => 'calendar', 'count' => (int) $navCounts['bookings'], 'tone' => 'amber'],
        'customers.php' => ['label' => 'Customers', 'icon' => 'users', 'count' => 0, 'tone' => ''],
    ],
    'Workshop' => [
        'jobs.php' => ['label' => 'Job cards', 'icon' => 'wrench', 'count' => (int) $navCounts['jobs'], 'tone' => 'green'],
        'invoices.php' => ['label' => 'Invoices', 'icon' => 'receipt', 'count' => (int) $navCounts['invoices'], 'tone' => 'amber'],
        'parts.php' => ['label' => 'Parts and stock', 'icon' => 'box', 'count' => (int) $navCounts['parts'], 'tone' => 'amber'],
        'mechanics.php' => ['label' => 'Mechanics', 'icon' => 'hardhat', 'count' => 0, 'tone' => ''],
        'reports.php' => ['label' => 'Reports', 'icon' => 'chart', 'count' => 0, 'tone' => ''],
    ],
    'Website and settings' => [
        'services.php' => ['label' => 'Services', 'icon' => 'list', 'count' => 0, 'tone' => ''],
        'faqs.php' => ['label' => 'Self-help guides', 'icon' => 'book', 'count' => 0, 'tone' => ''],
        'staff.php' => ['label' => 'Staff logins', 'icon' => 'key', 'count' => 0, 'tone' => ''],
        'account.php' => ['label' => 'My account', 'icon' => 'user', 'count' => 0, 'tone' => ''],
    ],
];
$navTitles = [
    'jobs.php' => 'Cars ready for collection',
    'invoices.php' => 'Invoices with money owed',
    'parts.php' => 'Parts low or out of stock',
    'breakdowns.php' => 'New breakdown requests',
    'bookings.php' => 'Bookings to confirm',
];
$signedInAs = ($headerAdmin['full_name'] ?? '') ?: ($headerAdmin['username'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($newBreakdowns > 0 ? '(' . $newBreakdowns . ') ' : '') . $pageTitle . ' | Staff | ' . config('garage.name')) ?></title>
<?php if (!empty($autoRefresh)): ?>
<noscript><meta http-equiv="refresh" content="<?= (int) $autoRefresh ?>"></noscript>
<?php endif; ?>
<link rel="icon" href="<?= e(asset('images/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<?php if (!empty($withDocumentStyles)): ?>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<?php endif; ?>
</head>
<body class="admin" data-auto-refresh="<?= (int) ($autoRefresh ?? 0) ?>" data-new-breakdowns="<?= $newBreakdowns ?>">
<a class="skip-link" href="#admin-main">Skip to content</a>
<div class="admin-shell">
  <header class="admin-topbar">
    <a class="brand" href="<?= e(url('admin/index.php')) ?>">
      <img src="<?= e(asset('images/brand/wycliffe-horizontal.svg')) ?>" alt="<?= e(config('garage.name')) ?>" width="128" height="40">
      <span class="brand-tag">Staff</span>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="admin-nav" data-nav-toggle>
      <span class="nav-toggle-bars" aria-hidden="true"></span>
      <span>Menu</span>
      <?php if ($newBreakdowns > 0): ?><span class="count count-red"><?= $newBreakdowns ?></span><?php endif; ?>
    </button>
  </header>

  <aside class="admin-sidebar" id="admin-nav">
    <a class="brand sidebar-brand" href="<?= e(url('admin/index.php')) ?>">
      <img src="<?= e(asset('images/brand/wycliffe-horizontal.svg')) ?>" alt="<?= e(config('garage.name')) ?>" width="186" height="58">
      <span class="brand-tag">Staff area</span>
    </a>
    <nav aria-label="Staff">
      <?php foreach ($adminNavGroups as $groupLabel => $groupItems): ?>
        <p class="admin-nav-label"><?= e($groupLabel) ?></p>
        <ul class="admin-nav">
          <?php foreach ($groupItems as $file => $item): ?>
            <li>
              <a href="<?= e(url('admin/' . $file)) ?>"<?= $activeNav === $file ? ' class="is-active" aria-current="page"' : '' ?>>
                <?= icon($item['icon'], 'icon nav-icon') ?>
                <span class="nav-label"><?= e($item['label']) ?></span>
                <?php if ($item['count'] > 0): ?>
                  <span class="count count-<?= e($item['tone']) ?>" title="<?= e($navTitles[$file] ?? '') ?>"><?= (int) $item['count'] ?></span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="sidebar-user">
        <span class="avatar avatar-gold"><?= e(initials($signedInAs)) ?></span>
        <span><small>Signed in as</small><strong><?= e($signedInAs) ?></strong></span>
      </div>
      <a class="sidebar-link" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View website</a>
      <form method="post" action="<?= e(url('admin/logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-light btn-sm btn-block"><?= icon('logout') ?> Log out</button>
      </form>
      <p class="refresh-note" data-refresh-note hidden></p>
    </div>
  </aside>

  <main class="admin-main" id="admin-main">
    <?php foreach (take_flashes() as $flashMessage): ?>
      <div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div>
    <?php endforeach; ?>
