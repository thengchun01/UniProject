<?php

require_once '../includes/config.php';

header('Content-Type: application/json');

$user = current_user();

if (!$user || $user['role'] !== 'ADMIN') {
    echo json_encode([
        'success' => false,
        'message' => '⚠️ Unauthorized: You must be logged in as ADMIN to create users.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '⚠️ Invalid request method. Please use POST.'
    ]);
    exit;
}

try {
    // Validate input
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = normalize_role($_POST['role'] ?? 'STUDENT');

    if (empty($username) || empty($email) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => '❌ All fields are required: Username, Email, and Password'
        ]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Invalid email format. Please enter a valid email address.'
        ]);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Password must be at least 6 characters long.'
        ]);
        exit;
    }

    $stmt = db()->prepare(
        'INSERT INTO users
        (
            username,
            email,
            password_hash,
            role
        )
        VALUES
        (
            :username,
            :email,
            :password_hash,
            :role
        )'
    );

    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role
    ]);

    $id = db()->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => '✅ User created successfully!',
        'user' => [
            'user_id' => $id,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Username or email already exists. Please use different credentials.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '❌ Database error: ' . $e->getMessage()
        ]);
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => '❌ Error: ' . $e->getMessage()
    ]);
}
?>