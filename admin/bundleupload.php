<?php
// Set a higher execution time for bulk uploads
set_time_limit(600);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ═══════════════════════════════════════════════════════════════
//  EXPORT — Download all items + variants as a ready-to-upload CSV
// ═══════════════════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    // Fetch all items with category and brand names
    $itemsStmt = $conn->query("
        SELECT
            i.id,
            i.name,
            i.category_id,
            c.name        AS category_name,
            i.brand_id,
            b.name        AS brand_name,
            i.status,
            i.packaging_type,
            i.product_form,
            i.origin,
            i.grade,
            i.purity,
            i.flavor,
            i.description,
            i.nutrition,
            i.shelf_life,
            i.storage_instructions,
            i.expiry_info,
            i.tags
        FROM items i
        LEFT JOIN categories c ON c.id = i.category_id
        LEFT JOIN brands     b ON b.id = i.brand_id
        ORDER BY i.id ASC
    ");
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // For each item fetch its variants and primary image filename
    $varStmt  = $conn->prepare("
        SELECT weight_value, weight_unit, price, old_price, discount, stock, status
        FROM item_variants
        WHERE item_id = ?
        ORDER BY id ASC
    ");
    $imgStmt  = $conn->prepare("
        SELECT image_path
        FROM item_images
        WHERE item_id = ?
        ORDER BY is_primary DESC, sort_order ASC
    ");

    // Stream CSV directly to browser
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="items_export_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fputs($out, "\xEF\xBB\xBF");

    // Header row — exactly matches bundleupload.php expected columns
    fputcsv($out, [
        'name', 'category_id', 'brand_id', 'status',
        'packaging_type', 'product_form', 'origin', 'grade', 'purity', 'flavor',
        'description', 'nutrition', 'shelf_life', 'storage_instructions', 'expiry_info', 'tags',
        'images',
        'variant_weight_value', 'variant_weight_unit',
        'variant_price', 'variant_old_price', 'variant_discount',
        'variant_stock', 'variant_status'
    ]);

    // ── Helper: strip unnecessary .00 from decimal values ──────────
    $cleanNum = function($val) {
        if ($val === null || $val === '') return '';
        $f = floatval($val);
        return ($f == floor($f)) ? (string)(int)$f : rtrim(rtrim(number_format($f, 2), '0'), '.');
    };

    foreach ($allItems as $item) {
        // Fetch variants
        $varStmt->execute([$item['id']]);
        $variants = $varStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch image filenames (basename only) — same for every variant row of this product
        $imgStmt->execute([$item['id']]);
        $imgRows  = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        $imgNames = implode('|', array_map(fn($p) => basename($p), $imgRows));

        // ── One row per variant ────────────────────────────────────────
        foreach ($variants as $variant) {
            $discount = floatval($variant['discount']) == 0 ? '' : $cleanNum($variant['discount']);

            fputcsv($out, [
                $item['name'],
                $item['category_id'],
                $item['brand_id'],
                $item['status'],
                $item['packaging_type'],
                $item['product_form'],
                $item['origin'],
                $item['grade'],
                $item['purity'],
                $item['flavor'],
                $item['description'],
                $item['nutrition'],
                $item['shelf_life'],
                $item['storage_instructions'],
                $item['expiry_info'],
                $item['tags'],
                $imgNames,
                $cleanNum($variant['weight_value']),
                $variant['weight_unit'],
                $cleanNum($variant['price']),
                $cleanNum($variant['old_price'] ?? ''),
                $discount,
                $variant['stock'],
                $variant['status'],
            ]);
        }
    }

    fclose($out);
    exit;
}
// ═══════════════════════════════════════════════════════════════

$stmt = $conn->prepare("SELECT type, width, height FROM image_settings");
$stmt->execute();
$imageSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sizes = [];
foreach ($imageSettings as $row) {
    $sizes[$row['type']] = [
        'width'  => (int)$row['width'],
        'height' => (int)$row['height']
    ];
}

$thumbWidth  = $sizes['thumbnail']['width'] ?? 150;
$thumbHeight = $sizes['thumbnail']['height'] ?? 150;

$productWidth  = $sizes['product']['width'] ?? 500;
$productHeight = $sizes['product']['height'] ?? 500;

function deleteDirectory($dir) {
    if (!is_dir($dir)) return false;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) deleteDirectory($path);
        else unlink($path);
    }
    return rmdir($dir);
}

function compressImage($source, $destination, $quality = 25) {

    if (!extension_loaded('gd')) {
        return copy($source, $destination);
    }

    $info = getimagesize($source);
    if ($info === false) return false;

    $image = false;
    switch ($info['mime']) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png': $image = imagecreatefrompng($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
        default: return false;
    }
    if ($image === false) return false;

    $destDir = dirname($destination);
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $result = false;
    switch ($info['mime']) {
        case 'image/jpeg':
        case 'image/webp':
            $result = imagejpeg($image, $destination, $quality);
            break;
        case 'image/png':
            $png_quality = max(0, min(9, round(($quality / 100) * 9)));
            $result = imagepng($image, $destination, $png_quality);
            break;
    }
    imagedestroy($image);
    return $result;
}

// ----------------- Handle CSV + Images Bundle Upload -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bundle_upload'])) {

    $errors = [];
    $successCount = 0;

    // Validate CSV
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "CSV file is required!";
    }

    // Images are OPTIONAL — only needed when adding new images via the CSV images column
    // If no images uploaded, variant/price updates still work fine from CSV alone

    if (empty($errors)) {
        $csvFile = $_FILES['csv_file']['tmp_name'];

        // Build a safe $images array — empty structure when no files uploaded
        $images = [
            'name'     => $_FILES['images']['name']     ?? [],
            'tmp_name' => $_FILES['images']['tmp_name'] ?? [],
            'error'    => $_FILES['images']['error']    ?? [],
        ];
        // Filter out blank entries (browser sends one empty entry when nothing selected)
        if (count($images['name']) === 1 && empty($images['name'][0])) {
            $images = ['name' => [], 'tmp_name' => [], 'error' => []];
        }

        // Read CSV
        $rawRows = [];
        if (($handle = fopen($csvFile, 'r')) !== false) {
            $header = fgetcsv($handle, 1000, ",");
            if ($header === false) {
                $errors[] = "CSV header is empty.";
            } else {
                // Strip UTF-8 BOM from first column header (added by Excel / our own export)
                // BOM is 3 bytes: 0xEF 0xBB 0xBF — silently breaks $row['name'] lookups
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
                // Also trim whitespace from all header keys for safety
                $header = array_map('trim', $header);
            }
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (count($data) !== count($header)) {
                    $errors[] = "CSV row does not match header columns.";
                    continue;
                }
                $rawRows[] = array_combine($header, $data);
            }
            fclose($handle);
        } else {
            $errors[] = "Failed to open CSV file.";
        }

        // ── Group rows by product name ──────────────────────────────────
        // Supports BOTH formats:
        //   Format A — one row per product, all variants pipe-separated
        //   Format B — one row per variant, same product name repeated
        // Result: one merged entry per unique product name.
        $grouped = [];
        foreach ($rawRows as $raw) {
            $key = strtolower(trim($raw['name']));
            if (!isset($grouped[$key])) {
                $grouped[$key] = $raw;
            } else {
                // Append variant fields from this extra row
                $pipe = function($existing, $new) {
                    $e = trim($existing);
                    $n = trim($new);
                    if ($e === '' && $n === '') return '';
                    if ($e === '') return $n;
                    if ($n === '') return $e;
                    return $e . '|' . $n;
                };
                $grouped[$key]['variant_weight_value'] = $pipe($grouped[$key]['variant_weight_value'], $raw['variant_weight_value']);
                $grouped[$key]['variant_weight_unit']  = $pipe($grouped[$key]['variant_weight_unit'],  $raw['variant_weight_unit']);
                $grouped[$key]['variant_price']        = $pipe($grouped[$key]['variant_price'],        $raw['variant_price']);
                $grouped[$key]['variant_old_price']    = $pipe($grouped[$key]['variant_old_price'],    $raw['variant_old_price']);
                $grouped[$key]['variant_discount']     = $pipe($grouped[$key]['variant_discount'],     $raw['variant_discount']);
                $grouped[$key]['variant_stock']        = $pipe($grouped[$key]['variant_stock'],        $raw['variant_stock']);
                $grouped[$key]['variant_status']       = $pipe($grouped[$key]['variant_status'],       $raw['variant_status']);
                // Merge images if the extra row has any
                if (!empty(trim($raw['images']))) {
                    $grouped[$key]['images'] = $pipe($grouped[$key]['images'], $raw['images']);
                }
            }
        }
        $rows = array_values($grouped);

        foreach ($rows as $rowIndex => $row) {
            $conn->beginTransaction();
            try {
// Check if product already exists
$checkStmt = $conn->prepare("SELECT id FROM items WHERE name = ?");
$checkStmt->execute([trim($row['name'])]);
$existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existingItem) {

    $item_id = $existingItem['id'];

    // ── UPDATE product fields only (never delete variants or images) ──
    $updateSql = "UPDATE items SET
        category_id          = ?,
        brand_id             = ?,
        status               = ?,
        packaging_type       = ?,
        product_form         = ?,
        origin               = ?,
        grade                = ?,
        purity               = ?,
        flavor               = ?,
        description          = ?,
        nutrition            = ?,
        shelf_life           = ?,
        storage_instructions = ?,
        expiry_info          = ?,
        tags                 = ?,
        updated_at           = NOW()
        WHERE id             = ?";

    $conn->prepare($updateSql)->execute([
        intval($row['category_id']),
        intval($row['brand_id']),
        isset($row['status']) ? intval($row['status']) : 1,
        trim($row['packaging_type']),
        trim($row['product_form']),
        trim($row['origin']),
        trim($row['grade']),
        trim($row['purity']),
        trim($row['flavor']),
        trim($row['description']),
        trim($row['nutrition']),
        trim($row['shelf_life']),
        trim($row['storage_instructions']),
        trim($row['expiry_info']),
        trim($row['tags']),
        $item_id
    ]);

} else {
    // ---------- INSERT new product ----------
    $conn->prepare("INSERT INTO items
        (name, category_id, brand_id, status, packaging_type, product_form, origin,
         grade, purity, flavor, description, nutrition, shelf_life,
         storage_instructions, expiry_info, tags)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([
        trim($row['name']),
        intval($row['category_id']),
        intval($row['brand_id']),
        isset($row['status']) ? intval($row['status']) : 1,
        trim($row['packaging_type']),
        trim($row['product_form']),
        trim($row['origin']),
        trim($row['grade']),
        trim($row['purity']),
        trim($row['flavor']),
        trim($row['description']),
        trim($row['nutrition']),
        trim($row['shelf_life']),
        trim($row['storage_instructions']),
        trim($row['expiry_info']),
        trim($row['tags'])
    ]);

    $item_id = $conn->lastInsertId();
}

                // ── Images: ADD new ones only, never delete existing ──────────
$imageNames    = array_filter(array_map('trim', explode('|', $row['images'] ?? '')));
$uploadDir     = "Uploads/";
$compressedDir = $uploadDir . "compressed/";

if (!is_dir($uploadDir))     mkdir($uploadDir,     0755, true);
if (!is_dir($compressedDir)) mkdir($compressedDir, 0755, true);

// Check if this item already has a thumbnail (so we don't assign a second primary)
$thumbCheck = $conn->prepare("SELECT COUNT(*) FROM item_images WHERE item_id = ? AND is_primary = 1");
$thumbCheck->execute([$item_id]);
$thumbnailAssigned = ($thumbCheck->fetchColumn() > 0);

// Get current max sort_order so new images are appended after existing ones
$sortCheck = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM item_images WHERE item_id = ?");
$sortCheck->execute([$item_id]);
$sortOffset = (int)$sortCheck->fetchColumn() + 1;

foreach ($imageNames as $i => $imgName) {

    $foundIndex = array_search(
        strtolower($imgName),
        array_map('strtolower', array_map('trim', $images['name']))
    );
    if ($foundIndex === false) continue;

    $tmpPath = $images['tmp_name'][$foundIndex];

    $imageInfo = getimagesize($tmpPath);
    if ($imageInfo === false) throw new Exception("Invalid image file: $imgName");

    $uploadedWidth  = $imageInfo[0];
    $uploadedHeight = $imageInfo[1];

    if ($uploadedWidth == $thumbWidth && $uploadedHeight == $thumbHeight) {
        $imageType = 'thumbnail';
    } elseif ($uploadedWidth == $productWidth && $uploadedHeight == $productHeight) {
        $imageType = 'gallery';
    } else {
        throw new Exception(
            "Image dimension mismatch for $imgName. " .
            "Allowed: Thumbnail {$thumbWidth}x{$thumbHeight}px, " .
            "Product {$productWidth}x{$productHeight}px. " .
            "Uploaded: {$uploadedWidth}x{$uploadedHeight}px."
        );
    }

    $uniqueName     = basename($imgName);
    $finalPath      = $uploadDir . $uniqueName;
    $compressedPath = $compressedDir . $uniqueName;

    if (!copy($tmpPath, $finalPath))
        throw new Exception("Failed to copy image: $imgName");

    if (!compressImage($finalPath, $compressedPath, 25))
        throw new Exception("Failed to compress image: $imgName");

    // Only make primary if no primary exists yet for this item
    $isPrimary = ($imageType === 'thumbnail' && !$thumbnailAssigned) ? 1 : 0;
    if ($isPrimary) $thumbnailAssigned = true;

    $conn->prepare("INSERT INTO item_images
        (item_id, image_path, compressed_path, sort_order, image_type, is_primary)
        VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$item_id, $compressedPath, $compressedPath, $sortOffset + $i, $imageType, $isPrimary]);
}

// If still no primary image at all, make the first one primary
if (!$thumbnailAssigned) {
    $conn->prepare("UPDATE item_images SET is_primary = 1
        WHERE item_id = ? ORDER BY id ASC LIMIT 1")
    ->execute([$item_id]);
}

                // ── Variants: UPDATE if same weight+unit exists, INSERT if new ──
$variantValues    = !empty(trim($row['variant_weight_value'] ?? '')) ? explode('|', $row['variant_weight_value']) : [];
$variantUnits     = !empty(trim($row['variant_weight_unit']  ?? '')) ? explode('|', $row['variant_weight_unit'])  : [];
$variantPrices    = !empty(trim($row['variant_price']        ?? '')) ? explode('|', $row['variant_price'])        : [];
$variantOldPrices = !empty(trim($row['variant_old_price']    ?? '')) ? explode('|', $row['variant_old_price'])    : [];
$variantDiscounts = !empty(trim($row['variant_discount']     ?? '')) ? explode('|', $row['variant_discount'])     : [];
$variantStocks    = !empty(trim($row['variant_stock']        ?? '')) ? explode('|', $row['variant_stock'])        : [];
$variantStatuses  = !empty(trim($row['variant_status']       ?? '')) ? explode('|', $row['variant_status'])       : [];

$variantCount = count($variantValues);

// Helper: get value at index, fall back to first value if only one given, then fall back to default
// This handles bundle.csv where variant_status=1 (single) applies to all 4 variants
$getVal = function(array $arr, int $idx, $default) {
    if (isset($arr[$idx])) return trim($arr[$idx]);
    if (isset($arr[0]))    return trim($arr[0]);   // single value = applies to all
    return $default;
};

// Prepared statements for upsert
$findVarStmt = $conn->prepare(
    "SELECT id FROM item_variants
     WHERE item_id = ? AND weight_value = ? AND weight_unit = ?
     LIMIT 1"
);
$updateVarStmt = $conn->prepare(
    "UPDATE item_variants SET
        price     = ?,
        old_price = ?,
        discount  = ?,
        stock     = ?,
        status    = ?
     WHERE id = ?"
);
$insertVarStmt = $conn->prepare(
    "INSERT INTO item_variants
        (item_id, weight_value, weight_unit, price, old_price, discount, stock, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);

for ($v = 0; $v < $variantCount; $v++) {
    $w_val     = floatval($getVal($variantValues,    $v, 0));
    $w_unit    = $getVal($variantUnits,   $v, 'pcs');
    $price     = floatval($getVal($variantPrices,    $v, 0));
    $op        = $getVal($variantOldPrices, $v, '');
    $old_price = ($op !== '' && is_numeric($op)) ? floatval($op) : null;
    $discount  = floatval($getVal($variantDiscounts, $v, 0));
    $stock     = intval($getVal($variantStocks,      $v, 0));
    $status    = intval($getVal($variantStatuses,    $v, 1));

    // Check if this weight+unit variant already exists for this item
    $findVarStmt->execute([$item_id, $w_val, $w_unit]);
    $existingVar = $findVarStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingVar) {
            // ── UPDATE existing variant — only change values given in CSV ──
            $updateVarStmt->execute([
                $price, $old_price, $discount, $stock, $status,
                $existingVar['id']
            ]);
        } else {
        // ── INSERT brand new variant ──
                $insertVarStmt->execute([
                    $item_id, $w_val, $w_unit,
                    $price, $old_price, $discount, $stock, $status
                ]);
            }
    }
                $conn->commit();
                $successCount++;

            } catch (Exception $e) {
                $conn->rollBack();
                $errors[] = "Row " . ($rowIndex + 2) . " failed: " . $e->getMessage();
            }
        }

        // results stored for UI rendering below
    } else {
        // top-level errors (e.g. missing CSV) — already in $errors
    }
}
// Build UI result flags used in the HTML below
$uploadDone    = isset($successCount);
$uploadSuccess = $uploadDone && $successCount > 0;
$uploadErrors  = $uploadDone ? $errors : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bundle Upload</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="flex">
      <?php require_once './common/admin_sidebar.php'; ?>
    <div class="max-w-3xl m-4 mx-auto bg-white p-6 rounded-lg shadow-lg">
        <h1 class="text-2xl font-bold text-indigo-600 mb-2">Bundle Upload Items</h1>

        <!-- Export button -->
        <div class="mb-6 flex items-center justify-between">
            <p class="text-sm text-gray-500">Upload a CSV with images to add or update products in bulk.</p>
            <a href="?export=csv"
               class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4"/>
                </svg>
                Export Current Items CSV
            </a>
        </div>

        <!-- Info banner -->
        <div class="mb-5 p-3 bg-blue-50 border border-blue-200 rounded-lg text-xs text-blue-700 space-y-1">
            <p>💡 <strong>Tip:</strong> Export your current items first, edit prices/variants/details in the CSV, then re-upload. Existing images are never deleted unless you provide new ones.</p>
            <p>📋 <strong>Export format:</strong> One row per variant — e.g. Badam with 3 sizes exports as 3 rows. Easy to edit in Excel or Google Sheets.</p>
            <p>📌 <strong>Variant / field update only:</strong> Leave the <code>images</code> column blank and don't upload image files — only product fields and variant data will be updated.</p>
            <p>➕ <strong>Add images:</strong> Fill the <code>images</code> column with filenames (use <code>|</code> to separate multiple) and upload the image files — they will be appended to existing images.</p>
        </div>

        <!-- ── Required image size info card ── -->
        <div class="mb-5 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-sm font-semibold text-indigo-700">Required Image Dimensions</span>
                <a href="Setting.php?tab=tabImage" class="ml-auto text-xs text-indigo-500 underline hover:text-indigo-700">Change in Image Settings →</a>
            </div>
            <div class="flex flex-wrap gap-3 text-xs">
                <?php
                $dimLabels = ['thumbnail' => '🖼 Thumbnail', 'product' => '📦 Product'];
                foreach ($dimLabels as $key => $label):
                    $w = $sizes[$key]['width']  ?? null;
                    $h = $sizes[$key]['height'] ?? null;
                ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full font-medium
                    <?= ($w && $h) ? 'bg-white border border-indigo-200 text-indigo-800' : 'bg-amber-100 border border-amber-300 text-amber-700' ?>">
                    <?= $label ?>:
                    <?= ($w && $h) ? "<strong>{$w} &times; {$h} px</strong>" : '<em>Not set</em>' ?>
                </span>
                <?php endforeach; ?>
            </div>
            <p class="mt-2 text-xs text-indigo-500">Images that don't match either dimension will be rejected during upload.</p>
        </div>

        <!-- ── Upload result messages ── -->
        <?php if (isset($uploadDone) && $uploadDone): ?>
            <?php if ($uploadSuccess): ?>
            <div class="mb-4 flex items-start gap-3 p-4 bg-green-50 border border-green-200 rounded-lg">
                <svg class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-green-800">Upload Completed Successfully</p>
                    <p class="text-xs text-green-700 mt-0.5"><?= $successCount ?> item<?= $successCount !== 1 ? 's' : '' ?> added or updated.</p>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($uploadErrors)): ?>
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-red-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
                    </svg>
                    <p class="text-sm font-semibold text-red-700"><?= count($uploadErrors) ?> error<?= count($uploadErrors) !== 1 ? 's' : '' ?> occurred</p>
                </div>
                <ul class="space-y-1 max-h-48 overflow-y-auto">
                    <?php foreach ($uploadErrors as $err): ?>
                    <li class="flex items-start gap-2 text-xs text-red-600">
                        <span class="mt-0.5 text-red-400">•</span>
                        <span><?= htmlspecialchars($err) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="mb-4">
                <label class="block font-medium text-gray-700 mb-1">CSV File <span class="text-red-500">*</span></label>
                <input type="file" name="csv_file" accept=".csv" required class="w-full border p-2 rounded">
                <p class="text-xs text-gray-500 mt-1">CSV columns must include: name, category_id, brand_id, status, packaging_type, product_form, origin, grade, purity, flavor, description, nutrition, shelf_life, storage_instructions, expiry_info, tags, images, variant_weight_value, variant_weight_unit, variant_price, variant_old_price, variant_discount, variant_stock, variant_status</p>
            </div>
            <div class="mb-4">
                <label class="block font-medium text-gray-700 mb-1">
                    Images
                    <!-- <span class="ml-2 text-xs font-normal text-green-600 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full">Optional</span> -->
                </label>
                <input type="file" name="images[]" multiple accept="image/*" class="w-full border p-2 rounded">
                <div class="mt-1 space-y-1">
                    <p class="text-xs text-gray-500">📌 Filenames must match those in the CSV <strong>images</strong> column, separated by <code>|</code></p>
                    <p class="text-xs text-blue-600">💡 <strong>Price / variant update only?</strong> Leave the images column blank in your CSV and don't upload any images — existing product images will be kept untouched.</p>
                    <p class="text-xs text-blue-600">➕ <strong>Want to add extra images?</strong> Fill the images column in CSV and upload the new image files — they will be appended to existing images.</p>
                </div>
            </div>
            <button type="submit" name="bundle_upload" class="w-full bg-indigo-600 text-white py-3 rounded-lg font-bold hover:bg-indigo-700 transition">Upload Bundle</button>
        </form>
    </div>
    </div>
</body>
</html>