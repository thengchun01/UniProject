<?php
require_once __DIR__ . '/includes/config.php';

$user = current_user();
$dbReady = is_database_connected();
$courses = [];
$selectedCourse = null;
$error = '';
$success = flash_get('course_request_success');
$old = $_POST;

if ($dbReady) {
    try {
        $courses = db()->query(
            "SELECT course_id, course_name, duration_options
             FROM courses WHERE is_active = 1 ORDER BY course_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'The course list is temporarily unavailable.';
    }
}

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
foreach ($courses as $course) {
    if ((int) $course['course_id'] === $courseId) {
        $selectedCourse = $course;
        break;
    }
}

function course_request_duration_options(?string $value): array {
    $parts = preg_split('/[,;|\\/\\r\\n]+/', (string) $value);
    $options = [];
    foreach ($parts ?: [] as $part) {
        $label = trim($part);
        if ($label === '') continue;

        if (!preg_match('/^(\\d+(?:\\.\\d+)?)\\s*(minutes?|mins?|m|hours?|hrs?|h)?$/i', $label, $match)) {
            continue;
        }

        $amount = (float) $match[1];
        $unit = strtolower($match[2] ?? '');
        $minutes = (int) round(in_array($unit, ['hour', 'hours', 'hr', 'hrs', 'h'], true) ? $amount * 60 : $amount);
        if ($minutes <= 0 || $minutes > 480) continue;

        $display = $minutes . ' min';
        if (!in_array($minutes, array_column($options, 'minutes'), true)) {
            $options[] = ['label' => $display, 'minutes' => $minutes];
        }
    }
    return $options;
}

if (empty($_SESSION['course_request_token'])) {
    $_SESSION['course_request_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady && $error === '') {
    try {
        if (!hash_equals((string) $_SESSION['course_request_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your form session expired. Please refresh the page and try again.');
        }
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            throw new RuntimeException('Unable to submit this request.');
        }

        $courseId = (int) ($_POST['course_id'] ?? 0);
        $stmt = db()->prepare('SELECT course_id, course_name, duration_options FROM courses WHERE course_id=? AND is_active=1');
        $stmt->execute([$courseId]);
        $selectedCourse = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$selectedCourse) {
            throw new RuntimeException('Please choose an available course.');
        }

        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $age = trim((string) ($_POST['student_age'] ?? ''));
        $days = $_POST['preferred_days'] ?? [];
        $timeStart = trim((string) ($_POST['preferred_time_start'] ?? ''));
        $duration = (int) ($_POST['lesson_duration'] ?? 0);
        $startDate = trim((string) ($_POST['preferred_start_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Please enter your name (maximum 120 characters).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) throw new RuntimeException('Please enter a valid email address.');
        if ($phone === '' || mb_strlen($phone) > 40) throw new RuntimeException('Please enter a contact number.');
        if (!is_array($days)) $days = [];
        $allowedDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $days = array_values(array_unique(array_intersect($allowedDays, array_map('strval', $days))));
        if (!$days) throw new RuntimeException('Please select at least one preferred day.');
        $availableDurations = course_request_duration_options($selectedCourse['duration_options'] ?? '');
        $validDuration = false;
        foreach ($availableDurations as $option) {
            if ($option['minutes'] === $duration) {
                $validDuration = true;
                break;
            }
        }
        if (!$validDuration) {
            throw new RuntimeException('Please choose one of the available lesson durations for this course.');
        }
        if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $timeStart)) {
            throw new RuntimeException('Please choose a valid class start time.');
        }
        [$startHour, $startMinute] = array_map('intval', explode(':', $timeStart));
        $endTotalMinutes = ($startHour * 60) + $startMinute + $duration;
        if ($endTotalMinutes >= 24 * 60) {
            throw new RuntimeException('This lesson would finish after midnight. Please choose an earlier start time.');
        }
        $timeEnd = sprintf('%02d:%02d', intdiv($endTotalMinutes, 60), $endTotalMinutes % 60);
        $durationLabel = $duration . ' min';
        if ($startDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || $startDate < date('Y-m-d'))) {
            throw new RuntimeException('Please choose a start date from today onwards.');
        }
        if (mb_strlen($age) > 30 || mb_strlen($notes) > 3000) {
            throw new RuntimeException('One of your answers is too long. Please shorten it and try again.');
        }

        $insert = db()->prepare(
            "INSERT INTO course_requests
             (course_id, course_name, full_name, email, phone, student_age, lesson_duration,
              preferred_days, preferred_time_start, preferred_time_end, preferred_start_date, notes)
             VALUES
             (:course_id, :course_name, :full_name, :email, :phone, :student_age, :lesson_duration,
              :preferred_days, :time_start, :time_end, :start_date, :notes)"
        );
        $insert->execute([
            'course_id' => (int) $selectedCourse['course_id'],
            'course_name' => $selectedCourse['course_name'],
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'student_age' => $age ?: null,
            'lesson_duration' => $durationLabel,
            'preferred_days' => implode(', ', $days),
            'time_start' => $timeStart . ':00',
            'time_end' => $timeEnd . ':00',
            'start_date' => $startDate ?: null,
            'notes' => $notes ?: null,
        ]);

        $_SESSION['course_request_token'] = bin2hex(random_bytes(32));
        flash_set('course_request_success', 'Your course request has been submitted. Our team will contact you to discuss the available schedule.');
        redirect_to('course-request.php');
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'We could not save your request. Please check that the course request database migration has been installed.';
    }
}

$pageTitle = 'Course Request';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(url_path('assets/css/courses.css') . '?v=' . filemtime(__DIR__ . '/assets/css/courses.css')) ?>">
<main class="course-request-page">
    <div class="course-request-heading">
        <p class="courses-eyebrow">TAKE THE NEXT STEP</p>
        <h1>Find a lesson time that works for you.</h1>
        <p>Tell us a little about the student and your preferred schedule. Our team will review your request and contact you to confirm availability.</p>
    </div>

    <?php if ($success): ?><div class="courses-alert success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="courses-alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$dbReady): ?>
        <div class="courses-alert error">The course request form is temporarily unavailable because the database is not connected.</div>
    <?php elseif (!$courses): ?>
        <div class="courses-alert">There are no active courses available for requests at the moment.</div>
    <?php else: ?>
    <form method="post" class="course-request-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['course_request_token']) ?>">
        <div class="course-request-honeypot" aria-hidden="true"><label>Leave this field empty<input name="website" tabindex="-1" autocomplete="off"></label></div>

        <section class="course-request-section">
            <div class="course-request-section-title"><span>01</span><div><h2>Course and student</h2><p>Choose the course you are interested in.</p></div></div>
            <div class="course-request-fields">
                <label class="request-field">Course
                    <select name="course_id" id="requestCourse" required>
                        <option value="">Select a course</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= (int) $course['course_id'] ?>" <?= $courseId === (int) $course['course_id'] ? 'selected' : '' ?>><?= e($course['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="request-field">Student's name<input name="full_name" maxlength="120" required value="<?= e($old['full_name'] ?? ($user['username'] ?? '')) ?>" placeholder="Full name"></label>
                <label class="request-field">Student's age (optional)<input name="student_age" maxlength="30" value="<?= e($old['student_age'] ?? '') ?>" placeholder="e.g. 12"></label>
                <label class="request-field">Lesson duration
                    <select name="lesson_duration" id="requestDuration" required>
                        <option value="">Select a duration</option>
                        <?php foreach (course_request_duration_options($selectedCourse['duration_options'] ?? '') as $durationOption): ?>
                            <option value="<?= (int) $durationOption['minutes'] ?>" <?= (int)($old['lesson_duration'] ?? 0) === $durationOption['minutes'] ? 'selected' : '' ?>><?= e($durationOption['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </section>

        <section class="course-request-section">
            <div class="course-request-section-title"><span>02</span><div><h2>Preferred schedule</h2><p>Select all days that could work for you.</p></div></div>
            <div class="request-day-grid">
                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day): ?>
                    <label><input type="checkbox" name="preferred_days[]" value="<?= $day ?>" <?= in_array($day, (array)($old['preferred_days'] ?? []), true) ? 'checked' : '' ?>><span><?= substr($day, 0, 3) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="course-request-fields schedule-fields">
                <label class="request-field">Preferred class start time<input type="time" id="requestStartTime" name="preferred_time_start" required value="<?= e($old['preferred_time_start'] ?? '15:00') ?>"></label>
                <div class="request-field"><span>Calculated class end time</span><output id="requestEndTime" class="request-time-preview">Select a lesson duration and start time</output></div>
                <label class="request-field">Preferred start date<input type="date" name="preferred_start_date" min="<?= date('Y-m-d') ?>" value="<?= e($old['preferred_start_date'] ?? date('Y-m-d')) ?>"></label>
            </div>
        </section>

        <section class="course-request-section">
            <div class="course-request-section-title"><span>03</span><div><h2>Contact details</h2><p>How can we reach you about this request?</p></div></div>
            <div class="course-request-fields">
                <label class="request-field">Email address<input type="email" name="email" maxlength="190" required value="<?= e($old['email'] ?? ($user['email'] ?? '')) ?>" placeholder="you@example.com"></label>
                <label class="request-field">Phone number<input type="tel" name="phone" maxlength="40" required value="<?= e($old['phone'] ?? '') ?>" placeholder="+60 12-345 6789"></label>
                <label class="request-field request-notes">Additional information (optional)<textarea name="notes" rows="3" maxlength="3000" placeholder="Previous experience, special requests, or anything else we should know"><?= e($old['notes'] ?? '') ?></textarea></label>
            </div>
        </section>

        <div class="course-request-submit">
            <p>Submitting this form is a request only. Your class is not confirmed until our team contacts you.</p>
            <button type="submit" class="course-primary-btn">Submit course request</button>
        </div>
    </form>
    <?php endif; ?>
</main>

<script>
(function () {
    var courses = <?= json_encode(array_map(static fn($course) => [
        'id' => (int) $course['course_id'],
        'durations' => course_request_duration_options($course['duration_options'] ?? ''),
    ], $courses), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var courseSelect = document.getElementById('requestCourse');
    var durationSelect = document.getElementById('requestDuration');
    var startInput = document.getElementById('requestStartTime');
    var endOutput = document.getElementById('requestEndTime');

    function updateEndTime() {
        if (!durationSelect || !startInput || !endOutput) return;
        var minutes = Number(durationSelect.value);
        var match = /^(\d{2}):(\d{2})$/.exec(startInput.value);
        if (!minutes || !match) {
            endOutput.textContent = 'Select a lesson duration and start time';
            return;
        }
        var total = (Number(match[1]) * 60) + Number(match[2]) + minutes;
        if (total >= 1440) {
            endOutput.textContent = 'End time would be after midnight';
            return;
        }
        var hour = Math.floor(total / 60);
        var minute = total % 60;
        var date = new Date(2000, 0, 1, hour, minute);
        endOutput.textContent = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function updateDurations() {
        if (!courseSelect || !durationSelect) return;
        var course = courses.find(function (item) { return item.id === Number(courseSelect.value); });
        var previous = durationSelect.value;
        durationSelect.innerHTML = '<option value="">Select a duration</option>';
        (course ? course.durations : []).forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.minutes;
            option.textContent = item.label;
            durationSelect.appendChild(option);
        });
        if (course && course.durations.some(function (item) { return String(item.minutes) === previous; })) {
            durationSelect.value = previous;
        }
        updateEndTime();
    }

    if (courseSelect) courseSelect.addEventListener('change', updateDurations);
    if (durationSelect) durationSelect.addEventListener('change', updateEndTime);
    if (startInput) startInput.addEventListener('input', updateEndTime);
    updateEndTime();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
