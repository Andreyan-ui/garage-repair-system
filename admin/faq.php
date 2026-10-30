<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$categories = faq_categories();
$id = (int) query_param('id');
$faq = null;
if ($id > 0) {
    $faq = db_one('SELECT * FROM faqs WHERE id = ?', 'i', [$id]);
    if ($faq === null) {
        abort(404, 'Guide not found', 'That self-help guide does not exist.');
    }
}

$defaultCategory = query_param('category');
$values = [
    'category' => $faq['category'] ?? (isset($categories[$defaultCategory]) ? $defaultCategory : array_key_first($categories)),
    'question' => $faq['question'] ?? '',
    'answer' => $faq['answer'] ?? '',
    'call_us_when' => (string) ($faq['call_us_when'] ?? ''),
    'sort_order' => (string) ($faq['sort_order'] ?? '10'),
    'is_published' => (string) ($faq['is_published'] ?? '1'),
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete' && $faq) {
        db_execute('DELETE FROM faqs WHERE id = ?', 'i', [$id]);
        flash('success', 'Guide deleted.');
        redirect('admin/faqs.php');
    }

    if ($action === 'save') {
        $values['category'] = input('category');
        $values['question'] = input('question');
        $values['answer'] = input('answer');
        $values['call_us_when'] = input('call_us_when');
        $values['sort_order'] = input('sort_order', '0');
        $values['is_published'] = isset($_POST['is_published']) ? '1' : '0';

        if (!isset($categories[$values['category']])) {
            $errors['category'] = 'Choose a topic.';
        }
        if ($values['question'] === '' || too_long($values['question'], 200)) {
            $errors['question'] = 'Enter the question or problem, up to 200 characters.';
        }
        if ($values['answer'] === '' || too_long($values['answer'], 5000)) {
            $errors['answer'] = 'Enter the steps, up to 5000 characters.';
        }
        if (too_long($values['call_us_when'], 500)) {
            $errors['call_us_when'] = 'Keep this under 500 characters.';
        }
        $sortOrder = filter_var($values['sort_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => -100000, 'max_range' => 100000]]);
        if ($sortOrder === false) {
            $errors['sort_order'] = 'Enter a whole number.';
        }

        if (!$errors) {
            $params = [
                $values['category'],
                $values['question'],
                $values['answer'],
                $values['call_us_when'] !== '' ? $values['call_us_when'] : null,
                $sortOrder,
                (int) $values['is_published'],
            ];
            if ($faq) {
                db_execute(
                    'UPDATE faqs SET category = ?, question = ?, answer = ?, call_us_when = ?, sort_order = ?, is_published = ? WHERE id = ?',
                    'ssssiii',
                    array_merge($params, [$id])
                );
                flash('success', 'Guide updated.');
                redirect('admin/faq.php?id=' . $id);
            }
            $newId = db_insert(
                'INSERT INTO faqs (category, question, answer, call_us_when, sort_order, is_published) VALUES (?, ?, ?, ?, ?, ?)',
                'ssssii',
                $params
            );
            flash('success', 'Guide added.');
            redirect('admin/faq.php?id=' . $newId);
        }
    }
}

$pageTitle = $faq ? 'Edit guide' : 'Add a guide';
$activeNav = 'faqs.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/faqs.php')) ?>">&larr; All guides</a></p>
<div class="page-head">
  <h1><?= $faq ? 'Edit guide' : 'Add a self-help guide' ?></h1>
  <?php if ($faq && $faq['is_published']): ?>
    <a class="btn btn-outline" href="<?= e(url('faq.php#faq-' . $faq['id'])) ?>" target="_blank" rel="noopener">View on website</a>
  <?php endif; ?>
</div>

<div class="detail-grid">
  <form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?= error_summary($errors) ?>
    <div class="field-row">
      <div class="field">
        <label for="category">Topic</label>
        <select id="category" name="category"<?= field_attrs($errors, 'category') ?>>
          <?php foreach ($categories as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= selected($values['category'], $key) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'category') ?>
      </div>
      <div class="field">
        <label for="sort_order">Display order</label>
        <input id="sort_order" name="sort_order" type="number" step="1" value="<?= e($values['sort_order']) ?>"<?= field_attrs($errors, 'sort_order') ?>>
        <?= field_error($errors, 'sort_order') ?>
      </div>
    </div>
    <div class="field">
      <label for="question">Question or problem</label>
      <input id="question" name="question" type="text" maxlength="200" required placeholder="e.g. My car won't start and I hear a clicking sound" value="<?= e($values['question']) ?>"<?= field_attrs($errors, 'question') ?>>
      <?= field_error($errors, 'question') ?>
    </div>
    <div class="field">
      <label for="answer">Steps</label>
      <textarea id="answer" name="answer" rows="12" maxlength="5000" required<?= field_attrs($errors, 'answer') ?>><?= e($values['answer']) ?></textarea>
      <p class="hint">Put each step on its own line. Start a line with "1.", "2." for numbered steps, or "-" for bullet points. Other lines show as normal text.</p>
      <?= field_error($errors, 'answer') ?>
    </div>
    <div class="field">
      <label for="call_us_when">Call us instead if&hellip; <span class="optional">optional</span></label>
      <textarea id="call_us_when" name="call_us_when" rows="2" maxlength="500"<?= field_attrs($errors, 'call_us_when') ?>><?= e($values['call_us_when']) ?></textarea>
      <p class="hint">When it is no longer safe or sensible to fix it yourself. Shown with breakdown and call buttons.</p>
      <?= field_error($errors, 'call_us_when') ?>
    </div>
    <div class="field">
      <label class="inline-check"><input type="checkbox" name="is_published" value="1"<?= checked($values['is_published'], '1') ?>> Published on the website</label>
    </div>
    <button type="submit" class="btn btn-dark btn-lg"><?= $faq ? 'Save guide' : 'Add guide' ?></button>
  </form>

  <div class="stack-lg">
    <?php if ($values['answer'] !== ''): ?>
      <section class="card">
        <h2>Preview</h2>
        <div class="preview faq-body">
          <p><strong><?= e($values['question']) ?></strong></p>
          <?= render_steps($values['answer']) ?>
          <?php if ($values['call_us_when'] !== ''): ?>
            <div class="call-us-box"><strong>Call us instead if:</strong><p><?= e($values['call_us_when']) ?></p></div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($faq): ?>
      <form method="post" class="card danger-zone" data-confirm="Delete this guide?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <h2>Delete guide</h2>
        <p class="muted">To hide it temporarily, untick "Published" instead.</p>
        <button type="submit" class="btn btn-ghost">Delete guide</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
