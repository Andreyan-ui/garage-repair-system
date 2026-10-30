<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

require_admin();

$id = (int) query_param('id');
$units = part_units();
$reasons = movement_reasons();

$part = null;
if ($id > 0) {
    $part = db_one('SELECT * FROM parts WHERE id = ?', 'i', [$id]);
    if ($part === null) {
        abort(404, 'Part not found', 'That part does not exist.');
    }
}

$lockPart = function () use ($id): array {
    $row = db_one('SELECT * FROM parts WHERE id = ? FOR UPDATE', 'i', [$id]);
    if ($row === null) {
        throw new UserFacingError('This part no longer exists.');
    }
    return $row;
};

$values = [
    'name' => $part['name'] ?? '',
    'part_number' => (string) ($part['part_number'] ?? ''),
    'unit' => $part['unit'] ?? 'piece',
    'cost_price' => $part ? number_format((int) $part['cost_price']) : '',
    'sell_price' => $part ? number_format((int) $part['sell_price']) : '',
    'reorder_level' => $part ? format_quantity($part['reorder_level']) : '2',
    'supplier' => (string) ($part['supplier'] ?? ''),
    'location' => (string) ($part['location'] ?? ''),
    'is_active' => (string) ($part['is_active'] ?? '1'),
    'opening_stock' => '0',
];
$errors = [];
$receive = ['quantity' => '', 'unit_cost' => $part ? number_format((int) $part['cost_price']) : '', 'update_cost' => '1', 'note' => ''];
$receiveErrors = [];
$count = ['counted' => '', 'note' => ''];
$countErrors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'save') {
        foreach (array_keys($values) as $field) {
            $values[$field] = input($field);
        }
        $values['is_active'] = isset($_POST['is_active']) ? '1' : '0';
        $values['part_number'] = strtoupper($values['part_number']);

        if ($values['name'] === '' || too_long($values['name'], 120)) {
            $errors['name'] = 'Enter the part name, up to 120 characters.';
        }
        if (too_long($values['part_number'], 40)) {
            $errors['part_number'] = 'Keep the part number under 40 characters.';
        }
        if (!in_array($values['unit'], $units, true)) {
            $errors['unit'] = 'Choose a unit.';
        }
        $cost = $values['cost_price'] === '' ? 0 : parse_money($values['cost_price']);
        if ($cost === null) {
            $errors['cost_price'] = 'Enter the cost in whole shillings.';
        }
        $sell = $values['sell_price'] === '' ? 0 : parse_money($values['sell_price']);
        if ($sell === null) {
            $errors['sell_price'] = 'Enter the selling price in whole shillings.';
        }
        $reorder = parse_quantity($values['reorder_level'] === '' ? '0' : $values['reorder_level'], true);
        if ($reorder === null) {
            $errors['reorder_level'] = 'Enter the quantity at which to reorder.';
        }
        if (too_long($values['supplier'], 100)) {
            $errors['supplier'] = 'Keep the supplier under 100 characters.';
        }
        if (too_long($values['location'], 50)) {
            $errors['location'] = 'Keep the shelf under 50 characters.';
        }
        $opening = 0.0;
        if (!$part) {
            $opening = parse_quantity($values['opening_stock'] === '' ? '0' : $values['opening_stock'], true);
            if ($opening === null) {
                $errors['opening_stock'] = 'Enter how many you have now, or 0.';
                $opening = 0.0;
            }
        }

        if (!$errors) {
            $partNumber = $values['part_number'] !== '' ? $values['part_number'] : null;
            $supplier = $values['supplier'] !== '' ? $values['supplier'] : null;
            $location = $values['location'] !== '' ? $values['location'] : null;
            try {
                if ($part) {
                    db_execute(
                        'UPDATE parts SET name = ?, part_number = ?, unit = ?, cost_price = ?, sell_price = ?, reorder_level = ?, supplier = ?, location = ?, is_active = ? WHERE id = ?',
                        'sssiidssii',
                        [$values['name'], $partNumber, $values['unit'], $cost, $sell, $reorder, $supplier, $location, (int) $values['is_active'], $id]
                    );
                    $savedId = $id;
                } else {
                    $savedId = db_transaction(function () use ($values, $partNumber, $cost, $sell, $reorder, $supplier, $location, $opening) {
                        $newId = db_insert(
                            'INSERT INTO parts (name, part_number, unit, cost_price, sell_price, reorder_level, supplier, location, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            'sssiidssi',
                            [$values['name'], $partNumber, $values['unit'], $cost, $sell, $reorder, $supplier, $location, (int) $values['is_active']]
                        );
                        if ($opening > 0) {
                            change_stock($newId, $opening, 'opening', null, 'Opening stock', $cost);
                        }
                        return $newId;
                    });
                }
                flash('success', $part ? 'Part updated.' : 'Part added.');
                if ($sell < $cost) {
                    flash('error', 'The selling price is lower than the cost price, so this part sells at a loss.');
                }
                redirect('admin/part.php?id=' . $savedId);
            } catch (mysqli_sql_exception $e) {
                if (!is_duplicate_key($e, 'uq_parts_number')) {
                    throw $e;
                }
                $errors['part_number'] = 'Another part already uses this part number.';
            }
        }
    }

    if ($action === 'receive' && $part) {
        foreach (array_keys($receive) as $field) {
            $receive[$field] = input($field);
        }
        $receive['update_cost'] = isset($_POST['update_cost']) ? '1' : '';
        $quantity = parse_quantity($receive['quantity']);
        if ($quantity === null) {
            $receiveErrors['quantity'] = 'Enter how many arrived.';
        }
        $unitCost = null;
        if ($receive['unit_cost'] !== '') {
            $unitCost = parse_money($receive['unit_cost']);
            if ($unitCost === null) {
                $receiveErrors['unit_cost'] = 'Enter the cost per unit in whole shillings.';
            }
        }
        if (too_long($receive['note'], 255)) {
            $receiveErrors['note'] = 'Keep the note under 255 characters.';
        }
        if (!$receiveErrors) {
            db_transaction(function () use ($lockPart, $id, $quantity, $unitCost, $receive) {
                $lockPart();
                change_stock($id, $quantity, 'purchase', null, $receive['note'] !== '' ? $receive['note'] : null, $unitCost);
                if ($unitCost !== null && $receive['update_cost'] === '1') {
                    db_execute('UPDATE parts SET cost_price = ? WHERE id = ?', 'ii', [$unitCost, $id]);
                }
            });
            flash('success', quantity_label($quantity, $part['unit']) . ' of ' . $part['name'] . ' added to stock.');
            redirect('admin/part.php?id=' . $id);
        }
    }

    if ($action === 'count' && $part) {
        $count['counted'] = input('counted');
        $count['note'] = input('note');
        $counted = parse_quantity($count['counted'], true);
        if ($counted === null) {
            $countErrors['counted'] = 'Enter the quantity you counted on the shelf.';
        }
        if ($count['note'] === '' || too_long($count['note'], 255)) {
            $countErrors['note'] = 'Say why the stock is being corrected, for example "Monthly stock count".';
        }
        if (!$countErrors) {
            $difference = db_transaction(function () use ($lockPart, $id, $counted, $count) {
                $current = $lockPart();
                $difference = round($counted - (float) $current['stock_quantity'], 2);
                if (abs($difference) >= 0.01) {
                    change_stock($id, $difference, 'adjustment', null, $count['note']);
                }
                return $difference;
            });
            if (abs($difference) < 0.01) {
                flash('success', 'The count matches the system. Nothing changed.');
            } else {
                flash('success', 'Stock corrected by ' . ($difference > 0 ? '+' : '') . quantity_label($difference, $part['unit']) . '.');
            }
            redirect('admin/part.php?id=' . $id);
        }
    }

    if ($action === 'delete' && $part) {
        $used = (int) db_value('SELECT COUNT(*) FROM job_items WHERE part_id = ?', 'i', [$id])
            + (int) db_value("SELECT COUNT(*) FROM stock_movements WHERE part_id = ? AND reason <> 'opening'", 'i', [$id]);
        if ($used > 0) {
            flash('error', 'This part has stock history, so it cannot be deleted. Untick "In use" to hide it instead.');
            redirect('admin/part.php?id=' . $id);
        }
        db_transaction(function () use ($id) {
            db_execute('DELETE FROM stock_movements WHERE part_id = ?', 'i', [$id]);
            db_execute('DELETE FROM parts WHERE id = ?', 'i', [$id]);
        });
        flash('success', 'Part deleted.');
        redirect('admin/parts.php');
    }
}

$movements = [];
$usage = null;
$canDelete = false;
if ($part) {
    $part = db_one('SELECT * FROM parts WHERE id = ?', 'i', [$id]);
    $movements = db_all(
        'SELECT m.*, a.full_name AS admin_name, a.username AS admin_username
         FROM stock_movements m
         LEFT JOIN admin a ON a.id = m.admin_id
         WHERE m.part_id = ?
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 100',
        'i',
        [$id]
    );
    $usage = db_one(
        "SELECT
            COALESCE(-SUM(CASE WHEN reason IN ('job_use', 'job_return') AND created_at >= NOW() - INTERVAL 30 DAY THEN quantity_change END), 0) AS last_30,
            COALESCE(-SUM(CASE WHEN reason IN ('job_use', 'job_return') AND created_at >= NOW() - INTERVAL 90 DAY THEN quantity_change END), 0) AS last_90
         FROM stock_movements WHERE part_id = ?",
        'i',
        [$id]
    );
    $canDelete = (int) db_value('SELECT COUNT(*) FROM job_items WHERE part_id = ?', 'i', [$id])
        + (int) db_value("SELECT COUNT(*) FROM stock_movements WHERE part_id = ? AND reason <> 'opening'", 'i', [$id]) === 0;
}

$pageTitle = $part ? $part['name'] : 'Add a part';
$activeNav = 'parts.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/parts.php')) ?>">&larr; Parts and stock</a></p>
<div class="page-head">
  <div>
    <h1><?= $part ? e($part['name']) : 'Add a part' ?></h1>
    <?php if ($part): ?>
      <p class="muted"><?= e(implode(' · ', array_filter([$part['part_number'], $part['location'] ? 'Shelf ' . $part['location'] : null, $part['supplier']]))) ?></p>
    <?php endif; ?>
  </div>
  <?php if ($part): ?>
    <?= $part['is_active'] ? stock_badge($part) : '<span class="badge badge-gray">Not in use</span>' ?>
  <?php endif; ?>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <?php if ($part): ?>
      <div class="stat-grid">
        <div class="stat <?= stock_state($part) === 'ok' ? 'stat-good' : 'stat-warn' ?>">
          <span class="stat-value"><?= e(format_quantity($part['stock_quantity'])) ?></span>
          <span class="stat-label"><?= e(ucfirst(unit_label($part['stock_quantity'], $part['unit']))) ?> in stock</span>
        </div>
        <div class="stat">
          <span class="stat-value"><?= e(format_quantity($part['reorder_level'])) ?></span>
          <span class="stat-label">Reorder level</span>
        </div>
        <div class="stat">
          <span class="stat-value"><?= e(format_quantity($usage['last_30'])) ?></span>
          <span class="stat-label">Used in 30 days (<?= e(format_quantity($usage['last_90'])) ?> in 90)</span>
        </div>
        <div class="stat stat-money">
          <span class="stat-value"><?= e(money(max(0, (float) $part['stock_quantity']) * (int) $part['cost_price'])) ?></span>
          <span class="stat-label">Stock value at cost</span>
        </div>
      </div>

      <section class="card panel">
        <div class="panel-head">
          <h2>Stock history</h2>
          <span class="muted">Latest <?= count($movements) ?></span>
        </div>
        <?php if (!$movements): ?>
          <p class="empty-state">No stock movements yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>When</th><th class="num">Change</th><th>Reason</th><th>Note</th><th>By</th></tr></thead>
              <tbody>
                <?php foreach ($movements as $movement): ?>
                  <?php $change = (float) $movement['quantity_change']; ?>
                  <tr>
                    <td class="nowrap"><?= e(format_datetime($movement['created_at'])) ?></td>
                    <td class="num"><strong class="<?= $change < 0 ? 'text-danger' : '' ?>"><?= $change > 0 ? '+' : '' ?><?= e(format_quantity($change)) ?></strong></td>
                    <td>
                      <?= e($reasons[$movement['reason']] ?? $movement['reason']) ?>
                      <?php if ($movement['job_id']): ?>
                        <a class="muted" href="<?= e(url('admin/job.php?id=' . $movement['job_id'])) ?>"><?= e(job_number($movement['job_id'])) ?></a>
                      <?php endif; ?>
                    </td>
                    <td><?= e($movement['note'] ?? '') ?><?= $movement['unit_cost'] !== null ? '<span class="muted">' . e(money($movement['unit_cost'])) . ' each</span>' : '' ?></td>
                    <td><?= e(($movement['admin_name'] ?: $movement['admin_username']) ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <form method="post" class="card form-card" action="<?= e(url($part ? 'admin/part.php?id=' . $id : 'admin/part.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <h2 class="card-title"><?= $part ? 'Part details' : 'New part' ?></h2>
      <?= error_summary($errors) ?>
      <div class="field-row">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" maxlength="120" required placeholder="e.g. Oil filter (Toyota)" value="<?= e($values['name']) ?>"<?= field_attrs($errors, 'name') ?>>
          <?= field_error($errors, 'name') ?>
        </div>
        <div class="field">
          <label for="part_number">Part number <span class="optional">optional</span></label>
          <input id="part_number" name="part_number" type="text" maxlength="40" autocapitalize="characters" value="<?= e($values['part_number']) ?>"<?= field_attrs($errors, 'part_number') ?>>
          <?= field_error($errors, 'part_number') ?>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="unit">Sold by the</label>
          <select id="unit" name="unit"<?= field_attrs($errors, 'unit') ?>>
            <?php foreach ($units as $unit): ?>
              <option value="<?= e($unit) ?>"<?= selected($values['unit'], $unit) ?>><?= e($unit) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'unit') ?>
        </div>
        <div class="field">
          <label for="reorder_level">Reorder when stock reaches</label>
          <input id="reorder_level" name="reorder_level" type="text" inputmode="decimal" maxlength="10" value="<?= e($values['reorder_level']) ?>"<?= field_attrs($errors, 'reorder_level') ?>>
          <?= field_error($errors, 'reorder_level') ?>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="cost_price">Cost price (<?= e(config('currency.code')) ?>)</label>
          <input id="cost_price" name="cost_price" type="text" inputmode="numeric" maxlength="14" value="<?= e($values['cost_price']) ?>"<?= field_attrs($errors, 'cost_price') ?>>
          <p class="hint">What the garage pays per <?= e($values['unit']) ?>.</p>
          <?= field_error($errors, 'cost_price') ?>
        </div>
        <div class="field">
          <label for="sell_price">Selling price (<?= e(config('currency.code')) ?>)</label>
          <input id="sell_price" name="sell_price" type="text" inputmode="numeric" maxlength="14" value="<?= e($values['sell_price']) ?>"<?= field_attrs($errors, 'sell_price') ?>>
          <p class="hint">Used as the default price on job cards.</p>
          <?= field_error($errors, 'sell_price') ?>
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="supplier">Supplier <span class="optional">optional</span></label>
          <input id="supplier" name="supplier" type="text" maxlength="100" value="<?= e($values['supplier']) ?>"<?= field_attrs($errors, 'supplier') ?>>
          <?= field_error($errors, 'supplier') ?>
        </div>
        <div class="field">
          <label for="location">Shelf or bin <span class="optional">optional</span></label>
          <input id="location" name="location" type="text" maxlength="50" value="<?= e($values['location']) ?>"<?= field_attrs($errors, 'location') ?>>
          <?= field_error($errors, 'location') ?>
        </div>
      </div>
      <?php if (!$part): ?>
        <div class="field">
          <label for="opening_stock">How many do you have now?</label>
          <input id="opening_stock" name="opening_stock" type="text" inputmode="decimal" maxlength="10" value="<?= e($values['opening_stock']) ?>"<?= field_attrs($errors, 'opening_stock') ?>>
          <p class="hint">Recorded as opening stock. Later deliveries are added with "Receive stock".</p>
          <?= field_error($errors, 'opening_stock') ?>
        </div>
      <?php endif; ?>
      <div class="field">
        <label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= checked($values['is_active'], '1') ?>> In use (can be added to job cards)</label>
      </div>
      <button type="submit" class="btn btn-dark"><?= $part ? 'Save details' : 'Add part' ?></button>
    </form>
  </div>

  <?php if ($part): ?>
    <div class="stack-lg">
      <form method="post" class="card form-card" id="receive">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive">
        <h2 class="card-title">Receive stock</h2>
        <p class="muted small">Use this when a delivery arrives.</p>
        <?= error_summary($receiveErrors) ?>
        <div class="field-row">
          <div class="field">
            <label for="receive_quantity">Quantity (<?= e($part['unit']) ?>)</label>
            <input id="receive_quantity" name="quantity" type="text" inputmode="decimal" maxlength="10" required value="<?= e($receive['quantity']) ?>"<?= field_attrs($receiveErrors, 'quantity') ?>>
            <?= field_error($receiveErrors, 'quantity') ?>
          </div>
          <div class="field">
            <label for="unit_cost">Cost each</label>
            <input id="unit_cost" name="unit_cost" type="text" inputmode="numeric" maxlength="14" value="<?= e($receive['unit_cost']) ?>"<?= field_attrs($receiveErrors, 'unit_cost') ?>>
            <?= field_error($receiveErrors, 'unit_cost') ?>
          </div>
        </div>
        <div class="field">
          <label class="inline-check"><input type="checkbox" name="update_cost" value="1"<?= checked($receive['update_cost'], '1') ?>> Update the part's cost price</label>
        </div>
        <div class="field">
          <label for="receive_note">Supplier or delivery note <span class="optional">optional</span></label>
          <input id="receive_note" name="note" type="text" maxlength="255" placeholder="e.g. Delivery note 4471" value="<?= e($receive['note']) ?>"<?= field_attrs($receiveErrors, 'note') ?>>
          <?= field_error($receiveErrors, 'note') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Add to stock</button>
      </form>

      <form method="post" class="card form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="count">
        <h2 class="card-title">Stock count</h2>
        <p class="muted small">Counted the shelf and the number is different? Enter what you counted and the system will correct itself. The system shows <?= e(quantity_label($part['stock_quantity'], $part['unit'])) ?>.</p>
        <?= error_summary($countErrors) ?>
        <div class="field">
          <label for="counted">Quantity counted</label>
          <input id="counted" name="counted" type="text" inputmode="decimal" maxlength="10" required value="<?= e($count['counted']) ?>"<?= field_attrs($countErrors, 'counted') ?>>
          <?= field_error($countErrors, 'counted') ?>
        </div>
        <div class="field">
          <label for="count_note">Reason</label>
          <input id="count_note" name="note" type="text" maxlength="255" required placeholder="e.g. Monthly stock count" value="<?= e($count['note']) ?>"<?= field_attrs($countErrors, 'note') ?>>
          <?= field_error($countErrors, 'note') ?>
        </div>
        <button type="submit" class="btn btn-dark btn-block">Save count</button>
      </form>

      <?php if ($canDelete): ?>
        <form method="post" class="card danger-zone" data-confirm="Delete <?= e($part['name']) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <h2>Delete part</h2>
          <p class="muted">This part has never been used or restocked, so it can be deleted.</p>
          <button type="submit" class="btn btn-ghost">Delete part</button>
        </form>
      <?php else: ?>
        <section class="card">
          <h2>Stop using this part</h2>
          <p class="muted">Parts with history cannot be deleted. Untick "In use" in the details to hide it from job cards.</p>
        </section>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
