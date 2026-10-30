<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_admin();

$fuelLevels = fuel_levels();
$statuses = job_statuses();
$problems = problem_types();
$startStatuses = ['checked_in' => $statuses['checked_in']['label'], 'in_progress' => $statuses['in_progress']['label']];
$maxYear = (int) date('Y') + 1;

$bookingId = (int) query_param('booking');
$breakdownId = (int) query_param('breakdown');
$lookupPhone = query_param('phone');

$booking = null;
if ($bookingId > 0) {
    $booking = db_one(
        'SELECT b.*, s.name AS service_name FROM bookings b JOIN services s ON s.id = b.service_id WHERE b.id = ?',
        'i',
        [$bookingId]
    );
    if ($booking === null) {
        abort(404, 'Booking not found', 'That booking does not exist.');
    }
    $existing = db_value('SELECT id FROM jobs WHERE booking_id = ?', 'i', [$bookingId]);
    if ($existing !== null) {
        flash('info', 'This booking already has a job card.');
        redirect('admin/job.php?id=' . $existing);
    }
}

$breakdown = null;
if ($breakdownId > 0) {
    $breakdown = db_one('SELECT * FROM breakdown_requests WHERE id = ?', 'i', [$breakdownId]);
    if ($breakdown === null) {
        abort(404, 'Breakdown request not found', 'That breakdown request does not exist.');
    }
    $existing = db_value('SELECT id FROM jobs WHERE breakdown_id = ?', 'i', [$breakdownId]);
    if ($existing !== null) {
        flash('info', 'This breakdown already has a job card.');
        redirect('admin/job.php?id=' . $existing);
    }
}

$values = [
    'contact_name' => '',
    'contact_phone' => $lookupPhone,
    'contact_email' => '',
    'vehicle_id' => 'new',
    'vehicle_plate' => '',
    'vehicle_make' => '',
    'vehicle_model' => '',
    'vehicle_year' => '',
    'vehicle_colour' => '',
    'odometer_km' => '',
    'fuel_level' => '',
    'condition_notes' => '',
    'complaint' => '',
    'mechanic_id' => '',
    'status' => 'checked_in',
    'promised_date' => '',
    'promised_time' => '17:00',
];
$suggestedCustomerId = null;
$linkByDefault = false;

if ($booking) {
    $values['contact_name'] = $booking['contact_name'];
    $values['contact_phone'] = format_phone($booking['contact_phone']);
    $values['contact_email'] = (string) $booking['contact_email'];
    $values['vehicle_plate'] = $booking['vehicle_plate'];
    $values['vehicle_make'] = $booking['vehicle_make'];
    $values['vehicle_model'] = $booking['vehicle_model'];
    $values['vehicle_id'] = $booking['vehicle_id'] ? (string) $booking['vehicle_id'] : 'new';
    $values['complaint'] = $booking['service_name'] . ($booking['message'] ? ': ' . $booking['message'] : '');
    $suggestedCustomerId = $booking['customer_id'];
    $linkByDefault = $booking['customer_id'] !== null;
}

if ($breakdown) {
    $values['contact_name'] = $breakdown['contact_name'];
    $values['contact_phone'] = format_phone($breakdown['contact_phone']);
    $values['vehicle_plate'] = (string) $breakdown['vehicle_plate'];
    $values['mechanic_id'] = (string) $breakdown['mechanic_id'];
    $values['complaint'] = ($problems[$breakdown['problem_type']] ?? 'Breakdown')
        . ' at the roadside (' . $breakdown['reference'] . ')'
        . ($breakdown['details'] ? ': ' . $breakdown['details'] : '');
    $suggestedCustomerId = $breakdown['customer_id'];
    $linkByDefault = $breakdown['customer_id'] !== null;
}

if (!$booking && !$breakdown && $lookupPhone !== '') {
    $linkByDefault = true;
}

$errors = [];
$linkChecked = $linkByDefault;

if (is_post()) {
    verify_csrf();
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $linkCustomerId = (int) input('link_customer_id');
    $linkChecked = $linkCustomerId > 0;
    $suggestedCustomerId = (int) input('suggested_customer_id') ?: null;

    $linked = null;
    if ($linkCustomerId > 0) {
        $linked = db_one('SELECT id, full_name FROM customers WHERE id = ?', 'i', [$linkCustomerId]);
        if ($linked === null) {
            $errors['link_customer_id'] = 'That customer account no longer exists.';
        }
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

    $savedVehicle = null;
    if (!$linked && $values['vehicle_id'] !== 'new') {
        $errors['vehicle_id'] = 'To use a saved vehicle, tick "Link this job card to their account". Otherwise choose "A different vehicle".';
    }
    if ($linked && $values['vehicle_id'] !== 'new') {
        $savedVehicle = db_one(
            'SELECT * FROM vehicles WHERE id = ? AND customer_id = ?',
            'ii',
            [(int) $values['vehicle_id'], $linked['id']]
        );
        if ($savedVehicle === null) {
            $errors['vehicle_id'] = 'Choose one of this customer\'s vehicles, or add a new one.';
        }
    }
    $plate = null;
    $year = null;
    if ($savedVehicle === null && !isset($errors['vehicle_id'])) {
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
        if ($values['vehicle_year'] !== '') {
            $year = filter_var($values['vehicle_year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => $maxYear]]);
            if ($year === false) {
                $errors['vehicle_year'] = 'Enter a year between 1950 and ' . $maxYear . '.';
                $year = null;
            }
        }
        if (too_long($values['vehicle_colour'], 30)) {
            $errors['vehicle_colour'] = 'Keep the colour under 30 characters.';
        }
    }

    $odometer = null;
    if ($values['odometer_km'] !== '') {
        $odometer = filter_var(str_replace([',', ' '], '', $values['odometer_km']), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3000000]]);
        if ($odometer === false) {
            $errors['odometer_km'] = 'Enter the odometer reading in kilometres.';
            $odometer = null;
        }
    }
    if ($values['fuel_level'] !== '' && !isset($fuelLevels[$values['fuel_level']])) {
        $errors['fuel_level'] = 'Choose a fuel level.';
    }
    if (too_long($values['condition_notes'], 2000)) {
        $errors['condition_notes'] = 'Keep the notes under 2000 characters.';
    }
    if ($values['complaint'] === '' || too_long($values['complaint'], 2000)) {
        $errors['complaint'] = 'Describe what the customer wants done.';
    }
    $mechanicId = null;
    if ($values['mechanic_id'] !== '') {
        $mechanicId = (int) db_value('SELECT id FROM mechanics WHERE id = ? AND is_active = 1', 'i', [(int) $values['mechanic_id']]) ?: null;
        if ($mechanicId === null) {
            $errors['mechanic_id'] = 'Choose an active mechanic.';
        }
    }
    if (!isset($startStatuses[$values['status']])) {
        $values['status'] = 'checked_in';
    }
    $promisedAt = null;
    if ($values['promised_date'] !== '') {
        $time = $values['promised_time'] !== '' ? $values['promised_time'] : '17:00';
        $promised = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $values['promised_date'] . ' ' . $time);
        if (!$promised || $promised->format('Y-m-d H:i') !== $values['promised_date'] . ' ' . $time) {
            $errors['promised_date'] = 'Enter a valid date and time.';
        } else {
            $promisedAt = $promised->format('Y-m-d H:i:s');
        }
    }

    if (!$errors) {
        try {
            $jobId = db_transaction(function () use ($values, $linked, $savedVehicle, $plate, $year, $phone, $email, $odometer, $mechanicId, $promisedAt, $booking, $breakdown, $admin) {
                $vehicleId = $savedVehicle['id'] ?? null;
                $vehicle = $savedVehicle
                    ? [$savedVehicle['plate_number'], $savedVehicle['make'], $savedVehicle['model'], $savedVehicle['model_year'], $savedVehicle['colour']]
                    : [$plate, $values['vehicle_make'], $values['vehicle_model'], $year, $values['vehicle_colour'] !== '' ? $values['vehicle_colour'] : null];

                if ($linked && $savedVehicle === null) {
                    $vehicleId = db_value('SELECT id FROM vehicles WHERE customer_id = ? AND plate_number = ?', 'is', [$linked['id'], $plate]);
                    if ($vehicleId === null) {
                        $vehicleId = db_insert(
                            'INSERT INTO vehicles (customer_id, plate_number, make, model, model_year, colour) VALUES (?, ?, ?, ?, ?, ?)',
                            'isssis',
                            [$linked['id'], $vehicle[0], $vehicle[1], $vehicle[2], $vehicle[3], $vehicle[4]]
                        );
                    }
                }

                $jobId = db_insert(
                    'INSERT INTO jobs (customer_id, vehicle_id, booking_id, breakdown_id, mechanic_id, contact_name, contact_phone, contact_email,
                        vehicle_plate, vehicle_make, vehicle_model, vehicle_year, vehicle_colour, odometer_km, fuel_level, condition_notes,
                        complaint, status, promised_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiiiissssssisisssssi',
                    [
                        $linked['id'] ?? null,
                        $vehicleId,
                        $booking['id'] ?? null,
                        $breakdown['id'] ?? null,
                        $mechanicId,
                        $values['contact_name'],
                        $phone,
                        $email,
                        $vehicle[0],
                        $vehicle[1],
                        $vehicle[2],
                        $vehicle[3],
                        $vehicle[4],
                        $odometer,
                        $values['fuel_level'] !== '' ? $values['fuel_level'] : null,
                        $values['condition_notes'] !== '' ? $values['condition_notes'] : null,
                        $values['complaint'],
                        $values['status'],
                        $promisedAt,
                        $admin['id'],
                    ]
                );

                if ($booking) {
                    sync_booking_with_job($booking['id'], $values['status']);
                }
                if ($breakdown && in_array($breakdown['status'], ['new', 'dispatched', 'on_site'], true)) {
                    db_execute(
                        "UPDATE breakdown_requests SET status = 'completed', garage_note = COALESCE(garage_note, ?) WHERE id = ?",
                        'si',
                        ['Your car has been brought to our workshop. We will call you with an update.', $breakdown['id']]
                    );
                }
                return $jobId;
            });
        } catch (mysqli_sql_exception $e) {
            if (is_duplicate_key($e, 'uq_jobs_booking') || is_duplicate_key($e, 'uq_jobs_breakdown')) {
                flash('info', 'Someone has already opened a job card for this. Here it is.');
                $existing = db_value('SELECT id FROM jobs WHERE booking_id = ? OR breakdown_id = ?', 'ii', [$booking['id'] ?? 0, $breakdown['id'] ?? 0]);
                redirect('admin/job.php?id=' . $existing);
            }
            throw $e;
        }

        flash('success', job_number($jobId) . ' opened. Add the work and parts as the job goes on.');
        redirect('admin/job.php?id=' . $jobId);
    }
}

$match = null;
$candidateId = is_post() ? ((int) input('link_customer_id') ?: $suggestedCustomerId) : $suggestedCustomerId;
if ($candidateId) {
    $match = db_one('SELECT id, full_name, phone, email FROM customers WHERE id = ?', 'i', [(int) $candidateId]);
}
if ($match === null) {
    $phoneForMatch = normalize_phone($values['contact_phone']);
    if ($phoneForMatch !== null) {
        $match = db_one('SELECT id, full_name, phone, email FROM customers WHERE phone = ?', 's', [$phoneForMatch]);
    }
}
$matchVehicles = $match
    ? db_all('SELECT id, plate_number, make, model, model_year, colour FROM vehicles WHERE customer_id = ? ORDER BY created_at', 'i', [$match['id']])
    : [];
if (!is_post() && $match && $lookupPhone !== '' && !$booking && !$breakdown) {
    $values['contact_name'] = $match['full_name'];
    $values['contact_phone'] = format_phone($match['phone']);
    $values['contact_email'] = (string) $match['email'];
    $values['vehicle_id'] = $matchVehicles ? (string) $matchVehicles[0]['id'] : 'new';
}
if (!$matchVehicles) {
    $values['vehicle_id'] = 'new';
}
$mechanics = active_mechanics();

$pageTitle = 'Check in a car';
$activeNav = 'jobs.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/jobs.php')) ?>">&larr; Job cards</a></p>
<div class="page-head">
  <div>
    <h1>Check in a car</h1>
    <p class="muted">Opens a job card for the workshop.</p>
  </div>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <?php if ($booking): ?>
      <div class="alert alert-info">
        Checking in booking <strong><?= e($booking['reference']) ?></strong>: <?= e($booking['service_name']) ?>,
        booked for <?= e(relative_day($booking['preferred_date'])) ?>. The customer's tracking page will update.
      </div>
    <?php elseif ($breakdown): ?>
      <div class="alert alert-info">
        Opening a job card for breakdown <strong><?= e($breakdown['reference']) ?></strong>.
        The roadside request will be marked resolved.
        <?php if ($breakdown['vehicle_description']): ?>
          Vehicle described as <strong><?= e($breakdown['vehicle_description']) ?></strong>.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <form method="get" class="card lookup-form">
        <div class="field">
          <label for="lookup-phone">Returning customer? Look them up by phone</label>
          <input id="lookup-phone" name="phone" type="tel" placeholder="<?= e(config('phone.example')) ?>" value="<?= e($lookupPhone) ?>">
        </div>
        <button type="submit" class="btn btn-dark">Find</button>
        <?php if ($lookupPhone !== '' && $match === null): ?>
          <p class="hint">No online account uses this number. That's fine, fill in the details below.</p>
        <?php endif; ?>
      </form>
    <?php endif; ?>

    <form method="post" class="card form-card" data-once>
      <?= csrf_field() ?>
      <input type="hidden" name="suggested_customer_id" value="<?= e($suggestedCustomerId ?? '') ?>">
      <?= error_summary($errors) ?>

      <?php if ($match): ?>
        <div class="customer-match">
          <p><strong><?= e($match['full_name']) ?></strong> has an online account (<?= e(format_phone($match['phone'])) ?><?= $matchVehicles ? ', ' . count($matchVehicles) . ' saved ' . (count($matchVehicles) === 1 ? 'vehicle' : 'vehicles') : '' ?>).</p>
          <label class="inline-check">
            <input type="checkbox" name="link_customer_id" value="<?= (int) $match['id'] ?>"<?= $linkChecked ? ' checked' : '' ?>>
            Link this job card to their account, so they can follow it online and see the invoice
          </label>
          <p class="hint">Only tick this if you have confirmed this is the same person.</p>
          <?= field_error($errors, 'link_customer_id') ?>
        </div>
      <?php endif; ?>

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
          <label for="contact_email">Email <span class="optional">optional</span></label>
          <input id="contact_email" name="contact_email" type="email" maxlength="150" value="<?= e($values['contact_email']) ?>"<?= field_attrs($errors, 'contact_email') ?>>
          <?= field_error($errors, 'contact_email') ?>
        </div>
      </fieldset>

      <fieldset class="form-section">
        <legend>Vehicle</legend>
        <?php if ($matchVehicles): ?>
          <div class="field">
            <label for="vehicle_id">Saved vehicle</label>
            <select id="vehicle_id" name="vehicle_id" data-vehicle-choice<?= field_attrs($errors, 'vehicle_id') ?>>
              <?php foreach ($matchVehicles as $vehicle): ?>
                <option value="<?= (int) $vehicle['id'] ?>"<?= selected($values['vehicle_id'], $vehicle['id']) ?>><?= e($vehicle['plate_number'] . ' - ' . vehicle_label($vehicle)) ?></option>
              <?php endforeach; ?>
              <option value="new"<?= selected($values['vehicle_id'], 'new') ?>>A different vehicle</option>
            </select>
            <p class="hint">Saved vehicles are only used when the job is linked to the account.</p>
            <?= field_error($errors, 'vehicle_id') ?>
          </div>
        <?php else: ?>
          <input type="hidden" name="vehicle_id" value="new">
        <?php endif; ?>
        <div class="field-group" data-new-vehicle>
          <div class="field-row">
            <div class="field">
              <label for="vehicle_plate">Number plate</label>
              <input id="vehicle_plate" name="vehicle_plate" type="text" maxlength="15" autocapitalize="characters" value="<?= e($values['vehicle_plate']) ?>"<?= $matchVehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_plate') ?>>
              <?= field_error($errors, 'vehicle_plate') ?>
            </div>
            <div class="field">
              <label for="vehicle_colour">Colour <span class="optional">optional</span></label>
              <input id="vehicle_colour" name="vehicle_colour" type="text" maxlength="30" value="<?= e($values['vehicle_colour']) ?>"<?= field_attrs($errors, 'vehicle_colour') ?>>
              <?= field_error($errors, 'vehicle_colour') ?>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="vehicle_make">Make</label>
              <input id="vehicle_make" name="vehicle_make" type="text" maxlength="50" value="<?= e($values['vehicle_make']) ?>"<?= $matchVehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_make') ?>>
              <?= field_error($errors, 'vehicle_make') ?>
            </div>
            <div class="field">
              <label for="vehicle_model">Model</label>
              <input id="vehicle_model" name="vehicle_model" type="text" maxlength="50" value="<?= e($values['vehicle_model']) ?>"<?= $matchVehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_model') ?>>
              <?= field_error($errors, 'vehicle_model') ?>
            </div>
          </div>
          <div class="field">
            <label for="vehicle_year">Year <span class="optional">optional</span></label>
            <input id="vehicle_year" name="vehicle_year" type="number" min="1950" max="<?= $maxYear ?>" inputmode="numeric" value="<?= e($values['vehicle_year']) ?>"<?= field_attrs($errors, 'vehicle_year') ?>>
            <?= field_error($errors, 'vehicle_year') ?>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="odometer_km">Odometer (km)</label>
            <input id="odometer_km" name="odometer_km" type="text" inputmode="numeric" maxlength="12" placeholder="e.g. 132,780" value="<?= e($values['odometer_km']) ?>"<?= field_attrs($errors, 'odometer_km') ?>>
            <?= field_error($errors, 'odometer_km') ?>
          </div>
          <div class="field">
            <label for="fuel_level">Fuel level</label>
            <select id="fuel_level" name="fuel_level"<?= field_attrs($errors, 'fuel_level') ?>>
              <option value="">Not recorded</option>
              <?php foreach ($fuelLevels as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($values['fuel_level'], $key) ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'fuel_level') ?>
          </div>
        </div>
        <div class="field">
          <label for="condition_notes">Condition at check-in <span class="optional">optional</span></label>
          <textarea id="condition_notes" name="condition_notes" rows="2" maxlength="2000" placeholder="Existing scratches or dents, warning lights on, items left in the car"<?= field_attrs($errors, 'condition_notes') ?>><?= e($values['condition_notes']) ?></textarea>
          <p class="hint">Recording this protects the garage if there is a dispute later.</p>
          <?= field_error($errors, 'condition_notes') ?>
        </div>
      </fieldset>

      <fieldset class="form-section">
        <legend>Work</legend>
        <div class="field">
          <label for="complaint">What does the customer want done?</label>
          <textarea id="complaint" name="complaint" rows="3" maxlength="2000" required<?= field_attrs($errors, 'complaint') ?>><?= e($values['complaint']) ?></textarea>
          <?= field_error($errors, 'complaint') ?>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="mechanic_id">Mechanic</label>
            <select id="mechanic_id" name="mechanic_id"<?= field_attrs($errors, 'mechanic_id') ?>>
              <option value="">Assign later</option>
              <?php foreach ($mechanics as $mechanic): ?>
                <option value="<?= (int) $mechanic['id'] ?>"<?= selected($values['mechanic_id'], $mechanic['id']) ?>><?= e($mechanic['full_name'] . ($mechanic['specialty'] ? ' - ' . $mechanic['specialty'] : '')) ?></option>
              <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'mechanic_id') ?>
          </div>
          <div class="field">
            <span class="label">Start as</span>
            <div class="choice-row">
              <?php foreach ($startStatuses as $key => $label): ?>
                <label class="choice choice-inline">
                  <input type="radio" name="status" value="<?= e($key) ?>"<?= checked($values['status'], $key) ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="promised_date">Promised ready date <span class="optional">optional</span></label>
            <input id="promised_date" name="promised_date" type="date" value="<?= e($values['promised_date']) ?>"<?= field_attrs($errors, 'promised_date') ?>>
            <?= field_error($errors, 'promised_date') ?>
          </div>
          <div class="field">
            <label for="promised_time">Time</label>
            <input id="promised_time" name="promised_time" type="time" value="<?= e($values['promised_time']) ?>">
          </div>
        </div>
      </fieldset>

      <button type="submit" class="btn btn-primary btn-lg" data-busy="Opening job card...">Open job card</button>
    </form>
  </div>

  <aside class="stack-lg">
    <section class="card">
      <h2>Check-in checklist</h2>
      <ul class="plain-list">
        <li>Read the odometer and fuel gauge with the customer present.</li>
        <li>Walk around the car and note any scratches, dents or cracks.</li>
        <li>Note warning lights that are already on.</li>
        <li>Ask the customer to remove valuables.</li>
        <li>Label the keys with the job card number.</li>
      </ul>
    </section>
    <section class="card">
      <h2>After check-in</h2>
      <p class="muted">Add labour and parts to the job card as the work happens. Parts are taken from stock automatically. When the work is done, create the invoice from the same page.</p>
    </section>
  </aside>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
