<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_admin();

$jobStatuses = job_statuses();
$breakdownStatuses = breakdown_statuses();
$bookingStatuses = booking_statuses();
$problems = problem_types();
$methods = payment_methods();
$slots = booking_slots();
$garage = config('garage');

$stats = db_one(
    "SELECT
        (SELECT COUNT(*) FROM breakdown_requests WHERE status = 'new') AS new_breakdowns,
        (SELECT COUNT(*) FROM bookings WHERE status = 'pending') AS pending_bookings,
        (SELECT COUNT(*) FROM jobs WHERE status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval')) AS in_workshop,
        (SELECT COUNT(*) FROM jobs WHERE status = 'ready') AS ready_jobs,
        (SELECT COUNT(*) FROM jobs WHERE status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval')
            AND promised_at IS NOT NULL AND promised_at < NOW()) AS late_jobs,
        (SELECT COUNT(*) FROM parts WHERE is_active = 1 AND stock_quantity <= reorder_level) AS low_stock"
);
$owed = db_one(
    "SELECT COUNT(*) AS invoices, COALESCE(SUM(x.total - x.paid), 0) AS amount
     FROM (SELECT i.total, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid
           FROM invoices i WHERE i.status = 'issued') x
     WHERE x.paid < x.total"
);

$takingRows = db_all('SELECT paid_on, SUM(amount) AS amount FROM payments WHERE paid_on >= CURDATE() - INTERVAL 6 DAY GROUP BY paid_on');
$takingMap = array_column($takingRows, 'amount', 'paid_on');
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $days[] = ['date' => $date, 'amount' => (int) ($takingMap[$date] ?? 0)];
}
$todayTakings = $days[6]['amount'];
$yesterdayTakings = $days[5]['amount'];
$weekTakings = array_sum(array_column($days, 'amount'));
$delta = $todayTakings - $yesterdayTakings;

$chartMax = max(1, max(array_column($days, 'amount')));
$slotWidth = 40;
$barWidth = 22;
$baseline = 70;
$barSpace = 62;

$boardJobs = db_all(
    "SELECT j.id, j.status, j.contact_name, j.vehicle_plate, j.vehicle_make, j.vehicle_model, j.promised_at, j.created_at,
            j.mechanic_id, m.full_name AS mechanic_name,
            (SELECT COALESCE(SUM(ROUND(ji.quantity * ji.unit_price)), 0) FROM job_items ji WHERE ji.job_id = j.id) AS work_total,
            inv.id AS invoice_id, inv.total AS invoice_total,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = inv.id), 0) AS amount_paid
     FROM jobs j
     LEFT JOIN mechanics m ON m.id = j.mechanic_id
     LEFT JOIN invoices inv ON inv.job_id = j.id AND inv.status = 'issued'
     WHERE j.status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval', 'ready')
        OR (j.status = 'collected' AND j.collected_at >= CURDATE())
     ORDER BY j.promised_at IS NULL, j.promised_at, j.created_at"
);
$columns = [
    'checked_in' => ['title' => 'Checked in', 'tone' => 'blue'],
    'in_progress' => ['title' => 'Being worked on', 'tone' => 'purple'],
    'awaiting_parts' => ['title' => 'Needs parts', 'tone' => 'amber'],
    'awaiting_approval' => ['title' => 'Needs approval', 'tone' => 'amber'],
    'ready' => ['title' => 'Ready to collect', 'tone' => 'green'],
    'collected' => ['title' => 'Collected today', 'tone' => 'gray'],
];
$byColumn = array_fill_keys(array_keys($columns), []);
foreach ($boardJobs as $job) {
    $byColumn[$job['status']][] = $job;
}

$breakdowns = db_all(
    "SELECT id, reference, contact_name, contact_phone, problem_type, location_text, status, responder_name, eta_at, created_at
     FROM breakdown_requests
     WHERE status IN ('new', 'dispatched', 'on_site')
     ORDER BY FIELD(status, 'new', 'dispatched', 'on_site'), created_at"
);
$sosBreakdowns = array_values(array_filter($breakdowns, fn ($row) => $row['status'] === 'new'));

$agendaRows = db_all(
    "SELECT b.id, b.reference, b.contact_name, b.contact_phone, b.vehicle_plate, b.vehicle_make, b.vehicle_model,
            b.preferred_date, b.preferred_slot, b.status, s.name AS service_name, j.id AS job_id
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     LEFT JOIN jobs j ON j.booking_id = b.id
     WHERE b.preferred_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY
       AND b.status IN ('pending', 'confirmed', 'in_progress')
     ORDER BY b.preferred_date, FIELD(b.preferred_slot, 'morning', 'afternoon'), b.id"
);
$agenda = [date('Y-m-d') => [], date('Y-m-d', strtotime('+1 day')) => []];
foreach ($agendaRows as $row) {
    $agenda[$row['preferred_date']][$row['preferred_slot']][] = $row;
}
$laterThisWeek = (int) db_value(
    "SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'confirmed')
     AND preferred_date > CURDATE() + INTERVAL 1 DAY AND preferred_date <= CURDATE() + INTERVAL 7 DAY"
);

$team = db_all(
    "SELECT m.id, m.full_name, m.specialty, m.phone,
            (SELECT COUNT(*) FROM jobs j WHERE j.mechanic_id = m.id AND j.status IN ('checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval')) AS open_jobs,
            (SELECT COUNT(*) FROM jobs j WHERE j.mechanic_id = m.id AND j.ready_at >= CURDATE()) AS finished_today,
            (SELECT b.id FROM breakdown_requests b WHERE b.mechanic_id = m.id AND b.status IN ('dispatched', 'on_site') ORDER BY b.created_at DESC LIMIT 1) AS breakdown_id
     FROM mechanics m
     WHERE m.is_active = 1
     ORDER BY m.full_name"
);

$debtors = db_all(
    "SELECT x.* FROM (
        SELECT i.id, i.bill_name, i.bill_phone, i.vehicle_plate, i.total, i.issued_at,
               COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS amount_paid
        FROM invoices i WHERE i.status = 'issued'
     ) x
     WHERE x.amount_paid < x.total
     ORDER BY x.issued_at
     LIMIT 5"
);

$lowStock = db_all(
    'SELECT id, name, unit, stock_quantity, reorder_level FROM parts
     WHERE is_active = 1 AND stock_quantity <= reorder_level
     ORDER BY stock_quantity / GREATEST(reorder_level, 1), name
     LIMIT 6'
);

$activity = db_all(
    "(SELECT 'payment' AS kind, p.invoice_id AS ref_id, p.amount AS amount, i.bill_name AS who, p.method AS extra, p.created_at AS happened_at
      FROM payments p JOIN invoices i ON i.id = p.invoice_id)
     UNION ALL
     (SELECT 'job', j.id, NULL, j.contact_name, CONCAT(j.vehicle_plate, ' ', j.vehicle_make, ' ', j.vehicle_model), j.created_at FROM jobs j)
     UNION ALL
     (SELECT 'invoice', i.id, i.total, i.bill_name, i.status, i.issued_at FROM invoices i)
     UNION ALL
     (SELECT 'breakdown', b.id, NULL, b.contact_name, b.problem_type, b.created_at FROM breakdown_requests b)
     UNION ALL
     (SELECT 'booking', b.id, NULL, b.contact_name, s.name, b.created_at FROM bookings b JOIN services s ON s.id = b.service_id)
     ORDER BY happened_at DESC
     LIMIT 8"
);

$attention = [];
if ((int) $stats['new_breakdowns'] > 0) {
    $attention[] = ['alert', (int) $stats['new_breakdowns'] . ' breakdown ' . ((int) $stats['new_breakdowns'] === 1 ? 'request needs' : 'requests need') . ' a response', 'breakdowns.php', 'red'];
}
if ((int) $stats['late_jobs'] > 0) {
    $attention[] = ['clock', (int) $stats['late_jobs'] . ' ' . ((int) $stats['late_jobs'] === 1 ? 'job is' : 'jobs are') . ' past the promised time', 'jobs.php', 'red'];
}
if ((int) $stats['ready_jobs'] > 0) {
    $attention[] = ['check', (int) $stats['ready_jobs'] . ' ' . ((int) $stats['ready_jobs'] === 1 ? 'car is' : 'cars are') . ' ready for collection', 'jobs.php?status=ready', 'green'];
}
if ((int) $stats['pending_bookings'] > 0) {
    $attention[] = ['calendar', (int) $stats['pending_bookings'] . ' ' . ((int) $stats['pending_bookings'] === 1 ? 'booking needs' : 'bookings need') . ' confirming', 'bookings.php?status=pending', 'amber'];
}

$firstName = explode(' ', trim(($admin['full_name'] ?? '') ?: $admin['username']))[0];
$avatarTones = ['gold', 'blue', 'purple', 'slate'];
$toneFor = fn ($id) => $avatarTones[((int) $id - 1) % count($avatarTones)];
$sinceAttr = fn (?string $dateTime) => $dateTime ? date('c', strtotime($dateTime)) : '';

$pageTitle = 'Dashboard';
$activeNav = 'index.php';
$autoRefresh = 60;
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<section class="dash-hero">
  <div class="dash-hero-copy">
    <p class="dash-date"><?= icon('sun') ?> <?= e(date('l j F Y')) ?> &middot; <span data-clock><?= e(date('g:i a')) ?></span></p>
    <h1><?= e(greeting()) ?>, <?= e($firstName) ?></h1>
    <?php if ($attention): ?>
      <ul class="attention-list">
        <?php foreach ($attention as [$attentionIcon, $text, $link, $tone]): ?>
          <li><a class="attention attention-<?= e($tone) ?>" href="<?= e(url('admin/' . $link)) ?>"><?= icon($attentionIcon) ?> <?= e($text) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="dash-calm"><?= icon('check') ?> Everything is under control. Nothing needs your attention right now.</p>
    <?php endif; ?>
  </div>
  <div class="dash-actions">
    <a class="dash-action dash-action-primary" href="<?= e(url('admin/job_new.php')) ?>"><?= icon('plus') ?><span>Check in a car</span></a>
    <a class="dash-action" href="<?= e(url('admin/invoices.php')) ?>"><?= icon('cash') ?><span>Take a payment</span></a>
    <a class="dash-action" href="<?= e(url('admin/bookings.php?status=pending')) ?>"><?= icon('calendar') ?><span>Confirm bookings</span></a>
    <a class="dash-action" href="<?= e(url('admin/parts.php?status=low')) ?>"><?= icon('box') ?><span>Receive stock</span></a>
    <button type="button" class="dash-action dash-sound" data-sound-toggle aria-pressed="false"><?= icon('bell') ?><span data-sound-label>Sound alerts off</span></button>
  </div>
</section>

<?php if ($sosBreakdowns): ?>
  <section class="sos-stack" aria-label="New breakdown requests">
    <?php foreach ($sosBreakdowns as $row): ?>
      <article class="sos-card">
        <span class="sos-beacon" aria-hidden="true"><?= icon('alert') ?></span>
        <div class="sos-body">
          <p class="sos-title"><?= e($problems[$row['problem_type']] ?? $row['problem_type']) ?> &middot; <?= e($row['contact_name']) ?></p>
          <p class="sos-where"><?= icon('pin') ?> <?= e($row['location_text']) ?></p>
        </div>
        <p class="sos-timer"><span class="visually-hidden">Waiting </span><span data-since="<?= e($sinceAttr($row['created_at'])) ?>"><?= e(time_ago($row['created_at'])) ?></span></p>
        <div class="sos-actions">
          <a class="btn btn-light btn-sm" href="<?= e(tel_link($row['contact_phone'])) ?>"><?= icon('phone') ?> Call</a>
          <a class="btn btn-outline-light btn-sm" href="<?= e(url('admin/breakdown.php?id=' . $row['id'])) ?>">Respond</a>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section class="kpi-grid" aria-label="Key figures">
  <article class="kpi kpi-hero">
    <div class="kpi-head">
      <span class="kpi-label"><?= icon('cash') ?> Money taken today</span>
      <?php if ($delta !== 0): ?>
        <span class="kpi-delta <?= $delta > 0 ? 'is-up' : 'is-down' ?>"><?= $delta > 0 ? '&#9650; ' : '&#9660; ' ?><?= e(compact_money(abs($delta))) ?> <?= $delta > 0 ? 'more' : 'less' ?> than yesterday</span>
      <?php else: ?>
        <span class="kpi-delta">Same as yesterday</span>
      <?php endif; ?>
    </div>
    <p class="kpi-value"><?= e(money($todayTakings)) ?></p>
    <p class="kpi-sub"><?= e(money($weekTakings)) ?> in the last 7 days</p>
    <div class="spark" data-spark>
      <svg class="spark-svg" width="<?= $slotWidth * 7 ?>" height="90" viewBox="0 0 <?= $slotWidth * 7 ?> 90" role="img" aria-label="Money taken each day for the last 7 days. Today is highlighted.">
        <line class="spark-baseline" x1="0" y1="<?= $baseline + 0.5 ?>" x2="<?= $slotWidth * 7 ?>" y2="<?= $baseline + 0.5 ?>"></line>
        <?php foreach ($days as $index => $day): ?>
          <?php
          $isToday = $index === 6;
          $height = $day['amount'] > 0 ? max(3, (int) round($day['amount'] / $chartMax * $barSpace)) : 0;
          $x = $index * $slotWidth + ($slotWidth - $barWidth) / 2;
          $y = $baseline - $height;
          $radius = min(4, $height);
          $label = ($isToday ? 'Today, ' : '') . date('D j M', strtotime($day['date']));
          ?>
          <g class="spark-col<?= $isToday ? ' is-today' : '' ?>" tabindex="0" data-tip-value="<?= e(money($day['amount'])) ?>" data-tip-label="<?= e($label) ?>" aria-label="<?= e($label . ': ' . money($day['amount'])) ?>">
            <rect class="spark-hit" x="<?= $index * $slotWidth ?>" y="0" width="<?= $slotWidth ?>" height="90"></rect>
            <?php if ($height > 0): ?>
              <path class="spark-bar" d="M<?= $x ?>,<?= $baseline ?> V<?= $y + $radius ?> Q<?= $x ?>,<?= $y ?> <?= $x + $radius ?>,<?= $y ?> H<?= $x + $barWidth - $radius ?> Q<?= $x + $barWidth ?>,<?= $y ?> <?= $x + $barWidth ?>,<?= $y + $radius ?> V<?= $baseline ?> Z"></path>
            <?php endif; ?>
            <text class="spark-day" x="<?= $index * $slotWidth + $slotWidth / 2 ?>" y="86" text-anchor="middle"><?= e($isToday ? 'Today' : date('D', strtotime($day['date']))) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>
      <div class="chart-tip" data-chart-tip hidden><strong></strong><span></span></div>
    </div>
    <details class="chart-table">
      <summary>Show as table</summary>
      <table class="table">
        <thead><tr><th>Day</th><th class="num">Money taken</th></tr></thead>
        <tbody>
          <?php foreach (array_reverse($days) as $day): ?>
            <tr><td><?= e(format_date($day['date'], 'D j M')) ?></td><td class="num"><?= e(money($day['amount'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </details>
  </article>

  <a class="kpi" href="<?= e(url('admin/jobs.php')) ?>">
    <span class="kpi-label"><?= icon('wrench') ?> Being worked on</span>
    <span class="kpi-value"><?= (int) $stats['in_workshop'] ?></span>
    <span class="kpi-sub"><?= (int) $stats['late_jobs'] > 0 ? '<span class="text-danger">' . (int) $stats['late_jobs'] . ' past the promised time</span>' : 'All on schedule' ?></span>
  </a>
  <a class="kpi<?= (int) $stats['ready_jobs'] > 0 ? ' kpi-good' : '' ?>" href="<?= e(url('admin/jobs.php?status=ready')) ?>">
    <span class="kpi-label"><?= icon('check') ?> Ready to collect</span>
    <span class="kpi-value"><?= (int) $stats['ready_jobs'] ?></span>
    <span class="kpi-sub">Let the customers know</span>
  </a>
  <a class="kpi<?= (int) $owed['amount'] > 0 ? ' kpi-warn' : '' ?>" href="<?= e(url('admin/invoices.php')) ?>">
    <span class="kpi-label"><?= icon('receipt') ?> Owed to the garage</span>
    <span class="kpi-value"><?= e(compact_money($owed['amount'])) ?></span>
    <span class="kpi-sub">On <?= (int) $owed['invoices'] ?> <?= (int) $owed['invoices'] === 1 ? 'invoice' : 'invoices' ?></span>
  </a>
  <a class="kpi<?= (int) $stats['low_stock'] > 0 ? ' kpi-warn' : '' ?>" href="<?= e(url('admin/parts.php?status=low')) ?>">
    <span class="kpi-label"><?= icon('box') ?> Parts to reorder</span>
    <span class="kpi-value"><?= (int) $stats['low_stock'] ?></span>
    <span class="kpi-sub"><?= (int) $stats['low_stock'] > 0 ? 'At or below the reorder level' : 'Stock levels are fine' ?></span>
  </a>
</section>

<section class="board-panel" aria-labelledby="board-title">
  <div class="board-head">
    <div>
      <h2 id="board-title"><?= icon('car') ?> Workshop board</h2>
      <p class="muted">Drag a car to a new column, or use its Move menu. Customers see the change on their tracking page.</p>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('admin/job_new.php')) ?>"><?= icon('plus') ?> Check in a car</a>
  </div>
  <div class="board" data-board data-endpoint="<?= e(url('admin/job_status.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <?php foreach ($columns as $columnKey => $column): ?>
      <section class="board-col board-col-<?= e($column['tone']) ?>" data-column="<?= e($columnKey) ?>" aria-label="<?= e($column['title']) ?>">
        <header class="board-col-head">
          <span class="board-dot" aria-hidden="true"></span>
          <h3><?= e($column['title']) ?></h3>
          <span class="board-count" data-count><?= count($byColumn[$columnKey]) ?></span>
        </header>
        <div class="board-list" data-dropzone="<?= e($columnKey) ?>">
          <?php foreach ($byColumn[$columnKey] as $job): ?>
            <?php
            $late = $job['promised_at'] && !in_array($job['status'], ['ready', 'collected'], true) && strtotime($job['promised_at']) < time();
            $balance = $job['invoice_id'] ? max(0, (int) $job['invoice_total'] - (int) $job['amount_paid']) : null;
            ?>
            <article class="job-card" data-job="<?= (int) $job['id'] ?>" data-status="<?= e($job['status']) ?>">
              <div class="job-card-top">
                <span class="plate"><?= e($job['vehicle_plate']) ?></span>
                <button type="button" class="job-grip" data-drag-handle aria-label="Drag <?= e($job['vehicle_plate']) ?> to another column"><?= icon('grip') ?></button>
              </div>
              <a class="job-card-title" href="<?= e(url('admin/job.php?id=' . $job['id'])) ?>"><?= e($job['vehicle_make'] . ' ' . $job['vehicle_model']) ?></a>
              <p class="job-card-customer"><?= e($job['contact_name']) ?> &middot; <?= e(job_number($job['id'])) ?></p>
              <div class="job-card-meta">
                <?php if ($job['mechanic_name']): ?>
                  <span class="avatar avatar-<?= e($toneFor($job['mechanic_id'])) ?>" title="<?= e($job['mechanic_name']) ?>"><?= e(initials($job['mechanic_name'])) ?></span>
                  <span class="job-card-mechanic"><?= e(explode(' ', $job['mechanic_name'])[0]) ?></span>
                <?php else: ?>
                  <span class="avatar avatar-empty" title="No mechanic assigned">?</span>
                  <span class="job-card-mechanic muted">Unassigned</span>
                <?php endif; ?>
                <?php if ($job['promised_at'] && $job['status'] !== 'collected'): ?>
                  <span class="job-due<?= $late ? ' is-late' : '' ?>"><?= icon('clock') ?> <?= e($late ? 'Late' : (date('Y-m-d', strtotime($job['promised_at'])) === date('Y-m-d') ? format_time($job['promised_at']) : format_date($job['promised_at'], 'j M'))) ?></span>
                <?php endif; ?>
              </div>
              <div class="job-card-foot">
                <?php if ($balance !== null): ?>
                  <span><?= e(invoice_number($job['invoice_id'])) ?></span>
                  <strong class="<?= $balance > 0 ? 'text-danger' : 'text-good' ?>"><?= $balance > 0 ? e(compact_money($balance)) . ' due' : 'Paid' ?></strong>
                <?php else: ?>
                  <span>Work so far</span>
                  <strong><?= e(compact_money($job['work_total'])) ?></strong>
                <?php endif; ?>
              </div>
              <details class="job-move">
                <summary>Move</summary>
                <form method="post" action="<?= e(url('admin/job_status.php')) ?>" class="job-move-menu">
                  <?= csrf_field() ?>
                  <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                  <input type="hidden" name="return" value="index.php">
                  <?php foreach ($columns as $targetKey => $target): ?>
                    <button type="submit" name="status" value="<?= e($targetKey) ?>"<?= $targetKey === $job['status'] ? ' hidden' : '' ?>><span class="board-dot board-dot-<?= e($target['tone']) ?>" aria-hidden="true"></span><?= e($targetKey === 'collected' ? 'Collected' : $target['title']) ?></button>
                  <?php endforeach; ?>
                </form>
              </details>
            </article>
          <?php endforeach; ?>
          <p class="board-empty"<?= $byColumn[$columnKey] ? ' hidden' : '' ?>>No cars here</p>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</section>

<div class="dash-grid">
  <section class="card panel dash-span-2">
    <div class="panel-head">
      <h2><?= icon('calendar') ?> Booked in</h2>
      <a href="<?= e(url('admin/bookings.php')) ?>">All bookings</a>
    </div>
    <div class="agenda">
      <?php foreach ($agenda as $date => $bySlot): ?>
        <div class="agenda-day">
          <h3><?= e(relative_day($date)) ?> <span class="muted"><?= e(format_date($date, 'D j M')) ?></span></h3>
          <?php if (!$bySlot): ?>
            <p class="agenda-empty"><?= in_array((int) date('w', strtotime($date)), config('booking.closed_days'), true) ? 'Closed. No bookings are taken on ' . e(date('l', strtotime($date))) . 's.' : 'No bookings.' ?></p>
          <?php endif; ?>
          <?php foreach ($slots as $slotKey => $slot): ?>
            <?php if (empty($bySlot[$slotKey])) continue; ?>
            <div class="agenda-slot">
              <p class="agenda-time"><?= e($slot['label']) ?> <span><?= e($slot['hours']) ?></span></p>
              <ul class="agenda-list">
                <?php foreach ($bySlot[$slotKey] as $row): ?>
                  <li class="agenda-item<?= $row['status'] === 'pending' ? ' is-pending' : '' ?>">
                    <div>
                      <a class="row-link" href="<?= e(url('admin/booking.php?id=' . $row['id'])) ?>"><?= e($row['contact_name']) ?></a>
                      <span class="muted"><?= e($row['service_name']) ?> &middot; <?= e($row['vehicle_plate']) ?> <?= e($row['vehicle_make'] . ' ' . $row['vehicle_model']) ?></span>
                    </div>
                    <div class="agenda-actions">
                      <?php if ($row['status'] === 'pending'): ?>
                        <span class="badge badge-amber">Needs confirming</span>
                        <a class="icon-button" href="<?= e(tel_link($row['contact_phone'])) ?>" aria-label="Call <?= e($row['contact_name']) ?>"><?= icon('phone') ?></a>
                      <?php endif; ?>
                      <?php if ($row['job_id']): ?>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('admin/job.php?id=' . $row['job_id'])) ?>"><?= e(job_number($row['job_id'])) ?></a>
                      <?php else: ?>
                        <a class="btn btn-dark btn-sm" href="<?= e(url('admin/job_new.php?booking=' . $row['id'])) ?>">Check in</a>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($laterThisWeek > 0): ?>
      <p class="panel-note"><a href="<?= e(url('admin/bookings.php')) ?>"><?= $laterThisWeek ?> more <?= $laterThisWeek === 1 ? 'booking' : 'bookings' ?> later this week</a></p>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head">
      <h2><?= icon('truck') ?> On the road</h2>
      <a href="<?= e(url('admin/breakdowns.php')) ?>">Breakdowns</a>
    </div>
    <?php if (!$breakdowns): ?>
      <p class="empty-state">No one is waiting for roadside help.</p>
    <?php else: ?>
      <ul class="road-list">
        <?php foreach ($breakdowns as $row): ?>
          <?php $minutesAway = minutes_until($row['eta_at']); ?>
          <li class="road-item road-<?= e($row['status']) ?>">
            <a class="row-link" href="<?= e(url('admin/breakdown.php?id=' . $row['id'])) ?>"><?= e($problems[$row['problem_type']] ?? $row['problem_type']) ?> &middot; <?= e($row['contact_name']) ?></a>
            <span class="muted"><?= e($row['location_text']) ?></span>
            <div class="road-meta">
              <?= status_badge($row['status'], $breakdownStatuses, true) ?>
              <?php if ($row['responder_name'] && $row['status'] !== 'new'): ?>
                <span><?= e($row['responder_name']) ?><?= $row['status'] === 'dispatched' && $minutesAway !== null ? ($minutesAway > 0 ? ', about ' . $minutesAway . ' min away' : ', due now') : '' ?></span>
              <?php else: ?>
                <span>Waiting <span data-since="<?= e($sinceAttr($row['created_at'])) ?>"><?= e(time_ago($row['created_at'])) ?></span></span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head">
      <h2><?= icon('hardhat') ?> Team today</h2>
      <a href="<?= e(url('admin/mechanics.php')) ?>">Mechanics</a>
    </div>
    <?php if (!$team): ?>
      <p class="empty-state">Add your mechanics to assign work.</p>
    <?php else: ?>
      <ul class="team-list">
        <?php foreach ($team as $member): ?>
          <li>
            <span class="avatar avatar-lg avatar-<?= e($toneFor($member['id'])) ?>"><?= e(initials($member['full_name'])) ?></span>
            <div class="team-body">
              <a class="row-link" href="<?= e(url('admin/mechanic.php?id=' . $member['id'])) ?>"><?= e($member['full_name']) ?></a>
              <span class="muted"><?= (int) $member['open_jobs'] ?> open <?= (int) $member['open_jobs'] === 1 ? 'job' : 'jobs' ?> &middot; <?= (int) $member['finished_today'] ?> finished today</span>
            </div>
            <?php if ($member['breakdown_id']): ?>
              <a class="badge badge-amber" href="<?= e(url('admin/breakdown.php?id=' . $member['breakdown_id'])) ?>">On a call-out</a>
            <?php elseif ((int) $member['open_jobs'] > 0): ?>
              <span class="badge badge-purple">Busy</span>
            <?php else: ?>
              <span class="badge badge-green">Available</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head">
      <h2><?= icon('receipt') ?> Money owed</h2>
      <a href="<?= e(url('admin/invoices.php')) ?>">Invoices</a>
    </div>
    <?php if (!$debtors): ?>
      <p class="empty-state">Nobody owes the garage money.</p>
    <?php else: ?>
      <ul class="debt-list">
        <?php foreach ($debtors as $row): ?>
          <?php
          $balance = (int) $row['total'] - (int) $row['amount_paid'];
          $age = max(0, (int) floor((time() - strtotime($row['issued_at'])) / 86400));
          $reminder = 'Hello ' . $row['bill_name'] . ', this is a friendly reminder from ' . $garage['name'] . ' that ' . money($balance)
              . ' is still due on invoice ' . invoice_number($row['id']) . ' for ' . $row['vehicle_plate'] . '. You can view it here: '
              . absolute_url(invoice_link((int) $row['id'])) . ' Thank you.';
          ?>
          <li>
            <div class="debt-body">
              <a class="row-link" href="<?= e(url('admin/invoice.php?id=' . $row['id'])) ?>"><?= e($row['bill_name']) ?></a>
              <span class="muted"><?= e(invoice_number($row['id'])) ?> &middot; <?= $age === 0 ? 'issued today' : e($age . ($age === 1 ? ' day' : ' days') . ' ago') ?></span>
            </div>
            <strong class="debt-amount<?= $age > 14 ? ' text-danger' : '' ?>"><?= e(compact_money($balance)) ?></strong>
            <a class="icon-button" href="<?= e(whatsapp_link($row['bill_phone'], $reminder)) ?>" target="_blank" rel="noopener" aria-label="Send <?= e($row['bill_name']) ?> a WhatsApp reminder" title="Send a WhatsApp reminder"><?= icon('message') ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card panel">
    <div class="panel-head">
      <h2><?= icon('box') ?> Running low</h2>
      <a href="<?= e(url('admin/parts.php?status=low')) ?>">Reorder list</a>
    </div>
    <?php if (!$lowStock): ?>
      <p class="empty-state">Stock levels are fine.</p>
    <?php else: ?>
      <ul class="stock-list">
        <?php foreach ($lowStock as $part): ?>
          <?php
          $quantity = (float) $part['stock_quantity'];
          $scale = max(1.0, (float) $part['reorder_level'] * 2);
          $fill = (int) round(min(100, $quantity / $scale * 100));
          $marker = (int) round(min(100, (float) $part['reorder_level'] / $scale * 100));
          $state = $quantity <= 0 ? 'out' : 'low';
          ?>
          <li>
            <div class="stock-row">
              <a class="row-link" href="<?= e(url('admin/part.php?id=' . $part['id'])) ?>"><?= e($part['name']) ?></a>
              <span class="stock-status stock-<?= $state ?>"><?= icon('alert') ?> <?= $state === 'out' ? 'Out of stock' : e(quantity_label($quantity, $part['unit']) . ' left') ?></span>
            </div>
            <div class="meter meter-<?= $state ?>" role="img" aria-label="<?= e(format_quantity($quantity) . ' in stock, reorder at ' . format_quantity($part['reorder_level'])) ?>">
              <span class="meter-fill" style="width: <?= $fill ?>%"></span>
              <span class="meter-mark" style="left: <?= $marker ?>%"></span>
            </div>
            <span class="muted small">Reorder at <?= e(format_quantity($part['reorder_level'])) ?> &middot; <a href="<?= e(url('admin/part.php?id=' . $part['id'])) ?>#receive">Receive stock</a></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card panel dash-span-full">
    <div class="panel-head">
      <h2><?= icon('activity') ?> Latest activity</h2>
      <a href="<?= e(url('admin/reports.php')) ?>">Reports</a>
    </div>
    <?php if (!$activity): ?>
      <p class="empty-state">Nothing has happened yet.</p>
    <?php else: ?>
      <ol class="feed feed-wide">
        <?php foreach ($activity as $event): ?>
          <?php
          [$eventIcon, $tone, $text, $link] = match ($event['kind']) {
              'payment' => ['cash', 'green', money($event['amount']) . ' received from ' . $event['who'] . ' by ' . strtolower($methods[$event['extra']] ?? $event['extra']), 'admin/invoice.php?id=' . $event['ref_id']],
              'job' => ['wrench', 'purple', $event['who'] . ' checked in ' . $event['extra'], 'admin/job.php?id=' . $event['ref_id']],
              'invoice' => ['receipt', 'gold', invoice_number($event['ref_id']) . ' issued to ' . $event['who'] . ' for ' . money($event['amount']) . ($event['extra'] === 'void' ? ' (now void)' : ''), 'admin/invoice.php?id=' . $event['ref_id']],
              'breakdown' => ['alert', 'red', 'Breakdown request from ' . $event['who'] . ': ' . strtolower($problems[$event['extra']] ?? $event['extra']), 'admin/breakdown.php?id=' . $event['ref_id']],
              default => ['calendar', 'blue', $event['who'] . ' booked ' . strtolower($event['extra']), 'admin/booking.php?id=' . $event['ref_id']],
          };
          ?>
          <li class="feed-item">
            <span class="feed-icon feed-<?= e($tone) ?>"><?= icon($eventIcon) ?></span>
            <a href="<?= e(url($link)) ?>"><?= e($text) ?></a>
            <time datetime="<?= e($sinceAttr($event['happened_at'])) ?>"><?= e(time_ago($event['happened_at'])) ?></time>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</div>

<div class="toast-stack" data-toasts aria-live="polite"></div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
