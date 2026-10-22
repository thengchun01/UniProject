<?php
// Admin proof upload (fee or commission); teachers may upload commission proof for their own lessons only.
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
$role = normalize_role($user['role'] ?? 'GUEST');
if (!$user || !in_array($role, ['ADMIN', 'TEACHER'], true)) {
    proof_error('ADMIN or TEACHER access required.', 403);
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
$feeId = (int) ($_POST['fee_id'] ?? 0);

if ($role === 'TEACHER' && $kind !== 'COMMISSION') {
    proof_error('Teachers may upload commission proof only.', 403);
}
if ($kind === 'MONTHLY_FEE') {
    if ($role !== 'ADMIN') {
        proof_error('ADMIN access required for payment proof uploads.', 403);
    }
    if ($feeId <= 0) {
        proof_error('Invalid monthly record.');
    }
} else {
    if (!in_array($kind, ['FEE', 'COMMISSION'], true) || $lessonId <= 0) {
        proof_error('Invalid proof target.');
    }
    if ($kind === 'FEE' && $enrollmentId <= 0) {
        proof_error('Enrollment is required for fee proofs.');
    }
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
    if ($kind === 'MONTHLY_FEE') {
        $stmt = $db->prepare('SELECT fee_id FROM monthly_fees WHERE fee_id = :id');
        $stmt->execute(['id' => $feeId]);
        if ($stmt->fetchColumn() === false) {
            proof_error('Monthly record not found.');
        }
        $target = 'monthly_' . $feeId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    } elseif ($kind === 'FEE') {
        $stmt = $db->prepare('SELECT enrollment_id FROM lesson_enrollments WHERE enrollment_id = :eid AND lesson_id = :lid');
        $stmt->execute(['eid' => $enrollmentId, 'lid' => $lessonId]);
        if ($stmt->fetchColumn() === false) {
            proof_error('Enrollment not found for this lesson.');
        }
        $target = 'fee_' . $lessonId . '_' . $enrollmentId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    } else {
        $lesson = $lessonsManager->getLesson($lessonId);
        if ($lesson === null) {
            proof_error('Lesson not found.');
        }
        if ($role === 'TEACHER' && (int) ($lesson['teacher_id'] ?? 0) !== (int) $user['user_id']) {
            proof_error('You can upload commission proof only for your own lessons.', 403);
        }
        $target = 'commission_' . $lessonId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    $dest = $proofDir . '/' . $target;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        proof_error('Unable to store proof file.', 500);
    }
    $relPath = 'uploads/proofs/' . $target;

    if ($kind === 'MONTHLY_FEE') {
        // A payment proof is the payment confirmation; reflect that in the status.
        $stmt = $db->prepare("UPDATE monthly_fees SET proof_path = :path, status = 'PAID' WHERE fee_id = :id");
        $stmt->execute(['path' => $relPath, 'id' => $feeId]);
    } elseif ($kind === 'FEE') {
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
        $kind === 'MONTHLY_FEE' ? 'FEE' : $kind,
        $kind === 'MONTHLY_FEE' ? null : $lessonId,
        $kind === 'FEE' ? $enrollmentId : null,
        $relPath,
        'UPLOAD',
        (int) $user['user_id']
    );

    echo json_encode(['success' => true, 'data' => ['path' => $relPath]]);
} catch (Throwable $e) {
    proof_error('Unable to upload proof.', 500);
}
