<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$user_id = $_SESSION["user_id"];
$address_id = $_POST["id"];
$is_default = isset($_POST['is_default']) ? 1 : 0;

// If default, make all others non-default
if ($is_default) {
    $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")
         ->execute([$user_id]);
}

$stmt = $conn->prepare("
    UPDATE user_addresses SET
        contact_name = ?, contact_mobile = ?, address_line1 = ?, address_line2 = ?,
        city = ?, state = ?, pincode = ?, landmark = ?, is_default = ?
    WHERE id = ? AND user_id = ?
");

$stmt->execute([
    $_POST['contact_name'], $_POST['contact_mobile'], $_POST['address_line1'],
    $_POST['address_line2'], $_POST['city'], $_POST['state'], $_POST['pincode'],
    $_POST['landmark'], $is_default, $address_id, $user_id
]);

header("Location: add_delivery_address.php");
exit;
