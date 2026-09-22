<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $id = $_POST['user_id'] ?? 0;
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = normalize_role($_POST['role'] ?? 'STUDENT');

    if (empty($username) || empty($email)) {
        echo json_encode([
            'success' => false,
            'message' => 'Username and email are required'
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email format'
        ]);
        exit;
    }

    // Prevent admin from demoting themselves
    $currentUserId = $user['user_id'];
    if ($id == $currentUserId && $role !== 'ADMIN') {
        echo json_encode([
            'success' => false,
            'message' => 'You cannot change your own role from ADMIN'
        ]);
        exit;
    }

    $stmt = db()->prepare("
        UPDATE users
        SET username = :username,
            email = :email,
            role = :role,
            updated_at = CURRENT_TIMESTAMP
        WHERE user_id = :id
    ");

    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'id' => $id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully',
        'user' => [
            'user_id' => $id,
            'username' => $username,
            'email' => $email,
            'role' => $role
        ]
    ]);

} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode([
            'success' => false,
            'message' => 'Username or email already exists'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>