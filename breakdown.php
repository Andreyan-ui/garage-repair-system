<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');
$customer = current_customer();
$problems = problem_types();
$towingOptions = towing_options();
$vehicles = $customer
    ? db_all('SELECT id, plate_number, make, model, colour FROM vehicles WHERE customer_id = ? ORDER BY created_at', 'i', [$customer['id']])
    : [];

$values = [
    'problem_type' => query_param('problem'),
    'location_text' => '',
    'latitude' => '',
    'longitude' => '',
    'location_accuracy' => '',
    'contact_name' => $customer['full_name'] ?? '',
    'contact_phone' => $customer ? format_phone($customer['phone']) : '',
    'saved_vehicle' => '',
    'vehicle_plate' => '',
    'vehicle_description' => '',
    'towing' => 'unsure',
    'details' => '',
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

    if (!isset($problems[$values['problem_type']])) {
        $errors['problem_type'] = 'Choose what went wrong.';
    }
    if (mb_strlen($values['location_text']) < 5) {
        $errors['location_text'] = 'Describe where you are, for example the road and a nearby landmark.';
    } elseif (too_long($values['location_text'], 255)) {
        $errors['location_text'] = 'Keep the location under 255 characters.';
    }
    if ($values['contact_name'] === '') {
        $errors['contact_name'] = 'Tell us your name.';
    } elseif (too_long($values['contact_name'], 100)) {
        $errors['contact_name'] = 'Keep your name under 100 characters.';
    }
    $phone = normalize_phone($values['contact_phone']);
    if ($phone === null) {
        $errors['contact_phone'] = 'Enter a phone number we can call you on, for example ' . config('phone.example') . '.';
    }

    $plate = null;
    $vehicleDescription = $values['vehicle_description'];
    if ($values['saved_vehicle'] !== '') {
        foreach ($vehicles as $vehicle) {
            if ((string) $vehicle['id'] === $values['saved_vehicle']) {
                $plate = $vehicle['plate_number'];
                $vehicleDescription = vehicle_label($vehicle);
            }
        }
        if ($plate === null) {
            $errors['saved_vehicle'] = 'Choose one of your saved vehicles.';
        }
    } elseif ($values['vehicle_plate'] !== '') {
        $plate = normalize_plate($values['vehicle_plate']);
        if ($plate === null) {
            $errors['vehicle_plate'] = 'Enter a valid number plate, for example UBA 123K.';
        }
    }
    if (too_long($vehicleDescription, 100)) {
        $errors['vehicle_description'] = 'Keep the description under 100 characters.';
    }
    if (!isset($towingOptions[$values['towing']])) {
        $values['towing'] = 'unsure';
    }
    if (too_long($values['details'], 2000)) {
        $errors['details'] = 'Keep the details under 2000 characters.';
    }

    [$latitude, $longitude, $accuracy] = parse_coordinates($values['latitude'], $values['longitude'], $values['location_accuracy']);

    if (!$errors) {
        [, $reference] = insert_with_reference('BD', 'breakdown_requests', [
            'customer_id' => ['i', $customer['id'] ?? null],
            'contact_name' => ['s', $values['contact_name']],
            'contact_phone' => ['s', $phone],
            'problem_type' => ['s', $values['problem_type']],
            'location_text' => ['s', $values['location_text']],
            'latitude' => ['d', $latitude],
            'longitude' => ['d', $longitude],
            'location_accuracy' => ['i', $accuracy],
            'vehicle_plate' => ['s', $plate],
            'vehicle_description' => ['s', $vehicleDescription !== '' ? $vehicleDescription : null],
            'towing' => ['s', $values['towing']],
            'details' => ['s', $values['details'] !== '' ? $values['details'] : null],
        ]);
        remember_reference($reference);
        flash('success', 'Your help request has been sent. ' . $garage['breakdown_callback']);
        redirect('track.php?ref=' . rawurlencode($reference));
    }
}

$pageTitle = 'Breakdown help';
$pageDescription = 'Broken down? Request roadside help from ' . $garage['full_name'] . '. Send your location and we will come to you.';
$bodyClass = 'page-breakdown';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header page-header-danger">
  <div class="container">
    <p class="eyebrow">Roadside assistance, <?= e($garage['breakdown_hours']) ?></p>
    <h1>Broken down? We will come to you.</h1>
    <p class="lead">Calling is the fastest way to reach us. If you can't talk right now, send your location below.</p>
    <div class="button-row">
      <a class="btn btn-light btn-lg" href="<?= e(tel_link($garage['phone'])) ?>">Call <?= e(format_phone($garage['phone'])) ?></a>
      <a class="btn btn-outline-light btn-lg" href="<?= e(whatsapp_link($garage['whatsapp'], 'Hello, my car has broken down and I need help.')) ?>" target="_blank" rel="noopener">WhatsApp us</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div>
      <div class="alert alert-error alert-strong" role="note">
        <strong>If anyone is hurt, call <?= e($garage['emergency_numbers']) ?> first.</strong>
        Then contact us once everyone is safe.
      </div>

      <form method="post" class="card form-card" data-once>
        <?= csrf_field() ?>
        <?= honeypot_field() ?>
        <?= error_summary($errors) ?>

        <fieldset class="form-section">
          <legend><span class="step-number">1</span> What went wrong?</legend>
          <div class="choice-grid"<?= field_attrs($errors, 'problem_type') ?> role="radiogroup">
            <?php foreach ($problems as $key => $label): ?>
              <label class="choice">
                <input type="radio" name="problem_type" value="<?= e($key) ?>"<?= checked($values['problem_type'], $key) ?> required>
                <span><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?= field_error($errors, 'problem_type') ?>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">2</span> Where are you?</legend>
          <div class="locate">
            <button type="button" class="btn btn-dark" data-locate hidden>Use my current location</button>
            <p class="locate-status" data-locate-status aria-live="polite"></p>
          </div>
          <input type="hidden" name="latitude" value="<?= e($values['latitude']) ?>">
          <input type="hidden" name="longitude" value="<?= e($values['longitude']) ?>">
          <input type="hidden" name="location_accuracy" value="<?= e($values['location_accuracy']) ?>">
          <div class="field">
            <label for="location_text">Road and nearest landmark</label>
            <textarea id="location_text" name="location_text" rows="3" maxlength="255" required placeholder="e.g. Jinja Road near Shell Kireka, heading towards Mukono"<?= field_attrs($errors, 'location_text') ?>><?= e($values['location_text']) ?></textarea>
            <p class="hint">Mention a fuel station, school, church or shop close to you, and which way you were driving.</p>
            <?= field_error($errors, 'location_text') ?>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">3</span> How can we reach you?</legend>
          <div class="field-row">
            <div class="field">
              <label for="contact_name">Your name</label>
              <input id="contact_name" name="contact_name" type="text" maxlength="100" autocomplete="name" required value="<?= e($values['contact_name']) ?>"<?= field_attrs($errors, 'contact_name') ?>>
              <?= field_error($errors, 'contact_name') ?>
            </div>
            <div class="field">
              <label for="contact_phone">Phone number</label>
              <input id="contact_phone" name="contact_phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="<?= e(config('phone.example')) ?>" value="<?= e($values['contact_phone']) ?>"<?= field_attrs($errors, 'contact_phone') ?>>
              <?= field_error($errors, 'contact_phone') ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">4</span> Your vehicle <span class="optional">optional</span></legend>
          <?php if ($vehicles): ?>
            <div class="field">
              <label for="saved_vehicle">Choose a saved vehicle</label>
              <select id="saved_vehicle" name="saved_vehicle" data-saved-vehicle<?= field_attrs($errors, 'saved_vehicle') ?>>
                <option value="">A different vehicle</option>
                <?php foreach ($vehicles as $vehicle): ?>
                  <option value="<?= e($vehicle['id']) ?>"<?= selected($values['saved_vehicle'], $vehicle['id']) ?>><?= e($vehicle['plate_number'] . ' - ' . vehicle_label($vehicle)) ?></option>
                <?php endforeach; ?>
              </select>
              <?= field_error($errors, 'saved_vehicle') ?>
            </div>
          <?php endif; ?>
          <div class="field-row" data-manual-vehicle>
            <div class="field">
              <label for="vehicle_plate">Number plate</label>
              <input id="vehicle_plate" name="vehicle_plate" type="text" maxlength="15" autocapitalize="characters" placeholder="UBA 123K" value="<?= e($values['vehicle_plate']) ?>"<?= field_attrs($errors, 'vehicle_plate') ?>>
              <?= field_error($errors, 'vehicle_plate') ?>
            </div>
            <div class="field">
              <label for="vehicle_description">Colour, make and model</label>
              <input id="vehicle_description" name="vehicle_description" type="text" maxlength="100" placeholder="White Toyota Corolla" value="<?= e($values['vehicle_description']) ?>"<?= field_attrs($errors, 'vehicle_description') ?>>
              <?= field_error($errors, 'vehicle_description') ?>
            </div>
          </div>
          <div class="field">
            <span class="label">Does the car need towing?</span>
            <div class="choice-row">
              <?php foreach ($towingOptions as $key => $label): ?>
                <label class="choice choice-inline">
                  <input type="radio" name="towing" value="<?= e($key) ?>"<?= checked($values['towing'], $key) ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </fieldset>

        <fieldset class="form-section">
          <legend><span class="step-number">5</span> Anything else we should know? <span class="optional">optional</span></legend>
          <div class="field">
            <label for="details" class="visually-hidden">More details</label>
            <textarea id="details" name="details" rows="3" maxlength="2000" placeholder="e.g. Smoke from the bonnet, the car is on a slope, I have children with me"<?= field_attrs($errors, 'details') ?>><?= e($values['details']) ?></textarea>
            <?= field_error($errors, 'details') ?>
          </div>
        </fieldset>

        <button type="submit" class="btn btn-danger btn-lg btn-block" data-busy="Sending your request...">Send help request</button>
        <p class="hint center"><?= e($garage['breakdown_callback']) ?></p>
      </form>
    </div>

    <aside class="sidebar">
      <div class="card safety-card">
        <h2>Stay safe while you wait</h2>
        <ol>
          <li>Switch on your hazard lights.</li>
          <li>Move the car as far off the road as you safely can.</li>
          <li>On a busy road, wait away from the car on the side away from traffic.</li>
          <li>Place your warning triangle (life saver) about 30 metres behind the car.</li>
          <li>Keep your phone switched on and nearby.</li>
        </ol>
      </div>
      <div class="card">
        <h2>Could you fix it yourself?</h2>
        <p class="muted">Some problems have a quick, safe fix.</p>
        <ul class="link-list">
          <li><a href="<?= e(url('faq.php#category-starting')) ?>">Car won't start</a></li>
          <li><a href="<?= e(url('faq.php#category-tyres')) ?>">Changing a flat tyre</a></li>
          <li><a href="<?= e(url('faq.php#category-engine')) ?>">Engine overheating</a></li>
        </ul>
      </div>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
