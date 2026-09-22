<?php
require_once __DIR__ . '/includes/config.php';

$user = current_user();
$authError = null;
$authMode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';

$loginUsername = trim($_POST['login_username'] ?? '');
$registerUsername = trim($_POST['register_username'] ?? '');
$registerEmail = trim($_POST['register_email'] ?? '');

$summary = [
    'completed_sections' => 0,
    'total_sections' => 0,
    'session_count' => 0,
    'avg_score' => 0,
    'avg_accuracy' => 0,
    'best_score' => 0,
    'avg_timing_ms' => 0,
    'midi_count' => 0,
    'activity_count' => 0,
];

$recentSessions = [];
$recentProgress = [];
$recentActivities = [];
$accountError = null;

/* ---------------- AUTH ---------------- */
if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $authAction = $_POST['auth_action'] ?? '';

    if ($authAction === 'login') {
        $authMode = 'login';
        $password = $_POST['login_password'] ?? '';

        if ($loginUsername === '' || $password === '') {
            $authError = 'Please enter both username/email and password.';
        } else {
            try {
                $stmt = db()->prepare(
                    'SELECT user_id, username, email, password_hash, role 
                     FROM users 
                     WHERE username = :username OR email = :email 
                     LIMIT 1'
                );

                $stmt->execute([
                    'username' => $loginUsername,
                    'email' => $loginUsername
                ]);

                $loginUser = $stmt->fetch();

                if ($loginUser && password_verify($password, $loginUser['password_hash'])) {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$loginUser['user_id'];
                    $_SESSION['username'] = $loginUser['username'];
                    $_SESSION['email'] = $loginUser['email'];
                    $_SESSION['role'] = normalize_role($loginUser['role']);

                    db()->prepare(
                        'UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = :user_id'
                    )->execute(['user_id' => $loginUser['user_id']]);

                    redirect_to('account.php');
                }

                $authError = 'Invalid username/email or password.';
            } catch (Throwable $exception) {
                $authError = 'Login error: ' . $exception->getMessage();
            }
        }
    }

    elseif ($authAction === 'register') {
        $authMode = 'register';
        $password = $_POST['register_password'] ?? '';

        if ($registerUsername === '' || $registerEmail === '' || $password === '') {
            $authError = 'Please fill in all registration fields.';
        } elseif (!filter_var($registerEmail, FILTER_VALIDATE_EMAIL)) {
            $authError = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $authError = 'Password must be at least 6 characters.';
        } else {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO users (username, email, password_hash, role)
                     VALUES (:username, :email, :password_hash, :role)'
                );

                $stmt->execute([
                    'username' => $registerUsername,
                    'email' => $registerEmail,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'STUDENT',
                ]);

                flash_set('success', 'Account created successfully. Please log in.');
                redirect_to('account.php?mode=login');
            } catch (PDOException $exception) {
                $authError = $exception->getCode() === '23000'
                    ? 'Username or email already exists.'
                    : 'Registration failed.';
            }
        }
    }
}

/* ---------------- USER DATA ---------------- */
$userInfo = null;

if ($user && is_database_connected()) {
    try {
        $db = db();

        // FULL ACCOUNT INFO
        $stmt = $db->prepare(
            'SELECT username, email, role, created_at, last_login
             FROM users
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userInfo && isset($userInfo['role'])) {
            $userInfo['role'] = normalize_role($userInfo['role']);
        }

        // tutorial stats
        $summary['total_sections'] = (int)$db->query(
            'SELECT COUNT(*) FROM tutorial_section'
        )->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) 
             FROM user_progress 
             WHERE user_id = :user_id AND is_completed = 1'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['completed_sections'] = (int)$stmt->fetchColumn();

        // performance stats
        $stmt = $db->prepare(
            'SELECT
                COUNT(*) AS session_count,
                0 AS avg_score,
                COALESCE(ROUND(AVG(accuracy),1),0) AS avg_accuracy,
                0 AS best_score,
                COALESCE(ROUND(AVG(averageSpeed)),0) AS avg_timing_ms
             FROM performance_session
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary = array_merge($summary, $stmt->fetch() ?: []);

        // MIDI count (FIXED)
        $stmt = $db->prepare('SELECT COUNT(*) FROM midi_file WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['midi_count'] = (int)$stmt->fetchColumn();

        // activity count
        $stmt = $db->prepare('SELECT COUNT(*) FROM user_activity_log WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['activity_count'] = (int)$stmt->fetchColumn();

    } catch (Throwable $exception) {
        $accountError = 'Account data could not be loaded.<br>' . $exception->getMessage();
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if (!$user): ?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/account.css')); ?>">
<?php else: ?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/account_log.css')); ?>">
<?php endif; ?>

<div class="account-page-content">
<h1 class="section-title">Account</h1>

<?php if (!$user): ?>

    <?php if ($message = flash_get('success')): ?>
        <div class="alert success"><?= e($message); ?></div>
    <?php endif; ?>

    <?php if ($authError): ?>
        <div class="alert error"><?= e($authError); ?></div>
    <?php endif; ?>

    <div class="account-auth-tabs">
        <a class="<?= $authMode === 'login' ? 'active' : '' ?>"
           href="<?= e(url_path('account.php?mode=login')); ?>">Login</a>

        <a class="<?= $authMode === 'register' ? 'active' : '' ?>"
           href="<?= e(url_path('account.php?mode=register')); ?>">Register</a>
    </div>

    <div class="account-auth-card">
        <?php if ($authMode === 'login'): ?>
            <form method="post">
                <input type="hidden" name="auth_action" value="login">
                <input type="text" name="login_username" placeholder="Username or Email" required>
                <input type="password" name="login_password" required>
                <button type="submit">Login</button>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="auth_action" value="register">
                <input type="text" name="register_username" required>
                <input type="email" name="register_email" required>
                <input type="password" name="register_password" required>
                <button type="submit">Register</button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>

<?php if ($accountError): ?>
    <div class="alert error"><?= ($accountError); ?></div>
<?php endif; ?>

<!-- HEADER + ACCOUNT INFO (UPDATED) -->
<div class="account-header">
    <div>
        <p class="eyebrow">Signed in as</p>
        <h2><?= e($userInfo['username']); ?></h2>

        <div style="margin-top:10px; font-size:14px;">
            <div><b>Email:</b> <?= e($userInfo['email']); ?></div>
            <div><b>Role:</b> <?= e($userInfo['role']); ?></div>
            <?php 
                $user_created_at = explode(' ', $userInfo['created_at']);
                $user_last_login = explode(' ', $userInfo['last_login'])
            ?>
            <div><b>Created:</b> <?= e($user_created_at[0]); ?></div>
            <div><b>Last Login:</b> <?= e($user_last_login[0] ?? 'Never'); ?></div>
        </div>
    </div>

    <div class="role-pill"><?= e($userInfo['role']); ?></div>
</div>

<!-- SUMMARY CARDS -->
<div class="card-grid account-summary">

    <div class="card">
        <h3>Tutorial Progress</h3>
        <strong><?= e($summary['completed_sections']); ?> / <?= e($summary['total_sections']); ?></strong>
    </div>

    <div class="card">
        <h3>Performance Sessions</h3>
        <strong><?= e($summary['session_count']); ?></strong>
    </div>

    <div class="card">
        <h3>Average Score</h3>
        <strong><?= e($summary['avg_score']); ?>/100</strong>
    </div>

    <div class="card">
        <h3>MIDI Library</h3>
        <strong><?= e($summary['midi_count']); ?></strong>
    </div>

    <div class="card">
        <h3>Activity Log</h3>
        <strong><?= e($summary['activity_count']); ?></strong>
    </div>

</div>
<section class="data-section">
        <h2>User Activity Log</h2>
        <?php if (!$recentActivities): ?>
            <p class="empty-state">Game rounds and piano Play sessions will appear here after they are completed while logged in.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Activity</th>
                        <th>Type</th>
                        <th>Score</th>
                        <th>Accuracy</th>
                        <th>Duration</th>
                        <th>Date</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivities as $activity): ?>
                        <tr>
                            <td><?php echo e($activity['title']); ?></td>
                            <td><?php echo e(str_replace('_', ' ', $activity['activity_type'])); ?></td>
                            <td><?php echo $activity['score'] !== null ? e($activity['score']) : '--'; ?></td>
                            <td><?php echo $activity['accuracy'] !== null ? e($activity['accuracy']) . '%' : '--'; ?></td>
                            <td><?php echo $activity['duration_seconds'] !== null ? e($activity['duration_seconds']) . 's' : '--'; ?></td>
                            <td><?php echo e($activity['created_at']); ?></td>
                            <td><a class="text-button small" href="<?php echo e(url_path('activity.php?id=' . $activity['activity_id'])); ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="data-section">
        <h2>Recent Performance</h2>
        <?php if (!$recentSessions): ?>
            <p class="empty-state">No database-backed play sessions yet. Complete a Play session in the Piano page while logged in.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Song</th>
                        <th>Input</th>
                        <th>Score</th>
                        <th>Accuracy</th>
                        <th>Duration</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSessions as $session): ?>
                        <tr>
                            <td><?php echo e($session['song_title'] ?: 'Imported MIDI'); ?></td>
                            <td><?php echo e($session['input_mode']); ?></td>
                            <td><?php echo e($session['score']); ?></td>
                            <td><?php echo e($session['accuracy']); ?>%</td>
                            <td><?php echo e($session['duration_seconds']); ?>s</td>
                            <td><?php echo e($session['session_date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="data-section">
        <h2>Recent Progress</h2>
        <?php if (!$recentProgress): ?>
            <p class="empty-state">Tutorial progress will appear here after a logged-in user completes tracked lesson tasks.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Topic</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Last Access</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentProgress as $progress): ?>
                        <tr>
                            <td><?php echo e($progress['topic_title']); ?></td>
                            <td><?php echo e($progress['section_title']); ?></td>
                            <td><?php echo $progress['is_completed'] ? 'Completed' : 'Started'; ?></td>
                            <td><?php echo e($progress['last_accessed']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
