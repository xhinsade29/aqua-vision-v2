<?php
/**
 * Aqua-Vision — Operator Activity Log
 * Operator-focused activity tracking
 * Location: apps/operator/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Operator Activity Error [$errno]: $errstr in $errfile:$errline");
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

// ── Helper Functions ─────────────────────────────────────────────────────
function getMyResolvedAlerts($conn, $userId, $hours = 24) {
    $sql = "SELECT a.alert_id, a.alert_type, a.message, a.created_at, a.resolved_at,
                   d.device_name, s.sensor_type, sr.value
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN sensor_readings sr ON sr.reading_id = a.reading_id
            WHERE a.resolved_by = ? AND a.resolved_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY a.resolved_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getMyMaintenanceLogs($conn, $userId, $hours = 24) {
    try {
        $sql = "SELECT ml.maintenance_id, ml.maintenance_type, ml.notes, ml.damage_level, 
                       ml.malfunction_type, ml.performed_at,
                       d.device_name, d.device_id
                FROM maintenance_logs ml
                JOIN devices d ON d.device_id = ml.device_id
                WHERE ml.performed_by = ? AND ml.performed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY ml.performed_at DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return []; // Table doesn't exist
        }
        $stmt->bind_param("ii", $userId, $hours);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        return []; // Return empty if table missing
    }
}

function getDeviceStatusChanges($conn, $hours = 24) {
    $sql = "SELECT d.device_id, d.device_name, d.status, d.updated_at,
                   l.location_name
            FROM devices d
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE d.updated_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY d.updated_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Page Data ─────────────────────────────────────────────────────────────
$currentPage = 'activity';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$userId = $_SESSION['user_id'];

$myAlerts = getMyResolvedAlerts($conn, $userId, $hoursFilter);
$myMaintenance = getMyMaintenanceLogs($conn, $userId, $hoursFilter);
$statusChanges = getDeviceStatusChanges($conn, $hoursFilter);

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
    <title>My Activity Log — Operator</title>
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
        
        .filters {
            display: flex; gap: 12px; margin-bottom: 24px;
        }
        .filter-select {
            padding: 8px 14px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); background: var(--surface);
            font-size: 13px; cursor: pointer;
        }
        
        .content-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
        }
        
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--operator-bg), white);
        }
        .card-title { 
            font-size: 15px; font-weight: 600; color: var(--operator);
            display: flex; align-items: center; gap: 8px;
        }
        .card-body { 
            padding: 0; max-height: 500px; overflow-y: auto;
        }
        
        .activity-item {
            display: flex; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid var(--border);
        }
        .activity-item:last-child { border-bottom: none; }
        
        .activity-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .activity-icon.alert { background: var(--good-bg); color: var(--good); }
        .activity-icon.maintenance { background: var(--warn-bg); color: var(--warn); }
        .activity-icon.status { background: var(--c4-soft); color: var(--c3); }
        
        .activity-content { flex: 1; }
        .activity-title { font-size: 14px; font-weight: 600; color: var(--c1); }
        .activity-desc { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .activity-meta { 
            font-size: 11px; color: var(--text3); margin-top: 6px;
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        
        .badge {
            display: inline-flex; align-items: center;
            padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 500;
        }
        .badge.repair { background: var(--crit-bg); color: var(--crit); }
        .badge.calibration { background: var(--warn-bg); color: var(--warn); }
        .badge.cleaning { background: var(--good-bg); color: var(--good); }
        .badge.inspection { background: var(--c4); color: var(--c1); }
        
        .damage-badge {
            padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 500;
        }
        .damage-badge.low { background: var(--warn-bg); color: var(--warn); }
        .damage-badge.medium { background: #fed7aa; color: #92400e; }
        .damage-badge.high { background: var(--crit-bg); color: var(--crit); }
        
        .empty-state {
            text-align: center; padding: 40px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 16px; font-weight: 600; color: var(--c1); }
        
        .stats-bar {
            display: flex; gap: 20px; margin-bottom: 24px;
        }
        .stat-item {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; background: var(--surface);
            border-radius: var(--radius-sm); border: 1px solid var(--border);
        }
        .stat-value { font-size: 20px; font-weight: 700; color: var(--c1); }
        .stat-label { font-size: 12px; color: var(--text3); }
        
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/operator_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">👤 My Activity Log</h1>
                <p style="color: var(--text2); font-size: 14px; margin-top: 4px;">Track your actions: alerts resolved, maintenance performed, and status changes</p>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="stats-bar">
            <div class="stat-item">
                <span class="stat-value" style="color: var(--good);"><?= count($myAlerts) ?></span>
                <span class="stat-label">Alerts Resolved</span>
            </div>
            <div class="stat-item">
                <span class="stat-value" style="color: var(--warn);"><?= count($myMaintenance) ?></span>
                <span class="stat-label">Maintenance Tasks</span>
            </div>
            <div class="stat-item">
                <span class="stat-value" style="color: var(--c3);"><?= count($statusChanges) ?></span>
                <span class="stat-label">Status Changes</span>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <select class="filter-select" onchange="window.location.href='?hours='+this.value">
                <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
            </select>
        </div>
        
        <div class="content-grid">
            <!-- Resolved Alerts -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">✅ Alerts Resolved</span>
                </div>
                <div class="card-body">
                    <?php if (empty($myAlerts)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-title">No Alerts Resolved</div>
                            <p style="color: var(--text3); font-size: 13px;">You haven't resolved any alerts in the last <?= $hoursFilter ?> hours</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($myAlerts as $alert): ?>
                            <div class="activity-item">
                                <div class="activity-icon alert">✓</div>
                                <div class="activity-content">
                                    <div class="activity-title"><?= ucfirst($alert['alert_type']) ?> Alert Resolved</div>
                                    <div class="activity-desc">
                                        <?= htmlspecialchars($alert['device_name']) ?> — 
                                        <?= htmlspecialchars($alert['message']) ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span>📍 <?= ucfirst(str_replace('_', ' ', $alert['sensor_type'])) ?></span>
                                        <span>🕐 <?= date('M d, H:i', strtotime($alert['resolved_at'])) ?></span>
                                        <?php if ($alert['value']): ?>
                                            <span>📊 Value: <?= round($alert['value'], 2) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Maintenance Log -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">🔧 My Maintenance Work</span>
                </div>
                <div class="card-body">
                    <?php if (empty($myMaintenance)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">🛠️</div>
                            <div class="empty-state-title">No Maintenance Logged</div>
                            <p style="color: var(--text3); font-size: 13px;">You haven't logged any maintenance in the last <?= $hoursFilter ?> hours</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($myMaintenance as $maint): ?>
                            <div class="activity-item">
                                <div class="activity-icon maintenance">🔧</div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?= ucfirst(str_replace('_', ' ', $maint['maintenance_type'])) ?>
                                        <span class="badge <?= $maint['maintenance_type'] ?>"><?= ucfirst($maint['maintenance_type']) ?></span>
                                        <?php if ($maint['damage_level'] !== 'none'): ?>
                                            <span class="damage-badge <?= $maint['damage_level'] ?>">Damage: <?= ucfirst($maint['damage_level']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-desc">
                                        <?= htmlspecialchars($maint['device_name']) ?>
                                        <?php if ($maint['malfunction_type']): ?>
                                            <br><span style="color: var(--warn);">⚠️ <?= htmlspecialchars($maint['malfunction_type']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span>🕐 <?= date('M d, H:i', strtotime($maint['performed_at'])) ?></span>
                                    </div>
                                    <?php if ($maint['notes']): ?>
                                        <div style="margin-top: 8px; padding: 8px; background: var(--bg); border-radius: var(--radius-sm); font-size: 12px; color: var(--text2);">
                                            <?= htmlspecialchars($maint['notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Device Status Changes -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header" style="background: linear-gradient(135deg, var(--c4-soft), white);">
                <span class="card-title" style="color: var(--c1);">🔄 Device Status Changes</span>
            </div>
            <div class="card-body">
                <?php if (empty($statusChanges)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <div class="empty-state-title">No Status Changes</div>
                        <p style="color: var(--text3); font-size: 13px;">No device status changes in the last <?= $hoursFilter ?> hours</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($statusChanges as $change): ?>
                        <div class="activity-item">
                            <div class="activity-icon status">🔄</div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($change['device_name']) ?></div>
                                <div class="activity-desc">
                                    Status changed to <strong><?= ucfirst($change['status']) ?></strong>
                                    <?php if ($change['location_name']): ?>
                                        at <?= htmlspecialchars($change['location_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <span>🕐 <?= date('M d, H:i', strtotime($change['updated_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        <?php if (!empty($success)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof showToast === 'function') {
                    showToast(<?= json_encode($success) ?>, 'success', 5000);
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>
