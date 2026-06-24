<?php
require_once __DIR__ . '/../../dbconf.php';
header('Content-Type: application/json');

if (!isset($_GET['order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id required']);
    exit;
}
$orderId = intval($_GET['order_id']);
$stmt = $conn->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$stmt->execute([$orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No shipment for this order']);
    exit;
}

// Try to fetch live tracking if AWB present
$live = null;
if ($row['awb']) {
    try {
        require_once __DIR__ . '/../shiprocket.php';
        $client = shiprocketClient();
        $live = $client->trackAwb($row['awb']);
    } catch (Exception $e) {
        $live = ['error' => $e->getMessage()];
    }
}

echo json_encode(['success' => true, 'shipment' => $row, 'live' => $live]);