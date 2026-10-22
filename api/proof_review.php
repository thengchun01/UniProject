<?php
// Review uploaded transaction proof. Payment proofs are reviewed by ADMIN;
// commission proofs are reviewed by the assigned TEACHER.
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function proof_review_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user) {
    proof_review_error('Login required.', 401);
}
$role = normalize_role($user['role'] ?? 'GUEST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    proof_review_error('POST request required.', 405);
}
if ($lessonsManager === null || !is_database_connected()) {
    proof_review_error('Transaction module or database is unavailable.', 500);
}

$action = strtoupper(trim((string) ($_POST['action'] ?? '')));
$kind = strtoupper(trim((string) ($_POST['kind'] ?? '')));
$feeId = (int) ($_POST['fee_id'] ?? 0);
$lessonId = (int) ($_POST['lesson_id'] ?? 0);
if (!in_array($action, ['APPROVE', 'REJECT'], true)) {
    proof_review_error('Choose approve or reject.');
}

$db = db();
try {
    if ($kind === 'MONTHLY_FEE') {
        if ($role !== 'ADMIN') {
            proof_review_error('Only an admin may review student payment proofs.', 403);
        }
        if ($feeId <= 0) {
            proof_review_error('Invalid monthly transaction.');
        }
        $stmt = $db->prepare('SELECT fee_id, proof_path, status FROM monthly_fees WHERE fee_id = :id LIMIT 1');
        $stmt->execute(['id' => $feeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            proof_review_error('Monthly transaction not found.');
        }
        if (empty($row['proof_path']) || strtoupper((string) $row['status']) !== 'PENDING') {
            proof_review_error('This transaction has no proof awaiting approval.');
        }

        $path = (string) $row['proof_path'];
        if ($action === 'APPROVE') {
            $stmt = $db->prepare("UPDATE monthly_fees SET status = 'PAID' WHERE fee_id = :id AND status = 'PENDING'");
            $stmt->execute(['id' => $feeId]);
        } else {
            $stmt = $db->prepare("UPDATE monthly_fees SET status = 'UNPAID', proof_path = NULL WHERE fee_id = :id AND status = 'PENDING'");
            $stmt->execute(['id' => $feeId]);
        }
        $lessonsManager->logProof('FEE', null, null, $path, $action, (int) $user['user_id']);
    } elseif ($kind === 'COMMISSION') {
        if ($role !== 'TEACHER') {
            proof_review_error('Only a teacher may review commission proofs.', 403);
        }
        if ($lessonId <= 0) {
            proof_review_error('Invalid lesson.');
        }
        $stmt = $db->prepare('SELECT lesson_id, teacher_id, commission_proof_path, commission_status FROM lessons WHERE lesson_id = :id LIMIT 1');
        $stmt->execute(['id' => $lessonId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            proof_review_error('Lesson not found.');
        }
        if ((int) ($row['teacher_id'] ?? 0) !== (int) $user['user_id']) {
            proof_review_error('You can review commission proof only for your own lessons.', 403);
        }
        if (empty($row['commission_proof_path']) || strtoupper((string) $row['commission_status']) !== 'PENDING') {
            proof_review_error('This commission has no proof awaiting approval.');
        }

        $path = (string) $row['commission_proof_path'];
        if ($action === 'APPROVE') {
            $stmt = $db->prepare("UPDATE lessons SET commission_status = 'PAID' WHERE lesson_id = :id AND commission_status = 'PENDING'");
            $stmt->execute(['id' => $lessonId]);
        } else {
            $stmt = $db->prepare("UPDATE lessons SET commission_status = 'UNPAID', commission_proof_path = NULL WHERE lesson_id = :id AND commission_status = 'PENDING'");
            $stmt->execute(['id' => $lessonId]);
        }
        $lessonsManager->logProof('COMMISSION', $lessonId, null, $path, $action, (int) $user['user_id']);
    } else {
        proof_review_error('Invalid proof type.');
    }

    if ($action === 'REJECT') {
        $proofDir = realpath(__DIR__ . '/../uploads/proofs');
        $full = realpath(__DIR__ . '/../' . $path);
        if ($proofDir !== false && $full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
            @unlink($full);
        }
    }

    echo json_encode(['success' => true, 'data' => ['action' => $action, 'kind' => $kind]]);
} catch (Throwable $e) {
    proof_review_error('Unable to review proof.', 500);
}
