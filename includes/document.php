<?php

function document_item_label(string $type): string
{
    return ['labour' => 'Labour', 'part' => 'Part', 'other' => 'Item'][$type] ?? $type;
}

function build_job_document(int $jobId, string $type): ?array
{
    $job = db_one(
        'SELECT j.*, m.full_name AS mechanic_name, b.reference AS booking_reference
         FROM jobs j
         LEFT JOIN mechanics m ON m.id = j.mechanic_id
         LEFT JOIN bookings b ON b.id = j.booking_id
         WHERE j.id = ?',
        'i',
        [$jobId]
    );
    if ($job === null) {
        return null;
    }
    $items = db_all(
        "SELECT ji.item_type, ji.description, ji.quantity, ji.unit_price, m.full_name AS mechanic_name
         FROM job_items ji
         LEFT JOIN mechanics m ON m.id = ji.mechanic_id
         WHERE ji.job_id = ?
         ORDER BY FIELD(ji.item_type, 'labour', 'part', 'other'), ji.id",
        'i',
        [$jobId]
    );
    foreach ($items as &$item) {
        $item['line_total'] = line_total($item['quantity'], $item['unit_price']);
    }
    unset($item);

    $isEstimate = $type === 'estimate';
    $validDays = (int) config('invoice.estimate_valid_days');
    $fuel = fuel_levels();

    $dates = [['Checked in', format_datetime($job['created_at'])]];
    if ($isEstimate) {
        $dates[] = ['Estimate date', date('j M Y')];
        $dates[] = ['Valid until', date('j M Y', strtotime('+' . $validDays . ' days'))];
    }
    if ($job['promised_at']) {
        $dates[] = ['Promised for', format_datetime($job['promised_at'])];
    }
    if ($job['mechanic_name']) {
        $dates[] = ['Mechanic', $job['mechanic_name']];
    }
    if ($job['booking_reference']) {
        $dates[] = ['Booking', $job['booking_reference']];
    }

    $notes = [['Customer concern', $job['complaint']]];
    if ($job['condition_notes']) {
        $notes[] = ['Condition at check-in', $job['condition_notes']];
    }
    if ($job['diagnosis']) {
        $notes[] = ['Findings', $job['diagnosis']];
    }
    if ($job['recommendations']) {
        $notes[] = ['Recommendations', $job['recommendations']];
    }
    if ($isEstimate) {
        $notes[] = ['About this estimate', 'This is an estimate, not an invoice. Prices are valid for ' . $validDays . ' days. Any extra work we find will only be done after you approve it.'];
    }

    return [
        'type' => $isEstimate ? 'estimate' : 'jobcard',
        'title' => $isEstimate ? 'Estimate' : 'Job card',
        'number' => job_number($job['id']),
        'status' => null,
        'party_heading' => 'Customer',
        'party' => [$job['contact_name'], format_phone($job['contact_phone']), $job['contact_email']],
        'vehicle' => [
            'plate' => $job['vehicle_plate'],
            'label' => job_vehicle_label($job),
            'odometer' => $job['odometer_km'],
            'fuel' => $job['fuel_level'] ? $fuel[$job['fuel_level']] : null,
        ],
        'dates' => $dates,
        'items' => $items,
        'show_prices' => $isEstimate,
        'totals' => $isEstimate ? calculate_totals($items) : null,
        'payments' => [],
        'amount_paid' => 0,
        'balance' => 0,
        'notes' => $notes,
        'signatures' => $isEstimate
            ? ['Customer approval', 'Date']
            : ['Car received by (staff)', 'Work done by (mechanic)', 'Collected by (customer)'],
        'footer' => config('invoice.thank_you'),
    ];
}

function build_invoice_document(int $invoiceId): ?array
{
    $invoice = find_invoice($invoiceId);
    if ($invoice === null) {
        return null;
    }
    $items = db_all(
        'SELECT item_type, description, quantity, unit_price, line_total FROM invoice_items WHERE invoice_id = ? ORDER BY id',
        'i',
        [$invoiceId]
    );
    $payments = db_all(
        'SELECT amount, method, reference, paid_on FROM payments WHERE invoice_id = ? ORDER BY paid_on, id',
        'i',
        [$invoiceId]
    );
    $booking = db_value(
        'SELECT b.reference FROM jobs j JOIN bookings b ON b.id = j.booking_id WHERE j.id = ?',
        'i',
        [(int) $invoice['job_id']]
    );
    $balance = invoice_balance($invoice);

    $dates = [
        ['Invoice date', format_date($invoice['issued_at'], 'j M Y')],
        ['Job card', job_number($invoice['job_id'])],
    ];
    if ($booking) {
        $dates[] = ['Booking', $booking];
    }

    $notes = [];
    if ($invoice['notes']) {
        $notes[] = ['Notes', $invoice['notes']];
    }
    if ($invoice['status'] === 'void') {
        $notes[] = ['This invoice is void', $invoice['void_reason'] ?: 'It has been cancelled and replaced.'];
    } elseif ($balance > 0) {
        $notes[] = ['Payment', config('invoice.payment_terms') . ' Mobile money, cash, bank transfer and card are accepted. Please quote ' . invoice_number($invoice['id']) . ' as the reference.'];
    }

    return [
        'type' => 'invoice',
        'title' => 'Invoice',
        'number' => invoice_number($invoice['id']),
        'status' => payment_state($invoice),
        'party_heading' => 'Bill to',
        'party' => [$invoice['bill_name'], format_phone($invoice['bill_phone']), $invoice['bill_email']],
        'vehicle' => [
            'plate' => $invoice['vehicle_plate'],
            'label' => $invoice['vehicle_label'],
            'odometer' => $invoice['odometer_km'],
            'fuel' => null,
        ],
        'dates' => $dates,
        'items' => $items,
        'show_prices' => true,
        'totals' => [
            'subtotal' => (int) $invoice['subtotal'],
            'discount' => (int) $invoice['discount'],
            'tax_rate' => (float) $invoice['tax_rate'],
            'tax' => (int) $invoice['tax_amount'],
            'total' => (int) $invoice['total'],
        ],
        'payments' => $payments,
        'amount_paid' => (int) $invoice['amount_paid'],
        'balance' => $balance,
        'notes' => $notes,
        'signatures' => [],
        'footer' => config('invoice.thank_you'),
    ];
}

function render_document(array $doc): void
{
    $garage = config('garage');
    $taxNumber = (string) config('invoice.tax_number');
    $methods = payment_methods();
    ?>
<article class="doc doc-<?= e($doc['type']) ?>">
  <?php if ($doc['status'] === 'void'): ?>
    <div class="doc-watermark" aria-hidden="true">VOID</div>
  <?php endif; ?>
  <header class="doc-header">
    <div class="doc-brand">
      <img src="<?= e(asset('images/brand/wycliffe-horizontal-light.svg')) ?>" alt="<?= e($garage['full_name']) ?>" width="199" height="62">
      <div>
        <span><?= e($garage['address']) ?></span>
        <span><?= e(format_phone($garage['phone'])) ?> &middot; <?= e($garage['email']) ?></span>
        <?php if ($taxNumber !== ''): ?>
          <span>TIN <?= e($taxNumber) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="doc-title">
      <h1><?= e($doc['title']) ?></h1>
      <p class="doc-number"><?= e($doc['number']) ?></p>
      <?php if ($doc['status'] !== null): ?>
        <?= status_badge($doc['status'], invoice_states()) ?>
      <?php endif; ?>
    </div>
  </header>

  <section class="doc-meta">
    <div>
      <h2><?= e($doc['party_heading']) ?></h2>
      <p><?= implode('<br>', array_map('e', array_filter($doc['party']))) ?></p>
    </div>
    <div>
      <h2>Vehicle</h2>
      <p>
        <strong><?= e($doc['vehicle']['plate']) ?></strong><br><?= e($doc['vehicle']['label']) ?>
        <?php if (!empty($doc['vehicle']['odometer'])): ?>
          <br>Odometer <?= e(number_format((int) $doc['vehicle']['odometer'])) ?> km
        <?php endif; ?>
        <?php if (!empty($doc['vehicle']['fuel'])): ?>
          <br>Fuel level <?= e(strtolower($doc['vehicle']['fuel'])) ?>
        <?php endif; ?>
      </p>
    </div>
    <div>
      <h2>Details</h2>
      <dl class="doc-dates">
        <?php foreach ($doc['dates'] as [$label, $value]): ?>
          <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </div>
  </section>

  <table class="doc-items">
    <thead>
      <tr>
        <th>Description</th>
        <th class="num">Qty</th>
        <?php if ($doc['show_prices']): ?>
          <th class="num">Unit price</th>
          <th class="num">Amount</th>
        <?php else: ?>
          <th>Mechanic</th>
          <th class="doc-tick-head">Done</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!$doc['items'] && $doc['show_prices']): ?>
        <tr><td colspan="4" class="doc-empty">No work has been added yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($doc['items'] as $item): ?>
        <tr>
          <td><?= e($item['description']) ?> <span class="doc-item-type"><?= e(document_item_label($item['item_type'])) ?></span></td>
          <td class="num"><?= e(format_quantity($item['quantity'])) ?></td>
          <?php if ($doc['show_prices']): ?>
            <td class="num"><?= e(number_format((int) $item['unit_price'])) ?></td>
            <td class="num"><?= e(number_format((int) $item['line_total'])) ?></td>
          <?php else: ?>
            <td><?= e($item['mechanic_name'] ?? '') ?></td>
            <td class="doc-tick"><span></span></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$doc['show_prices']): ?>
        <?php for ($i = 0; $i < 4; $i++): ?>
          <tr class="doc-blank"><td>&nbsp;</td><td></td><td></td><td class="doc-tick"><span></span></td></tr>
        <?php endfor; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($doc['totals'] !== null): ?>
    <?php $totals = $doc['totals']; ?>
    <dl class="doc-totals">
      <div><dt>Subtotal</dt><dd><?= e(money($totals['subtotal'])) ?></dd></div>
      <?php if ($totals['discount'] > 0): ?>
        <div><dt>Discount</dt><dd>&minus; <?= e(money($totals['discount'])) ?></dd></div>
      <?php endif; ?>
      <?php if ($totals['tax_rate'] > 0): ?>
        <div><dt><?= e(config('invoice.tax_label') . ' ' . format_quantity($totals['tax_rate']) . '%') ?></dt><dd><?= e(money($totals['tax'])) ?></dd></div>
      <?php endif; ?>
      <div class="doc-total"><dt>Total</dt><dd><?= e(money($totals['total'])) ?></dd></div>
      <?php if ($doc['type'] === 'invoice' && $doc['status'] !== 'void'): ?>
        <div><dt>Paid</dt><dd><?= e(money($doc['amount_paid'])) ?></dd></div>
        <div class="doc-balance"><dt>Balance due</dt><dd><?= e(money($doc['balance'])) ?></dd></div>
      <?php endif; ?>
    </dl>
  <?php endif; ?>

  <?php if ($doc['payments']): ?>
    <section class="doc-section">
      <h2>Payments received</h2>
      <table class="doc-items doc-payments">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($doc['payments'] as $payment): ?>
            <tr>
              <td><?= e(format_date($payment['paid_on'], 'j M Y')) ?></td>
              <td><?= e($methods[$payment['method']] ?? $payment['method']) ?></td>
              <td><?= e($payment['reference'] ?? '') ?></td>
              <td class="num"><?= e(number_format((int) $payment['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>

  <?php foreach ($doc['notes'] as [$heading, $text]): ?>
    <section class="doc-section">
      <h2><?= e($heading) ?></h2>
      <p><?= nl2br(e($text)) ?></p>
    </section>
  <?php endforeach; ?>

  <?php if ($doc['type'] === 'jobcard'): ?>
    <section class="doc-section">
      <h2>Mechanic notes</h2>
      <div class="doc-lines"><span></span><span></span><span></span></div>
    </section>
  <?php endif; ?>

  <?php if ($doc['signatures']): ?>
    <div class="doc-signatures">
      <?php foreach ($doc['signatures'] as $label): ?>
        <div><span class="doc-sign-line"></span><?= e($label) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <footer class="doc-footer"><?= e($doc['footer']) ?></footer>
</article>
    <?php
}

function render_document_page(array $doc, string $pageTitle, array $actions): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle . ' | ' . config('garage.full_name')) ?></title>
<link rel="icon" href="<?= e(asset('images/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
</head>
<body class="doc-page">
<div class="doc-toolbar no-print">
  <?php foreach ($actions as $action): ?>
    <a class="btn btn-outline btn-sm" href="<?= e($action['href']) ?>"<?= !empty($action['external']) ? ' target="_blank" rel="noopener"' : '' ?>><?= e($action['label']) ?></a>
  <?php endforeach; ?>
  <button type="button" class="btn btn-dark btn-sm" data-print>Print or save as PDF</button>
</div>
<main class="doc-wrap">
  <?php render_document($doc); ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
    <?php
}
