<?php
/**
 * Aqua-Vision Dashboard Data Functions
 * Helper functions for dashboard data retrieval and processing
 */

require_once '../../../database/config.php';

// ── Data Retrieval Functions ──────────────────────────────────

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

function av_overview_last_readings(mysqli $conn, int $limit = 10, ?int $deviceId = null): array {
    $deviceFilter = $deviceId ? "AND s.device_id = $deviceId" : "";
    
    $res = $conn->query("
        SELECT sr.recorded_at, d.device_name, l.location_name,
               s.sensor_type, s.unit, s.min_threshold, s.max_threshold, sr.value
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        JOIN devices d ON d.device_id = s.device_id
        JOIN locations l ON l.location_id = d.location_id
        WHERE 1=1 $deviceFilter
        ORDER BY sr.recorded_at DESC
        LIMIT $limit
    ");
    
    $logs = [];
    while ($r = $res->fetch_assoc()) {
        $logs[] = $r;
    }
    
    return $logs;
}

function av_overview_logs_union_sql(int $limit): string {
    return "
        (SELECT 
            sr.recorded_at, 
            d.device_name, 
            l.location_name,
            CONCAT(s.sensor_type, ' reading') as activity_type,
            CONCAT('Value: ', sr.value, ' ', s.unit) as details,
            s.sensor_type as category
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        JOIN devices d ON d.device_id = s.device_id
        JOIN locations l ON l.location_id = d.location_id
        ORDER BY sr.recorded_at DESC
        LIMIT $limit)
    ";
}

function av_overview_warn_count_from_readings(array $deviceReadings): int {
    $checks = [
        ['temperature', 20, 35],
        ['ph_level', 6.5, 8.5],
        ['turbidity', 0, 50],
        ['dissolved_oxygen', 5, 14],
        ['water_level', 0.5, 3.0],
        ['sediments', 0, 500], // Sediments check
    ];
    
    $warnCount = 0;
    foreach ($deviceReadings as $reading) {
        foreach ($checks as $check) {
            $field = $check[0];
            $min = $check[1];
            $max = $check[2];
            
            if (isset($reading[$field]) && $reading[$field] !== null) {
                $value = (float)$reading[$field];
                if ($value < $min || $value > $max) {
                    $warnCount++;
                    break; // Count each reading once if any parameter is out of range
                }
            }
        }
    }
    
    return $warnCount;
}

// ── Status Calculation Functions ───────────────────────────────

function av_overview_calculate_device_status(array $readings): string {
    if (empty($readings)) {
        return 'offline';
    }
    
    $warnCount = 0;
    $totalChecks = 0;
    
    $thresholds = [
        'temperature' => [20, 35],
        'ph_level' => [6.5, 8.5],
        'turbidity' => [0, 50],
        'dissolved_oxygen' => [5, 14],
        'water_level' => [0.5, 3.0],
        'sediments' => [0, 500],
    ];
    
    foreach ($readings as $reading) {
        $sensorType = $reading['sensor_type'];
        if (isset($thresholds[$sensorType])) {
            $min = $thresholds[$sensorType][0];
            $max = $thresholds[$sensorType][1];
            $value = (float)$reading['value'];
            
            if ($value < $min || $value > $max) {
                $warnCount++;
            }
            $totalChecks++;
        }
    }
    
    if ($totalChecks === 0) {
        return 'offline';
    }
    
    $warnRatio = $warnCount / $totalChecks;
    
    if ($warnRatio >= 0.5) {
        return 'critical';
    } elseif ($warnRatio >= 0.2) {
        return 'warning';
    } else {
        return 'normal';
    }
}

function av_overview_calculate_river_status(array $allReadings): array {
    $sections = ['upstream', 'midstream', 'downstream'];
    $sectionData = [];
    
    foreach ($sections as $section) {
        $sectionReadings = array_filter($allReadings, function($reading) use ($section) {
            return ($reading['river_section'] ?? '') === $section;
        });
        
        $status = av_overview_calculate_device_status($sectionReadings);
        $deviceCount = count(array_unique(array_column($sectionReadings, 'device_id')));
        $lastReading = !empty($sectionReadings) ? max(array_column($sectionReadings, 'recorded_at')) : null;
        
        $sectionData[$section] = [
            'status' => $status,
            'device_count' => $deviceCount,
            'last_reading' => $lastReading,
            'readings' => array_values($sectionReadings)
        ];
    }
    
    return $sectionData;
}

// ── Alert Functions ───────────────────────────────────────────

function av_overview_generate_alerts_from_readings(array $readings): array {
    $alerts = [];
    $thresholds = [
        'temperature' => [20, 35, 'Temperature'],
        'ph_level' => [6.5, 8.5, 'pH Level'],
        'turbidity' => [0, 50, 'Turbidity'],
        'dissolved_oxygen' => [5, 14, 'Dissolved Oxygen'],
        'water_level' => [0.5, 3.0, 'Water Level'],
        'sediments' => [0, 500, 'Sediments'],
    ];
    
    foreach ($readings as $reading) {
        $sensorType = $reading['sensor_type'];
        if (!isset($thresholds[$sensorType])) {
            continue;
        }
        
        $min = $thresholds[$sensorType][0];
        $max = $thresholds[$sensorType][1];
        $label = $thresholds[$sensorType][2];
        $value = (float)$reading['value'];
        
        if ($value < $min || $value > $max) {
            $alertType = $value > $max ? 'high' : 'low';
            $severity = ($value < $min * 0.5 || $value > $max * 1.5) ? 'critical' : 'warning';
            
            $message = $value > $max 
                ? "$label too high: {$value} (max: $max)"
                : "$label too low: {$value} (min: $min)";
            
            $alerts[] = [
                'device_name' => $reading['device_name'],
                'location_name' => $reading['location_name'],
                'sensor_type' => $sensorType,
                'alert_type' => $alertType,
                'severity' => $severity,
                'message' => $message,
                'value' => $value,
                'unit' => $reading['unit'],
                'created_at' => $reading['recorded_at']
            ];
        }
    }
    
    // Sort by severity and time
    usort($alerts, function($a, $b) {
        $severityOrder = ['critical' => 0, 'warning' => 1];
        if ($a['severity'] !== $b['severity']) {
            return $severityOrder[$a['severity']] - $severityOrder[$b['severity']];
        }
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return array_slice($alerts, 0, 10); // Return top 10 alerts
}

// ── Utility Functions ─────────────────────────────────────────

function av_overview_format_reading_value(float $value, string $unit): string {
    return number_format($value, 2) . ' ' . $unit;
}

function av_overview_get_sensor_icon(string $sensorType): string {
    $icons = [
        'temperature' => '🌡',
        'ph_level' => '🧪',
        'turbidity' => '🌫',
        'dissolved_oxygen' => '💧',
        'water_level' => '🌊',
        'sediments' => '🟤',
        'conductivity' => '⚡',
    ];
    
    return $icons[$sensorType] ?? '📊';
}

function av_overview_get_sensor_label(string $sensorType): string {
    $labels = [
        'temperature' => 'Temperature',
        'ph_level' => 'pH Level',
        'turbidity' => 'Turbidity',
        'dissolved_oxygen' => 'Dissolved Oxygen',
        'water_level' => 'Water Level',
        'sediments' => 'Sediments',
        'conductivity' => 'Conductivity',
    ];
    
    return $labels[$sensorType] ?? ucwords(str_replace('_', ' ', $sensorType));
}

function av_overview_time_ago(string $datetime): string {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
}
?>
