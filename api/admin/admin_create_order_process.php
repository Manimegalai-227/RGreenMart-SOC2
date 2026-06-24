<?php
/**
 * /api/admin/admin_create_order_process.php
 *
 * Backend for admin-created orders.
 * Mirrors create_order.php but accepts user_id from request body
 * instead of relying on $_SESSION['user_id'].
 */

session_start();
header("Content-Type: application/json");

// Admin-only
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/vendor/autoload.php";

// ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");

use Razorpay\Api\Api;

/* -----------------------------------------------
   1. Parse request
------------------------------------------------ */
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$user_id      = intval($data["user_id"]    ?? 0);
$address_id   = intval($data["address_id"] ?? 0);
$cartItems    = $data["cart"]              ?? [];
$paymentMethod = strtolower(trim($data['payment_method'] ?? 'online')) === 'cod' ? 'cod' : 'online';

if (!$user_id || !$address_id || empty($cartItems)) {
    echo json_encode(["success" => false, "message" => "Missing required fields (user, address or cart)."]);
    exit;
}

/* -----------------------------------------------
   2. Validate user exists
------------------------------------------------ */
$stmtU = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
$stmtU->execute([$user_id]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found."]);
    exit;
}

try {
    $conn->beginTransaction();

    /* -----------------------------------------------
       3. New enquiry number
    ------------------------------------------------ */
    $stmt = $conn->query("SELECT last_enquiry_number FROM settings LIMIT 1 FOR UPDATE");
    $row  = $stmt->fetch();
    $enquiryNumber = ($row['last_enquiry_number'] ?? 1000) + 1;
    $conn->exec("UPDATE settings SET last_enquiry_number = $enquiryNumber");

    /* -----------------------------------------------
       4. Validate address (belongs to user)
    ------------------------------------------------ */
    $stmtA = $conn->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
    $stmtA->execute([$address_id, $user_id]);
    $address = $stmtA->fetch(PDO::FETCH_ASSOC);
    if (!$address) {
        throw new Exception("Invalid address for this user.");
    }

    /* -----------------------------------------------
       5. Totals
    ------------------------------------------------ */
    $subtotal       = (float)($data["subtotal"]        ?? 0);
    $shippingCharge = (float)($data["shipping_charge"] ?? 0);
    $packingCharge  = (float)($data["packing_charge"]  ?? 0);
    $netTotal       = $subtotal + $shippingCharge + $packingCharge;
    $overallTotal   = (float)($data["overall_total"]   ?? $netTotal);

    /* -----------------------------------------------
       6. Minimum order check
    ------------------------------------------------ */
    $minRow = $conn->query("SELECT minimum_order FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $minimumOrder = isset($minRow['minimum_order']) ? (float)$minRow['minimum_order'] : 0;
    if ($overallTotal < $minimumOrder) {
        throw new Exception("Minimum order amount is ₹" . number_format($minimumOrder, 2));
    }

    /* -----------------------------------------------
       7. COD check
    ------------------------------------------------ */
    $stmtCod = $conn->prepare("SELECT cod_enabled FROM shipping_settings WHERE id = 1");
    $stmtCod->execute();
    $shippingSettings = $stmtCod->fetch(PDO::FETCH_ASSOC);
    $codEnabled = $shippingSettings['cod_enabled'] ?? 0;

    if ($paymentMethod === 'cod' && !$codEnabled) {
        throw new Exception("Cash on Delivery is currently disabled.");
    }

    /* -----------------------------------------------
       8. Insert order
    ------------------------------------------------ */
    $stmt = $conn->prepare("
        INSERT INTO orders (
            enquiry_no, user_id, address_id,
            subtotal, packing_charge, shipping_charge,
            net_total, overall_total,
            courier_company_id, courier_name,
            estimated_delivery_days, etd,
            status, payment_method, payment_status,
            created_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            'pending', ?, 'pending',
            NOW()
        )
    ");
    $stmt->execute([
        $enquiryNumber,
        $user_id,
        $address_id,
        $subtotal,
        $packingCharge,
        $shippingCharge,
        $netTotal,
        $overallTotal,
        $data['courier_company_id'] ?? null,
        $data['courier_name']       ?? null,
        $data['courier_eta']        ?? null,
        // Sanitize etd: MySQL DATE/DATETIME column rejects strings like "Mar 11, 2026"
        (function() use ($data) {
            $v = $data['courier_etd'] ?? null;
            if (!$v) return null;
            // Only keep values that look like a MySQL date (yyyy-mm-dd...)
            return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? $v : null;
        })(),
        $paymentMethod,
    ]);
    $orderId = $conn->lastInsertId();

    /* -----------------------------------------------
       9. Detect order_items columns
    ------------------------------------------------ */
    $dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
    $colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'order_items'");
    $colsStmt->execute([$dbName]);
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $hasVariantId         = in_array('variant_id',          $cols);
    $hasVariantWeightValue = in_array('variant_weight_value', $cols);
    $hasVariantWeightUnit  = in_array('variant_weight_unit',  $cols);
    $hasVariantPrice       = in_array('variant_price',        $cols);

    $baseCols = ['order_id','item_id','original_price','discount_percentage','discounted_price','quantity','amount'];
    if ($hasVariantId)          $baseCols[] = 'variant_id';
    if ($hasVariantWeightValue) $baseCols[] = 'variant_weight_value';
    if ($hasVariantWeightUnit)  $baseCols[] = 'variant_weight_unit';
    if ($hasVariantPrice)       $baseCols[] = 'variant_price';

    $placeholders = implode(', ', array_fill(0, count($baseCols), '?'));
    $insertSql    = 'INSERT INTO order_items (' . implode(',', $baseCols) . ') VALUES (' . $placeholders . ')';
    $stmtItem     = $conn->prepare($insertSql);

    foreach ($cartItems as $item) {
        $qty          = $item["quantity"] ?? $item['qty'] ?? 1;
        // Use variant_price as authoritative selling price when present
        $sellingPrice = (float)($item['variant_price'] ?? $item["price"] ?? 0);
        $originalPrice = (float)($item['variant_old_price'] ?? $item["oldamt"] ?? $sellingPrice);
        $discountPct  = (float)($item['variant_discount'] ?? $item["discountRate"] ?? 0);
        // Recompute from actual prices if discount% not provided
        if ($discountPct <= 0 && $originalPrice > $sellingPrice) {
            $discountPct = floor(($originalPrice - $sellingPrice) / $originalPrice * 100);
        }

        $params = [
            $orderId,
            $item["id"],
            $originalPrice,
            $discountPct,
            $sellingPrice,
            $qty,
            $sellingPrice * $qty
        ];
        if ($hasVariantId)          $params[] = $item['variant_id']     ?? null;
        if ($hasVariantWeightValue) $params[] = $item['variant_weight']  ?? ($item['weight_value'] ?? null);
        if ($hasVariantWeightUnit)  $params[] = $item['variant_unit']    ?? ($item['weight_unit']  ?? null);
        if ($hasVariantPrice)       $params[] = $sellingPrice;

        $stmtItem->execute($params);
    }

    /* -----------------------------------------------
       10. COD — set status to ordered, commit and return
    ------------------------------------------------ */
    if ($paymentMethod === 'cod') {
        // Mark as ordered so it enters the fulfilment workflow.
        // payment_status stays 'pending' until admin marks as paid after cash receipt.
        $conn->prepare("UPDATE orders SET status='ordered' WHERE id=?")->execute([$orderId]);
        $conn->commit();
        echo json_encode([
            "success"        => true,
            "order_id"       => $orderId,
            "payment_method" => "cod",
            "message"        => "Order placed via Cash on Delivery. Awaiting payment."
        ]);
        exit;
    }

    /* -----------------------------------------------
       11. Online — create Razorpay order
    ------------------------------------------------ */
    $api = new Api($_ENV['RAZORPAY_KEY_ID'], $_ENV['RAZORPAY_KEY_SECRET']);
    $razorpayOrder = $api->order->create([
        "receipt"  => "ADMIN_ORDER_" . $orderId,
        "amount"   => round($overallTotal * 100),
        "currency" => "INR"
    ]);
    $razorpayOrderId = $razorpayOrder["id"];

    $conn->prepare("UPDATE orders SET razorpay_order_id = ?, payment_method = 'online' WHERE id = ?")
         ->execute([$razorpayOrderId, $orderId]);

    $conn->commit();

    // Return Razorpay details so admin can share payment link or record manually
    echo json_encode([
        "success"           => true,
        "order_id"          => $orderId,
        "razorpay_order_id" => $razorpayOrderId,
        "key"               => $_ENV['RAZORPAY_KEY_ID'],
        "amount"            => round($overallTotal * 100),
        "message"           => "Online order created. Razorpay Order ID: " . $razorpayOrderId
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}