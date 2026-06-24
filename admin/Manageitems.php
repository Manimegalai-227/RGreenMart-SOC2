<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

/* ---------------------------------------
   DELETE HANDLER (AJAX)
---------------------------------------- */
if (isset($_POST['delete'])) {
    $id = intval($_POST['id']);
    $message = '';
    $message_class = '';

    if ($id <= 0) {
        $message = 'Invalid item ID.';
        $message_class = 'error';
    } else {
        try {
            // 1. Get associated image paths BEFORE deleting any DB records
            $stmt = $conn->prepare("SELECT image_path, compressed_path FROM item_images WHERE item_id=?");
            $stmt->execute([$id]);
            $imgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Delete ALL child/dependent rows in correct FK dependency order:
            //    order_items → item_variants → item_images → items (parent last)

            $stmt = $conn->prepare("DELETE FROM order_items WHERE item_id=?");
            $stmt->execute([$id]);

            $stmt = $conn->prepare("DELETE FROM item_variants WHERE item_id=?");
            $stmt->execute([$id]);

            $stmt = $conn->prepare("DELETE FROM item_images WHERE item_id=?");
            $stmt->execute([$id]);

            // 3. Now safe to delete the parent record
            $stmt = $conn->prepare("DELETE FROM items WHERE id=?");
            $stmt->execute([$id]);

            // 4. Delete image files from disk
            foreach ($imgs as $imgRow) {
                foreach (['image_path', 'compressed_path'] as $p) {
                    if (empty($imgRow[$p])) continue;
                    $full = $_SERVER['DOCUMENT_ROOT'] . "/admin/" . ltrim($imgRow[$p], "/");
                    if (file_exists($full)) {
                        @unlink($full);
                    }
                }
            }

            // 5. Attempt to remove the item's upload directory
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/admin/Uploads/" . $id;
            if (is_dir($uploadDir)) {
                $it = new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isDir()) rmdir($file->getRealPath()); else unlink($file->getRealPath());
                }
                @rmdir($uploadDir);
            }

            $message = 'Item deleted successfully.';
            $message_class = 'success';
        } catch (Exception $e) {
            $message = 'Error deleting item: ' . $e->getMessage();
            $message_class = 'error';
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => $message_class, 'message' => $message]);
        exit;
    }
}

/* ---------------------------------------
   AJAX Endpoints
---------------------------------------- */
if (isset($_GET['action']) && $_GET['action'] === 'fetch_variants' && isset($_GET['id'])) {
    $itemId = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT id, weight_value, weight_unit, price, old_price, discount, stock, status FROM item_variants WHERE item_id = ? ORDER BY id ASC");
    $stmt->execute([$itemId]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

/* ---------------------------------------
   FETCH DATA
---------------------------------------- */
$items = $conn->query("
    SELECT items.*, categories.name AS category_name, brands.name AS brand_name,
        (
            SELECT COALESCE(compressed_path, image_path) FROM item_images
            WHERE item_images.item_id = items.id
            ORDER BY is_primary DESC, sort_order ASC LIMIT 1
        ) AS thumb_image,
        (SELECT MIN(price) FROM item_variants WHERE item_variants.item_id = items.id) AS min_price,
        (SELECT MAX(price) FROM item_variants WHERE item_variants.item_id = items.id) AS max_price,
        (SELECT COALESCE(SUM(stock),0) FROM item_variants WHERE item_variants.item_id = items.id) AS total_stock,
        (SELECT COUNT(*) FROM item_variants WHERE item_variants.item_id = items.id) AS variants_count
    FROM items
    LEFT JOIN categories ON items.category_id=categories.id
    LEFT JOIN brands ON items.brand_id=brands.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as &$it) {
    $stmt = $conn->prepare("SELECT id, price, old_price FROM item_variants WHERE item_id = ? ORDER BY id ASC LIMIT 2");
    $stmt->execute([$it['id']]);
    $it['variant_preview'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($it);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Items</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Poppins', sans-serif; }

    /* Horizontal scroll container */
    .table-scroll { overflow-x: auto; }

    /* Fixed layout — columns never squeeze each other */
    table { table-layout: fixed; min-width: 1800px; border-collapse: collapse; }

    /* All cells: single line, no wrapping, ellipsis for overflow */
    thead th, tbody td {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
        padding: 10px 12px;
        font-size: 12.5px;
    }
    thead th {
        text-align: left;
        background: #6366f1;
        color: #fff;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    tbody tr:nth-child(even) { background: #f8f9ff; }
    tbody tr:hover { background: #eef0ff !important; transition: background 0.15s; }
    tbody td { border-bottom: 1px solid #e5e7eb; }

    /* Column widths */
    th:nth-child(1),  td:nth-child(1)  { width: 130px; } /* Name */
    th:nth-child(2),  td:nth-child(2)  { width: 110px; } /* Price Range */
    th:nth-child(3),  td:nth-child(3)  { width: 75px;  } /* Total Stock */
    th:nth-child(4),  td:nth-child(4)  { width: 100px; } /* Variants */
    th:nth-child(5),  td:nth-child(5)  { width: 90px;  } /* Category */
    th:nth-child(6),  td:nth-child(6)  { width: 80px;  } /* Brand */
    th:nth-child(7),  td:nth-child(7)  { width: 100px; } /* Packaging */
    th:nth-child(8),  td:nth-child(8)  { width: 70px;  } /* Form */
    th:nth-child(9),  td:nth-child(9)  { width: 70px;  } /* Origin */
    th:nth-child(10), td:nth-child(10) { width: 110px; } /* Grade */
    th:nth-child(11), td:nth-child(11) { width: 60px;  } /* Purity */
    th:nth-child(12), td:nth-child(12) { width: 70px;  } /* Flavor */
    th:nth-child(13), td:nth-child(13) { width: 75px;  } /* Shelf Life */
    th:nth-child(14), td:nth-child(14) { width: 140px; } /* Description */
    th:nth-child(15), td:nth-child(15) { width: 130px; } /* Nutrition */
    th:nth-child(16), td:nth-child(16) { width: 100px; } /* Expiry Info */
    th:nth-child(17), td:nth-child(17) { width: 110px; } /* Tags */
    th:nth-child(18), td:nth-child(18) { width: 120px; } /* Storage */
    th:nth-child(19), td:nth-child(19) { width: 60px;  } /* Image */
    th:nth-child(20), td:nth-child(20) { width: 120px; } /* Actions */

    /* Stock badge */
    .stock-badge {
        display: inline-block;
        min-width: 32px;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
    }
    .stock-ok   { background: #dcfce7; color: #15803d; }
    .stock-low  { background: #fef9c3; color: #854d0e; }
    .stock-none { background: #fee2e2; color: #b91c1c; }

    /* Grade badge — replaces underscores with spaces */
    .grade-badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        background: #ede9fe;
        color: #5b21b6;
        max-width: 100%;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        cursor: default;
    }

    /* Variant price preview */
    .variant-list { font-size: 11px; color: #6b7280; line-height: 1.5; }
    .variant-list s { color: #d1d5db; }

    /* Action buttons */
    .action-btn-wrap { display: flex; gap: 5px; align-items: center; }
    .btn-edit   { background:#4f46e5; color:#fff; padding:3px 10px; border-radius:5px; font-size:11.5px; font-weight:600; text-decoration:none; white-space:nowrap; }
    .btn-edit:hover { background:#4338ca; color:#fff; }
    .btn-delete { background:#dc2626; color:#fff; padding:3px 10px; border-radius:5px; font-size:11.5px; font-weight:600; border:none; cursor:pointer; white-space:nowrap; }
    .btn-delete:hover { background:#b91c1c; }

    /* Confirm Modal */
    #confirm-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(30,27,75,0.45);
        backdrop-filter: blur(3px);
        z-index: 9999; align-items: center; justify-content: center;
    }
    #confirm-overlay.show { display: flex; }
    #confirm-box {
        background: #fff; border-radius: 14px; padding: 32px 28px 24px;
        max-width: 420px; width: 90%;
        box-shadow: 0 20px 60px rgba(99,102,241,0.18);
        text-align: center;
        animation: popIn .22s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes popIn {
        from { transform: scale(0.85); opacity: 0; }
        to   { transform: scale(1);    opacity: 1; }
    }
    .confirm-icon {
        width: 52px; height: 52px; background: #fee2e2; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 16px; font-size: 24px;
    }
    #confirm-box h3 { font-size: 16px; font-weight: 700; color: #1e1b4b; margin-bottom: 6px; }
    #confirm-box p  { font-size: 13px; color: #6b7280; margin-bottom: 22px; line-height: 1.5; }
    #confirm-box p strong { color: #374151; }
    .confirm-actions { display: flex; gap: 10px; justify-content: center; }
    .btn-cancel-modal {
        flex: 1; padding: 9px 0; border-radius: 8px; font-size: 13px; font-weight: 600;
        border: 1.5px solid #e5e7eb; background: #f9fafb; color: #374151; cursor: pointer;
    }
    .btn-cancel-modal:hover { background: #f3f4f6; }
    .btn-confirm-delete {
        flex: 1; padding: 9px 0; border-radius: 8px; font-size: 13px; font-weight: 600;
        border: none; background: #dc2626; color: #fff; cursor: pointer;
    }
    .btn-confirm-delete:hover { background: #b91c1c; }

    /* Toast notification */
    #toast-container {
        position: fixed; top: 22px; right: 22px; z-index: 99999;
        display: flex; flex-direction: column; gap: 10px;
    }
    .toast {
        display: flex; align-items: center; gap: 12px;
        padding: 13px 18px; border-radius: 10px;
        font-size: 13px; font-weight: 600;
        min-width: 280px; max-width: 380px;
        box-shadow: 0 8px 28px rgba(0,0,0,0.13);
        animation: slideIn .28s ease;
    }
    @keyframes slideIn {
        from { transform: translateX(120%); opacity: 0; }
        to   { transform: translateX(0);    opacity: 1; }
    }
    .toast.fadeOut { animation: slideOut .35s ease forwards; }
    @keyframes slideOut { to { transform: translateX(120%); opacity: 0; } }
    .toast.success { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
    .toast.error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
    .toast-icon { font-size: 18px; flex-shrink: 0; }
    .toast-msg  { flex: 1; }
</style>
</head>
<body class="bg-gray-100">

<div class="admin-container flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="flex-1 p-6">
        <div class="container mx-auto max-w-7xl p-6 bg-white rounded-lg shadow-lg mt-4 min-h-[80vh]">
            <h2 class="text-2xl font-bold text-indigo-600 mb-4">Manage Items</h2>

            <!-- (messages now shown as toasts — no inline container needed) -->

            <!-- Search bar -->
            <div class="mb-4">
                <input type="text" id="searchInput"
                    placeholder="Search by name, category, brand, tags..."
                    class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
            </div>

            <div class="table-scroll shadow-md rounded-lg">
            <table id="itemsTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Price Range</th>
                        <th>Total Stock</th>
                        <th>Variants</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Packaging</th>
                        <th>Form</th>
                        <th>Origin</th>
                        <th>Grade</th>
                        <th>Purity</th>
                        <th>Flavor</th>
                        <th>Shelf Life</th>
                        <th>Description</th>
                        <th>Nutrition</th>
                        <th>Expiry Info</th>
                        <th>Tags</th>
                        <th>Storage</th>
                        <th>Image</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $item):
                        $stock        = intval($item['total_stock']);
                        $stockClass   = $stock === 0 ? 'stock-none' : ($stock <= 5 ? 'stock-low' : 'stock-ok');
                        $gradeDisplay = str_replace('_', ' ', $item['grade'] ?? '');
                        $searchStr    = strtolower(
                            ($item['name'] ?? '') . ' ' .
                            ($item['category_name'] ?? '') . ' ' .
                            ($item['brand_name'] ?? '') . ' ' .
                            ($item['tags'] ?? '') . ' ' .
                            ($item['origin'] ?? '') . ' ' .
                            ($item['flavor'] ?? '')
                        );
                    ?>
                    <tr class="item-row" data-search="<?= htmlspecialchars($searchStr) ?>">

                        <!-- Name -->
                        <td title="<?= htmlspecialchars($item['name']) ?>" class="font-semibold text-gray-800">
                            <?= htmlspecialchars($item['name']) ?>
                        </td>

                        <!-- Price Range -->
                        <td class="font-semibold text-indigo-700">
                            <?php if ($item['min_price'] !== null) {
                                echo '₹' . $item['min_price'];
                                if ($item['max_price'] !== null && $item['max_price'] != $item['min_price'])
                                    echo ' – ₹' . $item['max_price'];
                            } else {
                                echo '<span style="color:#9ca3af">N/A</span>';
                            } ?>
                        </td>

                        <!-- Total Stock -->
                        <td style="text-align:center;">
                            <span class="stock-badge <?= $stockClass ?>"><?= $stock ?></span>
                        </td>

                        <!-- Variants -->
                        <td>
                            <span style="font-weight:700;"><?= $item['variants_count'] ?></span>
                            <div class="variant-list">
                                <?php foreach($item['variant_preview'] as $vp): ?>
                                    <div>₹<?= $vp['price'] ?><?php if ($vp['old_price']) echo ' <s>₹' . $vp['old_price'] . '</s>'; ?></div>
                                <?php endforeach; ?>
                            </div>
                        </td>

                        <!-- Category -->
                        <td data-category-id="<?= $item['category_id'] ?>"
                            title="<?= htmlspecialchars($item['category_name'] ?? '') ?>">
                            <?= htmlspecialchars($item['category_name'] ?? '—') ?>
                        </td>

                        <!-- Brand -->
                        <td data-brand-id="<?= $item['brand_id'] ?>"
                            title="<?= htmlspecialchars($item['brand_name'] ?? '') ?>">
                            <?= htmlspecialchars($item['brand_name'] ?? '—') ?>
                        </td>

                        <!-- Packaging -->
                        <td title="<?= htmlspecialchars($item['packaging_type'] ?? '') ?>">
                            <?= htmlspecialchars($item['packaging_type'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Form -->
                        <td><?= htmlspecialchars($item['product_form'] ?? '') ?: '—' ?></td>

                        <!-- Origin -->
                        <td><?= htmlspecialchars($item['origin'] ?? '') ?: '—' ?></td>

                        <!-- Grade -->
                        <td title="<?= htmlspecialchars($gradeDisplay) ?>">
                            <?php if ($gradeDisplay): ?>
                                <span class="grade-badge"><?= htmlspecialchars($gradeDisplay) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>

                        <!-- Purity -->
                        <td><?= htmlspecialchars($item['purity'] ?? '') ?: '—' ?></td>

                        <!-- Flavor -->
                        <td><?= htmlspecialchars($item['flavor'] ?? '') ?: '—' ?></td>

                        <!-- Shelf Life -->
                        <td><?= htmlspecialchars($item['shelf_life'] ?? '') ?: '—' ?></td>

                        <!-- Description -->
                        <td class="description-cell"
                            title="<?= htmlspecialchars($item['description'] ?? '') ?>">
                            <?= htmlspecialchars($item['description'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Nutrition -->
                        <td class="nutrition-cell"
                            title="<?= htmlspecialchars($item['nutrition'] ?? '') ?>">
                            <?= htmlspecialchars($item['nutrition'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Expiry Info -->
                        <td class="expiry-info-cell"
                            title="<?= htmlspecialchars($item['expiry_info'] ?? '') ?>">
                            <?= htmlspecialchars($item['expiry_info'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Tags -->
                        <td class="tags-cell"
                            title="<?= htmlspecialchars($item['tags'] ?? '') ?>">
                            <?= htmlspecialchars($item['tags'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Storage -->
                        <td class="storage-instructions-cell"
                            title="<?= htmlspecialchars($item['storage_instructions'] ?? '') ?>">
                            <?= htmlspecialchars($item['storage_instructions'] ?? '') ?: '—' ?>
                        </td>

                        <!-- Image -->
                        <td class="image-cell" style="text-align:center;">
                            <?php
                                $shown = false;
                                if (!empty($item['thumb_image'])) {
                                    $thumbFull = $_SERVER['DOCUMENT_ROOT'] . "/admin/" . ltrim($item['thumb_image'], '/');
                                    if (file_exists($thumbFull)) {
                                        echo '<img src="/admin/' . htmlspecialchars($item['thumb_image'], ENT_QUOTES) . '" style="width:40px;height:40px;object-fit:cover;border-radius:6px;display:block;margin:auto;">';
                                        $shown = true;
                                    }
                                }
                                if (!$shown && !empty($item['image'])) {
                                    $fallbackFull = $_SERVER['DOCUMENT_ROOT'] . "/admin/" . ltrim($item['image'], '/');
                                    if (file_exists($fallbackFull)) {
                                        echo '<img src="/admin/' . htmlspecialchars($item['image'], ENT_QUOTES) . '" style="width:40px;height:40px;object-fit:cover;border-radius:6px;display:block;margin:auto;">';
                                        $shown = true;
                                    }
                                }
                                if (!$shown) echo '<span style="font-size:11px;color:#9ca3af;">No Image</span>';
                            ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div class="action-btn-wrap">
                                <a href="edit_item.php?id=<?= $item['id'] ?>" class="btn-edit">Edit</a>
                                <form method="POST" class="delete-form" style="display:inline;"
                                      data-item-name="<?= htmlspecialchars($item['name']) ?>">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <!-- FIX: Added value="1" so FormData includes the delete field -->
                                    <button type="submit" name="delete" value="1" class="btn-delete">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /table-scroll -->

        </div>
    </main>
</div>

<!-- Confirm Delete Modal -->
<div id="confirm-overlay">
    <div id="confirm-box">
        <div class="confirm-icon">🗑️</div>
        <h3>Delete Item?</h3>
        <p>You are about to permanently delete <strong id="confirm-item-name"></strong>.<br>This will also remove all variants, images and order records. This cannot be undone.</p>
        <div class="confirm-actions">
            <button class="btn-cancel-modal" id="modal-cancel-btn">Cancel</button>
            <button class="btn-confirm-delete" id="modal-confirm-btn">Delete</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container"></div>

<script>
    // ── Live search ──
    document.getElementById('searchInput').addEventListener('keyup', function () {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('.item-row').forEach(row => {
            row.style.display = row.getAttribute('data-search').includes(filter) ? '' : 'none';
        });
    });

    // ── Toast helper ──
    function showToast(message, type) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.innerHTML = `<span class="toast-icon">${type === 'success' ? '✅' : '❌'}</span>
                           <span class="toast-msg">${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('fadeOut');
            toast.addEventListener('animationend', () => toast.remove());
        }, 3500);
    }

    // ── Delete modal logic ──
    let pendingForm = null;

    document.querySelectorAll('.delete-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            pendingForm = form;
            const itemName = form.getAttribute('data-item-name') || 'this item';
            document.getElementById('confirm-item-name').textContent = '"' + itemName + '"';
            document.getElementById('confirm-overlay').classList.add('show');
        });
    });

    document.getElementById('modal-cancel-btn').addEventListener('click', function () {
        document.getElementById('confirm-overlay').classList.remove('show');
        pendingForm = null;
    });

    // Close on backdrop click
    document.getElementById('confirm-overlay').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
            pendingForm = null;
        }
    });

    document.getElementById('modal-confirm-btn').addEventListener('click', function () {
        if (!pendingForm) return;
        const form = pendingForm;
        pendingForm = null;
        document.getElementById('confirm-overlay').classList.remove('show');

        const btn = this;
        btn.textContent = 'Deleting…';
        btn.disabled = true;

        const formData = new FormData(form);
        if (!formData.has('delete')) formData.append('delete', '1');

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            btn.textContent = 'Delete';
            btn.disabled = false;
            showToast(data.message, data.status);
            if (data.status === 'success') {
                const row = form.closest('tr');
                if (row) {
                    row.style.transition = 'opacity .35s, transform .35s';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(30px)';
                    setTimeout(() => row.remove(), 360);
                }
            }
        })
        .catch(() => {
            btn.textContent = 'Delete';
            btn.disabled = false;
            showToast('Network error — please try again.', 'error');
        });
    });
</script>

</body>
</html>