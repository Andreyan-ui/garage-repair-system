<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$statuses = booking_statuses();
$filters = ['active' => 'Active'];
foreach ($statuses as $key => $info) {
    $filters[$key] = $info['short'];
}
$filters['all'] = 'All';

$status = query_param('status', 'active');
if (!isset($filters[$status])) {
    $status = 'active';
}
$date = query_param('date');
if ($date !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
    $date = '';
}
$search = mb_substr(query_param('q'), 0, 100);

$conditions = [];
$types = '';
$params = [];
if ($status === 'active') {
    $conditions[] = "b.status IN ('pending', 'confirmed', 'in_progress')";
} elseif ($status !== 'all') {
    $conditions[] = 'b.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($date !== '') {
    $conditions[] = 'b.preferred_date = ?';
    $types .= 's';
    $params[] = $date;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $phone = normalize_phone($search) ?? '';
    $conditions[] = '(b.reference LIKE ? OR b.contact_name LIKE ? OR b.contact_phone LIKE ? OR b.contact_phone = ? OR b.vehicle_plate LIKE ?)';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $phone, $like);
}

$sort = in_array($status, ['active', 'pending', 'confirmed', 'in_progress'], true)
    ? "b.preferred_date ASC, FIELD(b.preferred_slot, 'morning', 'afternoon'), b.id"
    : 'b.preferred_date DESC, b.id DESC';

$rows = db_all(
    'SELECT b.id, b.reference, b.customer_id, b.contact_name, b.contact_phone, b.vehicle_plate, b.vehicle_make, b.vehicle_model,
            b.preferred_date, b.preferred_slot, b.status, b.created_at, s.name AS service_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id'
    . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '')
    . ' ORDER BY ' . $sort . ' LIMIT 300',
    $types,
    $params
);

$pageTitle = 'Bookings';
$activeNav = 'bookings.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Bookings</h1>
    <p class="muted"><?= count($rows) ?> <?= count($rows) === 1 ? 'booking' : 'bookings' ?> shown</p>
  </div>
</div>

<nav class="tabs" aria-label="Filter by status">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= e(url('admin/bookings.php?' . http_build_query(array_filter(['status' => $key, 'date' => $date, 'q' => $search])))) ?>"<?= $status === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="field field-grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Reference, name, phone or plate" value="<?= e($search) ?>">
  </div>
  <div class="field">
    <label for="date">Date</label>
    <input id="date" name="date" type="date" value="<?= e($date) ?>">
  </div>
  <button type="submit" class="btn btn-dark">Filter</button>
  <?php if ($search !== '' || $date !== ''): ?>
    <a class="btn btn-outline" href="<?= e(url('admin/bookings.php?status=' . $status)) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-wrap">
  <?php if (!$rows): ?>
    <p class="empty-state">No bookings match these filters.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Customer</th>
          <th>Vehicle</th>
          <th>Service</th>
          <th>Status</th>
          <th><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="nowrap">
              <strong><?= e(relative_day($row['preferred_date'])) ?></strong>
              <span class="muted"><?= e(booking_slots()[$row['preferred_slot']]['label'] ?? $row['preferred_slot']) ?> &middot; <?= e($row['reference']) ?></span>
            </td>
            <td>
              <?= e($row['contact_name']) ?><?= $row['customer_id'] ? ' <span class="badge badge-blue">Account</span>' : '' ?>
              <a class="muted nowrap" href="<?= e(tel_link($row['contact_phone'])) ?>"><?= e(format_phone($row['contact_phone'])) ?></a>
            </td>
            <td><span class="plate"><?= e($row['vehicle_plate']) ?></span><span class="muted"><?= e($row['vehicle_make'] . ' ' . $row['vehicle_model']) ?></span></td>
            <td><?= e($row['service_name']) ?></td>
            <td><?= status_badge($row['status'], $statuses, true) ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/booking.php?id=' . $row['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
