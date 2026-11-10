<?php
// One-time setup: creates the database and tables. Delete this file after running it.
require __DIR__ . '/config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore "duplicate column" (1060) from migration ALTERs that already ran.
            if ($e->errorInfo[1] !== 1060) {
                throw $e;
            }
        }
    }    $msg = 'Database "' . DB_NAME . '" is ready. You can now <a href="register.php">create an account</a>. Delete install.php when done.';
} catch (PDOException $e) {
    $msg = 'Install failed: ' . htmlspecialchars($e->getMessage()) . '<br>Is MySQL running in the XAMPP Control Panel?';
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Install</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main class="container narrow"><div class="card"><h1>Install</h1><p><?= $msg ?></p></div></main></body></html>
