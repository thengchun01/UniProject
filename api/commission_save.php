<?php
// Admin-only universal commission default.
// POST: action=default, commission_type (PERCENT|FIXED), commission_value
// JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function commission_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    commission_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    commission_error('POST request required.', 405);
}

$action = trim($_POST['action'] ?? 'default');
$type = strtoupper(trim($_POST['commission_type'] ?? ''));
$value = $_POST['commission_value'] ?? null;

if (!in_array($type, ['PERCENT', 'FIXED'], true) || !is_numeric($value)) {
    commission_error('Commission type must be PERCENT/FIXED with a numeric value.');
}
$value = round((float) $value, 2);
if ($type === 'PERCENT' && ($value < 0 || $value > 100)) {
    commission_error('Percent must be 0–100.');
}
if ($value < 0) {
    commission_error('Commission cannot be negative.');
}

$db = db();
try {
    if ($action !== 'default') {
        commission_error('Unknown commission setting action.');
    }

    $stmt = $db->prepare(
        "INSERT INTO commission_settings (setting_key, commission_type, commission_value)
         VALUES ('default', :type, :value)
         ON DUPLICATE KEY UPDATE commission_type = VALUES(commission_type), commission_value = VALUES(commission_value)"
    );
    $stmt->execute(['type' => $type, 'value' => $value]);
    echo json_encode(['success' => true, 'data' => ['default' => ['type' => $type, 'value' => $value]]]);
} catch (Throwable $e) {
    commission_error('Unable to save commission.', 500);
}
