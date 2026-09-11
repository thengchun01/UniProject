<?php include 'includes/header.php'; ?>
<?php
$currentUserObj = null;
if (is_logged_in() && isset($userManager)) {
    $currentUserObj = $userManager->getUser($_SESSION['user_id']);
}

// Logic for Note Recognition
$canPlayRecog = true;
$recogMsg = '';
if ($currentUserObj) {
    if (isset($classroomManager) && $classroomManager->isGameDisabledForStudent($currentUserObj, 'recognition')) {
        $canPlayRecog = false;
        $recogMsg = 'Disabled by Teacher';
    } elseif (isset($gameManager) && !$gameManager->canUserPlay($currentUserObj, 'recognition')) {
        $canPlayRecog = false;
        $req = $gameManager->getLevelRequirement('recognition');
        $recogMsg = "Unlocks at Level $req";
    }
} else {
    $canPlayRecog = false;
    $recogMsg = 'Please login to play';
}

// Logic for Key and Note
$canPlayIdentify = true;
$identifyMsg = '';
if ($currentUserObj) {
    if (isset($classroomManager) && $classroomManager->isGameDisabledForStudent($currentUserObj, 'identify')) {
        $canPlayIdentify = false;
        $identifyMsg = 'Disabled by Teacher';
    } elseif (isset($gameManager) && !$gameManager->canUserPlay($currentUserObj, 'identify')) {
        $canPlayIdentify = false;
        $req = $gameManager->getLevelRequirement('identify');
        $identifyMsg = "Unlocks at Level $req";
    }
} else {
    $canPlayIdentify = false;
    $identifyMsg = 'Please login to play';
}
// Logic for Car Race
$canPlayCarRace = true;
$carRaceMsg = '';
if ($currentUserObj) {
    if (isset($classroomManager) && $classroomManager->isGameDisabledForStudent($currentUserObj, 'car_race')) {
        $canPlayCarRace = false;
        $carRaceMsg = 'Disabled by Teacher';
    } elseif (isset($gameManager) && !$gameManager->canUserPlay($currentUserObj, 'car_race')) {
        $canPlayCarRace = false;
        $req = $gameManager->getLevelRequirement('car_race');
        $carRaceMsg = "Unlocks at Level $req";
    }
} else {
    $canPlayCarRace = false;
    $carRaceMsg = 'Please login to play';
}
?>

<section class="game-page" id="game-app">
    <header class="piano-hero game-hero">
        <div>
            <p class="eyebrow">Piano Games</p>
            <h1 class="section-title">Training Games</h1>
            <?php if ($currentUserObj): ?>
                <div style="color: var(--primary-color); font-weight: bold;">Your Level: <?= e($currentUserObj['level']) ?></div>
            <?php endif; ?>
        </div>

        <div class="gm-tab-group" id="gm-tab-group" aria-label="Game mode" hidden>
            <button class="gm-tab active" type="button" data-gm="recognition">Note Recognition</button>
            <button class="gm-tab" type="button" data-gm="identify">Key and Note</button>
        </div>

        <button class="btn secondary" id="gm-menu-back" type="button" hidden>Game Menu</button>
        <a href="rankings.php" class="btn primary-btn" style="margin-left: auto;">View Rankings</a>
    </header>

    <section class="game-menu" id="game-menu">
        <button class="game-menu-card" type="button" <?= $canPlayRecog ? 'data-gm="recognition"' : 'disabled style="opacity:0.6; cursor:not-allowed;"' ?>>
            <span>Note Recognition</span>
            <small>Read notes from the staff and play the matching piano keys.</small>
            <?php if (!$canPlayRecog): ?>
                <div style="color:var(--studio-danger); font-weight:bold; margin-top:10px; font-size:14px;"><?= $recogMsg ?></div>
            <?php endif; ?>
        </button>

        <button class="game-menu-card" type="button" <?= $canPlayIdentify ? 'data-gm="identify"' : 'disabled style="opacity:0.6; cursor:not-allowed;"' ?>>
            <span>Key and Note</span>
            <small>Identify highlighted keys or staff notes using the note wheel.</small>
            <?php if (!$canPlayIdentify): ?>
                <div style="color:var(--studio-danger); font-weight:bold; margin-top:10px; font-size:14px;"><?= $identifyMsg ?></div>
            <?php endif; ?>
        </button>

        <button class="game-menu-card" type="button" <?= $canPlayCarRace ? 'onclick="window.location.href=\'car_race.php\'"' : 'disabled style="opacity:0.6; cursor:not-allowed;"' ?>>
            <span>🏎️ Car Race</span>
            <small>Speed up your car by playing the correct notes on the piano before time runs out!</small>
            <?php if (!$canPlayCarRace): ?>
                <div style="color:var(--studio-danger); font-weight:bold; margin-top:10px; font-size:14px;"><?= $carRaceMsg ?></div>
            <?php endif; ?>
        </button>
    </section>

    <?php include 'includes/games/note-recognition.php'; ?>
    <?php include 'includes/games/identify-note.php'; ?>
</section>

<?php include 'includes/footer.php'; ?>
