<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/document.php';

require_admin();

$type = query_param('doc');
$id = (int) query_param('id');

if ($type === 'invoice') {
    $doc = build_invoice_document($id);
    $back = ['href' => url('admin/invoice.php?id=' . $id), 'label' => 'Back to invoice'];
} elseif (in_array($type, ['jobcard', 'estimate'], true)) {
    $doc = build_job_document($id, $type);
    $back = ['href' => url('admin/job.php?id=' . $id), 'label' => 'Back to job card'];
} else {
    abort(404, 'Document not found', 'Choose a job card, estimate or invoice to print.');
}

if ($doc === null) {
    abort(404, 'Document not found', 'That record does not exist.');
}

render_document_page($doc, $doc['title'] . ' ' . $doc['number'], [$back]);
