<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Fetch all users
$users = $conn->query("SELECT id, name, mobile, email FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all items with their variants
$items = $conn->query("
    SELECT i.id, i.name,
        (SELECT COALESCE(compressed_path, image_path) FROM item_images WHERE item_id = i.id ORDER BY is_primary DESC LIMIT 1) AS thumb
    FROM items i ORDER BY i.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch pickup pincode from settings
$settingsRow = $conn->query("SELECT pickuplocation_pincode, minimum_order FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pickupPincode = $settingsRow['pickuplocation_pincode'] ?? '625005';
$minimumOrder  = $settingsRow['minimum_order'] ?? 0;

// Fetch shipping & COD settings
$shippingSettings = $conn->query("SELECT * FROM shipping_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$codEnabled     = $shippingSettings['cod_enabled'] ?? 0;
$shippingMode   = $shippingSettings['shipping_mode'] ?? 'default';
$brokerEnabled  = intval($shippingSettings['broker_enabled'] ?? 0);
if ($brokerEnabled && $shippingMode !== 'broker') $shippingMode = 'broker';

// Build a human-readable shipping mode notice for admin UI
switch($shippingMode) {
    case 'free':
        $shippingModeLabel = ' Free Shipping – all orders ship for free.';
        break;
    case 'conditional':
        $shippingModeLabel = ' Conditional Free Shipping – free above ₹' . number_format($shippingSettings['conditional_min_order'] ?? 0, 2) . '; otherwise Shiprocket rate.';
        break;
    case 'fixed':
        $shippingModeLabel = ($shippingSettings['shipping_calculation'] ?? 'flat') === 'flat'
            ? ' Fixed Flat Charge – ₹' . number_format($shippingSettings['fixed_charge'] ?? 0, 2) . ' per order.'
            : ' Weight-Based – ₹' . number_format($shippingSettings['weight_rate'] ?? 0, 2) . '/kg.';
        break;
    case 'broker':
        $shippingModeLabel = ' Broker Shipping (' . htmlspecialchars($shippingSettings['broker_name'] ?? 'Broker') . ') – ₹' . number_format($shippingSettings['broker_charge'] ?? 0, 2) . ' flat.';
        break;
    default:
        $shippingModeLabel = ' Default – Shiprocket recommended rate.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Order – Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { font-family: 'DM Sans', sans-serif; }
body { background: #f0f4ff; }
.admin-main { margin-left: 3rem; }

/* Step indicator */
.step-dot { width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;transition:all .3s; }
.step-dot.active { background:#4f46e5;color:#fff; }
.step-dot.done { background:#22c55e;color:#fff; }
.step-dot.idle { background:#e5e7eb;color:#6b7280; }
.step-line { flex:1;height:3px;background:#e5e7eb;margin:0 6px;transition:background .3s; }
.step-line.done { background:#22c55e; }

/* Panels */
.panel { display:none; }
.panel.active { display:block; }

/* Cart table */
.cart-table th { background:#4f46e5;color:#fff;padding:10px 12px;text-align:left;font-size:13px; }
.cart-table td { padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:middle; }
.cart-table tr:last-child td { border-bottom:none; }

/* Input */
.field { border:1.5px solid #d1d5db;border-radius:8px;padding:9px 12px;width:100%;font-size:14px;outline:none;transition:border .2s; }
.field:focus { border-color:#4f46e5; }

/* Btn */
.btn-primary { background:#4f46e5;color:#fff;padding:10px 22px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;transition:background .2s; }
.btn-primary:hover { background:#4338ca; }
.btn-secondary { background:#e5e7eb;color:#374151;padding:10px 22px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer; }
.btn-danger { background:#fee2e2;color:#dc2626;padding:6px 12px;border-radius:6px;font-size:12px;cursor:pointer; }

/* Address card */
.addr-card { border:2px solid #e5e7eb;border-radius:10px;padding:14px;cursor:pointer;transition:all .2s; }
.addr-card:hover,.addr-card.selected { border-color:#4f46e5;background:#eef2ff; }

/* Spinner */
.spinner { display:inline-block;width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Toast */
#toast-wrap{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{min-width:260px;max-width:360px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.15);display:flex;align-items:flex-start;gap:10px;animation:toastIn .3s ease;pointer-events:auto;}
.toast.success{background:#f0fdf4;border:1px solid #86efac;color:#166534;}
.toast.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}
.toast.info{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af;}
.toast.warning{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;}
.toast-close{margin-left:auto;cursor:pointer;opacity:.6;font-size:18px;line-height:1;flex-shrink:0;}
.toast-close:hover{opacity:1;}
@keyframes toastIn{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}
@keyframes toastOut{from{transform:translateX(0);opacity:1;}to{transform:translateX(110%);opacity:0;}}

/* Address modal */
#addrModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8000;display:flex;align-items:center;justify-content:center;}
#addrModal.hidden{display:none;}
</style>
</head>
<body>
<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="max-w-5xl mx-auto">

            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-indigo-600">Create New Order</h1>
                <p class="text-gray-500 text-sm mt-1">Place an order on behalf of a customer</p>
            </div>

            <!-- Shipping Mode Banner -->
            <div class="mb-5 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800 flex items-center gap-2">
                <span class="font-semibold">Active Shipping Policy:</span>
                <span><?= $shippingModeLabel ?></span>
                <a href="Shipping.php" class="ml-auto text-xs text-indigo-500 underline whitespace-nowrap">Change Settings</a>
            </div>

            <!-- Step Bar -->
            <div class="flex items-center mb-8 bg-white rounded-xl p-4 shadow-sm">
                <div class="flex items-center flex-col">
                    <div class="step-dot active" id="dot1">1</div>
                    <span class="text-xs mt-1 text-gray-500">Customer</span>
                </div>
                <div class="step-line" id="line1"></div>
                <div class="flex items-center flex-col">
                    <div class="step-dot idle" id="dot2">2</div>
                    <span class="text-xs mt-1 text-gray-500">Items</span>
                </div>
                <div class="step-line" id="line2"></div>
                <div class="flex items-center flex-col">
                    <div class="step-dot idle" id="dot3">3</div>
                    <span class="text-xs mt-1 text-gray-500">Address</span>
                </div>
                <div class="step-line" id="line3"></div>
                <div class="flex items-center flex-col">
                    <div class="step-dot idle" id="dot4">4</div>
                    <span class="text-xs mt-1 text-gray-500">Review</span>
                </div>
            </div>

            <!-- ===== STEP 1: Select Customer ===== -->
            <div class="panel active bg-white rounded-xl p-6 shadow-sm" id="step1">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Step 1 – Select Customer</h2>

                <input type="text" id="userSearch" class="field mb-3" placeholder="🔍 Search by name or mobile...">

                <div id="userList" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-80 overflow-y-auto pr-1">
                    <?php foreach ($users as $u): ?>
                    <div class="addr-card user-card flex items-center gap-3"
                         data-id="<?= $u['id'] ?>"
                         data-name="<?= htmlspecialchars($u['name'] ?? '') ?>"
                         data-mobile="<?= htmlspecialchars($u['mobile'] ?? '') ?>"
                         onclick="selectUser(this)">
                        <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-content-center font-bold text-lg flex-shrink-0" style="justify-content:center">
                            <?= strtoupper(substr($u['name'] ?? '?', 0, 1)) ?>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800"><?= htmlspecialchars($u['name'] ?? '') ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($u['mobile'] ?? '') ?> · <?= htmlspecialchars($u['email'] ?? '') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="selectedUserBadge" class="hidden mt-4 p-3 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-700 font-medium"></div>

                <div class="flex justify-end mt-6">
                    <button class="btn-primary" onclick="goStep(2)">Next: Add Items →</button>
                </div>
            </div>

            <!-- ===== STEP 2: Add Items ===== -->
            <div class="panel bg-white rounded-xl p-6 shadow-sm" id="step2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Step 2 – Add Items</h2>

                <!-- Item Selector -->
                <div class="flex gap-3 mb-4">
                    <select id="itemSelect" class="field flex-1">
                        <option value="">— Select a product —</option>
                        <?php foreach ($items as $it): ?>
                        <option value="<?= $it['id'] ?>" data-name="<?= htmlspecialchars($it['name']) ?>" data-thumb="<?= htmlspecialchars($it['thumb'] ?? '') ?>">
                            <?= htmlspecialchars($it['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-primary whitespace-nowrap" onclick="loadVariants()">Load Variants</button>
                </div>

                <!-- Variants Dropdown -->
                <div id="variantSection" class="hidden mb-4 p-4 bg-gray-50 rounded-lg border">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Select Variant</label>
                    <select id="variantSelect" class="field mb-3">
                        <option value="">— Loading variants... —</option>
                    </select>
                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium text-gray-600">Qty:</label>
                        <input type="number" id="itemQty" class="field" style="width:80px" value="1" min="1">
                        <button class="btn-primary" onclick="addItemToCart()">+ Add to Cart</button>
                    </div>
                </div>

                <!-- Cart Table -->
                <div id="cartSection" class="hidden">
                    <h3 class="font-semibold text-gray-700 mb-2">Cart Items</h3>
                    <table class="cart-table w-full rounded-lg overflow-hidden border border-gray-100">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Variant</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th>Amount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cartBody"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-right font-bold text-gray-700 pr-3">Subtotal</td>
                                <td class="font-bold text-indigo-600" id="subtotalCell">₹0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="flex justify-between mt-6">
                    <button class="btn-secondary" onclick="goStep(1)">← Back</button>
                    <button class="btn-primary" onclick="goStep(3)">Next: Select Address →</button>
                </div>
            </div>

            <!-- ===== STEP 3: Address ===== -->
            <div class="panel bg-white rounded-xl p-6 shadow-sm" id="step3">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Step 3 – Delivery Address</h2>

                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-gray-500" id="addrHint">Select a customer in Step 1 to load addresses.</span>
                    <button class="btn-primary text-sm py-2 px-4" onclick="openAddrModal(null)" style="font-size:13px;padding:7px 14px;">
                        + Add New Address
                    </button>
                </div>
                <div id="addressList" class="grid grid-cols-1 md:grid-cols-2 gap-3 max-h-72 overflow-y-auto pr-1">
                    <p class="text-gray-400 text-sm">Select a customer first to load addresses.</p>
                </div>

                <!-- Shipping Charge -->
                <div class="mt-6 p-4 bg-gray-50 border rounded-lg">

                    <!-- Shipping mode info banner -->
                    <div class="mb-4 p-3 rounded-lg border text-sm font-medium
                        <?php if($shippingMode==='free') echo 'bg-green-50 border-green-200 text-green-800';
                              elseif($shippingMode==='conditional') echo 'bg-blue-50 border-blue-200 text-blue-800';
                              elseif($shippingMode==='fixed') echo 'bg-yellow-50 border-yellow-200 text-yellow-800';
                              elseif($shippingMode==='broker') echo 'bg-purple-50 border-purple-200 text-purple-800';
                              else echo 'bg-gray-100 border-gray-200 text-gray-700'; ?>">
                        <span class="font-semibold">Shipping Policy:</span> <?= $shippingModeLabel ?>
                    </div>

                    <div class="flex items-center gap-4 flex-wrap">
                        <!-- Pincode always shown (needed for default/conditional) -->
                        <div>
                            <label class="text-sm font-semibold text-gray-700 block mb-1">Delivery Pincode</label>
                            <input type="text" id="deliveryPincode" class="field" style="width:150px" placeholder="e.g. 600001" maxlength="6">
                        </div>

                        <!-- Weight: only needed when rate depends on it -->
                        <?php if(in_array($shippingMode, ['default','conditional','fixed']) && ($shippingSettings['shipping_calculation']??'flat')==='weight'): ?>
                        <div>
                            <label class="text-sm font-semibold text-gray-700 block mb-1">Weight (kg)</label>
                            <input type="number" id="shipWeight" class="field" style="width:100px" value="0.5" step="0.1" min="0.1">
                        </div>
                        <?php else: ?>
                        <!-- hidden weight fallback -->
                        <input type="hidden" id="shipWeight" value="0.5">
                        <?php endif; ?>

                        <!-- Payment method -->
                        <div>
                            <label class="text-sm font-semibold text-gray-700 block mb-1">Payment Method</label>
                            <select id="paymentMethod" class="field" style="width:160px">
                                <option value="online">Online / Razorpay</option>
                                <?php if ($codEnabled): ?>
                                <option value="cod">Cash on Delivery (COD)</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Check Shipping button: shown only for default/conditional (need Shiprocket call) -->
                        <?php if(in_array($shippingMode, ['default','conditional'])): ?>
                        <div class="mt-5">
                            <button class="btn-primary" onclick="fetchShipping()">
                                <span id="shipBtnLabel">Check Shipping</span>
                            </button>
                        </div>
                        <?php else: ?>
                        <!-- For free/fixed/broker: auto-set charge without Shiprocket call -->
                        <div class="mt-5">
                            <button class="btn-primary" onclick="autoSetShipping()">
                                <span id="shipBtnLabel">Apply Shipping</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="shippingResult" class="mt-3 hidden text-sm text-gray-700"></div>
                </div>

                <div class="flex justify-between mt-6">
                    <button class="btn-secondary" onclick="goStep(2)">← Back</button>
                    <button class="btn-primary" onclick="goStep(4)">Next: Review →</button>
                </div>
            </div>

            <!-- ===== STEP 4: Review & Place ===== -->
            <div class="panel bg-white rounded-xl p-6 shadow-sm" id="step4">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Step 4 – Review &amp; Place Order</h2>

                <div id="reviewContent" class="space-y-4 text-sm text-gray-700"></div>

                <div id="placeOrderResult" class="mt-4"></div>

                <div class="flex justify-between mt-6">
                    <button class="btn-secondary" onclick="goStep(3)">← Back</button>
                    <button class="btn-primary" id="placeBtn" onclick="placeOrder()">
                        🛒 Place Order
                    </button>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
/* =========================================
   TOAST SYSTEM
========================================= */
const _toastIcons = {success:'✅',error:'❌',info:'ℹ️',warning:'⚠️'};
function showToast(msg, type='info', duration=4000) {
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.id='toast-wrap'; document.body.appendChild(wrap); }
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<span>${_toastIcons[type]||'ℹ️'}</span><span style="flex:1">${msg}</span><span class="toast-close" onclick="this.parentElement.remove()">×</span>`;
    wrap.appendChild(t);
    setTimeout(() => {
        t.style.animation = 'toastOut .3s ease forwards';
        setTimeout(() => t.remove(), 300);
    }, duration);
}

/* =========================================
   STATE
========================================= */
let selectedUser   = null;
let cart           = [];
let selectedAddrId = null;
let _addrCache     = [];   // full address objects for edit
let shippingData   = { charge: 0, courierId: null, courierName: '', eta: '', etd: '' };
let currentStep    = 1;

// PHP shipping config passed to JS
const shippingMode     = <?= json_encode($shippingMode) ?>;
const shippingModeData = <?= json_encode([
    'mode'            => $shippingMode,
    'fixed_charge'    => (float)($shippingSettings['fixed_charge'] ?? 0),
    'weight_rate'     => (float)($shippingSettings['weight_rate'] ?? 0),
    'broker_charge'   => (float)($shippingSettings['broker_charge'] ?? 0),
    'broker_name'     => $shippingSettings['broker_name'] ?? '',
    'calc_type'       => $shippingSettings['shipping_calculation'] ?? 'flat',
    'conditional_min' => (float)($shippingSettings['conditional_min_order'] ?? 0),
]) ?>;

function safeDate(val) {
    if (!val) return null;
    if (/^\d{4}-\d{2}-\d{2}/.test(val)) return val;
    return null;
}

/* =========================================
   STEPS
========================================= */
function goStep(n) {
    if (n === 2 && !selectedUser) { showToast('Please select a customer first.', 'warning'); return; }
    if (n === 3 && cart.length === 0) { showToast('Please add at least one item to the cart.', 'warning'); return; }
    if (n === 4) {
        if (!selectedAddrId) { showToast('Please select a delivery address.', 'warning'); return; }
        if (shippingData.charge === 0 && shippingMode !== 'free') {
            // Allow zero charge only for free mode; for others require shipping check
            if (!['free'].includes(shippingMode) && !shippingData.courierName) {
                showToast('Please apply/check shipping charge before reviewing.', 'warning'); return;
            }
        }
        buildReview();
    }
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    currentStep = n;
    updateDots(n);
    if (n === 3 && selectedUser) loadAddresses();
}

function updateDots(active) {
    for (let i = 1; i <= 4; i++) {
        const dot  = document.getElementById('dot' + i);
        const line = document.getElementById('line' + i);
        dot.className = 'step-dot ' + (i < active ? 'done' : i === active ? 'active' : 'idle');
        if (line) line.className = 'step-line ' + (i < active ? 'done' : '');
    }
}

/* =========================================
   STEP 1 – Customer
========================================= */
document.getElementById('userSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.user-card').forEach(c => {
        c.style.display = (c.dataset.name.toLowerCase().includes(q) || c.dataset.mobile.includes(q)) ? '' : 'none';
    });
});

function selectUser(el) {
    document.querySelectorAll('.user-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedUser = { id: el.dataset.id, name: el.dataset.name, mobile: el.dataset.mobile };
    const badge = document.getElementById('selectedUserBadge');
    badge.classList.remove('hidden');
    badge.textContent = '✓ Selected: ' + selectedUser.name + ' (' + selectedUser.mobile + ')';
}

/* =========================================
   STEP 2 – Items
========================================= */
let variantsCache = {};

async function loadVariants() {
    const sel = document.getElementById('itemSelect');
    const id  = sel.value;
    if (!id) { showToast('Please select a product first.', 'warning'); return; }
    const section = document.getElementById('variantSection');
    section.classList.remove('hidden');
    const vSel = document.getElementById('variantSelect');
    vSel.innerHTML = '<option>Loading...</option>';
    if (variantsCache[id]) { populateVariants(variantsCache[id]); return; }
    const res  = await fetch('Manageitems.php?action=fetch_variants&id=' + id);
    const data = await res.json();
    variantsCache[id] = data;
    populateVariants(data);
}

function populateVariants(variants) {
    const vSel = document.getElementById('variantSelect');
    vSel.innerHTML = '<option value="">— Select variant —</option>';
    variants.forEach(v => {
        const label = v.weight_value + ' ' + v.weight_unit + ' — ₹' + v.price
            + (v.old_price ? ' (was ₹' + v.old_price + ')' : '') + ' | Stock: ' + v.stock;
        const opt = new Option(label, v.id);
        opt.dataset.price    = v.price;
        opt.dataset.oldprice = v.old_price || v.price;
        opt.dataset.discount = v.discount || 0;
        opt.dataset.stock    = v.stock;
        opt.dataset.label    = v.weight_value + ' ' + v.weight_unit;
        vSel.appendChild(opt);
    });
}

function addItemToCart() {
    const itemSel    = document.getElementById('itemSelect');
    const variantSel = document.getElementById('variantSelect');
    const qty        = parseInt(document.getElementById('itemQty').value) || 1;
    if (!variantSel.value) { showToast('Please select a variant.', 'warning'); return; }
    const opt      = variantSel.options[variantSel.selectedIndex];
    const itemId   = itemSel.value;
    const itemName = itemSel.options[itemSel.selectedIndex].dataset.name;
    const existing = cart.find(c => c.variantId == variantSel.value);
    if (existing) {
        existing.qty += qty;
        showToast('Quantity updated for ' + itemName, 'info');
    } else {
        cart.push({
            itemId, itemName,
            variantId:    variantSel.value,
            variantLabel: opt.dataset.label,
            price:        parseFloat(opt.dataset.price),
            oldPrice:     parseFloat(opt.dataset.oldprice),
            discount:     parseFloat(opt.dataset.discount),
            qty
        });
        showToast(itemName + ' added to cart.', 'success');
    }
    renderCart();
    document.getElementById('itemSelect').value = '';
    document.getElementById('variantSection').classList.add('hidden');
}

function renderCart() {
    const tbody = document.getElementById('cartBody');
    tbody.innerHTML = '';
    let subtotal = 0;
    cart.forEach((item, idx) => {
        const amt = item.price * item.qty;
        subtotal += amt;
        tbody.innerHTML += `
            <tr>
                <td>${idx + 1}</td>
                <td>${item.itemName}</td>
                <td>${item.variantLabel}</td>
                <td>₹${item.price.toFixed(2)}</td>
                <td>
                    <div class="flex items-center gap-1">
                        <button onclick="changeQty(${idx},-1)" class="w-6 h-6 rounded bg-gray-200 text-gray-700 font-bold text-sm">−</button>
                        <span class="w-8 text-center">${item.qty}</span>
                        <button onclick="changeQty(${idx},1)"  class="w-6 h-6 rounded bg-gray-200 text-gray-700 font-bold text-sm">+</button>
                    </div>
                </td>
                <td class="font-semibold text-indigo-600">₹${amt.toFixed(2)}</td>
                <td><button class="btn-danger" onclick="removeItem(${idx})">✕</button></td>
            </tr>`;
    });
    document.getElementById('subtotalCell').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('cartSection').classList.toggle('hidden', cart.length === 0);
}

function changeQty(idx, delta) {
    cart[idx].qty = Math.max(1, cart[idx].qty + delta);
    renderCart();
}

function removeItem(idx) {
    const name = cart[idx].itemName;
    cart.splice(idx, 1);
    renderCart();
    showToast(name + ' removed from cart.', 'info');
}

/* =========================================
   STEP 3 – Address
========================================= */
async function loadAddresses() {
    const container = document.getElementById('addressList');
    container.innerHTML = '<p class="text-gray-400 text-sm col-span-2">Loading addresses...</p>';
    const res  = await fetch('/api/admin/get_user_addresses.php?user_id=' + selectedUser.id);
    const data = await res.json();

    if (!data.success || !data.addresses.length) {
        container.innerHTML = '<p class="text-gray-400 text-sm col-span-2 italic">No saved addresses for this customer. Use "+ Add New Address" to create one.</p>';
        _addrCache = [];
        return;
    }

    _addrCache = data.addresses;
    container.innerHTML = '';
    data.addresses.forEach(addr => {
        const div = document.createElement('div');
        div.className = 'addr-card relative';
        div.dataset.id = addr.id;
        div.dataset.pincode = addr.pincode;
        div.innerHTML = `
            <div class="font-semibold text-gray-800">${addr.contact_name} <span class="text-gray-400 font-normal">(${addr.contact_mobile})</span></div>
            <div class="text-xs text-gray-500 mt-1 leading-relaxed">
                ${addr.address_line1}${addr.address_line2 ? ', ' + addr.address_line2 : ''}<br>
                ${addr.city}, ${addr.state} – ${addr.pincode}
                ${addr.landmark ? '<br><span class="italic">Near: ' + addr.landmark + '</span>' : ''}
            </div>
            <button onclick="event.stopPropagation(); editAddress(${addr.id})"
                class="absolute top-2 right-2 text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-600 border border-indigo-200 px-2 py-1 rounded-md font-medium transition">
                ✏️ Edit
            </button>`;
        div.onclick = () => {
            document.querySelectorAll('#addressList .addr-card').forEach(c => c.classList.remove('selected'));
            div.classList.add('selected');
            selectedAddrId = addr.id;
            document.getElementById('deliveryPincode').value = addr.pincode;
            showToast('Address selected. Now apply shipping below.', 'info', 2500);
        };
        container.appendChild(div);
    });
}

function editAddress(id) {
    const addr = _addrCache.find(a => a.id == id);
    if (addr) openAddrModal(addr);
}

/* ─── autoSetShipping: used for free / fixed / broker modes ─── */
function autoSetShipping() {
    const pincode = document.getElementById('deliveryPincode').value.trim();
    if (pincode.length !== 6) {
        showToast('Please select an address or enter a valid 6-digit pincode first.', 'warning');
        return;
    }

    let charge = 0;
    let courierLabel = '';
    let icon = '📦';

    if (shippingMode === 'free') {
        charge = 0;
        courierLabel = 'Free Shipping';
        icon = '🆓';
    } else if (shippingMode === 'fixed') {
        const weight = parseFloat(document.getElementById('shipWeight')?.value || 0.5);
        charge = shippingModeData.calc_type === 'weight'
            ? Math.round(shippingModeData.weight_rate * weight * 100) / 100
            : shippingModeData.fixed_charge;
        courierLabel = 'Fixed Rate';
        icon = '📦';
    } else if (shippingMode === 'broker') {
        charge       = shippingModeData.broker_charge;
        courierLabel = shippingModeData.broker_name || 'Broker';
        icon = '🚚';
    }

    shippingData = { charge, courierId: null, courierName: courierLabel, eta: '', etd: null };

    const result = document.getElementById('shippingResult');
    result.classList.remove('hidden');
    result.innerHTML = `
        <div class="flex items-center gap-3 p-3 bg-green-50 border border-green-200 rounded-lg text-sm">
            <span class="text-green-700 font-semibold">${icon} ${courierLabel}</span>
            <span class="text-gray-600">Charge: <strong class="text-gray-900">₹${charge.toFixed(2)}</strong></span>
            <span class="ml-auto text-green-600 font-semibold text-xs">✓ Applied</span>
        </div>`;
    showToast('Shipping applied: ' + courierLabel + ' – ₹' + charge.toFixed(2), 'success');
}

/* ─── fetchShipping: used for default / conditional modes ─── */
async function fetchShipping() {
    const pincode = document.getElementById('deliveryPincode').value.trim();
    const weight  = document.getElementById('shipWeight').value;
    const method  = document.getElementById('paymentMethod').value;

    if (pincode.length !== 6) {
        showToast('Please select an address or enter a valid 6-digit pincode.', 'warning');
        return;
    }

    const btn = document.getElementById('shipBtnLabel');
    btn.innerHTML = '<span class="spinner"></span> Checking...';

    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const body = new URLSearchParams({ pincode, weight, cod: method === 'cod' ? 1 : 0, subtotal });

    const res  = await fetch('/getDeliveryCharge.php', { method: 'POST', body });
    const data = await res.json();
    btn.textContent = 'Check Shipping';

    const result = document.getElementById('shippingResult');
    result.classList.remove('hidden');

    if (!data.success) {
        result.innerHTML = `<div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-600 text-sm">⚠️ ${data.error || 'Could not fetch shipping rates.'}</div>`;
        showToast(data.error || 'Could not fetch shipping rates.', 'error');
        return;
    }

    shippingData = {
        charge:      data.rate,
        courierId:   data.courier_id,
        courierName: data.courier_name,
        eta:         data.estimated_delivery_days || '',
        etd:         safeDate(data.etd)
    };

    result.innerHTML = `
        <div class="flex items-center gap-4 flex-wrap p-3 bg-green-50 border border-green-200 rounded-lg text-sm">
            <span class="text-green-700 font-semibold">✓ ${data.courier_name}</span>
            <span class="text-gray-700">Shipping: <strong>₹${data.rate}</strong></span>
            ${data.estimated_delivery_days ? `<span class="text-gray-500">ETA: <strong>${data.estimated_delivery_days} days</strong></span>` : ''}
            ${data.etd ? `<span class="text-gray-400 text-xs">(${data.etd})</span>` : ''}
        </div>`;
    showToast('Shipping rate fetched: ' + data.courier_name + ' – ₹' + data.rate, 'success');
}

/* =========================================
   STEP 4 – Review
========================================= */
function buildReview() {
    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const total    = subtotal + shippingData.charge;
    const method   = document.getElementById('paymentMethod').value;
    const courier  = shippingData.courierName || '—';
    const shipping = shippingData.charge;

    const itemsHtml = cart.map((item, i) => `
        <tr>
            <td>${i+1}</td>
            <td>${item.itemName}</td>
            <td>${item.variantLabel}</td>
            <td>₹${item.price.toFixed(2)}</td>
            <td>${item.qty}</td>
            <td class="font-bold text-indigo-600">₹${(item.price * item.qty).toFixed(2)}</td>
        </tr>`).join('');

    // Find selected address label
    const selectedAddr = _addrCache.find(a => a.id == selectedAddrId);
    const addrLine = selectedAddr
        ? `${selectedAddr.contact_name}, ${selectedAddr.address_line1}, ${selectedAddr.city} – ${selectedAddr.pincode}`
        : '—';

    document.getElementById('reviewContent').innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 bg-indigo-50 rounded-lg border border-indigo-100">
                <h3 class="font-bold text-indigo-700 mb-2">👤 Customer</h3>
                <p><strong>Name:</strong> ${selectedUser.name}</p>
                <p><strong>Mobile:</strong> ${selectedUser.mobile}</p>
            </div>
            <div class="p-4 bg-indigo-50 rounded-lg border border-indigo-100">
                <h3 class="font-bold text-indigo-700 mb-2"> Payment & Shipping</h3>
                <p><strong>Method:</strong> ${method === 'cod' ? 'Cash on Delivery' : 'Online / Razorpay'}</p>
                <p><strong>Courier:</strong> ${courier}</p>
                <p><strong>Shipping:</strong> ₹${shipping.toFixed(2)}</p>
            </div>
        </div>
        <div class="mt-3 p-3 bg-gray-50 rounded-lg border text-sm text-gray-700">
            <span class="font-semibold">📍 Deliver to:</span> ${addrLine}
        </div>
        <div class="mt-4">
            <h3 class="font-bold text-gray-700 mb-2">🛒 Items</h3>
            <table class="cart-table w-full rounded-lg overflow-hidden border border-gray-100">
                <thead><tr><th>#</th><th>Product</th><th>Variant</th><th>Price</th><th>Qty</th><th>Amount</th></tr></thead>
                <tbody>${itemsHtml}</tbody>
            </table>
        </div>
        <div class="mt-4 p-4 bg-gray-50 rounded-lg border text-right space-y-1 text-sm">
            <p>Subtotal: <strong>₹${subtotal.toFixed(2)}</strong></p>
            <p>Shipping: <strong>₹${shippingData.charge.toFixed(2)}</strong></p>
            <p class="text-lg font-bold text-indigo-600 mt-1">Total: ₹${total.toFixed(2)}</p>
        </div>`;
}

/* =========================================
   PLACE ORDER
========================================= */
async function placeOrder() {
    const btn    = document.getElementById('placeBtn');
    const result = document.getElementById('placeOrderResult');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Placing Order...';
    result.innerHTML = '';

    const subtotal = cart.reduce((s, i) => s + i.price * i.qty, 0);
    const total    = subtotal + shippingData.charge;
    const method   = document.getElementById('paymentMethod').value;

    const payload = {
        user_id:            selectedUser.id,
        address_id:         selectedAddrId,
        payment_method:     method,
        subtotal:           parseFloat(subtotal.toFixed(2)),
        shipping_charge:    shippingData.charge,
        packing_charge:     0,
        overall_total:      parseFloat(total.toFixed(2)),
        courier_company_id: shippingData.courierId,
        courier_name:       shippingData.courierName,
        courier_eta:        shippingData.eta ? String(shippingData.eta) : null,
        courier_etd:        safeDate(shippingData.etd),
        cart: cart.map(i => ({
            id:             i.itemId,
            variant_id:     i.variantId,
            variant_weight: i.variantLabel.split(' ')[0],
            variant_unit:   i.variantLabel.split(' ')[1] || '',
            price:          i.price,
            variant_price:  i.price,
            oldamt:         i.oldPrice,
            discountRate:   i.discount,
            quantity:       i.qty
        }))
    };

    try {
        const res  = await fetch('/api/admin/admin_create_order_process.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        });
        const data = await res.json();

        btn.disabled    = false;
        btn.textContent = '🛒 Place Order';

        if (data.success) {
            // Trigger PDF in background
            fetch('/pdf_generation.php?order_id=' + data.order_id).catch(() => {});

            // Show big success banner
            result.innerHTML = `
                <div class="p-5 bg-green-50 border-2 border-green-300 rounded-xl text-green-800 mt-2">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-3xl">✅</span>
                        <div>
                            <p class="text-lg font-bold">Order #${data.order_id} Placed Successfully!</p>
                            <p class="text-sm text-green-600">${data.message || ''}</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">📄 Invoice is being generated and will appear in Enquiries shortly.</p>
                    <div class="flex flex-wrap gap-2">
                        <a href="view_order.php?id=${data.order_id}" class="btn-primary text-sm">👁 View Order</a>
                        <a href="order.php" class="btn-secondary text-sm">📋 All Orders</a>
                        <a href="ListOfEnquiries.php" class="btn-secondary text-sm">📄 Enquiries</a>
                        <button onclick="resetForm()" class="btn-secondary text-sm">➕ Create Another</button>
                    </div>
                </div>`;

            btn.style.display = 'none';
            showToast('Order #' + data.order_id + ' created successfully!', 'success', 6000);
            // Scroll result into view
            result.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            result.innerHTML = `
                <div class="p-4 bg-red-50 border border-red-300 rounded-lg text-red-700 flex items-start gap-3">
                    <span class="text-xl">❌</span>
                    <div>
                        <p class="font-semibold">Order Failed</p>
                        <p class="text-sm mt-1">${data.message || 'An unknown error occurred.'}</p>
                    </div>
                </div>`;
            showToast(data.message || 'Order placement failed.', 'error');
        }
    } catch (e) {
        btn.disabled    = false;
        btn.textContent = '🛒 Place Order';
        result.innerHTML = `<div class="p-4 bg-red-50 border border-red-300 rounded-lg text-red-700">❌ Network error. Please try again.</div>`;
        showToast('Network error. Please try again.', 'error');
    }
}

function resetForm() {
    selectedUser = null; cart = []; selectedAddrId = null; _addrCache = [];
    shippingData = { charge: 0, courierId: null, courierName: '', eta: '', etd: '' };
    document.getElementById('placeBtn').style.display = '';
    document.getElementById('placeOrderResult').innerHTML = '';
    document.getElementById('selectedUserBadge').classList.add('hidden');
    document.querySelectorAll('.user-card').forEach(c => c.classList.remove('selected'));
    renderCart();
    goStep(1);
}

/* =========================================
   ADDRESS MODAL
========================================= */
function openAddrModal(addr) {
    if (!selectedUser) { showToast('Please select a customer first.', 'warning'); return; }
    document.getElementById('addrModalTitle').textContent = addr ? 'Edit Address' : 'Add New Address';
    document.getElementById('editAddrId').value   = addr?.id    || '';
    document.getElementById('ea_name').value      = addr?.contact_name   || '';
    document.getElementById('ea_mobile').value    = addr?.contact_mobile || '';
    document.getElementById('ea_line1').value     = addr?.address_line1  || '';
    document.getElementById('ea_line2').value     = addr?.address_line2  || '';
    document.getElementById('ea_city').value      = addr?.city     || '';
    document.getElementById('ea_state').value     = addr?.state    || '';
    document.getElementById('ea_pincode').value   = addr?.pincode  || '';
    document.getElementById('ea_landmark').value  = addr?.landmark || '';
    document.getElementById('addrModalErr').classList.add('hidden');
    document.getElementById('addrModal').classList.remove('hidden');
}

function closeAddrModal() {
    document.getElementById('addrModal').classList.add('hidden');
}

async function saveAddress() {
    const id      = document.getElementById('editAddrId').value;
    const name    = document.getElementById('ea_name').value.trim();
    const mobile  = document.getElementById('ea_mobile').value.trim();
    const line1   = document.getElementById('ea_line1').value.trim();
    const city    = document.getElementById('ea_city').value.trim();
    const state   = document.getElementById('ea_state').value.trim();
    const pincode = document.getElementById('ea_pincode').value.trim();
    const errEl   = document.getElementById('addrModalErr');
    errEl.classList.add('hidden');

    if (!name || !mobile || !line1 || !city || !state || !pincode) {
        errEl.textContent = 'Please fill all required fields (*)'; errEl.classList.remove('hidden'); return;
    }
    if (!/^\d{10}$/.test(mobile)) {
        errEl.textContent = 'Mobile must be 10 digits.'; errEl.classList.remove('hidden'); return;
    }
    if (!/^\d{6}$/.test(pincode)) {
        errEl.textContent = 'Pincode must be 6 digits.'; errEl.classList.remove('hidden'); return;
    }

    const saveLbl = document.getElementById('saveAddrLbl');
    saveLbl.innerHTML = '<span class="spinner" style="border-color:#fff;border-top-color:transparent;"></span> Saving...';

    const payload = {
        user_id: selectedUser.id, id: id || null,
        contact_name: name, contact_mobile: mobile,
        address_line1: line1,
        address_line2: document.getElementById('ea_line2').value.trim(),
        city, state, pincode,
        landmark: document.getElementById('ea_landmark').value.trim()
    };

    try {
        const res  = await fetch('/save_address.php?admin=1', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
        });
        const data = await res.json();
        saveLbl.textContent = 'Save';
        if (!data.success) { errEl.textContent = data.message || 'Failed to save.'; errEl.classList.remove('hidden'); return; }
        showToast(id ? 'Address updated.' : 'New address added.', 'success');
        closeAddrModal();
        loadAddresses();
    } catch(e) {
        saveLbl.textContent = 'Save';
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.remove('hidden');
    }
}
</script></html>

<!-- Toast Container -->
<div id="toast-wrap"></div>

<!-- Address Edit / Add Modal -->
<div id="addrModal" class="hidden">
    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-4 max-h-screen overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-gray-800" id="addrModalTitle">Add Address</h3>
            <button onclick="closeAddrModal()" class="text-gray-400 hover:text-gray-700 text-2xl leading-none font-light">&times;</button>
        </div>
        <input type="hidden" id="editAddrId">
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-gray-600 block mb-1">Contact Name *</label>
                    <input type="text" id="ea_name" class="field" placeholder="Full name">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-600 block mb-1">Mobile *</label>
                    <input type="text" id="ea_mobile" class="field" placeholder="10-digit" maxlength="10">
                </div>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600 block mb-1">Address Line 1 *</label>
                <input type="text" id="ea_line1" class="field" placeholder="House/Flat, Street">
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600 block mb-1">Address Line 2</label>
                <input type="text" id="ea_line2" class="field" placeholder="Area, Colony (optional)">
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-xs font-semibold text-gray-600 block mb-1">City *</label>
                    <input type="text" id="ea_city" class="field">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-600 block mb-1">State *</label>
                    <input type="text" id="ea_state" class="field">
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-600 block mb-1">Pincode *</label>
                    <input type="text" id="ea_pincode" class="field" maxlength="6">
                </div>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600 block mb-1">Landmark</label>
                <input type="text" id="ea_landmark" class="field" placeholder="Near...">
            </div>
        </div>
        <div id="addrModalErr" class="hidden mt-3 p-2 bg-red-50 border border-red-200 rounded text-red-700 text-sm"></div>
        <div class="flex justify-end gap-3 mt-5">
            <button class="btn-secondary" onclick="closeAddrModal()">Cancel</button>
            <button class="btn-primary" onclick="saveAddress()">
                <span id="saveAddrLbl">Save</span>
            </button>
        </div>
    </div>
</div>

</body>
</html>