<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$states = invoice_states();
$filters = [
    'outstanding' => 'Money owed',
    'paid' => 'Paid',
    'void' => 'Void',
    'all' => 'All',
];
$filter = query_param('status', 'outstanding');
if (!isset($filters[$filter])) {
    $filter = 'outstanding';
}
$search = mb_substr(query_param('q'), 0, 100);

$conditions = [match ($filter) {
    'outstanding' => "x.status = 'issued' AND x.amount_paid < x.total",
    'paid' => "x.status = 'issued' AND x.amount_paid >= x.total",
    'void' => "x.status = 'void'",
    default => '1 = 1',
}];
$types = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $phone = normalize_phone($search) ?? '';
    $number = parse_document_number($search, 'INV') ?? 0;
    $conditions[] = '(x.id = ? OR x.bill_name LIKE ? OR x.bill_phone LIKE ? OR x.bill_phone = ? OR x.vehicle_plate LIKE ?)';
    $types = 'issss';
    $params = [$number, $like, $like, $phone, $like];
}

$invoices = db_all(
    'SELECT x.* FROM (
        SELECT i.id, i.job_id, i.status, i.bill_name, i.bill_phone, i.vehicle_plate, i.vehicle_label, i.total, i.issued_at,
               COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS amount_paid,
               (SELECT MAX(p.paid_on) FROM payments p WHERE p.invoice_id = i.id) AS last_paid_on
        FROM invoices i
     ) x
     WHERE ' . implode(' AND ', $conditions) . '
     ORDER BY ' . ($filter === 'outstanding' ? 'x.issued_at ASC' : 'x.issued_at DESC') . '
     LIMIT 300',
    $types,
    $params
);

$owed = db_one(
    "SELECT COUNT(*) AS invoices, COALESCE(SUM(x.total - x.amount_paid), 0) AS amount
     FROM (
        SELECT i.total, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS amount_paid
        FROM invoices i WHERE i.status = 'issued'
     ) x
     WHERE x.amount_paid < x.total"
);
$shownTotal = array_sum(array_map(fn ($row) => (int) $row['total'], $invoices));
$shownBalance = array_sum(array_map(fn ($row) => $row['status'] === 'void' ? 0 : max(0, (int) $row['total'] - (int) $row['amount_paid']), $invoices));

$pageTitle = 'Invoices';
$activeNav = 'invoices.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Invoices</h1>
    <p class="muted">Invoices are created from job cards. <?= (int) $owed['invoices'] ?> <?= (int) $owed['invoices'] === 1 ? 'invoice has' : 'invoices have' ?> money owed, <?= e(money($owed['amount'])) ?> in total.</p>
  </div>
</div>

<nav class="tabs" aria-label="Filter invoices">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= e(url('admin/invoices.php?' . http_build_query(array_filter(['status' => $key, 'q' => $search])))) ?>"<?= $filter === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" class="filter-bar">
  <input type="hidden" name="status" value="<?= e($filter) ?>">
  <div class="field field-grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Invoice number, name, phone or plate" value="<?= e($search) ?>">
  </div>
  <button type="submit" class="btn btn-dark">Search</button>
  <?php if ($search !== ''): ?>
    <a class="btn btn-outline" href="<?= e(url('admin/invoices.php?status=' . $filter)) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-wrap">
  <?php if (!$invoices): ?>
    <p class="empty-state"><?= $filter === 'outstanding' && $search === '' ? 'Nobody owes the garage money right now.' : 'No invoices match.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Invoice</th>
          <th>Bill to</th>
          <th>Vehicle</th>
          <th class="num">Total</th>
          <th class="num">Paid</th>
          <th class="num">Balance</th>
          <th>Status</th>
          <th><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $row): ?>
          <?php $state = payment_state($row); ?>
          <tr class="<?= $state === 'void' ? 'is-muted' : '' ?>">
            <td class="nowrap">
              <a class="row-link" href="<?= e(url('admin/invoice.php?id=' . $row['id'])) ?>"><?= e(invoice_number($row['id'])) ?></a>
              <span class="muted"><?= e(format_date($row['issued_at'], 'j M Y')) ?> &middot; <?= e(job_number($row['job_id'])) ?></span>
            </td>
            <td><?= e($row['bill_name']) ?><a class="muted nowrap" href="<?= e(tel_link($row['bill_phone'])) ?>"><?= e(format_phone($row['bill_phone'])) ?></a></td>
            <td><span class="plate"><?= e($row['vehicle_plate']) ?></span><span class="muted"><?= e($row['vehicle_label']) ?></span></td>
            <td class="num"><?= e(number_format((int) $row['total'])) ?></td>
            <td class="num"><?= e(number_format((int) $row['amount_paid'])) ?><?php if ($row['last_paid_on']): ?><span class="muted"><?= e(format_date($row['last_paid_on'], 'j M')) ?></span><?php endif; ?></td>
            <td class="num"><strong><?= e(number_format($state === 'void' ? 0 : max(0, (int) $row['total'] - (int) $row['amount_paid']))) ?></strong></td>
            <td><?= status_badge($state, $states, true) ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/invoice.php?id=' . $row['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3"><?= count($invoices) ?> shown (<?= e(config('currency.code')) ?>)</td>
          <td class="num"><?= e(number_format($shownTotal)) ?></td>
          <td></td>
          <td class="num"><?= e(number_format($shownBalance)) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
