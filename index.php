<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
$scrollMessages = $conn->query("SELECT message FROM scrolling_messages WHERE status=1 ORDER BY id DESC")
                       ->fetchAll(PDO::FETCH_ASSOC);

$logoDir = "images/logo/";
$logos = glob($logoDir . "*.{png,jpg,jpeg,svg,webp}", GLOB_BRACE);
// Opt-in debugging flag (use ?debug_images=1)
$debugImages = isset($_GET['debug_images']) && $_GET['debug_images'] === '1';

// Only fetch carousel-type images (excludes popup rows), ordered by admin drag-drop
$res = $conn->prepare("SELECT * FROM site_images WHERE type = 'carousel' AND status = 1 ORDER BY sort_order ASC");
$res->execute();
$slides = $res->fetchAll(PDO::FETCH_ASSOC);

// Fetch Categories for Filter
$catStmt = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
$catStmt->execute();
$filterCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Brands for Filter
$brandStmt = $conn->prepare("SELECT id, name FROM brands ORDER BY name ASC");
$brandStmt->execute();
$filterBrands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch GST rate from the settings table
$stmt = $conn->prepare("SELECT gst_rate, last_enquiry_number, notification_text, minimum_order, popup_enabled, popup_interval_hours FROM settings LIMIT 1");
$stmt->execute();
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
$notificationText = !empty($settings['notification_text']) ? $settings['notification_text'] : null;
$gstRate = isset($settings['gst_rate']) ? floatval($settings['gst_rate']) : 18;
$minimumOrder = !empty($settings['minimum_order']) ? floatval($settings['minimum_order']) : 2000;

// ── Popup Ad ──────────────────────────────────────────────────
// Fetch popup images that are individually enabled (status=1) when global toggle is on
$popupAdEnabled = isset($settings['popup_enabled']) ? (int)$settings['popup_enabled'] : 0;
$popupAdImages  = [];
if ($popupAdEnabled) {
    $popStmt = $conn->prepare(
        "SELECT image_path FROM site_images WHERE type = 'popup' AND status = 1 ORDER BY id ASC"
    );
    $popStmt->execute();
    $popupAdImages = $popStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch shop details from DB
$stmt = $conn->prepare("SELECT name, shopaddress, phone, email FROM admin_details LIMIT 1");
$stmt->execute();
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT * FROM image_settings");
$stmt->execute();
$imageSizes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sizes = [];
foreach ($imageSizes as $size) {
    $sizes[$size['type']] = $size;
}

$productWidth  = $sizes['product']['width'] ?? 250;
$productHeight = $sizes['product']['height'] ?? 250;
$thumbWidth  = $sizes['thumbnail']['width'] ?? 120;
$thumbHeight = $sizes['thumbnail']['height'] ?? 120;

$adminName = $shop['name'] ?? 'RGreenMart';
$shopAddress = $shop['shopaddress'] ?? 'Chandragandhi Nagar, Madurai, Tamil Nadu';
$shopPhone = $shop['phone'] ?? '99524 24474';
$shopEmail = $shop['email'] ?? 'sales@rgreenmart.com';

$searchQuery = trim($_GET['q'] ?? '');
$searchSQL = '';
$params = [];

if (!empty($searchQuery)) {
    $searchSQL = " WHERE i.name LIKE :search OR i.description LIKE :search ";
    $params[':search'] = "%$searchQuery%";
}

$stmt = $conn->prepare(
    "SELECT 
        i.id, 
        i.name, 
        i.category_id, 
        i.brand_id, 
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
        i.tags, 
        i.created_at, 
        i.updated_at,
        i.initial_rating,
        i.total_rating_points,
        i.total_reviews,
        i.badge_label,
        COALESCE(i.image, (
            SELECT COALESCE(compressed_path, image_path) 
            FROM item_images 
            WHERE item_id = i.id 
            AND image_type = 'thumbnail'
            ORDER BY is_primary DESC, sort_order ASC
            LIMIT 1
        )) AS primary_image,
        v.id AS variant_id,
        v.price, 
        v.old_price, 
        v.discount, 
        v.stock, 
        v.weight_value, 
        v.weight_unit
    FROM items i
    LEFT JOIN item_variants v ON v.id = (
        SELECT id FROM item_variants 
        WHERE item_id = i.id AND status = 1 
        ORDER BY price ASC LIMIT 1
    )"
);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$imageStmt = $conn->prepare("
    SELECT item_id,
           COALESCE(compressed_path, image_path) AS image_path,
           image_type
    FROM item_images
    ORDER BY is_primary DESC, sort_order ASC, id ASC
");
$imageStmt->execute();
$allImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

$productThumbnails = [];
$productGallery = [];

foreach ($allImages as $img) {
    if ($img['image_type'] === 'thumbnail') {
        $productThumbnails[$img['item_id']][] = $img['image_path'];
    } else {
        $productGallery[$img['item_id']][] = $img['image_path'];
    }
}

if ($debugImages) {
    $jsonItems = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log('[image-debug] items query result: ' . $jsonItems);
}

$gstRate = 18;
$processedItems = [];

foreach ($items as $idx => $item) {
    $oldPrice  = (float) $item['old_price'];
    $sellPrice = (float) $item['price'];
    $dbDiscount = (float) $item['discount']; // discount % stored directly in item_variants

    // If old_price is missing but a DB discount % is set, derive old_price
    if ($oldPrice <= 0 && $sellPrice > 0 && $dbDiscount > 0 && $dbDiscount < 100) {
        $oldPrice = $sellPrice / (1 - $dbDiscount / 100);
    }

    $grossPrice = round($oldPrice);
    $simpleDiscountedPrice = round($sellPrice);

    if ($oldPrice > 0 && $sellPrice > 0 && $oldPrice > $sellPrice) {
        $discountRate = floor((($oldPrice - $sellPrice) / $oldPrice) * 100);
    } else {
        $discountRate = 0;
    }

    $netPrice = round($sellPrice / (1 + $gstRate / 100));
    $gstAmount = $simpleDiscountedPrice - $netPrice;
    $discountAmount = $grossPrice - $simpleDiscountedPrice;

    $defaultPublicImage = "/images/default.jpg";
    $candidate = trim($item['primary_image'] ?? '');

    $primaryImageStmt = $conn->prepare("
        SELECT COALESCE(compressed_path, image_path) AS primary_image
        FROM item_images
        WHERE item_id = :item_id AND image_type = 'thumbnail'
        ORDER BY is_primary DESC, sort_order ASC
        LIMIT 1
    ");
    $primaryImageStmt->execute([':item_id' => $item['id']]);
    $primaryImage = $primaryImageStmt->fetchColumn();

    $displayImgPath  = $primaryImage ? "/admin/" . ltrim($primaryImage, '/') : $defaultPublicImage;
    $publicOriginal  = $displayImgPath;
    $publicCompressed = $displayImgPath;

    $displayImgPath = $defaultPublicImage;
    $publicOriginal = $defaultPublicImage;
    $publicCompressed = $defaultPublicImage;
    $fileExists = false;
    $testedPaths = [];

    if (!empty($candidate)) {
        $variants = [
            '/' . ltrim($candidate, '/'),
            '/admin/' . ltrim($candidate, '/'),
        ];
        foreach ($variants as $p) {
            $testedPaths[] = $p;
            $serverP = $_SERVER['DOCUMENT_ROOT'] . $p;
            if (file_exists($serverP)) {
                $displayImgPath = $p;
                $publicOriginal = $p;
                $publicCompressed = $p;
                $fileExists = true;
                break;
            }
        }
    }

    if ($debugImages) {
        $dbg = [
            'item_id' => $item['id'] ?? null,
            'item_name' => $item['name'] ?? null,
            'primary_image_field' => $item['primary_image'] ?? null,
            'candidate' => $candidate,
            'tested_paths' => $testedPaths ?? [],
            'serverPath' => isset($serverP) ? $serverP : null,
            'file_exists' => $fileExists,
            'displayImgPath' => $displayImgPath
        ];
        error_log("[image-debug] " . json_encode($dbg));
    }

    $processedItems[$idx] = [
        'id'          => htmlspecialchars($item['id'], ENT_QUOTES),
        'name'        => htmlspecialchars($item['name'], ENT_QUOTES),
        'category_id' => $item['category_id'],
        'brand_id'    => $item['brand_id'],
        'price'                => $item['price'],
        'old_price'            => $item['old_price'],
        'discount'             => $discountRate,
        'grossPrice'           => $grossPrice,
        'netPrice'             => $netPrice,
        'gstAmount'            => $gstAmount,
        'discountAmount'       => $discountAmount,
        'simpleDiscountedPrice'=> $simpleDiscountedPrice,
        'stock'  => $item['stock'],
        'variant_id'   => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
        'weight_value' => $item['weight_value'] ?? '',
        'weight_unit'  => $item['weight_unit']  ?? '',
        'image'           => $publicOriginal,
        'compressedImage' => $publicCompressed,
        'displayImgPath'  => htmlspecialchars($displayImgPath, ENT_QUOTES),
        'weight'        => $item['weight_value'] . ' ' . $item['weight_unit'],
        'packaging_type'=> $item['packaging_type'],
        'product_form'  => $item['product_form'],
        'origin'        => $item['origin'],
        'grade'         => $item['grade'],
        'purity'        => $item['purity'],
        'flavor'        => $item['flavor'],
        'description'           => $item['description'],
        'nutrition'             => $item['nutrition'],
        'shelf_life'            => $item['shelf_life'],
        'storage_instructions'  => $item['storage_instructions'],
        'expiry_info'           => $item['expiry_info'],
        'tags'        => $item['tags'],
        'created_at'  => $item['created_at'],
        'updated_at'  => $item['updated_at'],
        'badge_label' => htmlspecialchars($item['badge_label'] ?? '', ENT_QUOTES),
        'discountRate' => $discountRate,
        'final_rating' => (function() use ($item) {
            $totalReviews = (int)($item['total_reviews'] ?? 0);
            $totalPoints  = (float)($item['total_rating_points'] ?? 0);
            $r = $totalReviews > 0 ? ($totalPoints / $totalReviews) : 0;
            return round(max(0, min(5, $r)), 1);
        })(),
    ]; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RGreenMart</title>
    <link rel="icon" type="image/png" href="./images/LOGO.jpg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" type="text/css" href="./Styles.css">
    <style>
        :root {
            --product-width: <?= $productWidth ?>px;
            --product-height: <?= $productHeight ?>px;
            --thumbnail-width: <?= $thumbWidth ?>px;
            --thumbnail-height: <?= $thumbHeight ?>px;
        }

        #main-body {
            margin-top: 30px !important;
            margin-bottom: 60px !important;
            padding-top: 0 !important;
        }

        #hero {
            padding: 0 !important;
            line-height: 0;
            font-size: 0;
            margin-bottom: 60px;
        }

        .filter-fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        /* ===================== */
        /* MOBILE: Floating btn  */
        /* ===================== */
        #filter-master-btn {
            position: fixed;
            bottom: 40px;
            left: 40px;
            z-index: 5000;
            transition: all 0.3s ease;
            border: none;
            width: 55px;
            height: 55px;
            padding: 0;
        }

        #filter-master-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }

        #filter-master-btn:active {
            transform: scale(0.95);
        }

        #filter-master-btn.active {
              background: linear-gradient(135deg, #e91e63, #6a1b9a) !important;
        }

        /* ========================= */
        /* MOBILE: Slide-up drawer   */
        /* ========================= */
        @media (max-width: 991px) {
            #filter-sidebar {
                position: fixed;
                bottom: 0;
                left: 0;
                width: 100%;
                height: 75vh;
                background: #fff;
                z-index: 4000;
                border-radius: 20px 20px 0 0;
                transform: translateY(100%);
                transition: transform 0.35s ease;
                display: flex;
                flex-direction: column;
            }

            #filter-sidebar.show {
                transform: translateY(0);
            }

            .filter-mobile-header {
                padding: 15px 20px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fff;
                border-radius: 20px 2px 0 0;
                flex-shrink: 0;
            }

            .filter-card {
                flex: 1;
                overflow-y: auto;
                padding: 20px;
            }

            /* Products take full width on mobile */
            #products {
                width: 100%;
            }
        }

        /* ============================ */
        /* DESKTOP: Static left sidebar */
        /* ============================ */
        @media (min-width: 992px) {
            /* Main layout: flex row */
            #main-body .row {
                display: flex;
                flex-direction: row;
                align-items: flex-start;
                flex-wrap: nowrap;
            }

            #filter-sidebar {
                /* Static — part of normal document flow */
                position: sticky;
                top: 80px; /* Adjust to sit below your fixed header height */
                width: 280px;
                min-width: 280px;
                max-width: 280px;
                height: auto;
                max-height: calc(100vh - 100px);
                overflow-y: auto;
                background: #fff;
                transform: none !important;  /* Override any mobile transform */
                border-radius: 12px;
                display: flex !important;    /* Always visible on desktop */
                flex-direction: column;
                box-shadow: 0 2px 16px rgba(0,0,0,0.09);
                flex-shrink: 0;
                margin-right: 16px;
            }

            /* Hide overlay on desktop — not needed */
            #filter-overlay {
                display: none !important;
            }

            /* Products section fills remaining space */
            #products {
                flex: 1;
                min-width: 0; /* Prevents overflow */
            }

            /* Hide mobile-only header in sidebar */
            .filter-mobile-header {
                display: none !important;
            }

            .filter-card {
                padding: 16px;
            }

            /* Scrollbar styling for sidebar */
            #filter-sidebar::-webkit-scrollbar {
                width: 4px;
            }
            #filter-sidebar::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 4px;
            }
            #filter-sidebar::-webkit-scrollbar-thumb {
                background: #c5c5c5;
                border-radius: 4px;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .transition-all {
            transition: all 0.3s ease-in-out;
        }

        .filter-checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            padding-left: 5px;
        }

        .form-check {
            margin-bottom: 6px;
        }

        .form-check-label {
            font-size: 14px;
            cursor: pointer;
        }

        header .container-fluid {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: nowrap;
        }

        #filter-sidebar .search-container {
            max-width: 150px;
        }

        #filter-sidebar .search-container {
            max-width: 100%;
        }

        .search-container {
            position: relative;
            width: 100%;
            margin-bottom: 1.5rem;
        }

        #filter-sidebar .search-container input {
            max-width: 100% !important;
            padding-right: 40px !important;
            padding-left: 15px !important;
            height: 40px;
        }

        #filter-sidebar .search-container .bi-search {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #157347;
            pointer-events: none;
            display: flex;
            align-items: center;
            z-index: 5;
        }

    @media (max-width: 768px) {
        #main-body {
            margin-top: 8px !important;
        }
        .carousel-inner {
            font-size: 0;
            line-height: 0;
        }
        .carousel-item {
            line-height: 0;
            font-size: 0;
            min-height: 0 !important;
            overflow: hidden !important;
        }
        .carousel-img {
            display: block;
            width: 100% !important;
            height: auto !important;
            max-height: 220px;
            object-fit: cover;
            object-position: center;
        }
    }

    #products {
            padding-left: 10px !important;
            padding-right: 10px !important;
    }

    /* ============================= */
    /* MOBILE PERFECT PRODUCT GRID  */
    /* ============================= */
    @media (max-width: 576px) {
        #products {
            padding-left: 8px !important;
            padding-right: 8px !important;
        }
        .card-container {
            padding: 6px;
            border: 1px solid #f0f0f0;
        }
        .thumbnail-image {
            height: 190px !important;
        }
        .slider-img {
            object-fit: cover !important;
        }
        .product-title {
            font-size: 13px;
            margin: 0;
        }
        .price {
            margin: 0 2px 2px;
            gap: 6px;
        }
        .new-price {
            font-size: 20px;
            font-weight: 700;
        }
        .old-price {
            font-size: 15px;
        }
        .discount-text {
            font-size: 25px;
        }
        .add-to-cart-btn {
            font-size: 12px;
            padding: 6px 0;
            border-radius: 2px;
            margin-top: 0px;
        }
        .badge {
            font-size: 10px;
            padding: 3px 6px;
        }
    }

    /* ============================
       DISCOUNT CIRCLE BADGE
       ============================ */
    .discount-circle-badge {
        position: absolute;
        top: 8px;
        left: 8px;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #e53935;
        color: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-weight: 900;
        line-height: 1;
        z-index: 3;
        /* box-shadow: 0 2px 8px rgba(229,57,53,0.45); */
        pointer-events: none;
        text-align: center;
    }
    .discount-circle-badge .dc-pct {
        font-size: 12px;
        letter-spacing: -0.5px;
    }
    .discount-circle-badge .dc-off {
        font-size: 8px;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin-top: 1px;
    }
    @media (max-width: 576px) {
        .discount-circle-badge { width: 35px; height: 35px; }
        .discount-circle-badge .dc-pct { font-size: 10px; }
        .discount-circle-badge .dc-off  { font-size: 8px; }
    }

    /* ============================
       RIBBON BADGE (top-right)
       ============================ */
    .ribbon-badge {
        position: absolute;
        top: 0;
        right: 0;
        z-index: 4;
        overflow: hidden;
        /* Wide enough to never clip even long labels like "NEWLY ARRIVED" */
        width: 120px;
        height: 120px;
        pointer-events: none;
    }
    .ribbon-badge span {
        position: absolute;
        display: block;
        /* Wide band so all text fits; the rotated diagonal reveals ~85px of width */
        width: 160px;
        padding: 6px 0;
        background: #c62828;
        color: #fff;
        font-size: 9.5px;
        font-weight: 800;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        /* Shift right & down so the band sits in the top-right corner triangle */
        right: -38px;
        top: 24px;
        transform: rotate(45deg);
        box-shadow: 0 2px 6px rgba(0,0,0,0.30);
        /* Never clip — the wider container gives full room */
        white-space: nowrap;
        overflow: visible;
        line-height: 1.4;
    }
    @media (max-width: 576px) {
        .ribbon-badge { width: 100px; height: 100px; }
        .ribbon-badge span { width: 136px; font-size: 8.5px; right: -30px; top: 20px; }
    }

    /* ============================
       PRICE ROW — new style
       ============================ */
    .price-row {
        display: flex;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 4px;
        margin: 4px 4px 2px;
    }
    .price-discount-pill {
        font-size: 12px;
        font-weight: 700;
        color: #388e3c;
        background: #e8f5e9;
        border-radius: 4px;
        padding: 1px 5px;
        white-space: nowrap;
        margin-left: 2px;
    }
    .price-selling {
        padding-left: 2px;
        font-size: 19px;
        font-weight: 800;
        color: #111;
        white-space: nowrap;
    }
    .price-selling sup {
        font-size: 11px;
        font-weight: 700;
        /* vertical-align: super; */
    }
    .price-selling .price-paise {
        font-size: 12px;
        font-weight: 700;
        /* vertical-align: super; */
    }
    .price-mrp {
        font-size: 15px;
        color: #888;
        text-decoration: line-through;
        white-space: nowrap;
        padding-left: 1px;
    }
    @media (max-width: 576px) {
        .price-selling { font-size: 16px; margin: 0px;}
        .price-discount-pill { font-size: 11px; }
        .price-mrp { font-size: 14px; }
    }

    /* Filter overlay backdrop for mobile */
    .filter-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.4);
        z-index: 3999;
    }
        
/* ================= PREMIUM PURPOSE CARDS ================= */
    .purpose-card {
        perspective: 1000px;
        height: 210px;
        cursor: pointer;
        transition: transform 0.3s ease;
    }

    .purpose-card:hover {
        transform: scale(1.03);
    }

    .purpose-inner {
        position: relative;
        width: 100%;
        height: 100%;
        transition: transform 0.7s;
        transform-style: preserve-3d;
    }

    .purpose-card.active .purpose-inner { transform: rotateY(180deg); }

    .purpose-front,
    .purpose-back {
        position: absolute;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        border-radius: 18px;
        padding: 30px 20px;
        text-align: center;
        border: 1px solid #f1f1f1;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        backface-visibility: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        transition: 0.3s;
    }

    .purpose-card:hover .purpose-front { box-shadow: 0 20px 45px rgba(21,115,71,0.12); }

    .purpose-back {
        transform: rotateY(180deg);
        color: #ffff;
        font-size: 14px;
        font-weight: 500;
    }

    .purpose-icon-wrapper {
        font-size: 40px;
        color: #ffff;
        margin-bottom: 15px;          
        transition: 0.3s;
    }

    .purpose-card:hover .purpose-icon-wrapper { transform: translateY(-5px); }

    .purpose-title {
        color: #ffff;
        font-size: 17px;
        font-weight: 600;
    }
    
    .top-scroll-bar {
        width: 100%;
        overflow: hidden;
        background: linear-gradient(90deg, #000000, #ff4081);
        color: #fff;
        padding: 18px 0;
        font-weight: 500;
        position: relative;
    }

    .scroll-track{
        display:flex;
        align-items:center;
        width:max-content;
        animation:scrollLeft 20s linear infinite;
    }

    .scroll-item{
        white-space:nowrap;
        padding: 0 60px;   /* equal left & right space */
    }

    .pipe{
        padding: 0 20px;   /* space around pipe */
        opacity:0.8;
    }

    /* continuous scroll — no pause on hover */

    @keyframes scrollLeft {
        from {
            transform: translateX(0);
        }
        to {
            transform: translateX(-50%);
        }
    }

    /* ================= WHY CHOOSE ================= */
    .why-choose-section {
        background: #ffffff;
    }

    .why-card {
        background: #ffffff;
        border-radius: 18px;
        padding: 35px 25px;
        border: 1px solid #f3f3f3;
        box-shadow: 0 6px 25px rgba(0,0,0,0.04);
        transition: all 0.35s ease;
        text-align: center;
    }

    .why-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 15px 40px rgba(21,115,71,0.12);
    }

    .why-icon {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 18px;
        font-size: 28px;
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        color: #ffff;
        transition: 0.3s;
    }

    .why-card:hover .why-icon {
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        color: #ffffff;
        transform: rotateY(180deg);
    }

    .why-title {
        font-weight: 600;
        font-size: 16px;
        margin-bottom: 10px;
    }

    .why-text {
        font-size: 14px;
        color: #666;
        line-height: 1.6;
    }

    /* ================= TESTIMONIALS ================= */

    .testimonials-section {
        position: relative;
    }

    /* Decorative background quote */
    .testimonial-card {
        background: #ffffff;
        border-radius: 18px;
        padding: 35px 25px;
        border: none;
        box-shadow: 0 10px 35px rgba(21,115,71,0.08);
        transition: all 0.35s ease;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .testimonial-card::before {
        content: "“";
        position: absolute;
        top: 10px;
        left: 20px;
        font-size: 70px;
        color: rgba(21,115,71,0.06);
        font-weight: bold;
    }

    .testimonial-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 45px rgba(21,115,71,0.15);
    }

    /* Profile Image */
    .testimonial-img {
        width: 60px;
        height: 60px;
        margin: 0 auto 15px;
    }

    .testimonial-img img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #6a1b9a;
    }

    /* Stars */
    .stars {
        color: #ffa407;
        font-size: 15px;
        letter-spacing: 2px;
        margin-bottom: 10px;
    }

    /* Text */
    .testimonial-text {
        font-size: 14px;
        color: #555;
        line-height: 1.6;
        margin-bottom: 15px;
    }

    /* Name */
    .testimonial-name {
        font-weight: 600;
        color: #6a1b9a ;
        font-size: 15px;
        padding-bottom: 10px;
    }

    .testimonial-location {
        font-size: 12px;
        color: #999;
    }

    /* Carousel controls better style */
    .carousel-control-prev-icon,
    .carousel-control-next-icon {
        background-color: #00000060;
        border-radius: 50%;
        padding: 18px;
    }

    .btn-gradient-filter {
        border: none;
    }

    .btn-gradient-filter:hover {
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #c2185b, #4a148c);
    }

    /* Product Name Styling */
    .product-card h3, 
    .product-title {
        font-family: 'Poppins', sans-serif; /* Matching your scrolling text font */
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-dark); /* Dark gray/black for readability */
        margin: 10px 0 0 60px;
        line-height: 1.4;
        transition: color 0.3s ease;
        display: -webkit-box;
        -webkit-line-clamp: 2; /* Limits name to 2 lines for uniformity */
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>
</head>

<body>
<main id="main" style="position: relative; z-index: 2;">
    <?php if ($notificationText): ?>
    <div class="scrolling-text-container">
        <span class="scrolling-text">
            <?= htmlspecialchars($notificationText); ?>
        </span>
    </div>
    <?php endif; ?>

    <?php require_once $_SERVER["DOCUMENT_ROOT"] . "/includes/header.php"; ?>

<div class="top-scroll-bar">
    <div class="scroll-wrapper">
        <div class="scroll-track">

            <?php
            $first = true;

            foreach($scrollMessages as $msg){
                if(!$first){
                    echo "<span class='pipe'> | </span>";
                }
                echo "<span class='scroll-item'>🔥 " . htmlspecialchars($msg['message']) . "</span>";
                $first = false;
            }

            // duplicate for infinite scroll
            foreach($scrollMessages as $msg){
                echo "<span class='pipe'> | </span>";
                echo "<span class='scroll-item'>🔥 " . htmlspecialchars($msg['message']) . "</span>";
            }
            ?>

        </div>
    </div>
</div>

    <section id="hero">
        <div id="bannerCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5000">
            <div class="carousel-indicators">
                <?php foreach ($slides as $i => $slide): ?>
                    <button type="button" data-bs-target="#bannerCarousel" data-bs-slide-to="<?= $i ?>"
                        class="<?= $i === 0 ? 'active' : '' ?>"></button>
                <?php endforeach; ?>
            </div>
            <div class="carousel-inner">
                <?php foreach ($slides as $i => $slide): ?>
                    <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>" data-bs-interval="5000">
                        <img src="<?= $slide['image_path'] ?>" class="d-block w-100 carousel-img" alt="Slide <?= $i + 1 ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#bannerCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#bannerCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
            </button>
        </div>
    </section>

    <!-- Mobile-only floating filter button (hidden on desktop lg+) -->
    <button class="btn btn-success d-flex d-lg-none align-items-center justify-content-center shadow rounded-circle"
            type="button"
            onclick="toggleFilters()"
            id="filter-master-btn">
        <i class="bi bi-funnel-fill" style="font-size: 1.5rem;"></i>
    </button>

    <div id="main-body" class="container-fluid px-2 px-md-3">
        <div class="row flex-nowrap align-items-start">

            <!-- Filter overlay (mobile backdrop) -->
            <div id="filter-overlay" class="filter-overlay" onclick="toggleFilters()"></div>

            <!-- ===== FILTER SIDEBAR ===== -->
            <aside id="filter-sidebar">
                <!-- Desktop header (no close button needed since sidebar is always visible) -->
                <div class="d-none d-lg-flex justify-content-between align-items-center p-3 border-bottom">
                    <h5 class="m-0 fw-bold" style=" color: linear-gradient(135deg, #e91e63, #6a1b9a);">
                        <i class="bi bi-funnel me-2"></i>Filters
                    </h5>
                </div>

                <!-- Mobile header (with close button) -->
                <div class="filter-mobile-header d-lg-none">
                    <h5 class="m-0">Filters &amp; Search</h5>
                    <button onclick="toggleFilters()" class="btn-close"></button>
                </div>

                <div class="filter-card p-3 bg-white">
                    <div class="search-container mb-3">
                        <label class="form-label small fw-bold text-muted">Search Products</label>
                        <div class="position-relative">
                            <input type="text" id="filter-search" class="form-control form-control-sm" placeholder="Search name..." onkeyup="applyFilters()">
                            <i class="bi bi-search"></i>
                        </div>
                    </div>

                    <div class="mb-3">
                        <button onclick="clearFilters()" class="btn btn-sm btn-outline-danger w-100">
                            <i class="bi bi-x-circle me-1"></i>Clear All Filters
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Category</label>
                        <div id="filter-category" class="filter-checkbox-group">
                            <?php foreach($filterCategories as $cat): ?>
                                <div class="form-check">
                                    <input class="form-check-input category-checkbox" type="checkbox" value="<?= $cat['id'] ?>" onchange="applyFilters()">
                                    <label class="form-check-label"><?= htmlspecialchars($cat['name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Brand</label>
                        <div id="filter-brand" class="filter-checkbox-group">
                            <?php foreach($filterBrands as $brand): ?>
                                <div class="form-check">
                                    <input class="form-check-input brand-checkbox" type="checkbox" value="<?= $brand['id'] ?>" onchange="applyFilters()">
                                    <label class="form-check-label"><?= htmlspecialchars($brand['name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted d-flex justify-content-between">
                            Price Range <span>₹0 – ₹<span id="price-val">5000</span></span>
                        </label>
                        <input type="range" class="form-range" id="filter-price" min="0" max="5000" step="10" value="5000" oninput="applyFilters()">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Offers</label>
                        <div class="form-check">
                            <input class="form-check-input offer-checkbox" type="checkbox" value="1" onchange="applyFilters()">
                            <label class="form-check-label">Only Show Products with Offers</label>
                        </div>
                    </div>
                </div>
            </aside>
            <!-- ===== END FILTER SIDEBAR ===== -->

            <!-- ===== PRODUCTS SECTION ===== -->
            <section id="products" class="transition-all px-2 px-md-3">
                <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-2 sm:gap-3 md:gap-4">
                    <?php foreach ($processedItems as $item): ?>
                    <?php
                        $cartData = [
                            "id"                => (int)$item["id"],
                            "name"              => $item["name"],
                            "price"             => (float)$item["simpleDiscountedPrice"],
                            "oldamt"            => (float)$item["grossPrice"],
                            "discountRate"      => (float)$item["discountRate"],
                            "gstRate"           => $gstRate,
                            "image"             => $item["displayImgPath"],
                            "variant_id"        => $item["variant_id"],
                            "variant_price"     => (float)$item["simpleDiscountedPrice"],
                            "variant_old_price" => (float)$item["grossPrice"],
                            "variant_discount"  => (float)$item["discountRate"],
                            "variant_weight"    => $item["weight_value"],
                            "variant_unit"      => $item["weight_unit"],
                        ];
                    ?>
                    <div class="card-container" data-category="<?= $item['category_id']; ?>" data-brand="<?= $item['brand_id']; ?>">
                        <a href="product.php?id=<?= $item['id']; ?>">
                            <div class="thumbnail-image slider-container" style="position:relative;overflow:hidden;">
                                <?php
                                    $images = $productThumbnails[$item['id']] ?? [];
                                    $carouselStmt = $conn->prepare("
                                        SELECT COALESCE(compressed_path, image_path) AS image_path
                                        FROM item_images
                                        WHERE item_id = :item_id AND image_type = 'carousel'
                                        ORDER BY is_primary DESC, sort_order ASC
                                    ");
                                    $carouselStmt->execute([':item_id' => $item['id']]);
                                    $carouselImages = $carouselStmt->fetchAll(PDO::FETCH_COLUMN);
                                    $images = array_merge($images, $carouselImages);
                                    if (empty($images)) { $images = [$item['displayImgPath']]; }
                                ?>
                                <div class="slider-images">
                                    <?php foreach ($images as $index => $img): ?>
                                        <img src="/admin/<?= ltrim($img, '/') ?>"
                                            class="slider-img <?= $index === 0 ? 'active' : '' ?>">
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($item['discountRate'] > 0): ?>
                                    <div class="discount-circle-badge">
                                        <span class="dc-pct"><?= $item['discountRate']; ?>%</span>
                                        <span class="dc-off">OFF</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($item['badge_label'])): ?>
                                    <div class="ribbon-badge">
                                        <span><?= $item['badge_label']; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:4px;margin:4px 4px 0px;min-height:2.2rem;">
                                <p class="product-title" style="margin:0;flex:1;min-width:0;">
                                    <?= htmlspecialchars($item['name']); ?>
                                    <?php if (!empty($item['weight'])): ?>
                                    <span class="text-muted small"> (<?= htmlspecialchars($item['weight']); ?>) </span>
                                    <?php endif; ?>
                                </p>
                                <?php if ($item['final_rating'] > 0): ?>
                                <span style="
                                    display:inline-flex;
                                    align-items:center;
                                    gap:4px;
                                    flex-shrink:0;
                                    background:#ffa407;
                                    color:#fff;
                                    font-size:12px;
                                    font-weight:700;
                                    padding:3px 8px 3px 6px;
                                    border-radius:20px;
                                    line-height:1.4;
                                    letter-spacing:0.3px;
                                    white-space:nowrap;
                                    margin-top:2px;
                                ">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="#fff" style="flex-shrink:0;">
                                        <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                                    </svg>
                                    <?= number_format($item['final_rating'], 1) ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <?php
                                $sp = (int)$item['simpleDiscountedPrice'];
                                $sp_rupees = $sp;
                                $gp = (int)$item['grossPrice'];
                                $dr = (int)$item['discountRate'];
                            ?>
                            <div class="price-row">
                                <?php if ($dr > 0): ?>
                                    <span class="price-discount-pill">-<?= $dr ?>%</span>
                                <?php endif; ?>
                                <span class="price-selling">
                                    <sup>₹</sup><?= $sp_rupees ?><span class="price-paise"></span>
                                </span>
                                <?php if ($gp > $sp): ?>
                                    <span class="price-mrp">₹<?= $gp ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                        <button class="add-to-cart-btn"
                                onclick='event.stopPropagation(); event.preventDefault();
                                saveToCart(<?= json_encode($cartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>);'
                                title="Add to Cart">
                            <i class="fas fa-shopping-cart"></i> Add to Cart
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="no-products" class="text-center py-5" style="display: none;">
                    <i class="bi bi-search" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="mt-3 text-muted">No products found matching your filters.</p>
                </div>
            </section>
            <!-- ===== END PRODUCTS SECTION ===== -->

        </div><!-- end .row -->
    </div><!-- end #main-body -->
</main>

<hr class="container my-5" style="border-top: 1px solid #6b6b6b;">

<!-- ================= SHOP BY PURPOSE ================= -->
<section class="container purpose-section">
    <div class="text-center mb-5">
    <h2 class="fw-bold" style="color: linear-gradient(135deg, #e91e63, #6a1b9a);">Shop By Purpose</h2>
        <p class="text-muted">
            We just made it easy for you to shop on your terms.
        </p>
    </div>

    <div class="row g-4">

        <!-- Gifting -->
        <div class="col-6 col-md-3">
            <div class="purpose-card gifting" onclick="togglePurpose(this)">
                    <div class="purpose-inner">
                        <div class="purpose-front">
                            <div class="purpose-icon-wrapper">
                                <i class="bi bi-gift"></i>
                            </div>
                            <h5 class="purpose-title">Gifting</h5>
                        </div>
                        
                        <div class="purpose-back">
                            <p class="purpose-message">
                                Wanna gift your special ones something healthy and tasty? Let’s go!
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Cooking -->
        <div class="col-6 col-md-3">
            <div class="purpose-card cooking" onclick="togglePurpose(this)">
                <div class="purpose-inner">
                    <div class="purpose-front">
                        <div class="purpose-icon-wrapper">
                            <i class="bi bi-basket"></i>
                        </div>
                        <h5 class="purpose-title">Cooking</h5>
                    </div>
                    <div class="purpose-back">
                        <p class="purpose-message">
                            Make your recipes richer and healthier with premium ingredients.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Snacking -->
        <div class="col-6 col-md-3">
            <div class="purpose-card snacking" onclick="togglePurpose(this)">
                <div class="purpose-inner">
                    <div class="purpose-front">
                        <div class="purpose-icon-wrapper">
                            <i class="bi bi-nut"></i>
                        </div>
                        <h5 class="purpose-title">Snacking</h5>
                    </div>
                    <div class="purpose-back">
                        <p class="purpose-message">
                            Healthy munching made delicious. Snack smarter every day!
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Nutrition -->
        <div class="col-6 col-md-3">
            <div class="purpose-card nutrition" onclick="togglePurpose(this)">
                <div class="purpose-inner">
                    <div class="purpose-front">
                        <div class="purpose-icon-wrapper">
                            <i class="bi bi-heart-pulse"></i>
                        </div>
                        <h5 class="purpose-title">Daily Nutrition</h5>
                    </div>
                    <div class="purpose-back">
                        <p class="purpose-message">
                            Fuel your body with essential nutrients for everyday wellness.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<hr class="container my-5" style="border-top: 1px solid #6b6b6b;">
<!-- ================= WHY CHOOSE RGreenMart ================= -->
<section class="why-choose-section py-3">
    <div class="container">
        
        <div class="text-center mb-5">
            <h2 class="fw-bold" style="color: linear-gradient(135deg, #e91e63, #6a1b9a);">Why Choose RGreenMart?</h2>
            <p class="text-muted">Premium quality. Honest pricing. Health you can trust.</p>
        </div>

        <div class="row g-4">

            <!-- 1 -->
            <div class="col-6 col-md-3">
                <div class="why-card text-center h-100">
                    <div class="why-icon">
                        <i class="bi bi-patch-check-fill"></i>
                    </div>
                    <h6 class="why-title">Premium Quality Products</h6>
                    <p class="why-text">
                        We source carefully selected dry fruits, nuts, and healthy essentials with strict quality checks to ensure purity, freshness, and superior taste in every pack.
                    </p>
                </div>
            </div>

            <!-- 2 -->
            <div class="col-6 col-md-3">
                <div class="why-card text-center h-100">
                    <div class="why-icon">
                        <i class="bi bi-tags-fill"></i>
                    </div>
                    <h6 class="why-title">Honest Pricing & Great Offers</h6>
                    <p class="why-text">
                        No inflated MRPs. Transparent pricing with genuine discounts so you always get the best value for your money.
                    </p>
                </div>
            </div>

            <!-- 3 -->
            <div class="col-6 col-md-3">
                <div class="why-card text-center h-100">
                    <div class="why-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <h6 class="why-title">Hygienic & Secure Packaging</h6>
                    <p class="why-text">
                        Every product is packed with care to preserve freshness, nutrition, and flavor — delivered safely to your doorstep.
                    </p>
                </div>
            </div>

            <!-- 4 -->
            <div class="col-6 col-md-3">
                <div class="why-card text-center h-100">
                    <div class="why-icon">
                        <i class="bi bi-heart-fill"></i>
                    </div>
                    <h6 class="why-title">Trusted Local Brand</h6>
                    <p class="why-text">
                        Serving families with healthy, premium grocery essentials. Your everyday nutrition partner for gifting, cooking, and smart snacking.
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>



<script>
function togglePurpose(card) {
    card.classList.toggle("active");
}

function toggleFilters() {
    // On desktop, sidebar is always visible — do nothing
    if (window.innerWidth >= 992) return;

    const sidebar   = document.getElementById('filter-sidebar');
    const overlay   = document.getElementById('filter-overlay');
    const masterBtn = document.getElementById('filter-master-btn');
    const isOpen    = sidebar.classList.contains('show');

    if (!isOpen) {
        sidebar.classList.add('show');
        overlay.style.display = 'block';
        document.body.style.overflow = 'hidden';
        masterBtn.classList.add('active');
        masterBtn.innerHTML = '<i class="bi bi-x-lg" style="font-size:1.5rem;"></i>';
    } else {
        sidebar.classList.remove('show');
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        masterBtn.classList.remove('active');
        masterBtn.innerHTML = '<i class="bi bi-funnel-fill" style="font-size:1.5rem;"></i>';
    }
}

function saveToCart(product) {
    product.quantity = 1;
    addToCart(product);
}

function applyFilters() {
    const searchVal = document.getElementById('filter-search').value.toLowerCase().trim();
    const priceVal  = parseFloat(document.getElementById('filter-price').value);
    document.getElementById('price-val').innerText = priceVal;

    const selectedCategories = Array.from(
        document.querySelectorAll('.category-checkbox:checked')
    ).map(cb => String(cb.value));

    const selectedBrands = Array.from(
        document.querySelectorAll('.brand-checkbox:checked')
    ).map(cb => String(cb.value));

    const offerChecked = document.querySelector('.offer-checkbox').checked;
    const cards = document.querySelectorAll('.card-container');
    let visibleCount = 0;

    cards.forEach(card => {
        // ── Product name (search) ──────────────────────────────
        const titleEl = card.querySelector('.product-title');
        const pName   = titleEl ? titleEl.innerText.toLowerCase() : '';

        // ── Category & brand from data attributes ──────────────
        const pCat   = String(card.getAttribute('data-category') || '');
        const pBrand = String(card.getAttribute('data-brand')    || '');

        // ── Price: use .price-selling (actual class in this template) ──
        const priceEl   = card.querySelector('.price-selling');
        const priceText = priceEl ? priceEl.innerText.replace(/[^\d.]/g, '') : '0';
        const pPrice    = parseFloat(priceText) || 0;

        // ── Offer: check .discount-circle-badge OR .price-discount-pill ──
        const hasOffer = card.querySelector('.discount-circle-badge') !== null
                      || card.querySelector('.price-discount-pill')   !== null;

        // ── Apply all filter conditions ──────────────────────────
        const matchSearch = searchVal === '' || pName.includes(searchVal);
        const matchCat    = selectedCategories.length === 0 || selectedCategories.includes(pCat);
        const matchBrand  = selectedBrands.length  === 0 || selectedBrands.includes(pBrand);
        const matchPrice  = isNaN(pPrice) || pPrice <= priceVal;
        const matchOffer  = !offerChecked || hasOffer;

        if (matchSearch && matchCat && matchBrand && matchPrice && matchOffer) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const noProductsMsg = document.getElementById('no-products');
    if (noProductsMsg) {
        noProductsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function clearFilters() {
    document.getElementById('filter-search').value = '';
    document.getElementById('filter-price').value = 5000;
    document.querySelectorAll('.category-checkbox, .brand-checkbox, .offer-checkbox')
        .forEach(cb => cb.checked = false);
    applyFilters();
}
</script>

<div class="partner-wrapper mt-3">
    <div class="partner-track">
        <?php foreach ($logos as $logo): ?>
            <div class="partner-logo">
                <img src="<?= $logo ?>" alt="Partner Logo">
            </div>
        <?php endforeach; ?>
        <?php foreach ($logos as $logo): ?>
            <div class="partner-logo">
                <img src="<?= $logo ?>" alt="Partner Logo">
            </div>
        <?php endforeach; ?>
    </div>
</div>

<hr class="container my-1" style="border-top: 1px solid #6b6b6b;">
<!-- ================= TESTIMONIALS ================= -->
<section class="testimonials-section py-5">
    <div class="container">

        <div class="text-center mb-5">
            <h2 class="fw-bold" style="color: linear-gradient(135deg, #e91e63, #6a1b9a);">What Our Customers Say</h2>
            <p class="text-muted">Real reviews from happy RGreenMart families.</p>
        </div>

        <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="4000">

            <div class="carousel-inner">

                <!-- Slide 1 -->
                <div class="carousel-item active">
                    <div class="row justify-content-center">
                        <div class="col-12 col-md-6">
                            <div class="testimonial-card">
                                <div class="testimonial-img">
                                    <img src="/images/female.webp" alt="Customer">
                                </div>
                                <div class="stars">★★★★★</div>
                                <p class="testimonial-text">
                                    “I’ve been ordering dry fruits from RGreenMart for months. The quality is consistently fresh and premium. Highly recommended!”
                                </p>
                                <h6 class="testimonial-name">Priya S.</h6>
                                <span class="testimonial-location">Madurai</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 2 -->
                <div class="carousel-item">
                    <div class="row justify-content-center">
                        <div class="col-12 col-md-6">
                            <div class="testimonial-card">
                                <div class="testimonial-img">
                                    <img src="/images/male.webp" alt="Customer">
                                </div>
                                <div class="stars">★★★★★</div>
                                <p class="testimonial-text">
                                    “Very transparent pricing. Almonds and cashews are top quality and perfect for daily snacking.”
                                </p>
                                <h6 class="testimonial-name">Aravind Kumar</h6>
                                <span class="testimonial-location">Chennai</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 3 -->
                <div class="carousel-item">
                    <div class="row justify-content-center">
                        <div class="col-12 col-md-6">
                            <div class="testimonial-card">
                                <div class="testimonial-img">
                                    <img src="/images/female.webp" alt="Customer">
                                </div>
                                <div class="stars">★★★★★</div>
                                <p class="testimonial-text">
                                    “Bought gift packs for a function and everyone appreciated the quality. Will definitely order again!”
                                </p>
                                <h6 class="testimonial-name">Lakshmi Narayanan</h6>
                                <span class="testimonial-location">Trichy</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Slide 4 -->
                <div class="carousel-item">
                    <div class="row justify-content-center">
                        <div class="col-12 col-md-6">
                            <div class="testimonial-card">
                                <div class="testimonial-img">
                                    <img src="/images/female.webp" alt="Customer">
                                </div>
                                <div class="stars">★★★★★</div>
                                <p class="testimonial-text">
                                    “Healthy, hygienic, and trustworthy. RGreenMart is our go-to store for monthly essentials.”
                                </p>
                                <h6 class="testimonial-name">Meena R.</h6>
                                <span class="testimonial-location">Tirunelveli</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Dot Indicators -->
            <div class="carousel-indicators" style="position:relative; bottom:unset; margin-top:1rem;">
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active" style="background-color:#064e3b; width:10px; height:10px; border-radius:50%;"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1" style="background-color:#064e3b; width:10px; height:10px; border-radius:50%;"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2" style="background-color:#064e3b; width:10px; height:10px; border-radius:50%;"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3" style="background-color:#064e3b; width:10px; height:10px; border-radius:50%;"></button>
            </div>

            <!-- Controls -->
            <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon rounded-circle p-3"></span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon rounded-circle p-3"></span>
            </button>

        </div>
    </div>
</section>

<?php require_once $_SERVER["DOCUMENT_ROOT"] ."/includes/footer.php"; ?>
<script src="/cart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.slider-container').forEach(container => {
    const images = container.querySelectorAll('.slider-img');
    const nextBtn = container.querySelector('.next');
    const prevBtn = container.querySelector('.prev');
    let current = 0;

    function showImage(index) {
        images.forEach(img => img.classList.remove('active'));
        images[index].classList.add('active');
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', (e) => {
            e.preventDefault();
            current = (current + 1) % images.length;
            showImage(current);
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', (e) => {
            e.preventDefault();
            current = (current - 1 + images.length) % images.length;
            showImage(current);
        });
    }

    let interval;
    container.addEventListener('mouseenter', () => {
        interval = setInterval(() => {
            current = (current + 1) % images.length;
            showImage(current);
        }, 1500);
    });

    container.addEventListener('mouseleave', () => {
        clearInterval(interval);
        current = 0;
        showImage(current);
    });
});
</script>

<!-- ░░░ VARIANT MODAL BACKDROP ░░░ -->
<div id="vm-backdrop" onclick="closeVariantModal()" style="
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.50);z-index:9100;
  backdrop-filter:blur(3px);
"></div>

<!-- ░░░ VARIANT MODAL ░░░ -->
<div id="vm-modal" style="
  display:none;position:fixed;bottom:0;left:50%;
  transform:translateX(-50%) translateY(100%);
  width:100%;max-width:480px;
  background:#fff;z-index:9101;
  border-radius:20px 20px 0 0;
  padding:0 0 24px;
  box-shadow:0 -12px 48px rgba(0,0,0,.16);
  transition:transform .34s cubic-bezier(.4,0,.2,1);
  font-family:Arial,sans-serif;
">
  <div style="text-align:center;padding:14px 0 2px;">
    <span style="display:inline-block;width:40px;height:4px;background:#e5e7eb;border-radius:99px;"></span>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 20px 12px;">
    <span style="font-size:16px;font-weight:700;color:#064e3b;">Select Variant</span>
    <button onclick="closeVariantModal()" style="
      background:#f3f4f6;border:none;width:32px;height:32px;
      border-radius:50%;cursor:pointer;font-size:15px;color:#555;
      display:flex;align-items:center;justify-content:center;line-height:1;
    ">&#10005;</button>
  </div>

  <!-- product preview strip -->
  <div style="
    display:flex;align-items:center;gap:12px;
    padding:10px 20px;background:#f0fdf4;
    border-top:1px solid #d1fae5;border-bottom:1px solid #d1fae5;margin-bottom:16px;
  ">
    <img id="vm-img" src="" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid #d1fae5;flex-shrink:0;">
    <div>
      <div id="vm-name" style="font-size:13px;font-weight:700;color:#064e3b;margin-bottom:3px;line-height:1.3;"></div>
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <span id="vm-old-price" style="font-size:12px;color:#9ca3af;text-decoration:line-through;display:none;"></span>
        <span id="vm-price"     style="font-size:15px;font-weight:700;color:#059669;"></span>
        <span id="vm-disc"      style="display:none;font-size:11px;font-weight:700;color:#fff;background:#e91e63;border-radius:4px;padding:1px 6px;"></span>
      </div>
    </div>
  </div>

  <div style="padding:0 20px;">
    <p style="font-size:11px;font-weight:700;color:#9ca3af;letter-spacing:.6px;text-transform:uppercase;margin:0 0 10px;">Choose Weight / Size</p>

    <!-- variant chips — each chip shows weight AND price -->
    <div id="vm-chips" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;"></div>

    <!-- qty row -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
      <span style="font-size:13px;font-weight:600;color:#475569;">Quantity:</span>
      <div style="display:flex;align-items:center;border:2px solid #059669;border-radius:8px;overflow:hidden;">
        <button onclick="vmQtyChange(-1)" class="vmqbtn">&#8722;</button>
        <span id="vm-qty-val" style="width:36px;text-align:center;font-size:14px;font-weight:700;color:#1f2937;">1</span>
        <button onclick="vmQtyChange(1)"  class="vmqbtn">+</button>
      </div>
    </div>

    <button id="vm-add-btn" onclick="vmConfirm()" disabled style="
      width:100%;padding:14px;border:none;border-radius:10px;
      background:#d1d5db;color:#fff;font-size:15px;font-weight:700;
      cursor:not-allowed;transition:all .2s;letter-spacing:.3px;
      font-family:Arial,sans-serif;
    ">Add to Cart</button>
  </div>
</div>


<!-- ░░░ CART PANEL OVERLAY ░░░ -->
<div id="cp-overlay" onclick="closeCartPanel()" style="
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.36);z-index:8100;
  backdrop-filter:blur(2px);
"></div>

<!-- ░░░ CART PANEL ░░░ -->
<div id="cart-panel" style="
  position:fixed;top:0;right:0;height:100vh;
  width:420px;max-width:100vw;
  background:#fff;z-index:8101;
  transform:translateX(105%);
  transition:transform .36s cubic-bezier(.4,0,.2,1);
  display:flex;flex-direction:column;
  box-shadow:-6px 0 40px rgba(0,0,0,.13);
  font-family:Arial,sans-serif;
">
  <!-- header -->
  <div style="
    padding:16px 18px;
    display:flex;align-items:center;justify-content:space-between;
    background: linear-gradient(135deg, #e91e63 0%, #6a1b9a 100%);
    color:#fff;flex-shrink:0;
  ">
    <div style="display:flex;align-items:center;gap:9px;">
      <i class="fas fa-shopping-cart" style="font-size:17px;"></i>
      <span style="font-size:16px;font-weight:700;">Your Cart</span>
      <span id="cp-badge" style="
        background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.35);
        border-radius:20px;padding:0 10px;font-size:13px;font-weight:700;line-height:22px;
      ">0</span>
    </div>
    <button onclick="closeCartPanel()" style="
      background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);
      color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;
      font-size:16px;display:flex;align-items:center;justify-content:center;
      transition:background .2s;
    " onmouseover="this.style.background='rgba(255,255,255,.28)'"
       onmouseout="this.style.background='rgba(255,255,255,.15)'">&#10005;</button>
  </div>

  <!-- scrollable items list -->
  <div id="cp-items" style="flex:1;overflow-y:auto;padding:12px 12px 4px;background:#f9fafb;"></div>

  <!-- order summary footer -->
  <div style="border-top:2px solid #e5e7eb;background:#fff;flex-shrink:0;padding:14px 18px 18px;">
    <div style="font-size:15px;font-weight:700;color:#064e3b;text-align:center;margin-bottom:11px;">Order Summary</div>

    <div class="cprow"><span>Total Items</span><strong id="cp-ti">0</strong></div>
    <div class="cprow"><span>Total Quantity</span><strong id="cp-tq">0</strong></div>
    <div class="cprow" style="border-bottom:none;padding-bottom:12px;">
      <span style="font-size:14px;font-weight:700;color:#1f2937;">Total Amount</span>
      <strong id="cp-gt" style="font-size:17px;color:#059669;font-weight:800;">&#8377;0.00</strong>
    </div>

    <!-- Checkout — PHP session check -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <button onclick="window.location.href='add_delivery_address.php'" class="cp-checkout-btn">
      Proceed to Checkout &#8594;
    </button>
    <?php else: ?>
    <button onclick="window.location.href='login.php'" class="cp-checkout-btn">
      Login to Checkout &#8594;
    </button>
    <?php endif; ?>

    <button onclick="window.location.href='viewcart.php'" class="cp-viewcart-btn">
      View Full Cart
    </button>
  </div>
</div>

<style>
.vm-chip {
  padding:8px 14px;border:2px solid #e5e7eb;border-radius:8px;
  cursor:pointer;background:#fff;transition:all .14s;
  font-family:Arial,sans-serif;text-align:left;
}
.vm-chip:hover { border-color:#059669;color:#059669; }
.vm-chip.active {
  border-color:#059669;background:#f0fdf4;color:#064e3b;
  box-shadow:0 0 0 3px rgba(5,150,105,.14);
}
.vm-chip-weight { font-size:13px;font-weight:700;color:inherit;display:block;line-height:1.2; }
.vm-chip-price  { font-size:12px;color:#059669;display:block;margin-top:2px;font-weight:600; }
.vm-chip.active .vm-chip-price { color:#064e3b; }

/* variant modal qty buttons */
.vmqbtn {
  background:#f0fdf4;border:none;width:32px;height:32px;cursor:pointer;
  font-size:16px;color:#059669;font-weight:700;
  display:flex;align-items:center;justify-content:center;transition:background .13s;
}
.vmqbtn:hover { background:#d1fae5; }

/* cart panel summary rows */
.cprow {
  display:flex;justify-content:space-between;align-items:center;
  padding:7px 0;border-bottom:1px solid #f3f4f6;
  font-size:14px;color:#6b7280;font-family:Arial,sans-serif;
}

/* checkout button — matches project .checkout-btn */
.cp-checkout-btn {
  width:100%;padding:13px;border:none;border-radius:4px;cursor:pointer;
  background:linear-gradient(135deg,#e91e63,#6a1b9a);
  color:#fff;font-size:15px;font-weight:600;letter-spacing:.2px;
  transition:all .25s;font-family:Arial,sans-serif;
}
.cp-checkout-btn:hover {
  background:linear-gradient(135deg,#c2185b,#4a148c);
  transform:translateY(-2px);
}
.cp-checkout-btn:active { transform:scale(0.97); }

.cp-viewcart-btn {
  width:100%;padding:10px;margin-top:7px;
  border:2px solid #059669;border-radius:4px;
  background:#fff;color:#059669;font-size:14px;font-weight:600;
  cursor:pointer;transition:background .18s;font-family:Arial,sans-serif;
}
.cp-viewcart-btn:hover { background:#f0fdf4; }

/* cart item card */
.cp-card {
  background:#fff;border-radius:10px;padding:11px 12px;
  margin-bottom:9px;display:flex;gap:11px;align-items:flex-start;
  border:1px solid #e5e7eb;
  box-shadow:0 1px 6px rgba(0,0,0,.04);transition:box-shadow .18s;
}
.cp-card:hover { box-shadow:0 3px 14px rgba(0,0,0,.08); }
.cp-card img {
  width:68px;height:68px;border-radius:7px;object-fit:cover;
  border:1px solid #d1fae5;flex-shrink:0;
}
.cp-card-body { flex:1;min-width:0; }
.cp-card-name {
  font-size:13px;font-weight:700;color:#1f2937;margin-bottom:2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.cp-variant-tag { font-size:11px;color:#6b7280;margin-bottom:4px; }
.cp-prices { display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:8px; }
.cp-fp  { font-size:14px;font-weight:700;color:#059669; }
.cp-op  { font-size:12px;color:#d1d5db;text-decoration:line-through; }
.cp-dc  { font-size:10px;font-weight:700;color:#e91e63;background:#fce7f3;border-radius:4px;padding:1px 5px; }
.cp-bot { display:flex;align-items:center;justify-content:space-between; }
.cp-qwrap {
  display:flex;align-items:center;
  border:1.5px solid #059669;border-radius:7px;overflow:hidden;
}
.cpqb {
  background:#f0fdf4;border:none;width:27px;height:27px;cursor:pointer;
  font-size:13px;color:#059669;font-weight:700;
  display:flex;align-items:center;justify-content:center;transition:background .13s;
}
.cpqb:hover { background:#d1fae5; }
.cpqi {
  width:30px;text-align:center;font-size:13px;font-weight:700;
  color:#1f2937;border:none;border-left:1.5px solid linear-gradient(135deg, #e91e63, #6a1b9a);
  border-right:1.5px solid linear-gradient(135deg, #e91e63, #6a1b9a);height:27px;background:#fff;outline:none;
  font-family:Arial,sans-serif;
}
.cp-line-total { font-size:13px;font-weight:700;color:#374151; }
.cp-rm {
  background:none;border:none;color:#dc2626;font-size:12px;
  cursor:pointer;padding:2px 5px;border-radius:4px;transition:background .13s;
  margin-top:4px;font-family:Arial,sans-serif;
}
.cp-rm:hover { background:#fee2e2; }

/* empty state */
.cp-empty {
  text-align:center;padding:60px 20px;
  font-family:Arial,sans-serif;
}
.cp-empty i   { font-size:50px;color:#d1fae5;display:block;margin-bottom:12px; }
.cp-empty p   { font-size:15px;font-weight:700;color:#6b7280;margin-bottom:4px; }
.cp-empty small { font-size:12px;color:#9ca3af; }

/* scrollbar */
#cp-items::-webkit-scrollbar { width:4px; }
#cp-items::-webkit-scrollbar-track { background:#f9fafb; }
#cp-items::-webkit-scrollbar-thumb { background:#d1fae5;border-radius:4px; }

@media (max-width:480px) {
  #cart-panel { width:100vw !important; }
  #vm-modal   { max-width:100vw; }
}
</style>


<!-- ░░░ JAVASCRIPT ░░░ -->
<script>
/* ─── VARIANT MODAL ─────────────────────────────── */
var _vmProd = null, _vmVars = [], _vmSel = null, _vmQty = 1;

/**
 * Called by "Add to Cart" on index.php product cards.
 * Fetches variants; if >1 shows modal, otherwise adds directly.
 */
function saveToCart(product) {
  fetch('/api/get_variants.php?item_id=' + encodeURIComponent(product.id))
    .then(function(r){ return r.json(); })
    .then(function(vars){
      if (vars && vars.length > 1) {
        openVarModal(product, vars);
      } else {
        if (vars && vars.length === 1) {
          var v = vars[0];
          product.variant_id        = v.id;
          product.variant_weight    = v.weight_value;
          product.variant_unit      = v.weight_unit;
          product.variant_price     = Number(v.price     || 0);
          product.variant_old_price = Number(v.old_price || 0);
          product.variant_discount  = Number(v.discount  || 0);
        }
        product.quantity = 1;
        addToCart(product);
        openCartPanel();
      }
    })
    .catch(function(){
      product.quantity = 1;
      addToCart(product);
      openCartPanel();
    });
}

function openVarModal(product, vars) {
  _vmProd = product; _vmVars = vars; _vmSel = null; _vmQty = 1;
  document.getElementById('vm-qty-val').textContent = '1';
  document.getElementById('vm-img').src = product.image || '/images/default.jpg';
  document.getElementById('vm-name').textContent = product.name;

  /* cheapest variant shown in preview */
  var cheap = vars.slice().sort(function(a,b){ return Number(a.price)-Number(b.price); })[0];
  _vmRefPrice(cheap);

  /* build chips — weight label + price label */
  var el = document.getElementById('vm-chips');
  el.innerHTML = '';
  vars.forEach(function(v){
    var wLabel = [v.weight_value, v.weight_unit].filter(Boolean).join(' ') || 'Option';
    var pLabel = '&#8377;' + Number(v.price).toFixed(0);
    var chip = document.createElement('button');
    chip.className = 'vm-chip';
    chip.innerHTML = '<span class="vm-chip-weight">' + wLabel + '</span>'
                   + '<span class="vm-chip-price">'  + pLabel + '</span>';
    chip.onclick = (function(cv, cel){ return function(){ _vmSelect(cel, cv); }; })(v, chip);
    el.appendChild(chip);
  });

  _vmBtnState(false);
  document.getElementById('vm-backdrop').style.display = 'block';
  var modal = document.getElementById('vm-modal');
  modal.style.display = 'block';
  requestAnimationFrame(function(){
    modal.style.transform = 'translateX(-50%) translateY(0)';
  });
}

function _vmSelect(chip, v) {
  document.querySelectorAll('.vm-chip').forEach(function(c){ c.classList.remove('active'); });
  chip.classList.add('active');
  _vmSel = v;
  _vmRefPrice(v);
  _vmBtnState(true);
}

function _vmRefPrice(v) {
  var p  = Number(v.price    || 0);
  var op = Number(v.old_price || 0);
  var dc = Number(v.discount  || 0);

  // Derive old_price from price + discount% when old_price is missing
  if (op <= 0 && p > 0 && dc > 0 && dc < 100) {
    op = p / (1 - dc / 100);
  }

  // Recompute discount% from actual prices (floor, always non-negative)
  if (op > p && p > 0) {
    dc = Math.floor(((op - p) / op) * 100);
  } else if (op <= p) {
    dc = 0;
  }

  document.getElementById('vm-price').innerHTML = '&#8377;' + p.toFixed(0);
  var opEl = document.getElementById('vm-old-price');
  opEl.style.display = (op > p) ? 'inline' : 'none';
  if (op > p) opEl.innerHTML = '&#8377;' + Math.round(op).toFixed(0);
  var dEl = document.getElementById('vm-disc');
  dEl.style.display = (dc > 0) ? 'inline' : 'none';
  if (dc > 0) dEl.textContent = Math.floor(dc) + '% OFF';
}

function _vmBtnState(on) {
  var btn = document.getElementById('vm-add-btn');
  btn.disabled    = !on;
  btn.style.background = on ? 'linear-gradient(135deg,#e91e63,#6a1b9a)' : '#d1d5db';
  btn.style.cursor     = on ? 'pointer' : 'not-allowed';
}

function vmQtyChange(d) {
  _vmQty = Math.max(1, _vmQty + d);
  document.getElementById('vm-qty-val').textContent = _vmQty;
}

function vmConfirm() {
  if (!_vmSel) return;

  // Derive old_price for selected variant (needed as oldamt for invoice MRP)
  var selOldPrice = Number(_vmSel.old_price || 0);
  var selDiscount = Number(_vmSel.discount  || 0);
  var selPrice    = Number(_vmSel.price     || 0);
  if (selOldPrice <= 0 && selDiscount > 0 && selDiscount < 100 && selPrice > 0) {
    selOldPrice = selPrice / (1 - selDiscount / 100);
  }

  var item = Object.assign({}, _vmProd, {
    variant_id       : _vmSel.id,
    variant_weight   : _vmSel.weight_value,
    variant_unit     : _vmSel.weight_unit,
    variant_price    : selPrice,
    variant_old_price: selOldPrice,
    variant_discount : selDiscount,
    // Override oldamt with the SELECTED variant's MRP so create_order stores the right original_price
    oldamt           : selOldPrice > 0 ? selOldPrice : selPrice,
    quantity: _vmQty, qty: _vmQty
  });
  addToCart(item);
  closeVariantModal();
  openCartPanel();
}

function closeVariantModal() {
  var m = document.getElementById('vm-modal');
  m.style.transform = 'translateX(-50%) translateY(100%)';
  setTimeout(function(){ m.style.display = 'none'; }, 350);
  document.getElementById('vm-backdrop').style.display = 'none';
}

/* ─── CART PANEL ─────────────────────────────────── */
function openCartPanel() {
  renderCP();
  document.getElementById('cart-panel').style.transform = 'translateX(0)';
  document.getElementById('cp-overlay').style.display   = 'block';
  document.body.style.overflow = 'hidden';
}
function closeCartPanel() {
  document.getElementById('cart-panel').style.transform = 'translateX(105%)';
  document.getElementById('cp-overlay').style.display   = 'none';
  document.body.style.overflow = '';
}

function renderCP() {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var box  = document.getElementById('cp-items');
  box.innerHTML = '';

  var tqAll = cart.reduce(function(s,i){ return s + Number(i.quantity != null ? i.quantity : (i.qty||0)); }, 0);
  document.getElementById('cp-badge').textContent = tqAll;

  if (!cart.length) {
    box.innerHTML = '<div class="cp-empty">'
      + '<i class="fas fa-shopping-basket"></i>'
      + '<p>Your cart is empty</p>'
      + '<small>Add products to get started!</small>'
      + '</div>';
    document.getElementById('cp-ti').textContent  = 0;
    document.getElementById('cp-tq').textContent  = 0;
    document.getElementById('cp-gt').innerHTML    = '&#8377;0.00';
    return;
  }

  var grand = 0, tq = 0;
  cart.forEach(function(item, idx) {
    // Always use variant_price as the actual selling price (authoritative from DB)
    var up = Number(item.variant_price != null ? item.variant_price : (item.price || 0));
    var qty = Number(item.quantity != null ? item.quantity : (item.qty||0));
    var lt  = up * qty;
    grand += lt; tq += qty;

    // Derive old_price for display when missing but discount% is set
    var op = Number(item.variant_old_price || 0);
    var dc = Number(item.variant_discount  || 0);
    if (op <= 0 && up > 0 && dc > 0 && dc < 100) {
      op = up / (1 - dc / 100);
    }
    // Recompute display discount% from prices (floor, non-negative)
    var displayDc = (op > up && up > 0) ? Math.floor(((op - up) / op) * 100) : 0;

    var hv  = item.variant_weight || item.variant_unit;
    var hop = op > up;
    var hdc = displayDc > 0;

    /* trash icon inline SVG */
    var trash = '<svg width="11" height="11" fill="none" stroke="#dc2626" stroke-width="2.4" viewBox="0 0 24 24">'
      + '<polyline points="3 6 5 6 21 6"/>'
      + '<path d="M19 6l-1 14H6L5 6"/>'
      + '<path d="M10 11v6M14 11v6"/>'
      + '<path d="M9 6V4h6v2"/></svg>';

    var card = document.createElement('div');
    card.className = 'cp-card';
    card.innerHTML =
      '<img src="' + (item.image||'/images/default.jpg') + '" alt="' + item.name + '" onerror="this.src=\'/images/default.jpg\'">'
      + '<div class="cp-card-body">'
        + '<div class="cp-card-name">' + item.name + '</div>'
        + (hv ? '<div class="cp-variant-tag">' + (item.variant_weight||'') + (item.variant_unit ? ' '+item.variant_unit : '') + '</div>' : '')
        + '<div class="cp-prices">'
          + '<span class="cp-fp">&#8377;' + up.toFixed(0) + '</span>'
          + (hop ? '<span class="cp-op">&#8377;' + Math.round(op).toFixed(0) + '</span>' : '')
          + (hdc ? '<span class="cp-dc">' + displayDc + '% OFF</span>' : '')
        + '</div>'
        + '<div class="cp-bot">'
          + '<div class="cp-qwrap">'
            + '<button class="cpqb" onclick="cpQ(' + idx + ',-1)">' + (qty<=1 ? trash : '&#8722;') + '</button>'
            + '<input class="cpqi" type="number" value="' + qty + '" min="1" onchange="cpSQ(' + idx + ',this.value)">'
            + '<button class="cpqb" onclick="cpQ(' + idx + ',1)">+</button>'
          + '</div>'
          + '<span class="cp-line-total">&#8377;' + lt.toFixed(2) + '</span>'
        + '</div>'
        + '<button class="cp-rm" onclick="cpRm(' + idx + ')">&#10005; Remove</button>'
      + '</div>';
    box.appendChild(card);
  });

  document.getElementById('cp-ti').textContent = cart.length;
  document.getElementById('cp-tq').textContent = tq;
  document.getElementById('cp-gt').innerHTML   = '&#8377;' + grand.toFixed(2);
  document.getElementById('cp-badge').textContent = tq;
}

function cpQ(idx, d) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var nq   = Number(cart[idx].quantity != null ? cart[idx].quantity : (cart[idx].qty||0)) + d;
  if (nq <= 0) cart.splice(idx, 1);
  else cart[idx].quantity = cart[idx].qty = nq;
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
}
function cpSQ(idx, val) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  cart[idx].quantity = cart[idx].qty = Math.max(1, Number(val));
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
}
function cpRm(idx) {
  var cart = JSON.parse(localStorage.getItem('cart')) || [];
  var nm   = cart[idx].name;
  cart.splice(idx, 1);
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount(); renderCP();
  if (typeof showToastMessage === 'function') showToastMessage(nm + ' removed');
}

document.addEventListener('DOMContentLoaded', updateCartCount);
</script>

<?php if ($popupAdEnabled && !empty($popupAdImages)): ?>
<!-- ░░░ POPUP ADVERTISEMENT ░░░ -->
<style>
/* Overlay */
#padOverlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 9500;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    padding: 20px;
}
/* Modal box */
#padBox {
    position: relative;
    width: 100%;
    max-width: 800px;
    border-radius: 10px;
    overflow: hidden;
}
/* Entrance / exit animations */
@keyframes padIn  { from{opacity:0;transform:scale(.85) translateY(28px)} to{opacity:1;transform:scale(1) translateY(0)} }
@keyframes padOut { from{opacity:1;transform:scale(1)} to{opacity:0;transform:scale(.9)} }
#padBox.entering { animation: padIn  .38s cubic-bezier(.22,1,.36,1) both; }
#padBox.leaving  { animation: padOut .22s ease forwards; }

/* The ad image */
#padImg {
    display: block;
    width: 100%;
    max-height: 90vh;
    object-fit: contain;
    transition: opacity .25s;
}

/* Top gradient so buttons are readable */
#padBox::before {
    content: '';
    position: absolute; top:0; left:0; right:0; height:90px;
    background: linear-gradient(to bottom, rgba(0,0,0,.25) 0%, transparent 100%);
    pointer-events: none; z-index: 2;
}
/* Bottom gradient for dots */
#padBox::after {
    content: '';
    position: absolute; bottom:0; left:0; right:0; height:70px;
    background: linear-gradient(to top, rgba(0,0,0,.25) 0%, transparent 100%);
    pointer-events: none; z-index: 2;
}

/* ❌ Close button */
#padClose {
    position: absolute; top:14px; right:14px; z-index:10;
    width:32px; height:32px;
    border-radius:50%; border:2px solid rgba(255,255,255,0.6);
    background: rgba(0,0,0,.35);
    color:#fff; font-size:17px; font-weight:700;
    cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition: background .2s, border-color .2s, transform .15s;
    backdrop-filter: blur(4px);
    line-height:1;
}
#padClose:hover {
    background: rgba(220,38,38,.9);
    border-color: rgba(220,38,38,.9);
    transform: scale(1.1);
}

/* "1 of 3" counter top-left */
#padCounter {
    position: absolute; top:17px; left:16px; z-index:10;
    background: rgba(0,0,0,.35);
    color:#fff;
    font-size:11px; font-weight:700; letter-spacing:.5px;
    padding:4px 11px; border-radius:999px;
    backdrop-filter:blur(4px);
    font-family:'Poppins',sans-serif;
}

/* Left / Right arrow nav buttons */
.pad-arrow {
    position: absolute; top:50%; transform:translateY(-50%);
    z-index:10;
    width:38px; height:38px;
    border-radius:50%;
    border:2px solid rgba(255,255,255,0.55);
    background: rgba(0,0,0,.35);
    color:#fff; font-size:20px;
    cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition: background .2s, transform .15s, opacity .2s;
    backdrop-filter: blur(4px);
    user-select: none;
}
.pad-arrow:hover { background: rgba(0,0,0,.65); transform:translateY(-50%) scale(1.1); }
.pad-arrow.pad-hidden { opacity:0; pointer-events:none; }
#padPrev { left:12px; }
#padNext { right:12px; }

/* Dot indicators bottom-center */
#padDots {
    position: absolute; bottom:14px; left:50%; transform:translateX(-50%);
    z-index:10;
    display:flex; gap:6px; align-items:center;
}
.pad-dot {
    height:7px; border-radius:4px;
    background:rgba(255,255,255,.35);
    transition: width .3s, background .25s;
    width:7px;
    cursor:pointer;
}
.pad-dot.on { background:#fff; width:20px; }

/* Auto-slide progress bar (thin line at very bottom) */
#padProgress {
    position: absolute; bottom:0; left:0;
    height:3px;
    background: linear-gradient(90deg, #e91e63, #6a1b9a);
    width:0%; z-index:11;
    transition: width linear;
}
</style>

<script>
var _padImgs          = <?= json_encode(array_values(array_map(fn($p) => '/' . ltrim($p, '/'), $popupAdImages)), JSON_UNESCAPED_SLASHES) ?>;
var _padIntervalHours = <?= (int)($settings['popup_interval_hours'] ?? 6) ?>;
var _padAutoSlideMs   = 2000;
</script>

<div id="padOverlay">
    <div id="padBox">

        <!-- "1 of N" counter (only if multiple images) -->
        <?php if (count($popupAdImages) > 1): ?>
        <div id="padCounter">
            <span id="padNum">1</span> of <?= count($popupAdImages) ?>
        </div>
        <?php endif; ?>

        <!-- ❌ Close -->
        <button id="padClose" onclick="padDismiss()" aria-label="Close">&#10005;</button>

        <!-- ◀ Prev arrow (only if multiple images) -->
        <?php if (count($popupAdImages) > 1): ?>
        <button class="pad-arrow pad-hidden" id="padPrev" onclick="padNav(-1)" aria-label="Previous">&#8249;</button>
        <?php endif; ?>

        <!-- Ad image -->
        <img id="padImg" src="" alt="Advertisement">

        <!-- ▶ Next arrow (only if multiple images) -->
        <?php if (count($popupAdImages) > 1): ?>
        <button class="pad-arrow" id="padNext" onclick="padNav(1)" aria-label="Next">&#8250;</button>
        <?php endif; ?>

        <!-- Dot indicators (only if multiple images) -->
        <?php if (count($popupAdImages) > 1): ?>
        <div id="padDots">
            <?php for ($di = 0; $di < count($popupAdImages); $di++): ?>
            <div class="pad-dot<?= $di === 0 ? ' on' : '' ?>" onclick="padGoTo(<?= $di ?>)"></div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <!-- Auto-slide progress bar -->
        <div id="padProgress"></div>

    </div>
</div>

<script>
(function(){
    /* ── config ── */
    var STORAGE_KEY   = 'rgm_pad_last_shown';
    var imgs          = _padImgs;
    var total         = imgs.length;
    var intervalHours = _padIntervalHours;   /* 0 = always show */
    var autoMs        = _padAutoSlideMs;     /* ms per slide    */

    /* ── state ── */
    var cur       = 0;
    var busy      = false;
    var autoTimer = null;

    /* ── DOM ── */
    var overlay = document.getElementById('padOverlay');
    var box     = document.getElementById('padBox');
    var imgEl   = document.getElementById('padImg');
    var numEl   = document.getElementById('padNum');
    var dots    = document.querySelectorAll('.pad-dot');
    var prevBtn = document.getElementById('padPrev');
    var nextBtn = document.getElementById('padNext');
    var progBar = document.getElementById('padProgress');

    /* ── localStorage: should we show? ── */
    function shouldShow() {
        if (intervalHours <= 0) return true;
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (!stored) return true;
            var elapsed = (Date.now() - parseInt(stored, 10)) / 3600000;
            return elapsed >= intervalHours;
        } catch(e) { return true; }
    }
    function recordShown() {
        try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch(e) {}
    }

    /* ── progress bar ── */
    function resetProgress() {
        if (!progBar || total < 2) return;
        progBar.style.transition = 'none';
        progBar.style.width = '0%';
        void progBar.offsetWidth; /* force reflow */
        progBar.style.transition = 'width ' + (autoMs / 1000) + 's linear';
        progBar.style.width = '100%';
    }

    /* ── arrow visibility ── */
    function updateArrows() {
        if (!prevBtn || !nextBtn) return;
        prevBtn.classList.toggle('pad-hidden', cur === 0);
        nextBtn.classList.toggle('pad-hidden', cur === total - 1);
    }

    /* ── show slide ── */
    function show(idx, firstOpen) {
        if (idx < 0 || idx >= total) return;
        cur = idx;

        if (numEl) numEl.textContent = idx + 1;
        dots.forEach(function(d,i){ d.classList.toggle('on', i === idx); });
        updateArrows();

        imgEl.style.opacity = '0';
        imgEl.src = imgs[idx];
        imgEl.onload = function(){ imgEl.style.opacity = '1'; };

        if (firstOpen) {
            box.classList.remove('leaving');
            box.classList.add('entering');
            overlay.style.display = 'flex';
        }

        /* restart auto-slide */
        clearInterval(autoTimer);
        resetProgress();
        if (total > 1) {
            autoTimer = setInterval(function(){
                show((cur + 1) % total, false); /* loop */
            }, autoMs);
        }
    }

    /* ── manual nav ── */
    window.padNav = function(dir){
        var next = cur + dir;
        if (next < 0 || next >= total) return;
        show(next, false);
    };
    window.padGoTo = function(idx){ show(idx, false); };

    /* ── close ── */
    window.padDismiss = function(){
        if (busy) return;
        busy = true;
        clearInterval(autoTimer);
        box.classList.remove('entering');
        box.classList.add('leaving');
        setTimeout(function(){
            overlay.style.display = 'none';
            box.classList.remove('leaving');
            busy = false;
        }, 240);
    };

    /* ── backdrop click ── */
    overlay.addEventListener('click', function(e){
        if (e.target === overlay) padDismiss();
    });

    /* ── keyboard ── */
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape')      padDismiss();
        if (e.key === 'ArrowLeft')   padNav(-1);
        if (e.key === 'ArrowRight')  padNav(1);
    });

    /* ── pause on hover ── */
    box.addEventListener('mouseenter', function(){
        clearInterval(autoTimer);
        if (progBar) progBar.style.transition = 'none';
    });
    box.addEventListener('mouseleave', function(){
        if (overlay.style.display === 'none') return;
        resetProgress();
        if (total > 1) {
            autoTimer = setInterval(function(){
                show((cur + 1) % total, false);
            }, autoMs);
        }
    });

    /* ── kick-off ── */
    setTimeout(function(){
        if (!shouldShow()) return;
        recordShown();
        show(0, true);
    }, 600);
})();
</script>
<?php endif; ?>

</body>
</html>