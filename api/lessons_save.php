<?php
// Admin-only lesson create / update / delete (schedule).
// POST form fields. JSON shape: {success, data|error}.

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function lessons_error(string $message): void
{
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    http_response_code(403);
    lessons_error('ADMIN access required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    lessons_error('POST request required.');
}

if ($lessonsManager === null) {
    http_response_code(500);
    lessons_error('Lessons module is not available. Import database/psm_lessons.sql first.');
}

$action = trim($_POST['action'] ?? 'create');
$db = db();

function fetch_user_role(PDO $db, int $id): ?string
{
    $stmt = $db->prepare('SELECT role FROM users WHERE user_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : normalize_role((string) $role);
}

function validate_commission(?string $type, $value): array
{
    // Blank type always means "inherit default chain", whatever the value field holds.
    if ($type === null || trim((string) $type) === '') {
        return [null, null];
    }
    $t = strtoupper(trim((string) $type));
    if (!in_array($t, ['PERCENT', 'FIXED'], true)) {
        lessons_error('Commission type must be PERCENT or FIXED.');
    }
    if (!is_numeric($value)) {
        lessons_error('Commission value must be a number.');
    }
    $v = (float) $value;
    if ($t === 'PERCENT' && ($v < 0 || $v > 100)) {
        lessons_error('Commission percent must be 0–100.');
    }
    if ($t === 'FIXED' && $v < 0) {
        lessons_error('Commission amount cannot be negative.');
    }
    return [$t, round($v, 2)];
}

try {
    if ($action === 'delete') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            lessons_error('Invalid lesson.');
        }
        // Read class identity and enrolled students before deleting the lesson.
        $metaStmt = $db->prepare('SELECT lesson_date, recurrence_group FROM lessons WHERE lesson_id = :id');
        $metaStmt->execute(['id' => $lessonId]);
        $lessonMeta = $metaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lessonMeta) {
            lessons_error('Lesson not found.');
        }
        $lessonMonth = substr((string) $lessonMeta['lesson_date'], 0, 7);
        $seriesGroup = trim((string) ($lessonMeta['recurrence_group'] ?? ''));
        $studentStmt = $db->prepare('SELECT student_id FROM lesson_enrollments WHERE lesson_id = :id');
        $studentStmt->execute(['id' => $lessonId]);
        $studentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        // Remove physical proof files scoped to uploads/proofs before cascade delete.
        $proofDir = realpath(__DIR__ . '/../uploads/proofs');
        $paths = [];
        $stmt = $db->prepare('SELECT proof_path FROM lesson_enrollments WHERE lesson_id = :id');
        $stmt->execute(['id' => $lessonId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (is_string($p) && $p !== '') {
                $paths[] = $p;
            }
        }
        $stmt = $db->prepare('SELECT commission_proof_path FROM lessons WHERE lesson_id = :id');
        $stmt->execute(['id' => $lessonId]);
        $cp = $stmt->fetchColumn();
        if (is_string($cp) && $cp !== '') {
            $paths[] = $cp;
        }
        $stmt = $db->prepare('DELETE FROM lessons WHERE lesson_id = :id');
        $stmt->execute(['id' => $lessonId]);

        // Keep billing and schedule aligned by month: remove this month's
        // series transaction when its last occurrence is deleted. If the entire
        // series is gone, remove any remaining transactions for that series too.
        if ($seriesGroup !== '') {
            $sameMonthStmt = $db->prepare(
                "SELECT COUNT(*) FROM lessons
                 WHERE recurrence_group = :g AND DATE_FORMAT(lesson_date, '%Y-%m') = :month"
            );
            $sameMonthStmt->execute(['g' => $seriesGroup, 'month' => $lessonMonth]);
            if ((int) $sameMonthStmt->fetchColumn() === 0) {
                $deleteMonthFees = $db->prepare('DELETE FROM monthly_fees WHERE recurrence_group = :g AND month = :month');
                $deleteMonthFees->execute(['g' => $seriesGroup, 'month' => $lessonMonth]);
            }
            $remainingStmt = $db->prepare('SELECT COUNT(*) FROM lessons WHERE recurrence_group = :g');
            $remainingStmt->execute(['g' => $seriesGroup]);
            if ((int) $remainingStmt->fetchColumn() === 0) {
                $deleteFees = $db->prepare('DELETE FROM monthly_fees WHERE recurrence_group = :g');
                $deleteFees->execute(['g' => $seriesGroup]);
            }
        } else {
            // Standalone class: remove its ungrouped fee only if the student has
            // no other lesson that month (avoids deleting a shared monthly charge).
            $otherStmt = $db->prepare(
                "SELECT COUNT(*) FROM lessons l
                 INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
                 WHERE e.student_id = :sid AND DATE_FORMAT(l.lesson_date, '%Y-%m') = :month"
            );
            $deleteFee = $db->prepare(
                "DELETE FROM monthly_fees
                 WHERE student_id = :sid AND month = :month
                   AND (recurrence_group IS NULL OR recurrence_group = '')"
            );
            foreach ($studentIds as $sid) {
                $otherStmt->execute(['sid' => $sid, 'month' => $lessonMonth]);
                if ((int) $otherStmt->fetchColumn() === 0) {
                    $deleteFee->execute(['sid' => $sid, 'month' => $lessonMonth]);
                }
            }
        }

        if ($proofDir !== false) {
            foreach ($paths as $p) {
                $full = realpath(__DIR__ . '/../' . $p);
                if ($full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
                    @unlink($full);
                }
            }
        }
        echo json_encode(['success' => true, 'data' => ['deleted' => $lessonId]]);
        exit;
    }

    // Issue 50: delete every lesson sharing one repeat series.
    if ($action === 'delete_group') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            lessons_error('Invalid lesson.');
        }
        $lesson = $lessonsManager->getLesson($lessonId);
        if ($lesson === null) {
            lessons_error('Lesson not found.');
        }
        $group = $lesson['recurrence_group'] ?? null;
        if ($group === null || $group === '') {
            lessons_error('This lesson is not part of a repeat series.');
        }
        $stmt = $db->prepare('SELECT lesson_id FROM lessons WHERE recurrence_group = :g');
        $stmt->execute(['g' => $group]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (empty($ids)) {
            lessons_error('Repeat series is empty.');
        }
        $proofDir = realpath(__DIR__ . '/../uploads/proofs');
        $paths = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT proof_path FROM lesson_enrollments WHERE lesson_id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (is_string($p) && $p !== '') {
                $paths[] = $p;
            }
        }
        $stmt = $db->prepare("SELECT commission_proof_path FROM lessons WHERE lesson_id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (is_string($p) && $p !== '') {
                $paths[] = $p;
            }
        }
        $stmt = $db->prepare('DELETE FROM lessons WHERE recurrence_group = :g');
        $stmt->execute(['g' => $group]);

        // Remove monthly billing transactions associated with the deleted class.
        $monthlyStmt = $db->prepare('DELETE FROM monthly_fees WHERE recurrence_group = :g');
        $monthlyStmt->execute(['g' => $group]);

        if ($proofDir !== false) {
            foreach ($paths as $p) {
                $full = realpath(__DIR__ . '/../' . $p);
                if ($full !== false && strpos($full, $proofDir) === 0 && is_file($full)) {
                    @unlink($full);
                }
            }
        }
        echo json_encode(['success' => true, 'data' => ['deleted' => $ids]]);
        exit;
    }

    if ($action === 'update') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            lessons_error('Invalid lesson.');
        }
        $existing = $lessonsManager->getLesson($lessonId);
        if ($existing === null) {
            lessons_error('Lesson not found.');
        }
        // Issue 65: empty teacher_id clears the assignment.
        $teacherRaw = array_key_exists('teacher_id', $_POST) ? trim((string) $_POST['teacher_id']) : null;
        if ($teacherRaw === null) {
            $teacherId = $existing['teacher_id'] !== null ? (int) $existing['teacher_id'] : null;
        } else {
            $teacherId = $teacherRaw === '' ? null : (int) $teacherRaw;
        }
        if ($teacherId !== null && ($teacherId <= 0 || fetch_user_role($db, $teacherId) !== 'TEACHER')) {
            lessons_error('Choose a valid teacher, or leave it unassigned.');
        }
        $date = trim($_POST['lesson_date'] ?? $existing['lesson_date']);
        $start = trim($_POST['start_time'] ?? $existing['start_time']);
        $end = trim($_POST['end_time'] ?? $existing['end_time']);
        [$ok, $err] = $lessonsManager->checkSlot($date, $start, $end);
        if (!$ok) {
            lessons_error($err);
        }

        // When an edited occurrence belongs to a class series, changing its
        // weekday moves every occurrence by the same day offset. Other fields
        // in this form continue to update the selected occurrence only.
        $seriesGroup = trim((string) ($existing['recurrence_group'] ?? ''));
        $existingDate = substr((string) $existing['lesson_date'], 0, 10);
        $dateShift = (int) round((strtotime($date) - strtotime($existingDate)) / 86400);
        if ($seriesGroup !== '' && $dateShift !== 0) {
            $groupStmt = $db->prepare(
                'SELECT lesson_id, lesson_date, start_time, end_time FROM lessons WHERE recurrence_group = :g'
            );
            $groupStmt->execute(['g' => $seriesGroup]);
            $groupRows = $groupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $dateUpdates = [];
            foreach ($groupRows as $groupRow) {
                $groupId = (int) $groupRow['lesson_id'];
                $oldGroupDate = substr((string) $groupRow['lesson_date'], 0, 10);
                $newGroupDate = date('Y-m-d', strtotime($oldGroupDate . ' ' . ($dateShift > 0 ? '+' : '') . $dateShift . ' days'));
                $groupStart = $groupId === $lessonId ? $start : substr((string) $groupRow['start_time'], 0, 5);
                $groupEnd = $groupId === $lessonId ? $end : substr((string) $groupRow['end_time'], 0, 5);
                [$groupSlotOk, $groupSlotError] = $lessonsManager->checkSlot($newGroupDate, $groupStart, $groupEnd);
                if (!$groupSlotOk) {
                    lessons_error('Cannot move the whole class: ' . $groupSlotError);
                }
                $dateUpdates[] = [$groupId, $newGroupDate];
            }
            $db->beginTransaction();
            try {
                $dateStmt = $db->prepare('UPDATE lessons SET lesson_date = :d WHERE lesson_id = :id');
                foreach ($dateUpdates as [$groupId, $newGroupDate]) {
                    $dateStmt->execute(['d' => $newGroupDate, 'id' => $groupId]);
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        $status = strtoupper(trim($_POST['status'] ?? $existing['status']));
        if (!in_array($status, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
            lessons_error('Invalid status.');
        }
        if ($status === 'COMPLETED') {
            // Issue 37: COMPLETED only when the lesson time is over (auto-flip handles
            // past lessons; this guards the manual path). CANCELLED stays always allowed.
            $endTs = strtotime($date . ' ' . $end);
            if ($endTs === false || $endTs > time()) {
                lessons_error('A lesson can only be marked COMPLETED after its end time. Use CANCELLED to void it.');
            }
        }
        [$ctype, $cvalue] = validate_commission(
            array_key_exists('commission_type', $_POST) ? $_POST['commission_type'] : $existing['commission_type'],
            array_key_exists('commission_value', $_POST) ? $_POST['commission_value'] : $existing['commission_value']
        );
        $courseRaw = trim((string) ($_POST['course_id'] ?? ($existing['course_id'] ?? '')));
        $courseId = $courseRaw === '' ? 0 : (int) $courseRaw;
        if ($courseId <= 0) {
            lessons_error('Choose a configured course. Create a course in Courses first if it is missing.');
        }
        $courseStmt = $db->prepare('SELECT course_name, is_active FROM courses WHERE course_id = :id LIMIT 1');
        $courseStmt->execute(['id' => $courseId]);
        $courseRow = $courseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$courseRow || (int) $courseRow['is_active'] !== 1) {
            lessons_error('Choose an active configured course.');
        }
        $stmt = $db->prepare(
            'UPDATE lessons SET teacher_id = :teacher_id, title = :title, course_id = :course_id, lesson_date = :lesson_date,
              start_time = :start_time, end_time = :end_time, status = :status,
              commission_type = :ctype, commission_value = :cvalue WHERE lesson_id = :id'
        );
        $stmt->execute([
            'teacher_id' => $teacherId,
            'title' => (string) $courseRow['course_name'],
            'course_id' => $courseId,
            'lesson_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
            'ctype' => $ctype,
            'cvalue' => $cvalue,
            'id' => $lessonId,
        ]);
        echo json_encode(['success' => true, 'data' => ['lesson_id' => $lessonId]]);
        exit;
    }

    // Default: create (single + optional recurrence clones on extra dates).
    // Issue 65: teacher may be left blank (unassigned) temporarily.
    $teacherRaw = trim($_POST['teacher_id'] ?? '');
    $teacherId = $teacherRaw === '' ? null : (int) $teacherRaw;
    if ($teacherId !== null && ($teacherId <= 0 || fetch_user_role($db, $teacherId) !== 'TEACHER')) {
        lessons_error('Choose a valid teacher, or leave it unassigned.');
    }
    $date = trim($_POST['lesson_date'] ?? '');
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    if ($date === '' || $start === '' || $end === '') {
        lessons_error('Date, start and end time are required.');
    }
    [$ctype, $cvalue] = validate_commission(
        $_POST['commission_type'] ?? null,
        $_POST['commission_value'] ?? null
    );

    // Issue 90: every class repeats weekly — no one-offs, no biweekly/monthly.
    // Without an explicit until-month (repeat dialog), new classes run to the
    // end of the start month; the admin copies the month forward from there.
    $extraDates = [];
    $repeatUntil = trim($_POST['repeat_until'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $repeatUntil) !== 1) {
        $repeatUntil = substr($date, 0, 7);
    }
    $skipped = [];
    $addExtraDate = function (string $d) use (&$extraDates, &$skipped, $date, $start, $end, $lessonsManager): void {
        if ($d === $date || in_array($d, $extraDates, true)) {
            return;
        }
        [$rok] = $lessonsManager->checkSlot($d, $start, $end);
        if (!$rok) {
            $skipped[] = $d;
            return;
        }
        $extraDates[] = $d;
    };
    if ($repeatUntil < substr($date, 0, 7)) {
        lessons_error('Repeat-until month must not be before the lesson month.');
    }
    $untilEnd = date('Y-m-t', strtotime($repeatUntil . '-01'));
    $cursor = $date;
    while (count($extraDates) < 24) {
        $cursor = date('Y-m-d', strtotime($cursor . ' +7 days'));
        if ($cursor > $untilEnd) {
            break;
        }
        $addExtraDate($cursor);
    }

    // Students + per-student fees.
    $studentIds = $_POST['student_ids'] ?? [];
    if (!is_array($studentIds)) {
        $studentIds = [$studentIds];
    }
    $fees = $_POST['fees'] ?? [];
    if (!is_array($fees)) {
        $fees = [];
    }
    $cleanStudents = [];
    foreach ($studentIds as $sid) {
        $sid = (int) $sid;
        if ($sid <= 0 || isset($cleanStudents[$sid])) {
            continue;
        }
        if (fetch_user_role($db, $sid) !== 'STUDENT') {
            lessons_error('Student ID ' . $sid . ' is not a valid student.');
        }
        $fee = 0.0;
        if (isset($fees[$sid]) || isset($fees[(string) $sid])) {
            $feeRaw = $fees[$sid] ?? $fees[(string) $sid];
            if (!is_numeric($feeRaw) || (float) $feeRaw < 0) {
                lessons_error('Fee for student ' . $sid . ' must be 0 or more.');
            }
            $fee = round((float) $feeRaw, 2);
        }
        $cleanStudents[$sid] = $fee;
    }

    $courseRaw = trim((string) ($_POST['course_id'] ?? ''));
    $courseId = $courseRaw === '' ? 0 : (int) $courseRaw;
    if ($courseId <= 0) {
        lessons_error('Choose a configured course. Create a course in Courses before scheduling a class.');
    }
    $courseStmt = $db->prepare('SELECT course_name, minimum_fee, duration_options, is_active FROM courses WHERE course_id = :id LIMIT 1');
    $courseStmt->execute(['id' => $courseId]);
    $courseRow = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$courseRow || (int) $courseRow['is_active'] !== 1) lessons_error('Choose an active configured course.');
    $minimumFee = (float) $courseRow['minimum_fee'];
    // Course configuration is the sole source of the class title.
    $title = (string) $courseRow['course_name'];

    // Enforce the first listed duration as the course minimum (e.g. "30 / 45 min" => 30).
    // If the submitted end time is too early, extend it from the selected start time.
    $minimumDuration = 0;
    if ($courseId !== null && !empty($courseRow['duration_options'])
        && preg_match('/\\d+/', (string)$courseRow['duration_options'], $durationMatch)) {
        $minimumDuration = (int)$durationMatch[0];
    }
    if ($minimumDuration > 0
        && preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', substr($start, 0, 5), $startParts)) {
        $startMinutes = ((int)$startParts[1] * 60) + (int)$startParts[2];
        $endMinutes = -1;
        if (preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)$/', substr($end, 0, 5), $endParts)) {
            $endMinutes = ((int)$endParts[1] * 60) + (int)$endParts[2];
        }
        if ($endMinutes - $startMinutes < $minimumDuration) {
            $adjustedEnd = $startMinutes + $minimumDuration;
            $end = sprintf('%02d:%02d', intdiv($adjustedEnd, 60), $adjustedEnd % 60);
        }
    }
    [$ok, $err] = $lessonsManager->checkSlot($date, $start, $end);
    if (!$ok) {
        lessons_error($err);
    }

    foreach ($cleanStudents as $sid => $fee) {
        if ($courseId !== null && $minimumFee > 0 && $fee < $minimumFee) {
            lessons_error('The fee for each student in this course must be at least RM ' . number_format($minimumFee, 2) . '.');
        }
    }
    // Issue 66: repeat-from-existing clones only the NEW dates and joins the
    // original series (starting one and stamping the original when needed).
    $skipBase = !empty($_POST['skip_base']);
    $joinGroup = trim($_POST['join_group'] ?? '');
    $baseLessonId = (int) ($_POST['base_lesson_id'] ?? 0);
    if ($skipBase) {
        if (empty($extraDates)) {
            lessons_error('No new lesson dates in the selected range.');
        }
        if ($joinGroup === '') {
            if ($baseLessonId <= 0 || $lessonsManager->getLesson($baseLessonId) === null) {
                lessons_error('Original lesson not found.');
            }
            $joinGroup = bin2hex(random_bytes(8));
            $stmt = $db->prepare("UPDATE lessons SET recurrence_group = :g WHERE lesson_id = :id AND (recurrence_group IS NULL OR recurrence_group = '')");
            $stmt->execute(['g' => $joinGroup, 'id' => $baseLessonId]);
        }
        $group = $joinGroup;
        $allDates = $extraDates;
    } else {
        $group = count($extraDates) > 0 ? bin2hex(random_bytes(8)) : null;
        $allDates = array_merge([$date], $extraDates);
    }

    $db->beginTransaction();
    $hasMonthlyFees = false;
    try {
        $hasMonthlyFees = $db->query("SHOW TABLES LIKE 'monthly_fees'")->fetchColumn() !== false;
    } catch (Throwable $e) {
        $hasMonthlyFees = false;
    }
    $created = [];
    foreach ($allDates as $d) {
        $lessonId = $lessonsManager->createLesson([
            'teacher_id' => $teacherId,
            'title' => $title,
            'lesson_date' => $d,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'SCHEDULED',
            'recurrence_group' => $group,
            'commission_type' => $ctype,
            'commission_value' => $cvalue,
            'created_by' => (int) $user['user_id'],
        ]);
        if ($courseId !== null) {
            $courseLinkStmt = $db->prepare('UPDATE lessons SET course_id = :course_id WHERE lesson_id = :lesson_id');
            $courseLinkStmt->execute(['course_id' => $courseId, 'lesson_id' => $lessonId]);
        }
        foreach ($cleanStudents as $sid => $fee) {
            $lessonsManager->addEnrollment($lessonId, $sid, $fee);
            // Keep the course roster in sync with students scheduled into this course.
            if ($courseId !== null) {
                $rosterStmt = $db->prepare('INSERT IGNORE INTO course_students (course_id, student_id) VALUES (:course_id, :student_id)');
                $rosterStmt->execute(['course_id' => $courseId, 'student_id' => $sid]);
            }
        }
        // Monthly billing record per student per class (keep existing records untouched).
        if ($hasMonthlyFees) {
            $mstmt = $db->prepare(
                'INSERT IGNORE INTO monthly_fees (student_id, month, recurrence_group, amount, status)
                 VALUES (:sid, :month, :grp, :amount, \'UNPAID\')'
            );
            foreach ($cleanStudents as $sid => $fee) {
                $mstmt->execute(['sid' => $sid, 'month' => substr($d, 0, 7), 'grp' => (string) ($group ?? ''), 'amount' => $fee]);
            }
        }
        $created[] = $lessonId;
    }
    $db->commit();

    echo json_encode(['success' => true, 'data' => ['lesson_ids' => $created, 'skipped' => $skipped]]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    lessons_error('Unable to save lesson.');
}
