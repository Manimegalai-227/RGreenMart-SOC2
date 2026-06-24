<?php
session_start();
header("Content-Type: application/json");
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
require_once "vendor/autoload.php";   // Razorpay PHP SDK

// ── Ensure all date/time operations use IST (Asia/Kolkata, UTC+5:30) ──────────
date_default_timezone_set('Asia/Kolkata');
// Also set the MySQL session timezone so NOW() / DEFAULT CURRENT_TIMESTAMP
// stores IST, not the server's UTC or OS timezone.
$conn->exec("SET time_zone = '+05:30'");

use Razorpay\Api\Api;

// USER LOGIN CHECK
$user_id = $_SESSION["user_id"] ?? 0;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User not logged in"]);
    exit;
}

// EMAIL VERIFIED CHECK — block order creation if email not yet verified
$evStmt = $conn->prepare("SELECT email_verified FROM users WHERE id = ? LIMIT 1");
$evStmt->execute([$user_id]);
$evRow = $evStmt->fetch(PDO::FETCH_ASSOC);
if (empty($evRow['email_verified'])) {
    echo json_encode(["success" => false, "message" => "Please verify your email address before placing an order."]);
    exit;
}

/**
 * Credit referral commission to the referrer's wallet after a successful first order.
 * Safe to call multiple times — checks referral_commission_credited flag.
 */
function creditReferralCommission(PDO $conn, int $orderId): void {
    try {
        $oStmt = $conn->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch(PDO::FETCH_ASSOC);

        // Guard: order must exist, not already credited, not cancelled
        if (!$order || !empty($order['referral_commission_credited'])) return;
        if ($order['status'] === 'cancelled') return;

        // ── KEY RULE ──────────────────────────────────────────────────────
        // Referrer earns commission ONLY when this specific order had a
        // referral discount applied. Commission = exact discount the buyer got.
        if ((float)($order['referral_discount'] ?? 0) <= 0) return;

        $userId = (int)$order['user_id'];

        $promo = $conn->query(
            "SELECT referral_enabled, referral_type, referral_percent, referral_amount
             FROM promo_settings WHERE id=1"
        )->fetch(PDO::FETCH_ASSOC);
        if (empty($promo['referral_enabled'])) return;

        $uStmt = $conn->prepare("SELECT referred_by FROM users WHERE id=? LIMIT 1");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $referrerId = (int)($uRow['referred_by'] ?? 0);
        if (!$referrerId) return;

        // Commission = the exact referral discount amount the buyer received
        $commission = round((float)$order['referral_discount'], 2);
        if ($commission <= 0) return;

        $conn->prepare("UPDATE users SET referral_wallet = referral_wallet + ? WHERE id=?")
             ->execute([$commission, $referrerId]);

        $conn->prepare(
            "INSERT INTO referral_transaction
             (earner_id, referred_id, order_id, order_amount, percent, earned_amount)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $referrerId, $userId, $orderId,
            (float)$order['overall_total'],
            (float)($promo['referral_percent'] ?? 0),
            $commission,
        ]);

        $conn->prepare("UPDATE orders SET referral_commission_credited=1 WHERE id=?")
             ->execute([$orderId]);

    } catch (Throwable $e) {
        error_log("creditReferralCommission error: " . $e->getMessage());
    }
}

// GET RAW JSON
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$address_id = $data["address_id"] ?? 0;
$cartItems  = $data["cart"]       ?? [];

if (!$address_id || empty($cartItems)) {
    echo json_encode(["success" => false, "message" => "Invalid address or empty cart"]);
    exit;
}

try {
    $conn->beginTransaction();

    // 1. NEW ENQUIRY NUMBER (locked)
    $stmt = $conn->query("SELECT last_enquiry_number FROM settings LIMIT 1 FOR UPDATE");
    $row  = $stmt->fetch();
    $enquiryNumber = ($row['last_enquiry_number'] ?? 1000) + 1;
    $conn->exec("UPDATE settings SET last_enquiry_number = $enquiryNumber");

    // 2. FETCH ADDRESS
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE id=? AND user_id=?");
    $stmt->execute([$address_id, $user_id]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$address) { throw new Exception("Invalid address"); }

    // 3. TOTALS
    $subtotal       = (float)($data["subtotal"]        ?? 0);
    $shippingcharge = (float)($data["shipping_charge"] ?? 0);
    $packing_charge = (float)($data["packing_charge"]  ?? 0);
    $net_total      = $subtotal + $shippingcharge + $packing_charge;
    $overall_total  = (float)($data["overall_total"]   ?? $net_total);

    // 3b. COUPON
    $coupon_code             = trim($data['coupon_code'] ?? '');
    $coupon_discount_amount  = (float)($data['coupon_discount_amount'] ?? 0);

    // 3c. REFERRAL DISCOUNT (first order)
    $referral_discount   = (float)($data['referral_discount']   ?? 0);

    // 3d. WALLET
    $wallet_amount_used  = (float)($data['wallet_amount_used']  ?? 0);

    // 4. MINIMUM ORDER CHECK
    $minRow       = $conn->query("SELECT minimum_order FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $minimumOrder = isset($minRow['minimum_order']) ? (float)$minRow['minimum_order'] : 0;
    if ($overall_total < $minimumOrder) {
        echo json_encode(["success" => false,
            "message" => "Minimum order amount is ₹" . number_format($minimumOrder, 2)]);
        $conn->rollBack();
        exit;
    }

    // 4b. VALIDATE REFERRAL DISCOUNT — verify against the user's locked-in snapshot
    //     so that tampered frontend values or stale JS calculations are corrected server-side.
    $promoRow = $conn->query("SELECT referral_enabled, referral_type, referral_percent, referral_amount, wallet_purchase_enabled FROM promo_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $referralEnabled       = (int)($promoRow['referral_enabled']       ?? 0);
    $walletPurchaseEnabled = (int)($promoRow['wallet_purchase_enabled'] ?? 0);

    if ($referral_discount > 0) {
        // ── ONE-TIME DISCOUNT GATE ────────────────────────────────────────
        // Fetch the user's referral state in one query (referred_by + used flag).
        // referral_discount_used=1 is set at INSERT time below and NEVER reset,
        // even on cancellation — so this is the single authoritative gate.
        $refGateStmt = $conn->prepare(
            "SELECT referred_by, referral_discount_used FROM users WHERE id=? LIMIT 1 FOR UPDATE"
        );
        $refGateStmt->execute([$user_id]);
        $refGateRow = $refGateStmt->fetch(PDO::FETCH_ASSOC);

        // Must have a referrer
        if (empty($refGateRow['referred_by'])) {
            $referral_discount = 0;
        }

        // Discount already used on a previous order (even a cancelled one) → reject
        if ($referral_discount > 0 && !empty($refGateRow['referral_discount_used'])) {
            $referral_discount = 0;
        }

        // Server-side: recalculate expected discount using getUserReferralDiscount()
        // which handles both snapshot users (new) and pre-fix users (falls back to promo_settings).
        // This also prevents frontend tampering by clamping to the server-calculated value.
        if ($referral_discount > 0) {
            require_once 'generate_referral_code.php';
            $serverDiscount = getUserReferralDiscount($conn, $user_id);
            if ($serverDiscount['type'] === 'none' || $serverDiscount['value'] <= 0) {
                $referral_discount = 0;
            } else {
                $expectedDiscount = ($serverDiscount['type'] === 'fixed')
                    ? $serverDiscount['value']
                    : round($subtotal * $serverDiscount['value'] / 100, 2);
                // Correct floating-point drift; clamp any suspicious discrepancy
                if (abs($referral_discount - $expectedDiscount) > 1.00) {
                    $referral_discount = $expectedDiscount;
                }
            }
        }
    }

    // 4c. VALIDATE WALLET USAGE
    if ($wallet_amount_used > 0) {
        if (!$walletPurchaseEnabled) {
            echo json_encode(["success" => false, "message" => "Wallet purchase is currently disabled. Please contact the administrator to enable this option."]);
            $conn->rollBack();
            exit;
        }
        // Fetch fresh wallet balance
        $wStmt = $conn->prepare("SELECT referral_wallet FROM users WHERE id=? LIMIT 1 FOR UPDATE");
        $wStmt->execute([$user_id]);
        $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);
        $freshWallet = (float)($wRow['referral_wallet'] ?? 0);
        if ($wallet_amount_used > $freshWallet) {
            echo json_encode(["success" => false, "message" => "Insufficient wallet balance. Available: ₹" . number_format($freshWallet, 2)]);
            $conn->rollBack();
            exit;
        }
        // Wallet cannot exceed the order total
        $wallet_amount_used = min($wallet_amount_used, $overall_total);
    }

    // 5. PAYMENT METHOD (from frontend)
    $rawMethod      = strtolower(trim($data['payment_method'] ?? 'online'));
    $isWalletMethod = ($rawMethod === 'wallet');
    // wallet is resolved to 'online' for DB storage; we handle it specially below
    $paymentMethod  = in_array($rawMethod, ['cod', 'cod_advance']) ? $rawMethod : 'online';

    // 6. COD + ADVANCE + CHARGE SETTINGS
    $stmtCod = $conn->prepare("SELECT cod_enabled, cod_advance_enabled, cod_advance_percent,
                                       cod_charge_enabled, cod_charge_type, cod_charge_value
                                FROM shipping_settings WHERE id = 1");
    $stmtCod->execute();
    $shipSet = $stmtCod->fetch(PDO::FETCH_ASSOC);

    $codEnabled         = (int)($shipSet['cod_enabled']          ?? 0);
    $codAdvanceEnabled  = (int)($shipSet['cod_advance_enabled']  ?? 0);
    $codAdvancePercent  = (float)($shipSet['cod_advance_percent'] ?? 0);
    $codChargeEnabled   = (int)($shipSet['cod_charge_enabled']   ?? 0);
    $codChargeType      = $shipSet['cod_charge_type']            ?? 'flat';
    $codChargeValue     = (float)($shipSet['cod_charge_value']   ?? 0);

    // Charge mode is active only when advance is NOT active
    $codChargeModeActive = ($codChargeEnabled && $codChargeValue > 0 && !$codAdvanceEnabled);

    if ($paymentMethod === 'cod' && !$codEnabled) {
        echo json_encode(["success" => false, "message" => "Cash on Delivery is currently disabled."]);
        exit;
    }

    // Server-side: calculate authoritative COD convenience fee
    $cod_convenience_fee = 0;
    if ($paymentMethod === 'cod' && $codChargeModeActive) {
        if ($codChargeType === 'percent') {
            $cod_convenience_fee = round($subtotal * $codChargeValue / 100, 2);
        } else {
            $cod_convenience_fee = round($codChargeValue, 2);
        }
        // Override overall_total to include the fee (server is authoritative)
        $overall_total = round($overall_total + $cod_convenience_fee, 2);
    }

    $initialPaymentStatus = 'pending';

    // 7. INSERT ORDER
    $stmt = $conn->prepare("
        INSERT INTO orders (
            enquiry_no, user_id, address_id,
            subtotal, packing_charge, shipping_charge, net_total, overall_total,
            courier_company_id, courier_name, estimated_delivery_days, etd,
            status, payment_method, payment_status,
            coupon_code, coupon_discount_amount,
            referral_discount, wallet_amount_used, cod_convenience_fee,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $enquiryNumber, $user_id, $address_id,
        $subtotal, $packing_charge, $shippingcharge, $net_total, $overall_total,
        $data['courier_company_id'] ?? null,
        $data['courier_name']       ?? null,
        $data['courier_eta']        ?? null,
        (preg_match('/^\d{4}-\d{2}-\d{2}/', $data['courier_etd'] ?? '') ? $data['courier_etd'] : null),
        $paymentMethod === 'cod_advance' ? 'cod_advance' : $paymentMethod,
        $initialPaymentStatus,
        $coupon_code ?: null,
        $coupon_discount_amount > 0 ? $coupon_discount_amount : null,
        $referral_discount > 0 ? $referral_discount : 0,
        $wallet_amount_used > 0 ? $wallet_amount_used : 0,
        $cod_convenience_fee > 0 ? $cod_convenience_fee : 0,
    ]);
    $orderId = $conn->lastInsertId();

    // ── CONSUME THE ONE-TIME REFERRAL DISCOUNT ───────────────────────────
    // Set referral_discount_used=1 immediately inside this transaction.
    // This flag is NEVER reset — not even on cancellation — so the referred
    // user can never claim the discount a second time regardless of outcome.
    if ($referral_discount > 0) {
        $conn->prepare("UPDATE users SET referral_discount_used = 1 WHERE id = ?")
             ->execute([$user_id]);
    }

    // Deduct wallet balance immediately if used
    if ($wallet_amount_used > 0) {
        $conn->prepare("UPDATE users SET referral_wallet = referral_wallet - ? WHERE id=? AND referral_wallet >= ?")
             ->execute([$wallet_amount_used, $user_id, $wallet_amount_used]);
    }

    // ════════════════════════════════════════════════════════════════════
    // 9a. WALLET FULL-COVERAGE BRANCH — wallet_amount_used == overall_total
    //     Wallet already deducted above; mark paid, no Razorpay needed.
    // ════════════════════════════════════════════════════════════════════
    if ($isWalletMethod) {
        // Server-side guard: wallet must cover the full order total
        if ($wallet_amount_used < $overall_total) {
            $conn->rollBack();
            echo json_encode(["success" => false, "message" => "Insufficient wallet balance to cover the full order total."]);
            exit;
        }
        $conn->prepare("UPDATE orders SET payment_status='paid', status='ordered', payment_method='online' WHERE id=?")->execute([$orderId]);
        $conn->commit();
        creditReferralCommission($conn, $orderId);
        unset($_SESSION['order_id']);
        echo json_encode([
            "success"        => true,
            "order_id"       => $orderId,
            "payment_method" => "wallet",
            "message"        => "Order placed and fully paid via wallet.",
        ]);
        exit;
    }

    // 8. INSERT ORDER ITEMS (schema-safe variant columns)
    $dbName = $_ENV['DB_NAME'] ?? (parse_url($_ENV['DATABASE_URL'] ?? '')['path'] ?? null);
    if (!$dbName) { $dbName = $conn->query('select database()')->fetchColumn(); }

    $colsStmt = $conn->prepare("SELECT column_name FROM information_schema.columns
                                 WHERE table_schema = ? AND table_name = 'order_items'");
    $colsStmt->execute([$dbName]);
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $hasVariantId          = in_array('variant_id',           $cols);
    $hasVariantWeightValue = in_array('variant_weight_value', $cols);
    $hasVariantWeightUnit  = in_array('variant_weight_unit',  $cols);
    $hasVariantPrice       = in_array('variant_price',        $cols);

    $baseCols = ['order_id','item_id','original_price','discount_percentage','discounted_price','quantity','amount'];
    if ($hasVariantId)          $baseCols[] = 'variant_id';
    if ($hasVariantWeightValue) $baseCols[] = 'variant_weight_value';
    if ($hasVariantWeightUnit)  $baseCols[] = 'variant_weight_unit';
    if ($hasVariantPrice)       $baseCols[] = 'variant_price';

    $placeholders = implode(', ', array_fill(0, count($baseCols), '?'));
    $insertSql    = 'INSERT INTO order_items (' . implode(',', $baseCols) . ') VALUES (' . $placeholders . ')';
    $stmt         = $conn->prepare($insertSql);

    foreach ($cartItems as $item) {
        $qty = $item["quantity"] ?? $item['qty'] ?? 1;

        // Authoritative selling price: always prefer variant_price (set by variant selection),
        // fall back to price. NEVER derive selling price from oldamt x discount — floating-point
        // math produces a different value than the actual DB price, causing invoice mismatches.
        $sellingPrice  = (float)($item['variant_price'] ?? $item['price'] ?? 0);

        // IMPORTANT: variant_old_price is authoritative for MRP — it comes from the SELECTED
        // variant, whereas oldamt comes from the product card (cheapest variant). Always prefer
        // variant_old_price so that the correct MRP is stored on the invoice.
        $variantOldPrice = isset($item['variant_old_price']) ? (float)$item['variant_old_price'] : null;
        $variantDiscount = isset($item['variant_discount'])  ? (float)$item['variant_discount']  : null;

        if ($variantOldPrice !== null && $variantOldPrice > 0) {
            $originalPrice = $variantOldPrice;
        } elseif ($variantOldPrice !== null && $variantOldPrice <= 0 && $variantDiscount !== null && $variantDiscount > 0 && $variantDiscount < 100 && $sellingPrice > 0) {
            // old_price missing in DB but discount% is set — derive MRP
            $originalPrice = $sellingPrice / (1 - $variantDiscount / 100);
        } else {
            // Final fallback: oldamt from product card (may be cheapest variant's price)
            $originalPrice = (float)($item['oldamt'] ?? $sellingPrice);
        }

        // Use variant_discount as authoritative discount% (from selected variant),
        // fall back to discountRate (from cheapest-variant card data) only if needed.
        if ($variantDiscount !== null && $variantDiscount > 0) {
            $discountPct = $variantDiscount;
        } elseif ($originalPrice > 0 && $originalPrice > $sellingPrice) {
            // Compute from actual prices — always accurate and consistent with invoice
            $discountPct = floor((($originalPrice - $sellingPrice) / $originalPrice) * 100);
        } else {
            $discountPct = (float)($item['discountRate'] ?? 0);
        }

        $params = [
            $orderId,
            $item["id"],
            $originalPrice,
            $discountPct,
            $sellingPrice,        // discounted_price = actual selling price stored in cart
            $qty,
            $sellingPrice * $qty,
        ];
        if ($hasVariantId)          $params[] = $item['variant_id']     ?? null;
        if ($hasVariantWeightValue) $params[] = $item['variant_weight'] ?? ($item['weight_value'] ?? null);
        if ($hasVariantWeightUnit)  $params[] = $item['variant_unit']   ?? ($item['weight_unit']  ?? null);
        if ($hasVariantPrice)       $params[] = $sellingPrice;           // variant_price = selling price
        $stmt->execute($params);
    }

    // ════════════════════════════════════════════════════════════════════
    // 9. COD BRANCH
    // ════════════════════════════════════════════════════════════════════
    if ($paymentMethod === 'cod') {

        // ── Case A: COD + Advance (partial Razorpay) ──────────────────
        if ($codAdvanceEnabled && $codAdvancePercent > 0) {

            $advanceAmount = round($overall_total * $codAdvancePercent / 100, 2);
            if ($advanceAmount < 1) $advanceAmount = 1.00;
            $balanceAmount = round($overall_total - $advanceAmount, 2);

            // Record advance amount on the order; payment_method stays 'cod'
            // payment_status stays 'pending' until Razorpay confirms (verify_payment.php sets 'advance_paid')
            $conn->prepare("
                UPDATE orders
                SET cod_advance_amount = ?,
                    payment_method     = 'cod_advance',
                    payment_status     = 'pending'
                WHERE id = ?
            ")->execute([$advanceAmount, $orderId]);

            $conn->commit();

            // Create Razorpay order for the ADVANCE amount only
            $api           = new Api($_ENV['RAZORPAY_KEY_ID'], $_ENV['RAZORPAY_KEY_SECRET']);
            $razorpayOrder = $api->order->create([
                "receipt"  => "ADV_" . $orderId,
                "amount"   => (int)round($advanceAmount * 100),
                "currency" => "INR",
                "notes"    => [
                    "type"             => "cod_advance",
                    "internal_order_id"=> $orderId,
                    "advance_percent"  => $codAdvancePercent,
                ],
            ]);
            $razorpayOrderId = $razorpayOrder["id"];

            // Save razorpay_order_id
            $conn->prepare("UPDATE orders SET razorpay_order_id = ? WHERE id = ?")
                 ->execute([$razorpayOrderId, $orderId]);

            // Fetch user for Razorpay prefill
            $uStmt = $conn->prepare("SELECT name, email, mobile FROM users WHERE id=?");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "success"           => true,
                "payment_method"    => "cod_advance",  // frontend branch key
                "order_id"          => $orderId,
                "razorpay_order_id" => $razorpayOrderId,
                "key"               => $_ENV['RAZORPAY_KEY_ID'],
                "amount"            => (int)round($advanceAmount * 100),
                "advance_amount"    => $advanceAmount,
                "balance_amount"    => $balanceAmount,
                "advance_percent"   => $codAdvancePercent,
                "overall_total"     => $overall_total,
                "prefill" => [
                    "name"    => $user["name"]   ?? '',
                    "email"   => $user["email"]  ?? '',
                    "contact" => $user["mobile"] ?? '',
                ],
                "message" => sprintf(
                    "Pay ₹%.2f online now (%g%% advance). Balance ₹%.2f to pay in cash on delivery.",
                    $advanceAmount, $codAdvancePercent, $balanceAmount
                ),
            ]);
            exit;
        }

        // ── Case B: COD + Convenience Fee (fee paid online, order paid on delivery) ──
        if ($codChargeModeActive && $cod_convenience_fee > 0) {

            $chargeAmount  = $cod_convenience_fee;
            // Balance = overall_total minus the fee (what customer pays in cash)
            $balanceAmount = round($overall_total - $chargeAmount, 2);

            // payment_status stays 'pending' until fee payment confirmed by verify_payment.php
            $conn->prepare("
                UPDATE orders
                SET payment_method  = 'cod',
                    payment_status  = 'pending'
                WHERE id = ?
            ")->execute([$orderId]);

            $conn->commit();

            // Create Razorpay order for the CONVENIENCE FEE only
            $api           = new Api($_ENV['RAZORPAY_KEY_ID'], $_ENV['RAZORPAY_KEY_SECRET']);
            $razorpayOrder = $api->order->create([
                "receipt"  => "CODFEE_" . $orderId,
                "amount"   => (int)round($chargeAmount * 100),
                "currency" => "INR",
                "notes"    => [
                    "type"              => "cod_charge",
                    "internal_order_id" => $orderId,
                    "charge_type"       => $codChargeType,
                    "charge_value"      => $codChargeValue,
                ],
            ]);
            $razorpayOrderId = $razorpayOrder["id"];

            $conn->prepare("UPDATE orders SET razorpay_order_id = ? WHERE id = ?")
                 ->execute([$razorpayOrderId, $orderId]);

            $uStmt = $conn->prepare("SELECT name, email, mobile FROM users WHERE id=?");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "success"           => true,
                "payment_method"    => "cod_charge",   // frontend branch key
                "order_id"          => $orderId,
                "razorpay_order_id" => $razorpayOrderId,
                "key"               => $_ENV['RAZORPAY_KEY_ID'],
                "amount"            => (int)round($chargeAmount * 100),
                "charge_amount"     => $chargeAmount,
                "balance_amount"    => $balanceAmount,
                "overall_total"     => $overall_total,
                "prefill" => [
                    "name"    => $user["name"]   ?? '',
                    "email"   => $user["email"]  ?? '',
                    "contact" => $user["mobile"] ?? '',
                ],
                "message" => sprintf(
                    "Pay COD fee ₹%.2f online now. Order amount ₹%.2f to be paid in cash on delivery.",
                    $chargeAmount, $balanceAmount
                ),
            ]);
            exit;
        }

        // ── Case C: Pure COD ─────────────────────────────────────────
        // Payment is collected on delivery — keep payment_status as 'pending'.
        // Only mark the order as 'ordered' so it enters the fulfilment workflow.
        $conn->prepare("UPDATE orders SET payment_status='pending', status='ordered' WHERE id=?")->execute([$orderId]);
        $conn->commit();

        // Do NOT credit referral commission here — COD is unpaid until delivery.
        // Call creditReferralCommission() from update_payment_status.php once
        // the seller confirms cash receipt.

        unset($_SESSION['order_id']);
        echo json_encode([
            "success"        => true,
            "order_id"       => $orderId,
            "payment_method" => "cod",
            "message"        => "Order placed with Cash on Delivery.",
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════
    // 10. ONLINE PAYMENT BRANCH (Razorpay full amount)
    // ════════════════════════════════════════════════════════════════════
    $api           = new Api($_ENV['RAZORPAY_KEY_ID'], $_ENV['RAZORPAY_KEY_SECRET']);
    $razorpayOrder = $api->order->create([
        "receipt"  => "ORDER_" . $orderId,
        "amount"   => (int)round($overall_total * 100),
        "currency" => "INR",
    ]);
    $razorpayOrderId = $razorpayOrder["id"];

    $conn->prepare("UPDATE orders SET razorpay_order_id = ?, payment_method = 'online' WHERE id = ?")
         ->execute([$razorpayOrderId, $orderId]);

    $conn->commit();

    $stmt = $conn->prepare("SELECT name, email, mobile FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success"           => true,
        "order_id"          => $orderId,
        "razorpay_order_id" => $razorpayOrderId,
        "key"               => $_ENV['RAZORPAY_KEY_ID'],
        "amount"            => (int)round($overall_total * 100),
        "prefill" => [
            "name"   => $user["name"],
            "email"  => $user["email"],
            "mobile" => $user["mobile"],
        ],
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}