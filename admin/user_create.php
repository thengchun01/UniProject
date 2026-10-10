<?php

require_once '../includes/config.php';
require_once '../includes/verification_mail.php';

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

    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Username and Password are required (email may be left blank)'
        ]);
        exit;
    }

    // Issue 64: email is optional; validate format only when provided.
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Invalid email format. Leave it blank or enter a valid email address.'
        ]);
        exit;
    }
    $emailDb = $email !== '' ? $email : null;

    if (strlen($password) < 6) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Password must be at least 6 characters long.'
        ]);
        exit;
    }

    // Reject a username or email another account already uses. Enforced here as
    // well as by the UNIQUE keys, because a unique email index cannot be created
    // while the database still holds a duplicate address.
    $conflict = psm_find_account_conflict($username, $email);

    if ($conflict !== null) {
        echo json_encode([
            'success' => false,
            'message' => '❌ ' . $conflict
        ]);
        exit;
    }

    $stmt = db()->prepare(
        'INSERT INTO users
        (
            username,
            email,
            password_hash,
            role,
            email_verified_at
        )
        VALUES
        (
            :username,
            :email,
            :password_hash,
            :role,
            CURRENT_TIMESTAMP
        )'
    );

    $stmt->execute([
        'username' => $username,
        'email' => $emailDb,
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