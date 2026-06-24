<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
// --- Database Configuration ---
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'diwali_db';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? ""; // Ensure this variable is used for DB connection

$error = null;

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set character set to UTF8
    $conn->exec("set names utf8");
} catch (PDOException $e) {
    // In a production environment, avoid echoing the full error message
    // echo "Connection failed: " . $e->getMessage();
    $error = "Database connection failed."; 
    // You might want to log the full error instead
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    // Sanitize input
    
    $adminUsername = trim($_POST['username'] ?? '');
    $adminPassword = trim($_POST['password'] ?? '');

    // 1. Fetch user data (specifically the hashed password) from DB
    $stmt = $conn->prepare("SELECT username, password FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$adminUsername]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Check if a user was found AND verify the password
    if ($row && password_verify($adminPassword, $row['password'])) {
        
        // --- Authentication Success ---
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $row['username']; // Use the username fetched from DB
        session_write_close();
        // Redirect to the dashboard
        header('Location: Manageitems.php');
        exit;
        
    } else {
        // --- Authentication Failure ---
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
</head>
<body>
    <div style="padding-top: 10%;">
    <div class="container" style="max-width: 400px; margin: 50px auto; background-color: #d7f7cdff; border: 1px solid #ddd; border-radius: 5px; padding:50px;">
        <h2>Admin Login</h2>
        <?php if (isset($error)): ?>
            <p class="error" style="color: red; text-align: center; font-weight: bold;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="POST" style="display: flex; flex-direction: column;">
            <label style="margin-bottom: 10px;">Username:</label>
            <input type="text" name="username" required style="padding: 10px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 5px;">
            <label style="margin-bottom: 10px;">Password:</label>
            <div style="position: relative; margin-bottom: 20px;">
                <input type="password" name="password" id="adminPassword" required
                    style="padding: 10px; padding-right: 42px; width: 100%; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box;">
                <button type="button" id="togglePassword"
                    onclick="togglePass()"
                    style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
                           background: none; border: none; cursor: pointer; padding: 0; color: #666; font-size: 16px;"
                    title="Show/Hide Password">
                    <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="display:none;">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
            <button type="submit" style="padding: 10px; background-color: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer;">Login</button>
        </form>
    </div>
    </div>

<script>
function togglePass() {
    var input   = document.getElementById('adminPassword');
    var eyeOn   = document.getElementById('eyeIcon');
    var eyeOff  = document.getElementById('eyeOffIcon');
    if (input.type === 'password') {
        input.type   = 'text';
        eyeOn.style.display  = 'none';
        eyeOff.style.display = 'block';
    } else {
        input.type   = 'password';
        eyeOn.style.display  = 'block';
        eyeOff.style.display = 'none';
    }
}
</script>
</body>
</html>