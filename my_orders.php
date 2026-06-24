<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ── Display dates in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");


if (!isset($_SESSION["user_id"])) {
    $_SESSION["redirect_after_login"] = "my_orders.php";
    header("Location: login.php");
    exit();
}

$userId = $_SESSION["user_id"];

// Fetch all orders of the user
// Detect if order_items table has variant columns to avoid SQL errors
$dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
$colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'order_items'");
$colsStmt->execute([$dbName]);
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
$selectExtra = '';
$hasVariantWeightValue = in_array('variant_weight_value', $cols);
$hasVariantWeightUnit = in_array('variant_weight_unit', $cols);
$hasVariantPrice = in_array('variant_price', $cols);
$hasDiscount = in_array('discount_percentage', $cols) || in_array('discount', $cols);
if ($hasVariantWeightValue) $selectExtra .= 'oi.variant_weight_value,';
if ($hasVariantWeightUnit) $selectExtra .= 'oi.variant_weight_unit,';
if ($hasVariantPrice) $selectExtra .= 'oi.variant_price,';

// Build items summary subquery (product name + optional variant weight/unit + qty + unit price + optional discount)
$hasVariantId = in_array('variant_id', $cols);
if ($hasVariantId) {
    $itemsSub = "(SELECT GROUP_CONCAT(CONCAT(i.name, ' (', COALESCE(v.weight_value, ''), ' ', COALESCE(v.weight_unit, ''), ')', ' x', oi.quantity, ' @ Rs ', FORMAT(COALESCE(";
    if ($hasVariantPrice) {
        $itemsSub .= "oi.variant_price, oi.discounted_price";
    } else {
        $itemsSub .= "oi.discounted_price, oi.original_price";
    }
    $itemsSub .= "),2)";
    if ($hasDiscount) {
        $itemsSub .= ", ' (', oi.discount_percentage, '% off)'";
    }
    $itemsSub .= ") SEPARATOR ' || ') FROM order_items oi JOIN items i ON i.id = oi.item_id LEFT JOIN item_variants v ON v.id = oi.variant_id WHERE oi.order_id = o.id) AS items_summary";
} else {
    // No variant_id on order_items — attempt to find a matching variant by price using LEFT JOIN
    // Build a price expression that avoids referencing oi.variant_price when it does not exist
    if ($hasVariantPrice) {
        $priceExpr = "COALESCE(oi.variant_price, oi.discounted_price, oi.original_price)";
    } else {
        $priceExpr = "COALESCE(oi.discounted_price, oi.original_price)";
    }

    $itemsSub = "(SELECT GROUP_CONCAT(CONCAT(i.name, ' (', COALESCE(iv.weight_value, ''), ' ', COALESCE(iv.weight_unit, ''), ') x', oi.quantity, ' @ Rs ', FORMAT(" . $priceExpr . ",2)";
    if ($hasDiscount) {
        $itemsSub .= ", ' (', oi.discount_percentage, '% off)'";
    }
    $itemsSub .= ") SEPARATOR ' || ') FROM order_items oi JOIN items i ON i.id = oi.item_id LEFT JOIN item_variants iv ON iv.item_id = oi.item_id AND iv.price = " . $priceExpr . " WHERE oi.order_id = o.id) AS items_summary";
}

// Build variant weights subquery (aggregated per order)
if ($hasVariantWeightValue) {
    $variantWeightsSub = "(SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(oi.variant_weight_value, ''), ' ', COALESCE(oi.variant_weight_unit, '')) SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) AS variant_weights";
} else {
    $variantWeightsSub = "(SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(iv.weight_value, ''), ' ', COALESCE(iv.weight_unit, '')) SEPARATOR ', ') FROM order_items oi LEFT JOIN item_variants iv ON iv.item_id = oi.item_id AND iv.price = " . $priceExpr . " WHERE oi.order_id = o.id) AS variant_weights";
}

$sql = "
SELECT 
    o.id AS order_id,
    o.order_date,
    o.overall_total,
    o.payment_status,
    o.payment_method,
    o.status,
    o.shipment_id,
    o.enquiry_no,
    o.coupon_code,
    o.coupon_discount_amount,
    o.referral_discount,
    o.wallet_amount_used,
    o.cod_convenience_fee,

    (
        SELECT MIN(i.name)
        FROM order_items oi2
        JOIN items i ON i.id = oi2.item_id
        WHERE oi2.order_id = o.id
    ) AS product_name,

    (
        SELECT COALESCE(compressed_path, image_path)
        FROM item_images img
        JOIN order_items oi3 ON oi3.item_id = img.item_id
        WHERE oi3.order_id = o.id
        ORDER BY img.is_primary DESC, img.sort_order ASC
        LIMIT 1
    ) AS product_image,

    " . $variantWeightsSub . ",

    " . $itemsSub . "

FROM orders o
WHERE o.user_id = ?
ORDER BY o.order_date DESC
";


$stmt = $conn->prepare($sql);
$stmt->execute([$userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get live shipment status for orders that have a shipment_id (uses Shiprocket shipments endpoint)
$shipmentStatuses = [];
$shipmentIds = [];

foreach ($orders as $r) {
    if (!empty($r['shipment_id'])) $shipmentIds[ $r['shipment_id'] ] = true;
}
if (!empty($shipmentIds)) {
    try {
        require_once __DIR__ . '/api/shiprocket.php';
        $client = shiprocketClient();
        foreach (array_keys($shipmentIds) as $sid) {
            try {
                $resp = $client->request('GET', '/shipments/' . urlencode($sid));
                $label = null;
                if (is_array($resp)) {
                    if (isset($resp['status'])) $label = $resp['status'];
                    elseif (isset($resp['data']['status'])) $label = $resp['data']['status'];
                    elseif (isset($resp['shipment']['status'])) $label = $resp['shipment']['status'];
                    elseif (isset($resp['shipment_status'])) $label = $resp['shipment_status'];
                }
                if (empty($label) && isset($resp['status_code'])) $label = $resp['status'] ?? 'Unknown';
                if (empty($label)) $label = 'Unknown';
                $shipmentStatuses[$sid] = ['label' => ucfirst(strtolower(str_replace('_', ' ', (string)$label))), 'raw' => $resp];
            } catch (Exception $e) {
                $shipmentStatuses[$sid] = ['label' => 'Error', 'raw' => ['error' => $e->getMessage()]];
            }
        }
    } catch (Exception $e) {
        foreach (array_keys($shipmentIds) as $sid) {
            $shipmentStatuses[$sid] = ['label' => 'Error'];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Orders</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f3f4f6; margin: 0; }

    .orders-container {
        max-width: 780px;
        margin: 0 auto;
        padding: 24px 16px;
        min-height: 50vh;
    }

    .headingh2 {
        font-size: 1.6em;
        font-weight: 700;
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 20px;
    }

    /* ── Card wrapper ── */
    .order-card-wrapper {
        margin-bottom: 14px;
        cursor: pointer;
    }
    .order-card {
        background: #fff;
        border-radius: 14px;
        border-left: 5px solid #6a1b9a;
        box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        transition: box-shadow 0.18s;
        overflow: hidden;
    }
    .order-card-wrapper:hover .order-card {
        box-shadow: 0 6px 22px rgba(106,27,154,0.13);
    }

    /* ── Card top row: image + header info ── */
    .card-top {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 14px 16px 10px;
    }
    .card-thumb {
        width: 64px;
        height: 64px;
        border-radius: 10px;
        object-fit: cover;
        flex-shrink: 0;
        border: 1px solid #e5e7eb;
    }
    .card-header {
        flex: 1;
        min-width: 0;
    }
    .card-product-name {
        font-size: 15px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .card-meta-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 2px;
    }
    .card-date {
        font-size: 12px;
        color: #9ca3af;
    }
    .card-price {
        font-size: 15px;
        font-weight: 700;
        color: #166534;
    }
    .card-chevron {
        color: #d1d5db;
        font-size: 14px;
        padding-top: 4px;
    }

    /* ── Items list ── */
    .card-items {
        padding: 0 16px 6px 16px;
        border-top: 1px solid #f3f4f6;
        margin-top: 4px;
    }
    .card-items-label {
        font-size: 10px;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 7px 0 5px;
    }
    .item-line {
        display: flex;
        align-items: baseline;
        gap: 8px;
        padding: 4px 0;
        border-bottom: 1px dashed #f3f4f6;
        font-size: 13px;
        color: #374151;
    }
    .item-line:last-child { border-bottom: none; }
    .item-name {
        font-weight: 600;
        color: #1f2937;
        flex-shrink: 0;
    }
    .item-weight {
        background: #ede9fe;
        color: #6d28d9;
        font-size: 11px;
        font-weight: 600;
        padding: 1px 7px;
        border-radius: 20px;
        white-space: nowrap;
    }
    .item-qty {
        color: #6b7280;
        font-size: 12px;
        white-space: nowrap;
    }
    .item-price {
        margin-left: auto;
        font-weight: 600;
        color: #059669;
        white-space: nowrap;
        font-size: 12px;
    }
    .item-discount {
        font-size: 11px;
        color: #dc2626;
        white-space: nowrap;
    }

    /* ── Card footer: badges ── */
    .card-footer {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
        padding: 8px 16px 12px;
        background: #fafafa;
        border-top: 1px solid #f3f4f6;
    }
    .badge {
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    .badge-cod     { background:#fff4e6; color:#92400e; border:1px solid #f59e0b; }
    .badge-advance { background:#fef3c7; color:#78350f; border:1px solid #f59e0b; }
    .badge-prepaid { background:#ecfccb; color:#14532d; border:1px solid #86efac; }
    .badge-coupon  { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
    .badge-referral{ background:#faf5ff; color:#6b21a8; border:1px solid #c4b5fd; }
    .badge-wallet  { background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd; }

    .invoice-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        color: #fff !important;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-decoration: none !important;
        border: none;
        cursor: pointer;
        transition: opacity 0.2s;
        z-index: 10;
        position: relative;
    }
    .invoice-btn:hover { opacity: 0.88; color: #fff !important; }

    .cancel-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        background: #dc2626;
        color: #fff;
        border: none;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity 0.2s;
        z-index: 10;
        position: relative;
    }
    .cancel-btn:hover { opacity: 0.85; }

    /* Cancel modal */
    #cancelModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center; }
    #cancelModal.open { display:flex; }
        .card-top { gap: 10px; }
        .card-thumb { width: 52px; height: 52px; }
    }
    </style>
</head>
<body>
    <?php include "includes/header.php"; ?>

    <div class="orders-container">
        <h2 class="headingh2">My Orders</h2>

        <?php if (empty($orders)): ?>
            <p style="color:#6b7280; text-align:center; padding:40px 0;">You have no orders yet.</p>
        <?php else: ?>

        <?php foreach ($orders as $row):
            $enquiryNo = $row['enquiry_no'] ?? '';
            $ordId     = $row['order_id'];
            $pdfFile   = $_SERVER['DOCUMENT_ROOT'] . '/bills/invoice_' . $enquiryNo . '.pdf';
            $pdfUrl    = '/bills/invoice_' . $enquiryNo . '.pdf';
            $hasPdf    = !empty($enquiryNo) && file_exists($pdfFile);

            // ── Parse items_summary into individual item lines ──────────────
            // Format per item: "Name (weight unit) xQty @ Rs price (discount% off)"
            $rawSummary = trim($row['items_summary'] ?? '');
            $itemLines  = [];
            if ($rawSummary !== '') {
                foreach (explode(' || ', $rawSummary) as $part) {
                    $part = trim($part);
                    if ($part === '') continue;

                    // Extract discount if present: " (XX.00% off)" at end
                    $discount = '';
                    if (preg_match('/\(([0-9.]+)%\s*off\)\s*$/', $part, $dm)) {
                        $pct = rtrim(rtrim(number_format((float)$dm[1], 2), '0'), '.');
                        $discount = $pct . '% off';
                        $part = trim(substr($part, 0, strrpos($part, '('.$dm[1])));
                    }

                    // Extract price after "@ Rs "
                    $price = '';
                    if (preg_match('/@\s*Rs\s+([\d,.]+)\s*$/', $part, $pm)) {
                        $price = '₹' . $pm[1];
                        $part  = trim(substr($part, 0, strrpos($part, '@ Rs')));
                    }

                    // Extract qty after " x"
                    $qty = '';
                    if (preg_match('/\s+x(\d+)\s*$/', $part, $qm)) {
                        $qty  = '×' . $qm[1];
                        $part = trim(substr($part, 0, strrpos($part, ' x'.$qm[1])));
                    }

                    // Extract weight from "(weight unit)" at end
                    $weight = '';
                    if (preg_match('/\(([^)]+)\)\s*$/', $part, $wm)) {
                        // Clean up .00 from weight value
                        $raw_w = $wm[1];
                        $weight = preg_replace_callback('/\b(\d+)\.00\b/', fn($m) => $m[1], $raw_w);
                        $part   = trim(substr($part, 0, strrpos($part, '('.$wm[1].')')));
                    }

                    $itemLines[] = [
                        'name'     => trim($part),
                        'weight'   => trim($weight),
                        'qty'      => $qty,
                        'price'    => $price,
                        'discount' => $discount,
                    ];
                }
            }
        ?>

        <div class="order-card-wrapper">
            <div class="order-card" onclick="window.location='order_details.php?id=<?= $row['order_id'] ?>'">

                <!-- Top row: image + name + date + price -->
                <div class="card-top">
                    <img class="card-thumb"
                         src="/admin/<?= htmlspecialchars($row['product_image'] ?? 'images/default.jpg') ?>"
                         onerror="this.src='images/default.jpg';" alt="Product">

                    <div class="card-header">
                        <p class="card-product-name">
                            <?= htmlspecialchars($row['product_name'] ?? 'Order #'.$row['order_id'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if (count($itemLines) > 1): ?>
                                <span style="font-size:11px;font-weight:500;color:#6b7280;"> +<?= count($itemLines)-1 ?> more</span>
                            <?php endif; ?>
                        </p>
                        <div class="card-meta-row">
                            <span class="card-date"><?= date("d M Y", strtotime($row['order_date'])) ?></span>
                            <span class="card-price">₹<?= number_format($row['overall_total'], 2) ?></span>
                            <span style="font-size:11px;color:#9ca3af;">Order #<?= $row['order_id'] ?></span>
                        </div>
                    </div>
                    <div class="card-chevron"><i class="fa-solid fa-chevron-right"></i></div>
                </div>

                <!-- Items list — one line per product -->
                <?php if (!empty($itemLines)): ?>
                <div class="card-items">
                    <div class="card-items-label">Items ordered</div>
                    <?php foreach ($itemLines as $il): ?>
                    <div class="item-line">
                        <span class="item-name"><?= htmlspecialchars($il['name']) ?></span>
                        <?php if ($il['weight']): ?>
                            <span class="item-weight"><?= htmlspecialchars($il['weight']) ?></span>
                        <?php endif; ?>
                        <?php if ($il['qty']): ?>
                            <span class="item-qty"><?= htmlspecialchars($il['qty']) ?></span>
                        <?php endif; ?>
                        <?php if ($il['discount']): ?>
                            <span class="item-discount"><?= htmlspecialchars($il['discount']) ?></span>
                        <?php endif; ?>
                        <?php if ($il['price']): ?>
                            <span class="item-price"><?= htmlspecialchars($il['price']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Footer: payment badge + discount badges + invoice -->
                <div class="card-footer" onclick="event.stopPropagation()">
                    <?php if ($row['payment_method'] === 'cod'): ?>
                        <span class="badge badge-cod">💵 COD</span>
                    <?php elseif ($row['payment_method'] === 'cod_advance'): ?>
                        <span class="badge badge-advance">💳 COD + Advance</span>
                    <?php else: ?>
                        <span class="badge badge-prepaid">✅ Prepaid</span>
                    <?php endif; ?>

                    <?php if (!empty($row['coupon_code']) && floatval($row['coupon_discount_amount'] ?? 0) > 0): ?>
                        <span class="badge badge-coupon">🎟 <?= htmlspecialchars($row['coupon_code']) ?> −₹<?= number_format($row['coupon_discount_amount'], 2) ?></span>
                    <?php endif; ?>

                    <?php if (floatval($row['referral_discount'] ?? 0) > 0): ?>
                        <span class="badge badge-referral">🎁 Referral −₹<?= number_format($row['referral_discount'], 2) ?></span>
                    <?php endif; ?>

                    <?php if (floatval($row['wallet_amount_used'] ?? 0) > 0): ?>
                        <span class="badge badge-wallet">💜 Wallet −₹<?= number_format($row['wallet_amount_used'], 2) ?></span>
                    <?php endif; ?>

                    <?php if (floatval($row['cod_convenience_fee'] ?? 0) > 0): ?>
                        <span class="badge" style="background:#fff7ed;color:#92400e;border:1px solid #f59e0b;">
                            🏷 COD Fee +₹<?= number_format($row['cod_convenience_fee'], 2) ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($hasPdf): ?>
                    <a href="<?= htmlspecialchars($pdfUrl) ?>"
                       download="Invoice_<?= htmlspecialchars($enquiryNo) ?>.pdf"
                       class="invoice-btn"
                       onclick="event.stopPropagation();">
                        <i class="fa-solid fa-file-pdf"></i> Invoice
                    </a>
                    <?php endif; ?>

                    <?php if (!in_array($row['status'], ['cancelled', 'delivered', 'shipped'])): ?>
                    <button class="cancel-btn"
                        onclick="event.stopPropagation(); openCancelModal(<?= $row['order_id'] ?>)">
                        <i class="fa-solid fa-ban"></i> Cancel
                    </button>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/footer.php'; ?>

<!-- Cancel Order Modal -->
<div id="cancelModal">
    <div style="background:#fff; padding:24px; border-radius:16px; width:320px; box-shadow:0 20px 50px rgba(0,0,0,0.2);">
        <h2 style="font-weight:700; color:#b91c1c; margin:0 0 12px; font-size:18px;">Cancel Order</h2>
        <input type="hidden" id="cancelOrderId" value="">
        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:6px;">Reason for cancellation</label>
        <textarea id="cancelReason" rows="3"
            style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px; font-size:13px; margin-bottom:14px; outline:none; resize:none;"
            placeholder="Enter reason..."></textarea>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button onclick="closeCancelModal()"
                style="padding:8px 18px; background:#f3f4f6; border:none; border-radius:8px; font-weight:600; cursor:pointer;">No</button>
            <button onclick="confirmCancelOrder()"
                style="padding:8px 18px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Yes, Cancel</button>
        </div>
    </div>
</div>

<script>
function openCancelModal(orderId) {
    document.getElementById('cancelOrderId').value = orderId;
    document.getElementById('cancelReason').value = '';
    document.getElementById('cancelModal').classList.add('open');
}
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('open');
}
function confirmCancelOrder() {
    const orderId = document.getElementById('cancelOrderId').value;
    const reason  = document.getElementById('cancelReason').value.trim();
    if (reason.length < 3) { alert('Please enter a valid reason!'); return; }
    fetch('/cancel_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `order_id=${encodeURIComponent(orderId)}&reason=${encodeURIComponent(reason)}`
    })
    .then(r => r.text())
    .then(text => {
        let d;
        try { d = JSON.parse(text); } catch(e) { alert('Server error — please try again.'); return; }
        if (d.success) { closeCancelModal(); location.reload(); }
        else alert('Error: ' + (d.message || 'Unknown error'));
    })
    .catch(() => alert('Network error — check your connection.'));
}
</script>
</body>
</html>