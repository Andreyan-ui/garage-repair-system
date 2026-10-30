<?php
require __DIR__ . '/includes/bootstrap.php';

$customer = require_customer();
$bookingStatuses = booking_statuses();
$breakdownStatuses = breakdown_statuses();
$problems = problem_types();

$bookings = db_all(
    'SELECT b.reference, b.preferred_date, b.preferred_slot, b.status, b.vehicle_plate, b.vehicle_make, b.vehicle_model, s.name AS service_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     WHERE b.customer_id = ?
     ORDER BY b.preferred_date DESC, b.id DESC',
    'i',
    [$customer['id']]
);
$currentBookings = array_reverse(array_values(array_filter($bookings, fn ($b) => in_array($b['status'], ['pending', 'confirmed', 'in_progress'], true))));
$pastBookings = array_values(array_filter($bookings, fn ($b) => in_array($b['status'], ['completed', 'cancelled'], true)));

$breakdowns = db_all(
    'SELECT reference, problem_type, location_text, status, created_at
     FROM breakdown_requests
     WHERE customer_id = ?
     ORDER BY id DESC
     LIMIT 10',
    'i',
    [$customer['id']]
);
$activeBreakdowns = array_filter($breakdowns, fn ($b) => in_array($b['status'], ['new', 'dispatched', 'on_site'], true));

$vehicles = db_all(
    'SELECT v.id, v.plate_number, v.make, v.model, v.model_year, v.colour,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) AS booking_count
     FROM vehicles v
     WHERE v.customer_id = ?
     ORDER BY v.created_at',
    'i',
    [$customer['id']]
);

$jobStatuses = job_statuses();
$jobs = db_all(
    "SELECT j.id, j.status, j.vehicle_plate, j.vehicle_make, j.vehicle_model, j.complaint, j.customer_note, j.recommendations,
            j.created_at, j.ready_at, j.collected_at,
            inv.id AS invoice_id, inv.total AS invoice_total, inv.status AS invoice_status,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = inv.id), 0) AS amount_paid
     FROM jobs j
     LEFT JOIN invoices inv ON inv.job_id = j.id AND inv.status = 'issued'
     WHERE j.customer_id = ?
     ORDER BY j.created_at DESC
     LIMIT 30",
    'i',
    [$customer['id']]
);
$openJobs = array_values(array_filter($jobs, fn ($j) => in_array($j['status'], open_job_statuses(), true)));
$pastJobs = array_values(array_filter($jobs, fn ($j) => !in_array($j['status'], open_job_statuses(), true)));
$totalOwed = 0;
foreach ($jobs as $job) {
    if ($job['invoice_id']) {
        $totalOwed += max(0, (int) $job['invoice_total'] - (int) $job['amount_paid']);
    }
}

$firstName = explode(' ', $customer['full_name'])[0];
$pageTitle = 'My account';
$activeNav = 'account.php';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
  <div class="container">
    <p class="eyebrow">My account</p>
    <h1>Hello, <?= e($firstName) ?></h1>
    <div class="button-row">
      <a class="btn btn-primary" href="<?= e(url('book.php')) ?>">Book a service</a>
      <a class="btn btn-danger" href="<?= e(url('breakdown.php')) ?>">Get roadside help</a>
      <a class="btn btn-outline-light" href="<?= e(url('vehicles.php')) ?>">Add a vehicle</a>
    </div>
  </div>
</section>

<section class="section">
  <div class="container layout-sidebar">
    <div class="stack-lg">
      <?php foreach ($activeBreakdowns as $breakdown): ?>
        <a class="alert alert-error alert-link" href="<?= e(url('track.php?ref=' . $breakdown['reference'])) ?>">
          <strong><?= e($breakdownStatuses[$breakdown['status']]['label']) ?></strong>
          <span><?= e($problems[$breakdown['problem_type']] ?? '') ?> at <?= e($breakdown['location_text']) ?>. View live status</span>
        </a>
      <?php endforeach; ?>

      <?php if ($totalOwed > 0): ?>
        <div class="alert alert-info">
          You have an outstanding balance of <strong><?= e(money($totalOwed)) ?></strong>. You can pay at the garage by cash, mobile money or card.
        </div>
      <?php endif; ?>

      <?php if ($openJobs): ?>
        <section>
          <h2 class="section-title">In our workshop now</h2>
          <div class="stack">
            <?php foreach ($openJobs as $job): ?>
              <article class="card workshop-card<?= $job['status'] === 'ready' ? ' is-ready' : '' ?>">
                <div class="card-head">
                  <h3><span class="plate"><?= e($job['vehicle_plate']) ?></span> <?= e($job['vehicle_make'] . ' ' . $job['vehicle_model']) ?></h3>
                  <?= status_badge($job['status'], $jobStatuses) ?>
                </div>
                <p><?= e($jobStatuses[$job['status']]['customer']) ?></p>
                <?php if ($job['customer_note']): ?>
                  <p class="note-inline"><strong>From the garage:</strong> <?= nl2br(e($job['customer_note'])) ?></p>
                <?php endif; ?>
                <p class="muted small">Job card <?= e(job_number($job['id'])) ?> &middot; checked in <?= e(format_datetime($job['created_at'])) ?></p>
                <?php if ($job['invoice_id']): ?>
                  <div class="button-row">
                    <a class="btn btn-dark btn-sm" href="<?= e(url(invoice_link((int) $job['invoice_id']))) ?>">View invoice (<?= e(money(max(0, (int) $job['invoice_total'] - (int) $job['amount_paid']))) ?> to pay)</a>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <section>
        <h2 class="section-title">Current bookings</h2>
        <?php if (!$currentBookings): ?>
          <div class="card empty-state">
            <p>You have no upcoming bookings.</p>
            <a class="btn btn-primary btn-sm" href="<?= e(url('book.php')) ?>">Book a service</a>
          </div>
        <?php else: ?>
          <div class="booking-list">
            <?php foreach ($currentBookings as $booking): ?>
              <a class="booking-card" href="<?= e(url('track.php?ref=' . $booking['reference'])) ?>">
                <div class="booking-date">
                  <span class="booking-day"><?= e(format_date($booking['preferred_date'], 'j')) ?></span>
                  <span class="booking-month"><?= e(format_date($booking['preferred_date'], 'M')) ?></span>
                </div>
                <div class="booking-main">
                  <strong><?= e($booking['service_name']) ?></strong>
                  <span class="muted"><?= e(relative_day($booking['preferred_date'])) ?>, <?= e(strtolower(booking_slots()[$booking['preferred_slot']]['label'] ?? '')) ?> &middot; <?= e($booking['vehicle_plate']) ?> <?= e($booking['vehicle_make'] . ' ' . $booking['vehicle_model']) ?></span>
                  <span class="muted small"><?= e($booking['reference']) ?></span>
                </div>
                <div class="booking-status"><?= status_badge($booking['status'], $bookingStatuses) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($pastBookings): ?>
        <section>
          <h2 class="section-title">Past bookings</h2>
          <div class="table-wrap card">
            <table class="table">
              <thead><tr><th>Date</th><th>Service</th><th>Vehicle</th><th>Status</th><th><span class="visually-hidden">View</span></th></tr></thead>
              <tbody>
                <?php foreach ($pastBookings as $booking): ?>
                  <tr>
                    <td><?= e(format_date($booking['preferred_date'], 'j M Y')) ?></td>
                    <td><?= e($booking['service_name']) ?></td>
                    <td><?= e($booking['vehicle_plate']) ?></td>
                    <td><?= status_badge($booking['status'], $bookingStatuses, true) ?></td>
                    <td><a href="<?= e(url('track.php?ref=' . $booking['reference'])) ?>">View</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($pastJobs): ?>
        <section>
          <h2 class="section-title">Service history</h2>
          <div class="table-wrap card">
            <table class="table">
              <thead><tr><th>Date</th><th>Vehicle</th><th>Work</th><th>Amount</th><th><span class="visually-hidden">Invoice</span></th></tr></thead>
              <tbody>
                <?php foreach ($pastJobs as $job): ?>
                  <?php $balance = $job['invoice_id'] ? max(0, (int) $job['invoice_total'] - (int) $job['amount_paid']) : 0; ?>
                  <tr>
                    <td class="nowrap"><?= e(format_date($job['created_at'], 'j M Y')) ?></td>
                    <td class="nowrap"><?= e($job['vehicle_plate']) ?></td>
                    <td>
                      <?= e(mb_strimwidth($job['complaint'], 0, 60, '...')) ?>
                      <?php if ($job['recommendations']): ?>
                        <br><span class="muted small">Advice: <?= e($job['recommendations']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="nowrap">
                      <?php if ($job['invoice_id']): ?>
                        <?= e(money($job['invoice_total'])) ?>
                        <?php if ($balance > 0): ?><br><span class="text-danger small"><?= e(money($balance)) ?> owed</span><?php endif; ?>
                      <?php else: ?>
                        <?= e($jobStatuses[$job['status']]['short']) ?>
                      <?php endif; ?>
                    </td>
                    <td><?php if ($job['invoice_id']): ?><a href="<?= e(url(invoice_link((int) $job['invoice_id']))) ?>">Invoice</a><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <section>
        <h2 class="section-title">Breakdown help requests</h2>
        <?php if (!$breakdowns): ?>
          <div class="card empty-state"><p>You haven't requested roadside help. We hope it stays that way.</p></div>
        <?php else: ?>
          <div class="table-wrap card">
            <table class="table">
              <thead><tr><th>Sent</th><th>Problem</th><th>Location</th><th>Status</th><th><span class="visually-hidden">View</span></th></tr></thead>
              <tbody>
                <?php foreach ($breakdowns as $breakdown): ?>
                  <tr>
                    <td><?= e(format_datetime($breakdown['created_at'])) ?></td>
                    <td><?= e($problems[$breakdown['problem_type']] ?? $breakdown['problem_type']) ?></td>
                    <td><?= e($breakdown['location_text']) ?></td>
                    <td><?= status_badge($breakdown['status'], $breakdownStatuses, true) ?></td>
                    <td><a href="<?= e(url('track.php?ref=' . $breakdown['reference'])) ?>">View</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="sidebar">
      <div class="card">
        <div class="card-head">
          <h2>My vehicles</h2>
          <a href="<?= e(url('vehicles.php')) ?>">Manage</a>
        </div>
        <?php if (!$vehicles): ?>
          <p class="muted">Save your car to book faster next time.</p>
          <a class="btn btn-outline btn-block" href="<?= e(url('vehicles.php')) ?>">Add a vehicle</a>
        <?php else: ?>
          <ul class="vehicle-list">
            <?php foreach ($vehicles as $vehicle): ?>
              <li>
                <span class="plate"><?= e($vehicle['plate_number']) ?></span>
                <span><?= e(vehicle_label($vehicle)) ?><?= $vehicle['model_year'] ? ' (' . (int) $vehicle['model_year'] . ')' : '' ?></span>
                <span class="muted small"><?= (int) $vehicle['booking_count'] ?> <?= (int) $vehicle['booking_count'] === 1 ? 'booking' : 'bookings' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-head">
          <h2>My details</h2>
          <a href="<?= e(url('profile.php')) ?>">Edit</a>
        </div>
        <dl class="details compact">
          <div><dt>Name</dt><dd><?= e($customer['full_name']) ?></dd></div>
          <div><dt>Phone</dt><dd><?= e(format_phone($customer['phone'])) ?></dd></div>
          <div><dt>Email</dt><dd><?= $customer['email'] ? e($customer['email']) : '<span class="muted">Not added</span>' ?></dd></div>
          <div><dt>Member since</dt><dd><?= e(format_date($customer['created_at'], 'F Y')) ?></dd></div>
        </dl>
      </div>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
