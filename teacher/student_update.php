<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'TEACHER') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id = $_POST['user_id'] ?? 0;
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');

if (empty($username) || empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Username and email are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

try {
    $db = db();
    
    $stmt = $db->prepare("UPDATE users SET username = :username, email = :email WHERE user_id = :id AND role = 'STUDENT'");
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'id' => $id
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
    
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