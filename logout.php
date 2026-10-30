<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout_customer();
    flash('success', 'You have been logged out.');
}

redirect('index.php');
