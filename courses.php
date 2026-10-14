<?php
require_once __DIR__ . '/includes/config.php';
$user = current_user();
if (!$user || normalize_role($user['role'] ?? '') !== 'ADMIN') {
    redirect_to('account.php?mode=login');
}
if (!is_database_connected()) {
    redirect_to('index.php');
}
$db = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create' || $action === 'update') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            $name = trim((string)($_POST['course_name'] ?? ''));
            $mode = trim((string)($_POST['class_mode'] ?? 'Individual'));
            $age = trim((string)($_POST['age_range'] ?? ''));
            $duration = trim((string)($_POST['duration_options'] ?? ''));
            $feeRaw = trim((string)($_POST['minimum_fee'] ?? '0'));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Enter a course name (maximum 120 characters).');
            if (!is_numeric($feeRaw) || (float)$feeRaw < 0) throw new RuntimeException('Minimum monthly fee must be zero or more.');
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO courses (course_name,class_mode,age_range,duration_options,minimum_fee,description) VALUES (:n,:m,:a,:d,:f,:x)');
                $stmt->execute(['n'=>$name,'m'=>$mode,'a'=>$age ?: null,'d'=>$duration ?: null,'f'=>round((float)$feeRaw,2),'x'=>$description ?: null]);
            } else {
                if ($courseId <= 0) throw new RuntimeException('Invalid course.');
                $stmt = $db->prepare('UPDATE courses SET course_name=:n,class_mode=:m,age_range=:a,duration_options=:d,minimum_fee=:f,description=:x WHERE course_id=:id');
                $stmt->execute(['n'=>$name,'m'=>$mode,'a'=>$age ?: null,'d'=>$duration ?: null,'f'=>round((float)$feeRaw,2),'x'=>$description ?: null,'id'=>$courseId]);
            }
            $message = $action === 'create' ? 'Course created.' : 'Course updated.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['course_id'] ?? 0);
            $db->prepare('UPDATE courses SET is_active = IF(is_active=1,0,1) WHERE course_id=?')->execute([$id]);
            $message = 'Course status updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $error = $e instanceof PDOException && (string)$e->getCode() === '23000'
            ? 'A course with that name already exists.'
            : ($e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this course. Please check the database migration.');
    }
}

$courses = $db->query(
    "SELECT c.*,
        (SELECT COUNT(DISTINCT cs.student_id)
         FROM course_students cs INNER JOIN users su ON su.user_id=cs.student_id
         WHERE cs.course_id=c.course_id AND su.role='STUDENT') AS enrolled_count,
        (SELECT MAX(l.lesson_date) FROM lessons l
         WHERE l.course_id=c.course_id AND l.teacher_id IS NOT NULL AND l.status <> 'CANCELLED') AS last_taught
     FROM courses c
     ORDER BY c.is_active DESC, c.course_name"
)->fetchAll(PDO::FETCH_ASSOC);

$teacherMap = [];
if ($courses) {
    $ids = array_map(static fn($c)=>(int)$c['course_id'], $courses);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT c.course_id, u.user_id, u.username, MAX(l.lesson_date) AS last_taught
         FROM courses c
         INNER JOIN lessons l ON (l.course_id=c.course_id OR (l.course_id IS NULL AND l.title=c.course_name))
         INNER JOIN users u ON u.user_id=l.teacher_id
         WHERE c.course_id IN ($ph) AND l.status <> 'CANCELLED'
         GROUP BY c.course_id, u.user_id, u.username
         ORDER BY c.course_id ASC, last_taught DESC, u.username ASC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
        $teacherMap[(int)$teacher['course_id']][] = $teacher;
    }
}

function course_min_duration($value): int
{
    if (preg_match('/\d+/', (string)$value, $match)) return (int)$match[0];
    return 0;
}

$pageTitle = 'Courses';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/courses.css')) ?>">
<section class="courses-page">
    <div class="courses-heading">
        <div><p class="courses-eyebrow">COURSE MANAGEMENT</p><h1>Courses</h1><p>Manage the course catalogue. Enrolment counts and teachers are based on course assignments and scheduled lessons. Active courses appear in Schedule.</p></div>
        <span class="courses-count"><?= count(array_filter($courses, static fn($c)=>(int)$c['is_active']===1)) ?> active courses</span>
    </div>
    <?php if ($message !== ''): ?><div class="courses-alert success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="courses-alert error"><?= e($error) ?></div><?php endif; ?>

    <section class="courses-panel">
        <div class="courses-panel-title"><div><h2>Create a course</h2><p>Course fees are monthly, matching the stakeholder's standard billing model. The first duration listed is treated as the minimum lesson duration.</p></div></div>
        <form method="post" class="course-form">
            <input type="hidden" name="action" value="create">
            <label>Course name<input name="course_name" maxlength="120" required placeholder="e.g. Drums"></label>
            <label>Class format<select name="class_mode"><option>Individual</option><option>Individual / small group</option><option>Small group</option></select></label>
            <label>Minimum monthly fee (RM)<input type="number" name="minimum_fee" min="0" step="0.01" value="0.00" required></label>
            <label>Recommended age<input name="age_range" maxlength="40" placeholder="e.g. 7+"></label>
            <label>Lesson durations<input name="duration_options" maxlength="80" placeholder="e.g. 30 / 45 min" required></label>
            <label class="course-description">Description / learning focus<textarea name="description" rows="2" placeholder="Course overview and learning goals"></textarea></label>
            <div class="course-form-actions"><button type="submit" class="course-primary-btn">＋ Create course</button></div>
        </form>
    </section>

    <div class="courses-list-heading"><div><h2>Course catalogue</h2><p>Choose a column heading to sort the table. Student counts open the course schedule.</p></div><span><?= count($courses) ?> total</span></div>
    <div class="courses-table-wrap">
        <table class="courses-table" id="coursesTable">
            <thead><tr>
                <th><button type="button" data-sort="name" data-type="text">Course <span>↕</span></button></th>
                <th>Class format</th>
                <th><button type="button" data-sort="duration" data-type="number">Min. duration <span>↕</span></button></th>
                <th>Monthly fee</th>
                <th><button type="button" data-sort="students" data-type="number">Enrolled students <span>↕</span></button></th>
                <th>Recently taught by</th>
                <th>Status</th>
                <th>Manage</th>
            </tr></thead>
            <tbody>
            <?php foreach ($courses as $course):
                $cid=(int)$course['course_id'];
                $minDuration=course_min_duration($course['duration_options'] ?? '');
                $recentTeachers=$teacherMap[$cid] ?? [];
                $recentTeacher=$recentTeachers[0] ?? null;
                $lastMonth=$recentTeacher && !empty($recentTeacher['last_taught']) ? date('Y-m', strtotime($recentTeacher['last_taught'])) : date('Y-m');
            ?>
                <tr class="course-row" data-course-id="<?= $cid ?>" data-name="<?= e(mb_strtolower($course['course_name'])) ?>" data-duration="<?= $minDuration ?>" data-students="<?= (int)$course['enrolled_count'] ?>">
                    <td><strong><?= e($course['course_name']) ?></strong><small><?= e($course['age_range'] ?: 'Age not specified') ?></small></td>
                    <td><?= e($course['class_mode']) ?></td>
                    <td><?= $minDuration > 0 ? $minDuration . ' min' : 'Not set' ?></td>
                    <td>RM <?= number_format((float)$course['minimum_fee'],2) ?></td>
                    <td><a class="course-count-link" href="<?= e(url_path('schedule.php?course_id='.$cid)) ?>" title="View scheduled lessons for this course"><strong><?= (int)$course['enrolled_count'] ?></strong><span>View schedule ↗</span></a></td>
                    <td>
                        <?php if ($recentTeachers): ?>
                            <div class="course-teacher-list">
                            <?php foreach (array_slice($recentTeachers,0,3) as $teacher): $teacherMonth=!empty($teacher['last_taught'])?date('Y-m',strtotime($teacher['last_taught'])):$lastMonth; ?>
                                <a href="<?= e(url_path('schedule.php?course_id='.$cid.'&teacher_id='.(int)$teacher['user_id'].'&month='.$teacherMonth)) ?>" title="View this teacher's course schedule"><?= e($teacher['username']) ?><small><?= e(date('d M Y',strtotime($teacher['last_taught']))) ?></small></a>
                            <?php endforeach; ?>
                            <?php if (count($recentTeachers)>3): ?><span class="course-more-teachers">+<?= count($recentTeachers)-3 ?> more</span><?php endif; ?>
                            </div>
                        <?php else: ?><span class="course-muted">No lessons recorded yet</span><?php endif; ?>
                    </td>
                    <td><span class="course-status <?= (int)$course['is_active']===1?'active':'inactive' ?>"><?= (int)$course['is_active']===1?'Active':'Inactive' ?></span></td>
                    <td><button type="button" class="course-secondary-btn course-edit-toggle" data-target="course-edit-<?= $cid ?>">Edit</button>
                        <form method="post" class="course-inline-toggle"><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= $cid ?>"><button class="course-secondary-btn" type="submit"><?= (int)$course['is_active']===1?'Deactivate':'Reactivate' ?></button></form>
                    </td>
                </tr>
                <tr class="course-edit-row" id="course-edit-<?= $cid ?>" hidden><td colspan="8">
                    <form method="post" class="course-edit-form">
                        <input type="hidden" name="action" value="update"><input type="hidden" name="course_id" value="<?= $cid ?>">
                        <div class="course-form">
                            <label>Course name<input name="course_name" maxlength="120" required value="<?= e($course['course_name']) ?>"></label>
                            <label>Class format<select name="class_mode"><?php foreach (['Individual','Individual / small group','Small group'] as $m): ?><option <?= $course['class_mode']===$m?'selected':'' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
                            <label>Minimum monthly fee (RM)<input type="number" name="minimum_fee" min="0" step="0.01" required value="<?= e(number_format((float)$course['minimum_fee'],2,'.','')) ?>"></label>
                            <label>Recommended age<input name="age_range" maxlength="40" value="<?= e($course['age_range']??'') ?>"></label>
                            <label>Lesson durations<input name="duration_options" maxlength="80" required value="<?= e($course['duration_options']??'') ?>"></label>
                            <label class="course-description">Description / learning focus<textarea name="description" rows="2"><?= e($course['description']??'') ?></textarea></label>
                        </div>
                        <div class="course-edit-actions"><button type="submit" class="course-primary-btn">Save course</button></div>
                    </form>
                </td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
(function () {
    var table = document.getElementById('coursesTable');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    var direction = {};
    table.querySelectorAll('thead button[data-sort]').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.getAttribute('data-sort');
            var type = button.getAttribute('data-type');
            direction[key] = direction[key] === 'asc' ? 'desc' : 'asc';
            var rows = Array.from(tbody.querySelectorAll('tr.course-row'));
            rows.sort(function (a, b) {
                var av = a.getAttribute('data-' + key) || '';
                var bv = b.getAttribute('data-' + key) || '';
                var cmp = type === 'number' ? (Number(av) - Number(bv)) : av.localeCompare(bv);
                return direction[key] === 'asc' ? cmp : -cmp;
            });
            rows.forEach(function (row) {
                var detail = document.getElementById('course-edit-' + row.getAttribute('data-course-id'));
                tbody.appendChild(row);
                if (detail) tbody.appendChild(detail);
            });
            table.querySelectorAll('thead button[data-sort] span').forEach(function (span) { span.textContent = '↕'; });
            button.querySelector('span').textContent = direction[key] === 'asc' ? '↑' : '↓';
        });
    });
    table.querySelectorAll('.course-edit-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = document.getElementById(button.getAttribute('data-target'));
            if (row) {
                row.hidden = !row.hidden;
                button.textContent = row.hidden ? 'Edit' : 'Close';
                if (!row.hidden) row.scrollIntoView({behavior:'smooth', block:'nearest'});
            }
        });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
