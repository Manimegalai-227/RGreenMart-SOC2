<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'vendor/autoload.php';
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php"; // PDO $conn

// Fetch COD + advance + charge settings in one query
$stmtCod = $conn->prepare("SELECT cod_enabled, cod_advance_enabled, cod_advance_percent,
                                   cod_charge_enabled, cod_charge_type, cod_charge_value
                             FROM shipping_settings WHERE id = 1");
$stmtCod->execute();
$shipSet = $stmtCod->fetch(PDO::FETCH_ASSOC);

$codEnabled        = (int)($shipSet['cod_enabled']          ?? 0);
$codAdvanceEnabled = (int)($shipSet['cod_advance_enabled']  ?? 0);
$codAdvancePercent = (float)($shipSet['cod_advance_percent'] ?? 0);
// Only active when COD is on AND advance is on AND percent > 0
$showAdvance = ($codEnabled && $codAdvanceEnabled && $codAdvancePercent > 0);

// COD Convenience Fee (charge mode) — mutually exclusive with advance
$codChargeEnabled = (int)($shipSet['cod_charge_enabled'] ?? 0);
$codChargeType    = $shipSet['cod_charge_type'] ?? 'flat';
$codChargeValue   = (float)($shipSet['cod_charge_value'] ?? 0);
$showCodCharge    = ($codEnabled && $codChargeEnabled && $codChargeValue > 0 && !$showAdvance);

// Coupon feature toggle
$promoRow      = $conn->query("SELECT coupon_enabled, wallet_purchase_enabled, referral_enabled, referral_type, referral_percent, referral_amount FROM promo_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$couponEnabled          = (int)($promoRow['coupon_enabled']          ?? 0);
$walletPurchaseEnabled  = (int)($promoRow['wallet_purchase_enabled'] ?? 0);
$referralEnabled        = (int)($promoRow['referral_enabled']        ?? 0);

use Razorpay\Api\Api;

if (!isset($_SESSION["user_id"])) {
    $_SESSION["redirect_after_login"] = "add_delivery_address.php";
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION["user_id"] ?? 0;

// Fetch wallet balance + referred_by for this user
$userRow = $conn->prepare("SELECT referral_wallet, referred_by, name, referral_discount_type, referral_discount_value, email_verified FROM users WHERE id=? LIMIT 1");
$userRow->execute([$user_id]);
$userInfo = $userRow->fetch(PDO::FETCH_ASSOC);
$walletBalance   = floatval($userInfo['referral_wallet'] ?? 0);
$emailVerified   = (int)($userInfo['email_verified'] ?? 0);

// Check if this user has placed a first order already (to know if referral discount applies)
$firstOrderStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND payment_status IN ('paid','advance_paid','partial_paid')");
$firstOrderStmt->execute([$user_id]);
$paidOrderCount = (int)$firstOrderStmt->fetchColumn();
$isFirstOrder   = ($paidOrderCount === 0);

// Referral discount for this user's first order.
// getUserReferralDiscount() reads the locked-in snapshot on the user row (set at registration).
// For users who registered BEFORE the snapshot fix, it automatically falls back to live
// promo_settings and saves the snapshot — so the discount works for ALL existing referred users.
// NOTE: $referralEnabled check is intentionally omitted here — the function handles it
// internally so that users whose snapshot was already saved still get their discount even
// if the admin temporarily disables the promo toggle.
$referralDiscountValue = 0;
$referralDiscountType  = 'none';
if ($isFirstOrder && !empty($userInfo['referred_by'])) {
    require_once 'generate_referral_code.php';
    $userDiscount = getUserReferralDiscount($conn, $user_id);
    $referralDiscountType  = $userDiscount['type'];
    $referralDiscountValue = ($referralDiscountType !== 'none') ? $userDiscount['value'] : 0;
}

// Fetch user addresses
$stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
$stmt->execute([$user_id]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Delivery Address</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script src="cart.js"></script>
    <script src="toast.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-100">
    <?php include "includes/header.php"; ?>

    <div class="max-w-3xl mx-auto m-10 p-6 bg-white rounded-xl shadow-lg">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold text-gray-700">Select Delivery Address</h2>
            <button onclick="openModal()" class="text-indigo-600 hover:text-indigo-800 font-semibold">
                + Add New Address
            </button>
        </div>

        <div class="space-y-4">
            <?php foreach ($addresses as $addr): ?>
            <div class="border rounded-xl p-4 hover:shadow-md bg-gray-50 transition address-card">
                <div class="flex justify-between">
                    <label class="flex items-start space-x-3 cursor-pointer w-full">
                        <input type="radio" name="selected_address" value="<?= $addr['id'] ?>"
                            data-pincode="<?= htmlspecialchars($addr['pincode']) ?>"
                            class="mt-1 w-4 h-4 text-indigo-600" <?= $addr['is_default'] ? 'checked' : '' ?>>
                        <div class="flex-1">
                            <p class="font-semibold text-gray-800">
                                <?= htmlspecialchars($addr['contact_name']) ?>
                                <?php if($addr['is_default']): ?>
                                <span class="ml-2 text-xs bg-green-200 text-green-700 px-2 py-1 rounded-full">Default</span>
                                <?php endif; ?>
                            </p>
                            <p class="text-gray-600 text-sm"><?= htmlspecialchars($addr['contact_mobile']) ?></p>
                            <p class="text-gray-700 mt-1 text-sm">
                                <?= htmlspecialchars($addr['address_line1']) ?>,
                                <?= htmlspecialchars($addr['address_line2']) ?><br>
                                <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['state']) ?> -
                                <span class="font-bold"><?= htmlspecialchars($addr['pincode']) ?></span>
                                <?php if(!empty($addr['landmark'])): ?>
                                <br><span class="text-gray-500 italic">Landmark: <?= htmlspecialchars($addr['landmark']) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </label>
                    <div class="flex space-x-4 ml-4">
                        <button onclick='editAddress(<?= json_encode($addr) ?>)' class="text-blue-500 hover:text-blue-700">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <a href="delete_address.php?id=<?= $addr['id'] ?>"
                            onclick="return confirm('Delete this address?')" class="text-red-500 hover:text-red-700">
                            <i class="fa-solid fa-trash-can"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($addresses)): ?>
            <div class="text-center py-10 border-2 border-dashed rounded-xl">
                <p class="text-gray-500">No saved addresses found. Please add one to continue.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Order Summary -->
        <div id="orderSummary" class="mt-8 mb-4 p-4 bg-gray-50 rounded-lg text-gray-700 space-y-2"
             style="border-top: 4px solid; border-image: linear-gradient(135deg,#e91e63,#6a1b9a) 1; border-left:none; border-right:none; border-bottom:none;">

            <div class="flex justify-between">
                <span>Items Subtotal:</span>
                <span>₹<span id="subtotalAmount">0.00</span></span>
            </div>

            <div class="flex justify-between">
                <span>Courier:</span>
                <span id="courierName">—</span>
            </div>
            <div class="flex justify-between">
                <span>Estimated Delivery:</span>
                <span id="courierETA">—</span>
            </div>
            <div class="flex justify-between">
                <span>Shipping Charge:</span>
                <span>
                    <span id="originalShippingCharge" class="line-through text-gray-400 mr-1 hidden"></span>
                    <span id="shippingChargeLabel">₹<span id="shippingCharge">0.00</span></span>
                    <span id="freeShippingBadge" class="hidden text-green-600 font-semibold">FREE</span>
                </span>
            </div>

            <?php if ($couponEnabled): ?>
            <!-- ── COUPON CODE SECTION ── -->
            <div class="py-2 border-t border-dashed">
                <label class="flex items-center gap-2 cursor-pointer mb-2">
                    <input type="checkbox" id="couponToggle" onchange="toggleCouponInput(this.checked)"
                           class="w-4 h-4 accent-indigo-600 rounded">
                    <span class="font-medium text-gray-700 text-sm">🎟 I have a Coupon Code</span>
                </label>
                <div id="couponInputArea" class="hidden ml-6">
                    <div class="flex gap-2 mb-1">
                        <input type="text" id="couponCodeInput" placeholder="Enter coupon code"
                               class="flex-1 p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-400 text-sm uppercase tracking-widest">
                        <button onclick="applyCoupon()"
                                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-semibold whitespace-nowrap">
                            Apply
                        </button>
                        <button onclick="removeCoupon()" id="removeCouponBtn"
                                class="hidden px-3 py-2 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 text-sm font-semibold">
                            ✕
                        </button>
                    </div>
                    <div id="couponMsg" class="hidden text-xs mt-1 p-2 rounded"></div>
                </div>
            </div>
            <!-- Coupon discount row (shown after successful apply) -->
            <div id="couponDiscountRow" class="hidden flex justify-between text-green-700 font-semibold text-sm">
                <span>🎟 Coupon (<span id="couponCodeApplied" class="font-mono"></span>):</span>
                <span>-₹<span id="couponDiscountAmt">0.00</span></span>
            </div>
            <?php endif; ?>

            <?php if ($referralDiscountValue > 0): ?>
            <!-- ── REFERRAL DISCOUNT ROW (first order only) ── -->
            <div class="py-2 border-t border-dashed">
                <div class="flex justify-between items-center py-1 px-2 bg-purple-50 rounded-lg border border-purple-200">
                    <span class="text-purple-700 font-semibold text-sm">
                        🎁 Referral Discount
                        <?php if ($referralDiscountType === 'percent'): ?>
                            <span class="text-xs font-normal text-purple-500">(<?= $referralDiscountValue ?>% off on first order)</span>
                        <?php endif; ?>
                    </span>
                    <span class="text-purple-700 font-bold text-sm" id="referralDiscountDisplay">
                        <?php if ($referralDiscountType === 'fixed'): ?>
                            -₹<?= number_format($referralDiscountValue, 2) ?>
                        <?php else: ?>
                            -₹<span id="referralDiscountAmt">0.00</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <!-- Hidden row kept for JS compat -->
            <div id="referralDiscountAmtRow" class="hidden"></div>
            <?php endif; ?>

            <hr class="my-2">

            <!-- Payment Method Selection -->
            <div class="space-y-2">
                <p class="font-semibold text-gray-700 text-sm">Payment Method:</p>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="payment_method" value="online" checked
                           onchange="onPaymentMethodChange('online')"
                           class="w-4 h-4 accent-indigo-600">
                    <span class="font-medium text-gray-800">Online Payment</span>
                </label>

                <?php if($codEnabled): ?>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="payment_method" value="cod"
                           onchange="onPaymentMethodChange('cod')"
                           class="w-4 h-4 accent-indigo-600">
                    <span class="font-medium text-gray-800">
                        Cash on Delivery
                        <?php if($showAdvance): ?>
                            <span class="ml-2 text-xs font-bold bg-orange-100 text-orange-700
                                         border border-orange-300 px-2 py-0.5 rounded-full">
                                <?= $codAdvancePercent ?>% Advance Required
                            </span>
                        <?php endif; ?>
                        <?php if($showCodCharge): ?>
                            <span class="ml-2 text-xs font-bold bg-amber-100 text-amber-700
                                         border border-amber-300 px-2 py-0.5 rounded-full">
                                + ₹<?= $codChargeType === 'flat' ? number_format($codChargeValue, 2) : $codChargeValue.'%' ?> COD Fee
                            </span>
                        <?php endif; ?>
                    </span>
                </label>

                <?php if($showAdvance): ?>
                <!-- COD Advance info banner — hidden until COD is selected -->
                <div id="codAdvanceBanner" class="hidden mt-1 ml-6 p-3
                     bg-orange-50 border border-orange-200 rounded-lg text-sm space-y-1">
                    <p class="font-bold text-orange-800">⚠️ Advance Payment Required</p>
                    <p class="text-orange-700">
                        To confirm this COD order, you must pay
                        <strong><?= $codAdvancePercent ?>%</strong> of your order total online now:
                        <strong class="text-blue-700">₹<span id="advanceAmountDisplay">—</span></strong>
                    </p>
                    <p class="text-orange-700">
                        Remaining balance
                        <strong class="text-green-700">₹<span id="balanceAmountDisplay">—</span></strong>
                        will be collected in cash at delivery.
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        The advance is processed securely via Razorpay. Your order is confirmed only after the advance is paid.
                    </p>
                </div>
                <?php endif; ?>

                <?php if($showCodCharge): ?>
                <!-- COD Charge banner — hidden until COD is selected -->
                <div id="codChargeBanner" class="hidden mt-1 ml-6 p-3
                     bg-amber-50 border border-amber-200 rounded-lg text-sm space-y-1">
                    <p class="font-bold text-amber-800">💳 COD Convenience Fee Required</p>
                    <p class="text-amber-700">
                        A convenience fee of
                        <?php if($codChargeType === 'flat'): ?>
                            <strong>₹<?= number_format($codChargeValue, 2) ?></strong>
                        <?php else: ?>
                            <strong><?= $codChargeValue ?>%</strong> of your order subtotal
                        <?php endif; ?>
                        is charged for Cash on Delivery orders.
                    </p>
                    <p class="text-amber-700">
                        COD Fee to pay now online:
                        <strong class="text-blue-700">₹<span id="codChargeAmountDisplay">—</span></strong>
                    </p>
                    <p class="text-amber-700">
                        Remaining order amount
                        <strong class="text-green-700">₹<span id="codChargeBalanceDisplay">—</span></strong>
                        will be collected in cash at delivery.
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        The convenience fee is processed securely via Razorpay. Your order is confirmed only after the fee is paid.
                    </p>
                </div>
                <?php endif; ?>
                <?php endif; // end codEnabled ?>

                <?php if ($walletPurchaseEnabled): ?>
                <!-- ── WALLET RADIO — only shown when admin enables wallet purchase ── -->
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="payment_method" value="wallet"
                           onchange="onPaymentMethodChange('wallet')"
                           class="w-4 h-4 accent-purple-600">
                    <span class="font-medium text-gray-800">
                         Pay via Wallet
                        <span class="ml-2 text-xs font-bold text-purple-700 bg-purple-100 border border-purple-200 px-2 py-0.5 rounded-full">
                            ₹<?= number_format($walletBalance, 2) ?> available
                        </span>
                    </span>
                </label>
                <!-- Wallet info/error banner — shown when wallet radio is selected -->
                <div id="walletBanner" class="hidden mt-1 ml-6 p-3 rounded-lg text-sm">
                    <p id="walletBannerMsg" class="font-medium"></p>
                </div>
                <?php endif; ?>

            </div><!-- end payment method space-y-2 -->

            <!-- Wallet deduction summary row — shown in order total section when wallet selected -->
            <div id="walletDiscountRow" class="hidden flex justify-between text-purple-700 font-semibold text-sm">
                <span> Wallet used:</span>
                <span>-₹<span id="walletDiscountAmt">0.00</span></span>
            </div>

            <!-- COD Convenience Fee row — shown when COD is selected and charge is enabled -->
            <div id="codChargeRow" class="hidden flex justify-between text-amber-700 font-semibold text-sm">
                <span>COD Convenience Fee:</span>
                <span>+₹<span id="codChargeRowAmt">0.00</span></span>
            </div>

            <hr class="my-2">
            <div class="flex justify-between text-xl font-bold text-gray-900">
                <span>Order Total:</span>
                <span>₹<span id="finalTotal">0.00</span></span>
            </div>

            <?php if($showCodCharge): ?>
            <!-- COD charge breakdown — hidden until COD selected -->
            <div id="codChargeBreakdownRow" class="hidden">
                <div class="flex justify-between text-sm text-blue-700 font-semibold">
                    <span>💳 Pay COD Fee Online Now:</span>
                    <span>₹<span id="codChargeDueDisplay">—</span></span>
                </div>
                <div class="flex justify-between text-sm text-green-700 font-semibold">
                    <span>🏠 Pay Cash on Delivery:</span>
                    <span>₹<span id="codChargeBalanceDueDisplay">—</span></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if($showAdvance): ?>
            <!-- Advance breakdown row — hidden until COD selected -->
            <div id="advanceBreakdownRow" class="hidden">
                <div class="flex justify-between text-sm text-blue-700 font-semibold">
                    <span>💳 Pay Online Now (<?= $codAdvancePercent ?>% advance):</span>
                    <span>₹<span id="advanceDueDisplay">—</span></span>
                </div>
                <div class="flex justify-between text-sm text-green-700 font-semibold">
                    <span>🏠 Pay Cash on Delivery:</span>
                    <span>₹<span id="balanceDueDisplay">—</span></span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <button onclick="continueToPayment()" id="payBtn"
            class="mt-4 w-full bg-gradient-to-br from-[#e91e63] to-[#6a1b9a]
                hover:from-[#c2185b] hover:to-[#4a148c]
                text-white py-3 rounded-lg font-bold text-lg
                transition duration-300 disabled:bg-gray-400">
            Continue to Payment
        </button>
    </div>

    <!-- Address Modal -->
    <div id="addressModal" style="z-index: 51;"
        class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-white p-6 rounded-xl shadow-lg w-full max-w-md">
            <h3 id="modalTitle" class="text-xl font-bold mb-4">Add New Address</h3>
            <form id="addressForm" action="save_address.php" method="POST" class="space-y-3">
                <input type="hidden" name="id" id="address_id">
                <input type="text" name="contact_name"  id="contact_name"  placeholder="Full Name"
                    class="w-full border rounded p-2" required>
                <input type="text" name="contact_mobile" id="contact_mobile" placeholder="Mobile Number"
                    class="w-full border rounded p-2" required>
                <input type="text" name="address_line1" id="address_line1" placeholder="Flat, House no., Building"
                    class="w-full border rounded p-2" required>
                <input type="text" name="address_line2" id="address_line2" placeholder="Area, Street, Sector, Village"
                    class="w-full border rounded p-2">
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" name="city"  id="city"  placeholder="City"  class="w-full border rounded p-2" required>
                    <input type="text" name="state" id="state" placeholder="State" class="w-full border rounded p-2" required>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <!-- <input type="text" name="pincode"  id="pincode"  placeholder="Pincode" class="w-full border rounded p-2" required> -->
                    <input type="text" name="pincode" id="pincode" placeholder="Pincode" class="w-full border rounded p-2"
                     required pattern="[0-9]{6}" maxlength="6" title="Please enter a valid 6-digit pincode (numbers only)">
                    <input type="text" name="landmark" id="landmark" placeholder="Landmark (Optional)" class="w-full border rounded p-2">
                </div>
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="is_default" id="is_default" value="1">
                    <span class="text-sm text-gray-600">Set as Default Address</span>
                </label>
                <div class="flex justify-end space-x-3 mt-4">
                    <button type="button" onclick="closeModal()"
                        class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Cancel</button>
                    <button id="submitButton" type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {
        const addressForm = document.getElementById("addressForm");
        if (addressForm) {
            addressForm.addEventListener("submit", function(e) {
                const pincode = document.getElementById("pincode").value.trim();
                if (!/^\d{6}$/.test(pincode)) {
                    e.preventDefault();
                    showToast("❌ Only 6-digit numeric pincode is allowed", {
                        background: "#dc2626",
                        color: "#fff"
                    });
                    return false;
                }
            });
        }
    });

    // ── PHP config passed to JS ────────────────────────────────────────────
    const EMAIL_VERIFIED      = <?= json_encode((bool)$emailVerified) ?>;
    const COD_ADVANCE_ENABLED = <?= json_encode((bool)$showAdvance) ?>;
    const COD_ADVANCE_PERCENT = <?= json_encode((float)$codAdvancePercent) ?>;
    const COD_CHARGE_ENABLED  = <?= json_encode((bool)$showCodCharge) ?>;
    const COD_CHARGE_TYPE     = <?= json_encode($codChargeType) ?>;
    const COD_CHARGE_VALUE    = <?= json_encode((float)$codChargeValue) ?>;
    const COUPON_ENABLED      = <?= json_encode((bool)$couponEnabled) ?>;
    const WALLET_ENABLED      = <?= json_encode((bool)$walletPurchaseEnabled) ?>;
    const WALLET_BALANCE      = <?= json_encode((float)$walletBalance) ?>;
    const REFERRAL_DISCOUNT_TYPE  = <?= json_encode($referralDiscountType) ?>;
    const REFERRAL_DISCOUNT_VALUE = <?= json_encode((float)$referralDiscountValue) ?>;

    // ── State ─────────────────────────────────────────────────────────────
    window.currentShippingCharge   = 0;
    window.currentFinalTotal       = 0;
    window.currentCourier          = null;
    window.currentETA              = null;
    window.currentCourierId        = null;
    window.appliedCoupon           = null; // {coupon_code, discount_type, discount_value, discount_amount}
    window.walletSelected          = false; // true when wallet radio is chosen
    // Discount accumulators — initialised to 0 so COD checkout never sends undefined/null
    window.currentCouponDiscount   = 0;
    window.currentReferralDiscount = 0;
    window.currentWalletUsed       = 0;
    window.currentCodConvFee       = 0; // COD convenience fee (charge mode)

    // ── Address modal helpers ─────────────────────────────────────────────
    function openModal() {
        document.getElementById("addressForm").reset();
        document.getElementById("modalTitle").innerText = "Add New Address";
        document.getElementById("addressForm").action   = "save_address.php";
        document.getElementById("address_id").value     = "";
        document.getElementById('addressModal').classList.remove('hidden');
    }
    function editAddress(addr) {
        document.getElementById("modalTitle").innerText        = "Edit Address";
        document.getElementById("addressForm").action          = "update_address.php";
        document.getElementById("address_id").value            = addr.id;
        document.getElementById("contact_name").value          = addr.contact_name;
        document.getElementById("contact_mobile").value        = addr.contact_mobile;
        document.getElementById("address_line1").value         = addr.address_line1;
        document.getElementById("address_line2").value         = addr.address_line2;
        document.getElementById("city").value                  = addr.city;
        document.getElementById("state").value                 = addr.state;
        document.getElementById("pincode").value               = addr.pincode;
        document.getElementById("landmark").value              = addr.landmark;
        document.getElementById("is_default").checked          = addr.is_default == 1;
        document.getElementById('addressModal').classList.remove('hidden');
    }
    function closeModal() {
        document.getElementById('addressModal').classList.add('hidden');
    }

    // ── Coupon helpers ────────────────────────────────────────────────────
    function toggleCouponInput(show) {
        const area = document.getElementById('couponInputArea');
        if (show) { area.classList.remove('hidden'); }
        else      { area.classList.add('hidden'); removeCoupon(); }
    }

    async function applyCoupon() {
        const input = document.getElementById('couponCodeInput');
        const code  = input.value.trim().toUpperCase();
        if (!code) { showCouponMsg('Please enter a coupon code.', false); return; }

        const subtotal = calculateSubtotal();
        try {
            const res  = await fetch('checkcoupon.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `coupon_code=${encodeURIComponent(code)}&cart_total=${encodeURIComponent(subtotal)}`
            });
            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                console.error('Coupon response is not JSON:', rawText);
                showCouponMsg('❌ Server error. Please try again.', false);
                return;
            }
            if (data.success) {
                window.appliedCoupon = data;
                input.value    = data.coupon_code;
                input.disabled = true;
                document.getElementById('removeCouponBtn').classList.remove('hidden');
                showCouponMsg('✅ ' + data.message, true);
            } else {
                window.appliedCoupon = null;
                showCouponMsg('❌ ' + data.message, false);
            }
        } catch (e) {
            console.error('Coupon fetch error:', e);
            showCouponMsg('❌ Network error. Please try again.', false);
        }
        updateOrderSummary(window.currentShippingCharge, window.currentCourier, window.currentETA, window.currentCourierId);
    }

    function removeCoupon() {
        window.appliedCoupon = null;
        const input = document.getElementById('couponCodeInput');
        if (input) { input.value = ''; input.disabled = false; }
        document.getElementById('removeCouponBtn')?.classList.add('hidden');
        document.getElementById('couponDiscountRow')?.classList.add('hidden');
        hideCouponMsg();
        updateOrderSummary(window.currentShippingCharge, window.currentCourier, window.currentETA, window.currentCourierId);
    }

    function showCouponMsg(msg, ok) {
        const el = document.getElementById('couponMsg');
        if (!el) return;
        el.textContent = msg;
        el.className   = 'text-xs mt-1 p-2 rounded ' + (ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
        el.classList.remove('hidden');
    }
    function hideCouponMsg() {
        const el = document.getElementById('couponMsg');
        if (el) { el.classList.add('hidden'); el.textContent = ''; }
    }
    function getCartItems() {
        return JSON.parse(localStorage.getItem("cart")) || [];
    }
    function calculateSubtotal() {
        return getCartItems().reduce((total, item) => {
            const price = parseFloat(item.variant_price ?? item.price) || 0;
            const qty   = parseInt(item.quantity ?? item.qty) || 1;
            return total + price * qty;
        }, 0);
    }
    function calculateCartWeight() {
        // Returns total cart weight in KG — always a valid, positive number.
        //
        // Field name map (covers all known cart.js storage patterns):
        //   Numeric weight  → item.weight_value  OR  item.variant_weight  OR  item.weight
        //   Unit string     → item.weight_unit   OR  item.variant_unit
        //
        // Unit heuristic when unit field is missing/blank:
        //   value >= 10  → treat as grams  (e.g. 500 g, 1000 g, 250 g)
        //   value <  10  → treat as kg     (e.g. 0.5 kg, 1 kg, 1.5 kg)

        const MIN_ITEM_WEIGHT_KG = 0.1; // 100 g fallback when product has no weight set

        return getCartItems().reduce((total, item) => {

            // ── Read raw numeric weight ────────────────────────────────────
            const rawW = item.weight_value   // primary (item_variants.weight_value)
                      ?? item.variant_weight  // alternate field name
                      ?? item.weight;         // simple-product fallback

            let w = parseFloat(rawW);

            // ── Read unit ─────────────────────────────────────────────────
            const rawUnit = item.weight_unit   // primary
                         ?? item.variant_unit;  // alternate
            const unit = (rawUnit ?? '').toLowerCase().trim();

            // ── Normalise to KG ───────────────────────────────────────────
            if (!isFinite(w) || w <= 0) {
                // Weight completely missing from this product
                w = MIN_ITEM_WEIGHT_KG;
            } else if (unit === 'g' || unit === 'gm' || unit === 'gram' || unit === 'grams') {
                w = w / 1000;          // explicit grams → kg
            } else if (unit === 'kg') {
                // already in kg — no conversion needed
            } else {
                // Unit unknown or blank — apply heuristic:
                //   ≥10 almost certainly grams (500, 1000, 250 …)
                //   <10 almost certainly kg    (0.5, 1, 1.5, 2 …)
                w = (w >= 10) ? w / 1000 : w;
            }

            const qty = parseInt(item.quantity ?? item.qty) || 1;
            return total + w * qty;

        }, 0);
    }

    // ── Wallet helpers ────────────────────────────────────────────────────
    // Called from updateOrderSummary. Returns actual wallet amount deducted.
    // Rules:
    //   • wallet radio selected AND wallet >= orderTotal  → full coverage, order goes free, no Razorpay
    //   • wallet radio selected AND wallet < orderTotal   → BLOCKED (insufficient), show error, deduct 0
    //   • COD + advance selected → wallet not used at checkout (COD advance uses Razorpay for advance)
    function applyWalletLogic(orderTotal) {
        const banner    = document.getElementById('walletBanner');
        const bannerMsg = document.getElementById('walletBannerMsg');
        const walletRow = document.getElementById('walletDiscountRow');
        const walletAmt = document.getElementById('walletDiscountAmt');

        if (!window.walletSelected || !WALLET_ENABLED) {
            if (banner) banner.classList.add('hidden');
            if (walletRow) walletRow.classList.add('hidden');
            return 0;
        }

        if (WALLET_BALANCE >= orderTotal && orderTotal > 0) {
            // ✅ Full coverage
            if (bannerMsg) {
                bannerMsg.textContent = `✅ Your wallet balance (₹${WALLET_BALANCE.toFixed(2)}) fully covers this order. No additional payment needed!`;
                bannerMsg.className = 'font-medium text-green-700';
            }
            if (banner) {
                banner.className = 'mt-1 ml-6 p-3 rounded-lg text-sm bg-green-50 border border-green-200';
                banner.classList.remove('hidden');
            }
            if (walletAmt) walletAmt.textContent = orderTotal.toFixed(2);
            if (walletRow) walletRow.classList.remove('hidden');
            return orderTotal;
        } else {
            // ❌ Insufficient balance — block checkout
            const shortfall = (orderTotal - WALLET_BALANCE).toFixed(2);
            if (bannerMsg) {
                bannerMsg.textContent = `⚠️ Insufficient wallet balance. Your wallet has ₹${WALLET_BALANCE.toFixed(2)} but the order total is ₹${orderTotal.toFixed(2)} (short by ₹${shortfall}). Please select Online Payment or Cash on Delivery.`;
                bannerMsg.className = 'font-medium text-red-700';
            }
            if (banner) {
                banner.className = 'mt-1 ml-6 p-3 rounded-lg text-sm bg-red-50 border border-red-200';
                banner.classList.remove('hidden');
            }
            if (walletRow) walletRow.classList.add('hidden');
            return 0; // do NOT deduct — payment is blocked
        }
    }

    // ── Update order summary display ──────────────────────────────────────
    function updateOrderSummary(shipping = 0, courier = null, eta = null, courierId = null, originalRate = null, isFree = false) {
        shipping = parseFloat(shipping) || 0;
        const subtotal = parseFloat(calculateSubtotal()) || 0;

        // Coupon discount
        let couponDiscount = 0;
        if (window.appliedCoupon) {
            if (window.appliedCoupon.discount_type === 'percent') {
                couponDiscount = Math.round(subtotal * window.appliedCoupon.discount_value / 100 * 100) / 100;
            } else {
                couponDiscount = Math.min(window.appliedCoupon.discount_value, subtotal);
            }
        }

        // Referral discount (first order only, from PHP)
        let referralDiscount = 0;
        if (REFERRAL_DISCOUNT_TYPE === 'fixed') {
            referralDiscount = REFERRAL_DISCOUNT_VALUE;
        } else if (REFERRAL_DISCOUNT_TYPE === 'percent' && REFERRAL_DISCOUNT_VALUE > 0) {
            referralDiscount = Math.round(subtotal * REFERRAL_DISCOUNT_VALUE / 100 * 100) / 100;
        }
        // Update referral discount amount display
        const refAmtEl = document.getElementById('referralDiscountAmt');
        if (refAmtEl && referralDiscount > 0) {
            refAmtEl.textContent = referralDiscount.toFixed(2);
        }
        const refAmtRow = document.getElementById('referralDiscountAmtRow');

        const afterDiscounts = subtotal - couponDiscount - referralDiscount + shipping;

        // Wallet — radio-based, strict: wallet must cover full order total or it's blocked
        const walletAmountUsed = applyWalletLogic(afterDiscounts);

        // ── COD Convenience Fee ────────────────────────────────────────────
        // Only added when COD is the selected payment method
        const isCodSelected = (document.querySelector('input[name="payment_method"]:checked')?.value === 'cod');
        let codConvFee = 0;
        if (COD_CHARGE_ENABLED && isCodSelected) {
            if (COD_CHARGE_TYPE === 'percent') {
                codConvFee = Math.round(subtotal * COD_CHARGE_VALUE / 100 * 100) / 100;
            } else {
                codConvFee = COD_CHARGE_VALUE;
            }
        }
        // Show/hide COD fee row
        const codChargeRow    = document.getElementById('codChargeRow');
        const codChargeRowAmt = document.getElementById('codChargeRowAmt');
        if (codChargeRow) {
            if (codConvFee > 0) {
                codChargeRow.classList.remove('hidden');
                if (codChargeRowAmt) codChargeRowAmt.textContent = codConvFee.toFixed(2);
            } else {
                codChargeRow.classList.add('hidden');
            }
        }

        const finalTotal = Math.max(0, afterDiscounts - walletAmountUsed + codConvFee);

        document.getElementById('subtotalAmount').innerText = subtotal.toFixed(2);
        document.getElementById('shippingCharge').innerText = shipping.toFixed(2);
        document.getElementById('finalTotal').innerText     = finalTotal.toFixed(2);
        document.getElementById('courierName').innerText    = courier ?? '—';
        document.getElementById('courierETA').innerText     = eta     ?? '—';

        // Coupon row
        const couponRow = document.getElementById('couponDiscountRow');
        if (couponRow) {
            if (window.appliedCoupon && couponDiscount > 0) {
                document.getElementById('couponCodeApplied').textContent = window.appliedCoupon.coupon_code;
                document.getElementById('couponDiscountAmt').textContent = couponDiscount.toFixed(2);
                couponRow.classList.remove('hidden');
            } else {
                couponRow.classList.add('hidden');
            }
        }

        const origSpan    = document.getElementById('originalShippingCharge');
        const freeBadge   = document.getElementById('freeShippingBadge');
        const chargeLabel = document.getElementById('shippingChargeLabel');
        if (isFree && originalRate && originalRate > 0) {
            origSpan.innerText = '₹' + parseFloat(originalRate).toFixed(2);
            origSpan.classList.remove('hidden');
            freeBadge.classList.remove('hidden');
            chargeLabel.classList.add('hidden');
        } else {
            origSpan.classList.add('hidden');
            freeBadge.classList.add('hidden');
            chargeLabel.classList.remove('hidden');
        }

        window.currentShippingCharge   = shipping;
        window.currentCourierId        = courierId;
        window.currentFinalTotal       = finalTotal;
        window.currentCouponDiscount   = couponDiscount;
        window.currentReferralDiscount = referralDiscount;
        window.currentWalletUsed       = walletAmountUsed;
        window.currentCodConvFee       = codConvFee;
        window.currentCourier          = courier;
        window.currentETA              = eta;

        refreshAdvanceDisplay();
        refreshCodChargeDisplay();
    }

    // ── COD advance display helpers ───────────────────────────────────────
    function refreshAdvanceDisplay() {
        if (!COD_ADVANCE_ENABLED) return;
        const total   = parseFloat(window.currentFinalTotal) || 0;
        const advance = Math.max(1, parseFloat((total * COD_ADVANCE_PERCENT / 100).toFixed(2)));
        const balance = parseFloat((total - advance).toFixed(2));

        const fmt = v => v.toFixed(2);
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = fmt(v); };

        set('advanceAmountDisplay', advance);
        set('balanceAmountDisplay', balance);
        set('advanceDueDisplay',    advance);
        set('balanceDueDisplay',    balance);
    }

    // ── COD charge display helpers ────────────────────────────────────────
    function refreshCodChargeDisplay() {
        if (!COD_CHARGE_ENABLED) return;
        const isCodSelected = (document.querySelector('input[name="payment_method"]:checked')?.value === 'cod');
        if (!isCodSelected) return;

        // Recalculate fee from subtotal (before shipping/discounts — matches server logic)
        const subtotal = parseFloat(calculateSubtotal()) || 0;
        const fee = (COD_CHARGE_TYPE === 'percent')
            ? Math.round(subtotal * COD_CHARGE_VALUE / 100 * 100) / 100
            : COD_CHARGE_VALUE;

        // Order total without the fee (what user pays on delivery)
        const totalWithoutFee = parseFloat(window.currentFinalTotal) - fee;

        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = parseFloat(v).toFixed(2); };
        set('codChargeAmountDisplay',     fee);
        set('codChargeBalanceDisplay',    Math.max(0, totalWithoutFee));
        set('codChargeDueDisplay',        fee);
        set('codChargeBalanceDueDisplay', Math.max(0, totalWithoutFee));
    }

    function onPaymentMethodChange(method) {
        // Track wallet radio state
        window.walletSelected = (method === 'wallet');

        // Always hide all conditional banners first; helpers re-show them as needed
        const codBanner      = document.getElementById('codAdvanceBanner');
        const codChargeBanner= document.getElementById('codChargeBanner');
        const advRow         = document.getElementById('advanceBreakdownRow');
        const chargeBreakRow = document.getElementById('codChargeBreakdownRow');
        const wBanner        = document.getElementById('walletBanner');
        if (codBanner)       codBanner.classList.add('hidden');
        if (codChargeBanner) codChargeBanner.classList.add('hidden');
        if (advRow)          advRow.classList.add('hidden');
        if (chargeBreakRow)  chargeBreakRow.classList.add('hidden');
        if (wBanner && !window.walletSelected) wBanner.classList.add('hidden');

        const btn = document.getElementById('payBtn');

        if (method === 'wallet') {
            btn.innerText = 'Place Order via Wallet';
        } else if (method === 'cod') {
            if (COD_ADVANCE_ENABLED) {
                if (codBanner) codBanner.classList.remove('hidden');
                if (advRow)    advRow.classList.remove('hidden');
                btn.innerText = `Pay ${COD_ADVANCE_PERCENT}% Advance & Place Order`;
            } else if (COD_CHARGE_ENABLED) {
                if (codChargeBanner) codChargeBanner.classList.remove('hidden');
                if (chargeBreakRow)  chargeBreakRow.classList.remove('hidden');
                btn.innerText = 'Pay COD Fee & Place Order';
            } else {
                btn.innerText = 'Place Order (COD)';
            }
        } else {
            btn.innerText = 'Continue to Payment';
        }

        updateOrderSummary(window.currentShippingCharge, window.currentCourier, window.currentETA, window.currentCourierId);
        if (method !== 'wallet') refreshAdvanceDisplay();
    }

    // ── Address selection + shipping fetch ────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        const radios = Array.from(document.querySelectorAll('input[name="selected_address"]'));
        if (radios.length === 0) {
            updateOrderSummary(0, 'Calculating…', 'Calculating…', 0);
            return;
        }

        radios.forEach(radio => {
            radio.addEventListener('change', async function () {
                const pincode = this.getAttribute('data-pincode');
                updateOrderSummary(0, 'Calculating…', 'Calculating…', 0);
                if (!pincode) return;

                document.getElementById('shippingCharge').innerText = "Calculating...";
                try {
                    const totalWeight = calculateCartWeight(); // always in KG
                    console.log('[Weight Debug] Cart weight:', totalWeight.toFixed(3), 'kg', '| Items:', getCartItems().map(i => ({
                        name: i.name, raw_weight_value: i.weight_value ?? i.variant_weight ?? i.weight,
                        raw_unit: i.weight_unit ?? i.variant_unit ?? '(none)', qty: i.quantity ?? i.qty
                    })));
                    const isCod = (document.querySelector('input[name="payment_method"]:checked')?.value === 'cod') ? 1 : 0;
                    const res = await fetch('getDeliveryCharge.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `pincode=${encodeURIComponent(pincode)}&weight=${encodeURIComponent(totalWeight)}&weight_unit=kg&subtotal=${encodeURIComponent(calculateSubtotal())}&cod=${isCod}`,
                    });
                    const data = JSON.parse(await res.text());
                    let shipping = 0;
                    if (data.success === true) {
                        shipping = parseFloat(data.rate) || 0;
                        document.getElementById('courierName').innerText = data.courier_name;
                        document.getElementById('courierETA').innerText  =
                            data.estimated_delivery_days ? `${data.estimated_delivery_days} Days` : 'N/A';
                    } else {
                        const errMsg = data.error ?? '';
                        console.warn("Delivery API error:", data);
                        if (errMsg.toLowerCase().includes('pickup pincode') || errMsg.toLowerCase().includes('not configured')) {
                            document.getElementById('courierName').innerText = '⚠️ Store config issue';
                            document.getElementById('courierETA').innerText  = 'Contact support';
                        } else {
                            document.getElementById('courierName').innerText = 'Not serviceable';
                            document.getElementById('courierETA').innerText  = '—';
                        }
                    }
                    updateOrderSummary(
                        shipping, data.courier_name,
                        data.estimated_delivery_days ? `${data.estimated_delivery_days} Days` : 'N/A',
                        data.courier_id, data.original_rate, data.is_free
                    );
                } catch (err) {
                    console.error("Shipping fetch error:", err);
                    updateOrderSummary(0, 'Calculating…', 'Calculating…', 0);
                }
            });
        });

        let checked = document.querySelector('input[name="selected_address"]:checked');
        if (!checked) {
            radios[0].checked = true;
            radios[0].dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            checked.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Auto-uppercase coupon input
        document.getElementById('couponCodeInput')?.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });

    // ── Main checkout function ─────────────────────────────────────────────
    window.continueToPayment = async function () {
        // ── Email verification gate ───────────────────────────────
        if (!EMAIL_VERIFIED) {
            openEvPopup();
            return;
        }

        const selected = document.querySelector('input[name="selected_address"]:checked');
        if (!selected) { alert("Please select a delivery address"); return; }

        const cart = getCartItems();
        if (!cart.length) { alert("Cart is empty"); return; }

        const btn = document.getElementById("payBtn");
        btn.disabled  = true;
        btn.innerText = "Processing...";

        try {
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;

            // ── Guard: wallet selected but insufficient balance ────────────
            if (paymentMethod === 'wallet') {
                const orderTotal = parseFloat(window.currentFinalTotal) || 0;
                // currentFinalTotal already = 0 when wallet covers it; check raw afterDiscounts
                const rawTotal = (parseFloat(calculateSubtotal()) || 0)
                               - (window.currentCouponDiscount   || 0)
                               - (window.currentReferralDiscount || 0)
                               + (window.currentShippingCharge   || 0);
                if (WALLET_BALANCE < rawTotal) {
                    showToast(
                        `⚠️ Insufficient wallet balance (₹${WALLET_BALANCE.toFixed(2)}). Please choose Online Payment or COD.`,
                        { background: '#dc2626', color: '#fff' }
                    );
                    resetPaymentButton();
                    return;
                }
            }

            const payload = {
                address_id:             selected.value,
                cart:                   cart,
                subtotal:               calculateSubtotal().toFixed(2),
                shipping_charge:        window.currentShippingCharge.toFixed(2),
                packing_charge:         0,
                overall_total:          window.currentFinalTotal.toFixed(2),
                payment_method:         paymentMethod,
                coupon_code:            window.appliedCoupon?.coupon_code            ?? null,
                coupon_discount_amount: (window.currentCouponDiscount ?? 0) > 0
                                            ? (window.currentCouponDiscount).toFixed(2)
                                            : null,
                referral_discount:      (window.currentReferralDiscount ?? 0) > 0
                                            ? (window.currentReferralDiscount).toFixed(2)
                                            : null,
                wallet_amount_used:     (window.currentWalletUsed ?? 0) > 0
                                            ? (window.currentWalletUsed).toFixed(2)
                                            : null,
                cod_convenience_fee:    (window.currentCodConvFee ?? 0) > 0
                                            ? (window.currentCodConvFee).toFixed(2)
                                            : null,
                ...(window.currentCourier    ? { courier_name:       window.currentCourier    } : {}),
                ...(window.currentETA        ? { courier_eta:        window.currentETA        } : {}),
                ...(window.currentCourierId  ? { courier_company_id: window.currentCourierId  } : {}),
            };

            const res  = await fetch("create_order.php", {
                method:  "POST",
                headers: { "Content-Type": "application/json" },
                body:    JSON.stringify(payload),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message);

            // ── Pure COD ─────────────────────────────────────────────────
            if (data.payment_method === 'cod') {
                try { localStorage.removeItem('cart'); } catch(e) {}
                updateCartCount();
                showOrderSuccess(data.order_id);
                return;
            }

            // ── Full wallet payment — no Razorpay needed ──────────────────
            if (data.payment_method === 'wallet') {
                try { localStorage.removeItem('cart'); } catch(e) {}
                updateCartCount();
                showOrderSuccess(data.order_id);
                return;
            }

            // ── COD + Advance (partial Razorpay) ──────────────────────────
            if (data.payment_method === 'cod_advance') {
                if (!data.razorpay_order_id || !data.key) {
                    throw new Error('Advance payment gateway init failed');
                }

                const advOptions = {
                    key:         data.key,
                    amount:      data.amount,           // advance amount in paise
                    currency:    "INR",
                    name:        "RgreenMart",
                    description: `Advance (${data.advance_percent}%) for Order #${data.order_id}`,
                    order_id:    data.razorpay_order_id,
                    handler: function(response) {
                        try { localStorage.removeItem('cart'); } catch(e) {}
                        // Pass type=cod_advance so verify_payment.php knows this is a partial payment
                        window.location.href =
                            `verify_payment.php?order_id=${data.order_id}` +
                            `&payment_id=${response.razorpay_payment_id}` +
                            `&signature=${response.razorpay_signature}` +
                            `&type=cod_advance`;
                    },
                    modal: {
                        ondismiss: function () {
                            resetPaymentButton();
                            showToast(
                                'Advance payment cancelled. Order not confirmed.',
                                { background: '#dc2626', color: '#fff' }
                            );
                            fetch("update_payment_status.php", {
                                method:  "POST",
                                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                body:    `order_id=${data.order_id}&status=failed`,
                            });
                        },
                    },
                    prefill: {
                        name:    data.prefill.name,
                        email:   data.prefill.email,
                        contact: data.prefill.contact,
                    },
                    notes: { order_type: 'cod_advance' }
                };

                showToast(
                    `Pay ₹${data.advance_amount.toFixed(2)} advance now. Balance ₹${data.balance_amount.toFixed(2)} on delivery.`
                );
                const rzpAdv = new Razorpay(advOptions);
                rzpAdv.open();
                return;
            }

            // ── COD + Convenience Fee (fee paid online via Razorpay) ──────
            if (data.payment_method === 'cod_charge') {
                if (!data.razorpay_order_id || !data.key) {
                    throw new Error('COD fee payment gateway init failed');
                }

                const chargeOptions = {
                    key:         data.key,
                    amount:      data.amount,   // fee amount in paise
                    currency:    "INR",
                    name:        "RgreenMart",
                    description: `COD Convenience Fee for Order #${data.order_id}`,
                    order_id:    data.razorpay_order_id,
                    handler: function(response) {
                        try { localStorage.removeItem('cart'); } catch(e) {}
                        window.location.href =
                            `verify_payment.php?order_id=${data.order_id}` +
                            `&payment_id=${response.razorpay_payment_id}` +
                            `&signature=${response.razorpay_signature}` +
                            `&type=cod_charge`;
                    },
                    modal: {
                        ondismiss: function () {
                            resetPaymentButton();
                            showToast(
                                'COD fee payment cancelled. Order not confirmed.',
                                { background: '#dc2626', color: '#fff' }
                            );
                            fetch("update_payment_status.php", {
                                method:  "POST",
                                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                                body:    `order_id=${data.order_id}&status=failed`,
                            });
                        },
                    },
                    prefill: {
                        name:    data.prefill.name,
                        email:   data.prefill.email,
                        contact: data.prefill.contact,
                    },
                    notes: { order_type: 'cod_charge' }
                };

                showToast(
                    `Pay COD fee ₹${data.charge_amount.toFixed(2)} now. Order amount ₹${data.balance_amount.toFixed(2)} paid on delivery.`
                );
                const rzpCharge = new Razorpay(chargeOptions);
                rzpCharge.open();
                return;
            }

            // ── Full online payment ──────────────────────────────────────
            if (!data.razorpay_order_id || !data.key) {
                throw new Error('Payment gateway initialization failed');
            }
            const options = {
                key:         data.key,
                amount:      data.amount,
                currency:    "INR",
                name:        "RgreenMart",
                description: "Order #" + data.order_id,
                order_id:    data.razorpay_order_id,
                handler: async function(response) {
                    window.location.href =
                        `verify_payment.php?order_id=${data.order_id}` +
                        `&payment_id=${response.razorpay_payment_id}` +
                        `&signature=${response.razorpay_signature}`;
                },
                modal: {
                    ondismiss: function () {
                        resetPaymentButton();
                        fetch("update_payment_status.php", {
                            method:  "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body:    `order_id=${data.order_id}&status=failed`,
                        });
                    },
                },
                prefill: {
                    name:    data.prefill.name,
                    email:   data.prefill.email,
                    contact: data.prefill.mobile,
                },
            };
            const rzp = new Razorpay(options);
            rzp.open();

        } catch (error) {
            console.error("Payment initiation error:", error);
            showToast("Error: " + error.message, { background: "#e63946", color: "#fff" });
            resetPaymentButton();
        }
    };

    function resetPaymentButton() {
        const btn = document.getElementById("payBtn");
        btn.disabled  = false;
        btn.innerText = "Continue to Payment";
    }

    /* ── Email Verification Popup JS ─────────────────────── */
    let _evOtpSent = false;

    function openEvPopup() {
        _evOtpSent = false;
        document.getElementById('evOtpWrap').style.display    = 'none';
        document.getElementById('evOtpInput').value           = '';
        document.getElementById('evMsg').textContent          = '';
        document.getElementById('evActionBtn').textContent    = 'Send OTP';
        document.getElementById('evActionBtn').disabled       = false;
        document.getElementById('evPopupSubtitle').textContent = "We'll send a 6-digit OTP to your registered email.";
        const overlay = document.getElementById('emailVerifyOverlay');
        overlay.style.display = 'flex';
    }

    function closeEvPopup() {
        document.getElementById('emailVerifyOverlay').style.display = 'none';
    }

    // Close on backdrop click
    document.getElementById('emailVerifyOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeEvPopup();
    });

    async function handleEvBtn() {
        const btn   = document.getElementById('evActionBtn');
        const msgEl = document.getElementById('evMsg');

        if (!_evOtpSent) {
            // Step 1 — send OTP
            btn.disabled = true;
            btn.textContent = 'Sending…';
            const fd = new FormData();
            fd.append('action', 'send_verify_otp');
            try {
                const res  = await fetch('/update_profile.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch(pe) {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = 'Server error. Please try again.';
                    console.error('Non-JSON response:', text);
                    btn.disabled = false; btn.textContent = 'Send OTP'; return;
                }
                if (data.success) {
                    _evOtpSent = true;
                    document.getElementById('evOtpWrap').style.display = 'block';
                    document.getElementById('evPopupSubtitle').textContent = data.message;
                    btn.textContent     = 'Verify OTP';
                    msgEl.style.color   = '#059669';
                    msgEl.textContent   = 'OTP sent! Check your inbox.';
                } else {
                    msgEl.style.color   = '#dc2626';
                    msgEl.textContent   = data.message || 'Failed to send OTP.';
                    btn.textContent     = 'Send OTP';
                }
            } catch(e) {
                msgEl.style.color = '#dc2626';
                msgEl.textContent = 'Connection error: ' + e.message;
                btn.textContent   = 'Send OTP';
            }
            btn.disabled = false;

        } else {
            // Step 2 — verify OTP
            const otp = document.getElementById('evOtpInput').value.trim();
            if (!otp) {
                msgEl.style.color = '#dc2626';
                msgEl.textContent = 'Enter the OTP first.';
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Verifying…';
            const fd = new FormData();
            fd.append('action', 'confirm_verify_otp');
            fd.append('otp', otp);
            try {
                const res  = await fetch('/update_profile.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch(pe) {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = 'Server error. Please try again.';
                    console.error('Non-JSON response:', text);
                    btn.disabled = false; btn.textContent = 'Verify OTP'; return;
                }
                if (data.success) {
                    msgEl.style.color   = '#059669';
                    msgEl.textContent   = '✅ Email verified! Continuing…';
                    btn.textContent     = '✅ Verified';
                    setTimeout(() => location.reload(), 1200);
                } else {
                    msgEl.style.color   = '#dc2626';
                    msgEl.textContent   = data.message || 'Invalid OTP.';
                    btn.disabled        = false;
                    btn.textContent     = 'Verify OTP';
                }
            } catch(e) {
                msgEl.style.color = '#dc2626';
                msgEl.textContent = 'Connection error: ' + e.message;
                btn.disabled      = false;
                btn.textContent   = 'Verify OTP';
            }
        }
    }
    </script>

    <!-- ── EMAIL VERIFICATION POPUP ────────────────────────────────────────── -->
    <div id="emailVerifyOverlay" style="
        display:none;position:fixed;inset:0;z-index:9998;
        background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);
        align-items:center;justify-content:center;">
        <div style="
            background:#fff;border-radius:20px;padding:32px 28px;
            max-width:400px;width:92%;text-align:center;
            box-shadow:0 24px 60px rgba(0,0,0,0.2);
            animation:popIn 0.3s cubic-bezier(.4,0,.2,1);
        ">
            <!-- Icon -->
            <div style="width:70px;height:70px;border-radius:50%;margin:0 auto 16px;
                background:linear-gradient(135deg,#f59e0b,#d97706);
                display:flex;align-items:center;justify-content:center;">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none"
                     stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
            </div>
            <h2 style="font-size:20px;font-weight:800;color:#1f2937;margin:0 0 8px;">Verify Your Email</h2>
            <p style="font-size:13px;color:#6b7280;margin:0 0 4px;">
                You need to verify your email before placing an order.
            </p>
            <p id="evPopupSubtitle" style="font-size:13px;color:#6b7280;margin:0 0 20px;">
                We'll send a 6-digit OTP to your registered email.
            </p>

            <!-- OTP input (hidden until OTP sent) -->
            <div id="evOtpWrap" style="display:none;margin-bottom:14px;text-align:left;">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Enter OTP</label>
                <input type="text" id="evOtpInput" maxlength="6" placeholder="6-digit OTP"
                    style="width:100%;border:1.5px solid #d1d5db;border-radius:10px;padding:11px;
                           font-size:18px;letter-spacing:8px;text-align:center;outline:none;box-sizing:border-box;">
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Check your inbox and spam folder.</div>
            </div>
            <div id="evMsg" style="font-size:13px;min-height:18px;margin-bottom:12px;font-weight:600;"></div>

            <div style="display:flex;gap:10px;">
                <button onclick="closeEvPopup()"
                    style="flex:1;padding:11px;background:#f3f4f6;color:#374151;border:none;
                           border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;">
                    Cancel
                </button>
                <button id="evActionBtn" onclick="handleEvBtn()"
                    style="flex:2;padding:11px;
                           background:linear-gradient(135deg,#e91e63,#6a1b9a);
                           color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer;">
                    Send OTP
                </button>
            </div>
        </div>
    </div>

    <!-- ── ORDER SUCCESS OVERLAY ───────────────────────────────────────── -->
    <div id="orderSuccessOverlay" style="
        display:none;position:fixed;inset:0;z-index:9999;
        background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);
        align-items:center;justify-content:center;
    ">
        <div style="
            background:#fff;border-radius:20px;padding:40px 36px;
            max-width:440px;width:90%;text-align:center;
            box-shadow:0 24px 60px rgba(0,0,0,0.2);
            animation:popIn 0.35s cubic-bezier(.4,0,.2,1);
        ">
            <!-- Checkmark circle -->
            <div style="
                width:80px;height:80px;border-radius:50%;margin:0 auto 20px;
                background:linear-gradient(135deg,#e91e63,#6a1b9a);
                display:flex;align-items:center;justify-content:center;
            ">
                <svg width="38" height="38" viewBox="0 0 24 24" fill="none"
                     stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>

            <h2 style="font-size:22px;font-weight:800;color:#1f2937;margin:0 0 8px;">Order Confirmed! 🎉</h2>
            <p style="font-size:14px;color:#6b7280;margin:0 0 6px;">
                Your order has been placed successfully.
            </p>
            <p style="font-size:13px;color:#9ca3af;margin:0 0 28px;">
                Order ID: <strong id="successOrderId" style="color:#6a1b9a;"></strong>
            </p>

            <!-- Invoice download button -->
            <a id="invoiceDownloadBtn" href="#" target="_blank" style="
                display:inline-flex;align-items:center;gap:8px;
                padding:13px 28px;border-radius:10px;
                background:linear-gradient(135deg,#e91e63,#6a1b9a);
                color:#fff;font-size:15px;font-weight:700;
                text-decoration:none;margin-bottom:12px;
                transition:opacity 0.2s;cursor:pointer;
                box-shadow:0 4px 14px rgba(233,30,99,0.35);
            " onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="12" y1="18" x2="12" y2="12"/>
                    <line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
                Download Invoice
            </a>

            <br>
            <a href="my_orders.php" style="
                display:inline-block;margin-top:4px;
                font-size:13px;color:#6a1b9a;font-weight:600;
                text-decoration:underline;
            ">View My Orders</a>
        </div>
    </div>

    <style>
    @keyframes popIn {
        from { transform:scale(0.85);opacity:0; }
        to   { transform:scale(1);opacity:1; }
    }
    </style>

    <script>
    function showOrderSuccess(orderId) {
        document.getElementById('successOrderId').textContent = '#' + orderId;

        const overlay  = document.getElementById('orderSuccessOverlay');
        const dlBtn    = document.getElementById('invoiceDownloadBtn');

        // Show overlay immediately
        overlay.style.display = 'flex';

        // Show a loading state on the download button while PDF generates in background
        dlBtn.style.pointerEvents = 'none';
        dlBtn.style.opacity       = '0.6';
        dlBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                 style="animation:overlayBtnSpin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            Preparing Invoice\u2026`;

        // Silently trigger PDF generation in the background RIGHT AWAY
        // so the file exists on disk whether or not the user clicks "Download Invoice"
        fetch('pdf_generation.php?order_id=' + orderId + '&cart_cleared=true')
            .then(function() {
                var pdfUrl = 'pdf_generation.php?order_id=' + orderId + '&cart_cleared=true';
                dlBtn.href             = pdfUrl;
                dlBtn.style.pointerEvents = 'auto';
                dlBtn.style.opacity       = '1';
                dlBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="12" y1="18" x2="12" y2="12"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg> Download Invoice`;
            })
            .catch(function() {
                // Generation failed — still enable button so user can try manually
                dlBtn.href             = 'pdf_generation.php?order_id=' + orderId;
                dlBtn.style.pointerEvents = 'auto';
                dlBtn.style.opacity       = '1';
                dlBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="12" y1="18" x2="12" y2="12"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg> Download Invoice`;
            });
    }
    </script>

    <style>
    @keyframes overlayBtnSpin { to { transform: rotate(360deg); } }
    </style>

    <?php include "includes/footer.php"; ?>
</body>
</html>