<?php
require __DIR__ . '/includes/bootstrap.php';

$next = safe_next(query_param('next', input('next')), 'account.php');
if (current_customer()) {
    redirect($next);
}

$identifier = '';
$error = '';

if (is_post()) {
    verify_csrf();
    $identifier = input('identifier');
    $password = (string) ($_POST['password'] ?? '');
    $throttleKey = strtolower($identifier);

    if (too_many_attempts('customer', $throttleKey)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $customer = null;
        if (str_contains($identifier, '@')) {
            $email = normalize_email($identifier);
            if ($email !== null) {
                $customer = db_one('SELECT id, full_name, password_hash FROM customers WHERE email = ?', 's', [$email]);
            }
        } else {
            $phone = normalize_phone($identifier);
            if ($phone !== null) {
                $customer = db_one('SELECT id, full_name, password_hash FROM customers WHERE phone = ?', 's', [$phone]);
            }
        }

        $valid = password_verify($password, $customer['password_hash'] ?? DUMMY_PASSWORD_HASH);
        if ($customer !== null && $valid) {
            clear_attempts('customer', $throttleKey);
            if (password_needs_rehash($customer['password_hash'], PASSWORD_DEFAULT)) {
                db_execute('UPDATE customers SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $customer['id']]);
            }
            login_customer((int) $customer['id']);
            flash('success', 'Welcome back, ' . $customer['full_name'] . '.');
            redirect($next);
        }

        record_failed_attempt('customer', $throttleKey);
        $error = 'That phone number or email and password do not match our records.';
    }
}

$pageTitle = 'Log in';
$activeNav = 'login.php';
require __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
  <div class="container auth-split">
    <div class="auth-panel">
    <div class="auth-head">
      <h1>Log in to your account</h1>
      <p class="muted">See your bookings, help requests and saved vehicles.</p>
    </div>
    <form method="post" class="card form-card">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <div class="field">
        <label for="identifier">Phone number or email</label>
        <input id="identifier" name="identifier" type="text" maxlength="150" autocomplete="username" required value="<?= e($identifier) ?>">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-dark btn-lg btn-block">Log in</button>
      <p class="hint center">Forgot your password? Call us on <?= e(format_phone(config('garage.phone'))) ?> and we will help you get back in.</p>
    </form>
    <p class="center">New here? <a href="<?= e(url('register.php?next=' . rawurlencode($next))) ?>">Create an account</a></p>
    <p class="center muted">You can also <a href="<?= e(url('book.php')) ?>">book without an account</a> and <a href="<?= e(url('track.php')) ?>">track it with your reference</a>.</p>
    </div>
    <figure class="auth-photo">
      <?= photo('repair4.jpg', 'A mechanic servicing a car') ?>
      <figcaption>
        <strong>Everything about your car in one place</strong>
        <span><?= icon('check') ?> Bookings and live workshop progress</span>
        <span><?= icon('check') ?> Invoices and service history</span>
        <span><?= icon('check') ?> Saved vehicles for faster booking</span>
      </figcaption>
    </figure>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
