<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

$id = $_GET['id'];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);

header("Location: add_delivery_address.php");
exit;
