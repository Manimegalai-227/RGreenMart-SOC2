<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

$orderId = $_POST['order_id'] ?? null;
$reason  = trim($_POST['reason'] ?? '');

if (!$orderId || strlen($reason) < 3) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// ---------------------------------------------
// HELPER: determine refund_status based on payment
// ---------------------------------------------
function getRefundStatus(PDO $conn, int $orderId): string {
    $stmt = $conn->prepare("SELECT payment_status FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $ps = $row['payment_status'] ?? 'pending';
    // Only initiate refund if money was actually collected
    return in_array($ps, ['paid', 'advance_paid', 'partial_paid'])
        ? 'initiated'
        : 'not_applicable';
}

// ---------------------------------------------
// USER CANCEL FUNCTION
// ---------------------------------------------
function cancelOrderByUser($conn, $orderId, $reason, $userId)
{
    $refundStatus = getRefundStatus($conn, (int)$orderId);
    $sql = "
        UPDATE orders
        SET status              = 'cancelled',
            cancellation_reason = ?,
            cancelled_at        = NOW(),
            cancelled_by        = 'user',
            refund_status       = ?
        WHERE id = ? AND user_id = ?
    ";
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$reason, $refundStatus, $orderId, $userId]);
}

// ---------------------------------------------
// ADMIN CANCEL FUNCTION
// ---------------------------------------------
function cancelOrderByAdmin($conn, $orderId, $reason)
{
    $refundStatus = getRefundStatus($conn, (int)$orderId);
    $sql = "
        UPDATE orders
        SET status              = 'cancelled',
            cancellation_reason = ?,
            cancelled_at        = NOW(),
            cancelled_by        = 'seller',  /* DB enum is ('user','seller') — 'admin' is not valid */
            refund_status       = ?
        WHERE id = ?
    ";
    $stmt = $conn->prepare($sql);
    return $stmt->execute([$reason, $refundStatus, $orderId]);
}

// ---------------------------------------------
// MAIN EXECUTION LOGIC
// ---------------------------------------------
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if ($isAdmin) {
    // Admin cancelling order
    $done = cancelOrderByAdmin($conn, $orderId, $reason);
} else {
    // Normal user cancelling their order
    $done = cancelOrderByUser($conn, $orderId, $reason, $_SESSION['user_id']);
}

echo json_encode(['success' => $done]);
?>