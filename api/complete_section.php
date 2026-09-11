<?php
/**
 * API: Mark a tutorial section as completed and award XP.
 * POST JSON: { "section_id": 123 }
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
$sectionId = (int) ($input['section_id'] ?? 0);

if ($sectionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid section_id']);
    exit;
}

try {
    $userId = (int) $_SESSION['user_id'];

    // Mark completed via Tutorial class
    $isNewCompletion = $tutorialManager->markCompleted($userId, $sectionId);

    $xpGained = 0;
    $levelUp = false;
    $newLevel = null;
    $newXp = null;

    if ($isNewCompletion) {
        // Award 50 XP per section completion
        $xpGained = 50;
        $result = $userManager->addExperience($userId, $xpGained);
        $levelUp = $result['level_up'] ?? false;
        $newLevel = $result['level'] ?? null;
        $newXp = $result['experience'] ?? null;

        // Log the activity
        $stmt = db()->prepare(
            "INSERT INTO user_activity_log (user_id, activity_type, activity_title, attribute, created_at) 
             VALUES (:uid, 'TUTORIAL', :title, :attr, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            'uid' => $userId,
            'title' => 'Completed section #' . $sectionId,
            'attr' => json_encode([
                'section_id' => $sectionId,
                'xp_gained' => $xpGained,
                'shortcut_url' => 'tutorials/lesson.php?section=' . $sectionId
            ])
        ]);
    }

    echo json_encode([
        'success' => true,
        'new_completion' => $isNewCompletion,
        'xp_gained' => $xpGained,
        'level_up' => $levelUp,
        'level' => $newLevel,
        'experience' => $newXp,
        'message' => $isNewCompletion ? 'Section completed! +' . $xpGained . ' XP' : 'Already completed.'
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
