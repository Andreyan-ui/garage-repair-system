<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');
$categories = faq_categories();
$faqs = db_all('SELECT id, category, question, answer, call_us_when FROM faqs WHERE is_published = 1 ORDER BY sort_order, id');
$diagnosticsId = db_value("SELECT id FROM services WHERE name = 'Diagnostics' AND is_active = 1");

$grouped = [];
foreach ($faqs as $faq) {
    $grouped[$faq['category']][] = $faq;
}
$ordered = [];
foreach ($categories as $key => $label) {
    if (!empty($grouped[$key])) {
        $ordered[$key] = ['label' => $label, 'faqs' => $grouped[$key]];
    }
}
foreach ($grouped as $key => $items) {
    if (!isset($ordered[$key])) {
        $ordered[$key] = ['label' => ucwords(str_replace('_', ' ', $key)), 'faqs' => $items];
    }
}

$pageTitle = 'Self-help guides';
$pageDescription = 'Simple, safe fixes for common car problems you can sort out yourself.';
$activeNav = 'faq.php';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header has-photo">
  <div class="container page-header-grid">
    <div>
      <p class="eyebrow">Self-help</p>
      <h1>Fix it yourself</h1>
      <p class="lead">Quick, safe guides for small problems. If a fix doesn't feel safe, stop and call us. That's what we're here for.</p>
      <div class="search-box">
        <label for="faq-search" class="visually-hidden">Search the guides</label>
        <span class="search-icon"><?= icon('search') ?></span>
        <input id="faq-search" type="search" placeholder="Search, e.g. battery, tyre, overheating" autocomplete="off" data-faq-search>
      </div>
    </div>
    <figure class="page-header-figure"><?= photo('diagnosis3.jpg', 'Checking dashboard warning lights with a scanner', '', true) ?></figure>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div>
      <?php if (!$ordered): ?>
        <div class="empty-state card">
          <h2>No guides yet</h2>
          <p>We are working on them. In the meantime, call us and we will talk you through it.</p>
        </div>
      <?php endif; ?>

      <?php foreach ($ordered as $key => $group): ?>
        <section class="faq-group" id="category-<?= e($key) ?>" data-faq-group>
          <h2><?= e($group['label']) ?></h2>
          <?php foreach ($group['faqs'] as $faq): ?>
            <details class="faq-item" id="faq-<?= (int) $faq['id'] ?>" data-faq-item>
              <summary><?= e($faq['question']) ?></summary>
              <div class="faq-body">
                <?= render_steps($faq['answer']) ?>
                <?php if (!empty($faq['call_us_when'])): ?>
                  <div class="call-us-box">
                    <strong>Call us instead if:</strong>
                    <p><?= e($faq['call_us_when']) ?></p>
                    <div class="button-row">
                      <a class="btn btn-danger btn-sm" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
                      <a class="btn btn-outline btn-sm" href="<?= e(tel_link($garage['phone'])) ?>">Call <?= e(format_phone($garage['phone'])) ?></a>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <div class="empty-state card" data-faq-empty hidden>
        <h2>No guides match your search</h2>
        <p>Try another word, or call us on <a href="<?= e(tel_link($garage['phone'])) ?>"><?= e(format_phone($garage['phone'])) ?></a> and we will help.</p>
      </div>
    </div>

    <aside class="sidebar">
      <?php if (count($ordered) > 1): ?>
        <nav class="card" aria-label="Guide topics">
          <h2>Topics</h2>
          <ul class="link-list">
            <?php foreach ($ordered as $key => $group): ?>
              <li><a href="#category-<?= e($key) ?>"><?= e($group['label']) ?> <span class="muted">(<?= count($group['faqs']) ?>)</span></a></li>
            <?php endforeach; ?>
          </ul>
        </nav>
      <?php endif; ?>
      <div class="card safety-card">
        <h2>Safety first</h2>
        <p>Never work on a car next to moving traffic, under a car held up only by a jack, or on a hot engine. If you are unsure, don't attempt it.</p>
      </div>
      <div class="card">
        <h2>Still stuck?</h2>
        <p class="muted">We can come to you, or book your car in.</p>
        <div class="stack">
          <a class="btn btn-danger btn-block" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
          <a class="btn btn-outline btn-block" href="<?= e(url($diagnosticsId ? 'book.php?service=' . $diagnosticsId : 'book.php')) ?>"><?= $diagnosticsId ? 'Book a diagnostics check' : 'Book a service' ?></a>
        </div>
      </div>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
