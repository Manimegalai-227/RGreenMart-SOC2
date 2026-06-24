<?php
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['user_id']);

// Fetch fresh user details if logged in
$headerUser      = null;
$referralWallet  = 0.0;
$referralEnabled = false;
$headerAddress   = null;
$referralStats   = ['count' => 0, 'people' => []];

if ($isLoggedIn) {
    if (!isset($conn)) {
        require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
    }
    $uStmt = $conn->prepare("SELECT id, name, email, mobile, referral_code, referral_wallet, referred_by, referral_discount_type, referral_discount_value, referral_discount_used, email_verified FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$_SESSION['user_id']]);
    $headerUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    // Guard: fetch() returns false when the session user_id no longer exists in the DB
    // (e.g. stale session after account deletion, or visiting register.php via a referral link
    //  while a ghost session is still present). Treat as not logged in to prevent fatal errors.
    if ($headerUser === false) {
        $headerUser  = null;
        $isLoggedIn  = false;
    } else {
        $referralWallet = (float)($headerUser['referral_wallet'] ?? 0);

        // Auto-generate referral code if missing
        if (empty($headerUser['referral_code'])) {
            require_once $_SERVER["DOCUMENT_ROOT"] . "/generate_referral_code.php";
            $headerUser['referral_code'] = assignReferralCode($conn, (int)$_SESSION['user_id']);
        }

    // Fetch default/latest address
    $aStmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC LIMIT 1");
    $aStmt->execute([$_SESSION['user_id']]);
    $headerAddress = $aStmt->fetch(PDO::FETCH_ASSOC);

    // Referral stats: people this user referred
    $rStmt = $conn->prepare("SELECT name, email FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 10");
    $rStmt->execute([$_SESSION['user_id']]);
    $referralPeople = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    $referralStats  = ['count' => count($referralPeople), 'people' => $referralPeople];

    try {
        $promoRow = $conn->query("SELECT referral_enabled, referral_type, referral_percent, referral_amount FROM promo_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $referralEnabled = !empty($promoRow['referral_enabled']);
        
        // Fetch referral discount details for notification
        $refDiscountText = "";
        if ($isLoggedIn && !empty($headerUser['referred_by'])) {
            
            // CHANGE: Instead of counting orders, check the new 'referral_discount_used' field
            if ((int)($headerUser['referral_discount_used'] ?? 0) === 0) {
                
                // Use snapshot from user record
                $snapType  = $headerUser['referral_discount_type']  ?? 'none';
                $snapValue = (float)($headerUser['referral_discount_value'] ?? 0);

                // Fallback for older users without a snapshot
                if (($snapType === 'none' || $snapValue <= 0) && $referralEnabled) {
                    $snapType  = $promoRow['referral_type']    ?? 'percent';
                    $snapValue = ($snapType === 'fixed')
                        ? (float)($promoRow['referral_amount']  ?? 0)
                        : (float)($promoRow['referral_percent'] ?? 0);
                }

                if ($snapType !== 'none' && $snapValue > 0) {
                    $val = ($snapType === 'percent')
                        ? $snapValue . '%'
                        : '₹' . number_format($snapValue, 2);
                    $refDiscountText = "🎁 You have a {$val} referral discount on your first order!";
                }
            }
        }
    } catch (Exception $e) { $referralEnabled = false; }
    } // end else (headerUser !== false)
}
?>
<!DOCTYPE html>
<html lang="en">
<head> 
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', 'G-PE2CJCXNGL');
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../Styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
    * { box-sizing: border-box; }

    /* ── TOP BAR ─────────────────────────────────────────────────── */
    .header-topbar {
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        padding: 6px 0;
        width: 100%;
    }
    .topbar-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0;
    }
    .topbar-link {
        color: rgba(255,255,255,0.88);
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        padding: 0 14px;
        border-right: 1px solid rgba(255,255,255,0.2);
        letter-spacing: 0.02em;
        transition: color 0.2s;
        white-space: nowrap;
    }
    .topbar-link:last-of-type {
        border-right: none;
    }

    .topbar-socials {
        display: flex;
        align-items: center;
        gap: 8px;
        padding-left: 16px;
        margin-left: 6px;
        border-left: 1px solid rgba(255,255,255,0.2);
    }
    .topbar-social-btn {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s, opacity 0.2s;
        text-decoration: none;
        flex-shrink: 0;
    }
    .topbar-social-btn:hover { transform: scale(1.12); opacity: 0.9; }
    .topbar-social-btn.fb  { background: #1877f2; }
    .topbar-social-btn.yt  { background: #ff0000; }
    .topbar-social-btn i   { color: #fff; font-size: 15px; }

    /* ── MAIN HEADER BAR ─────────────────────────────────────────── */
    #header {
        position: sticky;
        top: 0;
        z-index: 999;
        background: #fff;
        box-shadow: 0 1px 0 rgba(0,0,0,0.08), 0 4px 16px rgba(0,0,0,0.06);
    }
    .header-main-bar {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 70px;
    }

    /* Logo */
    .logo-container {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        flex-shrink: 0;
    }
    .sparkles-icon { width: 26px; color: #059669; flex-shrink:0; }
    .logo-text h1  { margin: 0; font-size: 21px; font-weight: 800; color: #064e3b; letter-spacing: -0.3px; line-height: 1.1; }
    .logo-text p   { margin: 0; font-size: 11px; color: #059669; font-weight: 500; letter-spacing: 0.04em; }

    /* Right icon cluster */
    .header-icons {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .hdr-icon-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        border: none;
        background: transparent;
        color: #064e3b;
        font-size: 20px;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.18s, color 0.18s;
        position: relative;
    }
    .hdr-icon-btn:hover {
        background: #f0fdf4;
        color: #047857;
    }

    /* Cart badge */
    .noticount {
        position: absolute;
        top: 4px;
        right: 4px;
        background: linear-gradient(135deg, #e91e63, #6a1b9a);
        color: #fff;
        font-size: 9px;
        font-weight: 700;
        min-width: 17px;
        height: 17px;
        line-height: 17px;
        text-align: center;
        border-radius: 50%;
        border: 2px solid #fff;
    }

    /* Mobile hamburger - hidden on desktop */
    .mobile-nav-toggle {
        display: none;
        width: 44px;
        height: 44px;
        align-items: center;
        justify-content: center;
        background: none;
        border: none;
        color: #064e3b;
        font-size: 22px;
        cursor: pointer;
        border-radius: 50%;
        transition: background 0.18s;
    }
    .mobile-nav-toggle:hover { background: #f0fdf4; }
    .hidden { display: none !important; }

    /* ── MOBILE NAV DRAWER ───────────────────────────────────────── */
    .mobile-nav {
        display: none;
        background: #fff;
        border-top: 1px solid #e5e7eb;
        padding: 12px 20px 16px;
    }
    .mobile-nav.active {
        display: block;
        animation: slideDown 0.25s ease;
    }
    @keyframes slideDown {
        from { opacity:0; transform:translateY(-8px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .mobile-nav ul {
        list-style: none; padding: 0; margin: 0;
        display: flex; flex-direction: column; gap: 2px;
    }
    .mobile-nav li a {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px; border-radius: 10px;
        color: #064e3b; font-size: 15px; font-weight: 600;
        text-decoration: none; transition: background 0.15s;
    }
    .mobile-nav li a:hover { background: #f0fdf4; }
    .mobile-nav li a i { width: 20px; text-align: center; font-size: 16px; }

    /* Mobile social row */
    .mobile-nav-socials {
        display: flex; gap: 10px;
        padding: 12px 12px 4px;
        border-top: 1px solid #f3f4f6;
        margin-top: 4px;
    }
    .mobile-social-btn {
        width: 36px; height: 36px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        text-decoration: none; transition: opacity 0.2s;
    }
    .mobile-social-btn:hover { opacity: 0.85; }
    .mobile-social-btn i { color: #fff; font-size: 16px; }

    /* ── ACCOUNT PANEL ───────────────────────────────────────────── */
    #accountPanel {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 340px;
        max-width: 93vw;
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.18);
        z-index: 99999;
        overflow: hidden;
        border: 1px solid #e5e7eb;
        max-height: 88vh;
        overflow-y: auto;
    }
    @media (min-width: 993px) {
        #accountPanel {
            position: absolute;
            top: 120%;
            left: auto;
            right: 0;
            transform: none;
            width: 340px;
            z-index: 9999;
        }
    }
    .account-panel-header {
        background: linear-gradient(135deg, #c2185b, #4a148c);
        padding: 18px 20px;
        display: flex; align-items: center; gap: 14px;
    }
    .account-avatar {
        width: 48px; height: 48px; border-radius: 50%;
        background: rgba(255,255,255,0.25);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: white; flex-shrink: 0;
    }
    .account-panel-header .user-name  { color:#fff; font-weight:700; font-size:15px; margin:0; }
    .account-panel-header .user-email { color:rgba(255,255,255,0.8); font-size:12px; margin:2px 0 0; word-break:break-all; }
    .account-panel-body { padding: 14px 18px; }
    .account-section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:#9ca3af; margin:10px 0 6px; }
    .account-info-row { display:flex; align-items:flex-start; gap:10px; padding:7px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; }
    .account-info-row:last-of-type { border-bottom:none; }
    .account-info-row i { color:#059669; width:18px; font-size:14px; flex-shrink:0; margin-top:2px; }
    .account-info-label { font-size:10px; color:#9ca3af; display:block; margin-bottom:1px; }
    .account-info-value { font-weight:600; color:#111827; word-break:break-all; }
    .referral-link-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:8px 10px; display:flex; align-items:center; gap:6px; margin-top:4px; }
    .referral-link-box span { font-size:11px; font-family:monospace; color:#065f46; flex:1; word-break:break-all; }
    .copy-btn { border:none; background:#059669; color:white; border-radius:6px; padding:3px 8px; font-size:11px; cursor:pointer; white-space:nowrap; }
    .referral-people-list { list-style:none; padding:0; margin:4px 0 0; }
    .referral-people-list li { font-size:12px; color:#374151; padding:3px 0; display:flex; align-items:center; gap:6px; }
    .referral-people-list li::before { content:"👤"; font-size:11px; }
    .account-panel-actions { padding:12px 18px 16px; display:flex; flex-direction:column; gap:8px; }
    .btn-edit-profile { display:flex; align-items:center; justify-content:center; gap:8px; padding:10px; background:#f0fdf4; color:#059669; border:1.5px solid #059669; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; width:100%; }
    .btn-edit-profile:hover { background:#dcfce7; }
    .btn-logout-panel { display:flex; align-items:center; justify-content:center; gap:8px; padding:10px; background:linear-gradient(135deg,#e91e63,#6a1b9a); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.2s; width:100%; }
    .btn-logout-panel:hover { background:linear-gradient(135deg,#c2185b,#4a148c); }

    /* ── EDIT PROFILE MODAL ──────────────────────────────────────── */
    #editProfileModal { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:99999; }
    .edit-modal-box { background:white; border-radius:14px; padding:24px; max-width:420px; width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto; }
    .edit-modal-box h2 { margin:0 0 16px; font-size:17px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; }
    .edit-modal-box h2 i { color:#059669; }
    .edit-field-group { margin-bottom:12px; }
    .edit-field-group label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
    .edit-field-group input { width:100%; border:1.5px solid #d1d5db; border-radius:8px; padding:9px 12px; font-size:13px; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
    .edit-field-group input:focus { border-color:#059669; }
    .edit-field-group .field-note { font-size:10px; color:#9ca3af; margin-top:3px; }
    .edit-section-divider { border:none; border-top:1px solid #e5e7eb; margin:14px 0; }
    .edit-section-label { font-size:11px; font-weight:700; text-transform:uppercase; color:#6b7280; margin-bottom:10px; letter-spacing:0.6px; }
    .otp-row { display:flex; gap:8px; align-items:flex-end; }
    .otp-row .edit-field-group { flex:1; margin-bottom:0; }
    .btn-send-otp { padding:9px 14px; background:#059669; color:white; border:none; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; transition:background 0.2s; }
    .btn-send-otp:hover { background:#047857; }
    .btn-send-otp:disabled { background:#9ca3af; cursor:not-allowed; }
    .edit-modal-actions { display:flex; gap:10px; margin-top:18px; }
    .btn-modal-cancel { flex:1; padding:10px; background:#f3f4f6; color:#374151; border:none; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; }
    .btn-modal-save { flex:2; padding:10px; background:linear-gradient(135deg,#059669,#064e3b); color:white; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
    #profileSaveMsg { font-size:12px; margin-top:8px; text-align:center; min-height:16px; }

    /* ── LOGOUT MODAL ────────────────────────────────────────────── */
    #logoutModal { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:99999; }
    .logout-modal-box { background:white; border-radius:12px; padding:28px; max-width:360px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.2); }
    .logout-modal-box h2 { margin:0 0 12px; font-size:18px; font-weight:700; color:#111827; }
    .logout-modal-box p  { margin:0 0 20px; color:#6b7280; font-size:14px; }
    .logout-modal-actions { display:flex; gap:10px; }
    .btn-logout-cancel  { flex:1; padding:10px; background:#f3f4f6; color:#374151; border:none; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; transition:background 0.2s; }
    .btn-logout-cancel:hover { background:#e5e7eb; }
    .btn-logout-confirm { flex:1; padding:10px; background:linear-gradient(135deg,#e91e63,#6a1b9a); color:white; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
    .btn-logout-confirm:hover { background:linear-gradient(135deg,#c2185b,#4a148c); }

    /* ── RESPONSIVE ──────────────────────────────────────────────── */
    @media (max-width: 992px) {
        .header-topbar { display: none; } /* hide top bar on mobile, show links in drawer */
        .mobile-nav-toggle { display: flex !important; }
        .desktop-icons-only { display: none !important; }   /* hide desktop icon cluster */
        .mobile-icon-cluster { display: flex !important; }  /* show mobile cart+hamburger */
    }
    @media (min-width: 993px) {
        .mobile-nav-toggle  { display: none !important; }
        .mobile-icon-cluster { display: none !important; }
        .desktop-icons-only  { display: flex !important; }
    }
    </style>
</head>

<body>
<header id="header">

    <!-- ══ TOP INFO BAR ══════════════════════════════════════════════ -->
    <div class="header-topbar">
        <div class="topbar-inner">
            <a href="/includes/ContactUs.php"   class="topbar-link">Contact Us</a>
            <a href="/includes/HealthyTips.php" class="topbar-link">Healthy Tips</a>
            <a href="/includes/About.php"       class="topbar-link">About Us</a>
            <div class="topbar-socials">
                <a href="https://www.facebook.com/people/RGreenMart/61584629313778/"
                   target="_blank" rel="noopener" class="topbar-social-btn fb" title="Facebook">
                    <i class="fa-brands fa-facebook-f"></i>
                </a>
                <a href="https://www.youtube.com/@RGreenmart"
                   target="_blank" rel="noopener" class="topbar-social-btn yt" title="YouTube">
                    <i class="fa-brands fa-youtube"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- ══ MAIN BAR ══════════════════════════════════════════════════ -->
    <div class="header-main-bar">

        <!-- LOGO (left) -->
        <a href="/index.php" class="logo-container">
            <svg class="sparkles-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 9.143 15.143 12l2.286 6.857L12 15.143 6.857 18 9.143 11.143 3 8l5.714 2.857L12 3z"/>
            </svg>
            <div class="logo-text">
                <h1>RGreenMart</h1>
                <p>Fresh. Pure. Premium.</p>
            </div>
        </a>

        <!-- DESKTOP icon cluster (right) — icons only, no text -->
        <div class="header-icons desktop-icons-only" style="position:relative;">
            <!-- Home -->
            <a href="/index.php" class="hdr-icon-btn" title="Home">
                <i class="fa-solid fa-house"></i>
            </a>

            <?php if ($isLoggedIn): ?>
            <!-- My Orders -->
            <a href="/my_orders.php" class="hdr-icon-btn" title="My Orders">
                <i class="fa-solid fa-box"></i>
            </a>
            <!-- Account -->
            <button id="userBtn" class="hdr-icon-btn" title="Account">
                <i class="fa-solid fa-user"></i>
            </button>
            <?php else: ?>
            <!-- Login -->
            <a href="/login.php" class="hdr-icon-btn" title="Login">
                <i class="fa-solid fa-right-to-bracket"></i>
            </a>
            <?php endif; ?>

            <!-- Cart -->
            <a href="/viewcart.php" class="hdr-icon-btn" title="Cart">
                <i class="fa-solid fa-cart-shopping"></i>
                <span id="cartCount" class="noticount">0</span>
            </a>
        </div>

        <!-- MOBILE cluster: cart badge + hamburger -->
        <div class="mobile-icon-cluster" style="display:none; align-items:center; gap:4px;">
            <a href="/viewcart.php" class="hdr-icon-btn" title="Cart">
                <i class="fa-solid fa-cart-shopping"></i>
                <span id="cartCountMobile" class="noticount">0</span>
            </a>
            <button class="mobile-nav-toggle" aria-label="Toggle menu" aria-expanded="false" onclick="toggleMobileNav()">
                <svg id="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <svg id="close-icon" class="hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

    </div><!-- /.header-main-bar -->

    <!-- ══ MOBILE DRAWER ═════════════════════════════════════════════ -->
    <div class="mobile-nav" id="mobile-nav" aria-hidden="true">
        <nav aria-label="Mobile navigation">
            <ul>
                <li><a href="/index.php"><i class="fa-solid fa-house"></i> Home</a></li>
                <li><a href="/includes/About.php"><i class="fa-solid fa-circle-info"></i> About Us</a></li>
                <li><a href="/includes/HealthyTips.php"><i class="fa-solid fa-heart-pulse"></i> Healthy Tips</a></li>
                <li><a href="/includes/ContactUs.php"><i class="fa-solid fa-phone"></i> Contact Us</a></li>
                <?php if ($isLoggedIn): ?>
                    <li><a href="/my_orders.php"><i class="fa-solid fa-box"></i> My Orders</a></li>
                    <li><a href="#" onclick="toggleMobileNav(); setTimeout(openAccountPanel,200); return false;"><i class="fa-solid fa-user"></i> My Account</a></li>
                    <?php if ($referralEnabled && $referralWallet > 0): ?>
                    <li><a href="#" style="color:#d97706;"><i class="fa-solid fa-wallet"></i> Wallet: ₹<?= number_format($referralWallet, 2) ?></a></li>
                    <?php endif; ?>
                    <li><a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
                <?php else: ?>
                    <li><a href="/login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a></li>
                    <li><a href="/register.php"><i class="fa-solid fa-user-plus"></i> Register</a></li>
                <?php endif; ?>
            </ul>
            <!-- Social icons in mobile drawer -->
            <div class="mobile-nav-socials">
                <a href="https://www.facebook.com/people/RGreenMart/61584629313778/"
                   target="_blank" class="mobile-social-btn" style="background:#1877f2;" title="Facebook">
                    <i class="fa-brands fa-facebook-f"></i>
                </a>
                <a href="https://www.youtube.com/@RGreenmart"
                   target="_blank" class="mobile-social-btn" style="background:#ff0000;" title="YouTube">
                    <i class="fa-brands fa-youtube"></i>
                </a>
            </div>
        </nav>
    </div>

    <!-- MOBILE BACKDROP -->
    <div id="accountPanelBackdrop"
         style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:99998;"
         onclick="closeAccountPanel()">
    </div>

    </header>

    <?php if ($isLoggedIn): ?>
    <!-- ===== ACCOUNT PANEL ===== -->
    <div id="accountPanel" class="hidden">
        <!-- Panel Header -->
        <div class="account-panel-header">
            <div class="account-avatar" style="background:rgba(255,255,255,0.2);border:2px solid rgba(255,255,255,0.4);">
                <i class="fa-solid fa-circle-user" style="font-size:26px;"></i>
            </div>
            <div>
                <p class="user-name"><?= htmlspecialchars($headerUser['name'] ?? 'User') ?></p>
                <p class="user-email"><?= htmlspecialchars($headerUser['email'] ?? '') ?></p>
            </div>
        </div>

        <!-- Info Body -->
        <div class="account-panel-body">

            <?php if (!empty($refDiscountText)): ?>
            <div style="background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;text-align:center;margin-bottom:12px;line-height:1.4;">
                <?= htmlspecialchars($refDiscountText) ?>
            </div>
            <?php endif; ?>

            <!-- Personal Info -->
            <div class="account-section-title">Personal Info</div>
            <div class="account-info-row">
                <i class="fa-solid fa-user"></i>
                <div>
                    <span class="account-info-label">Full Name</span>
                    <span class="account-info-value"><?= htmlspecialchars($headerUser['name'] ?? '—') ?></span>
                </div>
            </div>
            <div class="account-info-row">
                <i class="fa-solid fa-phone"></i>
                <div>
                    <span class="account-info-label">Mobile</span>
                    <span class="account-info-value"><?= htmlspecialchars($headerUser['mobile'] ?? '—') ?></span>
                </div>
            </div>
            <div class="account-info-row">
                <i class="fa-solid fa-envelope"></i>
                <div style="width:100%">
                    <span class="account-info-label">Email</span>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="account-info-value"><?= htmlspecialchars($headerUser['email'] ?? '—') ?></span>
                        <?php if (!empty($headerUser['email_verified'])): ?>
                            <span title="Email Verified" style="display:inline-flex;align-items:center;gap:4px;
                                background:#d1fae5;color:#065f46;font-size:11px;font-weight:700;
                                padding:2px 8px;border-radius:20px;border:1px solid #6ee7b7;">
                                ✅ Verified
                            </span>
                        <?php else: ?>
                            <button onclick="openVerifyEmailModal(); closeAccountPanel();"
                                style="display:inline-flex;align-items:center;gap:4px;
                                    background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;
                                    padding:2px 8px;border-radius:20px;border:1px solid #fcd34d;
                                    cursor:pointer;transition:background 0.2s;"
                                onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                                ⚠️ Verify Email
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($headerAddress): ?>
            <div class="account-section-title" style="margin-top:12px;">Delivery Address</div>
            <div class="account-info-row">
                <i class="fa-solid fa-location-dot"></i>
                <div>
                    <span class="account-info-label"><?= htmlspecialchars($headerAddress['contact_name']) ?> &bull; <?= htmlspecialchars($headerAddress['contact_mobile']) ?></span>
                    <span class="account-info-value" style="font-size:12px;line-height:1.5;">
                        <?= htmlspecialchars($headerAddress['address_line1']) ?>
                        <?php if ($headerAddress['address_line2']): ?>, <?= htmlspecialchars($headerAddress['address_line2']) ?><?php endif; ?><br>
                        <?= htmlspecialchars($headerAddress['city']) ?>, <?= htmlspecialchars($headerAddress['state']) ?> – <?= htmlspecialchars($headerAddress['pincode']) ?>
                        <?php if ($headerAddress['landmark']): ?><br>Landmark: <?= htmlspecialchars($headerAddress['landmark']) ?><?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Wallet -->
            <div class="account-section-title" style="margin-top:12px;">Referral &amp; Wallet</div>
            <div class="account-info-row">
                <i class="fa-solid fa-wallet"></i>
                <div style="width:100%">
                    <span class="account-info-label">Wallet Balance</span>
                    <span class="account-info-value" style="color:#059669;font-size:15px;">₹<?= number_format($referralWallet, 2) ?></span>
                </div>
            </div>

            <?php if (!empty($headerUser['referral_code'])): ?>
            <div class="account-info-row">
                <i class="fa-solid fa-share-nodes"></i>
                <div style="width:100%">
                    <span class="account-info-label">Your Referral Link</span>
                    <?php
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $referralLink = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/register.php?ref=' . $headerUser['referral_code'];
                    ?>
                    <div class="referral-link-box">
                        <span id="referralLinkText"><?= htmlspecialchars($referralLink) ?></span>
                        <button class="copy-btn" onclick="copyReferralLink()">Copy</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($referralStats['count'] > 0): ?>
            <div class="account-info-row">
                <i class="fa-solid fa-users"></i>
                <div style="width:100%">
                    <span class="account-info-label">Referred Friends (<?= $referralStats['count'] ?>)</span>
                    <ul class="referral-people-list">
                        <?php foreach ($referralStats['people'] as $rp): ?>
                        <li><?= htmlspecialchars($rp['name'] ?: $rp['email']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php else: ?>
            <div class="account-info-row">
                <i class="fa-solid fa-users"></i>
                <div>
                    <span class="account-info-label">Referred Friends</span>
                    <span class="account-info-value" style="font-weight:400;color:#9ca3af;font-size:12px;">No referrals yet</span>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.account-panel-body -->

        <!-- Actions -->
        <div class="account-panel-actions">
            <button class="btn-edit-profile" id="editProfileBtn">
                <i class="fa-solid fa-pen-to-square"></i> Edit Profile
            </button>
            <button class="btn-logout-panel" id="logoutBtn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </button>
        </div>
    </div><!-- /#accountPanel -->
    <?php endif; ?>

    <!-- ===== LOGOUT MODAL ===== -->
    <div id="logoutModal" class="hidden">
        <div class="logout-modal-box">
            <h2>Confirm Logout</h2>
            <p>Are you sure you want to logout?</p>
            <div class="logout-modal-actions">
                <button class="btn-logout-cancel" id="cancelLogout">Cancel</button>
                <button class="btn-logout-confirm" id="confirmLogout">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </button>
            </div>
        </div>
    </div>

    <!-- ===== VERIFY EMAIL MODAL ===== -->
    <div id="verifyEmailModal" class="hidden" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);
         display:flex;align-items:center;justify-content:center;z-index:99999;">
        <div style="background:#fff;border-radius:14px;padding:28px 24px;max-width:400px;width:92%;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);position:relative;">
            <button onclick="closeVerifyEmailModal()"
                style="position:absolute;top:14px;right:16px;background:none;border:none;
                       font-size:20px;cursor:pointer;color:#9ca3af;">&times;</button>
            <h2 style="margin:0 0 6px;font-size:17px;font-weight:700;color:#111827;">
                ✉️ Verify Your Email
            </h2>
            <p style="font-size:13px;color:#6b7280;margin:0 0 18px;" id="verifyEmailSubtitle">
                We'll send a 6-digit OTP to your registered email address.
            </p>
            <!-- OTP input — hidden until OTP sent -->
            <div id="verifyOtpWrap" style="display:none;margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Enter OTP</label>
                <input type="text" id="verifyOtpInput" maxlength="6" placeholder="6-digit OTP"
                    style="width:100%;border:1.5px solid #d1d5db;border-radius:8px;padding:9px 12px;
                           font-size:16px;letter-spacing:6px;text-align:center;outline:none;box-sizing:border-box;">
                <div style="font-size:10px;color:#9ca3af;margin-top:3px;">Check your inbox (and spam folder).</div>
            </div>
            <div id="verifyEmailMsg" style="font-size:12px;min-height:16px;margin-bottom:10px;text-align:center;"></div>
            <div style="display:flex;gap:10px;">
                <button onclick="closeVerifyEmailModal()"
                    style="flex:1;padding:10px;background:#f3f4f6;color:#374151;border:none;
                           border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">Cancel</button>
                <button id="verifyEmailBtn" onclick="handleVerifyEmailBtn()"
                    style="flex:2;padding:10px;background:linear-gradient(135deg,#e91e63,#6a1b9a);
                           color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">
                    Send OTP
                </button>
            </div>
        </div>
    </div>

    <!-- ===== EDIT PROFILE MODAL ===== -->
    <div id="editProfileModal" class="hidden">
        <div class="edit-modal-box">
            <h2><i class="fa-solid fa-pen-to-square"></i> Edit Profile</h2>

            <div class="edit-section-label">Personal Details</div>
            <div class="edit-field-group">
                <label>Full Name</label>
                <input type="text" id="epName" placeholder="Your full name">
            </div>
            <div class="edit-field-group">
                <label>Mobile Number</label>
                <input type="tel" id="epMobile" placeholder="10-digit mobile" maxlength="10">
            </div>

            <hr class="edit-section-divider">
            <div class="edit-section-label">Email <span style="font-weight:400;color:#9ca3af;">(OTP required to change)</span></div>
            <div class="otp-row">
                <div class="edit-field-group">
                    <label>New Email</label>
                    <input type="email" id="epEmail" placeholder="your@email.com">
                </div>
                <button class="btn-send-otp" id="sendOtpBtn" onclick="sendOtp()">Send OTP</button>
            </div>
            <div class="edit-field-group" id="otpFieldWrap" style="display:none; margin-top:10px;">
                <label>Enter OTP</label>
                <input type="text" id="epOtp" placeholder="6-digit OTP" maxlength="6">
                <div class="field-note">Check your new email inbox for the OTP.</div>
            </div>

            <div id="profileSaveMsg"></div>

            <div class="edit-modal-actions">
                <button class="btn-modal-cancel" id="closeEditModal">Cancel</button>
                <button class="btn-modal-save" onclick="saveProfile()">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <script>
    /* ===== MOBILE NAV TOGGLE ===== */
    function toggleMobileNav() {
        const mobileNav    = document.getElementById('mobile-nav');
        const menuIcon     = document.getElementById('menu-icon');
        const closeIcon    = document.getElementById('close-icon');
        const toggleButton = document.querySelector('.mobile-nav-toggle');
        const isExpanded   = toggleButton.getAttribute('aria-expanded') === 'true';
        mobileNav.classList.toggle('active');
        menuIcon.classList.toggle('hidden');
        closeIcon.classList.toggle('hidden');
        mobileNav.setAttribute('aria-hidden', String(isExpanded));
        toggleButton.setAttribute('aria-expanded', String(!isExpanded));
    }
    document.querySelector('.mobile-nav-toggle')
        ?.addEventListener('keydown', (e) => {
            if (e.key==='Enter'||e.key===' '){e.preventDefault();toggleMobileNav();}
        });

    /* ===== ACCOUNT PANEL ===== */
    const userBtn      = document.getElementById('userBtn');
    const accountPanel = document.getElementById('accountPanel');
    const backdrop     = document.getElementById('accountPanelBackdrop');

    function openAccountPanel() {
        accountPanel.classList.remove('hidden');
        accountPanel.style.display = '';
        // On desktop: position below the userBtn
        if (window.innerWidth >= 993 && userBtn) {
            const rect = userBtn.getBoundingClientRect();
            accountPanel.style.position = 'fixed';
            accountPanel.style.top      = (rect.bottom + 8) + 'px';
            accountPanel.style.left     = 'auto';
            accountPanel.style.right    = (window.innerWidth - rect.right) + 'px';
            accountPanel.style.transform = 'none';
        } else {
            // Mobile: centered
            accountPanel.style.position  = 'fixed';
            accountPanel.style.top       = '50%';
            accountPanel.style.left      = '50%';
            accountPanel.style.right     = 'auto';
            accountPanel.style.transform = 'translate(-50%, -50%)';
            backdrop.style.display = 'block';
        }
    }
    function closeAccountPanel() {
        accountPanel.classList.add('hidden');
        backdrop.style.display = 'none';
    }
    if (userBtn && accountPanel) {
        userBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            accountPanel.classList.contains('hidden') ? openAccountPanel() : closeAccountPanel();
        });
        document.addEventListener('click', (e) => {
            if (!userBtn.contains(e.target) && !accountPanel.contains(e.target)) closeAccountPanel();
        });
    }

    /* ===== LOGOUT MODAL ===== */
    const logoutBtn     = document.getElementById('logoutBtn');
    const logoutModal   = document.getElementById('logoutModal');
    const cancelLogout  = document.getElementById('cancelLogout');
    const confirmLogout = document.getElementById('confirmLogout');
    logoutBtn?.addEventListener('click', () => { closeAccountPanel(); logoutModal.classList.remove('hidden'); });
    cancelLogout?.addEventListener('click',  () => logoutModal.classList.add('hidden'));
    confirmLogout?.addEventListener('click', () => window.location.href = 'logout.php');
    logoutModal?.addEventListener('click', (e) => { if (e.target===logoutModal) logoutModal.classList.add('hidden'); });

    /* ===== EDIT PROFILE MODAL ===== */
    const editProfileModal = document.getElementById('editProfileModal');
    const editProfileBtn   = document.getElementById('editProfileBtn');
    const closeEditModalBtn = document.getElementById('closeEditModal');

    // Pre-fill current values from PHP
    const _currentName   = <?php echo json_encode($headerUser['name'] ?? ''); ?>;
    const _currentMobile = <?php echo json_encode($headerUser['mobile'] ?? ''); ?>;
    const _currentEmail  = <?php echo json_encode($headerUser['email'] ?? ''); ?>;

    editProfileBtn?.addEventListener('click', () => {
        closeAccountPanel();
        document.getElementById('epName').value   = _currentName;
        document.getElementById('epMobile').value = _currentMobile;
        document.getElementById('epEmail').value  = '';  // User must type new email explicitly
        document.getElementById('epEmail').placeholder = _currentEmail ? 'Current: ' + _currentEmail + ' — enter new email' : 'your@email.com';
        document.getElementById('profileSaveMsg').textContent = '';
        document.getElementById('otpFieldWrap').style.display = 'none';
        otpSent = false;
        editProfileModal.classList.remove('hidden');
    });
    closeEditModalBtn?.addEventListener('click', () => editProfileModal.classList.add('hidden'));
    editProfileModal?.addEventListener('click', (e) => { if(e.target===editProfileModal) editProfileModal.classList.add('hidden'); });

    /* ===== OTP FLOW ===== */
    let otpSent = false;

    async function sendOtp() {
        const email = document.getElementById('epEmail').value.trim();
        if (!email || email === _currentEmail) { showProfileMsg('Enter a different email first.', 'red'); return; }
        const btn = document.getElementById('sendOtpBtn');
        btn.disabled = true; btn.textContent = 'Sending…';
        const fd = new FormData();
        fd.append('action', 'send_otp');
        fd.append('email', email);
        let data;
        try {
            const res = await fetch('/update_profile.php', {method:'POST', body:fd});
            data = await res.json();
        } catch(fetchErr) {
            btn.disabled = false; btn.textContent = 'Send OTP';
            showProfileMsg('Network error. Please try again.', 'red'); return;
        }
        btn.disabled = false; btn.textContent = 'Send OTP';
        if (data.success) {
            otpSent = true;
            document.getElementById('otpFieldWrap').style.display = 'block';
            showProfileMsg('OTP sent to ' + email, 'green');
        } else {
            showProfileMsg(data.message || 'Failed to send OTP.', 'red');
        }
    }

    /* ===== SAVE PROFILE ===== */
    async function saveProfile() {
        const name   = document.getElementById('epName').value.trim();
        const mobile = document.getElementById('epMobile').value.trim();
        const email  = document.getElementById('epEmail').value.trim();
        const otp    = document.getElementById('epOtp').value.trim();
        if (!name) { showProfileMsg('Name is required.', 'red'); return; }
        // If email blank = user didn't change it; use current
        const effectiveEmail = email || _currentEmail;
        const emailChanged   = email !== '' && email !== _currentEmail;
        if (emailChanged && !otpSent) { showProfileMsg('Send OTP to verify new email first.', 'red'); return; }
        if (emailChanged && !otp)    { showProfileMsg('Please enter the OTP.', 'red'); return; }
        const fd = new FormData();
        fd.append('action', 'save_profile');
        fd.append('name',   name);
        fd.append('mobile', mobile);
        fd.append('email',  effectiveEmail);
        if (emailChanged) fd.append('otp', otp);
        let data;
        try {
            const res = await fetch('/update_profile.php', {method:'POST', body:fd});
            data = await res.json();
        } catch(fetchErr) {
            showProfileMsg('Network error. Please try again.', 'red'); return;
        }
        if (data.success) {
            showProfileMsg('Profile updated!', 'green');
            setTimeout(() => location.reload(), 1200);
        } else {
            showProfileMsg(data.message || 'Update failed.', 'red');
        }
    }

    function showProfileMsg(msg, color) {
        const el = document.getElementById('profileSaveMsg');
        el.textContent = msg;
        el.style.color = color==='green' ? '#059669' : '#dc2626';
    }

    /* ===== VERIFY EMAIL MODAL ===== */
    let _verifyOtpSent = false;

    function openVerifyEmailModal() {
        _verifyOtpSent = false;
        document.getElementById('verifyOtpWrap').style.display = 'none';
        document.getElementById('verifyOtpInput').value = '';
        document.getElementById('verifyEmailMsg').textContent = '';
        document.getElementById('verifyEmailBtn').textContent = 'Send OTP';
        document.getElementById('verifyEmailSubtitle').textContent = "We\'ll send a 6-digit OTP to your registered email address.";
        document.getElementById('verifyEmailModal').classList.remove('hidden');
    }
    function closeVerifyEmailModal() {
        document.getElementById('verifyEmailModal').classList.add('hidden');
    }
    document.getElementById('verifyEmailModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeVerifyEmailModal();
    });

    async function handleVerifyEmailBtn() {
        const btn = document.getElementById('verifyEmailBtn');
        const msgEl = document.getElementById('verifyEmailMsg');

        if (!_verifyOtpSent) {
            // Step 1: Send OTP
            btn.disabled = true; btn.textContent = 'Sending…';
            const fd = new FormData();
            fd.append('action', 'send_verify_otp');
            try {
                const res  = await fetch('/update_profile.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch(pe) {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = 'Server error. Please try again.';
                    console.error('Non-JSON response:', text);
                    btn.disabled = false; btn.textContent = 'Send OTP'; return;
                }
                if (data.success) {
                    _verifyOtpSent = true;
                    document.getElementById('verifyOtpWrap').style.display = 'block';
                    document.getElementById('verifyEmailSubtitle').textContent = data.message;
                    btn.textContent = 'Verify OTP';
                    msgEl.style.color = '#059669';
                    msgEl.textContent = 'OTP sent! Check your inbox.';
                } else {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = data.message || 'Failed to send OTP.';
                    btn.textContent = 'Send OTP';
                }
            } catch(e) {
                msgEl.style.color = '#dc2626';
                msgEl.textContent = 'Connection error: ' + e.message;
                btn.textContent = 'Send OTP';
            }
            btn.disabled = false;
        } else {
            // Step 2: Verify OTP
            const otp = document.getElementById('verifyOtpInput').value.trim();
            if (!otp) { msgEl.style.color='#dc2626'; msgEl.textContent='Enter the OTP first.'; return; }
            btn.disabled = true; btn.textContent = 'Verifying…';
            const fd = new FormData();
            fd.append('action', 'confirm_verify_otp');
            fd.append('otp', otp);
            try {
                const res  = await fetch('/update_profile.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch(pe) {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = 'Server error. Please try again.';
                    console.error('Non-JSON response:', text);
                    btn.disabled = false; btn.textContent = 'Verify OTP'; return;
                }
                if (data.success) {
                    msgEl.style.color = '#059669';
                    msgEl.textContent = '✅ Email verified!';
                    btn.textContent = '✅ Verified';
                    setTimeout(() => location.reload(), 1200);
                } else {
                    msgEl.style.color = '#dc2626';
                    msgEl.textContent = data.message || 'Invalid OTP.';
                    btn.disabled = false;
                    btn.textContent = 'Verify OTP';
                }
            } catch(e) {
                msgEl.style.color = '#dc2626';
                msgEl.textContent = 'Connection error: ' + e.message;
                btn.disabled = false; btn.textContent = 'Verify OTP';
            }
        }
    }

    /* ===== COPY REFERRAL LINK ===== */
    function copyReferralLink() {
        const text = document.getElementById('referralLinkText')?.textContent;
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            const btn = event.target;
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => btn.textContent = orig, 2000);
        });
    }
    </script>
</body>
</html>