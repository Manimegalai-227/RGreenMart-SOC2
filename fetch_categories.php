<?php
require_once 'vendor/autoload.php';
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'diwali_db';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

$brand = $_GET['brand'] ?? 'all';
$sql = "SELECT DISTINCT category FROM items";
$params = [];
if ($brand !== 'all') {
    $sql .= " WHERE brand = :brand";
    $params['brand'] = $brand;
}
$sql .= " ORDER BY category";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode($categories);
?>