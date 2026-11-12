<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$role = current_user()['role'];

$map = [
    'researcher'  => 'researcher.php',
    'coordinator' => 'coordinator.php',
    'college'     => 'college.php',
    'director'    => 'director.php',
    'vp'          => 'vp.php',
    'reviewer'    => 'reviewer.php',
    'admin'       => 'admin.php',
    'finance'     => 'finance.php'
];

$file = $map[$role] ?? null;

if (!$file || !file_exists(__DIR__ . '/dashboards/' . $file)) {
    die("Dashboard not found for role: " . e($role));
}

require __DIR__ . '/dashboards/' . $file;