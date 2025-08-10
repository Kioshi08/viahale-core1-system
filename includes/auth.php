<?php
session_start();

// If user not logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

// Restrict access based on role
function checkRole($allowed_roles = []) {
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: ../unauthorized.php"); // or redirect somewhere else
        exit();
    }
}
?>
