<?php
session_start();

// Only admin can view
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";


// Check order ID
if (!isset($_GET['id'])) {
    die("Order ID missing!");
}
$order_id = intval($_GET['id']);

/* ----------------------------------------------------
   FETCH ORDER + USER + ADDRESS DETAILS
-----------------------------------------------------*/

$sql = "
    SELECT o.*, 
           u.name AS user_name, u.mobile AS user_mobile, u.email AS user_email,
           a.contact_name, a.contact_mobile, a.address_line1, a.address_line2,
           a.city, a.state, a.pincode, a.landmark
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN user_addresses a ON o.address_id = a.id
    WHERE o.id = :id
";

$stmt = $conn->prepare($sql);
$stmt->execute(['id' => $order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}

/* ----------------------------------------------------
   FETCH ORDER ITEMS
-----------------------------------------------------*/

// Fetch items with optional variant info (schema-safe)
$dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
$colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'order_items'");
$colsStmt->execute([$dbName]);
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
$hasVariantId = in_array('variant_id', $cols);

if ($hasVariantId) {
    $item_sql = "
        SELECT oi.*, it.name AS item_name,
               v.weight_value AS variant_weight,
               v.weight_unit AS variant_unit,
               v.price AS variant_price,
               v.old_price AS variant_old_price,
               v.discount AS variant_discount
        FROM order_items oi
        JOIN items it ON oi.item_id = it.id
        LEFT JOIN item_variants v ON oi.variant_id = v.id
        WHERE oi.order_id = :id
    ";
} else {
    $item_sql = "
        SELECT oi.*, it.name AS item_name,
               NULL AS variant_weight,
               NULL AS variant_unit,
               NULL AS variant_price,
               NULL AS variant_old_price,
               NULL AS variant_discount
        FROM order_items oi
        JOIN items it ON oi.item_id = it.id
        WHERE oi.order_id = :id
    ";
}

$stmt_items = $conn->prepare($item_sql);
$stmt_items->execute(['id' => $order_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order #<?= $order_id ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="/toast.js"></script>
    <style>
        .admin-main { margin-left: 3rem; }
  
    
</style>
</head>
<body class="bg-gray-100 min-h-screen">

<div class="admin-container flex">

    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="container mx-auto max-w-5xl">

            <!-- Order Header -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-bold text-indigo-600">Order #<?= $order['id'] ?></h2>

                    <!-- Action Buttons -->
                    <div class="flex gap-2 flex-wrap">
                    <?php if (
                        in_array($order['payment_method'], ['cod','cod_advance']) &&
                        $order['payment_status'] === 'pending' &&
                        $order['status'] === 'ordered'
                    ): ?>
                    <button onclick="markAsPaid(<?= $order['id'] ?>)"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold">
                        ✓ Mark as Paid
                    </button>
                    <?php endif; ?>
                    <?php if ($order['status'] !== 'cancelled'): ?>
                    <button onclick="openAdminCancelModal(<?= $order['id'] ?>)"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                        Cancel Order
                    </button>
                    <?php endif; ?>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Order Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">

                    <p><span class="font-semibold">User Name:</span> <?= $order['user_name'] ?></p>
                    <p><span class="font-semibold">User Mobile:</span> <?= $order['user_mobile'] ?></p>
                    <p><span class="font-semibold">User Email:</span> <?= $order['user_email'] ?></p>

                    <p><span class="font-semibold">Order Date:</span> <?= $order['order_date'] ?></p>

                    <p>
                        <span class="font-semibold">Payment Status:</span>
                        <?php if ($order['payment_status'] === 'paid'): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-sm">Paid</span>
                        <?php elseif ($order['payment_status'] === 'advance_paid'): ?>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">Advance Paid</span>
                        <?php elseif ($order['payment_status'] === 'partial_paid'): ?>
                            <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded-full text-sm">Partial Paid</span>
                        <?php elseif ($order['payment_status'] === 'pending'): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm">Pending</span>
                        <?php elseif ($order['payment_status'] === 'failed'): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-sm">Failed</span>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-sm"><?= ucfirst($order['payment_status'] ?? '-') ?></span>
                        <?php endif; ?>
                    </p>

                    <p>
                        <span class="font-semibold">Payment Method:</span>
                        <?php if ($order['payment_method'] === 'cod'): ?>
                            <span class="px-2 py-1 bg-orange-100 text-orange-800 rounded-full text-sm">COD</span>
                        <?php elseif ($order['payment_method'] === 'cod_advance'): ?>
                            <span class="px-2 py-1 bg-amber-100 text-amber-800 rounded-full text-sm">COD + Advance</span>
                            <?php if (!empty($order['cod_advance_amount'])): ?>
                            <span class="text-xs text-gray-500 ml-1">(₹<?= number_format($order['cod_advance_amount'],2) ?> advance paid)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-emerald-100 text-emerald-800 rounded-full text-sm">Online</span>
                        <?php endif; ?>
                    </p>

                    <p>
                        <span class="font-semibold">Order Status:</span>
                        <span class="px-2 py-1 rounded-full text-sm 
                            <?= $order['status'] === 'cancelled' ? 'bg-red-100 text-red-800' : '' ?>
                            <?= $order['status'] === 'delivered' ? 'bg-green-100 text-green-800' : '' ?>
                            <?= $order['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' ?>">
                            <?= ucfirst($order['status']) ?>
                        </span>
                    </p>

                </div>

                <!-- Address -->
                <h3 class="text-xl font-semibold mt-6 mb-2 text-gray-800">Delivery Address</h3>
                <div class="text-gray-700 leading-6">
                    <?= $order['contact_name'] ?> (<?= $order['contact_mobile'] ?>)<br>
                    <?= $order['address_line1'] ?><br>
                    <?= $order['address_line2'] ? $order['address_line2'] . '<br>' : '' ?>
                    <?= $order['city'] ?>, <?= $order['state'] ?> - <?= $order['pincode'] ?><br>
                    <?= $order['landmark'] ?>
                </div>

                <!-- Totals -->
                <h3 class="text-xl font-semibold mt-6 mb-2 text-gray-800">Bill Summary</h3>
                <div class="bg-gray-50 rounded-lg p-4 space-y-2 text-sm max-w-sm">
                    <div class="flex justify-between">
                        <span class="font-semibold text-gray-700">Subtotal:</span>
                        <span>₹<?= number_format($order['subtotal'], 2) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-semibold text-gray-700">Shipping:</span>
                        <span>₹<?= number_format($order['shipping_charge'], 2) ?></span>
                    </div>
                    <?php if (!empty($order['coupon_code']) && floatval($order['coupon_discount_amount'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-green-700">
                        <span class="font-semibold">🎟 Coupon (<?= htmlspecialchars($order['coupon_code']) ?>):</span>
                        <span>-₹<?= number_format($order['coupon_discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (floatval($order['referral_discount'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-purple-700">
                        <span class="font-semibold">🎁 Referral Discount (1st order):</span>
                        <span>-₹<?= number_format($order['referral_discount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (floatval($order['wallet_amount_used'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-indigo-700">
                        <span class="font-semibold">💜 Wallet Used:</span>
                        <span>-₹<?= number_format($order['wallet_amount_used'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (floatval($order['cod_convenience_fee'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-orange-700">
                        <span class="font-semibold">💵 COD Fee:</span>
                        <span>+₹<?= number_format($order['cod_convenience_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between border-t pt-2 text-base font-bold text-gray-900">
                        <span>Order Total:</span>
                        <span>₹<?= number_format($order['overall_total'], 2) ?></span>
                    </div>
                    <?php if (in_array($order['payment_method'], ['cod','cod_advance']) && floatval($order['cod_advance_amount'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-orange-700 text-xs">
                        <span>💳 Advance Paid:</span>
                        <span>₹<?= number_format($order['cod_advance_amount'], 2) ?></span>
                    </div>
                    <div class="flex justify-between text-green-700 text-xs">
                        <span>🏠 Balance on Delivery:</span>
                        <span>₹<?= number_format(max(0, $order['overall_total'] - $order['cod_advance_amount']), 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
<?php
$orderId  = $order['id'];
$enquiryNo = $order['enquiry_no'] ?? '';
$pdfPath  = $_SERVER["DOCUMENT_ROOT"] . "/bills/invoice_{$enquiryNo}.pdf";
$pdfUrl   = "/bills/invoice_{$enquiryNo}.pdf";
$pdfExists = !empty($enquiryNo) && file_exists($pdfPath);
?>

<h3 class="text-xl font-semibold mt-6 mb-2 text-gray-800">Estimate PDF</h3>

<?php if ($pdfExists): ?>
    <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank"
        class="px-3 py-1 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition inline-block mr-2">
        Open
    </a>
    <a href="<?= htmlspecialchars($pdfUrl) ?>" download="Invoice_<?= htmlspecialchars($enquiryNo) ?>.pdf"
        class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 transition inline-block">
        Download
    </a>
<?php else: ?>
    <span class="px-3 py-1 text-gray-500">No PDF Available</span>
<?php endif; ?>

                <!-- Razorpay -->
                <h3 class="text-xl font-semibold mt-6 mb-2 text-gray-800">Payment Details</h3>
            <!-- Toast Box -->
<div id="copyToast">Copied to clipboard!</div>

<?php
// Fetch shipment record for this order (if any)
$stmtS = $conn->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$stmtS->execute([$order_id]);
$shipmentRec = $stmtS->fetch(PDO::FETCH_ASSOC);
?>

<?php if (!empty($shipmentRec)): ?>
    <p><b>AWB: </b> <?= htmlspecialchars($shipmentRec['awb'] ?? '-') ?>
    <?php if (!empty($shipmentRec['awb'])): ?>
        <a href="/api/admin/download_awb.php?order_id=<?= $order_id ?>" target="_blank" class="px-2 py-1 bg-indigo-600 text-white rounded ml-2">Download Label</a>
    <?php endif; ?>
    </p>
<?php endif; ?>

<p>
    <b>Order ID: </b> 
    <span id="orderId"><?= $order['razorpay_order_id'] ?></span>
    <i class="fa-regular fa-copy copy-icon" onclick="copyText('orderId', this)"></i>
</p>

<p>
    <b>Payment ID: </b> 
    <span id="paymentId"><?= $order['razorpay_payment_id'] ?></span>
    <i class="fa-regular fa-copy copy-icon" onclick="copyText('paymentId', this)"></i>
</p>

<p>
    <b>Signature: </b> 
    <span id="signature"><?= $order['razorpay_signature'] ?></span>
    <i class="fa-regular fa-copy copy-icon" onclick="copyText('signature', this)"></i>
</p>

            </div>

            <!-- Order Items -->
            <div class="bg-white shadow rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">#</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">MRP</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Discount %</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Selling Price</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php $i=1; $total=0; foreach($items as $it): $total += $it['amount']; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm"><?= $i++ ?></td>
                            <td class="px-6 py-4 text-sm">
                                <span class="font-medium text-gray-900"><?= htmlspecialchars($it['item_name']) ?></span>
                                <?php if (!empty($it['variant_weight'])): ?>
                                <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($it['variant_weight']) ?> <?= htmlspecialchars($it['variant_unit']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 line-through">₹<?= number_format($it['original_price'],2) ?></td>
                            <td class="px-6 py-4 text-sm text-orange-600"><?= number_format($it['discount_percentage'],0) ?>%</td>
                            <td class="px-6 py-4 text-sm font-medium">₹<?= number_format($it['variant_price'] ?? $it['discounted_price'] ?? 0,2) ?></td>
                            <td class="px-6 py-4 text-sm text-center"><?= $it['quantity'] ?></td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">₹<?= number_format($it['amount'],2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-100 font-bold">
                            <td colspan="6" class="px-6 py-4 text-right text-sm">Items Total:</td>
                            <td class="px-6 py-4 text-sm">₹<?= number_format($total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<!-- ADMIN CANCEL MODAL -->
<div id="adminCancelModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center">
    <div class="bg-white p-6 rounded-lg w-96 shadow-lg">
        <h2 class="text-xl font-bold mb-4">Cancel Order</h2>

        <input type="hidden" id="adminCancelOrderId">

        <label class="block font-semibold mb-1">Reason:</label>
        <textarea id="adminCancelReason" class="w-full p-2 border rounded" rows="3"></textarea>

        <div class="text-right mt-4">
            <button onclick="closeAdminCancelModal()" class="px-3 py-1 bg-gray-400 text-white rounded">Close</button>
            <button onclick="confirmAdminCancelOrder()" class="px-3 py-1 bg-red-600 text-white rounded">Cancel Order</button>
        </div>
    </div>
</div>


<!-- ── TOAST ──────────────────────────────────────────────── -->
<div id="adminToast" style="
    position:fixed;bottom:28px;right:28px;z-index:99999;
    min-width:260px;max-width:360px;
    padding:14px 20px 14px 16px;border-radius:12px;
    background:linear-gradient(135deg,#6a1b9a,#e91e63);
    color:#fff;font-size:14px;font-weight:600;
    box-shadow:0 8px 28px rgba(106,27,154,0.35);
    display:flex;align-items:center;gap:12px;
    transform:translateY(80px);opacity:0;
    transition:transform 0.3s cubic-bezier(.4,0,.2,1),opacity 0.3s;
    pointer-events:none;
">
    <span id="adminToastIcon" style="font-size:20px;flex-shrink:0;">✓</span>
    <span id="adminToastMsg">Done</span>
</div>

<!-- ── CONFIRM DIALOG ─────────────────────────────────────── -->
<div id="adminConfirmOverlay" style="
    display:none;position:fixed;inset:0;z-index:99998;
    background:rgba(0,0,0,0.5);backdrop-filter:blur(3px);
    align-items:center;justify-content:center;
">
    <div style="
        background:#fff;border-radius:16px;padding:32px 28px;
        max-width:400px;width:90%;text-align:center;
        box-shadow:0 20px 60px rgba(0,0,0,0.2);
        animation:adminPopIn 0.25s cubic-bezier(.4,0,.2,1);
    ">
        <div id="adminConfirmIcon" style="font-size:36px;margin-bottom:12px;">⚠️</div>
        <h3 id="adminConfirmTitle" style="font-size:17px;font-weight:700;color:#1f2937;margin:0 0 8px;"></h3>
        <p id="adminConfirmMsg" style="font-size:14px;color:#6b7280;margin:0 0 24px;"></p>
        <div style="display:flex;gap:12px;justify-content:center;">
            <button id="adminConfirmNo"
                style="padding:10px 24px;border-radius:9999px;border:2px solid #e5e7eb;
                       background:#fff;color:#6b7280;font-weight:600;font-size:14px;cursor:pointer;">
                Cancel
            </button>
            <button id="adminConfirmYes"
                style="padding:10px 24px;border-radius:9999px;border:none;
                       background:linear-gradient(135deg,#e91e63,#6a1b9a);
                       color:#fff;font-weight:700;font-size:14px;cursor:pointer;">
                Confirm
            </button>
        </div>
    </div>
</div>

<style>
@keyframes adminPopIn {
    from { transform:scale(0.88);opacity:0; }
    to   { transform:scale(1);opacity:1; }
}
</style>

<script>
// ── Toast ────────────────────────────────────────────────────────
function showAdminToast(msg, type = 'success') {
    const toast = document.getElementById('adminToast');
    const colors = {
        success: 'linear-gradient(135deg,#6a1b9a,#e91e63)',
        error:   'linear-gradient(135deg,#dc2626,#b91c1c)',
        info:    'linear-gradient(135deg,#0369a1,#0284c7)',
    };
    const icons = { success: '✓', error: '✕', info: 'ℹ' };
    toast.style.background               = colors[type] || colors.success;
    document.getElementById('adminToastIcon').textContent = icons[type] || '✓';
    document.getElementById('adminToastMsg').textContent  = msg;
    toast.style.transform = 'translateY(0)';
    toast.style.opacity   = '1';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
        toast.style.transform = 'translateY(80px)';
        toast.style.opacity   = '0';
    }, 3500);
}

// ── Confirm ──────────────────────────────────────────────────────
function adminConfirm({ title, message, icon = '⚠️', confirmText = 'Confirm' } = {}) {
    return new Promise(resolve => {
        const overlay = document.getElementById('adminConfirmOverlay');
        document.getElementById('adminConfirmTitle').textContent = title || '';
        document.getElementById('adminConfirmMsg').textContent   = message || '';
        document.getElementById('adminConfirmIcon').textContent  = icon;
        document.getElementById('adminConfirmYes').textContent   = confirmText;
        overlay.style.display = 'flex';
        const yes = document.getElementById('adminConfirmYes');
        const no  = document.getElementById('adminConfirmNo');
        const cleanup = (val) => {
            overlay.style.display = 'none';
            yes.onclick = null; no.onclick = null;
            resolve(val);
        };
        yes.onclick = () => cleanup(true);
        no.onclick  = () => cleanup(false);
    });
}

// ── Cancel Modal ─────────────────────────────────────────────────
function openAdminCancelModal(id) {
    document.getElementById("adminCancelOrderId").value = id;
    document.getElementById("adminCancelModal").classList.remove("hidden");
}
function closeAdminCancelModal() {
    document.getElementById("adminCancelModal").classList.add("hidden");
}
async function confirmAdminCancelOrder() {
    const id     = document.getElementById("adminCancelOrderId").value;
    const reason = document.getElementById("adminCancelReason").value.trim();
    if (reason.length < 3) {
        showAdminToast('Please enter a valid reason (min 3 characters).', 'error');
        return;
    }
    closeAdminCancelModal();
    try {
        const res  = await fetch("/api/admin/cancel_order.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `order_id=${id}&reason=${encodeURIComponent(reason)}`
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            console.error('Cancel response not JSON:', text);
            showAdminToast('Server error — check PHP logs.', 'error'); return;
        }
        if (data.success) {
            showAdminToast('Order #' + id + ' cancelled successfully.', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showAdminToast('Error: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch(err) {
        console.error(err);
        showAdminToast('Network error — check your connection.', 'error');
    }
}

// ── Mark as Paid ─────────────────────────────────────────────────
// FIX: correct path — file lives at /update_payment_status.php (root)
async function markAsPaid(orderId) {
    const ok = await adminConfirm({
        title:       'Mark as Paid',
        message:     'Mark order #' + orderId + ' as Paid? This will credit referral commission if applicable.',
        icon:        '💰',
        confirmText: 'Mark Paid'
    });
    if (!ok) return;
    try {
        const res  = await fetch('/update_payment_status.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    'order_id=' + orderId + '&status=success'
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            console.error('markAsPaid response not JSON:', text);
            showAdminToast('Server error — check PHP logs for markAsPaid.', 'error');
            return;
        }
        if (data.success) {
            showAdminToast('Order #' + orderId + ' marked as Paid!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showAdminToast('Error: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch(err) {
        console.error(err);
        showAdminToast('Network error — check your connection.', 'error');
    }
}

// ── Copy text ────────────────────────────────────────────────────
function copyText(elementId) {
    const text = document.getElementById(elementId)?.innerText || '';
    navigator.clipboard.writeText(text).then(() => {
        showAdminToast('Copied to clipboard!', 'info');
    }).catch(() => {
        showAdminToast('Copy failed — try manually.', 'error');
    });
}
</script>

</body>
</html>