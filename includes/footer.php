<?php
$garage = config('garage');
?>
</main>

<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-brand">
      <img src="<?= e(asset('images/brand/wycliffe-logo.svg')) ?>" alt="<?= e($garage['full_name']) ?>" width="150" height="179">
      <p><?= e($garage['tagline']) ?>. Reliable, affordable repairs and roadside help.</p>
    </div>
    <div>
      <h2>Opening hours</h2>
      <dl class="hours">
        <?php foreach ($garage['opening_hours'] as $days => $hours): ?>
          <div><dt><?= e($days) ?></dt><dd><?= e($hours) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Breakdown help</dt><dd><?= e($garage['breakdown_hours']) ?></dd></div>
      </dl>
    </div>
    <div>
      <h2>Contact</h2>
      <ul class="footer-list">
        <li><a href="<?= e(tel_link($garage['phone'])) ?>"><?= e(format_phone($garage['phone'])) ?></a></li>
        <li><a href="<?= e(whatsapp_link($garage['whatsapp'])) ?>" rel="noopener" target="_blank">WhatsApp us</a></li>
        <li><a href="mailto:<?= e($garage['email']) ?>"><?= e($garage['email']) ?></a></li>
        <li><?= e($garage['address']) ?></li>
      </ul>
    </div>
    <div>
      <h2>Quick links</h2>
      <ul class="footer-list">
        <li><a href="<?= e(url('book.php')) ?>">Book a service</a></li>
        <li><a href="<?= e(url('breakdown.php')) ?>">Breakdown help</a></li>
        <li><a href="<?= e(url('faq.php')) ?>">Self-help guides</a></li>
        <li><a href="<?= e(url('track.php')) ?>">Track a booking</a></li>
        <li><a href="<?= e(url('account.php')) ?>">My account</a></li>
      </ul>
    </div>
  </div>
  <div class="container footer-bottom">
    <p>&copy; <?= date('Y') ?> <?= e($garage['full_name']) ?>. All rights reserved.</p>
    <a href="<?= e(url('admin/login.php')) ?>">Staff login</a>
  </div>
</footer>

<nav class="mobile-actions" aria-label="Quick actions">
  <a href="<?= e(tel_link($garage['phone'])) ?>">Call us</a>
  <a class="is-danger" href="<?= e(url('breakdown.php')) ?>">Breakdown</a>
  <a class="is-primary" href="<?= e(url('book.php')) ?>">Book</a>
</nav>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
