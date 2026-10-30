<?php
$pageTitle = $pageTitle ?? '';
$activeNav = $activeNav ?? '';
$bodyClass = $bodyClass ?? '';
$garage = config('garage');
$headerCustomer = current_customer();
$navItems = [
    'index.php' => 'Home',
    'services.php' => 'Services',
    'faq.php' => 'Self-help',
    'track.php' => 'Track',
    'about.php' => 'About',
    'contact.php' => 'Contact',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle !== '' ? $pageTitle . ' | ' : '') . $garage['full_name']) ?></title>
<meta name="description" content="<?= e($pageDescription ?? $garage['full_name'] . ': car servicing, repairs and roadside breakdown help. Book online and track your car.') ?>">
<meta name="theme-color" content="#2b1d0e">
<?php if (!empty($autoRefresh)): ?>
<meta http-equiv="refresh" content="<?= (int) $autoRefresh ?>">
<?php endif; ?>
<?php
$shareImage = safe_image($shareImage ?? 'garage3.jpg', 'garage3.jpg');
[$shareWidth, $shareHeight] = image_size($shareImage);
?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($garage['full_name']) ?>">
<meta property="og:title" content="<?= e($pageTitle !== '' ? $pageTitle . ' | ' . $garage['full_name'] : $garage['full_name']) ?>">
<meta property="og:description" content="<?= e($pageDescription ?? $garage['full_name'] . ': car servicing, repairs and roadside breakdown help. Book online and track your car.') ?>">
<meta property="og:image" content="<?= e(absolute_url('images/' . rawurlencode($shareImage))) ?>">
<meta property="og:image:width" content="<?= $shareWidth ?>">
<meta property="og:image:height" content="<?= $shareHeight ?>">
<meta property="og:url" content="<?= e(absolute_url(ltrim(substr((string) strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), strlen(base_path())), '/'))) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset('images/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= e(asset('images/brand/apple-touch-icon.png')) ?>">
<meta name="theme-color" content="#170f07">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<div class="emergency-bar">
  <div class="container emergency-inner">
    <p><strong>Broken down?</strong> <span class="hide-sm">We come to you, <?= e($garage['breakdown_hours']) ?>.</span></p>
    <div class="emergency-actions">
      <a href="<?= e(tel_link($garage['phone'])) ?>">Call <?= e(format_phone($garage['phone'])) ?></a>
      <a class="emergency-cta" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
    </div>
  </div>
</div>

<header class="site-header">
  <div class="container header-inner">
    <a class="brand brand-logo" href="<?= e(url('index.php')) ?>" data-brand-shine>
      <img src="<?= e(asset('images/brand/wycliffe-horizontal-chrome.svg')) ?>" alt="<?= e($garage['name']) ?> home" width="167" height="52">
      <span class="brand-shine" aria-hidden="true"></span>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
      <span class="nav-toggle-bars" aria-hidden="true"></span>
      <span>Menu</span>
    </button>
    <nav id="site-nav" class="site-nav" aria-label="Main">
      <ul class="nav-links">
        <?php foreach ($navItems as $file => $label): ?>
          <li><a href="<?= e(url($file)) ?>"<?= $activeNav === $file ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="nav-actions">
        <?php if ($headerCustomer): ?>
          <a class="nav-account<?= in_array($activeNav, ['account.php', 'vehicles.php', 'profile.php'], true) ? ' is-active' : '' ?>" href="<?= e(url('account.php')) ?>">My account</a>
          <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="link-button">Log out</button>
          </form>
        <?php else: ?>
          <a class="nav-account<?= $activeNav === 'login.php' ? ' is-active' : '' ?>" href="<?= e(url('login.php')) ?>">Log in</a>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('book.php')) ?>">Book a service</a>
      </div>
    </nav>
  </div>
</header>

<main id="main">
<?php $flashes = take_flashes(); ?>
<?php if ($flashes): ?>
  <div class="container flash-stack">
    <?php foreach ($flashes as $flashMessage): ?>
      <div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
