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
                'SELECT user_id, username, email, password_hash, role, classroom, classroom_status
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
                    $_SESSION['user_id'] = (int) $loginUser['user_id'];
                    $_SESSION['username'] = $loginUser['username'];
                    $_SESSION['email'] = $loginUser['email'];
                    $_SESSION['role'] = $loginUser['role'];
                    $_SESSION['classroom'] = $loginUser['classroom'];
                    $_SESSION['classroom_status'] = $loginUser['classroom_status'];

                    db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = :user_id')
                        ->execute(['user_id' => $loginUser['user_id']]);

                    redirect_to('account.php');
                }

                $authError = 'Invalid username/email or password.';
                } catch (Throwable $exception) {
                    $authError = 'Login error: ' . $exception->getMessage();
                }
        }
    } elseif ($authAction === 'register') {
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
                    'INSERT INTO users (username, email, password_hash, role, classroom_status) 
                     VALUES (:username, :email, :password_hash, :role, "NONE")'
                );
                $stmt->execute([
                    'username' => $registerUsername,
                    'email' => $registerEmail,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'STUDENT',
                ]);
                
                $newUserId = db()->lastInsertId();
                if (isset($tutorialManager)) {
                    $tutorialManager->initUserProgress($newUserId);
                }

                flash_set('success', 'Account created successfully. Please log in.');
                redirect_to('account.php?mode=login');
            } catch (PDOException $exception) {
                $authError = $exception->getCode() === '23000'
                    ? 'Username or email already exists.'
                    : 'Registration failed. Please check the PSM database connection.';
            } catch (Throwable $exception) {
                $authError = 'Registration failed because the PSM database is not connected yet.';
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
            'SELECT username, email, role, classroom, classroom_status, created_at, last_login
             FROM users
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update session with latest classroom data
        $_SESSION['classroom'] = $userInfo['classroom'];
        $_SESSION['classroom_status'] = $userInfo['classroom_status'];

        $stmt = $db->query('SELECT COUNT(*) FROM tutorial_section');
        $summary['total_sections'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_progress WHERE user_id = :user_id AND is_completed = 1');
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['completed_sections'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT
                COUNT(*) AS session_count,
                0 AS avg_score,
                COALESCE(ROUND(AVG(accuracy), 1), 0) AS avg_accuracy,
                0 AS best_score,
                COALESCE(ROUND(AVG(averageSpeed)), 0) AS avg_timing_ms
             FROM performance_session
             WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $performance = $stmt->fetch() ?: [];
        $summary = array_merge($summary, $performance);

        $stmt = $db->prepare('SELECT COUNT(*) FROM midi_file WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['midi_count'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_activity_log WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['user_id']]);
        $summary['activity_count'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT datetime as session_date, accuracy, averageSpeed as duration_seconds
             FROM performance_session
             WHERE user_id = :user_id
             ORDER BY datetime DESC, session_id DESC
             LIMIT 5'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $recentSessions = $stmt->fetchAll();

        $stmt = $db->prepare(
            'SELECT tt.title AS topic_title, CONCAT("Part ", ts.order_index) AS section_title, up.is_completed, up.completed_at, up.last_accessed
             FROM user_progress up
             INNER JOIN tutorial_section ts ON ts.section_id = up.section_id
             INNER JOIN tutorial_topic tt ON tt.tutorial_id = ts.tutorial_id
             WHERE up.user_id = :user_id
             ORDER BY up.last_accessed DESC
             LIMIT 5'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $recentProgress = $stmt->fetchAll();

        $stmt = $db->prepare(
            'SELECT activity_id, activity_type, activity_title AS title, attribute, created_at
             FROM user_activity_log
             WHERE user_id = :user_id
             ORDER BY created_at DESC, activity_id DESC
             LIMIT 8'
        );
        $stmt->execute(['user_id' => $user['user_id']]);
        $recentActivities = $stmt->fetchAll();

        // Parse attribute JSON for display
        foreach ($recentActivities as &$act) {
            $attr = json_decode($act['attribute'] ?? '{}', true) ?: [];
            $act['score'] = $attr['score'] ?? null;
            $act['accuracy'] = $attr['accuracy'] ?? null;
            $act['duration_seconds'] = $attr['duration_seconds'] ?? null;
        }
        unset($act);
    } catch (Throwable $exception) {
        $accountError = 'Account data could not be loaded. Please confirm database/psm_schema.sql has been imported. <br>' . $exception->getMessage();
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if (!$user): ?>
    <link rel="stylesheet" href="<?= e(url_path('assets/css/account.css')); ?>">
<?php endif; ?>
<?php if ($user): ?>
    <link rel="stylesheet" href="<?= e(url_path('assets/css/account_log.css')); ?>">
<?php endif; ?>

<div class="account-page-content">
    <div style="display: flex; flex-direction: row; width: 100%; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
        <?php if ($user): ?>
            <h1 class="section-title">Account</h1>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if ($user['role'] === 'ADMIN'): ?>
                <a href="<?= e(url_path('admin/dashboard.php')); ?>">
                    <button type="button" class="primary-btn">Open Admin Panel</button>
                </a>
                <?php endif; ?>
                
                <?php if ($user['role'] === 'TEACHER'): ?>
                    <?php if (empty($userInfo['classroom'])): ?>
                        <button type="button" class="primary-btn" id="createClassroomBtn" style="align-self: center;">+ Create Classroom</button>
                    <?php else: ?>
                        <div style="display: flex; gap: 10px;">
                            <span class="classroom-badge">Classroom: <?= e($userInfo['classroom']) ?></span>
                            <a href="<?= BASE_URL . "teacher/teacher_classroom.php" ?>">
                                <button type="button" class="secondary-btn">Manage Classroom</button>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($user['role'] === 'STUDENT'): ?>
                    <?php if (empty($userInfo['classroom']) || $userInfo['classroom_status'] === 'NONE'): ?>
                        <button type="button" class="primary-btn" id="joinClassroomBtn">Join Classroom</button>
                    <?php elseif ($userInfo['classroom_status'] === 'PENDING'): ?>
                        <span class="classroom-badge pending">Request Pending Approval</span>
                    <?php elseif ($userInfo['classroom_status'] === 'APPROVED'): ?>
                        <div style="display: flex; gap: 10px;">
                            <span class="classroom-badge approved">Classroom: <?= e($userInfo['classroom']) ?></span>
                            <a href="<?= e(url_path('student/student_classroom.php')) ?>">
                                <button type="button" class="secondary-btn">View Classroom</button>
                            </a>
                        </div>
                    <?php elseif ($userInfo['classroom_status'] === 'REJECTED'): ?>
                        <span class="classroom-badge rejected">Request Rejected</span>
                        <button type="button" class="primary-btn" id="joinClassroomBtn">Join Different Classroom</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php if (!$user): ?>
    <?php if ($message = flash_get('success')): ?>
        <div class="alert success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($authError): ?>
        <div class="alert error"><?php echo e($authError); ?></div>
    <?php endif; ?>

    <div class="account-auth-layout">
        <section class="account-auth-intro">
            <p class="eyebrow">Piano learning, your way</p>
            <h1>Make every practice session count.</h1>
            <p>Keep your tutorials, songs, MIDI files, and performance progress together in one place.</p>
            <div class="auth-benefits" aria-label="Account benefits">
                <span>Track tutorial progress</span>
                <span>Review play sessions</span>
                <span>Build your MIDI library</span>
            </div>
        </section>

        <div class="account-auth-form">
            <div class="account-auth-tabs" aria-label="Account options">
                <a class="<?php echo $authMode === 'login' ? 'active' : ''; ?>" href="<?php echo e(url_path('account.php?mode=login')); ?>">Login</a>
                <a class="<?php echo $authMode === 'register' ? 'active' : ''; ?>" href="<?php echo e(url_path('account.php?mode=register')); ?>">Register</a>
            </div>

            <div class="account-auth-card">
                <?php if ($authMode === 'login'): ?>
                <section class="auth-panel" id="login-panel">
                    <h2>Welcome back</h2>
                    <p class="auth-panel-copy">Log in to continue your piano learning journey.</p>
                    <form class="form-panel compact" method="post" action="<?php echo e(url_path('account.php?mode=login')); ?>">
                        <input type="hidden" name="auth_action" value="login">

                        <label>
                            Username or Email
                            <input type="text" name="login_username" value="<?php echo e($loginUsername); ?>" autocomplete="username" required>
                        </label>

                        <label>
                            Password
                            <input type="password" name="login_password" autocomplete="current-password" required>
                        </label>

                        <button type="submit">Log In</button>
                    </form>
                </section>
                <?php else: ?>

                <section class="auth-panel" id="register-panel">
                    <h2>Start learning</h2>
                    <p class="auth-panel-copy">Create an account to save your progress across sessions.</p>
                    <form class="form-panel compact" method="post" action="<?php echo e(url_path('account.php?mode=register')); ?>">
                        <input type="hidden" name="auth_action" value="register">

                        <label>
                            Username
                            <input type="text" name="register_username" value="<?php echo e($registerUsername); ?>" maxlength="100" autocomplete="username" required>
                        </label>

                        <label>
                            Email
                            <input type="email" name="register_email" value="<?php echo e($registerEmail); ?>" maxlength="100" autocomplete="email" required>
                        </label>

                        <label>
                            Password
                            <input type="password" name="register_password" minlength="6" autocomplete="new-password" required>
                        </label>

                        <button type="submit">Create Account</button>
                    </form>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php if ($accountError): ?>
        <div class="alert error"><?php echo ($accountError); ?></div>
    <?php endif; ?>

    <div class="account-header">
        <div>
            <p class="eyebrow">Signed in as</p>
            <h2><?php echo e($user['username']); ?></h2>
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
        <div class="role-pill"><?php echo e($user['role']); ?></div>
    </div>

    <div class="card-grid account-summary">
        <div class="card">
            <h3>Tutorial Progress</h3>
            <strong><?php echo e($summary['completed_sections']); ?> / <?php echo e($summary['total_sections']); ?></strong>
            <p>Completed sections</p>
        </div>

        <div class="card">
            <h3>Performance Sessions</h3>
            <strong><?php echo e($summary['session_count']); ?></strong>
            <p>Saved play sessions</p>
        </div>

        <div class="card">
            <h3>Average Score</h3>
            <strong><?php echo e($summary['avg_score']); ?>/100</strong>
            <p><?php echo e($summary['avg_accuracy']); ?>% average accuracy</p>
        </div>

        <div class="card">
            <h3>MIDI Library</h3>
            <strong><?php echo e($summary['midi_count']); ?></strong>
            <p>Uploaded MIDI files</p>
        </div>

        <div class="card">
            <h3>Activity Log</h3>
            <strong><?php echo e($summary['activity_count']); ?></strong>
            <p>Saved games and piano rounds</p>
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
                            <td>
                                <?php if (!empty($activity['shortcut_url'])): ?>
                                    <a class="text-button small" href="<?php echo e(url_path($activity['shortcut_url'])); ?>">View</a>
                                <?php else: ?>
                                    <a class="text-button small" href="<?php echo e(url_path('activity.php?id=' . $activity['activity_id'])); ?>">View</a>
                                <?php endif; ?>
                            </td>
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

<!-- Create Classroom Modal - Full Page Coverage -->
<div id="createClassroomModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Create Classroom</h2>
            <button type="button" class="close-btn" id="closeCreateClassroomModal">×</button>
        </div>
        <div id="classroomErrorMsg" class="error-message" style="display: none;"></div>
        <form id="createClassroomForm">
            <div class="form-group">
                <label>Classroom Name</label>
                <input type="text" name="classroom_name" required placeholder="e.g., Piano Class 2024">
            </div>
            <button type="submit" class="submit-btn">Create Classroom</button>
        </form>
    </div>
</div>

<!-- Join Classroom Modal - Full Page Coverage -->
<div id="joinClassroomModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Join Classroom</h2>
            <button type="button" class="close-btn" id="closeJoinClassroomModal">×</button>
        </div>
        <div id="joinErrorMsg" class="error-message" style="display: none;"></div>
        <form id="joinClassroomForm">
            <div class="form-group">
                <label>Teacher's Username</label>
                <input type="text" name="teacher_username" required placeholder="Enter teacher's username">
                <small style="color: #666;">Enter the username of your teacher to request joining their classroom</small>
            </div>
            <button type="submit" class="submit-btn">Request to Join</button>
        </form>
    </div>
</div>

<style>
/* ============================================ */
/* FULL PAGE MODAL COVERAGE - FIXED            */
/* ============================================ */

/* Modal Overlay - Full screen coverage */
.modal-overlay-full {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999999;
}

.modal-overlay-full.show {
    display: flex;
}

.modal-container {
    width: min(450px, 90vw);
    background: #fff;
    border-radius: 16px;
    padding: 28px;
    max-height: 85vh;
    overflow-y: auto;
    position: relative;
    z-index: 1000000;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #000;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.4rem;
}

.close-btn {
    width: 36px;
    height: 36px;
    border: none;
    background: #f0f0f0;
    font-size: 24px;
    cursor: pointer;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.close-btn:hover {
    background: #e0e0e0;
    transform: scale(1.05);
}

.error-message {
    background: #ffebee;
    color: #c62828;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: none;
    border-left: 4px solid #c62828;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #000;
}

.form-group input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-sizing: border-box;
    font-size: 14px;
}

.form-group input:focus {
    outline: none;
    border-color: #000;
    box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #666;
}

.submit-btn {
    width: 100%;
    padding: 12px;
    background: #000;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.submit-btn:hover {
    background: #333;
}

/* Prevent body scroll when modal is open */
body.modal-open {
    overflow: hidden !important;
    position: fixed !important;
    width: 100% !important;
    height: 100% !important;
}

.classroom-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
}

.classroom-badge.pending {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffb74d;
}

.classroom-badge.approved {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #81c784;
}

.classroom-badge.rejected {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ef9a9a;
}

.secondary-btn {
    background: #fff;
    color: #000;
    border: 2px solid #000;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
}

.secondary-btn:hover {
    background: #000;
    color: #fff;
}

.primary-btn {
    background: #000;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.primary-btn:hover {
    background: #333;
}

@keyframes modalShow {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-container {
    animation: modalShow 0.25s ease;
}
</style>

<script>
// Helper functions for modal management
function openModal(modal) {
    modal.classList.add('show');
    document.body.classList.add('modal-open');
}

function closeModal(modal) {
    modal.classList.remove('show');
    document.body.classList.remove('modal-open');
}

// Create Classroom Modal
const createModal = document.getElementById('createClassroomModal');
const joinModal = document.getElementById('joinClassroomModal');

document.getElementById('createClassroomBtn')?.addEventListener('click', () => {
    openModal(createModal);
});

document.getElementById('closeCreateClassroomModal')?.addEventListener('click', () => {
    closeModal(createModal);
});

document.getElementById('joinClassroomBtn')?.addEventListener('click', () => {
    openModal(joinModal);
});

document.getElementById('closeJoinClassroomModal')?.addEventListener('click', () => {
    closeModal(joinModal);
});

// Create Classroom Form
document.getElementById('createClassroomForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('classroomErrorMsg');
    
    errorDiv.style.display = 'none';
    submitBtn.textContent = 'Creating...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('teacher/classroom_create.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Classroom created successfully!');
            window.location.reload();
        } else {
            errorDiv.textContent = data.message;
            errorDiv.style.display = 'block';
            submitBtn.textContent = 'Create Classroom';
            submitBtn.disabled = false;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.textContent = 'Create Classroom';
        submitBtn.disabled = false;
    }
});

// Join Classroom Form
document.getElementById('joinClassroomForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('joinErrorMsg');
    
    errorDiv.style.display = 'none';
    submitBtn.textContent = 'Sending Request...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('student/classroom_join.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            errorDiv.textContent = data.message;
            errorDiv.style.display = 'block';
            submitBtn.textContent = 'Request to Join';
            submitBtn.disabled = false;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.textContent = 'Request to Join';
        submitBtn.disabled = false;
    }
});

// Close modals when clicking on overlay background
createModal?.addEventListener('click', function(e) {
    if (e.target === createModal) closeModal(createModal);
});

joinModal?.addEventListener('click', function(e) {
    if (e.target === joinModal) closeModal(joinModal);
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (createModal?.classList.contains('show')) closeModal(createModal);
        if (joinModal?.classList.contains('show')) closeModal(joinModal);
    }
});
</script>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
