<?php
/**
 * Aqua-Vision — Operator Navigation Sidebar
 * Location: apps/operator/operator_nav.php
 */
$currentPage = $currentPage ?? 'operator-dashboard';

// Simple absolute path to logo from document root
$logoSrc = '/Aqua-Vision-v2/assets/logo.png?v=2';
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Space+Grotesk:wght@400;500;600&display=swap');

  :root {
    --c1:          #0F2854;
    --c2:          #1C4D8D;
    --c3:          #4988C4;
    --c4:          #BDE8F5;
    --c4-soft:     rgba(189,232,245,0.13);
    --c4-hover:    rgba(189,232,245,0.20);
    --operator:    #0891b2;
    --operator-bg:  rgba(8, 145, 178, 0.13);
    --sidebar-w:   240px;
    --radius:      14px;
    --radius-sm:   8px;
    --transition:  0.22s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .av-sidebar {
    width: var(--sidebar-w);
    min-height: 100vh;
    background: linear-gradient(180deg, #0F2854 0%, #0a1f42 100%);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    border-right: 1px solid rgba(189,232,245,0.08);
    z-index: 100;
    font-family: 'DM Sans', sans-serif;
    overflow-y: auto;
  }

  .av-sidebar::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%234988C4' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
  }

  .av-logo-area {
    padding: 18px 16px 16px;
    border-bottom: 1px solid rgba(189,232,245,0.08);
    position: relative;
  }

  .av-logo-row {
    display: flex;
    align-items: center;
    gap: 11px;
  }

  .av-logo-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    background: transparent;
    border: 1.5px solid rgba(189,232,245,0.15);
    padding: 0;
    position: relative;
    z-index: 1;
  }

  .av-logo-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 50%;
  }

  .av-logo-text {
    display: flex;
    flex-direction: column;
    line-height: 1;
  }

  .av-logo-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--c4);
    letter-spacing: 0.02em;
  }

  .av-logo-sub {
    font-size: 10px;
    color: rgba(189,232,245,0.45);
    font-weight: 400;
    margin-top: 3px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .av-status-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    background: rgba(189,232,245,0.07);
    border: 1px solid rgba(189,232,245,0.12);
    border-radius: 20px;
    padding: 5px 10px;
    width: fit-content;
  }

  .av-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #5cd67e;
    box-shadow: 0 0 0 2px rgba(92,214,126,0.3);
    animation: av-pulse 2s infinite;
  }

  @keyframes av-pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(92,214,126,0.30); }
    50%       { box-shadow: 0 0 0 4px rgba(92,214,126,0.15); }
  }

  .av-status-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(189,232,245,0.7);
    letter-spacing: 0.04em;
  }

  .av-role-badge {
    margin-top: 8px;
    padding: 4px 12px;
    background: var(--operator-bg);
    border: 1px solid var(--operator);
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    color: var(--operator);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    width: fit-content;
  }

  .av-nav-section {
    padding: 16px 12px 4px;
    flex: 1;
    position: relative;
  }

  .av-section-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(189,232,245,0.3);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    padding: 0 8px;
    margin-bottom: 4px;
  }

  .av-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition), transform var(--transition);
    position: relative;
    margin-bottom: 1px;
    user-select: none;
    text-decoration: none;
    border: 1px solid transparent;
  }

  .av-nav-item:hover { background: var(--c4-hover); }
  .av-nav-item:active { transform: scale(0.98); }

  .av-nav-item.active {
    background: linear-gradient(90deg, rgba(73,136,196,0.30), rgba(73,136,196,0.10));
    border-color: rgba(73,136,196,0.30);
  }

  .av-nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 3px;
    background: var(--c4);
    border-radius: 0 4px 4px 0;
  }

  .av-nav-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: rgba(189,232,245,0.06);
    transition: background var(--transition);
  }

  .av-nav-item.active .av-nav-icon { background: rgba(73,136,196,0.35); }
  .av-nav-icon svg { width: 14px; height: 14px; }

  .av-nav-label-wrap { flex: 1; }

  .av-nav-label {
    font-size: 13px;
    font-weight: 500;
    color: rgba(189,232,245,0.65);
    transition: color var(--transition);
    line-height: 1;
  }

  .av-nav-sublabel { font-size: 10px; color: rgba(189,232,245,0.30); margin-top: 2px; }
  .av-nav-item.active .av-nav-label { color: var(--c4); }

  .av-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 10px;
    background: #e85555;
    color: #fff;
    min-width: 18px;
    text-align: center;
    line-height: 14px;
  }

  .av-badge.info {
    background: rgba(73,136,196,0.40);
    color: var(--c4);
  }

  .av-nav-divider {
    height: 1px;
    background: rgba(189,232,245,0.07);
    margin: 10px 12px;
  }

  .av-user-footer {
    padding: 12px;
    border-top: 1px solid rgba(189,232,245,0.07);
    position: relative;
  }

  .av-user-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition);
  }

  .av-user-card:hover { background: var(--c4-soft); }

  .av-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--operator), var(--c3));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--c4);
    border: 1.5px solid rgba(189,232,245,0.20);
    flex-shrink: 0;
  }

  .av-user-info { flex: 1; }
  .av-user-name { font-size: 12px; font-weight: 500; color: rgba(189,232,245,0.80); }
  .av-user-role { font-size: 10px; color: rgba(189,232,245,0.35); margin-top: 1px; }

  .av-user-menu-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    opacity: 0.4;
    width: 20px;
    height: 20px;
  }

  .av-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--c4); }

  body { margin-left: var(--sidebar-w); }
</style>

<nav class="av-sidebar" id="av-sidebar" aria-label="Operator navigation">
  <div class="av-logo-area">
    <div class="av-logo-row">
      <div class="av-logo-icon">
        <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>"
             alt="Aqua-Vision logo"
             onerror="this.style.display='none'">
      </div>
      <div class="av-logo-text">
        <span class="av-logo-title">Aqua-Vision</span>
        <span class="av-logo-sub">Operations Center</span>
      </div>
    </div>
    <div class="av-role-badge">🔧 Operator</div>
    <div class="av-status-pill" role="status" aria-live="polite">
      <div class="av-status-dot"></div>
      <span class="av-status-label">Monitoring Active</span>
    </div>
  </div>

  <div class="av-nav-section">
    <div class="av-section-label" aria-hidden="true">Operations</div>

    <a href="/Aqua-Vision/apps/operator/dashboard.php"
       class="av-nav-item <?= $currentPage === 'operator-dashboard' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'operator-dashboard' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <rect x="1" y="1" width="6" height="6" rx="1.5" fill="#BDE8F5"/>
          <rect x="9" y="1" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
          <rect x="1" y="9" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
          <rect x="9" y="9" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Dashboard</div>
        <div class="av-nav-sublabel">Manage devices & alerts</div>
      </div>
    </a>

    <a href="/Aqua-Vision/apps/operator/devices.php"
       class="av-nav-item <?= $currentPage === 'devices' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'devices' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <rect x="2" y="4" width="12" height="8" rx="1.5" stroke="#4988C4" stroke-width="1.4"/>
          <path d="M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1" stroke="#4988C4" stroke-width="1.4"/>
          <circle cx="8" cy="8" r="1.5" fill="#4988C4" opacity="0.6"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Devices</div>
        <div class="av-nav-sublabel">Maintenance & repairs</div>
      </div>
    </a>

    <a href="/Aqua-Vision/apps/operator/activitylog.php"
       class="av-nav-item <?= $currentPage === 'activity' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'activity' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="#4988C4" stroke-width="1.4"/>
          <path d="M8 5v3.5l2.5 1.5" stroke="#4988C4" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">My Activity</div>
        <div class="av-nav-sublabel">Your work history</div>
      </div>
    </a>

    <div class="av-nav-divider" role="separator"></div>
  </div>

  <div class="av-user-footer">
    <a href="/Aqua-Vision/logout.php" class="av-user-card" role="button" tabindex="0" aria-label="Logout" onclick="return confirm('Are you sure you want to logout?');">
      <div class="av-avatar" aria-hidden="true"><?= strtoupper(substr($_SESSION['user_name'] ?? 'O', 0, 1)) ?></div>
      <div class="av-user-info">
        <div class="av-user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Operator') ?></div>
        <div class="av-user-role"><?= ucfirst($_SESSION['user_role'] ?? 'Operator') ?> • Click to logout</div>
      </div>
      <div class="av-user-menu-btn" aria-hidden="true">
        <div class="av-dot"></div>
        <div class="av-dot"></div>
        <div class="av-dot"></div>
      </div>
    </a>
  </div>
</nav>

<!-- Toast Notifications -->
<?php include __DIR__ . '/../../assets/toast.php'; ?>
