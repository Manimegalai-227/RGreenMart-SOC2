<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/api/shiprocket.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {

    /* ----------------------------------------------------
       1. Fetch pickup pincode from settings
    ---------------------------------------------------- */
    $stmt = $conn->query("SELECT pickuplocation_pincode FROM settings LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $pickupPincode = trim($row['pickuplocation_pincode'] ?? '');
    if ($pickupPincode === '') {
        $pickupPincode = '625005';
    }

    // Validate pickup pincode: must be exactly 6 digits and not all zeros
    if (!preg_match('/^\d{6}$/', $pickupPincode) || $pickupPincode === '000000') {
        throw new Exception("Store pickup pincode is not configured. Please update it in Admin → Settings.");
    }

    /* ----------------------------------------------------
       2. Inputs
    ---------------------------------------------------- */
    $deliveryPincode = trim($_POST['pincode'] ?? '');
    $isCOD           = intval($_POST['cod'] ?? 0);
    $subtotal        = floatval($_POST['subtotal'] ?? 0);

    // Weight normalisation — convert to KG for all calculations.
    // Frontend may send weight in grams (weight_unit=g) or kilograms (weight_unit=kg).
    // If weight_unit is not sent, fall back to the old heuristic:
    //   values >= 100 are treated as grams, otherwise already in kg.
    $rawWeight  = floatval($_POST['weight']      ?? 0);
    $weightUnit = strtolower(trim($_POST['weight_unit'] ?? ''));

    // floatval('NaN') = 0 in PHP, but guard explicitly for clarity
    if (!is_numeric($_POST['weight'] ?? '')) {
        $rawWeight = 0;
    }

    if ($weightUnit === 'g') {
        $weight = $rawWeight / 1000;            // explicit grams → kg
    } elseif ($weightUnit === 'kg') {
        $weight = $rawWeight;                   // already kg — trust the value
    } else {
        // Legacy heuristic: >= 100 assumed to be grams, otherwise already kg
        $weight = ($rawWeight >= 100) ? $rawWeight / 1000 : $rawWeight;
    }

    // Minimum 100 g (0.1 kg) — prevents ₹1 charges from near-zero weights.
    // Do NOT raise this floor — 250 g must still give ₹25 at ₹100/kg.
    $weight = max(0.1, round($weight, 3));

    if ($deliveryPincode === '') {
        throw new Exception("Delivery pincode missing");
    }

    /* ----------------------------------------------------
       3. Fetch shipping settings
    ---------------------------------------------------- */
    $stmtSettings = $conn->prepare("SELECT * FROM shipping_settings WHERE id = 1");
    $stmtSettings->execute();
    $shippingSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

    $shippingMode    = $shippingSettings['shipping_mode'] ?? 'default';
    $brokerEnabled   = intval($shippingSettings['broker_enabled'] ?? 0);

    // Normalise: if broker_enabled=1 (legacy data), treat mode as broker
    if ($brokerEnabled && $shippingMode !== 'broker') {
        $shippingMode = 'broker';
    }

    // COD Convenience Fee settings
    $codChargeEnabled = intval($shippingSettings['cod_charge_enabled'] ?? 0);
    $codChargeType    = $shippingSettings['cod_charge_type'] ?? 'flat';
    $codChargeValue   = floatval($shippingSettings['cod_charge_value'] ?? 0);

    /* ----------------------------------------------------
       4. Call Shiprocket serviceability API
    ---------------------------------------------------- */
    $shiprocket = new Shiprocket($conn);
    $response = $shiprocket->request(
        'GET',
        '/courier/serviceability/',
        [
            'pickup_postcode'   => $pickupPincode,
            'delivery_postcode' => $deliveryPincode,
            'weight'            => $weight,
            'length'            => 10,
            'breadth'           => 10,
            'height'            => 5,
            'cod'               => $isCOD
        ]
    );

    $couriers = $response['data']['available_courier_companies'] ?? [];

    if (empty($couriers)) {
        throw new Exception("No courier available for this pincode");
    }

    /* ----------------------------------------------------
       5. Get Shiprocket recommended courier
    ---------------------------------------------------- */
    $recommendedCourierId =
        $response['data']['shiprocket_recommended_courier_id']
        ?? $response['data']['recommended_courier_company_id']
        ?? null;

    $selectedCourier = null;

    // Try Shiprocket recommended courier first
    if ($recommendedCourierId) {
        foreach ($couriers as $courier) {
            if ((int)$courier['courier_company_id'] === (int)$recommendedCourierId) {
                $selectedCourier = $courier;
                break;
            }
        }
    }

    // Fallback: pick cheapest courier
    if (!$selectedCourier) {
        $lowestRate = PHP_FLOAT_MAX;
        foreach ($couriers as $courier) {
            if (!empty($courier['blocked'])) continue;
            $rate        = floatval($courier['rate'] ?? 0);
            $codCharges  = $isCOD ? floatval($courier['cod_charges'] ?? 0) : 0;
            $finalRate   = $rate + $codCharges;
            if ($finalRate > 0 && $finalRate < $lowestRate) {
                $lowestRate      = $finalRate;
                $selectedCourier = $courier;
            }
        }
    }

    if (!$selectedCourier) {
        throw new Exception("Unable to determine courier");
    }

    /* ----------------------------------------------------
       6. Raw Shiprocket rate (always calculated)
    ---------------------------------------------------- */
    $rate              = floatval($selectedCourier['rate'] ?? 0);
    $codCharges        = $isCOD ? floatval($selectedCourier['cod_charges'] ?? 0) : 0;
    $actualCourierRate = round($rate + $codCharges, 2);

    /* ----------------------------------------------------
       7. Apply shipping mode rules to determine final rate
    ---------------------------------------------------- */
    $finalRate    = $actualCourierRate; // default fallback
    $courierName  = $selectedCourier['courier_name'];

    switch ($shippingMode) {

        case 'default':
            // Use raw Shiprocket recommended rate — no override
            $finalRate   = $actualCourierRate;
            break;

        case 'free':
            // Always free
            $finalRate   = 0;
            break;

        case 'conditional':
            $minOrder  = floatval($shippingSettings['conditional_min_order'] ?? 0);
            // Free if subtotal meets threshold, otherwise Shiprocket rate
            $finalRate = ($minOrder > 0 && $subtotal >= $minOrder) ? 0 : $actualCourierRate;
            break;

        case 'fixed':
            $calcType  = $shippingSettings['shipping_calculation'] ?? 'flat';
            if ($calcType === 'flat') {
                $finalRate = floatval($shippingSettings['fixed_charge'] ?? 0);
            } else {
                // Slab-based weight pricing:
                //   Admin sets a base slab weight (e.g. 0.5 kg) and a charge per slab (e.g. ₹50).
                //   Slabs = ceil(order_weight / slab_weight)
                //   Charge = slabs × rate_per_slab
                //
                //   Examples (slab=0.5 kg, rate=₹50):
                //     200 g  (0.200 kg) → ceil(0.200/0.5) = 1 slab → ₹50
                //     500 g  (0.500 kg) → ceil(0.500/0.5) = 1 slab → ₹50
                //     600 g  (0.600 kg) → ceil(0.600/0.5) = 2 slabs → ₹100
                //     1000 g (1.000 kg) → ceil(1.000/0.5) = 2 slabs → ₹100
                //     1100 g (1.100 kg) → ceil(1.100/0.5) = 3 slabs → ₹150
                //     1500 g (1.500 kg) → ceil(1.500/0.5) = 3 slabs → ₹150
                //     1700 g (1.700 kg) → ceil(1.700/0.5) = 4 slabs → ₹200
                $ratePerSlab = floatval($shippingSettings['weight_rate'] ?? 0);
                $slabWeight  = max(0.001, floatval($shippingSettings['weight_slab'] ?? 0.5));
                $slabs       = (int)ceil($weight / $slabWeight);
                $finalRate   = round($ratePerSlab * $slabs, 2);
                error_log("[Shipping] slab-based: {$weight} kg ÷ {$slabWeight} kg/slab = {$slabs} slabs × ₹{$ratePerSlab} = ₹{$finalRate}");
            }
            break;

        case 'broker':
            // Use broker's fixed charge and show broker's courier name
            $finalRate   = floatval($shippingSettings['broker_charge'] ?? 0);
            $courierName = $shippingSettings['broker_name'] ?? $selectedCourier['courier_name'];
            break;

        default:
            // Unknown mode — fall back to Shiprocket rate
            $finalRate = $actualCourierRate;
            break;
    }

    $finalRate = round($finalRate, 2);

    // ── Calculate COD Convenience Fee (only when payment is COD) ─────────
    $codConvenienceFee = 0;
    if ($isCOD && $codChargeEnabled && $codChargeValue > 0) {
        if ($codChargeType === 'percent') {
            // Percent is calculated on subtotal (items total, not including shipping)
            $codConvenienceFee = round($subtotal * $codChargeValue / 100, 2);
        } else {
            $codConvenienceFee = round($codChargeValue, 2);
        }
    }

    /* ----------------------------------------------------
       8. Send final response
    ---------------------------------------------------- */
    echo json_encode([
        'success'                   => true,
        'courier_id'                => $selectedCourier['courier_company_id'],
        'courier_name'              => $courierName,
        'rate'                      => $finalRate,
        'original_rate'             => $actualCourierRate,
        'is_free'                   => ($finalRate == 0),
        'estimated_delivery_days'   => $selectedCourier['estimated_delivery_days'] ?? '',
        'etd'                       => $selectedCourier['etd'] ?? '',
        'is_shiprocket_recommended' => (
            $recommendedCourierId &&
            (int)$selectedCourier['courier_company_id'] === (int)$recommendedCourierId
        ),
        'cod_convenience_fee'       => $codConvenienceFee,
        'cod_charge_type'           => $codChargeType,
        'cod_charge_value'          => $codChargeValue,
        'cod_charge_enabled'        => (bool)($isCOD && $codChargeEnabled),
        'weight_kg'                 => $weight,   // actual weight used for calculation (in kg)
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}