<?php
require_once __DIR__ . '/dbconf.php';
require_once __DIR__ . '/api/shiprocket.php';

if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    die('Invalid Order ID');
}

$orderId = (int) $_GET['order_id'];

/* 1️⃣ Get order + shipment info */
$sql = "
    SELECT 
        o.id AS order_id,
        s.awb,
        s.courier_name
    FROM orders o
    LEFT JOIN shipments s ON s.order_id = o.id
    WHERE o.id = :order_id
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->execute(['order_id' => $orderId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die('Order not found');
}

if (empty($data['awb'])) {
    die('AWB not assigned yet. Please wait for shipment creation.');
}

/* 2️⃣ Track using AWB */
$shiprocket = shiprocketClient();

try {
    $tracking = $shiprocket->trackAwb($data['awb']);
} catch (Exception $e) {
    die('<pre>' . $e->getMessage() . '</pre>');
}

/* 3️⃣ Extract activities safely */
$activities = $tracking['tracking_data']['shipment_track_activities'] ?? [];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Track Shipment</title>
    <style>
        body { font-family: Arial; background:#f5f5f5; }
        .box {
            border:1px solid #ddd;
            padding:15px;
            margin-bottom:15px;
            background:#fff;
            border-radius:6px;
        }
        .step {
            margin-bottom:10px;
        }
        .date {
            font-size:13px;
            color:#666;
        }
        .status {
            font-weight:bold;
            margin:3px 0;
        }
    </style>
</head>
<body>

<h2>Track Shipment</h2>

<div class="box">
    <b>Order ID:</b> <?= htmlspecialchars($data['order_id']) ?><br>
    <b>AWB:</b> <?= htmlspecialchars($data['awb']) ?><br>
    <b>Courier:</b> <?= htmlspecialchars($data['courier_name'] ?? 'N/A') ?>
</div>

<div class="box">
    <h3>Tracking Status</h3>

    <?php if (!empty($activities)): ?>
        <?php foreach ($activities as $step): ?>
            <div class="step">
                <div class="date">
                    <?= htmlspecialchars($step['date'] ?? 'N/A') ?>
                </div>
                <div class="status">
                    <?= htmlspecialchars($step['activity'] ?? 'N/A') ?>
                </div>
                <div>
                    <?= htmlspecialchars($step['location'] ?? 'N/A') ?>
                </div>
            </div>
            <hr>
        <?php endforeach; ?>
    <?php else: ?>
        <p><b>No tracking updates yet.</b></p>
        <p>Tracking will appear after courier pickup scan.</p>
    <?php endif; ?>
</div>

</body>
</html>
