<?php

require __DIR__ . '/helpers.php';
require __DIR__ . '/icons.php';

date_default_timezone_set(config('timezone'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);
    $message = config('debug')
        ? (string) $e
        : 'Please try again in a moment. If this keeps happening, call us on ' . format_phone(config('garage.phone')) . '.';
    render_error_page(500, 'Something went wrong', $message);
});

session_name('garage_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => base_path() . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
