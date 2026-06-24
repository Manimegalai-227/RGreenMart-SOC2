<?php
/**
 * forms/contact.php
 * Contact form handler — returns JSON always
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

// ── Helper: clean JSON exit ───────────────────────────────────────────────
function jsonExit(bool $ok, string $msg = ''): void {
    ob_end_clean();
    echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => $msg]);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(false, 'Invalid request.');
}

// ── Autoloader ────────────────────────────────────────────────────────────
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    error_log('[contact.php] vendor/autoload.php not found');
    jsonExit(false, 'Server configuration error.');
}
require_once $autoload;

// ── .env loader — reads file raw so $ signs are never interpreted ─────────
function readEnvFile(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        $eqAt = strpos($line, '=');
        $key  = trim(substr($line, 0, $eqAt));
        $val  = trim(substr($line, $eqAt + 1));
        // Remove surrounding quotes: "value" or 'value'
        if (strlen($val) >= 2 && in_array($val[0], ['"', "'"], true) && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        // Set only if not already defined (don't overwrite real env vars)
        if (!array_key_exists($key, $_ENV) || $_ENV[$key] === '') {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

// Load .env from project root (one level up from forms/)
readEnvFile(dirname(__DIR__) . '/.env');

// Also load dbconf.php in case it sets $_ENV via vlucas/phpdotenv
$dbconf = dirname(__DIR__) . '/dbconf.php';
if (file_exists($dbconf)) {
    require_once $dbconf;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// ── Validate inputs ───────────────────────────────────────────────────────
$name    = trim(strip_tags($_POST['name']    ?? ''));
$email   = trim(strip_tags($_POST['email']   ?? ''));
$subject = trim(strip_tags($_POST['subject'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

if (strlen($name) < 2)                         jsonExit(false, 'Please enter your name.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonExit(false, 'Please enter a valid email address.');
if (empty($subject))                            jsonExit(false, 'Subject is required.');
if (strlen($message) < 5)                       jsonExit(false, 'Message must be at least 5 characters.');

// ── Read SMTP credentials ─────────────────────────────────────────────────
$smtpHost = $_ENV['MAIL_HOST']     ?? 'smtp.hostinger.com';
$smtpUser = $_ENV['SMTP_MAIL']     ?? '';
$smtpPass = $_ENV['SMTP_PASSWORD'] ?? '';
$smtpPort = (int)($_ENV['SMTP_PORT'] ?? 465);

if (empty($smtpUser) || empty($smtpPass)) {
    error_log('[contact.php] SMTP credentials not set in .env');
    jsonExit(false, 'Mail is not configured. Please email us at sales@rgreenmart.com');
}

// ── Send email ────────────────────────────────────────────────────────────
try {
    $mail = new PHPMailer(true);

    // SMTP — Hostinger port 465 needs ENCRYPTION_SMTPS (SSL wrapper)
    $mail->isSMTP();
    $mail->SMTPDebug  = 0;
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 'smtps' = SSL on port 465
    $mail->Port       = $smtpPort;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 20;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    // From your inbox; Reply-To set to customer so you can reply directly
    $mail->setFrom($smtpUser, 'RGreenMart Website');
    $mail->addAddress($smtpUser, 'RGreenMart');
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = '[Contact] ' . $subject;
    $mail->Body    = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
  <div style="background:linear-gradient(135deg,#e91e63,#6a1b9a);padding:18px 24px;">
    <h2 style="color:#fff;margin:0;font-size:18px;">New Contact Form Message</h2>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      <tr>
        <td style="padding:8px 0;color:#6b7280;width:90px;font-weight:600;">Name</td>
        <td style="padding:8px 0;color:#1f2937;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>
      </tr>
      <tr style="background:#f9fafb;">
        <td style="padding:8px 4px;color:#6b7280;font-weight:600;">Email</td>
        <td style="padding:8px 4px;">
          <a href="mailto:' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '" style="color:#6a1b9a;">'
            . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</a>
        </td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#6b7280;font-weight:600;">Subject</td>
        <td style="padding:8px 0;color:#1f2937;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</td>
      </tr>
    </table>
    <hr style="border:none;border-top:1px solid #f3f4f6;margin:16px 0;">
    <p style="font-size:13px;color:#6b7280;margin-bottom:8px;font-weight:600;">Message:</p>
    <div style="background:#f9fafb;border-radius:6px;padding:14px;font-size:14px;color:#374151;white-space:pre-wrap;">'
      . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>
    <p style="font-size:11px;color:#9ca3af;margin-top:16px;">
      Sent via RGreenMart Contact Form &bull; ' . date('d M Y, h:i A') . '
    </p>
  </div>
</div>';
    $mail->AltBody = "From: $name <$email>\nSubject: $subject\n\n$message";

    $mail->send();
    jsonExit(true);

} catch (MailException $e) {
    error_log('[contact.php] SMTP error: ' . $mail->ErrorInfo);
    // Return the actual SMTP error so you can see what's really happening
    jsonExit(false, 'SMTP Error: ' . $mail->ErrorInfo);
}