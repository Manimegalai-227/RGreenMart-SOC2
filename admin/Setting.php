<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php"); exit();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ──────────────────────────────────────────────────────────────
//  COMPANY PROFILE
// ──────────────────────────────────────────────────────────────
$companyMessage = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['company_profile_submit'])) {
    $companyName    = trim($_POST['company_name']);
    $companyAddress = trim($_POST['company_address']);
    $companyMobile  = trim($_POST['company_mobile']);
    $companyEmail   = trim($_POST['company_email']);
    $existing = $conn->query("SELECT id FROM company_profile LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $conn->prepare("UPDATE company_profile SET company_name=?,address=?,mobile=?,email=? WHERE id=?")
             ->execute([$companyName,$companyAddress,$companyMobile,$companyEmail,$existing['id']]);
    } else {
        $conn->prepare("INSERT INTO company_profile (company_name,address,mobile,email) VALUES (?,?,?,?)")
             ->execute([$companyName,$companyAddress,$companyMobile,$companyEmail]);
    }
    $companyMessage = "Company profile saved successfully!";
}
$cp = $conn->query("SELECT * FROM company_profile LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cpName    = $cp['company_name'] ?? '';
$cpAddress = $cp['address']      ?? '';
$cpMobile  = $cp['mobile']       ?? '';
$cpEmail   = $cp['email']        ?? '';

// ──────────────────────────────────────────────────────────────
//  IMAGE SETTINGS
// ──────────────────────────────────────────────────────────────
// Migrate enum to include 'popup' and 'carousel' if not already present
try {
    $conn->exec("ALTER TABLE image_settings MODIFY COLUMN type ENUM('thumbnail','product','popup','carousel') NOT NULL");
} catch (PDOException $e) { /* already updated or unsupported — safe to ignore */ }

$imageMessage = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['image_settings_submit'])) {
    $type = $_POST['type']; $width = intval($_POST['width']); $height = intval($_POST['height']);
    $stmt = $conn->prepare("SELECT id FROM image_settings WHERE type=?"); $stmt->execute([$type]);
    if ($stmt->rowCount() > 0) {
        $conn->prepare("UPDATE image_settings SET width=?,height=? WHERE type=?")->execute([$width,$height,$type]);
    } else {
        $conn->prepare("INSERT INTO image_settings (type,width,height) VALUES (?,?,?)")->execute([$type,$width,$height]);
    }
    $imageMessage = "Image settings updated!";
}

// ──────────────────────────────────────────────────────────────
//  SCROLLING MESSAGES
// ──────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_scroll_message'])) {
        $msg = trim($_POST['scroll_message']);
        if (!empty($msg)) $conn->prepare("INSERT INTO scrolling_messages (message) VALUES (?)")->execute([$msg]);
    }
    if (isset($_POST['delete_scroll_id'])) {
        $conn->prepare("DELETE FROM scrolling_messages WHERE id=?")->execute([intval($_POST['delete_scroll_id'])]);
    }
}

// ──────────────────────────────────────────────────────────────
//  PROMO SETTINGS (Coupon + Referral toggle & referral %)
// ──────────────────────────────────────────────────────────────
$promoMessage = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['promo_settings_submit'])) {
    $couponEnabled          = isset($_POST['coupon_enabled'])          ? 1 : 0;
    $referralEnabled        = isset($_POST['referral_enabled'])        ? 1 : 0;
    $walletPurchaseEnabled  = isset($_POST['wallet_purchase_enabled']) ? 1 : 0;
    $referralPercent        = max(0, min(100, floatval($_POST['referral_percent'] ?? 5)));
    $referralType           = in_array($_POST['referral_type'] ?? 'percent', ['percent','fixed']) ? $_POST['referral_type'] : 'percent';
    $referralAmount         = max(0, floatval($_POST['referral_amount'] ?? 0));
    $conn->prepare("UPDATE promo_settings SET coupon_enabled=?,referral_enabled=?,referral_percent=?,referral_type=?,referral_amount=?,wallet_purchase_enabled=? WHERE id=1")
         ->execute([$couponEnabled, $referralEnabled, $referralPercent, $referralType, $referralAmount, $walletPurchaseEnabled]);
    $promoMessage = "Promo settings saved!";
}

// ──────────────────────────────────────────────────────────────
//  COUPON CRUD
// ──────────────────────────────────────────────────────────────
$couponMessage = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_coupon'])) {
    $code  = strtoupper(trim($_POST['coupon_code']));
    $dtype = $_POST['discount_type'];
    $dval  = floatval($_POST['discount_value']);
    $exp   = trim($_POST['expiry_date']);
    if ($code && $dval > 0 && $exp) {
        try {
            $conn->prepare("INSERT INTO coupons (code,discount_type,discount_value,expiry_date,is_active) VALUES (?,?,?,?,1)")
                 ->execute([$code,$dtype,$dval,$exp]);
            $couponMessage = "Coupon <strong>$code</strong> created!";
        } catch (PDOException $e) {
            $couponMessage = "Error: Coupon code already exists.";
        }
    } else {
        $couponMessage = "Please fill all coupon fields correctly.";
    }
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_coupon_id'])) {
    $cid = intval($_POST['toggle_coupon_id']);
    $conn->prepare("UPDATE coupons SET is_active = 1 - is_active WHERE id=?")->execute([$cid]);
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_coupon_id'])) {
    $conn->prepare("DELETE FROM coupons WHERE id=?")->execute([intval($_POST['delete_coupon_id'])]);
}

// ──────────────────────────────────────────────────────────────
//  FETCH DATA
// ──────────────────────────────────────────────────────────────
$imageSettings = $conn->query("SELECT * FROM image_settings")->fetchAll(PDO::FETCH_ASSOC);
$settings      = $conn->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$gstRate       = $settings['gst_rate']              ?? '';
$discount      = $settings['discount']              ?? '';
$pickupPin     = $settings['pickuplocation_pincode'] ?? '';
$notifText     = $settings['notification_text']     ?? '';
$minimumOrder  = $settings['minimum_order']         ?? '';

$promo = $conn->query("SELECT * FROM promo_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$promo) { // insert default if missing
    $conn->exec("INSERT INTO promo_settings (id,coupon_enabled,referral_enabled,referral_percent,referral_type,referral_amount,wallet_purchase_enabled) VALUES (1,0,0,5.00,'percent',0.00,0)");
    $promo = ['coupon_enabled'=>0,'referral_enabled'=>0,'referral_percent'=>5.00,'referral_type'=>'percent','referral_amount'=>0.00,'wallet_purchase_enabled'=>0];
}

$coupons = $conn->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Poppins', sans-serif; } .admin-main { margin-left: 3rem; }</style>
</head>
<body class="bg-gray-100">
<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="container mx-auto max-w-5xl p-6 bg-white rounded-lg shadow-lg mt-10">

            <!-- ── TABS ── -->
            <div class="flex flex-wrap gap-1 mb-6 border-b overflow-x-auto">
                <?php
                $tabs = [
                    'tabCompany' => 'Company Profile',
                    'tabUpdate'  => 'General Settings',
                    'tabImage'   => 'Image Settings',
                    'tabScroll'  => 'Scrolling Bar',
                    'tabCoupon'  => 'Coupon Code',
                ];
                foreach ($tabs as $id => $label) {
                    $active = ($id === 'tabCompany') ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-gray-500';
                    echo "<button id=\"$id\" class=\"tab-btn px-5 py-2 font-semibold whitespace-nowrap $active\">$label</button>";
                }
                ?>
            </div>

            <!-- ── COMPANY PROFILE ── -->
            <div id="companySection">
                <h2 class="text-2xl font-bold text-indigo-600 mb-4">Company Profile</h2>
                <p class="text-sm text-gray-500 mb-4">These details appear on every customer invoice.</p>
                <?php if ($companyMessage): ?>
                    <div class="mb-4 p-3 bg-green-100 text-green-700 rounded"><?= htmlspecialchars($companyMessage) ?></div>
                <?php endif; ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="company_profile_submit" value="1">
                    <div><label class="block text-sm font-medium text-gray-700">Company Name</label>
                        <input type="text" name="company_name" value="<?= htmlspecialchars($cpName) ?>" required placeholder="e.g. RGreenMart"
                               class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Address</label>
                        <textarea name="company_address" rows="3" required placeholder="Full address"
                                  class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($cpAddress) ?></textarea></div>
                    <div><label class="block text-sm font-medium text-gray-700">Mobile</label>
                        <input type="text" name="company_mobile" value="<?= htmlspecialchars($cpMobile) ?>" required
                               class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="company_email" value="<?= htmlspecialchars($cpEmail) ?>" required
                               class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>
                    <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700">Save Company Profile</button>
                </form>
            </div>

            <!-- ── GENERAL SETTINGS ── -->
            <div id="updateSection" class="hidden">
                <h2 class="text-2xl font-bold text-indigo-600 mb-4">General Settings</h2>
                <div id="messageBox" class="hidden mb-4 p-3 rounded"></div>
                <form id="settingsForm" class="space-y-4">
                    <div><label class="block text-sm font-medium text-gray-700">GST Rate (%)</label>
                        <input type="number" name="gst_rate" value="<?= htmlspecialchars($gstRate) ?>" step="0.01" required
                               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Discount (%)</label>
                        <input type="number" name="discount" value="<?= htmlspecialchars($discount) ?>" step="0.01" required
                               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Pickup Location Pincode</label>
                        <input type="text" name="pickuplocation_pincode" value="<?= htmlspecialchars($pickupPin) ?>" required
                               pattern="[1-9][0-9]{5}" maxlength="6"
                               title="Enter a valid 6-digit Indian pincode (cannot start with 0)"
                               placeholder="e.g. 625005"
                               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <p class="text-xs text-gray-400 mt-1">⚠️ Must be a valid 6-digit pincode. This is used to calculate shipping charges for customers.</p>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700">Minimum Order Amount</label>
                        <input type="number" name="minimum_order" value="<?= htmlspecialchars($minimumOrder) ?>" step="0.01" min="0"
                               class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Notification Text</label>
                        <textarea name="notification_text" rows="4" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($notifText) ?></textarea></div>
                    <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700">Update Settings</button>
                </form>
            </div>

            <!-- ── IMAGE SETTINGS ── -->
            <div id="imageSection" class="hidden">
                <h2 class="text-2xl font-bold text-indigo-600 mb-4">Image Size Settings</h2>
                <?php if($imageMessage): ?><div class="mb-4 p-3 bg-green-100 text-green-700 rounded"><?= htmlspecialchars($imageMessage) ?></div><?php endif; ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="image_settings_submit" value="1">
                    <div><label class="block text-sm font-medium text-gray-700">Image Type</label>
                        <select name="type" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                            <option value="thumbnail">Thumbnail</option>
                            <option value="product">Product Image</option>
                            <option value="popup">Popup Image</option>
                            <option value="carousel">Carousel Image</option>
                        </select></div>
                    <div><label class="block text-sm font-medium text-gray-700">Width (px)</label>
                        <input type="number" name="width" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"></div>
                    <div><label class="block text-sm font-medium text-gray-700">Height (px)</label>
                        <input type="number" name="height" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500"></div>
                    <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700">Save Image Settings</button>
                </form>
                <h3 class="text-lg font-semibold text-gray-700 mt-8 mb-4">Current Settings</h3>
                <div class="overflow-x-auto">
                    <table class="w-full border border-gray-200 rounded">
                        <thead><tr class="bg-indigo-600 text-white"><th class="p-3 text-left">Type</th><th class="p-3 text-center">Width</th><th class="p-3 text-center">Height</th></tr></thead>
                        <tbody>
                        <?php
                        $typeLabels = [
                            'thumbnail' => 'Thumbnail',
                            'product'   => 'Product Image',
                            'popup'     => 'Popup Image',
                            'carousel'  => 'Carousel Image',
                        ];
                        if(empty($imageSettings)): ?>
                            <tr><td colspan="3" class="p-4 text-center text-gray-500">No settings saved yet.</td></tr>
                        <?php else: foreach($imageSettings as $row):
                            $label = $typeLabels[$row['type']] ?? ucfirst($row['type']); ?>
                            <tr class="border-t hover:bg-gray-50">
                                <td class="p-3"><?= htmlspecialchars($label) ?></td>
                                <td class="p-3 text-center"><?= htmlspecialchars($row['width']) ?>px</td>
                                <td class="p-3 text-center"><?= htmlspecialchars($row['height']) ?>px</td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── SCROLLING BAR ── -->
            <div id="scrollSection" class="hidden">
                <h2 class="text-2xl font-bold text-indigo-600 mb-4">Scrolling Bar Settings</h2>
                <form method="POST" class="space-y-4 mb-6">
                    <input type="hidden" name="add_scroll_message" value="1">
                    <div><label class="block text-sm font-medium text-gray-700">Add New Scrolling Text</label>
                        <textarea name="scroll_message" required rows="3"
                            class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="Enter scrolling text..."></textarea></div>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Add Message</button>
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full border border-gray-200 rounded">
                        <thead><tr class="bg-indigo-600 text-white"><th class="p-3 text-left">Message</th><th class="p-3 text-center">Action</th></tr></thead>
                        <tbody>
                        <?php
                        $scrollData = $conn->query("SELECT * FROM scrolling_messages ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                        if(empty($scrollData)) {
                            echo '<tr><td colspan="2" class="p-4 text-center text-gray-500">No messages added.</td></tr>';
                        } else {
                            foreach($scrollData as $row) {
                                echo '<tr class="border-t"><td class="p-3">'.htmlspecialchars($row["message"]).'</td>';
                                echo '<td class="p-3 text-center"><form method="POST" style="display:inline;">
                                    <input type="hidden" name="delete_scroll_id" value="'.$row["id"].'">
                                    <button class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">Delete</button>
                                    </form></td></tr>';
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>

                <!-- COUPON CODE TAB  -->
            <div id="couponSection" class="hidden space-y-8">
                <h2 class="text-2xl font-bold text-indigo-600">Coupon Code & Referral Settings</h2>

                <!-- Flash messages -->
                <?php if ($promoMessage): ?>
                    <div class="p-3 bg-green-100 text-green-700 rounded"><?= htmlspecialchars($promoMessage) ?></div>
                <?php endif; ?>
                <?php if ($couponMessage): ?>
                    <div class="p-3 bg-blue-100 text-blue-700 rounded"><?= $couponMessage ?></div>
                <?php endif; ?>

                <!-- ── Toggle Panel ── -->
                <div class="bg-gray-50 border rounded-xl p-5">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Feature Toggles</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="promo_settings_submit" value="1">

                        <div class="flex items-center justify-between p-4 bg-white rounded-lg border">
                            <div>
                                <p class="font-semibold text-gray-800">Coupon Codes</p>
                                <p class="text-sm text-gray-500">Allow customers to apply coupon codes at checkout</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="coupon_enabled" value="1" class="sr-only peer"
                                    <?= $promo['coupon_enabled'] ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:bg-indigo-600 after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                            </label>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-white rounded-lg border">
                            <div>
                                <p class="font-semibold text-gray-800">Referral Program</p>
                                <p class="text-sm text-gray-500">Reward users who refer new customers</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="referral_enabled" value="1" class="sr-only peer"
                                    <?= $promo['referral_enabled'] ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:bg-green-500 after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                            </label>
                        </div>

                        <!-- ── Referral Reward Type ── -->
                        <div class="p-4 bg-white rounded-lg border">
                            <label class="block text-sm font-semibold text-gray-700 mb-3">
                                Referral Reward Type
                            </label>
                            <div class="flex gap-6 mb-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="referral_type" value="percent"
                                           class="w-4 h-4 accent-indigo-600"
                                           <?= ($promo['referral_type'] ?? 'percent') === 'percent' ? 'checked' : '' ?>
                                           onchange="toggleReferralFields()">
                                    <span class="text-gray-700 font-medium">Percentage (%)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="referral_type" value="fixed"
                                           class="w-4 h-4 accent-indigo-600"
                                           <?= ($promo['referral_type'] ?? '') === 'fixed' ? 'checked' : '' ?>
                                           onchange="toggleReferralFields()">
                                    <span class="text-gray-700 font-medium">Fixed Amount (₹)</span>
                                </label>
                            </div>

                            <!-- Percent field -->
                            <div id="refPercentField" class="flex items-center gap-3">
                                <input type="number" name="referral_percent" step="0.1" min="0" max="100"
                                       value="<?= htmlspecialchars($promo['referral_percent']) ?>"
                                       class="w-40 p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                                <span class="text-gray-600">% discount on the referred person's first order</span>
                            </div>

                            <!-- Fixed amount field -->
                            <div id="refFixedField" class="flex items-center gap-3 hidden">
                                <span class="text-gray-600 font-medium">₹</span>
                                <input type="number" name="referral_amount" step="0.01" min="0"
                                       value="<?= htmlspecialchars($promo['referral_amount'] ?? '0') ?>"
                                       class="w-40 p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                                <span class="text-gray-600">fixed discount on the referred person's first order</span>
                            </div>

                            <p class="text-xs text-gray-400 mt-2">
                                <strong>Referee</strong> (new referred user) — gets this as a <strong>discount on their first order only</strong>.<br>
                                <strong>Referrer</strong> (the user who shared the link) — gets the <strong>same amount credited to their wallet</strong> after the referred user's first order is successfully paid.<br>
                                Changes here apply only to <strong>new referrals</strong>. Existing referred users keep their original discount.
                            </p>
                        </div>

                        <!-- ── Wallet Purchase Toggle ── -->
                        <div class="flex items-center justify-between p-4 bg-white rounded-lg border">
                            <div>
                                <p class="font-semibold text-gray-800">Wallet Purchase</p>
                                <p class="text-sm text-gray-500">Allow customers to pay using their referral wallet balance</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="wallet_purchase_enabled" value="1" class="sr-only peer"
                                    <?= ($promo['wallet_purchase_enabled'] ?? 0) ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:bg-purple-600 after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                            </label>
                        </div>

                        <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700 font-semibold">
                            Save Toggle Settings
                        </button>
                    </form>
                </div>

                <!-- ── Create Coupon ── -->
                <div class="bg-gray-50 border rounded-xl p-5">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Create New Coupon</h3>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="hidden" name="add_coupon" value="1">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Coupon Code</label>
                            <input type="text" name="coupon_code" required placeholder="e.g. RGREEN100"
                                   style="text-transform:uppercase"
                                   class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500 uppercase">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Discount Type</label>
                            <select name="discount_type" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                                <option value="percent">Percentage (%)</option>
                                <option value="flat">Flat Amount (₹)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Discount Value</label>
                            <input type="number" name="discount_value" step="0.01" min="0.01" required placeholder="e.g. 10 or 100"
                                   class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date & Time</label>
                            <input type="datetime-local" name="expiry_date" required
                                   class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="w-full bg-indigo-600 text-white p-3 rounded-lg hover:bg-indigo-700 font-semibold">
                                Create Coupon
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ── Coupon List ── -->
                <div class="bg-gray-50 border rounded-xl p-5">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4"> All Coupons</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse bg-white rounded-lg shadow-sm text-sm">
                            <thead>
                                <tr class="bg-indigo-500 text-white">
                                    <th class="p-3 text-left">Code</th>
                                    <th class="p-3 text-left">Type</th>
                                    <th class="p-3 text-left">Value</th>
                                    <th class="p-3 text-left">Expiry</th>
                                    <th class="p-3 text-center">Status</th>
                                    <!-- <th class="p-3 text-center">Used</th> -->
                                    <th class="p-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($coupons)): ?>
                                <tr><td colspan="7" class="p-4 text-center text-gray-500">No coupons created yet.</td></tr>
                            <?php else: foreach ($coupons as $c):
                                $expired = strtotime($c['expiry_date']) < time();
                                $statusClass = $c['is_active'] && !$expired ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                                $statusText  = $expired ? 'Expired' : ($c['is_active'] ? 'Active' : 'Disabled');
                            ?>
                                <tr class="border-t hover:bg-gray-50">
                                    <td class="p-3 font-mono font-bold"><?= htmlspecialchars($c['code']) ?></td>
                                    <td class="p-3 capitalize"><?= $c['discount_type'] === 'percent' ? 'Percentage' : 'Flat ₹' ?></td>
                                    <td class="p-3 font-semibold">
                                        <?= $c['discount_type'] === 'percent' ? htmlspecialchars($c['discount_value']).'%' : '₹'.htmlspecialchars($c['discount_value']) ?>
                                    </td>
                                    <td class="p-3 <?= $expired ? 'text-red-600 font-semibold' : '' ?>">
                                        <?= date('d M Y h:i A', strtotime($c['expiry_date'])) ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $statusClass ?>"><?= $statusText ?></span>
                                    </td>
                                    <!-- <td class="p-3 text-center"><?= (int)$c['usage_count'] ?></td> -->
                                    <td class="p-3 text-center flex gap-2 justify-center">
                                        <?php if (!$expired): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="toggle_coupon_id" value="<?= $c['id'] ?>">
                                            <button class="text-xs px-3 py-1 rounded <?= $c['is_active'] ? 'bg-yellow-500 text-white hover:bg-yellow-600' : 'bg-green-600 text-white hover:bg-green-700' ?>">
                                                <?= $c['is_active'] ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete coupon <?= htmlspecialchars($c['code']) ?>?')">
                                            <input type="hidden" name="delete_coupon_id" value="<?= $c['id'] ?>">
                                            <button class="text-xs px-3 py-1 rounded bg-red-600 text-white hover:bg-red-700">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- end couponSection -->

        </div><!-- end card -->
    </main>
</div>

<script>
// ── TAB SYSTEM ──────────────────────────────────────────────────────────────
const tabMap = {
    tabCompany : 'companySection',
    tabUpdate  : 'updateSection',
    tabImage   : 'imageSection',
    tabScroll  : 'scrollSection',
    tabCoupon  : 'couponSection',
};
const allTabBtns     = Object.keys(tabMap).map(id => document.getElementById(id));
const allSectionIds  = Object.values(tabMap);

function activateTab(tabId) {
    allTabBtns.forEach(btn => {
        btn.classList.remove('text-indigo-600','border-b-2','border-indigo-600');
        btn.classList.add('text-gray-500');
    });
    allSectionIds.forEach(sid => document.getElementById(sid).classList.add('hidden'));

    const btn = document.getElementById(tabId);
    const sec = document.getElementById(tabMap[tabId]);
    btn.classList.add('text-indigo-600','border-b-2','border-indigo-600');
    btn.classList.remove('text-gray-500');
    sec.classList.remove('hidden');
}

Object.keys(tabMap).forEach(id => {
    document.getElementById(id)?.addEventListener('click', () => activateTab(id));
});

// ── AJAX GENERAL SETTINGS ────────────────────────────────────────────────────
document.getElementById("settingsForm")?.addEventListener("submit", async function(e) {
    e.preventDefault();
    const res    = await fetch("/api/admin/settings_update.php", { method:"POST", body: new FormData(this) });
    const result = await res.json();
    const box    = document.getElementById("messageBox");
    box.classList.remove("hidden");
    box.textContent = result.message;
    box.style.backgroundColor = result.status === "success" ? "#d1fae5" : "#fee2e2";
    box.style.color            = result.status === "success" ? "#065f46" : "#991b1b";
    setTimeout(() => { box.classList.add("hidden"); }, 6000);
});

// Auto-uppercase coupon code input
document.querySelector('input[name="coupon_code"]')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Referral type toggle
function toggleReferralFields() {
    const isFixed = document.querySelector('input[name="referral_type"][value="fixed"]')?.checked;
    document.getElementById('refPercentField')?.classList.toggle('hidden', !!isFixed);
    document.getElementById('refFixedField')?.classList.toggle('hidden', !isFixed);
}
// Run on page load
toggleReferralFields();
</script>
</body>
</html>