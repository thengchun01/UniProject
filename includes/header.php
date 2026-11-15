<?php
    require_once __DIR__ . '/config.php';
    $currentUser = current_user();
    // Server-synced personal settings (one cheap PK lookup; null when logged
    // out, on DB error, or when the user_settings migration is not applied).
    $serverSettingsJson = 'null';
    if ($currentUser && is_database_connected()) {
        try {
            $settingsStmt = db()->prepare('SELECT settings_json FROM user_settings WHERE user_id = :id');
            $settingsStmt->execute(['id' => (int) $currentUser['user_id']]);
            $settingsRow = $settingsStmt->fetch(PDO::FETCH_ASSOC);
            $settingsData = json_decode($settingsRow['settings_json'] ?? '', true);
            if (is_array($settingsData)) {
                $serverSettingsJson = json_encode($settingsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            }
        } catch (Throwable $e) {
            $serverSettingsJson = 'null';
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>
(function(){try{var t=localStorage.getItem("psm-theme");if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(SITE_NAME); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo e(BASE_URL . 'assets/images/piano-icon.svg'); ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(BASE_URL . 'assets/images/favicon-32.png?v=' . filemtime(__DIR__ . '/../assets/images/favicon-32.png')); ?>">
<link rel="apple-touch-icon" href="<?php echo e(BASE_URL . 'assets/images/apple-touch-icon.png?v=' . filemtime(__DIR__ . '/../assets/images/apple-touch-icon.png')); ?>">

<link rel="stylesheet" href="<?php echo e(BASE_URL . 'assets/css/style.css?v=' . filemtime(__DIR__ . '/../assets/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo e(BASE_URL . 'assets/css/user-widget.css?v=' . filemtime(__DIR__ . '/../assets/css/user-widget.css')); ?>">
<link rel="stylesheet" href="<?php echo e(BASE_URL . 'assets/css/piano.css?v=' . filemtime(__DIR__ . '/../assets/css/piano.css')); ?>">

<script src="https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tonejs/midi@2.0.28"></script>
<script src="https://cdn.jsdelivr.net/npm/vexflow/build/cjs/vexflow.js"></script>
<script>
window.PSM_CONFIG = {
    isLoggedIn: <?php echo is_logged_in() ? 'true' : 'false'; ?>,
    apiBase: "<?php echo e(url_path('api')); ?>",
    userSettings: <?php echo $serverSettingsJson; ?>
};
</script>
</head>

<body>

<?php
$navPage = basename($_SERVER['PHP_SELF'] ?? '');
$navRole = normalize_role($currentUser['role'] ?? 'GUEST');
$workspace = 'discover';
if (in_array($navPage, ['manage.php', 'schedule.php', 'transactions.php', 'courses.php', 'users.php'], true) || ($navPage === 'account.php' && $currentUser)) {
    $workspace = 'manage';
} elseif (in_array($navPage, ['studio.php', 'tutorial.php', 'lesson.php', 'songs.php', 'piano.php', 'game.php', 'car_race.php', 'rankings.php'], true)) {
    $workspace = 'studio';
}
$workspaceLinks = [
    'discover' => [
        ['label' => 'Home', 'path' => 'index.php', 'pages' => ['index.php']],
    ],
    'manage' => [
        ['label' => 'Overview', 'path' => 'manage.php', 'pages' => ['manage.php']],
        ['label' => 'Schedule', 'path' => 'schedule.php', 'pages' => ['schedule.php']],
        ['label' => 'Transactions', 'path' => 'transactions.php', 'pages' => ['transactions.php']],
    ],
    'studio' => [
        ['label' => 'Overview', 'path' => 'studio.php', 'pages' => ['studio.php']],
        ['label' => 'Tutorials', 'path' => 'tutorial.php', 'pages' => ['tutorial.php', 'lesson.php']],
        ['label' => 'Songs', 'path' => 'songs.php', 'pages' => ['songs.php']],
        ['label' => 'Piano', 'path' => 'piano.php', 'pages' => ['piano.php']],
        ['label' => 'Games', 'path' => 'game.php', 'pages' => ['game.php', 'car_race.php', 'rankings.php']],
    ],
];
if ($currentUser && $navRole === 'ADMIN') {
    $workspaceLinks['manage'][] = ['label' => 'Courses', 'path' => 'courses.php', 'pages' => ['courses.php']];
    $workspaceLinks['manage'][] = ['label' => 'Users', 'path' => 'admin/users.php', 'pages' => ['users.php']];
}
$workspaceLinks[$workspace][] = ['label' => $currentUser ? 'Account' : 'Log in', 'path' => $currentUser ? 'account.php' : 'account.php?mode=login', 'pages' => ['account.php']];
?>
<nav class="topnav">
    <a class="logo" href="<?= e(url_path($workspace === 'manage' ? 'manage.php' : ($workspace === 'studio' ? 'studio.php' : 'index.php'))) ?>">
        <img src="<?= e(BASE_URL . 'assets/images/piano-icon.svg') ?>" alt="" width="28" height="28">
        <span><?= e(SITE_NAME) ?></span>
    </a>

    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation" aria-expanded="false">☰</button>

    <div class="nav-main" id="navMain">
        <div class="nav-links" id="navLinks">
            <?php foreach ($workspaceLinks[$workspace] as $navItem): ?>
                <a href="<?= e(url_path($navItem['path'])) ?>" <?= in_array($navPage, $navItem['pages'], true) ? 'class="active"' : '' ?>><?= e($navItem['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($currentUser): ?>
                <a href="<?= e(url_path('logout.php')) ?>" data-logout-confirm>Logout</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($currentUser): ?>
    <div class="workspace-switcher" aria-label="Workspaces">
        <a href="<?= e(url_path('index.php')) ?>" class="<?= $workspace === 'discover' ? 'active' : '' ?>">Discover</a>
        <a href="<?= e(url_path('manage.php')) ?>" class="<?= $workspace === 'manage' ? 'active' : '' ?>">Manage</a>
        <a href="<?= e(url_path('studio.php')) ?>" class="<?= $workspace === 'studio' ? 'active' : '' ?>">Studio</a>
    </div>
    <?php endif; ?>

    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark theme" title="Switch theme" aria-pressed="false">
        <span class="theme-toggle-icon" aria-hidden="true">☾</span>
    </button>
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
            <a href="<?= BASE_URL ?>schedule.php" class="ufw-action-btn">
                <span class="ufw-icon">📅</span> Schedule
            </a>
            <a href="<?= BASE_URL ?>transactions.php" class="ufw-action-btn">
                <span class="ufw-icon">💳</span> Transactions
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
        <div class="ufw-guest-title">Join Tasto</div>
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
    const navMain = document.getElementById('navMain');
    
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function() {
            navLinks.classList.toggle('show');
            navMain.classList.toggle('show');
            this.setAttribute('aria-expanded', navMain.classList.contains('show') ? 'true' : 'false');
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
                navMain.classList.remove('show');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
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
