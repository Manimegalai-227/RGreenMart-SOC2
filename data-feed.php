<?php

ini_set('display_errors', 0);
error_reporting(0);

// Clean output buffer completely
if (ob_get_length()) ob_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="meta_catalog.csv"');

// Load .env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// DB
$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($conn->connect_error) exit;

echo "\xEF\xBB\xBF";
$output = fopen("php://output", "w");

// CSV Header
fputcsv($output, [
    "id",
    "item_group_id",
    "title",
    "description",
    "availability",
    "condition",
    "price",
    "link",
    "image_link",
    "brand"
]);

$query = "
SELECT 
    i.id AS product_id,
    i.name,
    i.description,
    v.id AS variant_id,
    v.weight_value,
    v.weight_unit,
    v.price,
    v.stock,
    (
        SELECT CONCAT('https://rgreenmart.com/admin/', COALESCE(compressed_path, image_path))
        FROM item_images
        WHERE item_id = i.id
        LIMIT 1
    ) AS image
FROM items i
LEFT JOIN item_variants v ON v.item_id = i.id
WHERE i.status = 1
";

$result = $conn->query($query);
if (!$result) exit;

while ($row = $result->fetch_assoc()) {

    if (!$row['variant_id']) continue;

    // Clean ID for variant
    $id = $row['product_id'] . "_" . $row['variant_id'];

    // Availability
    $availability = ($row['stock'] > 0) ? "in stock" : "out of stock";

    // Clean weight (remove .00)
    $weight = '';
    if ($row['weight_value'] && $row['weight_unit']) {
        $cleanWeight = rtrim(rtrim($row['weight_value'], '0'), '.');
        $weight = " - " . $cleanWeight . $row['weight_unit'];
    }

    // Clean title
    $title = trim($row['name']) . $weight;

    // Clean price (no comma, 2 decimals)
    $price = number_format((float)$row['price'], 2, '.', '') . " INR";

    fputcsv($output, [
        $id,
        $row['product_id'],   // item_group_id
        $title,
        strip_tags($row['description']),
        $availability,
        "new",
        $price,
        "https://rgreenmart.com/product.php?id=" . $row['product_id'],
        $row['image'] ? $row['image'] : '',
        "RGreenMart"
    ]);
}


fclose($output);
exit;
