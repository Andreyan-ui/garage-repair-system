<?php
require __DIR__ . '/includes/bootstrap.php';

$customer = current_customer();
$services = db_all('SELECT id, name, description FROM services WHERE is_active = 1 ORDER BY sort_order, name');
$serviceIds = array_map('strval', array_column($services, 'id'));
$slots = booking_slots();
$vehicles = $customer
    ? db_all('SELECT id, plate_number, make, model, colour FROM vehicles WHERE customer_id = ? ORDER BY created_at', 'i', [$customer['id']])
    : [];
$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+' . (int) config('booking.max_days_ahead') . ' days'));

$values = [
    'service_id' => query_param('service'),
    'preferred_date' => '',
    'preferred_slot' => '',
    'vehicle_id' => $vehicles ? (string) $vehicles[0]['id'] : 'new',
    'vehicle_plate' => '',
    'vehicle_make' => '',
    'vehicle_model' => '',
    'save_vehicle' => '1',
    'contact_name' => $customer['full_name'] ?? '',
    'contact_phone' => $customer ? format_phone($customer['phone']) : '',
    'contact_email' => $customer['email'] ?? '',
    'message' => '',
];
$errors = [];

if (is_post()) {
    verify_csrf();
    if (honeypot_tripped()) {
        redirect('index.php');
    }
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $values['save_vehicle'] = isset($_POST['save_vehicle']) ? '1' : '';
    if (!$customer) {
        $values['vehicle_id'] = 'new';
    }

    if (!in_array($values['service_id'], $serviceIds, true)) {
        $errors['service_id'] = 'Choose the service you need.';
    }
    if (!isset($slots[$values['preferred_slot']])) {
        $errors['preferred_slot'] = 'Choose a morning or afternoon slot.';
    }
    $dateError = validate_booking_date($values['preferred_date'], $values['preferred_slot']);
    if ($dateError !== null) {
        $errors['preferred_date'] = $dateError;
    }

    $vehicle = null;
    if ($values['vehicle_id'] !== 'new') {
        foreach ($vehicles as $saved) {
            if ((string) $saved['id'] === $values['vehicle_id']) {
                $vehicle = $saved;
            }
        }
        if ($vehicle === null) {
            $errors['vehicle_id'] = 'Choose one of your vehicles, or add a new one.';
        }
    } else {
        $plate = normalize_plate($values['vehicle_plate']);
        if ($plate === null) {
            $errors['vehicle_plate'] = 'Enter a valid number plate, for example UBA 123K.';
        }
        if ($values['vehicle_make'] === '' || too_long($values['vehicle_make'], 50)) {
            $errors['vehicle_make'] = 'Enter the make, for example Toyota.';
        }
        if ($values['vehicle_model'] === '' || too_long($values['vehicle_model'], 50)) {
            $errors['vehicle_model'] = 'Enter the model, for example Premio.';
        }
    }

    if ($values['contact_name'] === '' || too_long($values['contact_name'], 100)) {
        $errors['contact_name'] = 'Tell us your name.';
    }
    $phone = normalize_phone($values['contact_phone']);
    if ($phone === null) {
        $errors['contact_phone'] = 'Enter a valid phone number, for example ' . config('phone.example') . '.';
    }
    $email = null;
    if ($values['contact_email'] !== '') {
        $email = normalize_email($values['contact_email']);
        if ($email === null) {
            $errors['contact_email'] = 'Enter a valid email address, or leave it blank.';
        }
    }
    if (too_long($values['message'], 2000)) {
        $errors['message'] = 'Keep your message under 2000 characters.';
    }

    if (!$errors) {
        $vehicleId = $vehicle['id'] ?? null;
        if ($vehicle === null) {
            $vehicle = ['plate_number' => $plate, 'make' => $values['vehicle_make'], 'model' => $values['vehicle_model']];
            if ($customer && $values['save_vehicle'] === '1') {
                $vehicleId = db_value('SELECT id FROM vehicles WHERE customer_id = ? AND plate_number = ?', 'is', [$customer['id'], $plate]);
                if ($vehicleId === null) {
                    $vehicleId = db_insert(
                        'INSERT INTO vehicles (customer_id, plate_number, make, model) VALUES (?, ?, ?, ?)',
                        'isss',
                        [$customer['id'], $plate, $values['vehicle_make'], $values['vehicle_model']]
                    );
                }
            }
        }

        [, $reference] = insert_with_reference('BK', 'bookings', [
            'customer_id' => ['i', $customer['id'] ?? null],
            'vehicle_id' => ['i', $vehicleId],
            'service_id' => ['i', (int) $values['service_id']],
            'contact_name' => ['s', $values['contact_name']],
            'contact_phone' => ['s', $phone],
            'contact_email' => ['s', $email],
            'vehicle_plate' => ['s', $vehicle['plate_number']],
            'vehicle_make' => ['s', $vehicle['make']],
            'vehicle_model' => ['s', $vehicle['model']],
            'preferred_date' => ['s', $values['preferred_date']],
            'preferred_slot' => ['s', $values['preferred_slot']],
            'message' => ['s', $values['message'] !== '' ? $values['message'] : null],
        ]);
        remember_reference($reference);
        flash('success', 'Booking received. We will call you to confirm the time.');
        redirect('track.php?ref=' . rawurlencode($reference));
    }
}

$pageTitle = 'Book a service';
$bodyClass = 'page-book';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header has-photo">
  <div class="container page-header-grid">
    <div>
      <p class="eyebrow">Book online</p>
      <h1>Book a service</h1>
      <p class="lead">Tell us what your car needs and when suits you. We will call to confirm the time.</p>
    </div>
    <figure class="page-header-figure"><?= photo('garage3.jpg', 'A friendly Wycliffe Motors mechanic', '', true) ?></figure>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div>
      <?php if (!$customer): ?>
        <div class="alert alert-info">
          Have an account? <a href="<?= e(url('login.php?next=book.php')) ?>">Log in</a> to use your saved vehicles and see all your bookings in one place.
          No account? No problem, you can still book below.
        </div>
      <?php endif; ?>

      <?php if (!$services): ?>
        <div class="card empty-state">
          <h2>Online booking is unavailable</h2>
          <p>Please call us on <a href="<?= e(tel_link(config('garage.phone'))) ?>"><?= e(format_phone(config('garage.phone'))) ?></a> to book.</p>
        </div>
      <?php else: ?>
      <form method="post" class="card form-card" data-once>
        <?= csrf_field() ?>
        <?= honeypot_field() ?>
        <?= error_summary($errors) ?>

        <fieldset class="form-section">
          <legend><span class="step-number">1</span> What does your car need?</legend>
          <div class="field">
            <label for="service_id">Service</label>
            <select id="service_id" name="service_id" required<?= field_attrs($errors, 'service_id') ?>>
              <option value="">Choose a service</option>
              <?php foreach ($services as $service): ?>
                <option value="<?= e($service['id']) ?>"<?= selected($values['service_id'], $service['id']) ?>><?= e($service['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_id') ?>
          </div>
          <div class="field">
            <label for="message">Describe the problem <span class="optional">optional</span></label>
            <textarea id="message" name="message" rows="3" maxlength="2000" placeholder="e.g. Squeaking noise when braking, worse in the morning"<?= field_attrs($errors, 'message') ?>><?= e($values['message']) ?></textarea>
            <?= field_error($errors, 'message') ?>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">2</span> When would you like to come in?</legend>
          <div class="field-row">
            <div class="field">
              <label for="preferred_date">Date</label>
              <input id="preferred_date" name="preferred_date" type="date" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" required value="<?= e($values['preferred_date']) ?>"<?= field_attrs($errors, 'preferred_date') ?>>
              <?php if (closed_days_hint() !== ''): ?>
                <p class="hint"><?= e(closed_days_hint()) ?></p>
              <?php endif; ?>
              <?= field_error($errors, 'preferred_date') ?>
            </div>
            <div class="field">
              <span class="label">Time of day</span>
              <div class="choice-row"<?= field_attrs($errors, 'preferred_slot') ?>>
                <?php foreach ($slots as $key => $slot): ?>
                  <label class="choice choice-inline">
                    <input type="radio" name="preferred_slot" value="<?= e($key) ?>"<?= checked($values['preferred_slot'], $key) ?> required>
                    <span><?= e($slot['label']) ?> <small><?= e($slot['hours']) ?></small></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <?= field_error($errors, 'preferred_slot') ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">3</span> Which car?</legend>
          <?php if ($vehicles): ?>
            <div class="field">
              <label for="vehicle_id">Vehicle</label>
              <select id="vehicle_id" name="vehicle_id" data-vehicle-choice<?= field_attrs($errors, 'vehicle_id') ?>>
                <?php foreach ($vehicles as $vehicle): ?>
                  <option value="<?= e($vehicle['id']) ?>"<?= selected($values['vehicle_id'], $vehicle['id']) ?>><?= e($vehicle['plate_number'] . ' - ' . vehicle_label($vehicle)) ?></option>
                <?php endforeach; ?>
                <option value="new"<?= selected($values['vehicle_id'], 'new') ?>>Add a different vehicle</option>
              </select>
              <?= field_error($errors, 'vehicle_id') ?>
            </div>
          <?php else: ?>
            <input type="hidden" name="vehicle_id" value="new">
          <?php endif; ?>

          <div class="new-vehicle" data-new-vehicle>
            <div class="field">
              <label for="vehicle_plate">Number plate</label>
              <input id="vehicle_plate" name="vehicle_plate" type="text" maxlength="15" autocapitalize="characters" placeholder="UBA 123K" value="<?= e($values['vehicle_plate']) ?>"<?= $vehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_plate') ?>>
              <?= field_error($errors, 'vehicle_plate') ?>
            </div>
            <div class="field-row">
              <div class="field">
                <label for="vehicle_make">Make</label>
                <input id="vehicle_make" name="vehicle_make" type="text" maxlength="50" placeholder="Toyota" value="<?= e($values['vehicle_make']) ?>"<?= $vehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_make') ?>>
                <?= field_error($errors, 'vehicle_make') ?>
              </div>
              <div class="field">
                <label for="vehicle_model">Model</label>
                <input id="vehicle_model" name="vehicle_model" type="text" maxlength="50" placeholder="Premio" value="<?= e($values['vehicle_model']) ?>"<?= $vehicles ? ' data-required-when-new' : ' required' ?><?= field_attrs($errors, 'vehicle_model') ?>>
                <?= field_error($errors, 'vehicle_model') ?>
              </div>
            </div>
            <?php if ($customer): ?>
              <label class="checkbox">
                <input type="checkbox" name="save_vehicle" value="1"<?= checked($values['save_vehicle'], '1') ?>>
                <span>Save this vehicle to my account</span>
              </label>
            <?php endif; ?>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">4</span> Your contact details</legend>
          <div class="field-row">
            <div class="field">
              <label for="contact_name">Full name</label>
              <input id="contact_name" name="contact_name" type="text" maxlength="100" autocomplete="name" required value="<?= e($values['contact_name']) ?>"<?= field_attrs($errors, 'contact_name') ?>>
              <?= field_error($errors, 'contact_name') ?>
            </div>
            <div class="field">
              <label for="contact_phone">Phone number</label>
              <input id="contact_phone" name="contact_phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="<?= e(config('phone.example')) ?>" value="<?= e($values['contact_phone']) ?>"<?= field_attrs($errors, 'contact_phone') ?>>
              <?= field_error($errors, 'contact_phone') ?>
            </div>
          </div>
          <div class="field">
            <label for="contact_email">Email <span class="optional">optional</span></label>
            <input id="contact_email" name="contact_email" type="email" maxlength="150" autocomplete="email" value="<?= e($values['contact_email']) ?>"<?= field_attrs($errors, 'contact_email') ?>>
            <?= field_error($errors, 'contact_email') ?>
          </div>
        </fieldset>

        <button type="submit" class="btn btn-primary btn-lg btn-block" data-busy="Sending your booking...">Send booking request</button>
        <p class="hint center">You will get a booking reference so you can track it online.</p>
      </form>
      <?php endif; ?>
    </div>

    <aside class="sidebar">
      <div class="card">
        <h2>What happens next</h2>
        <ol class="how-steps compact">
          <li><strong>We call you</strong><span>to confirm the day and time.</span></li>
          <li><strong>Bring your car in</strong><span>at the agreed time.</span></li>
          <li><strong>Track progress</strong><span>online with your booking reference.</span></li>
        </ol>
      </div>
      <div class="card">
        <h2>Opening hours</h2>
        <dl class="hours">
          <?php foreach (config('garage.opening_hours') as $days => $hours): ?>
            <div><dt><?= e($days) ?></dt><dd><?= e($hours) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </div>
      <div class="card card-danger">
        <h2>Car won't move?</h2>
        <p>Don't drive a car that isn't safe. We can come to you.</p>
        <a class="btn btn-danger btn-block" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
      </div>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
