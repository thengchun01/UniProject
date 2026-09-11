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
    api_response(['success' => false, 'message' => 'Please log in before saving performance.'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    api_response(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
}

try {
    $events = is_array($input['events'] ?? null) ? $input['events'] : [];
    $analysis = is_array($input['analysis'] ?? null) ? $input['analysis'] : [];
    $summary = is_array($input['summary'] ?? null) ? $input['summary'] : [];

    $methodCounts = [];
    foreach ($events as $event) {
        $method = strtoupper((string) ($event['input_method'] ?? 'KEYBOARD'));
        if (!in_array($method, ['KEYBOARD', 'MOUSE', 'TOUCH', 'MIDI'], true)) {
            $method = 'KEYBOARD';
        }
        $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
    }
    arsort($methodCounts);
    $inputMode = array_key_first($methodCounts) ?: 'KEYBOARD';

    $timingValues = [];
    $bestStreak = 0;
    $currentStreak = 0;
    foreach ($analysis as $event) {
        if (isset($event['timingDeltaMs']) && is_numeric($event['timingDeltaMs'])) {
            $timingValues[] = abs((float) $event['timingDeltaMs']);
        }

        if (!empty($event['correct'])) {
            $currentStreak++;
            $bestStreak = max($bestStreak, $currentStreak);
        } else {
            $currentStreak = 0;
        }
    }

    $durationMs = (int) ($summary['duration_ms'] ?? $input['duration_ms'] ?? 0);
    $durationSeconds = max(0, (int) round($durationMs / 1000));
    $totalNotes = (int) ($summary['total_notes'] ?? count($events));
    $averageSpeed = $durationSeconds > 0 ? round($totalNotes / $durationSeconds, 2) : 0;
    $clientSessionId = substr((string) ($input['session_id'] ?? uniqid('session_', true)), 0, 100);
    $songTitle = substr((string) ($summary['song_name'] ?? $input['song_name'] ?? 'Imported MIDI'), 0, 150);
    $accuracy = (float) ($summary['accuracy'] ?? 0);
    $score = (int) ($summary['score'] ?? 0);
    $expectedNotes = (int) ($summary['expected_notes'] ?? $input['expected_notes'] ?? 0);
    $avgTimingMs = $timingValues ? (int) round(array_sum($timingValues) / count($timingValues)) : 0;

    $stmt = db()->prepare(
        'INSERT INTO performance_session (
            user_id,
            midi_id,
            datetime,
            accuracy,
            averageSpeed
        ) VALUES (
            :user_id,
            NULL,
            CURRENT_TIMESTAMP,
            :accuracy,
            :average_speed
        )'
    );

    $stmt->execute([
        'user_id' => (int) $_SESSION['user_id'],
        'accuracy' => $accuracy,
        'average_speed' => $averageSpeed
    ]);
    
    $sessionId = (int) db()->lastInsertId();

    if ($sessionId > 0) {
        $attribute = [
            'mode_key' => 'piano:play',
            'shortcut_url' => 'piano.php?mode=play',
            'related_session_id' => $sessionId,
            'score' => $score,
            'accuracy' => $accuracy,
            'duration_seconds' => $durationSeconds,
            'summary_json' => [
                'song' => $songTitle,
                'input_mode' => $inputMode,
                'score' => $score,
                'accuracy' => $accuracy,
                'best_streak' => $bestStreak,
                'avg_timing_ms' => $avgTimingMs,
                'expected_notes' => $expectedNotes,
                'total_notes' => $totalNotes,
            ],
            'detail_json' => [
                'events' => $events,
                'analysis' => $analysis,
            ]
        ];

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
                :title,
                :attribute,
                CURRENT_TIMESTAMP
            )'
        );

        $stmt->execute([
            'user_id' => (int) $_SESSION['user_id'],
            'activity_type' => 'PIANO_PLAY',
            'title' => 'Piano Play: ' . $songTitle,
            'attribute' => json_encode($attribute)
        ]);
    }

    api_response([
        'success' => true,
        'session_id' => $sessionId,
        'message' => 'Performance session saved to PSM.',
    ]);
} catch (Throwable $exception) {
    api_response(['success' => false, 'message' => 'Unable to save performance session.'], 500);
}
?>
