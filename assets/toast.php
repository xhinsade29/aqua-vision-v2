<?php
/**
 * Toast Notification Component
 * Include this file in pages that need toast notifications
 * Usage: 
 *   include 'toast.php';
 *   showToast('message', 'success|error|warning|info', duration_ms);
 */
?>
<style>
/* Toast Container */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 400px;
}

/* Toast Item */
.toast {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    border-radius: 12px;
    background: white;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    border-left: 4px solid;
    animation: toastSlideIn 0.3s ease-out;
    transform-origin: right;
    min-width: 300px;
    max-width: 400px;
}

.toast.success { border-left-color: #059669; background: #f0fdf4; }
.toast.error { border-left-color: #dc2626; background: #fef2f2; }
.toast.warning { border-left-color: #d97706; background: #fffbeb; }
.toast.info { border-left-color: #3b82f6; background: #eff6ff; }

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(100%) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

@keyframes toastSlideOut {
    from {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
    to {
        opacity: 0;
        transform: translateX(100%) scale(0.9);
    }
}

.toast.hiding {
    animation: toastSlideOut 0.3s ease-in forwards;
}

.toast-icon {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 14px;
}

.toast.success .toast-icon { background: #059669; color: white; }
.toast.error .toast-icon { background: #dc2626; color: white; }
.toast.warning .toast-icon { background: #d97706; color: white; }
.toast.info .toast-icon { background: #3b82f6; color: white; }

.toast-content {
    flex: 1;
    min-width: 0;
}

.toast-title {
    font-weight: 600;
    font-size: 14px;
    color: #111827;
    margin-bottom: 2px;
}

.toast.success .toast-title { color: #059669; }
.toast.error .toast-title { color: #dc2626; }
.toast.warning .toast-title { color: #d97706; }
.toast.info .toast-title { color: #3b82f6; }

.toast-message {
    font-size: 13px;
    color: #4b5563;
    line-height: 1.4;
}

.toast-close {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 4px;
    color: #9ca3af;
    transition: all 0.2s;
    flex-shrink: 0;
    font-size: 16px;
    line-height: 1;
}

.toast-close:hover {
    color: #4b5563;
    background: rgba(0,0,0,0.05);
}

/* Toast Progress Bar */
.toast-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: rgba(0,0,0,0.1);
    border-radius: 0 0 0 12px;
    animation: toastProgress linear;
}

.toast.success .toast-progress { background: #059669; }
.toast.error .toast-progress { background: #dc2626; }
.toast.warning .toast-progress { background: #d97706; }
.toast.info .toast-progress { background: #3b82f6; }

@keyframes toastProgress {
    from { width: 100%; }
    to { width: 0%; }
}
</style>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container"></div>

<script>
const ToastIcons = {
    success: '✓',
    error: '✕',
    warning: '⚠',
    info: 'ℹ'
};

const ToastTitles = {
    success: 'Success',
    error: 'Error',
    warning: 'Warning',
    info: 'Info'
};

/**
 * Show a toast notification
 * @param {string} message - The message to display
 * @param {string} type - success, error, warning, info
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    toast.innerHTML = `
        <div class="toast-icon">${ToastIcons[type] || 'ℹ'}</div>
        <div class="toast-content">
            <div class="toast-title">${ToastTitles[type] || 'Info'}</div>
            <div class="toast-message">${message}</div>
        </div>
        <div class="toast-close" onclick="hideToast(this.parentElement)">×</div>
        <div class="toast-progress" style="animation-duration: ${duration}ms;"></div>
    `;
    
    container.appendChild(toast);
    
    // Auto hide after duration
    setTimeout(() => hideToast(toast), duration);
}

/**
 * Hide a toast notification
 * @param {HTMLElement} toast - The toast element to hide
 */
function hideToast(toast) {
    if (!toast || toast.classList.contains('hiding')) return;
    
    toast.classList.add('hiding');
    setTimeout(() => {
        if (toast.parentElement) {
            toast.parentElement.removeChild(toast);
        }
    }, 300);
}

// PHP session-based toast support
<?php if (isset($_SESSION['toast'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($_SESSION['toast'] as $toast): ?>
    showToast(
        <?= json_encode($toast['message']) ?>,
        <?= json_encode($toast['type']) ?>,
        <?= $toast['duration'] ?? 5000 ?>
    );
    <?php endforeach; ?>
});
<?php unset($_SESSION['toast']); endif; ?>
</script>
