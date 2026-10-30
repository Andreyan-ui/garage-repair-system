<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');
$gallery = [
    ['yard-gate.jpg', 'A busy morning in our yard', 'is-wide'],
    ['engine-strip.jpg', 'An engine stripped down for repair'],
    ['oil-change-underbody.jpg', 'Draining the old oil on a service'],
    ['wheel-bodywork.jpg', 'Wheels, tyres and bodywork'],
    ['painting1.jpg', 'Bodywork and panel repair'],
    ['wheel3.jpg', 'Tyres and wheels'],
    ['diagnosis5.jpg', 'Computer diagnostics'],
    ['wheel2.jpg', 'Brakes and suspension'],
    ['repair2.jpg', 'Test drives and final checks'],
    ['diagnosis7.jpg', 'Electrics and sensors'],
];

$pageTitle = 'About us';
$activeNav = 'about.php';
$shareImage = 'yard-gate.jpg';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header has-photo">
  <div class="container page-header-grid">
    <div>
      <p class="eyebrow">About us</p>
      <h1><?= e($garage['full_name']) ?></h1>
      <p class="lead">Dependable, affordable and high quality vehicle maintenance, built on honesty and professionalism.</p>
    </div>
    <figure class="page-header-figure"><?= photo('engine-bay-open.jpg', 'A Mercedes with the bonnet up for engine work in our yard', '', true) ?></figure>
  </div>
</section>

<section class="section">
  <div class="container split split-photo">
    <div class="prose">
      <p class="eyebrow eyebrow-dark">Our story</p>
      <h2>Our background</h2>
      <p>Founded with a passion for excellence, our garage was created to provide customers with dependable, affordable and high quality vehicle maintenance. Over the years we have earned a reputation for honesty, professionalism and commitment to customer satisfaction.</p>
      <p>At <?= e($garage['full_name']) ?> we handle a wide range of services including engine diagnostics, brake repairs, oil changes, tyre replacement, car electrical work and general vehicle servicing. Our skilled mechanics use modern tools and techniques to make sure your vehicle runs safely and efficiently.</p>
      <p>When your car lets you down on the road, our breakdown team comes to you, <?= e($garage['breakdown_hours']) ?>.</p>
    </div>
    <figure class="framed-photo">
      <?= photo('garage3.jpg', 'One of our mechanics in the workshop') ?>
    </figure>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="feature-grid">
      <article class="feature">
        <span class="feature-icon"><?= icon('star') ?></span>
        <h2>Our vision</h2>
        <p>To become the most trusted and reliable auto repair service provider in Uganda by delivering quality, affordable and technology-driven vehicle maintenance.</p>
      </article>
      <article class="feature">
        <span class="feature-icon"><?= icon('wrench') ?></span>
        <h2>Our mission</h2>
        <p>To provide exceptional car repair and maintenance through skilled professionals, modern diagnostic tools and a strong commitment to customer satisfaction, so every vehicle leaves our garage in perfect condition.</p>
      </article>
      <article class="feature">
        <span class="feature-icon"><?= icon('check') ?></span>
        <h2>Our objectives</h2>
        <ul class="plain-list">
          <li>Make it easy for customers to reach us online.</li>
          <li>Provide clear contact information and communication channels.</li>
          <li>Show our team, mission and vision for transparency and trust.</li>
          <li>Promote digital transformation in local automotive services.</li>
        </ul>
      </article>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <p class="eyebrow eyebrow-dark">Inside the workshop</p>
        <h2>Our work</h2>
      </div>
    </div>
    <div class="masonry">
      <?php foreach ($gallery as $tile): ?>
        <?php [$file, $caption] = $tile; ?>
        <?php [$galleryWidth, $galleryHeight] = image_size($file); ?>
        <?php $tileClass = $tile[2] ?? ($galleryHeight > $galleryWidth ? 'is-tall' : ''); ?>
        <figure<?= $tileClass !== '' ? ' class="' . e($tileClass) . '"' : '' ?>><?= photo($file, $caption) ?><figcaption><?= e($caption) ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
    <div class="cta-band has-photo">
      <div>
        <h2>Ready when you are</h2>
        <p>Book your car in, or ask us anything.</p>
      </div>
      <div class="button-row">
        <a class="btn btn-primary btn-lg" href="<?= e(url('book.php')) ?>">Book a service</a>
        <a class="btn btn-outline-light btn-lg" href="<?= e(url('contact.php')) ?>">Contact us</a>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
