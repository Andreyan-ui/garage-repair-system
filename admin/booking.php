<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$statuses = booking_statuses();
$slots = booking_slots();
$id = (int) query_param('id');

$booking = db_one(
    'SELECT b.*, s.name AS service_name, c.full_name AS account_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     LEFT JOIN customers c ON c.id = b.customer_id
     WHERE b.id = ?',
    'i',
    [$id]
);
if ($booking === null) {
    abort(404, 'Booking not found', 'That booking does not exist or has been deleted.');
}

$services = db_all(
    'SELECT id, name, is_active FROM services WHERE is_active = 1 OR id = ? ORDER BY sort_order, name',
    'i',
    [$booking['service_id']]
);
$serviceIds = array_map('strval', array_column($services, 'id'));

$fields = ['status', 'service_id', 'preferred_date', 'preferred_slot', 'contact_name', 'contact_phone', 'contact_email', 'vehicle_plate', 'vehicle_make', 'vehicle_model', 'message', 'garage_note'];
$values = [];
foreach ($fields as $field) {
    $values[$field] = (string) $booking[$field];
}
$values['contact_phone'] = format_phone($booking['contact_phone']);
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete') {
        db_execute('DELETE FROM bookings WHERE id = ?', 'i', [$id]);
        flash('success', 'Booking ' . $booking['reference'] . ' was deleted.');
        redirect('admin/bookings.php');
    }

    if ($action === 'update') {
        foreach ($fields as $field) {
            $values[$field] = input($field);
        }
        if (!isset($statuses[$values['status']])) {
            $errors['status'] = 'Choose a valid status.';
        }
        if (!in_array($values['service_id'], $serviceIds, true)) {
            $errors['service_id'] = 'Choose a service.';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $values['preferred_date']);
        if (!$day || $day->format('Y-m-d') !== $values['preferred_date']) {
            $errors['preferred_date'] = 'Enter a valid date.';
        }
        if (!isset($slots[$values['preferred_slot']])) {
            $errors['preferred_slot'] = 'Choose a time slot.';
        }
        if ($values['contact_name'] === '' || too_long($values['contact_name'], 100)) {
            $errors['contact_name'] = 'Enter the customer name.';
        }
        $phone = normalize_phone($values['contact_phone']);
        if ($phone === null) {
            $errors['contact_phone'] = 'Enter a valid phone number.';
        }
        $email = null;
        if ($values['contact_email'] !== '') {
            $email = normalize_email($values['contact_email']);
            if ($email === null) {
                $errors['contact_email'] = 'Enter a valid email or leave it blank.';
            }
        }
        $plate = normalize_plate($values['vehicle_plate']);
        if ($plate === null) {
            $errors['vehicle_plate'] = 'Enter a valid number plate.';
        }
        if ($values['vehicle_make'] === '' || too_long($values['vehicle_make'], 50)) {
            $errors['vehicle_make'] = 'Enter the make.';
        }
        if ($values['vehicle_model'] === '' || too_long($values['vehicle_model'], 50)) {
            $errors['vehicle_model'] = 'Enter the model.';
        }
        if (too_long($values['message'], 2000)) {
            $errors['message'] = 'Keep the message under 2000 characters.';
        }
        if (too_long($values['garage_note'], 2000)) {
            $errors['garage_note'] = 'Keep the note under 2000 characters.';
        }

        if (!$errors) {
            $cancelledBy = $booking['cancelled_by'];
            if ($values['status'] === 'cancelled' && $booking['status'] !== 'cancelled') {
                $cancelledBy = 'garage';
            } elseif ($values['status'] !== 'cancelled') {
                $cancelledBy = null;
            }
            db_execute(
                'UPDATE bookings SET
                    status = ?, service_id = ?, preferred_date = ?, preferred_slot = ?,
                    contact_name = ?, contact_phone = ?, contact_email = ?,
                    vehicle_plate = ?, vehicle_make = ?, vehicle_model = ?,
                    message = ?, garage_note = ?, cancelled_by = ?
                 WHERE id = ?',
                'sisssssssssssi',
                [
                    $values['status'],
                    (int) $values['service_id'],
                    $values['preferred_date'],
                    $values['preferred_slot'],
                    $values['contact_name'],
                    $phone,
                    $email,
                    $plate,
                    $values['vehicle_make'],
                    $values['vehicle_model'],
                    $values['message'] !== '' ? $values['message'] : null,
                    $values['garage_note'] !== '' ? $values['garage_note'] : null,
                    $cancelledBy,
                    $id,
                ]
            );
            flash('success', 'Booking ' . $booking['reference'] . ' saved.');
            redirect('admin/booking.php?id=' . $id);
        }
    }
}

$history = $booking['vehicle_plate'] !== ''
    ? db_all(
        'SELECT b.id, b.reference, b.preferred_date, b.status, s.name AS service_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         WHERE b.vehicle_plate = ? AND b.id <> ?
         ORDER BY b.preferred_date DESC
         LIMIT 10',
        'si',
        [$booking['vehicle_plate'], $id]
    )
    : [];

$bookingJob = db_one('SELECT id, status FROM jobs WHERE booking_id = ?', 'i', [$id]);

$pageTitle = 'Booking ' . $booking['reference'];
$activeNav = 'bookings.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/bookings.php')) ?>">&larr; All bookings</a></p>
<div class="page-head">
  <div>
    <h1><?= e($booking['service_name']) ?> &middot; <?= e($booking['contact_name']) ?></h1>
    <p class="muted"><?= e($booking['reference']) ?> &middot; <?= e(relative_day($booking['preferred_date'])) ?>, <?= e(strtolower(slot_label($booking['preferred_slot']))) ?> &middot; booked <?= e(format_datetime($booking['created_at'])) ?></p>
  </div>
  <?= status_badge($booking['status'], $statuses) ?>
</div>

<div class="detail-grid">
  <form method="post" class="card form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <?= error_summary($errors) ?>

    <fieldset class="form-section">
      <legend>Booking</legend>
      <div class="field-row">
        <div class="field">
          <label for="status">Status</label>
          <select id="status" name="status"<?= field_attrs($errors, 'status') ?>>
            <?php foreach ($statuses as $key => $info): ?>
              <option value="<?= e($key) ?>"<?= selected($values['status'], $key) ?>><?= e($info['short']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'status') ?>
        </div>
        <div class="field">
          <label for="service_id">Service</label>
          <select id="service_id" name="service_id"<?= field_attrs($errors, 'service_id') ?>>
            <?php foreach ($services as $service): ?>
              <option value="<?= e($service['id']) ?>"<?= selected($values['service_id'], $service['id']) ?>><?= e($service['name']) ?><?= $service['is_active'] ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'service_id') ?>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="preferred_date">Date</label>
          <input id="preferred_date" name="preferred_date" type="date" required value="<?= e($values['preferred_date']) ?>"<?= field_attrs($errors, 'preferred_date') ?>>
          <?= field_error($errors, 'preferred_date') ?>
        </div>
        <div class="field">
          <label for="preferred_slot">Time</label>
          <select id="preferred_slot" name="preferred_slot"<?= field_attrs($errors, 'preferred_slot') ?>>
            <?php foreach ($slots as $key => $slot): ?>
              <option value="<?= e($key) ?>"<?= selected($values['preferred_slot'], $key) ?>><?= e($slot['label'] . ' (' . $slot['hours'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'preferred_slot') ?>
        </div>
      </div>
      <div class="field">
        <label for="garage_note">Message to the customer</label>
        <textarea id="garage_note" name="garage_note" rows="3" maxlength="2000"<?= field_attrs($errors, 'garage_note') ?>><?= e($values['garage_note']) ?></textarea>
        <p class="hint">Shown on the customer's tracking page, e.g. "Please bring your service book".</p>
        <?= field_error($errors, 'garage_note') ?>
      </div>
    </fieldset>

    <fieldset class="form-section">
      <legend>Customer</legend>
      <div class="field-row">
        <div class="field">
          <label for="contact_name">Name</label>
          <input id="contact_name" name="contact_name" type="text" maxlength="100" required value="<?= e($values['contact_name']) ?>"<?= field_attrs($errors, 'contact_name') ?>>
          <?= field_error($errors, 'contact_name') ?>
        </div>
        <div class="field">
          <label for="contact_phone">Phone</label>
          <input id="contact_phone" name="contact_phone" type="tel" maxlength="20" required value="<?= e($values['contact_phone']) ?>"<?= field_attrs($errors, 'contact_phone') ?>>
          <?= field_error($errors, 'contact_phone') ?>
        </div>
      </div>
      <div class="field">
        <label for="contact_email">Email</label>
        <input id="contact_email" name="contact_email" type="email" maxlength="150" value="<?= e($values['contact_email']) ?>"<?= field_attrs($errors, 'contact_email') ?>>
        <?= field_error($errors, 'contact_email') ?>
      </div>
      <div class="field">
        <label for="message">Customer's message</label>
        <textarea id="message" name="message" rows="3" maxlength="2000"<?= field_attrs($errors, 'message') ?>><?= e($values['message']) ?></textarea>
        <?= field_error($errors, 'message') ?>
      </div>
    </fieldset>

    <fieldset class="form-section">
      <legend>Vehicle</legend>
      <div class="field">
        <label for="vehicle_plate">Number plate</label>
        <input id="vehicle_plate" name="vehicle_plate" type="text" maxlength="15" required value="<?= e($values['vehicle_plate']) ?>"<?= field_attrs($errors, 'vehicle_plate') ?>>
        <?= field_error($errors, 'vehicle_plate') ?>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="vehicle_make">Make</label>
          <input id="vehicle_make" name="vehicle_make" type="text" maxlength="50" required value="<?= e($values['vehicle_make']) ?>"<?= field_attrs($errors, 'vehicle_make') ?>>
          <?= field_error($errors, 'vehicle_make') ?>
        </div>
        <div class="field">
          <label for="vehicle_model">Model</label>
          <input id="vehicle_model" name="vehicle_model" type="text" maxlength="50" required value="<?= e($values['vehicle_model']) ?>"<?= field_attrs($errors, 'vehicle_model') ?>>
          <?= field_error($errors, 'vehicle_model') ?>
        </div>
      </div>
    </fieldset>

    <button type="submit" class="btn btn-dark btn-lg">Save booking</button>
  </form>

  <div class="stack-lg">
    <section class="card">
      <h2>Workshop</h2>
      <?php if ($bookingJob): ?>
        <p>The car has been checked in on job card <a href="<?= e(url('admin/job.php?id=' . $bookingJob['id'])) ?>"><?= e(job_number($bookingJob['id'])) ?></a>.</p>
        <p><?= status_badge($bookingJob['status'], job_statuses()) ?></p>
        <p class="hint">The booking status now follows the job card.</p>
        <a class="btn btn-dark btn-block" href="<?= e(url('admin/job.php?id=' . $bookingJob['id'])) ?>">Open job card</a>
      <?php elseif ($booking['status'] === 'cancelled'): ?>
        <p class="muted">This booking was cancelled.</p>
      <?php else: ?>
        <p class="muted">When the car arrives, check it in to open a job card with these details filled in.</p>
        <a class="btn btn-primary btn-block" href="<?= e(url('admin/job_new.php?booking=' . $id)) ?>">Check the car in</a>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Contact</h2>
      <p>
        <strong><?= e($booking['contact_name']) ?></strong><br>
        <?= e(format_phone($booking['contact_phone'])) ?>
        <?php if ($booking['contact_email']): ?><br><a href="mailto:<?= e($booking['contact_email']) ?>"><?= e($booking['contact_email']) ?></a><?php endif; ?>
      </p>
      <p class="muted"><?= $booking['customer_id'] ? 'Account: <a href="' . e(url('admin/customer.php?id=' . $booking['customer_id'])) . '">' . e($booking['account_name']) . '</a>' : 'Guest booking' ?></p>
      <div class="contact-actions">
        <a class="btn btn-dark btn-sm" href="<?= e(tel_link($booking['contact_phone'])) ?>">Call</a>
        <a class="btn btn-outline btn-sm" href="<?= e(whatsapp_link($booking['contact_phone'], 'Hello ' . $booking['contact_name'] . ', this is ' . config('garage.name') . ' about your booking ' . $booking['reference'] . '.')) ?>" target="_blank" rel="noopener">WhatsApp</a>
      </div>
    </section>

    <section class="card">
      <h2>Other visits by <span class="plate"><?= e($booking['vehicle_plate']) ?></span></h2>
      <?php if (!$history): ?>
        <p class="muted">This is the first booking for this vehicle.</p>
      <?php else: ?>
        <ul class="link-list">
          <?php foreach ($history as $visit): ?>
            <li><a href="<?= e(url('admin/booking.php?id=' . $visit['id'])) ?>"><?= e(format_date($visit['preferred_date'], 'j M Y')) ?> &middot; <?= e($visit['service_name']) ?> <?= status_badge($visit['status'], $statuses, true) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <form method="post" class="card danger-zone" data-confirm="Delete booking <?= e($booking['reference']) ?> permanently? Consider marking it cancelled instead.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <h2>Delete booking</h2>
      <p class="muted">Removes it for good, including from the customer's account. To keep the history, set the status to Cancelled instead.</p>
      <button type="submit" class="btn btn-ghost">Delete permanently</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
