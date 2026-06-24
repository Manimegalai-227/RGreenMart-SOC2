<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";



// Build an items summary per order (safe: check if variant weight columns exist)
$dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
$colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = 'order_items'");
$colsStmt->execute([$dbName]);
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
$hasVariantWeightValue = in_array('variant_weight_value', $cols);
$hasVariantWeightUnit = in_array('variant_weight_unit', $cols);

// Subquery for items summary: product name, optional variant, qty, unit price and optional discount
$hasVariantPrice = in_array('variant_price', $cols);
$hasDiscount = in_array('discount_percentage', $cols) || in_array('discount', $cols);

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
    // No variant_id on order_items — attempt to find matching variant by price using LEFT JOIN
    // Build price expression without referencing oi.variant_price when not present
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

$sql = "
SELECT 
    o.id AS order_id,
    o.enquiry_no,
    o.overall_total,
    o.subtotal,
    o.shipping_charge,
    o.payment_status,
    o.payment_method,
    o.order_date,
    o.status,
    o.cancelled_by,
    o.cancelled_at,
    o.cancellation_reason,
    o.coupon_code,
    o.coupon_discount_amount,
    o.referral_discount,
    o.wallet_amount_used,

    u.name AS user_name,
    u.mobile AS user_mobile,

    ua.contact_name,
    ua.contact_mobile,
    " . $itemsSub . "

FROM orders o
LEFT JOIN users u ON o.user_id = u.id
LEFT JOIN user_addresses ua ON o.address_id = ua.id
ORDER BY o.id DESC
";

$stmt = $conn->query($sql);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$billsDir = $_SERVER['DOCUMENT_ROOT'] . '/bills';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Orders List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body {
        font-family: 'Poppins', sans-serif;
    }

    .admin-main {
        margin-left: 3rem;
    }
    </style>
</head>

<body class="bg-gray-100">
    <div class="admin-container flex">
        <?php require_once './common/admin_sidebar.php'; ?>
        <main class="admin-main flex-1 p-6">
            <div
                class="container mx-auto max-w-6xl p-6 bg-white rounded-lg shadow-lg mt-10 min-h-[80vh] overflow-y-auto">
                <h2 class="text-2xl font-bold text-indigo-600 mb-6">Orders</h2>
                <div class="mb-4">
                    <input type="text" id="searchInput" placeholder="Search by order ID or enquiry number..."
                        class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="overflow-x-auto bg-white shadow-md rounded-lg">
                    <table class="w-full border-collapse bg-white rounded-lg shadow-sm" id="ordersTable">
                        <thead>
                            <tr class="bg-indigo-500 text-white">
                                <th class="p-3 text-left">Order ID</th>
                                <th class="p-3 text-left">Name</th>
                                <th class="p-3 text-left">Mobile</th>
                                <th class="p-3 text-left">Overall Total (₹)</th>
                                <th class="p-3 text-left">Payment Status</th>
                                <th class="p-3 text-left">Payment Method</th>
                                <th class="p-3 text-left">Order Status</th>
                                <th class="p-3 text-left">Order Date</th>
                                <th class="p-3 text-left">Enquiry No</th>
                                <th class="p-3 text-left">Items</th>
                                <th class="p-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): 
                            $enquiryNo = $order['enquiry_no'] ?? '';
                            $ordId     = $order['order_id'];
                            $pdfFile   = $billsDir . "/invoice_{$enquiryNo}.pdf";
                            $pdfUrl    = "/bills/invoice_{$enquiryNo}.pdf";
                            $hasPdf    = !empty($enquiryNo) && file_exists($pdfFile);
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="p-3 border-b"><?= $order['order_id'] ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($order['contact_name']) ?> </td>
                                <td class="p-3 border-b"><?= htmlspecialchars($order['contact_mobile']) ?></td>
                                <td class="p-3 border-b font-medium">
                                    ₹<?= number_format($order['overall_total'],2) ?>
                                    <?php if (floatval($order['referral_discount'] ?? 0) > 0): ?>
                                    <span class="block text-xs text-purple-600 font-normal">- ₹<?= number_format($order['referral_discount'],2) ?> referral</span>
                                    <?php endif; ?>
                                    <?php if (floatval($order['wallet_amount_used'] ?? 0) > 0): ?>
                                    <span class="block text-xs text-indigo-600 font-normal">- ₹<?= number_format($order['wallet_amount_used'],2) ?> wallet</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($order['payment_status'] === 'paid'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Paid</span>
                                    <?php elseif ($order['payment_status'] === 'advance_paid'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Advance Paid</span>
                                    <?php elseif ($order['payment_status'] === 'partial_paid'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">Partial Paid</span>
                                    <?php elseif ($order['payment_status'] === 'pending'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                    <?php elseif ($order['payment_status'] === 'failed'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Failed</span>
                                    <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800"><?= ucfirst($order['payment_status'] ?? '-') ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (isset($order['payment_method']) && $order['payment_method'] === 'cod'): ?>
                                        <span class="px-2 inline-flex text-xs bg-orange-100 text-orange-800 rounded-full">COD</span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs bg-emerald-100 text-emerald-800 rounded-full"><?php echo strtoupper($order['payment_method'] ?? 'ONLINE'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($order['status'] === 'pending'): ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-yellow-100 text-yellow-800 rounded-full">Pending</span>

                                    <?php elseif ($order['status'] === 'ordered'): ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-blue-100 text-blue-800 rounded-full">ordered</span>

                                    <?php elseif ($order['status'] === 'shipped'): ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-indigo-100 text-indigo-800 rounded-full">Shipped</span>

                                    <?php elseif ($order['status'] === 'delivered'): ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-green-100 text-green-800 rounded-full">Delivered</span>

                                    <?php elseif ($order['status'] === 'cancelled'): ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-red-100 text-red-800 rounded-full">Cancelled</span>

                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs font-semibold bg-gray-100 text-gray-800 rounded-full">Unknown</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= $order['order_date'] ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $enquiryNo ?></td>
                                <td class="px-6 py-4 text-sm text-gray-700">
                                    <div class="flex flex-col gap-1">
                                        <?php
                                        $itemsList = explode(' || ', $order['items_summary'] ?? '-');
                                        foreach ($itemsList as $item):
                                            $item = trim($item);
                                            if ($item === '') continue;
                                        ?>
                                        <span class="inline-block bg-indigo-50 text-indigo-800 border border-indigo-200 rounded-md px-2 py-1 text-xs leading-snug whitespace-nowrap">
                                            <?= htmlspecialchars($item) ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <a href="view_order.php?id=<?= $order['order_id'] ?>"
                                        class="px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">View</a>

                                    <?php if ($order['status'] !== 'cancelled'): ?>
                                    <button onclick="openAdminCancelModal(<?= $order['order_id'] ?>)"
                                        class="px-3 py-1 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                        Cancel
                                    </button>
                                    <?php endif; ?>

                                    <?php if (
                                        in_array($order['payment_method'], ['cod','cod_advance']) &&
                                        $order['payment_status'] === 'pending' &&
                                        $order['status'] === 'ordered'
                                    ): ?>
                                    <button onclick="markAsPaid(<?= $order['order_id'] ?>)"
                                        class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 transition text-xs">
                                        Mark Paid
                                    </button>
                                    <?php endif; ?>

                                    <!-- Shiprocket actions -->
                                    <button onclick="createShipment(<?= $order['order_id'] ?>, this)" class="px-3 py-1 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">Create Shipment</button>

                                    <button onclick="viewShipment(<?= $order['order_id'] ?>)" class="px-3 py-1 bg-gray-700 text-white rounded-lg hover:bg-gray-800 transition">View Shipment</button>

                                    <?php if ($hasPdf): ?>
                                    <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank"
                                        class="px-3 py-1 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">Open</a>
                                    <a href="<?= htmlspecialchars($pdfUrl) ?>" download="Invoice_<?= htmlspecialchars($enquiryNo) ?>.pdf"
                                        class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">Download</a>
                                    <?php else: ?>
                                    <span class="px-3 py-1 text-gray-400">No PDF</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
<!-- Admin Cancel Modal -->
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

<!-- Shipment Modal -->
<div id="shipmentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center">
    <div class="bg-white p-6 rounded-lg w-11/12 max-w-3xl shadow-lg">
        <h2 class="text-xl font-bold mb-4">Shipment Details</h2>
        <div id="shipmentContent" class="space-y-2 text-sm text-gray-700"></div>
        <div class="text-right mt-4">
            <button onclick="closeShipmentModal()" class="px-3 py-1 bg-gray-400 text-white rounded">Close</button>
        </div>
    </div>
</div>



<!-- ── TOAST NOTIFICATION ─────────────────────────────────────── -->
<div id="adminToast" style="
    position:fixed; bottom:28px; right:28px; z-index:99999;
    min-width:280px; max-width:380px;
    padding:14px 20px 14px 16px;
    border-radius:12px;
    background: linear-gradient(135deg,#6a1b9a,#e91e63);
    color:#fff; font-size:14px; font-weight:600;
    box-shadow:0 8px 28px rgba(106,27,154,0.35);
    display:flex; align-items:center; gap:12px;
    transform:translateY(80px); opacity:0;
    transition:transform 0.3s cubic-bezier(.4,0,.2,1), opacity 0.3s;
    pointer-events:none;
">
    <span id="adminToastIcon" style="font-size:20px;flex-shrink:0;">✓</span>
    <span id="adminToastMsg">Done</span>
</div>

<!-- ── CONFIRM DIALOG ─────────────────────────────────────────── -->
<div id="adminConfirmOverlay" style="
    display:none; position:fixed; inset:0; z-index:99998;
    background:rgba(0,0,0,0.5); backdrop-filter:blur(3px);
    align-items:center; justify-content:center;
">
    <div style="
        background:#fff; border-radius:16px; padding:32px 28px;
        max-width:400px; width:90%; text-align:center;
        box-shadow:0 20px 60px rgba(0,0,0,0.2);
        animation:adminPopIn 0.25s cubic-bezier(.4,0,.2,1);
    ">
        <div id="adminConfirmIcon" style="font-size:36px; margin-bottom:12px;">⚠️</div>
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
    from { transform:scale(0.88); opacity:0; }
    to   { transform:scale(1);    opacity:1; }
}
</style>

<script>
// ── Toast ───────────────────────────────────────────────────────
function showAdminToast(msg, type = 'success') {
    const toast = document.getElementById('adminToast');
    const icon  = document.getElementById('adminToastIcon');
    const msgEl = document.getElementById('adminToastMsg');
    const colors = {
        success: 'linear-gradient(135deg,#6a1b9a,#e91e63)',
        error:   'linear-gradient(135deg,#dc2626,#b91c1c)',
        info:    'linear-gradient(135deg,#0369a1,#0284c7)',
    };
    const icons = { success: '✓', error: '✕', info: 'ℹ' };
    toast.style.background = colors[type] || colors.success;
    icon.textContent  = icons[type]  || '✓';
    msgEl.textContent = msg;
    toast.style.transform = 'translateY(0)';
    toast.style.opacity   = '1';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
        toast.style.transform = 'translateY(80px)';
        toast.style.opacity   = '0';
    }, 3500);
}

// ── Confirm Dialog ───────────────────────────────────────────────
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

// ── Cancel Order ─────────────────────────────────────────────────
function openAdminCancelModal(orderId) {
    document.getElementById("adminCancelOrderId").value = orderId;
    document.getElementById("adminCancelModal").classList.remove("hidden");
}
function closeAdminCancelModal() {
    document.getElementById("adminCancelModal").classList.add("hidden");
}
async function confirmAdminCancelOrder() {
    const orderId = document.getElementById("adminCancelOrderId").value;
    const reason  = document.getElementById("adminCancelReason").value.trim();

    if (reason.length < 3) {
        showAdminToast('Please enter a valid cancellation reason.', 'error');
        return;
    }
    closeAdminCancelModal();

    try {
        const res  = await fetch("/api/admin/cancel_order.php", {
            method:  "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body:    `order_id=${orderId}&reason=${encodeURIComponent(reason)}`
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            console.error('Cancel response not JSON:', text);
            showAdminToast('Server error — check PHP logs.', 'error'); return;
        }
        if (data.success) {
            showAdminToast('Order #' + orderId + ' cancelled.', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showAdminToast('Error: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch(err) {
        console.error(err);
        showAdminToast('Network error — check your connection.', 'error');
    }
}

// ── Search ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows   = document.querySelectorAll('#ordersTable tbody tr');
        rows.forEach(row => {
            const text = Array.from(row.cells).map(c => c.textContent.toLowerCase()).join(' ');
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
});

// ── Create Shipment ──────────────────────────────────────────────
async function createShipment(orderId, btnEl) {
    const ok = await adminConfirm({
        title: 'Create Shipment',
        message: 'Create a Shiprocket shipment for order #' + orderId + '?',
        icon: '🚚',
        confirmText: 'Create'
    });
    if (!ok) return;

    const btn = btnEl || null;
    if (btn) { btn.disabled = true; btn.innerText = 'Creating...'; }

    try {
        const body = new URLSearchParams();
        body.append('order_id', orderId);
        body.append('auto_assign', 1);

        const res  = await fetch('/api/admin/create_shipment.php', {
            method:  'POST',
            body:    body.toString(),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            console.error('Shipment response not JSON:', text);
            showAdminToast('Server error creating shipment — check PHP logs.', 'error');
            if (btn) { btn.disabled = false; btn.innerText = 'Create Shipment'; }
            return;
        }
        if (btn) { btn.disabled = false; btn.innerText = 'Create Shipment'; }
        if (data.success) {
            showAdminToast('Shipment created! AWB: ' + (data.awb || 'N/A'), 'success');
            if (data.label_url) window.open(data.label_url, '_blank');
            setTimeout(() => location.reload(), 1800);
        } else {
            showAdminToast('Shipment error: ' + (data.error || data.message || 'Unknown'), 'error');
            console.error('Shipment error detail:', data);
        }
    } catch(err) {
        console.error(err);
        showAdminToast('Network error — check your connection.', 'error');
        if (btn) { btn.disabled = false; btn.innerText = 'Create Shipment'; }
    }
}

function downloadAWB(orderId) {
    window.open('/api/admin/download_awb.php?order_id=' + encodeURIComponent(orderId), '_blank');
}

// ── View Shipment ─────────────────────────────────────────────────
function viewShipment(orderId) {
    document.getElementById('shipmentContent').innerHTML = '<p class="text-gray-500 text-sm">Loading…</p>';
    document.getElementById('shipmentModal').classList.remove('hidden');
    fetch('/api/admin/get_shipment.php?order_id=' + encodeURIComponent(orderId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('shipmentContent').innerText = data.message || 'No shipment';
                return;
            }
            const s = data.shipment;
            let html = '<div class="space-y-2 text-sm">';
            html += '<div class="flex gap-2"><span class="font-semibold w-28">AWB:</span><span>' + (s.awb || '—') + '</span></div>';
            html += '<div class="flex gap-2"><span class="font-semibold w-28">Courier:</span><span>' + (s.courier_name || s.courier_code || '—') + '</span></div>';
            html += '<div class="flex gap-2"><span class="font-semibold w-28">Status:</span><span>' + (s.status || '—') + '</span></div>';
            if (s.label_url) html += '<div><a href="' + s.label_url + '" target="_blank" class="text-indigo-600 underline">Open Label</a></div>';
            if (data.live) html += '<div class="mt-3"><p class="font-semibold mb-1">Live Tracking:</p><pre class="bg-gray-100 p-2 rounded text-xs overflow-auto">' + JSON.stringify(data.live, null, 2) + '</pre></div>';
            html += '</div>';
            document.getElementById('shipmentContent').innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            document.getElementById('shipmentContent').innerText = 'Error fetching shipment.';
        });
}
function closeShipmentModal() {
    document.getElementById('shipmentModal').classList.add('hidden');
    document.getElementById('shipmentContent').innerHTML = '';
}

// ── Mark as Paid ─────────────────────────────────────────────────
// FIX: correct path — update_payment_status.php is at /update_payment_status.php (root),
// NOT at /api/admin/update_payment_status.php (which caused the DOCTYPE 404 JSON parse error)
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
</script>

</body>
</html>