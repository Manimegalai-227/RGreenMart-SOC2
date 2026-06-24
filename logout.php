<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy all session data
$_SESSION = [];
session_unset();
session_destroy();

// Optional: Prevent back button from showing logged-in pages
header("Cache-Control: no-cache, must-revalidate");
header("Expires: 0");

// Redirect to login page (or any page you want)
header("Location: login.php");
exit();
