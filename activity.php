<?php
require_once __DIR__ . '/includes/config.php';

if (!is_logged_in()) {
    redirect_to('account.php');
}

$activityId = (int) ($_GET['id'] ?? 0);
$activity = null;
$summary = [];
$details = [];
$activityError = null;

if ($activityId <= 0) {
    $activityError = 'Activity not found.';
} elseif (is_database_connected()) {
    try {
        $stmt = db()->prepare(
            'SELECT *
             FROM user_activity_log
             WHERE activity_id = :activity_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'activity_id' => $activityId,
            'user_id' => (int) $_SESSION['user_id'],
        ]);
        $activity = $stmt->fetch();

        if (!$activity) {
            $activityError = 'Activity not found.';
        } else {
            $summary = json_decode($activity['summary_json'] ?: '[]', true) ?: [];
            $details = json_decode($activity['detail_json'] ?: '[]', true) ?: [];
        }
    } catch (Throwable $exception) {
        $activityError = 'Activity data could not be loaded.';
    }
} else {
    $activityError = 'Database connection to PSM is not ready.';
}

include __DIR__ . '/includes/header.php';
?>

<h1 class="section-title">Activity Detail</h1>
<div class="account-page-content">
    <?php if ($activityError): ?>
        <div class="alert error"><?php echo e($activityError); ?></div>
        <a class="text-button" href="<?php echo e(url_path('account.php')); ?>">Back to Account</a>
    <?php else: ?>
        <div class="account-header">
            <div>
                <p class="eyebrow"><?php echo e(str_replace('_', ' ', $activity['activity_type'])); ?></p>
                <h2><?php echo e($activity['title']); ?></h2>
            </div>
            <a class="text-button" href="<?php echo e(url_path($activity['shortcut_url'] ?: 'account.php')); ?>">Open Mode</a>
        </div>

        <div class="card-grid account-summary">
            <div class="card">
                <h3>Score</h3>
                <strong><?php echo $activity['score'] !== null ? e($activity['score']) : '--'; ?></strong>
            </div>
            <div class="card">
                <h3>Accuracy</h3>
                <strong><?php echo $activity['accuracy'] !== null ? e($activity['accuracy']) . '%' : '--'; ?></strong>
            </div>
            <div class="card">
                <h3>Duration</h3>
                <strong><?php echo $activity['duration_seconds'] !== null ? e($activity['duration_seconds']) . 's' : '--'; ?></strong>
            </div>
            <div class="card">
                <h3>Completed</h3>
                <strong class="activity-date"><?php echo e($activity['created_at']); ?></strong>
            </div>
        </div>

        <?php if ($summary): ?>
            <section class="data-section">
                <h2>Round Summary</h2>
                <table class="data-table compact-table">
                    <tbody>
                        <?php foreach ($summary as $key => $value): ?>
                            <tr>
                                <th><?php echo e(ucwords(str_replace('_', ' ', $key))); ?></th>
                                <td><?php echo e(is_array($value) ? json_encode($value) : $value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if (!empty($details['attempts']) && is_array($details['attempts'])): ?>
            <section class="data-section">
                <h2>Answers</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Expected</th>
                            <th>Chosen</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($details['attempts'], 0, 50) as $index => $attempt): ?>
                            <tr>
                                <td><?php echo e($index + 1); ?></td>
                                <td><?php echo e($attempt['expected'] ?? '--'); ?></td>
                                <td><?php echo e($attempt['chosen'] ?? '--'); ?></td>
                                <td><?php echo !empty($attempt['correct']) ? 'Correct' : 'Wrong'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if (!empty($details['mistakes']) && is_array($details['mistakes'])): ?>
            <section class="data-section">
                <h2>Mistakes</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Expected</th>
                            <th>Pressed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($details['mistakes'], 0, 50) as $index => $mistake): ?>
                            <tr>
                                <td><?php echo e($index + 1); ?></td>
                                <td><?php echo e(is_array($mistake['expected'] ?? null) ? implode(', ', $mistake['expected']) : ($mistake['expected'] ?? '--')); ?></td>
                                <td><?php echo e($mistake['pressed'] ?? '--'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if (!empty($details['analysis']) && is_array($details['analysis'])): ?>
            <section class="data-section">
                <h2>Piano Analysis Events</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Expected MIDI</th>
                            <th>Pressed MIDI</th>
                            <th>Timing</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($details['analysis'], 0, 50) as $index => $event): ?>
                            <tr>
                                <td><?php echo e($index + 1); ?></td>
                                <td><?php echo e($event['expectedMidi'] ?? '--'); ?></td>
                                <td><?php echo e($event['pressedMidi'] ?? '--'); ?></td>
                                <td><?php echo e($event['timingDeltaMs'] ?? '--'); ?>ms</td>
                                <td><?php echo !empty($event['correct']) ? 'Correct' : 'Wrong'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <p class="activity-actions">
            <a class="text-button" href="<?php echo e(url_path('account.php')); ?>">Back to Account</a>
            <a class="text-button" href="<?php echo e(url_path($activity['shortcut_url'] ?: 'account.php')); ?>">Open Mode</a>
        </p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
