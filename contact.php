<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');

$pageTitle = 'Contact us';
$shareImage = 'IMG-20251007-WA0036.jpg';
$activeNav = 'contact.php';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header has-photo">
  <div class="container page-header-grid">
    <div>
      <p class="eyebrow">Contact</p>
      <h1>Get in touch</h1>
      <p class="lead">Call, WhatsApp or email us. For a breakdown, calling is always fastest.</p>
      <div class="button-row">
        <a class="btn btn-primary" href="<?= e(tel_link($garage['phone'])) ?>"><?= icon('phone') ?> Call us now</a>
        <a class="btn btn-outline-light" href="<?= e(whatsapp_link($garage['whatsapp'])) ?>" target="_blank" rel="noopener"><?= icon('message') ?> WhatsApp</a>
      </div>
    </div>
    <figure class="page-header-figure"><?= photo('IMG-20251007-WA0036.jpg', 'A mechanic explaining the work to a customer', '', true) ?></figure>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="contact-grid">
      <a class="card contact-card" href="<?= e(tel_link($garage['phone'])) ?>">
        <span class="contact-icon"><?= icon('phone') ?></span>
        <span class="contact-label">Call us</span>
        <strong><?= e(format_phone($garage['phone'])) ?></strong>
        <span class="muted">Bookings, questions and breakdowns</span>
      </a>
      <a class="card contact-card" href="<?= e(whatsapp_link($garage['whatsapp'], 'Hello ' . $garage['name'] . ', ')) ?>" target="_blank" rel="noopener">
        <span class="contact-icon"><?= icon('message') ?></span>
        <span class="contact-label">WhatsApp</span>
        <strong><?= e(format_phone($garage['whatsapp'])) ?></strong>
        <span class="muted">Send photos of the problem</span>
      </a>
      <a class="card contact-card" href="mailto:<?= e($garage['email']) ?>">
        <span class="contact-icon"><?= icon('receipt') ?></span>
        <span class="contact-label">Email</span>
        <strong><?= e($garage['email']) ?></strong>
        <span class="muted">For questions that are not urgent</span>
      </a>
      <div class="card contact-card">
        <span class="contact-icon"><?= icon('pin') ?></span>
        <span class="contact-label">Visit us</span>
        <strong><?= e($garage['address']) ?></strong>
        <dl class="hours">
          <?php foreach ($garage['opening_hours'] as $days => $hours): ?>
            <div><dt><?= e($days) ?></dt><dd><?= e($hours) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>

    <?php if ($garage['facebook_url'] !== '' || $garage['instagram_url'] !== ''): ?>
      <div class="social">
        <h2>Follow us</h2>
        <div class="button-row">
          <?php if ($garage['facebook_url'] !== ''): ?>
            <a class="social-link" href="<?= e($garage['facebook_url']) ?>" target="_blank" rel="noopener"><img src="<?= e(asset('images/facebook icon.jpg')) ?>" alt="" width="40" height="40"> Facebook</a>
          <?php endif; ?>
          <?php if ($garage['instagram_url'] !== ''): ?>
            <a class="social-link" href="<?= e($garage['instagram_url']) ?>" target="_blank" rel="noopener"><img src="<?= e(asset('images/instagram icon.jpg')) ?>" alt="" width="40" height="40"> Instagram</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="feature-grid">
      <article class="feature">
        <h2>Book a service</h2>
        <p>Choose a service and a day that suits you. We will call to confirm.</p>
        <a class="feature-link" href="<?= e(url('book.php')) ?>">Book online</a>
      </article>
      <article class="feature feature-danger">
        <h2>Broken down?</h2>
        <p>Send us your location and we will send help.</p>
        <a class="feature-link" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
      </article>
      <article class="feature">
        <h2>Already booked?</h2>
        <p>Check the status of your booking with your reference number.</p>
        <a class="feature-link" href="<?= e(url('track.php')) ?>">Track a booking</a>
      </article>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
