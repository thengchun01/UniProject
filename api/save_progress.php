<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

function api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_response(['success' => false, 'message' => 'POST request required.'], 405);
}

if (!is_logged_in()) {
    api_response(['success' => false, 'message' => 'Please log in before saving progress.'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    api_response(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
}

$sectionKey = trim((string) ($input['section_key'] ?? ''));
$isCompleted = !empty($input['is_completed']);

if ($sectionKey === '') {
    api_response(['success' => false, 'message' => 'section_key is required.'], 400);
}

try {
    $stmt = db()->prepare('SELECT section_id FROM tutorial_section WHERE section_id = :section_key LIMIT 1');
    $stmt->execute(['section_key' => $sectionKey]);
    $sectionId = $stmt->fetchColumn();

    if (!$sectionId) {
        api_response(['success' => false, 'message' => 'Unknown tutorial section.'], 404);
    }

    $stmt = db()->prepare(
        'INSERT INTO user_progress (user_id, section_id, is_completed, completed_at, last_accessed)
         VALUES (:user_id, :section_id, :is_completed, :completed_at, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            is_completed = VALUES(is_completed),
            completed_at = IF(VALUES(is_completed) = 1, COALESCE(user_progress.completed_at, CURRENT_TIMESTAMP), user_progress.completed_at),
            last_accessed = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'section_id' => (int) $sectionId,
        'is_completed' => $isCompleted ? 1 : 0,
        'completed_at' => $isCompleted ? date('Y-m-d H:i:s') : null,
    ]);

    api_response(['success' => true, 'message' => 'Tutorial progress saved to PSM.']);
} catch (Throwable $exception) {
    api_response(['success' => false, 'message' => 'Unable to save tutorial progress.'], 500);
}
?>
