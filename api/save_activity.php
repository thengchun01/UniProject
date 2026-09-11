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
    api_response(['success' => false, 'message' => 'Please log in before saving activity.'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    api_response(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
}

$activityType = strtoupper((string) ($input['activity_type'] ?? ''));

if (!in_array($activityType, ['PIANO_PLAY', 'GAME'], true)) {
    api_response(['success' => false, 'message' => 'Unsupported activity type.'], 400);
}

try {
    $stmt = db()->prepare(
        'INSERT INTO user_activity_log (
            user_id,
            activity_type,
            activity_title,
            attribute,
            created_at
        ) VALUES (
            :user_id,
            :activity_type,
            :activity_title,
            :attribute,
            CURRENT_TIMESTAMP
        )'
    );

    $attribute = [
        'mode_key' => substr((string) ($input['mode_key'] ?? ''), 0, 100),
        'shortcut_url' => substr((string) ($input['shortcut_url'] ?? ''), 0, 255),
        'related_session_id' => (int) ($input['related_session_id'] ?? 0) ?: null,
        'score' => (int) ($input['score'] ?? 0),
        'accuracy' => (float) ($input['accuracy'] ?? 0),
        'duration_seconds' => (int) ($input['duration_seconds'] ?? 0),
        'summary_json' => is_array($input['summary'] ?? null) ? $input['summary'] : [],
        'detail_json' => is_array($input['details'] ?? null) ? $input['details'] : []
    ];

    $stmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'activity_type' => $activityType,
        'activity_title' => substr((string) ($input['title'] ?? 'Unknown Activity'), 0, 200),
        'attribute' => json_encode($attribute)
    ]);

    api_response([
        'success' => true,
        'activity_id' => (int) db()->lastInsertId(),
        'message' => 'Activity saved to PSM.',
    ]);
} catch (Throwable $exception) {
    api_response(['success' => false, 'message' => 'Unable to save activity.'], 500);
}
?>
