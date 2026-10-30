<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');
$services = db_all('SELECT id, name, description, image FROM services WHERE is_active = 1 ORDER BY sort_order, name LIMIT 6');
$tips = db_all('SELECT id, question FROM faqs WHERE is_published = 1 ORDER BY sort_order, id LIMIT 5');

$activeNav = 'index.php';
$bodyClass = 'page-home';
require __DIR__ . '/includes/header.php';
?>
<section class="hero-split">
  <div class="container hero-grid">
    <div class="hero-copy">
      <p class="eyebrow"><?= e($garage['full_name']) ?> &middot; <?= e($garage['address']) ?></p>
      <h1>Reliable repairs, and roadside help when your car lets you down.</h1>
      <p class="lead">Honest, affordable servicing and repairs by skilled mechanics. Book online, follow every step, and call us any time you are stuck.</p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(url('book.php')) ?>">Book a service</a>
        <a class="btn btn-danger btn-lg" href="<?= e(url('breakdown.php')) ?>"><?= icon('alert') ?> I've broken down</a>
      </div>
      <ul class="hero-points">
        <li><?= icon('shield') ?> We explain the work and the price before we start</li>
        <li><?= icon('clock') ?> Follow your car online, from check-in to collection</li>
        <li><?= icon('phone') ?> Call <a href="<?= e(tel_link($garage['phone'])) ?>"><?= e(format_phone($garage['phone'])) ?></a></li>
      </ul>
    </div>

    <div class="hero-collage">
      <figure class="collage-tall"><?= photo('repair1.jpg', 'A mechanic working under the bonnet of a car', '', true) ?></figure>
      <figure class="collage-top"><?= photo('garage3.jpg', 'A smiling Wycliffe Motors mechanic in the workshop', '', true) ?></figure>
      <figure class="collage-bottom"><?= photo('yard-gate.jpg', 'Cars under repair in the Wycliffe Motors yard', '', true) ?></figure>
      <div class="collage-badge collage-badge-live" aria-hidden="true">
        <span class="pulse-dot"></span>
        <span><strong>Roadside team on call</strong><small><?= e(ucfirst($garage['breakdown_hours'])) ?></small></span>
      </div>
      <div class="collage-badge collage-badge-track" aria-hidden="true">
        <span class="track-icon"><?= icon('check') ?></span>
        <span><strong>Your car is ready</strong><small>Track every step online</small></span>
      </div>
    </div>
  </div>
</section>

<section class="trust-strip" aria-label="Why drivers choose us">
  <div class="container">
  <div class="trust-grid">
    <div class="trust-item">
      <span class="trust-icon"><?= icon('shield') ?></span>
      <div><strong>Honest quotes</strong><p>You approve the work and the price before we go ahead.</p></div>
    </div>
    <div class="trust-item">
      <span class="trust-icon trust-icon-red"><?= icon('truck') ?></span>
      <div><strong>Roadside help</strong><p>Broken down? We come to you, <?= e($garage['breakdown_hours']) ?>.</p></div>
    </div>
    <div class="trust-item">
      <span class="trust-icon"><?= icon('gauge') ?></span>
      <div><strong>Modern diagnostics</strong><p>Computer scans find the cause of faults and warning lights fast.</p></div>
    </div>
    <div class="trust-item">
      <span class="trust-icon"><?= icon('clock') ?></span>
      <div><strong>Track your car</strong><p>See the status, our findings and your invoice online.</p></div>
    </div>
  </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <p class="eyebrow eyebrow-dark">What we do</p>
        <h2>Our services</h2>
        <p class="muted">Servicing, inspections, repairs and specialised work.</p>
      </div>
      <a class="btn btn-outline" href="<?= e(url('services.php')) ?>">All services <?= icon('arrow') ?></a>
    </div>
    <div class="card-grid photo-grid">
      <?php foreach ($services as $service): ?>
        <article class="service-card has-photo">
          <div class="service-photo"><?= photo(safe_image($service['image']), '') ?></div>
          <div class="service-body">
            <h3><?= e($service['name']) ?></h3>
            <p><?= e($service['description']) ?></p>
            <a class="btn btn-outline btn-sm" href="<?= e(url('book.php?service=' . $service['id'])) ?>">Book <?= e(strtolower($service['name'])) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="container split split-photo">
    <figure class="framed-photo">
      <?= photo('repair2.jpg', 'A mechanic checking a car with a laptop') ?>
      <figcaption>
        <span class="track-icon"><?= icon('check') ?></span>
        <span>Every booking gets its own reference, so you can track it online.</span>
      </figcaption>
    </figure>
    <div>
      <p class="eyebrow eyebrow-dark">Simple and clear</p>
      <h2>How booking works</h2>
      <ol class="how-steps">
        <li><strong>Book online</strong><span>Pick a service, your car and a day that suits you.</span></li>
        <li><strong>We confirm</strong><span>We call you to confirm the time and answer any questions.</span></li>
        <li><strong>We check and quote</strong><span>You approve the work and the price before we start.</span></li>
        <li><strong>Track and collect</strong><span>Follow your car online and see your invoice when it is ready.</span></li>
      </ol>
      <div class="button-row">
        <a class="btn btn-dark" href="<?= e(url('book.php')) ?>">Book a service</a>
        <?php if (!current_customer()): ?>
          <a class="btn btn-outline" href="<?= e(url('register.php')) ?>">Create an account</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="breakdown-band">
      <div class="breakdown-band-copy">
        <p class="eyebrow">Roadside assistance</p>
        <h2>Stuck at the roadside?</h2>
        <p>Flat tyre, flat battery, overheating or out of fuel. Send us your location and we will send a mechanic or a tow truck, <?= e($garage['breakdown_hours']) ?>.</p>
        <div class="button-row">
          <a class="btn btn-light btn-lg" href="<?= e(tel_link($garage['phone'])) ?>"><?= icon('phone') ?> Call <?= e(format_phone($garage['phone'])) ?></a>
          <a class="btn btn-outline-light btn-lg" href="<?= e(url('breakdown.php')) ?>"><?= icon('pin') ?> Send my location</a>
        </div>
      </div>
      <figure class="breakdown-band-photo"><?= photo('wheel3.jpg', 'A mechanic changing a tyre with an impact wrench') ?></figure>
    </div>
  </div>
</section>

<section class="section section-tight">
  <div class="container split">
    <div class="card tips-card">
      <p class="eyebrow eyebrow-dark">Self-help</p>
      <h2>Quick fixes you can try</h2>
      <p class="muted">Small problems you can often solve yourself, safely.</p>
      <ul class="link-list">
        <?php foreach ($tips as $tip): ?>
          <li><a href="<?= e(url('faq.php#faq-' . $tip['id'])) ?>"><?= e($tip['question']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <a class="feature-link" href="<?= e(url('faq.php')) ?>">All self-help guides</a>
    </div>
    <div class="card visit-card">
      <p class="eyebrow eyebrow-dark">Visit us</p>
      <h2><?= e($garage['address']) ?></h2>
      <dl class="hours">
        <?php foreach ($garage['opening_hours'] as $days => $hours): ?>
          <div><dt><?= e($days) ?></dt><dd><?= e($hours) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Breakdown help</dt><dd><?= e(ucfirst($garage['breakdown_hours'])) ?></dd></div>
      </dl>
      <div class="button-row">
        <a class="btn btn-dark btn-sm" href="<?= e(tel_link($garage['phone'])) ?>"><?= icon('phone') ?> Call</a>
        <a class="btn btn-outline btn-sm" href="<?= e(whatsapp_link($garage['whatsapp'])) ?>" target="_blank" rel="noopener"><?= icon('message') ?> WhatsApp</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('contact.php')) ?>">Contact us</a>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
