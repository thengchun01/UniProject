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
    
    // Get teacher's classroom
    $stmt = $db->prepare("SELECT classroom FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user['user_id']]);
    $teacher = $stmt->fetch();
    
    $stmt = $db->prepare("UPDATE users SET classroom = NULL, classroom_status = 'NONE' WHERE user_id = :user_id AND classroom = :classroom");
    $stmt->execute([
        'user_id' => $studentId,
        'classroom' => $teacher['classroom']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Student removed successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>