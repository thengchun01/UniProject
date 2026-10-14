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
$teachers = $db->query("SELECT user_id, username FROM users WHERE role='TEACHER' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$students = $db->query("SELECT user_id, username FROM users WHERE role='STUDENT' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

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
            $period = trim((string)($_POST['fee_period'] ?? 'month'));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Enter a course name (maximum 120 characters).');
            if (!is_numeric($feeRaw) || (float)$feeRaw < 0) throw new RuntimeException('Minimum fee must be zero or more.');
            if (!in_array($period, ['month','lesson'], true)) $period = 'month';
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO courses (course_name,class_mode,age_range,duration_options,minimum_fee,fee_period,description) VALUES (:n,:m,:a,:d,:f,:p,:x)');
                $stmt->execute(['n'=>$name,'m'=>$mode,'a'=>$age ?: null,'d'=>$duration ?: null,'f'=>round((float)$feeRaw,2),'p'=>$period,'x'=>$description ?: null]);
                $courseId = (int)$db->lastInsertId();
            } else {
                if ($courseId <= 0) throw new RuntimeException('Invalid course.');
                $stmt = $db->prepare('UPDATE courses SET course_name=:n,class_mode=:m,age_range=:a,duration_options=:d,minimum_fee=:f,fee_period=:p,description=:x WHERE course_id=:id');
                $stmt->execute(['n'=>$name,'m'=>$mode,'a'=>$age ?: null,'d'=>$duration ?: null,'f'=>round((float)$feeRaw,2),'p'=>$period,'x'=>$description ?: null,'id'=>$courseId]);
            }
            if (isset($_POST['save_assignments'])) {
                $db->beginTransaction();
                $db->prepare('DELETE FROM course_teachers WHERE course_id=?')->execute([$courseId]);
                $db->prepare('DELETE FROM course_students WHERE course_id=?')->execute([$courseId]);
                $teacherInsert = $db->prepare('INSERT IGNORE INTO course_teachers (course_id,teacher_id) VALUES (?,?)');
                $studentInsert = $db->prepare('INSERT IGNORE INTO course_students (course_id,student_id) VALUES (?,?)');
                foreach ((array)($_POST['teacher_ids'] ?? []) as $tid) if ((int)$tid > 0) $teacherInsert->execute([$courseId,(int)$tid]);
                foreach ((array)($_POST['student_ids'] ?? []) as $sid) if ((int)$sid > 0) $studentInsert->execute([$courseId,(int)$sid]);
                $db->commit();
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
        if ($db->inTransaction()) $db->rollBack();
        $error = $e instanceof PDOException && (string)$e->getCode() === '23000'
            ? 'A course with that name already exists.'
            : ($e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this course. Please check the database migration.');
    }
}

$courses = $db->query("SELECT c.* FROM courses c ORDER BY c.is_active DESC, c.course_name")->fetchAll(PDO::FETCH_ASSOC);
$teacherMap = $studentMap = [];
if ($courses) {
    $ids = array_map(static fn($c)=>(int)$c['course_id'], $courses);
    $ph = implode(',', array_fill(0,count($ids),'?'));
    $st = $db->prepare("SELECT ct.course_id, u.user_id, u.username FROM course_teachers ct JOIN users u ON u.user_id=ct.teacher_id WHERE ct.course_id IN ($ph) ORDER BY u.username");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $teacherMap[(int)$r['course_id']][] = $r;
    $st = $db->prepare("SELECT cs.course_id, u.user_id, u.username FROM course_students cs JOIN users u ON u.user_id=cs.student_id WHERE cs.course_id IN ($ph) ORDER BY u.username");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $studentMap[(int)$r['course_id']][] = $r;
}
$pageTitle = 'Courses';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/courses.css')) ?>">
<section class="courses-page">
    <div class="courses-heading">
        <div><p class="courses-eyebrow">COURSE MANAGEMENT</p><h1>Courses</h1><p>Manage course presets, minimum fees, eligible teachers and enrolled students. Active courses appear in Schedule.</p></div>
        <span class="courses-count"><?= count(array_filter($courses, static fn($c)=>(int)$c['is_active']===1)) ?> active courses</span>
    </div>
    <?php if ($message !== ''): ?><div class="courses-alert success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="courses-alert error"><?= e($error) ?></div><?php endif; ?>

    <section class="courses-panel">
        <div class="courses-panel-title"><div><h2>Create a course</h2><p>New courses become available as class presets in the Schedule page.</p></div></div>
        <form method="post" class="course-form">
            <input type="hidden" name="action" value="create">
            <label>Course name<input name="course_name" maxlength="120" required placeholder="e.g. Drums"></label>
            <label>Class format<select name="class_mode"><option>Individual</option><option>Individual / small group</option><option>Small group</option></select></label>
            <label>Minimum fee (RM)<input type="number" name="minimum_fee" min="0" step="0.01" value="0.00" required></label>
            <label>Fee period<select name="fee_period"><option value="month">Per month</option><option value="lesson">Per lesson</option></select></label>
            <label>Recommended age<input name="age_range" maxlength="40" placeholder="e.g. 7+"></label>
            <label>Lesson duration<input name="duration_options" maxlength="80" placeholder="e.g. 30 / 45 min"></label>
            <label class="course-description">Description / learning focus<textarea name="description" rows="2" placeholder="Course overview and learning goals"></textarea></label>
            <div class="course-form-actions"><button type="submit" class="course-primary-btn">＋ Create course</button></div>
        </form>
    </section>

    <div class="courses-list-heading"><h2>Course catalogue</h2><span><?= count($courses) ?> total</span></div>
    <?php foreach ($courses as $course): $cid=(int)$course['course_id']; $assignedT=array_map(static fn($r)=>(int)$r['user_id'],$teacherMap[$cid]??[]); $assignedS=array_map(static fn($r)=>(int)$r['user_id'],$studentMap[$cid]??[]); ?>
    <details class="course-card" <?= count($courses)===1 ? 'open' : '' ?>>
        <summary>
            <span class="course-card-name"><?= e($course['course_name']) ?></span>
            <span class="course-card-meta"><?= e($course['class_mode']) ?> · Minimum RM <?= number_format((float)$course['minimum_fee'],2) ?>/<?= $course['fee_period']==='lesson'?'lesson':'month' ?></span>
            <span class="course-status <?= (int)$course['is_active']===1?'active':'inactive' ?>"><?= (int)$course['is_active']===1?'Active':'Inactive' ?></span>
        </summary>
        <form method="post" class="course-edit-form">
            <input type="hidden" name="action" value="update"><input type="hidden" name="course_id" value="<?= $cid ?>"><input type="hidden" name="save_assignments" value="1">
            <div class="course-form">
                <label>Course name<input name="course_name" maxlength="120" required value="<?= e($course['course_name']) ?>"></label>
                <label>Class format<select name="class_mode"><?php foreach (['Individual','Individual / small group','Small group'] as $m): ?><option <?= $course['class_mode']===$m?'selected':'' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
                <label>Minimum fee (RM)<input type="number" name="minimum_fee" min="0" step="0.01" required value="<?= e(number_format((float)$course['minimum_fee'],2,'.','')) ?>"></label>
                <label>Fee period<select name="fee_period"><option value="month" <?= $course['fee_period']==='month'?'selected':'' ?>>Per month</option><option value="lesson" <?= $course['fee_period']==='lesson'?'selected':'' ?>>Per lesson</option></select></label>
                <label>Recommended age<input name="age_range" maxlength="40" value="<?= e($course['age_range']??'') ?>"></label>
                <label>Lesson duration<input name="duration_options" maxlength="80" value="<?= e($course['duration_options']??'') ?>"></label>
                <label class="course-description">Description / learning focus<textarea name="description" rows="2"><?= e($course['description']??'') ?></textarea></label>
            </div>
            <div class="course-assignments">
                <fieldset><legend>Teachers who can teach this course</legend><?php if (!$teachers): ?><p>No teacher accounts found.</p><?php else: ?><div class="course-check-grid"><?php foreach($teachers as $t): ?><label><input type="checkbox" name="teacher_ids[]" value="<?= (int)$t['user_id'] ?>" <?= in_array((int)$t['user_id'],$assignedT,true)?'checked':'' ?>><?= e($t['username']) ?></label><?php endforeach; ?></div><?php endif; ?></fieldset>
                <fieldset><legend>Students learning this course</legend><?php if (!$students): ?><p>No student accounts found.</p><?php else: ?><div class="course-check-grid"><?php foreach($students as $st): ?><label><input type="checkbox" name="student_ids[]" value="<?= (int)$st['user_id'] ?>" <?= in_array((int)$st['user_id'],$assignedS,true)?'checked':'' ?>><?= e($st['username']) ?></label><?php endforeach; ?></div><?php endif; ?></fieldset>
            </div>
            <div class="course-edit-actions"><button type="submit" class="course-primary-btn">Save course and assignments</button></div>
        </form>
        <form method="post" class="course-toggle-form"><input type="hidden" name="action" value="toggle"><input type="hidden" name="course_id" value="<?= $cid ?>"><button class="course-secondary-btn" type="submit"><?= (int)$course['is_active']===1?'Deactivate course':'Reactivate course' ?></button></form>
    </details>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
