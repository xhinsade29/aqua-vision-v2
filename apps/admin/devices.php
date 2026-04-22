<?php
/**
 * Device & Location Management
 * Unified interface for managing monitoring equipment and stations
 */

require_once __DIR__ . '/../../database/config.php';

// Initialize session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser caching to ensure fresh data fetch
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sun, 01 Jan 2014 00:00:00 GMT');

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Check if user has admin role
$currentUserRole = $_SESSION['user_role'] ?? '';
if ($currentUserRole !== 'admin') {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    if ($currentUserRole === 'researcher') {
        header('Location: /Aqua-Vision/apps/researcher/dashboard.php');
    } else {
        header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    }
    exit;
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle API requests
if ($action === 'get_readings') {
    $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
    
    if ($deviceId > 0) {
        // Fetch latest sensor readings for this device
        $readings = getLatestDeviceReadings($conn, $deviceId);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'readings' => $readings
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid device ID'
        ]);
    }
    exit;
}

// Handle device history API
if ($action === 'get_history') {
    $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
    
    if ($deviceId > 0) {
        $history = getDeviceHistory($conn, $deviceId);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'history' => $history
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid device ID'
        ]);
    }
    exit;
}

// Handle map sync API
if ($action === 'map_sync') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    
    // Get locations with device counts
    $locRes = $conn->query("SELECT l.location_id, l.location_name, l.latitude, l.longitude, l.river_section,
        COUNT(d.device_id) AS total_devices,
        SUM(CASE WHEN d.status='active' THEN 1 ELSE 0 END) AS active_devices,
        SUM(CASE WHEN d.status='maintenance' THEN 1 ELSE 0 END) AS maint_devices
        FROM locations l
        LEFT JOIN devices d ON d.location_id = l.location_id
        GROUP BY l.location_id");
    
    $locations = [];
    while ($r = $locRes->fetch_assoc()) {
        $locations[] = [
            'id' => (int)$r['location_id'],
            'name' => $r['location_name'],
            'lat' => (float)$r['latitude'],
            'lng' => (float)$r['longitude'],
            'section' => $r['river_section'],
            'total' => (int)$r['total_devices'],
            'active' => (int)$r['active_devices'],
            'maint' => (int)$r['maint_devices']
        ];
    }
    
    // Get all devices with their status
    $devRes = $conn->query("SELECT d.device_id, d.device_name, d.status, d.device_condition, d.last_active,
        l.location_id, l.location_name, l.river_section, l.latitude, l.longitude
        FROM devices d
        LEFT JOIN locations l ON l.location_id = d.location_id
        WHERE d.status = 'active'");
    
    $devices = [];
    while ($r = $devRes->fetch_assoc()) {
        $devices[] = [
            'device_id' => (int)$r['device_id'],
            'device_name' => $r['device_name'],
            'status' => $r['status'],
            'device_condition' => $r['device_condition'] ?? 'normal',
            'last_active' => $r['last_active'],
            'location_id' => $r['location_id'] ? (int)$r['location_id'] : null,
            'location_name' => $r['location_name'],
            'river_section' => $r['river_section'],
            'lat' => $r['latitude'] ? (float)$r['latitude'] : null,
            'lng' => $r['longitude'] ? (float)$r['longitude'] : null
        ];
    }
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'locations' => $locations,
        'devices' => $devices
    ], JSON_NUMERIC_CHECK);
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleFormSubmission($conn, $_POST);
}

// Get data for current view
$device = ($action === 'edit' && $id) ? getDeviceById($conn, $id) : null;
$location = ($action === 'edit_location' && isset($_GET['loc_id'])) ? getLocationById($conn, (int)$_GET['loc_id']) : null;

// Debug: Check database connection
if (!$conn) {
    die("Database connection failed");
}

// Debug: Check for devices directly
$testRes = $conn->query("SELECT COUNT(*) as cnt FROM devices");
$deviceCount = $testRes ? $testRes->fetch_assoc()['cnt'] : 0;

$devices = ($action === 'list' || $action === 'edit') ? getAllDevices($conn) : [];
$locations = ($action === 'list') ? getAllLocations($conn) : [];

$currentPage = 'devices';
include __DIR__ . '/../../assets/navigation.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device & Location Management - Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-700);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: var(--radius);
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: white;
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
        }
        
        .btn-secondary:hover {
            background: var(--gray-50);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
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
            border-radius: 9999px;
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
        
        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.875rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #86efac;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .modal-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        
        /* Map */
        #location-map {
            height: 400px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        /* Device List */
        .device-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .device-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .device-item:last-child {
            border-bottom: none;
        }
        
        .device-info {
            flex: 1;
        }
        
        .device-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .device-location {
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📡 Device Management</h1>
            <div class="header-actions" style="display: flex; gap: 0.5rem;">
                <a href="?action=add" class="btn btn-primary">+ Add Device</a>
            </div>
        </div>
        
        <?php if ($action === 'add'): ?>
            <!-- Add Device Form -->
            <div class="card">
                <div class="card-header">
                    <h3>Add New Device</h3>
                </div>
                <div class="card-body" style="padding: 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 300px; gap: 1.5rem;">
                        <!-- Device Location Map (Only for Active) -->
                        <div id="addDeviceMapContainer" style="display: none;">
                            <div id="add-device-location-map" style="height: 400px; border-radius: var(--radius); border: 1px solid var(--gray-200);"></div>
                            <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
                                💡 Click on the map to set device location, or drag the marker
                            </p>
                        </div>
                        
                        <!-- Map Placeholder for Non-Active -->
                        <div id="addDeviceMapPlaceholder" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 400px; border-radius: var(--radius); border: 2px dashed var(--gray-300); background: #f8fafc;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📍</div>
                            <p style="font-size: 0.875rem; color: var(--gray-500); text-align: center; padding: 0 1rem;">
                                Select "Active" status to enable<br>map location assignment
                            </p>
                        </div>
                        
                        <!-- Device Form -->
                        <form method="POST" action="" style="grid-column: span 2;">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="form-group">
                                <label>Device Name *</label>
                                <input type="text" name="device_name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="addDeviceStatus" required onchange="toggleAddDeviceMap()">
                                    <option value="active">Active</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="offline">Offline</option>
                                    <option value="unassigned">Unassigned</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Device Condition</label>
                                <select name="device_condition" id="addDeviceCondition">
                                    <option value="normal">Normal</option>
                                    <option value="displaced">Displaced (Out of Position)</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="malfunctioning">Malfunctioning</option>
                                </select>
                                <small style="color: var(--gray-500);">Physical state of the device</small>
                            </div>
                            
                            <div id="addDeviceLocationFields" style="display: none;">
                                <div class="form-group">
                                    <label>Latitude</label>
                                    <input type="number" id="addLatitude" name="latitude" step="0.000001" value="8.369297" readonly>
                                </div>
                                
                                <div class="form-group">
                                    <label>Longitude</label>
                                    <input type="number" id="addLongitude" name="longitude" step="0.000001" value="124.876785" readonly>
                                </div>
                                
                                <div class="form-group">
                                    <label>Stream Assigned</label>
                                    <input type="text" id="addLocationName" name="location_name" value="" readonly>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Save Device</button>
                                <a href="?action=list" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                    
                    <script>
                        let addDeviceMap = null;
                        let addDeviceMarker = null;
                        
                        function initAddDeviceMap() {
                            if (addDeviceMap) return; // Already initialized
                            
                            // Default to midstream start point
                            const defaultLat = 8.369297;
                            const defaultLng = 124.876785;
                            
                            addDeviceMap = L.map('add-device-location-map').setView([defaultLat, defaultLng], 13);
                            
                            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                                attribution: '© OpenStreetMap contributors',
                                subdomains: 'abcd',
                                maxZoom: 19
                            }).addTo(addDeviceMap);
                            
                            // Add river polyline
                            const riverCoords = [
                                [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
                                [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
                                [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
                                [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
                                [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
                                [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
                                [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
                                [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
                                [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
                                [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
                                [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
                                [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
                                [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
                                [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
                                [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
                                [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
                                [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
                                [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
                                [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
                            ];
                            
                            L.polyline(riverCoords, {
                                color: '#3b82f6',
                                weight: 4,
                                opacity: 0.85
                            }).addTo(addDeviceMap);
                            
                            // Add existing devices to prevent duplication
                            const existingDevices = <?= json_encode($devices, JSON_NUMERIC_CHECK) ?>;
                            existingDevices.forEach(device => {
                                if (!device.latitude || !device.longitude) return;
                                
                                const deviceStatusColor = device.device_condition === 'displaced' ? '#7c3aed' : 
                                                        device.device_condition === 'damaged' ? '#1f2937' : 
                                                        device.device_condition === 'malfunctioning' ? '#d97706' : 
                                                        device.status === 'active' ? '#16a34a' : 
                                                        device.status === 'maintenance' ? '#3b82f6' : '#9ca3af';
                                
                                L.marker([device.latitude, device.longitude], {
                                    icon: L.divIcon({
                                        html: `<div style="position: relative; width: 24px; height: 24px;">
                                                <div style="position: absolute; inset: 0; border-radius: 50%; background: ${deviceStatusColor}; opacity: 0.3;"></div>
                                                <div style="position: absolute; inset: 2px; border-radius: 50%; background: ${deviceStatusColor}; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>
                                                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #fff; font-size: 10px; font-weight: bold;">📡</div>
                                            </div>`,
                                        iconSize: [24, 24],
                                        iconAnchor: [12, 12],
                                        className: ''
                                    })
                                }).addTo(addDeviceMap).bindPopup(`
                                    <div style="font-family: 'Inter', sans-serif; min-width: 180px;">
                                        <div style="font-weight: 600; margin-bottom: 4px;">${device.device_name}</div>
                                        <div style="font-size: 11px; color: #6b7280;">Status: ${device.status}</div>
                                        ${device.device_condition && device.device_condition !== 'normal' ? `<div style="font-size: 11px; color: #7c3aed; margin-top: 2px;">⚠️ ${device.device_condition}</div>` : ''}
                                        <div style="font-size: 10px; color: #dc2626; margin-top: 4px;">⚠️ Existing device</div>
                                    </div>
                                `);
                            });
                            
                            // Add draggable marker
                            addDeviceMarker = L.marker([defaultLat, defaultLng], { 
                                draggable: true,
                                icon: L.divIcon({
                                    html: `<div style="position: relative; width: 30px; height: 30px;">
                                            <div style="position: absolute; inset: 0; border-radius: 50%; background: #1a56db; opacity: 0.2; animation: pulse 2s ease-out infinite"></div>
                                            <div style="position: absolute; inset: 4px; border-radius: 50%; background: #1a56db; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.3); cursor: move;"></div>
                                            <div style="position: absolute; top: -8px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid #1a56db;"></div>
                                        </div>`,
                                    iconSize: [30, 30],
                                    iconAnchor: [15, 30],
                                    className: ''
                                })
                            }).addTo(addDeviceMap);
                            
                            // Update form when marker is dragged
                            addDeviceMarker.on('dragend', function(e) {
                                const pos = e.target.getLatLng();
                                updateAddDeviceLocation(pos.lat, pos.lng);
                            });
                            
                            // Update marker when map is clicked
                            addDeviceMap.on('click', function(e) {
                                addDeviceMarker.setLatLng(e.latlng);
                                updateAddDeviceLocation(e.latlng.lat, e.latlng.lng);
                            });
                            
                            // Initial location update
                            updateAddDeviceLocation(defaultLat, defaultLng);
                        }
                        
                        function updateAddDeviceLocation(lat, lng) {
                            document.getElementById('addLatitude').value = lat.toFixed(6);
                            document.getElementById('addLongitude').value = lng.toFixed(6);
                            
                            // Check if near river
                            const isNearRiver = checkAddDeviceDistanceToRiver(lat, lng);
                            
                            if (!isNearRiver) {
                                document.getElementById('addLocationName').value = 'Out of River Bounds';
                                // Show warning but don't auto-change status for add form
                                showAddDeviceWarning('⚠️ Warning: This location is far from Mangima River');
                            } else {
                                // Detect river section
                                const midstreamStart = { lat: 8.369297, lng: 124.876785 };
                                const midstreamEnd = { lat: 8.394873, lng: 124.903068 };
                                
                                let riverSectionDisplay = '';
                                if (lng < midstreamStart.lng) {
                                    riverSectionDisplay = 'Upstream';
                                } else if (lng >= midstreamStart.lng && lng <= midstreamEnd.lng) {
                                    riverSectionDisplay = 'Midstream';
                                } else {
                                    riverSectionDisplay = 'Downstream';
                                }
                                
                                document.getElementById('addLocationName').value = `${riverSectionDisplay} Section`;
                            }
                        }
                        
                        function checkAddDeviceDistanceToRiver(lat, lng) {
                            const riverCoords = [
                                [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
                                [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
                                [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
                                [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
                                [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
                                [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
                                [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
                                [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
                                [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
                                [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
                                [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
                                [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
                                [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
                                [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
                                [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
                                [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
                                [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
                                [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
                                [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
                            ];
                            
                            const MAX_DISTANCE_KM = 0.5;
                            let minDistance = Infinity;
                            
                            for (let i = 0; i < riverCoords.length; i++) {
                                const distance = calculateAddDeviceDistance(lat, lng, riverCoords[i][0], riverCoords[i][1]);
                                if (distance < minDistance) minDistance = distance;
                            }
                            
                            return minDistance <= MAX_DISTANCE_KM;
                        }
                        
                        function calculateAddDeviceDistance(lat1, lng1, lat2, lng2) {
                            const R = 6371;
                            const dLat = (lat2 - lat1) * Math.PI / 180;
                            const dLng = (lng2 - lng1) * Math.PI / 180;
                            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                                      Math.sin(dLng/2) * Math.sin(dLng/2);
                            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                            return R * c;
                        }
                        
                        function showAddDeviceWarning(message) {
                            const existingWarning = document.getElementById('add-device-warning');
                            if (existingWarning) existingWarning.remove();
                            
                            const warning = document.createElement('div');
                            warning.id = 'add-device-warning';
                            warning.style.cssText = `
                                position: absolute;
                                top: 10px;
                                left: 10px;
                                right: 10px;
                                background: #f59e0b;
                                color: white;
                                padding: 10px 16px;
                                border-radius: 8px;
                                font-size: 12px;
                                font-weight: 600;
                                text-align: center;
                                z-index: 1000;
                                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
                            `;
                            warning.innerHTML = message;
                            
                            const mapContainer = document.getElementById('add-device-location-map');
                            if (mapContainer && mapContainer.parentNode) {
                                mapContainer.parentNode.style.position = 'relative';
                                mapContainer.parentNode.appendChild(warning);
                                
                                setTimeout(() => {
                                    if (warning.parentNode) warning.parentNode.removeChild(warning);
                                }, 4000);
                            }
                        }
                        
                        function toggleAddDeviceMap() {
                            const status = document.getElementById('addDeviceStatus').value;
                            const mapContainer = document.getElementById('addDeviceMapContainer');
                            const mapPlaceholder = document.getElementById('addDeviceMapPlaceholder');
                            const locationFields = document.getElementById('addDeviceLocationFields');
                            
                            if (status === 'active') {
                                // Show map and location fields
                                mapContainer.style.display = 'block';
                                mapPlaceholder.style.display = 'none';
                                locationFields.style.display = 'block';
                                
                                // Initialize map if not already done
                                setTimeout(() => {
                                    initAddDeviceMap();
                                    if (addDeviceMap) addDeviceMap.invalidateSize();
                                }, 100);
                            } else {
                                // Hide map and location fields
                                mapContainer.style.display = 'none';
                                mapPlaceholder.style.display = 'flex';
                                locationFields.style.display = 'none';
                                
                                // Clear location fields
                                document.getElementById('addLatitude').value = '';
                                document.getElementById('addLongitude').value = '';
                                document.getElementById('addLocationName').value = '';
                            }
                        }
                        
                        // Initialize on page load
                        document.addEventListener('DOMContentLoaded', function() {
                            toggleAddDeviceMap();
                        });
                    </script>
                </div>
            </div>
            
        <?php elseif ($action === 'edit'): ?>
            <!-- Edit Device Form with Map -->
            <div class="card">
                <div class="card-header">
                    <h3>Edit Device</h3>
                </div>
                <div class="card-body" style="padding: 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 300px; gap: 1.5rem;">
                        <!-- Device Location Map -->
                        <div>
                            <div id="device-location-map" style="height: 400px; border-radius: var(--radius); border: 1px solid var(--gray-200);"></div>
                            <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
                                💡 Click on the map to set device location, or drag the marker
                            </p>
                        </div>
                        
                        <!-- Device Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                            
                            <div class="form-group">
                                <label>Device Name *</label>
                                <input type="text" name="device_name" value="<?= htmlspecialchars($device['device_name']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="deviceStatus" required onchange="toggleMapAccess()">
                                    <option value="active" <?= $device['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="maintenance" <?= $device['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    <option value="inactive" <?= $device['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="offline" <?= $device['status'] === 'offline' ? 'selected' : '' ?>>Offline</option>
                                    <option value="unassigned" <?= $device['status'] === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Device Condition</label>
                                <select name="device_condition" id="deviceCondition">
                                    <option value="normal" <?= ($device['device_condition'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                                    <option value="displaced" <?= ($device['device_condition'] ?? '') === 'displaced' ? 'selected' : '' ?>>Displaced (Out of Position)</option>
                                    <option value="damaged" <?= ($device['device_condition'] ?? '') === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                                    <option value="malfunctioning" <?= ($device['device_condition'] ?? '') === 'malfunctioning' ? 'selected' : '' ?>>Malfunctioning</option>
                                </select>
                                <small style="color: var(--gray-500);">Physical state of the device</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Latitude</label>
                                <input type="number" id="latitude" name="latitude" step="0.000001" 
                                       value="<?= $device['latitude'] ?: '8.368900' ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Longitude</label>
                                <input type="number" id="longitude" name="longitude" step="0.000001" 
                                       value="<?= $device['longitude'] ?: '124.863000' ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Stream Assigned</label>
                                <input type="text" id="locationName" name="location_name" 
                                       value="<?= $device['location_name'] ?: '' ?>" readonly>
                            </div>
                            
                            <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Save Device</button>
                                <a href="?action=list" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                        
                        <!-- Device Info Panel -->
                        <div class="card" style="margin: 0;">
                            <div class="card-header" style="padding: 0.75rem 1rem;">
                                <h3 style="font-size: 0.875rem; margin: 0;">📊 Device Information</h3>
                            </div>
                            <div class="card-body" style="padding: 1rem;">
                                <!-- Device Stats -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                                    <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                        <div style="font-size: 1.5rem; font-weight: 600; color: var(--primary);">ID #<?= $device['device_id'] ?></div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">Device ID</div>
                                    </div>
                                    <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                        <div style="font-size: 1.5rem; font-weight: 600; color: <?= $device['status'] === 'active' ? 'var(--success)' : 'var(--danger)' ?>;">
                                            <?= ucfirst($device['status']) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">Current Status</div>
                                    </div>
                                </div>
                                
                                <!-- Current Assignment -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Current Location</div>
                                    <div style="font-size: 0.875rem; font-weight: 500;">
                                        <?= $device['location_name'] ?: 'Unassigned' ?>
                                    </div>
                                </div>
                                
                                <!-- Last Active -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Last Active</div>
                                    <div style="font-size: 0.875rem; font-weight: 500;">
                                        <?= $device['last_active'] ? date('M d, H:i', strtotime($device['last_active'])) : 'Never' ?>
                                    </div>
                                </div>
                                
                                <!-- Coordinates -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Coordinates</div>
                                    <div style="font-size: 0.875rem; font-weight: 500; font-family: monospace;">
                                        <?= $device['latitude'] ? number_format($device['latitude'], 5) . '°N, ' . number_format($device['longitude'], 5) . '°E' : 'Not set' ?>
                                    </div>
                                </div>
                                
                                <!-- Last Updated -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Last Updated</div>
                                    <div style="font-size: 0.875rem; font-weight: 500;">
                                        <?= $device['updated_at'] ? date('M d, H:i', strtotime($device['updated_at'])) : 'Never' ?>
                                    </div>
                                </div>
                                
                                <!-- Activity History -->
                                <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                    <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Activity History</div>
                                    <div id="editDeviceHistory" style="font-size: 0.7rem; color: var(--gray-500); max-height: 120px; overflow-y: auto;">
                                        <div style="padding: 0.5rem 0; color: var(--gray-400);">Loading history...</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
                // Load device history on page load
                document.addEventListener('DOMContentLoaded', function() {
                    fetchDeviceHistoryForEdit(<?= $device['device_id'] ?>);
                });
                
                function fetchDeviceHistoryForEdit(deviceId) {
                    fetch(`devices.php?action=get_history&device_id=${deviceId}&_=${Date.now()}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.history) {
                                updateEditDeviceHistoryDisplay(data.history);
                            } else {
                                document.getElementById('editDeviceHistory').innerHTML = 
                                    '<div style="padding: 0.5rem 0; color: var(--gray-400);">No history available</div>';
                            }
                        })
                        .catch(error => {
                            console.log('Could not fetch device history:', error);
                            document.getElementById('editDeviceHistory').innerHTML = 
                                '<div style="padding: 0.5rem 0; color: var(--gray-400);">Unable to load history</div>';
                        });
                }
                
                function updateEditDeviceHistoryDisplay(history) {
                    const historyElement = document.getElementById('editDeviceHistory');
                    if (!historyElement) return;
                    
                    if (history.length === 0) {
                        historyElement.innerHTML = '<div style="padding: 0.5rem 0; color: var(--gray-400);">No history available</div>';
                        return;
                    }
                    
                    const historyHtml = history.slice(0, 5).map(entry => {
                        const date = new Date(entry.created_at);
                        const dateStr = date.toLocaleDateString('en-PH', {month: 'short', day: 'numeric'});
                        const timeStr = date.toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit', hour12: false});
                        const userName = entry.user_name || 'System';
                        
                        // Parse changes from details
                        let changesHtml = '';
                        if (entry.details && entry.details.includes(' | ')) {
                            const parts = entry.details.split(' | ');
                            const changes = parts.slice(1); // Skip the first part ("Updated device ID: X")
                            if (changes.length > 0) {
                                changesHtml = changes.map(change => {
                                    // Format change: "Field: 'old' → 'new'"
                                    const match = change.match(/^([^:]+):\s*'(.+)'\s*→\s*'(.+)'$/);
                                    if (match) {
                                        const [, field, oldVal, newVal] = match;
                                        let icon = '📝';
                                        if (field === 'Name') icon = '🏷️';
                                        else if (field === 'Status') icon = '🔘';
                                        else if (field === 'Condition') icon = '🔧';
                                        else if (field === 'Location') icon = '📍';
                                        return `<div style="margin: 0.15rem 0; padding: 0.2rem 0.4rem; background: var(--gray-50); border-radius: 4px; font-size: 0.65rem;">
                                            <span style="color: var(--gray-600);">${icon} ${field}:</span> 
                                            <span style="text-decoration: line-through; color: var(--gray-400);">${oldVal}</span> 
                                            <span style="color: var(--gray-500);">→</span> 
                                            <span style="color: var(--primary); font-weight: 500;">${newVal}</span>
                                        </div>`;
                                    }
                                    return `<div style="font-size: 0.65rem; color: var(--gray-500);">${change}</div>`;
                                }).join('');
                            }
                        }
                        
                        let actionIcon = '📝';
                        if (entry.action === 'DEVICE_CREATE') actionIcon = '➕';
                        else if (entry.action === 'DEVICE_UPDATE') actionIcon = '✏️';
                        else if (entry.action === 'DEVICE_DELETE') actionIcon = '🗑️';
                        
                        return `
                            <div style="padding: 0.4rem 0; border-bottom: 1px solid var(--gray-100);">
                                <div style="display: flex; justify-content: space-between; color: var(--gray-600);">
                                    <span style="font-weight: 500;">${actionIcon} ${entry.action}</span>
                                    <span style="color: var(--gray-400); font-size: 0.65rem;">${dateStr}, ${timeStr}</span>
                                </div>
                                ${changesHtml ? `<div style="margin-top: 0.3rem;">${changesHtml}</div>` : ''}
                                <div style="color: var(--gray-400); font-size: 0.6rem; margin-top: 0.2rem;">by ${userName}</div>
                            </div>
                        `;
                    }).join('');
                    
                    historyElement.innerHTML = historyHtml;
                }
            </script>
            
            <script>
                // Initialize device location map
                const lat = parseFloat(document.getElementById('latitude').value) || 8.368900;
                const lng = parseFloat(document.getElementById('longitude').value) || 124.863000;
                
                // Mangima River coordinates for visualization
                const mangimaRiverCoords = [
                    [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
                    [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
                    [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
                    [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
                    [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
                    [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
                    [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
                    [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
                    [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
                    [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
                    [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
                    [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
                    [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
                    [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
                    [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
                    [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
                    [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
                    [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
                    [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
                ];
                
                const deviceMap = L.map('device-location-map', {
                    center: [lat, lng],
                    zoom: 13,
                    minZoom: 12,
                    maxZoom: 16,
                    maxBounds: [[8.32, 124.88], [8.42, 124.93]],
                    maxBoundsViscosity: 1.0
                }).setView([lat, lng], 13);
                
                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                    attribution: '© OpenStreetMap contributors',
                    subdomains: 'abcd'
                }).addTo(deviceMap);
                
                // Add Mangima River visualization
                L.polyline(mangimaRiverCoords, {
                    color: '#0d1117',
                    weight: 18,
                    opacity: 0.12
                }).addTo(deviceMap);
                
                L.polyline(mangimaRiverCoords, {
                    color: '#1a56db',
                    weight: 8,
                    opacity: 0.55
                }).addTo(deviceMap);
                
                L.polyline(mangimaRiverCoords, {
                    color: '#60a5fa',
                    weight: 4,
                    opacity: 0.85
                }).addTo(deviceMap);
                
                // Add existing location markers
                const locations = <?= json_encode($locations, JSON_NUMERIC_CHECK) ?>;
                locations.forEach(loc => {
                    const color = getRiverSectionColor(loc.river_section);
                    
                    const marker = L.circleMarker([loc.latitude, loc.longitude], {
                        radius: 8,
                        fillColor: color,
                        color: '#fff',
                        weight: 2,
                        fillOpacity: 0.8
                    }).addTo(deviceMap);
                    
                    marker.bindPopup(`
                        <div style="font-family: 'Inter', sans-serif; min-width: 150px;">
                            <div style="font-weight: 600; margin-bottom: 4px;">${loc.location_name}</div>
                            <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">
                                ${loc.latitude.toFixed(5)}°N, ${loc.longitude.toFixed(5)}°E
                            </div>
                            <div style="font-size: 11px; color: #1a56db;">Click to select location</div>
                        </div>
                    `);
                });
                
                // Add other device markers to prevent area duplication
                const allDevices = <?= json_encode($devices, JSON_NUMERIC_CHECK) ?>;
                const currentDeviceId = <?= json_encode($device['device_id'] ?? 0) ?>;
                
                allDevices.forEach(otherDevice => {
                    // Skip current device being edited
                    if (otherDevice.device_id == currentDeviceId) return;
                    // Skip devices without coordinates
                    if (!otherDevice.latitude || !otherDevice.longitude) return;
                    
                    const deviceStatusColor = otherDevice.device_condition === 'displaced' ? '#7c3aed' : 
                                            otherDevice.device_condition === 'damaged' ? '#1f2937' : 
                                            otherDevice.device_condition === 'malfunctioning' ? '#d97706' : 
                                            otherDevice.status === 'active' ? '#16a34a' : 
                                            otherDevice.status === 'maintenance' ? '#3b82f6' : '#9ca3af';
                    
                    const otherDeviceMarker = L.marker([otherDevice.latitude, otherDevice.longitude], {
                        icon: L.divIcon({
                            html: `<div style="position: relative; width: 24px; height: 24px;">
                                    <div style="position: absolute; inset: 0; border-radius: 50%; background: ${deviceStatusColor}; opacity: 0.3;"></div>
                                    <div style="position: absolute; inset: 2px; border-radius: 50%; background: ${deviceStatusColor}; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>
                                    <div style="position: absolute; top: -8px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid ${deviceStatusColor};"></div>
                                </div>`,
                            iconSize: [24, 24],
                            iconAnchor: [12, 12],
                            className: ''
                        })
                    }).addTo(deviceMap);
                    
                    otherDeviceMarker.bindPopup(`
                        <div style="font-family: 'Inter', sans-serif; min-width: 180px;">
                            <div style="font-weight: 600; margin-bottom: 4px; color: ${deviceStatusColor};">
                                ${otherDevice.device_name}
                            </div>
                            <div style="font-size: 11px; color: #6b7280;">Status: ${otherDevice.status}</div>
                            ${otherDevice.device_condition && otherDevice.device_condition !== 'normal' ? `<div style="font-size: 11px; color: #7c3aed; margin-top: 2px;">${otherDevice.device_condition}</div>` : ''}
                            <div style="font-size: 10px; color: #9ca3af; margin-top: 4px;">
                                ${otherDevice.river_section ? otherDevice.river_section : 'No section'}
                            </div>
                        </div>
                    `);
                });
                
                // Add draggable device marker
                const deviceMarker = L.marker([lat, lng], { 
                    draggable: true,
                    icon: L.divIcon({
                        html: `<div style="position: relative; width: 30px; height: 30px;">
                                <div style="position: absolute; inset: 0; border-radius: 50%; background: #1a56db; opacity: 0.2; animation: pulse 2s ease-out infinite"></div>
                                <div style="position: absolute; inset: 4px; border-radius: 50%; background: #1a56db; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.3); cursor: move;"></div>
                                <div style="position: absolute; top: -8px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid #1a56db;"></div>
                            </div>`,
                        iconSize: [30, 30],
                        iconAnchor: [15, 30],
                        className: ''
                    })
                }).addTo(deviceMap);
                
                // Update form when marker is dragged
                deviceMarker.on('dragend', function(e) {
                    const pos = e.target.getLatLng();
                    updateDeviceLocation(pos.lat, pos.lng);
                });
                
                // Update marker when map is clicked
                deviceMap.on('click', function(e) {
                    deviceMarker.setLatLng(e.latlng);
                    updateDeviceLocation(e.latlng.lat, e.latlng.lng);
                });
                
                function updateDeviceLocation(lat, lng) {
                    document.getElementById('latitude').value = lat.toFixed(6);
                    document.getElementById('longitude').value = lng.toFixed(6);
                    
                    // Check if device is close to the river
                    const isNearRiver = checkDistanceToRiver(lat, lng);
                    
                    if (!isNearRiver) {
                        // Auto-set status to displaced if too far from river
                        const statusSelect = document.getElementById('deviceStatus');
                        statusSelect.value = 'displaced';
                        
                        // Show warning
                        showLocationWarning('Device is too far from Mangima River. Status auto-changed to "Displaced".');
                        
                        // Update stream assigned field
                        document.getElementById('locationName').value = 'Out of River Bounds';
                    } else {
                        // Auto-detect river section
                        detectRiverSection(lat, lng);
                    }
                    
                    // Trigger status change to update map access
                    toggleMapAccess();
                }
                
                function checkDistanceToRiver(lat, lng) {
                    // Mangima River coordinates
                    const riverCoords = [
                        [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
                        [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
                        [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
                        [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
                        [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
                        [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
                        [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
                        [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
                        [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
                        [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
                        [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
                        [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
                        [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
                        [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
                        [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
                        [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
                        [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
                        [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
                        [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
                    ];
                    
                    const MAX_DISTANCE_KM = 0.5; // 500 meters threshold
                    
                    let minDistance = Infinity;
                    
                    // Find minimum distance to any river point
                    for (let i = 0; i < riverCoords.length; i++) {
                        const distance = calculateDistance(lat, lng, riverCoords[i][0], riverCoords[i][1]);
                        if (distance < minDistance) {
                            minDistance = distance;
                        }
                    }
                    
                    return minDistance <= MAX_DISTANCE_KM;
                }
                
                function showLocationWarning(message) {
                    // Remove existing warning if present
                    const existingWarning = document.getElementById('location-warning');
                    if (existingWarning) {
                        existingWarning.remove();
                    }
                    
                    // Create warning element
                    const warning = document.createElement('div');
                    warning.id = 'location-warning';
                    warning.style.cssText = `
                        position: absolute;
                        top: 10px;
                        left: 10px;
                        right: 10px;
                        background: #7c3aed;
                        color: white;
                        padding: 12px 16px;
                        border-radius: 8px;
                        font-size: 13px;
                        font-weight: 600;
                        text-align: center;
                        z-index: 1000;
                        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
                        animation: slideDown 0.3s ease-out;
                    `;
                    warning.innerHTML = `⚠️ ${message}`;
                    
                    // Add to map container
                    const mapContainer = document.getElementById('device-location-map');
                    if (mapContainer && mapContainer.parentNode) {
                        mapContainer.parentNode.style.position = 'relative';
                        mapContainer.parentNode.appendChild(warning);
                        
                        // Auto-hide after 5 seconds
                        setTimeout(() => {
                            if (warning.parentNode) {
                                warning.style.animation = 'slideDown 0.3s ease-out reverse';
                                setTimeout(() => {
                                    if (warning.parentNode) {
                                        warning.parentNode.removeChild(warning);
                                    }
                                }, 300);
                            }
                        }, 5000);
                    }
                }
                
                function calculateDistance(lat1, lng1, lat2, lng2) {
                    const R = 6371; // Earth's radius in km
                    const dLat = (lat2 - lat1) * Math.PI / 180;
                    const dLng = (lng2 - lng1) * Math.PI / 180;
                    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                              Math.sin(dLng/2) * Math.sin(dLng/2);
                    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                    return R * c;
                }
                
                function detectRiverSection(lat, lng) {
                    // Define river section boundaries based on coordinates
                    // Upstream: before midstream start
                    // Midstream: 8.369297, 124.876785 to 8.394873, 124.903068
                    // Downstream: after midstream end
                    
                    const midstreamStart = { lat: 8.369297, lng: 124.876785 };
                    const midstreamEnd = { lat: 8.394873, lng: 124.903068 };
                    
                    // Calculate position along the river (using longitude as primary axis)
                    let riverSection = '';
                    let riverSectionDisplay = '';
                    let riverProgress = 0;
                    
                    if (lng < midstreamStart.lng) {
                        // Upstream section
                        riverSection = 'upstream';
                        riverSectionDisplay = 'Upstream';
                        riverProgress = 0.15; // Approximate upstream position
                    } else if (lng >= midstreamStart.lng && lng <= midstreamEnd.lng) {
                        // Midstream section
                        riverSection = 'midstream';
                        riverSectionDisplay = 'Midstream';
                        // Calculate progress within midstream
                        riverProgress = (lng - midstreamStart.lng) / (midstreamEnd.lng - midstreamStart.lng);
                    } else {
                        // Downstream section
                        riverSection = 'downstream';
                        riverSectionDisplay = 'Downstream';
                        riverProgress = 0.85; // Approximate downstream position
                    }
                    
                    // Always update location name with river section info
                    document.getElementById('locationName').value = `${riverSectionDisplay} Section`;
                    
                    // Show river section indicator
                    showRiverSectionIndicator(riverSection, riverSectionDisplay, riverProgress);
                }
                
                function showRiverSectionIndicator(section, displayName, progress) {
                    // Remove existing indicator if present
                    const existingIndicator = document.getElementById('river-section-indicator');
                    if (existingIndicator) {
                        existingIndicator.remove();
                    }
                    
                    // Create indicator element
                    const indicator = document.createElement('div');
                    indicator.id = 'river-section-indicator';
                    indicator.style.cssText = `
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: ${getRiverSectionColor(section)};
                        color: white;
                        padding: 6px 12px;
                        border-radius: 20px;
                        font-size: 12px;
                        font-weight: 600;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                        z-index: 1000;
                        animation: slideInRight 0.3s ease-out;
                    `;
                    indicator.textContent = `${displayName} (${Math.round(progress * 100)}% down river)`;
                    
                    // Add to map container
                    const mapContainer = document.getElementById('device-location-map');
                    if (mapContainer && mapContainer.parentNode) {
                        mapContainer.parentNode.style.position = 'relative';
                        mapContainer.parentNode.appendChild(indicator);
                        
                        // Auto-hide after 3 seconds
                        setTimeout(() => {
                            if (indicator.parentNode) {
                                indicator.style.animation = 'slideInRight 0.3s ease-out reverse';
                                setTimeout(() => {
                                    if (indicator.parentNode) {
                                        indicator.parentNode.removeChild(indicator);
                                    }
                                }, 300);
                            }
                        }, 3000);
                    }
                }
                
                function getRiverSectionColor(section) {
                    const colors = {
                        'upstream': '#059669',
                        'midstream': '#d97706', 
                        'downstream': '#dc2626'
                    };
                    return colors[section] || '#3b82f6';
                }
                
                // Add pulse animation CSS
                if (!document.getElementById('device-pulse-style')) {
                    const pulseStyle = document.createElement('style');
                    pulseStyle.id = 'device-pulse-style';
                    pulseStyle.textContent = `
                        @keyframes pulse {
                            0% { transform: scale(1); opacity: 1; }
                            50% { transform: scale(1.1); opacity: 0.7; }
                            100% { transform: scale(1); opacity: 1; }
                        }
                        @keyframes slideInRight {
                            from { transform: translateX(100%); opacity: 0; }
                            to { transform: translateX(0); opacity: 1; }
                        }
                        @keyframes slideDown {
                            from { transform: translateY(-100%); opacity: 0; }
                            to { transform: translateY(0); opacity: 1; }
                        }
                    `;
                    document.head.appendChild(pulseStyle);
                }
                
                // Add status badge to map
                updateMapStatusBadge();
                
                function updateMapStatusBadge() {
                    const status = document.getElementById('deviceStatus').value;
                    const statusColors = {
                        'active': '#16a34a',
                        'maintenance': '#d97706',
                        'displaced': '#7c3aed',
                        'damaged': '#1f2937',
                        'inactive': '#dc2626'
                    };
                    const statusLabels = {
                        'active': '● Active',
                        'maintenance': '🔧 Maintenance',
                        'displaced': '📍 Displaced',
                        'damaged': '💔 Damaged',
                        'inactive': '⚠️ Inactive'
                    };
                    
                    // Remove existing badge
                    const existingBadge = document.getElementById('map-status-badge');
                    if (existingBadge) {
                        existingBadge.remove();
                    }
                    
                    // Create status badge
                    const badge = document.createElement('div');
                    badge.id = 'map-status-badge';
                    badge.style.cssText = `
                        position: absolute;
                        top: 10px;
                        left: 10px;
                        background: ${statusColors[status]};
                        color: white;
                        padding: 8px 14px;
                        border-radius: 20px;
                        font-size: 12px;
                        font-weight: 600;
                        z-index: 1000;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                        font-family: 'Inter', sans-serif;
                    `;
                    badge.textContent = statusLabels[status];
                    
                    // Add to map container
                    const mapContainer = document.getElementById('device-location-map');
                    if (mapContainer && mapContainer.parentNode) {
                        mapContainer.parentNode.style.position = 'relative';
                        mapContainer.parentNode.appendChild(badge);
                    }
                }
                
                // Initialize map access based on device status
                toggleMapAccess();
                updateMapStatusBadge();
                
                function toggleMapAccess() {
                    const status = document.getElementById('deviceStatus').value;
                    const mapContainer = document.getElementById('device-location-map');
                    const latitudeField = document.getElementById('latitude');
                    const longitudeField = document.getElementById('longitude');
                    const streamAssignedField = document.getElementById('locationName');
                    
                    if (status === 'active') {
                        // Enable map functionality
                        if (mapContainer) {
                            mapContainer.style.opacity = '1';
                            mapContainer.style.pointerEvents = 'auto';
                            mapContainer.style.filter = 'none';
                        }
                        if (deviceMarker) {
                            deviceMarker.dragging.enable();
                        }
                        // Remove status message if exists
                        const statusMsg = document.getElementById('map-status-message');
                        if (statusMsg) {
                            statusMsg.remove();
                        }
                    } else {
                        // Disable map functionality
                        if (mapContainer) {
                            mapContainer.style.opacity = '0.5';
                            mapContainer.style.pointerEvents = 'none';
                            mapContainer.style.filter = 'grayscale(100%)';
                        }
                        if (deviceMarker) {
                            deviceMarker.dragging.disable();
                        }
                        
                        // Show status message
                        let statusMsg = document.getElementById('map-status-message');
                        if (!statusMsg) {
                            statusMsg = document.createElement('div');
                            statusMsg.id = 'map-status-message';
                            statusMsg.style.cssText = `
                                position: absolute;
                                top: 50%;
                                left: 50%;
                                transform: translate(-50%, -50%);
                                background: rgba(0,0,0,0.8);
                                color: white;
                                padding: 1rem 1.5rem;
                                border-radius: 8px;
                                font-size: 14px;
                                font-weight: 600;
                                text-align: center;
                                z-index: 1001;
                                pointer-events: none;
                            `;
                            mapContainer.parentNode.style.position = 'relative';
                            mapContainer.parentNode.appendChild(statusMsg);
                        }
                        
                        if (status === 'maintenance') {
                            statusMsg.innerHTML = '🔧 <strong>Device Under Maintenance</strong><br><small>Location assignment is disabled during maintenance</small>';
                        } else if (status === 'displaced') {
                            statusMsg.innerHTML = '📍 <strong>Device Displaced</strong><br><small>Device is out of assigned position - location locked</small>';
                        } else if (status === 'damaged') {
                            statusMsg.innerHTML = '💔 <strong>Device Damaged</strong><br><small>Device is beyond repair - location assignment disabled</small>';
                        } else if (status === 'inactive') {
                            statusMsg.innerHTML = '⚠️ <strong>Device Inactive</strong><br><small>Location assignment is disabled for inactive devices</small>';
                        }
                        
                        // Clear coordinate fields for non-active devices
                        latitudeField.value = '';
                        longitudeField.value = '';
                        streamAssignedField.value = '';
                    }
                    
                    // Update status badge on map
                    updateMapStatusBadge();
                }
            </script>
            
        <?php elseif ($action === 'delete'): ?>
            <!-- Delete Confirmation -->
            <div class="modal" onclick="if(event.target === this) window.location.href='?action=list'">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Confirm Delete</h3>
                        <a href="?action=list" style="text-decoration: none; font-size: 1.5rem;">&times;</a>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this device?</p>
                        <p><strong><?= $device ? htmlspecialchars($device['device_name']) : '' ?></strong></p>
                        <p style="color: var(--danger); font-size: 0.875rem;">This action cannot be undone.</p>
                    </div>
                    <div class="modal-actions" style="padding: 1rem; border-top: 1px solid var(--gray-200);">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="device_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                            <a href="?action=list" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
            
        <?php elseif ($action === 'edit_location' && $location): ?>
            <!-- Edit Location Form -->
            <div class="card">
                <div class="card-header">
                    <h3>Edit Location: <?= htmlspecialchars($location['location_name']) ?></h3>
                </div>
                <div class="card-body" style="padding: 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <!-- Location Map -->
                        <div>
                            <div id="edit-location-map" style="height: 400px; border-radius: var(--radius); border: 1px solid var(--gray-200);"></div>
                            <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
                                💡 Click on the map or drag the marker to update location
                            </p>
                        </div>
                        
                        <!-- Location Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="edit_location">
                            <input type="hidden" name="location_id" value="<?= $location['location_id'] ?>">
                            
                            <div class="form-group">
                                <label>Location Name *</label>
                                <input type="text" name="location_name" value="<?= htmlspecialchars($location['location_name']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>River Section</label>
                                <select name="river_section" required>
                                    <option value="upstream" <?= $location['river_section'] === 'upstream' ? 'selected' : '' ?>>Upstream</option>
                                    <option value="midstream" <?= $location['river_section'] === 'midstream' ? 'selected' : '' ?>>Midstream</option>
                                    <option value="downstream" <?= $location['river_section'] === 'downstream' ? 'selected' : '' ?>>Downstream</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Latitude</label>
                                <input type="number" id="locLatitude" name="latitude" step="0.000001" value="<?= $location['latitude'] ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Longitude</label>
                                <input type="number" id="locLongitude" name="longitude" step="0.000001" value="<?= $location['longitude'] ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: var(--radius);"><?= htmlspecialchars($location['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Save Location</button>
                                <a href="?action=list" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                    
                    <script>
                        // Initialize map for location editing
                        const locLat = <?= $location['latitude'] ?>;
                        const locLng = <?= $location['longitude'] ?>;
                        
                        const locMap = L.map('edit-location-map').setView([locLat, locLng], 14);
                        
                        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                            attribution: '© OpenStreetMap contributors',
                            subdomains: 'abcd',
                            maxZoom: 19
                        }).addTo(locMap);
                        
                        // Add river polyline
                        const riverCoords = [
                            [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
                            [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
                            [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
                            [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
                            [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
                            [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
                            [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
                            [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
                            [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
                            [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
                            [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
                            [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
                            [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
                            [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
                            [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
                            [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
                            [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
                            [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
                            [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
                        ];
                        
                        L.polyline(riverCoords, {
                            color: '#3b82f6',
                            weight: 4,
                            opacity: 0.85
                        }).addTo(locMap);
                        
                        // Add draggable marker
                        const locMarker = L.marker([locLat, locLng], { 
                            draggable: true,
                            icon: L.divIcon({
                                html: `<div style="position: relative; width: 30px; height: 30px;">
                                        <div style="position: absolute; inset: 0; border-radius: 50%; background: #1a56db; opacity: 0.2; animation: pulse 2s ease-out infinite"></div>
                                        <div style="position: absolute; inset: 4px; border-radius: 50%; background: #1a56db; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.3); cursor: move;"></div>
                                        <div style="position: absolute; top: -8px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid #1a56db;"></div>
                                    </div>`,
                                iconSize: [30, 30],
                                iconAnchor: [15, 30],
                                className: ''
                            })
                        }).addTo(locMap);
                        
                        // Update form when marker is dragged
                        locMarker.on('dragend', function(e) {
                            const pos = e.target.getLatLng();
                            document.getElementById('locLatitude').value = pos.lat.toFixed(6);
                            document.getElementById('locLongitude').value = pos.lng.toFixed(6);
                        });
                        
                        // Update marker when map is clicked
                        locMap.on('click', function(e) {
                            locMarker.setLatLng(e.latlng);
                            document.getElementById('locLatitude').value = e.latlng.lat.toFixed(6);
                            document.getElementById('locLongitude').value = e.latlng.lng.toFixed(6);
                        });
                    </script>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Combined Status with Condition Summary -->
            <div class="card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); margin-bottom: 1.5rem;">
                <div class="card-header" style="padding: 1rem;">
                    <h4 style="font-size: 0.875rem; color: var(--gray-600); margin: 0;">📊 Devices by Status with Condition</h4>
                </div>
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.75rem;">
                        <?php
                        // Calculate status + condition combinations
                        $statusConditionCounts = [];
                        $statusLabels = ['active' => 'Active', 'maintenance' => 'Maintenance', 'inactive' => 'Inactive', 'offline' => 'Offline', 'unassigned' => 'Unassigned'];
                        $conditionColors = ['normal' => '#16a34a', 'displaced' => '#7c3aed', 'damaged' => '#1f2937', 'malfunctioning' => '#d97706'];
                        
                        foreach ($statusLabels as $status => $statusLabel) {
                            foreach ($conditionColors as $condition => $color) {
                                $key = $status . '_' . $condition;
                                $statusConditionCounts[$key] = 0;
                            }
                        }
                        
                        foreach ($devices as $device) {
                            $status = $device['status'] ?? 'inactive';
                            $condition = $device['device_condition'] ?? 'normal';
                            $key = $status . '_' . $condition;
                            if (isset($statusConditionCounts[$key])) {
                                $statusConditionCounts[$key]++;
                            }
                        }
                        
                        // Display combinations with devices
                        foreach ($statusLabels as $status => $statusLabel) {
                            foreach ($conditionColors as $condition => $color) {
                                $count = $statusConditionCounts[$status . '_' . $condition];
                                if ($count > 0) {
                                    $conditionLabel = $condition === 'normal' ? '' : ' (' . ucfirst($condition) . ')';
                                    echo '
                                    <div style="text-align: center; padding: 0.75rem; background: white; border-radius: 8px; border-left: 4px solid ' . $color . ';">
                                        <div style="font-size: 1.5rem; font-weight: 700; color: ' . $color . ';">' . $count . '</div>
                                        <div style="font-size: 0.7rem; color: var(--gray-500);">' . $statusLabel . $conditionLabel . '</div>
                                    </div>';
                                }
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Device Management with Map and Details -->
            <div style="display: grid; grid-template-columns: 1fr 400px; gap: 1.5rem;">
                <!-- Device Map Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3>📍 Device Locations Overview</h3>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span class="badge badge-info"><?= count($devices) ?> devices</span>
                            <button onclick="toggleDeviceMap()" class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">
                                <span id="deviceMapToggleText">Hide Map</span>
                            </button>
                        </div>
                    </div>
                    <div id="deviceMapContainer" style="padding: 1.25rem;">
                        <div id="devices-overview-map" style="height: 500px; border-radius: var(--radius); border: 1px solid var(--gray-200);"></div>
                        <div style="margin-top: 0.75rem; padding: 0.75rem; background: #f8fafc; border-radius: 8px;">
                            <div style="font-size: 0.75rem; font-weight: 600; color: var(--gray-600); margin-bottom: 0.5rem;">📊 Legend</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; font-size: 0.7rem; color: var(--gray-500);">
                                <!-- Status Colors (when condition is normal) -->
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #059669;"></span>
                                    <span>Active</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #3b82f6;"></span>
                                    <span>Maintenance</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #dc2626;"></span>
                                    <span>Inactive</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #6b7280;"></span>
                                    <span>Offline</span>
                                </div>
                                <!-- Condition Colors (priority) -->
                                <div style="display: flex; align-items: center; gap: 0.25rem; margin-left: 0.5rem; border-left: 1px solid var(--gray-300); padding-left: 0.5rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #7c3aed;"></span>
                                    <span>⚠️ Displaced</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #1f2937;"></span>
                                    <span>⚠️ Damaged</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #d97706;"></span>
                                    <span>⚠️ Malfunctioning</span>
                                </div>
                            </div>
                            <div style="font-size: 0.65rem; color: var(--gray-400); margin-top: 0.25rem; font-style: italic;">
                                * Condition colors take priority over status colors
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Device Details Panel -->
                <div class="card" style="margin: 0;">
                    <div class="card-header">
                        <h3 style="font-size: 1rem;">📋 Device Details</h3>
                    </div>
                    <div id="deviceDetailsPanel" style="padding: 1rem;">
                        <!-- Device Statistics -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                            <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--primary);"><?= count($devices) ?></div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">Total Devices</div>
                            </div>
                            <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                <?php 
                                $activeCount = count(array_filter($devices, fn($d) => $d['status'] === 'active'));
                                ?>
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--success);"><?= $activeCount ?></div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">Active</div>
                            </div>
                        </div>
                        
                        <!-- Device Selection -->
                        <div style="margin-bottom: 1rem;">
                            <div style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem;">Select Device</div>
                            <select id="deviceSelector" onchange="selectDevice(this.value)" 
                                    style="width: 100%; padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.875rem;">
                                <?php foreach ($devices as $device): ?>
                                    <option value="<?= $device['device_id'] ?>" 
                                            <?= isset($devices[0]) && $device['device_id'] === $devices[0]['device_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($device['device_name']) ?> 
                                        <?= $device['status'] === 'active' ? '🟢' : ($device['status'] === 'maintenance' ? '🔵' : '🔴') ?>
                                        <?= $device['location_name'] ? '• ' . htmlspecialchars($device['location_name']) : '• Unassigned' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Selected Device Details -->
                        <div id="selectedDeviceDetails">
                            <?php if (!empty($devices)): ?>
                                <?php $firstDevice = $devices[0]; ?>
                                <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius);">
                                    <div style="font-weight: 600; margin-bottom: 0.5rem;"><?= htmlspecialchars($firstDevice['device_name']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                        Status: <span class="badge badge-<?= $firstDevice['status'] === 'active' ? 'success' : ($firstDevice['status'] === 'maintenance' ? 'warning' : 'danger') ?>"><?= ucfirst($firstDevice['status']) ?></span>
                                    </div>
                                    <?php if ($firstDevice['location_name']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                            Location: <?= htmlspecialchars($firstDevice['location_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($firstDevice['river_section']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                            Section: <?= ucfirst($firstDevice['river_section']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($firstDevice['last_active']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 1rem;">
                                            Last Active: <?= date('M d, H:i', strtotime($firstDevice['last_active'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Last Sensor Readings -->
                                    <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                        <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Last Sensor Readings</div>
                                        <div id="deviceReadings-<?= $firstDevice['device_id'] ?>" style="font-size: 0.75rem; color: var(--gray-500);">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌡️ Temp:</span>
                                                    <span style="font-weight: 500;">--°C</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🧪 pH:</span>
                                                    <span style="font-weight: 500;">--</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌫️ Turb:</span>
                                                    <span style="font-weight: 500;">-- NTU</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>💧 DO:</span>
                                                    <span style="font-weight: 500;">-- mg/L</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌊 Level:</span>
                                                    <span style="font-weight: 500;">-- m</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>� Sed:</span>
                                                    <span style="font-weight: 500;">-- mg/L</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                    No devices available. Click "Add Device" to create one.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Devices Summary Dashboard -->
            <?php
            // Calculate device statistics
            $statusCounts = [
                'active' => 0, 'maintenance' => 0, 'inactive' => 0, 'offline' => 0, 'unassigned' => 0
            ];
            $conditionCounts = [
                'normal' => 0, 'displaced' => 0, 'damaged' => 0, 'malfunctioning' => 0
            ];
            $sectionCounts = [
                'upstream' => 0, 'midstream' => 0, 'downstream' => 0,
                'unassigned' => 0
            ];
            
            foreach ($devices as $device) {
                $status = $device['status'] ?? 'inactive';
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
                
                $condition = $device['device_condition'] ?? 'normal';
                if (isset($conditionCounts[$condition])) {
                    $conditionCounts[$condition]++;
                }
                
                $section = $device['river_section'] ?? '';
                if ($section && isset($sectionCounts[$section])) {
                    $sectionCounts[$section]++;
                } elseif (!$section) {
                    $sectionCounts['unassigned']++;
                }
            }
            ?>
            
            <!-- Device Management with Map and Details -->
            <div class="card">
                <div class="card-header" style="flex-direction: column; align-items: stretch; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3>📡 All Monitoring Devices</h3>
                        <span class="badge badge-info" id="deviceCount"><?= count($devices) ?> total</span>
                    </div>
                    
                    <!-- Filter Controls -->
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <label style="font-size: 0.75rem; color: var(--gray-500); font-weight: 500;">Status</label>
                            <select id="filterStatus" style="padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 0.875rem;" onchange="filterDevices()">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="displaced">Displaced</option>
                                <option value="damaged">Damaged</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <label style="font-size: 0.75rem; color: var(--gray-500); font-weight: 500;">River Section</label>
                            <select id="filterSection" style="padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 0.875rem;" onchange="filterDevices()">
                                <option value="">All Sections</option>
                                <option value="upstream">Upstream</option>
                                <option value="midstream">Midstream</option>
                                <option value="downstream">Downstream</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <label style="font-size: 0.75rem; color: var(--gray-500); font-weight: 500;">Search</label>
                            <input type="text" id="filterSearch" placeholder="Device name..." style="padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 0.875rem;" onkeyup="filterDevices()">
                        </div>
                        
                        <div style="display: flex; align-items: flex-end;">
                            <button onclick="resetFilters()" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">Reset Filters</button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table" id="devicesTable">
                        <thead>
                            <tr>
                                <th>Device Name</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>River Section</th>
                                <th>Last Active</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="devicesTableBody">
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 2rem;">
                                        No devices found. Click "Add Device" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $device): ?>
                                    <tr class="device-row" 
                                        data-status="<?= $device['status'] ?>" 
                                        data-condition="<?= $device['device_condition'] ?? 'normal' ?>"
                                        data-section="<?= $device['river_section'] ?? '' ?>"
                                        data-name="<?= strtolower(htmlspecialchars($device['device_name'])) ?>">
                                        <td><strong><?= htmlspecialchars($device['device_name']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $device['status'] === 'active' ? 'success' : ($device['status'] === 'maintenance' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($device['status']) ?>
                                            </span>
                                            <?php if (($device['device_condition'] ?? 'normal') !== 'normal'): ?>
                                                <span class="badge" style="background: <?= ($device['device_condition'] === 'displaced') ? '#7c3aed' : (($device['device_condition'] === 'damaged') ? '#1f2937' : '#d97706') ?>; margin-left: 4px;">
                                                    <?= ucfirst($device['device_condition']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $device['location_name'] ? htmlspecialchars($device['location_name']) : '—' ?></td>
                                        <td><?= $device['river_section'] ? ucfirst($device['river_section']) : '—' ?></td>
                                        <td><?= $device['last_active'] ? date('M d, H:i', strtotime($device['last_active'])) : 'Never' ?></td>
                                        <td style="display: flex; gap: 0.5rem;">
                                            <a href="?action=edit&id=<?= $device['device_id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.75rem;">Edit</a>
                                            <a href="?action=delete&id=<?= $device['device_id'] ?>" class="btn btn-danger" style="padding: 0.25rem 0.75rem;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
                // Device filtering functionality
                function filterDevices() {
                    const statusFilter = document.getElementById('filterStatus').value.toLowerCase();
                    const sectionFilter = document.getElementById('filterSection').value.toLowerCase();
                    const searchFilter = document.getElementById('filterSearch').value.toLowerCase();
                    
                    const rows = document.querySelectorAll('.device-row');
                    let visibleCount = 0;
                    
                    rows.forEach(row => {
                        const status = row.getAttribute('data-status').toLowerCase();
                        const section = row.getAttribute('data-section').toLowerCase();
                        const name = row.getAttribute('data-name').toLowerCase();
                        
                        const statusMatch = !statusFilter || status === statusFilter;
                        const sectionMatch = !sectionFilter || section === sectionFilter;
                        const searchMatch = !searchFilter || name.includes(searchFilter);
                        
                        if (statusMatch && sectionMatch && searchMatch) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                    
                    // Update count badge
                    const totalCount = document.querySelectorAll('.device-row').length;
                    document.getElementById('deviceCount').textContent = `${visibleCount} of ${totalCount} shown`;
                    
                    // Show no results message if needed
                    const tbody = document.getElementById('devicesTableBody');
                    const existingNoResults = tbody.querySelector('.no-results-row');
                    if (existingNoResults) existingNoResults.remove();
                    
                    if (visibleCount === 0) {
                        const noResultsRow = document.createElement('tr');
                        noResultsRow.className = 'no-results-row';
                        noResultsRow.innerHTML = `
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                No devices match the selected filters.
                            </td>
                        `;
                        tbody.appendChild(noResultsRow);
                    }
                }
                
                function resetFilters() {
                    document.getElementById('filterStatus').value = '';
                    document.getElementById('filterSection').value = '';
                    document.getElementById('filterSearch').value = '';
                    filterDevices();
                }
                // Device map and details functionality
                let deviceOverviewMap = null;
                let deviceMapVisible = true;
                let selectedDeviceId = <?= !empty($devices) ? $devices[0]['device_id'] : 'null' ?>;
                const devicesData = <?= json_encode($devices, JSON_NUMERIC_CHECK) ?>;
                
                function initializeDeviceMap() {
                    const devices = devicesData;
                    
                    if (devices.length === 0) {
                        document.getElementById('deviceMapContainer').style.display = 'none';
                        return;
                    }
                    
                    // Filter devices with locations
                    const devicesWithLocations = devices.filter(d => d.latitude && d.longitude);
                    
                    if (devicesWithLocations.length === 0) {
                        document.getElementById('deviceMapContainer').innerHTML = 
                            '<div style="text-align: center; padding: 2rem; color: var(--gray-500);">No devices with assigned locations found.</div>';
                        return;
                    }
                    
                    // Calculate center point
                    const avgLat = devicesWithLocations.reduce((sum, d) => sum + d.latitude, 0) / devicesWithLocations.length;
                    const avgLng = devicesWithLocations.reduce((sum, d) => sum + d.longitude, 0) / devicesWithLocations.length;
                    
                    deviceOverviewMap = L.map('devices-overview-map').setView([avgLat, avgLng], 12);
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                        attribution: '© OpenStreetMap contributors',
                        subdomains: 'abcd'
                    }).addTo(deviceOverviewMap);
                    
                    // Add markers for each device
                    devicesWithLocations.forEach(device => {
                        const color = getDeviceStatusColor(device.status, device.device_condition);
                        
                        const marker = L.circleMarker([device.latitude, device.longitude], {
                            radius: 10,
                            fillColor: color,
                            color: '#fff',
                            weight: 2,
                            fillOpacity: 0.9
                        }).addTo(deviceOverviewMap);
                        
                        // Highlight selected device
                        if (device.device_id === selectedDeviceId) {
                            marker.setStyle({
                                radius: 14,
                                weight: 3,
                                fillOpacity: 1.0
                            });
                        }
                        
                        // Popup content
                        const popupContent = `
                            <div style="font-family: 'Inter', sans-serif; min-width: 200px;">
                                <div style="font-weight: 600; margin-bottom: 8px;">${device.device_name}</div>
                                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                    Status: <span style="color: ${color}; font-weight: 500;">${device.status}</span>
                                </div>
                                ${device.device_condition && device.device_condition !== 'normal' ? `
                                    <div style="font-size: 12px; color: #7c3aed; margin-bottom: 4px;">
                                        ⚠️ Condition: ${device.device_condition}
                                    </div>
                                ` : ''}
                                ${device.location_name ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                        Location: ${device.location_name}
                                    </div>
                                ` : ''}
                                ${device.river_section ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">
                                        ${device.river_section.charAt(0).toUpperCase() + device.river_section.slice(1)} Section
                                    </div>
                                ` : ''}
                                <div style="font-size: 11px; font-family: monospace; color: #9ca3af; margin-bottom: 12px;">
                                    ${device.latitude.toFixed(5)}°N, ${device.longitude.toFixed(5)}°E
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <a href="?action=edit&id=${device.device_id}" 
                                       style="flex: 1; text-align: center; padding: 4px 8px; background: #f3f4f6; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                        Edit
                                    </a>
                                    <a href="?action=delete&id=${device.device_id}" 
                                       style="flex: 1; text-align: center; padding: 4px 8px; background: #fee2e2; color: #dc2626; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                        Delete
                                    </a>
                                </div>
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                        
                        // Click handler for marker
                        marker.on('click', function() {
                            selectDevice(device.device_id);
                        });
                    });
                    
                    // Fit map to show all markers
                    if (devicesWithLocations.length > 0) {
                        const bounds = L.latLngBounds(devicesWithLocations.map(d => [d.latitude, d.longitude]));
                        deviceOverviewMap.fitBounds(bounds.pad(0.1));
                    }
                }
                
                function getDeviceStatusColor(status, condition) {
                    // Condition takes priority over status for coloring
                    if (condition && condition !== 'normal') {
                        const conditionColors = {
                            'displaced': '#7c3aed',      // Purple
                            'damaged': '#1f2937',        // Dark gray/black
                            'malfunctioning': '#d97706'  // Orange
                        };
                        return conditionColors[condition] || '#9ca3af';
                    }
                    
                    // Use status color if condition is normal
                    const statusColors = {
                        'active': '#059669',      // Green
                        'maintenance': '#3b82f6', // Blue
                        'inactive': '#dc2626',    // Red
                        'offline': '#6b7280',     // Gray
                        'unassigned': '#9ca3af'   // Light gray
                    };
                    return statusColors[status] || '#9ca3af';
                }
                
                function selectDevice(deviceId) {
                    selectedDeviceId = deviceId;
                    
                    // Update dropdown selector
                    const dropdown = document.getElementById('deviceSelector');
                    if (dropdown) {
                        dropdown.value = deviceId;
                    }
                    
                    // Update details panel
                    const device = devicesData.find(d => d.device_id === deviceId);
                    if (device) {
                        updateDeviceDetails(device);
                    }
                    
                    // Update map marker highlighting
                    if (deviceOverviewMap) {
                        deviceOverviewMap.eachLayer(layer => {
                            if (layer instanceof L.CircleMarker) {
                                layer.setStyle({
                                    radius: 10,
                                    weight: 2,
                                    fillOpacity: 0.9
                                });
                            }
                        });
                        
                        // Highlight selected marker
                        const devicesWithLocations = devicesData.filter(d => d.latitude && d.longitude);
                        const selectedDevice = devicesWithLocations.find(d => d.device_id === deviceId);
                        if (selectedDevice) {
                            deviceOverviewMap.eachLayer(layer => {
                                if (layer instanceof L.CircleMarker) {
                                    const latlng = layer.getLatLng();
                                    if (Math.abs(latlng.lat - selectedDevice.latitude) < 0.0001 && 
                                        Math.abs(latlng.lng - selectedDevice.longitude) < 0.0001) {
                                        layer.setStyle({
                                            radius: 14,
                                            weight: 3,
                                            fillOpacity: 1.0
                                        });
                                    }
                                }
                            });
                        }
                    }
                }
                
                function updateDeviceDetails(device) {
                    const detailsHtml = `
                        <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius);">
                            <div style="font-weight: 600; margin-bottom: 0.5rem;">${device.device_name}</div>
                            <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                Status: <span class="badge badge-${device.status === 'active' ? 'success' : (device.status === 'maintenance' ? 'warning' : 'danger')}">${device.status.charAt(0).toUpperCase() + device.status.slice(1)}</span>
                            </div>
                            ${device.device_condition && device.device_condition !== 'normal' ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Condition: <span class="badge" style="background: ${device.device_condition === 'displaced' ? '#7c3aed' : (device.device_condition === 'damaged' ? '#1f2937' : '#d97706')}; color: white;">${device.device_condition.charAt(0).toUpperCase() + device.device_condition.slice(1)}</span>
                                </div>
                            ` : ''}
                            ${device.location_name ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Location: ${device.location_name}
                                </div>
                            ` : ''}
                            ${device.river_section ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Section: ${device.river_section.charAt(0).toUpperCase() + device.river_section.slice(1)}
                                </div>
                            ` : ''}
                            ${device.last_active ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Last Active: ${new Date(device.last_active).toLocaleDateString('en-PH', {month: 'short', day: 'numeric'})}, ${new Date(device.last_active).toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit', hour12: false})}
                                </div>
                            ` : ''}
                            ${device.updated_at ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 1rem;">
                                    Last Updated: ${new Date(device.updated_at).toLocaleDateString('en-PH', {month: 'short', day: 'numeric'})}, ${new Date(device.updated_at).toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit', hour12: false})}
                                </div>
                            ` : ''}
                            
                            <!-- Last Sensor Readings -->
                            <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Last Sensor Readings</div>
                                <div id="deviceReadings-${device.device_id}" style="font-size: 0.75rem; color: var(--gray-500);">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌡️ Temp:</span>
                                            <span style="font-weight: 500;">--°C</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🧪 pH:</span>
                                            <span style="font-weight: 500;">--</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌫️ Turb:</span>
                                            <span style="font-weight: 500;">-- NTU</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>💧 DO:</span>
                                            <span style="font-weight: 500;">-- mg/L</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌊 Level:</span>
                                            <span style="font-weight: 500;">-- m</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🟤 Sed:</span>
                                            <span style="font-weight: 500;">-- mg/L</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Device Activity History -->
                            <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Activity History</div>
                                <div id="deviceHistory-${device.device_id}" style="font-size: 0.75rem; color: var(--gray-500); max-height: 150px; overflow-y: auto;">
                                    <div style="padding: 0.5rem 0; color: var(--gray-400);">Loading history...</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('selectedDeviceDetails').innerHTML = detailsHtml;
                    
                    // Fetch and display latest sensor readings for this device
                    fetchDeviceReadings(device.device_id);
                    
                    // Fetch and display device history
                    fetchDeviceHistory(device.device_id);
                }
                
                function fetchDeviceReadings(deviceId) {
                    // Fetch the latest readings for this device directly from database
                    fetch(`devices.php?action=get_readings&device_id=${deviceId}&_=${Date.now()}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.readings) {
                                updateDeviceReadingsDisplay(deviceId, data.readings);
                            }
                        })
                        .catch(error => {
                            console.log('Could not fetch device readings:', error);
                        });
                }
                
                function fetchDeviceHistory(deviceId) {
                    // Fetch device activity history
                    fetch(`devices.php?action=get_history&device_id=${deviceId}&_=${Date.now()}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.history) {
                                updateDeviceHistoryDisplay(deviceId, data.history);
                            } else {
                                document.getElementById(`deviceHistory-${deviceId}`).innerHTML = 
                                    '<div style="padding: 0.5rem 0; color: var(--gray-400);">No history available</div>';
                            }
                        })
                        .catch(error => {
                            console.log('Could not fetch device history:', error);
                            document.getElementById(`deviceHistory-${deviceId}`).innerHTML = 
                                '<div style="padding: 0.5rem 0; color: var(--gray-400);">Unable to load history</div>';
                        });
                }
                
                function updateDeviceHistoryDisplay(deviceId, history) {
                    const historyElement = document.getElementById(`deviceHistory-${deviceId}`);
                    if (!historyElement) return;
                    
                    if (history.length === 0) {
                        historyElement.innerHTML = '<div style="padding: 0.5rem 0; color: var(--gray-400);">No history available</div>';
                        return;
                    }
                    
                    const historyHtml = history.map(entry => {
                        const date = new Date(entry.created_at);
                        const dateStr = date.toLocaleDateString('en-PH', {month: 'short', day: 'numeric'});
                        const timeStr = date.toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit', hour12: false});
                        const userName = entry.user_name || 'System';
                        
                        // Parse changes from details
                        let changesHtml = '';
                        if (entry.details && entry.details.includes(' | ')) {
                            const parts = entry.details.split(' | ');
                            const changes = parts.slice(1); // Skip the first part ("Updated device ID: X")
                            if (changes.length > 0) {
                                changesHtml = changes.map(change => {
                                    // Format change: "Field: 'old' → 'new'"
                                    const match = change.match(/^([^:]+):\s*'(.+)'\s*→\s*'(.+)'$/);
                                    if (match) {
                                        const [, field, oldVal, newVal] = match;
                                        let icon = '📝';
                                        if (field === 'Name') icon = '🏷️';
                                        else if (field === 'Status') icon = '🔘';
                                        else if (field === 'Condition') icon = '🔧';
                                        else if (field === 'Location') icon = '📍';
                                        return `<div style="margin: 0.15rem 0; padding: 0.2rem 0.4rem; background: var(--gray-50); border-radius: 4px; font-size: 0.65rem;">
                                            <span style="color: var(--gray-600);">${icon} ${field}:</span> 
                                            <span style="text-decoration: line-through; color: var(--gray-400);">${oldVal}</span> 
                                            <span style="color: var(--gray-500);">→</span> 
                                            <span style="color: var(--primary); font-weight: 500;">${newVal}</span>
                                        </div>`;
                                    }
                                    return `<div style="font-size: 0.65rem; color: var(--gray-500);">${change}</div>`;
                                }).join('');
                            }
                        }
                        
                        let actionIcon = '📝';
                        if (entry.action === 'DEVICE_CREATE') actionIcon = '➕';
                        else if (entry.action === 'DEVICE_UPDATE') actionIcon = '✏️';
                        else if (entry.action === 'DEVICE_DELETE') actionIcon = '🗑️';
                        
                        return `
                            <div style="padding: 0.4rem 0; border-bottom: 1px solid var(--gray-100); font-size: 0.7rem;">
                                <div style="display: flex; justify-content: space-between; color: var(--gray-600);">
                                    <span style="font-weight: 500;">${actionIcon} ${entry.action}</span>
                                    <span style="color: var(--gray-400);">${dateStr}, ${timeStr}</span>
                                </div>
                                ${changesHtml ? `<div style="margin-top: 0.3rem;">${changesHtml}</div>` : `<div style="color: var(--gray-500); margin-top: 0.15rem; font-size: 0.65rem;">${entry.details}</div>`}
                                <div style="color: var(--gray-400); font-size: 0.6rem; margin-top: 0.2rem;">by ${userName}</div>
                            </div>
                        `;
                    }).join('');
                    
                    historyElement.innerHTML = historyHtml;
                }
                
                function updateDeviceReadingsDisplay(deviceId, readings) {
                    const readingsElement = document.getElementById(`deviceReadings-${deviceId}`);
                    if (!readingsElement) return;
                    
                    const sensors = [
                        { key: 'temperature', icon: '🌡️', unit: '°C', format: v => v?.toFixed(1) },
                        { key: 'ph_level', icon: '🧪', unit: '', format: v => v?.toFixed(2) },
                        { key: 'turbidity', icon: '🌫️', unit: 'NTU', format: v => v?.toFixed(1) },
                        { key: 'dissolved_oxygen', icon: '💧', unit: 'mg/L', format: v => v?.toFixed(1) },
                        { key: 'water_level', icon: '🌊', unit: 'm', format: v => v?.toFixed(2) },
                        { key: 'sediments', icon: '🟤', unit: 'mg/L', format: v => v?.toFixed(0) }
                    ];
                    
                    const readingsHtml = sensors.map(sensor => {
                        const value = readings[sensor.key];
                        const displayValue = value !== null && value !== undefined ? 
                            sensor.format(value) + sensor.unit : '--' + sensor.unit;
                        
                        return `
                            <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                <span>${sensor.icon} ${sensor.key.replace('_', ' ').charAt(0).toUpperCase() + sensor.key.slice(1).replace('_', ' ')}:</span>
                                <span style="font-weight: 500;">${displayValue}</span>
                            </div>
                        `;
                    }).join('');
                    
                    readingsElement.innerHTML = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            ${readingsHtml}
                        </div>
                    `;
                }
                
                function toggleDeviceMap() {
                    const container = document.getElementById('deviceMapContainer');
                    const toggleText = document.getElementById('deviceMapToggleText');
                    
                    if (deviceMapVisible) {
                        container.style.display = 'none';
                        toggleText.textContent = 'Show Map';
                        deviceMapVisible = false;
                    } else {
                        container.style.display = 'block';
                        toggleText.textContent = 'Hide Map';
                        deviceMapVisible = true;
                        
                        // Reinitialize map if needed
                        if (!deviceOverviewMap) {
                            setTimeout(initializeDeviceMap, 100);
                        }
                    }
                }
                
                // Initialize map when page loads
                document.addEventListener('DOMContentLoaded', function() {
                    initializeDeviceMap();
                    startMapSync(10000); // Start syncing every 10 seconds
                });
                
                // ── Map Sync with Dashboard ───────────────────────────────
                let _mapSyncTimer = null;
                let _mapSyncBusy = false;
                
                function startMapSync(ms) {
                    if (_mapSyncTimer) clearInterval(_mapSyncTimer);
                    _mapSyncTimer = setInterval(syncMapNow, ms);
                }
                
                function stopMapSync() {
                    if (_mapSyncTimer) {
                        clearInterval(_mapSyncTimer);
                        _mapSyncTimer = null;
                    }
                }
                
                async function syncMapNow() {
                    if (_mapSyncBusy || !deviceOverviewMap) return;
                    _mapSyncBusy = true;
                    
                    try {
                        const res = await fetch('devices.php?action=map_sync&_=' + Date.now());
                        if (!res.ok) return;
                        const d = await res.json();
                        if (!d.ok) return;
                        
                        updateMapFromSync(d.devices, d.locations);
                    } catch (e) {
                        console.error('Map sync error:', e);
                    } finally {
                        _mapSyncBusy = false;
                    }
                }
                
                function updateMapFromSync(devices, locations) {
                    if (!deviceOverviewMap) return;
                    
                    // Clear existing markers (keep the map tiles)
                    deviceOverviewMap.eachLayer(layer => {
                        if (layer instanceof L.CircleMarker || layer instanceof L.Popup) {
                            deviceOverviewMap.removeLayer(layer);
                        }
                    });
                    
                    // Add updated markers
                    const activeDevices = devices.filter(d => d.lat && d.lng);
                    
                    activeDevices.forEach(device => {
                        const color = getDeviceStatusColor(device.status, device.device_condition);
                        
                        const marker = L.circleMarker([device.lat, device.lng], {
                            radius: 10,
                            fillColor: color,
                            color: '#fff',
                            weight: 2,
                            fillOpacity: 0.9
                        }).addTo(deviceOverviewMap);
                        
                        // Highlight selected device
                        if (device.device_id === selectedDeviceId) {
                            marker.setStyle({
                                radius: 14,
                                weight: 3,
                                fillOpacity: 1.0
                            });
                        }
                        
                        const popupContent = `
                            <div style="font-family: 'Inter', sans-serif; min-width: 200px;">
                                <div style="font-weight: 600; margin-bottom: 8px;">${device.device_name}</div>
                                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                    Status: <span style="color: ${color}; font-weight: 500;">${device.status}</span>
                                </div>
                                ${device.device_condition && device.device_condition !== 'normal' ? `
                                    <div style="font-size: 12px; color: #7c3aed; margin-bottom: 4px;">
                                        ⚠️ Condition: ${device.device_condition}
                                    </div>
                                ` : ''}
                                ${device.location_name ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                        Location: ${device.location_name}
                                    </div>
                                ` : ''}
                                ${device.river_section ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">
                                        ${device.river_section.charAt(0).toUpperCase() + device.river_section.slice(1)} Section
                                    </div>
                                ` : ''}
                                <div style="font-size: 11px; font-family: monospace; color: #9ca3af; margin-bottom: 12px;">
                                    ${device.lat.toFixed(5)}°N, ${device.lng.toFixed(5)}°E
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <a href="?action=edit&id=${device.device_id}" 
                                       style="flex: 1; text-align: center; padding: 4px 8px; background: #f3f4f6; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                        Edit
                                    </a>
                                </div>
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                        marker.on('click', function() {
                            selectDevice(device.device_id);
                        });
                    });
                }
            </script>
        <?php endif; ?>
        
        <!-- Toast Notifications -->
        <?php include '../../assets/toast.php'; ?>
        
        <?php
        // Show toasts for session messages
        if (!empty($success)) {
            echo "<script>showToast(" . json_encode($success) . ", 'success', 5000);</script>";
        }
        if (!empty($error)) {
            echo "<script>showToast(" . json_encode($error) . ", 'error', 8000);</script>";
        }
        if (isset($_SESSION['warning'])) {
            echo "<script>showToast(" . json_encode($_SESSION['warning']) . ", 'warning', 6000);</script>";
            unset($_SESSION['warning']);
        }
        ?>
    </div>
</body>
</html>

<?php
// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Log system activity
 */
function logActivity($conn, $action, $details = '') {
    $userId = $_SESSION['user_id'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $action, $details, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();
}
function handleFormSubmission($conn, $data) {
    $action = $data['action'] ?? '';
    
    try {
        if ($action === 'add' || $action === 'edit') {
            handleDeviceSubmission($conn, $data);
        } elseif ($action === 'edit_location') {
            handleLocationSubmission($conn, $data);
        } elseif ($action === 'delete') {
            handleDeviceDelete($conn, $data);
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/**
 * Calculate distance between two coordinates using Haversine formula
 */
function calculateDistanceToRiver($lat, $lng) {
    // Mangima River coordinates
    $riverCoords = [
        [8.345958, 124.898607], [8.346955, 124.899036], [8.347603, 124.898081],
        [8.349471, 124.896461], [8.349216, 124.895474], [8.349535, 124.894755],
        [8.348909, 124.894058], [8.349881, 124.893209], [8.352050, 124.889584],
        [8.351096, 124.889497], [8.351978, 124.888415], [8.352369, 124.887056],
        [8.352210, 124.886676], [8.352643, 124.886427], [8.353468, 124.884863],
        [8.355492, 124.883376], [8.356292, 124.881332], [8.358270, 124.881140],
        [8.368532, 124.875713], [8.373977, 124.876690], [8.381657, 124.897203],
        [8.394810, 124.903483], [8.396343, 124.907500], [8.399906, 124.911121],
        [8.400757, 124.910773], [8.401407, 124.910581], [8.401636, 124.910868],
        [8.401774, 124.911007], [8.402125, 124.911168], [8.402489, 124.911218],
        [8.402853, 124.911196], [8.403020, 124.911119], [8.403792, 124.910506],
        [8.405310, 124.909972], [8.405901, 124.909983], [8.406337, 124.910087],
        [8.406533, 124.910179], [8.406700, 124.910291], [8.406745, 124.910385],
        [8.406713, 124.910512], [8.405924, 124.911388], [8.405818, 124.911576],
        [8.405829, 124.911689], [8.405924, 124.911801], [8.406275, 124.911984],
        [8.406715, 124.912414], [8.407049, 124.912661], [8.409034, 124.913466],
        [8.409793, 124.913708], [8.410064, 124.913713], [8.410472, 124.913676],
        [8.411629, 124.913198], [8.412245, 124.912800], [8.412515, 124.912462],
        [8.412632, 124.911962], [8.413237, 124.909739], [8.413179, 124.909497]
    ];
    
    $R = 6371; // Earth's radius in km
    $maxDistance = 0.5; // 500 meters threshold
    $minDistance = PHP_FLOAT_MAX;
    
    foreach ($riverCoords as $riverPoint) {
        $lat2 = $riverPoint[0];
        $lng2 = $riverPoint[1];
        
        $dLat = deg2rad($lat2 - $lat);
        $dLng = deg2rad($lng2 - $lng);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $distance = $R * $c;
        
        if ($distance < $minDistance) {
            $minDistance = $distance;
        }
    }
    
    return $minDistance <= $maxDistance;
}

/**
 * Handle device add/edit
 */
function handleDeviceSubmission($conn, $data) {
    $deviceName = trim($data['device_name'] ?? '');
    $status = $data['status'] ?? 'inactive';
    $deviceCondition = $data['device_condition'] ?? 'normal';
    $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
    $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
    $streamAssigned = trim($data['location_name'] ?? '');
    
    if (empty($deviceName)) {
        throw new Exception('Device name is required');
    }
    
    // Check for duplicate device name
    $deviceId = isset($data['device_id']) ? (int)$data['device_id'] : 0;
    $checkStmt = $conn->prepare("SELECT device_id FROM devices WHERE device_name = ? AND device_id != ?");
    $checkStmt->bind_param("si", $deviceName, $deviceId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        throw new Exception("Device name '{$deviceName}' is already taken. Please choose a different name.");
    }
    
    $locationId = null;
    $conditionChanged = false;
    
    // Check if device is too far from river when active with coordinates
    if ($status === 'active' && $latitude && $longitude) {
        $isNearRiver = calculateDistanceToRiver($latitude, $longitude);
        
        if (!$isNearRiver) {
            // Auto-change condition to displaced (keep status as active)
            $deviceCondition = 'displaced';
            $conditionChanged = true;
        }
    }
    
    // Only create/update location if device is active and has coordinates
    if ($status === 'active' && $latitude && $longitude) {
        // Try to find existing location with similar coordinates
        $stmt = $conn->prepare("SELECT location_id FROM locations WHERE 
                               ABS(latitude - ?) < 0.001 AND ABS(longitude - ?) < 0.001");
        $stmt->bind_param("dd", $latitude, $longitude);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($existingLocation = $result->fetch_assoc()) {
            $locationId = $existingLocation['location_id'];
        } else {
            // Create new location for this device
            $riverSection = 'custom'; // Default section
            if (strpos($streamAssigned, 'Upstream') !== false) {
                $riverSection = 'upstream';
            } elseif (strpos($streamAssigned, 'Midstream') !== false) {
                $riverSection = 'midstream';
            } elseif (strpos($streamAssigned, 'Downstream') !== false) {
                $riverSection = 'downstream';
            }
            
            $locationName = $streamAssigned ?: 'Custom Location';
            
            $stmt = $conn->prepare("INSERT INTO locations (location_name, river_section, latitude, longitude) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssdd", $locationName, $riverSection, $latitude, $longitude);
            
            if ($stmt->execute()) {
                $locationId = $conn->insert_id;
            } else {
                throw new Exception('Failed to create location: ' . $conn->error);
            }
        }
    }
    
    if (isset($data['device_id']) && $data['device_id'] > 0) {
        // Get existing device data to compare changes
        $existingDevice = getDeviceById($conn, $data['device_id']);
        
        // Update existing device
        $stmt = $conn->prepare("UPDATE devices SET device_name = ?, status = ?, device_condition = ?, location_id = ?, updated_at = NOW() WHERE device_id = ?");
        $stmt->bind_param("ssssi", $deviceName, $status, $deviceCondition, $locationId, $data['device_id']);
        
        if ($stmt->execute()) {
            if ($conditionChanged) {
                $_SESSION['warning'] = 'Device condition set to "Displaced" - location is far from Mangima River but status remains Active.';
            } else {
                $_SESSION['success'] = 'Device updated successfully';
            }
            
            // Build detailed log of what changed
            $changes = [];
            if ($existingDevice) {
                if ($existingDevice['device_name'] !== $deviceName) {
                    $changes[] = "Name: '{$existingDevice['device_name']}' → '{$deviceName}'";
                }
                if ($existingDevice['status'] !== $status) {
                    $changes[] = "Status: '{$existingDevice['status']}' → '{$status}'";
                }
                if ($existingDevice['device_condition'] !== $deviceCondition) {
                    $changes[] = "Condition: '{$existingDevice['device_condition']}' → '{$deviceCondition}'";
                }
                if (($existingDevice['latitude'] != $latitude) || ($existingDevice['longitude'] != $longitude)) {
                    $oldLat = $existingDevice['latitude'] ? number_format($existingDevice['latitude'], 5) : 'none';
                    $oldLng = $existingDevice['longitude'] ? number_format($existingDevice['longitude'], 5) : 'none';
                    $newLat = $latitude ? number_format($latitude, 5) : 'none';
                    $newLng = $longitude ? number_format($longitude, 5) : 'none';
                    $changes[] = "Location: ({$oldLat}, {$oldLng}) → ({$newLat}, {$newLng})";
                }
            }
            
            // Log the activity with detailed changes
            $logDetails = "Updated device ID: {$data['device_id']}";
            if (!empty($changes)) {
                $logDetails .= " | " . implode(" | ", $changes);
            }
            logActivity($conn, 'DEVICE_UPDATE', $logDetails);
        } else {
            throw new Exception('Failed to update device: ' . $conn->error);
        }
    } else {
        // Add new device
        $stmt = $conn->prepare("INSERT INTO devices (device_name, status, device_condition, location_id, last_active) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssi", $deviceName, $status, $deviceCondition, $locationId);
        
        if ($stmt->execute()) {
            if ($conditionChanged) {
                $_SESSION['warning'] = 'Device condition set to "Displaced" - location is far from Mangima River but status remains Active.';
            } else {
                $_SESSION['success'] = 'Device added successfully';
            }
            // Log the activity
            logActivity($conn, 'DEVICE_CREATE', "Created new device: {$deviceName} (Status: {$status})");
        } else {
            throw new Exception('Failed to add device: ' . $conn->error);
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Handle location edit
 */
function handleLocationSubmission($conn, $data) {
    $locationId = (int)($data['location_id'] ?? 0);
    $locationName = trim($data['location_name'] ?? '');
    $riverSection = $data['river_section'] ?? 'upstream';
    $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
    $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
    $description = trim($data['description'] ?? '');
    
    if ($locationId <= 0) {
        throw new Exception('Invalid location ID');
    }
    
    if (empty($locationName)) {
        throw new Exception('Location name is required');
    }
    
    $stmt = $conn->prepare("UPDATE locations SET location_name = ?, river_section = ?, latitude = ?, longitude = ?, description = ? WHERE location_id = ?");
    $stmt->bind_param("ssddsi", $locationName, $riverSection, $latitude, $longitude, $description, $locationId);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Location updated successfully';
        // Log the activity
        logActivity($conn, 'LOCATION_UPDATE', "Updated location: {$locationName} (ID: {$locationId}, Section: {$riverSection})");
    } else {
        throw new Exception('Failed to update location: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Handle device deletion
 */
function handleDeviceDelete($conn, $data) {
    $deviceId = (int)$data['device_id'];
    
    if ($deviceId <= 0) {
        throw new Exception('Invalid device ID');
    }
    
    // Check if device has readings
    $result = $conn->query("SELECT COUNT(*) as count FROM sensor_readings sr 
                            JOIN sensors s ON s.sensor_id = sr.sensor_id 
                            WHERE s.device_id = $deviceId");
    $readingsCount = $result->fetch_assoc()['count'];
    
    if ($readingsCount > 0) {
        throw new Exception("Cannot delete device: It has $readingsCount sensor readings. Delete readings first.");
    }
    
    $stmt = $conn->prepare("DELETE FROM devices WHERE device_id = ?");
    $stmt->bind_param("i", $deviceId);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Device deleted successfully';
        // Log the activity
        logActivity($conn, 'DEVICE_DELETE', "Deleted device ID: {$deviceId}");
    } else {
        throw new Exception('Failed to delete device: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Get device activity history from system logs
 */
function getDeviceHistory($conn, $deviceId) {
    $sql = "SELECT sl.*, u.full_name as user_name 
            FROM system_logs sl
            LEFT JOIN users u ON u.user_id = sl.user_id
            WHERE sl.details LIKE ? OR sl.details LIKE ?
            ORDER BY sl.created_at DESC
            LIMIT 20";
    
    $pattern1 = "%ID: {$deviceId}%";
    $pattern2 = "%device_id: {$deviceId}%";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $pattern1, $pattern2);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get all devices with location info and coordinates
 */
function getAllDevices($conn) {
    $sql = "SELECT d.*, l.location_name, l.river_section, l.latitude, l.longitude 
            FROM devices d 
            LEFT JOIN locations l ON l.location_id = d.location_id 
            ORDER BY d.device_name";
    
    $result = $conn->query($sql);
    $devices = [];
    
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
    
    return $devices;
}

/**
 * Get all locations for dropdown
 */
function getAllLocations($conn) {
    $sql = "SELECT location_id, location_name, river_section 
            FROM locations 
            ORDER BY location_name";
    
    $result = $conn->query($sql);
    $locations = [];
    
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    
    return $locations;
}

/**
 * Get device by ID
 */
function getDeviceById($conn, $id) {
    $stmt = $conn->prepare("SELECT d.*, l.location_name, l.river_section, l.latitude, l.longitude, d.updated_at
                            FROM devices d 
                            LEFT JOIN locations l ON l.location_id = d.location_id 
                            WHERE d.device_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get location by ID
 */
function getLocationById($conn, $id) {
    $stmt = $conn->prepare("SELECT l.* FROM locations l WHERE l.location_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get latest sensor readings for a device
 */
function getLatestDeviceReadings($conn, $deviceId) {
    // Get all sensors for this device
    $sensorsQuery = $conn->prepare("SELECT sensor_id, sensor_type FROM sensors WHERE device_id = ?");
    $sensorsQuery->bind_param("i", $deviceId);
    $sensorsQuery->execute();
    $sensorsResult = $sensorsQuery->get_result();
    
    $readings = [];
    $sensorTypes = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
    
    // Initialize all sensor types to null
    foreach ($sensorTypes as $type) {
        $readings[$type] = null;
    }
    
    // Fetch latest reading for each sensor
    while ($sensor = $sensorsResult->fetch_assoc()) {
        $sensorId = $sensor['sensor_id'];
        $sensorType = $sensor['sensor_type'];
        
        // Get the latest reading for this sensor
        $readingQuery = $conn->prepare("
            SELECT value, timestamp 
            FROM sensor_readings 
            WHERE sensor_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ");
        $readingQuery->bind_param("i", $sensorId);
        $readingQuery->execute();
        $readingResult = $readingQuery->get_result();
        
        if ($reading = $readingResult->fetch_assoc()) {
            $readings[$sensorType] = floatval($reading['value']);
        }
    }
    
    return $readings;
}
?>