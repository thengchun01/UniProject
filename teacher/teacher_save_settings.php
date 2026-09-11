<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user || $user['role'] !== 'TEACHER') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled_games = isset($_POST['enabled_games']) ? $_POST['enabled_games'] : [];
    $enabled_tutorials = isset($_POST['enabled_tutorials']) ? $_POST['enabled_tutorials'] : [];

    // The frontend sends what is ENABLED, but DB stores what is DISABLED.
    $all_games = ['recognition', 'identify', 'car_race'];
    $disabled_games = array_values(array_diff($all_games, $enabled_games));

    // Get all tutorial IDs to compute disabled ones
    $topics = $tutorialManager->getAllTopics();
    $all_topic_ids = array_map(function($t) { return (string)$t['tutorial_id']; }, $topics);
    $disabled_tutorials = array_values(array_diff($all_topic_ids, $enabled_tutorials));

    $settings = [
        'disabled_games' => $disabled_games,
        'disabled_tutorials' => $disabled_tutorials
    ];

    $classroomManager->saveSettings($user['user_id'], $settings);

    echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
