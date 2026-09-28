<?php
// Admin-only proof image upload (fee or commission).
// POST multipart: kind=FEE|COMMISSION, lesson_id, enrollment_id (FEE only), file field "proof".
// JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function proof_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    proof_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    proof_error('POST request required.', 405);
}
if ($lessonsManager === null) {
    proof_error('Lessons module is not available. Import database/psm_lessons.sql first.', 500);
}

$kind = strtoupper(trim($_POST['kind'] ?? ''));
$lessonId = (int) ($_POST['lesson_id'] ?? 0);
$enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);

if (!in_array($kind, ['FEE', 'COMMISSION'], true) || $lessonId <= 0) {
    proof_error('Invalid proof target.');
}
if ($kind === 'FEE' && $enrollmentId <= 0) {
    proof_error('Enrollment is required for fee proofs.');
}
if (empty($_FILES['proof']) || ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    proof_error('Choose a proof image to upload.');
}

$file = $_FILES['proof'];
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
    proof_error('Proof file must be 5MB or smaller.');
}
$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
    proof_error('Proof must be JPG, PNG, WEBP or PDF.');
}
if (!is_uploaded_file($file['tmp_name'] ?? '')) {
    proof_error('Invalid upload.');
}

$proofDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($proofDir) && !@mkdir($proofDir, 0755, true)) {
    proof_error('Proof storage is not available.', 500);
}

$db = db();
try {
    if ($kind === 'FEE') {
        $stmt = $db->prepare('SELECT enrollment_id FROM lesson_enrollments WHERE enrollment_id = :eid AND lesson_id = :lid');
        $stmt->execute(['eid' => $enrollmentId, 'lid' => $lessonId]);
        if ($stmt->fetchColumn() === false) {
            proof_error('Enrollment not found for this lesson.');
        }
        $target = 'fee_' . $lessonId . '_' . $enrollmentId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    } else {
        if ($lessonsManager->getLesson($lessonId) === null) {
            proof_error('Lesson not found.');
        }
        $target = 'commission_' . $lessonId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    $dest = $proofDir . '/' . $target;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        proof_error('Unable to store proof file.', 500);
    }
    $relPath = 'uploads/proofs/' . $target;

    if ($kind === 'FEE') {
        $stmt = $db->prepare(
            "UPDATE lesson_enrollments SET proof_path = :path, proof_uploaded_at = CURRENT_TIMESTAMP,
              payment_status = CASE WHEN payment_status = 'UNPAID' THEN 'PENDING' ELSE payment_status END
             WHERE enrollment_id = :eid"
        );
        $stmt->execute(['path' => $relPath, 'eid' => $enrollmentId]);
    } else {
        $stmt = $db->prepare(
            "UPDATE lessons SET commission_proof_path = :path,
              commission_status = CASE WHEN commission_status = 'UNPAID' THEN 'PENDING' ELSE commission_status END
             WHERE lesson_id = :lid"
        );
        $stmt->execute(['path' => $relPath, 'lid' => $lessonId]);
    }

    $lessonsManager->logProof(
        $kind,
        $lessonId,
        $kind === 'FEE' ? $enrollmentId : null,
        $relPath,
        'UPLOAD',
        (int) $user['user_id']
    );

    echo json_encode(['success' => true, 'data' => ['path' => $relPath]]);
} catch (Throwable $e) {
    proof_error('Unable to upload proof.', 500);
}
