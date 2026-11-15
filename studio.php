<?php
require_once __DIR__ . '/includes/config.php';

$user = current_user();
$role = $user ? normalize_role($user['role'] ?? 'GUEST') : 'GUEST';
$userId = (int) ($user['user_id'] ?? 0);
$studioStats = ['completed' => 0, 'total' => 0, 'sessions' => 0, 'accuracy' => 0, 'recent' => []];
$studioError = null;

if ($user && is_database_connected()) {
    try {
        $db = db();
        $studioStats['total'] = (int) $db->query('SELECT COUNT(*) FROM tutorial_section')->fetchColumn();
        $stmt = $db->prepare('SELECT COUNT(*) FROM user_progress WHERE user_id=? AND is_completed=1');
        $stmt->execute([$userId]);
        $studioStats['completed'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) AS sessions,COALESCE(ROUND(AVG(accuracy),1),0) AS accuracy FROM performance_session WHERE user_id=?');
        $stmt->execute([$userId]);
        $performance = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $studioStats['sessions'] = (int) ($performance['sessions'] ?? 0);
        $studioStats['accuracy'] = (float) ($performance['accuracy'] ?? 0);

        $stmt = $db->prepare('SELECT datetime,accuracy,averageSpeed FROM performance_session WHERE user_id=? ORDER BY datetime DESC,session_id DESC LIMIT 4');
        $stmt->execute([$userId]);
        $studioStats['recent'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $studioError = 'Your learning statistics are not available yet. You can still open the learning tools below.';
    }
}
$progressPercent = $studioStats['total'] > 0 ? min(100, (int) round($studioStats['completed'] / $studioStats['total'] * 100)) : 0;
$xp = (int) ($user['experience'] ?? 0);
$level = max(1, (int) ($user['level'] ?? 1));
$xpStart = ($level - 1) * ($level - 1) * 100;
$xpNext = $level * $level * 100;
$xpPercent = $user ? min(100, max(0, (int) round(($xp - $xpStart) / max(1, $xpNext - $xpStart) * 100))) : 0;

$pageTitle = 'Studio';
include __DIR__ . '/includes/header.php';
?>
<section class="workspace-page studio-dashboard">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">STUDIO WORKSPACE</p>
            <h1><?= $user ? 'Keep making progress' : 'Your piano learning studio' ?></h1>
            <p>Learn the fundamentals, practise songs, play the piano and build confidence through games.</p>
        </div>
        <a class="btn primary-btn" href="<?= e(url_path('tutorial.php')) ?>">Continue learning</a>
    </div>

    <?php if ($studioError): ?><div class="alert error"><?= e($studioError) ?></div><?php endif; ?>

    <?php if ($user): ?>
        <section class="workspace-progress-panel">
            <div class="workspace-progress-top">
                <div><p class="eyebrow">YOUR JOURNEY</p><h2>Level <?= $level ?> <span>· <?= number_format($xp) ?> XP</span></h2></div>
                <span class="workspace-progress-percent"><?= $xpPercent ?>%</span>
            </div>
            <div class="workspace-progress-track"><span style="width:<?= $xpPercent ?>%"></span></div>
            <small><?= number_format(max(0, $xpNext - $xp)) ?> XP to reach level <?= $level + 1 ?></small>
        </section>

        <div class="workspace-stat-grid studio-stat-grid">
            <article class="workspace-stat-card"><span>Tutorial sections</span><strong><?= $studioStats['completed'] ?> / <?= $studioStats['total'] ?></strong><small><?= $progressPercent ?>% completed</small></article>
            <article class="workspace-stat-card"><span>Piano sessions</span><strong><?= $studioStats['sessions'] ?></strong><small>Saved practice sessions</small></article>
            <article class="workspace-stat-card"><span>Average accuracy</span><strong><?= number_format($studioStats['accuracy'], 1) ?>%</strong><small>Across recorded sessions</small></article>
        </div>
    <?php else: ?>
        <section class="workspace-progress-panel guest-studio-panel">
            <p class="eyebrow">START YOUR JOURNEY</p>
            <h2>Learn at your own pace, one note at a time.</h2>
            <p>Create a free account to save your progress, earn XP and review your piano practice history.</p>
            <div class="workspace-actions">
                <a class="btn primary-btn" href="<?= e(url_path('account.php?mode=register')) ?>">Create account</a>
                <a class="text-button secondary" href="<?= e(url_path('account.php?mode=login')) ?>">Log in</a>
            </div>
        </section>
    <?php endif; ?>

    <div class="workspace-content-grid studio-content-grid">
        <section class="workspace-panel">
            <div class="workspace-panel-heading"><div><p class="eyebrow">PICK UP WHERE YOU LEFT OFF</p><h2>Learning tools</h2></div></div>
            <div class="workspace-shortcuts studio-shortcuts">
                <a href="<?= e(url_path('tutorial.php')) ?>"><span>📚</span><strong>Tutorials</strong><small>Build your piano foundations step by step</small></a>
                <a href="<?= e(url_path('songs.php')) ?>"><span>🎵</span><strong>Songs</strong><small>Explore songs and practise pieces</small></a>
                <a href="<?= e(url_path('piano.php')) ?>"><span>🎹</span><strong>Virtual piano</strong><small>Play notes and analyse your sessions</small></a>
                <a href="<?= e(url_path('game.php')) ?>"><span>🎮</span><strong>Games</strong><small>Train note recognition and key knowledge</small></a>
            </div>
        </section>

        <?php if ($user): ?>
        <section class="workspace-panel">
            <div class="workspace-panel-heading"><div><p class="eyebrow">PRACTICE HISTORY</p><h2>Recent piano sessions</h2></div><a class="text-button secondary" href="<?= e(url_path('piano.php')) ?>">Open piano</a></div>
            <?php if (!$studioStats['recent']): ?>
                <div class="workspace-empty"><strong>Your first session is waiting</strong><p>Start practising on the virtual piano and your saved sessions will appear here.</p></div>
            <?php else: ?>
                <div class="workspace-list">
                    <?php foreach ($studioStats['recent'] as $session): ?>
                        <div class="workspace-list-item">
                            <span class="workspace-session-icon">🎹</span>
                            <span class="workspace-list-main"><strong><?= e(date('d M Y · H:i', strtotime($session['datetime']))) ?></strong><small><?= number_format((float) $session['accuracy'], 1) ?>% accuracy · <?= e($session['averageSpeed']) ?> average timing</small></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="workspace-panel studio-preview-panel">
            <p class="eyebrow">A LITTLE PRACTICE GOES A LONG WAY</p>
            <h2>Explore, practise and improve.</h2>
            <p>You can preview the learning areas now. Sign in to keep your achievements and learning history together.</p>
        </section>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
