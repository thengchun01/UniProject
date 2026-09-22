<?php
    require_once __DIR__ . '/config.php';
    $currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(SITE_NAME); ?></title>

<link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/style.css'; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/user-widget.css'; ?>">
<link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/piano.css'; ?>">

<script src="https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tonejs/midi@2.0.28"></script>
<script src="https://cdn.jsdelivr.net/npm/vexflow/build/cjs/vexflow.js"></script>
<script>
window.PSM_CONFIG = {
    isLoggedIn: <?php echo is_logged_in() ? 'true' : 'false'; ?>,
    apiBase: "<?php echo e(url_path('api')); ?>"
};
</script>
</head>

<body>

<nav class="topnav">
    <div class="logo">PIANO LOGO</div>
    
    <!-- Mobile menu toggle button -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Menu">☰</button>
    
    <div class="nav-links" id="navLinks">
        <a href="<?php echo BASE_URL . 'index.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'class="active"' : ''; ?>>Home</a>
        <a href="<?php echo BASE_URL . 'tutorial.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'tutorial.php' ? 'class="active"' : ''; ?>>Tutorial</a>
        <a href="<?php echo BASE_URL . 'songs.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'songs.php' ? 'class="active"' : ''; ?>>Songs</a>
        <a href="<?php echo BASE_URL . 'piano.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'piano.php' ? 'class="active"' : ''; ?>>Piano</a>
        <a href="<?php echo BASE_URL . 'game.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'game.php' ? 'class="active"' : ''; ?>>Game</a>
        <a href="<?php echo BASE_URL . 'account.php'; ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'account.php' ? 'class="active"' : ''; ?>>Account</a>
        <?php if ($currentUser): ?>
            <a href="<?php echo BASE_URL . 'logout.php'; ?>" data-logout-confirm>Logout</a>
        <?php endif; ?>
    </div>
</nav>

<?php
// ── Floating User Widget ──────────────────────────────────────────────────────
// Suppress on the account page (user already sees full profile there)
$_ufw_page = basename($_SERVER['PHP_SELF']);
$_ufw_hide = in_array($_ufw_page, ['account.php', 'login.php', 'register.php'], true);

if (!$_ufw_hide):
    // XP leveling formula: Level = 1 + floor(sqrt(XP/100))
    // XP needed to reach level L = (L-1)^2 * 100
    // XP needed to reach level L+1 = L^2 * 100
    $_ufw_user = $currentUser;
    if ($_ufw_user):
        $_ufw_xp    = (int)($_ufw_user['experience'] ?? 0);
        $_ufw_level = (int)($_ufw_user['level'] ?? 1);
        $_ufw_role  = ucfirst(strtolower($_ufw_user['role'] ?? 'student'));
        $_ufw_name  = $_ufw_user['username'] ?? 'User';

        // initials (up to 2 chars)
        $words = array_filter(explode(' ', trim($_ufw_name)));
        $_ufw_initials = strtoupper(
            count($words) >= 2
                ? mb_substr($words[0],0,1) . mb_substr(end($words),0,1)
                : mb_substr($_ufw_name, 0, 2)
        );

        // XP thresholds for current level
        $_ufw_xp_start = pow($_ufw_level - 1, 2) * 100;
        $_ufw_xp_next  = pow($_ufw_level, 2) * 100;
        $_ufw_xp_range = max(1, $_ufw_xp_next - $_ufw_xp_start);
        $_ufw_xp_into  = max(0, $_ufw_xp - $_ufw_xp_start);
        $_ufw_xp_pct   = round(min(100, ($_ufw_xp_into / $_ufw_xp_range) * 100), 1);
        $_ufw_xp_label = $_ufw_xp_into . ' / ' . $_ufw_xp_range . ' XP';
?>
<div id="user-float-widget"
     data-xp-pct="<?= $_ufw_xp_pct ?>"
     data-logged-in="true">

    <!-- Collapsed bubble -->
    <div class="ufw-bubble" title="<?= e($_ufw_name) ?>">
        <svg class="ufw-ring" viewBox="0 0 72 72">
            <circle class="ufw-ring-bg"  cx="36" cy="36" r="32"/>
            <circle class="ufw-ring-fill" cx="36" cy="36" r="32"/>
        </svg>
        <span class="ufw-initials"><?= e($_ufw_initials) ?></span>
    </div>

    <!-- Expanded panel -->
    <div class="ufw-panel">

        <!-- Header -->
        <div class="ufw-panel-header">
            <div class="ufw-avatar-lg"><?= e($_ufw_initials) ?></div>
            <div class="ufw-name-block">
                <div class="ufw-username"><?= e($_ufw_name) ?></div>
                <span class="ufw-role-badge"><?= e($_ufw_role) ?></span>
            </div>
        </div>

        <!-- XP / Level -->
        <div class="ufw-xp-section">
            <div class="ufw-level-row">
                <span class="ufw-level-label">Level</span>
                <span class="ufw-level-value">⭐ <?= $_ufw_level ?></span>
            </div>
            <div class="ufw-xp-bar-track">
                <div class="ufw-xp-bar-fill"></div>
            </div>
            <div class="ufw-xp-label"><?= e($_ufw_xp_label) ?></div>
        </div>

        <div class="ufw-divider"></div>

        <!-- Actions -->
        <div class="ufw-actions">
            <a href="<?= BASE_URL ?>index.php" class="ufw-action-btn">
                <span class="ufw-icon">🏠</span> Home
            </a>
            <a href="<?= BASE_URL ?>account.php" class="ufw-action-btn">
                <span class="ufw-icon">👤</span> My Profile
            </a>
            <a href="<?= BASE_URL ?>tutorial.php" class="ufw-action-btn">
                <span class="ufw-icon">🎹</span> Tutorials
            </a>
            <div class="ufw-divider" style="margin:4px -18px 4px;"></div>
            <a href="<?= BASE_URL ?>logout.php" class="ufw-action-btn danger" data-logout-confirm>
                <span class="ufw-icon">🚪</span> Log out
            </a>
        </div>

    </div>
</div>

<?php else: // Guest widget ?>
<div id="user-float-widget"
     data-xp-pct="0"
     data-logged-in="false">

    <!-- Collapsed bubble -->
    <div class="ufw-bubble guest" title="Sign in">
        <svg class="ufw-ring" viewBox="0 0 72 72">
            <circle class="ufw-ring-bg" cx="36" cy="36" r="32"/>
        </svg>
        <span class="ufw-initials">?</span>
    </div>

    <!-- Guest panel -->
    <div class="ufw-panel guest-panel">
        <span class="ufw-guest-icon">🎵</span>
        <div class="ufw-guest-title">Join Piano Course</div>
        <div class="ufw-guest-sub">Sign in to track progress, earn XP and climb the rankings!</div>
        <a href="<?= BASE_URL ?>account.php?mode=login" class="ufw-login-btn">Log In</a>
        <a href="<?= BASE_URL ?>account.php?mode=register" class="ufw-register-link">Create a free account</a>
    </div>

</div>
<?php
    endif; // logged in vs guest
endif; // !$_ufw_hide
?>

<?php if ($currentUser): ?>
<dialog class="logout-confirm-dialog" id="logoutConfirmDialog" aria-labelledby="logoutConfirmTitle">
    <div class="logout-confirm-content">
        <p class="logout-confirm-eyebrow">ACCOUNT</p>
        <h2 id="logoutConfirmTitle">Log out?</h2>
        <p>Are you sure you want to end this session?</p>
        <div class="logout-confirm-actions">
            <button class="logout-confirm-cancel" type="button" data-logout-cancel>Stay Logged In</button>
            <a class="logout-confirm-submit" href="<?= e(url_path('logout.php')); ?>">Log Out</a>
        </div>
    </div>
</dialog>
<?php endif; ?>

<!-- Mobile menu toggle JavaScript -->
<script>
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const navLinks = document.getElementById('navLinks');
    
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            navLinks.classList.toggle('show');
            // Change toggle icon
            this.textContent = navLinks.classList.contains('show') ? '✕' : '☰';
        });
    }
    
    // Close menu when clicking a link on mobile
    const navItems = document.querySelectorAll('.nav-links a');
    navItems.forEach(item => {
        item.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                navLinks.classList.remove('show');
                if (mobileMenuToggle) mobileMenuToggle.textContent = '☰';
            }
        });
    });
</script>

<div class="container site-content">
<?php if (!is_database_connected()): ?>
    <div class="alert error">
        Database connection to PSM is not ready. Import <strong>database/psm_schema.sql</strong> and check the database settings in <strong>includes/config.php</strong>.
    </div>
<?php endif; ?>
