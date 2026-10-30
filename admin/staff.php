<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_admin();

$editId = (int) query_param('id');
$editing = $editId > 0 ? db_one('SELECT id, username, full_name, is_active FROM admin WHERE id = ?', 'i', [$editId]) : null;
if ($editId > 0 && $editing === null) {
    abort(404, 'Staff login not found', 'That staff login does not exist.');
}

$values = [
    'username' => $editing['username'] ?? '',
    'full_name' => (string) ($editing['full_name'] ?? ''),
    'is_active' => (string) ($editing['is_active'] ?? '1'),
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $values['username'] = strtolower(input('username'));
    $values['full_name'] = input('full_name');
    $values['is_active'] = isset($_POST['is_active']) ? '1' : '0';
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!$editing) {
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $values['username'])) {
            $errors['username'] = 'Use 3 to 50 lowercase letters, numbers, dots, dashes or underscores.';
        }
    }
    if ($values['full_name'] === '' || too_long($values['full_name'], 100)) {
        $errors['full_name'] = 'Enter the person\'s name.';
    }
    if (!$editing || $password !== '') {
        $passwordError = validate_new_password($password, $confirmation);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }
    }
    if ($editing && (int) $editing['id'] === (int) $admin['id'] && $values['is_active'] !== '1') {
        $errors['is_active'] = 'You cannot deactivate your own login.';
    }
    if ($editing && $values['is_active'] !== '1' && (int) db_value('SELECT COUNT(*) FROM admin WHERE is_active = 1 AND id <> ?', 'i', [$editing['id']]) === 0) {
        $errors['is_active'] = 'At least one staff login must stay active.';
    }

    if (!$errors) {
        try {
            if ($editing) {
                db_execute('UPDATE admin SET full_name = ?, is_active = ? WHERE id = ?', 'sii', [$values['full_name'], (int) $values['is_active'], $editing['id']]);
                if ($password !== '') {
                    db_execute('UPDATE admin SET password_hash = ? WHERE id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), $editing['id']]);
                }
                flash('success', 'Login for ' . $values['full_name'] . ' updated.' . ($password !== '' ? ' Their password has been changed.' : ''));
            } else {
                db_insert(
                    'INSERT INTO admin (username, full_name, password_hash) VALUES (?, ?, ?)',
                    'sss',
                    [$values['username'], $values['full_name'], password_hash($password, PASSWORD_DEFAULT)]
                );
                flash('success', 'Login created. ' . $values['full_name'] . ' can now sign in as "' . $values['username'] . '".');
            }
            redirect('admin/staff.php');
        } catch (mysqli_sql_exception $e) {
            if (!is_duplicate_key($e, 'uq_admin_username')) {
                throw $e;
            }
            $errors['username'] = 'That username is already taken.';
        }
    }
}

$staff = db_all('SELECT id, username, full_name, is_active, created_at FROM admin ORDER BY is_active DESC, full_name, username');

$pageTitle = 'Staff logins';
$activeNav = 'staff.php';
require dirname(__DIR__) . '/includes/admin_header.php';
?>
<div class="page-head">
  <div>
    <h1>Staff logins</h1>
    <p class="muted">People who can sign in to this staff area. Every login currently has full access.</p>
  </div>
</div>

<div class="detail-grid">
  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Username</th><th>Status</th><th>Added</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($staff as $person): ?>
          <tr class="<?= $person['is_active'] ? '' : 'is-muted' ?>">
            <td><?= e($person['full_name'] ?: '-') ?><?= (int) $person['id'] === (int) $admin['id'] ? ' <span class="badge badge-blue">You</span>' : '' ?></td>
            <td><code><?= e($person['username']) ?></code></td>
            <td><?= $person['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Deactivated</span>' ?></td>
            <td class="nowrap"><?= e(format_date($person['created_at'], 'j M Y')) ?></td>
            <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/staff.php?id=' . $person['id'])) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" class="card form-card" action="<?= e(url($editing ? 'admin/staff.php?id=' . $editing['id'] : 'admin/staff.php')) ?>">
    <?= csrf_field() ?>
    <h2 class="card-title"><?= $editing ? 'Edit ' . e($editing['username']) : 'Add a staff login' ?></h2>
    <?= error_summary($errors) ?>
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" maxlength="50" autocomplete="off" required value="<?= e($values['username']) ?>"<?= $editing ? ' readonly' : '' ?><?= field_attrs($errors, 'username') ?>>
      <?php if ($editing): ?><p class="hint">Usernames cannot be changed.</p><?php endif; ?>
      <?= field_error($errors, 'username') ?>
    </div>
    <div class="field">
      <label for="full_name">Full name</label>
      <input id="full_name" name="full_name" type="text" maxlength="100" required value="<?= e($values['full_name']) ?>"<?= field_attrs($errors, 'full_name') ?>>
      <?= field_error($errors, 'full_name') ?>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="password"><?= $editing ? 'New password' : 'Password' ?><?= $editing ? ' <span class="optional">leave blank to keep</span>' : '' ?></label>
        <input id="password" name="password" type="password" maxlength="72" autocomplete="new-password"<?= $editing ? '' : ' required' ?><?= field_attrs($errors, 'password') ?>>
      </div>
      <div class="field">
        <label for="password_confirmation">Confirm</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="72" autocomplete="new-password"<?= $editing ? '' : ' required' ?>>
      </div>
    </div>
    <?= field_error($errors, 'password') ?>
    <?php if ($editing): ?>
      <div class="field">
        <label class="inline-check"><input type="checkbox" name="is_active" value="1"<?= checked($values['is_active'], '1') ?>> Can sign in</label>
        <p class="hint">Untick when someone leaves. Their name stays on the records they created.</p>
        <?= field_error($errors, 'is_active') ?>
      </div>
    <?php else: ?>
      <input type="hidden" name="is_active" value="1">
    <?php endif; ?>
    <button type="submit" class="btn btn-dark btn-block"><?= $editing ? 'Save' : 'Create login' ?></button>
    <?php if ($editing): ?>
      <p class="center small"><a href="<?= e(url('admin/staff.php')) ?>">Cancel</a></p>
    <?php endif; ?>
  </form>
</div>
<?php require dirname(__DIR__) . '/includes/admin_footer.php'; ?>
