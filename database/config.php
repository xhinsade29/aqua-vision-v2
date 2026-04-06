<?php
/**
 * Aqua-Vision Database Configuration
 * Location: database/config.php
 */

// Database connection settings
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mangina_watershed');

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

// Try to select database, create if it doesn't exist
if (!$conn->select_db(DB_NAME)) {
    // Database doesn't exist, create it first
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $conn->select_db(DB_NAME);
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Application constants
define('APP_NAME', 'Aqua-Vision');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/Aqua-Vision');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// User session check
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
}

// Check if user is admin
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// Get current user info
function get_current_user_info() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'Unknown',
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['user_role'] ?? 'user'
        ];
    }
    return null;
}

// Sanitize input
function sanitize($input) {
    global $conn;
    return htmlspecialchars(trim($conn->real_escape_string($input)));
}

// Format date/time
function format_datetime($datetime, $format = 'M j, Y H:i') {
    return date($format, strtotime($datetime));
}

// Generate alert message
function generate_alert_message($sensorType, $value, $min, $max) {
    $sensorLabels = [
        'temperature' => 'Temperature',
        'ph_level' => 'pH Level',
        'turbidity' => 'Turbidity',
        'dissolved_oxygen' => 'Dissolved Oxygen',
        'water_level' => 'Water Level',
        'sediments' => 'Sediments',
        'conductivity' => 'Conductivity'
    ];
    
    $label = $sensorLabels[$sensorType] ?? $sensorType;
    
    if ($value > $max) {
        return "$label too high: " . number_format($value, 2) . " (max: $max)";
    } else {
        return "$label too low: " . number_format($value, 2) . " (min: $min)";
    }
}

// Log activity
function log_activity($action, $details = '') {
    global $conn;
    
    $user = get_current_user_info();
    $userId = $user['id'] ?? 0;
    $userName = $user['name'] ?? 'System';
    
    $stmt = $conn->prepare("
        INSERT INTO system_logs (user_id, action, details, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt->bind_param("issss", 
        $userId, 
        $action, 
        $details, 
        $ipAddress,
        $userAgent
    );
    
    $stmt->execute();
    $stmt->close();
}
?>
