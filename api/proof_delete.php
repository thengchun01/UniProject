<?php
// Admin-only proof delete (file removed, DB path cleared, DELETE logged).
// POST: kind=FEE|COMMISSION, lesson_id, enrollment_id (FEE only).
// JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function proof_del_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    proof_del_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    proof_del_error('POST request required.', 405);
}
if ($lessonsManager === null) {
    proof_del_error('Lessons module is not available. Import database/psm_lessons.sql first.', 500);
}

$kind = strtoupper(trim($_POST['kind'] ?? ''));
$lessonId = (int) ($_POST['lesson_id'] ?? 0);
$enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);

if (!in_array($kind, ['FEE', 'COMMISSION'], true) || $lessonId <= 0) {
    proof_del_error('Invalid proof target.');
}

$db = db();
try {
    if ($kind === 'FEE') {
        if ($enrollmentId <= 0) {
            proof_del_error('Enrollment is required.');
        }
        $stmt = $db->prepare('SELECT proof_path FROM lesson_enrollments WHERE enrollment_id = :eid AND lesson_id = :lid');
        $stmt->execute(['eid' => $enrollmentId, 'lid' => $lessonId]);
        $path = $stmt->fetchColumn();
        if (!is_string($path) || $path === '') {
            proof_del_error('No proof to delete.');
        }
        $stmt = $db->prepare('UPDATE lesson_enrollments SET proof_path = NULL WHERE enrollment_id = :eid');
        $stmt->execute(['eid' => $enrollmentId]);
    } else {
        $lesson = $lessonsManager->getLesson($lessonId);
        if ($lesson === null || empty($lesson['commission_proof_path'])) {
            proof_del_error('No proof to delete.');
        }
        $path = $lesson['commission_proof_path'];
        $stmt = $db->prepare('UPDATE lessons SET commission_proof_path = NULL WHERE lesson_id = :lid');
        $stmt->execute(['lid' => $lessonId]);
    }

    $proofDir = realpath(__DIR__ . '/../uploads/proofs');
    $full = realpath(__DIR__ . '/../' . $path);
    if ($proofDir !== false && $full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
        @unlink($full);
    }

    $lessonsManager->logProof(
        $kind,
        $lessonId,
        $kind === 'FEE' ? $enrollmentId : null,
        $path,
        'DELETE',
        (int) $user['user_id']
    );

    echo json_encode(['success' => true, 'data' => ['deleted' => true]]);
} catch (Throwable $e) {
    proof_del_error('Unable to delete proof.', 500);
}
