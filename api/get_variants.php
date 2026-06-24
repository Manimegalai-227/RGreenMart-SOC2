<?php
/**
 * /api/get_variants.php
 * Returns JSON array of active variants for a given item_id.
 * Called by the cart panel variant selector modal (AJAX).
 *
 * Usage: GET /api/get_variants.php?item_id=123
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once $_SERVER['DOCUMENT_ROOT'] . '/dbconf.php';

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

if ($itemId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            id,
            item_id,
            weight_value,
            weight_unit,
            price,
            old_price,
            discount,
            stock,
            status
        FROM item_variants
        WHERE item_id = :item_id
          AND status  = 1
        ORDER BY price ASC
    ");
    $stmt->execute([':item_id' => $itemId]);
    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric strings to proper types for JS
    foreach ($variants as &$v) {
        $v['id']           = (int)   $v['id'];
        $v['item_id']      = (int)   $v['item_id'];
        $v['price']        = (float) $v['price'];
        $v['old_price']    = (float) ($v['old_price'] ?? 0);
        $v['discount']     = (float) ($v['discount']  ?? 0);
        $v['stock']        = (int)   ($v['stock']     ?? 0);
    }

    echo json_encode($variants);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([]);
}