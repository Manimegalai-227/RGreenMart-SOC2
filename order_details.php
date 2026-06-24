<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ── Display dates in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");


// Check login
if (!isset($_SESSION["user_id"])) {
    $_SESSION["redirect_after_login"] = "my_orders.php";
    header("Location: login.php");
    exit();
}

$userId = $_SESSION["user_id"];

// Get order ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid order ID");
}
$orderId = (int)$_GET['id'];

// Fetch order details along with user address
$sqlOrder = "
    SELECT o.*, ua.contact_name, ua.contact_mobile, ua.address_line1, ua.address_line2, ua.city, ua.state, ua.pincode 
    FROM orders o
    LEFT JOIN user_addresses ua ON o.address_id = ua.id
    WHERE o.id = ? AND o.user_id = ?
";
$stmtOrder = $conn->prepare($sqlOrder);
$stmtOrder->execute([$orderId, $userId]);
$order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}

// Fetch order items with product and variant info (schema-safe)
$dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
$colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'order_items'");
$colsStmt->execute([$dbName]);
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
$hasVariantId = in_array('variant_id', $cols);

if ($hasVariantId) {
    $sqlItems = "
        SELECT 
            oi.*, 
            i.name AS product_name,
            v.weight_value AS variant_weight,
            v.weight_unit AS variant_unit,
            v.price AS variant_price,
            v.old_price AS variant_old_price,
            v.discount AS variant_discount,
            (
                SELECT COALESCE(compressed_path, image_path) FROM item_images
                WHERE item_images.item_id = i.id
                ORDER BY is_primary DESC, sort_order ASC LIMIT 1
            ) AS product_image
        FROM order_items oi
        LEFT JOIN items i ON oi.item_id = i.id
        LEFT JOIN item_variants v ON oi.variant_id = v.id
        WHERE oi.order_id = ?
    ";
} else {
    // No variant_id on order_items — try to infer variant by matching price
    $hasVariantPrice = in_array('variant_price', $cols);
    if ($hasVariantPrice) {
        $priceExpr = "COALESCE(oi.variant_price, oi.discounted_price, oi.original_price)";
    } else {
        $priceExpr = "COALESCE(oi.discounted_price, oi.original_price)";
    }

    $sqlItems = "
        SELECT 
            oi.*, 
            i.name AS product_name,
            (
                SELECT weight_value FROM item_variants 
                WHERE item_variants.item_id = oi.item_id 
                AND item_variants.price = " . $priceExpr . "
                LIMIT 1
            ) AS variant_weight,
            (
                SELECT weight_unit FROM item_variants 
                WHERE item_variants.item_id = oi.item_id 
                AND item_variants.price = " . $priceExpr . "
                LIMIT 1
            ) AS variant_unit,
            (
                SELECT price FROM item_variants 
                WHERE item_variants.item_id = oi.item_id 
                AND item_variants.price = " . $priceExpr . "
                LIMIT 1
            ) AS variant_price,
            NULL AS variant_old_price,
            NULL AS variant_discount,
            (
                SELECT COALESCE(compressed_path, image_path) FROM item_images
                WHERE item_images.item_id = i.id
                ORDER BY is_primary DESC, sort_order ASC LIMIT 1
            ) AS product_image
        FROM order_items oi
        LEFT JOIN items i ON oi.item_id = i.id
        WHERE oi.order_id = ?
    ";
}

$stmtItems = $conn->prepare($sqlItems);
$stmtItems->execute([$orderId]);
$orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Aggregate distinct variant weights present in this order for a brief summary
$variantWeightsArr = [];
foreach ($orderItems as $it) {
    $w = trim((string)($it['variant_weight'] ?? $it['variant_weight_value'] ?? ''));
    $u = trim((string)($it['variant_unit'] ?? $it['variant_weight_unit'] ?? ''));
    if ($w !== '') {
        $entry = trim($w . ' ' . $u);
        if (!empty($entry)) $variantWeightsArr[$entry] = true;
    }
}
$variantWeightsSummary = implode(', ', array_keys($variantWeightsArr));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order #<?= $order['id'] ?> Details</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="/toast.js"></script>
<style>
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f3f4f6; }

/* ── Status badges ── */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.paid     { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.pending  { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
.failed   { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.badge-cod     { background:#fff4e6; color:#92400e; border:1px solid #f59e0b; }
.badge-online  { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.badge-advance { background:#fef3c7; color:#78350f; border:1px solid #f59e0b; }

.shipment_badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:12px; font-weight:700; text-transform:uppercase; }
.shipment_badge.not_shipped { background:#f3f4f6; color:#4b5563; border:1px solid #d1d5db; }
.shipment_badge.shipped     { background:#dbeafe; color:#1d4ed8; border:1px solid #93c5fd; }
.shipment_badge.in_transit  { background:#fef9c3; color:#854d0e; border:1px solid #fde047; }
.shipment_badge.delivered   { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.shipment_badge.cancelled   { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
.shipment_badge.error       { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }

/* ── Item card ── */
.item-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    padding: 14px 16px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: box-shadow 0.15s;
}
.item-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.item-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.item-body { flex: 1; min-width: 0; }
.item-name {
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 6px;
}
.item-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 8px;
}
.tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 9px;
    border-radius: 20px;
}
.tag-weight   { background: #ede9fe; color: #6d28d9; }
.tag-qty      { background: #e0f2fe; color: #0369a1; }
.tag-discount { background: #fee2e2; color: #b91c1c; }
.item-price-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.item-selling-price {
    font-size: 16px;
    font-weight: 700;
    color: #059669;
}
.item-mrp {
    font-size: 13px;
    color: #9ca3af;
    text-decoration: line-through;
}
.item-amount {
    margin-left: auto;
    font-size: 14px;
    font-weight: 700;
    color: #1f2937;
    white-space: nowrap;
}

/* ── Info card ── */
.info-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    padding: 18px 20px;
}
.info-row {
    display: flex;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13.5px;
    align-items: flex-start;
}
.info-row:last-child { border-bottom: none; }
.info-label {
    font-weight: 600;
    color: #6b7280;
    min-width: 110px;
    flex-shrink: 0;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding-top: 2px;
}
.info-value { color: #1f2937; flex: 1; }

/* ── Totals ── */
.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    font-size: 14px;
    border-bottom: 1px solid #f3f4f6;
}
.totals-row:last-child { border-bottom: none; }
.totals-final {
    font-size: 17px;
    font-weight: 800;
    color: #1f2937;
    border-top: 2px solid #e5e7eb;
    margin-top: 4px;
    padding-top: 10px;
}
</style>
</head>
<body>
<?php include "includes/header.php"; ?>

<?php
// Fetch shipment info
$stmtS = $conn->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$stmtS->execute([$orderId]);
$shipment = $stmtS->fetch(PDO::FETCH_ASSOC);

$liveShipment = null;
$liveTracking = null;
$displayStatus = $shipment['status'] ?? null;
$latestEvent = null;
if ($shipment) {
    try {
        require_once __DIR__ . '/api/shiprocket.php';
        $client = shiprocketClient();
        if (!empty($shipment['shipment_id'])) {
            try {
                $resp = $client->request('GET', '/shipments/' . urlencode($shipment['shipment_id']));
                $liveShipment = $resp;
                if (is_array($resp)) {
                    if (!empty($resp['status'])) $displayStatus = $resp['status'];
                    elseif (!empty($resp['data']['status'])) $displayStatus = $resp['data']['status'];
                    elseif (!empty($resp['shipment']['status'])) $displayStatus = $resp['shipment']['status'];
                }
            } catch (Throwable $e) { $liveShipment = ['error' => $e->getMessage()]; }
        }
        if (!empty($shipment['awb'])) {
            try {
                $tr = $client->trackAwb($shipment['awb']);
                $liveTracking = $tr;
                $events = [];
                $data = $tr['data'] ?? $tr;
                if (isset($data['trackings']) && is_array($data['trackings'])) {
                    foreach ($data['trackings'] as $t) {
                        if (isset($t['tracking_data']))   $events = array_merge($events, $t['tracking_data']);
                        if (isset($t['tracking_details'])) $events = array_merge($events, $t['tracking_details']);
                        if (isset($t['timeline']))         $events = array_merge($events, $t['timeline']);
                    }
                } elseif (isset($data['tracking_data'])) { $events = $data['tracking_data']; }
                  elseif (isset($data['data']))           { $events = $data['data']; }
                if (!empty($events)) {
                    usort($events, function($a,$b){
                        $ta = strtotime($a['date'] ?? $a['time'] ?? $a['datetime'] ?? $a['created_at'] ?? 0);
                        $tb = strtotime($b['date'] ?? $b['time'] ?? $b['datetime'] ?? $b['created_at'] ?? 0);
                        return $tb - $ta;
                    });
                    $latestEvent   = $events[0];
                    $displayStatus = $latestEvent['status'] ?? $latestEvent['title'] ?? $displayStatus;
                }
            } catch (Throwable $e) { $liveTracking = ['error' => $e->getMessage()]; }
        }
    } catch (Throwable $e) {}
}
$badgeClass = 'shipped';
if (!empty($displayStatus)) {
    $k = strtolower($displayStatus);
    if (strpos($k,'deliv') !== false) $badgeClass = 'delivered';
    elseif (strpos($k,'out for') !== false || strpos($k,'transit') !== false) $badgeClass = 'in_transit';
    elseif (strpos($k,'cancel') !== false) $badgeClass = 'cancelled';
    elseif (!empty($liveShipment['error']) || !empty($liveTracking['error'])) $badgeClass = 'error';
    else $badgeClass = 'shipped';
}
$displayStatus = $displayStatus ?? 'Unknown';

// Payment helpers
$ps       = $order['payment_status'] ?? '';
$pm       = $order['payment_method'] ?? '';
$psLabel  = ($ps === 'advance_paid') ? 'Advance Paid' : (($ps === 'advance_pending') ? 'Advance Pending' : ucfirst($ps));
$psClass  = ($ps === 'advance_paid') ? 'paid' : $ps;

// Totals
$codAdvAmt      = floatval($order['cod_advance_amount'] ?? 0);
$isCodAdv       = (($pm === 'cod' || $pm === 'cod_advance') && $codAdvAmt > 0);
$codBalance     = $isCodAdv ? round($order['overall_total'] - $codAdvAmt, 2) : 0;
$couponCode     = trim($order['coupon_code'] ?? '');
$couponDiscount = floatval($order['coupon_discount_amount'] ?? 0);
$referralDiscount   = floatval($order['referral_discount'] ?? 0);
$walletAmountUsed   = floatval($order['wallet_amount_used'] ?? 0);
$codConvFee         = floatval($order['cod_convenience_fee'] ?? 0);
?>

<div style="max-width:900px; margin:0 auto; padding:20px 14px;">

    <!-- ── Page header ── -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px;">
        <div>
            <h1 style="font-size:22px; font-weight:800; color:#166534; margin:0;">Order #<?= $order['id'] ?></h1>
            <p style="font-size:12px; color:#9ca3af; margin:3px 0 0;">
                <?= date("d M Y, h:i A", strtotime($order['order_date'])) ?>
                &nbsp;·&nbsp; Enquiry #<?= htmlspecialchars($order['enquiry_no'] ?? '') ?>
            </p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <span class="status-badge <?= htmlspecialchars($psClass) ?>"><?= $psLabel ?></span>
            <?php if ($pm === 'cod_advance' || ($pm === 'cod' && $codAdvAmt > 0)): ?>
                <span class="status-badge badge-advance">COD + Advance</span>
            <?php elseif ($pm === 'cod'): ?>
                <span class="status-badge badge-cod">💵 COD</span>
            <?php else: ?>
                <span class="status-badge badge-online">✅ <?= strtoupper(htmlspecialchars($pm ?: 'Online')) ?></span>
            <?php endif; ?>
            <span style="font-size:13px; color:#6b7280;">Status: <strong style="color:#1f2937;"><?= ucfirst($order['status']) ?></strong></span>
        </div>
    </div>

    <!-- ── Two-col: delivery info + shipment ── -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">

        <!-- Delivery Info -->
        <div class="info-card">
            <p style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 10px;">Delivery Details</p>
            <div class="info-row">
                <span class="info-label">Name</span>
                <span class="info-value"><?= htmlspecialchars($order['contact_name'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Mobile</span>
                <span class="info-value"><?= htmlspecialchars($order['contact_mobile'] ?? '') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Address</span>
                <span class="info-value">
                    <?= htmlspecialchars(trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? ''))) ?>,
                    <?= htmlspecialchars($order['city'] ?? '') ?>,
                    <?= htmlspecialchars($order['state'] ?? '') ?> — <?= htmlspecialchars($order['pincode'] ?? '') ?>
                </span>
            </div>
            <?php if ($order['payment_status'] === 'paid' && !empty($order['razorpay_payment_id'])): ?>
            <div class="info-row">
                <span class="info-label">Payment ID</span>
                <span class="info-value" style="display:flex; align-items:center; gap:6px;">
                    <code id="paymentId" style="background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px;"><?= htmlspecialchars($order['razorpay_payment_id']) ?></code>
                    <i class="fa-regular fa-copy" style="cursor:pointer; color:#6b7280;" onclick="copyPaymentId()" title="Copy"></i>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Shipment Info -->
        <div class="info-card">
            <p style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 10px;">Shipment</p>
            <?php if ($shipment): ?>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                    <span style="font-weight:600; font-size:13px; color:#1f2937;">Tracking Status</span>
                    <span class="shipment_badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars(ucfirst($displayStatus)) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">AWB</span>
                    <span class="info-value" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars($shipment['awb'] ?? '—') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Courier</span>
                    <span class="info-value"><?= htmlspecialchars($shipment['courier_code'] ?? '—') ?></span>
                </div>
                <?php if (!empty($latestEvent)): ?>
                <div class="info-row">
                    <span class="info-label">Latest</span>
                    <span class="info-value" style="color:#374151;">
                        <?= htmlspecialchars($latestEvent['description'] ?? $latestEvent['status'] ?? $latestEvent['title'] ?? '') ?>
                        <br><span style="font-size:11px; color:#9ca3af;"><?= htmlspecialchars($latestEvent['date'] ?? $latestEvent['time'] ?? '') ?></span>
                    </span>
                </div>
                <?php endif; ?>
                <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                    <?php if ($shipment['label_url']): ?>
                        <a href="<?= htmlspecialchars($shipment['label_url']) ?>" target="_blank" style="font-size:12px; color:#4f46e5; text-decoration:none;">📦 Open Label</a>
                    <?php endif; ?>
                    <a href="/track_shipment.php?order_id=<?= $order['id'] ?>" style="font-size:12px; color:#4f46e5; text-decoration:none;">🔍 Full Tracking</a>
                </div>
            <?php else: ?>
                <div style="display:flex; align-items:center; gap:8px; padding:10px 0;">
                    <span style="font-size:20px;">📦</span>
                    <span style="color:#6b7280; font-size:13px;">Not shipped yet. Your order is being processed.</span>
                </div>
            <?php endif; ?>

            <?php if ($order['status'] === 'cancelled'): ?>
            <div style="margin-top:12px; padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px;">
                <p style="font-weight:700; color:#b91c1c; font-size:13px; margin:0 0 6px;">❌ Order Cancelled</p>
                <p style="font-size:12px; color:#dc2626; margin:2px 0;"><strong>At:</strong> <?= date("d M Y, h:i A", strtotime($order['cancelled_at'])) ?></p>
                <p style="font-size:12px; color:#dc2626; margin:2px 0;"><strong>By:</strong> <?= htmlspecialchars($order['cancelled_by']) ?></p>
                <p style="font-size:12px; color:#dc2626; margin:2px 0;"><strong>Reason:</strong> <?= htmlspecialchars($order['cancellation_reason']) ?></p>
                <p style="font-size:12px; color:#dc2626; margin:2px 0;"><strong>Refund:</strong> <?= htmlspecialchars($order['refund_status']) ?></p>
                <?php if (!empty($order['refund_payment_id'])): ?>
                <p style="font-size:12px; color:#dc2626; margin:4px 0 0; display:flex; gap:6px; align-items:center;">
                    <strong>Refund ID:</strong>
                    <span id="refundId" style="font-family:monospace;"><?= htmlspecialchars($order['refund_payment_id']) ?></span>
                    <button onclick="copyRefundId()" style="font-size:11px; background:#dc2626; color:#fff; border:none; padding:2px 8px; border-radius:4px; cursor:pointer;">Copy</button>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Items ── -->
    <div class="info-card" style="margin-bottom:14px;">
        <p style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 12px;">
            Items Ordered <span style="background:#e0e7ff; color:#3730a3; padding:1px 8px; border-radius:20px; font-size:11px; margin-left:6px;"><?= count($orderItems) ?></span>
        </p>
        <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($orderItems as $item):
            $img = $item['product_image'] ?? null;
            $imgSrc = $img ? '/admin/' . ltrim($img, '/') : '/images/default.jpg';
            $unitPrice   = floatval($item['variant_price'] ?? $item['discounted_price'] ?? 0);
            $mrpPrice    = floatval($item['original_price'] ?? 0);
            $varOldPrice = floatval($item['variant_old_price'] ?? 0);
            $varDiscount = floatval($item['variant_discount'] ?? 0);
            if ($mrpPrice <= 0 || $mrpPrice < $unitPrice) {
                if ($varOldPrice > 0) { $mrpPrice = $varOldPrice; }
                elseif ($varDiscount > 0 && $varDiscount < 100 && $unitPrice > 0) { $mrpPrice = $unitPrice / (1 - $varDiscount / 100); }
                else { $mrpPrice = $unitPrice; }
            }
            $discPct = ($mrpPrice > 0 && $mrpPrice > $unitPrice) ? (int)floor((($mrpPrice - $unitPrice) / $mrpPrice) * 100) : 0;
            // Clean weight display — remove .00
            $wVal = $item['variant_weight'] ?? $item['variant_weight_value'] ?? '';
            $wUnit = $item['variant_unit'] ?? $item['variant_weight_unit'] ?? '';
            $weightDisplay = '';
            if ($wVal !== '' && $wVal !== null) {
                $wClean = (floatval($wVal) == floor(floatval($wVal))) ? (int)floatval($wVal) : floatval($wVal);
                $weightDisplay = $wClean . ' ' . $wUnit;
            }
        ?>
        <div class="item-card">
            <img class="item-img" src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" onerror="this.src='/images/default.jpg'">
            <div class="item-body">
                <p class="item-name"><?= htmlspecialchars($item['product_name']) ?></p>
                <div class="item-tags">
                    <?php if ($weightDisplay): ?>
                        <span class="tag tag-weight"><?= htmlspecialchars($weightDisplay) ?></span>
                    <?php endif; ?>
                    <span class="tag tag-qty">Qty: <?= $item['quantity'] ?></span>
                    <?php if ($discPct > 0): ?>
                        <span class="tag tag-discount"><?= $discPct ?>% OFF</span>
                    <?php endif; ?>
                </div>
                <div class="item-price-row">
                    <span class="item-selling-price">₹<?= number_format($unitPrice, 2) ?></span>
                    <?php if ($mrpPrice > $unitPrice): ?>
                        <span class="item-mrp">₹<?= number_format($mrpPrice, 2) ?></span>
                    <?php endif; ?>
                    <span style="font-size:12px; color:#9ca3af;">× <?= $item['quantity'] ?></span>
                    <span class="item-amount">= ₹<?= number_format($item['amount'], 2) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Order Totals ── -->
    <div class="info-card" style="margin-bottom:14px;">
        <p style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 10px;">Order Summary</p>

        <div class="totals-row">
            <span style="color:#6b7280;">Items Subtotal</span>
            <span style="font-weight:600;">₹<?= number_format($order['subtotal'], 2) ?></span>
        </div>
        <div class="totals-row">
            <span style="color:#6b7280;">Shipping Charge</span>
            <span style="font-weight:600;">₹<?= number_format($order['shipping_charge'], 2) ?></span>
        </div>
        <?php if ($codConvFee > 0): ?>
        <div class="totals-row">
            <span style="color:#92400e;">🏷 COD Convenience Fee</span>
            <span style="font-weight:600; color:#92400e;">₹<?= number_format($codConvFee, 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($couponCode !== '' && $couponDiscount > 0): ?>
        <div class="totals-row">
            <span style="color:#166534;">🎟 Coupon (<?= htmlspecialchars($couponCode) ?>)</span>
            <span style="font-weight:600; color:#166534;">−₹<?= number_format($couponDiscount, 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($referralDiscount > 0): ?>
        <div class="totals-row">
            <span style="color:#6b21a8;">🎁 Referral Discount</span>
            <span style="font-weight:600; color:#6b21a8;">−₹<?= number_format($referralDiscount, 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($walletAmountUsed > 0): ?>
        <div class="totals-row">
            <span style="color:#1d4ed8;">💜 Wallet Used</span>
            <span style="font-weight:600; color:#1d4ed8;">−₹<?= number_format($walletAmountUsed, 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="totals-row totals-final">
            <span>Order Total</span>
            <span>₹<?= number_format($order['overall_total'], 2) ?></span>
        </div>

        <?php if ($isCodAdv): ?>
        <div style="margin-top:14px; padding:14px; background:#fff7ed; border:2px solid #fed7aa; border-radius:12px;">
            <p style="font-weight:700; color:#92400e; font-size:13px; margin:0 0 10px;">💳 COD + Advance Breakdown</p>
            <div style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:10px 14px; border-radius:8px; border:1px solid #fed7aa; margin-bottom:8px;">
                <div>
                    <p style="font-weight:600; color:#1d4ed8; font-size:13px; margin:0;">💳 Advance Paid Online</p>
                    <p style="font-size:11px; color:#6b7280; margin:2px 0 0;">
                        <?= ($order['payment_status'] === 'advance_paid') ? '<span style="color:#16a34a;">✓ Confirmed</span>' : '<span style="color:#d97706;">Pending</span>' ?>
                    </p>
                </div>
                <span style="font-weight:700; color:#1d4ed8; font-size:15px;">₹<?= number_format($codAdvAmt, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; background:#fff; padding:10px 14px; border-radius:8px; border:1px solid #86efac;">
                <div>
                    <p style="font-weight:600; color:#166534; font-size:13px; margin:0;">🏠 Balance to Pay on Delivery</p>
                    <p style="font-size:11px; color:#6b7280; margin:2px 0 0;">Please keep cash ready for delivery agent</p>
                </div>
                <span style="font-weight:800; color:#166534; font-size:16px;">₹<?= number_format($codBalance, 2) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Actions ── -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:30px;">
        <?php
            $enqNo      = $order['enquiry_no'] ?? '';
            $ordPdfFile = $_SERVER['DOCUMENT_ROOT'] . '/bills/invoice_' . $enqNo . '.pdf';
            $ordPdfUrl  = '/bills/invoice_' . $enqNo . '.pdf';
            if (!empty($enqNo) && file_exists($ordPdfFile)):
        ?>
        <a href="<?= htmlspecialchars($ordPdfUrl) ?>"
           download="Invoice_<?= htmlspecialchars($enqNo) ?>.pdf"
           style="display:inline-flex; align-items:center; gap:7px; padding:10px 20px; background:linear-gradient(135deg,#e91e63,#6a1b9a); color:#fff; border-radius:9999px; font-size:13px; font-weight:600; text-decoration:none; box-shadow:0 2px 8px rgba(233,30,99,0.3); transition:opacity 0.2s;"
           onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
            <i class="fa-solid fa-file-pdf"></i> Download Invoice
        </a>
        <?php endif; ?>

        <?php if (!in_array($order['status'], ['cancelled', 'delivered', 'shipped'])): ?>
        <button onclick="openCancelModal()"
            style="display:inline-flex; align-items:center; gap:7px; padding:10px 20px; background:#dc2626; color:#fff; border:none; border-radius:9999px; font-size:13px; font-weight:600; cursor:pointer; box-shadow:0 2px 8px rgba(220,38,38,0.3); transition:opacity 0.2s;"
            onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
            <i class="fa-solid fa-ban"></i> Cancel Order
        </button>
        <?php endif; ?>
    </div>

</div><!-- /max-width wrapper -->

<?php require_once 'includes/footer.php'; ?>
<script>
function copyPaymentId() {
    navigator.clipboard.writeText(document.getElementById('paymentId').innerText)
        .then(() => showToast('Payment ID copied!'));
}
function copyRefundId() {
    navigator.clipboard.writeText(document.getElementById('refundId').innerText)
        .then(() => showToast('Refund ID copied!'));
}
function openCancelModal()  { document.getElementById('cancelModal').classList.remove('hidden'); }
function closeCancelModal() { document.getElementById('cancelModal').classList.add('hidden'); }
function confirmCancelOrder() {
    let reason = document.getElementById("cancelReason").value.trim();
    if (reason.length < 3) { showToast("Please enter a valid reason!", { background:"#e63946", color:"#fff" }); return; }
    fetch("/cancel_order.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `order_id=<?= $order['id'] ?>&reason=${encodeURIComponent(reason)}`
    })
    .then(r => r.text())
    .then(text => {
        let d;
        try { d = JSON.parse(text); } catch(e) {
            console.error('cancel_order not JSON:', text);
            showToast("Server error — please try again.", { background:"#e63946", color:"#fff" });
            return;
        }
        if (d.success) { showToast("Order Cancelled!", { background:"#dc2626", color:"#fff" }); setTimeout(()=>location.reload(), 1200); }
        else showToast("Error: " + (d.message || "Unknown error"), { background:"#e63946", color:"#fff" });
    })
    .catch(() => showToast("Network error — check your connection.", { background:"#e63946", color:"#fff" }));
    closeCancelModal();
}
</script>

<!-- Cancel Modal -->
<div id="cancelModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center" style="z-index:999;">
    <div style="background:#fff; padding:24px; border-radius:16px; width:320px; box-shadow:0 20px 50px rgba(0,0,0,0.2);">
        <h2 style="font-weight:700; color:#b91c1c; margin:0 0 12px; font-size:18px;">Cancel Order</h2>
        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:6px;">Reason for cancellation</label>
        <textarea id="cancelReason" rows="3"
            style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px; font-size:13px; margin-bottom:14px; outline:none;"
            placeholder="Enter reason..."></textarea>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button onclick="closeCancelModal()"
                style="padding:8px 18px; background:#f3f4f6; border:none; border-radius:8px; font-weight:600; cursor:pointer;">No</button>
            <button onclick="confirmCancelOrder()"
                style="padding:8px 18px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Yes, Cancel</button>
        </div>
    </div>
</div>
</body>
</html>