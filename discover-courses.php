<?php
require_once __DIR__ . '/includes/config.php';
$courses = [];
$dbError = false;
if (is_database_connected()) {
    try {
        $stmt = db()->query(
            "SELECT course_id, course_name, class_mode, age_range, duration_options, minimum_fee, description
             FROM courses
             WHERE is_active = 1
             ORDER BY course_name ASC"
        );
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $dbError = true;
    }
}
$pageTitle = 'Courses';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/homepage.css') . '?v=' . filemtime(__DIR__ . '/assets/css/homepage.css')) ?>">
<main class="container discover-courses-page">
    <section class="data-section offered-courses-section">
        <p class="section-kicker">FIND YOUR STARTING POINT</p>
        <h1 class="section-title">Courses We Offer</h1>
        <p class="section-intro">Explore our available courses and find a learning path that suits you.</p>
        <?php if ($dbError): ?>
            <div class="offered-courses-empty"><p>We couldn't load the course catalogue right now. Please try again later.</p></div>
        <?php elseif ($courses): ?>
            <div class="card-grid offered-course-grid">
                <?php foreach ($courses as $course): ?>
                    <article class="card offered-course-card">
                        <div class="offered-course-topline">
                            <span><?= e($course['class_mode'] ?: 'Class') ?></span>
                            <span>RM <?= number_format((float) $course['minimum_fee'], 2) ?> / month</span>
                        </div>
                        <h2><?= e($course['course_name']) ?></h2>
                        <p><?= e($course['description'] ?: 'Build your skills with structured lessons and guided practice.') ?></p>
                        <div class="offered-course-meta">
                            <?php if (!empty($course['age_range'])): ?><span><?= e($course['age_range']) ?></span><?php endif; ?>
                            <?php if (!empty($course['duration_options'])): ?><span><?= e($course['duration_options']) ?></span><?php endif; ?>
                        </div>
                        <a href="<?= e(url_path('account.php?mode=register')) ?>" class="text-button">Get Started</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="offered-courses-empty"><p>No active courses are available at the moment. Please check back soon.</p></div>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
