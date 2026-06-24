<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/shiprocket.php";

header('Content-Type: application/json');

try {
    $shiprocket = shiprocketClient();

    // ✅ Get values from request (frontend / API call)
    $deliveryPostcode = $_GET['pincode'] ?? null;
    $weight = $_GET['weight'] ?? 1;
    $cod = $_GET['cod'] ?? 0;

    // ❌ Validation
    if (!$deliveryPostcode) {
        echo json_encode(["error" => "Delivery pincode required"]);
        exit;
    }

    // ✅ Call with parameters
    $response = $shiprocket->getCouriers($deliveryPostcode, $weight, $cod);

    if (isset($response['data']['available_courier_companies'])) {
        echo json_encode($response['data']['available_courier_companies']);
    } else {
        echo json_encode([]);
    }

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
