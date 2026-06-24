<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $id = (int)$_POST['id'];
    // ✅ Prevent self-deletion
    if ($id === (int)$_SESSION['admin_id']) {
        $message = "You cannot delete your own account.";
    } else {
        $stmt = $conn->prepare("DELETE FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Admin deleted successfully.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $id = (int)$_POST['id'];
    try {
        $conn->beginTransaction();

        // Dynamically find all tables with a user_id column so we never crash
        // on a table that doesn't exist in this database.
        $dbName = $conn->query("SELECT DATABASE()")->fetchColumn();
        $stmt = $conn->prepare("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND COLUMN_NAME   = 'user_id'
              AND TABLE_NAME   != 'users'
        ");
        $stmt->execute([$dbName]);
        $tablesWithUserId = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tablesWithUserId as $table) {
            $conn->prepare("DELETE FROM `$table` WHERE user_id = ?")->execute([$id]);
        }

        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $conn->commit();
        $message = "User deleted successfully.";
    } catch (\PDOException $e) {
        $conn->rollBack();
        $message = "Delete failed: " . $e->getMessage();
    }
}

$admins = $conn->query("SELECT id, username FROM admin_users")->fetchAll(PDO::FETCH_ASSOC);
$users = $conn->query("SELECT id, name, mobile, email FROM users")->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .admin-main {
            margin-left: 3rem;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="admin-container flex">
        <?php require_once './common/admin_sidebar.php'; ?>
<main class="admin-main flex-1 p-6">
    <div class="container mx-auto max-w-6xl p-6 bg-white rounded-lg shadow-lg mt-10">
        <h2 class="text-2xl font-bold text-indigo-600 mb-6">User Management Panel</h2>
            <?php if (!empty($message)): ?>
        <div id="successMessage"
            class="mb-4 p-3 rounded bg-green-100 text-green-800 border border-green-300">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

        <!-- Tabs -->
        <div class="flex border-b mb-6">
            <button onclick="showTab('adminTab')" id="adminBtn"
                class="px-6 py-2 font-medium text-indigo-600 border-b-2 border-indigo-600">
                Admin Users
            </button>

            <button onclick="showTab('userTab')" id="userBtn"
                class="px-6 py-2 font-medium text-gray-500">
                Registered Users
            </button>
        </div>

        <!-- ADMIN TABLE -->
        <div id="adminTab">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse bg-white rounded-lg shadow-sm">
                    <thead>
                        <tr class="bg-indigo-500 text-white">
                            <th class="p-3 text-left">ID</th>
                            <th class="p-3 text-left">Username</th>
                            <th class="p-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-3 border-b"><?= htmlspecialchars($admin['id']) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($admin['username']) ?></td>
                                <td class="p-3 border-b">
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                                        <button type="submit" name="delete_admin"
                                            onclick="return confirm('Are you sure you want to delete this admin?')"
                                            class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- USER TABLE -->
        <div id="userTab" class="hidden">
            <div class="overflow-x-auto">
                <div class="mb-4 text-lg font-semibold text-gray-700">
                    Total Registered Users: 
                    <span class="text-indigo-600"><?= $totalUsers ?></span>
                </div>
                <table class="w-full border-collapse bg-white rounded-lg shadow-sm">
                    <thead>
                        <tr class="bg-indigo-500 text-white">
                            <th class="p-3 text-left">ID</th>
                            <th class="p-3 text-left">Name</th>
                            <th class="p-3 text-left">Mobile</th>
                            <th class="p-3 text-left">Email</th>
                            <th class="p-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-3 border-b"><?= htmlspecialchars((string)($user['id'] ?? '')) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars((string)($user['name'] ?? '')) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars((string)($user['mobile'] ?? '—')) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars((string)($user['email'] ?? '')) ?></td>
                                <td class="p-3 border-b">
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                        <button type="submit" name="delete_user"
                                            class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
    </div>
</body>
<script>
setTimeout(() => {
    const msg = document.getElementById('successMessage');
    if (msg) {
        msg.style.transition = "opacity 0.5s ease";
        msg.style.opacity = "0";
        setTimeout(() => msg.remove(), 500);
    }
}, 3000);


function showTab(tabId) {
    document.getElementById('adminTab').classList.add('hidden');
    document.getElementById('userTab').classList.add('hidden');

    document.getElementById('adminBtn').classList.remove('text-indigo-600', 'border-indigo-600');
    document.getElementById('userBtn').classList.remove('text-indigo-600', 'border-indigo-600');

    document.getElementById('adminBtn').classList.add('text-gray-500');
    document.getElementById('userBtn').classList.add('text-gray-500');

    document.getElementById(tabId).classList.remove('hidden');

    if (tabId === 'adminTab') {
        document.getElementById('adminBtn').classList.remove('text-gray-500');
        document.getElementById('adminBtn').classList.add('text-indigo-600', 'border-b-2', 'border-indigo-600');
    } else {
        document.getElementById('userBtn').classList.remove('text-gray-500');
        document.getElementById('userBtn').classList.add('text-indigo-600', 'border-b-2', 'border-indigo-600');
    }
}
</script>

</html>