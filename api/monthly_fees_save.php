<?php
// Admin-only monthly fee records (function.md §4 item 1.5).
// POST: month (YYYY-MM), fees[student_id] = amount, status[student_id] = status.
// Upserts one row per student per month. JSON: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function monthly_fee_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    monthly_fee_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    monthly_fee_error('POST request required.', 405);
}
if (!is_database_connected()) {
    monthly_fee_error('Database is not connected.', 500);
}

$db = db();

// Deleting a transaction also deletes its linked class schedule. For a recurring
// class, the recurrence group is the shared identity across schedule + billing rows.
if (trim($_POST['action'] ?? '') === 'delete') {
    $fid = (int) ($_POST['fee_id'] ?? 0);
    if ($fid <= 0) {
        monthly_fee_error('Invalid record.');
    }

    $lookup = $db->prepare('SELECT student_id, month, recurrence_group, proof_path FROM monthly_fees WHERE fee_id = :id LIMIT 1');
    $lookup->execute(['id' => $fid]);
    $feeRow = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$feeRow) {
        monthly_fee_error('Transaction not found.');
    }

    $studentId = (int) $feeRow['student_id'];
    $month = (string) $feeRow['month'];
    $group = trim((string) ($feeRow['recurrence_group'] ?? ''));
    $proofPaths = [];
    if (!empty($feeRow['proof_path'])) {
        $proofPaths[] = (string) $feeRow['proof_path'];
    }
    $proofDir = realpath(__DIR__ . '/../uploads/proofs');
    $db->beginTransaction();
    try {
        if ($group !== '') {
            $monthlyProofs = $db->prepare('SELECT proof_path FROM monthly_fees WHERE recurrence_group = :g');
            $monthlyProofs->execute(['g' => $group]);
            foreach ($monthlyProofs->fetchAll(PDO::FETCH_COLUMN) as $path) {
                if (is_string($path) && $path !== '') $proofPaths[] = $path;
            }
            $lessonStmt = $db->prepare('SELECT lesson_id, commission_proof_path FROM lessons WHERE recurrence_group = :g');
            $lessonStmt->execute(['g' => $group]);
            $lessonRows = $lessonStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lessonIds = [];
            foreach ($lessonRows as $lessonRow) {
                $lessonIds[] = (int) $lessonRow['lesson_id'];
                if (!empty($lessonRow['commission_proof_path'])) {
                    $proofPaths[] = (string) $lessonRow['commission_proof_path'];
                }
            }
            if ($lessonIds !== []) {
                $marks = implode(',', array_fill(0, count($lessonIds), '?'));
                $enrollProofs = $db->prepare("SELECT proof_path FROM lesson_enrollments WHERE lesson_id IN ($marks)");
                $enrollProofs->execute($lessonIds);
                foreach ($enrollProofs->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    if (is_string($path) && $path !== '') $proofPaths[] = $path;
                }
                $deleteLessons = $db->prepare("DELETE FROM lessons WHERE lesson_id IN ($marks)");
                $deleteLessons->execute($lessonIds);
            }
            $deleteFees = $db->prepare('DELETE FROM monthly_fees WHERE recurrence_group = :g');
            $deleteFees->execute(['g' => $group]);
        } else {
            // Legacy/standalone monthly rows have no group key. Remove the
            // student's scheduled lessons in this month and their related fee rows.
            $lessonStmt = $db->prepare(
                "SELECT DISTINCT l.lesson_id, l.commission_proof_path
                 FROM lessons l
                 INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
                 WHERE e.student_id = :sid AND DATE_FORMAT(l.lesson_date, '%Y-%m') = :month
                   AND (l.recurrence_group IS NULL OR l.recurrence_group = '')"
            );
            $lessonStmt->execute(['sid' => $studentId, 'month' => $month]);
            $lessonRows = $lessonStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lessonIds = [];
            foreach ($lessonRows as $lessonRow) {
                $lessonIds[] = (int) $lessonRow['lesson_id'];
                if (!empty($lessonRow['commission_proof_path'])) {
                    $proofPaths[] = (string) $lessonRow['commission_proof_path'];
                }
            }
            if ($lessonIds !== []) {
                $marks = implode(',', array_fill(0, count($lessonIds), '?'));
                $enrollProofs = $db->prepare("SELECT proof_path FROM lesson_enrollments WHERE lesson_id IN ($marks)");
                $enrollProofs->execute($lessonIds);
                foreach ($enrollProofs->fetchAll(PDO::FETCH_COLUMN) as $path) {
                    if (is_string($path) && $path !== '') $proofPaths[] = $path;
                }
                $deleteLessons = $db->prepare("DELETE FROM lessons WHERE lesson_id IN ($marks)");
                $deleteLessons->execute($lessonIds);
            }
            $deleteFee = $db->prepare(
                "DELETE FROM monthly_fees WHERE student_id = :sid AND month = :month
                 AND (recurrence_group IS NULL OR recurrence_group = '')"
            );
            $deleteFee->execute(['sid' => $studentId, 'month' => $month]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        monthly_fee_error('Unable to delete the transaction and its linked class.', 500);
    }

    // Remove only proof files physically located in uploads/proofs.
    if ($proofDir !== false) {
        foreach (array_unique($proofPaths) as $path) {
            $full = realpath(__DIR__ . '/../' . $path);
            if ($full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
                @unlink($full);
            }
        }
    }
    echo json_encode(['success' => true, 'data' => ['deleted' => $fid, 'class_deleted' => true]]);
    exit;
}

$month = trim($_POST['month'] ?? '');
if (preg_match('/^\\d{4}-\\d{2}$/', $month) !== 1) {
    monthly_fee_error('Month must be YYYY-MM.');
}
[$y, $m] = array_map('intval', explode('-', $month));
if (!checkdate($m, 1, $y)) {
    monthly_fee_error('Month must be a real calendar month.');
}

$fees = $_POST['fees'] ?? [];
if (!is_array($fees)) {
    $fees = [];
}
$statuses = $_POST['status'] ?? [];
if (!is_array($statuses)) {
    $statuses = [];
}
if ($fees === []) {
    monthly_fee_error('No student fees submitted.');
}

$group = trim($_POST['group'] ?? '');
if ($group !== '' && preg_match('/^[0-9a-fA-F]{1,64}$/', $group) !== 1) {
    monthly_fee_error('Invalid class series.');
}

try {
    $roleStmt = $db->prepare('SELECT role FROM users WHERE user_id = :id LIMIT 1');
    $stmt = $db->prepare(
        'INSERT INTO monthly_fees (student_id, month, recurrence_group, amount, status)
         VALUES (:sid, :month, :grp, :amount, :status)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount), status = VALUES(status)'
    );
    $saved = 0;
    foreach ($fees as $sidRaw => $amountRaw) {
        $sid = (int) $sidRaw;
        if ($sid <= 0) {
            continue;
        }
        if ($amountRaw === '' || $amountRaw === null) {
            continue; // blank = leave the existing record unchanged
        }
        $roleStmt->execute(['id' => $sid]);
        if (normalize_role((string) $roleStmt->fetchColumn()) !== 'STUDENT') {
            monthly_fee_error('User ' . $sid . ' is not a student.');
        }
        if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
            monthly_fee_error('Fee must be 0 or more.');
        }
        $st = strtoupper(trim((string) ($statuses[$sidRaw] ?? $statuses[(string) $sidRaw] ?? 'UNPAID')));
        if (!in_array($st, ['UNPAID', 'PENDING', 'PAID'], true)) {
            monthly_fee_error('Status must be UNPAID, PENDING or PAID.');
        }
        $stmt->execute([
            'sid' => $sid,
            'month' => $month,
            'grp' => $group,
            'amount' => round((float) $amountRaw, 2),
            'status' => $st,
        ]);
        $saved++;
    }
    echo json_encode(['success' => true, 'data' => ['month' => $month, 'saved' => $saved]]);
} catch (Throwable $e) {
    monthly_fee_error('Unable to save monthly fees.', 500);
}
