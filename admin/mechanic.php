<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$id = (int) query_param('id');
$mechanic = db_one('SELECT * FROM mechanics WHERE id = ?', 'i', [$id]);
if ($mechanic === null) {
    abort(404, 'Mechanic not found', 'That mechanic does not exist.');
}
$statuses = job_statuses();
$breakdownStatuses = breakdown_statuses();
$problems = problem_types();

$values = [
    'full_name' => $mechanic['full_name'],
    'phone' => format_phone($mechanic['phone']),
    'specialty' => (string) $mechanic['specialty'],
    'is_active' => (string) $mechanic['is_active'],
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'save') {
        foreach (['full_name', 'phone', 'specialty'] as $field) {
            $values[$field] = input($field);
        }
        $values['is_active'] = isset($_POST['is_active']) ? '1' : '0';
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
            db_execute(
                'UPDATE mechanics SET full_name = ?, phone = ?, specialty = ?, is_active = ? WHERE id = ?',
                'sssii',
                [$values['full_name'], $phone, $values['specialty'] !== '' ? $values['specialty'] : null, (int) $values['is_active'], $id]
            );
            flash('success', 'Saved.');
            redirect('admin/mechanic.php?id=' . $id);
        }
    }

    if ($action === 'delete') {
        $used = (int) db_value('SELECT COUNT(*) FROM jobs WHERE mechanic_id = ?', 'i', [$id])
            + (int) db_value('SELECT COUNT(*) FROM job_items WHERE mechanic_id = ?', 'i', [$id])
            + (int) db_value('SELECT COUNT(*) FROM breakdown_requests WHERE mechanic_id = ?', 'i', [$id]);
        if ($used > 0) {
            flash('error', $mechanic['full_name'] . ' has work on record, so they cannot be deleted. Untick "Active" instead.');
            redirect('admin/mechanic.php?id=' . $id);
        }
        db_execute('DELETE FROM mechanics WHERE id = ?', 'i', [$id]);
        flash('success', $mechanic['full_name'] . ' was removed.');
        redirect('admin/mechanics.php');
    }
}

$month = query_param('month', date('Y-m'));
[$monthStart, $monthEnd] = month_bounds($month);
$from = $monthStart->format('Y-m-d H:i:s');
$to = $monthEnd->format('Y-m-d H:i:s');

$stats = db_one(
    "SELECT
        (SELECT COUNT(*) FROM jobs WHERE mechanic_id = ? AND created_at >= ? AND created_at < ?) AS jobs_started,
        (SELECT COUNT(*) FROM jobs WHERE mechanic_id = ? AND ready_at >= ? AND ready_at < ?) AS jobs_finished,
        (SELECT COALESCE(SUM(ROUND(quantity * unit_price)), 0) FROM job_items WHERE mechanic_id = ? AND item_type = 'labour' AND created_at >= ? AND created_at < ?) AS labour_value,
        (SELECT COUNT(*) FROM job_items WHERE mechanic_id = ? AND item_type = 'labour' AND created_at >= ? AND created_at < ?) AS labour_lines,
        (SELECT COUNT(*) FROM breakdown_requests WHERE mechanic_id = ? AND created_at >= ? AND created_at < ?) AS callouts",
    'issississississ',
    [$id, $from, $to, $id, $from, $to, $id, $from, $to, $id, $from, $to, $id, $from, $to]
);
$openJobs = db_all(
    "SELECT id, status, vehicle_plate, vehicle_make, vehicle_model, complaint, promised_at
     FROM jobs WHERE mechanic_id = ? AND status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval', 'ready')
     ORDER BY created_at",
    'i',
    [$id]
);
$labour = db_all(
    "SELECT ji.description, ji.quantity, ji.unit_price, ji.created_at, j.id AS job_id, j.vehicle_plate
     FROM job_items ji JOIN jobs j ON j.id = ji.job_id
     WHERE ji.mechanic_id = ? AND ji.item_type = 'labour' AND ji.created_at >= ? AND ji.created_at < ?
     ORDER BY ji.created_at DESC",
    'iss',
    [$id, $from, $to]
);
$callouts = db_all(
    'SELECT id, reference, problem_type, location_text, status, created_at FROM breakdown_requests
     WHERE mechanic_id = ? ORDER BY created_at DESC LIMIT 10',
    'i',
    [$id]
);
$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthEnd->format('Y-m');

$pageTitle = $mechanic['full_name'];
$activeNav = 'mechanics.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/mechanics.php')) ?>">&larr; Mechanics</a></p>
<div class="page-head">
  <div>
    <h1><?= e($mechanic['full_name']) ?></h1>
    <p class="muted"><?= e(implode(' · ', array_filter([$mechanic['specialty'], format_phone($mechanic['phone'])]))) ?></p>
  </div>
  <nav class="month-nav" aria-label="Choose month">
    <a class="btn btn-outline btn-sm" href="<?= e(url('admin/mechanic.php?id=' . $id . '&month=' . $prevMonth)) ?>">&larr; Earlier</a>
    <strong><?= e($monthStart->format('F Y')) ?></strong>
    <?php if ($monthEnd < new DateTimeImmutable('first day of next month midnight')): ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url('admin/mechanic.php?id=' . $id . '&month=' . $nextMonth)) ?>">Later &rarr;</a>
    <?php endif; ?>
  </nav>
</div>

<div class="stat-grid">
  <div class="stat"><span class="stat-value"><?= (int) $stats['jobs_started'] ?></span><span class="stat-label">Jobs started</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['jobs_finished'] ?></span><span class="stat-label">Jobs finished</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['labour_lines'] ?></span><span class="stat-label">Labour lines</span></div>
  <div class="stat stat-money"><span class="stat-value"><?= e(money($stats['labour_value'])) ?></span><span class="stat-label">Labour charged</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $stats['callouts'] ?></span><span class="stat-label">Breakdown call-outs</span></div>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <section class="card panel">
      <div class="panel-head"><h2>Current jobs (<?= count($openJobs) ?>)</h2></div>
      <?php if (!$openJobs): ?>
        <p class="empty-state">No open jobs assigned.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Job</th><th>Vehicle</th><th>Concern</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($openJobs as $job): ?>
                <tr>
                  <td><a class="row-link" href="<?= e(url('admin/job.php?id=' . $job['id'])) ?>"><?= e(job_number($job['id'])) ?></a></td>
                  <td><span class="plate"><?= e($job['vehicle_plate']) ?></span><span class="muted"><?= e($job['vehicle_make'] . ' ' . $job['vehicle_model']) ?></span></td>
                  <td><?= e(mb_strimwidth($job['complaint'], 0, 60, '...')) ?></td>
                  <td><?= status_badge($job['status'], $statuses, true) ?><?php if ($job['promised_at']): ?><span class="muted">Due <?= e(format_datetime($job['promised_at'])) ?></span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card panel">
      <div class="panel-head"><h2>Labour in <?= e($monthStart->format('F Y')) ?></h2></div>
      <?php if (!$labour): ?>
        <p class="empty-state">No labour recorded this month.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Date</th><th>Work</th><th>Job</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
            <tbody>
              <?php foreach ($labour as $line): ?>
                <tr>
                  <td class="nowrap"><?= e(format_date($line['created_at'], 'j M')) ?></td>
                  <td><?= e($line['description']) ?></td>
                  <td class="nowrap"><a href="<?= e(url('admin/job.php?id=' . $line['job_id'])) ?>"><?= e(job_number($line['job_id'])) ?></a> <span class="muted"><?= e($line['vehicle_plate']) ?></span></td>
                  <td class="num"><?= e(format_quantity($line['quantity'])) ?></td>
                  <td class="num"><?= e(number_format(line_total($line['quantity'], $line['unit_price']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card panel">
      <div class="panel-head"><h2>Recent breakdown call-outs</h2></div>
      <?php if (!$callouts): ?>
        <p class="empty-state">None yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>When</th><th>Problem</th><th>Location</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($callouts as $callout): ?>
                <tr>
                  <td class="nowrap"><a class="row-link" href="<?= e(url('admin/breakdown.php?id=' . $callout['id'])) ?>"><?= e(format_datetime($callout['created_at'])) ?></a></td>
                  <td><?= e($problems[$callout['problem_type']] ?? $callout['problem_type']) ?></td>
                  <td><?= e($callout['location_text']) ?></td>
                  <td><?= status_badge($callout['status'], $breakdownStatuses, true) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack-lg">
    <form method="post" class="card form-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <h2 class="card-title">Details</h2>
      <?= error_summary($errors) ?>
      <div class="field">
        <label for="full_name">Name</label>
        <input id="full_name" name="full_name" type="text" maxlength="100" required value="<?= e($values['full_name']) ?>"<?= field_attrs($errors, 'full_name') ?>>
        <?= field_error($errors, 'full_name') ?>
      </div>
      <div class="field">
        <label for="phone">Phone</label>
        <input id="phone" name="phone" type="tel" maxlength="20" value="<?= e($values['phone']) ?>"<?= field_attrs($errors, 'phone') ?>>
        <?= field_error($errors, 'phone') ?>
      </div>
      <div class="field">
        <label for="specialty">Speciality</label>
        <input id="specialty" name="specialty" type="text" maxlength="100" value="<?= e($values['specialty']) ?>"<?= field_attrs($errors, 'specialty') ?>>
        <?= field_error($errors, 'specialty') ?>
      </div>
      <div class="field">
        <label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= checked($values['is_active'], '1') ?>> Active (can be assigned work)</label>
      </div>
      <button type="submit" class="btn btn-dark btn-block">Save</button>
    </form>

    <form method="post" class="card danger-zone" data-confirm="Remove <?= e($mechanic['full_name']) ?>?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <h2>Remove mechanic</h2>
      <p class="muted">Mechanics with work on record cannot be removed. Untick "Active" when someone leaves, so their history stays correct.</p>
      <button type="submit" class="btn btn-ghost">Remove</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
