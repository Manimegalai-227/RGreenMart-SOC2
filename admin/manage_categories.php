<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Upload directory
$uploadDir = $_SERVER["DOCUMENT_ROOT"] . "/admin/categoryImages/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ADD CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {

    $name = trim($_POST['name']);
    $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $imageName = null;

    if (!empty($_FILES['image']['name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imageName = uniqid("cat_") . "." . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName);
    }

    $stmt = $conn->prepare("INSERT INTO categories (name, parent_id, image) VALUES (?, ?, ?)");
    $stmt->execute([$name, $parent_id, $imageName]);

    header("Location: manage_categories.php");
    exit;
}

// FETCH CATEGORIES
$categories = $conn->query("
    SELECT c.id, c.name, p.name AS parent_name, c.image
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    ORDER BY c.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Categories</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
<div class="flex">
    <?php require_once './common/admin_sidebar.php'; ?>

    <main class="flex-1 p-6">
        <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow">

            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-indigo-600">Manage Categories</h1>
                <button onclick="openModal()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">
                    + Add Category
                </button>
            </div>

            <!-- CATEGORY TABLE -->
            <table class="w-full border text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-3 border">ID</th>
                        <th class="p-3 border">Image</th>
                        <th class="p-3 border">Name</th>
                        <th class="p-3 border">Parent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="p-3 border"><?= $cat['id'] ?></td>
                        <td class="p-3 border">
                            <?php if ($cat['image']): ?>
                                <img src="/admin/categoryImages/<?= $cat['image'] ?>" class="h-12 rounded">
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="p-3 border"><?= htmlspecialchars($cat['name']) ?></td>
                        <td class="p-3 border"><?= $cat['parent_name'] ?? '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
    </main>
</div>

<!-- ADD CATEGORY MODAL -->
<div id="categoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center">
    <div class="bg-white p-6 rounded-xl w-96">
        <h2 class="text-xl font-bold mb-4 text-indigo-600">Add Category</h2>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="add_category" value="1">

            <label class="block mb-2">Category Name</label>
            <input type="text" name="name" required class="w-full p-2 border rounded mb-4">

            <label class="block mb-2">Parent Category</label>
            <select name="parent_id" class="w-full p-2 border rounded mb-4">
                <option value="">None</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="block mb-2">Category Image</label>
            <input type="file" name="image" accept="image/*" class="mb-4">

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-indigo-600 text-white py-2 rounded">Save</button>
                <button type="button" onclick="closeModal()" class="flex-1 border py-2 rounded">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('categoryModal').classList.remove('hidden');
}
function closeModal() {
    document.getElementById('categoryModal').classList.add('hidden');
}
</script>

</body>
</html>
