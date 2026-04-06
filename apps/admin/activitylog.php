<?php
/**
 * Aqua-Vision — Device & Sensor History Page
 * Location: apps/admin/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("History Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// ── Admin Access Control ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    if ($_SESSION['user_role'] === 'researcher') {
        header('Location: /Aqua-Vision/apps/researcher/dashboard.php');
    } else {
        header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    }
    exit;
}

// ── Helper Functions ───────────────────────────────────────────────────────────

function getMaintenanceLogs($conn, $hours = 24) {
    $sql = "SELECT ml.maintenance_id, ml.maintenance_type, ml.notes, ml.damage_level, 
                   ml.malfunction_type, ml.performed_at,
                   d.device_name, d.device_id,
                   u.full_name as operator_name, u.user_id
            FROM maintenance_logs ml
            JOIN devices d ON d.device_id = ml.device_id
            JOIN users u ON u.user_id = ml.performed_by
            WHERE ml.performed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY ml.performed_at DESC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get sensor reading history for a device
 */
function getDeviceReadingHistory($conn, $deviceId, $hours = 24) {
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at, s.sensor_type, s.unit
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.device_id = ? AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sr.recorded_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $deviceId, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get alert history
 */
function getAlertHistory($conn, $limit = 50) {
    $sql = "SELECT a.alert_id, a.alert_type, a.message, a.status, a.created_at, 
                   a.acknowledged_at, a.resolved_at,
                   d.device_name, s.sensor_type, s.unit
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            ORDER BY a.created_at DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get device status change history
 */
function getDeviceStatusHistory($conn, $limit = 50) {
    $sql = "SELECT d.device_id, d.device_name, d.status, d.device_condition, 
                   d.updated_at, d.last_active,
                   l.location_name, l.river_section
            FROM devices d
            LEFT JOIN locations l ON l.location_id = d.location_id
            ORDER BY d.updated_at DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get system activity logs
 */
function getSystemLogs($conn, $hours = 24) {
    $sql = "SELECT sl.log_id, sl.action, sl.details, sl.ip_address, sl.created_at,
                   u.username, u.full_name
            FROM system_logs sl
            LEFT JOIN users u ON u.user_id = sl.user_id
            WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sl.created_at DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get activity timeline combining readings, alerts, status changes, and system logs
 */
function getActivityTimeline($conn, $hours = 24, $typeFilter = 'all') {
    $timeline = [];
    
    // Get recent sensor readings
    if ($typeFilter === 'all' || $typeFilter === 'reading') {
        $readings = getDeviceReadingHistory($conn, null, $hours);
        foreach ($readings as $r) {
        $timeline[] = [
            'type' => 'reading',
            'timestamp' => $r['recorded_at'],
            'device_name' => null,
            'message' => sprintf("%s: %.2f %s", 
                ucfirst(str_replace('_', ' ', $r['sensor_type'])),
                $r['value'],
                $r['unit']
            ),
            'severity' => 'info',
            'data' => $r
        ];
        }
    }
    
    // Get recent alerts
    if ($typeFilter === 'all' || $typeFilter === 'alert') {
        $alerts = getAlertHistory($conn, 50);
        foreach ($alerts as $a) {
        if (strtotime($a['created_at']) >= strtotime("-$hours hours")) {
            $timeline[] = [
                'type' => 'alert',
                'timestamp' => $a['created_at'],
                'device_name' => $a['device_name'],
                'message' => $a['message'],
                'severity' => $a['alert_type'],
                'status' => $a['status'],
                'data' => $a
            ];
        }
        }
    }
    
    // Get system logs
    if ($typeFilter === 'all' || $typeFilter === 'system' || $typeFilter === 'device') {
        $systemLogs = getSystemLogs($conn, $hours);
        foreach ($systemLogs as $log) {
        // Check if this is a device-related log
        $isDeviceLog = in_array($log['action'], ['DEVICE_CREATE', 'DEVICE_UPDATE', 'DEVICE_DELETE']);
        
        $timeline[] = [
            'type' => $isDeviceLog ? 'device' : 'system',
            'timestamp' => $log['created_at'],
            'action' => $log['action'],
            'message' => $log['details'],
            'user' => $log['full_name'] ?? $log['username'] ?? 'System',
            'ip' => $log['ip_address'],
            'severity' => 'info',
            'data' => $log
        ];
        }
    }
    
    // Get maintenance logs
    if ($typeFilter === 'all' || $typeFilter === 'maintenance') {
        $maintenanceLogs = getMaintenanceLogs($conn, $hours);
        foreach ($maintenanceLogs as $log) {
        $timeline[] = [
            'type' => 'maintenance',
            'timestamp' => $log['performed_at'],
            'device_name' => $log['device_name'],
            'message' => $log['notes'] ?? 'Maintenance performed',
            'maintenance_type' => $log['maintenance_type'],
            'damage_level' => $log['damage_level'],
            'malfunction_type' => $log['malfunction_type'],
            'operator_name' => $log['operator_name'],
            'severity' => $log['damage_level'] === 'critical' ? 'critical' : ($log['damage_level'] === 'high' ? 'warning' : 'info'),
            'data' => $log
        ];
        }
    }
    
    // Sort by timestamp descending
    usort($timeline, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return array_slice($timeline, 0, 100);
}

// ── Get Data ─────────────────────────────────────────────────────────────────
$currentPage = 'history';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$deviceFilter = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$typeFilter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Get all devices for filter dropdown
$devices = $conn->query("SELECT device_id, device_name, status FROM devices ORDER BY device_name")->fetch_all(MYSQLI_ASSOC);

// Get activity timeline
$timeline = getActivityTimeline($conn, $hoursFilter, $typeFilter);

// Get maintenance logs from operators
$maintenanceLogs = getMaintenanceLogs($conn, $hoursFilter);

// Get alert statistics
$alertStats = $conn->query("SELECT 
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_alerts,
    SUM(CASE WHEN alert_type='critical' THEN 1 ELSE 0 END) as critical_alerts,
    SUM(CASE WHEN alert_type='high' THEN 1 ELSE 0 END) as high_alerts,
    SUM(CASE WHEN alert_type='low' THEN 1 ELSE 0 END) as low_alerts
FROM alerts")->fetch_assoc();

// Get reading statistics
$readingStats = $conn->query("SELECT COUNT(*) as total_readings,
    COUNT(DISTINCT sensor_id) as active_sensors,
    MAX(recorded_at) as last_reading
FROM sensor_readings 
WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $hoursFilter HOUR)")->fetch_assoc();

// ── API: ?action=fetch ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'fetch') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    
    // Get fresh data
    $freshTimeline = getActivityTimeline($conn, $hoursFilter, $typeFilter);
    $freshAlertStats = $conn->query("SELECT 
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_alerts,
        SUM(CASE WHEN alert_type='critical' THEN 1 ELSE 0 END) as critical_alerts,
        SUM(CASE WHEN alert_type='high' THEN 1 ELSE 0 END) as high_alerts,
        SUM(CASE WHEN alert_type='low' THEN 1 ELSE 0 END) as low_alerts
    FROM alerts")->fetch_assoc();
    $freshReadingStats = $conn->query("SELECT COUNT(*) as total_readings,
        COUNT(DISTINCT sensor_id) as active_sensors,
        MAX(recorded_at) as last_reading
    FROM sensor_readings 
    WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $hoursFilter HOUR)")->fetch_assoc();
    
    echo json_encode([
        'ok' => true,
        'timeline' => $freshTimeline,
        'alert_stats' => $freshAlertStats,
        'reading_stats' => $freshReadingStats,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_NUMERIC_CHECK);
    exit;
}

?>
<?php include '../../assets/navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History | Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a56db;
            --primary-dark: #0e3a8a;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --radius: 8px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.6;
        }
        
        /* Layout */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: var(--gray-500);
            font-size: 0.875rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary);
        }
        
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.success { border-left-color: var(--success); }
        
        .stat-card h3 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .stat-card p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        /* Filters */
        .filters {
            background: white;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }
        
        .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.875rem;
            background: white;
            cursor: pointer;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            border: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        /* Timeline */
        .timeline {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .timeline-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .timeline-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .timeline-body {
            padding: 1.25rem;
        }
        
        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .timeline-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.25rem;
        }
        
        .timeline-icon.reading {
            background: var(--gray-100);
        }
        
        .timeline-icon.alert-low {
            background: #fef3c7;
        }
        
        .timeline-icon.alert-high {
            background: #fee2e2;
        }
        
        .timeline-icon.alert-critical {
            background: #fecaca;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-title {
            font-weight: 500;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .timeline-desc {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .timeline-meta {
            font-size: 0.75rem;
            color: var(--gray-400);
        }
        
        .timeline-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .timeline-badge.critical {
            background: #fecaca;
            color: #991b1b;
        }
        
        .timeline-badge.high {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .timeline-badge.low {
            background: #fef3c7;
            color: #92400e;
        }
        
        .timeline-badge.info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-400);
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1>📜 History & Activity Log</h1>
            <p>View sensor readings, alerts, device activity, and system logs over time</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card success">
                <h3><?= number_format($readingStats['total_readings'] ?? 0) ?></h3>
                <p>Sensor Readings (<?= $hoursFilter ?>h)</p>
            </div>
            <div class="stat-card warning">
                <h3><?= $alertStats['active_alerts'] ?? 0 ?></h3>
                <p>Active Alerts</p>
            </div>
            <div class="stat-card danger">
                <h3><?= $alertStats['critical_alerts'] ?? 0 ?></h3>
                <p>Critical Alerts</p>
            </div>
            <div class="stat-card">
                <h3><?= $alertStats['high_alerts'] ?? 0 ?></h3>
                <p>High Priority</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label>Time Range:</label>
                <select onchange="window.location.href='?hours='+this.value">
                    <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                    <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                    <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Device:</label>
                <select onchange="window.location.href='?hours=<?= $hoursFilter ?>&device_id='+this.value">
                    <option value="">All Devices</option>
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['device_id'] ?>" <?= $deviceFilter == $d['device_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['device_name']) ?> (<?= $d['status'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Log Type:</label>
                <select onchange="window.location.href='?hours=<?= $hoursFilter ?>&type='+this.value">
                    <option value="all" <?= $typeFilter == 'all' ? 'selected' : '' ?>>All Logs</option>
                    <option value="reading" <?= $typeFilter == 'reading' ? 'selected' : '' ?>>Sensor Readings</option>
                    <option value="alert" <?= $typeFilter == 'alert' ? 'selected' : '' ?>>Alerts</option>
                    <option value="maintenance" <?= $typeFilter == 'maintenance' ? 'selected' : '' ?>>Maintenance Logs</option>
                    <option value="system" <?= $typeFilter == 'system' ? 'selected' : '' ?>>System Logs</option>
                </select>
            </div>
            <a href="activitylog.php" class="btn btn-secondary">Reset Filters</a>
            <a href="?action=export&hours=<?= $hoursFilter ?>" class="btn btn-primary">📥 Export CSV</a>
        </div>
        
        <!-- Activity Timeline -->
        <div class="timeline">
            <div class="timeline-header">
                <h2>Activity Timeline</h2>
                <span style="font-size: 0.875rem; color: var(--gray-500);">
                    <?= count($timeline) ?> events
                </span>
            </div>
            <div class="timeline-body">
                <?php if (empty($timeline)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No activity recorded in the last <?= $hoursFilter ?> hours</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($timeline as $item): ?>
                        <div class="timeline-item">
                            <?php if ($item['type'] === 'reading'): ?>
                                <div class="timeline-icon reading">📊</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        Sensor Reading
                                        <span class="timeline-badge info">Normal</span>
                                    </div>
                                    <div class="timeline-desc"><?= htmlspecialchars($item['message']) ?></div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                    </div>
                                </div>
                            <?php elseif ($item['type'] === 'alert'): ?>
                                <div class="timeline-icon alert-<?= $item['severity'] ?>">⚠️</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        Alert: <?= htmlspecialchars($item['device_name'] ?? 'Unknown') ?>
                                        <span class="timeline-badge <?= $item['severity'] ?>">
                                            <?= ucfirst($item['severity']) ?>
                                        </span>
                                        <?php if ($item['status'] === 'active'): ?>
                                            <span class="timeline-badge critical">Active</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-desc"><?= htmlspecialchars($item['message']) ?></div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                        <?php if ($item['status'] === 'acknowledged'): ?>
                                            • Acknowledged
                                        <?php elseif ($item['status'] === 'resolved'): ?>
                                            • Resolved
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($item['type'] === 'system' || $item['type'] === 'device'): ?>
                                <?php
                                // Parse device update details
                                $changes = [];
                                $message = $item['message'];
                                $deviceInfo = $message;
                                
                                if (strpos($message, ' | ') !== false) {
                                    $parts = explode(' | ', $message);
                                    $deviceInfo = $parts[0];
                                    $changes = array_slice($parts, 1);
                                }
                                
                                // Determine icon and color based on action
                                $icon = '🔧';
                                $bgColor = '#dbeafe';
                                if ($item['action'] === 'DEVICE_CREATE') {
                                    $icon = '➕';
                                    $bgColor = '#d1fae5';
                                } elseif ($item['action'] === 'DEVICE_UPDATE') {
                                    $icon = '✏️';
                                    $bgColor = '#fef3c7';
                                } elseif ($item['action'] === 'DEVICE_DELETE') {
                                    $icon = '🗑️';
                                    $bgColor = '#fee2e2';
                                }
                                ?>
                                <div class="timeline-icon" style="background: <?= $bgColor ?>;"><?= $icon ?></div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= htmlspecialchars(str_replace('_', ' ', $item['action'])) ?>
                                        <span class="timeline-badge <?= $item['type'] === 'device' ? 'info' : 'info' ?>">
                                            <?= $item['type'] === 'device' ? 'Device' : 'System' ?>
                                        </span>
                                    </div>
                                    <div class="timeline-desc">
                                        <?= htmlspecialchars($deviceInfo) ?>
                                        <?php if (!empty($changes)): ?>
                                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--gray-50); border-radius: 4px; font-size: 0.8rem;">
                                                <?php foreach ($changes as $change): ?>
                                                    <?php
                                                    // Parse change: "Field: 'old' → 'new'"
                                                    if (preg_match("/^([^:]+):\s*'(.+)'\s*→\s*'(.+)'$/", $change, $matches)) {
                                                        $field = $matches[1];
                                                        $oldVal = $matches[2];
                                                        $newVal = $matches[3];
                                                        $changeIcon = '📝';
                                                        if ($field === 'Name') $changeIcon = '🏷️';
                                                        elseif ($field === 'Status') $changeIcon = '🔘';
                                                        elseif ($field === 'Condition') $changeIcon = '🔧';
                                                        elseif ($field === 'Location') $changeIcon = '📍';
                                                    ?>
                                                        <div style="margin: 0.25rem 0; padding: 0.2rem 0;">
                                                            <span style="color: var(--gray-600);"><?= $changeIcon ?> <?= htmlspecialchars($field) ?>:</span>
                                                            <span style="text-decoration: line-through; color: var(--gray-400);"><?= htmlspecialchars($oldVal) ?></span>
                                                            <span style="color: var(--gray-500);">→</span>
                                                            <span style="color: var(--primary); font-weight: 500;"><?= htmlspecialchars($newVal) ?></span>
                                                        </div>
                                                    <?php } else { ?>
                                                        <div style="font-size: 0.75rem; color: var(--gray-500);"><?= htmlspecialchars($change) ?></div>
                                                    <?php } ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                        • By: <?= htmlspecialchars($item['user']) ?>
                                        <?php if ($item['ip']): ?>
                                            • IP: <?= htmlspecialchars($item['ip']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($item['type'] === 'maintenance'): ?>
                                <div class="timeline-icon" style="background: #cffafe;">🔧</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= htmlspecialchars(str_replace('_', ' ', $item['maintenance_type'])) ?>
                                        <span class="timeline-badge info">Maintenance</span>
                                        <?php if ($item['damage_level'] !== 'none'): ?>
                                            <span class="timeline-badge critical">Damage: <?= ucfirst($item['damage_level']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-desc">
                                        <strong><?= htmlspecialchars($item['device_name']) ?></strong>
                                        <?php if ($item['malfunction_type']): ?>
                                            <br><span style="color: var(--warning);">⚠️ <?= htmlspecialchars($item['malfunction_type']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($item['message'] && $item['message'] !== 'Maintenance performed'): ?>
                                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--gray-50); border-radius: 4px; font-size: 0.85rem;">
                                                <?= htmlspecialchars($item['message']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                        • By: <?= htmlspecialchars($item['operator_name']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        const SELF = 'activitylog.php';
        const HOURS_FILTER = <?= $hoursFilter ?>;
        const TYPE_FILTER = '<?= $typeFilter ?>';
        
        // ── Sync Engine ───────────────────────────────────────────────
        let _syncTimer = null, _syncBusy = false;
        
        function startSync(ms) { 
            stopSync(); 
            syncNow(); 
            _syncTimer = setInterval(syncNow, ms || 10000); 
        }
        
        function stopSync() { 
            if (_syncTimer) { clearInterval(_syncTimer); _syncTimer = null; } 
        }
        
        async function syncNow() {
            if (_syncBusy) return; 
            _syncBusy = true;
            try {
                const res = await fetch(`${SELF}?action=fetch&hours=${HOURS_FILTER}&type=${TYPE_FILTER}&_=${Date.now()}`);
                if (!res.ok) return;
                const d = await res.json();
                if (!d.ok) return;
                _applySync(d);
            } catch (_) {}
            finally { _syncBusy = false; }
        }
        
        function _applySync(d) {
            // Update stats
            if (d.reading_stats) {
                document.querySelector('.stat-card.success h3').textContent = 
                    parseInt(d.reading_stats.total_readings || 0).toLocaleString();
            }
            if (d.alert_stats) {
                document.querySelector('.stat-card.warning h3').textContent = 
                    d.alert_stats.active_alerts || 0;
                document.querySelector('.stat-card.danger h3').textContent = 
                    d.alert_stats.critical_alerts || 0;
                document.querySelector('.stat-card:last-child h3').textContent = 
                    d.alert_stats.high_alerts || 0;
            }
            
            // Update timeline
            if (d.timeline) {
                updateTimeline(d.timeline);
            }
            
            // Update event count
            const eventCount = d.timeline ? d.timeline.length : 0;
            document.querySelector('.timeline-header span').textContent = 
                `${eventCount} events • Updated ${new Date().toLocaleTimeString()}`;
        }
        
        function updateTimeline(timeline) {
            const container = document.querySelector('.timeline-body');
            if (!container) return;
            
            if (timeline.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No activity recorded in the last ${HOURS_FILTER} hours</p>
                    </div>`;
                return;
            }
            
            container.innerHTML = timeline.map(item => {
                if (item.type === 'reading') {
                    return `
                        <div class="timeline-item">
                            <div class="timeline-icon reading">📊</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    Sensor Reading
                                    <span class="timeline-badge info">Normal</span>
                                </div>
                                <div class="timeline-desc">${escapeHtml(item.message)}</div>
                                <div class="timeline-meta">${formatDate(item.timestamp)}</div>
                            </div>
                        </div>`;
                } else if (item.type === 'alert') {
                    return `
                        <div class="timeline-item">
                            <div class="timeline-icon alert-${item.severity}">⚠️</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    Alert: ${escapeHtml(item.device_name || 'Unknown')}
                                    <span class="timeline-badge ${item.severity}">${capitalize(item.severity)}</span>
                                    ${item.status === 'active' ? '<span class="timeline-badge critical">Active</span>' : ''}
                                </div>
                                <div class="timeline-desc">${escapeHtml(item.message)}</div>
                                <div class="timeline-meta">
                                    ${formatDate(item.timestamp)}
                                    ${item.status === 'acknowledged' ? '• Acknowledged' : ''}
                                    ${item.status === 'resolved' ? '• Resolved' : ''}
                                </div>
                            </div>
                        </div>`;
                } else if (item.type === 'system' || item.type === 'device') {
                    // Parse device update details
                    let changesHtml = '';
                    let deviceInfo = item.message;
                    let icon = '🔧';
                    let bgColor = '#dbeafe';
                    
                    if (item.action === 'DEVICE_CREATE') {
                        icon = '➕';
                        bgColor = '#d1fae5';
                    } else if (item.action === 'DEVICE_UPDATE') {
                        icon = '✏️';
                        bgColor = '#fef3c7';
                    } else if (item.action === 'DEVICE_DELETE') {
                        icon = '🗑️';
                        bgColor = '#fee2e2';
                    }
                    
                    // Parse changes from message
                    if (item.message && item.message.includes(' | ')) {
                        const parts = item.message.split(' | ');
                        deviceInfo = parts[0];
                        const changes = parts.slice(1);
                        
                        if (changes.length > 0) {
                            changesHtml = changes.map(change => {
                                // Format change: "Field: 'old' → 'new'"
                                const match = change.match(/^([^:]+):\s*'(.+)'\s*→\s*'(.+)'$/);
                                if (match) {
                                    const [, field, oldVal, newVal] = match;
                                    let changeIcon = '📝';
                                    if (field === 'Name') changeIcon = '🏷️';
                                    else if (field === 'Status') changeIcon = '🔘';
                                    else if (field === 'Condition') changeIcon = '🔧';
                                    else if (field === 'Location') changeIcon = '📍';
                                    return `<div style="margin: 0.25rem 0; padding: 0.2rem 0;">
                                        <span style="color: var(--gray-600);">${changeIcon} ${escapeHtml(field)}:</span>
                                        <span style="text-decoration: line-through; color: var(--gray-400);">${escapeHtml(oldVal)}</span>
                                        <span style="color: var(--gray-500);">→</span>
                                        <span style="color: var(--primary); font-weight: 500;">${escapeHtml(newVal)}</span>
                                    </div>`;
                                }
                                return `<div style="font-size: 0.75rem; color: var(--gray-500);">${escapeHtml(change)}</div>`;
                            }).join('');
                        }
                    }
                    
                    return `
                        <div class="timeline-item">
                            <div class="timeline-icon" style="background: ${bgColor};">${icon}</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    ${escapeHtml(item.action.replace(/_/g, ' '))}
                                    <span class="timeline-badge info">${item.type === 'device' ? 'Device' : 'System'}</span>
                                </div>
                                <div class="timeline-desc">
                                    ${escapeHtml(deviceInfo)}
                                    ${changesHtml ? `<div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--gray-50); border-radius: 4px; font-size: 0.8rem;">${changesHtml}</div>` : ''}
                                </div>
                                <div class="timeline-meta">
                                    ${formatDate(item.timestamp)}
                                    • By: ${escapeHtml(item.user)}
                                    ${item.ip ? `• IP: ${escapeHtml(item.ip)}` : ''}
                                </div>
                            </div>
                        </div>`;
                } else if (item.type === 'maintenance') {
                    return `
                        <div class="timeline-item">
                            <div class="timeline-icon" style="background: #cffafe;">🔧</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    ${escapeHtml(item.maintenance_type.replace(/_/g, ' '))}
                                    <span class="timeline-badge info">Maintenance</span>
                                    ${item.damage_level !== 'none' ? `<span class="timeline-badge critical">Damage: ${capitalize(item.damage_level)}</span>` : ''}
                                </div>
                                <div class="timeline-desc">
                                    <strong>${escapeHtml(item.device_name)}</strong>
                                    ${item.malfunction_type ? `<br><span style="color: var(--warning);">⚠️ ${escapeHtml(item.malfunction_type)}</span>` : ''}
                                    ${item.message && item.message !== 'Maintenance performed' ? `<div style="margin-top: 0.5rem; padding: 0.5rem; background: var(--gray-50); border-radius: 4px; font-size: 0.85rem;">${escapeHtml(item.message)}</div>` : ''}
                                </div>
                                <div class="timeline-meta">
                                    ${formatDate(item.timestamp)}
                                    • By: ${escapeHtml(item.operator_name)}
                                </div>
                            </div>
                        </div>`;
                }
            }).join('');
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, '&amp;')
                       .replace(/</g, '&lt;')
                       .replace(/>/g, '&gt;')
                       .replace(/"/g, '&quot;');
        }
        
        function capitalize(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        
        function formatDate(timestamp) {
            const date = new Date(timestamp.replace(' ', 'T'));
            return date.toLocaleString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        }
        
        // Start syncing when page loads
        document.addEventListener('DOMContentLoaded', () => {
            startSync(10000); // Sync every 10 seconds
        });
    </script>
    
    <!-- Toast Notifications -->
    <?php include '../../assets/toast.php'; ?>
    
    <?php
    // Show session-based toast messages
    if (isset($_SESSION['success'])) {
        echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast(" . json_encode($_SESSION['success']) . ", 'success', 5000); });</script>";
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast(" . json_encode($_SESSION['error']) . ", 'error', 8000); });</script>";
        unset($_SESSION['error']);
    }
    ?>
</body>
</html>
