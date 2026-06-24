<?php
require_once 'vendor/autoload.php';
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!isset($_GET['order_id'])) {
        die("<p style='color:#dc2626;'>Error: Order ID missing in URL.</p>");
    }
    $orderId = intval($_GET['order_id']);

    // ── Fetch order (includes cod_advance_amount) ────────────────────────
    $orderStmt = $conn->prepare("
        SELECT o.*, u.name AS customer_name, u.mobile AS customer_mobile, u.email AS customer_email,
               a.contact_name, a.contact_mobile,
               a.address_line1, a.address_line2, a.city, a.state, a.pincode, a.landmark
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN user_addresses a ON a.id = o.address_id
        WHERE o.id = ?
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("<p style='color:#dc2626;'>Error: Order ID $orderId not found.</p>");
    }

    // Use address contact_name/mobile as primary (what was entered at checkout)
    // Fall back to user account name/mobile if address fields are somehow empty
    $customerName     = $order['contact_name']   ?: ($order['customer_name']   ?? '');
    $customerMobile   = $order['contact_mobile'] ?: ($order['customer_mobile'] ?? '');
    $customerEmail    = $order['customer_email']  ?? '';
    $customerAddress  = trim(($order['address_line1'] ?? '') . ' ' . ($order['address_line2'] ?? ''));
    $customerCity     = $order['city']     ?? '';
    $customerState    = $order['state']    ?? '';
    $customerPincode  = $order['pincode']  ?? '';
    $customerLandmark = $order['landmark'] ?? '';
    $enquiryNumber    = $order['enquiry_no'];
    $orderedDateTime  = $order['order_date'] ?? $order['created_at'];
    $shippingCharge      = floatval($order['shipping_charge']);
    $codConvenienceFee   = floatval($order['cod_convenience_fee'] ?? 0);
    $paymentMethod       = $order['payment_method'] ?? '';
    $paymentStatus       = $order['payment_status'] ?? '';
    $codAdvanceAmount = floatval($order['cod_advance_amount'] ?? 0);
    $isCodAdvance     = ($paymentMethod === 'cod' || $paymentMethod === 'cod_advance') && $codAdvanceAmount > 0;
    $couponCode       = trim($order['coupon_code'] ?? '');
    $couponDiscount   = floatval($order['coupon_discount_amount'] ?? 0);
    $referralDiscount = floatval($order['referral_discount']      ?? 0);
    $walletAmountUsed = floatval($order['wallet_amount_used']     ?? 0);

    // ── Fetch order items (schema-safe) ──────────────────────────────────
    $dbName = $_ENV['DB_NAME'] ?? $conn->query('select database()')->fetchColumn();
    $colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns
                                 WHERE table_schema = ? AND table_name = 'order_items'");
    $colsStmt->execute([$dbName]);
    $cols         = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasVariantId = in_array('variant_id', $cols);

    if ($hasVariantId) {
        $itemsSql = "
            SELECT oi.*, i.name AS product_name,
                   v.weight_value AS variant_weight, v.weight_unit AS variant_unit,
                   v.price AS variant_price, v.old_price AS variant_old_price, v.discount AS variant_discount
            FROM order_items oi
            JOIN items i ON i.id = oi.item_id
            LEFT JOIN item_variants v ON oi.variant_id = v.id
            WHERE oi.order_id = ?
        ";
    } else {
        $itemsSql = "
            SELECT oi.*, i.name AS product_name,
                   NULL AS variant_weight, NULL AS variant_unit,
                   NULL AS variant_price, NULL AS variant_old_price, NULL AS variant_discount
            FROM order_items oi
            JOIN items i ON i.id = oi.item_id
            WHERE oi.order_id = ?
        ";
    }
    $itemsStmt = $conn->prepare($itemsSql);
    $itemsStmt->execute([$orderId]);
    $itemsBought = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$itemsBought) {
        die("<p style='color:#dc2626;'>Error: No items found for order ID $orderId.</p>");
    }

    // ── Company profile ──────────────────────────────────────────────────
    $cpStmt   = $conn->query("SELECT * FROM company_profile LIMIT 1");
    $cp       = $cpStmt->fetch(PDO::FETCH_ASSOC);
    $adminName    = $cp['company_name'] ?? 'RGreenMart';
    $adminMobile  = $cp['mobile']       ?? '99524 24474';
    $adminEmail   = $cp['email']        ?? 'sales@rgreenmart.com';
    $adminAddress = $cp['address']      ?? 'Chandragandhi Nagar, Madurai, Tamil Nadu';

    $stmt = $conn->prepare("SELECT gst_rate FROM settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $gstRate  = isset($settings['gst_rate']) ? floatval($settings['gst_rate']) : 18;

    $smtpEmail    = $_ENV['SMTP_MAIL']     ?? '';
    $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
    if (empty($smtpEmail) || empty($smtpPassword)) {
        error_log("SMTP credentials missing");
        echo "<p style='color:#dc2626;'>Error: SMTP credentials are not configured.</p>";
        exit;
    }

    // ── Calculate item totals ─────────────────────────────────────────────
    $subtotal            = 0;
    $netTotal            = 0;
    $totalDiscountAmount = 0;
    $rowsHtml            = "";

    foreach ($itemsBought as $index => $item) {
        $discountedPrice = floatval($item['variant_price'] ?? $item['discounted_price'] ?? $item['original_price'] ?? 0);
        $quantity        = intval($item['quantity']);

        // ── Determine authoritative MRP (original_price / old_price) ──────
        // Priority:
        //   1. variant_old_price from live DB JOIN (most accurate — reflects selected variant)
        //   2. Derive from variant_discount % + selling price
        //   3. stored original_price (may be stale / wrong variant's MRP)
        //   4. Fallback: no discount
        $variantOldPrice = floatval($item['variant_old_price'] ?? 0);
        $variantDiscount = floatval($item['variant_discount']  ?? 0);
        $storedOriginal  = floatval($item['original_price']    ?? 0);

        if ($variantOldPrice > 0 && $variantOldPrice >= $discountedPrice) {
            $originalPrice = $variantOldPrice;
        } elseif ($variantDiscount > 0 && $variantDiscount < 100 && $discountedPrice > 0) {
            $originalPrice = $discountedPrice / (1 - $variantDiscount / 100);
        } elseif ($storedOriginal > 0 && $storedOriginal >= $discountedPrice) {
            $originalPrice = $storedOriginal;
        } else {
            $originalPrice = $discountedPrice;
        }

        $discountAmount = max(0, $originalPrice - $discountedPrice);
        $discountPerc   = ($originalPrice > 0 && $discountAmount > 0)
                            ? floor(($discountAmount / $originalPrice) * 100)
                            : 0;

        $totalDiscountAmount += $discountAmount  * $quantity;
        $subtotal            += $originalPrice   * $quantity;
        $netTotal            += $discountedPrice * $quantity;

        $productDisplay = htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8');
        if (!empty($item['variant_weight'])) {
            $productDisplay .= " - " . htmlspecialchars($item['variant_weight'], ENT_QUOTES, 'UTF-8')
                              . ' ' . htmlspecialchars($item['variant_unit'], ENT_QUOTES, 'UTF-8');
        }

        $rowsHtml .= "<tr>
            <td>" . ($index + 1) . "</td>
            <td class='left'>" . $productDisplay . "</td>
            <td>" . number_format($originalPrice, 2) . "</td>
            <td>" . number_format($discountAmount, 2) . " (" . number_format($discountPerc, 2) . "%)</td>
            <td>" . number_format($discountedPrice, 2) . "</td>
            <td>" . $quantity . "</td>
            <td>" . number_format($discountedPrice * $quantity, 2) . "</td>
        </tr>";
    }

    $overallTotal = $netTotal - $couponDiscount - $referralDiscount - $walletAmountUsed + $shippingCharge + $codConvenienceFee;
    $balanceCod   = $isCodAdvance ? round($overallTotal - $codAdvanceAmount, 2) : 0;

    // ── Payment method label for invoice ─────────────────────────────────
    if ($isCodAdvance) {
        $paymentLabel = "COD + Advance Payment";
    } elseif ($paymentMethod === 'cod') {
        $paymentLabel = "Cash on Delivery (COD)";
    } else {
        $paymentLabel = "Online Payment (Prepaid)";
    }

    // ── COD advance rows (only when applicable) ───────────────────────────
    $codAdvanceRows = '';
    if ($isCodAdvance) {
        $advStatus = ($paymentStatus === 'advance_paid') ? ' ✓ PAID' : ' (pending)';
        $codAdvanceRows = "
            <tr style='background:#fff7ed;'>
                <td colspan='6' style='text-align:right; font-weight:bold; color:#92400e;'>
                    💳 Advance Paid Online ({$advStatus})
                </td>
                <td style='font-weight:bold; color:#92400e;'>
                    " . number_format($codAdvanceAmount, 2) . "
                </td>
            </tr>
            <tr style='background:#f0fdf4;'>
                <td colspan='6' style='text-align:right; font-weight:bold; color:#065f46;'>
                    🏠 Balance to Pay on Delivery (Cash)
                </td>
                <td style='font-weight:bold; color:#065f46;'>
                    " . number_format($balanceCod, 2) . "
                </td>
            </tr>";
    }

    // ── Build PDF HTML ────────────────────────────────────────────────────
    $html = "
    <html>
    <head>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; }
            .invoice-box { border: 1px solid #000; padding: 5px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #000; padding: 6px; text-align: center; }
            th { font-weight: bold; background: #f3f4f6; }
            .header { width: 100%; border-bottom: 1px solid #000; margin-bottom: 5px; }
            .header td { border: none; padding: 2px 5px; }
            .bold  { font-weight: bold; }
            .right { text-align: right; }
            .left  { text-align: left; }
            .center{ text-align: center; }
            .payment-badge {
                display: inline-block;
                padding: 3px 7px;
                font-size: 12px;
                font-weight: bold;
            }
            .advance-box {
                border: 2px solid #fdba74;
                border-radius: 6px;
                background: #fff7ed;
                padding: 8px 12px;
                margin-top: 8px;
                font-size: 12px;
            }
            .btn-success {
                background: #16a34a; color: #ffffff; padding: 0.75rem 1.5rem;
                border-radius: 9999px; text-decoration: none; font-weight: bold;
                transition: all 0.2s; display: inline-block;
            }
        </style>
        <title>Invoice - $enquiryNumber</title>
    </head>
    <body>
        <div style='margin:30px auto; max-width:900px; border:2px solid #4b5563;
                    background:#fff; padding:20px; box-shadow:0 2px 8px #ccc;'>
            <div class='invoice-box'>

                <!-- Header -->
                <table class='header'>
                    <tr>
                        <td class='left'>Invoice No: <strong>$enquiryNumber</strong></td>
                        <td class='right'>Date: $orderedDateTime</td>
                    </tr>
                    <tr>
                        <td class='left bold'>Mobile: $adminMobile</td>
                        <td class='right bold'>E-mail: $adminEmail</td>
                    </tr>
                    <tr>
                        <td colspan='2' style='text-align:center; font-size:16px; font-weight:bold;'>
                            $adminName<br>
                            <span style='font-weight:normal;'>$adminAddress</span>
                        </td>
                    </tr>
                </table>

                <!-- Customer Details -->
                <h3 style='margin:8px 0 4px 0; font-weight:bold;'>Customer Details :</h3>
                <table style='width:100%; border-collapse:collapse; margin-bottom:10px;'>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; width:30%; text-align:left;'>Name</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; text-align:left;'>Phone</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerMobile, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; text-align:left;'>Email</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; text-align:left;'>Address</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerAddress . ', ' . $customerCity, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; text-align:left;'>State</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerState, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                    <tr>
                        <td style='border:1px solid #000; padding:6px 10px; font-weight:bold; background:#f3f4f6; text-align:left;'>Pincode</td>
                        <td style='border:1px solid #000; padding:6px 10px; text-align:left;'>" . htmlspecialchars($customerPincode, ENT_QUOTES, 'UTF-8') . "</td>
                    </tr>
                </table>

                <!-- Payment Method -->
                <h3 style='margin:6px 0 4px 0;'>Payment Method:
                    <span class='payment-badge'>$paymentLabel</span>
                </h3>

                <!-- Items Table -->
                <table>
                    <tr>
                        <th>S.No</th>
                        <th>Product Name</th>
                        <th>MRP (Inc. GST)</th>
                        <th>Discount (₹ / %)</th>
                        <th>Discounted Price</th>
                        <th>QTY</th>
                        <th>Amount (₹)</th>
                    </tr>
                    $rowsHtml
                    <tr>
                        <td colspan='6' class='right'>Gross Total</td>
                        <td>" . number_format($subtotal, 2) . "</td>
                    </tr>
                    <tr>
                        <td colspan='6' class='right'>Discount Amount (-" . number_format($subtotal > 0 ? ($totalDiscountAmount / $subtotal) * 100 : 0, 2) . "%)</td>
                        <td>- " . number_format($totalDiscountAmount, 2) . "</td>
                    </tr>
                    <tr>
                        <td colspan='6' class='right bold'>Net Amount</td>
                        <td class='bold'>" . number_format($netTotal, 2) . "</td>
                    </tr>
                    " . ($couponCode !== '' && $couponDiscount > 0 ? "
                    <tr>
                        <td colspan='6' class='right'>Coupon Discount &nbsp;<span style='font-style:italic;font-size:11px;'>(" . htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8') . ")</span></td>
                        <td style='color:#000;'>- " . number_format($couponDiscount, 2) . "</td>
                    </tr>" : "") . "
                    " . ($referralDiscount > 0 ? "
                    <tr>
                        <td colspan='6' class='right' style='color:#6d28d9;'>Referral Discount (First Order)</td>
                        <td style='color:#6d28d9; font-weight:bold;'>- " . number_format($referralDiscount, 2) . "</td>
                    </tr>" : "") . "
                    " . ($walletAmountUsed > 0 ? "
                    <tr>
                        <td colspan='6' class='right' style='color:#4338ca;'>&#128156; Wallet Used</td>
                        <td style='color:#4338ca; font-weight:bold;'>- " . number_format($walletAmountUsed, 2) . "</td>
                    </tr>" : "") . "
                    <tr>
                        <td colspan='6' class='right bold'>Shipping Charge</td>
                        <td class='bold'>" . number_format($shippingCharge, 2) . "</td>
                    </tr>
                    " . ($codConvenienceFee > 0 ? "
                    <tr>
                        <td colspan='6' class='right'>COD Convenience Fee</td>
                        <td>" . number_format($codConvenienceFee, 2) . "</td>
                    </tr>" : "") . "
                    <tr style='background:#f0fdf4;'>
                        <td colspan='6' class='right bold'>Overall Total</td>
                        <td class='bold'>" . number_format($overallTotal, 2) . "</td>
                    </tr>
                    $codAdvanceRows
                </table>

                <p class='bold'>Total Items: " . count($itemsBought) . "</p>
            </div>";

    // ── COD Advance summary box (only for advance orders) ─────────────────
    if ($isCodAdvance) {
        $advStatusText = ($paymentStatus === 'advance_paid') ? 'Paid ✓' : 'Pending';
        $html .= "
            <div class='advance-box' style='margin-top:12px;'>
                <p style='margin:0 0 6px 0; font-weight:bold; color:#92400e; font-size:13px;'>
                    💳 COD + Advance Payment Summary
                </p>
                <table style='border:none; font-size:12px;'>
                    <tr style='border:none;'>
                        <td style='border:none; text-align:left; width:60%;'>Order Total</td>
                        <td style='border:none; font-weight:bold;'>₹" . number_format($overallTotal, 2) . "</td>
                    </tr>
                    <tr style='border:none;'>
                        <td style='border:none; text-align:left; color:#92400e;'>
                            💳 Advance Paid Online (" . ($paymentStatus === 'advance_paid' ? 'Confirmed' : 'Status: '.$paymentStatus) . ")
                        </td>
                        <td style='border:none; font-weight:bold; color:#92400e;'>
                            ₹" . number_format($codAdvanceAmount, 2) . "
                        </td>
                    </tr>
                    <tr style='border:none;'>
                        <td style='border:none; text-align:left; color:#065f46;'>🏠 Balance to Pay in Cash on Delivery</td>
                        <td style='border:none; font-weight:bold; color:#065f46;'>₹" . number_format($balanceCod, 2) . "</td>
                    </tr>
                </table>
                <p style='margin:6px 0 0 0; font-size:11px; color:#78350f;'>
                    Please keep ₹" . number_format($balanceCod, 2) . " cash ready at the time of delivery.
                </p>
            </div>";
    }

    $html .= "
            <div'>
            </div>
        </div>
    </body>
    </html>";

    // ── Generate PDF ──────────────────────────────────────────────────────
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $billsDir = __DIR__ . '/bills';
    if (!is_dir($billsDir)) { mkdir($billsDir, 0777, true); }

    // ── FILENAME: invoice_{enquiryNo}.pdf ────────────────────────────────
    $fileName = "invoice_{$enquiryNumber}.pdf";
    $filePath = "$billsDir/$fileName";
    file_put_contents($filePath, $dompdf->output());

    // ── Send invoice email ────────────────────────────────────────────────
    function send_invoice_pdf($pdfPath, $userEmail, $userName, $adminEmail, $adminName, $smtpEmail, $smtpPassword) {
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug   = 0;
            $mail->Debugoutput = function($str, $level) { error_log("PHPMailer Debug [$level]: $str"); };
            $mail->isSMTP();
            $mail->Host        = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth    = true;
            $mail->Username    = $smtpEmail;
            $mail->Password    = $smtpPassword;
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port        = 587;

            $mail->setFrom($smtpEmail, $adminName);
            $mail->addAddress($userEmail, $userName);
            $mail->addBCC($adminEmail, $adminName);
            $mail->addAttachment($pdfPath);

            $downloadLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
                          . "://" . $_SERVER['HTTP_HOST'] . "/bills/" . basename($pdfPath);

            $mail->isHTML(true);
            $mail->Subject = 'Your Invoice - ' . basename($pdfPath, '.pdf');
            $mail->Body    = 'Dear ' . htmlspecialchars($userName ?? '', ENT_QUOTES, 'UTF-8') . ',<br><br>'
                           . 'Thank you for your order. Please find your invoice attached.<br><br>'
                           . 'You can also download it here: <a href="' . $downloadLink . '">' . $downloadLink . '</a><br><br>'
                           . 'Regards,<br>' . htmlspecialchars($adminName ?? '', ENT_QUOTES, 'UTF-8');
            $mail->AltBody = "Dear $userName,\n\nThank you for your order.\nDownload: $downloadLink\n\nRegards,\n$adminName";
            $mail->send();
            error_log("Invoice email sent to $userEmail");
        } catch (Exception $e) {
            error_log("Failed to send invoice email: " . $mail->ErrorInfo);
        }
    }

    send_invoice_pdf($filePath, $customerEmail, $customerName, $adminEmail, $adminName, $smtpEmail, $smtpPassword);

    echo $html;
    $conn = null;

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    echo "<p style='color:#dc2626;'>Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
    exit;
}
?>
<script>
function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex   = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
}
var shouldClearCart = getUrlParameter('cart_cleared');
if (shouldClearCart === 'true' && localStorage.getItem("cart")) {
    localStorage.removeItem("cart");
    console.log("Shopping cart cleared after successful order.");
}
</script>