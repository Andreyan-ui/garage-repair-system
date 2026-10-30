<?php
require __DIR__ . '/includes/bootstrap.php';

$services = db_all('SELECT id, name, description, image FROM services WHERE is_active = 1 ORDER BY sort_order, name');

$pageTitle = 'Our services';
$activeNav = 'services.php';
$shareImage = 'engine2.jpg';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header has-photo">
  <div class="container page-header-grid">
    <div>
      <p class="eyebrow">What we do</p>
      <h1>Our services</h1>
      <p class="lead">From routine servicing to engine work, our mechanics use modern tools to keep your car running safely.</p>
      <div class="button-row">
        <a class="btn btn-primary" href="<?= e(url('book.php')) ?>">Book a service</a>
        <a class="btn btn-outline-light" href="<?= e(url('faq.php')) ?>">Try a quick fix first</a>
      </div>
    </div>
    <figure class="page-header-figure"><?= photo('engine2.jpg', 'A mechanic checking an engine with a laptop', '', true) ?></figure>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="card-grid photo-grid">
      <?php foreach ($services as $service): ?>
        <article class="service-card has-photo">
          <div class="service-photo"><?= photo(safe_image($service['image']), '') ?></div>
          <div class="service-body">
            <h2><?= e($service['name']) ?></h2>
            <p><?= e($service['description']) ?></p>
            <a class="btn btn-primary btn-sm" href="<?= e(url('book.php?service=' . $service['id'])) ?>">Book this service</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="breakdown-band breakdown-band-slim">
      <div class="breakdown-band-copy">
        <h2>Stuck at the roadside?</h2>
        <p>Our breakdown team can jump-start, change tyres, deliver fuel and tow your car to the workshop.</p>
      </div>
      <div class="button-row">
        <a class="btn btn-light btn-lg" href="<?= e(url('breakdown.php')) ?>"><?= icon('pin') ?> Get roadside help</a>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
