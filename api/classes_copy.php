<?php
// Copy class schedules from one month to another without copying billing,
// transaction, payment-status, or proof records.
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function copy_classes_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || normalize_role($user['role'] ?? '') !== 'ADMIN') {
    copy_classes_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    copy_classes_error('POST request required.', 405);
}
if ($lessonsManager === null || !is_database_connected()) {
    copy_classes_error('The schedule database is not available.', 500);
}

$sourceMonth = trim((string) ($_POST['source_month'] ?? ''));
$targetMonth = trim((string) ($_POST['target_month'] ?? ''));
$validMonth = static function (string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
        return false;
    }
    $year = (int) $m[1];
    $month = (int) $m[2];
    return $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12;
};
if (!$validMonth($sourceMonth) || !$validMonth($targetMonth)) {
    copy_classes_error('Choose valid source and destination months.');
}
if ($sourceMonth === $targetMonth) {
    copy_classes_error('The source and destination months must be different.');
}

$db = db();
$sourceStart = $sourceMonth . '-01';
$sourceEnd = date('Y-m-t', strtotime($sourceStart));
$targetStart = $targetMonth . '-01';
$targetEnd = date('Y-m-t', strtotime($targetStart));

try {
    // Copy only grouped classes (the class table represents recurrence groups).
    $stmt = $db->prepare(
        "SELECT l.* FROM lessons l
         WHERE l.recurrence_group IS NOT NULL AND l.recurrence_group <> ''
           AND l.lesson_date BETWEEN :from_date AND :to_date
         ORDER BY l.recurrence_group, l.lesson_date, l.start_time"
    );
    $stmt->execute(['from_date' => $sourceStart, 'to_date' => $sourceEnd]);
    $sourceLessons = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($sourceLessons === []) {
        copy_classes_error('No recurring classes were found in the source month.');
    }

    $groups = [];
    foreach ($sourceLessons as $lesson) {
        $group = (string) $lesson['recurrence_group'];
        $weekday = (int) date('N', strtotime((string) $lesson['lesson_date']));
        $start = substr((string) $lesson['start_time'], 0, 5);
        $end = substr((string) $lesson['end_time'], 0, 5);
        $patternKey = $weekday . '|' . $start . '|' . $end;
        if (!isset($groups[$group])) {
            $groups[$group] = ['patterns' => [], 'students' => []];
        }
        if (!isset($groups[$group]['patterns'][$patternKey])) {
            $groups[$group]['patterns'][$patternKey] = [
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'teacher_id' => $lesson['teacher_id'] !== null ? (int) $lesson['teacher_id'] : null,
                'title' => $lesson['title'],
                'course_id' => isset($lesson['course_id']) && $lesson['course_id'] !== null ? (int) $lesson['course_id'] : null,
                'commission_type' => $lesson['commission_type'] ?? null,
                'commission_value' => $lesson['commission_value'] ?? null,
                'created_by' => (int) $user['user_id'],
            ];
        }
    }

    // Collect the class roster and its fee amounts from the source month.
    $rosterStmt = $db->prepare(
        "SELECT l.recurrence_group, e.student_id, MAX(e.fee_amount) AS fee_amount
         FROM lessons l
         INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
         WHERE l.recurrence_group = :group_id
           AND l.lesson_date BETWEEN :from_date AND :to_date
         GROUP BY l.recurrence_group, e.student_id"
    );
    foreach (array_keys($groups) as $group) {
        $rosterStmt->execute([
            'group_id' => $group,
            'from_date' => $sourceStart,
            'to_date' => $sourceEnd,
        ]);
        foreach ($rosterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $student) {
            $groups[$group]['students'][(int) $student['student_id']] = round((float) $student['fee_amount'], 2);
        }
    }

    // Find target-month dates already present so rerunning a copy is safe.
    $existingStmt = $db->prepare(
        'SELECT lesson_date, start_time, end_time FROM lessons
         WHERE recurrence_group = :group_id AND lesson_date BETWEEN :from_date AND :to_date'
    );
    $created = 0;
    $skipped = 0;
    $db->beginTransaction();
    foreach ($groups as $groupId => $groupData) {
        $existingStmt->execute([
            'group_id' => $groupId,
            'from_date' => $targetStart,
            'to_date' => $targetEnd,
        ]);
        $existing = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $existing[substr((string) $row['lesson_date'], 0, 10) . '|' . substr((string) $row['start_time'], 0, 5) . '|' . substr((string) $row['end_time'], 0, 5)] = true;
        }

        for ($day = 1; $day <= (int) date('t', strtotime($targetStart)); $day++) {
            $date = sprintf('%s-%02d', $targetMonth, $day);
            $weekday = (int) date('N', strtotime($date));
            foreach ($groupData['patterns'] as $pattern) {
                if ($pattern['weekday'] !== $weekday) {
                    continue;
                }
                $key = $date . '|' . $pattern['start_time'] . '|' . $pattern['end_time'];
                if (isset($existing[$key])) {
                    $skipped++;
                    continue;
                }
                [$ok, $error] = $lessonsManager->checkSlot($date, $pattern['start_time'], $pattern['end_time']);
                if (!$ok) {
                    $skipped++;
                    continue;
                }
                $lessonId = $lessonsManager->createLesson([
                    'teacher_id' => $pattern['teacher_id'],
                    'title' => $pattern['title'],
                    'lesson_date' => $date,
                    'start_time' => $pattern['start_time'],
                    'end_time' => $pattern['end_time'],
                    'status' => 'SCHEDULED',
                    'recurrence_group' => $groupId,
                    'commission_type' => $pattern['commission_type'],
                    'commission_value' => $pattern['commission_value'],
                    'created_by' => $pattern['created_by'],
                ]);
                if ($pattern['course_id'] !== null) {
                    $courseLink = $db->prepare('UPDATE lessons SET course_id = :course_id WHERE lesson_id = :lesson_id');
                    $courseLink->execute(['course_id' => $pattern['course_id'], 'lesson_id' => $lessonId]);
                }
                foreach ($groupData['students'] as $studentId => $fee) {
                    $lessonsManager->addEnrollment($lessonId, (int) $studentId, (float) $fee);
                }
                $existing[$key] = true;
                $created++;
            }
        }
    }
    $db->commit();

    echo json_encode(['success' => true, 'data' => ['created' => $created, 'skipped' => $skipped]]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    copy_classes_error('Unable to copy class schedules.');
}
