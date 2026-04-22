<?php
/**
 * Aqua-Vision — Overview Dashboard (Redesigned + Fixed)
 * Location: apps/admin/dashboard.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Dashboard Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// ── Admin Access Control ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: /aqua-vision-v2/login.php');
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    if ($_SESSION['user_role'] === 'researcher') {
        header('Location: /aqua-vision-v2/apps/researcher/dashboard.php');
    } else {
        header('Location: /aqua-vision-v2/login.php');
    }
    exit;
}

// ── Helper ────────────────────────────────────────────────────────────────────
function trend24(mysqli $conn, string $col, ?int $deviceId = null): array {
    $c = $conn->real_escape_string($col);
    $deviceFilter = $deviceId ? "AND s.device_id = " . (int)$deviceId : "";
    $sql = "SELECT HOUR(sr.recorded_at) AS hr, AVG(sr.value) AS avg_val 
            FROM sensor_readings sr 
            JOIN sensors s ON s.sensor_id = sr.sensor_id 
            WHERE s.sensor_type = '$c' AND sr.recorded_at >= NOW() - INTERVAL 24 HOUR $deviceFilter 
            GROUP BY hr ORDER BY hr";
    $res = $conn->query($sql);
    if (!$res) { return array_fill(0, 24, null); }
    $map = [];
    while ($r = $res->fetch_assoc()) $map[(int)$r['hr']] = round((float)$r['avg_val'], 2);
    $out = [];
    for ($i = 0; $i < 24; $i++) $out[] = $map[$i] ?? null;
    return $out;
}

// ── API: ?action=simulate ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'simulate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { echo json_encode(['error'=>'Invalid JSON']); exit; }
    $did  = isset($body['device_id'])       ? (int)$body['device_id']         : 0;
    $temp = isset($body['temperature'])      ? (float)$body['temperature']     : null;
    $ph   = isset($body['ph_level'])         ? (float)$body['ph_level']        : null;
    $turb = isset($body['turbidity'])        ? (float)$body['turbidity']       : null;
    $do_  = isset($body['dissolved_oxygen']) ? (float)$body['dissolved_oxygen']: null;
    $wl   = isset($body['water_level'])      ? (float)$body['water_level']     : null;
    $sed  = isset($body['sediments'])        ? (float)$body['sediments']       : null;
    if ($did <= 0) { echo json_encode(['error'=>'Invalid device_id']); exit; }
    $chk = $conn->prepare("SELECT d.device_id, d.device_name, l.river_section FROM devices d LEFT JOIN locations l ON l.location_id = d.location_id WHERE d.device_id=?");
    $chk->bind_param('i', $did); $chk->execute();
    $dev = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$dev) { echo json_encode(['error'=>"Device $did not found"]); exit; }

    // Insert via sensor_readings table
    $alertsCreated = [];
    $insertedReadings = [];
    $sensorMap = [
        'temperature' => [$temp, 20, 35, 'Temperature', '°C'],
        'ph_level'    => [$ph, 6.5, 8.5, 'pH Level', 'pH'],
        'turbidity'   => [$turb, 0, 50, 'Turbidity', 'NTU'],
        'dissolved_oxygen' => [$do_, 5, 14, 'Dissolved Oxygen', 'mg/L'],
        'water_level' => [$wl, 0.5, 3.0, 'Water Level', 'm'],
        'sediments'   => [$sed, 0, 500, 'Sediments', 'mg/L'],
    ];

    $lastRid = 0;
    foreach ($sensorMap as $stype => [$v, $mn, $mx, $lbl, $unit]) {
        if ($v === null) continue;
        // Get or create sensor_id for this device+type
        $sRes = $conn->query("SELECT sensor_id FROM sensors WHERE device_id=$did AND sensor_type='" . $conn->real_escape_string($stype) . "' LIMIT 1");
        if (!$sRes) continue;
        
        if ($sRes->num_rows === 0) {
            // Auto-create sensor if it doesn't exist
            $insSensor = $conn->prepare("INSERT INTO sensors (device_id, sensor_type, unit, min_threshold, max_threshold) VALUES (?, ?, ?, ?, ?)");
            $insSensor->bind_param('issdd', $did, $stype, $unit, $mn, $mx);
            if (!$insSensor->execute()) { $insSensor->close(); continue; }
            $sid = $conn->insert_id;
            $insSensor->close();
        } else {
            $sid = (int)$sRes->fetch_assoc()['sensor_id'];
        }

        $ins = $conn->prepare("INSERT INTO sensor_readings (sensor_id, value, recorded_at) VALUES (?, ?, NOW())");
        $ins->bind_param('id', $sid, $v);
        if (!$ins->execute()) { $ins->close(); continue; }
        $rid = $conn->insert_id; $ins->close();
        $lastRid = $rid;
        $insertedReadings[$stype] = ['value' => $v, 'unit' => $unit, 'reading_id' => $rid];

        if ($v < $mn || $v > $mx) {
            $dir = $v < $mn ? 'low' : 'high';
            $type = ($v < $mn * 0.8 || $v > $mx * 1.3) ? 'critical' : $dir;
            $msg = "$lbl $dir: $v (safe $mn–$mx) on {$dev['device_name']}";
            $ast = $conn->prepare("INSERT INTO alerts (sensor_id, reading_id, alert_type, message, status, created_at) VALUES (?,?,?,?,'active',NOW())");
            $ast->bind_param('iiss', $sid, $rid, $type, $msg); $ast->execute(); $ast->close();
            $alertsCreated[] = ['type'=>$type,'message'=>$msg,'sensor_type'=>$stype,'value'=>$v];
        }
    }

    $conn->query("UPDATE devices SET last_active=NOW() WHERE device_id=$did");

    // Log simulation activity
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['username'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $logDetails = "User {$userName} simulated readings for device {$dev['device_name']} (ID: {$did})";
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address, user_agent) VALUES (?, 'SENSOR_SIMULATION', ?, ?, ?)");
    $stmt->bind_param("isss", $userId, $logDetails, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();

    // Fetch fresh data for syncing
    $syncData = _build_full_fetch($conn);
    $conn->close();

    echo json_encode([
        'success'       => true,
        'reading_id'    => $lastRid,
        'device_id'     => $did,
        'device_name'   => $dev['device_name'],
        'river_section' => $dev['river_section'],
        'readings'      => $insertedReadings,
        'alerts_created'=> $alertsCreated,
        'timestamp'     => date('Y-m-d H:i:s'),
        'sync'          => $syncData,
    ]);
    exit;
}

// ── API: ?action=monitor_state ────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
if (($_GET['action'] ?? '') === 'monitor_state') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON body']); exit; }
            $state = json_encode(['running'=>$body['running']??false,'mode'=>$body['mode']??'normal','device_id'=>$body['device_id']??0,'interval'=>$body['interval']??5000,'started_at'=>($body['running']??false)?date('Y-m-d H:i:s'):null,'started_by'=>$_SESSION['user_id']??0]);
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('live_monitor',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
            if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'Prepare failed: '.$conn->error]); exit; }
            $stmt->bind_param('s', $state); $stmt->execute(); $stmt->close();
            echo json_encode(['ok'=>true]);
        } else {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='live_monitor'");
            $row = $res->fetch_assoc();
            $state = $row ? json_decode($row['setting_value'], true) : ['running'=>false];
            if (json_last_error() !== JSON_ERROR_NONE) $state = ['running'=>false];
            echo json_encode(['ok'=>true,'state'=>$state]);
        }
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── Helper: build full fetch payload ─────────────────────────────────────────
function _build_full_fetch(mysqli $conn): array {
    $dcRes = $conn->query("SELECT COUNT(*) AS total,SUM(status='active') AS active,SUM(status='inactive') AS offline,SUM(status='maintenance') AS maint FROM devices");
    $devCounts = $dcRes->fetch_assoc();
    $alertCount = (int)$conn->query("SELECT COUNT(*) AS cnt FROM alerts WHERE status='active'")->fetch_assoc()['cnt'];

    $dRes = $conn->query("SELECT d.device_id,d.device_name,d.status,d.last_active,l.location_name,l.river_section FROM devices d LEFT JOIN locations l ON l.location_id=d.location_id WHERE d.status='active' ORDER BY l.river_section,d.device_name");
    $devs = [];
    while ($r = $dRes->fetch_assoc()) $devs[] = $r;

    $deviceReadings = [];
    foreach ($devs as $dv) {
        $did = (int)$dv['device_id'];
        // Get latest reading per sensor type
        $r = $conn->query("SELECT s.sensor_type, sr.value, sr.recorded_at FROM sensor_readings sr JOIN sensors s ON s.sensor_id=sr.sensor_id WHERE s.device_id=$did ORDER BY sr.recorded_at DESC LIMIT 50");
        $latest = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (!isset($latest[$row['sensor_type']])) $latest[$row['sensor_type']] = $row;
            }
        }
        // Flatten for compat
        $flat = ['recorded_at' => null];
        foreach (['temperature','ph_level','turbidity','dissolved_oxygen','water_level','sediments'] as $st) {
            $flat[$st] = $latest[$st]['value'] ?? null;
            if ($latest[$st]['recorded_at'] ?? null) {
                if (!$flat['recorded_at'] || $latest[$st]['recorded_at'] > $flat['recorded_at'])
                    $flat['recorded_at'] = $latest[$st]['recorded_at'];
            }
        }
        $deviceReadings[$did] = array_sum(array_map(fn($v)=>$v!==null?1:0, array_intersect_key($flat, array_flip(['temperature','ph_level','turbidity','dissolved_oxygen','water_level','sediments'])))) > 0 ? $flat : null;
    }

    $aRes = $conn->query("SELECT a.alert_id,a.alert_type,a.message,a.created_at,l.location_name,d.device_name,s.sensor_type FROM alerts a JOIN sensors s ON s.sensor_id=a.sensor_id JOIN devices d ON d.device_id=s.device_id JOIN locations l ON l.location_id=d.location_id WHERE a.status='active' ORDER BY a.created_at DESC LIMIT 10");
    $alerts = [];
    while ($r = $aRes->fetch_assoc()) $alerts[] = $r;

    // Logs via sensor_readings
    $lRes = $conn->query("SELECT sr.recorded_at,d.device_id,d.device_name,l.location_name,l.river_section,s.sensor_type,s.unit,s.min_threshold,s.max_threshold,sr.value FROM sensor_readings sr JOIN sensors s ON s.sensor_id=sr.sensor_id JOIN devices d ON d.device_id=s.device_id JOIN locations l ON l.location_id=d.location_id ORDER BY sr.recorded_at DESC LIMIT 60");
    $logs = [];
    while ($r = $lRes->fetch_assoc()) $logs[] = ['recorded_at'=>$r['recorded_at'],'device_id'=>(int)$r['device_id'],'device_name'=>$r['device_name'],'location_name'=>$r['location_name'],'river_section'=>$r['river_section'],'sensor_type'=>$r['sensor_type'],'unit'=>$r['unit'],'min_threshold'=>(float)$r['min_threshold'],'max_threshold'=>(float)$r['max_threshold'],'value'=>(float)$r['value']];

    $locRes = $conn->query("SELECT l.location_id,l.river_section,COUNT(d.device_id) AS total_devices,SUM(d.status='active') AS active_devices,SUM(d.status='maintenance') AS maint_devices FROM locations l LEFT JOIN devices d ON d.location_id=l.location_id GROUP BY l.location_id");
    $mapLoc = [];
    while ($r = $locRes->fetch_assoc()) $mapLoc[] = $r;

    // Maintenance logs
    $mRes = $conn->query("SELECT ml.maintenance_type,ml.notes,ml.performed_at,d.device_name,u.full_name FROM maintenance_logs ml JOIN devices d ON d.device_id=ml.device_id JOIN users u ON u.user_id=ml.performed_by ORDER BY ml.performed_at DESC LIMIT 4");
    $maints = [];
    while ($r = $mRes->fetch_assoc()) $maints[] = $r;

    // Warn count
    $warnCount = 0;
    $checks = [['temperature',20,35],['ph_level',6.5,8.5],['turbidity',0,50],['dissolved_oxygen',5,14],['water_level',0.5,3.0],['sediments',0,500]];
    foreach ($deviceReadings as $row) {
        if (!$row) continue;
        foreach ($checks as [$f,$mn,$mx]) { if ($row[$f] !== null && ($row[$f]<$mn||$row[$f]>$mx)) $warnCount++; }
    }

    $riverStatus = $warnCount===0?'Normal':($warnCount<=2?'Moderate':'Critical');
    $bannerColor = $warnCount===0?'#16a34a':($warnCount<=2?'#f59e0b':'#ef4444');
    $bannerEmoji = $warnCount===0?'✅':($warnCount<=2?'⚠️':'🚨');

    $chartData = ['temperature'=>trend24($conn,'temperature'),'pH'=>trend24($conn,'ph_level'),'turbidity'=>trend24($conn,'turbidity'),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen'),'water_level'=>trend24($conn,'water_level'),'sediments'=>trend24($conn,'sediments')];
    $deviceChartData = [];
    foreach ($devs as $dev) {
        $did = (int)$dev['device_id'];
        $deviceChartData[$did] = ['temperature'=>trend24($conn,'temperature',$did),'pH'=>trend24($conn,'ph_level',$did),'turbidity'=>trend24($conn,'turbidity',$did),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen',$did),'water_level'=>trend24($conn,'water_level',$did),'sediments'=>trend24($conn,'sediments',$did)];
    }

    // Water conditions per section
    $sectionConditions = [];
    foreach (['upstream','midstream','downstream'] as $sec) {
        $sData = ['temperature'=>[],'ph_level'=>[],'turbidity'=>[],'dissolved_oxygen'=>[],'water_level'=>[],'sediments'=>[]];
        foreach ($devs as $dv) {
            if (($dv['river_section']??'') !== $sec) continue;
            $did = (int)$dv['device_id'];
            $dr = $deviceReadings[$did] ?? null;
            if (!$dr) continue;
            foreach (array_keys($sData) as $k) { if ($dr[$k] !== null) $sData[$k][] = (float)$dr[$k]; }
        }
        $avgs = [];
        foreach ($sData as $k => $vals) $avgs[$k] = count($vals) ? round(array_sum($vals)/count($vals), 2) : null;
        $sectionConditions[$sec] = $avgs;
    }

    return [
        'ok'=>true,'ts'=>date('Y-m-d H:i:s'),'river_status'=>$riverStatus,'banner_color'=>$bannerColor,'banner_emoji'=>$bannerEmoji,'warn_count'=>$warnCount,'alert_count'=>$alertCount,'dev_counts'=>$devCounts,
        'device_readings'=>$deviceReadings,
        'devices'=>array_map(fn($d)=>['device_id'=>(int)$d['device_id'],'device_name'=>$d['device_name'],'status'=>$d['status'],'location_name'=>$d['location_name'],'river_section'=>$d['river_section'],'last_active'=>$d['last_active']],$devs),
        'alerts'=>$alerts,'logs'=>$logs,'map_locations'=>$mapLoc,'chart_data'=>$chartData,'device_chart_data'=>$deviceChartData,'maintenance'=>$maints,'section_conditions'=>$sectionConditions,
    ];
}

// ── API: ?action=fetch ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'fetch') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    try {
        $payload = _build_full_fetch($conn);
        $conn->close();
        echo json_encode($payload, JSON_NUMERIC_CHECK);
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'overview';
$tablesExist = $conn->query("SHOW TABLES LIKE 'devices'")->num_rows > 0;
if (!$tablesExist) { header('Location: ../database/setup.php'); exit(); }

$devCounts = $conn->query("SELECT COUNT(*) AS total,SUM(status='active') AS active,SUM(status='inactive') AS offline,SUM(status='maintenance') AS maint FROM devices")->fetch_assoc();
$alertCount = (int)$conn->query("SELECT COUNT(*) AS cnt FROM alerts WHERE status='active'")->fetch_assoc()['cnt'];

$alertsRes = $conn->query("SELECT a.alert_id,a.alert_type,a.message,a.created_at,l.location_name,d.device_name FROM alerts a JOIN sensors s ON s.sensor_id=a.sensor_id JOIN devices d ON d.device_id=s.device_id JOIN locations l ON l.location_id=d.location_id WHERE a.status='active' ORDER BY a.created_at DESC LIMIT 10");
$alerts = [];
if ($alertsRes) while ($r = $alertsRes->fetch_assoc()) $alerts[] = $r;

$devicesRes = $conn->query("SELECT d.device_id,d.device_name,d.status,d.last_active,l.location_name,l.river_section,l.latitude,l.longitude,l.location_id FROM devices d LEFT JOIN locations l ON l.location_id=d.location_id WHERE d.status='active' ORDER BY l.river_section,d.device_name");
$devices = [];
$mapLocations = [];
$locationDevices = [];
if ($devicesRes) {
    while ($r = $devicesRes->fetch_assoc()) {
        $devices[] = $r;
        // Build map locations from device data
        if ($r['location_id'] && !isset($locationDevices[$r['location_id']])) {
            $locationDevices[$r['location_id']] = [];
            $mapLocations[] = [
                'location_id' => $r['location_id'],
                'location_name' => $r['location_name'],
                'latitude' => $r['latitude'],
                'longitude' => $r['longitude'],
                'river_section' => $r['river_section']
            ];
        }
        if ($r['location_id']) {
            $locationDevices[$r['location_id']][] = $r;
        }
    }
}

$deviceReadings = [];
foreach ($devices as $dev) {
    $did = (int)$dev['device_id'];
    $r = $conn->query("SELECT s.sensor_type, sr.value, sr.recorded_at FROM sensor_readings sr JOIN sensors s ON s.sensor_id=sr.sensor_id WHERE s.device_id=$did ORDER BY sr.recorded_at DESC LIMIT 50");
    $latest = [];
    if ($r) { while ($row = $r->fetch_assoc()) { if (!isset($latest[$row['sensor_type']])) $latest[$row['sensor_type']] = $row; } }
    $flat = ['recorded_at'=>null];
    foreach (['temperature','ph_level','turbidity','dissolved_oxygen','water_level','sediments'] as $st) {
        $flat[$st] = $latest[$st]['value'] ?? null;
        if (($latest[$st]['recorded_at'] ?? null) && (!$flat['recorded_at'] || $latest[$st]['recorded_at'] > $flat['recorded_at'])) $flat['recorded_at'] = $latest[$st]['recorded_at'];
    }
    $deviceReadings[$did] = $flat;
}

$maintRes = $conn->query("SELECT ml.maintenance_type,ml.notes,ml.performed_at,d.device_name,u.full_name FROM maintenance_logs ml JOIN devices d ON d.device_id=ml.device_id JOIN users u ON u.user_id=ml.performed_by ORDER BY ml.performed_at DESC LIMIT 4");
$maints = [];
if ($maintRes) while ($r = $maintRes->fetch_assoc()) $maints[] = $r;

// Fetch logs for sensor data logs section
$logsRes = $conn->query("SELECT sr.recorded_at,d.device_id,d.device_name,l.location_name,l.river_section,s.sensor_type,s.unit,s.min_threshold,s.max_threshold,sr.value FROM sensor_readings sr JOIN sensors s ON s.sensor_id=sr.sensor_id JOIN devices d ON d.device_id=s.device_id JOIN locations l ON l.location_id=d.device_id ORDER BY sr.recorded_at DESC LIMIT 60");
$logs = [];
if ($logsRes) while ($r = $logsRes->fetch_assoc()) $logs[] = $r;

$chartData = ['temperature'=>trend24($conn,'temperature'),'pH'=>trend24($conn,'ph_level'),'turbidity'=>trend24($conn,'turbidity'),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen'),'water_level'=>trend24($conn,'water_level'),'sediments'=>trend24($conn,'sediments')];
$allChartData = [];
foreach ($devices as $dev) {
    $did = (int)$dev['device_id'];
    $allChartData[$did] = ['temperature'=>trend24($conn,'temperature',$did),'pH'=>trend24($conn,'ph_level',$did),'turbidity'=>trend24($conn,'turbidity',$did),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen',$did),'water_level'=>trend24($conn,'water_level',$did),'sediments'=>trend24($conn,'sediments',$did)];
}

// Section conditions
$sectionConditions = [];
foreach (['upstream','midstream','downstream'] as $sec) {
    $sData = ['temperature'=>[],'ph_level'=>[],'turbidity'=>[],'dissolved_oxygen'=>[],'water_level'=>[],'sediments'=>[]];
    foreach ($devices as $dv) {
        if (($dv['river_section']??'')===$sec) {
            $did=(int)$dv['device_id']; $dr=$deviceReadings[$did]??null; if(!$dr) continue;
            foreach(array_keys($sData) as $k) { if($dr[$k]!==null) $sData[$k][]=(float)$dr[$k]; }
        }
    }
    $avgs=[];
    foreach($sData as $k=>$vals) $avgs[$k]=count($vals)?round(array_sum($vals)/count($vals),2):null;
    $sectionConditions[$sec]=$avgs;
}

$warnCount = 0;
$checks = [['temperature',20,35],['ph_level',6.5,8.5],['turbidity',0,50],['dissolved_oxygen',5,14],['water_level',0.5,3.0],['sediments',0,500]];
foreach ($deviceReadings as $row) { if (!$row) continue; foreach ($checks as [$f,$mn,$mx]) { if ($row[$f]!==null&&($row[$f]<$mn||$row[$f]>$mx)) $warnCount++; } }
$riverStatus = $warnCount===0?'Normal':($warnCount<=2?'Moderate':'Critical');
$bannerColor = $warnCount===0?'#16a34a':($warnCount<=2?'#f59e0b':'#ef4444');
$lastTs = !empty($logs) ? $logs[0]['recorded_at'] : null;
?>
<?php include '../../assets/navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aqua-Vision — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root {
  --ink:#0d1117;--ink2:#3d4a5c;--ink3:#8897aa;--ink4:#b8c4d0;
  --rule:rgba(13,17,23,.07);--rule2:rgba(13,17,23,.12);
  --bg:#f5f6f8;--surf:#ffffff;--surf2:#f9fafb;
  --accent:#1a56db;--acc-bg:#eff4ff;
  --good:#059669;--good-bg:#d1fae5;
  --warn:#d97706;--warn-bg:#fef3c7;
  --crit:#dc2626;--crit-bg:#fee2e2;
  --up:#059669;--mid:#d97706;--down:#dc2626;
  --r-sm:4px;--r:8px;--r-lg:12px;--r-xl:16px;
  --sh:0 1px 2px rgba(13,17,23,.04),0 4px 16px rgba(13,17,23,.06);
  --sh-sm:0 1px 2px rgba(13,17,23,.05);
  --sans:'Instrument Sans',sans-serif;--serif:'Instrument Serif',serif;--mono:'JetBrains Mono',monospace;
}
html{font-size:14px}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased}
::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--rule2);border-radius:4px}
.wrap{max-width:1440px;padding:28px 32px 64px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:32px;padding-bottom:20px;border-bottom:1px solid var(--rule)}
.topbar-brand{display:flex;align-items:baseline;gap:10px}
.topbar-brand .wordmark{font-family:var(--serif);font-size:22px;color:var(--ink);letter-spacing:-.01em;font-style:italic}
.topbar-brand .slash{color:var(--ink4);font-size:16px;margin:0 2px}
.topbar-brand .page-name{font-size:13px;font-weight:500;color:var(--ink3);letter-spacing:.01em}
.topbar-right{display:flex;align-items:center;gap:10px}
.ts-line{font-family:var(--mono);font-size:11px;color:var(--ink4);display:flex;align-items:center;gap:6px}
.ts-line::before{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--good);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.btn{height:32px;padding:0 14px;border-radius:var(--r);font:500 12px/1 var(--sans);cursor:pointer;border:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s;text-decoration:none;white-space:nowrap}
.btn-outline{background:var(--surf);color:var(--ink2);border:1px solid var(--rule2)}
.btn-outline:hover{background:var(--surf2);border-color:var(--ink4)}
.btn-primary{background:var(--ink);color:#fff}
.btn-primary:hover{background:var(--ink2)}
.river-banner{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:20px;background:var(--surf);border:1px solid var(--rule);border-radius:var(--r-xl);padding:18px 24px;margin-bottom:24px;box-shadow:var(--sh-sm);position:relative;overflow:hidden}
.river-banner::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--status-color,var(--good));border-radius:3px 0 0 3px}
.banner-status-dot{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;background:var(--status-bg,var(--good-bg));flex-shrink:0}
.banner-body{min-width:0}
.banner-title{font-family:var(--serif);font-size:16px;font-style:italic;color:var(--ink);margin-bottom:2px}
.banner-sub{font-size:12px;color:var(--ink3)}
.banner-stats{display:flex;align-items:center;gap:0}
.bstat{padding:0 20px;text-align:right;border-left:1px solid var(--rule)}
.bstat:first-child{border-left:none}
.bstat-v{font-family:var(--mono);font-size:18px;font-weight:500;color:var(--ink)}
.bstat-l{font-size:10px;color:var(--ink4);letter-spacing:.06em;text-transform:uppercase;margin-top:2px}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.kpi{background:var(--surf);border:1px solid var(--rule);border-radius:var(--r-lg);padding:18px 20px;box-shadow:var(--sh-sm);position:relative}
.kpi-label{font-size:11px;font-weight:500;color:var(--ink3);letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px}
.kpi-value{font-family:var(--mono);font-size:28px;font-weight:500;color:var(--ink);line-height:1}
.kpi-sub{font-size:11px;color:var(--ink4);margin-top:6px}
.kpi-badge{position:absolute;top:16px;right:16px;width:28px;height:28px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:13px}
.kpi-badge.good{background:var(--good-bg)}.kpi-badge.warn{background:var(--warn-bg)}.kpi-badge.crit{background:var(--crit-bg)}.kpi-badge.info{background:var(--acc-bg)}
.grid-main{display:grid;grid-template-columns:1fr 360px;gap:16px;margin-bottom:16px;align-items:start}
.grid-bottom{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.card{background:var(--surf);border:1px solid var(--rule);border-radius:var(--r-xl);box-shadow:var(--sh-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--rule);gap:10px;flex-wrap:wrap}
.card-head-l{display:flex;align-items:center;gap:8px;min-width:0}
.card-title{font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-head-r{display:flex;align-items:center;gap:8px;flex-shrink:0}
.tag{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap;letter-spacing:.03em}
.tag-good{background:var(--good-bg);color:var(--good)}
.tag-warn{background:var(--warn-bg);color:var(--warn)}
.tag-crit{background:var(--crit-bg);color:var(--crit)}
.tag-info{background:var(--acc-bg);color:var(--accent)}
.tag-mute{background:#f3f4f6;color:#6b7280}
.tag-up{background:#d1fae5;color:#059669}.tag-mid{background:#fef3c7;color:#d97706}.tag-down{background:#fee2e2;color:#dc2626}
.sel{height:30px;padding:0 10px;border:1px solid var(--rule2);border-radius:var(--r);font:500 11px var(--sans);background:var(--surf);color:var(--ink2);cursor:pointer;outline:none;transition:border-color .15s}
.sel:hover,.sel:focus{border-color:var(--ink4)}
#av-map{height:420px;width:100%}
.map-legend{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:10px 18px;border-top:1px solid var(--rule);background:var(--surf2)}
.leg{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--ink3)}
.leg-dot{width:7px;height:7px;border-radius:50%}
.sim-log-wrap{padding:10px 18px 12px;border-top:1px solid var(--rule);background:var(--surf2)}
.sim-log-label{font-size:10px;font-weight:600;color:var(--ink4);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
#simLog{overflow-y:auto;font-family:var(--mono);font-size:10.5px;line-height:1.6}
.dev-panel{overflow-y:auto;max-height:500px}
.dev-panel-empty{padding:48px 24px;text-align:center;color:var(--ink4)}
.dev-panel-empty .empty-icon{font-size:32px;margin-bottom:10px;opacity:.4}
.dev-panel-empty p{font-size:12px}
.dev-header{padding:14px 18px;border-bottom:1px solid var(--rule);background:var(--surf2);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.dev-name{font-size:14px;font-weight:600;color:var(--ink);display:flex;align-items:center;gap:6px}
.dev-loc{font-size:11px;color:var(--ink4);margin-top:3px}
.sensor-row{display:flex;align-items:center;padding:11px 18px;border-bottom:1px solid var(--rule);gap:12px;transition:background .1s}
.sensor-row:last-child{border-bottom:none}
.sensor-row:hover{background:var(--surf2)}
.sensor-row.out{background:rgba(217,119,6,.03)}
.sensor-row.out:hover{background:rgba(217,119,6,.06)}
.sensor-icon{width:32px;height:32px;border-radius:var(--r);background:var(--surf2);border:1px solid var(--rule);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.sensor-label{flex:1;min-width:0}
.sensor-name{font-size:12px;font-weight:500;color:var(--ink)}
.sensor-range{font-size:10px;color:var(--ink4);margin-top:1px;font-family:var(--mono)}
.sensor-val{font-family:var(--mono);font-size:18px;font-weight:500;text-align:right;flex-shrink:0}
.sensor-val .unit{font-size:11px;color:var(--ink4);margin-left:2px;font-weight:400}
.sensor-status{text-align:right;flex-shrink:0;min-width:80px}
.chart-wrap{padding:12px 16px 16px;height:280px;position:relative}
.alert-item{display:flex;align-items:flex-start;gap:10px;padding:12px 18px;border-bottom:1px solid var(--rule)}
.alert-item:last-child{border-bottom:none}
.alert-ic{width:28px;height:28px;border-radius:var(--r);display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px}
.alert-ic.crit{background:var(--crit-bg)}.alert-ic.warn{background:var(--warn-bg)}
.alert-msg{font-size:12px;font-weight:500;color:var(--ink);line-height:1.4}
.alert-meta{font-size:11px;color:var(--ink4);margin-top:3px;font-family:var(--mono)}
.maint-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--rule)}
.maint-item:last-child{border-bottom:none}
.maint-ic{width:32px;height:32px;border-radius:var(--r);background:var(--warn-bg);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.maint-name{font-size:12px;font-weight:500;color:var(--ink)}
.maint-note{font-size:11px;color:var(--ink4);margin-top:2px}
.maint-when{font-family:var(--mono);font-size:10px;color:var(--ink4);text-align:right;margin-left:auto;white-space:nowrap}
.log-filter-bar{padding:10px 18px;border-bottom:1px solid var(--rule);background:var(--surf2);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.log-filter-label{font-size:11px;color:var(--ink4);font-weight:500}
.dev-group-head{padding:10px 18px;display:flex;align-items:center;gap:10px;background:var(--surf2);cursor:pointer;user-select:none;border-bottom:1px solid var(--rule);transition:background .1s}
.dev-group-head:hover{background:#f1f3f5}
.dev-group-badge{width:28px;height:28px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.dev-group-name{font-size:12px;font-weight:600;color:var(--ink)}
.dev-group-loc{font-size:11px;color:var(--ink4)}
.dev-group-meta{margin-left:auto;text-align:right;flex-shrink:0}
.dev-group-ts{font-family:var(--mono);font-size:10px;color:var(--ink4)}
.dev-group-chevron{font-size:10px;color:var(--ink4);margin-left:4px;transition:transform .2s}
.dev-group-chevron.open{transform:rotate(180deg)}
.metric-tbl{width:100%;border-collapse:collapse}
.metric-tbl tr{border-bottom:1px solid var(--rule);transition:background .1s}
.metric-tbl tr:last-child{border-bottom:none}
.metric-tbl tr:hover{background:var(--surf2)}
.metric-tbl tr.out{background:rgba(217,119,6,.03)}
.metric-tbl tr.out:hover{background:rgba(217,119,6,.06)}
.metric-tbl td{padding:9px 14px;font-size:12px;color:var(--ink3);font-family:var(--mono)}
.metric-tbl td.icon-c{width:32px;padding-right:4px;font-size:14px}
.metric-tbl td.name-c{font-family:var(--sans);font-weight:500;color:var(--ink);font-size:12px;min-width:120px}
.metric-tbl td.val-c{font-size:13px;font-weight:500;min-width:90px}
.metric-tbl td.range-c{font-size:11px;color:var(--ink4);min-width:110px}
.metric-tbl td.status-c{text-align:right;padding-right:18px}
#syncInd{font-family:var(--mono);font-size:11px;color:var(--ink4);opacity:.5;transition:all .4s;white-space:nowrap}
.empty{padding:28px;text-align:center;color:var(--ink4);font-size:12px}
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;margin-top:4px}
.section-label{font-size:11px;font-weight:600;color:var(--ink4);text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:8px}
.section-label::before{content:'';display:block;width:16px;height:1px;background:var(--ink4)}
.fade-in{animation:fi .3s ease both}
@keyframes fi{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.fade-in:nth-child(1){animation-delay:.04s}.fade-in:nth-child(2){animation-delay:.08s}.fade-in:nth-child(3){animation-delay:.12s}.fade-in:nth-child(4){animation-delay:.16s}
@keyframes av-ripple{0%{transform:scale(.6);opacity:.9}100%{transform:scale(2.4);opacity:0}}

/* Water Conditions */
.wc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:16px 18px;border-top:1px solid var(--rule)}
.wc-section{border:1px solid var(--rule);border-radius:var(--r-lg);overflow:hidden}
.wc-head{padding:10px 14px;border-bottom:1px solid var(--rule);display:flex;align-items:center;justify-content:space-between}
.wc-title{font-size:12px;font-weight:600;color:var(--ink)}
.wc-metrics{padding:4px 0}
.wc-row{display:flex;align-items:center;justify-content:space-between;padding:7px 14px;border-bottom:1px solid var(--rule)}
.wc-row:last-child{border-bottom:none}
.wc-label{font-size:11px;color:var(--ink3);display:flex;align-items:center;gap:5px}
.wc-val{font-family:var(--mono);font-size:12px;font-weight:500}

/* Sim layout */
.sim-layout{display:grid;grid-template-columns:1fr 300px;gap:1rem}
.sim-stats-2{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem}
.sim-stat-card{border:1px solid var(--rule);border-radius:var(--r);padding:1rem;background:var(--surf);text-align:center}
.sim-stat-card.wide{text-align:left}
.sim-stat-l{font-size:10px;font-weight:600;color:var(--ink4);text-transform:uppercase;letter-spacing:.06em}
.sim-stat-v{font-family:var(--mono);font-size:1.5rem;font-weight:500;margin-top:4px}
</style>
</head>
<body>
<div class="wrap">

<!-- Topbar -->
<div class="topbar fade-in">
  <div class="topbar-brand">
    <span class="wordmark">Aqua-Vision</span>
    <span class="slash">/</span>
    <span class="page-name">Overview</span>
  </div>
  <div class="topbar-right">
    <div class="ts-line" id="clock">Connecting…</div>
    <span id="syncInd">⟳</span>
  </div>
</div>

<!-- Banner -->
<div id="statusBanner" class="river-banner fade-in"
     style="--status-color:<?= $bannerColor ?>;--status-bg:<?= $warnCount===0?'var(--good-bg)':($warnCount<=2?'var(--warn-bg)':'var(--crit-bg)') ?>">
  <div class="banner-status-dot" id="bannerIcon"><?= $warnCount===0?'✅':($warnCount<=2?'⚠️':'🚨') ?></div>
  <div class="banner-body">
    <div class="banner-title" id="bannerTitle">Mangima River &mdash; <?= $riverStatus ?></div>
    <div class="banner-sub" id="bannerSub"><?= $warnCount===0?'All sensor readings are within safe thresholds.':"$warnCount parameter(s) outside safe range" ?><?= $lastTs?' · Updated '.date('H:i',strtotime($lastTs)):'' ?></div>
  </div>
  <div class="banner-stats">
    <div class="bstat"><div class="bstat-v" id="bAlerts"><?= $alertCount ?></div><div class="bstat-l">Alerts</div></div>
    <div class="bstat"><div class="bstat-v" id="bDevices"><?= $devCounts['active'] ?>/<?= $devCounts['total'] ?></div><div class="bstat-l">Online</div></div>
    <div class="bstat"><div class="bstat-v" id="bLastTs"><?= $lastTs?date('H:i',strtotime($lastTs)):'—' ?></div><div class="bstat-l">Last Read</div></div>
  </div>
</div>

<!-- KPI Row -->
<div class="kpi-row fade-in">
  <div class="kpi">
    <div class="kpi-badge good">📡</div>
    <div class="kpi-label">Active Devices</div>
    <div class="kpi-value"><?= $devCounts['active'] ?></div>
    <div class="kpi-sub">of <?= $devCounts['total'] ?> total · <?= $devCounts['maint'] ?> maintenance</div>
  </div>
  <div class="kpi">
    <div class="kpi-badge <?= $alertCount>0?'crit':'good' ?>">⚠</div>
    <div class="kpi-label">Active Alerts</div>
    <div class="kpi-value" id="kpiAlerts"><?= $alertCount ?></div>
    <div class="kpi-sub"><?= $alertCount===0?'All clear':'Requires attention' ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-badge info">📍</div>
    <div class="kpi-label">Monitoring Zones</div>
    <div class="kpi-value"><?= count($mapLocations) ?></div>
    <div class="kpi-sub">Upstream · Midstream · Downstream</div>
  </div>
  <div class="kpi">
    <div class="kpi-badge <?= $warnCount>0?'warn':'good' ?>">🌊</div>
    <div class="kpi-label">River Status</div>
    <div class="kpi-value" style="font-size:20px;font-family:var(--sans);font-weight:600"><?= $riverStatus ?></div>
    <div class="kpi-sub"><?= $warnCount ?> parameter<?= $warnCount!==1?'s':'' ?> out of range</div>
  </div>
</div>

<!-- Main Grid: Map + Device Panel -->
<div class="section-head fade-in">
  <div class="section-label">Live Monitoring</div>
</div>

<div class="grid-main fade-in">
  <!-- Map Card -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-l">
        <span class="card-title">Monitoring Locations — Active Devices</span>
        <span id="simStatus" class="tag tag-mute">● Stopped</span>
      </div>
      <div class="card-head-r">
        <select id="simInterval" class="sel">
          <option value="5000">5 s</option>
          <option value="10000" selected>10 s</option>
          <option value="30000">30 s</option>
          <option value="60000">1 min</option>
        </select>
        <span class="tag tag-info" style="font-size:11px">Mixed Modes</span>
        <button id="simStartBtn" onclick="startSim()"
          style="height:30px;padding:0 14px;border-radius:var(--r);font:600 11px var(--sans);cursor:pointer;border:none;background:#7c3aed;color:#fff">
          ▶ Start
        </button>
        <button id="simStopBtn" onclick="stopSim()" disabled
          style="height:30px;padding:0 14px;border-radius:var(--r);font:600 11px var(--sans);cursor:pointer;border:1px solid var(--rule2);background:var(--surf);color:var(--ink3);opacity:.45">
          ■ Stop
        </button>
      </div>
    </div>

    <div class="sim-layout">
      <div>
        <div id="av-map"></div>
        <div class="map-legend">
          <div class="leg"><span class="leg-dot" style="background:#059669"></span>Upstream</div>
          <div class="leg"><span class="leg-dot" style="background:#d97706"></span>Midstream</div>
          <div class="leg"><span class="leg-dot" style="background:#dc2626"></span>Downstream</div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;padding:12px">
        <div class="sim-stats-2">
          <div class="sim-stat-card">
            <div class="sim-stat-l">Readings</div>
            <div id="simCount" class="sim-stat-v" style="color:#7c3aed">0</div>
          </div>
          <div class="sim-stat-card">
            <div class="sim-stat-l">Alerts</div>
            <div id="simAlerts" class="sim-stat-v" style="color:var(--warn)">0</div>
          </div>
          <div class="sim-stat-card wide">
            <div class="sim-stat-l">Last Read</div>
            <div id="simLastTs" style="font-family:var(--mono);font-size:13px;color:var(--ink);margin-top:4px">—</div>
          </div>
          <div class="sim-stat-card wide">
            <div class="sim-stat-l">Device</div>
            <div id="simLastDevice" style="font-size:11px;color:var(--ink3);font-family:var(--mono);margin-top:4px;line-height:1.5">—</div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;height:280px">
          <div class="sim-log-label" style="margin-bottom:6px;flex-shrink:0">Simulation Log</div>
          <div id="simLog" style="height:250px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:var(--r);padding:.75rem;overflow-y:auto;font-family:var(--mono);font-size:10.5px;line-height:1.6">
            <div style="color:var(--ink4)">Monitoring stopped — press ▶ Start to begin.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Device Panel -->
  <div class="card" style="display:flex;flex-direction:column">
    <div class="card-head">
      <div class="card-head-l">
        <span class="card-title">Device Sensor Data</span>
        <span class="tag tag-info">📡 <?= count($devices) ?></span>
      </div>
      <div class="card-head-r">
        <select id="deviceSelector" class="sel" onchange="showDeviceData(this.value)">
          <option value="">Select device…</option>
          <?php foreach ($devices as $dev): ?>
            <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div id="deviceDataDisplay" class="dev-panel">
      <div class="dev-panel-empty">
        <div class="empty-icon">📡</div>
        <p>Select a device to view real-time sensor readings</p>
      </div>
    </div>
  </div>
</div>

<!-- Water Conditions Section -->
<div class="section-head fade-in">
  <div class="section-label">Water Conditions by Stream Section</div>
  <span id="wcLastUpdate" class="tag tag-info">Live</span>
</div>

<!-- Overall Water Conditions Summary -->
<div class="card fade-in" style="margin-bottom:16px">
  <div class="card-head">
    <div class="card-head-l">
      <span class="card-title">Overall Water Conditions Summary</span>
      <select id="pieDeviceSelector" class="sel" onchange="updateConditionPieChart(); updateOverallSensorStatus();" style="margin-left:12px">
        <option value="">All Devices</option>
        <?php foreach ($devices as $dev): ?>
          <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="card-head-r"><span class="tag <?= $warnCount===0?'tag-good':($warnCount<=2?'tag-warn':'tag-crit') ?>" id="pieStatusTag"><?= $warnCount===0?'All Normal':($warnCount<=2?'Moderate':'Critical') ?></span></div>
  </div>
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;padding:16px">
    <!-- Sensor Status Grid -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
      <?php
      $tempVal = $chartData['temperature'][23] ?? null;
      $tempStatus = $tempVal === null ? '—' : ($tempVal < 20 ? 'Cold' : ($tempVal > 32 ? 'Hot' : 'Normal'));
      
      $phVal = $chartData['pH'][23] ?? null;
      $phStatus = $phVal === null ? '—' : ($phVal < 6.5 ? 'Acidic' : ($phVal > 8.5 ? 'Alkaline' : 'Neutral'));
      
      $turbVal = $chartData['turbidity'][23] ?? null;
      $turbStatus = $turbVal === null ? '—' : ($turbVal < 5 ? 'Crystal Clear' : ($turbVal < 25 ? 'Clear' : ($turbVal < 50 ? 'Cloudy' : 'Polluted')));
      
      $doVal = $chartData['dissolved_oxygen'][23] ?? null;
      $doStatus = $doVal === null ? '—' : ($doVal < 5 ? 'Low Oxygen' : ($doVal > 10 ? 'High Oxygen' : 'Healthy'));
      
      $wlVal = $chartData['water_level'][23] ?? null;
      $wlStatus = $wlVal === null ? '—' : ($wlVal < 0.5 ? 'Low Level' : ($wlVal > 2.5 ? 'High Level' : 'Normal'));
      
      $sedVal = $chartData['sediments'][23] ?? null;
      $sedStatus = $sedVal === null ? '—' : ($sedVal < 50 ? 'Minimal' : ($sedVal < 200 ? 'Moderate' : ($sedVal < 400 ? 'High' : 'Severe')));
      
      $overallSensors = [
        ['key'=>'temperature','icon'=>'🌡','label'=>'Temperature','unit'=>'°C','value'=>$tempVal,'min'=>20,'max'=>35,'status'=>$tempStatus],
        ['key'=>'ph_level','icon'=>'🧪','label'=>'pH Level','unit'=>'pH','value'=>$phVal,'min'=>6.5,'max'=>8.5,'status'=>$phStatus],
        ['key'=>'turbidity','icon'=>'🌫','label'=>'Turbidity','unit'=>'NTU','value'=>$turbVal,'min'=>0,'max'=>50,'status'=>$turbStatus],
        ['key'=>'dissolved_oxygen','icon'=>'💧','label'=>'Dissolved O₂','unit'=>'mg/L','value'=>$doVal,'min'=>5,'max'=>14,'status'=>$doStatus],
        ['key'=>'water_level','icon'=>'🌊','label'=>'Water Level','unit'=>'m','value'=>$wlVal,'min'=>0.5,'max'=>3.0,'status'=>$wlStatus],
        ['key'=>'sediments','icon'=>'🟤','label'=>'Sediments','unit'=>'mg/L','value'=>$sedVal,'min'=>0,'max'=>500,'status'=>$sedStatus],
      ];
      foreach ($overallSensors as $os):
        $v = $os['value'];
        $good = $v!==null ? ($v>=$os['min']&&$v<=$os['max']) : null;
        $vc = $good===true?'#059669':($good===false?'#dc2626':'var(--ink4)');
        $bg = $good===true?'#d1fae5':($good===false?'#fee2e2':'#f3f4f6');
      ?>
      <div style="text-align:center;padding:16px 12px;background:<?= $bg ?>;border-radius:var(--r)" class="sensor-status-box" data-sensor="<?= $os['key'] ?>">
        <div style="font-size:24px;margin-bottom:8px"><?= $os['icon'] ?></div>
        <div style="font-size:11px;color:var(--ink4);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px"><?= $os['label'] ?></div>
        <div style="font-size:12px;font-weight:600;color:<?= $vc ?>;padding:6px 12px;background:rgba(255,255,255,.7);border-radius:var(--r);display:inline-block;border:1px solid rgba(0,0,0,.05)" class="sensor-status-text">
          <?= $os['status'] ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <!-- Pie Chart -->
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;border-left:1px solid var(--rule);padding-left:16px">
      <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">Condition Distribution</div>
      <div style="height:180px;width:180px;position:relative">
        <canvas id="conditionPieChart"></canvas>
      </div>
      <div id="pieLegend" style="display:flex;gap:12px;margin-top:12px;font-size:10px">
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:50%;background:#059669"></span>Normal</span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:50%;background:#d97706"></span>Moderate</span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:8px;height:8px;border-radius:50%;background:#dc2626"></span>Critical</span>
      </div>
    </div>
  </div>
</div>

<div class="card fade-in" style="margin-bottom:24px">
  <div class="card-head">
    <div class="card-head-l"><span class="card-title">River Section Water Quality Overview</span></div>
    <div class="card-head-r"><span class="tag tag-mute" id="wcTs">—</span></div>
  </div>
  <div class="wc-grid" id="wcGrid">
    <?php
    $sectionMeta = [
      'upstream'   => ['label'=>'Upstream',   'color'=>'#059669','bg'=>'#d1fae5','tag'=>'tag-up'],
      'midstream'  => ['label'=>'Midstream',  'color'=>'#d97706','bg'=>'#fef3c7','tag'=>'tag-mid'],
      'downstream' => ['label'=>'Downstream', 'color'=>'#dc2626','bg'=>'#fee2e2','tag'=>'tag-down'],
    ];
    $wcSensors = [
      ['key'=>'temperature','icon'=>'🌡','label'=>'Temperature','unit'=>'°C','min'=>20,'max'=>35],
      ['key'=>'ph_level','icon'=>'🧪','label'=>'pH Level','unit'=>'pH','min'=>6.5,'max'=>8.5],
      ['key'=>'turbidity','icon'=>'🌫','label'=>'Turbidity','unit'=>'NTU','min'=>0,'max'=>50],
      ['key'=>'dissolved_oxygen','icon'=>'💧','label'=>'Dissolved O₂','unit'=>'mg/L','min'=>5,'max'=>14],
      ['key'=>'water_level','icon'=>'🌊','label'=>'Water Level','unit'=>'m','min'=>0.5,'max'=>3.0],
      ['key'=>'sediments','icon'=>'🟤','label'=>'Sediments','unit'=>'mg/L','min'=>0,'max'=>500],
    ];
    foreach ($sectionMeta as $secKey => $sm):
      $sc = $sectionConditions[$secKey] ?? [];
      $outOfRange = 0;
      foreach ($wcSensors as $ws) { $v=$sc[$ws['key']]??null; if($v!==null&&($v<$ws['min']||$v>$ws['max'])) $outOfRange++; }
      $sStatus = ($outOfRange===0) ? 'Normal' : (($outOfRange<=1) ? 'Moderate' : 'Critical');
      $sTag = ($outOfRange===0) ? 'tag-good' : (($outOfRange<=1) ? 'tag-warn' : 'tag-crit');
    ?>
    <div class="wc-section">
      <div class="wc-head" style="background:<?= $sm['bg'] ?>">
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:8px;height:8px;border-radius:50%;background:<?= $sm['color'] ?>"></div>
          <span class="wc-title"><?= $sm['label'] ?></span>
        </div>
        <span class="tag <?= $sTag ?>"><?= $sStatus ?></span>
      </div>
      <div class="wc-metrics">
        <?php foreach ($wcSensors as $ws):
          $v = $sc[$ws['key']] ?? null;
          $good = $v!==null ? ($v>=$ws['min']&&$v<=$ws['max']) : null;
          $vc = ($good===true) ? '#059669' : (($good===false) ? '#d97706' : 'var(--ink4)');
        ?>
        <div class="wc-row">
          <div class="wc-label"><?= $ws['icon'] ?> <?= $ws['label'] ?></div>
          <div class="wc-val" style="color:<?= $vc ?>">
            <?= $v!==null ? number_format($v,1).' '.$ws['unit'] : '—' ?>
            <?php if ($good===false): ?><span style="font-size:9px;margin-left:4px">⚠</span><?php endif ?>
          </div>
        </div>
        <?php endforeach ?>
      </div>
    </div>
    <?php endforeach ?>
  </div>
</div>

<!-- Device Metric Charts -->
<div class="section-head fade-in">
  <div class="section-label">Device Metrics Trends</div>
  <span class="tag tag-info">24-Hour History</span>
</div>
<div class="metric-charts-grid fade-in" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">🌡 Temperature (°C)</div>
    <div style="height:180px"><canvas id="tempChart"></canvas></div>
  </div>
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">🧪 pH Level</div>
    <div style="height:180px"><canvas id="phChart"></canvas></div>
  </div>
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">🌫 Turbidity (NTU)</div>
    <div style="height:180px"><canvas id="turbChart"></canvas></div>
  </div>
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">💧 Dissolved O₂ (mg/L)</div>
    <div style="height:180px"><canvas id="doChart"></canvas></div>
  </div>
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">🌊 Water Level (m)</div>
    <div style="height:180px"><canvas id="levelChart"></canvas></div>
  </div>
  
  <div class="card metric-chart-card" style="padding:12px">
    <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:8px;text-align:center">🟤 Sediments (mg/L)</div>
    <div style="height:180px"><canvas id="sedChart"></canvas></div>
  </div>
  
</div>

<!-- Chart -->
<div class="section-head fade-in">
  <div class="section-label">24-Hour Trends</div>
  <div style="display:flex;align-items:center;gap:8px">
    <select id="chartDeviceId" class="sel" onchange="updateChart()">
      <option value="">All Devices</option>
      <?php foreach ($devices as $dev): ?>
        <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
      <?php endforeach; ?>
    </select>
    <span class="tag tag-info">Live</span>
  </div>
</div>
<div class="card fade-in" style="margin-bottom:24px">
  <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
</div>

<!-- Alerts + Maintenance -->
<div class="section-head fade-in">
  <div class="section-label">Events &amp; Maintenance</div>
</div>
<div class="grid-bottom fade-in">
  <div class="card">
    <div class="card-head">
      <div class="card-head-l"><span class="card-title">Active Alerts</span></div>
      <span id="alertPill" class="tag <?= $alertCount>0?'tag-crit':'tag-good' ?>"><?= $alertCount ?> Active</span>
    </div>
    <div id="alertsBody">
      <?php if (empty($alerts)): ?>
        <div class="empty">✓ No active alerts — all sensors nominal.</div>
      <?php else: ?>
        <?php foreach ($alerts as $al):
          $cls = in_array($al['alert_type'],['critical','high'])?'crit':'warn';
          $em  = $cls==='crit'?'🚨':'⚠️';
        ?>
        <div class="alert-item">
          <div class="alert-ic <?= $cls ?>"><?= $em ?></div>
          <div>
            <div class="alert-msg"><?= htmlspecialchars($al['message']) ?></div>
            <div class="alert-meta"><?= htmlspecialchars($al['device_name']) ?> · <?= htmlspecialchars($al['location_name']) ?> · <?= date('H:i, M j',strtotime($al['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head">
      <div class="card-head-l"><span class="card-title">Maintenance Logs</span></div>
      <span class="tag tag-info">Recent</span>
    </div>
    <?php if (empty($maints)): ?>
      <div class="empty">No maintenance records found.</div>
    <?php else: ?>
      <?php foreach ($maints as $m):
        $mIc=['calibration'=>'📐','repair'=>'🔧','cleaning'=>'🧹','inspection'=>'🔍','replacement'=>'🔄'][$m['maintenance_type']]??'📋';
      ?>
      <div class="maint-item">
        <div class="maint-ic"><?= $mIc ?></div>
        <div style="flex:1;min-width:0">
          <div class="maint-name"><?= htmlspecialchars($m['device_name']) ?></div>
          <div class="maint-note"><?= htmlspecialchars($m['notes']) ?></div>
        </div>
        <div class="maint-when"><?= htmlspecialchars($m['full_name']) ?><br><?= date('M j · H:i',strtotime($m['performed_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Sensor Logs -->
<div class="section-head fade-in">
  <div class="section-label">Sensor Data Logs</div>
  <span id="logCount" class="tag tag-info">Latest <?= count($logs) ?></span>
</div>
<div class="card log-section fade-in">
  <div class="log-filter-bar">
    <span class="log-filter-label">Filter:</span>
    <select id="logFilterDev" class="sel" onchange="renderLogGroups()">
      <option value="">All devices</option>
      <?php foreach ($devices as $dev): ?>
        <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
      <?php endforeach; ?>
    </select>
    <select id="logFilterSensor" class="sel" onchange="renderLogGroups()">
      <option value="">All sensors</option>
      <option value="temperature">🌡 Temperature</option>
      <option value="pH">🧪 pH Level</option>
      <option value="turbidity">🌫 Turbidity</option>
      <option value="dissolved_oxygen">💧 Dissolved O₂</option>
      <option value="water_level">🌊 Water Level</option>
      <option value="sediments">🟤 Sediments</option>
    </select>
    <select id="logFilterStatus" class="sel" onchange="renderLogGroups()">
      <option value="">All readings</option>
      <option value="normal">Normal only</option>
      <option value="warn">Out of range</option>
    </select>
  </div>
  <div id="logGroupsBody"></div>
</div>

</div>

<script>
const SELF = 'dashboard.php';

// ── Clock ─────────────────────────────────────────────────────
function updateClock() {
  document.getElementById('clock').textContent =
    new Date().toLocaleString('en-PH',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
}
updateClock(); setInterval(updateClock, 1000);

function _e(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Chart ─────────────────────────────────────────────────────
const now = new Date();
const hours = Array.from({length:24},(_,i)=>{
  const d = new Date(now);
  d.setHours(d.getHours() - 23 + i);
  d.setMinutes(0, 0, 0);
  return d.toISOString();
});
let dbData = <?= json_encode($chartData, JSON_NUMERIC_CHECK) ?>;
const allChartData = <?= json_encode($allChartData ?? [], JSON_NUMERIC_CHECK) ?>;

const CHART_DS = [
  {label:'Turbidity (NTU)',    color:'#d97706', key:'turbidity',         yAxisID:'y'},
  {label:'pH Level',           color:'#3b82f6', key:'pH',                yAxisID:'y1'},
  {label:'Temperature (°C)',   color:'#ef4444', key:'temperature',       yAxisID:'y'},
  {label:'Dissolved O₂ (mg/L)',color:'#10b981', key:'dissolved_oxygen',  yAxisID:'y'},
  {label:'Water Level (m)',    color:'#8b5cf6', key:'water_level',       yAxisID:'y'},
  {label:'Sediments (mg/L)',   color:'#92400e', key:'sediments',         yAxisID:'y'},
];

Chart.defaults.font.family = "'JetBrains Mono', monospace";
Chart.defaults.color = '#8897aa';

const chart = new Chart(document.getElementById('trendChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: hours,
    datasets: CHART_DS.map(d => ({
      label: d.label, data: dbData[d.key] || Array(24).fill(null),
      borderColor: d.color, backgroundColor: d.color + '14',
      borderWidth: 1.5, pointRadius: 1.5, pointHoverRadius: 4,
      fill: false, tension: .4, spanGaps: true, yAxisID: d.yAxisID,
    }))
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: {mode:'index', intersect:false},
    plugins: {
      legend: {display:true, position:'top', labels:{boxWidth:10,padding:14,font:{size:10},usePointStyle:true,pointStyleWidth:8}},
      tooltip: {mode:'index',intersect:false,backgroundColor:'rgba(13,17,23,.92)',padding:10,cornerRadius:6}
    },
    scales: {
      x: {type:'time',time:{unit:'hour',displayFormats:{hour:'HH:mm'}},grid:{color:'rgba(13,17,23,.04)'},ticks:{font:{size:9},maxTicksLimit:8}},
      y: {type:'linear',display:true,position:'left',grid:{color:'rgba(13,17,23,.04)'},ticks:{font:{size:9}},title:{display:true,text:'Turbidity/Temp/DO/Level/Sediments',font:{size:9}}},
      y1: {type:'linear',display:true,position:'right',grid:{drawOnChartArea:false},ticks:{font:{size:9},color:'#3b82f6'},title:{display:true,text:'pH',font:{size:9}}}
    }
  }
});

function updateChart() {
  const deviceId = document.getElementById('chartDeviceId').value;
  if (!deviceId) {
    // Use latest sync data instead of stale dbData
    const latestData = chart?.data?.datasets ? 
      Object.fromEntries(CHART_DS.map((d, i) => [d.key, chart.data.datasets[i].data])) : 
      dbData;
    CHART_DS.forEach((d, i) => { chart.data.datasets[i].data = latestData[d.key] || dbData[d.key] || Array(24).fill(null); });
  } else {
    const dd = allChartData[deviceId] || {};
    CHART_DS.forEach((d, i) => { chart.data.datasets[i].data = dd[d.key] || Array(24).fill(null); });
  }
  chart.update('none');
}

// ── Individual Metric Charts ─────────────────────────────────
const deviceColors = ['#059669', '#d97706', '#dc2626', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f59e0b'];
const metricCharts = {};

function createMetricChart(canvasId, metricKey, metricLabel, metricColor) {
  const ctx = document.getElementById(canvasId)?.getContext('2d');
  if (!ctx) return null;
  
  const datasets = Object.keys(allChartData).map((deviceId, idx) => {
    const deviceInfo = DEV_INFO[deviceId];
    const deviceName = deviceInfo ? deviceInfo.name : `Device ${deviceId}`;
    const color = deviceColors[idx % deviceColors.length];
    return {
      label: deviceName,
      data: allChartData[deviceId][metricKey] || Array(24).fill(null),
      borderColor: color,
      backgroundColor: color + '20',
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 3,
      fill: false,
      tension: 0.4,
      spanGaps: true
    };
  });
  
  return new Chart(ctx, {
    type: 'line',
    data: { labels: hours, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { 
          display: true, 
          position: 'bottom',
          labels: { boxWidth: 8, padding: 8, font: { size: 9 }, usePointStyle: true }
        },
        tooltip: { 
          mode: 'index', 
          intersect: false, 
          backgroundColor: 'rgba(13,17,23,.92)', 
          padding: 8, 
          cornerRadius: 6,
          titleFont: { size: 10 },
          bodyFont: { size: 10 }
        }
      },
      scales: {
        x: { type: 'time', time: { unit: 'hour', displayFormats: { hour: 'HH:mm' } }, display: false },
        y: { 
          display: true,
          grid: { color: 'rgba(13,17,23,.04)' }, 
          ticks: { font: { size: 9 } }
        }
      }
    }
  });
}

function initMetricCharts() {
  metricCharts.temperature = createMetricChart('tempChart', 'temperature', 'Temperature (°C)', '#ef4444');
  metricCharts.pH = createMetricChart('phChart', 'pH', 'pH Level', '#3b82f6');
  metricCharts.turbidity = createMetricChart('turbChart', 'turbidity', 'Turbidity (NTU)', '#d97706');
  metricCharts.dissolved_oxygen = createMetricChart('doChart', 'dissolved_oxygen', 'Dissolved O₂ (mg/L)', '#10b981');
  metricCharts.water_level = createMetricChart('levelChart', 'water_level', 'Water Level (m)', '#8b5cf6');
  metricCharts.sediments = createMetricChart('sedChart', 'sediments', 'Sediments (mg/L)', '#92400e');
}

function updateMetricCharts() {
  Object.keys(metricCharts).forEach(key => {
    const chart = metricCharts[key];
    if (!chart) return;
    
    chart.data.datasets.forEach((dataset, idx) => {
      const deviceId = Object.keys(allChartData)[idx];
      if (deviceId) {
        dataset.data = allChartData[deviceId][key] || Array(24).fill(null);
      }
    });
    chart.update('none');
  });
}

// ── Device Panel ──────────────────────────────────────────────
let DEV_READINGS = <?= json_encode(
  array_combine(
    array_column($devices,'device_id'),
    array_map(fn($d)=>$deviceReadings[$d['device_id']]??null,$devices)
  ), JSON_NUMERIC_CHECK) ?>;

let DEV_INFO = <?= json_encode(
  array_combine(
    array_column($devices,'device_id'),
    array_map(fn($d)=>['name'=>$d['device_name'],'location'=>$d['location_name'],'section'=>$d['river_section']??'','status'=>$d['status']],$devices)
  )) ?>;

const SENSORS = [
  {col:'temperature',      icon:'🌡',label:'Temperature',   unit:'°C',  min:20,  max:35 },
  {col:'ph_level',         icon:'🧪',label:'pH Level',      unit:'pH',  min:6.5, max:8.5},
  {col:'turbidity',        icon:'🌫',label:'Turbidity',     unit:'NTU', min:0,   max:50 },
  {col:'dissolved_oxygen', icon:'💧',label:'Dissolved O₂', unit:'mg/L',min:5,   max:14 },
  {col:'water_level',      icon:'🌊',label:'Water Level',   unit:'m',   min:0.5, max:3.0},
  {col:'sediments',        icon:'🟤',label:'Sediments',     unit:'mg/L',min:0,   max:500},
];

const SECT_TAG  = {upstream:'tag-up',midstream:'tag-mid',downstream:'tag-down'};
const SECT_BG   = {upstream:'#d1fae5',midstream:'#fef3c7',downstream:'#fee2e2'};
const SECT_LBL  = {upstream:'Upstream',midstream:'Midstream',downstream:'Downstream'};

function showDeviceData(deviceId) {
  const display = document.getElementById('deviceDataDisplay');
  const id = parseInt(deviceId, 10);
  const sel = document.getElementById('deviceSelector');
  if (sel && sel.value !== String(id||'')) sel.value = id || '';
  if (!id) {
    display.innerHTML = `<div class="dev-panel-empty"><div class="empty-icon">📡</div><p>Select a device to view real-time sensor readings</p></div>`;
    return;
  }
  const info = DEV_INFO[id], data = DEV_READINGS[id];
  if (!info) { display.innerHTML = '<div class="empty">Device not found.</div>'; return; }
  const stMap = {active:{color:'#059669',label:'Active'},maintenance:{color:'#3b82f6',label:'Maintenance'},inactive:{color:'#dc2626',label:'Offline'}};
  const st = stMap[info.status]||stMap.inactive;
  const sec = SECT_LBL[info.section]||info.section;
  const ts = (data&&data.recorded_at)
    ? new Date(data.recorded_at.replace(' ','T')).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',hour12:false})
    : null;

  let html = `<div class="dev-header"><div><div class="dev-name"><span style="width:7px;height:7px;border-radius:50%;background:${st.color};display:inline-block;flex-shrink:0"></span>${_e(info.name)}</div><div class="dev-loc">📍 ${_e(info.location)}${sec?' &mdash; '+sec:''}</div></div><div style="text-align:right;flex-shrink:0"><span class="tag ${SECT_TAG[info.section]||'tag-info'}">${sec}</span>${ts?`<div style="font-family:var(--mono);font-size:10px;color:var(--ink4);margin-top:4px">${ts}</div>`:''}</div></div>`;

  if (!data) {
    html += '<div class="empty">No readings recorded for this device.</div>';
  } else {
    SENSORS.forEach(s => {
      const raw = data[s.col], hasV = raw!==null&&raw!==undefined;
      const val = hasV ? parseFloat(raw) : null;
      const good = hasV ? (val>=s.min&&val<=s.max) : null;
      const vc = good===true?'#059669':good===false?'#d97706':'var(--ink4)';
      const cls = good===false?' out':'';
      const pill = good===true?'<span class="tag tag-good">✓ Normal</span>':good===false?`<span class="tag tag-warn">⚠ ${val<s.min?'Low':'High'}</span>`:'<span class="tag tag-mute">— No data</span>';
      html += `<div class="sensor-row${cls}"><div class="sensor-icon">${s.icon}</div><div class="sensor-label"><div class="sensor-name">${s.label}</div><div class="sensor-range">Safe ${s.min} – ${s.max} ${s.unit}</div></div><div class="sensor-val" style="color:${vc}">${hasV?val.toFixed(val%1===0?0:1):'—'}${hasV?`<span class="unit">${s.unit}</span>`:''}</div><div class="sensor-status">${pill}</div></div>`;
    });
  }
  display.innerHTML = html;
}

// ── Water Conditions Update ───────────────────────────────────
const WC_SENSORS = [
  {key:'temperature',icon:'🌡',label:'Temperature',unit:'°C',min:20,max:35},
  {key:'ph_level',icon:'🧪',label:'pH Level',unit:'pH',min:6.5,max:8.5},
  {key:'turbidity',icon:'🌫',label:'Turbidity',unit:'NTU',min:0,max:50},
  {key:'dissolved_oxygen',icon:'💧',label:'Dissolved O₂',unit:'mg/L',min:5,max:14},
  {key:'water_level',icon:'🌊',label:'Water Level',unit:'m',min:0.5,max:3.0},
  {key:'sediments',icon:'🟤',label:'Sediments',unit:'mg/L',min:0,max:500},
];
const SEC_META = {
  upstream:  {label:'Upstream',  color:'#059669',bg:'#d1fae5',tag:'tag-up'},
  midstream: {label:'Midstream', color:'#d97706',bg:'#fef3c7',tag:'tag-mid'},
  downstream:{label:'Downstream',color:'#dc2626',bg:'#fee2e2',tag:'tag-down'},
};

function updateWaterConditions(sectionConditions) {
  if (!sectionConditions) return;
  const grid = document.getElementById('wcGrid');
  if (!grid) return;
  let html = '';
  for (const [secKey, sm] of Object.entries(SEC_META)) {
    const sc = sectionConditions[secKey] || [];
    let outOfRange = 0;
    WC_SENSORS.forEach(ws => { const v=sc[ws.key]; if(v!==null&&v!==undefined&&(v<ws.min||v>ws.max)) outOfRange++; });
    const sStatus = (outOfRange===0) ? 'Normal' : ((outOfRange<=1) ? 'Moderate' : 'Critical');
    const sTag = (outOfRange===0) ? 'tag-good' : ((outOfRange<=1) ? 'tag-warn' : 'tag-crit');
    let rows = '';
    WC_SENSORS.forEach(ws => {
      const v = sc[ws.key];
      const hasV = v!==null&&v!==undefined;
      const good = hasV?(v>=ws.min&&v<=ws.max):null;
      const vc = good===true?'#059669':good===false?'#d97706':'var(--ink4)';
      rows += `<div class="wc-row"><div class="wc-label">${ws.icon} ${ws.label}</div><div class="wc-val" style="color:${vc}">${hasV?parseFloat(v).toFixed(1)+' '+ws.unit:'—'}${good===false?'<span style="font-size:9px;margin-left:4px">⚠</span>':''}</div></div>`;
    });
    html += `<div class="wc-section"><div class="wc-head" style="background:${sm.bg}"><div style="display:flex;align-items:center;gap:8px"><div style="width:8px;height:8px;border-radius:50%;background:${sm.color}"></div><span class="wc-title">${sm.label}</span></div><span class="tag ${sTag}">${sStatus}</span></div><div class="wc-metrics">${rows}</div></div>`;
  }
  grid.innerHTML = html;
  const el = document.getElementById('wcTs');
  if (el) el.textContent = new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
}

// ── Sync Engine ───────────────────────────────────────────────
let _syncTimer=null, _syncBusy=false;
function startSync(ms){ stopSync(); syncNow(); _syncTimer=setInterval(syncNow,ms||10000); }
function stopSync(){ if(_syncTimer){clearInterval(_syncTimer);_syncTimer=null;} }

async function syncNow() {
  if(_syncBusy) return; _syncBusy=true;
  try {
    const res = await fetch(`${SELF}?action=fetch&_=${Date.now()}`);
    if(!res.ok) return;
    const d = await res.json();
    if(!d.ok) return;
    _applySync(d);
  } catch(_){}
  finally { _syncBusy=false; }
}

function _applySync(d) {
  // Banner
  const banner = document.getElementById('statusBanner');
  if (banner) {
    banner.style.setProperty('--status-color', d.banner_color);
    banner.style.setProperty('--status-bg', d.warn_count===0?'var(--good-bg)':d.warn_count<=2?'var(--warn-bg)':'var(--crit-bg)');
    const icon=document.getElementById('bannerIcon'); if(icon) icon.textContent=d.banner_emoji;
    const t=document.getElementById('bannerTitle'); if(t) t.textContent=`Mangima River — ${d.river_status}`;
    const s=document.getElementById('bannerSub'); if(s) s.textContent=d.warn_count===0?'All sensor readings are within safe thresholds.':`${d.warn_count} parameter(s) outside safe range`;
    const bA=document.getElementById('bAlerts'); if(bA) bA.textContent=d.alert_count;
    const bD=document.getElementById('bDevices'); if(bD) bD.textContent=`${d.dev_counts.active}/${d.dev_counts.total}`;
    if (d.logs&&d.logs.length>0) {
      const bT=document.getElementById('bLastTs');
      if(bT){ const ts=new Date(d.logs[0].recorded_at.replace(' ','T')); bT.textContent=ts.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:false}); }
    }
  }
  const ka=document.getElementById('kpiAlerts'); if(ka) ka.textContent=d.alert_count;

  // DEV_READINGS + DEV_INFO
  (d.devices||[]).forEach(dv => {
    DEV_READINGS[dv.device_id] = d.device_readings[dv.device_id]||null;
    DEV_INFO[dv.device_id] = {name:dv.device_name,location:dv.location_name,section:dv.river_section,status:dv.status};
  });
  const sel=document.getElementById('deviceSelector');
  const curId=sel?parseInt(sel.value)||0:0;
  if(curId) showDeviceData(curId);
  else if((d.devices||[]).length>0&&sel&&!sel.value){ sel.value=d.devices[0].device_id; showDeviceData(d.devices[0].device_id); }

  // Chart
  if(d.chart_data){
    CHART_DS.forEach((ds,i)=>{ chart.data.datasets[i].data=d.chart_data[ds.key]||Array(24).fill(null); });
    Object.keys(d.device_chart_data||{}).forEach(did=>{ allChartData[did]=d.device_chart_data[did]; });
    updateChart();
    updateMetricCharts();
  }

  // Alerts
  const ap=document.getElementById('alertPill');
  if(ap){ap.textContent=`${d.alert_count} Active`;ap.className=`tag ${d.alert_count>0?'tag-crit':'tag-good'}`;}
  const ab=document.getElementById('alertsBody');
  if(ab){
    if(!(d.alerts||[]).length){ab.innerHTML='<div class="empty">✓ No active alerts — all sensors nominal.</div>';}
    else ab.innerHTML=d.alerts.map(al=>{
      const cls=(['critical','high'].includes(al.alert_type))?'crit':'warn';
      const em=cls==='crit'?'🚨':'⚠️';
      const ts=new Date(al.created_at.replace(' ','T')).toLocaleString('en-PH',{hour:'2-digit',minute:'2-digit',month:'short',day:'numeric',hour12:false});
      return `<div class="alert-item"><div class="alert-ic ${cls}">${em}</div><div><div class="alert-msg">${_e(al.message)}</div><div class="alert-meta">${_e(al.device_name)} · ${_e(al.location_name)} · ${ts}</div></div></div>`;
    }).join('');
  }

  // Maintenance
  if(d.maintenance){
    const mb=document.querySelector('.grid-bottom .card:last-child');
    if(mb){
      const mIcons={calibration:'📐',repair:'🔧',cleaning:'🧹',inspection:'🔍',replacement:'🔄'};
      const mBody=mb.querySelector('.maint-items-body');
      if(mBody){
        if(!d.maintenance.length){mBody.innerHTML='<div class="empty">No maintenance records found.</div>';}
        else mBody.innerHTML=d.maintenance.map(m=>{
          const ic=mIcons[m.maintenance_type]||'📋';
          const ts=new Date(m.performed_at.replace(' ','T')).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',hour12:false});
          return `<div class="maint-item"><div class="maint-ic">${ic}</div><div style="flex:1;min-width:0"><div class="maint-name">${_e(m.device_name)}</div><div class="maint-note">${_e(m.notes)}</div></div><div class="maint-when">${_e(m.full_name)}<br>${ts}</div></div>`;
        }).join('');
      }
    }
  }

  // Water conditions
  if(d.section_conditions) updateWaterConditions(d.section_conditions);

  // Update pie chart with latest data
  updateConditionPieChart();
  
  // Update Overall Water Conditions Summary sensor status boxes
  updateOverallSensorStatus();

  // Logs
  if(d.logs&&d.logs.length>0){
    buildLogGroups(d.logs);
    renderLogGroups();
    const lc=document.getElementById('logCount'); if(lc) lc.textContent=`Latest ${d.logs.length}`;
  }

  // Map markers
  const sc={upstream:'#059669',midstream:'#d97706',downstream:'#dc2626'};
  (d.map_locations||[]).forEach(loc=>{
    const m=_mapMk[loc.location_id]; if(!m) return;
    const allOff=loc.total_devices>0&&loc.active_devices==0&&loc.maint_devices==0;
    m.setStyle({fillColor:allOff?'#9ca3af':(sc[loc.river_section]||'#3b82f6')});
  });

  // Sync indicator
  const ind=document.getElementById('syncInd');
  if(ind){
    ind.style.opacity='1';ind.style.color='#059669';
    ind.textContent='⟳ '+new Date().toLocaleTimeString('en-PH',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
    setTimeout(()=>{ind.style.opacity='.4';ind.style.color='';},2000);
  }
}

// ── Simulator ─────────────────────────────────────────────────
const SIM_DEVICES = <?= json_encode(array_column($devices,'device_id'), JSON_NUMERIC_CHECK) ?>;
const MODES = {
  normal:    {temperature:{base:27,drift:1.5,min:24,max:30},ph_level:{base:7.2,drift:0.2,min:6.8,max:7.6},turbidity:{base:20,drift:8,min:5,max:45},dissolved_oxygen:{base:7.5,drift:0.5,min:6.5,max:8.5},water_level:{base:1.5,drift:0.1,min:1.2,max:1.8},sediments:{base:40,drift:10,min:10,max:80}},
  flood:     {temperature:{base:26,drift:1,min:24,max:28},ph_level:{base:6.8,drift:0.3,min:6.2,max:7.2},turbidity:{base:120,drift:30,min:60,max:200},dissolved_oxygen:{base:5.5,drift:0.8,min:4.0,max:6.5},water_level:{base:2.7,drift:0.2,min:2.3,max:3.5},sediments:{base:350,drift:80,min:200,max:550}},
  pollution: {temperature:{base:29,drift:1,min:27,max:32},ph_level:{base:5.8,drift:0.4,min:5.0,max:6.8},turbidity:{base:80,drift:20,min:40,max:130},dissolved_oxygen:{base:3.5,drift:0.5,min:2.5,max:4.5},water_level:{base:1.4,drift:0.1,min:1.1,max:1.6},sediments:{base:200,drift:60,min:100,max:400}},
  drought:   {temperature:{base:33,drift:1.5,min:30,max:37},ph_level:{base:8.0,drift:0.3,min:7.5,max:8.7},turbidity:{base:8,drift:3,min:3,max:15},dissolved_oxygen:{base:9.0,drift:0.5,min:8.0,max:10},water_level:{base:0.4,drift:0.05,min:0.3,max:0.6},sediments:{base:15,drift:5,min:5,max:30}},
};
const SIM_DEVICE_MODES = {};
const MODE_NAMES = ['normal', 'flood', 'pollution', 'drought'];
let _ds = {}, _di = 0, _st = null, _sc = 0, _sac = 0; // Simulation variables

function _assignDeviceModes() {
  // Distribute modes evenly across all devices (round-robin)
  SIM_DEVICES.forEach((id, idx) => {
    const modeIndex = idx % MODE_NAMES.length;
    SIM_DEVICE_MODES[id] = MODE_NAMES[modeIndex];
  });
}

function _getDeviceMode(id) {
  return SIM_DEVICE_MODES[id] || 'normal';
}

function _initDs(id,mode){ const m=MODES[mode]||MODES.normal; _ds[id]={}; for(const[k,cfg]of Object.entries(m)) _ds[id][k]=+(cfg.base+(Math.random()-.5)*cfg.drift).toFixed(2); }
function _next(id,key,mode){ const cfg=(MODES[mode]||MODES.normal)[key]; if(!cfg) return null; if(!_ds[id])_initDs(id,mode); let v=_ds[id][key]+(Math.random()-.5)*cfg.drift*.35; v=Math.max(cfg.min,Math.min(cfg.max,v)); _ds[id][key]=v; return+v.toFixed(2); }

function _slog(msg,color){
  const log=document.getElementById('simLog'); if(!log) return;
  const now=new Date().toLocaleTimeString('en-PH',{hour12:false});
  const d=document.createElement('div');
  d.style.cssText=`color:${color||'var(--ink3)'};padding:1px 0`;
  d.textContent=`[${now}]  ${msg}`;
  log.appendChild(d);
  while(log.children.length>80) log.removeChild(log.firstChild);
  // Auto-scroll to bottom (newest entries at bottom)
  log.scrollTop = log.scrollHeight;
}

async function _sendTick() {
  const ids=SIM_DEVICES;
  if(!ids.length){_slog('No active devices.','var(--warn)');return;}
  
  // Send to ALL devices simultaneously
  const promises = ids.map(async (id) => {
    const mode=_getDeviceMode(id);
    const p={
      device_id:id,
      temperature:_next(id,'temperature',mode),
      ph_level:_next(id,'ph_level',mode),
      turbidity:_next(id,'turbidity',mode),
      dissolved_oxygen:_next(id,'dissolved_oxygen',mode),
      water_level:_next(id,'water_level',mode),
      sediments:_next(id,'sediments',mode),
    };
    
    try {
      const res=await fetch(`${SELF}?action=simulate`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});
      const data=await res.json();
      if(!res.ok||data.error){
        _slog(`ERROR Device ${id}: ${data.error||res.status}`,'var(--crit)');
        return null;
      }
      
      _sc++;
      if(data.alerts_created&&data.alerts_created.length>0){
        _sac+=data.alerts_created.length;
        data.alerts_created.forEach(a=>_slog(`⚠ ALERT [${a.type.toUpperCase()}] ${a.message}`,'var(--warn)'));
      }
      _slog(`✓ #${data.reading_id} ${data.device_name} [${mode.toUpperCase()}] — T:${p.temperature} pH:${p.ph_level} Tu:${p.turbidity} DO:${p.dissolved_oxygen} Lv:${p.water_level} Sed:${p.sediments}`,'var(--good)');
      
      return data;
    } catch(err){
      _slog(`Fetch error Device ${id}: ${err.message}`,'var(--crit)');
      return null;
    }
  });
  
  // Wait for all devices to complete
  const results = await Promise.all(promises);
  
  // Update UI counters
  document.getElementById('simCount').textContent=_sc;
  document.getElementById('simLastTs').textContent=new Date().toLocaleTimeString('en-PH',{hour12:false});
  document.getElementById('simAlerts').textContent=_sac;
  
  // Get last successful result for device display
  const lastSuccess = results.filter(r=>r&&r.success).pop();
  if(lastSuccess){
    document.getElementById('simLastDevice').textContent=`${lastSuccess.device_name}\n${lastSuccess.river_section||''}`;
    
    // Sync dashboard with last response
    if(lastSuccess.sync && lastSuccess.sync.ok) {
      _applySync(lastSuccess.sync);
    }
  }
}

function startSim(){
  const ms=parseInt(document.getElementById('simInterval').value);
  
  // Assign different modes to each device
  _assignDeviceModes();
  
  // If already running, stop first then restart with new settings
  if(_st) {
    clearInterval(_st); _st=null;
    _slog(`↻ Restarting — Mixed Modes · interval:${ms/1000}s`,'#7c3aed');
  } else {
    const modeList=SIM_DEVICES.map(id=>`${id}:${SIM_DEVICE_MODES[id]}`).join(', ');
    _slog(` Started — Mixed Modes [${modeList}] · interval:${ms/1000}s`,'#7c3aed');
  }
  
  SIM_DEVICES.forEach(id=>_initDs(id,_getDeviceMode(id))); _di=0;
  _st=setInterval(_sendTick,ms);
  document.getElementById('simStatus').textContent=' Running';
  document.getElementById('simStatus').className='tag tag-good';
  document.getElementById('simStartBtn').disabled=true;
  document.getElementById('simStopBtn').disabled=false;
  document.getElementById('simStopBtn').style.opacity='1';
  saveMonitorState(true);
  _sendTick();
  
  // Show toast notification
  const intervalSec = Math.round(ms/1000);
  showToast(`Simulation started - Reading every ${intervalSec}s`, 'info', 4000);
}
function stopSim(){
  if(!_st) return;
  clearInterval(_st); _st=null;
  document.getElementById('simStatus').textContent=' Stopped';
  document.getElementById('simStatus').className='tag tag-mute';
  document.getElementById('simStartBtn').disabled=false;
  document.getElementById('simStopBtn').disabled=true;
  document.getElementById('simStopBtn').style.opacity='.45';
  _slog('■ Stopped.','var(--ink4)');
  saveMonitorState(false);
  
  // Show toast notification
  showToast('Simulation stopped', 'warning', 3000);
}

async function saveMonitorState(running){
  const mode=document.getElementById('simMode')?.value||'normal';
  const interval=parseInt(document.getElementById('simInterval')?.value)||10000;
  try{ await fetch(`${SELF}?action=monitor_state`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({running,mode,interval})}); }catch(e){}
}
async function loadMonitorState(){
  try{const res=await fetch(`${SELF}?action=monitor_state&_=${Date.now()}`);if(!res.ok)return null;const data=await res.json();return data.ok?data.state:null;}catch(e){return null;}
}
async function restoreMonitorIfRunning(){
  const state=await loadMonitorState();
  if(state&&state.running){
    const ms=document.getElementById('simMode'); if(ms&&state.mode) ms.value=state.mode;
    const is=document.getElementById('simInterval'); if(is&&state.interval) is.value=state.interval;
    _slog(`↻ Resuming (mode:${state.mode}, started ${state.started_at})`,'#7c3aed');
    startSim();
  }
}

// ── Log Groups ────────────────────────────────────────────────
const SENSOR_META = {
  temperature:      {icon:'🌡',label:'Temperature',   unit:'°C',  min:20,  max:35 },
  pH:               {icon:'🧪',label:'pH Level',      unit:'pH',  min:6.5, max:8.5},
  ph_level:         {icon:'🧪',label:'pH Level',      unit:'pH',  min:6.5, max:8.5},
  turbidity:        {icon:'🌫',label:'Turbidity',     unit:'NTU', min:0,   max:50 },
  dissolved_oxygen: {icon:'💧',label:'Dissolved O₂', unit:'mg/L',min:5,   max:14 },
  water_level:      {icon:'🌊',label:'Water Level',   unit:'m',   min:0.5, max:3.0},
  sediments:        {icon:'🟤',label:'Sediments',     unit:'mg/L',min:0,   max:500},
};

let LOG_GROUPS={};
function buildLogGroups(logsArray){
  LOG_GROUPS={};
  (logsArray||[]).forEach(log=>{
    const key=log.device_name+'||'+log.location_name;
    if(!LOG_GROUPS[key]) LOG_GROUPS[key]={device_name:log.device_name,location_name:log.location_name,device_id:log.device_id,river_section:log.river_section,readings:{}};
    const type = log.sensor_type; // sensor_type from DB (e.g. ph_level not pH)
    const existing=LOG_GROUPS[key].readings[type];
    if(!existing||log.recorded_at>existing.recorded_at) LOG_GROUPS[key].readings[type]=log;
  });
}

function renderLogGroups(){
  const filterDev=document.getElementById('logFilterDev')?.value||'';
  const filterSensor=document.getElementById('logFilterSensor')?.value||'';
  const filterStatus=document.getElementById('logFilterStatus')?.value||'';
  let html='',anyVisible=false;

  Object.values(LOG_GROUPS).forEach(group=>{
    if(filterDev&&String(group.device_id)!==String(filterDev)) return;
    const section=group.river_section||'';
    const timestamps=Object.values(group.readings).map(r=>r.recorded_at).filter(Boolean).sort().reverse();
    const lastTs=timestamps[0]?new Date(timestamps[0].replace(' ','T')).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}):'—';
    let rowsHtml='',rowCount=0;

    // Iterate SENSOR_META keys and match against readings
    const sensorKeys = ['temperature','ph_level','turbidity','dissolved_oxygen','water_level','sediments'];
    sensorKeys.forEach(type=>{
      const meta=SENSOR_META[type]; if(!meta) return;
      // filterSensor uses display keys
      if(filterSensor){
        const fsNorm=filterSensor==='pH'?'ph_level':filterSensor;
        if(type!==fsNorm) return;
      }
      const reading=group.readings[type]; if(!reading) return;
      const val=parseFloat(reading.value);
      const good=val>=meta.min&&val<=meta.max;
      if(filterStatus==='normal'&&!good) return;
      if(filterStatus==='warn'&&good) return;
      const vc=good?'#059669':'#d97706';
      const pill=good?'<span class="tag tag-good">✓ Normal</span>':`<span class="tag tag-warn">⚠ ${val<meta.min?'Low':'High'}</span>`;
      rowsHtml+=`<tr${!good?' class="out"':''}><td class="icon-c">${meta.icon}</td><td class="name-c">${meta.label}</td><td class="val-c" style="color:${vc}">${val.toFixed(val%1===0?0:1)} <span style="font-size:11px;color:var(--ink4);font-weight:400">${meta.unit}</span></td><td class="range-c">${meta.min} – ${meta.max} ${meta.unit}</td><td class="status-c">${pill}</td></tr>`;
      rowCount++;
    });
    if(!rowCount) return;
    anyVisible=true;
    const bgBadge=SECT_BG[section]||'#eff4ff';
    const tagCls=SECT_TAG[section]||'tag-info';
    const secLbl=SECT_LBL[section]||section;
    html+=`<div style="border-bottom:1px solid var(--rule)"><div class="dev-group-head" onclick="const nb=this.nextElementSibling;nb.style.display=nb.style.display==='none'?'':'none';this.querySelector('.dev-group-chevron').classList.toggle('open')"><div class="dev-group-badge" style="background:${bgBadge}">📡</div><div style="flex:1;min-width:0"><div class="dev-group-name">${_e(group.device_name)}</div><div class="dev-group-loc">📍 ${_e(group.location_name)}</div></div><div class="dev-group-meta"><span class="tag ${tagCls}">${secLbl}</span><div class="dev-group-ts">${lastTs}</div></div><div class="dev-group-chevron open">▼</div></div><div><table class="metric-tbl">${rowsHtml}</table></div></div>`;
  });

  const body=document.getElementById('logGroupsBody');
  if(!body) return;
  body.innerHTML=anyVisible?html:'<div class="empty">No readings match the selected filters.</div>';
}

// ── Map ────────────────────────────────────────────────────────
const locs = <?= json_encode(array_map(fn($l)=>['id'=>(int)$l['location_id'],'name'=>$l['location_name'],'lat'=>(float)$l['latitude'],'lng'=>(float)$l['longitude'],'section'=>$l['river_section'],'total'=>(int)$l['total_devices'],'active'=>(int)$l['active_devices'],'maint'=>(int)$l['maint_devices'],'device_id'=>$l['device_id']??null],$mapLocations)) ?>;
const locationDevices = <?= json_encode($locationDevices, JSON_NUMERIC_CHECK) ?>;
const _mapMk={};

(function(){
  // Mangima River coordinates
  const mangimaStart = [8.345958, 124.898607];
  const mangimaEnd = [8.413179, 124.909497];
  
  // Calculate center between start and end
  const centerLat = (mangimaStart[0] + mangimaEnd[0]) / 2;
  const centerLng = (mangimaStart[1] + mangimaEnd[1]) / 2;
  
  // Create map centered on Mangima River with bounds covering start to end
  const avMap=L.map('av-map',{
    zoomControl:false,
    minZoom:12,
    maxZoom:16,
    maxBounds:[[8.32,124.88],[8.42,124.93]],  // Bounds covering Mangima River
    maxBoundsViscosity:1.0  // Make bounds hard (can't drag outside)
  }).setView([centerLat, centerLng],13);
  
  L.control.zoom({position:'bottomright'}).addTo(avMap);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',{attribution:'&copy; OpenStreetMap &copy; CartoDB',subdomains:'abcd',maxZoom:19}).addTo(avMap);
  if(!document.getElementById('av-rs')){const s=document.createElement('style');s.id='av-rs';s.textContent='@keyframes av-ripple{0%{transform:scale(.6);opacity:.9}100%{transform:scale(2.4);opacity:0}}';document.head.appendChild(s);}
  const R=[[8.345958,124.898607],[8.346955,124.899036],[8.347603,124.898081],[8.349471,124.896461],[8.349216,124.895474],[8.349535,124.894755],[8.348909,124.894058],[8.349881,124.893209],[8.352050,124.889584],[8.351096,124.889497],[8.351978,124.888415],[8.352369,124.887056],[8.352210,124.886676],[8.352643,124.886427],[8.353468,124.884863],[8.355492,124.883376],[8.356292,124.881332],[8.358270,124.881140],[8.368532,124.875713],[8.373977,124.876690],[8.381657,124.897203],[8.394810,124.903483],[8.396343,124.907500],[8.399906,124.911121],[8.400757,124.910773],[8.401407,124.910581],[8.401636,124.910868],[8.401774,124.911007],[8.402125,124.911168],[8.402489,124.911218],[8.402853,124.911196],[8.403020,124.911119],[8.403792,124.910506],[8.405310,124.909972],[8.405901,124.909983],[8.406337,124.910087],[8.406533,124.910179],[8.406700,124.910291],[8.406745,124.910385],[8.406713,124.910512],[8.405924,124.911388],[8.405818,124.911576],[8.405829,124.911689],[8.405924,124.911801],[8.406275,124.911984],[8.406715,124.912414],[8.407049,124.912661],[8.409034,124.913466],[8.409793,124.913708],[8.410064,124.913713],[8.410472,124.913676],[8.411629,124.913198],[8.412245,124.912800],[8.412515,124.912462],[8.412632,124.911962],[8.413237,124.909739],[8.413179,124.909497]];
  L.polyline(R,{color:'#0d1117',weight:18,opacity:.12}).addTo(avMap);
  L.polyline(R,{color:'#1a56db',weight:8,opacity:.55}).addTo(avMap);
  L.polyline(R,{color:'#60a5fa',weight:4,opacity:.85}).addTo(avMap);
  const fl=L.polyline(R,{color:'#93c5fd',weight:2.5,opacity:.65,dashArray:'10 20',dashOffset:'0'}).addTo(avMap);
  let doff=0; setInterval(()=>{doff-=1.5;fl.setStyle({dashOffset:String(doff)});},60);
  [3,7,10,14,18,22].forEach(i=>{if(i>=R.length-1)return;const from=R[i],to=R[i+1],lat=(from[0]+to[0])/2,lng=(from[1]+to[1])/2;const angle=Math.atan2(to[1]-from[1],to[0]-from[0])*180/Math.PI-90;L.marker([lat,lng],{icon:L.divIcon({html:`<div style="transform:rotate(${angle}deg);color:#60a5fa;font-size:9px;opacity:.6">▲</div>`,iconSize:[10,10],iconAnchor:[5,5],className:''}),interactive:false}).addTo(avMap);});
  function pIcon(color,label){return L.divIcon({html:`<div style="position:relative;width:40px;height:40px"><div style="position:absolute;inset:0;border-radius:50%;background:${color};opacity:.12;animation:av-ripple 2s ease-out infinite"></div><div style="position:absolute;inset:8px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.15)"></div><div style="position:absolute;bottom:-16px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:9px;font-weight:600;color:${color};font-family:'Instrument Sans',sans-serif">${label}</div></div>`,iconSize:[40,40],iconAnchor:[20,20],className:''});}
  L.marker([8.345958,124.898607],{icon:pIcon('#059669','START')}).addTo(avMap);
  L.marker([8.413179,124.909497],{icon:pIcon('#dc2626','END')}).addTo(avMap);
  L.marker([8.368,124.882],{icon:L.divIcon({html:`<div style="font-family:'Instrument Serif',serif;font-size:12px;font-style:italic;color:#1a56db;opacity:.5;white-space:nowrap;transform:rotate(42deg)">Mangima River</div>`,iconSize:[130,20],iconAnchor:[65,10],className:''}),interactive:false}).addTo(avMap);
  const sC={upstream:'#059669',midstream:'#d97706',downstream:'#dc2626'};
  const sL={upstream:'Upstream',midstream:'Midstream',downstream:'Downstream'};
  locs.forEach(loc=>{
    const color=sC[loc.section]||'#1a56db';
    const devs=(locationDevices[loc.id]||[]).filter(d=>d.status==='active');
    const dHtml=devs.length>0?`<div style="margin:8px 0;padding-top:8px;border-top:1px solid #f0f0f0"><div style="font-size:10px;font-weight:600;color:#0d1117;margin-bottom:4px;letter-spacing:.04em;text-transform:uppercase">Active Devices</div>${devs.map(d=>{const c='#059669';return`<div style="display:flex;align-items:center;justify-content:space-between;padding:4px 8px;border-radius:4px;background:#f9fafb;margin-bottom:2px"><span style="font-size:11px;color:#0d1117;display:flex;align-items:center;gap:5px"><span style="width:5px;height:5px;border-radius:50%;background:${c};display:inline-block"></span>${d.device_name}</span><span style="font-size:10px;color:${c};font-weight:600">Active</span></div>`}).join('')}</div>`:`<div style="margin:8px 0;font-size:11px;color:#9ca3af;padding-top:8px;border-top:1px solid #f0f0f0">No active devices</div>`;
    const marker=L.circleMarker([loc.lat,loc.lng],{radius:12,fillColor:color,color:'#fff',weight:2.5,fillOpacity:.95}).addTo(avMap);
    _mapMk[loc.id]=marker;
    marker.bindPopup(`<div style="font-family:'Instrument Sans',sans-serif;min-width:210px"><div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><div style="width:8px;height:8px;border-radius:50%;background:${color}"></div><div style="font-size:13px;font-weight:600;color:#0d1117">${sL[loc.section]||loc.section}</div></div><div style="font-size:11px;color:#3d4a5c;margin-bottom:4px">${loc.name}</div>${dHtml}<div style="display:flex;gap:6px;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0"><button onclick="event.stopPropagation();window.location.href='devices.php?action=edit_location&loc_id=${loc.id}'" style="flex:1;padding:5px;font-size:11px;border:1px solid #1a56db;background:#eff4ff;color:#1a56db;border-radius:5px;cursor:pointer;font-family:inherit">Edit</button><button onclick="event.stopPropagation();if(confirm('Delete ${loc.name}?'))window.location.href='devices.php?action=delete_location&loc_id=${loc.id}'" style="flex:1;padding:5px;font-size:11px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;border-radius:5px;cursor:pointer;font-family:inherit">Delete</button></div><div style="font-size:10px;color:#8897aa;margin-top:6px;font-family:'JetBrains Mono',monospace;text-align:center">${loc.lat.toFixed(5)}°N · ${loc.lng.toFixed(5)}°E</div></div>`,{maxWidth:250});
    marker.on('click',()=>{if(loc.device_id) showDeviceData(loc.device_id);});
    L.tooltip({permanent:true,direction:'bottom',offset:[0,12]}).setContent(`<span style="font-size:9px;font-weight:600;color:#3d4a5c;font-family:'Instrument Sans',sans-serif;letter-spacing:.04em;text-transform:uppercase">${sL[loc.section]||loc.section}</span>`).setLatLng([loc.lat,loc.lng]).addTo(avMap);
  });
  const allPts=[...R,...locs.map(l=>[l.lat,l.lng])];
  const bounds=L.latLngBounds(allPts);
  if(bounds.isValid()) avMap.fitBounds(bounds.pad(.12));
})();

// ── Condition Pie Chart ─────────────────────────────────────
let conditionPieChart = null;
const PIE_SENSOR_LIMITS = {
  temperature: { min: 20, max: 35 },
  ph_level: { min: 6.5, max: 8.5 },
  turbidity: { min: 0, max: 50 },
  dissolved_oxygen: { min: 5, max: 14 },
  water_level: { min: 0.5, max: 3.0 },
  sediments: { min: 0, max: 500 }
};

function initConditionPieChart() {
  const ctx = document.getElementById('conditionPieChart')?.getContext('2d');
  if (!ctx) return;
  
  conditionPieChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels: ['Normal', 'Moderate', 'Critical'],
      datasets: [{
        data: [0, 0, 0],
        backgroundColor: ['#059669', '#d97706', '#dc2626'],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(13,17,23,.92)',
          padding: 10,
          cornerRadius: 6,
          callbacks: {
            label: function(context) {
              const total = context.dataset.data.reduce((a, b) => a + b, 0);
              const val = context.parsed;
              const pct = total > 0 ? Math.round((val / total) * 100) : 0;
              return `${context.label}: ${val} (${pct}%)`;
            }
          }
        }
      }
    }
  });
  
  updateConditionPieChart();
}

function updateConditionPieChart() {
  const deviceId = document.getElementById('pieDeviceSelector')?.value || '';
  let normal = 0, moderate = 0, critical = 0;
  
  // Get readings to analyze
  let readingsToAnalyze = [];
  
  if (!deviceId) {
    // All devices - aggregate all device readings
    Object.values(DEV_READINGS).forEach(reading => {
      if (reading) readingsToAnalyze.push(reading);
    });
  } else {
    // Single device
    const reading = DEV_READINGS[parseInt(deviceId)];
    if (reading) readingsToAnalyze.push(reading);
  }
  
  // Count conditions across all readings
  readingsToAnalyze.forEach(reading => {
    let deviceOutOfRange = 0;
    let sensorCount = 0;
    
    Object.entries(PIE_SENSOR_LIMITS).forEach(([sensor, limits]) => {
      const val = reading[sensor];
      if (val !== null && val !== undefined) {
        sensorCount++;
        if (val < limits.min || val > limits.max) {
          deviceOutOfRange++;
        }
      }
    });
    
    if (sensorCount === 0) return; // Skip if no sensor data
    
    // Categorize this device's condition
    if (deviceOutOfRange === 0) {
      normal++;
    } else if (deviceOutOfRange <= 2) {
      moderate++;
    } else {
      critical++;
    }
  });
  
  // If showing all devices and no data, show overall system status
  if (!deviceId && readingsToAnalyze.length === 0) {
    // Use latest chart data as fallback
    const sensors = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
    let outOfRange = 0, totalSensors = 0;
    
    // Use fresh data from chart if available
    const freshData = chart?.data?.datasets ? 
      Object.fromEntries(CHART_DS.map((d, i) => [d.key, chart.data.datasets[i].data])) : 
      dbData;
    
    sensors.forEach(sensor => {
      const val = freshData[sensor === 'ph_level' ? 'pH' : sensor]?.[23] ?? dbData[sensor === 'ph_level' ? 'pH' : sensor]?.[23];
      if (val !== null && val !== undefined) {
        totalSensors++;
        const limits = PIE_SENSOR_LIMITS[sensor];
        if (val < limits.min || val > limits.max) outOfRange++;
      }
    });
    
    if (totalSensors > 0) {
      if (outOfRange === 0) normal = 1;
      else if (outOfRange <= 2) moderate = 1;
      else critical = 1;
    }
  }
  
  // Update chart
  if (conditionPieChart) {
    conditionPieChart.data.datasets[0].data = [normal, moderate, critical];
    conditionPieChart.update();
  }
  
  // Update status tag
  const total = normal + moderate + critical;
  const tag = document.getElementById('pieStatusTag');
  if (tag && total > 0) {
    let statusText, tagClass;
    if (critical > 0) {
      statusText = 'Critical';
      tagClass = 'tag-crit';
    } else if (moderate > 0) {
      statusText = 'Moderate';
      tagClass = 'tag-warn';
    } else {
      statusText = 'All Normal';
      tagClass = 'tag-good';
    }
    tag.textContent = statusText;
    tag.className = `tag ${tagClass}`;
  }
}

// Update Overall Water Conditions Summary sensor status boxes
function updateOverallSensorStatus() {
  const deviceId = document.getElementById('pieDeviceSelector')?.value || '';
  
  // Get the latest values based on device selection
  let tempVal, phVal, turbVal, doVal, wlVal, sedVal;
  
  if (!deviceId) {
    // All devices - use latest chart data (hour 23 = most recent)
    // Use fresh data from chart if available, fallback to dbData
    const freshData = chart?.data?.datasets ? 
      Object.fromEntries(CHART_DS.map((d, i) => [d.key, chart.data.datasets[i].data])) : 
      dbData;
    tempVal = freshData.temperature?.[23] ?? dbData.temperature?.[23] ?? null;
    phVal = freshData.pH?.[23] ?? dbData.pH?.[23] ?? null;
    turbVal = freshData.turbidity?.[23] ?? dbData.turbidity?.[23] ?? null;
    doVal = freshData.dissolved_oxygen?.[23] ?? dbData.dissolved_oxygen?.[23] ?? null;
    wlVal = freshData.water_level?.[23] ?? dbData.water_level?.[23] ?? null;
    sedVal = freshData.sediments?.[23] ?? dbData.sediments?.[23] ?? null;
  } else {
    // Single device - use device-specific data
    const deviceData = DEV_READINGS[parseInt(deviceId)];
    if (deviceData) {
      tempVal = deviceData.temperature ?? null;
      phVal = deviceData.ph_level ?? null;
      turbVal = deviceData.turbidity ?? null;
      doVal = deviceData.dissolved_oxygen ?? null;
      wlVal = deviceData.water_level ?? null;
      sedVal = deviceData.sediments ?? null;
    }
  }
  
  // Update each sensor status box
  const sensors = [
    { key: 'temperature', val: tempVal, min: 20, max: 35, 
      getStatus: (v) => v === null ? '—' : (v < 20 ? 'Cold' : (v > 32 ? 'Hot' : 'Normal')) },
    { key: 'ph_level', val: phVal, min: 6.5, max: 8.5,
      getStatus: (v) => v === null ? '—' : (v < 6.5 ? 'Acidic' : (v > 8.5 ? 'Alkaline' : 'Neutral')) },
    { key: 'turbidity', val: turbVal, min: 0, max: 50,
      getStatus: (v) => v === null ? '—' : (v < 5 ? 'Crystal Clear' : (v < 25 ? 'Clear' : (v < 50 ? 'Cloudy' : 'Polluted'))) },
    { key: 'dissolved_oxygen', val: doVal, min: 5, max: 14,
      getStatus: (v) => v === null ? '—' : (v < 5 ? 'Low Oxygen' : (v > 10 ? 'High Oxygen' : 'Healthy')) },
    { key: 'water_level', val: wlVal, min: 0.5, max: 3.0,
      getStatus: (v) => v === null ? '—' : (v < 0.5 ? 'Low Level' : (v > 2.5 ? 'High Level' : 'Normal')) },
    { key: 'sediments', val: sedVal, min: 0, max: 500,
      getStatus: (v) => v === null ? '—' : (v < 50 ? 'Minimal' : (v < 200 ? 'Moderate' : (v < 400 ? 'High' : 'Severe'))) }
  ];
  
  sensors.forEach(sensor => {
    const box = document.querySelector(`.sensor-status-box[data-sensor="${sensor.key}"]`);
    if (!box) return;
    
    const statusText = box.querySelector('.sensor-status-text');
    if (!statusText) return;
    
    const status = sensor.getStatus(sensor.val);
    const good = sensor.val !== null ? (sensor.val >= sensor.min && sensor.val <= sensor.max) : null;
    const vc = good === true ? '#059669' : (good === false ? '#dc2626' : 'var(--ink4)');
    const bg = good === true ? '#d1fae5' : (good === false ? '#fee2e2' : '#f3f4f6');
    
    // Update status text
    statusText.textContent = status;
    statusText.style.color = vc;
    
    // Update box background
    box.style.background = bg;
  });
}

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{
  const sel=document.getElementById('deviceSelector');
  if(sel&&sel.options.length>1) showDeviceData(sel.options[1].value);
  buildLogGroups(<?= json_encode($logs, JSON_NUMERIC_CHECK) ?>);
  renderLogGroups();
  updateWaterConditions(<?= json_encode($sectionConditions, JSON_NUMERIC_CHECK) ?>);
  initMetricCharts();
  initConditionPieChart();
  updateOverallSensorStatus(); // Initialize sensor status boxes
  startSync(10000);
  restoreMonitorIfRunning();
  
  // Check for synced location data from devices page
  checkForLocationSync();
  
  // Auto-restart simulation when interval or mode changes
  document.getElementById('simInterval').addEventListener('change',()=>{ if(_st) startSim(); });
  document.getElementById('simMode').addEventListener('change',()=>{ if(_st) startSim(); });
});

// Check for location sync from devices page
function checkForLocationSync() {
  const syncedLocations = sessionStorage.getItem('devicesPageLocations');
  if (syncedLocations) {
    try {
      const locations = JSON.parse(syncedLocations);
      console.log(' Detected location sync from devices page:', locations);
      
      // Update map markers with new location data
      updateMapMarkersFromSync(locations);
      
      // Clear the sync data after processing
      sessionStorage.removeItem('devicesPageLocations');
      
      // Show sync notification
      showSyncNotification('Map locations updated from Device Management');
    } catch (e) {
      console.error('Error processing location sync:', e);
    }
  }
}

// Update map markers from synced data
function updateMapMarkersFromSync(locations) {
  if (!locations || locations.length === 0) return;
  
  // Update existing markers or add new ones
  locations.forEach(loc => {
    const marker = _mapMk[loc.id];
    if (marker) {
      // Update existing marker
      const isActive = loc.device_count > 0;
      const color = getRiverSectionColor(loc.river_section);
      
      marker.setStyle({
        fillColor: isActive ? color : '#9ca3af',
        color: '#fff',
        weight: 2.5,
        fillOpacity: 0.95
      });
      
      // Update popup content
      const popupContent = generatePopupContent(loc);
      marker.setPopupContent(popupContent);
      
      // Update tooltip
      const sectionLabel = getRiverSectionLabel(loc.river_section);
      marker.unbindTooltip();
      L.tooltip({permanent:true,direction:'bottom',offset:[0,12]})
        .setContent(`<span style="font-size:9px;font-weight:600;color:#3d4a5c;font-family:'Instrument Sans',sans-serif;letter-spacing:.04em;text-transform:uppercase">${sectionLabel}</span>`)
        .setLatLng([loc.lat, loc.lng])
        .addTo(window.avMap);
    }
  });
  
  // Fit map to show all updated markers
  if (window.avMap && locations.length > 0) {
    const bounds = L.latLngBounds(locations.map(loc => [loc.lat, loc.lng]));
    window.avMap.fitBounds(bounds.pad(0.12));
  }
}

// Helper functions for sync
function getRiverSectionColor(section) {
  const colors = {
    'upstream': '#059669',
    'midstream': '#d97706', 
    'downstream': '#dc2626'
  };
  return colors[section] || '#3b82f6';
}

function getRiverSectionLabel(section) {
  const labels = {
    'upstream': 'Upstream',
    'midstream': 'Midstream',
    'downstream': 'Downstream'
  };
  return labels[section] || section;
}

function generatePopupContent(loc) {
  const devices = locationDevices[loc.id] || [];
  const dHtml = devices.length > 0 ? 
    `<div style="margin:8px 0;padding-top:8px;border-top:1px solid #f0f0f0">
      <div style="font-size:10px;font-weight:600;color:#0d1117;margin-bottom:4px;letter-spacing:.04em;text-transform:uppercase">Devices</div>
      ${devices.map(d => {
        const c = d.status === 'active' ? '#059669' : d.status === 'maintenance' ? '#3b82f6' : '#9ca3af';
        return `<div style="display:flex;align-items:center;justify-content:space-between;padding:4px 8px;border-radius:4px;background:#f9fafb;margin-bottom:2px">
          <span style="font-size:11px;color:#0d1117;display:flex;align-items:center;gap:5px">
            <span style="width:5px;height:5px;border-radius:50%;background:${c};display:inline-block"></span>
            ${d.device_name}
          </span>
          <span style="font-size:10px;color:${c};font-weight:600">${d.status === 'active' ? 'Active' : d.status === 'maintenance' ? 'Maint.' : 'Offline'}</span>
        </div>`;
      }).join('')}
    </div>` : 
    `<div style="margin:8px 0;font-size:11px;color:#9ca3af;padding-top:8px;border-top:1px solid #f0f0f0">No devices assigned</div>`;
  
  return `<div style="font-family:'Instrument Sans',sans-serif;min-width:210px">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
      <div style="width:8px;height:8px;border-radius:50%;background:${getRiverSectionColor(loc.river_section)}"></div>
      <div style="font-size:13px;font-weight:600;color:#0d1117">${getRiverSectionLabel(loc.river_section)}</div>
    </div>
    <div style="font-size:11px;color:#3d4a5c;margin-bottom:4px">${loc.name}</div>
    ${dHtml}
    <div style="display:flex;gap:6px;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0">
      <button onclick="event.stopPropagation();window.location.href='devices.php?action=edit_location&loc_id=${loc.id}'" 
        style="flex:1;padding:5px;font-size:11px;border:1px solid #1a56db;background:#eff4ff;color:#1a56db;border-radius:5px;cursor:pointer;font-family:inherit">Edit</button>
      <button onclick="event.stopPropagation();if(confirm('Delete ${loc.name}?'))window.location.href='devices.php?action=delete_location&loc_id=${loc.id}'" 
        style="flex:1;padding:5px;font-size:11px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;border-radius:5px;cursor:pointer;font-family:inherit">Delete</button>
    </div>
    <div style="font-size:10px;color:#8897aa;margin-top:6px;font-family:'JetBrains Mono',monospace;text-align:center">${loc.lat.toFixed(5)}°N · ${loc.lng.toFixed(5)}°E</div>
  </div>`;
}

function showSyncNotification(message) {
  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: #059669;
    color: white;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 9999;
    max-width: 400px;
    animation: slideInRight 0.3s ease-out;
  `;
  notification.textContent = message;
  
  // Add animation
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
  `;
  document.head.appendChild(style);
  
  document.body.appendChild(notification);
  
  // Auto-remove after 3 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.style.animation = 'slideInRight 0.3s ease-out reverse';
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, 300);
    }
  }, 3000);
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

<script>
// Alert toast notification system
document.addEventListener('DOMContentLoaded', function() {
    // Check for new alerts every 10 seconds
    let lastAlertCount = 0;
    
    function checkAlerts() {
        fetch(`${SELF}?action=fetch&_=${Date.now()}`)
            .then(r => r.json())
            .then(d => {
                if (d.ok && d.alerts) {
                    const currentAlerts = d.alerts.filter(a => a.status === 'active');
                    
                    // Show toast for new alerts
                    currentAlerts.forEach(alert => {
                        const severity = alert.alert_type === 'critical' ? 'error' : 
                                        alert.alert_type === 'high' ? 'warning' : 'warning';
                        showToast(
                            `${alert.device_name}: ${alert.message}`,
                            severity,
                            8000
                        );
                    });
                    
                    lastAlertCount = currentAlerts.length;
                }
            })
            .catch(() => {});
    }
    
    // Initial check after 2 seconds
    setTimeout(checkAlerts, 2000);
    
    // Periodic checks every 30 seconds
    setInterval(checkAlerts, 30000);
});
</script>

</body>
</html>