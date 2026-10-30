<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$respond = function (bool $ok, array $messages, int $code = 200, string $status = '') use ($wantsJson): never {
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $statuses = job_statuses();
        echo json_encode([
            'ok' => $ok,
            'status' => $status,
            'label' => $statuses[$status]['label'] ?? '',
            'messages' => array_map(fn ($message) => ['type' => $message[0], 'text' => $message[1]], $messages),
        ]);
        exit;
    }
    foreach ($messages as [$type, $text]) {
        flash($type, $text);
    }
    $return = input('return');
    redirect(in_array($return, ['index.php', 'jobs.php'], true) ? 'admin/' . $return : 'admin/index.php');
};

if (!current_admin()) {
    if ($wantsJson) {
        $respond(false, [['error', 'Your session has ended. Please log in again.']], 401);
    }
    redirect('admin/login.php');
}

if (!is_post()) {
    redirect('admin/index.php');
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    $respond(false, [['error', 'This page is out of date. Refresh it and try again.']], 400);
}

$jobId = (int) input('job_id');
$status = input('status');

try {
    $messages = update_job_status($jobId, $status);
    $respond(true, $messages, 200, $status);
} catch (UserFacingError $e) {
    $respond(false, [['error', $e->getMessage()]], 422);
}
