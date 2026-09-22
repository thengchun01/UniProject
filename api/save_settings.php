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
    api_response(['success' => false, 'message' => 'Please log in before saving settings.'], 401);
}

$rawBody = file_get_contents('php://input');

if (!is_string($rawBody) || strlen($rawBody) > 8192) {
    api_response(['success' => false, 'message' => 'Settings payload too large.'], 400);
}

$input = json_decode($rawBody, true);

if (!is_array($input)) {
    api_response(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
}

$settings = $input['settings'] ?? null;

if (!is_array($settings)) {
    api_response(['success' => false, 'message' => 'Missing settings object.'], 400);
}

// Currently synced key: piano keybind map { computerKey: midiNote }.
$keybinds = $settings['keybinds'] ?? [];

if (!is_array($keybinds) || count($keybinds) > 88) {
    api_response(['success' => false, 'message' => 'Invalid keybind map.'], 400);
}

$cleanKeybinds = [];

foreach ($keybinds as $key => $midi) {
    if (!is_string($key) || $key === '' || strlen($key) > 12) {
        api_response(['success' => false, 'message' => 'Invalid keybind key.'], 400);
    }

    $midi = is_int($midi) ? $midi : (is_numeric($midi) ? (int) $midi : -1);

    if ($midi < 21 || $midi > 108) {
        api_response(['success' => false, 'message' => 'Keybind note out of range (21-108).'], 400);
    }

    $cleanKeybinds[$key] = $midi;
}

$keybindPreset = null;

if (array_key_exists('keybindPreset', $settings) && $settings['keybindPreset'] !== null) {
    if (!is_string($settings['keybindPreset']) || !in_array($settings['keybindPreset'], ['single', 'double', 'custom'], true)) {
        api_response(['success' => false, 'message' => 'Invalid keybind preset.'], 400);
    }

    $keybindPreset = $settings['keybindPreset'];
}

$doc = ['keybinds' => $cleanKeybinds];

if ($keybindPreset !== null) {
    $doc['keybindPreset'] = $keybindPreset;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO user_settings (user_id, settings_json)
         VALUES (:user_id, :settings_json)
         ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)'
    );

    $stmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'settings_json' => json_encode($doc),
    ]);

    api_response([
        'success' => true,
        'message' => 'Settings saved to PSM.',
    ]);
} catch (Throwable $exception) {
    api_response(['success' => false, 'message' => 'Unable to save settings.'], 500);
}
?>
