<?php
/**
 * Aqua-Vision Dashboard API
 * Handles dashboard data fetching and simulation
 */

// Set time limit to prevent timeouts
set_time_limit(30); // 30 seconds

// Disable error display for production
error_reporting(0);

require_once '../../../database/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'simulate':
            av_overview_api_simulate($conn);
            break;
        case 'fetch':
            av_overview_api_fetch($conn);
            break;
        case 'monitor_state':
            av_overview_api_monitor_state($conn);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();

// ── API Functions ────────────────────────────────────────

function av_overview_api_simulate(mysqli $conn): void {
    // Get JSON input from request body
    $jsonInput = file_get_contents('php://input');
    $input = json_decode($jsonInput, true);
    
    $deviceId = $input['device_id'] ?? null;
    
    if (!$deviceId) {
        echo json_encode(['error' => 'Device ID required']);
        return;
    }
    
    // Get the specific device
    $deviceRes = $conn->query("
        SELECT device_id, device_name 
        FROM devices 
        WHERE device_id = $deviceId AND status = 'active'
    ");
    
    if (!$deviceRes || $deviceRes->num_rows === 0) {
        echo json_encode(['error' => 'Device not found or inactive']);
        return;
    }
    
    $device = $deviceRes->fetch_assoc();
    
    // Get all sensors for this device
    $sensorRes = $conn->query("
        SELECT sensor_id, sensor_type, unit, min_threshold, max_threshold
        FROM sensors 
        WHERE device_id = $deviceId
    ");
    
    if (!$sensorRes || $sensorRes->num_rows === 0) {
        echo json_encode(['error' => 'No sensors found for device']);
        return;
    }
    
    $readings = [];
    $alerts = [];
    $lastReadingId = 0;
    
    // Generate readings for each sensor
    while ($sensor = $sensorRes->fetch_assoc()) {
        $sensorId = $sensor['sensor_id'];
        $sensorType = $sensor['sensor_type'];
        $minThreshold = (float)$sensor['min_threshold'];
        $maxThreshold = (float)$sensor['max_threshold'];
        
        // Generate realistic reading
        $value = generate_sensor_reading($sensorType, $minThreshold, $maxThreshold);
        
        // Insert reading
        $insertRes = $conn->query("
            INSERT INTO sensor_readings (sensor_id, value, recorded_at)
            VALUES ($sensorId, $value, NOW())
        ");
        
        if ($insertRes) {
            $readingId = $conn->insert_id;
            $lastReadingId = $readingId;
            
            // Check for alerts
            if ($value < $minThreshold || $value > $maxThreshold) {
                $alertType = ($value > $maxThreshold) ? 'high' : 'low';
                $message = generate_alert_message($sensorType, $value, $minThreshold, $maxThreshold);
                
                // Insert alert
                $conn->query("
                    INSERT INTO alerts (sensor_id, reading_id, alert_type, message, status, created_at)
                    VALUES ($sensorId, $readingId, '$alertType', '" . $conn->real_escape_string($message) . "', 'active', NOW())
                ");
                
                $alerts[] = [
                    'device_name' => $device['device_name'],
                    'sensor_type' => $sensorType,
                    'alert_type' => $alertType,
                    'message' => $message,
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
            
            $readings[] = [
                'sensor_id' => $sensorId,
                'sensor_type' => $sensorType,
                'value' => $value,
                'unit' => $sensor['unit'],
                'recorded_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    // Update device last_active
    $conn->query("UPDATE devices SET last_active = NOW() WHERE device_id = $deviceId");
    
    echo json_encode([
        'success' => true,
        'device_name' => $device['device_name'],
        'reading_id' => $lastReadingId,
        'readings' => $readings,
        'alerts_created' => $alerts
    ]);
}

function av_overview_api_fetch(mysqli $conn): void {
    // Get device info
    $devs = [];
    $devRes = $conn->query("
        SELECT d.device_id, d.device_name, d.device_type, d.status, d.last_active,
               l.location_name, l.river_section, l.latitude, l.longitude
        FROM devices d
        JOIN locations l ON l.location_id = d.location_id
        ORDER BY l.river_section, d.device_name
    ");
    
    while ($row = $devRes->fetch_assoc()) {
        $devs[$row['device_id']] = $row;
    }
    
    // Get latest readings
    $readings = [];
    $readRes = $conn->query("
        SELECT s.sensor_id, s.sensor_type, s.unit, s.min_threshold, s.max_threshold,
               sr.value, sr.recorded_at, s.device_id
        FROM sensors s
        JOIN sensor_readings sr ON sr.reading_id = (
            SELECT MAX(r2.reading_id) 
            FROM sensor_readings r2 
            WHERE r2.sensor_id = s.sensor_id
        )
        ORDER BY s.sensor_type, s.device_id
    ");
    
    while ($row = $readRes->fetch_assoc()) {
        $readings[] = $row;
    }
    
    // Get active alerts
    $alerts = [];
    $alertRes = $conn->query("
        SELECT a.alert_id, a.alert_type, a.message, a.created_at,
               s.sensor_type, s.unit, sr.value,
               l.location_name, d.device_name
        FROM alerts a
        JOIN sensors s ON s.sensor_id = a.sensor_id
        JOIN sensor_readings sr ON sr.reading_id = a.reading_id
        JOIN devices d ON d.device_id = s.device_id
        JOIN locations l ON l.location_id = d.location_id
        WHERE a.status = 'active'
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    
    while ($row = $alertRes->fetch_assoc()) {
        $alerts[] = $row;
    }
    
    // Get 24-hour trend data
    $chartData = [
        'temperature' => av_overview_trend24($conn, 'temperature'),
        'pH' => av_overview_trend24($conn, 'ph_level'),
        'turbidity' => av_overview_trend24($conn, 'turbidity'),
        'dissolved_oxygen' => av_overview_trend24($conn, 'dissolved_oxygen'),
        'water_level' => av_overview_trend24($conn, 'water_level'),
        'sediments' => av_overview_trend24($conn, 'sediments'),
    ];
    
    // Get device-specific chart data
    $deviceChartData = [];
    foreach ($devs as $dev) {
        $did = (int)$dev['device_id'];
        $deviceChartData[$did] = [
            'temperature' => av_overview_trend24($conn, 'temperature', $did),
            'pH' => av_overview_trend24($conn, 'ph_level', $did),
            'turbidity' => av_overview_trend24($conn, 'turbidity', $did),
            'dissolved_oxygen' => av_overview_trend24($conn, 'dissolved_oxygen', $did),
            'water_level' => av_overview_trend24($conn, 'water_level', $did),
            'sediments' => av_overview_trend24($conn, 'sediments', $did),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'devices' => $devs,
        'readings' => $readings,
        'alerts' => $alerts,
        'chartData' => $chartData,
        'deviceChartData' => $deviceChartData
    ]);
}

function av_overview_api_monitor_state(mysqli $conn): void {
    // Get current system state
    $deviceCount = $conn->query("SELECT COUNT(*) AS cnt FROM devices")->fetch_assoc()['cnt'];
    $activeDevices = $conn->query("SELECT COUNT(*) AS cnt FROM devices WHERE status = 'active'")->fetch_assoc()['cnt'];
    $alertCount = $conn->query("SELECT COUNT(*) AS cnt FROM alerts WHERE status = 'active'")->fetch_assoc()['cnt'];
    
    // Get latest reading timestamp
    $lastReading = $conn->query("
        SELECT MAX(recorded_at) AS last_ts 
        FROM sensor_readings
    ")->fetch_assoc()['last_ts'];
    
    echo json_encode([
        'success' => true,
        'device_count' => (int)$deviceCount,
        'active_devices' => (int)$activeDevices,
        'alert_count' => (int)$alertCount,
        'last_reading' => $lastReading
    ]);
}

// ── Helper Functions ─────────────────────────────────────

function generate_sensor_reading(string $sensorType, float $min, float $max): float {
    $range = $max - $min;
    $safeRange = $range * 0.8; // Keep 80% in safe range
    
    switch ($sensorType) {
        case 'temperature':
            return round(rand(20, 35) + rand(-5, 5) / 10, 2);
        case 'ph_level':
            return round(rand(65, 85) / 10, 2);
        case 'turbidity':
            return round(rand(0, 50) + rand(-10, 20), 2);
        case 'dissolved_oxygen':
            return round(rand(5, 14) + rand(-2, 2) / 10, 2);
        case 'water_level':
            return round(rand(50, 250) / 100, 2);
        case 'sediments':
            return round(rand(0, 500) + rand(-50, 100), 2);
        default:
            return round($min + rand(0, $safeRange) + rand(-$range * 0.1, $range * 0.1), 2);
    }
}

function generate_alert_message(string $sensorType, float $value, float $min, float $max): string {
    $sensorLabels = [
        'temperature' => 'Temperature',
        'ph_level' => 'pH Level',
        'turbidity' => 'Turbidity',
        'dissolved_oxygen' => 'Dissolved Oxygen',
        'water_level' => 'Water Level',
        'sediments' => 'Sediments'
    ];
    
    $label = $sensorLabels[$sensorType] ?? $sensorType;
    
    if ($value > $max) {
        return "$label too high: {$value} (max: $max)";
    } else {
        return "$label too low: {$value} (min: $min)";
    }
}

function av_overview_trend24(mysqli $conn, string $col, ?int $deviceId = null): array {
    $t = $conn->real_escape_string($col);
    $deviceFilter = $deviceId ? "AND d.device_id = $deviceId" : "";
    
    $res = $conn->query("
        SELECT HOUR(sr.recorded_at) AS hr, AVG(sr.value) AS avg_val
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        JOIN devices d ON d.device_id = s.device_id
        WHERE s.sensor_type = '$t'
          AND sr.recorded_at >= NOW() - INTERVAL 24 HOUR
          $deviceFilter
        GROUP BY hr 
        ORDER BY hr
    ");
    
    $map = [];
    while ($r = $res->fetch_assoc()) {
        $map[(int)$r['hr']] = round((float)$r['avg_val'], 2);
    }
    
    $out = [];
    for ($i = 0; $i < 24; $i++) {
        $out[] = $map[$i] ?? null;
    }
    
    return $out;
}
?>
