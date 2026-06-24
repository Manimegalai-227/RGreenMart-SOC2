<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
require_once 'vendor/autoload.php';
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

use Razorpay\Api\Api;
session_start();

// DB Config (Get configuration from environment variables)
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'diwali_db';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';

// --- 2. Initialize Database Connection ($conn) ---
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────
    date_default_timezone_set('Asia/Kolkata');
    $conn->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// --- 3. Initialize Razorpay API ---
$razorpayKeyId = $_ENV['RAZORPAY_KEY_ID'] ?? null;
$razorpayKeySecret = $_ENV['RAZORPAY_KEY_SECRET'] ?? null;

if (!$razorpayKeyId || !$razorpayKeySecret) {
    die("Configuration Error: Razorpay keys are missing.");
}

$api = new Api($razorpayKeyId, $razorpayKeySecret);

/**
 * Credit referral commission to referrer's wallet (first-order only, idempotent).
 */
function creditReferralCommission(PDO $conn, int $orderId): void {
    try {
        $oStmt = $conn->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        // Guard: order must exist, not already credited, not cancelled
        if (!$order || !empty($order['referral_commission_credited'])) return;
        if ($order['status'] === 'cancelled') return;

        // ── KEY RULE ──────────────────────────────────────────────────────
        // Referrer earns commission ONLY when the referred user's first order
        // actually had a referral discount applied (referral_discount > 0).
        // This ensures:
        //   - Referrer gets nothing if the referred user placed without discount
        //   - Referrer gets nothing if a previous discounted order was cancelled
        //     and the user somehow placed a new paid order (discount gone, no earn)
        if ((float)($order['referral_discount'] ?? 0) <= 0) return;

        $userId = (int)$order['user_id'];

        $promo = $conn->query(
            "SELECT referral_enabled, referral_type, referral_percent, referral_amount
             FROM promo_settings WHERE id=1"
        )->fetch(PDO::FETCH_ASSOC);
        if (empty($promo['referral_enabled'])) return;

        $uStmt = $conn->prepare("SELECT referred_by FROM users WHERE id=? LIMIT 1");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $referrerId = (int)($uRow['referred_by'] ?? 0);
        if (!$referrerId) return;

        // Commission = the actual referral discount amount the buyer received.
        // This is exact — no re-calculation needed, no floating-point drift.
        $commission = round((float)$order['referral_discount'], 2);
        if ($commission <= 0) return;

        $conn->prepare("UPDATE users SET referral_wallet = referral_wallet + ? WHERE id=?")
             ->execute([$commission, $referrerId]);

        $conn->prepare(
            "INSERT INTO referral_transaction
             (earner_id, referred_id, order_id, order_amount, percent, earned_amount)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $referrerId, $userId, $orderId,
            (float)$order['overall_total'],
            (float)($promo['referral_percent'] ?? 0),
            $commission,
        ]);

        $conn->prepare("UPDATE orders SET referral_commission_credited=1 WHERE id=?")
             ->execute([$orderId]);

    } catch (Throwable $e) {
        error_log("creditReferralCommission error: " . $e->getMessage());
    }
}

// --- 4. Get Data from URL and Database ---
$orderId     = $_GET['order_id']   ?? null;
$paymentId   = $_GET['payment_id']  ?? null;
$signature   = $_GET['signature']   ?? null;
$paymentType = $_GET['type']        ?? 'full';   // 'full' or 'cod_advance'

// Basic validation (same as before)
if (!$orderId || !$paymentId || !$signature ) {
    die("Invalid payment request parameters or session mismatch.");
}

// Fetch the order details, including the Razorpay Order ID
$stmt = $conn->prepare("SELECT * FROM orders WHERE id=?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Error: Order ID $orderId not found in database.");
}

$attributes = [
    'razorpay_order_id' => $order['razorpay_order_id'],
    'razorpay_payment_id' => $paymentId,
    'razorpay_signature' => $signature
];

// --- 5. Verify Signature and Update DB ---
try {
    $api->utility->verifyPaymentSignature($attributes);

    unset($_SESSION['order_id']);

    if ($paymentType === 'cod_advance') {
        // ── COD Advance: mark advance as paid, keep status 'pending' (balance due on delivery)
        $conn->prepare("
            UPDATE orders SET
                payment_status        = 'advance_paid',
                razorpay_payment_id   = ?,
                razorpay_signature    = ?,
                status                = 'ordered',
                payment_method        = 'cod_advance'
            WHERE id = ?
        ")->execute([$paymentId, $signature, $orderId]);
        // Credit referral commission for COD advance (advance counts as confirmed)
        creditReferralCommission($conn, $orderId);

    } elseif ($paymentType === 'cod_charge') {
        // ── COD + Convenience Fee: fee paid online, rest paid on delivery
        // payment_status = 'advance_paid' signals the fee was collected;
        // the order remains COD — full order amount still due at delivery.
        $conn->prepare("
            UPDATE orders SET
                payment_status        = 'advance_paid',
                razorpay_payment_id   = ?,
                razorpay_signature    = ?,
                status                = 'ordered',
                payment_method        = 'cod'
            WHERE id = ?
        ")->execute([$paymentId, $signature, $orderId]);
        // Do NOT credit referral commission — COD order not fully paid yet.
        // creditReferralCommission() will be called from update_payment_status.php
        // when the seller confirms cash receipt.

    } else {
        // ── Full online payment
        $conn->prepare("
            UPDATE orders SET
                payment_status        = 'paid',
                razorpay_payment_id   = ?,
                razorpay_signature    = ?,
                status                = 'ordered',
                payment_method        = 'online'
            WHERE id = ?
        ")->execute([$paymentId, $signature, $orderId]);
        // Credit referral commission after first successful online payment
        creditReferralCommission($conn, $orderId);
    }

    header("Location: order_success.php?order_id=$orderId&cart_cleared=true");
    exit;

} catch(\Razorpay\Api\Errors\SignatureVerificationError $e){
    // Update status to FAILED and record the payment ID and signature
    $errorMessage = "Payment Verification Failed: Invalid Signature. Debug: " . $e->getMessage();
    
    $conn->prepare("
        UPDATE orders 
        SET 
            payment_status='failed', 
            razorpay_payment_id=?, 
            razorpay_signature=? 
        WHERE id=?
    ")->execute([$paymentId, $signature, $orderId]);
         
    die($errorMessage);
    
} catch(Exception $e){
    // General error handler
    $conn->prepare("UPDATE orders SET payment_status='failed' WHERE id=?")->execute([$orderId]);
    die("Payment Verification Failed: An unexpected error occurred: " . $e->getMessage());
}
?>