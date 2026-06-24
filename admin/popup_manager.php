<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php"); exit();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ──────────────────────────────────────────────────────────────
//  FETCH POPUP DIMENSIONS FROM image_settings
// ──────────────────────────────────────────────────────────────
$dimStmt = $conn->prepare("SELECT width, height FROM image_settings WHERE type = 'popup' LIMIT 1");
$dimStmt->execute();
$dimRow      = $dimStmt->fetch(PDO::FETCH_ASSOC);
$popupWidth  = $dimRow ? (int)$dimRow['width']  : null;
$popupHeight = $dimRow ? (int)$dimRow['height'] : null;

$settingStmt = $conn->prepare("SELECT popup_enabled, popup_interval_hours FROM settings LIMIT 1");
$settingStmt->execute();
$settingRow          = $settingStmt->fetch(PDO::FETCH_ASSOC);
$popupEnabled        = isset($settingRow['popup_enabled'])        ? (int)$settingRow['popup_enabled']        : 0;
$popupIntervalHours  = isset($settingRow['popup_interval_hours']) ? (int)$settingRow['popup_interval_hours'] : 6;

$successMsg = '';
$errorMsg   = '';

// ──────────────────────────────────────────────────────────────
//  TOGGLE GLOBAL POPUP ON / OFF  (settings.popup_enabled)
// ──────────────────────────────────────────────────────────────
if (isset($_POST['toggle_popup'])) {
    $newVal = isset($_POST['popup_enabled']) ? 1 : 0;
    $conn->prepare("UPDATE settings SET popup_enabled = ?")->execute([$newVal]);
    header("Location: popup_manager.php"); exit;
}

// ──────────────────────────────────────────────────────────────
//  SAVE POPUP REAPPEARANCE INTERVAL
// ──────────────────────────────────────────────────────────────
if (isset($_POST['save_interval'])) {
    $hrs = max(0, (int)($_POST['popup_interval_hours'] ?? 6));
    $conn->prepare("UPDATE settings SET popup_interval_hours = ?")->execute([$hrs]);
    $popupIntervalHours = $hrs;
    $successMsg = 'Reappearance interval saved successfully.';
}

// ──────────────────────────────────────────────────────────────
//  TOGGLE INDIVIDUAL POPUP IMAGE STATUS (enable / disable)
// ──────────────────────────────────────────────────────────────
if (isset($_POST['toggle_image_id'])) {
    $tid = intval($_POST['toggle_image_id']);
    // Flip status: 1→0 or 0→1, only for popup rows
    $conn->prepare("UPDATE site_images SET status = 1 - status WHERE id = ? AND type = 'popup'")
         ->execute([$tid]);
    header("Location: popup_manager.php"); exit;
}

// ──────────────────────────────────────────────────────────────
//  DELETE POPUP IMAGE
// ──────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Only delete rows that are actually popup type
    $stmt = $conn->prepare("SELECT image_path FROM site_images WHERE id = ? AND type = 'popup'");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        $file = $_SERVER["DOCUMENT_ROOT"] . "/" . ltrim($data['image_path'], '/');
        if (file_exists($file)) unlink($file);
        $conn->prepare("DELETE FROM site_images WHERE id = ? AND type = 'popup'")->execute([$id]);
    }
    header("Location: popup_manager.php"); exit;
}

// ──────────────────────────────────────────────────────────────
//  UPLOAD POPUP IMAGE
// ──────────────────────────────────────────────────────────────
if (isset($_POST['upload_popup'])) {
    if (isset($_FILES['popup_image']) && $_FILES['popup_image']['error'] === 0) {
        $tmpPath = $_FILES['popup_image']['tmp_name'];
        $imgInfo = @getimagesize($tmpPath);

        if ($imgInfo === false) {
            $errorMsg = "Invalid file. Please upload a valid image (JPG, PNG, WEBP).";
        } elseif ($popupWidth === null || $popupHeight === null) {
            $errorMsg = "No popup dimensions configured yet. Please set the <strong>Popup Image</strong> dimensions in <a href='Setting.php' class='underline font-semibold'>Image Settings</a> first.";
        } else {
            $uploadedW = (int)$imgInfo[0];
            $uploadedH = (int)$imgInfo[1];

            if ($uploadedW !== $popupWidth || $uploadedH !== $popupHeight) {
                $errorMsg = "Invalid image dimensions. Please upload an image with the required size: "
                    . "<strong>{$popupWidth} x {$popupHeight} pixels</strong>, as configured in Image Settings.";
            } else {
                $uploadDir = $_SERVER["DOCUMENT_ROOT"] . "/images/popup/";
                $dbPath    = "images/popup/";
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                $ext     = strtolower(pathinfo($_FILES['popup_image']['name'], PATHINFO_EXTENSION));
                $newName = uniqid("popup_", true) . "." . $ext;

                if (move_uploaded_file($tmpPath, $uploadDir . $newName)) {
                    // type='popup', status=1 (enabled by default), sort_order=0 (unused for popups)
                    $conn->prepare(
                        "INSERT INTO site_images (image_path, type, status, sort_order) VALUES (?, 'popup', 1, 0)"
                    )->execute([$dbPath . $newName]);
                    $successMsg = "Popup image uploaded successfully.";
                } else {
                    $errorMsg = "Upload failed. Please check server folder permissions.";
                }
            }
        }
    } else {
        $errorMsg = "No file selected or an upload error occurred. Please try again.";
    }
}

// ──────────────────────────────────────────────────────────────
//  FETCH ALL POPUP IMAGES  (type = 'popup', newest first)
// ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM site_images WHERE type = 'popup' ORDER BY id DESC");
$stmt->execute();
$popupImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Popup Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Poppins', sans-serif; }
    .admin-main { margin-left: 3rem; }
    .active-settings-tab {
        background: linear-gradient(135deg, #4f46e5, #6d28d9);
        color: #fff !important;
        box-shadow: 0 2px 8px rgba(79,70,229,0.35);
    }
    .settings-page-tab { color: #6b7280; }
    /* Toggle switch */
    .toggle-wrap { position: relative; display: inline-block; width: 52px; height: 28px; }
    .toggle-wrap input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute; cursor: pointer; inset: 0;
        background: #d1d5db; border-radius: 28px; transition: .3s;
    }
    .toggle-slider::before {
        content: ""; position: absolute;
        width: 20px; height: 20px; left: 4px; bottom: 4px;
        background: #fff; border-radius: 50%; transition: .3s;
    }
    input:checked + .toggle-slider { background: linear-gradient(135deg, #4f46e5, #6d28d9); }
    input:checked + .toggle-slider::before { transform: translateX(24px); }
    /* Card hover */
    .popup-card { transition: box-shadow .2s, transform .2s; }
    .popup-card:hover { box-shadow: 0 8px 24px rgba(79,70,229,.15); transform: translateY(-2px); }
    @media (max-width: 768px) { .admin-main { margin-left: 0 !important; } }
</style>
</head>
<body class="bg-gray-100">
<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="admin-main flex-1 p-6">
        <div class="max-w-5xl mx-auto space-y-6 mt-4">

            <!-- ══════════════════════════════════════════
                 CARD 1 — Global Popup Toggle
            ══════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-indigo-700">Popup Ad Manager</h1>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Control popup advertisements shown to visitors on the homepage.
                        </p>
                    </div>

                    <!-- Global ON/OFF toggle -->
                    <form method="POST" class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-gray-600">
                            Popups:
                            <span class="<?= $popupEnabled ? 'text-green-600' : 'text-red-500' ?> font-bold">
                                <?= $popupEnabled ? 'ON' : 'OFF' ?>
                            </span>
                        </span>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="popup_enabled" <?= $popupEnabled ? 'checked' : '' ?>
                                   onchange="this.form.submit()">
                            <span class="toggle-slider"></span>
                        </label>
                        <input type="hidden" name="toggle_popup" value="1">
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════
                 CARD 2 — Reappearance Interval
            ══════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-indigo-600 mb-1">Popup Reappearance Interval</h2>
                <?php if ($successMsg): ?>
                <div class="mb-4 flex items-center gap-2 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span class="text-sm font-medium"><?= htmlspecialchars($successMsg) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="save_interval" value="1">
                    <div>
                        <label class="block text-sm font-semibold text-gray-600 mb-1.5">
                            Hours between popup appearances
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" name="popup_interval_hours"
                                   id="intervalInput"
                                   min="0" max="720" step="1"
                                   value="<?= $popupIntervalHours ?>"
                                   class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-sm font-semibold
                                          text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <span class="text-sm text-gray-400">hrs &nbsp;(0&nbsp;= always show)</span>
                        </div>
                    </div>
                    <button type="submit"
                            class="flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5
                                   rounded-lg hover:bg-indigo-700 transition font-semibold text-sm flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Interval
                    </button>
                </form>

                <!-- Live info chip -->
                <p class="mt-4 text-xs text-gray-400">
                    <svg class="inline w-3.5 h-3.5 mr-1 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
                    </svg>
                    Currently set to
                    <strong class="text-indigo-600"><?= $popupIntervalHours ?> hour<?= $popupIntervalHours !== 1 ? 's' : '' ?></strong>.
                    The timestamp is stored in the visitor's <code class="bg-gray-100 px-1 rounded">localStorage</code>
                    and compared on every visit.
                </p>
            </div>

            <!-- ══════════════════════════════════════════
                 CARD 3 — Upload New Popup Image
            ══════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-indigo-600 mb-4">Upload New Popup Image</h2>

                <!-- Dimension info banner -->
                <?php if ($popupWidth && $popupHeight): ?>
                <div class="flex items-start gap-3 mb-5 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
                    </svg>
                    <p class="text-sm text-blue-700">
                        <strong>Required size:</strong>
                        <span class="font-mono bg-blue-100 px-1.5 py-0.5 rounded text-blue-800"><?= $popupWidth ?> &times; <?= $popupHeight ?> px</span>.
                        Please upload images that match this dimension exactly.
                        To change required dimensions, go to
                        <a href="Setting.php" class="underline font-semibold hover:text-blue-900">Image Settings</a>.
                    </p>
                </div>
                <?php else: ?>
                <div class="flex items-start gap-3 mb-5 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
                    </svg>
                    <p class="text-sm text-amber-700">
                        <strong>No popup image dimensions configured yet.</strong>
                        Please set <strong>Popup Image</strong> dimensions in
                        <a href="Setting.php" class="underline font-semibold hover:text-amber-900">Image Settings</a>
                        before uploading.
                    </p>
                </div>
                <?php endif; ?>

                <!-- Flash messages -->
                <?php if ($successMsg): ?>
                <div class="mb-4 flex items-center gap-2 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span class="text-sm font-medium"><?= htmlspecialchars($successMsg) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($errorMsg): ?>
                <div class="mb-4 flex items-start gap-2 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
                    </svg>
                    <span class="text-sm font-medium"><?= $errorMsg ?></span>
                </div>
                <?php endif; ?>

                <!-- Upload form -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="upload_popup" value="1">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                        <div class="flex-1 w-full">
                            <input type="file" name="popup_image" accept="image/jpeg,image/png,image/webp" required
                                   id="popupFileInput"
                                   class="block w-full text-sm text-gray-500 border border-gray-300 rounded-lg cursor-pointer
                                          bg-gray-50 focus:outline-none
                                          file:mr-4 file:py-2.5 file:px-4 file:rounded-l-lg file:border-0
                                          file:text-sm file:font-semibold file:bg-indigo-600 file:text-white
                                          hover:file:bg-indigo-700 transition">
                            <p class="mt-1 text-xs text-gray-400">Accepted: JPG, PNG, WEBP
                                <?= ($popupWidth && $popupHeight) ? " &nbsp;|&nbsp; Required: {$popupWidth}&times;{$popupHeight}px" : '' ?>
                            </p>
                        </div>
                        <button type="submit"
                                class="flex-shrink-0 flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5
                                       rounded-lg hover:bg-indigo-700 transition font-semibold text-sm">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            Upload
                        </button>
                    </div>
                </form>

                <!-- Live preview -->
                <div id="previewBox" class="hidden mt-5 p-3 bg-gray-50 border border-dashed border-gray-300 rounded-lg">
                    <p class="text-xs text-gray-500 mb-2 font-semibold uppercase tracking-wide">Preview</p>
                    <img id="previewImg" src="#" alt="Preview"
                         class="max-h-48 rounded-lg object-contain border border-gray-200 shadow-sm">
                </div>
            </div>

            <!-- ══════════════════════════════════════════
                 CARD 4 — Manage Existing Popup Images
            ══════════════════════════════════════════ -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="mb-4 flex items-start justify-between flex-wrap gap-2">
                    <div>
                        <h2 class="text-xl font-bold text-indigo-600">Uploaded Popup Images</h2>
                        <p class="text-sm text-gray-500 mt-0.5">
                            <?= count($popupImages) ?> image<?= count($popupImages) !== 1 ? 's' : '' ?> uploaded.
                            Use the toggle on each card to enable or disable it individually.
                            <?= count($popupImages) > 1 ? 'Enabled images are displayed in a <strong>slider</strong> — use the left/right arrows to navigate. Closing dismisses the entire popup.' : '' ?>
                        </p>
                    </div>
                </div>

                <?php if (empty($popupImages)): ?>
                <div class="flex flex-col items-center justify-center py-14 text-gray-400">
                    <svg class="w-14 h-14 mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="font-semibold text-base">No popup images yet</p>
                    <p class="text-sm mt-1">Upload an image above to get started.</p>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach ($popupImages as $i => $img):
                        $isEnabled = (int)$img['status'] === 1;
                    ?>
                    <div class="popup-card bg-gray-50 border <?= $isEnabled ? 'border-gray-200' : 'border-red-200 opacity-60' ?> rounded-xl overflow-hidden">
                        <div class="relative">
                            <img src="../<?= htmlspecialchars($img['image_path']) ?>"
                                 alt="Popup image"
                                 class="w-full h-44 object-cover <?= $isEnabled ? '' : 'grayscale' ?>">
                            <!-- Position badge -->
                            <span class="absolute top-2 left-2 text-xs font-bold px-2 py-0.5 rounded-full
                                         bg-gray-900 bg-opacity-60 text-white">
                                #<?= $i + 1 ?>
                            </span>
                            <!-- Status badge -->
                            <span class="absolute top-2 right-2 text-xs font-bold px-2 py-0.5 rounded-full
                                         <?= $isEnabled ? 'bg-green-500 text-white' : 'bg-red-500 text-white' ?>">
                                <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                            </span>
                        </div>
                        <div class="p-3 space-y-2">
                            <p class="text-xs text-gray-400 truncate" title="<?= htmlspecialchars($img['image_path']) ?>">
                                <?= htmlspecialchars(basename($img['image_path'])) ?>
                            </p>
                            <div class="flex items-center justify-between gap-2">
                                <!-- Per-image enable/disable toggle -->
                                <form method="POST" class="flex items-center gap-2">
                                    <input type="hidden" name="toggle_image_id" value="<?= (int)$img['id'] ?>">
                                    <button type="submit"
                                            class="text-xs px-3 py-1 rounded font-semibold transition
                                                   <?= $isEnabled
                                                       ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200'
                                                       : 'bg-green-100 text-green-700 hover:bg-green-200' ?>">
                                        <?= $isEnabled ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <!-- Delete -->
                                <button type="button"
                                        onclick="openDeleteModal(<?= (int)$img['id'] ?>, '<?= htmlspecialchars(basename($img['image_path']), ENT_QUOTES) ?>')"
                                        class="flex items-center gap-1 text-xs text-red-500 hover:text-red-700 font-semibold transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- ── Delete Confirmation Modal ── -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black bg-opacity-40 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <!-- Dialog -->
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 animate-fade-in">
        <div class="flex flex-col items-center text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gray-800 mb-1">Delete Popup Image?</h3>
            <p class="text-sm text-gray-500 mb-1">You are about to delete:</p>
            <p id="deleteModalFilename" class="text-xs font-mono bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg mb-4 max-w-full truncate"></p>
            <p class="text-xs text-red-500 mb-6">This action cannot be undone.</p>
            <div class="flex gap-3 w-full">
                <button onclick="closeDeleteModal()"
                        class="flex-1 px-4 py-2.5 rounded-lg border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition">
                    Cancel
                </button>
                <a id="deleteModalConfirmBtn" href="#"
                   class="flex-1 px-4 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold text-center hover:bg-red-700 transition">
                    Yes, Delete
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Live image preview before upload
document.getElementById('popupFileInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewBox').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
});

// Delete modal
function openDeleteModal(id, filename) {
    document.getElementById('deleteModalFilename').textContent = filename;
    document.getElementById('deleteModalConfirmBtn').href = '?delete=' + id;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});
</script>
</body>
</html>