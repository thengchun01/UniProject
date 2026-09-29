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
        $teacherId = (int) ($_POST['teacher_id'] ?? $existing['teacher_id']);
        $role = $teacherId > 0 ? fetch_user_role($db, $teacherId) : null;
        if ($teacherId <= 0 || $role !== 'TEACHER') {
            lessons_error('Choose a valid teacher.');
        }
        $date = trim($_POST['lesson_date'] ?? $existing['lesson_date']);
        $start = trim($_POST['start_time'] ?? $existing['start_time']);
        $end = trim($_POST['end_time'] ?? $existing['end_time']);
        [$ok, $err] = $lessonsManager->checkSlot($date, $start, $end);
        if (!$ok) {
            lessons_error($err);
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
        $stmt = $db->prepare(
            'UPDATE lessons SET teacher_id = :teacher_id, title = :title, lesson_date = :lesson_date,
              start_time = :start_time, end_time = :end_time, status = :status,
              commission_type = :ctype, commission_value = :cvalue WHERE lesson_id = :id'
        );
        $stmt->execute([
            'teacher_id' => $teacherId,
            'title' => trim($_POST['title'] ?? ($existing['title'] ?? '')) ?: null,
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
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    if ($teacherId <= 0 || fetch_user_role($db, $teacherId) !== 'TEACHER') {
        lessons_error('Choose a valid teacher.');
    }
    $date = trim($_POST['lesson_date'] ?? '');
    $start = trim($_POST['start_time'] ?? '');
    $end = trim($_POST['end_time'] ?? '');
    if ($date === '' || $start === '' || $end === '') {
        lessons_error('Date, start and end time are required.');
    }
    [$ok, $err] = $lessonsManager->checkSlot($date, $start, $end);
    if (!$ok) {
        lessons_error($err);
    }
    [$ctype, $cvalue] = validate_commission(
        $_POST['commission_type'] ?? null,
        $_POST['commission_value'] ?? null
    );

    // Extra recurrence dates (Y-m-d list). Each must also pass slot rules.
    $extraDates = [];
    if (isset($_POST['recurrence_dates'])) {
        $raw = $_POST['recurrence_dates'];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (is_array($raw)) {
            foreach ($raw as $d) {
                $d = trim((string) $d);
                if ($d === '' || $d === $date) {
                    continue;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    lessons_error('Invalid recurrence date: ' . $d);
                }
                [$rok, $rerr] = $lessonsManager->checkSlot($d, $start, $end);
                if (!$rok) {
                    lessons_error($d . ': ' . $rerr);
                }
                $extraDates[] = $d;
            }
            $extraDates = array_values(array_unique($extraDates));
        }
    }

    // Issues 35/51: repeat modes (once | weekly | every 2 weeks | monthly same-date).
    // UI supplies repeat_until (YYYY-MM); legacy repeat_count still supported.
    // Unusable generated dates are skipped (reported) instead of failing the save.
    $repeatMode = strtolower(trim($_POST['repeat_mode'] ?? 'once'));
    if (!in_array($repeatMode, ['once', 'weekly', 'biweekly', 'monthly'], true)) {
        $repeatMode = 'once';
    }
    $repeatCount = max(0, min(12, (int) ($_POST['repeat_count'] ?? 0)));
    $repeatUntil = trim($_POST['repeat_until'] ?? '');
    $useUntil = preg_match('/^\d{4}-\d{2}$/', $repeatUntil) === 1;
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
    if ($repeatMode !== 'once') {
        if ($useUntil) {
            if ($repeatUntil < substr($date, 0, 7)) {
                lessons_error('Repeat-until month must not be before the lesson month.');
            }
            $untilEnd = date('Y-m-t', strtotime($repeatUntil . '-01'));
            if ($repeatMode === 'monthly') {
                $baseDay = (int) substr($date, 8, 2);
                $cursor = DateTime::createFromFormat('Y-m-d', $date);
                while (count($extraDates) < 24 && $cursor instanceof DateTime) {
                    $cursor = (clone $cursor)->modify('+1 month');
                    if ($cursor->format('Y-m-d') > $untilEnd) {
                        break;
                    }
                    if ((int) $cursor->format('j') !== $baseDay) {
                        $skipped[] = $cursor->format('Y-m') . ' (no such date)';
                        continue;
                    }
                    $addExtraDate($cursor->format('Y-m-d'));
                }
            } else {
                $step = $repeatMode === 'biweekly' ? 14 : 7;
                $cursor = $date;
                while (count($extraDates) < 24) {
                    $cursor = date('Y-m-d', strtotime($cursor . ' +' . $step . ' days'));
                    if ($cursor > $untilEnd) {
                        break;
                    }
                    $addExtraDate($cursor);
                }
            }
        } elseif ($repeatCount > 0) {
            $baseDay = (int) substr($date, 8, 2);
            for ($i = 1; $i <= $repeatCount; $i++) {
                if ($repeatMode === 'weekly') {
                    $d = date('Y-m-d', strtotime($date . ' +' . (7 * $i) . ' days'));
                } elseif ($repeatMode === 'biweekly') {
                    $d = date('Y-m-d', strtotime($date . ' +' . (14 * $i) . ' days'));
                } else {
                    $dt = DateTime::createFromFormat('Y-m-d', $date);
                    if ($dt === false) {
                        break;
                    }
                    $dt->modify('+' . $i . ' month');
                    if ((int) $dt->format('j') !== $baseDay) {
                        $skipped[] = $dt->format('Y-m') . ' (no such date)';
                        continue;
                    }
                    $d = $dt->format('Y-m-d');
                }
                $addExtraDate($d);
            }
        } else {
            lessons_error('Choose a repeat-until month, or set repeat back to Just once.');
        }
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

    $title = trim($_POST['title'] ?? '') ?: null;
    $group = count($extraDates) > 0 ? bin2hex(random_bytes(8)) : null;
    $allDates = array_merge([$date], $extraDates);

    $db->beginTransaction();
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
        foreach ($cleanStudents as $sid => $fee) {
            $lessonsManager->addEnrollment($lessonId, $sid, $fee);
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
