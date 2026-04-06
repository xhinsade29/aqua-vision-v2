<?php
/**
 * Aqua-Vision — Reports & Analytics Page
 * Location: apps/admin/reports.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Reports Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// Check login and admin role
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

$currentPage = 'reports';
include '../../assets/navigation.php';

// ── Helper Functions ─────────────────────────────────────────────────────────

function getSensorReadingsStats($conn, $deviceId, $sensorType, $section, $status, $days = 7) {
    $sql = "SELECT 
        s.sensor_type,
        COUNT(*) as total_readings,
        AVG(sr.value) as avg_value,
        MIN(sr.value) as min_value,
        MAX(sr.value) as max_value,
        STDDEV(sr.value) as std_dev
    FROM sensor_readings sr
    JOIN sensors s ON s.sensor_id = sr.sensor_id
    JOIN devices d ON d.device_id = s.device_id
    LEFT JOIN locations l ON l.location_id = d.location_id
    WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    
    $params = [$days];
    $types = 'i';
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= 'i';
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= 's';
    }
    
    if ($section) {
        $sql .= " AND l.river_section = ?";
        $params[] = $section;
        $types .= 's';
    }
    
    if ($status) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $sql .= " GROUP BY s.sensor_type";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getAlertSummary($conn, $days = 7) {
    $sql = "SELECT 
        alert_type,
        COUNT(*) as total_alerts,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_alerts,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_alerts,
        SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged_alerts
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY alert_type";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getDeviceActivity($conn, $deviceId, $sensorType, $section, $status, $days = 7) {
    $sql = "SELECT 
        d.device_name,
        d.device_id,
        d.status,
        COUNT(sr.reading_id) as total_readings,
        MAX(sr.recorded_at) as last_reading,
        COUNT(DISTINCT DATE(sr.recorded_at)) as active_days
    FROM devices d
    LEFT JOIN sensors s ON s.device_id = d.device_id
    LEFT JOIN sensor_readings sr ON sr.sensor_id = s.sensor_id 
        AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    LEFT JOIN locations l ON l.location_id = d.location_id
    WHERE 1=1";
    
    $params = [$days];
    $types = 'i';
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= 'i';
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= 's';
    }
    
    if ($section) {
        $sql .= " AND l.river_section = ?";
        $params[] = $section;
        $types .= 's';
    }
    
    if ($status) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $sql .= " GROUP BY d.device_id, d.device_name, d.status";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getDailyReadingsTrend($conn, $deviceId, $sensorType, $section, $status, $days = 7) {
    $sql = "SELECT 
        DATE(sr.recorded_at) as reading_date,
        COUNT(*) as total_readings
    FROM sensor_readings sr
    JOIN sensors s ON s.sensor_id = sr.sensor_id
    JOIN devices d ON d.device_id = s.device_id
    LEFT JOIN locations l ON l.location_id = d.location_id
    WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    
    $params = [$days];
    $types = 'i';
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= 'i';
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= 's';
    }
    
    if ($section) {
        $sql .= " AND l.river_section = ?";
        $params[] = $section;
        $types .= 's';
    }
    
    if ($status) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $sql .= " GROUP BY DATE(sr.recorded_at) ORDER BY reading_date";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getRiverSectionStats($conn, $deviceId, $sensorType, $section, $status, $days = 7) {
    $sql = "SELECT 
        l.river_section,
        COUNT(DISTINCT d.device_id) as device_count,
        COUNT(sr.reading_id) as total_readings,
        AVG(CASE WHEN s.sensor_type = 'temperature' THEN sr.value END) as avg_temp,
        AVG(CASE WHEN s.sensor_type = 'ph_level' THEN sr.value END) as avg_ph,
        AVG(CASE WHEN s.sensor_type = 'turbidity' THEN sr.value END) as avg_turbidity
    FROM locations l
    LEFT JOIN devices d ON d.location_id = l.location_id
    LEFT JOIN sensors s ON s.device_id = d.device_id
    LEFT JOIN sensor_readings sr ON sr.sensor_id = s.sensor_id 
        AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE 1=1";
    
    $params = [$days];
    $types = 'i';
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= 'i';
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= 's';
    }
    
    if ($section) {
        $sql .= " AND l.river_section = ?";
        $params[] = $section;
        $types .= 's';
    }
    
    if ($status) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $sql .= " GROUP BY l.river_section";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getDeviceSensorReadings($conn, $deviceId, $sensorType, $section, $status, $days = 7, $limit = 100) {
    $sql = "SELECT 
        sr.reading_id,
        sr.value,
        sr.recorded_at,
        s.sensor_type,
        s.unit,
        d.device_name
    FROM sensor_readings sr
    JOIN sensors s ON s.sensor_id = sr.sensor_id
    JOIN devices d ON d.device_id = s.device_id
    LEFT JOIN locations l ON l.location_id = d.location_id
    WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    
    $params = [$days];
    $types = 'i';
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= 'i';
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= 's';
    }
    
    if ($section) {
        $sql .= " AND l.river_section = ?";
        $params[] = $section;
        $types .= 's';
    }
    
    if ($status) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $sql .= " ORDER BY sr.recorded_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getAllDevices($conn) {
    $sql = "SELECT device_id, device_name, status FROM devices ORDER BY device_name";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// ── Get Data ─────────────────────────────────────────────────────────────────
$reportDays = isset($_GET['days']) ? intval($_GET['days']) : 7;
$selectedDevice = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$selectedSensor = $_GET['sensor'] ?? null;
$selectedSection = $_GET['section'] ?? null;
$selectedStatus = $_GET['status'] ?? null;
$allDevices = getAllDevices($conn);
$sensorStats = getSensorReadingsStats($conn, $selectedDevice, $selectedSensor, $selectedSection, $selectedStatus, $reportDays);
$alertSummary = getAlertSummary($conn, $reportDays);
$deviceActivity = getDeviceActivity($conn, $selectedDevice, $selectedSensor, $selectedSection, $selectedStatus, $reportDays);
$dailyTrend = getDailyReadingsTrend($conn, $selectedDevice, $selectedSensor, $selectedSection, $selectedStatus, $reportDays);
$sectionStats = getRiverSectionStats($conn, $selectedDevice, $selectedSensor, $selectedSection, $selectedStatus, $reportDays);
$deviceReadings = getDeviceSensorReadings($conn, $selectedDevice, $selectedSensor, $selectedSection, $selectedStatus, $reportDays, 100);

// Calculate summary metrics
$totalReadings = array_sum(array_column($sensorStats, 'total_readings'));
$totalAlerts = array_sum(array_column($alertSummary, 'total_alerts'));
$activeDevices = count(array_filter($deviceActivity, fn($d) => $d['total_readings'] > 0));
$totalDevices = count($deviceActivity);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1a56db;
            --primary-dark: #0e3a8a;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #3b82f6;
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

        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
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

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }

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

        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray-500);
        }

        .data-table tr:hover {
            background: var(--gray-50);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 4px;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Empty State */
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
            <h1>📊 Reports & Analytics</h1>
            <p>Comprehensive water quality analysis and system performance reports</p>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card success">
                <h3><?= number_format($totalReadings) ?></h3>
                <p>Total Readings (<?= $reportDays ?>d)</p>
            </div>
            <div class="stat-card info">
                <h3><?= $activeDevices ?>/<?= $totalDevices ?></h3>
                <p>Active Devices</p>
            </div>
            <div class="stat-card warning">
                <h3><?= number_format($totalAlerts) ?></h3>
                <p>Total Alerts</p>
            </div>
            <div class="stat-card">
                <h3><?= count($sensorStats) ?></h3>
                <p>Sensor Types</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label>Time Range:</label>
                <select onchange="updateReport()" id="daysFilter">
                    <option value="7" <?= $reportDays == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="14" <?= $reportDays == 14 ? 'selected' : '' ?>>Last 14 Days</option>
                    <option value="30" <?= $reportDays == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $reportDays == 90 ? 'selected' : '' ?>>Last 90 Days</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Device:</label>
                <select onchange="updateReport()" id="deviceFilter">
                    <option value="">All Devices</option>
                    <?php foreach ($allDevices as $dev): ?>
                    <option value="<?= $dev['device_id'] ?>" <?= $selectedDevice == $dev['device_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dev['device_name']) ?> (<?= $dev['status'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Sensor:</label>
                <select onchange="updateReport()" id="sensorFilter">
                    <option value="">All Sensors</option>
                    <option value="temperature" <?= ($_GET['sensor'] ?? '') == 'temperature' ? 'selected' : '' ?>>Temperature</option>
                    <option value="ph_level" <?= ($_GET['sensor'] ?? '') == 'ph_level' ? 'selected' : '' ?>>pH Level</option>
                    <option value="turbidity" <?= ($_GET['sensor'] ?? '') == 'turbidity' ? 'selected' : '' ?>>Turbidity</option>
                    <option value="dissolved_oxygen" <?= ($_GET['sensor'] ?? '') == 'dissolved_oxygen' ? 'selected' : '' ?>>Dissolved Oxygen</option>
                    <option value="water_level" <?= ($_GET['sensor'] ?? '') == 'water_level' ? 'selected' : '' ?>>Water Level</option>
                    <option value="sediments" <?= ($_GET['sensor'] ?? '') == 'sediments' ? 'selected' : '' ?>>Sediments</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Section:</label>
                <select onchange="updateReport()" id="sectionFilter">
                    <option value="">All Sections</option>
                    <option value="upstream" <?= ($_GET['section'] ?? '') == 'upstream' ? 'selected' : '' ?>>Upstream</option>
                    <option value="midstream" <?= ($_GET['section'] ?? '') == 'midstream' ? 'selected' : '' ?>>Midstream</option>
                    <option value="downstream" <?= ($_GET['section'] ?? '') == 'downstream' ? 'selected' : '' ?>>Downstream</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status:</label>
                <select onchange="updateReport()" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="active" <?= ($_GET['status'] ?? '') == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="maintenance" <?= ($_GET['status'] ?? '') == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="inactive" <?= ($_GET['status'] ?? '') == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <a href="reports.php" class="btn btn-secondary">Reset Filters</a>
            <button onclick="exportReport()" class="btn btn-primary">📥 Export Report</button>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Daily Readings Trend -->
            <div class="card">
                <div class="card-header">
                    <h2>Daily Readings Trend</h2>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="readingsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Sensor Distribution -->
            <div class="card">
                <div class="card-header">
                    <h2>Sensor Readings Distribution</h2>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="sensorChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts Summary -->
        <div class="card">
            <div class="card-header">
                <h2>Alert Summary</h2>
            </div>
            <div class="card-body">
                <?php if (empty($alertSummary)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No alerts in the last <?= $reportDays ?> days</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Alert Type</th>
                                    <th>Total</th>
                                    <th>Active</th>
                                    <th>Resolved</th>
                                    <th>Acknowledged</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alertSummary as $alert): ?>
                                <tr>
                                    <td><?= htmlspecialchars(ucfirst($alert['alert_type'])) ?></td>
                                    <td><?= $alert['total_alerts'] ?></td>
                                    <td><?= $alert['active_alerts'] ?></td>
                                    <td><?= $alert['resolved_alerts'] ?></td>
                                    <td><?= $alert['acknowledged_alerts'] ?></td>
                                    <td>
                                        <?php if ($alert['active_alerts'] > 0): ?>
                                            <span class="badge badge-danger">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">All Clear</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Device Activity -->
        <div class="card">
            <div class="card-header">
                <h2>Device Activity Report</h2>
            </div>
            <div class="card-body">
                <?php if (empty($deviceActivity)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No device activity in the last <?= $reportDays ?> days</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Device Name</th>
                                    <th>Status</th>
                                    <th>Total Readings</th>
                                    <th>Active Days</th>
                                    <th>Last Reading</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deviceActivity as $device): ?>
                                <tr>
                                    <td><?= htmlspecialchars($device['device_name']) ?></td>
                                    <td>
                                        <?php 
                                        $badgeClass = match($device['status']) {
                                            'active' => 'badge-success',
                                            'maintenance' => 'badge-warning',
                                            'inactive', 'offline' => 'badge-danger',
                                            default => 'badge-info'
                                        };
                                        ?>
                                        <span class="badge <?= $badgeClass ?>"><?= ucfirst($device['status']) ?></span>
                                    </td>
                                    <td><?= number_format($device['total_readings']) ?></td>
                                    <td><?= $device['active_days'] ?></td>
                                    <td><?= $device['last_reading'] ? date('M d, H:i', strtotime($device['last_reading'])) : 'Never' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- River Section Stats -->
        <div class="card">
            <div class="card-header">
                <h2>River Section Analysis</h2>
            </div>
            <div class="card-body">
                <?php if (empty($sectionStats)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No river section data available</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>River Section</th>
                                    <th>Devices</th>
                                    <th>Total Readings</th>
                                    <th>Avg Temperature</th>
                                    <th>Avg pH</th>
                                    <th>Avg Turbidity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sectionStats as $section): ?>
                                <tr>
                                    <td><?= htmlspecialchars(ucfirst($section['river_section'] ?? 'Unknown')) ?></td>
                                    <td><?= $section['device_count'] ?></td>
                                    <td><?= number_format($section['total_readings']) ?></td>
                                    <td><?= $section['avg_temp'] ? number_format($section['avg_temp'], 1) . ' °C' : 'N/A' ?></td>
                                    <td><?= $section['avg_ph'] ? number_format($section['avg_ph'], 2) : 'N/A' ?></td>
                                    <td><?= $section['avg_turbidity'] ? number_format($section['avg_turbidity'], 1) . ' NTU' : 'N/A' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sensor Statistics -->
        <div class="card">
            <div class="card-header">
                <h2>Sensor Statistics (<?= $reportDays ?> Days)</h2>
            </div>
            <div class="card-body">
                <?php if (empty($sensorStats)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No sensor data in the last <?= $reportDays ?> days</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Sensor Type</th>
                                    <th>Total Readings</th>
                                    <th>Average</th>
                                    <th>Minimum</th>
                                    <th>Maximum</th>
                                    <th>Std Deviation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sensorStats as $sensor): 
                                    $unit = match($sensor['sensor_type']) {
                                        'temperature' => '°C',
                                        'ph_level' => 'pH',
                                        'turbidity' => 'NTU',
                                        'dissolved_oxygen' => 'mg/L',
                                        'water_level' => 'm',
                                        'sediments' => 'mg/L',
                                        default => ''
                                    };
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $sensor['sensor_type']))) ?></td>
                                    <td><?= number_format($sensor['total_readings']) ?></td>
                                    <td><?= number_format($sensor['avg_value'], 2) ?> <?= $unit ?></td>
                                    <td><?= number_format($sensor['min_value'], 2) ?> <?= $unit ?></td>
                                    <td><?= number_format($sensor['max_value'], 2) ?> <?= $unit ?></td>
                                    <td><?= $sensor['std_dev'] ? number_format($sensor['std_dev'], 2) : 'N/A' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Device Sensor Readings -->
        <div class="card">
            <div class="card-header">
                <h2>Device Sensor Readings</h2>
                <span style="font-size: 0.875rem; color: var(--gray-500);">
                    <?= $selectedDevice ? 'Filtered by device' : 'Showing all devices' ?> • Last <?= $reportDays ?> days
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($deviceReadings)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No sensor readings found for the selected criteria</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Sensor Type</th>
                                    <th>Value</th>
                                    <th>Unit</th>
                                    <th>Recorded At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deviceReadings as $reading): 
                                    $sensorType = ucfirst(str_replace('_', ' ', $reading['sensor_type']));
                                    $badgeClass = match($reading['sensor_type']) {
                                        'temperature' => 'badge-danger',
                                        'ph_level' => 'badge-success',
                                        'turbidity' => 'badge-warning',
                                        'dissolved_oxygen' => 'badge-info',
                                        default => 'badge-info'
                                    };
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($reading['device_name']) ?></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><?= $sensorType ?></span>
                                    </td>
                                    <td><strong><?= number_format($reading['value'], 2) ?></strong></td>
                                    <td><?= htmlspecialchars($reading['unit']) ?></td>
                                    <td><?= date('M d, Y H:i:s', strtotime($reading['recorded_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Daily Readings Chart
        const dailyCtx = document.getElementById('readingsChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($dailyTrend, 'reading_date')) ?>,
                datasets: [{
                    label: 'Daily Readings',
                    data: <?= json_encode(array_column($dailyTrend, 'total_readings')) ?>,
                    borderColor: '#1a56db',
                    backgroundColor: 'rgba(26, 86, 219, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });

        // Sensor Distribution Chart
        const sensorCtx = document.getElementById('sensorChart').getContext('2d');
        new Chart(sensorCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_map(fn($s) => ucfirst(str_replace('_', ' ', $s['sensor_type'])), $sensorStats)) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($sensorStats, 'total_readings')) ?>,
                    backgroundColor: [
                        '#1a56db',
                        '#059669',
                        '#d97706',
                        '#dc2626',
                        '#7c3aed',
                        '#0891b2'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Export function
        function exportReport() {
            const csvContent = [
                ['Report Period', 'Last <?= $reportDays ?> Days'],
                ['Generated At', new Date().toLocaleString()],
                [''],
                ['Summary Statistics'],
                ['Total Readings', '<?= $totalReadings ?>'],
                ['Active Devices', '<?= $activeDevices ?>/<?= $totalDevices ?>'],
                ['Total Alerts', '<?= $totalAlerts ?>'],
                [''],
                ['Device Activity'],
                ['Device Name', 'Status', 'Total Readings', 'Active Days', 'Last Reading'],
                <?php foreach ($deviceActivity as $device): ?>
                ['<?= addslashes($device['device_name']) ?>', '<?= $device['status'] ?>', '<?= $device['total_readings'] ?>', '<?= $device['active_days'] ?>', '<?= $device['last_reading'] ?: 'Never' ?>'],
                <?php endforeach; ?>
            ].map(row => row.join(',')).join('\n');

            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'aqua-vision-report-<?= date('Y-m-d') ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            // Show toast
            if (typeof showToast !== 'undefined') {
                showToast('Report exported successfully!', 'success', 3000);
            }
        }

        // Update report based on filters
        function updateReport() {
            const days = document.getElementById('daysFilter').value;
            const deviceId = document.getElementById('deviceFilter').value;
            const sensor = document.getElementById('sensorFilter').value;
            const section = document.getElementById('sectionFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            const params = new URLSearchParams();
            params.set('days', days);
            if (deviceId) params.set('device_id', deviceId);
            if (sensor) params.set('sensor', sensor);
            if (section) params.set('section', section);
            if (status) params.set('status', status);
            
            window.location.href = '?' + params.toString();
        }
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
