<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$id = (int) query_param('id');
$service = null;
$bookingCount = 0;
if ($id > 0) {
    $service = db_one('SELECT * FROM services WHERE id = ?', 'i', [$id]);
    if ($service === null) {
        abort(404, 'Service not found', 'That service does not exist.');
    }
    $bookingCount = (int) db_value('SELECT COUNT(*) FROM bookings WHERE service_id = ?', 'i', [$id]);
}

$values = [
    'name' => $service['name'] ?? '',
    'description' => $service['description'] ?? '',
    'image' => (string) ($service['image'] ?? ''),
    'sort_order' => (string) ($service['sort_order'] ?? ((int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM services') + 10)),
    'is_active' => (string) ($service['is_active'] ?? '1'),
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete' && $service) {
        if ($bookingCount > 0) {
            flash('error', 'This service has bookings, so it cannot be deleted. Hide it instead.');
            redirect('admin/service.php?id=' . $id);
        }
        db_execute('DELETE FROM services WHERE id = ?', 'i', [$id]);
        flash('success', 'Service deleted.');
        redirect('admin/services.php');
    }

    if ($action === 'save') {
        $values['name'] = input('name');
        $values['description'] = input('description');
        $values['image'] = input('image');
        $values['sort_order'] = input('sort_order', '0');
        $values['is_active'] = isset($_POST['is_active']) ? '1' : '0';

        if ($values['name'] === '' || too_long($values['name'], 80)) {
            $errors['name'] = 'Enter a name of up to 80 characters.';
        }
        if (too_long($values['description'], 255)) {
            $errors['description'] = 'Keep the description under 255 characters.';
        }
        if ($values['image'] !== '' && !in_array($values['image'], gallery_images(), true)) {
            $errors['image'] = 'Choose one of the photos shown.';
        }
        $image = $values['image'] !== '' ? $values['image'] : null;
        $sortOrder = filter_var($values['sort_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => -100000, 'max_range' => 100000]]);
        if ($sortOrder === false) {
            $errors['sort_order'] = 'Enter a whole number.';
        }

        if (!$errors) {
            try {
                if ($service) {
                    db_execute(
                        'UPDATE services SET name = ?, description = ?, image = ?, sort_order = ?, is_active = ? WHERE id = ?',
                        'sssiii',
                        [$values['name'], $values['description'], $image, $sortOrder, (int) $values['is_active'], $id]
                    );
                    flash('success', 'Service updated.');
                } else {
                    db_insert(
                        'INSERT INTO services (name, description, image, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
                        'sssii',
                        [$values['name'], $values['description'], $image, $sortOrder, (int) $values['is_active']]
                    );
                    flash('success', 'Service added.');
                }
                redirect('admin/services.php');
            } catch (mysqli_sql_exception $e) {
                if (!is_duplicate_key($e, 'uq_services_name')) {
                    throw $e;
                }
                $errors['name'] = 'A service with this name already exists.';
            }
        }
    }
}

$pageTitle = $service ? 'Edit service' : 'Add a service';
$activeNav = 'services.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/services.php')) ?>">&larr; All services</a></p>
<div class="page-head">
  <h1><?= $service ? e($service['name']) : 'Add a service' ?></h1>
</div>

<div class="detail-grid">
  <form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?= error_summary($errors) ?>
    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" maxlength="80" required value="<?= e($values['name']) ?>"<?= field_attrs($errors, 'name') ?>>
      <?= field_error($errors, 'name') ?>
    </div>
    <div class="field">
      <label for="description">Short description</label>
      <textarea id="description" name="description" rows="2" maxlength="255"<?= field_attrs($errors, 'description') ?>><?= e($values['description']) ?></textarea>
      <p class="hint">One sentence shown on the services page.</p>
      <?= field_error($errors, 'description') ?>
    </div>
    <fieldset class="field photo-picker"<?= field_attrs($errors, 'image') ?>>
      <legend class="label">Photo on the website</legend>
      <p class="hint">Add more photos by copying them into the images folder.</p>
      <div class="photo-options">
        <label class="photo-option">
          <input type="radio" name="image" value=""<?= checked($values['image'], '') ?>>
          <span class="photo-none"><?= icon('wrench') ?> Default</span>
        </label>
        <?php foreach (gallery_images() as $file): ?>
          <label class="photo-option">
            <input type="radio" name="image" value="<?= e($file) ?>"<?= checked($values['image'], $file) ?>>
            <img src="<?= e(asset('images/' . $file)) ?>" alt="" loading="lazy" decoding="async">
            <span class="visually-hidden"><?= e($file) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= field_error($errors, 'image') ?>
    </fieldset>
    <div class="field-row">
      <div class="field">
        <label for="sort_order">Display order</label>
        <input id="sort_order" name="sort_order" type="number" step="1" value="<?= e($values['sort_order']) ?>"<?= field_attrs($errors, 'sort_order') ?>>
        <p class="hint">Lower numbers appear first.</p>
        <?= field_error($errors, 'sort_order') ?>
      </div>
      <div class="field">
        <span class="label">Visibility</span>
        <label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= checked($values['is_active'], '1') ?>> Customers can book this</label>
      </div>
    </div>
    <button type="submit" class="btn btn-dark btn-lg"><?= $service ? 'Save service' : 'Add service' ?></button>
  </form>

  <?php if ($service): ?>
    <div class="stack-lg">
      <section class="card">
        <h2>Usage</h2>
        <p><?= $bookingCount ?> <?= $bookingCount === 1 ? 'booking uses' : 'bookings use' ?> this service.</p>
        <p class="muted">Hiding a service removes it from the website and booking form. Existing bookings keep it.</p>
      </section>
      <form method="post" class="card danger-zone" data-confirm="Delete <?= e($service['name']) ?>?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <h2>Delete service</h2>
        <?php if ($bookingCount > 0): ?>
          <p class="muted">Services with bookings can't be deleted, because those bookings would lose their record. Untick "Customers can book this" to hide it instead.</p>
        <?php else: ?>
          <p class="muted">No bookings use this service, so it can be deleted safely.</p>
          <button type="submit" class="btn btn-ghost">Delete service</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
