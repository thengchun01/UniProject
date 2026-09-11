<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'TEACHER') {
    echo json_encode(['success' => false, 'message' => 'Only teachers can create classrooms']);
    exit;
}

$classroomName = trim($_POST['classroom_name'] ?? '');

if (empty($classroomName)) {
    echo json_encode(['success' => false, 'message' => 'Classroom name is required']);
    exit;
}

try {
    $db = db();
    
    // Format classroom: teacher_id#classroom_name
    $classroom = preg_replace('/[^a-zA-Z0-9]/', '', $classroomName) . '#' . $user['user_id'];
    
    // Update teacher's classroom
    $stmt = $db->prepare("UPDATE users SET classroom = :classroom, classroom_status = 'APPROVED' WHERE user_id = :user_id");
    $stmt->execute([
        'classroom' => $classroom,
        'user_id' => $user['user_id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Classroom created successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>