<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$id = (int) query_param('id');
$customer = db_one('SELECT id, full_name, phone, email, created_at FROM customers WHERE id = ?', 'i', [$id]);
if ($customer === null) {
    abort(404, 'Customer not found', 'That customer account does not exist.');
}

$bookingStatuses = booking_statuses();
$breakdownStatuses = breakdown_statuses();
$problems = problem_types();

$vehicles = db_all('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY created_at', 'i', [$id]);
$bookings = db_all(
    'SELECT b.id, b.reference, b.preferred_date, b.preferred_slot, b.status, b.vehicle_plate, s.name AS service_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     WHERE b.customer_id = ?
     ORDER BY b.preferred_date DESC, b.id DESC',
    'i',
    [$id]
);
$breakdowns = db_all(
    'SELECT id, reference, problem_type, location_text, status, created_at
     FROM breakdown_requests
     WHERE customer_id = ?
     ORDER BY created_at DESC',
    'i',
    [$id]
);

$jobs = db_all(
    "SELECT j.id, j.status, j.vehicle_plate, j.complaint, j.created_at,
            inv.id AS invoice_id, inv.total AS invoice_total, inv.status AS invoice_status,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = inv.id), 0) AS amount_paid
     FROM jobs j
     LEFT JOIN invoices inv ON inv.job_id = j.id AND inv.status = 'issued'
     WHERE j.customer_id = ?
     ORDER BY j.created_at DESC",
    'i',
    [$id]
);
$owedTotal = 0;
$spentTotal = 0;
foreach ($jobs as $job) {
    if ($job['invoice_id']) {
        $owedTotal += max(0, (int) $job['invoice_total'] - (int) $job['amount_paid']);
        $spentTotal += (int) $job['amount_paid'];
    }
}
$jobStatuses = job_statuses();
$states = invoice_states();

$pageTitle = $customer['full_name'];
$activeNav = 'customers.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/customers.php')) ?>">&larr; All customers</a></p>
<div class="page-head">
  <div>
    <h1><?= e($customer['full_name']) ?></h1>
    <p class="muted">Customer since <?= e(format_date($customer['created_at'], 'j F Y')) ?></p>
  </div>
  <div class="button-row">
    <a class="btn btn-primary" href="<?= e(url('admin/job_new.php?phone=' . rawurlencode($customer['phone']))) ?>">Check in a car</a>
    <a class="btn btn-dark" href="<?= e(tel_link($customer['phone'])) ?>">Call <?= e(format_phone($customer['phone'])) ?></a>
    <a class="btn btn-outline" href="<?= e(whatsapp_link($customer['phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><span class="stat-value"><?= count($jobs) ?></span><span class="stat-label">Workshop visits</span></div>
  <div class="stat stat-money"><span class="stat-value"><?= e(money($spentTotal)) ?></span><span class="stat-label">Paid to date</span></div>
  <div class="stat stat-money<?= $owedTotal > 0 ? ' stat-warn' : '' ?>"><span class="stat-value"><?= e(money($owedTotal)) ?></span><span class="stat-label">Still owed</span></div>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <section class="card panel">
      <div class="panel-head"><h2>Workshop visits (<?= count($jobs) ?>)</h2></div>
      <?php if (!$jobs): ?>
        <p class="empty-state">No job cards are linked to this account.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Job</th><th>Vehicle</th><th>Concern</th><th>Status</th><th class="num">Invoice</th></tr></thead>
            <tbody>
              <?php foreach ($jobs as $job): ?>
                <tr>
                  <td class="nowrap"><a class="row-link" href="<?= e(url('admin/job.php?id=' . $job['id'])) ?>"><?= e(job_number($job['id'])) ?></a><span class="muted"><?= e(format_date($job['created_at'], 'j M Y')) ?></span></td>
                  <td><span class="plate"><?= e($job['vehicle_plate']) ?></span></td>
                  <td><?= e(mb_strimwidth($job['complaint'], 0, 50, '...')) ?></td>
                  <td><?= status_badge($job['status'], $jobStatuses, true) ?></td>
                  <td class="num">
                    <?php if ($job['invoice_id']): ?>
                      <a href="<?= e(url('admin/invoice.php?id=' . $job['invoice_id'])) ?>"><?= e(money($job['invoice_total'])) ?></a>
                      <span class="muted"><?= status_badge(payment_state(['status' => $job['invoice_status'], 'total' => $job['invoice_total'], 'amount_paid' => $job['amount_paid']]), $states, true) ?></span>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card panel">
      <div class="panel-head"><h2>Bookings (<?= count($bookings) ?>)</h2></div>
      <?php if (!$bookings): ?>
        <p class="empty-state">No bookings yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Date</th><th>Service</th><th>Vehicle</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($bookings as $row): ?>
                <tr>
                  <td class="nowrap"><a class="row-link" href="<?= e(url('admin/booking.php?id=' . $row['id'])) ?>"><?= e(format_date($row['preferred_date'], 'j M Y')) ?></a><span class="muted"><?= e($row['reference']) ?></span></td>
                  <td><?= e($row['service_name']) ?></td>
                  <td><span class="plate"><?= e($row['vehicle_plate']) ?></span></td>
                  <td><?= status_badge($row['status'], $bookingStatuses, true) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card panel">
      <div class="panel-head"><h2>Breakdown requests (<?= count($breakdowns) ?>)</h2></div>
      <?php if (!$breakdowns): ?>
        <p class="empty-state">No breakdown requests.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Received</th><th>Problem</th><th>Location</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($breakdowns as $row): ?>
                <tr>
                  <td class="nowrap"><a class="row-link" href="<?= e(url('admin/breakdown.php?id=' . $row['id'])) ?>"><?= e(format_datetime($row['created_at'])) ?></a></td>
                  <td><?= e($problems[$row['problem_type']] ?? $row['problem_type']) ?></td>
                  <td><?= e($row['location_text']) ?></td>
                  <td><?= status_badge($row['status'], $breakdownStatuses, true) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack-lg">
    <section class="card">
      <h2>Contact details</h2>
      <dl class="details compact">
        <div><dt>Phone</dt><dd><?= e(format_phone($customer['phone'])) ?></dd></div>
        <div><dt>Email</dt><dd><?= $customer['email'] ? '<a href="mailto:' . e($customer['email']) . '">' . e($customer['email']) . '</a>' : '<span class="muted">Not given</span>' ?></dd></div>
      </dl>
    </section>
    <section class="card">
      <h2>Vehicles (<?= count($vehicles) ?>)</h2>
      <?php if (!$vehicles): ?>
        <p class="muted">No saved vehicles.</p>
      <?php else: ?>
        <ul class="vehicle-list">
          <?php foreach ($vehicles as $vehicle): ?>
            <li>
              <span class="plate"><?= e($vehicle['plate_number']) ?></span>
              <span><?= e(vehicle_label($vehicle)) ?><?= $vehicle['model_year'] ? ' (' . (int) $vehicle['model_year'] . ')' : '' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
