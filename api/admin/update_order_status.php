<?php
/**
 * /api/admin/update_order_status.php
 *
 * Admin-only endpoint to update an order's status (e.g. ordered → shipped → delivered).
 *
 * When an order is marked as DELIVERED (status = 'delivered'):
 *   → Also marks payment_status = 'paid' for COD orders (payment received on delivery)
 *   → Credits referral commission to the referrer's wallet (idempotent — safe to call multiple times)
 *   → Sends a wallet credit notification to the referrer
 *
 * When payment_status is marked as PAID directly (for COD cash receipt confirmation):
 *   → Also triggers referral commission credit
 *
 * POST params:
 *   order_id        int     required
 *   status          string  optional  e.g. 'ordered','shipped','delivered','cancelled'
 *   payment_status  string  optional  e.g. 'paid','pending','failed'
 */

session_start();
header('Content-Type: application/json');

// Admin only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbconf.php';

// ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");

$orderId       = intval($_POST['order_id']       ?? 0);
$newStatus     = trim($_POST['status']            ?? '');
$newPayStatus  = trim($_POST['payment_status']    ?? '');

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'order_id is required']);
    exit;
}

$allowedStatuses     = ['pending','ordered','shipped','delivered','cancelled'];
$allowedPayStatuses  = ['pending','paid','failed','advance_paid','partial_paid'];

if ($newStatus && !in_array($newStatus, $allowedStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}
if ($newPayStatus && !in_array($newPayStatus, $allowedPayStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment_status value']);
    exit;
}

try {
    $conn->beginTransaction();

    // Fetch current order state
    $oStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $oStmt->execute([$orderId]);
    $order = $oStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    $setClauses = [];
    $params     = [];

    // ── Status update ─────────────────────────────────────────────────────
    if ($newStatus) {
        $setClauses[] = 'status = ?';
        $params[]     = $newStatus;

        // When delivered: mark COD orders as paid (cash collected on delivery)
        if ($newStatus === 'delivered') {
            $currentPayStatus = $order['payment_status'];
            $currentMethod    = $order['payment_method'];

            // COD orders that are still pending get paid on delivery
            if (in_array($currentMethod, ['cod','cod_advance']) && $currentPayStatus === 'pending') {
                $setClauses[]  = 'payment_status = ?';
                $params[]      = 'paid';
                $newPayStatus  = 'paid'; // used below for commission trigger
            }
        }
    }

    // ── Payment status update ─────────────────────────────────────────────
    if ($newPayStatus && $newPayStatus !== ($order['payment_status'] ?? '')) {
        $setClauses[] = 'payment_status = ?';
        $params[]     = $newPayStatus;
    }

    if (empty($setClauses)) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Nothing to update']);
        exit;
    }

    $params[] = $orderId;
    $conn->prepare('UPDATE orders SET ' . implode(', ', $setClauses) . ' WHERE id = ?')
         ->execute($params);

    $conn->commit();

    // ── Credit referral commission when order is delivered OR payment is confirmed ──
    $shouldCredit = false;
    if ($newStatus === 'delivered') {
        $shouldCredit = true;
    }
    if (in_array($newPayStatus, ['paid','advance_paid','partial_paid'])) {
        $shouldCredit = true;
    }

    if ($shouldCredit) {
        creditReferralCommissionOnDelivery($conn, $orderId);
    }

    echo json_encode(['success' => true, 'order_id' => $orderId]);

} catch (Throwable $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/* =====================================================================
   creditReferralCommissionOnDelivery()

   Credits the referrer's wallet when the referred person's first order
   is delivered/paid. Uses the SNAPSHOTTED discount stored on the user
   at registration time (version control — admin changes only affect new
   referrals, not existing ones).

   Commission = the same ₹ amount the referred person saved as a discount
   (for percent type) OR the fixed amount (for fixed type).

   Safe to call multiple times — idempotent via referral_commission_credited flag.
===================================================================== */
function creditReferralCommissionOnDelivery(PDO $conn, int $orderId): void {
    try {
        // Lock order row to prevent double-credit race conditions
        $conn->beginTransaction();

        $oStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order || !empty($order['referral_commission_credited'])) {
            $conn->rollBack();
            return; // already credited or order not found
        }

        $userId = (int)$order['user_id'];

        // Referral program must be enabled
        $promo = $conn->query(
            "SELECT referral_enabled, referral_type, referral_percent, referral_amount FROM promo_settings WHERE id=1"
        )->fetch(PDO::FETCH_ASSOC);

        if (empty($promo['referral_enabled'])) {
            $conn->rollBack();
            return;
        }

        // Get the referred user's data including snapshotted discount
        $uStmt = $conn->prepare(
            "SELECT referred_by,
                    COALESCE(referral_discount_percent, 0) AS referral_discount_percent,
                    COALESCE(referral_discount_type, 'percent') AS referral_discount_type,
                    COALESCE(referral_discount_amount, 0) AS referral_discount_amount
             FROM users WHERE id = ? LIMIT 1"
        );
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);

        $referrerId = (int)($uRow['referred_by'] ?? 0);
        if (!$referrerId) {
            $conn->rollBack();
            return; // this user wasn't referred by anyone
        }

        // Only credit on the FIRST successfully completed order
        $cntStmt = $conn->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE user_id = ?
               AND payment_status IN ('paid','advance_paid','partial_paid')
               AND id != ?"
        );
        $cntStmt->execute([$userId, $orderId]);
        if ((int)$cntStmt->fetchColumn() > 0) {
            $conn->rollBack();
            return; // not the first paid order
        }

        // Determine commission using SNAPSHOTTED rate
        $snapType    = $uRow['referral_discount_type']    ?? null;
        $snapPercent = floatval($uRow['referral_discount_percent'] ?? 0);
        $snapAmount  = floatval($uRow['referral_discount_amount']  ?? 0);

        $hasSnapshot = $snapType && ($snapPercent > 0 || $snapAmount > 0);

        $referralDiscountOnOrder = floatval($order['referral_discount'] ?? 0);

        if ($hasSnapshot && $snapType === 'fixed') {
            // Fixed type: referrer earns the fixed amount
            $commission        = $snapAmount;
            $commissionPercent = 0;
        } elseif ($hasSnapshot && $snapType === 'percent') {
            // Percent type: referrer earns the same ₹ amount the referee saved
            $commission        = ($referralDiscountOnOrder > 0)
                                 ? round($referralDiscountOnOrder, 2)
                                 : round(floatval($order['overall_total']) * $snapPercent / 100, 2);
            $commissionPercent = $snapPercent;
        } elseif ($promo['referral_type'] === 'fixed') {
            // Fallback (pre-snapshot users) — fixed
            $commission        = floatval($promo['referral_amount'] ?? 0);
            $commissionPercent = 0;
        } else {
            // Fallback (pre-snapshot users) — percent
            $pct               = floatval($promo['referral_percent'] ?? 0);
            $commission        = ($referralDiscountOnOrder > 0)
                                 ? round($referralDiscountOnOrder, 2)
                                 : round(floatval($order['overall_total']) * $pct / 100, 2);
            $commissionPercent = $pct;
        }

        if ($commission <= 0) {
            $conn->rollBack();
            return;
        }

        // Credit wallet
        $conn->prepare('UPDATE users SET referral_wallet = referral_wallet + ? WHERE id = ?')
             ->execute([$commission, $referrerId]);

        // Record the transaction
        $conn->prepare(
            "INSERT INTO referral_transactions
                (earner_id, referred_id, order_id, order_amount, percent, earned_amount)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $referrerId,
            $userId,
            $orderId,
            floatval($order['overall_total']),
            $commissionPercent,
            $commission,
        ]);

        // Mark order as credited (prevents double-credit)
        $conn->prepare('UPDATE orders SET referral_commission_credited = 1 WHERE id = ?')
             ->execute([$orderId]);

        $conn->commit();

        error_log("[Referral] Commission ₹{$commission} credited to user #{$referrerId} for order #{$orderId}");

    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('[Referral] creditReferralCommissionOnDelivery error: ' . $e->getMessage());
    }
}