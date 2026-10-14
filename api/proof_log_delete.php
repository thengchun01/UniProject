<?php
// Admin-only deletion of a Transaction Log entry.
// This removes only the audit-log row; it does not delete or alter the proof file or payment record.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function proof_log_delete_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'ADMIN') {
    proof_log_delete_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    proof_log_delete_error('POST request required.', 405);
}

$logId = (int) ($_POST['log_id'] ?? 0);
if ($logId <= 0) {
    proof_log_delete_error('Invalid Transaction Log entry.', 400);
}

try {
    $db = db();
    $stmt = $db->prepare('DELETE FROM proof_logs WHERE log_id = :id');
    $stmt->execute(['id' => $logId]);
    if ($stmt->rowCount() < 1) {
        proof_log_delete_error('Transaction Log entry was not found.', 404);
    }
    echo json_encode(['success' => true, 'data' => ['deleted' => true]]);
} catch (Throwable $e) {
    proof_log_delete_error('Unable to delete Transaction Log entry.', 500);
}
