<?php
session_start();
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Check login
if (!isset($_SESSION["user_id"])) {
    $_SESSION["redirect_after_login"] = "my_orders.php";
    header("Location: login.php");
    exit();
}

$userId = $_SESSION["user_id"];

// Get order ID
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    die("Invalid order ID");
}

$orderId = (int)$_GET['order_id'];

// Fetch order details to get enquiry number
$sql = "SELECT enquiry_no FROM orders WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}

$enquiryNo = $order['enquiry_no'];

// Build PDF filename
$filename = "invoice_{$enquiryNo}.pdf";
$filePath = $_SERVER["DOCUMENT_ROOT"] . "/bills/" . $filename;

// Check if file exists
if (!file_exists($filePath)) {
    die("PDF not found for this order.");
}

// Serve PDF for download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit();