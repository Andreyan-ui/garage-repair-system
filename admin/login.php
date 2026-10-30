<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_admin()) {
    redirect('admin/index.php');
}

$username = '';
$error = '';

if (is_post()) {
    verify_csrf();
    $username = input('username');
    $password = (string) ($_POST['password'] ?? '');
    $throttleKey = strtolower($username);

    if (too_many_attempts('admin', $throttleKey)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $admin = db_one('SELECT id, password_hash FROM admin WHERE username = ? AND is_active = 1', 's', [$username]);
        $valid = password_verify($password, $admin['password_hash'] ?? DUMMY_PASSWORD_HASH);
        if ($admin !== null && $valid) {
            clear_attempts('admin', $throttleKey);
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                db_execute('UPDATE admin SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            }
            login_admin((int) $admin['id']);
            redirect('admin/index.php');
        }
        record_failed_attempt('admin', $throttleKey);
        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Staff login | <?= e(config('garage.name')) ?></title>
<link rel="icon" href="<?= e(asset('images/brand/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('images/brand/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin">
<main class="login-page">
  <form method="post" class="card form-card login-card">
    <?= csrf_field() ?>
    <a class="brand" href="<?= e(url('index.php')) ?>">
      <img src="<?= e(asset('images/brand/wycliffe-logo-light.svg')) ?>" alt="<?= e(config('garage.full_name')) ?> website" width="128" height="153">
    </a>
    <h1 class="center card-title">Staff login</h1>
    <p class="center muted"><?= e(config('garage.full_name')) ?></p>
    <?php if ($error !== ''): ?>
      <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required value="<?= e($username) ?>">
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-dark btn-lg btn-block">Log in</button>
    <p class="center small"><a href="<?= e(url('index.php')) ?>">&larr; Back to the website</a></p>
  </form>
</main>
</body>
</html>
