<?php
require_once __DIR__ . '/../../dbconf.php';
require_once __DIR__ . '/../../api/shiprocket.php';

// ── Store timestamps in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────
date_default_timezone_set('Asia/Kolkata');
$conn->exec("SET time_zone = '+05:30'");

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
$orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;
if (!$orderId) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'order_id required']); exit; }
try {
    $stmt = $conn->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new Exception('No shipment found for this order');
    if (!$s['awb']) throw new Exception('Shipment does not have AWB assigned yet');

    $client = shiprocketClient();
    // Build a minimal pickup payload; adjust per Shiprocket docs and your pickup slot
    $pickupPayload = [
        'pickup_location_id' => $_ENV['SHIPROCKET_PICKUP_LOC_ID'] ?? 1,
        'awb' => $s['awb'],
        'pickup_date' => date('Y-m-d'),
        'start_time' => '10:00',
        'end_time' => '18:00'
    ];
    $resp = $client->schedulePickup($pickupPayload);
    // Update DB with pickup info
    $upd = $conn->prepare('UPDATE shipments SET pickup_scheduled_at = ?, raw_response = ? WHERE id = ?');
    $upd->execute([date('Y-m-d H:i:s'), json_encode($resp), $s['id']]);
    echo json_encode(['success'=>true,'message'=>'Pickup scheduled','resp'=>$resp]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}