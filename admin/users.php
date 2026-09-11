<?php
require_once __DIR__ . '/../includes/config.php';

$user = current_user();

if (!$user || $user['role'] !== 'ADMIN') {
    redirect_to('../account.php');
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    header('Content-Type: application/json');
    
    $userId = (int)($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    
    if ($userId <= 0 || strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        exit;
    }
    
    try {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = db()->prepare("UPDATE users SET password_hash = :password WHERE user_id = :id");
        $stmt->execute(['password' => $hashedPassword, 'id' => $userId]);
        
        echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Get sort parameters
$sortColumn = $_GET['sort'] ?? 'user_id';
$sortOrder = $_GET['order'] ?? 'DESC';
$allowedColumns = ['user_id', 'username', 'email', 'role', 'classroom', 'created_at'];
$sortColumn = in_array($sortColumn, $allowedColumns) ? $sortColumn : 'user_id';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

$users = db()->query("SELECT * FROM users ORDER BY $sortColumn $sortOrder")->fetchAll();
$nextOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css">

<!-- Notification Toast Container -->
<div id="notificationToast" class="toast"></div>

<h1>User Management</h1>

<div class="page-actions" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <button id="openCreateModal" class="primary-btn">+ Create User</button>
    </div>
    <div>
        <a href="dashboard.php" class="return-btn">← Return to Dashboard</a>
    </div>
</div>

<div class="table-wrapper">
    <table class="data-table modern-table" id="userTable">
        <thead>
            <tr>
                <th class="sortable" data-column="user_id" data-order="<?= $sortColumn === 'user_id' ? $nextOrder : 'ASC' ?>">
                    ID 
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'user_id'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th class="sortable" data-column="username" data-order="<?= $sortColumn === 'username' ? $nextOrder : 'ASC' ?>">
                    Username
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'username'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th class="sortable" data-column="email" data-order="<?= $sortColumn === 'email' ? $nextOrder : 'ASC' ?>">
                    Email
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'email'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th class="sortable" data-column="role" data-order="<?= $sortColumn === 'role' ? $nextOrder : 'ASC' ?>">
                    Role
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'role'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th class="sortable" data-column="classroom" data-order="<?= $sortColumn === 'classroom' ? $nextOrder : 'ASC' ?>">
                    Classroom ID
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'classroom'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th class="sortable" data-column="created_at" data-order="<?= $sortColumn === 'created_at' ? $nextOrder : 'ASC' ?>">
                    Created
                    <span class="sort-icon">
                        <?php if ($sortColumn === 'created_at'): ?>
                            <?= $sortOrder === 'ASC' ? '↑' : '↓' ?>
                        <?php endif; ?>
                    </span>
                </th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr data-id="<?= $u['user_id'] ?>">
                    <td><?= e($u['user_id']) ?></td>
                    <td class="col-username"><?= e($u['username']) ?></td>
                    <td class="col-email"><?= e($u['email']) ?></td>
                    <td class="col-role">
                        <span class="role-badge role-<?= strtolower($u['role']) ?>">
                            <?= e($u['role']) ?>
                        </span>
                    </td>
                    <td class="col-classroom">
                        <?php if (!empty($u['classroom'])): ?>
                            <span class="classroom-id-badge"><?= e($u['classroom']) ?></span>
                        <?php else: ?>
                            <span class="no-classroom">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                    <td class="actions-cell">
                        <button class="action-btn view-btn" onclick="viewUser(<?= $u['user_id'] ?>)">View</button>
                        <button class="action-btn edit-btn" onclick="openEdit(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>', '<?= $u['role'] ?>')">Edit</button>
                        <?php if (!empty($u['classroom'])): ?>
                            <button class="action-btn classroom-btn" onclick="viewClassroom('<?= e($u['classroom']) ?>', '<?= e($u['username']) ?>')">View Classroom</button>
                        <?php else: ?>
                            <button class="action-btn classroom-btn disabled" disabled title="This user does not belong to any classroom">View Classroom</button>
                        <?php endif; ?>
                        <button class="action-btn reset-btn" onclick="openResetPassword(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">Reset Password</button>
                        <button class="action-btn delete-btn" onclick="openDelete(<?= $u['user_id'] ?>)">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- ALL MODALS MOVED AFTER FOOTER -->
<!-- CREATE MODAL -->
<div id="createModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Create User</h2>
            <button type="button" class="close-btn" id="closeCreateModal">×</button>
        </div>
        <div id="createErrorMsg" class="error-message" style="display: none;"></div>
        <form id="createUserForm" autocomplete="off">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role">
                    <option value="STUDENT">Student</option>
                    <option value="TEACHER">Teacher</option>
                    <option value="ADMIN">Admin</option>
                </select>
            </div>
            <button type="submit" class="submit-btn">Create User</button>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Edit User</h2>
            <button type="button" class="close-btn" id="closeEditModal">×</button>
        </div>
        <div id="editErrorMsg" class="error-message" style="display: none;"></div>
        <form id="editUserForm">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="edit_username" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" id="edit_email" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="edit_role">
                    <option value="STUDENT">Student</option>
                    <option value="TEACHER">Teacher</option>
                    <option value="ADMIN">Admin</option>
                </select>
            </div>
            <button type="submit" class="submit-btn">Update User</button>
        </form>
    </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div id="resetPasswordModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Reset Password</h2>
            <button type="button" class="close-btn" id="closeResetModal">×</button>
        </div>
        <div id="resetErrorMsg" class="error-message" style="display: none;"></div>
        <form id="resetPasswordForm">
            <input type="hidden" name="user_id" id="reset_user_id">
            <div class="form-group">
                <label>Username</label>
                <input type="text" id="reset_username" disabled style="background: #f5f5f5;">
            </div>
            <div class="form-group">
                <label>New Password (min 6 characters)</label>
                <input type="password" name="new_password" id="reset_password" required>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" id="confirm_password" required>
            </div>
            <button type="submit" class="submit-btn">Reset Password</button>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteModal" class="modal-overlay-full">
    <div class="modal-container">
        <div class="modal-header">
            <h2>Delete User</h2>
            <button type="button" class="close-btn" id="closeDeleteModal">×</button>
        </div>
        <div id="deleteErrorMsg" class="error-message" style="display: none;"></div>
        <div class="delete-box">
            <div class="delete-icon">⚠️</div>
            <div class="delete-title">Delete User?</div>
            <div class="delete-text">This action cannot be undone.</div>
            <input type="hidden" id="delete_user_id">
            <div class="modal-actions">
                <button class="btn-cancel" type="button" id="cancelDeleteBtn">Cancel</button>
                <button class="btn-danger" type="button" id="confirmDeleteBtn">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- USER INFO OVERLAY -->
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

<!-- Classroom View Modal -->
<div id="classroomViewModal" class="modal-overlay-full">
    <div class="modal-container" style="width: min(600px, 90vw);">
        <div class="modal-header">
            <h2 id="classroomModalTitle">Classroom Details</h2>
            <button type="button" class="close-btn" onclick="closeClassroomModal()">×</button>
        </div>
        <div id="classroomModalContent" class="classroom-info-content">
            <div class="loading">Loading classroom information...</div>
        </div>
    </div>
</div>


<script>
// ============================================
// CLASSROOM VIEW FUNCTION
// ============================================

window.viewClassroom = async function(classroomCode, userName) {
    if (!classroomCode) return;
    
    const modal = document.getElementById('classroomViewModal');
    const title = document.getElementById('classroomModalTitle');
    const content = document.getElementById('classroomModalContent');
    
    title.textContent = `Classroom: ${classroomCode}`;
    content.innerHTML = '<div class="loading">Loading classroom information...</div>';
    modal.classList.add('show');
    document.body.classList.add('modal-open');
    
    try {
        const response = await fetch(`get_classroom_info.php?code=${encodeURIComponent(classroomCode)}`);
        const data = await response.json();
        
        if (data.success) {
            let studentsHtml = '';
            if (data.students && data.students.length > 0) {
                studentsHtml = `
                    <div class="students-list">
                        <h4>Enrolled Students (${data.students.length})</h4>
                        ${data.students.map(student => `
                            <div class="student-item">
                                <div>
                                    <div class="student-name">${escapeHtml(student.username)}</div>
                                    <div class="student-email">${escapeHtml(student.email)}</div>
                                </div>
                                <div class="student-status approved">✓ Enrolled</div>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                studentsHtml = '<div class="empty-students">No students enrolled in this classroom yet.</div>';
            }
            
            let pendingHtml = '';
            if (data.pending_requests && data.pending_requests.length > 0) {
                pendingHtml = `
                    <div class="students-list">
                        <h4>Pending Requests (${data.pending_requests.length})</h4>
                        ${data.pending_requests.map(request => `
                            <div class="student-item">
                                <div>
                                    <div class="student-name">${escapeHtml(request.username)}</div>
                                    <div class="student-email">${escapeHtml(request.email)}</div>
                                </div>
                                <div class="student-status pending">⏳ Pending</div>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            content.innerHTML = `
                <div class="classroom-stats">
                    <div class="classroom-stat-card">
                        <div class="classroom-stat-value">${data.stats.enrolled || 0}</div>
                        <div class="classroom-stat-label">Enrolled Students</div>
                    </div>
                    <div class="classroom-stat-card">
                        <div class="classroom-stat-value">${data.stats.pending || 0}</div>
                        <div class="classroom-stat-label">Pending Requests</div>
                    </div>
                    <div class="classroom-stat-card">
                        <div class="classroom-stat-value">${data.stats.total || 0}</div>
                        <div class="classroom-stat-label">Total Students</div>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-card-title">Classroom Information</div>
                    <div class="info-row">
                        <span class="info-label">Classroom Code:</span>
                        <span class="info-value">${escapeHtml(classroomCode)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Teacher:</span>
                        <span class="info-value">${escapeHtml(data.teacher_name || userName)}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created:</span>
                        <span class="info-value">${escapeHtml(data.created_at || 'N/A')}</span>
                    </div>
                </div>
                ${studentsHtml}
                ${pendingHtml}
            `;
        } else {
            content.innerHTML = `<div class="error-message">${data.message}</div>`;
        }
    } catch (error) {
        content.innerHTML = `<div class="error-message">Error loading classroom information: ${error.message}</div>`;
    }
};

function closeClassroomModal() {
    const modal = document.getElementById('classroomViewModal');
    modal.classList.remove('show');
    document.body.classList.remove('modal-open');
}

// ============================================
// MODAL FUNCTIONS
// ============================================

function openModal(modal) {
    modal.classList.add('show');
    document.body.classList.add('modal-open');
}

function closeModal(modal) {
    modal.classList.remove('show');
    document.body.classList.remove('modal-open');
}

// Get modal elements
const createModal = document.getElementById('createModal');
const editModal = document.getElementById('editModal');
const resetModal = document.getElementById('resetPasswordModal');
const deleteModal = document.getElementById('deleteModal');

function closeAll() {
    if (createModal) closeModal(createModal);
    if (editModal) closeModal(editModal);
    if (resetModal) closeModal(resetModal);
    if (deleteModal) closeModal(deleteModal);
}

// Open create modal
document.getElementById('openCreateModal')?.addEventListener('click', () => {
    clearModalError('create');
    openModal(createModal);
});

// Close buttons
document.getElementById('closeCreateModal')?.addEventListener('click', () => closeModal(createModal));
document.getElementById('closeEditModal')?.addEventListener('click', () => closeModal(editModal));
document.getElementById('closeResetModal')?.addEventListener('click', () => closeModal(resetModal));
document.getElementById('closeDeleteModal')?.addEventListener('click', () => closeModal(deleteModal));
document.getElementById('cancelDeleteBtn')?.addEventListener('click', () => closeModal(deleteModal));

// Click outside to close
[createModal, editModal, resetModal, deleteModal].forEach(modal => {
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal);
        });
    }
});

// Escape key to close
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAll();
        closeUserInfo();
        closeClassroomModal();
    }
});

// Toast notification
function showNotification(message, type = 'success') {
    const toast = document.getElementById('notificationToast');
    toast.textContent = message;
    toast.style.background = type === 'success' ? '#10b981' : '#ef4444';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

function showModalError(modalId, message) {
    const errorDiv = document.getElementById(modalId + 'ErrorMsg');
    if (errorDiv) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        setTimeout(() => errorDiv.style.display = 'none', 5000);
    }
}

function clearModalError(modalId) {
    const errorDiv = document.getElementById(modalId + 'ErrorMsg');
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
    }
}

function setButtonLoading(button, isLoading) {
    if (isLoading) {
        button.originalText = button.textContent;
        button.textContent = 'Processing...';
        button.disabled = true;
    } else {
        button.textContent = button.originalText;
        button.disabled = false;
    }
}

// Sort functionality
document.querySelectorAll('.sortable').forEach(header => {
    header.addEventListener('click', () => {
        const column = header.dataset.column;
        const order = header.dataset.order;
        window.location.href = `?sort=${column}&order=${order}`;
    });
});

// Create user
document.getElementById('createUserForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    clearModalError('create');
    setButtonLoading(submitBtn, true);
    
    try {
        const response = await fetch('user_create.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('User created successfully!', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showModalError('create', data.message || 'Failed to create user');
            setButtonLoading(submitBtn, false);
        }
    } catch (error) {
        showModalError('create', 'Network error: ' + error.message);
        setButtonLoading(submitBtn, false);
    }
});

// Edit user
document.getElementById('editUserForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.target.querySelector('button[type="submit"]');
    clearModalError('edit');
    setButtonLoading(submitBtn, true);
    
    try {
        const response = await fetch('user_edit.php', {
            method: 'POST',
            body: new FormData(e.target)
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('User updated successfully!', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showModalError('edit', data.message || 'Failed to update user');
            setButtonLoading(submitBtn, false);
        }
    } catch (error) {
        showModalError('edit', 'Network error: ' + error.message);
        setButtonLoading(submitBtn, false);
    }
});

// Reset password
document.getElementById('resetPasswordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const newPassword = document.getElementById('reset_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        showModalError('reset', 'Passwords do not match!');
        return;
    }
    
    if (newPassword.length < 6) {
        showModalError('reset', 'Password must be at least 6 characters!');
        return;
    }
    
    const submitBtn = e.target.querySelector('button[type="submit"]');
    clearModalError('reset');
    setButtonLoading(submitBtn, true);
    
    try {
        const formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('user_id', document.getElementById('reset_user_id').value);
        formData.append('new_password', newPassword);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('Password reset successfully!', 'success');
            closeModal(resetModal);
        } else {
            showModalError('reset', data.message || 'Failed to reset password');
            setButtonLoading(submitBtn, false);
        }
    } catch (error) {
        showModalError('reset', 'Network error: ' + error.message);
        setButtonLoading(submitBtn, false);
    }
});

// Delete user
document.getElementById('confirmDeleteBtn')?.addEventListener('click', async () => {
    const id = document.getElementById('delete_user_id').value;
    const btn = document.getElementById('confirmDeleteBtn');
    const originalText = btn.textContent;
    
    btn.textContent = 'Deleting...';
    btn.disabled = true;
    
    try {
        const response = await fetch(`user_delete.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showNotification('User deleted successfully!', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showModalError('delete', data.message || 'Failed to delete user');
            btn.textContent = originalText;
            btn.disabled = false;
        }
    } catch (error) {
        showModalError('delete', 'Network error: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
});

// View user info overlay
window.viewUser = async function(userId) {
    const overlay = document.getElementById('userInfoOverlay');
    const content = document.getElementById('userInfoContent');
    content.innerHTML = '<div class="loading">Loading user information...</div>';
    overlay.classList.add('show');
    document.body.classList.add('modal-open');
    
    try {
        const response = await fetch(`get_user_info.php?id=${userId}`);
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

window.closeUserInfo = function() {
    document.getElementById('userInfoOverlay').classList.remove('show');
    document.body.classList.remove('modal-open');
};

window.openEdit = function(id, username, email, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    clearModalError('edit');
    openModal(editModal);
};

window.openResetPassword = function(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').value = username;
    document.getElementById('reset_password').value = '';
    document.getElementById('confirm_password').value = '';
    clearModalError('reset');
    openModal(resetModal);
};

window.openDelete = function(id) {
    document.getElementById('delete_user_id').value = id;
    clearModalError('delete');
    openModal(deleteModal);
};

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

</body>
</html>