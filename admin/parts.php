<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$filters = [
    'active' => 'In use',
    'low' => 'Low or out of stock',
    'inactive' => 'Not in use',
    'all' => 'All',
];
$filter = query_param('status', 'active');
if (!isset($filters[$filter])) {
    $filter = 'active';
}
$search = mb_substr(query_param('q'), 0, 100);

$conditions = [match ($filter) {
    'active' => 'p.is_active = 1',
    'low' => 'p.is_active = 1 AND p.stock_quantity <= p.reorder_level',
    'inactive' => 'p.is_active = 0',
    default => '1 = 1',
}];
$types = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $conditions[] = '(p.name LIKE ? OR p.part_number LIKE ? OR p.supplier LIKE ? OR p.location LIKE ?)';
    $types = 'ssss';
    $params = [$like, $like, $like, $like];
}

$parts = db_all(
    "SELECT p.*,
            (SELECT MAX(m.created_at) FROM stock_movements m WHERE m.part_id = p.id AND m.reason = 'purchase') AS last_received,
            (SELECT COALESCE(-SUM(m.quantity_change), 0) FROM stock_movements m
              WHERE m.part_id = p.id AND m.reason IN ('job_use', 'job_return') AND m.created_at >= NOW() - INTERVAL 30 DAY) AS used_30_days
     FROM parts p
     WHERE " . implode(' AND ', $conditions) . '
     ORDER BY ' . ($filter === 'low' ? 'p.stock_quantity - p.reorder_level, p.name' : 'p.name') . '
     LIMIT 500',
    $types,
    $params
);

$summary = db_one(
    'SELECT COUNT(*) AS parts,
            COALESCE(SUM(GREATEST(stock_quantity, 0) * cost_price), 0) AS value_at_cost,
            COALESCE(SUM(stock_quantity <= reorder_level), 0) AS low_count
     FROM parts WHERE is_active = 1'
);

$pageTitle = 'Parts and stock';
$activeNav = 'parts.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Parts and stock</h1>
    <p class="muted"><?= (int) $summary['parts'] ?> parts in use, worth <?= e(money($summary['value_at_cost'])) ?> at cost. Parts added to job cards are taken from stock automatically.</p>
  </div>
  <a class="btn btn-primary" href="<?= e(url('admin/part.php')) ?>">Add a part</a>
</div>

<?php if ((int) $summary['low_count'] > 0 && $filter !== 'low'): ?>
  <div class="alert alert-error">
    <?= (int) $summary['low_count'] ?> <?= (int) $summary['low_count'] === 1 ? 'part is' : 'parts are' ?> at or below the reorder level.
    <a href="<?= e(url('admin/parts.php?status=low')) ?>">See what to reorder</a>
  </div>
<?php endif; ?>

<nav class="tabs" aria-label="Filter parts">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= e(url('admin/parts.php?' . http_build_query(array_filter(['status' => $key, 'q' => $search])))) ?>"<?= $filter === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar">
  <input type="hidden" name="status" value="<?= e($filter) ?>">
  <div class="field field-grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Name, part number, supplier or shelf" value="<?= e($search) ?>">
  </div>
  <button type="submit" class="btn btn-dark">Search</button>
  <?php if ($search !== ''): ?>
    <a class="btn btn-outline" href="<?= e(url('admin/parts.php?status=' . $filter)) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-wrap">
  <?php if (!$parts): ?>
    <p class="empty-state"><?= $filter === 'low' ? 'Nothing needs reordering.' : 'No parts match.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Part</th>
          <th>Shelf</th>
          <th class="num">In stock</th>
          <th class="num">Reorder at</th>
          <th class="num">Used (30 days)</th>
          <th class="num">Cost</th>
          <th class="num">Sell</th>
          <th>Status</th>
          <th><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parts as $part): ?>
          <tr class="<?= $part['is_active'] ? '' : 'is-muted' ?>">
            <td>
              <a class="row-link" href="<?= e(url('admin/part.php?id=' . $part['id'])) ?>"><?= e($part['name']) ?></a>
              <span class="muted"><?= e(implode(' · ', array_filter([$part['part_number'], $part['supplier']]))) ?></span>
            </td>
            <td><?= e($part['location'] ?? '') ?></td>
            <td class="num"><strong><?= e(format_quantity($part['stock_quantity'])) ?></strong> <span class="muted"><?= e(unit_label($part['stock_quantity'], $part['unit'])) ?></span></td>
            <td class="num"><?= e(format_quantity($part['reorder_level'])) ?></td>
            <td class="num"><?= e(format_quantity($part['used_30_days'])) ?></td>
            <td class="num"><?= e(number_format((int) $part['cost_price'])) ?></td>
            <td class="num"><?= e(number_format((int) $part['sell_price'])) ?></td>
            <td><?= $part['is_active'] ? stock_badge($part) : '<span class="badge badge-gray">Not in use</span>' ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/part.php?id=' . $part['id'])) ?>#receive">Receive</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
