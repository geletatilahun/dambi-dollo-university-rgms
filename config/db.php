<?php
/**
 * Database connection (PDO)
 * DDU-RGMS
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ddu_rgms');
define('DB_USER', 'root');
define('DB_PASS', '');        // XAMPP default has no password
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME', 'DDU Research Grant Management System');
define('BASE_URL',  '/ddu-rgms');   // adjust if you rename the folder

$dsn = "mysql:host=" . DB_HOST . ";port=3307;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}