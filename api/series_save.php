<?php
// Admin-only whole-series edits (class table on schedule.php).
// POST: action + group (recurrence_group hash). JSON: {success, data|error}.
//
// Actions: rename_series | assign_teacher | set_commission | add_student |
//          remove_student | set_student_fee | get_teachers | get_students.
// Series pay settings are never silently changed: assigning a teacher keeps
// existing per-lesson commissions; student fees apply uniformly across the
// series when edited here (per-lesson differences remain editable in the
// lesson detail panel).

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

function series_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$user = current_user();
if (!$user || $user['role'] !== 'ADMIN') {
    series_error('ADMIN access required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    series_error('POST request required.', 405);
}
if ($lessonsManager === null) {
    series_error('Lessons module is not available. Import database/psm_lessons.sql first.', 500);
}
if (!is_database_connected()) {
    series_error('Database is not connected.', 500);
}

function series_user_role(PDO $db, int $id): ?string
{
    $stmt = $db->prepare('SELECT role FROM users WHERE user_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : normalize_role((string) $role);
}

function series_validate_commission(?string $type, $value): array
{
    if ($type === null || trim((string) $type) === '') {
        return [null, null];
    }
    $t = strtoupper(trim((string) $type));
    if (!in_array($t, ['PERCENT', 'FIXED'], true)) {
        series_error('Commission type must be PERCENT or FIXED.');
    }
    if (!is_numeric($value)) {
        series_error('Commission value must be a number.');
    }
    $v = (float) $value;
    if ($t === 'PERCENT' && ($v < 0 || $v > 100)) {
        series_error('Commission percent must be 0–100.');
    }
    if ($t === 'FIXED' && $v < 0) {
        series_error('Commission amount cannot be negative.');
    }
    return [$t, round($v, 2)];
}

function series_comm_label($type, $value): string
{
    $t = strtoupper(trim((string) $type));
    if ($t === 'PERCENT' && is_numeric($value)) {
        $v = (string) (float) $value;
        if (strpos($v, '.') !== false) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        return $v . '%';
    }
    if ($t === 'FIXED' && is_numeric($value)) {
        return 'RM ' . number_format((float) $value, 2);
    }
    return 'Default';
}

/** Lesson ids of a series; errors when the group does not exist. */
function series_lesson_ids(PDO $db, string $group): array
{
    $stmt = $db->prepare('SELECT lesson_id FROM lessons WHERE recurrence_group = :g ORDER BY lesson_date ASC');
    $stmt->execute(['g' => $group]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids === []) {
        series_error('Series not found.');
    }
    return array_map('intval', $ids);
}

$action = trim($_POST['action'] ?? '');
$db = db();

try {
    // ---------- read-only pickers ----------
    if ($action === 'get_teachers') {
        $stmt = $db->query("SELECT user_id, username, email FROM users WHERE role = 'TEACHER' ORDER BY username ASC");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tid = (int) $t['user_id'];
            [$ct, $cv] = $lessonsManager->resolveCommission($tid, null, null);
            $out[] = [
                'id' => $tid,
                'username' => $t['username'],
                'email' => $t['email'],
                'commission' => series_comm_label($ct, $cv),
                'availability' => $lessonsManager->getTeacherAvailability($tid),
            ];
        }
        echo json_encode(['success' => true, 'data' => ['teachers' => $out]]);
        exit;
    }

    if ($action === 'get_students') {
        $group = trim($_POST['group'] ?? '');
        $stmt = $db->query("SELECT user_id, username, email FROM users WHERE role = 'STUDENT' ORDER BY username ASC");
        $enrolled = [];
        $feeMode = [];
        if ($group !== '') {
            $stm = $db->prepare(
                'SELECT e.student_id, e.fee_amount FROM lesson_enrollments e
                 INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                 WHERE l.recurrence_group = :g'
            );
            $stm->execute(['g' => $group]);
            foreach ($stm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sid = (int) $row['student_id'];
                $enrolled[$sid] = true;
                $f = number_format((float) ($row['fee_amount'] ?? 0), 2, '.', '');
                $feeMode[$sid][$f] = ($feeMode[$sid][$f] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $sid = (int) $s['user_id'];
            $fee = null;
            if (isset($feeMode[$sid])) {
                arsort($feeMode[$sid]);
                $fee = (string) array_key_first($feeMode[$sid]);
            }
            $out[] = [
                'id' => $sid,
                'username' => $s['username'],
                'email' => $s['email'],
                'enrolled' => isset($enrolled[$sid]),
                'fee' => $fee,
            ];
        }
        echo json_encode(['success' => true, 'data' => ['students' => $out]]);
        exit;
    }

    // ---------- mutations need a real series ----------
    $group = trim($_POST['group'] ?? '');
    if ($group === '') {
        series_error('Series is required.');
    }
    $lessonIds = series_lesson_ids($db, $group);
    $inList = implode(',', array_fill(0, count($lessonIds), '?'));

    // Move every occurrence in a class to the chosen weekday, preserving its
    // week and time. This keeps the series aligned instead of editing one row.
    if ($action === 'set_weekday') {
        $targetDow = (int) ($_POST['weekday'] ?? 0); // 1=Mon ... 6=Sat
        if ($targetDow < 1 || $targetDow > 6) {
            series_error('Choose a weekday from Monday to Saturday.');
        }
        $stmt = $db->prepare(
            "SELECT lesson_id, lesson_date, start_time, end_time
             FROM lessons WHERE recurrence_group = :g ORDER BY lesson_date ASC"
        );
        $stmt->execute(['g' => $group]);
        $occurrences = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $updates = [];
        foreach ($occurrences as $occurrence) {
            $oldDate = substr((string) $occurrence['lesson_date'], 0, 10);
            $oldDow = (int) date('N', strtotime($oldDate . 'T12:00:00'));
            $newDate = date('Y-m-d', strtotime($oldDate . ' ' . ($targetDow - $oldDow >= 0 ? '+' : '') . ($targetDow - $oldDow) . ' days'));
            [$slotOk, $slotError] = $lessonsManager->checkSlot(
                $newDate,
                substr((string) $occurrence['start_time'], 0, 5),
                substr((string) $occurrence['end_time'], 0, 5)
            );
            if (!$slotOk) {
                series_error('Cannot move the class to that weekday: ' . $slotError);
            }
            $updates[] = [(int) $occurrence['lesson_id'], $newDate];
        }
        $db->beginTransaction();
        try {
            $updateDate = $db->prepare('UPDATE lessons SET lesson_date = :d WHERE lesson_id = :id');
            foreach ($updates as [$id, $newDate]) {
                $updateDate->execute(['d' => $newDate, 'id' => $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        echo json_encode(['success' => true, 'data' => ['updated' => count($updates), 'weekday' => $targetDow]]);
        exit;
    }

    if ($action === 'rename_series') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '' || mb_strlen($title) > 200) {
            series_error('Title must be 1–200 characters.');
        }
        $stmt = $db->prepare('UPDATE lessons SET title = :t WHERE recurrence_group = :g');
        $stmt->execute(['t' => $title, 'g' => $group]);
        echo json_encode(['success' => true, 'data' => ['updated' => $stmt->rowCount()]]);
        exit;
    }

    if ($action === 'assign_teacher') {
        $raw = trim($_POST['teacher_id'] ?? '');
        if ($raw === '' || $raw === '0') {
            $stmt = $db->prepare('UPDATE lessons SET teacher_id = NULL WHERE recurrence_group = :g');
            $stmt->execute(['g' => $group]);
        } else {
            $tid = (int) $raw;
            if ($tid <= 0 || series_user_role($db, $tid) !== 'TEACHER') {
                series_error('Choose a valid teacher, or unassign.');
            }
            $stmt = $db->prepare('UPDATE lessons SET teacher_id = :t WHERE recurrence_group = :g');
            $stmt->execute(['t' => $tid, 'g' => $group]);
        }
        echo json_encode(['success' => true, 'data' => ['updated' => $stmt->rowCount()]]);
        exit;
    }

    if ($action === 'set_commission') {
        [$ctype, $cvalue] = series_validate_commission(
            array_key_exists('commission_type', $_POST) ? $_POST['commission_type'] : null,
            $_POST['commission_value'] ?? null
        );
        $stmt = $db->prepare('UPDATE lessons SET commission_type = :t, commission_value = :v WHERE recurrence_group = :g');
        $stmt->execute(['t' => $ctype, 'v' => $cvalue, 'g' => $group]);
        echo json_encode(['success' => true, 'data' => ['updated' => $stmt->rowCount()]]);
        exit;
    }

    if ($action === 'add_student' || $action === 'set_student_fee') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        if ($sid <= 0 || series_user_role($db, $sid) !== 'STUDENT') {
            series_error('Choose a valid student.');
        }
        $feeRaw = $_POST['fee'] ?? 0;
        if (!is_numeric($feeRaw) || (float) $feeRaw < 0) {
            series_error('Fee must be 0 or more.');
        }
        $fee = round((float) $feeRaw, 2);
        if ($action === 'add_student') {
            foreach ($lessonIds as $lid) {
                $lessonsManager->addEnrollment($lid, $sid, $fee);
            }
        } else {
            $stmt = $db->prepare("UPDATE lesson_enrollments SET fee_amount = :f WHERE student_id = :s AND lesson_id IN ($inList)");
            $stmt->execute(array_merge(['f' => $fee, 's' => $sid], $lessonIds));
            if ($stmt->rowCount() === 0) {
                series_error('That student is not enrolled in this series.');
            }
        }
        echo json_encode(['success' => true, 'data' => ['lessons' => count($lessonIds)]]);
        exit;
    }

    if ($action === 'remove_student') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        if ($sid <= 0) {
            series_error('Choose a valid student.');
        }
        $stmt = $db->prepare("DELETE FROM lesson_enrollments WHERE student_id = :s AND lesson_id IN ($inList)");
        $stmt->execute(array_merge(['s' => $sid], $lessonIds));
        echo json_encode(['success' => true, 'data' => ['removed' => $stmt->rowCount()]]);
        exit;
    }

    series_error('Unknown action.', 400);
} catch (Throwable $e) {
    series_error('Unable to save series change.', 500);
}
