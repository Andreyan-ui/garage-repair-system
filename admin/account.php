<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_admin();
$errors = [];

if (is_post()) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');
    $hash = (string) db_value('SELECT password_hash FROM admin WHERE id = ?', 'i', [$admin['id']]);

    if (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Your current password is not correct.';
    }
    $newError = validate_new_password($new, $confirmation);
    if ($newError !== null) {
        $errors['new_password'] = $newError;
    } elseif (password_verify($new, $hash)) {
        $errors['new_password'] = 'Choose a password you have not used here before.';
    }

    if (!$errors) {
        db_execute('UPDATE admin SET password_hash = ? WHERE id = ?', 'si', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        session_regenerate_id(true);
        flash('success', 'Your password has been changed.');
        redirect('admin/account.php');
    }
}

$pageTitle = 'My account';
$activeNav = 'account.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>My account</h1>
    <p class="muted">Signed in as <strong><?= e($admin['username']) ?></strong></p>
  </div>
</div>

<div class="detail-grid">
  <form method="post" class="card form-card">
    <?= csrf_field() ?>
    <h2 class="card-title">Change password</h2>
    <?= error_summary($errors) ?>
    <div class="field">
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" maxlength="72" autocomplete="current-password" required<?= field_attrs($errors, 'current_password') ?>>
      <?= field_error($errors, 'current_password') ?>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" minlength="<?= (int) config('security.min_password_length') ?>" maxlength="72" autocomplete="new-password" required<?= field_attrs($errors, 'new_password') ?>>
      </div>
      <div class="field">
        <label for="new_password_confirmation">Confirm new password</label>
        <input id="new_password_confirmation" name="new_password_confirmation" type="password" maxlength="72" autocomplete="new-password" required>
      </div>
    </div>
    <?= field_error($errors, 'new_password') ?>
    <button type="submit" class="btn btn-dark">Change password</button>
  </form>
  <section class="card">
    <h2>Keep the staff area safe</h2>
    <ul class="plain-list">
      <li>Replace the default password before the system goes live.</li>
      <li>Always log out on shared computers.</li>
      <li>Sign-ins are locked for 15 minutes after <?= (int) config('security.max_attempts') ?> wrong passwords.</li>
    </ul>
  </section>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
