<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

// Allow ADMIN or TEACHER (for their own students)
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

try {
    $db = db();
    
    // Get target user info
    $stmt = $db->prepare("
        SELECT user_id, username, email, role, classroom, classroom_status, created_at, last_login 
        FROM users 
        WHERE user_id = :id
    ");
    $stmt->execute(['id' => $userId]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Permission check
    $isAdmin = ($user['role'] === 'ADMIN');
    $isTeacher = ($user['role'] === 'TEACHER');
    $isViewingOwnStudent = false;
    
    if ($isTeacher) {
        // Get teacher's classroom
        $stmt = $db->prepare("SELECT classroom FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user['user_id']]);
        $teacher = $stmt->fetch();
        $teacherClassroom = $teacher['classroom'];
        
        // Check if target user is a student in teacher's classroom
        $isViewingOwnStudent = ($targetUser['role'] === 'STUDENT' && $targetUser['classroom'] === $teacherClassroom);
    }
    
    if (!$isAdmin && !$isViewingOwnStudent) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to view this user']);
        exit;
    }
    
    // Get TOTAL sections from tutorial_section table
    $stmt = $db->query("SELECT COUNT(*) as total_sections FROM tutorial_section");
    $totalSections = (int)$stmt->fetchColumn();
    
    // Get COMPLETED sections from user_progress
    $stmt = $db->prepare("
        SELECT COUNT(*) as completed_sections
        FROM user_progress up
        WHERE up.user_id = :user_id AND up.is_completed = 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $completedSections = (int)$stmt->fetchColumn();
    
    // Get performance stats
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as session_count,
            0 as avg_score,
            COALESCE(ROUND(AVG(accuracy), 1), 0) as avg_accuracy,
            0 as best_score
        FROM performance_session
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $performance = $stmt->fetch();
    
    // Get recent activities
    $stmt = $db->prepare("
        SELECT activity_type, activity_title as title, JSON_EXTRACT(attribute, '$.score') as score, JSON_EXTRACT(attribute, '$.accuracy') as accuracy, JSON_EXTRACT(attribute, '$.duration_seconds') as duration_seconds, created_at
        FROM user_activity_log
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute(['user_id' => $userId]);
    $activities = $stmt->fetchAll();
    
    // Get recent performance sessions
    $stmt = $db->prepare("
        SELECT 'Performance' as song_title, 'MIDI' as input_mode, 0 as score, accuracy, averageSpeed as duration_seconds, datetime as session_date
        FROM performance_session
        WHERE user_id = :user_id
        ORDER BY datetime DESC
        LIMIT 5
    ");
    $stmt->execute(['user_id' => $userId]);
    $sessions = $stmt->fetchAll();
    
    // Get MIDI files count
    $stmt = $db->prepare("SELECT COUNT(*) as midi_count FROM midi_file WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    $midiCount = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'user' => [
            'user_id' => $targetUser['user_id'],
            'username' => $targetUser['username'],
            'email' => $targetUser['email'],
            'role' => $targetUser['role'],
            'classroom' => $targetUser['classroom'] ?? null,
            'classroom_status' => $targetUser['classroom_status'] ?? 'NONE',
            'created_at' => date('Y-m-d H:i:s', strtotime($targetUser['created_at'])),
            'last_login' => $targetUser['last_login'] ? date('Y-m-d H:i:s', strtotime($targetUser['last_login'])) : 'Never'
        ],
        'stats' => [
            'total_sections' => $totalSections,
            'completed_sections' => $completedSections,
            'session_count' => (int)($performance['session_count'] ?? 0),
            'avg_score' => (int)($performance['avg_score'] ?? 0),
            'avg_accuracy' => (float)($performance['avg_accuracy'] ?? 0),
            'best_score' => (int)($performance['best_score'] ?? 0),
            'midi_count' => (int)($midiCount['midi_count'] ?? 0)
        ],
        'activities' => $activities,
        'sessions' => $sessions
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>