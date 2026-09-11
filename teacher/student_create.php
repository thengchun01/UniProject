<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'TEACHER') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get teacher's classroom code
$stmt = db()->prepare("SELECT classroom FROM users WHERE user_id = :user_id");
$stmt->execute(['user_id' => $user['user_id']]);
$teacher = $stmt->fetch();
$classroomCode = $teacher['classroom'];

if (empty($classroomCode)) {
    echo json_encode(['success' => false, 'message' => 'No classroom found. Create a classroom first.']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

try {
    $db = db();
    
    // Check if username or email already exists
    $check = $db->prepare("SELECT user_id FROM users WHERE username = :username OR email = :email");
    $check->execute(['username' => $username, 'email' => $email]);
    if ($check->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
        exit;
    }
    
    $stmt = $db->prepare("
        INSERT INTO users (username, email, password_hash, role, classroom, classroom_status) 
        VALUES (:username, :email, :password_hash, 'STUDENT', :classroom, 'APPROVED')
    ");
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'classroom' => $classroomCode
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Student created successfully']);
    
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>