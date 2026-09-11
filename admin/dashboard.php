<?php
// Go up from admin/ to root, then into includes/
require_once __DIR__ . '/../includes/config.php';

// Fixed: added missing slash between __DIR__ and '../includes/'
include __DIR__ . '/../includes/header.php';
$user = current_user();

if (!$user || $user['role'] !== 'ADMIN') {
    redirect_to('../account.php');
}
?>

<link rel="stylesheet" href="<?= BASE_URL.'assets/css/dashboard.css' ?>">
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
    <h1 style="margin: 0;">Admin Panel</h1>
    <a href="<?= BASE_URL.'account.php' ?>" class="back-to-account-btn">← Back to Account</a>
</div>

<div class="card-grid">

    <div class="card">
        <h3>User Management</h3>
        <p>Create, edit and delete users.</p>

        <a href="users.php">
            <button>Manage Users</button>
        </a>
    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>