<?php
header("Content-Type: application/json");
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

try {
    $stmt = $conn->prepare("SELECT id, name FROM categories");
    $stmt->execute();

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
