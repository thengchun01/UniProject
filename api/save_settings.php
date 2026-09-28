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

// Load stored doc so partial updates (e.g. availability-only) never wipe keys.
$storedDoc = [];
try {
    $storedStmt = db()->prepare('SELECT settings_json FROM user_settings WHERE user_id = :id');
    $storedStmt->execute(['id' => (int) $_SESSION['user_id']]);
    $storedRaw = $storedStmt->fetchColumn();
    $decoded = json_decode((string) $storedRaw, true);
    if (is_array($decoded)) {
        $storedDoc = $decoded;
    }
} catch (Throwable $e) {
    $storedDoc = [];
}

// Piano keybind map { computerKey: midiNote }. Optional on availability-only saves.
$cleanKeybinds = null;
if (array_key_exists('keybinds', $settings)) {
    $keybinds = $settings['keybinds'];

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
} elseif (isset($storedDoc['keybinds']) && is_array($storedDoc['keybinds'])) {
    $cleanKeybinds = $storedDoc['keybinds'];
} else {
    $cleanKeybinds = [];
}

$keybindPreset = null;

if (array_key_exists('keybindPreset', $settings) && $settings['keybindPreset'] !== null) {
    if (!is_string($settings['keybindPreset']) || !in_array($settings['keybindPreset'], ['single', 'double', 'custom'], true)) {
        api_response(['success' => false, 'message' => 'Invalid keybind preset.'], 400);
    }

    $keybindPreset = $settings['keybindPreset'];
} elseif (isset($storedDoc['keybindPreset'])) {
    $keybindPreset = $storedDoc['keybindPreset'];
}

// Teacher availability: [{dow: 1-6, start: "HH:MM", end: "HH:MM"}]. Optional.
$availability = null;
if (array_key_exists('availability', $settings)) {
    $availability = $settings['availability'];
    if (!is_array($availability) || count($availability) > 42) {
        api_response(['success' => false, 'message' => 'Invalid availability list.'], 400);
    }
    $cleanAvailability = [];
    foreach ($availability as $slot) {
        if (!is_array($slot)) {
            api_response(['success' => false, 'message' => 'Invalid availability slot.'], 400);
        }
        $dow = (int) ($slot['dow'] ?? 0);
        $start = (string) ($slot['start'] ?? '');
        $end = (string) ($slot['end'] ?? '');
        if ($dow < 1 || $dow > 6) {
            api_response(['success' => false, 'message' => 'Availability day must be Mon(1)–Sat(6).'], 400);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            api_response(['success' => false, 'message' => 'Invalid availability time.'], 400);
        }
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        if (!in_array($sm, [0, 30], true) || !in_array($em, [0, 30], true) || ($eh * 60 + $em) <= ($sh * 60 + $sm)) {
            api_response(['success' => false, 'message' => 'Availability must use :00/:30 slots with end after start.'], 400);
        }
        $cleanAvailability[] = ['dow' => $dow, 'start' => $start, 'end' => $end];
    }
    $availability = $cleanAvailability;
} elseif (isset($storedDoc['availability']) && is_array($storedDoc['availability'])) {
    $availability = $storedDoc['availability'];
}

$doc = ['keybinds' => $cleanKeybinds];

if ($keybindPreset !== null) {
    $doc['keybindPreset'] = $keybindPreset;
}
if ($availability !== null) {
    $doc['availability'] = $availability;
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
