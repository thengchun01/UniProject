<?php 
require_once 'includes/config.php';
include 'includes/header.php'; 

if (!is_logged_in() || !isset($gameManager)) {
    echo "<div class='container'><div class='alert error'>Please login to view rankings.</div></div>";
    include 'includes/footer.php';
    exit;
}

$currentUserObj = $userManager->getUser($_SESSION['user_id']);

$games = [
    'recognition' => 'Note Recognition',
    'identify' => 'Key and Note'
];

$activeGame = $_GET['game'] ?? 'recognition';
if (!array_key_exists($activeGame, $games)) {
    $activeGame = 'recognition';
}

$globalRankings = $gameManager->getGlobalRanking($activeGame, 10);
$classroomRankings = [];
if (!empty($currentUserObj['classroom'])) {
    $classroomRankings = $gameManager->getClassroomRanking($activeGame, $currentUserObj['classroom'], 10);
}
?>

<div class="container">
    <div class="account-header">
        <div>
            <p class="eyebrow">Leaderboards</p>
            <h2>Game Rankings</h2>
        </div>
        <div>
            <form method="GET" style="display: flex; gap: 10px; align-items: center;">
                <label for="gameSelect" style="font-weight:bold;">Select Game:</label>
                <select name="game" id="gameSelect" onchange="this.form.submit()" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <?php foreach ($games as $key => $title): ?>
                        <option value="<?= e($key) ?>" <?= $activeGame === $key ? 'selected' : '' ?>><?= e($title) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="card-grid">
        <div class="card">
            <h3>Global Top 10</h3>
            <h4><?= e($games[$activeGame]) ?></h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Player</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($globalRankings)): ?>
                        <tr><td colspan="3" class="empty-state">No scores yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($globalRankings as $index => $row): ?>
                            <tr>
                                <td>#<?= $index + 1 ?></td>
                                <td style="font-weight:bold;"><?= e($row['username']) ?></td>
                                <td style="color:var(--primary-color); font-weight:bold;"><?= e($row['score']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Classroom Top 10</h3>
            <h4>
                <?php if (empty($currentUserObj['classroom'])): ?>
                    Not in a classroom
                <?php else: ?>
                    Class: <?= e($currentUserObj['classroom']) ?>
                <?php endif; ?>
            </h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Player</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currentUserObj['classroom'])): ?>
                        <tr><td colspan="3" class="empty-state">Join a classroom to see rankings.</td></tr>
                    <?php elseif (empty($classroomRankings)): ?>
                        <tr><td colspan="3" class="empty-state">No scores in your classroom yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($classroomRankings as $index => $row): ?>
                            <tr>
                                <td>#<?= $index + 1 ?></td>
                                <td style="font-weight:bold;"><?= e($row['username']) ?></td>
                                <td style="color:var(--primary-color); font-weight:bold;"><?= e($row['score']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
