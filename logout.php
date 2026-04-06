<?php
/**
 * Aqua-Vision Logout Page
 * Location: logout.php
 */

session_start();

// Log the logout activity before destroying session
if (isset($_SESSION['user_id'])) {
    require_once 'database/config.php';
    
    $userId = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address, user_agent) VALUES (?, 'LOGOUT', ?, ?, ?)");
    $details = "User {$username} logged out";
    $stmt->bind_param("isss", $userId, $details, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Store logout message in session for display after redirect
$_SESSION['logout_message'] = 'You have been logged out successfully';

// Clear all session data
$_SESSION = array();

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>
