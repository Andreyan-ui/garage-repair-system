<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$search = mb_substr(query_param('q'), 0, 100);
$types = '';
$params = [];
$where = '';
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $phone = normalize_phone($search) ?? '';
    $where = 'WHERE c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.phone = ?
              OR EXISTS (SELECT 1 FROM vehicles v WHERE v.customer_id = c.id AND v.plate_number LIKE ?)';
    $types = 'sssss';
    $params = [$like, $like, $like, $phone, $like];
}

$customers = db_all(
    "SELECT c.id, c.full_name, c.phone, c.email, c.created_at,
            (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicle_count,
            (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS booking_count,
            (SELECT COUNT(*) FROM breakdown_requests r WHERE r.customer_id = c.id) AS breakdown_count
     FROM customers c
     $where
     ORDER BY c.created_at DESC
     LIMIT 300",
    $types,
    $params
);

$pageTitle = 'Customers';
$activeNav = 'customers.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Customers</h1>
    <p class="muted">People who have created an online account. Guest bookings are listed under Bookings.</p>
  </div>
</div>

<form method="get" class="filter-bar">
  <div class="field field-grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Name, phone, email or plate" value="<?= e($search) ?>">
  </div>
  <button type="submit" class="btn btn-dark">Search</button>
  <?php if ($search !== ''): ?>
    <a class="btn btn-outline" href="<?= e(url('admin/customers.php')) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-wrap">
  <?php if (!$customers): ?>
    <p class="empty-state"><?= $search !== '' ? 'No customers match your search.' : 'No customers have registered yet.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>Name</th><th>Phone</th><th>Email</th><th>Vehicles</th><th>Bookings</th><th>Breakdowns</th><th>Joined</th><th><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $row): ?>
          <tr>
            <td><a class="row-link" href="<?= e(url('admin/customer.php?id=' . $row['id'])) ?>"><?= e($row['full_name']) ?></a></td>
            <td class="nowrap"><a href="<?= e(tel_link($row['phone'])) ?>"><?= e(format_phone($row['phone'])) ?></a></td>
            <td><?= $row['email'] ? e($row['email']) : '<span class="muted">-</span>' ?></td>
            <td><?= (int) $row['vehicle_count'] ?></td>
            <td><?= (int) $row['booking_count'] ?></td>
            <td><?= (int) $row['breakdown_count'] ?></td>
            <td class="nowrap"><?= e(format_date($row['created_at'], 'j M Y')) ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/customer.php?id=' . $row['id'])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
