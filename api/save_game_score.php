<?php
/**
 * API: Save a game score, update highest score, and award XP.
 * POST JSON: { "game_key": "recognition", "score": 95, "accuracy": 87.5, "duration": 120 }
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$gameKey = trim($input['game_key'] ?? '');
$score = (int) ($input['score'] ?? 0);
$accuracy = (float) ($input['accuracy'] ?? 0);
$duration = (int) ($input['duration'] ?? 0);

if ($gameKey === '' || $score < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid game data']);
    exit;
}

try {
    $userId = (int) $_SESSION['user_id'];

    // Log game activity
    $gameManager->logGameActivity($userId, $gameKey, ucwords(str_replace('_', ' ', $gameKey)), $score, $accuracy, $duration);

    // Update high score
    $isNewHighScore = $userManager->updateRecord($userId, $gameKey, $score);

    // Award XP: 10 XP per game played + bonus for high score
    $xpGained = 10;
    if ($isNewHighScore) {
        $xpGained += 25; // Bonus for new high score
    }
    $result = $userManager->addExperience($userId, $xpGained);

    echo json_encode([
        'success' => true,
        'new_high_score' => $isNewHighScore,
        'xp_gained' => $xpGained,
        'level_up' => $result['level_up'] ?? false,
        'level' => $result['level'] ?? null,
        'experience' => $result['experience'] ?? null,
        'message' => $isNewHighScore ? "New high score! +$xpGained XP" : "Game saved! +$xpGained XP"
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
