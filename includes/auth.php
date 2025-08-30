<?php
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

$username = $_SESSION['username'] ?? null;
if (!$username) { header('Location: /core1/login.php'); exit; }
$role = $_SESSION['role'] ?? null;

function require_role($requiredRole) {
    global $pdo, $username, $role, $DB_NAME;
    $allowed = false;
    try {
        $q = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME='users' AND COLUMN_NAME='role'");
        $q->execute(['db' => $DB_NAME]);
        if ($q->fetch()) {
            $r = $pdo->prepare('SELECT role FROM users WHERE username=:u LIMIT 1');
            $r->execute(['u' => $username]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['role'] === $requiredRole) $allowed = true;
        } else {
            $allowed = in_array($username, [$requiredRole, 'admin1'], true);
        }
    } catch (Exception $e) {
        $allowed = in_array($username, [$requiredRole, 'admin1'], true);
    }
    if (!$allowed) { http_response_code(403); echo 'Access denied'; exit; }
}
?>
