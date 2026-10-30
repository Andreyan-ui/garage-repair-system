<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$statuses = breakdown_statuses();
$problems = problem_types();
$towingOptions = towing_options();
$id = (int) query_param('id');

$request = db_one(
    'SELECT br.*, c.full_name AS account_name
     FROM breakdown_requests br
     LEFT JOIN customers c ON c.id = br.customer_id
     WHERE br.id = ?',
    'i',
    [$id]
);
if ($request === null) {
    abort(404, 'Request not found', 'That breakdown request does not exist or has been deleted.');
}

$values = [
    'status' => $request['status'],
    'mechanic_id' => $request['mechanic_id'] !== null ? (string) $request['mechanic_id'] : ($request['responder_name'] ? 'other' : ''),
    'responder_name' => $request['mechanic_id'] === null ? (string) $request['responder_name'] : '',
    'responder_phone' => $request['mechanic_id'] === null ? format_phone($request['responder_phone']) : '',
    'eta_minutes' => '',
    'garage_note' => (string) $request['garage_note'],
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete') {
        db_execute('DELETE FROM breakdown_requests WHERE id = ?', 'i', [$id]);
        flash('success', 'Request ' . $request['reference'] . ' was deleted.');
        redirect('admin/breakdowns.php');
    }

    if ($action === 'update') {
        foreach (array_keys($values) as $key) {
            $values[$key] = input($key);
        }
        if (!isset($statuses[$values['status']])) {
            $errors['status'] = 'Choose a valid status.';
        }
        $mechanicId = null;
        $responderName = null;
        $responderPhone = null;
        if ($values['mechanic_id'] === 'other') {
            if (too_long($values['responder_name'], 100)) {
                $errors['responder_name'] = 'Keep the name under 100 characters.';
            }
            $responderName = $values['responder_name'] !== '' ? $values['responder_name'] : null;
            if ($values['responder_phone'] !== '') {
                $responderPhone = normalize_phone($values['responder_phone']);
                if ($responderPhone === null) {
                    $errors['responder_phone'] = 'Enter a valid phone number.';
                }
            }
        } elseif ($values['mechanic_id'] !== '') {
            $mechanic = db_one('SELECT id, full_name, phone FROM mechanics WHERE id = ?', 'i', [(int) $values['mechanic_id']]);
            if ($mechanic === null) {
                $errors['mechanic_id'] = 'Choose a mechanic from the list.';
            } else {
                $mechanicId = (int) $mechanic['id'];
                $responderName = $mechanic['full_name'];
                $responderPhone = $mechanic['phone'];
            }
        }
        $etaAt = $request['eta_at'];
        if ($values['eta_minutes'] !== '') {
            $minutes = filter_var($values['eta_minutes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 600]]);
            if ($minutes === false) {
                $errors['eta_minutes'] = 'Enter a number of minutes between 0 and 600.';
            } else {
                $etaAt = date('Y-m-d H:i:s', time() + $minutes * 60);
            }
        }
        if (in_array($values['status'], ['dispatched', 'on_site'], true) && $responderName === null && !isset($errors['mechanic_id'])) {
            $errors['mechanic_id'] = 'Say who is going, so the customer knows who to expect.';
        }
        if (too_long($values['garage_note'], 2000)) {
            $errors['garage_note'] = 'Keep the note under 2000 characters.';
        }

        if (!$errors) {
            $cancelledBy = $request['cancelled_by'];
            if ($values['status'] === 'cancelled' && $request['status'] !== 'cancelled') {
                $cancelledBy = 'garage';
            } elseif ($values['status'] !== 'cancelled') {
                $cancelledBy = null;
            }
            db_execute(
                'UPDATE breakdown_requests
                 SET status = ?, mechanic_id = ?, responder_name = ?, responder_phone = ?, eta_at = ?, garage_note = ?, cancelled_by = ?
                 WHERE id = ?',
                'sisssssi',
                [
                    $values['status'],
                    $mechanicId,
                    $responderName,
                    $responderPhone,
                    $etaAt,
                    $values['garage_note'] !== '' ? $values['garage_note'] : null,
                    $cancelledBy,
                    $id,
                ]
            );
            flash('success', 'Request ' . $request['reference'] . ' updated. The customer will see the change on their tracking page.');
            redirect('admin/breakdown.php?id=' . $id);
        }
    }
}

$minutesAway = minutes_until($request['eta_at']);
$mechanics = active_mechanics($request['mechanic_id']);
$breakdownJob = db_one('SELECT id, status FROM jobs WHERE breakdown_id = ?', 'i', [$id]);

$pageTitle = 'Breakdown ' . $request['reference'];
$activeNav = 'breakdowns.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/breakdowns.php')) ?>">&larr; All breakdown requests</a></p>
<div class="page-head">
  <div>
    <h1><?= e($problems[$request['problem_type']] ?? $request['problem_type']) ?> &middot; <?= e($request['contact_name']) ?></h1>
    <p class="muted"><?= e($request['reference']) ?> &middot; received <?= e(time_ago($request['created_at'])) ?> (<?= e(format_datetime($request['created_at'])) ?>)</p>
  </div>
  <?= status_badge($request['status'], $statuses) ?>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <section class="card">
      <h2>Customer</h2>
      <dl class="details">
        <div><dt>Name</dt><dd><?= e($request['contact_name']) ?></dd></div>
        <div><dt>Phone</dt><dd><?= e(format_phone($request['contact_phone'])) ?></dd></div>
        <div><dt>Account</dt><dd><?= $request['customer_id'] ? '<a href="' . e(url('admin/customer.php?id=' . $request['customer_id'])) . '">' . e($request['account_name']) . '</a>' : '<span class="muted">Guest request</span>' ?></dd></div>
      </dl>
      <div class="contact-actions">
        <a class="btn btn-danger" href="<?= e(tel_link($request['contact_phone'])) ?>">Call customer</a>
        <a class="btn btn-outline" href="<?= e(whatsapp_link($request['contact_phone'], 'Hello ' . $request['contact_name'] . ', this is ' . config('garage.name') . ' about your breakdown request ' . $request['reference'] . '.')) ?>" target="_blank" rel="noopener">WhatsApp</a>
      </div>
    </section>

    <section class="card">
      <h2>Location</h2>
      <p class="status-message"><?= e($request['location_text']) ?></p>
      <?php if ($request['latitude'] !== null): ?>
        <p class="muted">GPS: <?= e($request['latitude']) ?>, <?= e($request['longitude']) ?><?= $request['location_accuracy'] !== null ? ' (accurate to about ' . (int) $request['location_accuracy'] . ' m)' : '' ?></p>
      <?php else: ?>
        <p class="muted">No GPS location was shared. Confirm the landmark on the phone.</p>
      <?php endif; ?>
      <a class="btn btn-dark" href="<?= e(map_link($request['latitude'], $request['longitude'], $request['location_text'])) ?>" target="_blank" rel="noopener"><?= $request['latitude'] !== null ? 'Open GPS pin in Google Maps' : 'Search landmark in Google Maps' ?></a>
    </section>

    <section class="card">
      <h2>Problem and vehicle</h2>
      <dl class="details">
        <div><dt>Problem</dt><dd><?= e($problems[$request['problem_type']] ?? $request['problem_type']) ?></dd></div>
        <div><dt>Towing</dt><dd><?= e($towingOptions[$request['towing']] ?? $request['towing']) ?></dd></div>
        <div><dt>Plate</dt><dd><?= $request['vehicle_plate'] ? '<span class="plate">' . e($request['vehicle_plate']) . '</span>' : '<span class="muted">Not given</span>' ?></dd></div>
        <div><dt>Vehicle</dt><dd><?= $request['vehicle_description'] ? e($request['vehicle_description']) : '<span class="muted">Not given</span>' ?></dd></div>
        <div><dt>Details</dt><dd><?= $request['details'] ? nl2br(e($request['details'])) : '<span class="muted">None</span>' ?></dd></div>
        <?php if ($request['status'] === 'cancelled'): ?>
          <div><dt>Cancelled by</dt><dd><?= e($request['cancelled_by'] === 'customer' ? 'Customer' : 'Garage') ?></dd></div>
        <?php endif; ?>
        <div><dt>Last update</dt><dd><?= e(format_datetime($request['updated_at'])) ?></dd></div>
      </dl>
    </section>
  </div>

  <div class="stack-lg">
    <form method="post" class="card form-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <h2 class="card-title">Respond</h2>
      <?= error_summary($errors) ?>
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status"<?= field_attrs($errors, 'status') ?>>
          <?php foreach ($statuses as $key => $info): ?>
            <option value="<?= e($key) ?>"<?= selected($values['status'], $key) ?>><?= e($info['short'] . ' - ' . $info['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'status') ?>
      </div>
      <div class="field">
        <label for="mechanic_id">Who is going</label>
        <select id="mechanic_id" name="mechanic_id" data-toggle-other<?= field_attrs($errors, 'mechanic_id') ?>>
          <option value="">Nobody yet</option>
          <?php foreach ($mechanics as $mechanic): ?>
            <option value="<?= (int) $mechanic['id'] ?>"<?= selected($values['mechanic_id'], $mechanic['id']) ?>><?= e($mechanic['full_name'] . ($mechanic['specialty'] ? ' - ' . $mechanic['specialty'] : '')) ?></option>
          <?php endforeach; ?>
          <option value="other"<?= selected($values['mechanic_id'], 'other') ?>>Someone else, such as a tow truck driver</option>
        </select>
        <p class="hint">Their name and phone number are shown to the customer.</p>
        <?= field_error($errors, 'mechanic_id') ?>
      </div>
      <div data-other-fields>
        <div class="field">
          <label for="responder_name">Name</label>
          <input id="responder_name" name="responder_name" type="text" maxlength="100" value="<?= e($values['responder_name']) ?>"<?= field_attrs($errors, 'responder_name') ?>>
          <?= field_error($errors, 'responder_name') ?>
        </div>
        <div class="field">
          <label for="responder_phone">Phone</label>
          <input id="responder_phone" name="responder_phone" type="tel" maxlength="20" value="<?= e($values['responder_phone']) ?>"<?= field_attrs($errors, 'responder_phone') ?>>
          <?= field_error($errors, 'responder_phone') ?>
        </div>
      </div>
      <div class="field">
        <label for="eta_minutes">Arrives in (minutes)</label>
        <input id="eta_minutes" name="eta_minutes" type="number" min="0" max="600" inputmode="numeric" value="<?= e($values['eta_minutes']) ?>"<?= field_attrs($errors, 'eta_minutes') ?>>
        <p class="hint">
          <?php if ($request['eta_at']): ?>
            Current ETA <?= e(format_time($request['eta_at'])) ?><?= $minutesAway !== null && $minutesAway > 0 ? ' (in ' . $minutesAway . ' min)' : ' (passed)' ?>. Leave blank to keep it.
          <?php else: ?>
            Leave blank if you don't know yet.
          <?php endif; ?>
        </p>
        <?= field_error($errors, 'eta_minutes') ?>
      </div>
      <div class="field">
        <label for="garage_note">Message to the customer</label>
        <textarea id="garage_note" name="garage_note" rows="3" maxlength="2000"<?= field_attrs($errors, 'garage_note') ?>><?= e($values['garage_note']) ?></textarea>
        <p class="hint">Shown on the customer's tracking page.</p>
        <?= field_error($errors, 'garage_note') ?>
      </div>
      <button type="submit" class="btn btn-dark btn-block">Save changes</button>
    </form>

    <section class="card">
      <h2>Brought to the workshop?</h2>
      <?php if ($breakdownJob): ?>
        <p>This car is on job card <a href="<?= e(url('admin/job.php?id=' . $breakdownJob['id'])) ?>"><?= e(job_number($breakdownJob['id'])) ?></a>.</p>
        <p><?= status_badge($breakdownJob['status'], job_statuses()) ?></p>
      <?php elseif ($request['status'] === 'cancelled'): ?>
        <p class="muted">This request was cancelled.</p>
      <?php else: ?>
        <p class="muted">If the car was towed in or needs workshop repairs, open a job card. This request will be marked resolved.</p>
        <a class="btn btn-primary btn-block" href="<?= e(url('admin/job_new.php?breakdown=' . $id)) ?>">Open a job card</a>
      <?php endif; ?>
    </section>

    <form method="post" class="card danger-zone" data-confirm="Delete request <?= e($request['reference']) ?> permanently? Only do this for spam or test requests.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <h2>Delete request</h2>
      <p class="muted">For spam or test requests only. Real requests should be marked resolved or cancelled so they stay on record.</p>
      <button type="submit" class="btn btn-ghost">Delete permanently</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
