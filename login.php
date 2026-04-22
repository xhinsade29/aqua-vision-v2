<?php
/**
 * Aqua-Vision Login Page
 * Location: login.php
 */

session_start();

// If already logged in, redirect based on role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'operator') {
        header('Location: apps/operator/dashboard.php');
    } else {
        header('Location: apps/admin/dashboard.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'database/config.php';
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // Check user credentials
        $stmt = $conn->prepare("
            SELECT user_id, username, email, password_hash, full_name, role, is_active
            FROM users 
            WHERE username = ? OR email = ?
        ");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($user['is_active'] && password_verify($password, $user['password_hash'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];
                
                // Set success message for toast
                $_SESSION['success'] = "Welcome back, {$user['full_name']}!";
                
                // Redirect based on role
                if ($user['role'] === 'operator') {
                    header('Location: apps/operator/dashboard.php');
                } else {
                    header('Location: apps/admin/dashboard.php');
                }
                exit();
            } else {
                if (!$user['is_active']) {
                    $error = 'Account is inactive. Please contact administrator.';
                } else {
                    $error = 'Invalid password';
                }
            }
        } else {
            $error = 'User not found';
        }
        
        $stmt->close();
    }
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Aqua-Vision</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --c1:#0F2854;--c2:#1C4D8D;--c3:#4988C4;--c4:#BDE8F5;
      --bg:#f0f5fb;--surface:#fff;--border:rgba(15,40,84,.08);
      --text:#0F2854;--text2:#4a6080;--text3:#8aa0bc;
      --good:#16a34a;--good-bg:#dcfce7;
      --warn:#d97706;--warn-bg:#fef3c7;
      --crit:#dc2626;--crit-bg:#fee2e2;
      --info-bg:#eff6ff;
    }
    body{font-family:'DM Sans',sans-serif;background:linear-gradient(135deg,var(--c1) 0%,var(--c2) 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .login-container{background:var(--surface);border-radius:16px;box-shadow:0 20px 40px rgba(15,40,84,.15);overflow:hidden;width:100%;max-width:420px}
    .login-header{background:linear-gradient(135deg,var(--c1),var(--c2));padding:40px 30px;text-align:center}
    .logo-img{width:80px;height:80px;margin-bottom:16px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.3);box-shadow:0 4px 15px rgba(0,0,0,.2)}
    .logo{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:#fff;margin-bottom:8px}
    .logo-sub{color:var(--c4);font-size:14px}
    .login-body{padding:40px 30px}
    .form-group{margin-bottom:24px}
    .form-label{display:block;font-weight:500;color:var(--text);margin-bottom:8px;font-size:14px}
    .form-input{width:100%;padding:12px 16px;border:2px solid var(--border);border-radius:8px;font-size:14px;transition:border-color .2s}
    .form-input:focus{outline:none;border-color:var(--c2)}
    .btn{width:100%;padding:14px 24px;background:var(--c2);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;transition:background .2s}
    .btn:hover{background:var(--c1)}
    .alert{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px}
    .alert-error{background:var(--crit-bg);color:var(--crit);border:1px solid rgba(220,38,38,.2)}
    .info-text{margin-top:24px;padding:16px;background:var(--info-bg);border-radius:8px;font-size:13px;color:var(--text2)}
    .info-text strong{color:var(--c1)}
  </style>
</head>
<body>
<div class="login-container">
  <div class="login-header">
    <img src="assets/logo.png" alt="Aqua-Vision Logo" class="logo-img">
    <div class="logo">Aqua-Vision</div>
    <div class="logo-sub">River Water Quality Monitoring</div>
  </div>
  
  <div class="login-body">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
      <div class="form-group">
        <label class="form-label" for="username">Username or Email</label>
        <input type="text" id="username" name="username" class="form-input" 
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
      </div>
      
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" class="form-input" required>
      </div>
      
      <button type="submit" class="btn">Sign In</button>
    </form>
  </div>
</div>

<!-- Toast Notifications -->
<?php include 'assets/toast.php'; ?>

<?php if ($error): ?>
<script>showToast(<?= json_encode($error) ?>, 'error', 5000);</script>
<?php endif; ?>

<?php if (isset($_SESSION['logout_message'])): ?>
<script>showToast(<?= json_encode($_SESSION['logout_message']) ?>, 'info', 4000);</script>
<?php unset($_SESSION['logout_message']); endif; ?>
</body>
</html>
