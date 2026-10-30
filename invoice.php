<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/document.php';

$id = (int) query_param('id');
$token = query_param('t');
$customer = current_customer();
$invoice = $id > 0 ? find_invoice($id) : null;

if ($invoice === null || !can_view_invoice($invoice, $customer, $token)) {
    abort(404, 'Invoice not found', 'This invoice link is not valid, or you need to log in to see it. If you think this is wrong, call us on ' . format_phone(config('garage.phone')) . '.');
}

header('X-Robots-Tag: noindex, nofollow');

$doc = build_invoice_document($id);
$booking = db_value(
    'SELECT b.reference FROM jobs j JOIN bookings b ON b.id = j.booking_id WHERE j.id = ?',
    'i',
    [(int) $invoice['job_id']]
);

$actions = [];
if ($customer) {
    $actions[] = ['href' => url('account.php'), 'label' => 'My account'];
}
if ($booking && can_view_record(find_tracked_record($booking) ?? ['customer_id' => null, 'reference' => ''], $customer)) {
    $actions[] = ['href' => url('track.php?ref=' . rawurlencode($booking)), 'label' => 'My booking'];
}
$actions[] = ['href' => url('index.php'), 'label' => config('garage.name') . ' website'];

render_document_page($doc, 'Invoice ' . $doc['number'], $actions);
