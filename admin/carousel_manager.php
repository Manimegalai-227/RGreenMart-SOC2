<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// ---------------------- DELETE IMAGE ----------------------
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Only delete carousel-type rows (never touch popup rows)
    $stmt = $conn->prepare("SELECT image_path FROM site_images WHERE id = ? AND type = 'carousel'");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        $file = $_SERVER["DOCUMENT_ROOT"] . "/" . $data['image_path'];
        if (file_exists($file)) unlink($file);

        $del = $conn->prepare("DELETE FROM site_images WHERE id = ? AND type = 'carousel'");
        $del->execute([$id]);
    }

    header("Location: carousel_manager.php");
    exit;
}

// ---------------------- UPLOAD IMAGE ----------------------
if (isset($_POST['upload'])) {
    if (isset($_FILES['carousel_image']) && $_FILES['carousel_image']['error'] === 0) {
        $tmpPath = $_FILES['carousel_image']['tmp_name'];
        $imgInfo = @getimagesize($tmpPath);

        $cdStmt = $conn->prepare("SELECT width, height FROM image_settings WHERE type = 'carousel' LIMIT 1");
        $cdStmt->execute();
        $cdRow = $cdStmt->fetch(PDO::FETCH_ASSOC);
        $reqW  = $cdRow ? (int)$cdRow['width']  : null;
        $reqH  = $cdRow ? (int)$cdRow['height'] : null;

        $uploadError = '';
        if ($imgInfo === false) {
            $uploadError = "Invalid file. Please upload a valid image (JPG, PNG, WEBP).";
        } elseif ($reqW !== null && $reqH !== null) {
            if ((int)$imgInfo[0] !== $reqW || (int)$imgInfo[1] !== $reqH) {
                $uploadError = "Invalid image dimensions. Required: {$reqW} × {$reqH}px. "
                    . "Your image is " . (int)$imgInfo[0] . " × " . (int)$imgInfo[1] . "px.";
            }
        }

        if ($uploadError) {
            $_SESSION['carousel_upload_error'] = $uploadError;
        } else {
            $uploadDir = $_SERVER["DOCUMENT_ROOT"] . "/images/";
            $dbPath = "images/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $ext = pathinfo($_FILES['carousel_image']['name'], PATHINFO_EXTENSION);
            $newName = uniqid("banner_", true) . "." . $ext;
            $filePath = $uploadDir . $newName;

            if (move_uploaded_file($tmpPath, $filePath)) {
                // Get max sort_order among carousel rows only
                $orderStmt = $conn->query(
                    "SELECT MAX(sort_order) AS m FROM site_images WHERE type = 'carousel'"
                );
                $lastOrder = $orderStmt->fetch(PDO::FETCH_ASSOC)['m'] ?? 0;

                // type='carousel', status=1 (visible), sort_order = next in sequence
                $stmt = $conn->prepare(
                    "INSERT INTO site_images (image_path, type, status, sort_order) VALUES (?, 'carousel', 1, ?)"
                );
                $stmt->execute([$dbPath . $newName, $lastOrder + 1]);
                $_SESSION['carousel_upload_success'] = "Carousel image uploaded successfully.";
            }
        }
    }

    header("Location: carousel_manager.php");
    exit;
}

// ---------------------- UPDATE ORDER (DRAG & DROP) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    $data = json_decode($_POST['reorder'], true);
    if ($data && is_array($data)) {
        foreach ($data as $item) {
            // Only reorder carousel rows
            $stmt = $conn->prepare(
                "UPDATE site_images SET sort_order = ? WHERE id = ? AND type = 'carousel'"
            );
            $stmt->execute([intval($item['sort_order']), intval($item['id'])]);
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// ---------------------- FETCH CAROUSEL IMAGES ONLY ----------------------
$stmt = $conn->prepare(
    "SELECT * FROM site_images WHERE type = 'carousel' ORDER BY sort_order ASC"
);
$stmt->execute();
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------- CAROUSEL DIMENSION SETTINGS ----------------------
$carDimStmt = $conn->prepare("SELECT width, height FROM image_settings WHERE type = 'carousel' LIMIT 1");
$carDimStmt->execute();
$carDimRow      = $carDimStmt->fetch(PDO::FETCH_ASSOC);
$carouselWidth  = $carDimRow ? (int)$carDimRow['width']  : null;
$carouselHeight = $carDimRow ? (int)$carDimRow['height'] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Carousel Manager</title>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    body { font-family: 'Poppins', sans-serif; }
    .admin-main { margin-left: 3rem; }
    .active-settings-tab {
        background: linear-gradient(135deg, #4f46e5, #6d28d9);
        color: #fff !important;
        box-shadow: 0 2px 8px rgba(79,70,229,0.35);
    }
    .settings-page-tab { color: #6b7280; }
    @media (max-width: 768px) { .admin-main { margin-left: 0 !important; } }
</style>
</head>
<body class="bg-gray-100">
    <div class="admin-container flex">
        <?php require_once './common/admin_sidebar.php'; ?>
        <main class="admin-main flex-1 p-6">
<div class="container mx-auto max-w-4xl p-6 bg-white rounded-lg shadow-lg mt-10">
<h2 class="text-2xl font-bold text-indigo-600 mb-4">Carousel Manager</h2>

<!-- UPLOAD -->
<div class="mb-6">
    <h2 class="text-xl font-semibold mb-3">Upload New Image</h2>

    <?php
    $carUploadError   = $_SESSION['carousel_upload_error']   ?? '';
    $carUploadSuccess = $_SESSION['carousel_upload_success'] ?? '';
    unset($_SESSION['carousel_upload_error'], $_SESSION['carousel_upload_success']);
    ?>

    <?php if ($carUploadSuccess): ?>
    <div class="flex items-center gap-2 mb-3 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
        <?= htmlspecialchars($carUploadSuccess) ?>
    </div>
    <?php endif; ?>

    <?php if ($carUploadError): ?>
    <div class="flex items-start gap-2 mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
        </svg>
        <?= htmlspecialchars($carUploadError) ?>
    </div>
    <?php endif; ?>

    <?php if ($carouselWidth && $carouselHeight): ?>
    <div class="flex items-start gap-3 mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
        </svg>
        <p class="text-sm text-blue-700">
            <strong>Required size:</strong>
            <span class="font-mono bg-blue-100 px-1.5 py-0.5 rounded text-blue-800"><?= $carouselWidth ?> &times; <?= $carouselHeight ?> pixels</span>.
            Images that don&rsquo;t match this size will be rejected.
            To change dimensions, go to
            <a href="Setting.php" class="underline font-semibold hover:text-blue-900">Image Settings → Carousel Image</a>.
        </p>
    </div>
    <?php else: ?>
    <div class="flex items-start gap-3 mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
        <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
        </svg>
        <p class="text-sm text-amber-700">
            <strong>No carousel dimensions set yet.</strong>
            Any image size is accepted. To enforce a required size, set <strong>Carousel Image</strong> dimensions in
            <a href="Setting.php" class="underline font-semibold hover:text-amber-900">Image Settings</a>.
        </p>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
        <input type="file" name="carousel_image" accept="image/*" required
               class="border p-2 rounded text-sm text-gray-600 bg-white file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-indigo-600 file:text-white file:font-semibold hover:file:bg-indigo-700 file:cursor-pointer transition">
        <button type="submit" name="upload"
                class="bg-indigo-500 text-white px-4 py-2 rounded hover:bg-indigo-600 font-semibold text-sm transition">
            Upload
        </button>
    </form>
</div>

<!-- DRAG & DROP LIST -->
<h2 class="text-xl font-semibold mb-2">Reorder Carousel Images</h2>
<p class="text-gray-600 mb-4">Drag and drop the images to reorder them. Changes will be saved automatically.</p>

<ul id="carouselList" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
<?php foreach ($images as $img): ?>
    <li class="bg-white p-4 rounded shadow cursor-move flex flex-col items-center" data-id="<?= $img['id'] ?>">
        <img src="../<?= htmlspecialchars($img['image_path']) ?>" class="w-full h-48 object-cover rounded mb-2">
        <a href="?delete=<?= $img['id'] ?>" class="text-red-500 hover:underline font-bold"
           onclick="return confirm('Delete this carousel image?')">Delete</a>
    </li>
<?php endforeach; ?>
</ul>
</div>
</main>
</div>
<script>
const el = document.getElementById('carouselList');
const sortable = Sortable.create(el, {
    animation: 150,
    onEnd: function(evt) {
        const items = el.querySelectorAll('li');
        let order = [];
        items.forEach((item, index) => {
            order.push({ id: item.getAttribute('data-id'), sort_order: index + 1 });
        });
        const formData = new FormData();
        formData.append('reorder', JSON.stringify(order));
        fetch('carousel_manager.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { if (data.success) console.log("Order updated"); });
    }
});
</script>
</body>
</html>