<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Display dates in IST (Asia/Kolkata, UTC+5:30) ─────────────────────────────
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$orderId = intval($_GET['order_id'] ?? 0);
if (!$orderId) {
    header("Location: my_orders.php");
    exit();
}

// Clear cart flag
$cartCleared = isset($_GET['cart_cleared']);

require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

// Fetch order to verify it belongs to this user and get enquiry_no
$stmt = $conn->prepare("SELECT o.id, o.enquiry_no, o.overall_total, o.payment_method, o.status
                         FROM orders o
                         WHERE o.id = ? AND o.user_id = ? LIMIT 1");
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: my_orders.php");
    exit();
}

$enquiryNo = $order['enquiry_no'];
$pdfFile   = $_SERVER['DOCUMENT_ROOT'] . "/bills/invoice_{$enquiryNo}.pdf";
$hasPdf    = !empty($enquiryNo) && file_exists($pdfFile);
$pdfUrl    = "/bills/invoice_{$enquiryNo}.pdf";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed – RGreenMart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="cart.js"></script>
    <style>
        body { font-family: 'Inter', 'Arial', sans-serif; background: #f3f4f6; }
        @keyframes popIn {
            from { transform: scale(0.85); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
        .success-card { animation: popIn 0.4s cubic-bezier(.4,0,.2,1); }
        @keyframes checkDraw {
            from { stroke-dashoffset: 60; }
            to   { stroke-dashoffset: 0; }
        }
        .check-path {
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: checkDraw 0.5s ease 0.2s forwards;
        }
    </style>
</head>
<body>
    <?php include "includes/header.php"; ?>

    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="success-card bg-white rounded-2xl shadow-xl p-10 max-w-md w-full text-center">

            <!-- Animated checkmark -->
            <div class="mx-auto mb-6 flex items-center justify-center"
                 style="width:90px;height:90px;border-radius:50%;background:linear-gradient(135deg,#e91e63,#6a1b9a);">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none"
                     stroke="#fff" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
                    <polyline class="check-path" points="20 6 9 17 4 12"/>
                </svg>
            </div>

            <h1 class="text-2xl font-extrabold text-gray-800 mb-2">Order Confirmed! 🎉</h1>
            <p class="text-gray-500 text-sm mb-1">Your order has been placed successfully.</p>
            <p class="text-gray-400 text-sm mb-8">
                Order ID: <strong class="text-purple-700">#<?= $orderId ?></strong>
                &nbsp;|&nbsp;
                Enquiry No: <strong class="text-purple-700"><?= htmlspecialchars($enquiryNo) ?></strong>
            </p>

            <!-- Invoice download -->
            <?php if ($hasPdf): ?>
            <a href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank"
               class="inline-flex items-center gap-3 w-full justify-center px-6 py-4 rounded-xl font-bold text-white text-base mb-4 transition-opacity hover:opacity-90"
               style="background:linear-gradient(135deg,#e91e63,#6a1b9a);box-shadow:0 6px 18px rgba(233,30,99,0.3);">
                <i class="fa-solid fa-file-pdf text-lg"></i>
                Download Invoice
            </a>
            <?php else: ?>
            <!-- PDF not ready yet — poll until it appears -->
            <button id="invoiceBtn" disabled
               class="inline-flex items-center gap-3 w-full justify-center px-6 py-4 rounded-xl font-bold text-white text-base mb-4 opacity-60 cursor-not-allowed"
               style="background:linear-gradient(135deg,#e91e63,#6a1b9a);">
                <i class="fa-solid fa-spinner fa-spin text-lg"></i>
                <span id="invoiceBtnText">Preparing Invoice…</span>
            </button>
            <p id="invoiceNote" class="text-xs text-gray-400 mb-4">Your invoice is being generated. It will be ready in a few seconds.</p>
            <?php endif; ?>

            <!-- Navigation links -->
            <a href="my_orders.php"
               class="inline-flex items-center gap-2 justify-center w-full py-3 rounded-xl border-2 border-purple-600 text-purple-700 font-semibold text-sm hover:bg-purple-50 transition mb-3">
                <i class="fa-solid fa-list-check"></i> View My Orders
            </a>
            <a href="index.php"
               class="inline-block text-sm text-gray-400 hover:text-gray-600 underline transition">
                Continue Shopping
            </a>
        </div>
    </div>

    <?php include "includes/footer.php"; ?>

    <script>
    // Clear cart on page load (order confirmed)
    <?php if ($cartCleared): ?>
    try { localStorage.removeItem('cart'); } catch(e) {}
    if (typeof updateCartCount === 'function') updateCartCount();
    <?php endif; ?>

    <?php if (!$hasPdf): ?>
    // Poll every 2.5s until the PDF is generated
    (function pollInvoice() {
        const orderId  = <?= $orderId ?>;
        const btn      = document.getElementById('invoiceBtn');
        const btnText  = document.getElementById('invoiceBtnText');
        const note     = document.getElementById('invoiceNote');

        function check() {
            fetch('download_bill.php?order_id=' + orderId, { method: 'HEAD' })
                .then(r => {
                    if (r.ok) {
                        // PDF ready — convert button to download link
                        btn.disabled = false;
                        btn.classList.remove('opacity-60', 'cursor-not-allowed');
                        btn.innerHTML = '<i class="fa-solid fa-file-pdf text-lg"></i> Download Invoice';
                        btn.onclick = function() {
                            window.open('pdf_generation.php?order_id=' + orderId + '&cart_cleared=true', '_blank');
                        };
                        if (note) note.textContent = 'Your invoice is ready!';
                    } else {
                        setTimeout(check, 2500);
                    }
                })
                .catch(() => setTimeout(check, 2500));
        }
        setTimeout(check, 2500);
    })();
    <?php endif; ?>
    </script>
</body>
</html>