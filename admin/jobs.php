<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$statuses = job_statuses();
$states = invoice_states();
$filters = [
    'open' => 'In the workshop',
    'ready' => 'Ready',
    'waiting' => 'Waiting',
    'collected' => 'Collected',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
$filter = query_param('status', 'open');
if (!isset($filters[$filter])) {
    $filter = 'open';
}
$search = mb_substr(query_param('q'), 0, 100);

$conditions = [];
$types = '';
$params = [];
$conditions[] = match ($filter) {
    'open' => "j.status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval', 'ready')",
    'ready' => "j.status = 'ready'",
    'waiting' => "j.status IN ('awaiting_parts', 'awaiting_approval')",
    'collected' => "j.status = 'collected'",
    'cancelled' => "j.status = 'cancelled'",
    default => '1 = 1',
};
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $phone = normalize_phone($search) ?? '';
    $number = parse_document_number($search, 'JC') ?? 0;
    $conditions[] = '(j.id = ? OR j.contact_name LIKE ? OR j.contact_phone LIKE ? OR j.contact_phone = ? OR j.vehicle_plate LIKE ? OR j.vehicle_make LIKE ? OR j.vehicle_model LIKE ?)';
    $types .= 'issssss';
    array_push($params, $number, $like, $like, $phone, $like, $like, $like);
}

$order = in_array($filter, ['open', 'ready', 'waiting'], true)
    ? "FIELD(j.status, 'ready', 'checked_in', 'in_progress', 'awaiting_approval', 'awaiting_parts'), j.promised_at IS NULL, j.promised_at, j.created_at"
    : 'j.created_at DESC';

$jobs = db_all(
    "SELECT j.id, j.status, j.contact_name, j.contact_phone, j.vehicle_plate, j.vehicle_make, j.vehicle_model,
            j.complaint, j.promised_at, j.created_at, j.booking_id, j.breakdown_id, m.full_name AS mechanic_name,
            (SELECT COALESCE(SUM(ROUND(ji.quantity * ji.unit_price)), 0) FROM job_items ji WHERE ji.job_id = j.id) AS work_total,
            inv.id AS invoice_id, inv.total AS invoice_total, inv.status AS invoice_status,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = inv.id), 0) AS amount_paid
     FROM jobs j
     LEFT JOIN mechanics m ON m.id = j.mechanic_id
     LEFT JOIN invoices inv ON inv.job_id = j.id AND inv.status = 'issued'
     WHERE " . implode(' AND ', $conditions) . "
     ORDER BY $order
     LIMIT 300",
    $types,
    $params
);

$pageTitle = 'Job cards';
$activeNav = 'jobs.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Job cards</h1>
    <p class="muted">Every car that comes into the workshop, from check-in to collection.</p>
  </div>
  <a class="btn btn-primary" href="<?= e(url('admin/job_new.php')) ?>">Check in a car</a>
</div>

<nav class="tabs" aria-label="Filter job cards">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= e(url('admin/jobs.php?' . http_build_query(array_filter(['status' => $key, 'q' => $search])))) ?>"<?= $filter === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar">
  <input type="hidden" name="status" value="<?= e($filter) ?>">
  <div class="field field-grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Job number, name, phone, plate or model" value="<?= e($search) ?>">
  </div>
  <button type="submit" class="btn btn-dark">Search</button>
  <?php if ($search !== ''): ?>
    <a class="btn btn-outline" href="<?= e(url('admin/jobs.php?status=' . $filter)) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-wrap">
  <?php if (!$jobs): ?>
    <p class="empty-state"><?= $filter === 'open' && $search === '' ? 'The workshop is empty. Check in a car to start a job card.' : 'No job cards match.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Job</th>
          <th>Vehicle</th>
          <th>Customer</th>
          <th>Concern</th>
          <th>Mechanic</th>
          <th>Status</th>
          <th class="num">Amount</th>
          <th><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $job): ?>
          <?php
          $invoiceState = $job['invoice_id'] ? payment_state(['status' => $job['invoice_status'], 'total' => $job['invoice_total'], 'amount_paid' => $job['amount_paid']]) : null;
          $overdue = $job['promised_at'] && in_array($job['status'], ['checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval'], true) && strtotime($job['promised_at']) < time();
          ?>
          <tr class="<?= $job['status'] === 'ready' ? 'is-ready' : '' ?>">
            <td class="nowrap">
              <a class="row-link" href="<?= e(url('admin/job.php?id=' . $job['id'])) ?>"><?= e(job_number($job['id'])) ?></a>
              <span class="muted"><?= e(time_ago($job['created_at'])) ?></span>
            </td>
            <td><span class="plate"><?= e($job['vehicle_plate']) ?></span><span class="muted"><?= e($job['vehicle_make'] . ' ' . $job['vehicle_model']) ?></span></td>
            <td><?= e($job['contact_name']) ?><a class="muted nowrap" href="<?= e(tel_link($job['contact_phone'])) ?>"><?= e(format_phone($job['contact_phone'])) ?></a></td>
            <td><?= e(mb_strimwidth($job['complaint'], 0, 70, '...')) ?><?php if ($job['booking_id']): ?><span class="muted">From a booking</span><?php elseif ($job['breakdown_id']): ?><span class="muted">From a breakdown</span><?php endif; ?></td>
            <td><?= $job['mechanic_name'] ? e($job['mechanic_name']) : '<span class="muted">Not assigned</span>' ?></td>
            <td>
              <?= status_badge($job['status'], $statuses, true) ?>
              <?php if ($job['promised_at'] && !in_array($job['status'], ['collected', 'cancelled'], true)): ?>
                <span class="muted<?= $overdue ? ' text-danger' : '' ?>"><?= $overdue ? 'Late, was due ' : 'Due ' ?><?= e(format_datetime($job['promised_at'])) ?></span>
              <?php endif; ?>
            </td>
            <td class="num">
              <?php if ($job['invoice_id']): ?>
                <?= e(money($job['invoice_total'])) ?>
                <span class="muted"><?= status_badge($invoiceState, $states, true) ?></span>
              <?php else: ?>
                <?= e(money($job['work_total'])) ?>
                <span class="muted">Not invoiced</span>
              <?php endif; ?>
            </td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/job.php?id=' . $job['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
