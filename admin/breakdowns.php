<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$statuses = breakdown_statuses();
$problems = problem_types();
$towingOptions = towing_options();
$filters = [
    'open' => 'Open',
    'completed' => 'Resolved',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
$filter = query_param('status', 'open');
if (!isset($filters[$filter])) {
    $filter = 'open';
}
$where = match ($filter) {
    'open' => "WHERE status IN ('new', 'dispatched', 'on_site')",
    'completed' => "WHERE status = 'completed'",
    'cancelled' => "WHERE status = 'cancelled'",
    default => '',
};
$order = $filter === 'open'
    ? "ORDER BY FIELD(status, 'new', 'dispatched', 'on_site'), created_at"
    : 'ORDER BY created_at DESC';

$rows = db_all(
    "SELECT id, reference, contact_name, contact_phone, problem_type, location_text, latitude, longitude,
            vehicle_plate, vehicle_description, towing, status, responder_name, eta_at, created_at
     FROM breakdown_requests
     $where
     $order
     LIMIT 200"
);

$pageTitle = 'Breakdowns';
$activeNav = 'breakdowns.php';
$autoRefresh = $filter === 'open' ? 30 : 0;
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Breakdown requests</h1>
    <p class="muted"><?= $filter === 'open' ? 'New requests appear here automatically. This page refreshes every 30 seconds.' : 'Past roadside help requests.' ?></p>
  </div>
</div>

<nav class="tabs" aria-label="Filter breakdowns">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= e(url('admin/breakdowns.php?status=' . $key)) ?>"<?= $filter === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<div class="card table-wrap">
  <?php if (!$rows): ?>
    <p class="empty-state"><?= $filter === 'open' ? 'No one is waiting for roadside help right now.' : 'No requests to show.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Received</th>
          <th>Customer</th>
          <th>Problem</th>
          <th>Location</th>
          <th>Vehicle</th>
          <th>Status</th>
          <th><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr class="<?= $row['status'] === 'new' ? 'is-urgent' : '' ?>">
            <td class="nowrap">
              <strong><?= e(time_ago($row['created_at'])) ?></strong>
              <span class="muted"><?= e($row['reference']) ?></span>
            </td>
            <td>
              <?= e($row['contact_name']) ?>
              <a class="muted nowrap" href="<?= e(tel_link($row['contact_phone'])) ?>"><?= e(format_phone($row['contact_phone'])) ?></a>
            </td>
            <td>
              <?= e($problems[$row['problem_type']] ?? $row['problem_type']) ?>
              <span class="muted">Towing: <?= e($towingOptions[$row['towing']] ?? $row['towing']) ?></span>
            </td>
            <td>
              <?= e($row['location_text']) ?>
              <a class="muted" href="<?= e(map_link($row['latitude'], $row['longitude'], $row['location_text'])) ?>" target="_blank" rel="noopener"><?= $row['latitude'] !== null ? 'Open GPS pin' : 'Search on map' ?></a>
            </td>
            <td><?= e($row['vehicle_plate'] ?? '') ?><span class="muted"><?= e($row['vehicle_description'] ?? '') ?></span></td>
            <td>
              <?= status_badge($row['status'], $statuses, true) ?>
              <?php if ($row['responder_name'] && in_array($row['status'], ['dispatched', 'on_site'], true)): ?>
                <span class="muted"><?= e($row['responder_name']) ?><?= $row['status'] === 'dispatched' && $row['eta_at'] ? ', ETA ' . e(format_time($row['eta_at'])) : '' ?></span>
              <?php endif; ?>
            </td>
            <td class="actions"><a class="btn btn-sm <?= $row['status'] === 'new' ? 'btn-danger' : 'btn-outline' ?>" href="<?= e(url('admin/breakdown.php?id=' . $row['id'])) ?>"><?= $row['status'] === 'new' ? 'Respond' : 'Open' ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
