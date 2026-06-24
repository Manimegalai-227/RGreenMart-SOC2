<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once $_SERVER["DOCUMENT_ROOT"] . "/vendor/autoload.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ══════════════════════════════════════════════════════════
//  ACTION: send_otp  — sends OTP to the new email address
// ══════════════════════════════════════════════════════════
if ($action === 'send_otp') {
    $newEmail = trim($_POST['email'] ?? '');
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    // Check email not already taken by another user
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $chk->execute([$newEmail, $userId]);
    if ($chk->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
        exit;
    }

    // Generate 6-digit OTP, store in session with expiry
    $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = time() + 600; // 10 minutes
    $_SESSION['email_otp']         = $otp;
    $_SESSION['email_otp_new']     = $newEmail;
    $_SESSION['email_otp_expires'] = $expires;

    // Send OTP email via PHPMailer
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_MAIL;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS on port 587 — more widely supported
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

        $mail->setFrom(SMTP_MAIL, 'RGreenMart');
        $mail->addAddress($newEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification OTP – RGreenMart';
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;
                        border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                <div style='background:linear-gradient(135deg,#e91e63,#6a1b9a);
                            padding:20px;text-align:center;'>
                    <h2 style='color:white;margin:0;'>RGreenMart</h2>
                    <p style='color:rgba(255,255,255,0.85);margin:4px 0 0;font-size:13px;'>
                        Fresh. Pure. Premium.
                    </p>
                </div>
                <div style='padding:28px 24px;'>
                    <p style='font-size:15px;color:#374151;margin:0 0 20px;'>
                        Your email change verification OTP is:
                    </p>
                    <div style='text-align:center;background:#f9fafb;border-radius:12px;
                                padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;'>
                        <span style='font-size:40px;font-weight:800;letter-spacing:10px;
                                     color:#e91e63;font-family:monospace;'>{$otp}</span>
                    </div>
                    <p style='font-size:12px;color:#9ca3af;text-align:center;margin:0;'>
                        This OTP expires in <strong>10 minutes</strong>.
                        Do not share it with anyone.
                    </p>
                </div>
            </div>";
        $mail->AltBody = "Your RGreenMart email verification OTP: {$otp}. Valid for 10 minutes.";
        $mail->send();

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'OTP sent.']);
    } catch (MailException $e) {
        error_log('OTP email failed: ' . $mail->ErrorInfo);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP: ' . $mail->ErrorInfo]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════
//  ACTION: save_profile  — saves name, mobile, email
// ══════════════════════════════════════════════════════════
if ($action === 'save_profile') {
    $name   = trim($_POST['name']   ?? '');
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    $email  = trim($_POST['email']  ?? '');
    $otp    = trim($_POST['otp']    ?? '');

    if (!$name) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit;
    }

    // Fetch current user
    $cur = $conn->prepare("SELECT email, mobile FROM users WHERE id = ? LIMIT 1");
    $cur->execute([$userId]);
    $currentUser = $cur->fetch(PDO::FETCH_ASSOC);

    $emailChanged = $email !== ($currentUser['email'] ?? '');

    // Validate OTP if email changed
    if ($emailChanged) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            exit;
        }
        if (
            empty($_SESSION['email_otp']) ||
            empty($_SESSION['email_otp_new']) ||
            $_SESSION['email_otp_new']     !== $email ||
            $_SESSION['email_otp_expires'] < time() ||
            $_SESSION['email_otp']         !== $otp
        ) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
            exit;
        }
        // Check email not taken
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk->execute([$email, $userId]);
        if ($chk->fetch()) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'This email is already registered.']);
            exit;
        }
        // Clear OTP session
        unset($_SESSION['email_otp'], $_SESSION['email_otp_new'], $_SESSION['email_otp_expires']);
    } else {
        $email = $currentUser['email']; // keep existing
    }

    // Validate mobile (optional but if provided must be 10 digits)
    if ($mobile !== '' && strlen($mobile) !== 10) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Mobile number must be 10 digits.']);
        exit;
    }

    // Update users table
    $stmt = $conn->prepare("UPDATE users SET name = ?, mobile = ?, email = ? WHERE id = ?");
    $stmt->execute([$name, $mobile ?: null, $email, $userId]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
    exit;
}

// ══════════════════════════════════════════════════════════
//  ACTION: send_verify_otp
//  Stores OTP in password_resets table (same proven method as register.php)
// ══════════════════════════════════════════════════════════
if ($action === 'send_verify_otp') {
    // Fetch current email from DB
    $cur = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $cur->execute([$userId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    $userEmail = $row['email'] ?? '';

    if (!$userEmail || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No valid email on your account.']);
        exit;
    }

    // Generate OTP and store hashed in password_resets table (DB-backed, session-safe)
    $otp     = rand(100000, 999999);
    $otpHash = password_hash((string)$otp, PASSWORD_BCRYPT);

    $conn->prepare(
        "INSERT INTO password_resets (user_id, otp_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
    )->execute([$userId, $otpHash]);

    try {
        $mail = new PHPMailer(true);
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
        $mail->setFrom(SMTP_MAIL, 'RGreenMart');
        $mail->addAddress($userEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification OTP – RGreenMart';
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;
                        border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                <div style='background:linear-gradient(135deg,#e91e63,#6a1b9a);
                            padding:20px;text-align:center;'>
                    <h2 style='color:white;margin:0;'>RGreenMart</h2>
                    <p style='color:rgba(255,255,255,0.85);margin:4px 0 0;font-size:13px;'>
                        Fresh. Pure. Premium.
                    </p>
                </div>
                <div style='padding:28px 24px;'>
                    <p style='font-size:15px;color:#374151;margin:0 0 20px;'>
                        Use the OTP below to verify your email address:
                    </p>
                    <div style='text-align:center;background:#f9fafb;border-radius:12px;
                                padding:20px;margin-bottom:20px;border:1px solid #e5e7eb;'>
                        <span style='font-size:40px;font-weight:800;letter-spacing:10px;
                                     color:#e91e63;font-family:monospace;'>$otp</span>
                    </div>
                    <p style='font-size:12px;color:#9ca3af;text-align:center;margin:0;'>
                        This OTP expires in <strong>10 minutes</strong>.
                        Do not share it with anyone.
                    </p>
                </div>
            </div>";
        $mail->AltBody = "Your RGreenMart email verification OTP: $otp — valid for 10 minutes.";
        $mail->send();
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $userEmail]);
    } catch (MailException $e) {
        error_log('Verify OTP email failed: ' . $mail->ErrorInfo);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP: ' . $mail->ErrorInfo]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════
//  ACTION: confirm_verify_otp
//  Validates OTP from password_resets table, sets email_verified = 1
// ══════════════════════════════════════════════════════════
if ($action === 'confirm_verify_otp') {
    $otp = trim($_POST['otp'] ?? '');

    // Look up most recent unused, non-expired OTP for this user
    $stmt = $conn->prepare("
        SELECT * FROM password_resets
        WHERE user_id = ? AND used = 0 AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify((string)$otp, $row['otp_hash'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please try again.']);
        exit;
    }

    // Mark OTP as used
    $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
         ->execute([$row['id']]);

    // Mark email as verified
    $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")
         ->execute([$userId]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Email verified successfully!']);
    exit;
}

// Unknown action
ob_end_clean();
echo json_encode(['success' => false, 'message' => 'Unknown action.']);