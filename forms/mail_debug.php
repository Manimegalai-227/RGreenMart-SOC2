<?php
/**
 * TEMPORARY DIAGNOSTIC FILE — forms/debug_mail.php
 * ⚠️  DELETE THIS FILE after fixing the issue — it exposes server config!
 * Visit: https://yourdomain.com/forms/debug_mail.php
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "=== RGreenMart Mail Diagnostics ===\n\n";

// ── 1. Find & parse .env ────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
echo "1. .env file path: $envFile\n";
echo "   Exists: " . (file_exists($envFile) ? "YES" : "NO ← PROBLEM") . "\n\n";

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "   Raw .env contents:\n";
    foreach ($lines as $line) {
        // Mask password value for safety
        if (stripos($line, 'PASSWORD') !== false || stripos($line, 'PASS') !== false) {
            [$k] = explode('=', $line, 2);
            echo "   $k=***MASKED***\n";
        } else {
            echo "   $line\n";
        }
    }
    echo "\n";
}

// ── 2. Load env the same way contact.php does ───────────────────────────────
function loadEnvRaw(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        $eqPos = strpos($line, '=');
        $key   = trim(substr($line, 0, $eqPos));
        $val   = trim(substr($line, $eqPos + 1));
        if (preg_match('/^(["\'])(.*)\1$/s', $val, $m)) {
            $val = $m[2];
        }
        if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}
if (file_exists($envFile)) loadEnvRaw($envFile);

// Also load dbconf.php in case it sets vars
$dbconf = dirname(__DIR__) . '/dbconf.php';
if (file_exists($dbconf)) {
    require_once $dbconf;
    echo "2. dbconf.php loaded: YES\n";
} else {
    echo "2. dbconf.php: NOT FOUND at $dbconf\n";
}

// ── 3. Show resolved ENV values ─────────────────────────────────────────────
$smtpHost = $_ENV['MAIL_HOST']     ?? 'NOT SET';
$smtpUser = $_ENV['SMTP_MAIL']     ?? 'NOT SET';
$smtpPass = $_ENV['SMTP_PASSWORD'] ?? 'NOT SET';
$smtpPort = $_ENV['SMTP_PORT']     ?? 'NOT SET';

echo "\n3. Resolved ENV values:\n";
echo "   MAIL_HOST     = $smtpHost\n";
echo "   SMTP_MAIL     = $smtpUser\n";
echo "   SMTP_PASSWORD = " . ($smtpPass !== 'NOT SET' ? '***SET (length=' . strlen($smtpPass) . ')***' : 'NOT SET ← PROBLEM') . "\n";
echo "   SMTP_PORT     = $smtpPort\n\n";

// ── 4. Check PHPMailer is installed ─────────────────────────────────────────
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
echo "4. vendor/autoload.php: " . (file_exists($autoload) ? "EXISTS" : "NOT FOUND ← run: composer require phpmailer/phpmailer") . "\n\n";

if (!file_exists($autoload)) {
    echo "STOP: Cannot test SMTP without PHPMailer. Install it first.\n";
    exit;
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── 5. Attempt SMTP connection ───────────────────────────────────────────────
echo "5. Attempting SMTP connection to $smtpHost:$smtpPort ...\n";

$mail = new PHPMailer(true);
$smtpLog = '';

try {
    $mail->isSMTP();
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) use (&$smtpLog) {
        $smtpLog .= "   $str\n";
    };
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 'ssl' — correct for port 465
    $mail->Port       = (int)$smtpPort;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom($smtpUser, 'Debug Test');
    $mail->addAddress($smtpUser);
    $mail->Subject = 'Debug Test';
    $mail->Body    = 'This is a diagnostic test email.';

    $mail->send();
    echo "   ✅ EMAIL SENT SUCCESSFULLY!\n\n";
    echo "   SMTP Log:\n$smtpLog\n";

} catch (Exception $e) {
    echo "   ❌ FAILED: " . $mail->ErrorInfo . "\n\n";
    echo "   SMTP Log:\n$smtpLog\n";
}

ob_end_flush();