<?php
require __DIR__ . '/includes/bootstrap.php';

$next = safe_next(query_param('next', input('next')), 'account.php');
if (current_customer()) {
    redirect($next);
}

$values = ['full_name' => '', 'phone' => '', 'email' => ''];
$errors = [];

if (is_post()) {
    verify_csrf();
    if (honeypot_tripped()) {
        redirect('index.php');
    }
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($values['full_name'] === '' || too_long($values['full_name'], 100)) {
        $errors['full_name'] = 'Enter your full name.';
    }
    $phone = normalize_phone($values['phone']);
    if ($phone === null) {
        $errors['phone'] = 'Enter a valid phone number, for example ' . config('phone.example') . '.';
    } elseif (db_value('SELECT id FROM customers WHERE phone = ?', 's', [$phone]) !== null) {
        $errors['phone'] = 'An account with this phone number already exists. Try logging in instead.';
    }
    $email = null;
    if ($values['email'] !== '') {
        $email = normalize_email($values['email']);
        if ($email === null) {
            $errors['email'] = 'Enter a valid email address, or leave it blank.';
        } elseif (db_value('SELECT id FROM customers WHERE email = ?', 's', [$email]) !== null) {
            $errors['email'] = 'An account with this email already exists. Try logging in instead.';
        }
    }
    $passwordError = validate_new_password($password, $confirmation);
    if ($passwordError !== null) {
        $errors['password'] = $passwordError;
    }

    if (!$errors) {
        try {
            $customerId = db_insert(
                'INSERT INTO customers (full_name, phone, email, password_hash) VALUES (?, ?, ?, ?)',
                'ssss',
                [$values['full_name'], $phone, $email, password_hash($password, PASSWORD_DEFAULT)]
            );
            login_customer($customerId);
            flash('success', 'Welcome, ' . $values['full_name'] . '! Your account is ready.');
            redirect($next);
        } catch (mysqli_sql_exception $e) {
            if (is_duplicate_key($e, 'uq_customers_phone')) {
                $errors['phone'] = 'An account with this phone number already exists. Try logging in instead.';
            } elseif (is_duplicate_key($e, 'uq_customers_email')) {
                $errors['email'] = 'An account with this email already exists. Try logging in instead.';
            } else {
                throw $e;
            }
        }
    }
}

$pageTitle = 'Create an account';
$activeNav = 'login.php';
require __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
  <div class="container auth-split">
    <div class="auth-panel">
    <div class="auth-head">
      <h1>Create your account</h1>
      <p class="muted">Book faster, save your vehicles and follow every booking in one place.</p>
    </div>
    <form method="post" class="card form-card" data-once>
      <?= csrf_field() ?>
      <?= honeypot_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?= error_summary($errors) ?>
      <div class="field">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" required value="<?= e($values['full_name']) ?>"<?= field_attrs($errors, 'full_name') ?>>
        <?= field_error($errors, 'full_name') ?>
      </div>
      <div class="field">
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required placeholder="<?= e(config('phone.example')) ?>" value="<?= e($values['phone']) ?>"<?= field_attrs($errors, 'phone') ?>>
        <p class="hint">You will use this to log in. We also use it to call you about your bookings.</p>
        <?= field_error($errors, 'phone') ?>
      </div>
      <div class="field">
        <label for="email">Email <span class="optional">optional</span></label>
        <input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= e($values['email']) ?>"<?= field_attrs($errors, 'email') ?>>
        <?= field_error($errors, 'email') ?>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" minlength="<?= (int) config('security.min_password_length') ?>" maxlength="72" autocomplete="new-password" required<?= field_attrs($errors, 'password') ?>>
          <p class="hint">At least <?= (int) config('security.min_password_length') ?> characters.</p>
        </div>
        <div class="field">
          <label for="password_confirmation">Confirm password</label>
          <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>
        </div>
      </div>
      <?= field_error($errors, 'password') ?>
      <button type="submit" class="btn btn-primary btn-lg btn-block" data-busy="Creating your account...">Create account</button>
    </form>
    <p class="center">Already have an account? <a href="<?= e(url('login.php?next=' . rawurlencode($next))) ?>">Log in</a></p>
    </div>
    <figure class="auth-photo">
      <?= photo('engine3.jpg', 'A smiling mechanic running a diagnostic check') ?>
      <figcaption>
        <strong>Why create an account?</strong>
        <span><?= icon('check') ?> Book in seconds with your saved cars</span>
        <span><?= icon('check') ?> Follow your car while it is in the workshop</span>
        <span><?= icon('check') ?> See every invoice and our advice for next time</span>
      </figcaption>
    </figure>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
