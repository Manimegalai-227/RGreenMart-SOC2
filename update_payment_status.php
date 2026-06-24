<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

header("Content-Type: application/json");

// ── Auth: admin only ──────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$orderId   = intval($_POST["order_id"]    ?? 0);
$status    = $_POST["status"]             ?? '';   // 'success' or 'failed'
$paymentId = trim($_POST["payment_id"]    ?? '');  // may be empty for failed

if (!$orderId || !in_array($status, ['success', 'failed'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid parameters"]);
    exit;
}

// ── Verify order exists ───────────────────────────────────────────────────────
$orderStmt = $conn->prepare("SELECT id, payment_method, payment_status FROM orders WHERE id = ? LIMIT 1");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Order not found"]);
    exit;
}

// ── creditReferralCommission — defined here for COD orders confirmed by admin ──
if (!function_exists('creditReferralCommission')) {
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

            // Commission = the exact referral discount amount the buyer received.
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
}

if ($status === "success") {
    // Preserve payment_method for COD orders (don't overwrite 'cod' with 'online')
    $isCod = in_array($order['payment_method'], ['cod', 'cod_advance']);
    $newPaymentMethod = $isCod ? $order['payment_method'] : 'online';

    $stmt = $conn->prepare("
        UPDATE orders
        SET payment_status      = 'paid',
            status              = 'ordered',
            razorpay_payment_id = ?,
            payment_method      = ?
        WHERE id = ?
    ");
    $stmt->execute([$paymentId ?: null, $newPaymentMethod, $orderId]);

    // Credit referral commission now that payment is confirmed
    // This is the correct place for COD orders — commission is only due after cash is received.
    creditReferralCommission($conn, $orderId);

} else {
    // FAILED PAYMENT — keep order pending so customer can retry
    $stmt = $conn->prepare("
        UPDATE orders
        SET payment_status = 'failed',
            status         = 'pending'
        WHERE id = ?
    ");
    $stmt->execute([$orderId]);
}

echo json_encode(["success" => true]);