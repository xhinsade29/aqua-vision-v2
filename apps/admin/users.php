<?php
/**
 * User / Researcher Management
 * Interface for managing system users and researchers
 */

require_once __DIR__ . '/../../database/config.php';

// Initialize session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sun, 01 Jan 2014 00:00:00 GMT');

if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleUserFormSubmission($conn, $_POST);
}

// Get data for current view
$user = ($action === 'edit' && $id) ? getUserById($conn, $id) : null;
$users = getAllUsers($conn);
$researchers = getUsersByRole($conn, 'researcher');

// Helper Functions
function getAllUsers($conn) {
    $sql = "SELECT user_id, username, email, full_name, role, is_active, created_at, updated_at 
            FROM users 
            ORDER BY created_at DESC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getUsersByRole($conn, $role) {
    $stmt = $conn->prepare("SELECT user_id, username, email, full_name, role, is_active, created_at, updated_at 
                          FROM users 
                          WHERE role = ? 
                          ORDER BY created_at DESC");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getUserById($conn, $id) {
    $stmt = $conn->prepare("SELECT user_id, username, email, full_name, role, is_active, created_at, updated_at 
                          FROM users 
                          WHERE user_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function handleUserFormSubmission($conn, $data) {
    $formAction = $data['form_action'] ?? '';
    
    try {
        if ($formAction === 'add' || $formAction === 'edit') {
            handleUserSubmission($conn, $data);
        } elseif ($formAction === 'delete') {
            handleUserDelete($conn, $data);
        } elseif ($formAction === 'toggle_status') {
            handleUserToggleStatus($conn, $data);
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

function handleUserSubmission($conn, $data) {
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $fullName = trim($data['full_name'] ?? '');
    $role = $data['role'] ?? 'researcher';
    $password = $data['password'] ?? '';
    $isActive = isset($data['is_active']) ? 1 : 0;
    
    // Validation
    if (empty($username)) {
        throw new Exception('Username is required');
    }
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    if (empty($fullName)) {
        throw new Exception('Full name is required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Check for duplicate username
    $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $checkStmt->bind_param("si", $username, $userId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        throw new Exception("Username '{$username}' is already taken.");
    }
    
    // Check for duplicate email
    $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $checkStmt->bind_param("si", $email, $userId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        throw new Exception("Email '{$email}' is already registered.");
    }
    
    if ($userId > 0) {
        // Update existing user
        if (!empty($password)) {
            // Update with new password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, password_hash = ?, is_active = ? WHERE user_id = ?");
            $stmt->bind_param("sssssii", $username, $email, $fullName, $role, $passwordHash, $isActive, $userId);
        } else {
            // Update without password
            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role = ?, is_active = ? WHERE user_id = ?");
            $stmt->bind_param("ssssii", $username, $email, $fullName, $role, $isActive, $userId);
        }
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'User updated successfully';
            logActivity($conn, 'USER_UPDATE', "Updated user: {$fullName} (ID: {$userId}, Role: {$role})");
        } else {
            throw new Exception('Failed to update user: ' . $conn->error);
        }
    } else {
        // Create new user
        if (empty($password)) {
            throw new Exception('Password is required for new users');
        }
        
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, full_name, role, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $email, $fullName, $role, $passwordHash, $isActive);
        
        if ($stmt->execute()) {
            $newUserId = $conn->insert_id;
            $_SESSION['success'] = 'User created successfully';
            logActivity($conn, 'USER_CREATE', "Created new user: {$fullName} (ID: {$newUserId}, Role: {$role})");
        } else {
            throw new Exception('Failed to create user: ' . $conn->error);
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleUserDelete($conn, $data) {
    $userId = (int)($data['user_id'] ?? 0);
    
    if ($userId <= 0) {
        throw new Exception('Invalid user ID');
    }
    
    // Prevent deleting yourself
    if ($userId === $_SESSION['user_id']) {
        throw new Exception('You cannot delete your own account');
    }
    
    // Get user info before deleting
    $user = getUserById($conn, $userId);
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = 'User deleted successfully';
        logActivity($conn, 'USER_DELETE', "Deleted user: {$user['full_name']} (ID: {$userId})");
    } else {
        throw new Exception('Failed to delete user: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleUserToggleStatus($conn, $data) {
    $userId = (int)($data['user_id'] ?? 0);
    $isActive = (int)($data['is_active'] ?? 0);
    
    if ($userId <= 0) {
        throw new Exception('Invalid user ID');
    }
    
    // Prevent deactivating yourself
    if ($userId === $_SESSION['user_id'] && $isActive === 0) {
        throw new Exception('You cannot deactivate your own account');
    }
    
    $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $stmt->bind_param("ii", $isActive, $userId);
    
    if ($stmt->execute()) {
        $statusText = $isActive ? 'activated' : 'deactivated';
        $_SESSION['success'] = "User {$statusText} successfully";
        logActivity($conn, 'USER_STATUS_CHANGE', "User ID {$userId} status changed to: " . ($isActive ? 'active' : 'inactive'));
    } else {
        throw new Exception('Failed to update user status: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function getRoleBadgeColor($role) {
    switch ($role) {
        case 'admin':
            return '#dc2626'; // Red
        case 'operator':
            return '#d97706'; // Orange
        case 'researcher':
            return '#059669'; // Green
        case 'viewer':
            return '#6b7280'; // Gray
        default:
            return '#6b7280';
    }
}

/**
 * Log system activity to database
 * @param mysqli $conn Database connection
 * @param string $action Action type (e.g., 'USER_CREATE', 'USER_UPDATE', 'USER_DELETE')
 * @param string $details Human-readable description of the action
 * @return bool True on success, false on failure
 */
function logActivity($conn, $action, $details) {
    $userId = $_SESSION['user_id'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $userId, $action, $details, $ipAddress);
    return $stmt->execute();
}

// Get counts
$totalUsers = count($users);
$activeUsers = count(array_filter($users, fn($u) => $u['is_active']));
$inactiveUsers = $totalUsers - $activeUsers;
$researcherCount = count(array_filter($users, fn($u) => $u['role'] === 'researcher'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Aqua-Vision</title>
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
            --radius: 14px; --radius-sm: 8px;
            --sidebar-w: 240px;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); min-height: 100vh; }
        .main-content { margin-left: var(--sidebar-w); padding: 24px; }
        
        /* Header */
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-title { font-family: 'Space Grotesk', sans-serif; font-size: 28px; font-weight: 700; color: var(--c1); }
        .page-subtitle { font-size: 14px; color: var(--text2); margin-top: 4px; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 12px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 32px; font-weight: 700; color: var(--c1); margin-top: 8px; }
        .stat-change { font-size: 12px; color: var(--text2); margin-top: 4px; }
        
        /* Content Grid */
        .content-grid {
            display: grid; grid-template-columns: 1fr 350px; gap: 24px;
        }
        
        /* Table Card */
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-title { font-size: 16px; font-weight: 600; color: var(--c1); }
        
        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: var(--bg); padding: 12px 16px;
            text-align: left; font-size: 12px; font-weight: 600;
            color: var(--text2); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .data-table td {
            padding: 16px; border-bottom: 1px solid var(--border);
            font-size: 14px; color: var(--text);
        }
        .data-table tr:hover td { background: var(--bg); }
        .data-table tr:last-child td { border-bottom: none; }
        
        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 500;
        }
        .badge-success { background: var(--good-bg); color: var(--good); }
        .badge-danger { background: var(--crit-bg); color: var(--crit); }
        
        .role-badge {
            display: inline-flex; align-items: center;
            padding: 4px 12px; border-radius: 4px;
            font-size: 12px; font-weight: 500; color: white;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: var(--radius-sm);
            font-size: 14px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-primary:hover { background: var(--c1); }
        .btn-danger { background: var(--crit-bg); color: var(--crit); }
        .btn-danger:hover { background: var(--crit); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .icon-btn {
            width: 32px; height: 32px; border-radius: 6px;
            display: inline-flex; align-items: center; justify-content: center;
            border: none; cursor: pointer; font-size: 14px; transition: all 0.2s;
        }
        .icon-btn:hover { background: var(--bg); }
        .icon-btn.edit { color: var(--c3); }
        .icon-btn.delete { color: var(--crit); }
        
        /* Form Styles */
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block; font-size: 14px; font-weight: 500;
            color: var(--text); margin-bottom: 8px;
        }
        .form-label .required { color: var(--crit); }
        .form-input, .form-select {
            width: 100%; padding: 12px 16px;
            border: 2px solid var(--border); border-radius: var(--radius-sm);
            font-size: 14px; font-family: inherit; background: var(--surface);
        }
        .form-input:focus, .form-select:focus {
            outline: none; border-color: var(--c3);
        }
        .form-hint {
            font-size: 12px; color: var(--text3); margin-top: 6px;
        }
        
        .checkbox-wrapper {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 0;
        }
        .checkbox-wrapper input[type="checkbox"] {
            width: 20px; height: 20px; cursor: pointer;
        }
        .checkbox-wrapper label {
            font-size: 14px; color: var(--text); cursor: pointer;
        }
        
        /* User Info in Table */
        .user-info { display: flex; align-items: center; gap: 12px; }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: linear-gradient(135deg, var(--c2), var(--c3));
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 16px;
        }
        .user-details { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: var(--c1); }
        .user-email { font-size: 12px; color: var(--text3); }
        
        /* Empty State */
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state-icon {
            font-size: 48px; margin-bottom: 16px;
        }
        .empty-state-title {
            font-size: 18px; font-weight: 600; color: var(--c1);
            margin-bottom: 8px;
        }
        .empty-state-text {
            font-size: 14px; color: var(--text2);
        }
        
        /* Actions */
        .actions { display: flex; gap: 8px; }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .content-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../assets/navigation.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">User Management</h1>
                <p class="page-subtitle">Manage researchers, operators, and system users</p>
            </div>
            <?php if ($action === 'list'): ?>
                <a href="?action=add" class="btn btn-primary">
                    <span>+</span> Add New User
                </a>
            <?php endif; ?>
        </div>
        
        <?php if ($action === 'list'): ?>
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?= $totalUsers ?></div>
                    <div class="stat-change">All system accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Users</div>
                    <div class="stat-value" style="color: var(--good);"><?= $activeUsers ?></div>
                    <div class="stat-change">Currently active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Researchers</div>
                    <div class="stat-value" style="color: var(--c3);"><?= $researcherCount ?></div>
                    <div class="stat-change">Research personnel</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Inactive</div>
                    <div class="stat-value" style="color: var(--crit);"><?= $inactiveUsers ?></div>
                    <div class="stat-change">Deactivated accounts</div>
                </div>
            </div>
            
            <!-- Users Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">All Users</h2>
                </div>
                
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">👥</div>
                        <div class="empty-state-title">No users found</div>
                        <div class="empty-state-text">Get started by adding your first user.</div>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?= htmlspecialchars($u['full_name']) ?></span>
                                                <span class="user-email"><?= htmlspecialchars($u['email']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge" style="background: <?= getRoleBadgeColor($u['role']) ?>">
                                            <?= ucfirst($u['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <span class="badge badge-success">● Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">● Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                    <td>
                                        <div class="actions">
                                            <a href="?action=edit&id=<?= $u['user_id'] ?>" class="icon-btn edit" title="Edit">✏️</a>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                <input type="hidden" name="form_action" value="delete">
                                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                                <button type="submit" class="icon-btn delete" title="Delete">🗑️</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <!-- Add/Edit Form -->
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><?= $action === 'add' ? 'Add New User' : 'Edit User' ?></h2>
                    </div>
                    <div style="padding: 24px;">
                        <form method="POST" action="">
                            <input type="hidden" name="form_action" value="<?= $action ?>">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="user_id" value="<?= $user['user_id'] ?? '' ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label class="form-label">Full Name <span class="required">*</span></label>
                                <input type="text" name="full_name" class="form-input" 
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label class="form-label">Username <span class="required">*</span></label>
                                    <input type="text" name="username" class="form-input" 
                                           value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email <span class="required">*</span></label>
                                    <input type="email" name="email" class="form-input" 
                                           value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label class="form-label">Role <span class="required">*</span></label>
                                    <select name="role" class="form-select" required>
                                        <option value="researcher" <?= ($user['role'] ?? '') === 'researcher' ? 'selected' : '' ?>>Researcher</option>
                                        <option value="operator" <?= ($user['role'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator</option>
                                        <option value="viewer" <?= ($user['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                        <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option>
                                    </select>
                                    <p class="form-hint">Researchers can view and analyze data</p>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">
                                        Password <?= $action === 'add' ? '<span class="required">*</span>' : '(leave blank to keep current)' ?>
                                    </label>
                                    <input type="password" name="password" class="form-input" 
                                           <?= $action === 'add' ? 'required' : '' ?>>
                                    <?php if ($action === 'add'): ?>
                                        <p class="form-hint">Minimum 6 characters recommended</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="checkbox-wrapper">
                                <input type="checkbox" id="is_active" name="is_active" value="1" 
                                       <?= ($user['is_active'] ?? 1) ? 'checked' : '' ?>>
                                <label for="is_active">Account is active and can log in</label>
                            </div>
                            
                            <div style="display: flex; gap: 12px; margin-top: 24px;">
                                <button type="submit" class="btn btn-primary">
                                    <?= $action === 'add' ? 'Create User' : 'Save Changes' ?>
                                </button>
                                <a href="?action=list" class="btn" style="background: var(--bg); color: var(--text);">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sidebar Info -->
                <div>
                    <div class="card" style="margin-bottom: 20px;">
                        <div class="card-header">
                            <h3 class="card-title">Role Permissions</h3>
                        </div>
                        <div style="padding: 20px; font-size: 14px; color: var(--text2);">
                            <div style="margin-bottom: 16px;">
                                <strong style="color: var(--c1); display: block; margin-bottom: 4px;">Administrator</strong>
                                Full access to all features including user management
                            </div>
                            <div style="margin-bottom: 16px;">
                                <strong style="color: var(--c1); display: block; margin-bottom: 4px;">Researcher</strong>
                                View all data, generate reports, analyze sensor readings
                            </div>
                            <div style="margin-bottom: 16px;">
                                <strong style="color: var(--c1); display: block; margin-bottom: 4px;">Operator</strong>
                                Manage devices, acknowledge alerts, view operational data
                            </div>
                            <div>
                                <strong style="color: var(--c1); display: block; margin-bottom: 4px;">Viewer</strong>
                                Read-only access to view data and reports
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($action === 'edit' && $user): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Account Info</h3>
                            </div>
                            <div style="padding: 20px; font-size: 14px; color: var(--text2);">
                                <div style="margin-bottom: 12px;">
                                    <strong style="color: var(--text3);">Created:</strong><br>
                                    <?= date('F d, Y \a\t H:i', strtotime($user['created_at'])) ?>
                                </div>
                                <div>
                                    <strong style="color: var(--text3);">Last Updated:</strong><br>
                                    <?= date('F d, Y \a\t H:i', strtotime($user['updated_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Display toast notifications for session messages
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
