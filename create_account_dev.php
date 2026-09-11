<?php
/**
 * Developer utility to create accounts directly, including ADMIN accounts.
 * This file is NOT linked from anywhere in the application.
 * 
 * Usage: Navigate to http://localhost/.../0.4.1/create_account_dev.php
 */

require_once __DIR__ . '/includes/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'STUDENT';
    $classroom_status = ($role === 'STUDENT') ? 'NONE' : NULL;

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!in_array($role, ['STUDENT', 'TEACHER', 'ADMIN'])) {
        $error = 'Invalid role selected.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO users (username, email, password_hash, role, classroom_status) 
                 VALUES (:username, :email, :password_hash, :role, :status)'
            );
            
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'status' => $classroom_status
            ]);

            $message = "Success! Account '{$username}' created with role '{$role}'.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Username or email already exists.';
            } else {
                $error = 'Database Error: ' . $e->getMessage();
            }
        } catch (Throwable $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev: Create Account</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #eee;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: #222;
            padding: 30px;
            border-radius: 8px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }
        h2 { margin-top: 0; color: #fff; text-align: center; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 14px; }
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #444;
            background: #333;
            color: #fff;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #8a2be2;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover { background: #9b4dca; }
        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
        .success { background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid #2ecc71; }
        .error { background: rgba(231, 76, 60, 0.2); color: #e74c3c; border: 1px solid #e74c3c; }
    </style>
</head>
<body>

<div class="container">
    <h2>🛠️ Dev Account Creator</h2>
    
    <?php if ($message): ?>
        <div class="alert success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required minlength="6">
        </div>
        
        <div class="form-group">
            <label>Account Role</label>
            <select name="role">
                <option value="STUDENT">Student</option>
                <option value="TEACHER">Teacher</option>
                <option value="ADMIN">Admin</option>
            </select>
        </div>
        
        <button type="submit">Create Account</button>
    </form>
</div>

</body>
</html>
