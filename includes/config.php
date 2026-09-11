<?php
if (session_status() === PHP_SESSION_NONE && PHP_SAPI === 'cli') {
    $_SESSION = $_SESSION ?? [];
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'Piano Course');
define('DB_HOST', getenv('PSM_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('PSM_DB_NAME') ?: 'psm_2');
define('DB_USER', getenv('PSM_DB_USER') ?: 'root');
define('DB_PASS', getenv('PSM_DB_PASS') ?: '');

// Require OOP Classes
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Game.php';
require_once __DIR__ . '/classes/Tutorial.php';
require_once __DIR__ . '/classes/Classroom.php';

// Manually define your base URL (most reliable for production)
define('BASE_URL', '/'); // Empty for root-relative

// OR if your project is in a subfolder:
// define('BASE_URL', '/psm/ai/0.3.6');

$pdo = null;
$databaseError = null;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    $databaseError = $exception->getMessage();
}

function db(): PDO
{
    global $pdo, $databaseError;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    throw new RuntimeException($databaseError ?: 'Database connection is not available.');
}

function is_database_connected(): bool
{
    global $pdo;
    return $pdo instanceof PDO;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in() || !is_database_connected()) {
        return null;
    }

    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Version 1
function app_base_url(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if ($scriptName === '') {
        return '';
    }

    $base = str_replace('\\', '/', dirname($scriptName));
    while (in_array(basename($base), ['api', 'tutorials', 'student', 'teacher', 'admin'], true)) {
        $base = dirname($base);
    }

    if ($base === '/' || $base === '.' || $base === '\\') {
        return '';
    }

    return rtrim($base, '/');
}

function url_path(string $path): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}
//

function redirect_to(string $path): void
{
    header('Location: ' . url_path($path));
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (empty($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
$userManager = null;
$gameManager = null;
$tutorialManager = null;
$classroomManager = null;

if (is_database_connected()) {
    $userManager = new User(db());
    $gameManager = new Game(db());
    $tutorialManager = new Tutorial(db());
    $classroomManager = new Classroom(db());
}
?>

