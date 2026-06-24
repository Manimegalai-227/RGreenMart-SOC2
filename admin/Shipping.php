<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
session_start();

// Admin Authentication
if(!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true){
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/api/shiprocket.php";
$couriers = [];

try {
    $shiprocket   = shiprocketClient();
    // getCouriers() needs a delivery pincode; use the pickup pincode as a
    // dummy self-check so the broker dropdown can list available couriers.
    $pickupPin    = trim($_ENV['SHIPROCKET_PICKUP_PINCODE'] ?? '625005');
    $response     = $shiprocket->getCouriers($pickupPin, 1, 0);
    if(isset($response['data']['available_courier_companies'])){
        $couriers = $response['data']['available_courier_companies'];
    }
} catch(Exception $e) {
    error_log($e->getMessage());
}

// Fetch settings (single row system)
$stmt = $conn->prepare("SELECT * FROM shipping_settings WHERE id = 1");
$stmt->execute();
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// If no row exists, create one
if(!$settings){
    $conn->exec("INSERT INTO shipping_settings (id) VALUES (1)");
    $stmt = $conn->prepare("SELECT * FROM shipping_settings WHERE id = 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Handle Form Submission ───────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shipping'])){

    $shipping_mode         = $_POST['shipping_mode'] ?? 'default';
    $broker_enabled        = ($shipping_mode === 'broker') ? 1 : 0;
    $shipping_mode_enabled = ($shipping_mode !== 'default') ? 1 : 0;

    $fixed_charge          = $_POST['fixed_charge']          ?? 0;
    $conditional_min_order = $_POST['conditional_min_order'] ?? 0;
    $broker_name           = $_POST['broker_name']           ?? null;
    $broker_charge         = $_POST['broker_charge']         ?? 0;
    $calculation_type      = $_POST['calculation_type']      ?? 'flat';
    $weight_rate           = $_POST['weight_rate']           ?? 0;
    $weight_slab           = max(0.001, (float)($_POST['weight_slab'] ?? 0.5));

    // COD + advance
    $cod_enabled         = isset($_POST['cod_enabled']) ? 1 : 0;
    $cod_advance_mode    = $_POST['cod_advance_mode']   ?? 'pure'; // 'pure', 'advance', or 'charge'
    $cod_advance_enabled = ($cod_enabled && $cod_advance_mode === 'advance') ? 1 : 0;
    $cod_advance_percent = $cod_advance_enabled
                            ? max(1, min(99, (float)($_POST['cod_advance_percent'] ?? 20)))
                            : 0;

    // COD Convenience Fee — enabled when mode is 'charge'
    $cod_charge_enabled = ($cod_enabled && $cod_advance_mode === 'charge') ? 1 : 0;
    $cod_charge_type    = in_array($_POST['cod_charge_type'] ?? '', ['flat','percent']) ? $_POST['cod_charge_type'] : 'flat';
    $cod_charge_value   = $cod_charge_enabled ? max(0, (float)($_POST['cod_charge_value'] ?? 0)) : 0;

    if($shipping_mode === 'default'){
        $calculation_type = 'flat'; $fixed_charge = 0; $weight_rate = 0; $weight_slab = 0.5;
        $conditional_min_order = 0; $broker_name = null; $broker_charge = 0;
    }

    $update = $conn->prepare("
        UPDATE shipping_settings SET
            shipping_mode_enabled  = :shipping_mode_enabled,
            shipping_mode          = :shipping_mode,
            shipping_calculation   = :calculation_type,
            fixed_charge           = :fixed_charge,
            weight_rate            = :weight_rate,
            weight_slab            = :weight_slab,
            conditional_min_order  = :conditional_min_order,
            broker_enabled         = :broker_enabled,
            broker_name            = :broker_name,
            broker_charge          = :broker_charge,
            cod_enabled            = :cod_enabled,
            cod_advance_enabled    = :cod_advance_enabled,
            cod_advance_percent    = :cod_advance_percent,
            cod_charge_enabled     = :cod_charge_enabled,
            cod_charge_type        = :cod_charge_type,
            cod_charge_value       = :cod_charge_value
        WHERE id = 1
    ");

    $update->execute([
        ':shipping_mode_enabled'  => $shipping_mode_enabled,
        ':shipping_mode'          => $shipping_mode,
        ':calculation_type'       => $calculation_type,
        ':fixed_charge'           => $fixed_charge,
        ':weight_rate'            => $weight_rate,
        ':weight_slab'            => $weight_slab,
        ':conditional_min_order'  => $conditional_min_order,
        ':broker_enabled'         => $broker_enabled,
        ':broker_name'            => $broker_name,
        ':broker_charge'          => $broker_charge,
        ':cod_enabled'            => $cod_enabled,
        ':cod_advance_enabled'    => $cod_advance_enabled,
        ':cod_advance_percent'    => $cod_advance_percent,
        ':cod_charge_enabled'     => $cod_charge_enabled,
        ':cod_charge_type'        => $cod_charge_type,
        ':cod_charge_value'       => $cod_charge_value,
    ]);

    header("Location: Shipping.php?success=1");
    exit();
}

// Determine current mode for the UI
$currentMode = $settings['shipping_mode'] ?? 'default';
if((int)($settings['broker_enabled'] ?? 0) === 1 && $currentMode !== 'broker'){
    $currentMode = 'broker';
}

$curCodEnabled  = (int)($settings['cod_enabled']         ?? 1);
$curAdvEnabled  = (int)($settings['cod_advance_enabled'] ?? 0);
$curAdvPercent  = (float)($settings['cod_advance_percent'] ?? 20);
// COD Convenience Fee
$curCodChargeEnabled = (int)($settings['cod_charge_enabled'] ?? 0);
$curCodChargeType    = $settings['cod_charge_type'] ?? 'flat';
$curCodChargeValue   = (float)($settings['cod_charge_value'] ?? 0);

// Determine current COD mode: pure / advance / charge
if ($curAdvEnabled) {
    $curAdvMode = 'advance';
} elseif ($curCodChargeEnabled) {
    $curAdvMode = 'charge';
} else {
    $curAdvMode = 'pure';
}
$exOnline = round(1000 * max(1, $curAdvPercent) / 100);
$exCod    = 1000 - $exOnline;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shipping Management</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body { font-family: 'Poppins', sans-serif; }
.admin-main { margin-left: 3rem; }
.cod-card {
    border: 2px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.15s;
}
.cod-card:hover { border-color: #a5b4fc; background: #f9fafb; }
.cod-card.selected-pure    { border-color: #6366f1; background: #eef2ff; }
.cod-card.selected-advance { border-color: #f97316; background: #fff7ed; }
.cod-card.selected-charge  { border-color: #d97706; background: #fffbeb; }
</style>
</head>
<body class="bg-gray-100">

<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="container mx-auto max-w-4xl p-6 bg-white rounded-lg shadow-lg mt-10">
            <h2 class="text-2xl font-bold text-indigo-600 mb-6">Shipping Management</h2>

            <?php if(isset($_GET['success'])): ?>
                <div class="mb-4 p-3 bg-green-100 text-green-700 rounded font-medium">
                    ✅ Settings updated successfully!
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">

                <!-- Shipping Mode Dropdown -->
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Custom Shipping Charge</label>
                    <select name="shipping_mode" id="shipping_mode_select"
                        onchange="handleModeChange(this.value)"
                        class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="default"     <?= $currentMode==='default'     ?'selected':'' ?>>Default Shipping</option>
                        <option value="free"        <?= $currentMode==='free'        ?'selected':'' ?>>Free Shipping</option>
                        <option value="conditional" <?= $currentMode==='conditional' ?'selected':'' ?>>Conditional Free Shipping</option>
                        <option value="fixed"       <?= $currentMode==='fixed'       ?'selected':'' ?>>Flat / Weight Based Shipping</option>
                        <option value="broker"      <?= $currentMode==='broker'      ?'selected':'' ?>>Broker Handles Shipping</option>
                    </select>
                </div>

                <div id="section_default" class="hidden p-3 bg-gray-50 rounded-lg border text-sm text-gray-600">
                    Shipping charges will be calculated automatically using Shiprocket's recommended courier rates.
                </div>
                <div id="section_free" class="hidden p-3 bg-green-50 rounded-lg border border-green-200 text-sm text-green-700">
                    All orders will have free shipping. No charges applied to customers.
                </div>
                <div id="section_conditional" class="hidden p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Order Amount for Free Shipping (₹)</label>
                    <input type="number" step="0.01" name="conditional_min_order"
                           value="<?= htmlspecialchars($settings['conditional_min_order'] ?? 0) ?>"
                           class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">Orders above this amount get free shipping. Others are charged Shiprocket rates.</p>
                </div>
                <div id="section_fixed" class="hidden p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Calculation Type</label>
                    <select name="calculation_type" id="calculation_type_select"
                        onchange="toggleCalculation(this.value)"
                        class="w-full p-3 border rounded-lg mb-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="flat"   <?= ($settings['shipping_calculation']??'flat')==='flat'   ?'selected':'' ?>>Flat Price (₹)</option>
                        <option value="weight" <?= ($settings['shipping_calculation']??'flat')==='weight' ?'selected':'' ?>>Weight Based (Slab)</option>
                    </select>
                    <div id="flat_section">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fixed Charge (₹)</label>
                        <input type="number" step="0.01" name="fixed_charge"
                            value="<?= htmlspecialchars($settings['fixed_charge'] ?? 0) ?>"
                            class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div id="weight_section" class="hidden space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Base Weight Slab (kg)</label>
                                <input type="number" step="0.001" min="0.001" name="weight_slab"
                                    id="weight_slab_input"
                                    value="<?= htmlspecialchars($settings['weight_slab'] ?? 0.5) ?>"
                                    oninput="refreshWeightExample()"
                                    class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Charge Per Slab (₹)</label>
                                <input type="number" step="0.01" min="0" name="weight_rate"
                                    id="weight_rate_input"
                                    value="<?= htmlspecialchars($settings['weight_rate'] ?? 0) ?>"
                                    oninput="refreshWeightExample()"
                                    class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>
                </div>
                <div id="section_broker" class="hidden p-3 bg-purple-50 rounded-lg border border-purple-200">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Courier (Broker)</label>
                    <select name="broker_name" id="broker_dropdown" onchange="updateBrokerCharge()"
                        class="w-full p-3 border rounded-lg mb-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">Select Courier</option>
                        <?php foreach($couriers as $courier): ?>
                            <option value="<?= htmlspecialchars($courier['courier_name']) ?>"
                                    data-rate="<?= $courier['rate'] ?? 0 ?>"
                                    <?= ($settings['broker_name']==$courier['courier_name'])?'selected':'' ?>>
                                <?= htmlspecialchars($courier['courier_name']) ?> (₹<?= $courier['rate']??'0' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Broker Charge (₹)</label>
                    <input type="number" step="0.01" name="broker_charge" id="broker_charge_input"
                        value="<?= htmlspecialchars($settings['broker_charge'] ?? 0) ?>"
                        class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">This charge will be shown to customers instead of Shiprocket rates.</p>
                </div>

                <!-- ═══════════════════════════════════════════════════════
                     COD SETTINGS
                ═══════════════════════════════════════════════════════ -->
                <div class="pt-5 border-t-2 border-dashed border-gray-200">
                    <h3 class="text-base font-bold text-gray-800 mb-4">Cash on Delivery (COD) Settings</h3>

                    <!-- Master enable toggle -->
                    <div class="flex items-center gap-3 mb-5">
                        <input type="checkbox" id="cod_checkbox" name="cod_enabled"
                               <?= $curCodEnabled ? 'checked' : '' ?>
                               onchange="onCodToggle()"
                               class="w-5 h-5 accent-indigo-600 cursor-pointer">
                        <label for="cod_checkbox" class="font-semibold text-gray-700 cursor-pointer select-none">
                            Enable Cash on Delivery for customers
                        </label>
                    </div>

                    <!-- COD mode cards -->
                    <div id="cod_mode_wrap" class="<?= $curCodEnabled ? '' : 'hidden' ?> space-y-3">
                        <p class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">
                            Select COD Mode
                        </p>

                        <!-- Card A: Pure COD -->
                        <div class="cod-card <?= $curAdvMode==='pure' ? 'selected-pure' : '' ?>"
                             id="card_pure"
                             onclick="selectCodMode('pure')">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="cod_advance_mode" value="pure"
                                       id="radio_pure"
                                       <?= $curAdvMode==='pure' ? 'checked' : '' ?>
                                       class="mt-1 w-4 h-4 accent-indigo-600">
                                <div>
                                    <p class="font-semibold text-gray-800">Pure COD — Pay fully on delivery</p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Customer pays the <strong>complete order amount in cash</strong> at the
                                        time of delivery. No online advance payment required.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Card B: COD + Advance -->
                        <div class="cod-card <?= $curAdvMode==='advance' ? 'selected-advance' : '' ?>"
                             id="card_advance"
                             onclick="selectCodMode('advance')">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="cod_advance_mode" value="advance"
                                       id="radio_advance"
                                       <?= $curAdvMode==='advance' ? 'checked' : '' ?>
                                       class="mt-1 w-4 h-4 accent-orange-500">
                                <div class="flex-1">
                                    <p class="font-semibold text-gray-800">COD + Advance — Pay % online, rest on delivery</p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Customer pays a <strong>percentage of the order total online</strong>
                                        (via Razorpay) to confirm the order, and pays the
                                        <strong>remaining balance in cash</strong> on delivery.
                                    </p>

                                    <!-- Percent input (only visible when advance is selected) -->
                                    <div id="advance_pct_wrap"
                                         class="<?= $curAdvMode==='advance' ? '' : 'hidden' ?> mt-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                                            Online Advance Percentage
                                            <span class="text-red-500">*</span>
                                            <span class="text-xs font-normal text-gray-400 ml-1">(1 – 99)</span>
                                        </label>
                                        <div class="flex items-center gap-2">
                                            <input type="number"
                                                   name="cod_advance_percent"
                                                   id="cod_adv_pct"
                                                   min="1" max="99" step="1"
                                                   value="<?= htmlspecialchars($curAdvPercent ?: 20) ?>"
                                                   oninput="refreshExample()"
                                                   onclick="event.stopPropagation()"
                                                   class="w-24 p-2 border-2 border-orange-300 rounded-lg text-center
                                                          text-xl font-bold focus:outline-none focus:ring-2 focus:ring-orange-400">
                                            <span class="text-2xl font-bold text-orange-600">%</span>
                                        </div>
                                        <!-- Live example -->
                                        <div class="mt-3 text-sm text-orange-800">
                                            <strong>Example</strong> — For a ₹1,000 order:
                                            <div class="mt-1 flex flex-wrap gap-3">
                                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-semibold">
                                                    💳 Pay Online Now: ₹<span id="ex_online"><?= $exOnline ?></span>
                                                </span>
                                                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full font-semibold">
                                                    🏠 Pay on Delivery: ₹<span id="ex_cod"><?= $exCod ?></span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card C: COD + Convenience Fee -->
                        <div class="cod-card <?= $curCodChargeEnabled ? 'selected-charge' : '' ?>"
                             id="card_charge"
                             onclick="selectCodMode('charge')">
                            <div class="flex items-start gap-3">
                                <input type="radio" name="cod_advance_mode" value="charge"
                                       id="radio_charge"
                                       <?= $curCodChargeEnabled ? 'checked' : '' ?>
                                       class="mt-1 w-4 h-4 accent-amber-500">
                                <div class="flex-1">
                                    <p class="font-semibold text-gray-800">COD + Convenience Fee — Add a fee for COD orders</p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Customer pays a <strong>flat or percentage fee</strong> as a convenience
                                        charge for choosing Cash on Delivery. Works even when shipping is free.
                                    </p>

                                    <!-- Fee config (only visible when charge mode is selected) -->
                                    <div id="cod_charge_fields"
                                         class="<?= $curCodChargeEnabled ? '' : 'hidden' ?> mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                                        <p class="text-xs text-amber-700 mb-3">
                                            💡 This fee is added on top of the shipping charge for COD orders only.
                                            Even if shipping is <strong>free</strong>, this fee will still apply.
                                        </p>

                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Fee Type</label>
                                        <div class="flex items-center gap-4 mb-3">
                                            <label class="flex items-center gap-2 cursor-pointer" onclick="event.stopPropagation()">
                                                <input type="radio" name="cod_charge_type" value="flat"
                                                       id="cod_charge_flat"
                                                       <?= $curCodChargeType === 'flat' ? 'checked' : '' ?>
                                                       onchange="onCodChargeTypeChange('flat')"
                                                       class="w-4 h-4 accent-amber-600">
                                                <span class="font-medium text-gray-700">Flat Amount (₹)</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer" onclick="event.stopPropagation()">
                                                <input type="radio" name="cod_charge_type" value="percent"
                                                       id="cod_charge_percent"
                                                       <?= $curCodChargeType === 'percent' ? 'checked' : '' ?>
                                                       onchange="onCodChargeTypeChange('percent')"
                                                       class="w-4 h-4 accent-amber-600">
                                                <span class="font-medium text-gray-700">Percentage of Order (%)</span>
                                            </label>
                                        </div>

                                        <div class="flex items-center gap-3" onclick="event.stopPropagation()">
                                            <input type="number" name="cod_charge_value" id="cod_charge_value"
                                                   min="0" step="0.01"
                                                   value="<?= htmlspecialchars($curCodChargeValue) ?>"
                                                   oninput="refreshCodChargeExample()"
                                                   class="w-36 p-2 border-2 border-amber-300 rounded-lg text-center text-xl font-bold focus:outline-none focus:ring-2 focus:ring-amber-400">
                                            <span id="cod_charge_unit_label" class="text-xl font-bold text-amber-600">
                                                <?= $curCodChargeType === 'percent' ? '%' : '₹' ?>
                                            </span>
                                        </div>

                                        <!-- Live example -->
                                        <div class="mt-3 text-sm text-amber-800">
                                            <strong>Example</strong> — For a ₹1,000 COD order:
                                            <div class="mt-1">
                                                <span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full font-semibold">
                                                    🏷 Convenience Fee: ₹<span id="cod_charge_example">
                                                        <?php
                                                        if ($curCodChargeEnabled) {
                                                            echo $curCodChargeType === 'percent'
                                                                ? number_format(1000 * $curCodChargeValue / 100, 2)
                                                                : number_format($curCodChargeValue, 2);
                                                        } else { echo '0.00'; }
                                                        ?>
                                                    </span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /cod_mode_wrap -->

                <button type="submit" name="save_shipping"
                        class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700 font-semibold mt-4">
                    Save Settings
                </button>

            </form>
        </div>
    </main>
</div>

<script>
// ── Shipping sections ─────────────────────────────────────────────────────
const sections = ['default','free','conditional','fixed','broker'];
function handleModeChange(mode){
    sections.forEach(s=>{ const el=document.getElementById('section_'+s); if(el) el.classList.add('hidden'); });
    const a=document.getElementById('section_'+mode); if(a) a.classList.remove('hidden');
}
function toggleCalculation(type){
    document.getElementById('flat_section').classList.toggle('hidden', type!=='flat');
    document.getElementById('weight_section').classList.toggle('hidden', type!=='weight');
    if(type==='weight') refreshWeightExample();
}
function updateBrokerCharge(){
    const dd=document.getElementById('broker_dropdown');
    const ci=document.getElementById('broker_charge_input');
    const sel=dd.options[dd.selectedIndex];
    ci.value=sel?.getAttribute('data-rate')||0;
}

// ── COD logic ─────────────────────────────────────────────────────────────
function onCodToggle(){
    const enabled=document.getElementById('cod_checkbox').checked;
    document.getElementById('cod_mode_wrap').classList.toggle('hidden',!enabled);
}

function selectCodMode(mode){
    // Tick the radio
    document.getElementById('radio_'+mode).checked=true;
    // Style cards — remove all selected classes first
    ['pure','advance','charge'].forEach(m=>{
        const card=document.getElementById('card_'+m);
        if(card) card.classList.remove('selected-pure','selected-advance','selected-charge');
    });
    // Apply correct selected class
    const selected=document.getElementById('card_'+mode);
    if(selected){
        if(mode==='pure')    selected.classList.add('selected-pure');
        if(mode==='advance') selected.classList.add('selected-advance');
        if(mode==='charge')  selected.classList.add('selected-charge');
    }
    // Show/hide advance percent input
    document.getElementById('advance_pct_wrap').classList.toggle('hidden', mode!=='advance');
    // Show/hide convenience fee config
    document.getElementById('cod_charge_fields').classList.toggle('hidden', mode!=='charge');
}

function refreshExample(){
    const pct=Math.max(1,Math.min(99,parseFloat(document.getElementById('cod_adv_pct').value)||0));
    const online=Math.round(1000*pct/100);
    document.getElementById('ex_online').textContent=online.toLocaleString('en-IN');
    document.getElementById('ex_cod').textContent=(1000-online).toLocaleString('en-IN');
}

// ── COD Convenience Fee ───────────────────────────────────────────────────
function onCodChargeTypeChange(type){
    document.getElementById('cod_charge_unit_label').textContent = (type==='percent') ? '%' : '₹';
    refreshCodChargeExample();
}

function refreshCodChargeExample(){
    const val   = parseFloat(document.getElementById('cod_charge_value').value) || 0;
    const type  = document.querySelector('input[name="cod_charge_type"]:checked')?.value || 'flat';
    const fee   = type === 'percent' ? (1000 * val / 100) : val;
    document.getElementById('cod_charge_example').textContent = fee.toFixed(2);
}

// ── Weight slab live preview ──────────────────────────────────────────────
function refreshWeightExample(){
    const slab = parseFloat(document.getElementById('weight_slab_input')?.value) || 0.5;
    const rate = parseFloat(document.getElementById('weight_rate_input')?.value) || 0;
    // Sample weights in kg to preview
    const samples = [
        slab * 0.4,       // well under one slab
        slab,             // exactly one slab
        slab * 1.2,       // just over one slab
        slab * 2,         // exactly two slabs
        slab * 2.2,       // just over two slabs
        slab * 3,         // exactly three slabs
    ];
    // Remove duplicates and sort
    const unique = [...new Set(samples.map(w => Math.round(w * 1000)))].sort((a,b)=>a-b);
    const tbody = document.getElementById('weight_preview_body');
    tbody.innerHTML = unique.map(wg => {
        const wKg = wg / 1000;
        const slabs = Math.ceil(wKg / slab);
        const charge = (slabs * rate).toFixed(2);
        const gLabel = wg >= 1000 ? (wKg.toFixed(2) + ' kg') : (wg + ' g');
        return `<tr class="border-t border-gray-100">
            <td class="py-1">${gLabel}</td>
            <td class="py-1">${slabs}</td>
            <td class="py-1 text-indigo-700 font-semibold">₹${charge}</td>
        </tr>`;
    }).join('');
}

// Init
handleModeChange("<?= $currentMode ?>");
toggleCalculation("<?= $settings['shipping_calculation'] ?? 'flat' ?>");
selectCodMode("<?= $curAdvMode ?>");
</script>
</body>
</html>