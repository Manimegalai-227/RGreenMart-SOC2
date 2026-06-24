<?php
require_once __DIR__ . '/shiprocket.php';
header('Content-Type: application/json');
try {
    $client = shiprocketClient();
    // Try a safe credential test that only authenticates and returns token/base URL
    $resp = $client->testAuth();
    echo json_encode(['success' => true, 'message' => 'Auth successful', 'resp' => $resp]);
} catch (Exception $e) {
    http_response_code(500);
    // Provide helpful diagnostics for common 404 cause
    $msg = $e->getMessage();
    if (strpos($msg, '404') !== false) {
        $msg .= ' — 404 often means the requested endpoint does not exist at the configured base URL. Check SHIPROCKET_BASE_URL in .env and try Test Auth again.';
    }
    echo json_encode(['success' => false, 'message' => $msg]);
}
