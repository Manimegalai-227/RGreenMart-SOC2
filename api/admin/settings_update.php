<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json");

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check if any row exists
    $row = $conn->query("SELECT id FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    try {
        if ($row) {
            // Row exists → UPDATE
            $stmt = $conn->prepare("
                UPDATE settings SET 
                    gst_rate                = ?, 
                    discount                = ?, 
                    packaging_charge        = ?, 
                    minimum_order           = ?, 
                    notification_text       = ?,
                    pickuplocation_pincode  = ?,
                    updated_at              = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['gst_rate'],
                $_POST['discount'],
                $_POST['packaging_charge'] ?? 0,
                $_POST['minimum_order']    ?? 0,
                $_POST['notification_text'] ?? '',
                $_POST['pickuplocation_pincode'] ?? '',
                $row['id']
            ]);
        } else {
            // Row does not exist → INSERT
            $stmt = $conn->prepare("
                INSERT INTO settings 
                    (gst_rate, discount, packaging_charge, minimum_order, notification_text, pickuplocation_pincode, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_POST['gst_rate'],
                $_POST['discount'],
                $_POST['packaging_charge'] ?? 0,
                $_POST['minimum_order']    ?? 0,
                $_POST['notification_text'] ?? '',
                $_POST['pickuplocation_pincode'] ?? '',
            ]);
        }

        echo json_encode(["status" => "success", "message" => "Settings updated successfully"]);
        exit();

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        exit();
    }
}

echo json_encode(["status" => "error", "message" => "Invalid Request"]);
exit();