<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout_admin();
}

redirect('admin/login.php');
