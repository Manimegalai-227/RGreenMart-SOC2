<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// --- Database Configuration ---
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'diwali_db';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? "";

$error = null;
$success = null;

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES utf8");
} catch (PDOException $e) {
    $error = "Database connection failed.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {

    $adminUsername = trim($_POST['username'] ?? '');
    $adminPassword = trim($_POST['password'] ?? '');

    if (empty($adminUsername) || empty($adminPassword)) {
        $error = "Username and password are required.";
    } else {

        // Check if username already exists
        $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
        $checkStmt->execute([$adminUsername]);

        if ($checkStmt->fetch()) {
            $error = "Username already exists.";
        } else {

            // Hash password
            $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

            // Insert admin user
            $insertStmt = $conn->prepare(
                "INSERT INTO admin_users (username, password) VALUES (?, ?)"
            );
            $insertStmt->execute([$adminUsername, $hashedPassword]);

            $success = "Admin registered successfully.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Registration</title>
</head>
<body>
<div style="padding-top:10%;">
    <div style="max-width:400px;margin:auto;background:#d7f7cdff;padding:50px;border-radius:5px;">
        <h2>Admin Registration</h2>

        <?php if ($error): ?>
            <p style="color:red;font-weight:bold;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p style="color:green;font-weight:bold;"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <form method="POST" style="display:flex;flex-direction:column;">
            <label>Username</label>
            <input type="text" name="username" required
                   style="padding:10px;margin-bottom:20px;">

            <label>Password</label>
            <input type="password" name="password" required
                   style="padding:10px;margin-bottom:20px;">

            <button type="submit"
                    style="padding:10px;background:#4CAF50;color:white;border:none;">
                Register
            </button>
        </form>
    </div>
</div>
</body>
</html>
