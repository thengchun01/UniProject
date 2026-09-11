<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'TEACHER') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$studentId = $data['student_id'] ?? 0;

if ($studentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

try {
    $db = db();
    
    $stmt = $db->prepare("UPDATE users SET classroom_status = 'APPROVED' WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $studentId]);
    
    echo json_encode(['success' => true, 'message' => 'Student approved successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>