<?php
require_once "dbconf.php";
require_once "vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = "";
$success = "";
$step = 1; // default step
$latestReset = null;

// -----------------------------
// STEP 1: Send OTP
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['send_otp'])) {
    $input = trim($_POST['user_input']);

    if ($input === "") {
        $error = "Enter your email or mobile number.";
    } else {
        $stmt = $conn->prepare("SELECT id,email FROM users WHERE email=? OR mobile=?");
        $stmt->execute([$input, $input]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = "No account found with this email or mobile.";
        } else {
            $otp = random_int(100000, 999999);
            $otpHash = password_hash($otp, PASSWORD_BCRYPT);

            // Insert OTP into password_resets table
            $conn->prepare(
                "INSERT INTO password_resets (user_id, otp_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
            )->execute([$user['id'], $otpHash]);

            // Get latest OTP entry for conditional tracking
            $stmt = $conn->prepare("SELECT * FROM password_resets WHERE user_id=? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user['id']]);
            $latestReset = $stmt->fetch();
            $step = 2;

            // Send Email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = MAIL_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_MAIL;
                $mail->Password   = SMTP_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->SMTPDebug  = 0;
                $mail->Timeout    = 30;
                $mail->CharSet    = 'UTF-8';
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                    'tls' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];

                $mail->setFrom(SMTP_MAIL, "RGreenMart");
                $mail->addAddress($user['email']);
                $mail->isHTML(true);
                $mail->Subject = "Password Reset OTP";
                $mail->Body = "<h3>Password Reset</h3><p>Your OTP is: <b>$otp</b></p><p>Valid for 10 minutes</p>";
                $mail->send();
                $success = "OTP sent to your email.";
            } catch (Exception $e) {
                $error = "Unable to send OTP. Try again later.";
            }
        }
    }
}

// -----------------------------
// STEP 2: Verify OTP
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_otp'])) {
    $user_id = $_POST['user_id'];
    $otp = trim($_POST['otp']);

    // Fetch latest unused OTP
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE user_id=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $latestReset = $stmt->fetch();

    if (!$latestReset || !password_verify($otp, $latestReset['otp_hash'])) {
        $error = "Invalid or expired OTP.";
        $step = 2;
    } else {
        // Mark OTP as used
        $conn->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$latestReset['id']]);
        $success = "OTP verified. Enter new password.";
        $step = 3;
    }
}

// -----------------------------
// STEP 3: Reset Password
// -----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_pass'])) {
    $user_id = $_POST['user_id'];
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
        $step = 3;
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
        $step = 3;
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $conn->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $user_id]);
        $success = "Password updated successfully. <a href='login.php'>Login</a>";
        $step = 1;
        $latestReset = null;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>

<?php include "includes/header.php"; ?>

<div class="flex justify-center items-center bg-gray-100 p-8">
<div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">

<h2 class="text-2xl font-bold mb-4 text-center">
<?php
if ($step === 1) echo "Forgot Password";
elseif ($step === 2) echo "Verify OTP";
else echo "Reset Password";
?>
</h2>

<?php if ($error): ?>
<div class="bg-red-100 text-red-800 p-3 mb-4 rounded"><?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="bg-green-100 text-green-800 p-3 mb-4 rounded"><?= $success ?></div>
<?php endif; ?>

<!-- STEP 1 -->
<?php if ($step === 1): ?>
<form method="POST" class="space-y-4">
    <input type="text" name="user_input" placeholder="Email or Mobile" class="w-full p-3 border rounded-lg" required>
    <button name="send_otp" class="w-full bg-green-600 text-white p-3 rounded-lg">Send OTP</button>
</form>

<!-- STEP 2 -->
<?php elseif ($step === 2): ?>
<form method="POST" class="space-y-4">
    <input type="text" name="otp" placeholder="Enter OTP" class="w-full p-3 border rounded-lg" required>
    <input type="hidden" name="user_id" value="<?= $latestReset['user_id'] ?>">
    <button name="verify_otp" class="w-full bg-green-600 text-white p-3 rounded-lg">Verify OTP</button>
</form>

<!-- STEP 3 -->
<?php else: ?>
<form method="POST" class="space-y-4">
    <input type="password" name="password" placeholder="New Password" class="w-full p-3 border rounded-lg" required>
    <input type="password" name="confirm_password" placeholder="Confirm Password" class="w-full p-3 border rounded-lg" required>
    <input type="hidden" name="user_id" value="<?= $latestReset['user_id'] ?>">
    <button name="reset_pass" class="w-full bg-green-600 text-white p-3 rounded-lg">Reset Password</button>
</form>
<?php endif; ?>

<p class="text-center text-sm mt-4">
    <a href="login.php" class="text-green-600">Back to Login</a>
</p>

</div>
</div>

<?php include "includes/footer.php"; ?>
</body>
</html>