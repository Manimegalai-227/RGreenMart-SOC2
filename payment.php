<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'vendor/autoload.php';
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";


// DB Config
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'diwali_db';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';
session_start();
use Razorpay\Api\Api;
$orderId = $_GET['order_id'] ?? null;

if (!$orderId || !isset($_SESSION['order_id']) || $orderId != $_SESSION['order_id']) {
    die("Invalid request.");
}

$conn = new PDO("mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

$api = new Api($_ENV['RAZORPAY_KEY_ID'], $_ENV['RAZORPAY_KEY_SECRET']);

$razorpayOrder = $api->order->create([
    'receipt' => "ORDER_$orderId",
    'amount' => $order['overall_total'] * 100, 
    'currency' => 'INR'
]);

$razorpayOrderId = $razorpayOrder['id'];

$conn->prepare("UPDATE orders SET razorpay_order_id = ? WHERE id = ?")->execute([$razorpayOrderId, $orderId]);

?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
var options = {
    "key": "<?= $_ENV['RAZORPAY_KEY_ID'] ?>",
    "amount": "<?= $order['overall_total'] * 100 ?>",
    "currency": "INR",
    "name": "RgreenMart",
    "description": "Order Payment",
    "order_id": "<?= $razorpayOrderId ?>", // ← REAL Order ID from PHP
    "handler": function (response){
        window.location.href = "verify_payment.php?order_id=<?= $orderId ?>&payment_id=" + response.razorpay_payment_id + "&signature=" + response.razorpay_signature;
    },
    "theme": {
        "color": "#3399cc"
    }
};
var rzp1 = new Razorpay(options);

document.getElementById('pay').onclick = function(e){
    rzp1.open();
    e.preventDefault();
}
</script>

