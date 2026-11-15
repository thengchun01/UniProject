<?php
require_once __DIR__ . '/includes/config.php';

$user = current_user();
if (!$user) {
    redirect_to('account.php?mode=login');
}
$role = normalize_role($user['role'] ?? 'GUEST');
if (!in_array($role, ['ADMIN', 'TEACHER', 'STUDENT'], true)) {
    redirect_to('account.php');
}

$db = is_database_connected() ? db() : null;
$stats = [];
$upcoming = [];
$dashboardError = null;
$money = static fn($amount) => 'RM ' . number_format((float) $amount, 2);

if ($db) {
    try {
        if ($lessonsManager) {
            $lessonsManager->autoCompletePast();
            $filters = ['from' => date('Y-m-d'), 'status' => 'SCHEDULED'];
            if ($role === 'TEACHER') {
                $filters['teacher_id'] = (int) $user['user_id'];
            } elseif ($role === 'STUDENT') {
                $filters['student_id'] = (int) $user['user_id'];
            }
            $upcoming = $lessonsManager->listLessons($filters, 'lesson_date', 'ASC', 6, 0);
        }

        if ($role === 'ADMIN') {
            $stats = [
                ['label' => 'Total users', 'value' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'hint' => 'All registered accounts'],
                ['label' => 'Active courses', 'value' => (int) $db->query('SELECT COUNT(*) FROM courses WHERE is_active = 1')->fetchColumn(), 'hint' => 'Available course presets'],
                ['label' => 'Today’s classes', 'value' => (int) $db->query("SELECT COUNT(*) FROM lessons WHERE lesson_date = CURDATE() AND status = 'SCHEDULED'")->fetchColumn(), 'hint' => 'Scheduled for today'],
            ];
            $pending = $db->query("SELECT COUNT(*) FROM monthly_fees WHERE UPPER(status) = 'PENDING'")->fetchColumn();
            $stats[] = ['label' => 'Pending fee proofs', 'value' => (int) $pending, 'hint' => 'Monthly payments awaiting review'];
        } elseif ($role === 'TEACHER') {
            $id = (int) $user['user_id'];
            $stmt = $db->prepare("SELECT COUNT(DISTINCT e.student_id) FROM lesson_enrollments e INNER JOIN lessons l ON l.lesson_id=e.lesson_id WHERE l.teacher_id=?");
            $stmt->execute([$id]);
            $students = (int) $stmt->fetchColumn();

            $commissionTotal = 0.0;
            $commissionPending = 0;
            if ($lessonsManager) {
                $stmt = $db->prepare("SELECT l.commission_type,l.commission_value,l.commission_status,COALESCE(SUM(e.fee_amount),0) AS fees FROM lessons l LEFT JOIN lesson_enrollments e ON e.lesson_id=l.lesson_id WHERE l.teacher_id=? GROUP BY l.lesson_id,l.commission_type,l.commission_value,l.commission_status");
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    [$type, $value] = $lessonsManager->resolveCommission($id, $row['commission_type'] ?? null, $row['commission_value'] ?? null);
                    $commissionTotal += $lessonsManager->commissionPayout($type, (float) $value, (float) $row['fees']);
                    if (strtoupper((string) ($row['commission_status'] ?? '')) !== 'PAID') $commissionPending++;
                }
            }
            $stats = [
                ['label' => 'My students', 'value' => $students, 'hint' => 'Students across your classes'],
                ['label' => 'Upcoming classes', 'value' => count($upcoming), 'hint' => 'Next scheduled lessons'],
                ['label' => 'Commission earned', 'value' => $money($commissionTotal), 'hint' => 'Calculated from your lessons'],
                ['label' => 'Commission awaiting payment', 'value' => $commissionPending, 'hint' => 'Lessons not marked as paid'],
            ];
        } else {
            $id = (int) $user['user_id'];
            $stmt = $db->prepare("SELECT COUNT(DISTINCT COALESCE(c.course_id, l.title)) FROM lesson_enrollments e INNER JOIN lessons l ON l.lesson_id=e.lesson_id LEFT JOIN courses c ON c.course_id=l.course_id WHERE e.student_id=?");
            $stmt->execute([$id]);
            $enrolled = (int) $stmt->fetchColumn();
            $stmt = $db->prepare("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM monthly_fees WHERE student_id=? AND UPPER(status)<>'PAID'");
            $stmt->execute([$id]);
            [$feeCount, $feeTotal] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $stats = [
                ['label' => 'Enrolled courses', 'value' => $enrolled, 'hint' => 'Courses linked to your classes'],
                ['label' => 'Upcoming classes', 'value' => count($upcoming), 'hint' => 'Your next scheduled lessons'],
                ['label' => 'Outstanding fees', 'value' => $money($feeTotal), 'hint' => (int) $feeCount . ' monthly fee record(s) unpaid or pending'],
            ];
        }
    } catch (Throwable $e) {
        $dashboardError = 'Some dashboard information could not be loaded. Check that the latest PSM database migrations are installed.';
    }
}

$pageTitle = 'Manage';
include __DIR__ . '/includes/header.php';
?>
<section class="workspace-page">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">MANAGE WORKSPACE</p>
            <h1>Good day, <?= e($user['username'] ?? 'there') ?>.</h1>
            <p>Your <?= e(strtolower($role)) ?> overview, important numbers and upcoming classes in one place.</p>
        </div>
        <a class="btn primary-btn" href="<?= e(url_path('schedule.php')) ?>">Open schedule</a>
    </div>

    <?php if ($dashboardError): ?><div class="alert error"><?= e($dashboardError) ?></div><?php endif; ?>

    <div class="workspace-stat-grid">
        <?php foreach ($stats as $stat): ?>
            <article class="workspace-stat-card">
                <span><?= e($stat['label']) ?></span>
                <strong><?= e($stat['value']) ?></strong>
                <small><?= e($stat['hint']) ?></small>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="workspace-content-grid">
        <section class="workspace-panel">
            <div class="workspace-panel-heading">
                <div><p class="eyebrow">YOUR TIMETABLE</p><h2>Upcoming classes</h2></div>
                <a class="text-button secondary" href="<?= e(url_path('schedule.php')) ?>">View all</a>
            </div>
            <?php if (!$upcoming): ?>
                <div class="workspace-empty"><strong>No upcoming classes</strong><p>There are no scheduled classes to show right now.</p></div>
            <?php else: ?>
                <div class="workspace-list">
                    <?php foreach ($upcoming as $lesson): ?>
                        <a class="workspace-list-item" href="<?= e(url_path('schedule.php')) ?>">
                            <span class="workspace-date"><strong><?= e(date('d', strtotime($lesson['lesson_date']))) ?></strong><small><?= e(date('M', strtotime($lesson['lesson_date']))) ?></small></span>
                            <span class="workspace-list-main">
                                <strong><?= e($lesson['title'] ?? $lesson['course_name'] ?? 'Piano class') ?></strong>
                                <small><?= e(date('D, d M Y', strtotime($lesson['lesson_date']))) ?> · <?= e(substr((string) ($lesson['start_time'] ?? ''), 0, 5)) ?>–<?= e(substr((string) ($lesson['end_time'] ?? ''), 0, 5)) ?></small>
                            </span>
                            <span class="workspace-list-arrow">›</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="workspace-panel">
            <div class="workspace-panel-heading"><div><p class="eyebrow">QUICK ACCESS</p><h2>Management tools</h2></div></div>
            <div class="workspace-shortcuts">
                <a href="<?= e(url_path('schedule.php')) ?>"><span>📅</span><strong>Schedule</strong><small>Classes and timetable</small></a>
                <a href="<?= e(url_path('transactions.php')) ?>"><span>💳</span><strong>Transactions</strong><small>Fees and commissions</small></a>
                <?php if ($role === 'ADMIN'): ?>
                    <a href="<?= e(url_path('courses.php')) ?>"><span>🎼</span><strong>Courses</strong><small>Course presets and fees</small></a>
                    <a href="<?= e(url_path('admin/users.php')) ?>"><span>👥</span><strong>Users</strong><small>Accounts and permissions</small></a>
                <?php endif; ?>
                <a href="<?= e(url_path('account.php')) ?>"><span>👤</span><strong>My account</strong><small>Profile and activity</small></a>
            </div>
        </section>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
