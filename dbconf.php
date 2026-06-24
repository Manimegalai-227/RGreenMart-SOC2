<?php
// ==========================
//  LOAD .env VARIABLES
// ==========================
function loadEnv() {
    // Load .env from the current project folder
    $path = __DIR__ . '/.env';

    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

loadEnv();

// ==========================
//  DATABASE CONNECTION (PDO)
// ==========================
try {
    $conn = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    exit("Database Connection Failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// ==========================
//  DATABASE CONNECTION (MySQLi)
// ==========================

$mysqli = @mysqli_connect(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);

if (!$mysqli) {
    exit("Database Connection Failed: " . mysqli_connect_error());
}

// ==========================
//  SMTP CONSTANTS
// ==========================
define('SMTP_MAIL', $_ENV['SMTP_MAIL']);
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD']);
define('MAIL_HOST', $_ENV['MAIL_HOST']);
define('WHATSAPP_DEFAULT_PHONE', $_ENV['WHATSAPP_DEFAULT_PHONE'] ?? '919655562772');
define('WHATSAPP_DEFAULT_MESSAGE', $_ENV['WHATSAPP_DEFAULT_MESSAGE'] ?? 'Hello RGreenmart, I would like more information.');
?>
