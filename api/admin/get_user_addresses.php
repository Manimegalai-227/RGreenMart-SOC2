<?php
/**
 * /api/admin/get_user_addresses.php
 *
 * Returns saved addresses for a given user_id.
 * Admin-only endpoint.
 */

session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$user_id = intval($_GET['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "user_id required"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, contact_name, contact_mobile, address_line1, address_line2,
           city, state, pincode, landmark
    FROM user_addresses
    WHERE user_id = ?
    ORDER BY id DESC
");
$stmt->execute([$user_id]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "success"   => true,
    "addresses" => $addresses
]);