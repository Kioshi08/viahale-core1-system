<?php
// -------------------------
// auth.php (Revised for TNVS OTP-based login + RBAC)
// -------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------
// Session Timeout
// -------------------------

define('SESSION_TIMEOUT', 180); // 3 minutes timeout

if (isset($_SESSION['LAST_ACTIVITY'])) {
    if (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header("Location: ../login.php?timeout=1"); // Redirect to login with timeout
        exit();
    }
}
$_SESSION['LAST_ACTIVITY'] = time(); // Update timestamp

// -------------------------
// Login Check
// -------------------------

function requireLogin(): void
{
    if (
        !isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true ||
        !isset($_SESSION['user_id']) || !isset($_SESSION['username'])
    ) {
        error_log("Unauthorized access attempt.");
        header("Location: ../login.php?error=unauthorized");
        exit();
    }
}

// -------------------------
// Role-Based Access Control (Optional)
// -------------------------

function requireRole(array $allowedRoles): void
{
    requireLogin();

    if (!isset($_SESSION['user_role'])) {
        error_log("Missing role in session.");
        http_response_code(403);
        exit("Access denied: Missing user role.");
    }

    $userRole = strtolower($_SESSION['user_role']);

    if (!in_array($userRole, array_map('strtolower', $allowedRoles), true)) {
        error_log("Access denied: User with role '{$userRole}' is not allowed.");
        http_response_code(403);
        exit("Access denied: You do not have permission to view this page.");
    }
}
