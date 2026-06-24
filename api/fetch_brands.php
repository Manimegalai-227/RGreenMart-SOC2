<?php
header("Content-Type: application/json");
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

try {
    // Use the correct table name and remove WHERE status if the column doesn't exist
    $stmt = $conn->prepare("SELECT id, name FROM brands");
    $stmt->execute();

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
