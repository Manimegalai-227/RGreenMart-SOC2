<?php
/************************************************************
 * CREATE SHIPMENT – FIXED (MINIMAL SAFE PATCHES ONLY)
 ************************************************************/

/* ========= GLOBAL HIT LOG ========= */
file_put_contents(
    __DIR__ . '/shipment_debug.log',
    date('Y-m-d H:i:s') . " | HIT | " . json_encode($_POST) . PHP_EOL,
    FILE_APPEND
);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../../dbconf.php';
require_once __DIR__ . '/../shiprocket.php';

// ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$orderId = intval($_POST['order_id'] ?? 0);
if (!$orderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required']);
    exit;
}

try {

    /* =====================================================
       🔒 BLOCK DUPLICATE SHIPMENT
    ===================================================== */
    $chk = $conn->prepare("
        SELECT shipment_id, awb 
        FROM shipments 
        WHERE order_id = ?
        LIMIT 1
    ");
    $chk->execute([$orderId]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing && !empty($existing['awb'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Shipment already created',
            'shipment_id' => $existing['shipment_id'],
            'awb' => $existing['awb']
        ]);
        exit;
    }

    // Shipment row exists but AWB not yet saved — try to fetch AWB from Shiprocket
    if ($existing && empty($existing['awb']) && !empty($existing['shipment_id'])) {
        try {
            $client = shiprocketClient();
            $detailResp = $client->request('GET', '/shipments/' . $existing['shipment_id']);
            $existingAwb = $detailResp['awb']
                ?? $detailResp['data']['awb']
                ?? $detailResp['shipment_track'][0]['awb_code']
                ?? null;
            if ($existingAwb) {
                $conn->prepare("UPDATE shipments SET awb=?, status='assigned' WHERE shipment_id=?")
                     ->execute([$existingAwb, $existing['shipment_id']]);
                $conn->prepare("UPDATE orders SET awb=?, awb_status='assigned' WHERE id=?")
                     ->execute([$existingAwb, $orderId]);
                echo json_encode([
                    'success' => true,
                    'message' => 'AWB recovered from Shiprocket',
                    'shipment_id' => $existing['shipment_id'],
                    'awb' => $existingAwb
                ]);
                exit;
            }
        } catch (Exception $e) {
            // Could not recover; fall through to normal flow which will re-use existing shipment_id
        }
    }

    /* ================= STEP 1: FETCH ORDER ================= */

    $stmt = $conn->prepare("
        SELECT o.*,
               ua.contact_name, ua.contact_mobile, ua.address_line1, ua.address_line2,
               ua.city, ua.state, ua.pincode,
               u.email AS user_email,
               u.mobile AS user_mobile
        FROM orders o
        LEFT JOIN user_addresses ua ON o.address_id = ua.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception('Order not found');

    /* ================= STEP 2: PHONE ================= */

    $normalize_phone = function($p) {
        $p = preg_replace('/\D+/', '', (string)$p);
        if (strlen($p) > 10) $p = substr($p, -10);
        return preg_match('/^[6-9][0-9]{9}$/', $p) ? $p : null;
    };

    $phone = $normalize_phone($order['contact_mobile'])
          ?: $normalize_phone($order['user_mobile']);

    if (!$phone) throw new Exception('Invalid phone number');

    /* ================= STEP 3: ADDRESS ================= */

    $billingAddress = trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? ''));
    if (!$billingAddress || !$order['city'] || !$order['state'] || !$order['pincode']) {
        throw new Exception('Incomplete address');
    }

    $pickupLocation = trim($_ENV['SHIPROCKET_PICKUP_LOC_NAME'] ?? '');
    $pickupPincode  = trim($_ENV['SHIPROCKET_PICKUP_PINCODE'] ?? '');
    if (!$pickupLocation || !$pickupPincode) {
        throw new Exception('Pickup location or pincode missing');
    }

    /* ================= STEP 4: ITEMS ================= */

    $stmt = $conn->prepare("
        SELECT oi.*, it.name AS item_name
        FROM order_items oi
        LEFT JOIN items it ON oi.item_id = it.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) throw new Exception('No items found');

    $orderItems = [];
    $subTotal = 0;
    $totalWeight = 0;

    foreach ($items as $it) {
        $price  = $it['variant_price'] ?? $it['discounted_price'] ?? $it['original_price'];
        $qty    = max(1, (int)$it['quantity']);
        $weight = max(0.5, floatval($it['variant_weight_value'] ?? 0.5));

        $subTotal += $price * $qty;
        $totalWeight += $weight * $qty;

        $orderItems[] = [
            'name' => $it['item_name'] ?? ('Item #' . $it['item_id']),
            'sku'  => 'SKU' . $it['item_id'],
            'units' => $qty,
            'selling_price' => round($price, 2)
        ];
    }

    /* ================= STEP 5: NAME ================= */

    $fullName = preg_replace('/\s+/', ' ', trim($order['contact_name'] ?? 'Customer'));
    $parts = explode(' ', $fullName, 2);
    $firstName = $parts[0] ?? 'Customer';
    $lastName  = $parts[1] ?? 'NA';

    /* ================= STEP 6: PAYLOAD ================= */

    $payload = [
        'order_id' => 'ERP-' . $orderId,
        'order_date' => date('Y-m-d H:i:s'),
        'pickup_location' => $pickupLocation,
        'billing_customer_name' => $fullName,
        'billing_first_name' => $firstName,
        'billing_last_name'  => $lastName,
        'billing_phone' => $phone,
        'billing_email' => $order['user_email'],
        'billing_address' => $billingAddress,
        'billing_city' => $order['city'],
        'billing_state' => $order['state'],
        'billing_pincode' => (string)$order['pincode'],
        'billing_country' => 'India',

        'shipping_is_billing' => 1,
        'payment_method' => in_array(strtolower($order['payment_method']), ['cod', 'cod_advance']) ? 'COD' : 'Prepaid',

        'order_items' => $orderItems,
        'weight' => round($totalWeight, 2),
        'length' => 10,
        'breadth' => 10,
        'height' => 5,
        'sub_total' => round($subTotal, 2)
    ];

    /* ================= STEP 7: CREATE SHIPMENT ================= */

    $client = shiprocketClient();
    $createResp = $client->createOrder($payload);

    $shipmentId = $createResp['shipment_id'] ?? null;
    if (!$shipmentId) throw new Exception('Shipment not created');

    /* =====================================================
       ✅ SAVE SHIPMENT IMMEDIATELY (CRITICAL FIX)
    ===================================================== */
    $conn->prepare("
        INSERT INTO shipments (order_id, shiprocket_order_id, shipment_id, status, created_at)
        VALUES (?,?,?,?,NOW())
    ")->execute([$orderId, $payload['order_id'], $shipmentId, 'created']);

    /* ================= STEP 8: SERVICEABILITY ================= */

    $serviceability = $client->request(
        'GET',
        '/courier/serviceability/?' . http_build_query([
            'pickup_postcode'   => $pickupPincode,
            'delivery_postcode' => $order['pincode'],
            'weight'            => round($totalWeight, 2),
            'cod'               => strtolower($payload['payment_method']) === 'cod' ? 1 : 0
        ])
    );

    // 🔧 FIX: SAFE CHECK
    $companies = $serviceability['data']['available_courier_companies'] ?? [];
    if (!$companies) {
        throw new Exception('No courier available for this pincode');
    }

    $savedCourierId = $order['courier_company_id'] ?? null;
    $courier = null;
    if ($savedCourierId) {
        foreach ($companies as $c) {
            if ((int)$c['courier_company_id'] === (int)$savedCourierId) {
                $courier = $c; break;
            }
        }
    }
    // Fallback to Shiprocket recommended if saved ID not found
    if (!$courier) {
        $recommendedId = $serviceability['data']['shiprocket_recommended_courier_id'] ?? null;
        foreach ($companies as $c) {
            if ($recommendedId && (int)$c['courier_company_id'] === (int)$recommendedId) {
                $courier = $c; break;
            }
        }
        $courier = $courier ?? $companies[0];
    }

    /* ================= STEP 9: ASSIGN AWB ================= */

    $assignResp = $client->assignAwb([
        'shipment_id' => (string)$shipmentId,
        'courier_id'  => (string)$courier['courier_company_id']
    ]);

    // Shiprocket returns AWB in different paths depending on API version
    $awb = $assignResp['data']['awb_assign_status_details'][0]['awb_number']
        ?? $assignResp['data']['awb']
        ?? $assignResp['awb']
        ?? null;

    // Handle "Awb Already Assigned" — AWB was assigned on a previous attempt;
    // fetch it from the shipment details endpoint
    if (!$awb) {
        $alreadyMsg = strtolower($assignResp['response']['data'] ?? $assignResp['message'] ?? '');
        if (str_contains($alreadyMsg, 'already assigned') || ($assignResp['awb_assign_status'] ?? 1) === 0) {
            try {
                $detailResp = $client->request('GET', '/shipments/' . $shipmentId);
                $awb = $detailResp['awb']
                    ?? $detailResp['data']['awb']
                    ?? $detailResp['shipment_track'][0]['awb_code']
                    ?? null;
            } catch (Exception $ex) {
                // log but continue to throw below
                file_put_contents(
                    __DIR__ . '/shipment_debug.log',
                    "AWB_FETCH_ERR: " . $ex->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
            }
        }
    }

    if (!$awb) {
        file_put_contents(
            __DIR__ . '/shipment_debug.log',
            "AWB_RESP: " . json_encode($assignResp) . PHP_EOL,
            FILE_APPEND
        );
        throw new Exception('AWB not assigned. Response: ' . json_encode($assignResp));
    }

    /* ================= STEP 10: UPDATE SHIPMENT ================= */

    $conn->prepare("
        UPDATE shipments
        SET awb=?, courier_id=?, courier_name=?, courier_code=?, status='assigned', raw_response=?
        WHERE shipment_id=?
    ")->execute([
        $awb,
        $courier['courier_company_id'],
        $courier['courier_name'],
        $courier['courier_company_code'] ?? null, // 🔧 FIX
        json_encode($assignResp),
        $shipmentId
    ]);

    /* ================= STEP 11: UPDATE ORDER ================= */

    $conn->prepare("
        UPDATE orders
        SET shipment_id=?, awb=?, courier_name=?, awb_status='assigned'

        WHERE id=?
    ")->execute([$shipmentId, $awb, $courier['courier_name'], $orderId]);


    echo json_encode([
        'success' => true,
        'shipment_id' => $shipmentId,
        'awb' => $awb,
        'courier' => $courier['courier_name']
    ]);

} catch (Throwable $e) {

    file_put_contents(
        __DIR__ . '/shipment_debug.log',
        "ERROR: " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}