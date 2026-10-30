<?php
require __DIR__ . '/includes/bootstrap.php';

$garage = config('garage');
$customer = current_customer();
$lookupReference = query_param('ref');
$lookupPhone = '';
$lookupError = '';

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'lookup') {
        $lookupReference = input('reference');
        $lookupPhone = input('phone');
        if (too_many_attempts('track', 'lookup', 10)) {
            $lookupError = 'Too many attempts. Please wait 15 minutes, or call us on ' . format_phone($garage['phone']) . '.';
        } else {
            $reference = normalize_reference($lookupReference);
            $phone = normalize_phone($lookupPhone);
            $found = ($reference !== null && $phone !== null) ? find_tracked_record($reference) : null;
            if ($found !== null && hash_equals($found['contact_phone'], $phone)) {
                remember_reference($found['reference']);
                redirect('track.php?ref=' . rawurlencode($found['reference']));
            }
            record_failed_attempt('track', 'lookup');
            $lookupError = 'We could not find a booking or help request with that reference and phone number. Check both and try again.';
        }
    }

    if ($action === 'cancel') {
        $reference = normalize_reference(input('reference')) ?? '';
        $found = $reference !== '' ? find_tracked_record($reference) : null;
        if ($found === null || !can_view_record($found, $customer)) {
            abort(404, 'Not found', 'We could not find that booking.');
        }
        $cancelled = 0;
        if ($found['kind'] === 'booking') {
            $cancelled = db_execute(
                "UPDATE bookings SET status = 'cancelled', cancelled_by = 'customer'
                 WHERE id = ? AND status IN ('pending', 'confirmed')",
                'i',
                [$found['id']]
            );
            flash($cancelled ? 'success' : 'error', $cancelled
                ? 'Your booking has been cancelled.'
                : 'This booking can no longer be cancelled online. Please call us.');
        } else {
            $cancelled = db_execute(
                "UPDATE breakdown_requests SET status = 'cancelled', cancelled_by = 'customer'
                 WHERE id = ? AND status = 'new'",
                'i',
                [$found['id']]
            );
            flash($cancelled ? 'success' : 'error', $cancelled
                ? 'Your help request has been cancelled. We are glad you are back on the road.'
                : 'Help is already on the way, so this request can no longer be cancelled online. Please call us.');
        }
        redirect('track.php?ref=' . rawurlencode($reference));
    }
}

$record = null;
$normalizedLookup = normalize_reference($lookupReference);
if (!is_post() && $normalizedLookup !== null) {
    $found = find_tracked_record($normalizedLookup);
    if ($found !== null && can_view_record($found, $customer)) {
        $record = $found;
    }
}

$isBreakdown = $record !== null && $record['kind'] === 'breakdown';
$isActiveBreakdown = $isBreakdown && in_array($record['status'], ['new', 'dispatched', 'on_site'], true);
$autoRefresh = $isActiveBreakdown ? 30 : 0;

$pageTitle = $record ? 'Reference ' . $record['reference'] : 'Track a booking';
$activeNav = 'track.php';
require __DIR__ . '/includes/header.php';
?>
<?php if ($record === null): ?>
<section class="page-header">
  <div class="container">
    <p class="eyebrow">Track</p>
    <h1>Track a booking or help request</h1>
    <p class="lead">Enter the reference we gave you and the phone number you used.</p>
  </div>
</section>

<section class="section">
  <div class="container narrow">
    <form method="post" class="card form-card" action="<?= e(url('track.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="lookup">
      <?php if ($lookupError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($lookupError) ?></div>
      <?php endif; ?>
      <div class="field">
        <label for="reference">Reference</label>
        <input id="reference" name="reference" type="text" maxlength="20" autocapitalize="characters" autocomplete="off" required placeholder="BK-XXXXXXXX or BD-XXXXXXXX" value="<?= e($lookupReference) ?>">
        <p class="hint">Booking references start with BK. Breakdown references start with BD.</p>
      </div>
      <div class="field">
        <label for="phone">Phone number used</label>
        <input id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="<?= e(config('phone.example')) ?>" value="<?= e($lookupPhone) ?>">
      </div>
      <button type="submit" class="btn btn-dark btn-block">Find my booking</button>
    </form>
    <p class="center muted">
      <?php if ($customer): ?>
        All your bookings are also listed in <a href="<?= e(url('account.php')) ?>">your account</a>.
      <?php else: ?>
        Have an account? <a href="<?= e(url('login.php?next=account.php')) ?>">Log in</a> to see all your bookings.
      <?php endif; ?>
    </p>
  </div>
</section>

<?php elseif ($isBreakdown):
    $statuses = breakdown_statuses();
    $problems = problem_types();
    $towingOptions = towing_options();
    $minutesAway = minutes_until($record['eta_at']);
?>
<section class="page-header <?= $isActiveBreakdown ? 'page-header-danger' : '' ?>">
  <div class="container">
    <p class="eyebrow">Breakdown help request</p>
    <h1><?= e($statuses[$record['status']]['label'] ?? $record['status']) ?></h1>
    <p class="lead">Reference <strong class="reference"><?= e($record['reference']) ?></strong> &middot; sent <?= e(time_ago($record['created_at'])) ?></p>
    <?php if ($isActiveBreakdown): ?>
      <div class="button-row">
        <a class="btn btn-light btn-lg" href="<?= e(tel_link($garage['phone'])) ?>">Call the garage</a>
        <?php if (!empty($record['responder_phone'])): ?>
          <a class="btn btn-outline-light btn-lg" href="<?= e(tel_link($record['responder_phone'])) ?>">Call <?= e($record['responder_name'] ?: 'your mechanic') ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div class="stack-lg">
      <?php if ($record['status'] === 'cancelled'): ?>
        <div class="alert alert-info">
          This request was cancelled <?= $record['cancelled_by'] === 'customer' ? 'by you' : 'by the garage' ?>.
          Still need help? <a href="<?= e(url('breakdown.php')) ?>">Send a new request</a> or call <?= e(format_phone($garage['phone'])) ?>.
        </div>
      <?php else: ?>
        <div class="card">
          <?= progress_steps([
              'new' => 'Received',
              'dispatched' => 'On the way',
              'on_site' => 'With you',
              'completed' => 'Resolved',
          ], $record['status']) ?>
          <?php if ($record['status'] === 'new'): ?>
            <p class="status-message"><?= e($garage['breakdown_callback']) ?></p>
          <?php elseif ($record['status'] === 'dispatched'): ?>
            <p class="status-message">
              <?= e($record['responder_name'] ?: 'A mechanic') ?> is on the way to you.
              <?php if ($minutesAway !== null && $minutesAway > 0): ?>
                Expected around <strong><?= e(format_time($record['eta_at'])) ?></strong> (about <?= (int) $minutesAway ?> min).
              <?php elseif ($minutesAway !== null): ?>
                They should be with you any moment now.
              <?php endif; ?>
            </p>
          <?php elseif ($record['status'] === 'on_site'): ?>
            <p class="status-message">Your mechanic has arrived and is working on your car.</p>
          <?php else: ?>
            <p class="status-message">This request has been resolved. Thank you for calling <?= e($garage['name']) ?>.</p>
          <?php endif; ?>
          <?php if ($isActiveBreakdown): ?>
            <p class="hint">This page updates automatically every 30 seconds.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($record['garage_note'])): ?>
        <div class="card note-card">
          <h2>Message from the garage</h2>
          <p><?= nl2br(e($record['garage_note'])) ?></p>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Request details</h2>
        <dl class="details">
          <div><dt>Problem</dt><dd><?= e($problems[$record['problem_type']] ?? $record['problem_type']) ?></dd></div>
          <div><dt>Location</dt><dd><?= e($record['location_text']) ?><?= $record['latitude'] !== null ? '<br><span class="muted">GPS location shared</span>' : '' ?></dd></div>
          <?php if (!empty($record['vehicle_plate']) || !empty($record['vehicle_description'])): ?>
            <div><dt>Vehicle</dt><dd><?= e(trim(($record['vehicle_plate'] ?? '') . ' ' . ($record['vehicle_description'] ?? ''))) ?></dd></div>
          <?php endif; ?>
          <div><dt>Towing</dt><dd><?= e($towingOptions[$record['towing']] ?? $record['towing']) ?></dd></div>
          <div><dt>Contact</dt><dd><?= e($record['contact_name']) ?>, <?= e(format_phone($record['contact_phone'])) ?></dd></div>
          <?php if (!empty($record['details'])): ?>
            <div><dt>Details</dt><dd><?= nl2br(e($record['details'])) ?></dd></div>
          <?php endif; ?>
          <div><dt>Sent</dt><dd><?= e(format_datetime($record['created_at'])) ?></dd></div>
        </dl>
      </div>

      <?php if ($record['status'] === 'new'): ?>
        <form method="post" class="card card-muted" data-confirm="Cancel this help request? Only do this if you no longer need help.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="reference" value="<?= e($record['reference']) ?>">
          <h2>Sorted it yourself?</h2>
          <p class="muted">If your car is working again, let us know so we can help someone else.</p>
          <button type="submit" class="btn btn-outline">Cancel my help request</button>
        </form>
      <?php endif; ?>
    </div>

    <aside class="sidebar">
      <?php if ($isActiveBreakdown): ?>
        <div class="card safety-card">
          <h2>While you wait</h2>
          <ul>
            <li>Keep your hazard lights on.</li>
            <li>Stay off the road and away from traffic.</li>
            <li>Keep your phone on so we can reach you.</li>
            <li>If anyone is hurt, call <?= e($garage['emergency_numbers']) ?>.</li>
          </ul>
        </div>
      <?php endif; ?>
      <div class="card">
        <h2>Save your reference</h2>
        <p class="reference-large"><?= e($record['reference']) ?></p>
        <p class="muted">Use it with your phone number to check this request again from any device.</p>
      </div>
      <?php if (!$customer): ?>
        <div class="card">
          <h2>Create an account</h2>
          <p class="muted">Keep all your bookings, help requests and vehicles in one place.</p>
          <a class="btn btn-outline btn-block" href="<?= e(url('register.php')) ?>">Create an account</a>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</section>

<?php else:
    $statuses = booking_statuses();
    $jobStatuses = job_statuses();
    $bookingJob = db_one(
        'SELECT id, status, customer_note, diagnosis, recommendations, odometer_km, created_at, ready_at, collected_at FROM jobs WHERE booking_id = ?',
        'i',
        [$record['id']]
    );
    $bookingInvoice = $bookingJob ? active_invoice_for_job((int) $bookingJob['id']) : null;
    $plannedWork = [];
    if ($bookingJob && $bookingJob['status'] === 'awaiting_approval' && !$bookingInvoice) {
        $plannedWork = db_all('SELECT description, quantity, unit_price FROM job_items WHERE job_id = ? ORDER BY id', 'i', [$bookingJob['id']]);
    }
    $canCancel = !$bookingJob && in_array($record['status'], ['pending', 'confirmed'], true);
?>
<section class="page-header">
  <div class="container">
    <p class="eyebrow">Service booking</p>
    <h1><?= e($record['service_name']) ?></h1>
    <p class="lead">Reference <strong class="reference"><?= e($record['reference']) ?></strong> &middot; <?= $bookingJob ? status_badge($bookingJob['status'], $jobStatuses) : status_badge($record['status'], $statuses) ?></p>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div class="stack-lg">
      <?php if ($record['status'] === 'cancelled'): ?>
        <div class="alert alert-info">
          This booking was cancelled <?= $record['cancelled_by'] === 'customer' ? 'by you' : 'by the garage' ?>.
          <a href="<?= e(url('book.php?service=' . $record['service_id'])) ?>">Book again</a>
        </div>
      <?php else: ?>
        <div class="card">
          <?= progress_steps([
              'pending' => 'Requested',
              'confirmed' => 'Confirmed',
              'in_progress' => 'In the workshop',
              'completed' => 'Ready',
          ], $record['status']) ?>
          <p class="status-message">
            <?php if ($record['status'] === 'pending'): ?>
              We have received your booking and will call you to confirm the time.
            <?php elseif ($record['status'] === 'confirmed'): ?>
              Your booking is confirmed. Please bring your car in on <strong><?= e(format_date($record['preferred_date'], 'l j F')) ?></strong>, <?= e(strtolower(slot_label($record['preferred_slot']))) ?>.
            <?php elseif ($bookingJob): ?>
              <?= e($jobStatuses[$bookingJob['status']]['customer']) ?>
            <?php elseif ($record['status'] === 'in_progress'): ?>
              Your car is in the workshop and our mechanics are working on it.
            <?php else: ?>
              The work is finished. Thank you for choosing <?= e($garage['name']) ?>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if ($bookingJob): ?>
        <div class="card workshop-card<?= $bookingJob['status'] === 'ready' ? ' is-ready' : '' ?>">
          <div class="card-head">
            <h2>In the workshop</h2>
            <?= status_badge($bookingJob['status'], $jobStatuses) ?>
          </div>
          <dl class="details compact">
            <div><dt>Job card</dt><dd><?= e(job_number($bookingJob['id'])) ?></dd></div>
            <div><dt>Checked in</dt><dd><?= e(format_datetime($bookingJob['created_at'])) ?></dd></div>
            <?php if ($bookingJob['ready_at']): ?>
              <div><dt>Ready</dt><dd><?= e(format_datetime($bookingJob['ready_at'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($bookingJob['collected_at']): ?>
              <div><dt>Collected</dt><dd><?= e(format_datetime($bookingJob['collected_at'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($bookingJob['diagnosis']): ?>
              <div><dt>What we found</dt><dd><?= nl2br(e($bookingJob['diagnosis'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($bookingJob['recommendations']): ?>
              <div><dt>Our advice</dt><dd><?= nl2br(e($bookingJob['recommendations'])) ?></dd></div>
            <?php endif; ?>
          </dl>
          <?php if ($bookingJob['customer_note']): ?>
            <p class="note-inline"><strong>From the garage:</strong> <?= nl2br(e($bookingJob['customer_note'])) ?></p>
          <?php endif; ?>

          <?php if ($plannedWork): ?>
            <?php $quote = calculate_totals($plannedWork); ?>
            <h3>Work we recommend</h3>
            <table class="price-list">
              <tbody>
                <?php foreach ($plannedWork as $line): ?>
                  <tr><td><?= e($line['description']) ?><?= (float) $line['quantity'] != 1.0 ? ' &times; ' . e(format_quantity($line['quantity'])) : '' ?></td><td><?= e(money(line_total($line['quantity'], $line['unit_price']))) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr><td>Estimated total</td><td><?= e(money($quote['total'])) ?></td></tr>
              </tfoot>
            </table>
            <div class="button-row">
              <a class="btn btn-primary" href="<?= e(tel_link($garage['phone'])) ?>">Call to approve</a>
              <a class="btn btn-outline" href="<?= e(whatsapp_link($garage['whatsapp'], 'Hello, I approve the work on job card ' . job_number($bookingJob['id']) . ' (booking ' . $record['reference'] . ').')) ?>" target="_blank" rel="noopener">Approve on WhatsApp</a>
            </div>
          <?php endif; ?>

          <?php if ($bookingInvoice): ?>
            <?php $invoiceBalance = invoice_balance($bookingInvoice); ?>
            <dl class="details compact">
              <div><dt>Invoice</dt><dd><?= e(invoice_number($bookingInvoice['id'])) ?> &middot; <?= e(money($bookingInvoice['total'])) ?></dd></div>
              <div><dt><?= $invoiceBalance > 0 ? 'To pay' : 'Balance' ?></dt><dd><?= $invoiceBalance > 0 ? '<strong>' . e(money($invoiceBalance)) . '</strong>' : 'Paid in full, thank you' ?></dd></div>
            </dl>
            <a class="btn btn-dark" href="<?= e(url(invoice_link((int) $bookingInvoice['id']))) ?>">View invoice</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($record['garage_note']) && !$bookingJob): ?>
        <div class="card note-card">
          <h2>Message from the garage</h2>
          <p><?= nl2br(e($record['garage_note'])) ?></p>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Booking details</h2>
        <dl class="details">
          <div><dt>Service</dt><dd><?= e($record['service_name']) ?></dd></div>
          <div><dt>Date</dt><dd><?= e(relative_day($record['preferred_date'])) ?><?= relative_day($record['preferred_date']) !== format_date($record['preferred_date']) ? ', ' . e(format_date($record['preferred_date'])) : '' ?></dd></div>
          <div><dt>Time</dt><dd><?= e(slot_label($record['preferred_slot'])) ?></dd></div>
          <div><dt>Vehicle</dt><dd><?= e($record['vehicle_plate']) ?>, <?= e($record['vehicle_make'] . ' ' . $record['vehicle_model']) ?></dd></div>
          <div><dt>Contact</dt><dd><?= e($record['contact_name']) ?>, <?= e(format_phone($record['contact_phone'])) ?><?= $record['contact_email'] ? '<br>' . e($record['contact_email']) : '' ?></dd></div>
          <?php if (!empty($record['message'])): ?>
            <div><dt>Your message</dt><dd><?= nl2br(e($record['message'])) ?></dd></div>
          <?php endif; ?>
          <div><dt>Booked</dt><dd><?= e(format_datetime($record['created_at'])) ?></dd></div>
        </dl>
      </div>

      <?php if ($canCancel): ?>
        <form method="post" class="card card-muted" data-confirm="Cancel this booking?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="reference" value="<?= e($record['reference']) ?>">
          <h2>Can't make it?</h2>
          <p class="muted">Cancel online, or call us to move your booking to another day.</p>
          <button type="submit" class="btn btn-outline">Cancel booking</button>
        </form>
      <?php endif; ?>
    </div>

    <aside class="sidebar">
      <div class="card">
        <h2>Save your reference</h2>
        <p class="reference-large"><?= e($record['reference']) ?></p>
        <p class="muted">Use it with your phone number to check this booking again from any device.</p>
      </div>
      <div class="card">
        <h2>Questions?</h2>
        <p class="muted">Call or WhatsApp us and quote your reference.</p>
        <div class="stack">
          <a class="btn btn-dark btn-block" href="<?= e(tel_link($garage['phone'])) ?>">Call <?= e(format_phone($garage['phone'])) ?></a>
          <a class="btn btn-outline btn-block" href="<?= e(whatsapp_link($garage['whatsapp'], 'Hello, I have a question about booking ' . $record['reference'] . '.')) ?>" target="_blank" rel="noopener">WhatsApp us</a>
        </div>
      </div>
      <?php if (!$customer): ?>
        <div class="card">
          <h2>Create an account</h2>
          <p class="muted">Keep all your bookings and vehicles in one place.</p>
          <a class="btn btn-outline btn-block" href="<?= e(url('register.php')) ?>">Create an account</a>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
