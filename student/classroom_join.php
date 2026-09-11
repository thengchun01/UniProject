<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'STUDENT') {
    echo json_encode(['success' => false, 'message' => 'Only students can join classrooms']);
    exit;
}

$teacherUsername = trim($_POST['teacher_username'] ?? '');

if (empty($teacherUsername)) {
    echo json_encode(['success' => false, 'message' => 'Teacher username is required']);
    exit;
}

try {
    $db = db();
    
    // Find teacher
    $stmt = $db->prepare("SELECT user_id, classroom FROM users WHERE username = :username AND role = 'TEACHER'");
    $stmt->execute(['username' => $teacherUsername]);
    $teacher = $stmt->fetch();
    
    if (!$teacher) {
        echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        exit;
    }
    
    if (empty($teacher['classroom'])) {
        echo json_encode(['success' => false, 'message' => 'This teacher has not created a classroom yet']);
        exit;
    }
    
    // Check existing status
    $stmt = $db->prepare("SELECT classroom_status FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user['user_id']]);
    $student = $stmt->fetch();
    
    $existingStatus = $student['classroom_status'];
    
    if ($existingStatus === 'PENDING') {
        echo json_encode(['success' => false, 'message' => 'You already have a pending request']);
        exit;
    }
    
    if ($existingStatus === 'APPROVED') {
        echo json_encode(['success' => false, 'message' => 'You are already in a classroom']);
        exit;
    }
    
    // Update student's request
    $stmt = $db->prepare("
        UPDATE users 
        SET classroom = :classroom, classroom_status = 'PENDING' 
        WHERE user_id = :user_id
    ");
    $stmt->execute([
        'classroom' => $teacher['classroom'],
        'user_id' => $user['user_id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Join request sent to teacher. Please wait for approval.'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>