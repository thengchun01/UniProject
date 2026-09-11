<?php
require_once __DIR__ . '/../includes/config.php';

$user = current_user();

if (!$user || strtoupper($user['role']) !== 'STUDENT') {
    redirect_to(url_path('account.php'));
}

if ($user['classroom_status'] !== 'APPROVED' || empty($user['classroom'])) {
    redirect_to(url_path('account.php'));
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h1>My Classroom</h1>
        <a href="<?= e(url_path('account.php')) ?>" class="back-btn">← Back to Account</a>
    </div>
    
    <div class="classroom-info">
        <p><strong>Classroom Code:</strong> <?= e($user['classroom']) ?></p>
        <p><strong>Status:</strong> <span class="approved-badge">Enrolled</span></p>
    </div>
    
    <p>This feature is coming soon. Your teacher will share classroom activities here.</p>
</div>

<style>
.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 40px;
}

.back-btn {
    display: inline-block;
    padding: 10px 20px;
    background: #fff;
    color: #000;
    border: 2px solid #000;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.back-btn:hover {
    background: #000;
    color: #fff;
}

.classroom-info {
    background: #f5f5f5;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.approved-badge {
    display: inline-block;
    padding: 4px 12px;
    background: #4caf50;
    color: white;
    border-radius: 20px;
    font-size: 12px;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>