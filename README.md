# Aqua-Vision - River Water Quality Monitoring System

A comprehensive web-based monitoring system for tracking water quality parameters in the Mangima Watershed.

## 🚀 Quick Start

### 1. Database Setup

1. Start your XAMPP server (Apache + MySQL)
2. Open your browser and go to: `http://localhost/Aqua-Vision/database/setup.php`
3. This will automatically create:
   - Database: `mangina_watershed`
   - All required tables
   - Sample data for testing

### 2. Login

- **URL**: `http://localhost/Aqua-Vision/login.php`
- **Default Credentials**:
  - Username: `admin`
  - Password: `admin123`

### 3. Access the Dashboard

After logging in, you'll be redirected to:
- **Dashboard**: `http://localhost/Aqua-Vision/apps/admin/dashboard.php`
- **Device Management**: `http://localhost/Aqua-Vision/apps/admin/devices.php`

## 📊 Features

### Real-time Monitoring
- **6 Water Quality Parameters**: Temperature, pH, Turbidity, Dissolved Oxygen, Water Level, Sediments
- **Live Data Updates**: Real-time sensor readings with automatic refresh
- **Alert System**: Automatic notifications when parameters exceed safe ranges
- **Interactive Maps**: Geographic visualization of monitoring stations

### River Section Tracking
- **3 River Sections**: Upstream, Midstream, Downstream
- **Section-specific Status**: Individual health monitoring for each section
- **Color-coded Indicators**: Visual status representation (Normal/Warning/Critical)

### Data Visualization
- **24-Hour Trend Charts**: Historical data visualization for all parameters
- **Device-specific Charts**: Individual sensor performance tracking
- **Alert Distribution**: Visual breakdown of alerts by river section

### Device Management
- **CRUD Operations**: Add, edit, delete monitoring devices
- **Device Status Tracking**: Active, inactive, maintenance, offline status
- **Sensor Configuration**: Customizable thresholds and units per sensor
- **Location Management**: Geographic organization of monitoring stations

## 🏗️ System Architecture

### Database Schema
```
├── users (System users and authentication)
├── locations (Monitoring stations)
├── devices (Physical monitoring equipment)
├── sensors (Individual sensor types per device)
├── sensor_readings (Time-series sensor data)
├── alerts (System notifications)
├── maintenance_logs (Device maintenance records)
├── notifications (User notifications)
├── reports (Generated reports)
├── system_logs (Audit trail)
└── system_settings (Configuration)
```

### File Structure
```
Aqua-Vision/
├── apps/
│   └── admin/
│       ├── dashboard.php (Main monitoring dashboard)
│       ├── devices.php (Device management)
│       └── includes/
│           ├── dashboard_overview_api.php (API endpoints)
│           └── dashboard_overview_data.php (Data functions)
├── assets/
│   └── navigation.php (Navigation component)
├── database/
│   ├── config.php (Database configuration)
│   └── setup.php (Database initialization)
├── login.php (Authentication)
├── logout.php (Session termination)
└── README.md (This file)
```

## 🔧 Technical Stack

- **Backend**: PHP 8.0+ with MySQLi
- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **Charts**: Chart.js 4.4.0
- **Maps**: Leaflet.js 1.9.4
- **UI Framework**: Custom CSS with Bootstrap Icons
- **Database**: MySQL 8.0+

## 📡 API Endpoints

### Dashboard API (`apps/admin/includes/dashboard_overview_api.php`)

- `GET ?action=fetch` - Retrieve current dashboard data
- `GET ?action=simulate` - Generate simulated sensor data
- `GET ?action=monitor_state` - Get system status

### Response Format
```json
{
  "success": true,
  "devices": [...],
  "readings": [...],
  "alerts": [...],
  "chartData": {...}
}
```

## 🚨 Alert System

### Thresholds (Default)
| Parameter | Min | Max | Unit |
|-----------|------|------|------|
| Temperature | 20°C | 35°C | °C |
| pH Level | 6.5 | 8.5 | pH |
| Turbidity | 0 | 50 | NTU |
| Dissolved Oxygen | 5 | 14 | mg/L |
| Water Level | 0.5 | 3.0 | m |
| Sediments | 0 | 500 | mg/L |

### Alert Types
- **Low**: Parameter below minimum threshold
- **High**: Parameter above maximum threshold
- **Critical**: Severe deviation from normal range

## 🔄 Data Simulation

The system includes a built-in data simulator for testing:
- Generates realistic sensor readings within safe ranges
- Occasionally produces out-of-range values to test alerts
- Updates device last_active timestamps
- Creates corresponding alerts when thresholds are exceeded

## 🛠️ Customization

### Adding New Sensor Types
1. Update the `sensors` table enum in `database/setup.php`
2. Add sensor configuration to `get_default_sensors()` in `devices.php`
3. Update threshold checks in `dashboard_overview_data.php`
4. Add sensor icon and label to helper functions

### Modifying Thresholds
- Edit device sensors through the Device Management interface
- Or modify default values in `database/setup.php`

## 🔒 Security Features

- **Session-based Authentication**: Secure user sessions
- **Input Sanitization**: SQL injection prevention
- **XSS Protection**: Output escaping
- **CSRF Protection**: Form validation tokens
- **Activity Logging**: Complete audit trail

## 📱 Responsive Design

- **Mobile-friendly**: Responsive layout for all screen sizes
- **Touch-optimized**: Mobile interaction support
- **Progressive Enhancement**: Works without JavaScript (basic functionality)

## 🚀 Deployment

### Production Setup
1. Update database credentials in `database/config.php`
2. Set `error_reporting(0)` in production
3. Configure HTTPS/SSL
4. Set up database backups
5. Configure monitoring alerts

### Environment Variables
```php
// database/config.php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'mangina_watershed');
```

## 🐛 Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check MySQL service is running
   - Verify database credentials in `config.php`
   - Ensure database exists

2. **No Data Displaying**
   - Run `database/setup.php` to initialize data
   - Check if devices are marked as 'active'
   - Verify sensor readings exist in database

3. **Charts Not Loading**
   - Check browser console for JavaScript errors
   - Ensure Chart.js library is loading
   - Verify API endpoints are responding

4. **Login Issues**
   - Clear browser cookies and cache
   - Check session configuration in PHP
   - Verify user exists in database

## 📞 Support

For technical support or questions:
1. Check the browser console for JavaScript errors
2. Review the system logs in the database
3. Verify all file permissions are correct
4. Ensure XAMPP services are running properly

## 📄 License

This project is part of the Aqua-Vision water quality monitoring system for the Mangima Watershed.

---

**Last Updated**: March 26, 2026
**Version**: 1.0.0
