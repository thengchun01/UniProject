<?php
require_once __DIR__ . '/includes/config.php';

$user = current_user();
if (!$user || normalize_role($user['role'] ?? '') !== 'ADMIN') {
    redirect_to('account.php?mode=login');
}
if (!is_database_connected() || $lessonsManager === null) {
    redirect_to('courses.php?tab=requests');
}

$db = db();
$requestId = (int) ($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$error = '';
$success = '';
$stmt = $db->prepare(
    'SELECT r.*, c.course_name AS current_course_name, c.minimum_fee, c.is_active,
            su.username AS student_username, su.email AS student_email,
            tu.username AS teacher_username
     FROM course_requests r
     LEFT JOIN courses c ON c.course_id = r.course_id
     LEFT JOIN users su ON su.user_id = r.student_id
     LEFT JOIN users tu ON tu.user_id = r.assigned_teacher_id
     WHERE r.request_id = ? LIMIT 1'
);
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request || empty($request['student_id']) || empty($request['assigned_teacher_id'])) {
    http_response_code(404);
    $error = 'This request needs a linked student account and an assigned teacher before it can be scheduled.';
} elseif (!empty($request['scheduled_lesson_id'])) {
    $success = 'This request has already been added to the schedule as lesson #' . (int) $request['scheduled_lesson_id'] . '.';
}

$preferredDays = array_values(array_filter(array_map('trim', explode(',', (string) ($request['preferred_days'] ?? '')))));
$allowedDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$preferredDays = array_values(array_intersect($allowedDays, $preferredDays));
$defaultDay = $preferredDays[0] ?? 'Monday';
$defaultDate = !empty($request['preferred_start_date']) && $request['preferred_start_date'] >= date('Y-m-d')
    ? $request['preferred_start_date'] : date('Y-m-d');
if ($preferredDays) {
    for ($offset = 0; $offset <= 7; $offset++) {
        $candidate = date('Y-m-d', strtotime($defaultDate . ' +' . $offset . ' days'));
        if (in_array(date('l', strtotime($candidate)), $preferredDays, true)) {
            $defaultDate = $candidate;
            $defaultDay = date('l', strtotime($candidate));
            break;
        }
    }
}
$defaultTime = substr((string) ($request['preferred_time_start'] ?? '15:00'), 0, 5);
$duration = (int) preg_replace('/[^0-9]/', '', (string) ($request['lesson_duration'] ?? '0'));
$csrfKey = 'course_request_schedule_token';
if (empty($_SESSION[$csrfKey])) $_SESSION[$csrfKey] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $success === '') {
    try {
        if (!hash_equals((string) $_SESSION[$csrfKey], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your form session expired. Please refresh and try again.');
        }
        $day = trim((string) ($_POST['preferred_day'] ?? ''));
        $date = trim((string) ($_POST['lesson_date'] ?? ''));
        $start = trim((string) ($_POST['start_time'] ?? ''));
        if (!in_array($day, $preferredDays, true)) throw new RuntimeException('Choose one of the student’s preferred days.');
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
            throw new RuntimeException('Choose a valid class date.');
        }
        if ($date < date('Y-m-d') || (!empty($request['preferred_start_date']) && $date < $request['preferred_start_date'])) {
            throw new RuntimeException('The class date cannot be earlier than today or the student’s preferred start date.');
        }
        if (date('l', strtotime($date)) !== $day) throw new RuntimeException('The selected date does not match the chosen weekday.');
        if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $start)) throw new RuntimeException('Choose a valid start time.');
        if ($duration <= 0 || $duration > 480 || $duration % 15 !== 0) throw new RuntimeException('The requested lesson duration is not supported by the schedule.');
        $startMinutes = ((int) substr($start,0,2) * 60) + (int) substr($start,3,2);
        $endMinutes = $startMinutes + $duration;
        if ($endMinutes >= 1440) throw new RuntimeException('This lesson would finish after midnight.');
        $end = sprintf('%02d:%02d', intdiv($endMinutes,60), $endMinutes % 60);

        $dates = [];
        $cursor = $date;
        $monthEnd = date('Y-m-t', strtotime($date));
        while ($cursor <= $monthEnd && count($dates) < 5) {
            [$slotOk, $slotError] = $lessonsManager->checkSlot($cursor, $start, $end);
            if (!$slotOk) throw new RuntimeException($slotError);
            $dates[] = $cursor;
            $cursor = date('Y-m-d', strtotime($cursor . ' +7 days'));
        }
        if (!$dates) throw new RuntimeException('No valid lesson dates were found.');

        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM course_requests WHERE request_id=? FOR UPDATE');
            $lock->execute([$requestId]);
            $lockedRequest = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRequest) throw new RuntimeException('Course request not found.');
            if (!empty($lockedRequest['scheduled_lesson_id'])) throw new RuntimeException('This request has already been scheduled.');
            $studentId = (int) ($lockedRequest['student_id'] ?? 0);
            $teacherId = (int) ($lockedRequest['assigned_teacher_id'] ?? 0);
            $courseId = (int) ($lockedRequest['course_id'] ?? 0);
            $roleCheck = $db->prepare('SELECT role FROM users WHERE user_id=?');
            $roleCheck->execute([$studentId]);
            if (normalize_role((string) $roleCheck->fetchColumn()) !== 'STUDENT') throw new RuntimeException('The linked student account is no longer valid.');
            $roleCheck->execute([$teacherId]);
            if (normalize_role((string) $roleCheck->fetchColumn()) !== 'TEACHER') throw new RuntimeException('The assigned teacher account is no longer valid.');
            $courseCheck = $db->prepare('SELECT course_name, minimum_fee, is_active FROM courses WHERE course_id=?');
            $courseCheck->execute([$courseId]);
            $course = $courseCheck->fetch(PDO::FETCH_ASSOC);
            if (!$course || (int)$course['is_active'] !== 1) throw new RuntimeException('The requested course is no longer active.');

            $group = count($dates) > 1 ? bin2hex(random_bytes(8)) : null;
            $fee = round((float) $course['minimum_fee'], 2);
            $firstLessonId = 0;
            foreach ($dates as $lessonDate) {
                $lessonId = $lessonsManager->createLesson([
                    'teacher_id' => $teacherId,
                    'title' => (string) $course['course_name'],
                    'lesson_date' => $lessonDate,
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => 'SCHEDULED',
                    'recurrence_group' => $group,
                    'created_by' => (int) $user['user_id'],
                ]);
                $link = $db->prepare('UPDATE lessons SET course_id=? WHERE lesson_id=?');
                $link->execute([$courseId, $lessonId]);
                $lessonsManager->addEnrollment($lessonId, $studentId, $fee);
                $roster = $db->prepare('INSERT IGNORE INTO course_students (course_id, student_id) VALUES (?, ?)');
                $roster->execute([$courseId, $studentId]);
                $monthly = $db->prepare(
                    "INSERT IGNORE INTO monthly_fees (student_id, month, recurrence_group, amount, status)
                     VALUES (?, ?, ?, ?, 'UNPAID')"
                );
                $monthly->execute([$studentId, substr($lessonDate,0,7), (string)($group ?? ''), $fee]);
                if ($firstLessonId === 0) $firstLessonId = $lessonId;
            }
            $update = $db->prepare(
                "UPDATE course_requests SET scheduled_lesson_id=?, status='SCHEDULED', reviewed_by=?, reviewed_at=NOW()
                 WHERE request_id=? AND scheduled_lesson_id IS NULL"
            );
            $update->execute([$firstLessonId, (int)$user['user_id'], $requestId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('This request was scheduled in another session. Please reload.');
            $db->commit();
            $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
            flash_set('success', 'Course request #' . $requestId . ' was added to the schedule (' . count($dates) . ' lesson' . (count($dates) === 1 ? '' : 's') . ').');
            redirect_to('courses.php?tab=requests');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to add this request to the schedule. No schedule changes were saved.';
    }
}

$pageTitle = 'Add Course Request to Schedule';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/courses.css')) ?>">
<main class="course-request-page">
    <div class="course-request-heading">
        <p class="courses-eyebrow">COURSE MANAGEMENT</p>
        <h1>Add course request to Schedule</h1>
        <p>Confirm the first lesson date and time. The class will repeat weekly through the end of that month.</p>
    </div>
    <?php if ($error !== ''): ?><div class="courses-alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="courses-alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($request && empty($request['scheduled_lesson_id']) && !empty($request['student_id']) && !empty($request['assigned_teacher_id'])): ?>
        <section class="courses-panel">
            <h2><?= e($request['course_name']) ?></h2>
            <p><strong>Student:</strong> <?= e($request['student_username'] ?? $request['full_name']) ?> · <?= e($request['student_email'] ?? $request['email']) ?></p>
            <p><strong>Teacher:</strong> <?= e($request['teacher_username'] ?? 'Assigned') ?></p>
            <p><strong>Duration:</strong> <?= (int)$duration ?> minutes · <strong>Monthly fee:</strong> RM <?= number_format((float)$request['minimum_fee'],2) ?></p>
            <p><strong>Preferred time:</strong> <?= e(date('g:i a',strtotime($request['preferred_time_start']))) ?> – <?= e(date('g:i a',strtotime($request['preferred_time_end']))) ?></p>
            <form method="post" class="course-form">
                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION[$csrfKey]) ?>">
                <label>Preferred weekday
                    <select name="preferred_day" required>
                        <?php foreach ($preferredDays as $dayOption): ?>
                            <option value="<?= e($dayOption) ?>" <?= $dayOption === $defaultDay ? 'selected' : '' ?>><?= e($dayOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>First lesson date<input type="date" name="lesson_date" min="<?= e(max(date('Y-m-d'), (string)($request['preferred_start_date'] ?? date('Y-m-d')))) ?>" value="<?= e($defaultDate) ?>" required></label>
                <label>Class start time<input type="time" name="start_time" value="<?= e($defaultTime) ?>" required></label>
                <div class="course-form-actions">
                    <button type="submit" class="course-primary-btn">Confirm and add to Schedule</button>
                    <a class="course-secondary-btn" href="<?= e(url_path('courses.php?tab=requests')) ?>">Cancel</a>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<script>
(function () {
    var daySelect = document.querySelector('select[name="preferred_day"]');
    var dateInput = document.querySelector('input[name="lesson_date"]');
    if (!daySelect || !dateInput) return;
    daySelect.addEventListener('change', function () {
        if (!dateInput.value) return;
        var selected = new Date(dateInput.value + 'T12:00:00');
        var names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var target = names.indexOf(daySelect.value);
        var offset = (target - selected.getDay() + 7) % 7;
        if (offset === 0) return;
        selected.setDate(selected.getDate() + offset);
        var yyyy = selected.getFullYear();
        var mm = String(selected.getMonth() + 1).padStart(2, '0');
        var dd = String(selected.getDate()).padStart(2, '0');
        dateInput.value = yyyy + '-' + mm + '-' + dd;
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
