<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_admin();

$id = (int) query_param('id');
$statuses = job_statuses();
$itemTypes = item_types();
$fuelLevels = fuel_levels();
$states = invoice_states();
$maxYear = (int) date('Y') + 1;

$loadJob = function () use ($id): ?array {
    return db_one(
        'SELECT j.*, m.full_name AS mechanic_name, c.full_name AS account_name, a.full_name AS created_by_name,
                b.reference AS booking_reference, br.reference AS breakdown_reference
         FROM jobs j
         LEFT JOIN mechanics m ON m.id = j.mechanic_id
         LEFT JOIN customers c ON c.id = j.customer_id
         LEFT JOIN admin a ON a.id = j.created_by
         LEFT JOIN bookings b ON b.id = j.booking_id
         LEFT JOIN breakdown_requests br ON br.id = j.breakdown_id
         WHERE j.id = ?',
        'i',
        [$id]
    );
};

$job = $loadJob();
if ($job === null) {
    abort(404, 'Job card not found', 'That job card does not exist or has been deleted.');
}

$lockJob = function () use ($id): array {
    $row = db_one('SELECT * FROM jobs WHERE id = ? FOR UPDATE', 'i', [$id]);
    if ($row === null) {
        throw new UserFacingError('This job card no longer exists.');
    }
    return $row;
};

$itemValues = [
    'item_type' => 'labour',
    'part_id' => '',
    'description' => '',
    'mechanic_id' => (string) $job['mechanic_id'],
    'quantity' => '1',
    'unit_price' => '',
];
$itemErrors = [];

$detailFields = ['contact_name', 'contact_phone', 'contact_email', 'vehicle_plate', 'vehicle_make', 'vehicle_model', 'vehicle_year', 'vehicle_colour', 'odometer_km', 'fuel_level', 'condition_notes', 'complaint', 'diagnosis', 'recommendations', 'mechanic_id'];
$detailValues = [];
foreach ($detailFields as $field) {
    $detailValues[$field] = (string) $job[$field];
}
$detailValues['contact_phone'] = format_phone($job['contact_phone']);
$detailValues['promised_date'] = $job['promised_at'] ? date('Y-m-d', strtotime($job['promised_at'])) : '';
$detailValues['promised_time'] = $job['promised_at'] ? date('H:i', strtotime($job['promised_at'])) : '17:00';
$detailErrors = [];

$invoiceValues = ['discount' => '', 'notes' => ''];
$invoiceErrors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'status') {
        $note = input('customer_note');
        if (too_long($note, 2000)) {
            flash('error', 'Keep the message to the customer under 2000 characters.');
            redirect('admin/job.php?id=' . $id);
        }
        try {
            foreach (update_job_status($id, input('status'), $note) as [$type, $message]) {
                flash($type, $message);
            }
        } catch (UserFacingError $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/job.php?id=' . $id);
    }

    if ($action === 'details') {
        foreach (array_keys($detailValues) as $field) {
            $detailValues[$field] = input($field);
        }
        if ($detailValues['contact_name'] === '' || too_long($detailValues['contact_name'], 100)) {
            $detailErrors['contact_name'] = 'Enter the customer name.';
        }
        $phone = normalize_phone($detailValues['contact_phone']);
        if ($phone === null) {
            $detailErrors['contact_phone'] = 'Enter a valid phone number.';
        }
        $email = null;
        if ($detailValues['contact_email'] !== '') {
            $email = normalize_email($detailValues['contact_email']);
            if ($email === null) {
                $detailErrors['contact_email'] = 'Enter a valid email or leave it blank.';
            }
        }
        $plate = normalize_plate($detailValues['vehicle_plate']);
        if ($plate === null) {
            $detailErrors['vehicle_plate'] = 'Enter a valid number plate.';
        }
        foreach (['vehicle_make' => 'make', 'vehicle_model' => 'model'] as $field => $label) {
            if ($detailValues[$field] === '' || too_long($detailValues[$field], 50)) {
                $detailErrors[$field] = 'Enter the ' . $label . '.';
            }
        }
        $year = null;
        if ($detailValues['vehicle_year'] !== '') {
            $year = filter_var($detailValues['vehicle_year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => $maxYear]]);
            if ($year === false) {
                $detailErrors['vehicle_year'] = 'Enter a year between 1950 and ' . $maxYear . '.';
                $year = null;
            }
        }
        if (too_long($detailValues['vehicle_colour'], 30)) {
            $detailErrors['vehicle_colour'] = 'Keep the colour under 30 characters.';
        }
        $odometer = null;
        if ($detailValues['odometer_km'] !== '') {
            $odometer = filter_var(str_replace([',', ' '], '', $detailValues['odometer_km']), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3000000]]);
            if ($odometer === false) {
                $detailErrors['odometer_km'] = 'Enter the odometer reading in kilometres.';
                $odometer = null;
            }
        }
        if ($detailValues['fuel_level'] !== '' && !isset($fuelLevels[$detailValues['fuel_level']])) {
            $detailErrors['fuel_level'] = 'Choose a fuel level.';
        }
        if ($detailValues['complaint'] === '' || too_long($detailValues['complaint'], 2000)) {
            $detailErrors['complaint'] = 'Describe what the customer wants done.';
        }
        foreach (['condition_notes', 'diagnosis', 'recommendations'] as $field) {
            if (too_long($detailValues[$field], 2000)) {
                $detailErrors[$field] = 'Keep this under 2000 characters.';
            }
        }
        $mechanicId = null;
        if ($detailValues['mechanic_id'] !== '') {
            $mechanicId = (int) db_value('SELECT id FROM mechanics WHERE id = ?', 'i', [(int) $detailValues['mechanic_id']]) ?: null;
            if ($mechanicId === null) {
                $detailErrors['mechanic_id'] = 'Choose a mechanic from the list.';
            }
        }
        $promisedAt = null;
        if ($detailValues['promised_date'] !== '') {
            $time = $detailValues['promised_time'] !== '' ? $detailValues['promised_time'] : '17:00';
            $promised = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $detailValues['promised_date'] . ' ' . $time);
            if (!$promised || $promised->format('Y-m-d H:i') !== $detailValues['promised_date'] . ' ' . $time) {
                $detailErrors['promised_date'] = 'Enter a valid date and time.';
            } else {
                $promisedAt = $promised->format('Y-m-d H:i:s');
            }
        }

        if (!$detailErrors) {
            $nullable = fn (string $value) => $value !== '' ? $value : null;
            db_execute(
                'UPDATE jobs SET contact_name = ?, contact_phone = ?, contact_email = ?, vehicle_plate = ?, vehicle_make = ?, vehicle_model = ?,
                    vehicle_year = ?, vehicle_colour = ?, odometer_km = ?, fuel_level = ?, condition_notes = ?, complaint = ?,
                    diagnosis = ?, recommendations = ?, mechanic_id = ?, promised_at = ?
                 WHERE id = ?',
                'ssssssisisssssisi',
                [
                    $detailValues['contact_name'],
                    $phone,
                    $email,
                    $plate,
                    $detailValues['vehicle_make'],
                    $detailValues['vehicle_model'],
                    $year,
                    $nullable($detailValues['vehicle_colour']),
                    $odometer,
                    $nullable($detailValues['fuel_level']),
                    $nullable($detailValues['condition_notes']),
                    $detailValues['complaint'],
                    $nullable($detailValues['diagnosis']),
                    $nullable($detailValues['recommendations']),
                    $mechanicId,
                    $promisedAt,
                    $id,
                ]
            );
            flash('success', 'Job details saved.');
            redirect('admin/job.php?id=' . $id);
        }
    }

    if ($action === 'add_item') {
        foreach (array_keys($itemValues) as $field) {
            $itemValues[$field] = input($field);
        }
        if (!isset($itemTypes[$itemValues['item_type']])) {
            $itemErrors['item_type'] = 'Choose what you are adding.';
        }
        $quantity = parse_quantity($itemValues['quantity']);
        if ($quantity === null) {
            $itemErrors['quantity'] = 'Enter a quantity greater than zero, up to 2 decimal places.';
        }
        $mechanicId = null;
        if ($itemValues['mechanic_id'] !== '') {
            $mechanicId = (int) db_value('SELECT id FROM mechanics WHERE id = ?', 'i', [(int) $itemValues['mechanic_id']]) ?: null;
        }
        $part = null;
        $description = $itemValues['description'];
        $price = null;
        if ($itemValues['item_type'] === 'part') {
            $part = db_one('SELECT * FROM parts WHERE id = ? AND is_active = 1', 'i', [(int) $itemValues['part_id']]);
            if ($part === null) {
                $itemErrors['part_id'] = 'Choose a part from stock.';
            } else {
                $description = $description !== '' ? $description : $part['name'];
                $price = $itemValues['unit_price'] === '' ? (int) $part['sell_price'] : parse_money($itemValues['unit_price']);
            }
            $mechanicId = null;
        } else {
            if ($description === '') {
                $itemErrors['description'] = 'Describe the work or item.';
            }
            $price = parse_money($itemValues['unit_price']);
        }
        if (too_long($description, 200)) {
            $itemErrors['description'] = 'Keep the description under 200 characters.';
        }
        if ($price === null && !isset($itemErrors['part_id'])) {
            $itemErrors['unit_price'] = 'Enter a price in whole shillings, for example 50,000.';
        }

        if (!$itemErrors) {
            try {
                db_transaction(function () use ($lockJob, $id, $itemValues, $part, $description, $mechanicId, $quantity, $price, $admin) {
                    $lockJob();
                    if (active_invoice_for_job($id)) {
                        throw new UserFacingError('This job has already been invoiced. Void the invoice to change the work.');
                    }
                    if ($part) {
                        $inStock = (float) db_value('SELECT stock_quantity FROM parts WHERE id = ? FOR UPDATE', 'i', [$part['id']]);
                        if ($inStock + 0.0001 < $quantity) {
                            throw new UserFacingError('Only ' . quantity_label($inStock, $part['unit']) . ' of ' . $part['name'] . ' in stock. Receive more stock first, or add it as an "Other item" if it was bought in for this job.');
                        }
                    }
                    db_insert(
                        'INSERT INTO job_items (job_id, item_type, description, part_id, mechanic_id, quantity, unit_price, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        'issiidii',
                        [$id, $itemValues['item_type'], $description, $part['id'] ?? null, $mechanicId, $quantity, $price, $admin['id']]
                    );
                    if ($part) {
                        change_stock((int) $part['id'], -$quantity, 'job_use', $id);
                    }
                });
                flash('success', 'Added "' . $description . '" (' . money(line_total($quantity, $price)) . ').');
                header('Location: ' . url('admin/job.php?id=' . $id) . '#items');
                exit;
            } catch (UserFacingError $e) {
                if (active_invoice_for_job($id)) {
                    flash('error', $e->getMessage());
                    redirect('admin/job.php?id=' . $id);
                }
                $itemErrors[$part ? 'quantity' : 'item_type'] = $e->getMessage();
            }
        }
    }

    if ($action === 'remove_item') {
        $itemId = (int) input('item_id');
        try {
            $removed = db_transaction(function () use ($lockJob, $id, $itemId) {
                $lockJob();
                if (active_invoice_for_job($id)) {
                    throw new UserFacingError('This job has already been invoiced. Void the invoice to change the work.');
                }
                $item = db_one('SELECT * FROM job_items WHERE id = ? AND job_id = ? FOR UPDATE', 'ii', [$itemId, $id]);
                if ($item === null) {
                    throw new UserFacingError('That line has already been removed.');
                }
                db_execute('DELETE FROM job_items WHERE id = ?', 'i', [$itemId]);
                if ($item['item_type'] === 'part' && $item['part_id'] !== null) {
                    change_stock((int) $item['part_id'], (float) $item['quantity'], 'job_return', $id);
                }
                return $item;
            });
            flash('success', 'Removed "' . $removed['description'] . '".' . ($removed['item_type'] === 'part' ? ' ' . format_quantity($removed['quantity']) . ' returned to stock.' : ''));
        } catch (UserFacingError $e) {
            flash('error', $e->getMessage());
        }
        header('Location: ' . url('admin/job.php?id=' . $id) . '#items');
        exit;
    }

    if ($action === 'invoice') {
        $invoiceValues['discount'] = input('discount');
        $invoiceValues['notes'] = input('notes');
        $discount = 0;
        if ($invoiceValues['discount'] !== '') {
            $discount = parse_money($invoiceValues['discount']);
            if ($discount === null) {
                $invoiceErrors['discount'] = 'Enter the discount in whole shillings, or leave it blank.';
            }
        }
        if (too_long($invoiceValues['notes'], 1000)) {
            $invoiceErrors['notes'] = 'Keep the notes under 1000 characters.';
        }
        if (!$invoiceErrors) {
            try {
                $invoiceId = db_transaction(function () use ($lockJob, $id, $discount, $invoiceValues, $admin) {
                    $current = $lockJob();
                    if (active_invoice_for_job($id)) {
                        throw new UserFacingError('This job already has an invoice.');
                    }
                    $lines = db_all('SELECT item_type, description, quantity, unit_price FROM job_items WHERE job_id = ? ORDER BY FIELD(item_type, \'labour\', \'part\', \'other\'), id', 'i', [$id]);
                    if (!$lines) {
                        throw new UserFacingError('Add at least one line of work or parts before creating the invoice.');
                    }
                    $totals = calculate_totals($lines, 0);
                    if ($discount > $totals['subtotal']) {
                        throw new UserFacingError('The discount cannot be more than the subtotal of ' . money($totals['subtotal']) . '.');
                    }
                    $totals = calculate_totals($lines, $discount);
                    $invoiceId = db_insert(
                        'INSERT INTO invoices (job_id, bill_name, bill_phone, bill_email, vehicle_plate, vehicle_label, odometer_km,
                            subtotal, discount, tax_rate, tax_amount, total, notes, issued_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        'isssssiiidiisi',
                        [
                            $id,
                            $current['contact_name'],
                            $current['contact_phone'],
                            $current['contact_email'],
                            $current['vehicle_plate'],
                            mb_substr(job_vehicle_label($current), 0, 120),
                            $current['odometer_km'],
                            $totals['subtotal'],
                            $totals['discount'],
                            $totals['tax_rate'],
                            $totals['tax'],
                            $totals['total'],
                            $invoiceValues['notes'] !== '' ? $invoiceValues['notes'] : null,
                            $admin['id'],
                        ]
                    );
                    foreach ($lines as $line) {
                        db_insert(
                            'INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)',
                            'issdii',
                            [$invoiceId, $line['item_type'], $line['description'], $line['quantity'], $line['unit_price'], line_total($line['quantity'], $line['unit_price'])]
                        );
                    }
                    return $invoiceId;
                });
                flash('success', invoice_number($invoiceId) . ' created. Record payments here, or share it with the customer.');
                redirect('admin/invoice.php?id=' . $invoiceId);
            } catch (UserFacingError $e) {
                if (active_invoice_for_job($id)) {
                    flash('error', $e->getMessage());
                    redirect('admin/job.php?id=' . $id);
                }
                $invoiceErrors['discount'] = $e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $itemCount = (int) db_value('SELECT COUNT(*) FROM job_items WHERE job_id = ?', 'i', [$id]);
        $invoiceCount = (int) db_value('SELECT COUNT(*) FROM invoices WHERE job_id = ?', 'i', [$id]);
        if ($itemCount > 0 || $invoiceCount > 0) {
            flash('error', 'Only empty job cards can be deleted. Set the status to Cancelled instead.');
            redirect('admin/job.php?id=' . $id);
        }
        db_transaction(function () use ($id, $job) {
            db_execute('DELETE FROM jobs WHERE id = ?', 'i', [$id]);
            if ($job['booking_id']) {
                db_execute("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'in_progress'", 'i', [$job['booking_id']]);
            }
        });
        flash('success', job_number($id) . ' was deleted.');
        redirect('admin/jobs.php');
    }
}

$job = $loadJob();
$invoice = active_invoice_for_job($id);
$locked = $invoice !== null;
$items = db_all(
    'SELECT ji.*, p.part_number, p.unit AS part_unit, m.full_name AS mechanic_name
     FROM job_items ji
     LEFT JOIN parts p ON p.id = ji.part_id
     LEFT JOIN mechanics m ON m.id = ji.mechanic_id
     WHERE ji.job_id = ?
     ORDER BY ji.id',
    'i',
    [$id]
);
$totals = calculate_totals($items);
$voidInvoices = db_all("SELECT id, total, voided_at FROM invoices WHERE job_id = ? AND status = 'void' ORDER BY id", 'i', [$id]);
$parts = db_all('SELECT id, part_number, name, unit, sell_price, stock_quantity FROM parts WHERE is_active = 1 ORDER BY name');
$mechanics = active_mechanics($job['mechanic_id']);
$history = db_all(
    "SELECT id, status, complaint, created_at FROM jobs WHERE vehicle_plate = ? AND id <> ? ORDER BY created_at DESC LIMIT 8",
    'si',
    [$job['vehicle_plate'], $id]
);
$canDelete = !$items && !$voidInvoices && !$invoice;
$vehicleName = trim($job['vehicle_make'] . ' ' . $job['vehicle_model']);
$garageName = config('garage.name');

$whatsAppText = '';
if ($job['status'] === 'ready') {
    $whatsAppText = 'Hello ' . $job['contact_name'] . ', your ' . $vehicleName . ' (' . $job['vehicle_plate'] . ') is ready for collection at ' . $garageName . '.'
        . ($invoice ? ' Amount due: ' . money(invoice_balance($invoice)) . '.' : '')
        . ' Job card ' . job_number($id) . '.';
} elseif ($job['status'] === 'awaiting_approval') {
    $whatsAppText = 'Hello ' . $job['contact_name'] . ', we have checked your ' . $vehicleName . ' (' . $job['vehicle_plate'] . ').'
        . ($job['diagnosis'] ? ' ' . $job['diagnosis'] : '')
        . ' The estimated cost is ' . money($totals['total']) . '. Please reply or call ' . format_phone(config('garage.phone')) . ' to approve the work. Job card ' . job_number($id) . '.';
} elseif ($job['status'] === 'awaiting_parts') {
    $whatsAppText = 'Hello ' . $job['contact_name'] . ', we are waiting for parts for your ' . $vehicleName . ' (' . $job['vehicle_plate'] . '). We will update you as soon as they arrive. Job card ' . job_number($id) . '.';
}

$pageTitle = job_number($id);
$activeNav = 'jobs.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/jobs.php')) ?>">&larr; Job cards</a></p>
<div class="page-head">
  <div>
    <h1><?= e(job_number($id)) ?> &middot; <span class="plate"><?= e($job['vehicle_plate']) ?></span> <?= e($vehicleName) ?></h1>
    <p class="muted">
      <?= e($job['contact_name']) ?> &middot; checked in <?= e(time_ago($job['created_at'])) ?><?= $job['created_by_name'] ? ' by ' . e($job['created_by_name']) : '' ?>
      <?php if ($job['promised_at'] && !in_array($job['status'], ['collected', 'cancelled'], true)): ?>
        &middot; <span class="<?= strtotime($job['promised_at']) < time() && $job['status'] !== 'ready' ? 'text-danger' : '' ?>">promised for <?= e(format_datetime($job['promised_at'])) ?></span>
      <?php endif; ?>
    </p>
  </div>
  <div class="button-row">
    <?= status_badge($job['status'], $statuses) ?>
    <a class="btn btn-outline btn-sm" href="<?= e(url('admin/print.php?doc=jobcard&id=' . $id)) ?>" target="_blank" rel="noopener">Print job card</a>
    <?php if ($invoice): ?>
      <a class="btn btn-dark btn-sm" href="<?= e(url('admin/invoice.php?id=' . $invoice['id'])) ?>">Open <?= e(invoice_number($invoice['id'])) ?></a>
    <?php else: ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url('admin/print.php?doc=estimate&id=' . $id)) ?>" target="_blank" rel="noopener">Print estimate</a>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <section class="card panel" id="items">
      <div class="panel-head">
        <h2>Work and parts</h2>
        <span class="muted"><?= count($items) ?> <?= count($items) === 1 ? 'line' : 'lines' ?></span>
      </div>
      <?php if (!$items): ?>
        <p class="empty-state">Nothing added yet. Add labour and parts below as the work happens.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit price</th>
                <th class="num">Amount</th>
                <?php if (!$locked): ?><th><span class="visually-hidden">Remove</span></th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td>
                    <?= e($item['description']) ?>
                    <span class="muted">
                      <?= e($itemTypes[$item['item_type']]) ?>
                      <?php if ($item['part_number']): ?>&middot; <?= e($item['part_number']) ?><?php endif; ?>
                      <?php if ($item['mechanic_name']): ?>&middot; <?= e($item['mechanic_name']) ?><?php endif; ?>
                    </span>
                  </td>
                  <td class="num"><?= e(format_quantity($item['quantity'])) ?><?= $item['part_unit'] ? ' <span class="muted">' . e($item['part_unit']) . '</span>' : '' ?></td>
                  <td class="num"><?= e(number_format((int) $item['unit_price'])) ?></td>
                  <td class="num"><?= e(number_format(line_total($item['quantity'], $item['unit_price']))) ?></td>
                  <?php if (!$locked): ?>
                    <td class="actions">
                      <form method="post" class="inline-form" data-confirm="Remove &quot;<?= e($item['description']) ?>&quot;?<?= $item['item_type'] === 'part' ? ' It will be returned to stock.' : '' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_item">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="item-remove">Remove</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php if ($totals['tax_rate'] > 0): ?>
                <tr><td colspan="3">Subtotal</td><td class="num"><?= e(money($totals['subtotal'])) ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr>
                <tr><td colspan="3"><?= e(config('invoice.tax_label') . ' ' . format_quantity($totals['tax_rate']) . '%') ?></td><td class="num"><?= e(money($totals['tax'])) ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr>
              <?php endif; ?>
              <tr><td colspan="3">Total before any discount</td><td class="num"><?= e(money($totals['total'])) ?></td><?php if (!$locked): ?><td></td><?php endif; ?></tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($locked): ?>
        <p class="locked-note">This work has been invoiced as <a href="<?= e(url('admin/invoice.php?id=' . $invoice['id'])) ?>"><?= e(invoice_number($invoice['id'])) ?></a>. To change it, void the invoice first.</p>
      <?php else: ?>
        <form method="post" class="item-form" data-item-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_item">
          <div class="field span-2">
            <label for="item_type">Add</label>
            <select id="item_type" name="item_type" data-item-type<?= field_attrs($itemErrors, 'item_type') ?>>
              <?php foreach ($itemTypes as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= selected($itemValues['item_type'], $key) ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field span-4" data-show-for="part">
            <label for="part_id">Part</label>
            <select id="part_id" name="part_id" data-part-select data-required-for="part"<?= field_attrs($itemErrors, 'part_id') ?>>
              <option value="">Choose a part</option>
              <?php foreach ($parts as $part): ?>
                <option value="<?= (int) $part['id'] ?>" data-price="<?= (int) $part['sell_price'] ?>" data-unit="<?= e($part['unit']) ?>" data-stock="<?= e(format_quantity($part['stock_quantity'])) ?>"<?= selected($itemValues['part_id'], $part['id']) ?><?= (float) $part['stock_quantity'] <= 0 ? ' disabled' : '' ?>>
                  <?= e($part['name'] . ' - ' . quantity_label($part['stock_quantity'], $part['unit']) . ' left - ' . money($part['sell_price'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field span-4" data-show-for="labour other">
            <label for="description">Description</label>
            <input id="description" name="description" type="text" maxlength="200" placeholder="e.g. Replace front brake pads" value="<?= e($itemValues['description']) ?>" data-required-for="labour other"<?= field_attrs($itemErrors, 'description') ?>>
          </div>
          <div class="field span-2" data-show-for="labour">
            <label for="item_mechanic">Done by</label>
            <select id="item_mechanic" name="mechanic_id">
              <option value="">Not recorded</option>
              <?php foreach ($mechanics as $mechanic): ?>
                <option value="<?= (int) $mechanic['id'] ?>"<?= selected($itemValues['mechanic_id'], $mechanic['id']) ?>><?= e($mechanic['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field span-2">
            <label for="quantity">Quantity</label>
            <input id="quantity" name="quantity" type="text" inputmode="decimal" maxlength="10" required value="<?= e($itemValues['quantity']) ?>"<?= field_attrs($itemErrors, 'quantity') ?>>
            <p class="hint" data-unit-label></p>
          </div>
          <div class="field span-2">
            <label for="unit_price">Unit price (<?= e(config('currency.code')) ?>)</label>
            <input id="unit_price" name="unit_price" type="text" inputmode="numeric" maxlength="14" placeholder="e.g. 50,000" value="<?= e($itemValues['unit_price']) ?>"<?= field_attrs($itemErrors, 'unit_price') ?>>
            <p class="hint" data-price-hint hidden>Leave blank to use the stock price.</p>
          </div>
          <div class="field span-2">
            <button type="submit" class="btn btn-dark btn-block">Add line</button>
          </div>
          <?php if ($itemErrors): ?>
            <div class="span-6">
              <?php foreach ($itemErrors as $message): ?>
                <p class="field-error"><?= e($message) ?></p>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </section>

    <form method="post" class="card form-card" id="details">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">
      <h2 class="card-title">Job details</h2>
      <?= error_summary($detailErrors) ?>

      <fieldset class="form-section">
        <legend>Work notes</legend>
        <div class="field">
          <label for="complaint">Customer concern</label>
          <textarea id="complaint" name="complaint" rows="2" maxlength="2000" required<?= field_attrs($detailErrors, 'complaint') ?>><?= e($detailValues['complaint']) ?></textarea>
          <?= field_error($detailErrors, 'complaint') ?>
        </div>
        <div class="field">
          <label for="diagnosis">Findings</label>
          <textarea id="diagnosis" name="diagnosis" rows="2" maxlength="2000" placeholder="What the mechanic found"<?= field_attrs($detailErrors, 'diagnosis') ?>><?= e($detailValues['diagnosis']) ?></textarea>
          <?= field_error($detailErrors, 'diagnosis') ?>
        </div>
        <div class="field">
          <label for="recommendations">Recommendations</label>
          <textarea id="recommendations" name="recommendations" rows="2" maxlength="2000" placeholder="Work the customer should plan for later"<?= field_attrs($detailErrors, 'recommendations') ?>><?= e($detailValues['recommendations']) ?></textarea>
          <p class="hint">Shown to the customer and printed on the job card.</p>
          <?= field_error($detailErrors, 'recommendations') ?>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="mechanic_id">Lead mechanic</label>
            <select id="mechanic_id" name="mechanic_id"<?= field_attrs($detailErrors, 'mechanic_id') ?>>
              <option value="">Not assigned</option>
              <?php foreach ($mechanics as $mechanic): ?>
                <option value="<?= (int) $mechanic['id'] ?>"<?= selected($detailValues['mechanic_id'], $mechanic['id']) ?>><?= e($mechanic['full_name'] . ($mechanic['is_active'] ? '' : ' (inactive)')) ?></option>
              <?php endforeach; ?>
            </select>
            <?= field_error($detailErrors, 'mechanic_id') ?>
          </div>
          <div class="field">
            <label for="promised_date">Promised ready</label>
            <div class="field-row">
              <input id="promised_date" name="promised_date" type="date" value="<?= e($detailValues['promised_date']) ?>"<?= field_attrs($detailErrors, 'promised_date') ?>>
              <input name="promised_time" type="time" aria-label="Promised time" value="<?= e($detailValues['promised_time']) ?>">
            </div>
            <?= field_error($detailErrors, 'promised_date') ?>
          </div>
        </div>
      </fieldset>

      <fieldset class="form-section">
        <legend>Vehicle at check-in</legend>
        <div class="field-row">
          <div class="field">
            <label for="vehicle_plate">Number plate</label>
            <input id="vehicle_plate" name="vehicle_plate" type="text" maxlength="15" required value="<?= e($detailValues['vehicle_plate']) ?>"<?= field_attrs($detailErrors, 'vehicle_plate') ?>>
            <?= field_error($detailErrors, 'vehicle_plate') ?>
          </div>
          <div class="field">
            <label for="vehicle_colour">Colour</label>
            <input id="vehicle_colour" name="vehicle_colour" type="text" maxlength="30" value="<?= e($detailValues['vehicle_colour']) ?>"<?= field_attrs($detailErrors, 'vehicle_colour') ?>>
            <?= field_error($detailErrors, 'vehicle_colour') ?>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="vehicle_make">Make</label>
            <input id="vehicle_make" name="vehicle_make" type="text" maxlength="50" required value="<?= e($detailValues['vehicle_make']) ?>"<?= field_attrs($detailErrors, 'vehicle_make') ?>>
            <?= field_error($detailErrors, 'vehicle_make') ?>
          </div>
          <div class="field">
            <label for="vehicle_model">Model</label>
            <input id="vehicle_model" name="vehicle_model" type="text" maxlength="50" required value="<?= e($detailValues['vehicle_model']) ?>"<?= field_attrs($detailErrors, 'vehicle_model') ?>>
            <?= field_error($detailErrors, 'vehicle_model') ?>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="vehicle_year">Year</label>
            <input id="vehicle_year" name="vehicle_year" type="number" min="1950" max="<?= $maxYear ?>" value="<?= e($detailValues['vehicle_year']) ?>"<?= field_attrs($detailErrors, 'vehicle_year') ?>>
            <?= field_error($detailErrors, 'vehicle_year') ?>
          </div>
          <div class="field">
            <label for="odometer_km">Odometer (km)</label>
            <input id="odometer_km" name="odometer_km" type="text" inputmode="numeric" maxlength="12" value="<?= e($detailValues['odometer_km']) ?>"<?= field_attrs($detailErrors, 'odometer_km') ?>>
            <?= field_error($detailErrors, 'odometer_km') ?>
          </div>
        </div>
        <div class="field">
          <label for="fuel_level">Fuel level</label>
          <select id="fuel_level" name="fuel_level"<?= field_attrs($detailErrors, 'fuel_level') ?>>
            <option value="">Not recorded</option>
            <?php foreach ($fuelLevels as $key => $label): ?>
              <option value="<?= e($key) ?>"<?= selected($detailValues['fuel_level'], $key) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($detailErrors, 'fuel_level') ?>
        </div>
        <div class="field">
          <label for="condition_notes">Condition at check-in</label>
          <textarea id="condition_notes" name="condition_notes" rows="2" maxlength="2000"<?= field_attrs($detailErrors, 'condition_notes') ?>><?= e($detailValues['condition_notes']) ?></textarea>
          <?= field_error($detailErrors, 'condition_notes') ?>
        </div>
      </fieldset>

      <fieldset class="form-section">
        <legend>Customer contact</legend>
        <div class="field-row">
          <div class="field">
            <label for="contact_name">Name</label>
            <input id="contact_name" name="contact_name" type="text" maxlength="100" required value="<?= e($detailValues['contact_name']) ?>"<?= field_attrs($detailErrors, 'contact_name') ?>>
            <?= field_error($detailErrors, 'contact_name') ?>
          </div>
          <div class="field">
            <label for="contact_phone">Phone</label>
            <input id="contact_phone" name="contact_phone" type="tel" maxlength="20" required value="<?= e($detailValues['contact_phone']) ?>"<?= field_attrs($detailErrors, 'contact_phone') ?>>
            <?= field_error($detailErrors, 'contact_phone') ?>
          </div>
        </div>
        <div class="field">
          <label for="contact_email">Email</label>
          <input id="contact_email" name="contact_email" type="email" maxlength="150" value="<?= e($detailValues['contact_email']) ?>"<?= field_attrs($detailErrors, 'contact_email') ?>>
          <?= field_error($detailErrors, 'contact_email') ?>
        </div>
      </fieldset>

      <button type="submit" class="btn btn-dark">Save details</button>
    </form>
  </div>

  <div class="stack-lg">
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="status">
      <h2>Status</h2>
      <p class="muted small"><?= e($statuses[$job['status']]['customer']) ?></p>
      <div class="quick-status" role="group" aria-label="Change status">
        <?php foreach ($statuses as $key => $info): ?>
          <button type="submit" name="status" value="<?= e($key) ?>" aria-pressed="<?= $job['status'] === $key ? 'true' : 'false' ?>"><?= e($info['short']) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="field">
        <label for="customer_note">Message the customer sees online</label>
        <textarea id="customer_note" name="customer_note" rows="3" maxlength="2000" placeholder="e.g. Waiting for brake pads, expected tomorrow morning"><?= e((string) $job['customer_note']) ?></textarea>
        <p class="hint">Saved when you press a status button.<?= $job['customer_id'] || $job['booking_id'] ? '' : ' This customer has no online account or booking, so they will not see it.' ?></p>
      </div>
      <?php if ($whatsAppText !== ''): ?>
        <a class="btn btn-primary btn-block" href="<?= e(whatsapp_link($job['contact_phone'], $whatsAppText)) ?>" target="_blank" rel="noopener">
          <?= $job['status'] === 'ready' ? 'Tell the customer on WhatsApp' : 'Update the customer on WhatsApp' ?>
        </a>
      <?php endif; ?>
    </form>

    <section class="card">
      <h2>Customer</h2>
      <p>
        <strong><?= e($job['contact_name']) ?></strong><br>
        <?= e(format_phone($job['contact_phone'])) ?>
        <?php if ($job['contact_email']): ?><br><a href="mailto:<?= e($job['contact_email']) ?>"><?= e($job['contact_email']) ?></a><?php endif; ?>
      </p>
      <ul class="plain-list small">
        <li><?= $job['customer_id'] ? 'Online account: <a href="' . e(url('admin/customer.php?id=' . $job['customer_id'])) . '">' . e($job['account_name']) . '</a>' : 'No online account linked' ?></li>
        <?php if ($job['booking_reference']): ?>
          <li>From booking <a href="<?= e(url('admin/booking.php?id=' . $job['booking_id'])) ?>"><?= e($job['booking_reference']) ?></a></li>
        <?php endif; ?>
        <?php if ($job['breakdown_reference']): ?>
          <li>From breakdown <a href="<?= e(url('admin/breakdown.php?id=' . $job['breakdown_id'])) ?>"><?= e($job['breakdown_reference']) ?></a></li>
        <?php endif; ?>
      </ul>
      <div class="contact-actions">
        <a class="btn btn-dark btn-sm" href="<?= e(tel_link($job['contact_phone'])) ?>">Call</a>
        <a class="btn btn-outline btn-sm" href="<?= e(whatsapp_link($job['contact_phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a>
      </div>
    </section>

    <section class="card" id="invoice">
      <h2>Invoice</h2>
      <?php if ($invoice): ?>
        <?php $state = payment_state($invoice); ?>
        <dl class="money-summary">
          <div><dt>Invoice</dt><dd><a href="<?= e(url('admin/invoice.php?id=' . $invoice['id'])) ?>"><?= e(invoice_number($invoice['id'])) ?></a> <?= status_badge($state, $states, true) ?></dd></div>
          <div><dt>Total</dt><dd><?= e(money($invoice['total'])) ?></dd></div>
          <div><dt>Paid</dt><dd><?= e(money($invoice['amount_paid'])) ?></dd></div>
          <div class="<?= invoice_balance($invoice) > 0 ? 'is-balance' : 'is-settled' ?>"><dt>Balance</dt><dd><?= e(money(invoice_balance($invoice))) ?></dd></div>
        </dl>
        <a class="btn btn-dark btn-block" href="<?= e(url('admin/invoice.php?id=' . $invoice['id'])) ?>"><?= invoice_balance($invoice) > 0 ? 'Record a payment' : 'View invoice' ?></a>
      <?php elseif (!$items): ?>
        <p class="muted">Add work and parts first. The invoice is created from those lines.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="invoice">
          <dl class="money-summary">
            <div><dt>Work and parts</dt><dd><?= e(money($totals['subtotal'])) ?></dd></div>
          </dl>
          <div class="field">
            <label for="discount">Discount (<?= e(config('currency.code')) ?>) <span class="optional">optional</span></label>
            <input id="discount" name="discount" type="text" inputmode="numeric" maxlength="14" placeholder="0" value="<?= e($invoiceValues['discount']) ?>"<?= field_attrs($invoiceErrors, 'discount') ?>>
            <?= field_error($invoiceErrors, 'discount') ?>
          </div>
          <div class="field">
            <label for="invoice_notes">Note on the invoice <span class="optional">optional</span></label>
            <textarea id="invoice_notes" name="notes" rows="2" maxlength="1000"<?= field_attrs($invoiceErrors, 'notes') ?>><?= e($invoiceValues['notes']) ?></textarea>
            <?= field_error($invoiceErrors, 'notes') ?>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Create invoice</button>
          <p class="hint">After this, the work lines are locked so the invoice always matches what was billed.</p>
        </form>
      <?php endif; ?>
      <?php if ($voidInvoices): ?>
        <p class="hint">Voided:
          <?php foreach ($voidInvoices as $index => $void): ?>
            <?= $index ? ', ' : '' ?><a href="<?= e(url('admin/invoice.php?id=' . $void['id'])) ?>"><?= e(invoice_number($void['id'])) ?></a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Earlier visits by <span class="plate"><?= e($job['vehicle_plate']) ?></span></h2>
      <?php if (!$history): ?>
        <p class="muted">This is the first job card for this vehicle.</p>
      <?php else: ?>
        <ul class="link-list">
          <?php foreach ($history as $visit): ?>
            <li><a href="<?= e(url('admin/job.php?id=' . $visit['id'])) ?>"><?= e(format_date($visit['created_at'], 'j M Y')) ?> &middot; <?= e(mb_strimwidth($visit['complaint'], 0, 40, '...')) ?> <?= status_badge($visit['status'], $statuses, true) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($canDelete): ?>
      <form method="post" class="card danger-zone" data-confirm="Delete <?= e(job_number($id)) ?>? Only do this if it was opened by mistake.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <h2>Delete job card</h2>
        <p class="muted">Only for job cards opened by mistake. Once work is added, cancel the job instead.</p>
        <button type="submit" class="btn btn-ghost">Delete job card</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
