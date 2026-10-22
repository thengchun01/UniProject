<?php
// Admin-only enrollment + status updates.
// POST: action=add|set_fee|set_payment|remove|set_commission_status
// JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function enroll_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    enroll_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enroll_error('POST request required.', 405);
}
if ($lessonsManager === null) {
    enroll_error('Lessons module is not available. Import database/psm_lessons.sql first.', 500);
}


// Keep monthly transaction amounts synchronized with enrollment fees. A monthly
// record represents the sum of this student's fees for the same month/group.
function sync_monthly_fee_amount(PDO $db, int $lessonId, ?int $studentId = null): void
{
    $stmt = $db->prepare('SELECT lesson_date, recurrence_group FROM lessons WHERE lesson_id = :id LIMIT 1');
    $stmt->execute(['id' => $lessonId]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) return;

    $month = substr((string) $lesson['lesson_date'], 0, 7);
    $group = (string) ($lesson['recurrence_group'] ?? '');
    $studentsSql = 'SELECT DISTINCT e.student_id FROM lesson_enrollments e INNER JOIN lessons l ON l.lesson_id = e.lesson_id WHERE SUBSTR(l.lesson_date, 1, 7) = :month AND COALESCE(l.recurrence_group, \'\') = :grp';
    $params = ['month' => $month, 'grp' => $group];
    if ($studentId !== null) {
        $studentsSql .= ' AND e.student_id = :sid';
        $params['sid'] = $studentId;
    } else {
        $studentsSql .= ' AND e.lesson_id = :lid';
        $params['lid'] = $lessonId;
    }
    $stmt = $db->prepare($studentsSql);
    $stmt->execute($params);
    $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $amountSql = 'SELECT COALESCE(SUM(e.fee_amount), 0) FROM lesson_enrollments e INNER JOIN lessons l ON l.lesson_id = e.lesson_id WHERE e.student_id = :sid AND SUBSTR(l.lesson_date, 1, 7) = :month AND COALESCE(l.recurrence_group, \'\') = :grp';
    $amountStmt = $db->prepare($amountSql);
    $updateStmt = $db->prepare('UPDATE monthly_fees SET amount = :amount WHERE student_id = :sid AND month = :month AND COALESCE(recurrence_group, \'\') = :grp');
    foreach ($studentIds as $sid) {
        $amountStmt->execute(['sid' => (int) $sid, 'month' => $month, 'grp' => $group]);
        $updateStmt->execute(['amount' => round((float) $amountStmt->fetchColumn(), 2), 'sid' => (int) $sid, 'month' => $month, 'grp' => $group]);
    }
}

$action = trim($_POST['action'] ?? '');
$db = db();

try {
    if ($action === 'set_commission_status') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        $status = strtoupper(trim($_POST['status'] ?? ''));
        if ($lessonId <= 0 || !in_array($status, ['UNPAID', 'PENDING', 'PAID'], true)) {
            enroll_error('Invalid lesson or status.');
        }
        if ($status === 'PAID') {
            enroll_error('Commission can be marked PAID only when the assigned teacher approves its proof.');
        }
        $stmt = $db->prepare('UPDATE lessons SET commission_status = :st WHERE lesson_id = :id');
        $stmt->execute(['st' => $status, 'id' => $lessonId]);
        echo json_encode(['success' => true, 'data' => ['lesson_id' => $lessonId, 'status' => $status]]);
        exit;
    }

    if ($action === 'add') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $fee = $_POST['fee_amount'] ?? 0;
        if ($lessonId <= 0 || $studentId <= 0) {
            enroll_error('Lesson and student are required.');
        }
        if (!is_numeric($fee) || (float) $fee < 0) {
            enroll_error('Fee must be 0 or more.');
        }
        $stmt = $db->prepare('SELECT role FROM users WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $studentId]);
        $role = $stmt->fetchColumn();
        if ($role === false || normalize_role((string) $role) !== 'STUDENT') {
            enroll_error('Choose a valid student.');
        }
        if ($lessonsManager->getLesson($lessonId) === null) {
            enroll_error('Lesson not found.');
        }
        $lessonsManager->addEnrollment($lessonId, $studentId, round((float) $fee, 2));
        echo json_encode(['success' => true, 'data' => ['lesson_id' => $lessonId, 'student_id' => $studentId]]);
        exit;
    }

    if ($action === 'set_fee') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $fee = $_POST['fee_amount'] ?? null;
        if ($enrollmentId <= 0 || !is_numeric($fee) || (float) $fee < 0) {
            enroll_error('Invalid enrollment or fee.');
        }
        $lookup = $db->prepare('SELECT lesson_id, student_id FROM lesson_enrollments WHERE enrollment_id = :id LIMIT 1');
        $lookup->execute(['id' => $enrollmentId]);
        $enrollment = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$enrollment) enroll_error('Enrollment not found.');
        $stmt = $db->prepare('UPDATE lesson_enrollments SET fee_amount = :fee WHERE enrollment_id = :id');
        $stmt->execute(['fee' => round((float) $fee, 2), 'id' => $enrollmentId]);
        sync_monthly_fee_amount($db, (int) $enrollment['lesson_id'], (int) $enrollment['student_id']);
        echo json_encode(['success' => true, 'data' => ['enrollment_id' => $enrollmentId]]);
        exit;
    }

    // Issue 60: set one fee for every enrolled student of a lesson (series row edit).
    if ($action === 'set_lesson_fees') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        $fee = $_POST['fee_amount'] ?? null;
        if ($lessonId <= 0 || !is_numeric($fee) || (float) $fee < 0) {
            enroll_error('Invalid lesson or fee.');
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM lesson_enrollments WHERE lesson_id = :id');
        $stmt->execute(['id' => $lessonId]);
        if ((int) $stmt->fetchColumn() === 0) {
            enroll_error('This lesson has no enrolled students.');
        }
        $stmt = $db->prepare('UPDATE lesson_enrollments SET fee_amount = :fee WHERE lesson_id = :id');
        $stmt->execute(['fee' => round((float) $fee, 2), 'id' => $lessonId]);
        sync_monthly_fee_amount($db, $lessonId);
        echo json_encode(['success' => true, 'data' => ['lesson_id' => $lessonId]]);
        exit;
    }

    if ($action === 'set_payment') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $status = strtoupper(trim($_POST['status'] ?? ''));
        if ($enrollmentId <= 0 || !in_array($status, ['UNPAID', 'PENDING', 'PAID'], true)) {
            enroll_error('Invalid enrollment or status.');
        }
        $stmt = $db->prepare('UPDATE lesson_enrollments SET payment_status = :st WHERE enrollment_id = :id');
        $stmt->execute(['st' => $status, 'id' => $enrollmentId]);
        echo json_encode(['success' => true, 'data' => ['enrollment_id' => $enrollmentId, 'status' => $status]]);
        exit;
    }

    if ($action === 'remove') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        if ($enrollmentId <= 0) {
            enroll_error('Invalid enrollment.');
        }
        // Delete physical fee proof file scoped to uploads/proofs (log row keeps the path).
        $stmt = $db->prepare('SELECT proof_path FROM lesson_enrollments WHERE enrollment_id = :id');
        $stmt->execute(['id' => $enrollmentId]);
        $path = $stmt->fetchColumn();
        $stmt = $db->prepare('DELETE FROM lesson_enrollments WHERE enrollment_id = :id');
        $stmt->execute(['id' => $enrollmentId]);
        if (is_string($path) && $path !== '') {
            $proofDir = realpath(__DIR__ . '/../uploads/proofs');
            $full = realpath(__DIR__ . '/../' . $path);
            if ($proofDir !== false && $full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
                @unlink($full);
            }
        }
        echo json_encode(['success' => true, 'data' => ['removed' => $enrollmentId]]);
        exit;
    }

    enroll_error('Unknown action.');
} catch (Throwable $e) {
    enroll_error('Unable to update enrollment.', 500);
}
