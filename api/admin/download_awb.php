<?php
require_once __DIR__ . '/../../dbconf.php';
require_once __DIR__ . '/../../api/shiprocket.php';

if (!isset($_GET['order_id'])) {
    http_response_code(400);
    echo 'order_id required';
    exit;
}
$orderId = intval($_GET['order_id']);
$stmt = $conn->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$stmt->execute([$orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'Shipment not found for order';
    exit;
}

// if label_path present and file exists, serve it
if (!empty($row['label_path'])) {
    $file = $_SERVER['DOCUMENT_ROOT'] . rtrim($row['label_path'],'/');
    if (file_exists($file)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }
}

// else attempt to fetch label from Shiprocket if AWB available
if (!empty($row['awb'])) {
    try {
        $client = shiprocketClient();
        $labelResp = $client->getLabel(['awb' => $row['awb']]);
        $base64 = null;
        if (!empty($labelResp['data']['label'])) $base64 = $labelResp['data']['label'];
        if (empty($base64) && !empty($labelResp['label'])) $base64 = $labelResp['label'];
        if (!empty($base64)) {
            $labelsDir = __DIR__ . '/../../shipments/labels';
            if (!is_dir($labelsDir)) mkdir($labelsDir, 0777, true);
            $fileName = 'awb_' . preg_replace('/[^0-9A-Za-z_-]/', '_', $row['awb']) . '_' . time() . '.pdf';
            $filePath = $labelsDir . '/' . $fileName;
            file_put_contents($filePath, base64_decode($base64));
            // update shipments
            $upd = $conn->prepare('UPDATE shipments SET label_path = ?, label_url = ? WHERE order_id = ?');
            $upd->execute(['/shipments/labels/' . $fileName, '/shipments/labels/' . $fileName, $orderId]);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            readfile($filePath);
            exit;
        }

        // If label_url present, redirect
        if (!empty($labelResp['data']['label_url'])) {
            header('Location: ' . $labelResp['data']['label_url']);
            exit;
        }
        if (!empty($labelResp['label_url'])) {
            header('Location: ' . $labelResp['label_url']);
            exit;
        }

        http_response_code(500);
        echo 'Label fetch returned no content';
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage();
        exit;
    }
}

http_response_code(404);
echo 'No AWB or label available';
