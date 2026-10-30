<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$month = query_param('month', date('Y-m'));
[$start, $end] = month_bounds($month);
$from = $start->format('Y-m-d H:i:s');
$to = $end->format('Y-m-d H:i:s');
$fromDate = $start->format('Y-m-d');
$toDate = $end->format('Y-m-d');
$methods = payment_methods();
$problems = problem_types();
$itemTypes = ['labour' => 'Labour', 'part' => 'Parts from stock', 'other' => 'Other items'];

$range = fn (int $times, bool $dates = false) => array_merge(...array_fill(0, $times, $dates ? [$fromDate, $toDate] : [$from, $to]));

$money = db_one(
    "SELECT
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_on >= ? AND paid_on < ?) AS collected,
        (SELECT COUNT(*) FROM payments WHERE paid_on >= ? AND paid_on < ?) AS payment_count,
        (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE status = 'issued' AND issued_at >= ? AND issued_at < ?) AS invoiced,
        (SELECT COUNT(*) FROM invoices WHERE status = 'issued' AND issued_at >= ? AND issued_at < ?) AS invoice_count,
        (SELECT COALESCE(SUM(discount), 0) FROM invoices WHERE status = 'issued' AND issued_at >= ? AND issued_at < ?) AS discounts",
    'ssssssssss',
    array_merge($range(2, true), $range(3))
);

$outstanding = db_one(
    "SELECT COUNT(*) AS invoices, COALESCE(SUM(x.total - x.paid), 0) AS amount
     FROM (SELECT i.total, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid
           FROM invoices i WHERE i.status = 'issued') x
     WHERE x.paid < x.total"
);

$work = db_one(
    'SELECT
        (SELECT COUNT(*) FROM jobs WHERE created_at >= ? AND created_at < ?) AS jobs_in,
        (SELECT COUNT(*) FROM jobs WHERE ready_at >= ? AND ready_at < ?) AS jobs_done,
        (SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, ready_at)) FROM jobs WHERE ready_at >= ? AND ready_at < ?) AS turnaround,
        (SELECT COUNT(*) FROM bookings WHERE created_at >= ? AND created_at < ?) AS bookings_made,
        (SELECT COUNT(*) FROM breakdown_requests WHERE created_at >= ? AND created_at < ?) AS breakdowns,
        (SELECT COUNT(*) FROM customers WHERE created_at >= ? AND created_at < ?) AS new_customers',
    'ssssssssssss',
    $range(6)
);

$bySource = db_all(
    "SELECT ii.item_type, COALESCE(SUM(ii.line_total), 0) AS amount
     FROM invoice_items ii
     JOIN invoices i ON i.id = ii.invoice_id
     WHERE i.status = 'issued' AND i.issued_at >= ? AND i.issued_at < ?
     GROUP BY ii.item_type",
    'ss',
    $range(1)
);
$sourceTotals = array_column($bySource, 'amount', 'item_type');

$partsMargin = db_one(
    "SELECT COALESCE(SUM(ROUND(ji.quantity * ji.unit_price)), 0) AS sold, COALESCE(SUM(ROUND(ji.quantity * p.cost_price)), 0) AS cost
     FROM job_items ji
     JOIN parts p ON p.id = ji.part_id
     JOIN invoices i ON i.job_id = ji.job_id AND i.status = 'issued'
     WHERE ji.item_type = 'part' AND i.issued_at >= ? AND i.issued_at < ?",
    'ss',
    $range(1)
);

$byMethod = db_all(
    'SELECT method, COUNT(*) AS payments, SUM(amount) AS amount FROM payments
     WHERE paid_on >= ? AND paid_on < ? GROUP BY method ORDER BY amount DESC',
    'ss',
    $range(1, true)
);

$mechanicRows = db_all(
    "SELECT m.id, m.full_name,
            (SELECT COUNT(*) FROM jobs j WHERE j.mechanic_id = m.id AND j.ready_at >= ? AND j.ready_at < ?) AS jobs_done,
            (SELECT COUNT(*) FROM job_items ji WHERE ji.mechanic_id = m.id AND ji.item_type = 'labour' AND ji.created_at >= ? AND ji.created_at < ?) AS labour_lines,
            (SELECT COALESCE(SUM(ROUND(ji.quantity * ji.unit_price)), 0) FROM job_items ji WHERE ji.mechanic_id = m.id AND ji.item_type = 'labour' AND ji.created_at >= ? AND ji.created_at < ?) AS labour_value,
            (SELECT COUNT(*) FROM breakdown_requests b WHERE b.mechanic_id = m.id AND b.created_at >= ? AND b.created_at < ?) AS callouts
     FROM mechanics m
     ORDER BY labour_value DESC, jobs_done DESC, m.full_name",
    'ssssssss',
    $range(4)
);
$mechanicRows = array_values(array_filter($mechanicRows, fn ($row) => $row['jobs_done'] + $row['labour_lines'] + $row['callouts'] > 0));

$topServices = db_all(
    "SELECT s.name, COUNT(*) AS bookings, SUM(b.status = 'cancelled') AS cancelled
     FROM bookings b JOIN services s ON s.id = b.service_id
     WHERE b.created_at >= ? AND b.created_at < ?
     GROUP BY s.id, s.name
     ORDER BY bookings DESC, s.name
     LIMIT 8",
    'ss',
    $range(1)
);

$partsUsed = db_all(
    "SELECT p.id, p.name, p.unit, -SUM(m.quantity_change) AS used, p.stock_quantity, p.reorder_level
     FROM stock_movements m JOIN parts p ON p.id = m.part_id
     WHERE m.reason IN ('job_use', 'job_return') AND m.created_at >= ? AND m.created_at < ?
     GROUP BY p.id, p.name, p.unit, p.stock_quantity, p.reorder_level
     HAVING used > 0
     ORDER BY used DESC
     LIMIT 10",
    'ss',
    $range(1)
);

$breakdownProblems = db_all(
    'SELECT problem_type, COUNT(*) AS requests, SUM(towing = \'yes\') AS towed
     FROM breakdown_requests WHERE created_at >= ? AND created_at < ?
     GROUP BY problem_type ORDER BY requests DESC',
    'ss',
    $range(1)
);

$turnaround = $work['turnaround'] !== null ? (int) round((float) $work['turnaround']) : null;
$turnaroundLabel = $turnaround === null ? '-' : ($turnaround >= 1440
    ? round($turnaround / 1440, 1) . ' days'
    : floor($turnaround / 60) . ' h ' . ($turnaround % 60) . ' min');
$margin = (int) $partsMargin['sold'] - (int) $partsMargin['cost'];
$prevMonth = $start->modify('-1 month')->format('Y-m');
$nextMonth = $end->format('Y-m');
$isCurrentMonth = $start->format('Y-m') === date('Y-m');

$pageTitle = 'Reports';
$activeNav = 'reports.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <p class="muted"><?= e(config('garage.full_name')) ?> &middot; <?= e($start->format('F Y')) ?><?= $isCurrentMonth ? ' so far' : '' ?></p>
  </div>
  <div class="month-nav">
    <a class="btn btn-outline btn-sm" href="<?= e(url('admin/reports.php?month=' . $prevMonth)) ?>">&larr; <?= e($start->modify('-1 month')->format('M')) ?></a>
    <strong><?= e($start->format('F Y')) ?></strong>
    <?php if (!$isCurrentMonth && $end <= new DateTimeImmutable('first day of this month midnight')): ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url('admin/reports.php?month=' . $nextMonth)) ?>"><?= e($end->format('M')) ?> &rarr;</a>
    <?php endif; ?>
    <button type="button" class="btn btn-dark btn-sm no-print" data-print>Print</button>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat-money stat-good"><span class="stat-value"><?= e(money($money['collected'])) ?></span><span class="stat-label">Money collected (<?= (int) $money['payment_count'] ?> payments)</span></div>
  <div class="stat stat-money"><span class="stat-value"><?= e(money($money['invoiced'])) ?></span><span class="stat-label">Invoiced (<?= (int) $money['invoice_count'] ?> invoices)</span></div>
  <a class="stat stat-money<?= (int) $outstanding['amount'] > 0 ? ' stat-warn' : '' ?>" href="<?= e(url('admin/invoices.php')) ?>"><span class="stat-value"><?= e(money($outstanding['amount'])) ?></span><span class="stat-label">Owed to the garage now (<?= (int) $outstanding['invoices'] ?> invoices)</span></a>
  <div class="stat"><span class="stat-value"><?= (int) $work['jobs_in'] ?> / <?= (int) $work['jobs_done'] ?></span><span class="stat-label">Cars checked in / finished</span></div>
  <div class="stat"><span class="stat-value"><?= e($turnaroundLabel) ?></span><span class="stat-label">Average check-in to ready</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $work['bookings_made'] ?></span><span class="stat-label">Online bookings made</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $work['breakdowns'] ?></span><span class="stat-label">Breakdown requests</span></div>
  <div class="stat"><span class="stat-value"><?= (int) $work['new_customers'] ?></span><span class="stat-label">New customer accounts</span></div>
</div>

<div class="report-grid">
  <section class="card panel">
    <div class="panel-head"><h2>Where the invoiced money came from</h2></div>
    <div class="table-wrap">
      <table class="table">
        <tbody>
          <?php foreach ($itemTypes as $key => $label): ?>
            <tr><td><?= e($label) ?></td><td class="num"><?= e(money($sourceTotals[$key] ?? 0)) ?></td></tr>
          <?php endforeach; ?>
          <?php if ((int) $money['discounts'] > 0): ?>
            <tr><td>Discounts given</td><td class="num">&minus; <?= e(money($money['discounts'])) ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr><td>Parts margin (sold <?= e(money($partsMargin['sold'])) ?>, cost <?= e(money($partsMargin['cost'])) ?>)</td><td class="num<?= $margin < 0 ? ' text-danger' : '' ?>"><?= e(money($margin)) ?></td></tr>
        </tfoot>
      </table>
    </div>
    <p class="hint panel-note">Parts cost uses today's cost prices.</p>
  </section>

  <section class="card panel">
    <div class="panel-head"><h2>Money collected by method</h2></div>
    <?php if (!$byMethod): ?>
      <p class="empty-state">No payments this month.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($byMethod as $row): ?>
              <tr><td><?= e($methods[$row['method']] ?? $row['method']) ?></td><td class="num"><?= (int) $row['payments'] ?></td><td class="num"><?= e(money($row['amount'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head"><h2>Mechanics</h2><a href="<?= e(url('admin/mechanics.php')) ?>">All mechanics</a></div>
    <?php if (!$mechanicRows): ?>
      <p class="empty-state">No mechanic activity recorded this month.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Name</th><th class="num">Jobs done</th><th class="num">Labour</th><th class="num">Call-outs</th></tr></thead>
          <tbody>
            <?php foreach ($mechanicRows as $row): ?>
              <tr>
                <td class="nowrap"><a href="<?= e(url('admin/mechanic.php?id=' . $row['id'] . '&month=' . $start->format('Y-m'))) ?>"><?= e($row['full_name']) ?></a></td>
                <td class="num"><?= (int) $row['jobs_done'] ?></td>
                <td class="num"><?= e(money($row['labour_value'])) ?><span class="muted"><?= (int) $row['labour_lines'] ?> <?= (int) $row['labour_lines'] === 1 ? 'line' : 'lines' ?></span></td>
                <td class="num"><?= (int) $row['callouts'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head"><h2>Most booked services</h2></div>
    <?php if (!$topServices): ?>
      <p class="empty-state">No online bookings this month.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Service</th><th class="num">Bookings</th><th class="num">Cancelled</th></tr></thead>
          <tbody>
            <?php foreach ($topServices as $row): ?>
              <tr><td><?= e($row['name']) ?></td><td class="num"><?= (int) $row['bookings'] ?></td><td class="num"><?= (int) $row['cancelled'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head"><h2>Parts used most</h2><a href="<?= e(url('admin/parts.php?status=low')) ?>">Reorder list</a></div>
    <?php if (!$partsUsed): ?>
      <p class="empty-state">No parts used from stock this month.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Part</th><th class="num">Used</th><th class="num">Left</th></tr></thead>
          <tbody>
            <?php foreach ($partsUsed as $row): ?>
              <tr>
                <td><a href="<?= e(url('admin/part.php?id=' . $row['id'])) ?>"><?= e($row['name']) ?></a></td>
                <td class="num"><?= e(quantity_label($row['used'], $row['unit'])) ?></td>
                <td class="num<?= (float) $row['stock_quantity'] <= (float) $row['reorder_level'] ? ' text-danger' : '' ?>"><?= e(format_quantity($row['stock_quantity'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head"><h2>Why people broke down</h2></div>
    <?php if (!$breakdownProblems): ?>
      <p class="empty-state">No breakdown requests this month.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Problem</th><th class="num">Requests</th><th class="num">Needed towing</th></tr></thead>
          <tbody>
            <?php foreach ($breakdownProblems as $row): ?>
              <tr><td><?= e($problems[$row['problem_type']] ?? $row['problem_type']) ?></td><td class="num"><?= (int) $row['requests'] ?></td><td class="num"><?= (int) $row['towed'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
