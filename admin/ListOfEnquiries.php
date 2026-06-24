<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$billsDir = $_SERVER['DOCUMENT_ROOT'] . '/bills';
$pdfFiles = is_dir($billsDir) ? glob($billsDir . '/*.pdf') : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List of Enquiries</title>
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
            <div class="container mx-auto max-w-4xl p-6 bg-white rounded-lg shadow-lg mt-10">
                <h2 class="text-2xl font-bold text-indigo-600 mt-8 mb-4">List of Enquiries</h2>
                <div class="mb-4">
                    <input type="text" id="searchInput" placeholder="Search by enquiry number (e.g., 1234)..." class="w-full p-3 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse bg-white rounded-lg shadow-sm" id="enquiriesTable">
                        <thead>
                            <tr class="bg-indigo-500 text-white">
                                <th class="p-3 text-left">Filename</th>
                                <th class="p-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdfFiles as $file): ?>
                                <?php $filename = basename($file); ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="p-3 border-b"><?php echo htmlspecialchars($filename); ?></td>
                                    <td class="p-3 border-b">
                                        <a href="../bills/<?php echo htmlspecialchars($filename); ?>" target="_blank" class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 transition-colors">Open</a>
                                        <a href="../bills/<?php echo htmlspecialchars($filename); ?>" download class="bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700 transition-colors">Download</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('searchInput').addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = document.querySelectorAll('#enquiriesTable tbody tr');
                rows.forEach(row => {
                    const filename = row.querySelector('td:first-child').textContent.toLowerCase();
                    row.style.display = filename.includes(filter) ? '' : 'none';
                });
            });
        });
    </script>
</body>
</html>