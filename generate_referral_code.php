<?php
/**
 * generate_referral_code.php
 *
 * USAGE in register.php (after INSERT):
 *   require_once 'generate_referral_code.php';
 *   assignReferralCode($conn, $newUserId);
 *
 * USAGE in index.php / any landing page to track referral click:
 *   require_once 'generate_referral_code.php';
 *   captureReferralClick();
 */

if (!function_exists('assignReferralCode')) {
    function assignReferralCode(PDO $conn, int $userId): string {
        $s = $conn->prepare("SELECT referral_code FROM users WHERE id=? LIMIT 1");
        $s->execute([$userId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['referral_code'])) return $row['referral_code'];

        do {
            $code = 'RGM' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $chk  = $conn->prepare("SELECT id FROM users WHERE referral_code=? LIMIT 1");
            $chk->execute([$code]);
        } while ($chk->fetch());

        $conn->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$code, $userId]);
        return $code;
    }
}

if (!function_exists('captureReferralClick')) {
    /**
     * Call on any landing page.
     * If ?ref=CODE is in the URL, store the referrer's user id in session.
     */
    function captureReferralClick(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_GET['ref'])) {
            if (!isset($conn)) require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
            global $conn;
            $refCode = strtoupper(trim($_GET['ref']));
            $s = $conn->prepare("SELECT id FROM users WHERE referral_code=? LIMIT 1");
            $s->execute([$refCode]);
            $referrer = $s->fetch(PDO::FETCH_ASSOC);
            if ($referrer) {
                $_SESSION['referred_by'] = (int)$referrer['id'];
            }
        }
    }
}

if (!function_exists('saveReferralDiscountSnapshot')) {
    /**
     * Save the current referral discount rate into the user's record (version-control).
     * This locks in the discount at the time of referral so future admin changes do NOT
     * affect users who were already referred.
     *
     * Call immediately after setting referred_by on a new user.
     */
    function saveReferralDiscountSnapshot(PDO $conn, int $userId): void {
        try {
            $promo = $conn->query(
                "SELECT referral_enabled, referral_type, referral_percent, referral_amount
                 FROM promo_settings WHERE id=1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if (empty($promo['referral_enabled'])) {
                $conn->prepare(
                    "UPDATE users SET referral_discount_type='none', referral_discount_value=0 WHERE id=?"
                )->execute([$userId]);
                return;
            }

            $type  = ($promo['referral_type'] === 'fixed') ? 'fixed' : 'percent';
            $value = ($type === 'fixed')
                        ? (float)($promo['referral_amount']  ?? 0)
                        : (float)($promo['referral_percent'] ?? 0);

            $conn->prepare(
                "UPDATE users SET referral_discount_type=?, referral_discount_value=? WHERE id=?"
            )->execute([$type, $value, $userId]);

        } catch (Throwable $e) {
            error_log("saveReferralDiscountSnapshot error: " . $e->getMessage());
        }
    }
}

if (!function_exists('getUserReferralDiscount')) {
    /**
     * Retrieve the referral discount for a referred user.
     *
     * Priority:
     *   1. Use the locked-in snapshot (referral_discount_type / referral_discount_value)
     *      stored on the user row — set at registration time via saveReferralDiscountSnapshot().
     *   2. If the snapshot is still 'none'/0 (user registered BEFORE this fix was deployed),
     *      fall back to the CURRENT promo_settings AND auto-save the snapshot so the
     *      next call uses version-controlled data going forward.
     *
     * Returns ['type' => 'percent'|'fixed'|'none', 'value' => float]
     */
    function getUserReferralDiscount(PDO $conn, int $userId): array {
        try {
            $stmt = $conn->prepare(
                "SELECT referral_discount_type, referral_discount_value, referred_by,
                        referral_discount_used
                 FROM users WHERE id=? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // No row or no referrer — discount does not apply
            if (!$row || empty($row['referred_by'])) {
                return ['type' => 'none', 'value' => 0.0];
            }

            // ── ONE-TIME RULE ─────────────────────────────────────────────
            // The referral discount is consumed the moment it is used on any
            // order — even if that order is later cancelled.
            // referral_discount_used=1 is set in create_order.php at INSERT
            // time and is NEVER reset, so this check is the single gate.
            if (!empty($row['referral_discount_used'])) {
                return ['type' => 'none', 'value' => 0.0];
            }

            $snapType  = $row['referral_discount_type']  ?? 'none';
            $snapValue = (float)($row['referral_discount_value'] ?? 0);

            // Snapshot is valid — return it directly
            if ($snapType !== 'none' && $snapValue > 0) {
                return ['type' => $snapType, 'value' => $snapValue];
            }

            // ── Snapshot missing (existing user pre-fix) ─────────────────
            // Fall back to live promo_settings AND auto-save the snapshot
            // so the next call is instant and version-controlled going forward.
            $promo = $conn->query(
                "SELECT referral_enabled, referral_type, referral_percent, referral_amount
                 FROM promo_settings WHERE id=1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if (empty($promo['referral_enabled'])) {
                return ['type' => 'none', 'value' => 0.0];
            }

            $type  = ($promo['referral_type'] === 'fixed') ? 'fixed' : 'percent';
            $value = ($type === 'fixed')
                        ? (float)($promo['referral_amount']  ?? 0)
                        : (float)($promo['referral_percent'] ?? 0);

            if ($value <= 0) {
                return ['type' => 'none', 'value' => 0.0];
            }

            // Auto-save snapshot so future calls don't need this fallback
            $conn->prepare(
                "UPDATE users SET referral_discount_type=?, referral_discount_value=? WHERE id=?"
            )->execute([$type, $value, $userId]);

            return ['type' => $type, 'value' => $value];

        } catch (Throwable $e) {
            error_log("getUserReferralDiscount error: " . $e->getMessage());
            return ['type' => 'none', 'value' => 0.0];
        }
    }
}