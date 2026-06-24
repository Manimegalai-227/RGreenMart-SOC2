<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$error = "";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $identifier = trim($_POST["identifier"]);
    $password   = trim($_POST["password"]);

    $sql  = "SELECT * FROM users WHERE email = :id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['id' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user["password_hash"])) {

        $_SESSION["user_id"]     = $user["id"];
        $_SESSION["user_name"]   = $user["name"];
        $_SESSION["user_email"]  = $user["email"];
        $_SESSION["user_mobile"] = $user["mobile"];

        if (!empty($_SESSION["redirect_after_login"])) {
            $redirect = $_SESSION["redirect_after_login"];
            unset($_SESSION["redirect_after_login"]);
            header("Location: $redirect");
            exit();
        }

        header("Location: index.php");
        exit();
    } else {
        $error = "Invalid login credentials!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/cart.js"></script>
</head>
<body>
<?php include "includes/header.php"; ?>

<div class="flex items-center justify-center bg-gray-100 p-8">
<div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">

    <h2 class="text-2xl font-bold text-center text-green-600 mb-6">Sign In</h2>

    <?php if ($error): ?>
        <div class="bg-red-100 text-red-600 p-3 rounded mb-4 text-sm" id="alertBox">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <!-- Email -->
        <label class="block text-gray-700 font-medium mb-1">Email</label>
        <input type="text" name="identifier" required
               value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500 outline-none mb-4"
               placeholder="Enter email">

        <!-- Password with eye icon -->
        <label class="block text-gray-700 font-medium mb-1">Password</label>
        <div class="relative mb-2">
            <input type="password" id="password" name="password" required
                   class="w-full p-3 pr-12 border rounded-lg focus:ring-2 focus:ring-green-500 outline-none"
                   placeholder="Enter password">
            <button type="button" onclick="togglePassword('password', this)"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-700">
                <!-- Eye Open -->
                <svg class="w-5 h-5 eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <!-- Eye Closed -->
                <svg class="w-5 h-5 eye-closed hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-width="2" d="M3 3l18 18M10.477 10.477A3 3 0 0113.52 13.52M7.05 7.05A7.5 7.5 0 004.22 10.5C5.29 13.574 8.418 16 12 16a7.48 7.48 0 003.95-1.05M9.9 4.24A9.1 9.1 0 0112 4c3.582 0 6.71 2.426 7.78 5.5a9.13 9.13 0 01-1.947 3.053"/>
                </svg>
            </button>
        </div>

        <p class="mb-5 text-sm text-right">
            <a href="forgot_password.php" class="text-green-600 font-medium">Forgot Password?</a>
        </p>

    <button type="submit"
        class="w-full bg-gradient-to-br from-[#e91e63] to-[#6a1b9a] 
            hover:from-[#c2185b] hover:to-[#4a148c] 
            text-white p-3 rounded-lg font-semibold transition duration-300">
        Login
    </button>


    </form>

    <p class="text-center text-gray-600 mt-4 text-sm">
        Don't have an account?
        <a href="register.php" class="text-green-600 font-medium">Register</a>
    </p>

</div>
</div>

<?php include "includes/footer.php"; ?>

<script>
function togglePassword(fieldId, btn) {
    const input      = document.getElementById(fieldId);
    const openIcon   = btn.querySelector('.eye-open');
    const closedIcon = btn.querySelector('.eye-closed');
    if (input.type === "password") {
        input.type = "text";
        openIcon.classList.add("hidden");
        closedIcon.classList.remove("hidden");
    } else {
        input.type = "password";
        openIcon.classList.remove("hidden");
        closedIcon.classList.add("hidden");
    }
}

setTimeout(() => {
    const alertBox = document.getElementById('alertBox');
    if (alertBox) alertBox.style.display = 'none';
}, 5000);
</script>
</body>
</html>