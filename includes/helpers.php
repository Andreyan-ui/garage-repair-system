<?php

const DUMMY_PASSWORD_HASH = '$2y$10$04OVsrM6uUQT/5cvXGRp3O551hJlq3lRIgqQyUk8BypYpY.fdbrvC';
const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

function config(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_connect(config('db.host'), config('db.user'), config('db.pass'), config('db.name'));
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = '" . date('P') . "'");
    }
    return $conn;
}

function db_run(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = mysqli_prepare(db(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function db_all(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_run($sql, $types, $params);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_one(string $sql, string $types = '', array $params = []): ?array
{
    $stmt = db_run($sql, $types, $params);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_value(string $sql, string $types = '', array $params = []): mixed
{
    $row = db_one($sql, $types, $params);
    return $row === null ? null : reset($row);
}

function db_execute(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_run($sql, $types, $params);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

function db_insert(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_run($sql, $types, $params);
    $id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
    return $id;
}

function is_duplicate_key(Throwable $e, string $keyName): bool
{
    return $e instanceof mysqli_sql_exception
        && $e->getCode() === 1062
        && str_contains($e->getMessage(), $keyName);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = '';
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $file = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($root !== '' && $file !== '' && stripos($file, $root) === 0) {
        $relative = substr($file, strlen($root));
        $tail = substr($script, -strlen($relative));
        if ($relative !== '' && strcasecmp($tail, $relative) === 0) {
            $base = rtrim(substr($script, 0, -strlen($relative)), '/');
        }
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file) ? filemtime($file) : 0;
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    return url($encoded) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query_param(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function too_long(string $value, int $max): bool
{
    return mb_strlen($value) > $max;
}

function render_error_page(int $code, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    $name = e(config('garage.name'));
    $home = e(url('index.php'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | ' . $name . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('css/style.css')) . '"></head>'
        . '<body class="error-page"><main class="container narrow"><div class="card error-card">'
        . '<p class="eyebrow">Error ' . $code . '</p><h1>' . e($title) . '</h1>'
        . '<p class="error-message">' . nl2br(e($message)) . '</p>'
        . '<a class="btn btn-dark" href="' . $home . '">Back to ' . $name . '</a>'
        . '</div></main></body></html>';
}

function abort(int $code, string $title, string $message = ''): never
{
    render_error_page($code, $title, $message);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        abort(400, 'Your session expired', 'For your security this form could not be sent. Please go back, refresh the page and try again.');
    }
}

function honeypot_field(): string
{
    return '<div class="hp" aria-hidden="true"><label>Leave this field empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
}

function honeypot_tripped(): bool
{
    return input('website') !== '';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function field_error(array $errors, string $field): string
{
    if (!isset($errors[$field])) {
        return '';
    }
    return '<p class="field-error" id="' . e($field) . '-error">' . e($errors[$field]) . '</p>';
}

function field_attrs(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . e($field) . '-error"' : '';
}

function error_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $count = count($errors);
    return '<div class="alert alert-error" role="alert">Please fix the ' . ($count === 1 ? 'problem' : $count . ' problems') . ' highlighted below.</div>';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' checked' : '';
}

function normalize_phone(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\+?[0-9\s().-]+$/', $raw)) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $raw);
    $countryCode = (string) config('phone.country_code');
    $localLength = (int) config('phone.local_length');
    $international = str_starts_with($raw, '+');

    if (!$international && str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
        $international = true;
    }

    if (!$international) {
        if (strlen($digits) === $localLength + 1 && $digits[0] === '0') {
            $digits = $countryCode . substr($digits, 1);
        } elseif (strlen($digits) === $localLength && $digits[0] !== '0') {
            $digits = $countryCode . $digits;
        } elseif (!str_starts_with($digits, $countryCode)) {
            return null;
        }
    }

    if ($digits === '' || $digits[0] === '0') {
        return null;
    }
    if (str_starts_with($digits, $countryCode)) {
        $local = substr($digits, strlen($countryCode));
        return strlen($local) === $localLength && $local[0] !== '0' ? '+' . $digits : null;
    }
    return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
}

function format_phone(?string $phone): string
{
    if ($phone === null || $phone === '') {
        return '';
    }
    $countryCode = preg_quote((string) config('phone.country_code'), '/');
    if (preg_match('/^\+(' . $countryCode . ')(\d{3})(\d{3})(\d{3})$/', $phone, $m)) {
        return '+' . $m[1] . ' ' . $m[2] . ' ' . $m[3] . ' ' . $m[4];
    }
    return $phone;
}

function tel_link(?string $phone): string
{
    return 'tel:' . preg_replace('/[^\d+]/', '', (string) $phone);
}

function whatsapp_link(?string $phone, string $text = ''): string
{
    $link = 'https://wa.me/' . preg_replace('/\D/', '', (string) $phone);
    return $text === '' ? $link : $link . '?text=' . rawurlencode($text);
}

function map_link(?string $latitude, ?string $longitude, string $fallback = ''): string
{
    $query = ($latitude !== null && $longitude !== null)
        ? rtrim(rtrim($latitude, '0'), '.') . ',' . rtrim(rtrim($longitude, '0'), '.')
        : $fallback;
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

function normalize_plate(string $raw): ?string
{
    $plate = strtoupper(preg_replace('/\s+/', ' ', trim($raw)));
    return preg_match('/^[A-Z0-9][A-Z0-9 -]{1,13}[A-Z0-9]$/', $plate) ? $plate : null;
}

function normalize_email(string $raw): ?string
{
    $email = strtolower(trim($raw));
    return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150 ? $email : null;
}

function generate_reference(string $prefix): string
{
    $code = '';
    $max = strlen(REFERENCE_ALPHABET) - 1;
    for ($i = 0; $i < 8; $i++) {
        $code .= REFERENCE_ALPHABET[random_int(0, $max)];
    }
    return $prefix . '-' . $code;
}

function normalize_reference(string $raw): ?string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    if (!preg_match('/^(BK|BD)([' . REFERENCE_ALPHABET . ']{8})$/', $clean, $m)) {
        return null;
    }
    return $m[1] . '-' . $m[2];
}

function insert_with_reference(string $prefix, string $table, array $columns): array
{
    $names = array_keys($columns);
    $sql = 'INSERT INTO `' . $table . '` (`reference`, `' . implode('`, `', $names) . '`) VALUES (?' . str_repeat(', ?', count($names)) . ')';
    $types = 's' . implode('', array_map(fn ($column) => $column[0], $columns));
    $values = array_map(fn ($column) => $column[1], array_values($columns));

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = generate_reference($prefix);
        try {
            $id = db_insert($sql, $types, array_merge([$reference], $values));
            return [$id, $reference];
        } catch (mysqli_sql_exception $e) {
            if (!is_duplicate_key($e, 'reference')) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique reference number.');
}

function remember_reference(string $reference): void
{
    $references = $_SESSION['tracked_refs'] ?? [];
    if (!in_array($reference, $references, true)) {
        $references[] = $reference;
    }
    $_SESSION['tracked_refs'] = array_slice($references, -20);
}

function find_tracked_record(string $reference): ?array
{
    if (str_starts_with($reference, 'BK-')) {
        $row = db_one(
            'SELECT b.*, s.name AS service_name
             FROM bookings b
             JOIN services s ON s.id = b.service_id
             WHERE b.reference = ?',
            's',
            [$reference]
        );
        return $row ? $row + ['kind' => 'booking'] : null;
    }
    if (str_starts_with($reference, 'BD-')) {
        $row = db_one('SELECT * FROM breakdown_requests WHERE reference = ?', 's', [$reference]);
        return $row ? $row + ['kind' => 'breakdown'] : null;
    }
    return null;
}

function can_view_record(array $record, ?array $customer): bool
{
    if ($customer !== null && $record['customer_id'] !== null && (int) $record['customer_id'] === (int) $customer['id']) {
        return true;
    }
    return in_array($record['reference'], $_SESSION['tracked_refs'] ?? [], true);
}

function current_customer(): ?array
{
    static $loaded = false;
    static $customer = null;
    if (!$loaded) {
        $loaded = true;
        if (!empty($_SESSION['customer_id'])) {
            $customer = db_one(
                'SELECT id, full_name, phone, email, created_at FROM customers WHERE id = ?',
                'i',
                [(int) $_SESSION['customer_id']]
            );
            if ($customer === null) {
                unset($_SESSION['customer_id']);
            }
        }
    }
    return $customer;
}

function require_customer(): array
{
    $customer = current_customer();
    if ($customer === null) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'account.php');
        if (!empty($_SERVER['QUERY_STRING'])) {
            $next .= '?' . $_SERVER['QUERY_STRING'];
        }
        flash('info', 'Please log in to continue.');
        redirect('login.php?next=' . rawurlencode($next));
    }
    return $customer;
}

function login_customer(int $customerId): void
{
    session_regenerate_id(true);
    $_SESSION['customer_id'] = $customerId;
}

function logout_customer(): void
{
    unset($_SESSION['customer_id'], $_SESSION['tracked_refs']);
    session_regenerate_id(true);
}

function current_admin(): ?array
{
    static $loaded = false;
    static $admin = null;
    if (!$loaded) {
        $loaded = true;
        if (!empty($_SESSION['admin_id'])) {
            $admin = db_one('SELECT id, username, full_name FROM admin WHERE id = ? AND is_active = 1', 'i', [(int) $_SESSION['admin_id']]);
            if ($admin === null) {
                unset($_SESSION['admin_id']);
            }
        }
    }
    return $admin;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect('admin/login.php');
    }
    return $admin;
}

function login_admin(int $adminId): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
}

function logout_admin(): void
{
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
}

function safe_next(string $next, string $fallback): string
{
    return preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $next) ? $next : $fallback;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function too_many_attempts(string $scope, string $identifier, ?int $limit = null): bool
{
    $window = (int) config('security.attempt_window_minutes');
    $limit ??= (int) config('security.max_attempts');
    $forIdentifier = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE scope = ? AND identifier = ? AND ip_address = ? AND attempted_at > NOW() - INTERVAL ? MINUTE',
        'sssi',
        [$scope, $identifier, client_ip(), $window]
    );
    if ($forIdentifier >= $limit) {
        return true;
    }
    $forIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE scope = ? AND ip_address = ? AND attempted_at > NOW() - INTERVAL ? MINUTE',
        'ssi',
        [$scope, client_ip(), $window]
    );
    return $forIp >= (int) config('security.max_attempts_per_ip');
}

function record_failed_attempt(string $scope, string $identifier): void
{
    db_execute('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    db_insert(
        'INSERT INTO login_attempts (scope, identifier, ip_address) VALUES (?, ?, ?)',
        'sss',
        [$scope, mb_substr($identifier, 0, 190), client_ip()]
    );
}

function clear_attempts(string $scope, string $identifier): void
{
    db_execute(
        'DELETE FROM login_attempts WHERE scope = ? AND identifier = ? AND ip_address = ?',
        'sss',
        [$scope, mb_substr($identifier, 0, 190), client_ip()]
    );
}

function validate_new_password(string $password, string $confirmation): ?string
{
    $min = (int) config('security.min_password_length');
    if (mb_strlen($password) < $min) {
        return 'Use at least ' . $min . ' characters.';
    }
    if (strlen($password) > 72) {
        return 'Use 72 characters or fewer.';
    }
    if ($password !== $confirmation) {
        return 'The two passwords do not match.';
    }
    return null;
}

function booking_statuses(): array
{
    return [
        'pending' => ['label' => 'Awaiting confirmation', 'short' => 'Pending', 'tone' => 'amber'],
        'confirmed' => ['label' => 'Confirmed', 'short' => 'Confirmed', 'tone' => 'blue'],
        'in_progress' => ['label' => 'In the workshop', 'short' => 'In progress', 'tone' => 'purple'],
        'completed' => ['label' => 'Completed', 'short' => 'Completed', 'tone' => 'green'],
        'cancelled' => ['label' => 'Cancelled', 'short' => 'Cancelled', 'tone' => 'gray'],
    ];
}

function breakdown_statuses(): array
{
    return [
        'new' => ['label' => 'Request received', 'short' => 'New', 'tone' => 'red'],
        'dispatched' => ['label' => 'Help is on the way', 'short' => 'Dispatched', 'tone' => 'amber'],
        'on_site' => ['label' => 'Mechanic is with you', 'short' => 'On site', 'tone' => 'blue'],
        'completed' => ['label' => 'Resolved', 'short' => 'Resolved', 'tone' => 'green'],
        'cancelled' => ['label' => 'Cancelled', 'short' => 'Cancelled', 'tone' => 'gray'],
    ];
}

function status_badge(string $status, array $statuses, bool $short = false): string
{
    $info = $statuses[$status] ?? ['label' => $status, 'short' => $status, 'tone' => 'gray'];
    return '<span class="badge badge-' . e($info['tone']) . '">' . e($short ? $info['short'] : $info['label']) . '</span>';
}

function problem_types(): array
{
    return [
        'wont_start' => "Won't start",
        'battery' => 'Flat battery',
        'flat_tyre' => 'Flat tyre',
        'overheating' => 'Overheating',
        'out_of_fuel' => 'Out of fuel',
        'locked_out' => 'Locked out or lost key',
        'accident' => 'Accident',
        'other' => 'Something else',
    ];
}

function towing_options(): array
{
    return [
        'unsure' => 'Not sure',
        'yes' => 'Yes, it needs towing',
        'no' => 'No, fix it where it is',
    ];
}

function faq_categories(): array
{
    return [
        'safety' => 'Safety first',
        'starting' => 'Starting and battery',
        'tyres' => 'Tyres and wheels',
        'warning_lights' => 'Warning lights',
        'engine' => 'Engine and fluids',
        'electrical' => 'Lights and electrical',
    ];
}

function booking_slots(): array
{
    return config('booking.slots');
}

function closed_days_hint(): string
{
    $names = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
    $closed = array_map(fn ($day) => $names[$day] ?? '', config('booking.closed_days'));
    $closed = array_values(array_filter($closed));
    if (!$closed) {
        return '';
    }
    $last = array_pop($closed);
    return 'We are closed on ' . ($closed ? implode(', ', $closed) . ' and ' : '') . $last . '.';
}

function slot_label(string $slot): string
{
    $slots = booking_slots();
    return isset($slots[$slot]) ? $slots[$slot]['label'] . ' (' . $slots[$slot]['hours'] . ')' : $slot;
}

function validate_booking_date(string $date, string $slot): ?string
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date) {
        return 'Choose the day you would like to bring your car in.';
    }
    $today = new DateTimeImmutable('today');
    $maxDays = (int) config('booking.max_days_ahead');
    if ($day < $today) {
        return 'Choose today or a later date.';
    }
    if ($day > $today->modify('+' . $maxDays . ' days')) {
        return 'Bookings can be made up to ' . $maxDays . ' days ahead.';
    }
    if (in_array((int) $day->format('w'), config('booking.closed_days'), true)) {
        return 'We are closed on ' . $day->format('l') . 's. Please choose another day.';
    }
    $slots = booking_slots();
    if ($day == $today && isset($slots[$slot]) && (int) date('G') >= (int) $slots[$slot]['ends_at_hour']) {
        return 'That time has already passed today. Please choose a later slot or another day.';
    }
    return null;
}

function parse_coordinates(string $latitude, string $longitude, string $accuracy): array
{
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return [null, null, null];
    }
    $lat = (float) $latitude;
    $lng = (float) $longitude;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
        return [null, null, null];
    }
    $acc = is_numeric($accuracy) ? max(0, min(100000, (int) round((float) $accuracy))) : null;
    return [round($lat, 7), round($lng, 7), $acc];
}

function format_date(?string $date, string $format = 'D j M Y'): string
{
    return $date ? date($format, strtotime($date)) : '';
}

function format_datetime(?string $dateTime): string
{
    return $dateTime ? date('j M Y, g:ia', strtotime($dateTime)) : '';
}

function format_time(?string $dateTime): string
{
    return $dateTime ? date('g:ia', strtotime($dateTime)) : '';
}

function time_ago(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }
    $seconds = time() - strtotime($dateTime);
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        $hours = (int) floor($seconds / 3600);
        return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    }
    return format_datetime($dateTime);
}

function minutes_until(?string $dateTime): ?int
{
    if (!$dateTime) {
        return null;
    }
    return (int) ceil((strtotime($dateTime) - time()) / 60);
}

function relative_day(string $date): string
{
    $today = date('Y-m-d');
    if ($date === $today) {
        return 'Today';
    }
    if ($date === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow';
    }
    if ($date === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return format_date($date);
}

function render_steps(string $text): string
{
    $html = '';
    $listTag = null;
    $items = [];
    $flush = function () use (&$html, &$listTag, &$items): void {
        if ($listTag !== null) {
            $html .= '<' . $listTag . '>' . implode('', $items) . '</' . $listTag . '>';
        }
        $listTag = null;
        $items = [];
    };

    foreach (preg_split('/\R/', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $m)) {
            $tag = 'ol';
        } elseif (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
            $tag = 'ul';
        } else {
            $flush();
            $html .= '<p>' . e($line) . '</p>';
            continue;
        }
        if ($listTag !== $tag) {
            $flush();
            $listTag = $tag;
        }
        $items[] = '<li>' . e($m[1]) . '</li>';
    }
    $flush();
    return $html;
}

function progress_steps(array $steps, string $current): string
{
    $keys = array_keys($steps);
    $position = array_search($current, $keys, true);
    $html = '<ol class="progress">';
    foreach ($keys as $index => $key) {
        $state = 'upcoming';
        if ($position !== false && $index < $position) {
            $state = 'done';
        } elseif ($index === $position) {
            $state = $index === count($keys) - 1 ? 'done' : 'current';
        }
        $aria = $index === $position ? ' aria-current="step"' : '';
        $html .= '<li class="progress-step is-' . $state . '"' . $aria . '><span class="progress-dot"></span><span class="progress-label">' . e($steps[$key]) . '</span></li>';
    }
    return $html . '</ol>';
}

function vehicle_label(array $vehicle): string
{
    $parts = array_filter([
        $vehicle['colour'] ?? null,
        $vehicle['make'] ?? null,
        $vehicle['model'] ?? null,
    ]);
    return trim(implode(' ', $parts));
}

function job_vehicle_label(array $job): string
{
    $label = trim(implode(' ', array_filter([
        $job['vehicle_colour'] ?? null,
        $job['vehicle_make'] ?? null,
        $job['vehicle_model'] ?? null,
    ])));
    return $label . (!empty($job['vehicle_year']) ? ' (' . $job['vehicle_year'] . ')' : '');
}

class UserFacingError extends RuntimeException
{
}

function db_transaction(callable $callback): mixed
{
    $conn = db();
    mysqli_begin_transaction($conn);
    try {
        $result = $callback();
        mysqli_commit($conn);
        return $result;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

function money(mixed $amount): string
{
    return config('currency.code') . ' ' . number_format((float) $amount, 0, '.', ',');
}

function parse_money(string $raw): ?int
{
    $clean = preg_replace('/[\s,]/', '', str_ireplace((string) config('currency.code'), '', $raw));
    if ($clean === '' || !preg_match('/^\d{1,10}$/', $clean)) {
        return null;
    }
    $value = (int) $clean;
    return $value <= 2000000000 ? $value : null;
}

function parse_quantity(string $raw, bool $allowZero = false): ?float
{
    $clean = str_replace(',', '', trim($raw));
    if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $clean)) {
        return null;
    }
    $value = (float) $clean;
    if ($value > 1000000 || (!$allowZero && $value <= 0)) {
        return null;
    }
    return $value;
}

function format_quantity(mixed $quantity): string
{
    $formatted = number_format((float) $quantity, 2, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function unit_label(mixed $quantity, string $unit): string
{
    return abs((float) $quantity) === 1.0 || $unit === 'kg' ? $unit : $unit . 's';
}

function quantity_label(mixed $quantity, string $unit): string
{
    return format_quantity($quantity) . ' ' . unit_label($quantity, $unit);
}

function line_total(mixed $quantity, mixed $unitPrice): int
{
    return (int) round((float) $quantity * (int) $unitPrice);
}

function calculate_totals(array $items, int $discount = 0, ?float $taxRate = null): array
{
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += line_total($item['quantity'], $item['unit_price']);
    }
    $discount = max(0, min($discount, $subtotal));
    $taxRate ??= (float) config('invoice.tax_rate');
    $tax = (int) round(($subtotal - $discount) * $taxRate / 100);
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax_rate' => $taxRate,
        'tax' => $tax,
        'total' => $subtotal - $discount + $tax,
    ];
}

function job_number(mixed $id): string
{
    return 'JC-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function invoice_number(mixed $id): string
{
    return 'INV-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function parse_document_number(string $raw, string $prefix): ?int
{
    if (preg_match('/^\s*(?:' . preg_quote($prefix, '/') . ')?[\s-]*0*(\d{1,9})\s*$/i', $raw, $m)) {
        return (int) $m[1];
    }
    return null;
}

function job_statuses(): array
{
    return [
        'checked_in' => ['label' => 'Checked in', 'short' => 'Checked in', 'tone' => 'blue', 'customer' => 'Your car has been checked in and is waiting for a mechanic.'],
        'in_progress' => ['label' => 'Being worked on', 'short' => 'In progress', 'tone' => 'purple', 'customer' => 'Our mechanics are working on your car.'],
        'awaiting_parts' => ['label' => 'Waiting for parts', 'short' => 'Needs parts', 'tone' => 'amber', 'customer' => 'We are waiting for parts to arrive before we can finish.'],
        'awaiting_approval' => ['label' => 'Waiting for customer approval', 'short' => 'Needs approval', 'tone' => 'amber', 'customer' => 'We need your go-ahead before we continue. Please call us.'],
        'ready' => ['label' => 'Ready for collection', 'short' => 'Ready', 'tone' => 'green', 'customer' => 'Your car is ready for collection.'],
        'collected' => ['label' => 'Collected', 'short' => 'Collected', 'tone' => 'gray', 'customer' => 'You have collected your car. Thank you.'],
        'cancelled' => ['label' => 'Cancelled', 'short' => 'Cancelled', 'tone' => 'gray', 'customer' => 'This workshop visit was cancelled.'],
    ];
}

function open_job_statuses(): array
{
    return ['checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval', 'ready'];
}

function fuel_levels(): array
{
    return [
        'empty' => 'Empty',
        'quarter' => 'Quarter',
        'half' => 'Half',
        'three_quarters' => 'Three quarters',
        'full' => 'Full',
    ];
}

function item_types(): array
{
    return [
        'labour' => 'Labour',
        'part' => 'Part from stock',
        'other' => 'Other item',
    ];
}

function part_units(): array
{
    return ['piece', 'set', 'pair', 'litre', 'bottle', 'can', 'metre', 'kg'];
}

function payment_methods(): array
{
    return [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile money',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
    ];
}

function movement_reasons(): array
{
    return [
        'opening' => 'Opening stock',
        'purchase' => 'Stock received',
        'job_use' => 'Used on job',
        'job_return' => 'Returned from job',
        'adjustment' => 'Stock count correction',
    ];
}

function invoice_states(): array
{
    return [
        'unpaid' => ['label' => 'Unpaid', 'short' => 'Unpaid', 'tone' => 'red'],
        'partly_paid' => ['label' => 'Part paid', 'short' => 'Part paid', 'tone' => 'amber'],
        'paid' => ['label' => 'Paid in full', 'short' => 'Paid', 'tone' => 'green'],
        'void' => ['label' => 'Void', 'short' => 'Void', 'tone' => 'gray'],
    ];
}

function payment_state(array $invoice): string
{
    if ($invoice['status'] === 'void') {
        return 'void';
    }
    $paid = (int) $invoice['amount_paid'];
    $total = (int) $invoice['total'];
    if ($total <= 0 || $paid >= $total) {
        return 'paid';
    }
    return $paid > 0 ? 'partly_paid' : 'unpaid';
}

function invoice_balance(array $invoice): int
{
    return $invoice['status'] === 'void' ? 0 : max(0, (int) $invoice['total'] - (int) $invoice['amount_paid']);
}

function invoice_query(): string
{
    return 'SELECT i.*, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS amount_paid FROM invoices i';
}

function find_invoice(int $invoiceId): ?array
{
    return db_one(invoice_query() . ' WHERE i.id = ?', 'i', [$invoiceId]);
}

function active_invoice_for_job(int $jobId): ?array
{
    return db_one(invoice_query() . " WHERE i.job_id = ? AND i.status = 'issued' ORDER BY i.id DESC LIMIT 1", 'i', [$jobId]);
}

function stock_state(array $part): string
{
    $quantity = (float) $part['stock_quantity'];
    if ($quantity <= 0) {
        return 'out';
    }
    return $quantity <= (float) $part['reorder_level'] ? 'low' : 'ok';
}

function stock_badge(array $part): string
{
    return match (stock_state($part)) {
        'out' => '<span class="badge badge-red">Out of stock</span>',
        'low' => '<span class="badge badge-amber">Low stock</span>',
        default => '<span class="badge badge-green">In stock</span>',
    };
}

function change_stock(int $partId, float $change, string $reason, ?int $jobId = null, ?string $note = null, ?int $unitCost = null): void
{
    db_execute('UPDATE parts SET stock_quantity = stock_quantity + ? WHERE id = ?', 'di', [$change, $partId]);
    db_insert(
        'INSERT INTO stock_movements (part_id, quantity_change, reason, job_id, unit_cost, note, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'idsiisi',
        [$partId, $change, $reason, $jobId, $unitCost, $note, current_admin()['id'] ?? null]
    );
}

function sync_booking_with_job(mixed $bookingId, string $jobStatus): void
{
    if (!$bookingId) {
        return;
    }
    $bookingStatus = match ($jobStatus) {
        'checked_in', 'in_progress', 'awaiting_parts', 'awaiting_approval' => 'in_progress',
        'ready', 'collected' => 'completed',
        default => null,
    };
    if ($bookingStatus !== null) {
        db_execute(
            'UPDATE bookings SET status = ?, cancelled_by = NULL WHERE id = ? AND status <> ?',
            'sis',
            [$bookingStatus, (int) $bookingId, $bookingStatus]
        );
    }
}

function active_mechanics(mixed $includeId = null): array
{
    return db_all(
        'SELECT id, full_name, phone, specialty, is_active FROM mechanics WHERE is_active = 1 OR id = ? ORDER BY full_name',
        'i',
        [(int) $includeId]
    );
}

function invoice_share_token(int $invoiceId): string
{
    return substr(hash_hmac('sha256', 'invoice:' . $invoiceId, (string) config('app_key')), 0, 32);
}

function absolute_url(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function invoice_link(int $invoiceId): string
{
    return 'invoice.php?id=' . $invoiceId . '&t=' . invoice_share_token($invoiceId);
}

function can_view_invoice(array $invoice, ?array $customer, string $token = ''): bool
{
    if ($token !== '' && hash_equals(invoice_share_token((int) $invoice['id']), $token)) {
        return true;
    }
    $job = db_one(
        'SELECT j.customer_id, b.reference AS booking_reference
         FROM jobs j
         LEFT JOIN bookings b ON b.id = j.booking_id
         WHERE j.id = ?',
        'i',
        [(int) $invoice['job_id']]
    );
    if ($job === null) {
        return false;
    }
    if ($customer !== null && $job['customer_id'] !== null && (int) $job['customer_id'] === (int) $customer['id']) {
        return true;
    }
    return $job['booking_reference'] !== null
        && in_array($job['booking_reference'], $_SESSION['tracked_refs'] ?? [], true);
}

function month_bounds(string $month): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$start || $start->format('Y-m') !== $month) {
        $start = new DateTimeImmutable('first day of this month midnight');
    }
    return [$start, $start->modify('first day of next month')];
}

function gallery_images(): array
{
    static $images = null;
    if ($images === null) {
        $images = [];
        foreach (glob(dirname(__DIR__) . '/images/*.*') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match('/\.(jpe?g|png|webp)$/i', $name) || preg_match('/logo|icon/i', $name)) {
                continue;
            }
            $images[] = $name;
        }
        natcasesort($images);
        $images = array_values($images);
    }
    return $images;
}

function safe_image(?string $image, string $fallback = 'garage1.jpg'): string
{
    $name = basename((string) $image);
    return in_array($name, gallery_images(), true) ? $name : $fallback;
}

function image_size(string $file): array
{
    static $sizes = [];
    if (!isset($sizes[$file])) {
        $path = dirname(__DIR__) . '/images/' . $file;
        $info = is_file($path) ? getimagesize($path) : false;
        $sizes[$file] = $info ? [(int) $info[0], (int) $info[1]] : [720, 480];
    }
    return $sizes[$file];
}

function photo(string $file, string $alt, string $class = '', bool $eager = false, string $sizes = ''): string
{
    $file = basename($file);
    [$width, $height] = image_size($file);
    $html = '<img src="' . e(asset('images/' . $file)) . '" alt="' . e($alt) . '" width="' . $width . '" height="' . $height . '"';
    $html .= $eager ? ' fetchpriority="high"' : ' loading="lazy"';
    $html .= ' decoding="async"';
    if ($sizes !== '') {
        $html .= ' sizes="' . e($sizes) . '"';
    }
    if ($class !== '') {
        $html .= ' class="' . e($class) . '"';
    }
    return $html . '>';
}

function update_job_status(int $jobId, string $newStatus, ?string $customerNote = null): array
{
    $statuses = job_statuses();
    if (!isset($statuses[$newStatus])) {
        throw new UserFacingError('Choose a valid status.');
    }
    db_transaction(function () use ($jobId, $newStatus, $customerNote) {
        $current = db_one('SELECT * FROM jobs WHERE id = ? FOR UPDATE', 'i', [$jobId]);
        if ($current === null) {
            throw new UserFacingError('This job card no longer exists.');
        }
        $now = date('Y-m-d H:i:s');
        $readyAt = in_array($newStatus, ['ready', 'collected'], true) ? ($current['ready_at'] ?? $now) : null;
        $collectedAt = $newStatus === 'collected' ? ($current['collected_at'] ?? $now) : null;
        $note = $customerNote === null ? $current['customer_note'] : ($customerNote !== '' ? $customerNote : null);
        db_execute(
            'UPDATE jobs SET status = ?, customer_note = ?, ready_at = ?, collected_at = ? WHERE id = ?',
            'ssssi',
            [$newStatus, $note, $readyAt, $collectedAt, $jobId]
        );
        sync_booking_with_job($current['booking_id'], $newStatus);
    });

    $messages = [['success', job_number($jobId) . ' is now "' . $statuses[$newStatus]['label'] . '".']];
    $invoice = active_invoice_for_job($jobId);
    $itemCount = (int) db_value('SELECT COUNT(*) FROM job_items WHERE job_id = ?', 'i', [$jobId]);
    if ($newStatus === 'collected' && $invoice && invoice_balance($invoice) > 0) {
        $messages[] = ['error', 'The car was collected with ' . money(invoice_balance($invoice)) . ' still owed on ' . invoice_number($invoice['id']) . '.'];
    } elseif (in_array($newStatus, ['ready', 'collected'], true) && !$invoice && $itemCount > 0) {
        $messages[] = ['info', 'Remember to create the invoice before the customer collects the car.'];
    } elseif ($newStatus === 'cancelled' && (int) db_value("SELECT COUNT(*) FROM job_items WHERE job_id = ? AND item_type = 'part'", 'i', [$jobId]) > 0) {
        $messages[] = ['info', 'Parts on this job are still counted as used. Remove any that were not fitted to return them to stock.'];
    }
    return $messages;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
}

function greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        return 'Good morning';
    }
    return $hour < 17 ? 'Good afternoon' : 'Good evening';
}

function compact_money(mixed $amount): string
{
    $value = (float) $amount;
    if ($value >= 1000000) {
        return config('currency.code') . ' ' . rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.') . 'M';
    }
    if ($value >= 10000) {
        return config('currency.code') . ' ' . number_format($value / 1000) . 'K';
    }
    return money($value);
}
