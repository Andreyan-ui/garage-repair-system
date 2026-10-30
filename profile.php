<?php
require __DIR__ . '/includes/bootstrap.php';

$customer = require_customer();
$values = [
    'full_name' => $customer['full_name'],
    'phone' => format_phone($customer['phone']),
    'email' => (string) $customer['email'],
];
$errors = [];
$passwordErrors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'details') {
        foreach (array_keys($values) as $key) {
            $values[$key] = input($key);
        }
        if ($values['full_name'] === '' || too_long($values['full_name'], 100)) {
            $errors['full_name'] = 'Enter your full name.';
        }
        $phone = normalize_phone($values['phone']);
        if ($phone === null) {
            $errors['phone'] = 'Enter a valid phone number, for example ' . config('phone.example') . '.';
        } elseif (db_value('SELECT id FROM customers WHERE phone = ? AND id <> ?', 'si', [$phone, $customer['id']]) !== null) {
            $errors['phone'] = 'Another account already uses this phone number.';
        }
        $email = null;
        if ($values['email'] !== '') {
            $email = normalize_email($values['email']);
            if ($email === null) {
                $errors['email'] = 'Enter a valid email address, or leave it blank.';
            } elseif (db_value('SELECT id FROM customers WHERE email = ? AND id <> ?', 'si', [$email, $customer['id']]) !== null) {
                $errors['email'] = 'Another account already uses this email.';
            }
        }
        if (!$errors) {
            db_execute(
                'UPDATE customers SET full_name = ?, phone = ?, email = ? WHERE id = ?',
                'sssi',
                [$values['full_name'], $phone, $email, $customer['id']]
            );
            flash('success', 'Your details have been updated.');
            redirect('account.php');
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');
        $hash = (string) db_value('SELECT password_hash FROM customers WHERE id = ?', 'i', [$customer['id']]);

        if (!password_verify($current, $hash)) {
            $passwordErrors['current_password'] = 'Your current password is not correct.';
        }
        $newError = validate_new_password($new, $confirmation);
        if ($newError !== null) {
            $passwordErrors['new_password'] = $newError;
        }
        if (!$passwordErrors) {
            db_execute('UPDATE customers SET password_hash = ? WHERE id = ?', 'si', [password_hash($new, PASSWORD_DEFAULT), $customer['id']]);
            session_regenerate_id(true);
            flash('success', 'Your password has been changed.');
            redirect('account.php');
        }
    }
}

$pageTitle = 'My details';
$activeNav = 'profile.php';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container narrow stack-lg">
    <p><a href="<?= e(url('account.php')) ?>">&larr; Back to my account</a></p>

    <form method="post" class="card form-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">
      <h1 class="card-title">My details</h1>
      <?= error_summary($errors) ?>
      <div class="field">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" type="text" maxlength="100" autocomplete="name" required value="<?= e($values['full_name']) ?>"<?= field_attrs($errors, 'full_name') ?>>
        <?= field_error($errors, 'full_name') ?>
      </div>
      <div class="field">
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" required value="<?= e($values['phone']) ?>"<?= field_attrs($errors, 'phone') ?>>
        <p class="hint">This is the number you log in with.</p>
        <?= field_error($errors, 'phone') ?>
      </div>
      <div class="field">
        <label for="email">Email <span class="optional">optional</span></label>
        <input id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= e($values['email']) ?>"<?= field_attrs($errors, 'email') ?>>
        <?= field_error($errors, 'email') ?>
      </div>
      <button type="submit" class="btn btn-dark">Save details</button>
    </form>

    <form method="post" class="card form-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <h2 class="card-title">Change password</h2>
      <?= error_summary($passwordErrors) ?>
      <div class="field">
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" maxlength="72" autocomplete="current-password" required<?= field_attrs($passwordErrors, 'current_password') ?>>
        <?= field_error($passwordErrors, 'current_password') ?>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="new_password">New password</label>
          <input id="new_password" name="new_password" type="password" minlength="<?= (int) config('security.min_password_length') ?>" maxlength="72" autocomplete="new-password" required<?= field_attrs($passwordErrors, 'new_password') ?>>
        </div>
        <div class="field">
          <label for="new_password_confirmation">Confirm new password</label>
          <input id="new_password_confirmation" name="new_password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>
        </div>
      </div>
      <?= field_error($passwordErrors, 'new_password') ?>
      <button type="submit" class="btn btn-dark">Change password</button>
    </form>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
