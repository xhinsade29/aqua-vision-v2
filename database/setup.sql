-- Aqua-Vision Database Setup Script
-- Run this in your MySQL/phpMyAdmin SQL editor

-- Create database
CREATE DATABASE IF NOT EXISTS mangina_watershed;
USE mangina_watershed;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'operator', 'viewer', 'researcher') DEFAULT 'viewer',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Locations table (represents stream section boundaries - start/end points)
CREATE TABLE IF NOT EXISTS locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(100) NOT NULL,
    river_section ENUM('upstream', 'midstream', 'downstream') NOT NULL,
    location_type ENUM('start', 'end') NOT NULL DEFAULT 'start',
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Devices table
CREATE TABLE IF NOT EXISTS devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    device_type VARCHAR(50) NOT NULL,
    location_id INT NULL,
    status ENUM('active', 'inactive', 'maintenance', 'offline', 'unassigned') DEFAULT 'active',
    device_condition ENUM('normal', 'displaced', 'damaged', 'malfunctioning') DEFAULT 'normal',
    description TEXT,
    installation_date DATE,
    last_active TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE CASCADE
);

-- Sensors table
CREATE TABLE IF NOT EXISTS sensors (
    sensor_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    sensor_type ENUM('temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments', 'conductivity', 'humidity', 'pressure', 'flow_rate') NOT NULL,
    unit VARCHAR(20) NOT NULL,
    min_threshold DECIMAL(10, 4) NOT NULL,
    max_threshold DECIMAL(10, 4) NOT NULL,
    calibration_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    UNIQUE KEY unique_device_sensor (device_id, sensor_type)
);

-- Sensor readings table
CREATE TABLE IF NOT EXISTS sensor_readings (
    reading_id INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id INT NOT NULL,
    value DECIMAL(10, 4) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    INDEX idx_sensor_time (sensor_id, recorded_at),
    INDEX idx_recorded_at (recorded_at)
);

-- Alerts table
CREATE TABLE IF NOT EXISTS alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    sensor_id INT NOT NULL,
    reading_id INT NOT NULL,
    alert_type ENUM('low', 'high', 'critical') NOT NULL,
    message VARCHAR(255) NOT NULL,
    status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
    acknowledged_by INT NULL,
    acknowledged_at TIMESTAMP NULL,
    resolved_by INT NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sensor_id) REFERENCES sensors(sensor_id) ON DELETE CASCADE,
    FOREIGN KEY (reading_id) REFERENCES sensor_readings(reading_id) ON DELETE CASCADE,
    INDEX idx_status_created (status, created_at),
    INDEX idx_sensor_created (sensor_id, created_at)
);

-- Maintenance logs table
CREATE TABLE IF NOT EXISTS maintenance_logs (
    maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    performed_by INT NOT NULL,
    maintenance_type ENUM('calibration', 'repair', 'replacement', 'cleaning', 'inspection', 'malfunction_fix') NOT NULL,
    damage_level ENUM('none', 'low', 'medium', 'high') DEFAULT 'none',
    malfunction_type VARCHAR(100) NULL,
    notes TEXT,
    parts_used VARCHAR(255),
    cost DECIMAL(10, 2) NULL,
    duration_minutes INT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_device_performed (device_id, performed_at)
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    alert_id INT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (alert_id) REFERENCES alerts(alert_id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read),
    INDEX idx_created (created_at)
);

-- Reports table
CREATE TABLE IF NOT EXISTS reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(255) NOT NULL,
    report_type ENUM('daily', 'weekly', 'monthly', 'custom') NOT NULL,
    generated_by INT NOT NULL,
    file_path VARCHAR(500),
    parameters JSON NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_type_generated (report_type, generated_at)
);

-- System logs table
CREATE TABLE IF NOT EXISTS system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_action_created (action, created_at),
    INDEX idx_user_created (user_id, created_at)
);

-- System settings table
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Insert river section boundary locations (start and end points for each stream section)
INSERT IGNORE INTO locations (location_name, river_section, location_type, latitude, longitude, description) VALUES
-- Upstream Section (Upper watershed entry point)
('Upstream Start', 'upstream', 'start', 8.345958, 124.898607, 'Upper watershed entry point - Mangima River origin'),
('Upstream End', 'upstream', 'end', 8.369297, 124.876785, 'Upper watershed exit point - transition to midstream'),

-- Midstream Section (Central monitoring area)
('Midstream Start', 'midstream', 'start', 8.369297, 124.876785, 'Central monitoring entry - convergence from upstream'),
('Midstream End', 'midstream', 'end', 8.394873, 124.903068, 'Central monitoring exit - transition to downstream'),

-- Downstream Section (Lower watershed exit point)
('Downstream Start', 'downstream', 'start', 8.394873, 124.903068, 'Lower watershed entry - river widening point'),
('Downstream End', 'downstream', 'end', 8.413179, 124.909497, 'Lower watershed exit - river outlet/mouth');

-- Insert sample devices (assigned to river section boundaries)
INSERT IGNORE INTO devices (device_name, device_type, location_id, status, installation_date) VALUES
('WQ-Upstream-Start', 'water_quality_station', 1, 'active', '2026-03-01'),
('WQ-Upstream-End', 'water_quality_station', 2, 'active', '2026-03-02'),
('WQ-Midstream-Start', 'water_quality_station', 3, 'active', '2026-03-03'),
('WQ-Midstream-End', 'water_quality_station', 4, 'active', '2026-03-04'),
('WQ-Downstream-Start', 'water_quality_station', 5, 'active', '2026-03-05'),
('WQ-Downstream-End', 'water_quality_station', 6, 'active', '2026-03-06'),
('Weather-Central', 'weather_station', 3, 'active', '2026-03-10'),
('Flow-Meter-Mid', 'flow_meter', 4, 'active', '2026-03-12');

-- Insert sensors for water quality stations (6 sensors per station)
INSERT IGNORE INTO sensors (device_id, sensor_type, unit, min_threshold, max_threshold) VALUES
-- WQ-Station-01
(1, 'temperature', '°C', 20, 35),
(1, 'ph_level', 'pH', 6.5, 8.5),
(1, 'turbidity', 'NTU', 0, 50),
(1, 'dissolved_oxygen', 'mg/L', 5, 14),
(1, 'water_level', 'm', 0.5, 3.0),
(1, 'sediments', 'mg/L', 0, 500),
-- WQ-Station-02
(2, 'temperature', '°C', 20, 35),
(2, 'ph_level', 'pH', 6.5, 8.5),
(2, 'turbidity', 'NTU', 0, 50),
(2, 'dissolved_oxygen', 'mg/L', 5, 14),
(2, 'water_level', 'm', 0.5, 3.0),
(2, 'sediments', 'mg/L', 0, 500),
-- WQ-Station-03
(3, 'temperature', '°C', 20, 35),
(3, 'ph_level', 'pH', 6.5, 8.5),
(3, 'turbidity', 'NTU', 0, 50),
(3, 'dissolved_oxygen', 'mg/L', 5, 14),
(3, 'water_level', 'm', 0.5, 3.0),
(3, 'sediments', 'mg/L', 0, 500),
-- WQ-Station-04
(4, 'temperature', '°C', 20, 35),
(4, 'ph_level', 'pH', 6.5, 8.5),
(4, 'turbidity', 'NTU', 0, 50),
(4, 'dissolved_oxygen', 'mg/L', 5, 14),
(4, 'water_level', 'm', 0.5, 3.0),
(4, 'sediments', 'mg/L', 0, 500),
-- WQ-Station-05
(5, 'temperature', '°C', 20, 35),
(5, 'ph_level', 'pH', 6.5, 8.5),
(5, 'turbidity', 'NTU', 0, 50),
(5, 'dissolved_oxygen', 'mg/L', 5, 14),
(5, 'water_level', 'm', 0.5, 3.0),
(5, 'sediments', 'mg/L', 0, 500),
-- WQ-Station-06
(6, 'temperature', '°C', 20, 35),
(6, 'ph_level', 'pH', 6.5, 8.5),
(6, 'turbidity', 'NTU', 0, 50),
(6, 'dissolved_oxygen', 'mg/L', 5, 14),
(6, 'water_level', 'm', 0.5, 3.0),
(6, 'sediments', 'mg/L', 0, 500),
-- Weather-01 (weather station sensors)
(7, 'temperature', '°C', 15, 40),
(7, 'humidity', '%', 30, 90),
(7, 'pressure', 'hPa', 980, 1040),
-- Flow-Meter-01 (flow meter sensors)
(8, 'flow_rate', 'm³/s', 0, 100),
(8, 'water_level', 'm', 0.5, 3.0);

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO users (username, email, password_hash, full_name, role) VALUES
('admin', 'admin@aqua-vision.com', 'admin123', 'System Administrator', 'admin');

-- Insert sample operator user (password: operator123)
INSERT IGNORE INTO users (username, email, password_hash, full_name, role) VALUES
('operator', 'operator@aqua-vision.com', 'operator123', 'Field Operator', 'operator');

-- Insert sample viewer user (password: viewer123)
INSERT IGNORE INTO users (username, email, password_hash, full_name, role) VALUES
('viewer', 'viewer@aqua-vision.com', 'viewer123', 'Data Viewer', 'viewer');

-- Insert sample researcher user (password: researcher123)
INSERT IGNORE INTO users (username, email, password_hash, full_name, role) VALUES
('researcher', 'researcher@aqua-vision.com', 'researcher123', 'Research Scientist', 'researcher');

-- Insert sample sensor readings (last 24 hours)
-- This will create some sample data for testing
INSERT IGNORE INTO sensor_readings (sensor_id, value, recorded_at) VALUES
-- Temperature readings (various times in last 24 hours)
(1, 25.5, DATE_SUB(NOW(), INTERVAL 23 HOUR)),
(1, 26.2, DATE_SUB(NOW(), INTERVAL 22 HOUR)),
(1, 24.8, DATE_SUB(NOW(), INTERVAL 21 HOUR)),
(1, 27.1, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(1, 25.9, DATE_SUB(NOW(), INTERVAL 19 HOUR)),
(2, 24.3, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(2, 26.7, DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(2, 25.1, DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(3, 26.8, DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(3, 24.6, DATE_SUB(NOW(), INTERVAL 14 HOUR)),
-- pH readings
(7, 7.2, DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(7, 7.5, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(7, 6.8, DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(8, 7.3, DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(8, 7.1, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
-- Turbidity readings
(13, 12.5, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(13, 15.3, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(14, 11.8, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(14, 13.2, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
-- Dissolved Oxygen readings
(19, 8.5, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(19, 9.2, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(20, 7.8, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
-- Water Level readings
(25, 1.5, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(25, 1.8, NOW()),
-- Sediments readings (NEW - included in schema)
(31, 45.2, DATE_SUB(NOW(), INTERVAL 23 HOUR)),
(31, 52.1, DATE_SUB(NOW(), INTERVAL 22 HOUR)),
(32, 38.7, DATE_SUB(NOW(), INTERVAL 21 HOUR)),
(32, 41.3, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(33, 47.8, DATE_SUB(NOW(), INTERVAL 19 HOUR));

-- Insert some sample maintenance logs
INSERT IGNORE INTO maintenance_logs (device_id, performed_by, maintenance_type, notes, performed_at) VALUES
(1, 1, 'calibration', 'Monthly calibration of all sensors', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(2, 1, 'cleaning', 'Cleaned turbidity sensor housing', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(3, 1, 'inspection', 'Routine inspection of device components', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- Insert sample system logs
INSERT IGNORE INTO system_logs (user_id, action, details, ip_address, created_at) VALUES
(1, 'login', 'Admin logged in successfully', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 22 HOUR)),
(2, 'login', 'Operator logged in successfully', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(2, 'alert_acknowledged', 'Acknowledged temperature alert on WQ-Upstream-Start', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(2, 'maintenance_logged', 'Logged calibration maintenance for device WQ-Upstream-Start', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(1, 'device_status_update', 'Changed device WQ-Midstream-Start status to active', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(2, 'alert_resolved', 'Resolved pH level alert on WQ-Upstream-End', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(4, 'login', 'Researcher logged in successfully', '192.168.1.102', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(2, 'maintenance_logged', 'Logged cleaning maintenance for device WQ-Upstream-End', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(1, 'settings_updated', 'Updated alert email settings', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(2, 'device_status_update', 'Changed device WQ-Downstream-Start status to maintenance', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(2, 'alert_acknowledged', 'Acknowledged turbidity alert on WQ-Midstream-End', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 'logout', 'Admin logged out', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 1 HOUR));

-- Insert sample system settings
INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('alert_email_enabled', 'true', 'boolean', 'Enable email alerts for critical notifications'),
('data_retention_days', '90', 'number', 'Number of days to retain sensor readings'),
('simulation_enabled', 'true', 'boolean', 'Enable data simulation for testing'),
('refresh_interval', '30', 'number', 'Dashboard refresh interval in seconds');

-- Success message
SELECT 'Aqua-Vision Database Setup Completed Successfully!' AS message,
       COUNT(*) as total_tables_created
FROM information_schema.tables 
WHERE table_schema = 'mangina_watershed';
