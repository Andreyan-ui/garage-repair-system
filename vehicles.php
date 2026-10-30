<?php
require __DIR__ . '/includes/bootstrap.php';

$customer = require_customer();
$editId = (int) query_param('edit');
$maxYear = (int) date('Y') + 1;

$editing = $editId > 0
    ? db_one('SELECT * FROM vehicles WHERE id = ? AND customer_id = ?', 'ii', [$editId, $customer['id']])
    : null;
if ($editId > 0 && $editing === null) {
    abort(404, 'Vehicle not found', 'That vehicle is not on your account.');
}

$values = [
    'plate_number' => $editing['plate_number'] ?? '',
    'make' => $editing['make'] ?? '',
    'model' => $editing['model'] ?? '',
    'model_year' => isset($editing['model_year']) ? (string) $editing['model_year'] : '',
    'colour' => $editing['colour'] ?? '',
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete') {
        $deleted = db_execute('DELETE FROM vehicles WHERE id = ? AND customer_id = ?', 'ii', [(int) input('id'), $customer['id']]);
        flash($deleted ? 'success' : 'error', $deleted ? 'Vehicle removed. Your past bookings are kept.' : 'That vehicle could not be removed.');
        redirect('vehicles.php');
    }

    if ($action === 'save') {
        foreach (array_keys($values) as $key) {
            $values[$key] = input($key);
        }
        $plate = normalize_plate($values['plate_number']);
        if ($plate === null) {
            $errors['plate_number'] = 'Enter a valid number plate, for example UBA 123K.';
        } else {
            $clash = db_value(
                'SELECT id FROM vehicles WHERE customer_id = ? AND plate_number = ? AND id <> ?',
                'isi',
                [$customer['id'], $plate, $editing['id'] ?? 0]
            );
            if ($clash !== null) {
                $errors['plate_number'] = 'You have already saved a vehicle with this plate.';
            }
        }
        if ($values['make'] === '' || too_long($values['make'], 50)) {
            $errors['make'] = 'Enter the make, for example Toyota.';
        }
        if ($values['model'] === '' || too_long($values['model'], 50)) {
            $errors['model'] = 'Enter the model, for example Premio.';
        }
        $year = null;
        if ($values['model_year'] !== '') {
            $year = filter_var($values['model_year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => $maxYear]]);
            if ($year === false) {
                $errors['model_year'] = 'Enter a year between 1950 and ' . $maxYear . ', or leave it blank.';
            }
        }
        if (too_long($values['colour'], 30)) {
            $errors['colour'] = 'Keep the colour under 30 characters.';
        }

        if (!$errors) {
            $colour = $values['colour'] !== '' ? $values['colour'] : null;
            if ($editing) {
                db_execute(
                    'UPDATE vehicles SET plate_number = ?, make = ?, model = ?, model_year = ?, colour = ? WHERE id = ? AND customer_id = ?',
                    'sssisii',
                    [$plate, $values['make'], $values['model'], $year, $colour, $editing['id'], $customer['id']]
                );
                flash('success', 'Vehicle updated.');
            } else {
                db_insert(
                    'INSERT INTO vehicles (customer_id, plate_number, make, model, model_year, colour) VALUES (?, ?, ?, ?, ?, ?)',
                    'isssis',
                    [$customer['id'], $plate, $values['make'], $values['model'], $year, $colour]
                );
                flash('success', 'Vehicle saved. You can now pick it when you book.');
            }
            redirect('vehicles.php');
        }
    }
}

$vehicles = db_all(
    'SELECT v.*, (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS booking_count,
            (SELECT MAX(b.preferred_date) FROM bookings b WHERE b.vehicle_id = v.id AND b.status = \'completed\') AS last_service
     FROM vehicles v
     WHERE v.customer_id = ?
     ORDER BY v.created_at',
    'i',
    [$customer['id']]
);

$pageTitle = 'My vehicles';
$activeNav = 'vehicles.php';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
  <div class="container">
    <p><a class="back-link" href="<?= e(url('account.php')) ?>">&larr; My account</a></p>
    <h1>My vehicles</h1>
    <p class="lead">Save your cars once and pick them in seconds when you book.</p>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div>
      <?php if (!$vehicles): ?>
        <div class="card empty-state"><p>You haven't saved any vehicles yet. Add one using the form.</p></div>
      <?php else: ?>
        <div class="vehicle-grid">
          <?php foreach ($vehicles as $vehicle): ?>
            <article class="card vehicle-card<?= $editing && (int) $editing['id'] === (int) $vehicle['id'] ? ' is-editing' : '' ?>">
              <span class="plate plate-lg"><?= e($vehicle['plate_number']) ?></span>
              <h2><?= e($vehicle['make'] . ' ' . $vehicle['model']) ?></h2>
              <p class="muted">
                <?= e(implode(' · ', array_filter([$vehicle['colour'], $vehicle['model_year'] ? (string) $vehicle['model_year'] : null]))) ?: '&nbsp;' ?>
              </p>
              <p class="small">
                <?= (int) $vehicle['booking_count'] ?> <?= (int) $vehicle['booking_count'] === 1 ? 'booking' : 'bookings' ?>
                <?php if ($vehicle['last_service']): ?>
                  &middot; last serviced <?= e(format_date($vehicle['last_service'], 'j M Y')) ?>
                <?php endif; ?>
              </p>
              <div class="button-row">
                <a class="btn btn-primary btn-sm" href="<?= e(url('book.php')) ?>">Book</a>
                <a class="btn btn-outline btn-sm" href="<?= e(url('vehicles.php?edit=' . $vehicle['id'])) ?>">Edit</a>
                <form method="post" class="inline-form" data-confirm="Remove <?= e($vehicle['plate_number']) ?> from your account? Past bookings will be kept.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $vehicle['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="sidebar">
      <form method="post" class="card form-card" id="vehicle-form" action="<?= e(url($editing ? 'vehicles.php?edit=' . $editing['id'] : 'vehicles.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <h2 class="card-title"><?= $editing ? 'Edit ' . e($editing['plate_number']) : 'Add a vehicle' ?></h2>
        <?= error_summary($errors) ?>
        <div class="field">
          <label for="plate_number">Number plate</label>
          <input id="plate_number" name="plate_number" type="text" maxlength="15" autocapitalize="characters" required placeholder="UBA 123K" value="<?= e($values['plate_number']) ?>"<?= field_attrs($errors, 'plate_number') ?>>
          <?= field_error($errors, 'plate_number') ?>
        </div>
        <div class="field">
          <label for="make">Make</label>
          <input id="make" name="make" type="text" maxlength="50" required placeholder="Toyota" value="<?= e($values['make']) ?>"<?= field_attrs($errors, 'make') ?>>
          <?= field_error($errors, 'make') ?>
        </div>
        <div class="field">
          <label for="model">Model</label>
          <input id="model" name="model" type="text" maxlength="50" required placeholder="Premio" value="<?= e($values['model']) ?>"<?= field_attrs($errors, 'model') ?>>
          <?= field_error($errors, 'model') ?>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="model_year">Year <span class="optional">optional</span></label>
            <input id="model_year" name="model_year" type="number" min="1950" max="<?= $maxYear ?>" inputmode="numeric" value="<?= e($values['model_year']) ?>"<?= field_attrs($errors, 'model_year') ?>>
            <?= field_error($errors, 'model_year') ?>
          </div>
          <div class="field">
            <label for="colour">Colour <span class="optional">optional</span></label>
            <input id="colour" name="colour" type="text" maxlength="30" value="<?= e($values['colour']) ?>"<?= field_attrs($errors, 'colour') ?>>
            <?= field_error($errors, 'colour') ?>
          </div>
        </div>
        <button type="submit" class="btn btn-dark btn-block"><?= $editing ? 'Save changes' : 'Save vehicle' ?></button>
        <?php if ($editing): ?>
          <p class="center"><a href="<?= e(url('vehicles.php')) ?>">Cancel editing</a></p>
        <?php endif; ?>
      </form>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
