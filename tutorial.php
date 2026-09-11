<?php include 'includes/header.php'; ?>
<?php
$currentUserObj = null;
$userProgressMap = [];

if (is_logged_in() && isset($tutorialManager) && isset($userManager)) {
    $currentUserObj = $userManager->getUser($_SESSION['user_id']);
    
    // Get all section progress for the user
    try {
        $stmt = db()->prepare(
            "SELECT up.section_id, up.is_completed 
             FROM user_progress up 
             WHERE up.user_id = :uid"
        );
        $stmt->execute(['uid' => $_SESSION['user_id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userProgressMap[$row['section_id']] = (int) $row['is_completed'];
        }
    } catch (Throwable $e) {
        // Progress not available yet
    }
}

// Fetch all tutorials from DB
$tutorials = [];
if (isset($tutorialManager)) {
    try {
        $tutorials = $tutorialManager->getAllTopics();
        
        // For each tutorial, get section count and completed count
        foreach ($tutorials as &$tut) {
            $sections = $tutorialManager->getSectionsByTopic($tut['tutorial_id']);
            $tut['section_count'] = count($sections);
            $tut['completed_count'] = 0;
            foreach ($sections as $sec) {
                if (isset($userProgressMap[$sec['section_id']]) && $userProgressMap[$sec['section_id']] == 1) {
                    $tut['completed_count']++;
                }
            }
        }
        unset($tut);
    } catch (Throwable $e) {
        // DB not seeded yet
    }
}
?>

<link rel="stylesheet" href="assets/css/tutorial.css">

<div class="tutorial-page">
    <div class="tutorial-hero">
        <p class="eyebrow">Starter Studies</p>
        <h1>Piano Tutorials</h1>
        <p>Master the fundamentals of piano step by step. Complete lessons to earn XP and level up!</p>
        <?php if ($currentUserObj): ?>
            <div style="margin-top: 16px; font-size: 14px; color: var(--primary-color); font-weight: 600;">
                Level <?= e($currentUserObj['level']) ?> · <?= e($currentUserObj['experience']) ?> XP
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($tutorials)): ?>
        <div style="text-align: center; padding: 60px 20px;">
            <h2 style="color: #999;">No tutorials available yet</h2>
            <p style="color: #888;">Please run <code>database/seed_tutorials.php</code> to populate lesson content.</p>
        </div>
    <?php else: ?>
        <div class="tutorial-list">
            <?php foreach ($tutorials as $index => $tut): ?>
                <?php
                    $isDisabled = false;
                    $disabledMsg = '';
                    
                    // Check if teacher has disabled this tutorial
                    if ($currentUserObj && isset($classroomManager)) {
                        if ($classroomManager->isTutorialDisabledForStudent($currentUserObj, $tut['tutorial_id'])) {
                            $isDisabled = true;
                            $disabledMsg = 'Disabled by Teacher';
                        }
                    }
                    
                    $allDone = ($tut['completed_count'] > 0 && $tut['completed_count'] >= $tut['section_count']);
                ?>
                <a class="tutorial-card <?= $isDisabled ? 'locked' : '' ?>" 
                   href="<?= $isDisabled ? '#' : 'tutorials/lesson.php?id=' . $tut['tutorial_id'] ?>">
                    <span class="card-number">Lesson <?= $index + 1 ?></span>
                    <h2><?= e($tut['title']) ?></h2>
                    <p><?= e($tut['content']) ?></p>
                    <div class="card-footer">
                        <?php if ($isDisabled): ?>
                            <span class="locked-badge">🔒 <?= $disabledMsg ?></span>
                        <?php else: ?>
                            <span class="difficulty-badge"><?= e(ucfirst($tut['difficulty'])) ?></span>
                        <?php endif; ?>
                        
                        <?php if ($currentUserObj && !$isDisabled): ?>
                            <?php if ($allDone): ?>
                                <span class="progress-indicator completed">✓ Completed</span>
                            <?php elseif ($tut['completed_count'] > 0): ?>
                                <span class="progress-indicator"><?= $tut['completed_count'] ?>/<?= $tut['section_count'] ?> done</span>
                            <?php else: ?>
                                <span class="progress-indicator"><?= $tut['section_count'] ?> sections</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="progress-indicator"><?= $tut['section_count'] ?> sections</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>