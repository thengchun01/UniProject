<?php
require_once __DIR__ . '/../includes/config.php';

$user = current_user();

if (!$user || $user['role'] !== 'TEACHER') {
    redirect_to('../account.php');
}

$db = db();

// Get teacher's classroom
$stmt = $db->prepare("SELECT classroom, classroom_status FROM users WHERE user_id = :user_id");
$stmt->execute(['user_id' => $user['user_id']]);
$teacherInfo = $stmt->fetch();

$settings = $classroomManager->getSettings($user['user_id']);
$topics = $tutorialManager->getAllTopics();

$classroomCode = $teacherInfo['classroom'];

if (empty($classroomCode)) {
    include __DIR__ . '/../includes/header.php';
    echo '<div class="container"><h1>No Classroom Yet</h1><p>Please create a classroom from your Dashboard first.</p><a href="'.BASE_URL.'account.php"><button>Go to Dashboard</button></a></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Handle classroom deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_classroom') {
    header('Content-Type: application/json');
    
    try {
        $stmt = $db->prepare("UPDATE users SET classroom = NULL, classroom_status = 'NONE' WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user['user_id']]);
        
        $stmt = $db->prepare("UPDATE users SET classroom = NULL, classroom_status = 'NONE' WHERE classroom = :classroom");
        $stmt->execute(['classroom' => $classroomCode]);
        
        echo json_encode(['success' => true, 'message' => 'Classroom deleted successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Get pending requests
$stmt = $db->prepare("
    SELECT user_id, username, email, created_at 
    FROM users 
    WHERE classroom = :classroom AND classroom_status = 'PENDING' AND role = 'STUDENT'
    ORDER BY created_at DESC
");
$stmt->execute(['classroom' => $classroomCode]);
$pendingRequests = $stmt->fetchAll();

// Get enrolled students
$stmt = $db->prepare("
    SELECT user_id, username, email, created_at 
    FROM users 
    WHERE classroom = :classroom AND classroom_status = 'APPROVED' AND role = 'STUDENT'
    ORDER BY username ASC
");
$stmt->execute(['classroom' => $classroomCode]);
$enrolledStudents = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- At the top of teacher_classroom.php -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/teacher.css">

<div class="classroom-wrapper">
    <!-- Header -->
    <div class="classroom-header">
        <div class="header-title">
            <h1>Classroom Management</h1>
            <div class="classroom-id">ID: <?= e($classroomCode) ?></div>
        </div>
        <div class="header-actions">
            <button id="deleteClassroomBtn" class="btn-outline-danger">Delete Classroom</button>
            <button id="" class="btn-outline">
                <a href="<?= BASE_URL.'account.php'?>" class="">Back to Dashboard</a>
            </button>
        </div>
    </div>
    
    <!-- Main Content with Sidebar -->
    <div class="main-layout" style="display: flex; flex-direction: column; gap: 40px; margin-right: 0;">
        
        <!-- ROW 1: Students and Pending Requests -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            
            <!-- Enrolled Students (Left, 2fr) -->
            <div class="students-section" style="margin: 0;">
                <div class="section-header">
                    <div>
                        <h2>Enrolled Students</h2>
                        <span class="student-count"><?= count($enrolledStudents) ?> students</span>
                    </div>
                    <button id="createStudentBtn" class="btn-outline" style="">+ Add New</button>
                </div>
                
                <?php if (empty($enrolledStudents)): ?>
                    <div class="empty-state">No students enrolled yet. Click "Add New" to get started.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Enrolled</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrolledStudents as $student): ?>
                                    <tr data-id="<?= $student['user_id'] ?>">
                                        <td><?= $student['user_id'] ?></td>
                                        <td><?= e($student['username']) ?></td>
                                        <td><?= e($student['email']) ?></td>
                                        <td><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                                        <td class="action-buttons">
                                            <button class="action-btn view-btn" onclick="viewUser(<?= $student['user_id'] ?>)">View</button>
                                            <button class="action-btn edit-btn" onclick="openEdit(<?= $student['user_id'] ?>, '<?= htmlspecialchars($student['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($student['email'], ENT_QUOTES) ?>')">Edit</button>
                                            <button class="action-btn reset-btn" onclick="openResetPassword(<?= $student['user_id'] ?>, '<?= htmlspecialchars($student['username'], ENT_QUOTES) ?>')">Reset Password</button>
                                            <button class="action-btn delete-btn" onclick="openDelete(<?= $student['user_id'] ?>)">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pending Requests (Right, 1fr) -->
            <div class="pending-sidebar" id="pendingSidebar" style="position: static; width: auto; height: auto;">
                <div class="sidebar-header">
                    <div class="sidebar-title">
                        <span class="icon">📋</span>
                        <span>Pending Requests</span>
                        <?php if (!empty($pendingRequests)): ?>
                            <span class="badge"><?= count($pendingRequests) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sidebar-content" id="sidebarContent" style="display: block;">
                    <?php if (empty($pendingRequests)): ?>
                        <div class="empty-requests">No pending requests</div>
                    <?php else: ?>
                        <div class="requests-list">
                            <?php foreach ($pendingRequests as $request): ?>
                                <div class="request-card">
                                    <div class="request-name"><?= e($request['username']) ?></div>
                                    <div class="request-email"><?= e($request['email']) ?></div>
                                    <div class="request-date"><?= date('M d', strtotime($request['created_at'])) ?></div>
                                    <div class="request-buttons">
                                        <button class="btn-approve" onclick="approveRequest(<?= $request['user_id'] ?>)">✓ Approve</button>
                                        <button class="btn-reject" onclick="rejectRequest(<?= $request['user_id'] ?>)">✗ Reject</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        
        <!-- ROW 2: Settings Section -->
        <div class="settings-section" style="margin: 0;">
            <div class="section-header">
                <h2>Classroom Access Control</h2>
            </div>
            <form id="settingsForm">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    
                    <!-- Tutorial Access (Left, 2fr) -->
                    <div class="card" style="padding: 20px;">
                        <h3 style="margin-bottom:10px;">Tutorial Access</h3>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($topics as $index => $topic): ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="enabled_tutorials[]" value="<?= $topic['tutorial_id'] ?>" <?= !in_array((string)$topic['tutorial_id'], $settings['disabled_tutorials'] ?? []) ? 'checked' : '' ?>> Enable Lesson <?= $index + 1 ?>: <?= e($topic['title']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Games Access (Right, 1fr) -->
                    <div class="card" style="padding: 20px;">
                        <h3 style="margin-bottom:10px;">Games Access</h3>
                        <label style="display:block; margin-bottom: 8px;">
                            <input type="checkbox" name="enabled_games[]" value="recognition" <?= !in_array('recognition', $settings['disabled_games'] ?? []) ? 'checked' : '' ?>> Enable Note Recognition
                        </label>
                        <label style="display:block; margin-bottom: 8px;">
                            <input type="checkbox" name="enabled_games[]" value="identify" <?= !in_array('identify', $settings['disabled_games'] ?? []) ? 'checked' : '' ?>> Enable Key and Note
                        </label>
                        <label style="display:block; margin-bottom: 8px;">
                            <input type="checkbox" name="enabled_games[]" value="car_race" <?= !in_array('car_race', $settings['disabled_games'] ?? []) ? 'checked' : '' ?>> Enable Car Racing
                        </label>
                    </div>
                    
                </div>
                <button type="button" class="primary-btn" style="margin-top:20px;" onclick="saveSettings()">Save Settings</button>
            </form>
        </div>
        
    </div>
</div>

<!-- Delete Classroom Modal -->
<div id="deleteClassroomModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Delete Classroom</h3>
            <button class="modal-close" onclick="closeDeleteClassroomModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="warning-icon">⚠️</div>
            <p>Are you sure you want to delete this classroom?</p>
            <p class="warning-text">This will remove all enrolled students. This action cannot be undone!</p>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeDeleteClassroomModal()">Cancel</button>
            <button class="btn-confirm-delete" id="confirmDeleteClassroomBtn">Delete Classroom</button>
        </div>
    </div>
</div>

<!-- Create Student Modal -->
<div id="createStudentModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Add New Student</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="createStudentErrorMsg" class="error-msg" style="display: none;"></div>
            <form id="createStudentForm">
                <div class="form-field">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-field">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-field">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn-submit">Create Student</button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div id="editStudentModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Edit Student</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="editStudentErrorMsg" class="error-msg" style="display: none;"></div>
            <form id="editStudentForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-field">
                    <label>Username</label>
                    <input type="text" name="username" id="edit_username" required>
                </div>
                <div class="form-field">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>
                <button type="submit" class="btn-submit">Update Student</button>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Reset Student Password</h3>
            <button class="modal-close" onclick="closeResetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="resetErrorMsg" class="error-msg" style="display: none;"></div>
            <form id="resetPasswordForm">
                <input type="hidden" name="student_id" id="reset_student_id">
                <div class="form-field">
                    <label>Student</label>
                    <input type="text" id="reset_username" disabled>
                </div>
                <div class="form-field">
                    <label>New Password (min 6 characters)</label>
                    <input type="password" name="new_password" id="reset_password" required>
                </div>
                <div class="form-field">
                    <label>Confirm Password</label>
                    <input type="password" id="confirm_password" required>
                </div>
                <button type="submit" class="btn-submit">Reset Password</button>
            </form>
        </div>
    </div>
</div>

<!-- Delete Student Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Remove Student</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="warning-icon">⚠️</div>
            <p>Are you sure you want to remove this student?</p>
            <p class="warning-text">The student will be removed from your classroom.</p>
            <input type="hidden" id="delete_student_id">
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn-confirm-delete" id="confirmDeleteBtn">Remove Student</button>
        </div>
    </div>
</div>

<!-- User Info Overlay -->
<div id="userInfoOverlay" class="overlay-full">
    <div class="overlay-container">
        <div class="overlay-header">
            <h2>User Information</h2>
            <button class="close-overlay" onclick="closeUserInfo()">×</button>
        </div>
        <div id="userInfoContent" class="user-info-content">
            <div class="loading">Loading...</div>
        </div>
    </div>
</div>

<script>
// ============================================
// USER INFO OVERLAY (using admin/get_user_info.php)
// ============================================

function openUserInfoOverlay() {
    const overlay = document.getElementById('userInfoOverlay');
    overlay.classList.add('show');
    document.body.classList.add('modal-open');
}

function closeUserInfo() {
    const overlay = document.getElementById('userInfoOverlay');
    overlay.classList.remove('show');
    document.body.classList.remove('modal-open');
}

window.viewUser = async function(userId) {
    const overlay = document.getElementById('userInfoOverlay');
    const content = document.getElementById('userInfoContent');
    content.innerHTML = '<div class="loading">Loading user information...</div>';
    overlay.classList.add('show');
    document.body.classList.add('modal-open');
    
    try {
        // Using the admin/get_user_info.php path (go up from teacher to root, then into admin)
        const response = await fetch('../admin/get_user_info.php?id=' + userId);
        const data = await response.json();
        
        if (data.success) {
            const user = data.user;
            const stats = data.stats;
            const activities = data.activities || [];
            const sessions = data.sessions || [];
            
            let activitiesHtml = '';
            if (activities.length > 0) {
                activitiesHtml = `
                    <div class="activity-section">
                        <div class="section-header">Recent Activities</div>
                        <div class="activity-list">
                            ${activities.map(act => `
                                <div class="activity-item">
                                    <div class="activity-title">${escapeHtml(act.title || 'Untitled Activity')}</div>
                                    <div class="activity-details">
                                        <div class="activity-detail"><strong>Type:</strong> ${escapeHtml(act.activity_type || 'Unknown')}</div>
                                        ${act.score ? `<div class="activity-detail"><strong>Score:</strong> ${act.score}</div>` : ''}
                                        ${act.accuracy ? `<div class="activity-detail"><strong>Accuracy:</strong> ${act.accuracy}%</div>` : ''}
                                        ${act.duration_seconds ? `<div class="activity-detail"><strong>Duration:</strong> ${act.duration_seconds}s</div>` : ''}
                                        <div class="activity-detail"><strong>Date:</strong> ${escapeHtml(act.created_at)}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                activitiesHtml = '<div class="empty-state">No recent activities found</div>';
            }
            
            let sessionsHtml = '';
            if (sessions.length > 0) {
                sessionsHtml = `
                    <div class="activity-section">
                        <div class="section-header">Recent Performance Sessions</div>
                        <div class="activity-list">
                            ${sessions.map(session => `
                                <div class="activity-item">
                                    <div class="activity-title">${escapeHtml(session.song_title || 'Untitled Session')}</div>
                                    <div class="activity-details">
                                        <div class="activity-detail"><strong>Mode:</strong> ${escapeHtml(session.input_mode || 'Unknown')}</div>
                                        ${session.score ? `<div class="activity-detail"><strong>Score:</strong> ${session.score}</div>` : ''}
                                        ${session.accuracy ? `<div class="activity-detail"><strong>Accuracy:</strong> ${session.accuracy}%</div>` : ''}
                                        ${session.duration_seconds ? `<div class="activity-detail"><strong>Duration:</strong> ${session.duration_seconds}s</div>` : ''}
                                        <div class="activity-detail"><strong>Date:</strong> ${escapeHtml(session.session_date)}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                sessionsHtml = '<div class="empty-state">No performance sessions found</div>';
            }
            
            content.innerHTML = `
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value">${stats.completed_sections}/${stats.total_sections}</div>
                        <div class="stat-label">Sections Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.session_count}</div>
                        <div class="stat-label">Play Sessions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.avg_score}</div>
                        <div class="stat-label">Avg Score</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.avg_accuracy}%</div>
                        <div class="stat-label">Avg Accuracy</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.best_score}</div>
                        <div class="stat-label">Best Score</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${stats.midi_count}</div>
                        <div class="stat-label">MIDI Files</div>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-card-title">User Information</div>
                    <div class="info-row">
                        <span class="info-label">User ID:</span>
                        <span class="info-value">${user.user_id}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Username:</span>
                        <span class="info-value">${escapeHtml(user.username)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value">${escapeHtml(user.email)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Role:</span>
                        <span class="info-value"><span class="role-badge role-${user.role.toLowerCase()}">${user.role}</span></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Classroom:</span>
                        <span class="info-value">${user.classroom ? escapeHtml(user.classroom) : '—'}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Classroom Status:</span>
                        <span class="info-value">${escapeHtml(user.classroom_status || 'NONE')}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created At:</span>
                        <span class="info-value">${escapeHtml(user.created_at)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Login:</span>
                        <span class="info-value">${escapeHtml(user.last_login)}</span>
                    </div>
                </div>
                
                ${activitiesHtml}
                ${sessionsHtml}
            `;
        } else {
            content.innerHTML = `<div class="error-message">${data.message}</div>`;
        }
    } catch (error) {
        content.innerHTML = `<div class="error-message">Error loading user information: ${error.message}</div>`;
    }
};

// ============================================
// SIDEBAR TOGGLE
// ============================================
let sidebarCollapsed = false;

function toggleSidebar() {
    const sidebar = document.getElementById('pendingSidebar');
    sidebarCollapsed = !sidebarCollapsed;
    if (sidebarCollapsed) {
        sidebar.classList.add('collapsed');
        document.getElementById('toggleSidebarBtn').innerHTML = '▶';
    } else {
        sidebar.classList.remove('collapsed');
        document.getElementById('toggleSidebarBtn').innerHTML = '◀';
    }
}

// ============================================
// DELETE CLASSROOM
// ============================================

function openDeleteClassroomModal() {
    document.getElementById('deleteClassroomModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDeleteClassroomModal() {
    document.getElementById('deleteClassroomModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('deleteClassroomBtn')?.addEventListener('click', openDeleteClassroomModal);

document.getElementById('confirmDeleteClassroomBtn')?.addEventListener('click', async () => {
    const btn = document.getElementById('confirmDeleteClassroomBtn');
    const originalText = btn.textContent;
    
    btn.textContent = 'Deleting...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_classroom');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Classroom deleted successfully!');
            window.location.href = "<?= BASE_URL .'account.php'?>";
        } else {
            alert(data.message);
            btn.textContent = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
});

// ============================================
// CREATE STUDENT MODAL
// ============================================

function openCreateModal() {
    document.getElementById('createStudentModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCreateModal() {
    document.getElementById('createStudentModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('createStudentBtn')?.addEventListener('click', openCreateModal);

document.getElementById('createStudentForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('createStudentErrorMsg');
    
    errorDiv.style.display = 'none';
    submitBtn.textContent = 'Creating...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('student_create.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Student created successfully!');
            window.location.reload();
        } else {
            errorDiv.textContent = data.message || 'Failed to create student';
            errorDiv.style.display = 'block';
            submitBtn.textContent = 'Create Student';
            submitBtn.disabled = false;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.textContent = 'Create Student';
        submitBtn.disabled = false;
    }
});

// ============================================
// EDIT STUDENT
// ============================================

function openEditModal() {
    document.getElementById('editStudentModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editStudentModal').classList.remove('show');
    document.body.style.overflow = '';
}

window.openEdit = function(id, username, email) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    openEditModal();
};

document.getElementById('editStudentForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const errorDiv = document.getElementById('editStudentErrorMsg');
    
    errorDiv.style.display = 'none';
    submitBtn.textContent = 'Updating...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('student_update.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Student updated successfully!');
            window.location.reload();
        } else {
            errorDiv.textContent = data.message || 'Failed to update student';
            errorDiv.style.display = 'block';
            submitBtn.textContent = 'Update Student';
            submitBtn.disabled = false;
        }
    } catch (error) {
        errorDiv.textContent = 'Network error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.textContent = 'Update Student';
        submitBtn.disabled = false;
    }
});

// ============================================
// RESET PASSWORD
// ============================================

function openResetModal() {
    document.getElementById('resetPasswordModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeResetModal() {
    document.getElementById('resetPasswordModal').classList.remove('show');
    document.body.style.overflow = '';
}

window.openResetPassword = function(id, username) {
    document.getElementById('reset_student_id').value = id;
    document.getElementById('reset_username').value = username;
    document.getElementById('reset_password').value = '';
    document.getElementById('confirm_password').value = '';
    openResetModal();
};

document.getElementById('resetPasswordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const newPassword = document.getElementById('reset_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const errorDiv = document.getElementById('resetErrorMsg');
    
    if (newPassword !== confirmPassword) {
        errorDiv.textContent = 'Passwords do not match!';
        errorDiv.style.display = 'block';
        return;
    }
    
    if (newPassword.length < 6) {
        errorDiv.textContent = 'Password must be at least 6 characters!';
        errorDiv.style.display = 'block';
        return;
    }
    
    errorDiv.style.display = 'none';
    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.textContent = 'Resetting...';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'reset_student_password');
        formData.append('student_id', document.getElementById('reset_student_id').value);
        formData.append('new_password', newPassword);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Password reset successfully!');
            closeResetModal();
        } else {
            errorDiv.textContent = data.message || 'Failed to reset password';
            errorDiv.style.display = 'block';
        }
        submitBtn.textContent = 'Reset Password';
        submitBtn.disabled = false;
    } catch (error) {
        errorDiv.textContent = 'Network error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.textContent = 'Reset Password';
        submitBtn.disabled = false;
    }
});

// ============================================
// DELETE STUDENT
// ============================================

function openDeleteModal() {
    document.getElementById('deleteModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    document.body.style.overflow = '';
}

window.openDelete = function(id) {
    document.getElementById('delete_student_id').value = id;
    openDeleteModal();
};

document.getElementById('confirmDeleteBtn')?.addEventListener('click', async () => {
    const id = document.getElementById('delete_student_id').value;
    const btn = document.getElementById('confirmDeleteBtn');
    const originalText = btn.textContent;
    
    btn.textContent = 'Removing...';
    btn.disabled = true;
    
    try {
        const response = await fetch('student_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: id })
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Student removed successfully');
            window.location.reload();
        } else {
            alert(data.message);
            btn.textContent = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
});

// ============================================
// APPROVE / REJECT REQUESTS
// ============================================

window.approveRequest = async function(studentId) {
    if (!confirm('Approve this student\'s request?')) return;
    
    try {
        const response = await fetch('request_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: studentId })
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Student approved successfully!');
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
};

window.rejectRequest = async function(studentId) {
    if (!confirm('Reject this student\'s request?')) return;
    
    try {
        const response = await fetch('request_reject.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: studentId })
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Request rejected');
            window.location.reload();
        } else {
            alert(data.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
};

// ============================================
// CLOSE MODALS WITH ESCAPE
// ============================================

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteClassroomModal();
        closeCreateModal();
        closeEditModal();
        closeResetModal();
        closeDeleteModal();
        closeUserInfo();
    }
});

// Close modals when clicking overlay
document.querySelectorAll('.modal-overlay, .overlay-full').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
            document.body.style.overflow = '';
        }
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// SAVE CLASSROOM SETTINGS
// ============================================
async function saveSettings() {
    const form = document.getElementById('settingsForm');
    const formData = new FormData(form);

    try {
        const response = await fetch('teacher_save_settings.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            alert('Settings saved successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        alert('Network error: ' + error.message);
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>