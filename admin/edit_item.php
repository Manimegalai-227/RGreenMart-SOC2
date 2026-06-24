<?php
//err response
// ini_set('display_errors', 0);        // Do NOT show errors to user
// ini_set('log_errors', 1);            // Enable logging
// ini_set('error_log', _DIR_ . '/php-error.log'); // Log file path
// error_reporting(E_ALL);              // Log ALL errors

set_time_limit(300);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Fetch image size settings
$stmt = $conn->prepare("SELECT type, width, height FROM image_settings");
$stmt->execute();
$imageSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sizes = [];
foreach ($imageSettings as $row) {
    $sizes[$row['type']] = [
        'width' => (int)$row['width'],
        'height'=> (int)$row['height']
    ];
}
$thumbWidth  = $sizes['thumbnail']['width'] ?? 280;
$thumbHeight = $sizes['thumbnail']['height'] ?? 320;
$galleryWidth  = $sizes['product']['width'] ?? 600;
$galleryHeight = $sizes['product']['height'] ?? 500;


// Get item ID from query
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($item_id <= 0) {
    die("Invalid Item ID");
}

// Fetch item data
$stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$item_id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) die("Item not found.");

// Fetch item images
$stmt = $conn->prepare("SELECT * FROM item_images WHERE item_id = ? ORDER BY sort_order ASC");
$stmt->execute([$item_id]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch item variants
$stmt = $conn->prepare("SELECT * FROM item_variants WHERE item_id = ?");
$stmt->execute([$item_id]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['edit_item']) || isset($_POST['single_upload']))) {
    $conn->beginTransaction();
    try {
        // Update main item
        // Recalculate initial rating from user_count and rating_star
        $init_user_count  = max(0, intval($_POST['init_user_count']  ?? 0));
        $init_rating_star = max(0, min(5, intval($_POST['init_rating_star'] ?? 0)));
        $init_total_points = $init_user_count * $init_rating_star;
        $bought_count = max(0, intval($_POST['bought_count'] ?? 0));
        $badge_label  = isset($_POST['badge_label']) ? trim($_POST['badge_label']) : '';

        $sql = "UPDATE items SET name=?, category_id=?, brand_id=?, status=?, packaging_type=?, product_form=?, origin=?, grade=?, purity=?, flavor=?, description=?, nutrition=?, shelf_life=?, storage_instructions=?, expiry_info=?, tags=?, total_rating_points=?, total_reviews=?, bought_count=?, badge_label=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            trim($_POST['name']),
            intval($_POST['category_id']),
            intval($_POST['brand_id']),
            intval($_POST['status']),
            trim($_POST['packaging_type']),
            trim($_POST['product_form']),
            trim($_POST['origin']),
            trim($_POST['grade']),
            trim($_POST['purity']),
            trim($_POST['flavour']),
            trim($_POST['description']),
            trim($_POST['nutrition']),
            trim($_POST['shelf_life']),
            trim($_POST['storage_instruction']),
            isset($_POST['expiry_info']) ? trim($_POST['expiry_info']) : null,
            isset($_POST['tags']) ? trim($_POST['tags']) : null,
            $init_total_points,
            $init_user_count,
            $bought_count,
            $badge_label,
            $item_id
        ]);

        // Update variants
        $conn->prepare("DELETE FROM item_variants WHERE item_id=?")->execute([$item_id]);
        $variant_weights = $_POST['variant_weight_value'] ?? [];
        $variant_units = $_POST['variant_weight_unit'] ?? [];
        $variant_prices = $_POST['variant_price'] ?? [];
        $variant_old_prices = $_POST['variant_old_price'] ?? [];
        $variant_discounts = $_POST['variant_discount'] ?? [];
        $variant_stocks = $_POST['variant_stock'] ?? [];
        $variant_statuses = $_POST['variant_status'] ?? [];

        $insertVarStmt = $conn->prepare("INSERT INTO item_variants (item_id, weight_value, weight_unit, price, old_price, discount, stock, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        for ($i = 0; $i < count($variant_weights); $i++) {
            if(empty($variant_weights[$i])) continue;
            $insertVarStmt->execute([
                $item_id,
                floatval($variant_weights[$i]),
                $variant_units[$i],
                floatval($variant_prices[$i]),
                ($variant_old_prices[$i] !== '' && $variant_old_prices[$i] !== null) ? floatval($variant_old_prices[$i]) : null,
                ($variant_discounts[$i] !== '' && $variant_discounts[$i] !== null) ? floatval($variant_discounts[$i]) : 0,
                intval($variant_stocks[$i]),
                intval($variant_statuses[$i])
            ]);
        }

        // Handle deleted images
        if (isset($_POST['removed_images'])) {
            foreach ($_POST['removed_images'] as $rid) {
                $stmt = $conn->prepare("DELETE FROM item_images WHERE id=?");
                $stmt->execute([intval($rid)]);
            }
        }

        // Handle image reordering and new uploads
        $uploadDir = "Uploads/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $compressedDir = $uploadDir . "compressed/";
        if (!is_dir($compressedDir)) {
            if (!mkdir($compressedDir, 0755, true)) {
                error_log("Failed to create compressed directory: $compressedDir");
                // Fallback: use uploads dir if compressed dir can't be created
                $compressedDir = $uploadDir;
            }
        }

        // Reset all primary flags for this item first
        $conn->prepare("UPDATE item_images SET is_primary = 0 WHERE item_id = ?")->execute([$item_id]);
        // Process images based on order
        $orderArray = isset($_POST['order']) ? $_POST['order'] : [];
        $thumbCount = 0;
        $galleryCount = 0;

        foreach ($orderArray as $visualOrder => $fileIndex) {
            $isPrimary = ($visualOrder == 0) ? 1 : 0;

            if ($fileIndex !== "existing" &&  isset($_FILES['images']['tmp_name'][$fileIndex]) &&  $_FILES['images']['error'][$fileIndex] === UPLOAD_ERR_OK)  {
                $tmpPath = $_FILES['images']['tmp_name'][$fileIndex];
                $originalName = $_FILES['images']['name'][$fileIndex];

                $sizeInfo = getimagesize($tmpPath);
                if (!$sizeInfo) throw new Exception("Unable to get image size for $originalName");

                $imgWidth = $sizeInfo[0];
                $imgHeight = $sizeInfo[1];

                // Determine image type
                if ($imgWidth == $thumbWidth && $imgHeight == $thumbHeight) {
                    $image_type = 'thumbnail';
                    $thumbCount++;
                    if ($thumbCount > 3) throw new Exception("Maximum 3 thumbnails allowed");
                } elseif ($imgWidth == $galleryWidth && $imgHeight == $galleryHeight) {
                    $image_type = 'gallery';
                } else {
                    throw new Exception("Image '$originalName' dimensions do not match thumbnail ({$thumbWidth}x{$thumbHeight}) or product ({$galleryWidth}x{$galleryHeight})");
                }

                $filename = uniqid() . "_" . preg_replace("/[^A-Za-z0-9\._-]/", "_", basename($originalName));
                $finalPath = $uploadDir . $filename;
                if (move_uploaded_file($tmpPath, $finalPath)) {
                    $compressedPath = $compressedDir . $filename;
                    compressImage($finalPath, $compressedPath, 25);

                    $stmt = $conn->prepare("INSERT INTO item_images (item_id, image_path, compressed_path, sort_order, is_primary, image_type) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$item_id, $finalPath, $compressedPath, $visualOrder, $isPrimary, $image_type]);
                }
            } elseif ($fileIndex === "existing" && isset($_POST['existing_image_ids'][$visualOrder])) {
                $existing_id = intval($_POST['existing_image_ids'][$visualOrder]);
                $stmt = $conn->prepare("UPDATE item_images SET sort_order = ?, is_primary = ? WHERE id = ?");
                $stmt->execute([$visualOrder, $isPrimary, $existing_id]);
                }
                
        }     // Update sort_order for existing images
            // Final sync: Update main item's primary image path
            $stmt = $conn->prepare("SELECT compressed_path FROM item_images WHERE item_id=? AND is_primary=1 LIMIT 1");
            $stmt->execute([$item_id]);
            $primaryImage = $stmt->fetchColumn();
            $stmt = $conn->prepare("UPDATE items SET image=? WHERE id=?");
            $stmt->execute([$primaryImage ?: '', $item_id]);

            $conn->commit();
            header("Location: edit_item.php?id=$item_id&success=1");
            exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_msg = $e->getMessage();
    }
}

function compressImage($source, $destination, $quality = 25) {

    if (!extension_loaded('gd')) {
        return copy($source, $destination);
    }

    if (!file_exists($source)) {
        throw new Exception('Source image not found');
    }

    $info = getimagesize($source);
    if ($info === false) return false;
    $image = false;
    switch ($info['mime']) {
    case 'image/jpeg':
        $image = imagecreatefromjpeg($source);
        break;
    case 'image/png':
        // For PNG, $quality is interpreted differently (0-9). 
        // We'll map 25 (JPEG scale) to a suitable PNG compression level (e.g., 7-9).
        $png_quality = max(0, min(9, round(($quality / 100) * 9)));
        $image = imagecreatefrompng($source);
        break;
    case 'image/webp':
        $image = imagecreatefromwebp($source);
        break;
    default:
        return false;
    }

    if ($image === false) {
        return false;
    }

    // Ensure destination directory exists
    $destDir = dirname($destination);
    if (!is_dir($destDir)) {
        // Use 0755 for better permissions on most web hosts
        if (!mkdir($destDir, 0755, true)) {
            imagedestroy($image);
            return false;
        }
    }

    // Save compressed image
    $result = false;
    switch ($info['mime']) {
        case 'image/jpeg':
        case 'image/webp':
            // Save as JPEG for compression consistency, even if source was WebP (common practice)
            $result = imagejpeg($image, $destination, $quality);
            break;
        case 'image/png':
            // Save PNG with calculated quality
            $result = imagepng($image, $destination, $png_quality);
            break;
    }
    
    imagedestroy($image);
    return $result;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Item - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/config.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .image-preview-container { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px; min-height: 150px; border: 2px dashed #ddd; padding: 10px; border-radius: 10px; }
        .img-box { width: 120px; position: relative; padding: 8px; border: 2px solid #ccc; border-radius: 12px; background: #fff; cursor: grab; }
        .img-box.primary { border-color: #4F46E5; box-shadow: 0 0 10px rgba(79, 70, 229, 0.3); }
        .img-box img { width: 100%; height: 90px; object-fit: cover; border-radius: 8px; }
        .delete-icon { position: absolute; top: -8px; right: -8px; width: 24px; height: 24px; border-radius: 50%; background: #ef4444; color: #fff; display: flex; justify-content: center; align-items: center; cursor: pointer; font-size: 14px; }
        .thumbnail-control { position: absolute; bottom: 5px; left: 5px; font-size: 10px; background: #4F46E5; color: #fff; padding: 2px 6px; border-radius: 10px; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex">
        <?php require_once './common/admin_sidebar.php'; ?>
        
        <main class="flex-1 p-6">
            <div class="max-w-5xl mx-auto bg-white p-8 rounded-xl shadow-md">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Edit Item</h1>
                    <a href="Manageitems.php" class="text-indigo-600 hover:underline">Back to List</a>
                </div>

                <?php if(isset($_GET['success'])): ?>
                    <div class="bg-green-100 text-green-700 p-4 rounded mb-6">Item updated successfully!</div>
                <?php endif; ?>

                <form id="itemForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="edit_item" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Product Name</label>
                                <input type="text" name="name" value="<?=htmlspecialchars($item['name'])?>" required class="w-full mt-1 p-3 border rounded-lg">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Status</label>
                                    <select name="status" class="w-full mt-1 p-3 border rounded-lg">
                                        <option value="1" <?=$item['status']==1?'selected':''?>>Active</option>
                                        <option value="0" <?=$item['status']==0?'selected':''?>>Inactive</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Packaging</label>
                                    <input type="text" name="packaging_type" value="<?=htmlspecialchars($item['packaging_type'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Category</label>
                                    <div class="flex gap-2">
                                        <select id="categorySelect" name="category_id" required class="flex-1 p-3 border rounded-lg"></select>
                                        <button type="button" onclick="openCategoryModal()" class="p-3 bg-gray-100 rounded-lg hover:bg-gray-200"><i class="fa-solid fa-plus"></i></button>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Brand</label>
                                    <div class="flex gap-2">
                                        <select id="brandSelect" name="brand_id" required class="flex-1 p-3 border rounded-lg"></select>
                                        <button type="button" onclick="openBrandModal()" class="p-3 bg-gray-100 rounded-lg hover:bg-gray-200"><i class="fa-solid fa-plus"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div id="variantsSection">
                                <div class="flex justify-between items-center mb-2">
                                    <label class="font-bold text-gray-700">Variants</label>
                                    <button type="button" onclick="addVariantRow()" class="text-sm text-indigo-600 font-semibold">+ Add Variant</button>
                                </div>
                                <div id="variantsContainer" class="space-y-3">
                                    <?php foreach($variants as $v): ?>
                                    <div class="variant-row bg-gray-50 p-3 rounded-lg border relative">
                                        <div class="grid grid-cols-3 gap-2">
                                            <div class="col-span-2">
                                                <label class="text-[10px] uppercase font-bold text-gray-500">Weight</label>
                                                <div class="flex gap-1">
                                                    <input type="number" step="0.01" name="variant_weight_value[]" value="<?=$v['weight_value']?>" class="w-2/3 p-1.5 text-sm border rounded">
                                                    <select name="variant_weight_unit[]" class="w-1/3 p-1.5 text-sm border rounded">
                                                        <?php foreach(['g','kg','ml','l','pcs'] as $u) echo "<option value='$u' ".($v['weight_unit']==$u?'selected':'').">$u</option>"; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="text-[10px] uppercase font-bold text-gray-500">Price</label>
                                                <input type="number" step="0.01" name="variant_price[]" oninput="computeVariantDiscount(this.closest('.variant-row'))" value="<?=$v['price']?>" class="w-full p-1.5 text-sm border rounded">
                                            </div>
                                            <div>
                                                <label class="text-[10px] uppercase font-bold text-gray-500">MRP Price</label>
                                                <input type="number" step="0.01" name="variant_old_price[]" oninput="computeVariantDiscount(this.closest('.variant-row'))" value="<?=$v['old_price']?>" class="w-full p-1.5 text-sm border rounded">
                                            </div>
                                            <div>
                                                <label class="text-[10px] uppercase font-bold text-gray-500">Stock</label>
                                                <input type="number" name="variant_stock[]" value="<?=$v['stock']?>" class="w-full p-1.5 text-sm border rounded">
                                            </div>
                                            <div>
                                                <label class="text-[10px] uppercase font-bold text-gray-500">Discount%</label>
                                                <input type="text" name="variant_discount[]" value="<?= round($v['discount']) ?>%" readonly class="w-full p-1.5 text-sm border rounded bg-gray-100">
                                            </div>
                                            <input type="hidden" name="variant_status[]" value="1">
                                        </div>
                                        <button type="button" onclick="this.parentElement.remove()" class="absolute -top-2 -right-2 bg-red-500 text-white w-5 h-5 rounded-full text-xs">×</button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Description</label>
                                <textarea name="description" rows="3" class="w-full mt-1 p-3 border rounded-lg"><?=htmlspecialchars($item['description'])?></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Origin</label>
                                    <input type="text" name="origin" value="<?=htmlspecialchars($item['origin'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Flavor</label>
                                    <input type="text" name="flavour" value="<?=htmlspecialchars($item['flavor'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Shelf Life</label>
                                    <input type="text" name="shelf_life" value="<?=htmlspecialchars($item['shelf_life'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Purity</label>
                                    <input type="text" name="purity" value="<?=htmlspecialchars($item['purity'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                            </div>
                             <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700"> Nutrition</label>
                                    <input type="text" name="nutrition" value="<?=htmlspecialchars($item['nutrition'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Product form</label>
                                    <input type="text" name="product_form" value="<?=htmlspecialchars($item['product_form'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                            </div>
                             <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Storage Instructions</label>
                                <input type="text" name="storage_instruction" value="<?=htmlspecialchars($item['storage_instructions'])?>" class="w-full mt-1 p-3 border rounded-lg">
                            </div>
                               <div>
                                    <label class="block text-sm font-medium text-gray-700">Grade</label>
                                    <input type="text" name="grade" value="<?=htmlspecialchars($item['grade'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                                    </div>
                           <div class="grid grid-cols-2 gap-4">
                                 <div>
                                    <label class="block text-sm font-medium text-gray-700">Expiry Info</label>
                                    <input type="text" name="expiry_info" value="<?=htmlspecialchars($item['expiry_info'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                </div>
                                <div>
                                <label class="block text-sm font-medium text-gray-700">Tags (comma separated)</label>
                                <input type="text" name="tags" value="<?=htmlspecialchars($item['tags'])?>" class="w-full mt-1 p-3 border rounded-lg">
                                    </div>
                                
                            </div>
                            <div class="mt-4">
                                <label class="block font-medium text-gray-700 mb-2">
                                    Initial Rating Setup
                                    <span class="ml-2 text-xs font-normal text-gray-400">(sets the starting review count &amp; star average)</span>
                                </label>
                                <?php
                                    // Reverse-calculate user_count and rating_star from stored values
                                    $stored_reviews = intval($item['total_reviews'] ?? 0);
                                    $stored_points  = floatval($item['total_rating_points'] ?? 0);
                                    $stored_star    = $stored_reviews > 0 ? round($stored_points / $stored_reviews) : 0;
                                ?>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">User Count</label>
                                        <input type="number" name="init_user_count" id="init_user_count"
                                               min="0" value="<?= $stored_reviews ?>"
                                               class="w-full p-3 border rounded-lg text-center text-lg font-bold"
                                               oninput="updateStarPreview()">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Rating Stars (1–5)</label>
                                        <input type="number" name="init_rating_star" id="init_rating_star"
                                               min="0" max="5" value="<?= $stored_star ?>"
                                               class="w-full p-3 border rounded-lg text-center text-lg font-bold"
                                               oninput="updateStarPreview()">
                                    </div>
                                </div>
                                <div class="mt-2 text-sm text-gray-500 bg-gray-50 rounded-lg p-2">
                                    Preview: <strong id="starPreview">0.0</strong> ⭐
                                    <span class="text-xs text-gray-400 ml-2" id="calcPreview"></span>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="block font-medium text-gray-700 mb-1">
                                    Bought Count
                                    <span class="ml-2 text-xs font-normal text-gray-400">(shows "X+ bought in past month" on product page)</span>
                                </label>
                                <input type="number" name="bought_count" id="bought_count"
                                       min="0" value="<?= intval($item['bought_count'] ?? 0) ?>"
                                       placeholder="e.g. 50"
                                       class="w-full p-3 border rounded-lg text-center text-lg font-bold">
                                <p class="text-xs text-gray-400 mt-1">Set to 0 to hide the badge. Use round numbers like 50, 100, 500.</p>
                            </div>

                            <div class="mt-4">
                                <label class="block font-medium text-gray-700 mb-1">
                                    Product Badge Label
                                    <span class="ml-2 text-xs font-normal text-gray-400">(ribbon shown on thumbnail corner, e.g. "Best Seller", "New", "Hot")</span>
                                </label>
                                <input type="text" name="badge_label" id="badge_label"
                                       maxlength="20"
                                       value="<?= htmlspecialchars($item['badge_label'] ?? '') ?>"
                                       placeholder='e.g. Best Seller, New, Hot Deal'
                                       class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                                <p class="text-xs text-gray-400 mt-1">Leave empty to show no ribbon badge on the product card.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 border-t pt-6">
                        <div class="flex justify-between items-center mb-4">
                            <label class="block font-bold text-gray-700">Product Images (Drag to reorder)</label>
                            <label for="images" class="cursor-pointer bg-indigo-50 text-indigo-600 px-4 py-2 rounded-lg hover:bg-indigo-100 transition">
                                <i class="fa-solid fa-upload mr-2"></i> Add More Images
                            </label>
                            <input type="file" id="images" name="images[]" multiple accept="image/*" class="hidden">
                        </div>
                        
                        <div id="preview" class="image-preview-container"></div>
                        <div id="hiddenInputs"></div>
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold text-lg hover:bg-indigo-700 transition shadow-lg">
                        Update Product
                    </button>
                </form>
            </div>
        </main>
    </div>

    <div id="categoryModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-xl w-96">
            <h2 class="text-xl font-bold mb-4">Add Category</h2>
            <input id="newCategoryName" type="text" class="w-full p-2 border rounded mb-4" placeholder="Category Name">
            <button onclick="saveCategory()" class="w-full py-2 bg-green-600 text-white rounded">Save</button>
            <button onclick="document.getElementById('categoryModal').classList.add('hidden')" class="w-full mt-2 py-2 text-gray-500">Cancel</button>
        </div>
    </div>

    <div id="brandModal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-xl w-96">
            <h2 class="text-xl font-bold mb-4">Add Brand</h2>
            <input id="newBrandName" type="text" class="w-full p-2 border rounded mb-4" placeholder="Brand Name">
            <button onclick="saveBrand()" class="w-full py-2 bg-green-600 text-white rounded">Save</button>
            <button onclick="document.getElementById('brandModal').classList.add('hidden')" class="w-full mt-2 py-2 text-gray-500">Cancel</button>
        </div>
    </div>

    <script>
    /* --- DATA INITIALIZATION --- */
    const currentCategoryId = <?=json_encode($item['category_id'])?>;
    const currentBrandId = <?=json_encode($item['brand_id'])?>;
    
    // Existing images from PHP to JS
    let imageList = <?= json_encode(array_map(function($img) {
        return [
            'id' => $img['id'],
            'path' => $img['compressed_path'],
            'type' => 'existing', 
            'image_type' => $img['image_type'], // 'thumbnail' or 'gallery'
        ];
    }, $images)) ?>;


    let selectedFiles = []; // For new uploads
    let removedImageIds = []; // Track IDs to delete

    /* --- DROPDOWNS --- */
    function loadCategories() {
        fetch(BASE_URL + "/api/fetch_categories.php")
            .then(res => res.json())
            .then(data => {
                let html = data.map(c => `<option value="${c.id}" ${c.id == currentCategoryId ? 'selected' : ''}>${c.name}</option>`).join('');
                document.getElementById("categorySelect").innerHTML = html;
            });
    }

    function loadBrands() {
        fetch(BASE_URL + "/api/fetch_brands.php")
            .then(res => res.json())
            .then(data => {
                let html = data.map(b => `<option value="${b.id}" ${b.id == currentBrandId ? 'selected' : ''}>${b.name}</option>`).join('');
                document.getElementById("brandSelect").innerHTML = html;
            });
    }

    /* --- IMAGE PREVIEW & DRAG-DROP --- */
    document.getElementById("images").addEventListener("change", function(e) {
        const files = Array.from(e.target.files);
        files.forEach(file => {
            selectedFiles.push(file);
            imageList.push({ type: 'new', file: file, name: file.name });
        });
        renderPreview();
        e.target.value = ""; 
    });

    function renderPreview() {
        const preview = document.getElementById("preview");
        preview.innerHTML = "";
        
        imageList.forEach((item, index) => {
            const wrapper = document.createElement("div");
            wrapper.classList.add("img-box");
            wrapper.setAttribute("draggable", "true");
            wrapper.dataset.index = index;
            if (index === 0) wrapper.classList.add("primary");

            const img = document.createElement("img");
            if (item.type === 'existing') {
                img.src =  item.path;
            } else {
                img.src = URL.createObjectURL(item.file);
            }

            const del = document.createElement("span");
            del.className = "delete-icon";
            del.innerHTML = "&times;";
            del.onclick = () => removeImage(index);

            wrapper.appendChild(img);
            wrapper.appendChild(del);

            // const isThumbnail = item.type === 'existing' 
            //     ? item.width === <?= $thumbWidth ?> && item.height === <?= $thumbHeight ?> 
            //     : false; // for new files, will calculate below
            const isThumbnail = item.type === 'existing' && item.image_type === 'thumbnail';


            // For new images, we need to read dimensions
            if(item.type === 'new') {
                const imgObj = new Image();
                imgObj.src = URL.createObjectURL(item.file);
                imgObj.onload = () => {
                    if(imgObj.width === <?= $thumbWidth ?> && imgObj.height === <?= $thumbHeight ?>){
                        wrapper.insertAdjacentHTML('beforeend', '<span class="thumbnail-control">Thumbnail</span>');
                    }
                };
            // } else if(isThumbnail) {
            } else if(item.type === 'existing' && item.image_type === 'thumbnail') {
            wrapper.insertAdjacentHTML('beforeend', '<span class="thumbnail-control">Thumbnail</span>');
            }

            addDragEvents(wrapper);
            preview.appendChild(wrapper);
        });
    }

    function removeImage(index) {
        const item = imageList[index];
        if (item.type === 'existing') {
            removedImageIds.push(item.id);
        } else {
            selectedFiles = selectedFiles.filter(f => f !== item.file);
        }
        imageList.splice(index, 1);
        renderPreview();
    }

    function addDragEvents(el) {
        el.addEventListener("dragstart", e => { e.dataTransfer.setData("text/plain", el.dataset.index); el.style.opacity = '0.4'; });
        el.addEventListener("dragend", () => el.style.opacity = '1');
        el.addEventListener("dragover", e => e.preventDefault());
        el.addEventListener("drop", e => {
            e.preventDefault();
            const fromIdx = parseInt(e.dataTransfer.getData("text/plain"));
            const toIdx = parseInt(el.dataset.index);
            if (fromIdx === toIdx) return;
            
            const movedItem = imageList.splice(fromIdx, 1)[0];
            imageList.splice(toIdx, 0, movedItem);
            renderPreview();
        });
    }

    function updateHiddenInputs() {
        const container = document.getElementById("hiddenInputs");
        container.innerHTML = "";

        removedImageIds.forEach(id => {
            container.innerHTML += `<input type="hidden" name="removed_images[]" value="${id}">`;
        });

        let newFileCounter = 0;

        imageList.forEach((item, visualOrder) => {

            if (item.type === 'existing') {
                container.innerHTML += `<input type="hidden" name="order[${visualOrder}]" value="existing">`;
                container.innerHTML += `<input type="hidden" name="existing_image_ids[${visualOrder}]" value="${item.id}">`;
            } else {
                container.innerHTML += `<input type="hidden" name="order[${visualOrder}]" value="${newFileCounter}">`;
                newFileCounter++;
            }

        });
    }


    /* --- VARIANTS --- */
   function computeVariantDiscount(row) {
    const priceInput = row.querySelector('input[name="variant_price[]"]');
    const oldPriceInput = row.querySelector('input[name="variant_old_price[]"]');
    const discountInput = row.querySelector('input[name="variant_discount[]"]');

    const price = parseFloat(priceInput.value) || 0;
    const oldPrice = parseFloat(oldPriceInput.value) || 0;

    if (oldPrice > price && price > 0) {
        const percentage = ((oldPrice - price) / oldPrice) * 100;
        
        // Use Math.round() to turn 12.5 into 13
        discountInput.value = Math.round(percentage) + "%"; 
    } else {
        discountInput.value = "0%";
    }
}
 function addVariantRow() {
    const html = `
    <div class="variant-row bg-gray-50 p-3 rounded-lg border relative mb-3">
        <div class="grid grid-cols-3 gap-2">
            <div class="col-span-2">
                <label class="text-[10px] uppercase font-bold text-gray-500">Weight</label>
                <div class="flex gap-1">
                    <input type="number" step="0.01" name="variant_weight_value[]" required class="w-2/3 p-1.5 text-sm border rounded">
                    <select name="variant_weight_unit[]" class="w-1/3 p-1.5 text-sm border rounded">
                        <option value="g">g</option><option value="kg">kg</option>
                        <option value="ml">ml</option><option value="l">l</option>
                        <option value="pcs">pcs</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="text-[10px] uppercase font-bold text-gray-500">Price</label>
                <input type="number" step="0.01" name="variant_price[]" oninput="computeVariantDiscount(this.closest('.variant-row'))" required class="w-full p-1.5 text-sm border rounded">
            </div>
            <div>
                <label class="text-[10px] uppercase font-bold text-gray-500">Old Price</label>
                <input type="number" step="0.01" name="variant_old_price[]" oninput="computeVariantDiscount(this.closest('.variant-row'))" class="w-full p-1.5 text-sm border rounded">
            </div>
            <div>
                <label class="text-[10px] uppercase font-bold text-gray-500">Discount%</label>
                <input type="text" name="variant_discount[]" readonly class="w-full p-1.5 text-sm border rounded bg-gray-100">
            </div>
            <div>
                <label class="text-[10px] uppercase font-bold text-gray-500">Stock</label>
                <input type="number" name="variant_stock[]" value="0" class="w-full p-1.5 text-sm border rounded">
            </div>
        </div>
        <input type="hidden" name="variant_status[]" value="1">
        <button type="button" onclick="this.parentElement.remove()" class="absolute -top-2 -right-2 bg-red-500 text-white w-5 h-5 rounded-full text-xs">×</button>
    </div>`;
    document.getElementById("variantsContainer").insertAdjacentHTML('beforeend', html);
}

    // Modal helpers
    function openCategoryModal() { document.getElementById("categoryModal").classList.remove("hidden"); }
    function openBrandModal() { document.getElementById("brandModal").classList.remove("hidden"); }
    
    function saveCategory() {
        let name = document.getElementById("newCategoryName").value;
        if (!name) return;
        fetch(BASE_URL + "/api/add_category.php", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: "name=" + encodeURIComponent(name) })
        .then(() => { loadCategories(); document.getElementById('categoryModal').classList.add('hidden'); });
    }

    function saveBrand() {
        let name = document.getElementById("newBrandName").value;
        if (!name) return;
        fetch(BASE_URL + "/api/add_brand.php", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: "name=" + encodeURIComponent(name) })
        .then(() => { loadBrands(); document.getElementById('brandModal').classList.add('hidden'); });
    }

    // Submit via AJAX to ensure file order
    document.getElementById('itemForm').addEventListener('submit', function(e) {
        e.preventDefault();
        updateHiddenInputs();

        const fd = new FormData(this);
        // Clear the generic file input and append only our ordered selectedFiles
        fd.delete('images[]'); 
        selectedFiles.forEach(file => fd.append('images[]', file));

        fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(html => { document.open(); document.write(html); document.close(); });
    });

    // Init
    loadCategories();
    loadBrands();
    renderPreview();

    function updateStarPreview() {
        const userCount  = parseInt(document.getElementById('init_user_count')?.value  || 0);
        const ratingStar = parseInt(document.getElementById('init_rating_star')?.value || 0);
        const totalPoints = userCount * ratingStar;
        // Initial avg = totalPoints / userCount  (e.g. 60×4=240 / 60 = 4.0)
        const avg = userCount > 0 ? (totalPoints / userCount) : 0;
        document.getElementById('starPreview').textContent = avg.toFixed(2);
        const calcEl = document.getElementById('calcPreview');
        if (calcEl && userCount > 0) {
            // Show initial setup + two example user-review steps
            const u1pts  = totalPoints + 5;
            const u1cnt  = userCount + 1;
            const u1avg  = (u1pts / u1cnt).toFixed(2);
            const u2pts  = u1pts + 3;
            const u2cnt  = u1cnt + 1;
            const u2avg  = (u2pts / u2cnt).toFixed(2);
            // calcEl.innerHTML =
            //     `Initial: ${userCount} × ${ratingStar} = ${totalPoints} pts, avg = ${totalPoints}/${userCount} = ${avg.toFixed(2)} ⭐`
            //   + `&nbsp;|&nbsp;User1 (5★): (${totalPoints}+5=${u1pts}) / ${u1cnt} = ${u1avg} ⭐`
            //   + `&nbsp;|&nbsp;User2 (3★): (${u1pts}+3=${u2pts}) / ${u2cnt} = ${u2avg} ⭐`;
        } else if (calcEl) {
            calcEl.textContent = '';
        }
    }
    // Init
    updateStarPreview();
    </script>
</body>
</html>