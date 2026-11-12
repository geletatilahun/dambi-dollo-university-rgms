<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

logout($pdo);
set_flash('success', 'You have been logged out.');
redirect('/login.php');