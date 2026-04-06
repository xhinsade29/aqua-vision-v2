<?php
/**
 * Aqua-Vision — Operator Dashboard
 * Manage devices, acknowledge alerts, view operational data
 * Location: apps/operator/dashboard.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Operator Error [$errno]: $errstr in $errfile:$errline");
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

// ── Handle Alert Acknowledgment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge_alert'])) {
    $alertId = intval($_POST['alert_id'] ?? 0);
    if ($alertId > 0) {
        $stmt = $conn->prepare("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE alert_id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $alertId);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Alert acknowledged successfully!';
        } else {
            $_SESSION['error'] = 'Failed to acknowledge alert.';
        }
        $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Handle Acknowledge All Alerts ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acknowledge_all'])) {
    $stmt = $conn->prepare("UPDATE alerts SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE status = 'active'");
    $stmt->bind_param("i", $_SESSION['user_id']);
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $_SESSION['success'] = "All {$affected} alerts acknowledged successfully!";
    } else {
        $_SESSION['error'] = 'Failed to acknowledge alerts.';
    }
    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Handle Device Status Update ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_device'])) {
    $deviceId = intval($_POST['device_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowedStatuses = ['active', 'inactive', 'maintenance'];
    
    if ($deviceId > 0 && in_array($status, $allowedStatuses)) {
        $stmt = $conn->prepare("UPDATE devices SET status = ? WHERE device_id = ?");
        $stmt->bind_param("si", $status, $deviceId);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Device status updated to ' . ucfirst($status) . '!';
            
            // Log maintenance if status is maintenance
            if ($status === 'maintenance') {
                $notes = $_POST['maintenance_notes'] ?? 'Status changed to maintenance';
                $logStmt = $conn->prepare("INSERT INTO maintenance_logs (device_id, maintenance_type, notes, performed_by, performed_at) VALUES (?, 'status_change', ?, ?, NOW())");
                $logStmt->bind_param("isi", $deviceId, $notes, $_SESSION['user_id']);
                $logStmt->execute();
                $logStmt->close();
            }
        } else {
            $_SESSION['error'] = 'Failed to update device status.';
        }
        $stmt->close();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Get Operator's Activity History ────────────────────────────────────────
function getMyActivityHistory($conn, $userId, $limit = 10) {
    $sql = "SELECT 
                'alert_resolved' as action_type,
                a.alert_id as reference_id,
                a.message as details,
                a.resolved_at as action_time,
                d.device_name,
                NULL as maintenance_type
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            WHERE a.resolved_by = ? AND a.resolved_at IS NOT NULL
            
            UNION ALL
            
            SELECT 
                'maintenance' as action_type,
                ml.maintenance_id as reference_id,
                ml.notes as details,
                ml.performed_at as action_time,
                d.device_name,
                ml.maintenance_type
            FROM maintenance_logs ml
            JOIN devices d ON d.device_id = ml.device_id
            WHERE ml.performed_by = ?
            
            ORDER BY action_time DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $userId, $userId, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getMyStats($conn, $userId) {
    $stats = [];
    
    // Alerts resolved today
    $result = $conn->query("SELECT COUNT(*) as cnt FROM alerts WHERE resolved_by = $userId AND DATE(resolved_at) = CURDATE()");
    $stats['alerts_today'] = $result->fetch_assoc()['cnt'];
    
    // Total alerts resolved
    $result = $conn->query("SELECT COUNT(*) as cnt FROM alerts WHERE resolved_by = $userId");
    $stats['alerts_total'] = $result->fetch_assoc()['cnt'];
    
    // Maintenance tasks today
    $result = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_logs WHERE performed_by = $userId AND DATE(performed_at) = CURDATE()");
    $stats['maintenance_today'] = $result->fetch_assoc()['cnt'];
    
    // Total maintenance
    $result = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_logs WHERE performed_by = $userId");
    $stats['maintenance_total'] = $result->fetch_assoc()['cnt'];
    
    return $stats;
}

function getDeviceMaintenanceHistory($conn, $deviceId) {
    $sql = "SELECT ml.*, u.full_name as operator_name 
            FROM maintenance_logs ml
            JOIN users u ON u.user_id = ml.performed_by
            WHERE ml.device_id = ?
            ORDER BY ml.performed_at DESC
            LIMIT 5";
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
    
    // Get last maintenance - handle case where table might not exist
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
        // Table doesn't exist or other error, proceed without maintenance data
    }
    
    if ($activeAlerts > 0) return ['status' => 'critical', 'label' => 'Malfunctioning'];
    if ($readings24h == 0) return ['status' => 'offline', 'label' => 'No Data'];
    if ($lastMaintenance && $lastMaintenance['damage_level'] === 'high') return ['status' => 'damaged', 'label' => 'Damaged'];
    if ($lastMaintenance && $lastMaintenance['damage_level'] === 'medium') return ['status' => 'warning', 'label' => 'Needs Repair'];
    return ['status' => 'healthy', 'label' => 'Healthy'];
}

// ── Helper Functions ─────────────────────────────────────────────────────

function getActiveAlerts($conn) {
    $sql = "SELECT a.alert_id, a.alert_type, a.message, a.created_at, a.status,
                   d.device_name, d.device_id, l.location_name, l.river_section,
                   s.sensor_type, sr.value
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            LEFT JOIN sensor_readings sr ON sr.reading_id = a.reading_id
            WHERE a.status = 'active'
            ORDER BY a.created_at DESC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function getDevicesWithStatus($conn) {
    $sql = "SELECT d.device_id, d.device_name, d.status, d.last_active, d.created_at,
                   l.location_name, l.river_section,
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

function getRecentOperationalData($conn, $hours = 24) {
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at,
                   s.sensor_type, s.unit,
                   d.device_name, l.location_name
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sr.recorded_at DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getOperationalStats($conn) {
    $stats = [];
    
    // Device counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(status='active') as active,
        SUM(status='inactive') as inactive,
        SUM(status='maintenance') as maintenance
    FROM devices");
    $stats['devices'] = $result->fetch_assoc();
    
    // Active alerts
    $result = $conn->query("SELECT COUNT(*) as cnt FROM alerts WHERE status='active'");
    $stats['active_alerts'] = $result->fetch_assoc()['cnt'];
    
    // Critical alerts
    $result = $conn->query("SELECT COUNT(*) as cnt FROM alerts WHERE status='active' AND alert_type='critical'");
    $stats['critical_alerts'] = $result->fetch_assoc()['cnt'];
    
    // Today's readings
    $result = $conn->query("SELECT COUNT(*) as cnt FROM sensor_readings WHERE DATE(recorded_at) = CURDATE()");
    $stats['today_readings'] = $result->fetch_assoc()['cnt'];
    
    // Devices needing attention (inactive for >24h)
    $result = $conn->query("SELECT COUNT(*) as cnt FROM devices WHERE status='active' AND (last_active IS NULL OR last_active < DATE_SUB(NOW(), INTERVAL 24 HOUR))");
    $stats['needs_attention'] = $result->fetch_assoc()['cnt'];
    
    return $stats;
}

// ── Page Data ─────────────────────────────────────────────────────────────
$currentPage = 'operator-dashboard';
$alerts = getActiveAlerts($conn);
$devices = getDevicesWithStatus($conn);
$recentData = getRecentOperationalData($conn, 24);
$stats = getOperationalStats($conn);
$myStats = getMyStats($conn, $_SESSION['user_id']);
$myActivity = getMyActivityHistory($conn, $_SESSION['user_id'], 10);

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
    <title>Operator Dashboard — Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
        .operator-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; background: var(--operator-bg);
            border: 1px solid var(--operator); border-radius: 20px;
            font-size: 12px; font-weight: 600; color: var(--operator);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 28px; font-weight: 700; color: var(--c1); margin-top: 8px; }
        .stat-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
        .needs-attention { color: var(--crit) !important; }
        
        /* Alerts Section */
        .content-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
            margin-bottom: 24px;
        }
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-title { font-size: 15px; font-weight: 600; color: var(--c1); }
        .card-body { padding: 0; max-height: 400px; overflow-y: auto; }
        
        /* Alert Item */
        .alert-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .alert-item:hover { background: var(--bg); }
        .alert-item:last-child { border-bottom: none; }
        .alert-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .alert-icon.critical { background: var(--crit-bg); color: var(--crit); }
        .alert-icon.high { background: var(--warn-bg); color: var(--warn); }
        .alert-icon.low { background: var(--good-bg); color: var(--good); }
        .alert-content { flex: 1; min-width: 0; }
        .alert-title { font-size: 14px; font-weight: 600; color: var(--c1); }
        .alert-message { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .alert-meta { font-size: 11px; color: var(--text3); margin-top: 6px; }
        .ack-btn {
            padding: 6px 12px; border-radius: var(--radius-sm);
            font-size: 12px; font-weight: 500; cursor: pointer;
            border: none; background: var(--good); color: white;
            flex-shrink: 0;
        }
        .ack-btn:hover { background: #15803d; }
        
        /* Device Item */
        .device-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid var(--border);
        }
        .device-item:last-child { border-bottom: none; }
        .device-status {
            width: 10px; height: 10px; border-radius: 50%;
            flex-shrink: 0;
        }
        .device-status.active { background: var(--good); box-shadow: 0 0 0 3px var(--good-bg); }
        .device-status.inactive { background: var(--text3); }
        .device-status.maintenance { background: var(--warn); box-shadow: 0 0 0 3px var(--warn-bg); }
        .device-info { flex: 1; }
        .device-name { font-size: 14px; font-weight: 600; color: var(--c1); }
        .device-location { font-size: 12px; color: var(--text3); }
        .device-stats { display: flex; gap: 12px; font-size: 11px; color: var(--text3); }
        .device-badge {
            padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 500;
        }
        .device-badge.alert { background: var(--crit-bg); color: var(--crit); }
        .status-select {
            padding: 6px 10px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-size: 12px;
            background: var(--surface); cursor: pointer;
        }
        
        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: var(--bg); padding: 12px 16px;
            text-align: left; font-size: 11px; font-weight: 600;
            color: var(--text2); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .data-table td {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
            font-size: 13px; color: var(--text);
        }
        .data-table tr:hover td { background: var(--bg); }
        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 500;
        }
        .status-badge.normal { background: var(--good-bg); color: var(--good); }
        .status-badge.warning { background: var(--warn-bg); color: var(--warn); }
        
        .section-badge {
            padding: 2px 8px; border-radius: 4px;
            font-size: 10px; text-transform: uppercase;
        }
        .section-upstream { background: #dbeafe; color: #1e40af; }
        .section-midstream { background: #fef3c7; color: #92400e; }
        .section-downstream { background: #fee2e2; color: #991b1b; }
        
        .empty-state {
            text-align: center; padding: 40px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 16px; font-weight: 600; color: var(--c1); }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/operator_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Operator Dashboard</h1>
                <p style="color: var(--text2); font-size: 14px; margin-top: 4px;">Manage devices, acknowledge alerts, and monitor operations</p>
            </div>
            <span class="operator-badge">🔧 Operator</span>
        </div>
        
        <!-- My Operator Stats -->
        <div class="card" style="margin-bottom: 24px; background: linear-gradient(135deg, var(--operator-bg), white); border-color: var(--operator);">
            <div class="card-header" style="border-bottom-color: var(--operator);">
                <span class="card-title" style="color: var(--operator);">👤 My Operator Activity</span>
                <span style="font-size: 12px; color: var(--text3);"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Operator') ?></span>
            </div>
            <div class="card-body" style="padding: 20px;">
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--operator);"><?= $myStats['alerts_today'] ?></div>
                        <div style="font-size: 11px; color: var(--text3);">Alerts Resolved Today</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--c1);"><?= $myStats['alerts_total'] ?></div>
                        <div style="font-size: 11px; color: var(--text3);">Total Alerts Resolved</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--operator);"><?= $myStats['maintenance_today'] ?></div>
                        <div style="font-size: 11px; color: var(--text3);">Maintenance Today</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: var(--c1);"><?= $myStats['maintenance_total'] ?></div>
                        <div style="font-size: 11px; color: var(--text3);">Total Maintenance</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Alerts & Devices -->
        <div class="content-grid">
            <!-- Active Alerts -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">🔔 Active Alerts (<?= count($alerts) ?>)</span>
                    <?php if (!empty($alerts)): ?>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="acknowledge_all" class="ack-btn" style="background: var(--c2);" onclick="return confirm('Acknowledge ALL <?= count($alerts) ?> alerts? This will mark them all as resolved.');">
                                ✓ Acknowledge All
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($alerts)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">✅</div>
                            <div class="empty-state-title">No Active Alerts</div>
                            <p style="color: var(--text3); font-size: 13px;">All systems operating normally</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($alerts, 0, 8) as $alert): ?>
                            <div class="alert-item">
                                <div class="alert-icon <?= $alert['alert_type'] ?>">
                                    <?= $alert['alert_type'] === 'critical' ? '!' : ($alert['alert_type'] === 'high' ? '⚠' : '•') ?>
                                </div>
                                <div class="alert-content">
                                    <div class="alert-title"><?= ucfirst($alert['alert_type']) ?> Alert — <?= ucfirst(str_replace('_', ' ', $alert['sensor_type'])) ?></div>
                                    <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
                                    <div class="alert-meta">
                                        📍 <?= ucfirst($alert['river_section'] ?? 'Unknown') ?> • 
                                        🔧 <?= htmlspecialchars($alert['device_name']) ?> • 
                                        🕐 <?= date('M d, H:i', strtotime($alert['created_at'])) ?>
                                        <?= $alert['value'] ? '• Value: ' . round($alert['value'], 2) : '' ?>
                                    </div>
                                </div>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="alert_id" value="<?= $alert['alert_id'] ?>">
                                    <button type="submit" name="acknowledge_alert" class="ack-btn" onclick="return confirm('Acknowledge this alert?');">
                                        ✓ Ack
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($alerts) > 8): ?>
                            <div style="padding: 12px; text-align: center; font-size: 12px; color: var(--text3);">
                                +<?= count($alerts) - 8 ?> more alerts
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Simple Device Status -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">🔧 Device Status</span>
                    <a href="/Aqua-Vision/apps/operator/devices.php" style="font-size: 12px; color: var(--c2); font-weight: 500;">Manage Devices →</a>
                </div>
                <div class="card-body">
                    <?php if (empty($devices)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-title">No Devices Found</div>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($devices, 0, 8) as $device): 
                            $health = getDeviceHealthStatus($conn, $device['device_id']);
                        ?>
                            <div class="device-item">
                                <div class="device-status <?= $device['status'] ?>"></div>
                                <div class="device-info">
                                    <div class="device-name"><?= htmlspecialchars($device['device_name']) ?></div>
                                    <div class="device-location">
                                        <?= htmlspecialchars($device['location_name'] ?? 'Unknown') ?> • 
                                        <?= $device['sensor_count'] ?> sensors
                                    </div>
                                </div>
                                <span style="padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; background: <?= $health['status'] === 'healthy' ? 'var(--good-bg)' : ($health['status'] === 'critical' ? 'var(--crit-bg)' : 'var(--warn-bg)') ?>; color: <?= $health['status'] === 'healthy' ? 'var(--good)' : ($health['status'] === 'critical' ? 'var(--crit)' : 'var(--warn)') ?>;">
                                    <?= $health['label'] ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        
        <!-- Recent Operational Data -->
        </div>
        
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <span class="card-title">📊 Recent Operational Data (Last 24 Hours)</span>
                <span style="font-size: 12px; color: var(--text3);"><?= count($recentData) ?> readings</span>
            </div>
            <div style="max-height: 350px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Device</th>
                            <th>Location</th>
                            <th>Sensor</th>
                            <th>Value</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($recentData, 0, 20) as $reading): 
                            $status = 'normal';
                            if ($reading['value'] > 100 || $reading['value'] < 0) {
                                $status = 'warning';
                            }
                        ?>
                            <tr>
                                <td><?= date('M d H:i', strtotime($reading['recorded_at'])) ?></td>
                                <td><?= htmlspecialchars($reading['device_name']) ?></td>
                                <td>
                                    <span class="section-badge section-<?= strtolower($reading['location_name'] ?? '') ?>">
                                        <?= htmlspecialchars($reading['location_name'] ?? 'Unknown') ?>
                                    </span>
                                </td>
                                <td><?= ucfirst(str_replace('_', ' ', $reading['sensor_type'])) ?></td>
                                <td><?= round($reading['value'], 2) ?> <?= $reading['unit'] ?></td>
                                <td>
                                    <span class="status-badge <?= $status ?>">
                                        <?= $status === 'normal' ? '✓' : '⚠' ?> <?= ucfirst($status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- My Recent Activity -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <span class="card-title">👤 My Recent Activity</span>
                <span style="font-size: 12px; color: var(--text3);">Your actions today</span>
            </div>
            <div class="card-body" style="padding: 0; max-height: 300px; overflow-y: auto;">
                <?php if (empty($myActivity)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <div class="empty-state-title">No Recent Activity</div>
                        <p style="color: var(--text3); font-size: 13px;">Start by acknowledging alerts or updating device status</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($myActivity as $activity): ?>
                        <div class="alert-item" style="padding: 12px 20px;">
                            <div class="alert-icon <?= $activity['action_type'] === 'alert_resolved' ? 'low' : 'high' ?>">
                                <?= $activity['action_type'] === 'alert_resolved' ? '✓' : '🔧' ?>
                            </div>
                            <div class="alert-content">
                                <div class="alert-title">
                                    <?= $activity['action_type'] === 'alert_resolved' ? 'Resolved Alert' : 'Maintenance: ' . ucfirst($activity['maintenance_type'] ?? 'General') ?>
                                </div>
                                <div class="alert-message"><?= htmlspecialchars($activity['details'] ?? '') ?></div>
                                <div class="alert-meta">
                                    📍 <?= htmlspecialchars($activity['device_name']) ?> • 
                                    🕐 <?= date('M d, H:i', strtotime($activity['action_time'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Show toast notifications for session messages
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
