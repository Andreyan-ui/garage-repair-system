<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/document.php';

$admin = require_admin();

$id = (int) query_param('id');
$methods = payment_methods();
$states = invoice_states();

$invoice = find_invoice($id);
if ($invoice === null) {
    abort(404, 'Invoice not found', 'That invoice does not exist.');
}

$lockInvoice = function () use ($id): array {
    $row = db_one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', 'i', [$id]);
    if ($row === null) {
        throw new UserFacingError('This invoice no longer exists.');
    }
    $row['amount_paid'] = (int) db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?', 'i', [$id]);
    return $row;
};

$balance = invoice_balance($invoice);
$payValues = [
    'amount' => $balance > 0 ? number_format($balance) : '',
    'method' => 'mobile_money',
    'reference' => '',
    'paid_on' => date('Y-m-d'),
    'note' => '',
];
$payErrors = [];
$voidReason = '';
$voidError = '';

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'pay') {
        foreach (array_keys($payValues) as $field) {
            $payValues[$field] = input($field);
        }
        $amount = parse_money($payValues['amount']);
        if ($amount === null || $amount <= 0) {
            $payErrors['amount'] = 'Enter the amount received in whole shillings.';
        }
        if (!isset($methods[$payValues['method']])) {
            $payErrors['method'] = 'Choose how the customer paid.';
        }
        if (in_array($payValues['method'], ['mobile_money', 'bank_transfer'], true) && $payValues['reference'] === '') {
            $payErrors['reference'] = 'Enter the transaction ID so the payment can be traced.';
        }
        if (too_long($payValues['reference'], 100)) {
            $payErrors['reference'] = 'Keep the reference under 100 characters.';
        }
        $paidOn = DateTimeImmutable::createFromFormat('!Y-m-d', $payValues['paid_on']);
        if (!$paidOn || $paidOn->format('Y-m-d') !== $payValues['paid_on']) {
            $payErrors['paid_on'] = 'Enter the date the money was received.';
        } elseif ($paidOn > new DateTimeImmutable('today')) {
            $payErrors['paid_on'] = 'The payment date cannot be in the future.';
        }
        if (too_long($payValues['note'], 255)) {
            $payErrors['note'] = 'Keep the note under 255 characters.';
        }

        if (!$payErrors) {
            try {
                $remaining = db_transaction(function () use ($lockInvoice, $id, $amount, $payValues, $admin) {
                    $current = $lockInvoice();
                    if ($current['status'] === 'void') {
                        throw new UserFacingError('This invoice is void, so it cannot take payments.');
                    }
                    $owed = (int) $current['total'] - (int) $current['amount_paid'];
                    if ($amount > $owed) {
                        throw new UserFacingError('That is more than the ' . money($owed) . ' still owed.');
                    }
                    db_insert(
                        'INSERT INTO payments (invoice_id, amount, method, reference, note, paid_on, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        'iissssi',
                        [
                            $id,
                            $amount,
                            $payValues['method'],
                            $payValues['reference'] !== '' ? $payValues['reference'] : null,
                            $payValues['note'] !== '' ? $payValues['note'] : null,
                            $payValues['paid_on'],
                            $admin['id'],
                        ]
                    );
                    return $owed - $amount;
                });
                flash('success', money($amount) . ' recorded. ' . ($remaining > 0 ? money($remaining) . ' is still owed.' : invoice_number($id) . ' is now paid in full.'));
                redirect('admin/invoice.php?id=' . $id);
            } catch (UserFacingError $e) {
                $fresh = find_invoice($id);
                if ($fresh === null || invoice_balance($fresh) === 0) {
                    flash('error', $e->getMessage());
                    redirect('admin/invoice.php?id=' . $id);
                }
                $payErrors['amount'] = $e->getMessage();
            }
        }
    }

    if ($action === 'delete_payment') {
        $paymentId = (int) input('payment_id');
        try {
            $deleted = db_transaction(function () use ($lockInvoice, $id, $paymentId) {
                $lockInvoice();
                $payment = db_one('SELECT * FROM payments WHERE id = ? AND invoice_id = ?', 'ii', [$paymentId, $id]);
                if ($payment === null) {
                    throw new UserFacingError('That payment has already been removed.');
                }
                db_execute('DELETE FROM payments WHERE id = ?', 'i', [$paymentId]);
                return $payment;
            });
            flash('success', 'Payment of ' . money($deleted['amount']) . ' removed.');
        } catch (UserFacingError $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/invoice.php?id=' . $id);
    }

    if ($action === 'void') {
        $voidReason = input('void_reason');
        if ($voidReason === '' || too_long($voidReason, 255)) {
            $voidError = 'Say why this invoice is being voided.';
        } else {
            try {
                db_transaction(function () use ($lockInvoice, $id, $voidReason) {
                    $current = $lockInvoice();
                    if ($current['status'] === 'void') {
                        throw new UserFacingError('This invoice is already void.');
                    }
                    if ((int) $current['amount_paid'] > 0) {
                        throw new UserFacingError('Remove or refund the payments on this invoice before voiding it.');
                    }
                    db_execute(
                        "UPDATE invoices SET status = 'void', void_reason = ?, voided_at = NOW() WHERE id = ?",
                        'si',
                        [$voidReason, $id]
                    );
                });
                flash('success', invoice_number($id) . ' is void. The job card is unlocked so you can correct the work and invoice again.');
                redirect('admin/job.php?id=' . $invoice['job_id']);
            } catch (UserFacingError $e) {
                flash('error', $e->getMessage());
                redirect('admin/invoice.php?id=' . $id);
            }
        }
    }
}

$invoice = find_invoice($id);
$balance = invoice_balance($invoice);
$state = payment_state($invoice);
$payments = db_all(
    'SELECT p.*, a.full_name AS received_by_name, a.username AS received_by_username
     FROM payments p
     LEFT JOIN admin a ON a.id = p.received_by
     WHERE p.invoice_id = ?
     ORDER BY p.paid_on, p.id',
    'i',
    [$id]
);
$job = db_one('SELECT id, status, customer_id, booking_id FROM jobs WHERE id = ?', 'i', [(int) $invoice['job_id']]);
$doc = build_invoice_document($id);
$shareUrl = absolute_url(invoice_link($id));
$shareText = 'Hello ' . $invoice['bill_name'] . ', here is your invoice ' . invoice_number($id) . ' from ' . config('garage.name')
    . ' for ' . $invoice['vehicle_plate'] . ': ' . money($invoice['total']) . '.'
    . ($balance > 0 ? ' Balance due: ' . money($balance) . '.' : ' Paid in full, thank you.')
    . ' View it here: ' . $shareUrl;

$pageTitle = invoice_number($id);
$activeNav = 'invoices.php';
$withDocumentStyles = true;
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<p class="breadcrumb"><a href="<?= e(url('admin/invoices.php')) ?>">&larr; Invoices</a></p>
<div class="page-head">
  <div>
    <h1><?= e(invoice_number($id)) ?> &middot; <?= e($invoice['bill_name']) ?></h1>
    <p class="muted">Issued <?= e(format_datetime($invoice['issued_at'])) ?> for <a href="<?= e(url('admin/job.php?id=' . $invoice['job_id'])) ?>"><?= e(job_number($invoice['job_id'])) ?></a></p>
  </div>
  <div class="button-row">
    <?= status_badge($state, $states) ?>
    <a class="btn btn-dark btn-sm" href="<?= e(url('admin/print.php?doc=invoice&id=' . $id)) ?>" target="_blank" rel="noopener">Print or save as PDF</a>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?php render_document($doc); ?>
  </div>

  <div class="stack-lg">
    <section class="card">
      <h2>Balance</h2>
      <dl class="money-summary">
        <div><dt>Total</dt><dd><?= e(money($invoice['total'])) ?></dd></div>
        <div><dt>Paid</dt><dd><?= e(money($invoice['amount_paid'])) ?></dd></div>
        <div class="<?= $balance > 0 ? 'is-balance' : 'is-settled' ?>"><dt><?= $state === 'void' ? 'Void' : 'Still owed' ?></dt><dd><?= e(money($balance)) ?></dd></div>
      </dl>
    </section>

    <?php if ($state !== 'void' && $balance > 0): ?>
      <form method="post" class="card form-card" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay">
        <h2 class="card-title">Record a payment</h2>
        <?= error_summary($payErrors) ?>
        <div class="field-row">
          <div class="field">
            <label for="amount">Amount (<?= e(config('currency.code')) ?>)</label>
            <input id="amount" name="amount" type="text" inputmode="numeric" maxlength="14" required value="<?= e($payValues['amount']) ?>"<?= field_attrs($payErrors, 'amount') ?>>
            <?= field_error($payErrors, 'amount') ?>
          </div>
          <div class="field">
            <label for="paid_on">Date received</label>
            <input id="paid_on" name="paid_on" type="date" max="<?= e(date('Y-m-d')) ?>" required value="<?= e($payValues['paid_on']) ?>"<?= field_attrs($payErrors, 'paid_on') ?>>
            <?= field_error($payErrors, 'paid_on') ?>
          </div>
        </div>
        <div class="field">
          <span class="label">Method</span>
          <div class="choice-row"<?= field_attrs($payErrors, 'method') ?>>
            <?php foreach ($methods as $key => $label): ?>
              <label class="choice choice-inline">
                <input type="radio" name="method" value="<?= e($key) ?>"<?= checked($payValues['method'], $key) ?>>
                <span><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?= field_error($payErrors, 'method') ?>
        </div>
        <div class="field">
          <label for="reference">Transaction ID <span class="optional">required for mobile money and bank</span></label>
          <input id="reference" name="reference" type="text" maxlength="100" autocomplete="off" value="<?= e($payValues['reference']) ?>"<?= field_attrs($payErrors, 'reference') ?>>
          <?= field_error($payErrors, 'reference') ?>
        </div>
        <div class="field">
          <label for="note">Note <span class="optional">optional</span></label>
          <input id="note" name="note" type="text" maxlength="255" value="<?= e($payValues['note']) ?>"<?= field_attrs($payErrors, 'note') ?>>
          <?= field_error($payErrors, 'note') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block" data-busy="Recording...">Record payment</button>
      </form>
    <?php endif; ?>

    <section class="card">
      <h2>Payments</h2>
      <?php if (!$payments): ?>
        <p class="muted">No payments recorded yet.</p>
      <?php else: ?>
        <ul class="vehicle-list">
          <?php foreach ($payments as $payment): ?>
            <li>
              <strong><?= e(money($payment['amount'])) ?></strong>
              <span><?= e($methods[$payment['method']] ?? $payment['method']) ?> on <?= e(format_date($payment['paid_on'], 'j M Y')) ?><?= $payment['reference'] ? ', ref ' . e($payment['reference']) : '' ?></span>
              <span class="muted small">
                Recorded by <?= e(($payment['received_by_name'] ?: $payment['received_by_username']) ?: 'a former staff member') ?>
                <?= $payment['note'] ? ' &middot; ' . e($payment['note']) : '' ?>
              </span>
              <?php if ($state !== 'void'): ?>
                <form method="post" class="inline-form" data-confirm="Remove this payment of <?= e(money($payment['amount'])) ?>? Only do this if it was recorded by mistake or refunded.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_payment">
                  <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                  <button type="submit" class="item-remove">Remove</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($state !== 'void'): ?>
      <section class="card">
        <h2>Send to the customer</h2>
        <p class="muted small">Anyone with this link can view this invoice, so only send it to the customer.<?= $job && ($job['customer_id'] || $job['booking_id']) ? ' They can also see it from their account or booking page.' : '' ?></p>
        <div class="share-box">
          <a class="btn btn-primary btn-sm" href="<?= e(whatsapp_link($invoice['bill_phone'], $shareText)) ?>" target="_blank" rel="noopener">Send on WhatsApp</a>
          <button type="button" class="btn btn-outline btn-sm" data-copy="<?= e($shareUrl) ?>">Copy link</button>
          <?php if ($invoice['bill_email']): ?>
            <a class="btn btn-outline btn-sm" href="mailto:<?= e($invoice['bill_email']) ?>?subject=<?= e(rawurlencode('Invoice ' . invoice_number($id) . ' from ' . config('garage.name'))) ?>&amp;body=<?= e(rawurlencode($shareText)) ?>">Email</a>
          <?php endif; ?>
        </div>
      </section>

      <form method="post" class="card danger-zone" data-confirm="Void <?= e(invoice_number($id)) ?>? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="void">
        <h2>Void invoice</h2>
        <?php if ((int) $invoice['amount_paid'] > 0): ?>
          <p class="muted">This invoice has payments. Remove or refund them before voiding it.</p>
        <?php else: ?>
          <p class="muted">Use this if the invoice is wrong. The number is kept for your records, and the job card is unlocked so you can fix the work and invoice again.</p>
          <div class="field">
            <label for="void_reason">Reason</label>
            <input id="void_reason" name="void_reason" type="text" maxlength="255" required value="<?= e($voidReason) ?>"<?= $voidError !== '' ? ' aria-invalid="true"' : '' ?>>
            <?php if ($voidError !== ''): ?>
              <p class="field-error"><?= e($voidError) ?></p>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-ghost">Void invoice</button>
        <?php endif; ?>
      </form>
    <?php else: ?>
      <section class="card">
        <h2>Voided</h2>
        <p><?= e($invoice['void_reason']) ?></p>
        <p class="muted small">Voided <?= e(format_datetime($invoice['voided_at'])) ?>.</p>
        <a class="btn btn-outline btn-block" href="<?= e(url('admin/job.php?id=' . $invoice['job_id'])) ?>">Go to the job card</a>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
