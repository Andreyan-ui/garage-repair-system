<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$services = db_all(
    'SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.service_id = s.id) AS booking_count
     FROM services s
     ORDER BY s.sort_order, s.name'
);

$pageTitle = 'Services';
$activeNav = 'services.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Services</h1>
    <p class="muted">What customers can book online. Inactive services are hidden from the website.</p>
  </div>
  <a class="btn btn-primary" href="<?= e(url('admin/service.php')) ?>">Add a service</a>
</div>

<div class="card table-wrap">
  <?php if (!$services): ?>
    <p class="empty-state">No services yet. Add one so customers can book.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Order</th><th>Service</th><th>Bookings</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($services as $service): ?>
          <tr>
            <td><?= (int) $service['sort_order'] ?></td>
            <td>
              <div class="with-thumb">
                <img class="thumb" src="<?= e(asset('images/' . safe_image($service['image']))) ?>" alt="" loading="lazy" width="72" height="48">
                <div><a class="row-link" href="<?= e(url('admin/service.php?id=' . $service['id'])) ?>"><?= e($service['name']) ?></a><span class="muted"><?= e($service['description']) ?></span></div>
              </div>
            </td>
            <td><?= (int) $service['booking_count'] ?></td>
            <td><?= $service['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Hidden</span>' ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/service.php?id=' . $service['id'])) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
