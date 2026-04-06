<?php
/**
 * Aqua-Vision — Operator Device Management
 * Full device maintenance, repair, and damage tracking
 * Location: apps/operator/devices.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Operator Devices Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// Check authentication and operator/admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
    exit;
}

$allowedRoles = ['operator', 'admin'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    if ($_SESSION['user_role'] === 'researcher') {
        header('Location: /Aqua-Vision/apps/researcher/dashboard.php');
    } else {
        header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    }
    exit;
}

// ── Handle Maintenance Log ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_maintenance'])) {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $maintenanceType = $_POST['maintenance_type'] ?? '';
    $notes = $_POST['maintenance_notes'] ?? '';
    $damageLevel = $_POST['damage_level'] ?? 'none';
    $malfunctionType = $_POST['malfunction_type'] ?? '';
    
    if ($deviceId > 0 && !empty($maintenanceType)) {
        // Set device to maintenance status
        $stmt = $conn->prepare("UPDATE devices SET status = 'maintenance' WHERE device_id = ?");
        $stmt->bind_param("i", $deviceId);
        $stmt->execute();
        $stmt->close();
        
        // Log maintenance
        $stmt = $conn->prepare("INSERT INTO maintenance_logs (device_id, maintenance_type, notes, damage_level, malfunction_type, performed_by, performed_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("issssi", $deviceId, $maintenanceType, $notes, $damageLevel, $malfunctionType, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Maintenance logged successfully: ' . ucfirst($maintenanceType);
        } else {
            $_SESSION['error'] = 'Failed to log maintenance: ' . $stmt->error;
        }
        $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Handle Device Status Update ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_device'])) {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowedStatuses = ['active', 'inactive', 'maintenance', 'damaged'];
    
    if ($deviceId > 0 && in_array($status, $allowedStatuses)) {
        $stmt = $conn->prepare("UPDATE devices SET status = ? WHERE device_id = ?");
        $stmt->bind_param("si", $status, $deviceId);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Device status updated to ' . ucfirst($status);
        } else {
            $_SESSION['error'] = 'Failed to update device status.';
        }
        $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Helper Functions ─────────────────────────────────────────────────────
function getAllDevices($conn) {
    $sql = "SELECT d.device_id, d.device_name, d.status, d.last_active, d.created_at,
                   l.location_name, l.river_section, l.latitude, l.longitude,
                   COUNT(DISTINCT s.sensor_id) as sensor_count,
                   COUNT(DISTINCT CASE WHEN a.status = 'active' THEN a.alert_id END) as alert_count
            FROM devices d
            LEFT JOIN locations l ON l.location_id = d.location_id
            LEFT JOIN sensors s ON s.device_id = d.device_id
            LEFT JOIN alerts a ON a.sensor_id = s.sensor_id
            GROUP BY d.device_id
            ORDER BY d.device_name";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function getDeviceMaintenanceHistory($conn, $deviceId) {
    $sql = "SELECT ml.*, u.full_name as operator_name 
            FROM maintenance_logs ml
            JOIN users u ON u.user_id = ml.performed_by
            WHERE ml.device_id = ?
            ORDER BY ml.performed_at DESC
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getDeviceSensors($conn, $deviceId) {
    $sql = "SELECT s.sensor_id, s.sensor_type, s.unit, s.min_threshold, s.max_threshold
            FROM sensors s
            WHERE s.device_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getDeviceHealthStatus($conn, $deviceId) {
    // Get recent readings count
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sensor_readings sr 
                           JOIN sensors s ON s.sensor_id = sr.sensor_id 
                           WHERE s.device_id = ? AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $readings24h = $result->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();
    
    // Get active alerts count
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM alerts a 
                           JOIN sensors s ON s.sensor_id = a.sensor_id 
                           WHERE s.device_id = ? AND a.status = 'active'");
    $stmt->bind_param("i", $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeAlerts = $result->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();
    
    // Get last maintenance
    $lastMaintenance = null;
    try {
        $stmt = $conn->prepare("SELECT performed_at, damage_level FROM maintenance_logs 
                               WHERE device_id = ? ORDER BY performed_at DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $deviceId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $lastMaintenance = $result->fetch_assoc();
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        // Table doesn't exist
    }
    
    if ($activeAlerts > 0) return ['status' => 'critical', 'label' => 'Malfunctioning'];
    if ($readings24h == 0) return ['status' => 'offline', 'label' => 'No Data'];
    if ($lastMaintenance && $lastMaintenance['damage_level'] === 'high') return ['status' => 'damaged', 'label' => 'Damaged'];
    if ($lastMaintenance && $lastMaintenance['damage_level'] === 'medium') return ['status' => 'warning', 'label' => 'Needs Repair'];
    return ['status' => 'healthy', 'label' => 'Healthy'];
}

// ── Page Data ─────────────────────────────────────────────────────────────
$currentPage = 'devices';
$devices = getAllDevices($conn);

// Get session messages for toast
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Management — Operator</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --c1: #0F2854; --c2: #1C4D8D; --c3: #4988C4; --c4: #BDE8F5;
            --bg: #f0f5fb; --surface: #fff; --border: rgba(15,40,84,.08);
            --text: #0F2854; --text2: #4a6080; --text3: #8aa0bc;
            --good: #16a34a; --good-bg: #dcfce7;
            --warn: #d97706; --warn-bg: #fef3c7;
            --crit: #dc2626; --crit-bg: #fee2e2;
            --operator: #0891b2; --operator-bg: #cffafe;
            --radius: 14px; --radius-sm: 8px;
            --sidebar-w: 240px;
        }
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: var(--bg); 
            min-height: 100vh;
            margin-left: var(--sidebar-w);
        }
        
        .main-content { padding: 24px; max-width: 1400px; }
        
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-title { 
            font-family: 'Space Grotesk', sans-serif; 
            font-size: 28px; font-weight: 700; color: var(--c1); 
        }
        
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--c2); font-size: 14px; text-decoration: none;
            padding: 8px 16px; border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .back-link:hover { background: var(--bg); }
        
        .device-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;
        }
        
        .device-card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .device-card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .device-status-dot {
            width: 12px; height: 12px; border-radius: 50%;
        }
        .device-status-dot.active { background: var(--good); box-shadow: 0 0 0 3px var(--good-bg); }
        .device-status-dot.inactive { background: var(--text3); }
        .device-status-dot.maintenance { background: var(--warn); box-shadow: 0 0 0 3px var(--warn-bg); }
        .device-status-dot.damaged { background: var(--crit); box-shadow: 0 0 0 3px var(--crit-bg); }
        
        .device-card-title { flex: 1; }
        .device-name { font-size: 16px; font-weight: 600; color: var(--c1); }
        .device-location { font-size: 12px; color: var(--text3); }
        
        .health-badge {
            padding: 4px 12px; border-radius: 12px;
            font-size: 11px; font-weight: 500;
        }
        .health-badge.healthy { background: var(--good-bg); color: var(--good); }
        .health-badge.warning { background: var(--warn-bg); color: var(--warn); }
        .health-badge.critical { background: var(--crit-bg); color: var(--crit); }
        .health-badge.damaged { background: var(--crit-bg); color: var(--crit); }
        .health-badge.offline { background: #e5e7eb; color: #6b7280; }
        
        .device-card-body { padding: 20px; }
        
        .info-row {
            display: flex; gap: 16px; margin-bottom: 12px;
            font-size: 13px; color: var(--text2);
        }
        .info-label { font-weight: 500; color: var(--text); min-width: 100px; }
        
        .alert-banner {
            padding: 10px 14px; background: var(--crit-bg);
            border-radius: var(--radius-sm); margin-bottom: 16px;
            font-size: 13px; color: var(--crit); display: flex; align-items: center; gap: 8px;
        }
        
        .maintenance-form { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .form-select, .form-input, .form-textarea {
            padding: 10px 12px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-size: 13px;
            background: var(--surface); width: 100%;
        }
        .form-textarea { min-height: 80px; resize: vertical; }
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none; border-color: var(--c3);
        }
        
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-primary:hover { background: var(--c1); }
        .btn-success { background: var(--good); color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-outline { background: var(--surface); color: var(--text2); border: 1px solid var(--border); }
        
        .status-form {
            display: flex; align-items: center; gap: 10px; margin-top: 16px;
            padding-top: 16px; border-top: 1px solid var(--border);
        }
        
        .history-section { margin-top: 16px; }
        .history-title { font-size: 12px; font-weight: 600; color: var(--text3); margin-bottom: 10px; }
        .history-item {
            padding: 10px; background: var(--bg); border-radius: var(--radius-sm);
            margin-bottom: 8px; font-size: 12px;
        }
        .history-meta { color: var(--text3); margin-bottom: 4px; }
        .history-type { font-weight: 500; color: var(--c1); }
        .history-damage { color: var(--crit); font-weight: 500; }
        .history-notes { color: var(--text2); margin-top: 4px; }
        
        .sensor-list {
            display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;
        }
        .sensor-tag {
            padding: 4px 10px; background: var(--c4-soft);
            border-radius: 10px; font-size: 11px; color: var(--c1);
        }
        
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .device-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/operator_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">🔧 Device Management</h1>
                <p style="color: var(--text2); font-size: 14px; margin-top: 4px;">Maintain, repair, and track device health</p>
            </div>
        </div>
        
        <div class="device-grid">
            <?php foreach ($devices as $device): 
                $health = getDeviceHealthStatus($conn, $device['device_id']);
                $maintenanceHistory = getDeviceMaintenanceHistory($conn, $device['device_id']);
                $sensors = getDeviceSensors($conn, $device['device_id']);
            ?>
                <div class="device-card">
                    <div class="device-card-header">
                        <div class="device-status-dot <?= $device['status'] ?>"></div>
                        <div class="device-card-title">
                            <div class="device-name"><?= htmlspecialchars($device['device_name']) ?></div>
                            <div class="device-location"><?= htmlspecialchars($device['location_name'] ?? 'Unknown Location') ?></div>
                        </div>
                        <span class="health-badge <?= $health['status'] ?>"><?= $health['label'] ?></span>
                    </div>
                    
                    <div class="device-card-body">
                        <div class="info-row">
                            <span class="info-label">Section:</span>
                            <span><?= ucfirst($device['river_section'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Sensors:</span>
                            <div class="sensor-list">
                                <?php foreach ($sensors as $sensor): ?>
                                    <span class="sensor-tag"><?= ucfirst(str_replace('_', ' ', $sensor['sensor_type'])) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Active:</span>
                            <span><?= $device['last_active'] ? date('M d, H:i', strtotime($device['last_active'])) : 'Never' ?></span>
                        </div>
                        
                        <?php if ($device['alert_count'] > 0): ?>
                            <div class="alert-banner">
                                ⚠️ <?= $device['alert_count'] ?> active alert<?= $device['alert_count'] > 1 ? 's' : '' ?> - Requires attention
                            </div>
                        <?php endif; ?>
                        
                        <!-- Maintenance Log Form -->
                        <form method="POST" class="maintenance-form">
                            <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                            <div class="form-row">
                                <select name="maintenance_type" class="form-select" required>
                                    <option value="">Select Maintenance Type</option>
                                    <option value="repair">🔧 Repair</option>
                                    <option value="calibration">📏 Calibration</option>
                                    <option value="cleaning">🧹 Cleaning</option>
                                    <option value="replacement">🔄 Part Replacement</option>
                                    <option value="inspection">🔍 Inspection</option>
                                    <option value="malfunction_fix">🛠️ Fix Malfunction</option>
                                    <option value="relocation">📍 Relocate Device</option>
                                </select>
                                <select name="damage_level" class="form-select">
                                    <option value="none">✓ No Damage</option>
                                    <option value="low">⚠️ Low Damage</option>
                                    <option value="medium">🔶 Medium Damage</option>
                                    <option value="high">🚨 High Damage</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <select name="malfunction_type" class="form-select" style="width: 100%;">
                                    <option value="">Select Malfunction Type (if any)</option>
                                    <option value="sensor_failure">📡 Sensor Failure</option>
                                    <option value="power_issue">🔌 Power Issue</option>
                                    <option value="communication_error">📶 Communication Error</option>
                                    <option value="calibration_drift">📏 Calibration Drift</option>
                                    <option value="physical_damage">💥 Physical Damage</option>
                                    <option value="water_intrusion">💧 Water Intrusion</option>
                                    <option value="connectivity_loss">🔗 Connectivity Loss</option>
                                    <option value="data_corruption">💾 Data Corruption</option>
                                    <option value="other">❓ Other</option>
                                </select>
                            </div>
                            <textarea name="maintenance_notes" placeholder="Describe the work performed, issues found, parts replaced, calibration details..." class="form-textarea"></textarea>
                            <button type="submit" name="log_maintenance" class="btn btn-success" style="width: 100%; margin-top: 10px;">
                                📝 Log Maintenance
                            </button>
                        </form>
                        
                        <!-- Status Change -->
                        <form method="POST" class="status-form">
                            <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                            <span style="font-size: 13px; color: var(--text3);">Change Status:</span>
                            <select name="status" class="form-select" onchange="this.form.submit()" style="flex: 1;">
                                <option value="active" <?= $device['status'] === 'active' ? 'selected' : '' ?>>🟢 Active</option>
                                <option value="maintenance" <?= $device['status'] === 'maintenance' ? 'selected' : '' ?>>🟡 Maintenance</option>
                                <option value="inactive" <?= $device['status'] === 'inactive' ? 'selected' : '' ?>>⚫ Inactive</option>
                                <option value="damaged" <?= $device['status'] === 'damaged' ? 'selected' : '' ?>>🔴 Damaged</option>
                            </select>
                            <input type="hidden" name="update_device" value="1">
                        </form>
                        
                        <!-- Maintenance History -->
                        <?php if (!empty($maintenanceHistory)): ?>
                            <div class="history-section">
                                <div class="history-title">📋 Recent Maintenance History</div>
                                <?php foreach (array_slice($maintenanceHistory, 0, 3) as $maint): ?>
                                    <div class="history-item">
                                        <div class="history-meta">
                                            <?= date('M d, Y H:i', strtotime($maint['performed_at'])) ?> by <?= htmlspecialchars($maint['operator_name']) ?>
                                        </div>
                                        <div>
                                            <span class="history-type"><?= ucfirst(str_replace('_', ' ', $maint['maintenance_type'])) ?></span>
                                            <?php if ($maint['damage_level'] !== 'none'): ?>
                                                <span class="history-damage">• Damage: <?= $maint['damage_level'] ?></span>
                                            <?php endif; ?>
                                            <?php if ($maint['malfunction_type']): ?>
                                                <span style="color: var(--warn);">• <?= htmlspecialchars($maint['malfunction_type']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($maint['notes']): ?>
                                            <div class="history-notes"><?= htmlspecialchars($maint['notes']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        // Show toast notifications
        <?php if (!empty($success)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof showToast === 'function') {
                    showToast(<?= json_encode($success) ?>, 'success', 5000);
                }
            });
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof showToast === 'function') {
                    showToast(<?= json_encode($error) ?>, 'error', 5000);
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>
