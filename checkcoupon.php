<?php
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Check if coupons are enabled
try {
    $promo = $conn->query("SELECT coupon_enabled FROM promo_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
if (!$promo || !$promo['coupon_enabled']) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Coupon codes are not available at the moment.']);
    exit;
}

$couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
$cartTotal  = floatval($_POST['cart_total'] ?? 0);

if (!$couponCode) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Please enter a coupon code.']);
    exit;
}

try {
    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        SELECT id, code, discount_type, discount_value, expiry_date
        FROM coupons
        WHERE UPPER(code) = ?
          AND is_active = 1
          AND expiry_date >= ?
        LIMIT 1
    ");
    $stmt->execute([$couponCode, $now]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        // Distinguish expired vs invalid
        $check = $conn->prepare("SELECT id FROM coupons WHERE UPPER(code) = ? LIMIT 1");
        $check->execute([$couponCode]);
        $msg = $check->fetch() ? 'This coupon has expired.' : 'Invalid coupon code.';
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    // Calculate discount
    $discountAmount = 0;
    if ($coupon['discount_type'] === 'percent') {
        $discountAmount = round($cartTotal * $coupon['discount_value'] / 100, 2);
        $displayText    = $coupon['discount_value'] . '% off';
    } else {
        $discountAmount = min($coupon['discount_value'], $cartTotal);
        $displayText    = '₹' . number_format($coupon['discount_value'], 2) . ' off';
    }

    ob_end_clean();
    echo json_encode([
        'success'         => true,
        'coupon_code'     => $coupon['code'],
        'discount_type'   => $coupon['discount_type'],
        'discount_value'  => $coupon['discount_value'],
        'discount_amount' => $discountAmount,
        'display_text'    => $displayText,
        'expiry'          => date('d M Y h:i A', strtotime($coupon['expiry_date'])),
        'message'         => "Coupon applied! You save $displayText",
    ]);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}