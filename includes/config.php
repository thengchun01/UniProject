<?php
if (session_status() === PHP_SESSION_NONE && PHP_SAPI === 'cli') {
    $_SESSION = $_SESSION ?? [];
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'Tasto');
define('DB_HOST', getenv('PSM_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('PSM_DB_NAME') ?: 'psm_2');
define('DB_USER', getenv('PSM_DB_USER') ?: 'root');
define('DB_PASS', getenv('PSM_DB_PASS') ?: '');

// ---------------------------------------------------------------------------
// Outgoing mail — Gmail SMTP with a Google app password
// ---------------------------------------------------------------------------
// Create the app password at https://myaccount.google.com/apppasswords (the
// Google account needs 2-step verification first). Google shows it once, as 16
// characters; spaces in the displayed value are cosmetic and can be kept.
//
// SECURITY: an app password is a secret. Supply it through the environment
// (PSM_MAIL_APP_PASSWORD) and never commit it to the repository — the literal
// fallback below is intentionally empty, so a fresh clone cannot send mail
// until the environment is configured. Leaving the username/app password empty
// simply disables outgoing mail.
//
// Optional local overrides live in includes/config.local.php, which is
// git-ignored so it is the safe place for credentials. It returns an array
// keyed by setting name and takes precedence over the environment, because
// environment variables only reach processes started after `setx` ran — a PHP
// server started from an already-open editor would otherwise never see them.
$psmLocalConfig = [];
if (is_file(__DIR__ . '/config.local.php')) {
    $psmLocal = require __DIR__ . '/config.local.php';

    if (is_array($psmLocal)) {
        $psmLocalConfig = $psmLocal;
    }
}

// Otherwise values are read from $_SERVER first (so Apache's SetEnv works under
// mod_php) and then from the real environment (setx / shell exports).
$psmEnv = static function (string $name, string $default = '') use ($psmLocalConfig): string {
    $local = $psmLocalConfig[$name] ?? null;

    if (is_scalar($local) && trim((string) $local) !== '') {
        return trim((string) $local);
    }

    $value = $_SERVER[$name] ?? getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};

define('PSM_MAIL_HOST', $psmEnv('PSM_MAIL_HOST', 'smtp.gmail.com'));
define('PSM_MAIL_PORT', (int) $psmEnv('PSM_MAIL_PORT', '587'));
define('PSM_MAIL_ENCRYPTION', strtolower($psmEnv('PSM_MAIL_ENCRYPTION', 'tls'))); // 'tls' = 587 STARTTLS, 'ssl' = 465
define('PSM_MAIL_USERNAME', $psmEnv('PSM_MAIL_USERNAME'));
define('PSM_MAIL_APP_PASSWORD', $psmEnv('PSM_MAIL_APP_PASSWORD'));
define('PSM_MAIL_FROM_ADDRESS', $psmEnv('PSM_MAIL_FROM_ADDRESS')); // defaults to the username
define('PSM_MAIL_FROM_NAME', $psmEnv('PSM_MAIL_FROM_NAME', SITE_NAME));

// Absolute base URL used in emailed activation links, for example
// http://localhost/psm/ai/0.4.1. Leave it empty to derive the URL from the
// incoming request; set it explicitly in production, because the Host header is
// supplied by the client.
define('PSM_SITE_URL', $psmEnv('PSM_SITE_URL'));

// How long an unactivated account survives, in hours. The same window is how
// long its activation link and code stay valid, after which the account is
// cancelled (deleted) and its username/email become available again.
define('PSM_VERIFICATION_GRACE_HOURS', (int) $psmEnv('PSM_VERIFICATION_GRACE_HOURS', '24'));

// Require OOP Classes
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Game.php';
require_once __DIR__ . '/classes/Tutorial.php';
require_once __DIR__ . '/classes/Classroom.php';
require_once __DIR__ . '/classes/Lessons.php';

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

function normalize_role($role): string
{
    return strtoupper(trim((string) $role));
}

function current_user(): ?array
{
    if (!is_logged_in() || !is_database_connected()) {
        return null;
    }

    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($user && isset($user['role'])) {
            $user['role'] = normalize_role($user['role']);
        }
        return $user;
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

/**
 * Absolute base URL for links that leave the browser, such as the activation
 * link sent by email. PSM_SITE_URL wins; otherwise it is derived from the
 * current request, with the client-supplied Host header reduced to characters a
 * host name may legitimately contain.
 */
function psm_site_url(): string
{
    $configured = trim((string) PSM_SITE_URL);

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = (string) preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        $host = 'localhost';
    }

    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = (is_string($https) && $https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host . app_base_url();
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

/**
 * Resolved outgoing-mail settings.
 *
 * The app password is read from the environment only; it is never written to
 * output, error messages, or the database.
 */
function psm_mail_config(): array
{
    $username = trim((string) PSM_MAIL_USERNAME);
    $fromAddress = trim((string) PSM_MAIL_FROM_ADDRESS);

    return [
        'host' => (string) PSM_MAIL_HOST,
        'port' => (int) PSM_MAIL_PORT,
        'encryption' => PSM_MAIL_ENCRYPTION === 'ssl' ? 'ssl' : 'tls',
        'username' => $username,
        // Google shows app passwords in space-separated groups; the spaces are
        // cosmetic, so strip any whitespace copied along with the value.
        'password' => (string) preg_replace('/\s+/', '', (string) PSM_MAIL_APP_PASSWORD),
        'from_address' => $fromAddress !== '' ? $fromAddress : $username,
        'from_name' => trim((string) PSM_MAIL_FROM_NAME) !== '' ? (string) PSM_MAIL_FROM_NAME : SITE_NAME,
        'timeout' => 15,
    ];
}

/**
 * True when a Gmail account and app password are available for sending.
 */
function psm_mail_is_configured(): bool
{
    $config = psm_mail_config();

    return $config['username'] !== ''
        && $config['password'] !== ''
        && $config['from_address'] !== '';
}

/**
 * Send a fresh email-verification code to $email.
 *
 * The SMTP client lives in includes/verification_mail.php and is required on
 * demand, so pages that never send mail do not load it.
 *
 * @return array{success: bool, error?: string, expires_in?: int, retry_after?: int}
 */
function send_verification_code(string $email, string $name = '', ?int $userId = null): array
{
    require_once __DIR__ . '/verification_mail.php';

    return psm_send_verification_code($email, $name, $userId);
}

$userManager = null;
$gameManager = null;
$tutorialManager = null;
$classroomManager = null;
$lessonsManager = null;

if (is_database_connected()) {
    $userManager = new User(db());
    $gameManager = new Game(db());
    $tutorialManager = new Tutorial(db());
    $classroomManager = new Classroom(db());
    try {
        $lessonsManager = new Lessons(db());
    } catch (Throwable $e) {
        $lessonsManager = null;
    }
}
?>

