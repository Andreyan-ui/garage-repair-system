<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$categories = faq_categories();
$faqs = db_all('SELECT id, category, question, sort_order, is_published, updated_at FROM faqs ORDER BY sort_order, id');
$grouped = [];
foreach ($faqs as $faq) {
    $grouped[$faq['category']][] = $faq;
}

$pageTitle = 'Self-help guides';
$activeNav = 'faqs.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Self-help guides</h1>
    <p class="muted">Short fixes customers can safely do themselves. <a href="<?= e(url('faq.php')) ?>" target="_blank" rel="noopener">View on website</a></p>
  </div>
  <a class="btn btn-primary" href="<?= e(url('admin/faq.php')) ?>">Add a guide</a>
</div>

<?php if (!$faqs): ?>
  <div class="card empty-state"><p>No guides yet.</p></div>
<?php endif; ?>

<div class="stack-lg">
  <?php foreach ($categories + array_fill_keys(array_keys(array_diff_key($grouped, $categories)), null) as $key => $label): ?>
    <?php if (empty($grouped[$key])) continue; ?>
    <section class="card panel">
      <div class="panel-head">
        <h2><?= e($label ?? $key) ?> (<?= count($grouped[$key]) ?>)</h2>
        <a href="<?= e(url('admin/faq.php?category=' . $key)) ?>">Add to this topic</a>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Order</th><th>Question</th><th>Status</th><th>Updated</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
            <?php foreach ($grouped[$key] as $faq): ?>
              <tr>
                <td><?= (int) $faq['sort_order'] ?></td>
                <td><a class="row-link" href="<?= e(url('admin/faq.php?id=' . $faq['id'])) ?>"><?= e($faq['question']) ?></a></td>
                <td><?= $faq['is_published'] ? '<span class="badge badge-green">Published</span>' : '<span class="badge badge-gray">Draft</span>' ?></td>
                <td class="nowrap"><?= e(format_date($faq['updated_at'], 'j M Y')) ?></td>
                <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/faq.php?id=' . $faq['id'])) ?>">Edit</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
