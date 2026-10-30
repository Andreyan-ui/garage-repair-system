<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$values = ['full_name' => '', 'phone' => '', 'specialty' => ''];
$errors = [];

if (is_post()) {
    verify_csrf();
    foreach (array_keys($values) as $field) {
        $values[$field] = input($field);
    }
    if ($values['full_name'] === '' || too_long($values['full_name'], 100)) {
        $errors['full_name'] = 'Enter the mechanic\'s name.';
    }
    $phone = null;
    if ($values['phone'] !== '') {
        $phone = normalize_phone($values['phone']);
        if ($phone === null) {
            $errors['phone'] = 'Enter a valid phone number, or leave it blank.';
        }
    }
    if (too_long($values['specialty'], 100)) {
        $errors['specialty'] = 'Keep this under 100 characters.';
    }
    if (!$errors) {
        $newId = db_insert(
            'INSERT INTO mechanics (full_name, phone, specialty) VALUES (?, ?, ?)',
            'sss',
            [$values['full_name'], $phone, $values['specialty'] !== '' ? $values['specialty'] : null]
        );
        flash('success', $values['full_name'] . ' added. They can now be assigned to jobs and breakdowns.');
        redirect('admin/mechanic.php?id=' . $newId);
    }
}

[$monthStart, $monthEnd] = month_bounds(date('Y-m'));
$mechanics = db_all(
    "SELECT m.*,
            (SELECT COUNT(*) FROM jobs j WHERE j.mechanic_id = m.id AND j.status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval')) AS open_jobs,
            (SELECT COUNT(*) FROM jobs j WHERE j.mechanic_id = m.id AND j.ready_at >= ? AND j.ready_at < ?) AS finished_this_month,
            (SELECT COUNT(*) FROM breakdown_requests b WHERE b.mechanic_id = m.id AND b.status IN ('dispatched', 'on_site')) AS on_the_road
     FROM mechanics m
     ORDER BY m.is_active DESC, m.full_name",
    'ss',
    [$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]
);

$pageTitle = 'Mechanics';
$activeNav = 'mechanics.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Mechanics</h1>
    <p class="muted">The people who do the work. They are assigned to job cards and breakdown call-outs, and do not need a login.</p>
  </div>
</div>

<div class="detail-grid">
  <div class="card table-wrap">
    <?php if (!$mechanics): ?>
      <p class="empty-state">No mechanics yet. Add your team using the form.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Phone</th>
            <th class="num">Open jobs</th>
            <th class="num">Finished in <?= e($monthStart->format('F')) ?></th>
            <th>Now</th>
            <th><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mechanics as $mechanic): ?>
            <tr class="<?= $mechanic['is_active'] ? '' : 'is-muted' ?>">
              <td><a class="row-link" href="<?= e(url('admin/mechanic.php?id=' . $mechanic['id'])) ?>"><?= e($mechanic['full_name']) ?></a><span class="muted"><?= e($mechanic['specialty'] ?? '') ?></span></td>
              <td class="nowrap"><?= $mechanic['phone'] ? '<a href="' . e(tel_link($mechanic['phone'])) . '">' . e(format_phone($mechanic['phone'])) . '</a>' : '<span class="muted">-</span>' ?></td>
              <td class="num"><?= (int) $mechanic['open_jobs'] ?></td>
              <td class="num"><?= (int) $mechanic['finished_this_month'] ?></td>
              <td>
                <?php if (!$mechanic['is_active']): ?>
                  <span class="badge badge-gray">Inactive</span>
                <?php elseif ((int) $mechanic['on_the_road'] > 0): ?>
                  <span class="badge badge-amber">On a breakdown</span>
                <?php elseif ((int) $mechanic['open_jobs'] > 0): ?>
                  <span class="badge badge-purple">In the workshop</span>
                <?php else: ?>
                  <span class="badge badge-green">Available</span>
                <?php endif; ?>
              </td>
              <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/mechanic.php?id=' . $mechanic['id'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <form method="post" class="card form-card">
    <?= csrf_field() ?>
    <h2 class="card-title">Add a mechanic</h2>
    <?= error_summary($errors) ?>
    <div class="field">
      <label for="full_name">Name</label>
      <input id="full_name" name="full_name" type="text" maxlength="100" required value="<?= e($values['full_name']) ?>"<?= field_attrs($errors, 'full_name') ?>>
      <?= field_error($errors, 'full_name') ?>
    </div>
    <div class="field">
      <label for="phone">Phone <span class="optional">shown to breakdown customers</span></label>
      <input id="phone" name="phone" type="tel" maxlength="20" value="<?= e($values['phone']) ?>"<?= field_attrs($errors, 'phone') ?>>
      <?= field_error($errors, 'phone') ?>
    </div>
    <div class="field">
      <label for="specialty">Speciality <span class="optional">optional</span></label>
      <input id="specialty" name="specialty" type="text" maxlength="100" placeholder="e.g. Engines and diagnostics" value="<?= e($values['specialty']) ?>"<?= field_attrs($errors, 'specialty') ?>>
      <?= field_error($errors, 'specialty') ?>
    </div>
    <button type="submit" class="btn btn-dark btn-block">Add mechanic</button>
  </form>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
