<?php
// Schedule / timetable: role-scoped week grid + lesson CRUD (admin) + availability (teacher).
require_once __DIR__ . '/includes/config.php';

$user = current_user();
if (!$user) {
    redirect_to('account.php?mode=login');
}
$role = normalize_role($user['role'] ?? 'GUEST');
if (!in_array($role, ['ADMIN', 'TEACHER', 'STUDENT'], true)) {
    redirect_to('account.php');
}

$lessonsAvailable = ($lessonsManager !== null);
$loadError = null;
if (!$lessonsAvailable) {
    $loadError = 'Schedule tables are missing. Import database/psm_lessons.sql, then reload.';
}

// ---- filters ----
$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
[$year, $mon] = array_map('intval', explode('-', $month));
$year = max(2000, min(2100, $year));
$mon = max(1, min(12, $mon));
$month = sprintf('%04d-%02d', $year, $mon);

$weekParam = trim($_GET['week'] ?? ''); // resolved to a Monday date below (issue 34)

$statusFilter = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
    $statusFilter = '';
}
$teacherFilter = (int) ($_GET['teacher_id'] ?? 0);
$studentFilter = (int) ($_GET['student_id'] ?? 0);
$courseFilter = $role === 'ADMIN' ? (int) ($_GET['course_id'] ?? 0) : 0;
$selectedLessonId = (int) ($_GET['lesson_id'] ?? 0);

// ---- issue 29: create-form defaults (next open day, next :00/:15/:30/:45 slot, +45 min) ----
$todayYmd = date('Y-m-d');
$nowMin = (int) date('G') * 60 + (int) date('i');
$nextSlotToday = (int) (floor($nowMin / 15) + 1) * 15; // strictly next boundary
$defaultDate = $todayYmd;
$defaultStartMin = 9 * 60;
for ($probe = 0; $probe < 8; $probe++) {
    $probeDate = date('Y-m-d', strtotime("+$probe day"));
    $probeDow = (int) date('N', strtotime($probeDate));
    if ($probeDow === 7) {
        continue; // closed Sunday
    }
    $closeMin = ($probeDow === 6) ? (18 * 60 + 30) : (22 * 60);
    $openStart = ($probe === 0) ? max($nextSlotToday, 9 * 60) : (9 * 60);
    if ($openStart + 45 <= $closeMin) {
        $defaultDate = $probeDate;
        $defaultStartMin = $openStart;
        break;
    }
}
$defaultStart = sprintf('%02d:%02d', (int) ($defaultStartMin / 60), $defaultStartMin % 60);
$defaultEndMin = $defaultStartMin + 45;
$defaultEnd = sprintf('%02d:%02d', (int) ($defaultEndMin / 60), $defaultEndMin % 60);
// quarter-hour options for the typable time datalist (issue 29)
$timeOptions = [];
for ($m = 9 * 60; $m <= 22 * 60; $m += 15) {
    $timeOptions[] = sprintf('%02d:%02d', (int) ($m / 60), $m % 60);
}
// "Today" shortcut target (issue 29): Monday of the current week (issue 34)
$todayMonth = date('Y-m');
$todayDow = (int) date('N');
$todayMonday = date('Y-m-d', strtotime($todayYmd . ' -' . ($todayDow - 1) . ' days'));

// ---- issue 34: Monday-first calendar weeks ----
$daysInMonth = (int) date('t', strtotime($month . '-01'));
$monthStart = sprintf('%04d-%02d-01', $year, $mon);
$monthEnd = sprintf('%04d-%02d-%02d', $year, $mon, $daysInMonth);
// calendar spans the Monday on/before the 1st through the Sunday on/after month end
$firstDow = (int) date('N', strtotime($monthStart));
$calStart = date('Y-m-d', strtotime($monthStart . ' -' . ($firstDow - 1) . ' days'));
$lastDow = (int) date('N', strtotime($monthEnd));
$calEnd = date('Y-m-d', strtotime($monthEnd . ' +' . (7 - $lastDow) . ' days'));
$calWeeks = []; // each: ['monday' => Y-m-d, 'days' => [7 x Y-m-d]]
for ($w = $calStart; $w <= $calEnd; $w = date('Y-m-d', strtotime($w . ' +7 days'))) {
    $wkDays = [];
    for ($i = 0; $i < 7; $i++) {
        $wkDays[] = date('Y-m-d', strtotime($w . " +$i days"));
    }
    $calWeeks[] = ['monday' => $w, 'days' => $wkDays];
}
// selected week: ?week=YYYY-MM-DD (snapped back to its Monday); legacy 1-5 still mapped
$weekMonday = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam)) {
    $wDow = (int) date('N', strtotime($weekParam));
    $weekMonday = date('Y-m-d', strtotime($weekParam . ' -' . ($wDow - 1) . ' days'));
} elseif (in_array($weekParam, ['1', '2', '3', '4', '5'], true)) {
    $weekMonday = $calWeeks[min((int) $weekParam, count($calWeeks)) - 1]['monday'];
} elseif ($month === $todayMonth && $todayYmd >= $calStart && $todayYmd <= $calEnd) {
    $weekMonday = $todayMonday;
} else {
    $weekMonday = $calWeeks[0]['monday'];
}
// issue 39: calendar month navigation targets (week defaults to the target month's first week)
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$prevWeekMonday = date('Y-m-d', strtotime($weekMonday . ' -7 days'));
$nextWeekMonday = date('Y-m-d', strtotime($weekMonday . ' +7 days'));
$weekEndSaturday = date('Y-m-d', strtotime($weekMonday . ' +5 days'));
$weekLabel = date('j M', strtotime($weekMonday . 'T12:00:00')) . ' – ' . date('j M Y', strtotime($weekEndSaturday . 'T12:00:00'));
// Week-of-month indicator follows the week's Thursday (majority of Mon-Sun sits in that month).
$weekThursday = date('Y-m-d', strtotime($weekMonday . ' +3 days'));
$weekOfMonth = (int) ceil((int) date('j', strtotime($weekThursday . 'T12:00:00')) / 7);
$weekOfMonthName = date('F', strtotime($weekThursday . 'T12:00:00'));
// grid days: Mon-Sat of the selected week (closed Sunday skipped)
$weekDays = [];
foreach (range(0, 5) as $i) {
    $ymd = date('Y-m-d', strtotime($weekMonday . " +$i days"));
    $weekDays[] = ['ymd' => $ymd, 'dow' => $i + 1, 'dowLabel' => date('D', strtotime($ymd . 'T12:00:00')), 'dateLabel' => date('j M', strtotime($ymd . 'T12:00:00'))];
}
// Timetable always shows exactly seven days (Mon–Sun); Sunday is closed.
$hDays = [];
foreach (range(0, 6) as $i) {
    $hYmd = date('Y-m-d', strtotime($weekMonday . " +$i days"));
    $hDow = $i + 1;
    $hDays[] = ['ymd' => $hYmd, 'dow' => $hDow, 'dowLabel' => date('D', strtotime($hYmd . 'T12:00:00')), 'dateLabel' => date('j M', strtotime($hYmd . 'T12:00:00'))];
}
$hLast = $hDays[count($hDays) - 1]['ymd'];

// ---- reference data ----
$teachers = [];
$students = [];
$coursePresets = [];
$teacherStudents = [];
$lessonStudents = [];
$lessons = [];
$selectedLesson = null;
$selectedEnrollments = [];
$groupSummary = [];
$enrolledIds = [];
$selectedTotals = ['student_count' => 0, 'total_fee' => 0, 'paid_total' => 0, 'unpaid_count' => 0];
$resolvedCommission = ['PERCENT', 0.0];
$myAvailability = [];

if ($lessonsAvailable && is_database_connected()) {
    try {
        $db = db();
        // Issue 32: roll past SCHEDULED lessons to COMPLETED before listing.
        $lessonsManager->autoCompletePast();
        $stmt = $db->query("SELECT user_id, username FROM users WHERE role = 'TEACHER' ORDER BY username ASC");
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $db->query("SELECT user_id, username, email FROM users WHERE role = 'STUDENT' ORDER BY username ASC");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $coursePresets = $db->query("SELECT course_id, course_name, class_mode, age_range, duration_options, minimum_fee FROM courses WHERE is_active = 1 ORDER BY course_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Issue 38: teacher's own students for the filter dropdown.
        $teacherStudents = [];
        if ($role === 'TEACHER') {
            $stmt = $db->prepare(
                "SELECT DISTINCT su.user_id, su.username FROM lesson_enrollments e
                 INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                 INNER JOIN users su ON su.user_id = e.student_id
                 WHERE l.teacher_id = :me ORDER BY su.username ASC"
            );
            $stmt->execute(['me' => (int) $user['user_id']]);
            $teacherStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $filters = ['from' => $calStart, 'to' => max($calEnd, $hLast)];
        if ($statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }
        if ($role === 'TEACHER') {
            $filters['teacher_id'] = (int) $user['user_id'];
        } elseif ($teacherFilter > 0) {
            $filters['teacher_id'] = $teacherFilter;
        }
        if ($role === 'STUDENT') {
            $filters['student_id'] = (int) $user['user_id'];
        } elseif ($studentFilter > 0) {
            // Issue 38: find all lessons for a certain student (admin + teacher).
            $filters['student_id'] = $studentFilter;
        }
        if ($role === 'ADMIN' && $courseFilter > 0) {
            $filters['course_id'] = $courseFilter;
        }
        $lessons = $lessonsManager->listLessons($filters, 'lesson_date', 'ASC', 500, 0);

        // Issue 41: student names per lesson for timetable chips.
        $lessonStudents = [];
        $lessonIds = array_unique(array_map(function ($l) {
            return (int) $l['lesson_id'];
        }, $lessons));
        if (!empty($lessonIds)) {
            $ph = implode(',', array_fill(0, count($lessonIds), '?'));
            $stmt = $db->prepare(
                'SELECT e.lesson_id, su.username FROM lesson_enrollments e
                 INNER JOIN users su ON su.user_id = e.student_id
                 WHERE e.lesson_id IN (' . $ph . ') ORDER BY su.username ASC'
            );
            $stmt->execute(array_values($lessonIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $nm) {
                $lessonStudents[(int) $nm['lesson_id']][] = $nm['username'];
            }
        }

        if ($selectedLessonId > 0) {
            $selectedLesson = $lessonsManager->getLesson($selectedLessonId);
            // Enforce scoping on the detail panel too.
            if ($selectedLesson !== null) {
                if ($role === 'TEACHER' && (int) $selectedLesson['teacher_id'] !== (int) $user['user_id']) {
                    $selectedLesson = null;
                } elseif ($role === 'STUDENT') {
                    $mine = false;
                    $check = $lessonsManager->getEnrollments($selectedLessonId);
                    foreach ($check as $c) {
                        if ((int) $c['student_id'] === (int) $user['user_id']) {
                            $mine = true;
                            break;
                        }
                    }
                    if (!$mine) {
                        $selectedLesson = null;
                    }
                }
            }
            if ($selectedLesson !== null) {
                $selectedEnrollments = $lessonsManager->getEnrollments($selectedLessonId);
                $selectedTotals = $lessonsManager->lessonTotals($selectedLessonId);
                $resolvedCommission = $lessonsManager->resolveCommission(
                    $selectedLesson['teacher_id'] !== null ? (int) $selectedLesson['teacher_id'] : null,
                    $selectedLesson['commission_type'],
                    $selectedLesson['commission_value']
                );
                // Issue 50/56: aggregated series rows for the details table.
                if (!empty($selectedLesson['recurrence_group'])) {
                    $groupSummary = $lessonsManager->getGroupSummary((string) $selectedLesson['recurrence_group']);
                }
                // Issue 63: students not yet enrolled, for the add-student picker.
                $enrolledIds = array_map(function ($e) {
                    return (int) $e['student_id'];
                }, $selectedEnrollments);
            }
        }

        if ($role === 'TEACHER') {
            $myAvailability = $lessonsManager->getTeacherAvailability((int) $user['user_id']);
        }
    } catch (Throwable $e) {
        $loadError = 'Schedule data could not be loaded. Confirm database/psm_lessons.sql has been imported.';
        $lessons = [];
    }
}

// ---- timetable slot rows (15-min, 09:00–22:00) ----
$slotRows = [];
for ($m = 9 * 60; $m < 22 * 60; $m += 15) {
    $slotRows[] = $m;
}
function sched_min(string $t): int
{
    $t = substr(trim($t), 0, 5);
    $p = explode(':', $t);
    return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
}
// Human-readable commission setting ("20%", "RM 50.00", "Default").
function sched_comm_label($type, $value): string
{
    $type = strtoupper(trim((string) $type));
    if ($type === 'PERCENT') {
        $v = trim((string) $value);
        if ($v !== '' && is_numeric($v)) {
            $v = (string) (float) $v;
            if (strpos($v, '.') !== false) {
                $v = rtrim(rtrim($v, '0'), '.');
            }
        }
        return $v . '%';
    }
    if ($type === 'FIXED') {
        return 'RM ' . (is_numeric($value) ? number_format((float) $value, 2) : trim((string) $value));
    }
    return 'Default';
}
// index lessons by date for the grid + calendar dots (issue 34) + empty counts (issue 61)
$lessonsByDate = [];
$dayCounts = [];
$dayEmpty = [];
foreach ($lessons as $l) {
    $d = substr((string) ($l['lesson_date'] ?? ''), 0, 10);
    if ($d < $calStart || $d > max($calEnd, $hLast)) {
        continue;
    }
    $lessonsByDate[$d][] = $l;
    $dayCounts[$d] = ($dayCounts[$d] ?? 0) + 1;
    if ((int) ($l['student_count'] ?? 0) === 0) {
        $dayEmpty[$d] = ($dayEmpty[$d] ?? 0) + 1;
    }
}

// Issue 86: week metadata — per-day counts, week totals, series identity.
// Everything derives from the already role/filter-scoped $lessons.
$weekStats = ['lessons' => 0, 'minutes' => 0, 'done' => 0, 'groups' => []];
$dayCountLabel = [];
foreach ($hDays as $day) {
    $dayList = $lessonsByDate[$day['ymd']] ?? [];
    $n = count($dayList);
    $dayCountLabel[$day['ymd']] = $n === 0 ? 'No lessons' : $n . ' lesson' . ($n === 1 ? '' : 's');
    foreach ($dayList as $l) {
        $weekStats['lessons']++;
        $weekStats['minutes'] += max(0, sched_min((string) $l['end_time']) - sched_min((string) $l['start_time']));
        if (($l['status'] ?? '') === 'COMPLETED') {
            $weekStats['done']++;
        }
        $g = (string) ($l['recurrence_group'] ?? '');
        if ($g !== '') {
            $weekStats['groups'][$g] = true;
        }
    }
}
$weekGroupIds = array_keys($weekStats['groups']);
// Series display name: most common non-empty title in the loaded range,
// falling back to the existing #hash convention.
$seriesNames = [];
$allGroupIds = [];
$titleVotes = [];
$seriesFirst = [];
foreach ($lessons as $l) {
    $g = (string) ($l['recurrence_group'] ?? '');
    if ($g === '') {
        continue;
    }
    if (!in_array($g, $allGroupIds, true)) {
        $allGroupIds[] = $g;
    }
    if (!isset($seriesFirst[$g])) {
        $seriesFirst[$g] = (int) ($l['lesson_id'] ?? 0);
    }
    $t = trim((string) ($l['course_name'] ?? ''));
    if ($t === '') $t = trim((string) ($l['title'] ?? ''));
    if ($t !== '') {
        $titleVotes[$g][$t] = ($titleVotes[$g][$t] ?? 0) + 1;
    }
}
foreach ($allGroupIds as $g) {
    if (!empty($titleVotes[$g])) {
        arsort($titleVotes[$g]);
        $seriesNames[$g] = (string) array_key_first($titleVotes[$g]);
    } else {
        $seriesNames[$g] = 'Series #' . substr($g, 0, 8);
    }
}
// Whole-series progress lives in the lesson detail panel; the class table
// below uses month-scoped commission/payment data instead (see seriesDetails).
// Series detail roll-up (teacher, commission, per-student fees) in one query.
$seriesDetails = [];
if ($allGroupIds !== [] && $lessonsAvailable && is_database_connected()) {
    try {
        $placeholders = implode(',', array_fill(0, count($allGroupIds), '?'));
        $stmt = db()->prepare(
            "SELECT l.recurrence_group AS g, l.teacher_id, tu.username AS teacher_name,
                    l.commission_type, l.commission_value, l.commission_status,
                    e.student_id, su.username AS student_name,
                    e.fee_amount, e.payment_status
             FROM lessons l
             LEFT JOIN users tu ON tu.user_id = l.teacher_id
             LEFT JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
             LEFT JOIN users su ON su.user_id = e.student_id
             WHERE l.recurrence_group IN ($placeholders)"
        );
        $stmt->execute($allGroupIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $g = (string) $row['g'];
            if (!isset($seriesDetails[$g])) {
                $seriesDetails[$g] = ['teachers' => [], 'tids' => [], 'comm' => [], 'pay' => [], 'students' => []];
            }
            $tn = trim((string) ($row['teacher_name'] ?? ''));
            if ($tn !== '') {
                $seriesDetails[$g]['teachers'][$tn] = ($seriesDetails[$g]['teachers'][$tn] ?? 0) + 1;
                $seriesDetails[$g]['tids'][$tn] = (int) ($row['teacher_id'] ?? 0);
            }
            $ck = strtoupper(trim((string) ($row['commission_type'] ?? ''))) . '|' . trim((string) ($row['commission_value'] ?? ''));
            $seriesDetails[$g]['comm'][$ck] = ($seriesDetails[$g]['comm'][$ck] ?? 0) + 1;
            $pcs = strtoupper(trim((string) ($row['commission_status'] ?? '')));
            if ($pcs !== '') {
                $seriesDetails[$g]['pay'][$pcs] = ($seriesDetails[$g]['pay'][$pcs] ?? 0) + 1;
            }
            if ($row['student_id'] !== null) {
                $sid = (int) $row['student_id'];
                if (!isset($seriesDetails[$g]['students'][$sid])) {
                    $seriesDetails[$g]['students'][$sid] = [
                        'name' => (string) ($row['student_name'] ?? ('#' . $sid)),
                        'lessons' => 0, 'total' => 0.0, 'unpaid' => 0, 'fees' => [],
                    ];
                }
                $seriesDetails[$g]['students'][$sid]['lessons']++;
                $seriesDetails[$g]['students'][$sid]['total'] += (float) ($row['fee_amount'] ?? 0);
                $feeStr = number_format((float) ($row['fee_amount'] ?? 0), 2, '.', '');
                $seriesDetails[$g]['students'][$sid]['fees'][$feeStr] = ($seriesDetails[$g]['students'][$sid]['fees'][$feeStr] ?? 0) + 1;
                if (strtoupper((string) ($row['payment_status'] ?? '')) !== 'PAID') {
                    $seriesDetails[$g]['students'][$sid]['unpaid']++;
                }
            }
        }
    } catch (Throwable $e) {
        $seriesDetails = [];
    }
}
// Frequency source: month lessons independent of grid filters (still
// role-scoped). Defined before the series loops that consume it.
$freqLessons = [];
if ($lessonsAvailable && is_database_connected()) {
    try {
        $ff = ['from' => $month . '-01', 'to' => date('Y-m-t', strtotime($month . '-01T12:00:00'))];
        if ($role === 'TEACHER') {
            $ff['teacher_id'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            $ff['student_id'] = (int) $user['user_id'];
        }
        $freqLessons = $lessonsManager->listLessons($ff, 'lesson_date', 'ASC', 2000, 0);
    } catch (Throwable $e) {
        $freqLessons = [];
    }
}
// Per-series month stats: class count + frequency in the calendar month,
// typical slot, and jump target. One-off month lessons counted separately.
$seriesMeta = [];
$monthGroupIds = [];
$monthOneOffs = 0;
$weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
foreach ($allGroupIds as $g) {
    $monthCount = 0;
    $uniqDates = [];
    $slotVotes = [];
    $nextDate = null;
    $lastDate = null;
    foreach ($lessons as $l) {
        if ((string) ($l['recurrence_group'] ?? '') !== $g) {
            continue;
        }
        $d = substr((string) ($l['lesson_date'] ?? ''), 0, 10);
        if ($lastDate === null || $d > $lastDate) {
            $lastDate = $d;
        }
        if (($l['status'] ?? '') === 'SCHEDULED' && $d >= $todayYmd && ($nextDate === null || $d < $nextDate)) {
            $nextDate = $d;
        }
        if (substr($d, 0, 7) !== $month) {
            continue;
        }
        $monthCount++;
        $uniqDates[$d] = true;
        $slotKey = date('N', strtotime($d . 'T12:00:00')) . '|' . substr((string) $l['start_time'], 0, 5);
        $slotVotes[$slotKey] = ($slotVotes[$slotKey] ?? 0) + 1;
    }
    if ($monthCount > 0) {
        $monthGroupIds[] = $g;
    }
    $cadence = '';
    $uniqDates = array_keys($uniqDates);
    sort($uniqDates);
    if (count($uniqDates) >= 2) {
        $gaps = [];
        for ($gi = 1; $gi < count($uniqDates); $gi++) {
            $gaps[] = (int) round((strtotime($uniqDates[$gi]) - strtotime($uniqDates[$gi - 1])) / 86400);
        }
        $uniform = count(array_unique($gaps)) === 1 ? $gaps[0] : null;
        if ($uniform === 1) {
            $cadence = 'Daily';
        } elseif ($uniform === 7) {
            $cadence = 'Weekly';
        } elseif ($uniform === 14) {
            $cadence = 'Every 2 weeks';
        } elseif ($uniform !== null && $uniform > 0) {
            $cadence = 'Every ' . $uniform . ' days';
        }
    }
    $slotLabel = '';
    if ($slotVotes !== []) {
        arsort($slotVotes);
        $parts = explode('|', (string) array_key_first($slotVotes));
        $slotLabel = trim(($weekdayNames[(int) $parts[0]] ?? '') . ' ' . ($parts[1] ?? ''));
    }
    $jumpDate = $nextDate ?? $lastDate;
    $jumpMonday = null;
    $jumpMonth = null;
    if ($jumpDate !== null) {
        $dow = (int) date('N', strtotime($jumpDate . 'T12:00:00'));
        $jumpMonday = date('Y-m-d', strtotime($jumpDate . ' -' . ($dow - 1) . ' days'));
        $jumpMonth = substr($jumpDate, 0, 7);
    }
    $seriesMeta[$g] = [
        'slot' => $slotLabel, 'jumpMonday' => $jumpMonday, 'jumpMonth' => $jumpMonth,
        'monthCount' => $monthCount, 'cadence' => $cadence,
    ];
}
// Groups active in the month regardless of grid filters (frequency + table
// stay truthful when a status/teacher filter hides rows).
foreach ($freqLessons as $l) {
    $g = (string) ($l['recurrence_group'] ?? '');
    if ($g !== '' && substr((string) ($l['lesson_date'] ?? ''), 0, 7) === $month && !in_array($g, $monthGroupIds, true)) {
        $monthGroupIds[] = $g;
    }
}
foreach ($lessons as $l) {
    if ((string) ($l['recurrence_group'] ?? '') === '' && substr((string) ($l['lesson_date'] ?? ''), 0, 7) === $month) {
        $monthOneOffs++;
    }
}
$monthLabel = date('F Y', strtotime($month . '-01T12:00:00'));

// Frequency squares use month lessons independent of grid filters (still
// role-scoped), so filtering never fakes empty weekdays.

// Frequency map: per-series weekday meeting ranges + clash flags.
// A weekday square is green when the series meets that day with no overlap,
// red when another displayed series overlaps it, grey otherwise.
$freqMap = [];
$freqClash = [];
foreach ($monthGroupIds as $g) {
    foreach ($freqLessons as $l) {
        if ((string) ($l['recurrence_group'] ?? '') !== $g) {
            continue;
        }
        $d = substr((string) ($l['lesson_date'] ?? ''), 0, 10);
        $dow = (int) date('N', strtotime($d . 'T12:00:00'));
        $s = substr((string) $l['start_time'], 0, 5);
        $e = substr((string) $l['end_time'], 0, 5);
        if (!isset($freqMap[$g][$dow])) {
            $freqMap[$g][$dow] = ['start' => $s, 'end' => $e];
        } else {
            if ($s < $freqMap[$g][$dow]['start']) {
                $freqMap[$g][$dow]['start'] = $s;
            }
            if ($e > $freqMap[$g][$dow]['end']) {
                $freqMap[$g][$dow]['end'] = $e;
            }
        }
    }
}
foreach ($monthGroupIds as $g) {
    foreach ($monthGroupIds as $h) {
        if ($h === $g) {
            continue;
        }
        foreach ([1, 2, 3, 4, 5, 6, 7] as $dow) {
            $a = $freqMap[$g][$dow] ?? null;
            $b = $freqMap[$h][$dow] ?? null;
            if ($a !== null && $b !== null && $a['start'] < $b['end'] && $b['start'] < $a['end']) {
                $freqClash[$g][$dow] = true;
            }
        }
    }
}

// Monthly fee records for the displayed month (class-table Fee column).
$monthFees = [];
if ($lessonsAvailable && is_database_connected()) {
    try {
        $stmt = db()->prepare('SELECT student_id, recurrence_group, amount, status FROM monthly_fees WHERE month = :m');
        $stmt->execute(['m' => $month]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $monthFees[(string) ($fr['recurrence_group'] ?? '')][(int) $fr['student_id']] = $fr;
        }
    } catch (Throwable $e) {
        $monthFees = [];
    }
}

// Series commission due: per-lesson payout on saved monthly transaction amounts,
 // regardless of student payment status. Teacher commission status is tracked separately.
$commDue = [];
if ($allGroupIds !== [] && $lessonsAvailable && $lessonsManager !== null && is_database_connected()) {
    try {
        $placeholders = implode(',', array_fill(0, count($allGroupIds), '?'));
        $stmt = db()->prepare(
            "SELECT l.lesson_id, l.lesson_date, l.recurrence_group AS g, l.teacher_id,
                    l.commission_type, l.commission_value,
                    e.student_id, e.fee_amount,
                    mf.amount AS monthly_amount
             FROM lessons l
             INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
             INNER JOIN monthly_fees mf ON mf.student_id = e.student_id
                 AND mf.month = SUBSTR(l.lesson_date, 1, 7)
                 AND COALESCE(mf.recurrence_group, '') = COALESCE(l.recurrence_group, '')
             WHERE l.recurrence_group IN ($placeholders)"
        );
        $stmt->execute($allGroupIds);
        $commissionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Allocate each monthly transaction amount over its lessons in
        // proportion to the lesson fees, then apply each lesson's own rate.
        $studentFeeTotals = [];
        $lessonCounts = [];
        $commissionLessons = [];
        foreach ($commissionRows as $commissionRow) {
            $commissionMonth = substr((string) $commissionRow['lesson_date'], 0, 7);
            $g = (string) ($commissionRow['g'] ?? '');
            $groupKey = $commissionMonth . '|' . $g;
            $studentKey = $groupKey . '|' . (int) $commissionRow['student_id'];
            $lessonKey = $groupKey . '|' . (int) $commissionRow['lesson_id'];
            $studentFeeTotals[$studentKey] = ($studentFeeTotals[$studentKey] ?? 0.0) + max(0.0, (float) $commissionRow['fee_amount']);
            $commissionLessons[$lessonKey] = $commissionRow;
            $lessonCounts[$groupKey][$lessonKey] = true;
        }
        $lessonPaidBases = [];
        foreach ($commissionRows as $commissionRow) {
            $commissionMonth = substr((string) $commissionRow['lesson_date'], 0, 7);
            $g = (string) ($commissionRow['g'] ?? '');
            $groupKey = $commissionMonth . '|' . $g;
            $studentKey = $groupKey . '|' . (int) $commissionRow['student_id'];
            $lessonKey = $groupKey . '|' . (int) $commissionRow['lesson_id'];
            $studentBase = (float) ($studentFeeTotals[$studentKey] ?? 0.0);
            $monthlyAmount = max(0.0, (float) $commissionRow['monthly_amount']);
            $basis = $studentBase > 0
                ? $monthlyAmount * max(0.0, (float) $commissionRow['fee_amount']) / $studentBase
                : $monthlyAmount / max(1, count($lessonCounts[$groupKey] ?? []));
            $lessonPaidBases[$lessonKey] = ($lessonPaidBases[$lessonKey] ?? 0.0) + $basis;
        }
        $percentBasesByRate = [];
        foreach ($commissionLessons as $lessonKey => $commissionRow) {
            $paidBasis = (float) ($lessonPaidBases[$lessonKey] ?? 0.0);
            if ($paidBasis <= 0) continue;
            $g = (string) ($commissionRow['g'] ?? '');
            [$ct, $cv] = $lessonsManager->resolveCommission(
                $commissionRow['teacher_id'] !== null ? (int) $commissionRow['teacher_id'] : null,
                $commissionRow['commission_type'],
                $commissionRow['commission_value']
            );
            if ($ct === 'PERCENT') {
                $rateKey = number_format((float) $cv, 2, '.', '');
                $percentBasesByRate[$g][$rateKey]['value'] = (float) $cv;
                $percentBasesByRate[$g][$rateKey]['basis'] = ($percentBasesByRate[$g][$rateKey]['basis'] ?? 0.0) + $paidBasis;
            } else {
                // Fixed commission remains a per-lesson amount.
                $commDue[$g] = ($commDue[$g] ?? 0.0) + $lessonsManager->commissionPayout($ct, (float) $cv, $paidBasis);
            }
        }
        foreach ($percentBasesByRate as $g => $rates) {
            foreach ($rates as $rate) {
                // Round percentage commission once for each rate within the series.
                $commDue[$g] = ($commDue[$g] ?? 0.0) + $lessonsManager->commissionPayout('PERCENT', $rate['value'], $rate['basis']);
            }
        }
    } catch (Throwable $e) {
        $commDue = [];
    }
}

// teacher availability map for the create form (admin)
$availMap = [];
if ($role === 'ADMIN' && $lessonsAvailable && $lessonsManager !== null) {
    foreach ($teachers as $t) {
        $availMap[(int) $t['user_id']] = $lessonsManager->getTeacherAvailability((int) $t['user_id']);
    }
}

function sched_qs(array $overrides): string
{
    $base = [
        'month' => $_GET['month'] ?? date('Y-m'),
        'week' => $_GET['week'] ?? '',
        'status' => $_GET['status'] ?? '',
        'teacher_id' => $_GET['teacher_id'] ?? '',
        'student_id' => $_GET['student_id'] ?? '',
    ];
    $q = array_merge($base, $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return http_build_query($q);
}

// Next/current class card. This query deliberately ignores the timetable filters:
// admins see the next class across the whole school, while teacher/student queries
// are restricted to the signed-in user's own lessons.
$nextClass = null;
$nextClassState = 'upcoming';
$nextClassStudents = [];
if ($lessonsAvailable && $lessonsManager !== null && is_database_connected()) {
    try {
        $nextFilters = [
            'from' => $todayYmd,
            'to' => date('Y-m-d', strtotime($todayYmd . ' +60 days')),
        ];
        if ($role === 'TEACHER') {
            $nextFilters['teacher_id'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            $nextFilters['student_id'] = (int) $user['user_id'];
        }
        $nextCandidates = $lessonsManager->listLessons($nextFilters, 'lesson_date', 'ASC', 1000, 0);
        $nowTs = time();
        $currentCandidates = [];
        $upcomingCandidates = [];
        foreach ($nextCandidates as $candidate) {
            if (strtoupper((string) ($candidate['status'] ?? '')) !== 'SCHEDULED') {
                continue;
            }
            $candidateDate = substr((string) ($candidate['lesson_date'] ?? ''), 0, 10);
            $startTs = strtotime($candidateDate . ' ' . substr((string) ($candidate['start_time'] ?? ''), 0, 8));
            $endTs = strtotime($candidateDate . ' ' . substr((string) ($candidate['end_time'] ?? ''), 0, 8));
            if ($startTs === false || $endTs === false || $endTs <= $nowTs) {
                continue;
            }
            $candidate['_start_ts'] = $startTs;
            $candidate['_end_ts'] = $endTs;
            if ($startTs <= $nowTs) {
                $currentCandidates[] = $candidate;
            } else {
                $upcomingCandidates[] = $candidate;
            }
        }
        if ($currentCandidates !== []) {
            usort($currentCandidates, function ($a, $b) {
                return $a['_end_ts'] <=> $b['_end_ts'];
            });
            $nextClass = $currentCandidates[0];
            $nextClassState = 'in-progress';
        } elseif ($upcomingCandidates !== []) {
            usort($upcomingCandidates, function ($a, $b) {
                return $a['_start_ts'] <=> $b['_start_ts'];
            });
            $nextClass = $upcomingCandidates[0];
        }
        if ($nextClass !== null) {
            $studentStmt = db()->prepare(
                'SELECT su.username FROM lesson_enrollments e
                 INNER JOIN users su ON su.user_id = e.student_id
                 WHERE e.lesson_id = :lesson_id ORDER BY su.username ASC'
            );
            $studentStmt->execute(['lesson_id' => (int) $nextClass['lesson_id']]);
            $nextClassStudents = $studentStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Throwable $e) {
        $nextClass = null;
        $nextClassStudents = [];
    }
}

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/admin.css?v=' . filemtime(__DIR__ . '/assets/css/admin.css')); ?>">
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/schedule.css?v=' . filemtime(__DIR__ . '/assets/css/schedule.css')); ?>">

<div class="sched-wrap">
    <div class="sched-header-bar">
        <div class="sched-title-wrap">
            <p class="eyebrow">Timetable &amp; Bookings</p>
            <div class="sched-title-row">
                <h1 class="sched-page-title">Schedule</h1>
                <span class="role-badge role-<?= strtolower($role) ?>"><?= e($role) ?></span>
            </div>
            <p class="sched-subtitle">
                <?php if ($role === 'ADMIN'): ?>15-min slots · weekday 9am–10pm · Sat 9am–6:30pm · closed Sun. Drag across empty cells to schedule lessons; click any lesson chip for details and fees.<?php endif; ?>
                <?php if ($role === 'TEACHER'): ?>Viewing your assigned lessons. Configure your weekly availability below so administrators can avoid clashes.<?php endif; ?>
                <?php if ($role === 'STUDENT'): ?>Viewing your enrolled lessons and schedule. Request new bookings directly with the administrator.<?php endif; ?>
            </p>
        </div>
        <div class="sched-header-actions">
            <a href="<?= e(url_path('transactions.php')) ?>" class="sched-txn-btn" title="View fees, student payments, and commissions">
                <span aria-hidden="true">💳</span> Transactions &amp; Fees
            </a>
            <a href="<?= e(url_path('schedule.php?month=' . $todayMonth . '&week=' . $todayMonday)) ?>" class="btn secondary-btn">
                Today
            </a>
            <a href="<?= e(url_path('account.php')) ?>" class="text-button">
                ← Back to Account
            </a>
        </div>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php endif; ?>

    <!-- Next/current class: role-scoped independently from the calendar filters. -->
    <section class="sched-next-card<?= $nextClassState === 'in-progress' ? ' is-live' : '' ?>" aria-labelledby="schedNextTitle">
        <div class="sched-next-icon" aria-hidden="true"><?= $nextClassState === 'in-progress' ? '▶' : '♫' ?></div>
        <div class="sched-next-content">
            <div class="sched-next-heading">
                <p class="sched-next-eyebrow"><?= $nextClassState === 'in-progress' ? 'Happening now' : 'Coming up next' ?></p>
                <h2 id="schedNextTitle"><?= $nextClass !== null ? e(trim((string) ($nextClass['course_name'] ?? '')) !== '' ? (string) $nextClass['course_name'] : (string) ($nextClass['title'] ?? 'Lesson')) : 'No upcoming class' ?></h2>
            </div>
            <?php if ($nextClass !== null): ?>
                <p class="sched-next-time">
                    <span aria-hidden="true">◷</span>
                    <?= e(date('D, j M Y', strtotime(substr((string) $nextClass['lesson_date'], 0, 10) . 'T12:00:00'))) ?>
                    <span class="sched-next-time-divider">·</span>
                    <?= e(date('g:i A', strtotime(substr((string) $nextClass['start_time'], 0, 5)))) ?>–<?= e(date('g:i A', strtotime(substr((string) $nextClass['end_time'], 0, 5)))) ?>
                </p>
                <div class="sched-next-meta">
                    <?php if ($role !== 'STUDENT'): ?>
                        <span><strong>Students</strong> <?= $nextClassStudents !== [] ? e(implode(', ', $nextClassStudents)) : 'No students enrolled' ?></span>
                    <?php endif; ?>
                    <?php if ($role !== 'TEACHER'): ?>
                        <span><strong>Teacher</strong> <?= !empty($nextClass['teacher_name']) ? e($nextClass['teacher_name']) : 'Not assigned' ?></span>
                    <?php endif; ?>
                    <?php if ($role === 'STUDENT'): ?>
                        <span><strong>Teacher</strong> <?= !empty($nextClass['teacher_name']) ? e($nextClass['teacher_name']) : 'Not assigned' ?></span>
                    <?php endif; ?>
                    <?php if ($role === 'ADMIN'): ?>
                        <span><strong>Enrolment</strong> <?= (int) ($nextClass['student_count'] ?? count($nextClassStudents)) ?> student<?= ((int) ($nextClass['student_count'] ?? count($nextClassStudents)) === 1) ? '' : 's' ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="sched-next-empty">There are no scheduled classes in the next 60 days.</p>
            <?php endif; ?>
        </div>
        <?php if ($nextClass !== null): ?>
            <a class="sched-next-open" href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => (int) $nextClass['lesson_id'], 'month' => substr((string) $nextClass['lesson_date'], 0, 7), 'week' => date('Y-m-d', strtotime(substr((string) $nextClass['lesson_date'], 0, 10) . ' -' . ((int) date('N', strtotime(substr((string) $nextClass['lesson_date'], 0, 10))) - 1) . ' days'))])) ?>#schedGrid">View class <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </section>

    <!-- Controls Deck: Mini-Calendar & Filters -->
    <div class="sched-controls-deck">
        <div class="sched-panel sched-cal-panel">
            <div class="sched-cal-head">
                <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $prevMonth, 'week' => '', 'lesson_id' => '', 'teacher_id' => '', 'student_id' => ''])) . '#schedGrid') ?>" aria-label="Previous month">‹ Prev</a>
                <strong><?= e(date('F Y', strtotime($monthStart . 'T12:00:00'))) ?></strong>
                <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $nextMonth, 'week' => '', 'lesson_id' => '', 'teacher_id' => '', 'student_id' => ''])) . '#schedGrid') ?>" aria-label="Next month">Next ›</a>
            </div>
            <table class="sched-cal" aria-label="Month calendar">
                <thead><tr><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th><th>Su</th></tr></thead>
                <tbody>
                    <?php foreach ($calWeeks as $cw): ?>
                        <?php $cwSel = ($cw['monday'] === $weekMonday); ?>
                        <tr class="sched-cal-week<?= $cwSel ? ' is-selected' : '' ?>" data-href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $month, 'week' => $cw['monday'], 'lesson_id' => ''])) . '#schedGrid') ?>" tabindex="0" title="Show week of <?= e($cw['monday']) ?>">
                            <?php foreach ($cw['days'] as $cdd): ?>
                                <?php
                                $cdDow = (int) date('N', strtotime($cdd . 'T12:00:00'));
                                $cdCls = 'sched-cal-day' . (substr($cdd, 0, 7) === $month ? '' : ' is-dim') . ($cdDow === 7 ? ' is-sun' : '') . ($cdd === $todayYmd ? ' is-today' : '');
                                $cdCnt = $dayCounts[$cdd] ?? 0;
                                $cdEmpty = $dayEmpty[$cdd] ?? 0;
                                ?>
                                <td class="<?= $cdCls ?>">
                                    <span class="sched-cal-num"><?= e((int) substr($cdd, 8, 2)) ?></span>
                                    <?php if ($cdCnt > 0): ?><span class="sched-cal-dot"><?= $cdCnt ?>•</span><?php endif; ?>
                                    <?php if ($cdEmpty > 0): ?><span class="sched-cal-empty" title="<?= $cdEmpty ?> lesson(s) with no students">∅<?= $cdEmpty ?></span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="sched-empty" style="margin:8px 0 0;font-size:11.5px;">Click a week row to view timetable</p>
        </div>

        <div class="sched-panel sched-filter-panel">
            <div>
                <h2 style="margin:0 0 10px;font-size:1.05rem;">Filter Schedule</h2>
                <form class="sched-filter-form" method="get" action="<?= e(url_path('schedule.php')); ?>">
                    <input type="hidden" name="month" value="<?= e($month) ?>">
                    <input type="hidden" name="week" value="<?= e($weekMonday) ?>">
                    <div class="sched-filter-grid">
                        <label>Status
                            <select name="status">
                                <option value="">All statuses</option>
                                <option value="SCHEDULED" <?= $statusFilter === 'SCHEDULED' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="COMPLETED" <?= $statusFilter === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                                <option value="CANCELLED" <?= $statusFilter === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </label>
                        <?php if ($role === 'ADMIN'): ?>
                        <label>Teacher
                            <select name="teacher_id">
                                <option value="">All teachers</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= (int) $t['user_id'] ?>" <?= $teacherFilter === (int) $t['user_id'] ? 'selected' : '' ?>><?= e($t['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Student
                            <select name="student_id">
                                <option value="">All students</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?= (int) $s['user_id'] ?>" <?= $studentFilter === (int) $s['user_id'] ? 'selected' : '' ?>><?= e($s['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php elseif ($role === 'TEACHER'): ?>
                        <label>Student
                            <select name="student_id">
                                <option value="">All my students</option>
                                <?php foreach ($teacherStudents as $s): ?>
                                    <option value="<?= (int) $s['user_id'] ?>" <?= $studentFilter === (int) $s['user_id'] ? 'selected' : '' ?>><?= e($s['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php endif; ?>
                    </div>
                    <div class="sched-filter-actions">
                        <button type="submit" class="primary-btn">Apply Filter</button>
                        <a href="<?= e(url_path('schedule.php?month=' . $month . '&week=' . $weekMonday)) ?>" class="btn secondary-btn" style="padding:7px 14px;font-size:13px;">Clear</a>
                    </div>
                </form>
            </div>
        </div>
    </div><!-- /.sched-controls-deck -->

    <!-- Classes this month: ID, title, teacher, commission, students, frequency, payment -->
    <div class="sched-panel sched-series-panel">
        <div class="sched-series-head">
            <h2>Classes · <?= e($monthLabel) ?></h2>
            <button type="button" class="btn secondary-btn" id="schedOpenCreateDialog">＋ New class</button>
        </div>
        <?php if ($monthGroupIds === []): ?>
            <p class="sched-empty" style="text-align:left;">No classes this month.</p>
        <?php else: ?>
            <div class="sched-table-scroll">
            <table class="sched-class-table">
                <thead><tr><th>Class</th><th>Title</th><th>Teacher</th><th>Commission</th><th>Students</th><th>Fee (RM/month)</th><th>Day</th><th>Time</th><th>Edit</th></tr></thead>
                <tbody>
                <?php foreach ($monthGroupIds as $g): ?>
                    <?php
                    $gd = $seriesDetails[$g] ?? ['teachers' => [], 'tids' => [], 'comm' => [], 'pay' => [], 'students' => []];
                    $teacherLabel = 'Unassigned';
                    $teacherIdNow = 0;
                    if ($gd['teachers'] !== []) {
                        arsort($gd['teachers']);
                        $topTeacher = (string) array_key_first($gd['teachers']);
                        $teacherIdNow = (int) (($gd['tids'] ?? [])[$topTeacher] ?? 0);
                        $teacherLabel = $topTeacher;
                        $teacherExtra = count($gd['teachers']) - 1;
                        if ($teacherExtra > 0) {
                            $teacherLabel .= ' +' . $teacherExtra;
                        }
                    }
                    $rangeList = [];
                    foreach ($freqMap[$g] ?? [] as $rdow => $rr) {
                        $rangeList[] = ['dow' => $rdow, 'start' => $rr['start'], 'end' => $rr['end']];
                    }
                    $rangesJson = json_encode($rangeList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    $commLabel = 'Default';
                    $commParts = ['', ''];
                    $hasCustomCommission = false;
                    foreach (array_keys($gd['comm']) as $commissionKey) {
                        $commissionParts = explode('|', (string) $commissionKey, 2);
                        if (trim((string) ($commissionParts[0] ?? '')) !== '' && trim((string) ($commissionParts[1] ?? '')) !== '') {
                            $hasCustomCommission = true;
                            break;
                        }
                    }
                    if ($gd['comm'] !== []) {
                        arsort($gd['comm']);
                        $commParts = explode('|', (string) array_key_first($gd['comm']), 2);
                        $commLabel = sched_comm_label($commParts[0] ?? '', $commParts[1] ?? '');
                    }
                    $effectiveCommission = $lessonsManager->resolveCommission($teacherIdNow > 0 ? $teacherIdNow : null, $commParts[0] ?? null, $commParts[1] ?? null);
                    $commissionSource = strtoupper((string) ($effectiveCommission[0] ?? 'PERCENT')) === 'FIXED' ? 'Fixed' : 'Percentage';
                    $payLabel = 'Unpaid';
                    $payCls = 'is-bad';
                    if ($gd['pay'] !== []) {
                        arsort($gd['pay']);
                        $payTop = (string) array_key_first($gd['pay']);
                        if ($payTop === 'PAID') {
                            $payLabel = 'Paid';
                            $payCls = 'is-ok';
                        } elseif ($payTop === 'PENDING') {
                            $payLabel = 'Pending';
                            $payCls = 'is-warn';
                        }
                    }
                    $studentList = $gd['students'];
                    uasort($studentList, function ($a, $b) { return strcmp((string) $a['name'], (string) $b['name']); });
                    $studUnpaid = 0;
                    foreach ($studentList as $stRow) {
                        $studUnpaid += (int) $stRow['unpaid'];
                    }
                    ?>
                    <tr>
                        <td><span class="sched-series-hash">#<?= e(substr($g, 0, 8)) ?></span></td>
                        <td><?php if ($role === 'ADMIN'): ?><button type="button" class="sched-cell-btn" data-series-title="<?= e($g) ?>" data-name="<?= e($seriesNames[$g] ?? '') ?>"><?= e($seriesNames[$g] ?? ('#' . substr($g, 0, 8))) ?></button><?php else: ?><strong><?= e($seriesNames[$g] ?? ('#' . substr($g, 0, 8))) ?></strong><?php endif; ?></td>
                        <td><?php if ($role === 'ADMIN'): ?><button type="button" class="sched-cell-btn" data-series-teacher="<?= e($g) ?>" data-teacher="<?= (int) $teacherIdNow ?>" data-ranges="<?= e($rangesJson) ?>"><?= e($teacherLabel) ?></button><?php else: ?><?= e($teacherLabel) ?><?php endif; ?></td>
                        <td>
                            <?php if ($role === 'ADMIN'): ?><button type="button" class="sched-cell-btn" data-series-commshow="<?= e($g) ?>" data-commission-type="<?= e($commParts[0] ?? '') ?>" data-commission-value="<?= e($commParts[1] ?? '') ?>" title="<?= e($commLabel) ?>">RM <?= e(number_format((float) ($commDue[$g] ?? 0), 2)) ?></button><?php else: ?>RM <?= e(number_format((float) ($commDue[$g] ?? 0), 2)) ?><?php endif; ?>
                            <div class="sched-commission-source <?= $commissionSource === 'Fixed' ? 'is-fixed' : 'is-percentage' ?>"><?= e($commissionSource) ?></div>
                        </td>
                        <td>
                            <?php if ($role === 'ADMIN'): ?>
                                <button type="button" class="sched-cell-btn sched-cell-students" data-series-students="<?= e($g) ?>" aria-label="Edit students for <?= e($seriesNames[$g] ?? 'class') ?>">
                                    <?php if ($studentList === []): ?><span class="sched-empty">＋ Add students</span>
                                    <?php else: ?>
                                        <?php foreach ($studentList as $stRow): ?><span class="stud-fee"><?= e($stRow['name']) ?></span><?php endforeach; ?>
                                        <span class="sched-inline-hint">Edit</span>
                                    <?php endif; ?>
                                </button>
                            <?php elseif ($studentList === []): ?>
                                <span class="sched-empty">—</span>
                            <?php else: ?>
                                <?php foreach ($studentList as $stRow): ?><span class="stud-fee"><?= e($stRow['name']) ?></span><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($studentList === []): ?>
                                <span class="sched-empty">—</span>
                            <?php elseif ($role === 'ADMIN'): ?>
                                <?php foreach ($studentList as $stSid => $stRow): ?>
                                    <?php $mf = $monthFees[$g][(int) $stSid] ?? null; ?>
                                    <input type="number" class="fee-input" min="0" step="0.01" value="<?= e($mf !== null ? number_format((float) $mf['amount'], 2, '.', '') : '') ?>" placeholder="0.00" aria-label="Monthly fee in RM for <?= e($stRow['name']) ?>" data-mfee data-month="<?= e($month) ?>" data-group="<?= e($g) ?>" data-sid="<?= (int) $stSid ?>" data-status="<?= e($mf['status'] ?? 'UNPAID') ?>">
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($studentList as $stSid => $stRow): ?>
                                    <?php $mf = $monthFees[$g][(int) $stSid] ?? null; ?>
                                    <span class="stud-fee"><?= $mf !== null ? 'RM ' . e(number_format((float) $mf['amount'], 2))  : '—' ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="sched-freq" aria-label="Class weekdays">
                            <?php foreach ([1, 2, 3, 4, 5, 6, 7] as $fdow): ?>
                                <?php
                                $fcell = $freqMap[$g][$fdow] ?? null;
                                $fcls = '';
                                $ftitle = $weekdayNames[$fdow] . ' — no class';
                                if ($fcell !== null) {
                                    $fcls = isset($freqClash[$g][$fdow]) ? 'is-clash' : 'is-on';
                                    $ftitle = $weekdayNames[$fdow] . ' ' . $fcell['start'] . '–' . $fcell['end'] . (isset($freqClash[$g][$fdow]) ? ' (clash!)' : '');
                                }
                                ?>
                                <?php if ($role === 'ADMIN' && $fdow < 7): ?>
                                    <button type="button" class="sched-freq-day <?= $fcls ?>" data-series-weekday="<?= e($g) ?>" data-target-dow="<?= $fdow ?>" title="Move this class to <?= e($weekdayNames[$fdow]) ?><?= $fcell !== null ? ' · ' . e($fcell['start'] . '–' . $fcell['end']) : '' ?>" aria-label="Move class to <?= e($weekdayNames[$fdow]) ?>"><?= e(substr($weekdayNames[$fdow], 0, 1)) ?></button>
                                <?php else: ?>
                                    <span class="<?= $fcls ?><?= $fdow === 7 ? ' is-closed' : '' ?>" title="<?= e($ftitle) ?>"></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $primarySlot = null;
                            foreach (($freqMap[$g] ?? []) as $slotDow => $slotData) { $primarySlot = $slotData; break; }
                            $classStart = $primarySlot['start'] ?? '09:00';
                            $classEnd = $primarySlot['end'] ?? '09:45';
                            ?>
                            <?php if ($role === 'ADMIN'): ?>
                                <button type="button" class="sched-cell-btn sched-cell-time" data-series-time="<?= e($g) ?>" data-start="<?= e($classStart) ?>" data-end="<?= e($classEnd) ?>" title="Edit class time"><?= e($classStart) ?>–<?= e($classEnd) ?> <span class="sched-inline-hint">Edit</span></button>
                            <?php else: ?>
                                <?= e($classStart) ?>–<?= e($classEnd) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $firstId = $seriesFirst[$g] ?? 0; ?>
                            <?php if ($firstId > 0): ?>
                                <a class="series-btn" href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => $firstId])) . '#schedEditForm') ?>">Edit</a>
                            <?php else: ?>
                                <span class="sched-empty">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <dialog class="sched-dialog sched-dialog-wide" id="seriesTeacherDialog" aria-labelledby="seriesTeacherTitle">
        <div class="sched-edit-head">
            <h2 id="seriesTeacherTitle">Assign teacher</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <p class="sched-dialog-sub" id="seriesTeacherSub"></p>
        <table class="sched-pick-table">
            <thead><tr><th>ID</th><th>Teacher</th><th>Commission</th><th>Availability</th><th></th></tr></thead>
            <tbody id="seriesTeacherRows"></tbody>
        </table>
        <p style="margin:12px 0 4px;"><button type="button" class="secondary-btn" id="seriesNewTeacherToggle" style="font-size:13px;">＋ New teacher account</button></p>
        <div id="seriesNewTeacherBox" hidden>
            <div id="seriesNewTeacherMsg" class="alert" style="display:none;"></div>
            <div class="form-field"><label>Username<input type="text" id="seriesNewTeacherUsername" maxlength="100" autocomplete="off"></label></div>
            <div class="form-field"><label>Email (optional)<input type="email" id="seriesNewTeacherEmail" maxlength="100" autocomplete="off"></label></div>
            <div class="form-field"><label>Password (min 6 characters)<input type="password" id="seriesNewTeacherPassword" autocomplete="new-password"></label></div>
            <button type="button" class="secondary-btn" id="seriesNewTeacherCreate" style="width:100%;">Create teacher</button>
        </div>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Close</button>
        </div>
    </dialog>

    <dialog class="sched-dialog sched-dialog-wide" id="seriesStudentDialog" aria-labelledby="seriesStudentTitle">
        <div class="sched-edit-head">
            <h2 id="seriesStudentTitle">Manage students</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <p class="sched-dialog-sub" id="seriesStudentSub"></p>
        <div class="form-field"><label>Filter students<input type="search" id="seriesStudentFilter" placeholder="Type a name…" autocomplete="off"></label></div>
        <table class="sched-pick-table">
            <thead><tr><th>ID</th><th>Student</th><th>Fee (RM)</th><th></th></tr></thead>
            <tbody id="seriesStudentRows"></tbody>
        </table>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Close</button>
        </div>
    </dialog>

    <dialog class="sched-dialog sched-dialog-wide" id="createTeacherDialog" aria-labelledby="createTeacherTitle">
        <div class="sched-edit-head">
            <h2 id="createTeacherTitle">Pick teacher</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <p class="sched-dialog-sub">Coverage is checked against the picked date and time.</p>
        <table class="sched-pick-table">
            <thead><tr><th>ID</th><th>Teacher</th><th>Commission</th><th>Availability</th><th></th></tr></thead>
            <tbody id="createTeacherRows"></tbody>
        </table>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Close</button>
        </div>
    </dialog>

    <dialog class="sched-dialog sched-dialog-wide" id="createStudentDialog" aria-labelledby="createStudentTitle">
        <div class="sched-edit-head">
            <h2 id="createStudentTitle">Add students</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <div class="form-field"><label>Filter students<input type="search" id="createStudentFilter" placeholder="Type a name…" autocomplete="off"></label></div>
        <table class="sched-pick-table">
            <thead><tr><th>ID</th><th>Student</th><th>Fee (RM/month)</th><th></th></tr></thead>
            <tbody id="createStudentRows"></tbody>
        </table>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Close</button>
        </div>
    </dialog>

    <dialog class="sched-dialog" id="seriesTimeDialog" aria-labelledby="seriesTimeTitle">
        <div class="sched-edit-head">
            <h2 id="seriesTimeTitle">Edit class time</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <p class="sched-dialog-sub" id="seriesTimeSub"></p>
        <div class="form-field"><label>Start time<input type="time" id="seriesTimeStart" step="900" required></label></div>
        <div class="form-field"><label>End time<input type="time" id="seriesTimeEnd" step="900" required></label></div>
        <p class="sched-empty">This updates the time of every lesson in the class. Times must use 15-minute intervals.</p>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Cancel</button>
            <button type="button" class="primary-btn" id="seriesTimeSave">Save time</button>
        </div>
    </dialog>

    <dialog class="sched-dialog" id="seriesCommDialog" aria-labelledby="seriesCommTitle">
        <div class="sched-edit-head">
            <h2 id="seriesCommTitle">Edit commission</h2>
            <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
        </div>
        <p class="sched-dialog-sub" id="seriesCommSub"></p>
        <div class="form-field"><label>Type<select id="seriesCommType">
            <option value="">Default</option>
            <option value="PERCENT">Percent %</option>
            <option value="FIXED">Fixed RM</option>
        </select></label></div>
        <div class="form-field"><label>Value<input type="number" id="seriesCommValue" min="0" step="0.01" placeholder="blank = default"></label></div>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" data-series-close>Cancel</button>
            <button type="button" class="primary-btn" id="seriesCommSave">Save</button>
        </div>
    </dialog>

    <!-- Main Stage: Weekly Timetable -->
    <div class="sched-panel sched-main-stage" id="schedGrid" role="region" aria-label="Weekly timetable">
        <div class="sched-stage-header">
            <div class="sched-week-nav">
                <div class="sched-week-title" title="Pick a week from the month calendar above">
                    <span class="sched-week-icon" aria-hidden="true">📅</span>
                    <strong>Week of <?= e($weekLabel) ?></strong>
                    <span class="sched-week-chip">Week <?= $weekOfMonth ?> of <?= e($weekOfMonthName) ?></span>
                </div>
            </div>
            <div class="sched-view-switcher" role="group" aria-label="Timetable view layout">
                <button type="button" class="sched-view-btn active" id="schedViewBtnH" data-view="horizontal">
                    <span>⇄</span> Horizontal Timeline
                </button>
                <button type="button" class="sched-view-btn" id="schedViewBtnV" data-view="vertical">
                    <span>⇅</span> Vertical Grid
                </button>
            </div>
        </div>

        <?php
        $weekHours = $weekStats['minutes'] > 0 ? rtrim(rtrim(number_format($weekStats['minutes'] / 60, 1), '0'), '.') : '0';
        $weekDonePct = $weekStats['lessons'] > 0 ? (int) round($weekStats['done'] / $weekStats['lessons'] * 100) . '%' : '—';
        ?>
        <div class="sched-week-strip" aria-label="This week at a glance">
            <span><strong><?= (int) $weekStats['lessons'] ?></strong> lessons</span>
            <span><strong><?= e($weekHours) ?></strong> hrs</span>
            <span><strong><?= count($weekGroupIds) ?></strong> series</span>
            <span><strong><?= e($weekDonePct) ?></strong> done</span>
        </div>

        <!-- HORIZONTAL TIMELINE VIEW (Days as rows, Time as columns) -->
        <div class="sched-view-horizontal" id="schedViewHorizontal">
            <div class="sched-h-scroll">
                <table class="sched-h-table">
                    <thead>
                        <tr>
                            <th class="sched-h-corner">Day / Time</th>
                            <?php foreach ($slotRows as $slotMin): ?>
                                <?php $slotLabel = sprintf('%02d:%02d', (int) ($slotMin / 60), $slotMin % 60); ?>
                                <?php $isHour = ($slotMin % 60 === 0); ?>
                                <th class="sched-h-time-head<?= $isHour ? ' is-hour' : '' ?>"><?= $isHour ? e($slotLabel) : '' ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hDays as $day): ?>
                            <?php $isToday = ($day['ymd'] === $todayYmd); ?>
                            <tr class="sched-h-day-row<?= $isToday ? ' is-today-row' : '' ?>"<?= $isToday ? ' id="schedTodayRow"' : '' ?>>
                                <th class="sched-h-day-head<?= $day['dow'] === 7 ? ' is-sun' : '' ?>">
                                    <div class="sched-h-day-badge">
                                        <span class="sched-h-day"><?= e($day['dowLabel']) ?></span>
                                        <span class="sched-h-date"><?= e($day['dateLabel']) ?></span>
                                        <span class="sched-day-count"><?= e($dayCountLabel[$day['ymd']] ?? '') ?></span>
                                        <?php if ($isToday): ?>
                                            <span class="sched-today-pill">TODAY</span>
                                        <?php endif; ?>
                                    </div>
                                </th>
                                <?php foreach ($slotRows as $slotMin): ?>
                                    <?php
                                    $slotLabel = sprintf('%02d:%02d', (int) ($slotMin / 60), $slotMin % 60);
                                    $slotEnd = $slotMin + 15;
                                    $isSatLate = ($day['dow'] === 6 && $slotMin >= 18 * 60 + 30);
                                    $isSunday = ($day['dow'] === 7);
                                    $cellLessons = [];
                                    foreach (($lessonsByDate[$day['ymd']] ?? []) as $l) {
                                        $ls = sched_min((string) $l['start_time']);
                                        $le = sched_min((string) $l['end_time']);
                                        if ($ls < $slotEnd && $le > $slotMin) {
                                            $cellLessons[] = $l;
                                        }
                                    }
                                    ?>
                                    <td class="sched-cell sched-h-cell" data-date="<?= e($day['ymd']) ?>" data-slot="<?= e($slotLabel) ?>">
                                        <?php if ($isSunday || $isSatLate): ?>
                                            <span class="sched-empty">Closed</span>
                                        <?php elseif (empty($cellLessons)): ?>
                                            <span class="sched-empty">—</span>
                                        <?php else: ?>
                                            <?php foreach ($cellLessons as $l): ?>
                                                <?php
                                                $lid = (int) $l['lesson_id'];
                                                $cancelled = ($l['status'] === 'CANCELLED');
                                                $qs = sched_qs(['lesson_id' => $lid]);
                                                $chipNames = $lessonStudents[$lid] ?? [];
                                                $chipShown = array_slice($chipNames, 0, 3);
                                                $chipMore = count($chipNames) - count($chipShown);
                                                $courseLabel = trim((string) ($l['course_name'] ?? ''));
                                                $lessonTitle = trim((string) ($l['title'] ?? ''));
                                                $chipTitle = $courseLabel !== '' ? $courseLabel : ($lessonTitle !== '' ? $lessonTitle : 'Lesson');
                                                $chipSubtitle = ($courseLabel !== '' && $lessonTitle !== '' && $lessonTitle !== $courseLabel) ? $lessonTitle : '';
                                                $chipEmpty = ((int) ($l['student_count'] ?? 0) === 0);
                                                ?>
                                                <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?><?= $chipEmpty ? ' is-empty' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs) . '#schedGrid') ?>">
                                                    <span class="sched-chip-title"><?= e($chipTitle) ?></span>
                                                    <?php if ($chipSubtitle !== ''): ?><span class="sched-chip-subtitle"><?= e($chipSubtitle) ?></span><?php endif; ?>
                                                    <span class="sched-chip-students"><?= $chipShown ? e(implode(', ', $chipShown)) . ($chipMore > 0 ? e(' +' . $chipMore . ' more') : '') : 'No students' ?></span>
                                                    <span class="sched-chip-teacher"><?= !empty($l['teacher_name']) ? e($l['teacher_name']) : 'Unassigned' ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VERTICAL GRID VIEW (Time as rows, Days as columns - toggleable) -->
        <div class="sched-view-vertical" id="schedViewVertical" style="display:none;">
            <div class="sched-v-scroll">
                <table class="sched-table">
                    <thead>
                        <tr>
                            <th>Slot</th>
                            <?php foreach ($hDays as $day): ?>
                                <?php $isToday = ($day['ymd'] === $todayYmd); ?>
                                <?php $thCls = trim(($isToday ? 'sched-today ' : '') . ($day['dow'] === 7 ? 'is-sun' : '')); ?>
                                <th<?= $thCls !== '' ? ' class="' . $thCls . '"' : '' ?><?= $isToday ? ' id="schedTodayCol"' : '' ?>><span class="sched-h-day"><?= e($day['dowLabel']) ?></span> <span class="sched-h-date"><?= e($day['dateLabel']) ?></span> <span class="sched-day-count"><?= e($dayCountLabel[$day['ymd']] ?? '') ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slotRows as $slotMin): ?>
                            <?php
                            $slotLabel = sprintf('%02d:%02d', (int) ($slotMin / 60), $slotMin % 60);
                            $slotEnd = $slotMin + 30;
                            ?>
                            <tr>
                                <td class="sched-slot"><?= e($slotLabel) ?></td>
                                <?php foreach ($hDays as $day): ?>
                                    <?php
                                    $isSatLate = ($day['dow'] === 6 && $slotMin >= 18 * 60 + 30);
                                    $isSunday = ($day['dow'] === 7);
                                    $cellLessons = [];
                                    foreach (($lessonsByDate[$day['ymd']] ?? []) as $l) {
                                        $ls = sched_min((string) $l['start_time']);
                                        $le = sched_min((string) $l['end_time']);
                                        if ($ls < $slotEnd && $le > $slotMin) {
                                            $cellLessons[] = $l;
                                        }
                                    }
                                    ?>
                                    <td class="sched-cell" data-date="<?= e($day['ymd']) ?>" data-slot="<?= e($slotLabel) ?>">
                                        <?php if ($isSunday || $isSatLate): ?>
                                            <span class="sched-empty">Closed</span>
                                        <?php elseif (empty($cellLessons)): ?>
                                            <span class="sched-empty">—</span>
                                        <?php else: ?>
                                            <?php foreach ($cellLessons as $l): ?>
                                                <?php
                                                $lid = (int) $l['lesson_id'];
                                                $cancelled = ($l['status'] === 'CANCELLED');
                                                $qs = sched_qs(['lesson_id' => $lid]);
                                                $chipNames = $lessonStudents[$lid] ?? [];
                                                $chipShown = array_slice($chipNames, 0, 3);
                                                $chipMore = count($chipNames) - count($chipShown);
                                                $courseLabel = trim((string) ($l['course_name'] ?? ''));
                                                $lessonTitle = trim((string) ($l['title'] ?? ''));
                                                $chipTitle = $courseLabel !== '' ? $courseLabel : ($lessonTitle !== '' ? $lessonTitle : 'Lesson');
                                                $chipSubtitle = ($courseLabel !== '' && $lessonTitle !== '' && $lessonTitle !== $courseLabel) ? $lessonTitle : '';
                                                $chipEmpty = ((int) ($l['student_count'] ?? 0) === 0);
                                                ?>
                                                <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?><?= $chipEmpty ? ' is-empty' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs) . '#schedGrid') ?>">
                                                    <span class="sched-chip-title"><?= e($chipTitle) ?></span>
                                                    <?php if ($chipSubtitle !== ''): ?><span class="sched-chip-subtitle"><?= e($chipSubtitle) ?></span><?php endif; ?>
                                                    <span class="sched-chip-students"><?= $chipShown ? e(implode(', ', $chipShown)) . ($chipMore > 0 ? e(' +' . $chipMore . ' more') : '') : 'No students' ?></span>
                                                    <span class="sched-chip-teacher"><?= !empty($l['teacher_name']) ? e($l['teacher_name']) : 'Unassigned' ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /.sched-main-stage -->

    <!-- Lower Workspace: Details & Editor -->
    <div class="sched-workspace-grid">
        <!-- Left: Lesson Details Panel -->
        <!-- Lesson Details removed (item 102): the Classes table substitutes it. Edit via the form deck. -->
        <!-- Right: Form Deck / Actions -->
        <div class="sched-panel sched-side">
            <?php if ($role === 'ADMIN'): ?>
                <?php if ($selectedLesson !== null): ?>
                <div class="sched-edit-head">
                    <h2><?= e($selectedLesson['title'] !== null && $selectedLesson['title'] !== '' ? $selectedLesson['title'] : 'Edit Lesson') ?></h2>
                    <button type="button" class="btn-outline-danger sched-delete-top" data-del-lesson>Delete</button>
                    <a class="sched-x-btn" href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => ''])) . '#schedGrid') ?>" title="Close edit mode and create a new lesson">×</a>
                </div>
                <p style="margin:-6px 0 12px;color:var(--text-muted,#666);font-size:13px;">Lesson #<?= (int) $selectedLesson['lesson_id'] ?> · <?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?> <?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>–<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?></p>
                <div id="schedEditMsg" class="alert" style="display:none;"></div>
                <form id="schedEditForm">
                    <input type="hidden" name="lesson_id" value="<?= (int) $selectedLesson['lesson_id'] ?>">
                    <fieldset class="sched-group">
                        <legend>Time &amp; Schedule</legend>
                        <div class="form-field"><label>Title (optional)<input type="text" name="title" maxlength="200" value="<?= e($selectedLesson['title'] ?? '') ?>"></label></div>
                        <div class="form-field"><label>Class weekday</label><input type="hidden" name="lesson_date" id="schedEditDate" value="<?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?>">
                            <div class="sched-day-pick" data-daypick-for="schedEditDate" role="group" aria-label="Pick class weekday">
                                <button type="button" class="sched-day-sq" data-dow="1" aria-pressed="false" title="Monday">M</button>
                                <button type="button" class="sched-day-sq" data-dow="2" aria-pressed="false" title="Tuesday">T</button>
                                <button type="button" class="sched-day-sq" data-dow="3" aria-pressed="false" title="Wednesday">W</button>
                                <button type="button" class="sched-day-sq" data-dow="4" aria-pressed="false" title="Thursday">T</button>
                                <button type="button" class="sched-day-sq" data-dow="5" aria-pressed="false" title="Friday">F</button>
                                <button type="button" class="sched-day-sq" data-dow="6" aria-pressed="false" title="Saturday">S</button>
                                <button type="button" class="sched-day-sq is-sun" disabled title="Sunday — closed">S</button>
                            </div>
                            <div class="sched-date-day" id="schedEditDateDay">Every <?= e(date('l', strtotime(substr((string) $selectedLesson['lesson_date'], 0, 10) . 'T12:00:00'))) ?></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-field"><label>Start (HH:MM)<input type="text" name="start_time" id="schedEditStart" value="<?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                            <div class="form-field"><label>End (HH:MM)<input type="text" name="end_time" id="schedEditEnd" value="<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                        </div>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Teacher &amp; Commission</legend>
                        <?php if (empty($teachers)): ?>
                            <span class="sched-empty">No teachers yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <div class="sched-field-head">
                                <span class="sched-field-title">Assigned teacher</span>
                                <button type="button" class="secondary-btn" id="schedEditPickTeacherBtn">Pick teacher</button>
                            </div>
                            <div id="schedPickedTeacher" class="sched-picked-single">
                                <span class="sched-empty" id="schedPickedTeacherLabel"><?= e($selectedTeacherName ?? 'Unassigned') ?></span>
                                <input type="hidden" name="teacher_id" id="schedPickedTeacherId" value="<?= (int) $selectedLesson['teacher_id'] ?>">
                                <input type="hidden" id="schedPickedTeacherName" value="<?= e($selectedTeacherName ?? '') ?>">
                            </div>
                        <?php endif; ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;">
                            <div class="form-field"><label>Commission<select name="commission_type"><option value="">Default</option><option value="PERCENT" <?= $selectedLesson['commission_type'] === 'PERCENT' ? 'selected' : '' ?>>Percent %</option><option value="FIXED" <?= $selectedLesson['commission_type'] === 'FIXED' ? 'selected' : '' ?>>Fixed RM</option></select></label></div>
                            <div class="form-field"><label>Value<input type="number" name="commission_value" min="0" step="0.01" value="<?= e($selectedLesson['commission_value'] !== null ? number_format((float) $selectedLesson['commission_value'], 2, '.', '') : '') ?>" placeholder="blank = default"></label></div>
                        </div>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Students (<?= count($selectedEnrollments) ?>)</legend>
                        <?php if (empty($students)): ?>
                            <span class="sched-empty">No students yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <table class="data-table modern-table" style="margin:0 0 8px;" aria-label="Currently enrolled students">
                                <thead><tr><th>ID</th><th>Name</th><th>Fee (RM/month)</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($selectedEnrollments as $en): ?>
                                    <tr data-enroll-id="<?= (int) $en['enrollment_id'] ?>">
                                        <td><?= e($en['student_id']) ?></td>
                                        <td><?= e($en['student_name']) ?></td>
                                        <td>RM <?= e(number_format((float) $en['fee_amount'], 2)) ?></td>
                                        <td><?= e($en['payment_status']) ?></td>
                                        <td><button type="button" class="action-btn delete-btn" data-enroll-remove="<?= (int) $en['enrollment_id'] ?>">Remove</button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="sched-field-head">
                                <span class="sched-count" id="schedEditAddCount">Add new student(s)</span>
                                <button type="button" class="secondary-btn" id="schedEditAddStudentBtn">＋ Add student</button>
                            </div>
                            <p style="color:var(--text-muted,#666);font-size:13px;margin:0;">Click the button above to pick from the available student list.</p>
                        <?php endif; ?>
                    </fieldset>
                    <button type="submit" class="primary-btn" style="width:100%;">Save Changes</button>
                </form>
                <?php if ($selectedLesson['status'] === 'COMPLETED'): ?>
                <fieldset class="sched-group" style="margin-top:12px;">
                    <legend>Repeat From This Lesson</legend>
                    <div id="schedRepeatMsg" class="alert" style="display:none;"></div>
                    <form id="schedRepeatForm">
                        <input type="hidden" name="skip_base" value="1">
                        <input type="hidden" name="join_group" value="<?= e($selectedLesson['recurrence_group'] ?? '') ?>">
                        <input type="hidden" name="base_lesson_id" value="<?= (int) $selectedLesson['lesson_id'] ?>">
                        <input type="hidden" name="title" value="<?= e($selectedLesson['title'] ?? '') ?>">
                        <input type="hidden" name="lesson_date" value="<?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?>">
                        <input type="hidden" name="start_time" value="<?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>">
                        <input type="hidden" name="end_time" value="<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?>">
                        <input type="hidden" name="teacher_id" value="<?= (int) $selectedLesson['teacher_id'] ?>">
                        <input type="hidden" name="commission_type" value="<?= e($selectedLesson['commission_type'] ?? '') ?>">
                        <input type="hidden" name="commission_value" value="<?= e($selectedLesson['commission_value'] !== null ? number_format((float) $selectedLesson['commission_value'], 2, '.', '') : '') ?>">
                        <?php foreach ($selectedEnrollments as $en): ?>
                            <input type="hidden" name="student_ids[]" value="<?= (int) $en['student_id'] ?>">
                            <input type="hidden" name="fees[<?= (int) $en['student_id'] ?>]" value="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>">
                        <?php endforeach; ?>
                        <div class="form-field"><label>Repeat every week until month<input type="text" name="repeat_until" value="<?= e(substr((string) $selectedLesson['lesson_date'], 0, 7)) ?>" placeholder="YYYY-MM" pattern="^\d{4}-\d{2}$" maxlength="7" inputmode="numeric" autocomplete="off" title="Month as YYYY-MM"></label></div>
                        <button type="submit" class="secondary-btn" style="width:100%;">Create repeats as new scheduled lessons</button>
                    </form>
                </fieldset>
                <?php endif; ?>
                <?php else: ?>
                <h2>Create Lesson</h2>
                <div id="schedCreateMsg" class="alert" style="display:none;"></div>
                <form id="schedCreateForm">
                    <fieldset class="sched-group">
                        <legend>Time &amp; Schedule</legend>
                        <div class="form-field"><label>Course<select id="schedClassPreset" name="course_id">
                            <option value="">Custom…</option>
                            <?php foreach ($coursePresets as $course): ?>
                                <option value="<?= (int)$course['course_id'] ?>" data-title="<?= e($course['course_name']) ?>" data-mode="<?= e($course['class_mode']) ?>" data-min-fee="<?= e(number_format((float)$course['minimum_fee'], 2, '.', '')) ?>" data-duration-options="<?= e($course['duration_options'] ?? '') ?>"><?= e($course['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select></label><div class="sched-date-day" id="schedPresetHint"></div></div>
                        <div class="form-field"><label>Title (optional)<input type="text" name="title" id="schedTitle" maxlength="200" placeholder="e.g. Beginner piano"></label></div>
                        <div class="form-field"><label>Class weekday</label><input type="hidden" name="lesson_date" id="schedDate" value="<?= e($defaultDate) ?>">
                            <div class="sched-day-pick" data-daypick-for="schedDate" role="group" aria-label="Pick class weekday">
                                <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'] as $dn => $dl): ?>
                                    <button type="button" class="sched-day-sq" data-dow="<?= $dn ?>" aria-pressed="false" title="<?= $dl ?>"><?= substr($dl, 0, 1) ?></button>
                                <?php endforeach; ?>
                                <button type="button" class="sched-day-sq is-sun" disabled title="Sunday — closed">S</button>
                            </div>
                            <div class="sched-date-day" id="schedDateDay">Every <?= e(date('l', strtotime($defaultDate . 'T12:00:00'))) ?></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-field"><label>Start (HH:MM)<input type="text" name="start_time" id="schedStart" value="<?= e($defaultStart) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                            <div class="form-field"><label>End (HH:MM)<input type="text" name="end_time" id="schedEnd" value="<?= e($defaultEnd) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                        </div>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Students</legend>
                        <?php if (empty($students)): ?>
                            <span class="sched-empty">No students yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <div class="sched-field-head">
                                <span class="sched-count" id="schedPickedCount">0 picked</span>
                                <button type="button" class="secondary-btn" id="schedPickStudentsBtn">＋ Add students</button>
                            </div>
                            <table class="sched-pick-table" aria-label="Picked students">
                                <thead><tr><th>ID</th><th>Name</th><th>Fee (RM/month)</th><th></th></tr></thead>
                                <tbody id="schedPickedStudents"></tbody>
                            </table>
                            <p class="sched-empty" id="schedPickedEmpty">No students picked yet.</p>
                        <?php endif; ?>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Teacher &amp; Commission</legend>
                        <?php if (empty($teachers)): ?>
                            <span class="sched-empty">No teachers yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <div class="sched-field-head">
                                <span class="sched-field-title">Assigned teacher</span>
                                <button type="button" class="secondary-btn" id="schedPickTeacherBtn">Pick teacher</button>
                            </div>
                            <div id="schedPickedTeacher" class="sched-picked-single">
                                <span class="sched-empty" id="schedPickedTeacherLabel">Unassigned</span>
                                <input type="hidden" name="teacher_id" id="schedPickedTeacherId" value="">
                                <input type="hidden" id="schedPickedTeacherName" value="">
                            </div>
                        <?php endif; ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;">
                            <div class="form-field"><label>Commission<select name="commission_type"><option value="">Default</option><option value="PERCENT">Percent %</option><option value="FIXED">Fixed RM</option></select></label></div>
                            <div class="form-field"><label>Value<input type="number" name="commission_value" min="0" step="0.01" placeholder="e.g. 20"></label></div>
                        </div>
                        <p style="margin:10px 0 4px;font-size:13px;"><a href="#" id="schedNewTeacherToggle">＋ Create new teacher account</a></p>
                        <div id="schedNewTeacherBox" style="display:none;border-top:1px solid var(--border-color,#eaeaea);padding-top:12px;margin-top:4px;">
                            <div id="schedNewTeacherMsg" class="alert" style="display:none;"></div>
                            <div class="form-field"><label>Username<input type="text" id="schedNewTeacherUsername" maxlength="100" autocomplete="off"></label></div>
                            <div class="form-field"><label>Email (optional)<input type="email" id="schedNewTeacherEmail" maxlength="100" autocomplete="off"></label></div>
                            <div class="form-field"><label>Password (min 6 characters)<input type="password" id="schedNewTeacherPassword" autocomplete="new-password"></label></div>
                            <button type="button" class="secondary-btn" id="schedNewTeacherCreate" style="width:100%;">Create teacher</button>
                        </div>
                    </fieldset>
                    <p class="sched-empty" style="margin:0 0 12px;font-size:12px;">Repeats every week until the end of the lesson's month. Dates outside working hours are skipped automatically.</p>
                    <button type="submit" class="primary-btn" style="width:100%;">Save Timetable</button>
                </form>
                <?php endif; ?>
                <datalist id="schedTimeList">
                    <?php foreach ($timeOptions as $opt): ?>
                        <option value="<?= e($opt) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php elseif ($role === 'TEACHER'): ?>
                <h2>My Availability</h2>
                <p style="color:var(--text-muted,#666);font-size:13px;">Mon–Sat only, :00/:15/:30/:45 slots. Administrators see this when scheduling your lessons.</p>
                <div id="schedAvailMsg" class="alert" style="display:none;"></div>
                <div id="schedAvailRows" style="display:flex;flex-direction:column;gap:8px;"></div>
                <div style="display:flex;gap:8px;margin-top:10px;">
                    <button type="button" class="secondary-btn" id="schedAvailAdd">＋ Add Slot</button>
                    <button type="button" class="primary-btn" id="schedAvailSave">Save Availability</button>
                </div>
            <?php else: ?>
                <h2>How Scheduling Works</h2>
                <p style="color:var(--text-muted,#666);font-size:14px;line-height:1.5;">Your enrolled piano lessons appear directly in the timetable above with time, teacher, and payment status. To request a new lesson time or adjust enrollments, speak with your teacher or the music academy administrator.</p>
            <?php endif; ?>
        </div>
    </div><!-- /.sched-workspace-grid -->
</div>

    <!-- Edit form student picker — table-based popup like create mode but no fee until added -->
<?php if ($role === 'ADMIN' && $selectedLesson !== null): ?>
<dialog class="sched-dialog sched-dialog-wide" id="schedEditStudentPicker" aria-labelledby="schedEditStudentPickerTitle">
    <div class="sched-edit-head">
        <h2 id="schedEditStudentPickerTitle">Add student</h2>
        <button type="button" class="sched-x-btn" data-series-close aria-label="Close">×</button>
    </div>
    <div class="form-field"><label>Filter students<input type="search" id="schedEditStudentFilter" placeholder="Type a name…" autocomplete="off"></label></div>
    <table class="sched-pick-table">
        <thead><tr><th>ID</th><th>Student</th><th></th></tr></thead>
        <tbody id="schedEditStudentRows"></tbody>
    </table>
</dialog>
<?php endif; ?>

<script>
(function () {
    var lessonsWeek = <?= json_encode(array_map(function ($l) {
        return [
            'date' => substr((string) ($l['lesson_date'] ?? ''), 0, 10),
            'start' => substr((string) $l['start_time'], 0, 5),
            'end' => substr((string) $l['end_time'], 0, 5),
            'teacher_id' => (int) ($l['teacher_id'] ?? 0),
        ];
    }, $lessons), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var availMap = <?= json_encode($availMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var myAvail = <?= json_encode($myAvailability, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var selectedLessonId = <?= (int) ($selectedLesson !== null ? $selectedLesson['lesson_id'] : 0) ?>;
    var schedWeekStart = <?= json_encode($weekDays[0]['ymd'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var schedWeekEnd = <?= json_encode($hLast, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function toMin(t) {
        var p = String(t).split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }
    function showMsg(id, text, ok) {
        var el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        el.className = 'alert ' + (ok ? 'success' : 'error');
        el.textContent = text;
    }

    // Issue 53: keep the sticky timetable header below the blurred site nav.
    function syncHeadOffset() {
        var nav = document.querySelector('.topnav');
        document.documentElement.style.setProperty('--sched-head-top', (nav ? nav.offsetHeight : 0) + 'px');
    }
    window.addEventListener('resize', syncHeadOffset);
    syncHeadOffset();

    // Issue 55.1: come back to the timetable instead of page top after refresh.
    function schedReload() {
        try { sessionStorage.setItem('schedScrollY', String(window.scrollY || 0)); } catch (e) {}
        window.location.reload();
    }
    (function restoreSchedScroll() {
        var y = null;
        try { y = sessionStorage.getItem('schedScrollY'); sessionStorage.removeItem('schedScrollY'); } catch (e) {}
        if (y !== null && y !== '') { window.scrollTo(0, parseInt(y, 10) || 0); }
    })();

    // Issue 88: expandable series rows (month classes panel).
    document.querySelectorAll('.sched-series-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = document.getElementById(btn.getAttribute('aria-controls'));
            if (!body) return;
            var open = body.hidden;
            body.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // Issue 90.4: class-table inline editors (admin only).
    var apiSeriesUrl = '<?= e(url_path('api/series_save.php')) ?>';
    // Use one shared popup shell for creating and editing classes.
    // The form's existing submit handler still decides whether to create or update.
    (function () {
        var openBtn = document.getElementById('schedOpenCreateDialog');
        if (!openBtn) return;

        var createForm = document.getElementById('schedCreateForm');
        var editForm = document.getElementById('schedEditForm');
        var isEditing = !!editForm;
        var form = isEditing ? editForm : createForm;

        if (!form) {
            openBtn.addEventListener('click', function () {
                var params = new URLSearchParams(window.location.search);
                params.delete('lesson_id');
                window.location.href = window.location.pathname + '?' + params.toString() + '#schedCreateForm';
            });
            return;
        }

        var side = form.closest('.sched-side');
        var msg = document.getElementById(isEditing ? 'schedEditMsg' : 'schedCreateMsg');
        var dlg = document.createElement('dialog');
        dlg.className = 'sched-dialog sched-dialog-wide sched-create-dialog';
        dlg.id = isEditing ? 'schedEditDialog' : 'schedCreateDialog';

        var head = document.createElement('div');
        head.className = 'sched-edit-head';
        head.innerHTML = '<h2>' + (isEditing ? 'Edit Class' : 'New Class') + '</h2><button type="button" class="sched-x-btn" aria-label="Close">×</button>';
        var closeButton = head.querySelector('button');
        closeButton.addEventListener('click', function () { dlg.close(); });
        // Keep Delete in the top-right header beside Close.
        if (isEditing && side) {
            var deleteButton = side.querySelector('[data-del-lesson]');
            if (deleteButton) head.insertBefore(deleteButton, closeButton);
        }
        dlg.appendChild(head);

        if (msg) dlg.appendChild(msg);

        dlg.appendChild(form);
        var timeList = document.getElementById('schedTimeList');
        if (timeList) dlg.appendChild(timeList);
        document.body.appendChild(dlg);

        if (side) side.hidden = true;
        dlg.addEventListener('click', function (ev) {
            if (ev.target === dlg) dlg.close();
        });

        openBtn.addEventListener('click', function () {
            if (isEditing) {
                // The button above the Classes table always starts a fresh class.
                var params = new URLSearchParams(window.location.search);
                params.delete('lesson_id');
                window.location.href = window.location.pathname + '?' + params.toString() + '#schedCreateForm';
                return;
            }
            if (!dlg.open) dlg.showModal();
        });

        // If this lesson is already loaded, clicking Edit only changes the URL hash
        // and does not reload the page. Open the existing dialog directly in that case.
        document.querySelectorAll('a[href*="#schedEditForm"]').forEach(function (link) {
            link.addEventListener('click', function (ev) {
                try {
                    var target = new URL(link.href, window.location.href);
                    var current = new URL(window.location.href);
                    var targetLesson = target.searchParams.get('lesson_id');
                    var currentLesson = current.searchParams.get('lesson_id');
                    if (target.pathname === current.pathname && targetLesson && targetLesson === currentLesson) {
                        ev.preventDefault();
                        if (!dlg.open) dlg.showModal();
                        history.replaceState(null, '', current.pathname + current.search);
                    }
                } catch (e) {
                    // If URL parsing fails, preserve the link's normal navigation.
                }
            });
        });

        // Open on initial navigation through an Edit/Create link.
        // Clear the hash after opening so refresh does not reopen the dialog.
        if (isEditing && window.location.hash === '#schedEditForm') {
            dlg.showModal();
            history.replaceState(null, '', window.location.pathname + window.location.search);
        } else if (!isEditing && window.location.hash === '#schedCreateForm') {
            dlg.showModal();
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    })();
    var apiUserCreateUrl = '<?= e(url_path('admin/user_create.php')) ?>';
    var apiMfeeUrl = '<?= e(url_path('api/monthly_fees_save.php')) ?>';
    function seriesEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function seriesPost(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        for (var k in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, k)) fd.append(k, extra[k]);
        }
        postForm(apiSeriesUrl, fd);
    }
    document.querySelectorAll('[data-series-close]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = b.closest('dialog');
            if (d) d.close();
        });
    });
    // Click a weekday square to move every lesson in that class series.
    document.querySelectorAll('[data-series-weekday]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group = btn.getAttribute('data-series-weekday');
            var weekday = btn.getAttribute('data-target-dow');
            var dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            PSMModal.confirm({
                title: 'Move entire class?',
                message: 'Move every lesson in this class to ' + dayNames[parseInt(weekday, 10)] + '? Lesson times, students, fees, and payment records will be preserved.',
                confirmText: 'Move class'
            }).then(function (yes) {
                if (yes) seriesPost('set_weekday', { group: group, weekday: weekday });
            });
        });
    });
    // Title rename.
    document.querySelectorAll('[data-series-title]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            PSMModal.prompt({ title: 'Rename series', label: 'Class title (1–200 characters)', value: btn.getAttribute('data-name') || '' }).then(function (v) {
                if (v === null) return;
                seriesPost('rename_series', { group: btn.getAttribute('data-series-title'), title: v });
            });
        });
    });
    // Quick-edit the time for every occurrence in a class series.
    var timeGroup = '';
    document.querySelectorAll('[data-series-time]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            timeGroup = btn.getAttribute('data-series-time') || '';
            var dlg = document.getElementById('seriesTimeDialog');
            var row = btn.closest('tr');
            var titleBtn = row ? row.querySelector('[data-series-title]') : null;
            var title = titleBtn ? (titleBtn.getAttribute('data-name') || titleBtn.textContent) : 'Class';
            document.getElementById('seriesTimeSub').textContent = title + ' — update every lesson in this class';
            document.getElementById('seriesTimeStart').value = btn.getAttribute('data-start') || '09:00';
            document.getElementById('seriesTimeEnd').value = btn.getAttribute('data-end') || '09:45';
            if (dlg && !dlg.open) dlg.showModal();
        });
    });
    var timeSave = document.getElementById('seriesTimeSave');
    if (timeSave) timeSave.addEventListener('click', function () {
        var start = document.getElementById('seriesTimeStart').value;
        var end = document.getElementById('seriesTimeEnd').value;
        if (!start || !end || start >= end) {
            PSMModal.toast('End time must be later than start time.', 'error');
            return;
        }
        seriesPost('set_time', { group: timeGroup, start_time: start, end_time: end });
    });
    // Commission editor popup (cell shows the RM due).
    var commGroup = '';
    document.querySelectorAll('[data-series-commshow]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dlg = document.getElementById('seriesCommDialog');
            if (!dlg) { PSMModal.toast('Commission editor is unavailable.', 'error'); return; }
            commGroup = btn.getAttribute('data-series-commshow');
            var row = btn.closest('tr');
            var titleButton = row ? row.querySelector('[data-series-title]') : null;
            var name = titleButton ? (titleButton.getAttribute('data-name') || titleButton.textContent) : 'Class';
            document.getElementById('seriesCommSub').textContent = name + ' — ' + btn.title;
            document.getElementById('seriesCommType').value = btn.getAttribute('data-commission-type') || '';
            document.getElementById('seriesCommValue').value = btn.getAttribute('data-commission-value') || '';
            try { if (!dlg.open) dlg.showModal(); } catch (e) { PSMModal.toast('Popup blocked: ' + e.message, 'error'); }
        });
    });
    (function () {
        var commSave = document.getElementById('seriesCommSave');
        if (commSave) commSave.addEventListener('click', function () {
            seriesPost('set_commission', {
                group: commGroup,
                commission_type: document.getElementById('seriesCommType').value,
                commission_value: document.getElementById('seriesCommValue').value
            });
        });
    })();
    // Monthly fee inputs (admin): save on change.
    document.querySelectorAll('[data-mfee]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var fd = new FormData();
            fd.append('month', inp.getAttribute('data-month'));
            fd.append('group', inp.getAttribute('data-group') || '');
            fd.append('fees[' + inp.getAttribute('data-sid') + ']', inp.value);
            fd.append('status[' + inp.getAttribute('data-sid') + ']', inp.getAttribute('data-status'));
            postForm(apiMfeeUrl, fd);
        });
    });
    // Teacher picker.
    function seriesCoverage(slots, ranges) {
        if (!slots || !slots.length) return { text: 'No info', cls: 'is-muted' };
        if (!ranges.length) return { text: slots.length + ' slot(s) set', cls: 'is-muted' };
        function covered(r) {
            return slots.some(function (s) {
                return parseInt(s.dow, 10) === r.dow && toMin(String(s.start || '')) <= toMin(r.start) && toMin(String(s.end || '')) >= toMin(r.end);
            });
        }
        if (ranges.every(covered)) return { text: 'Available', cls: 'is-ok' };
        if (ranges.some(covered)) return { text: 'Partial', cls: 'is-warn' };
        return { text: 'Not available', cls: 'is-warn' };
    }
    function openTeacherPicker(group, seriesName, ranges, currentTid) {
        var dlg = document.getElementById('seriesTeacherDialog');
        var tbody = document.getElementById('seriesTeacherRows');
        if (!dlg || !tbody) {
            PSMModal.toast('Teacher picker is unavailable on this page.', 'error');
            return;
        }
        var sub = document.getElementById('seriesTeacherSub');
        if (sub) sub.textContent = seriesName;
        tbody.innerHTML = '<tr><td colspan="5" class="sched-empty">Loading…</td></tr>';
        var box = document.getElementById('seriesNewTeacherBox');
        if (box) box.hidden = true;
        function row(t) {
            var tr = document.createElement('tr');
            if (t.id === currentTid) tr.className = 'is-current';
            var cov = t.id === 0 ? { text: '—', cls: 'is-muted' } : seriesCoverage(t.availability, ranges);
            tr.innerHTML =
                '<td>#' + t.id + '</td><td><strong>' + seriesEsc(t.username) + '</strong>' +
                (t.email ? '<span class="sched-mini-email">' + seriesEsc(t.email) + '</span>' : '') + '</td>' +
                '<td>' + seriesEsc(t.commission || 'Default') + '</td>' +
                '<td><span class="sched-pill ' + cov.cls + '">' + seriesEsc(cov.text) + '</span></td>';
            var td = document.createElement('td');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'series-btn' + (t.id === currentTid ? '' : ' is-primary');
            b.textContent = t.id === currentTid ? 'Current' : (t.id === 0 ? 'Unassign' : 'Assign');
            if (t.id === currentTid) b.disabled = true;
            b.addEventListener('click', function () {
                seriesPost('assign_teacher', { group: group, teacher_id: t.id });
            });
            td.appendChild(b);
            tr.appendChild(td);
            return tr;
        }
        var fd = new FormData();
        fd.append('action', 'get_teachers');
        fetch(apiSeriesUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    PSMModal.toast(data.error || 'Could not load teachers.', 'error');
                    return;
                }
                tbody.innerHTML = '';
                tbody.appendChild(row({ id: 0, username: 'Unassigned', email: '', commission: '', availability: [] }));
                data.data.teachers.forEach(function (t) { tbody.appendChild(row(t)); });
                if (!dlg.open) dlg.showModal();
            })
            .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
    }
    document.querySelectorAll('[data-series-teacher]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ranges = [];
            try { ranges = JSON.parse(btn.getAttribute('data-ranges') || '[]'); } catch (e) { ranges = []; }
            var cell = btn.closest('tr');
            var titleButton = cell ? cell.querySelector('[data-series-title]') : null;
            var name = titleButton ? (titleButton.getAttribute('data-name') || titleButton.textContent) : 'Class';
            openTeacherPicker(btn.getAttribute('data-series-teacher'), name, ranges, parseInt(btn.getAttribute('data-teacher') || '0', 10));
        });
    });
    // Create-teacher inside the picker (same endpoint as admin Users page).
    (function () {
        var toggle = document.getElementById('seriesNewTeacherToggle');
        var create = document.getElementById('seriesNewTeacherCreate');
        if (toggle) toggle.addEventListener('click', function () {
            var box = document.getElementById('seriesNewTeacherBox');
            if (box) box.hidden = !box.hidden;
        });
        if (create) create.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('username', (document.getElementById('seriesNewTeacherUsername') || {}).value || '');
            fd.append('email', (document.getElementById('seriesNewTeacherEmail') || {}).value || '');
            fd.append('password', (document.getElementById('seriesNewTeacherPassword') || {}).value || '');
            fd.append('role', 'TEACHER');
            fetch(apiUserCreateUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        PSMModal.toast(data.message || data.error || 'Could not create teacher.', 'error');
                        return;
                    }
                    window.location.reload();
                })
                .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        });
    })();
    // Student picker.
    function openStudentPicker(group, seriesName) {
        var dlg = document.getElementById('seriesStudentDialog');
        var tbody = document.getElementById('seriesStudentRows');
        var sub = document.getElementById('seriesStudentSub');
        var filter = document.getElementById('seriesStudentFilter');
        if (!dlg || !tbody) {
            PSMModal.toast('Student picker is unavailable on this page.', 'error');
            return;
        }
        sub.textContent = seriesName;
        if (filter) filter.value = '';
        tbody.innerHTML = '<tr><td colspan="4" class="sched-empty">Loading…</td></tr>';
        function row(s) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-search', (s.username + ' ' + (s.email || '')).toLowerCase());
            var info = '<td>#' + s.id + '</td><td><strong>' + seriesEsc(s.username) + '</strong>' +
                (s.email ? '<span class="sched-mini-email">' + seriesEsc(s.email) + '</span>' : '') +
                (s.enrolled ? ' <span class="sched-pill is-ok">Enrolled' + (s.fee !== null ? ' · RM ' + seriesEsc(s.fee) : '') + '</span>' : '') + '</td>';
            tr.innerHTML = info;
            var feeTd = document.createElement('td');
            var fee = document.createElement('input');
            fee.type = 'number';
            fee.min = '0';
            fee.step = '0.01';
            fee.value = s.fee !== null ? s.fee : '';
            fee.placeholder = '0.00';
            fee.setAttribute('aria-label', 'Fee in RM for ' + s.username);
            fee.className = 'fee-input';
            feeTd.appendChild(fee);
            var actTd = document.createElement('td');
            var act = document.createElement('button');
            act.type = 'button';
            act.className = 'series-btn' + (s.enrolled ? '' : ' is-primary');
            act.textContent = s.enrolled ? 'Update' : 'Add';
            act.addEventListener('click', function () {
                seriesPost(s.enrolled ? 'set_student_fee' : 'add_student', { group: group, student_id: s.id, fee: fee.value });
            });
            actTd.appendChild(act);
            if (s.enrolled) {
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'series-btn is-danger';
                del.textContent = 'Remove';
                del.style.marginLeft = '6px';
                del.addEventListener('click', function () {
                    PSMModal.confirm({
                        title: 'Remove student?',
                        message: 'Remove ' + s.username + ' from every lesson of this series?',
                        danger: true,
                        confirmText: 'Remove'
                    }).then(function (yes) {
                        if (yes) seriesPost('remove_student', { group: group, student_id: s.id });
                    });
                });
                actTd.appendChild(del);
            }
            tr.appendChild(feeTd);
            tr.appendChild(actTd);
            return tr;
        }
        var fd = new FormData();
        fd.append('action', 'get_students');
        fd.append('group', group);
        fetch(apiSeriesUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    PSMModal.toast(data.error || 'Could not load students.', 'error');
                    return;
                }
                tbody.innerHTML = '';
                data.data.students.forEach(function (s) { tbody.appendChild(row(s)); });
                if (!dlg.open) dlg.showModal();
            })
            .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
    }
    document.querySelectorAll('[data-series-students]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cell = btn.closest('tr');
            var titleButton = cell ? cell.querySelector('[data-series-title]') : null;
            var name = titleButton ? (titleButton.getAttribute('data-name') || titleButton.textContent) : 'Class';
            openStudentPicker(btn.getAttribute('data-series-students'), name);
        });
    });
    (function () {
        var filter = document.getElementById('seriesStudentFilter');
        if (filter) filter.addEventListener('input', function () {
            var q = filter.value.toLowerCase();
            document.querySelectorAll('#seriesStudentRows tr').forEach(function (tr) {
                var hay = tr.getAttribute('data-search') || '';
                tr.style.display = hay.indexOf(q) === -1 ? 'none' : '';
            });
        });
    })();

    // Timetable View Switcher (Horizontal Timeline vs Vertical Grid)
    var viewBtnH = document.getElementById('schedViewBtnH');
    var viewBtnV = document.getElementById('schedViewBtnV');
    var viewH = document.getElementById('schedViewHorizontal');
    var viewV = document.getElementById('schedViewVertical');

    function applyViewMode(mode) {
        if (mode === 'vertical') {
            if (viewH) viewH.style.display = 'none';
            if (viewV) viewV.style.display = 'block';
            if (viewBtnH) viewBtnH.classList.remove('active');
            if (viewBtnV) viewBtnV.classList.add('active');
            try { localStorage.setItem('psm_sched_view', 'vertical'); } catch (e) {}
            var todayCol = document.getElementById('schedTodayCol');
            if (todayCol && todayCol.scrollIntoView) {
                try { todayCol.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' }); } catch (e) {}
            }
        } else {
            if (viewH) viewH.style.display = 'block';
            if (viewV) viewV.style.display = 'none';
            if (viewBtnH) viewBtnH.classList.add('active');
            if (viewBtnV) viewBtnV.classList.remove('active');
            try { localStorage.setItem('psm_sched_view', 'horizontal'); } catch (e) {}
            var todayRow = document.getElementById('schedTodayRow');
            if (todayRow && todayRow.scrollIntoView) {
                try { todayRow.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' }); } catch (e) {}
            }
        }
        if (typeof syncGridSelection === 'function') syncGridSelection();
    }

    if (viewBtnH) viewBtnH.addEventListener('click', function () { applyViewMode('horizontal'); });
    if (viewBtnV) viewBtnV.addEventListener('click', function () { applyViewMode('vertical'); });

    // Restore saved view preference (default horizontal timeline)
    var initialView = 'horizontal';
    try { initialView = localStorage.getItem('psm_sched_view') || 'horizontal'; } catch (e) {}
    applyViewMode(initialView);

    // Issue 31: hold/drag across empty cells of one day to fill the form.
    // Mouse: press and drag to select consecutive slots. Touch/click: tap fills one slot.
    var createForm = document.getElementById('schedCreateForm');
    var editForm = document.getElementById('schedEditForm');
    function activeTimeInputs() {
        var d = document.getElementById('schedDate') || document.getElementById('schedEditDate');
        var s = document.getElementById('schedStart') || document.getElementById('schedEditStart');
        var e = document.getElementById('schedEnd') || document.getElementById('schedEditEnd');
        if (!d || !s || !e) return null;
        return { date: d, start: s, end: e };
    }
    function fmtMin(mins) {
        return String(Math.floor(mins / 60)).padStart(2, '0') + ':' + String(mins % 60).padStart(2, '0');
    }
    function fillTimeInputs(date, fromSlot, toSlot) {
        var t = activeTimeInputs();
        if (!t) return;
        var a = toMin(fromSlot), b = toMin(toSlot);
        t.date.value = date;
        t.start.value = fmtMin(Math.min(a, b));
        t.end.value = fmtMin(Math.max(a, b) + 15);
        t.start.dispatchEvent(new Event('input', { bubbles: true }));
        t.date.dispatchEvent(new Event('input', { bubbles: true }));
        t.date.dispatchEvent(new Event('change', { bubbles: true }));
        t.date.dispatchEvent(new Event('input', { bubbles: true }));
        t.date.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function paintDrag() {
        document.querySelectorAll('.sched-cell.sched-selected').forEach(function (c) { c.classList.remove('sched-selected'); });
        if (!dragState) return;
        var lo = dragState.from < dragState.to ? dragState.from : dragState.to;
        var hi = dragState.from < dragState.to ? dragState.to : dragState.from;
        document.querySelectorAll('.sched-cell').forEach(function (c) {
            if (c.getAttribute('data-date') !== dragState.date) return;
            var s = c.getAttribute('data-slot');
            if (s >= lo && s <= hi) c.classList.add('sched-selected');
        });
    }
    var dragState = null;
    var suppressClick = false;
    document.querySelectorAll('.sched-cell').forEach(function (cell) {
        if (cell.querySelector('.sched-chip') || cell.textContent.trim() === 'Closed') return;
        cell.addEventListener('pointerdown', function (ev) {
            if (ev.pointerType !== 'mouse' || ev.button !== 0 || !activeTimeInputs()) return;
            ev.preventDefault();
            dragState = { date: cell.getAttribute('data-date'), from: cell.getAttribute('data-slot'), to: cell.getAttribute('data-slot'), moved: false };
            paintDrag();
        });
        cell.addEventListener('pointerenter', function () {
            if (!dragState || dragState.date !== cell.getAttribute('data-date')) return;
            if (cell.getAttribute('data-slot') !== dragState.to) {
                dragState.to = cell.getAttribute('data-slot');
                dragState.moved = true;
                paintDrag();
            }
        });
        cell.addEventListener('click', function () {
            if (suppressClick) { suppressClick = false; return; }
            if (!activeTimeInputs()) return;
            fillTimeInputs(cell.getAttribute('data-date'), cell.getAttribute('data-slot'), cell.getAttribute('data-slot'));
        });
    });
    document.addEventListener('pointerup', function () {
        if (!dragState) return;
        var st = dragState;
        dragState = null;
        if (st.moved) {
            fillTimeInputs(st.date, st.from, st.to);
            suppressClick = true;
        }
    });

    // Issue 53 rework: one shared selection synced both ways (mouse <-> form).
    // Nothing is pre-painted: the highlight appears only after an explicit
    // selection, whether dragged on the grid or typed in the form.
    var schedTouched = false;
    function syncGridSelection() {
        document.querySelectorAll('.sched-cell.sched-selected').forEach(function (c) { c.classList.remove('sched-selected'); });
        if (dragState || !schedTouched) return;
        var t = activeTimeInputs();
        if (!t || !t.date.value) return;
        var s = t.start.value, e = t.end.value;
        if (!/^\d{2}:\d{2}$/.test(s) || !/^\d{2}:\d{2}$/.test(e) || !(e > s)) return;
        document.querySelectorAll('.sched-cell').forEach(function (c) {
            if (c.getAttribute('data-date') !== t.date.value) return;
            var slot = c.getAttribute('data-slot');
            if (slot >= s && slot < e) c.classList.add('sched-selected');
        });
    }
    ['schedDate', 'schedStart', 'schedEnd', 'schedEditDate', 'schedEditStart', 'schedEditEnd'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function () { schedTouched = true; syncGridSelection(); });
            el.addEventListener('change', function () { schedTouched = true; syncGridSelection(); });
        }
    });

    // Issue 54: show the weekday next to form dates.
    function schedDayName(ymd) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
        var d = new Date(ymd + 'T12:00:00');
        if (isNaN(d)) return '';
        return 'Every ' + d.toLocaleDateString('en-GB', { weekday: 'long' });
    }
    function wireDateDay(dateId, badgeId) {
        var di = document.getElementById(dateId), badge = document.getElementById(badgeId);
        if (!di || !badge) return;
        var upd = function () { badge.textContent = schedDayName(di.value); };
        di.addEventListener('input', upd);
        di.addEventListener('change', upd);
        upd();
    }
    wireDateDay('schedDate', 'schedDateDay');
    wireDateDay('schedEditDate', 'schedEditDateDay');

    // Issue 90: weekday squares drive their lesson date (single pick each).
    document.querySelectorAll('[data-daypick-for]').forEach(function (box) {
        var input = document.getElementById(box.getAttribute('data-daypick-for'));
        if (!input) return;
        var sqs = Array.prototype.slice.call(box.querySelectorAll('.sched-day-sq[data-dow]'));
        function paint() {
            var dow = 0;
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
            if (m) {
                var dt = new Date(input.value + 'T12:00:00');
                if (!isNaN(dt)) {
                    dow = dt.getDay();
                    dow = dow === 0 ? 7 : dow;
                }
            }
            sqs.forEach(function (b) {
                var on = parseInt(b.getAttribute('data-dow'), 10) === dow;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        sqs.forEach(function (b) {
            b.addEventListener('click', function () {
                var target = parseInt(b.getAttribute('data-dow'), 10);
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
                var base = m ? new Date(input.value + 'T12:00:00') : new Date();
                if (isNaN(base)) base = new Date();
                var cur = base.getDay();
                cur = cur === 0 ? 7 : cur;
                var dayShift = target - cur;
                if (input.id === 'schedDate' && dayShift < 0) dayShift += 7; // create the next occurrence, not a past date
                base.setDate(base.getDate() + dayShift);
                function p2(n) { return String(n).padStart(2, '0'); }
                var v = base.getFullYear() + '-' + p2(base.getMonth() + 1) + '-' + p2(base.getDate());
                if (input.value !== v) {
                    input.value = v;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                } else {
                    paint();
                }
            });
        });
        input.addEventListener('input', paint);
        paint();
    });

    // Course selection updates the title, fee hint and minimum duration immediately.
    (function () {
        var preset = document.getElementById('schedClassPreset');
        var titleInput = document.getElementById('schedTitle');
        var hint = document.getElementById('schedPresetHint');
        var startInput = document.getElementById('schedStart');
        var endInput = document.getElementById('schedEnd');
        if (!preset || !titleInput || !startInput || !endInput) return;

        function minDuration(opt) {
            var raw = opt ? (opt.getAttribute('data-duration-options') || '') : '';
            var match = raw.match(/\d+/);
            return match ? parseInt(match[0], 10) : 0;
        }
        function timeToMinutes(value) {
            var match = (value || '').match(/^(\d{1,2}):(\d{2})$/);
            return match ? Number(match[1]) * 60 + Number(match[2]) : null;
        }
        function minutesToTime(value) {
            return String(Math.floor(value / 60)).padStart(2, '0') + ':' + String(value % 60).padStart(2, '0');
        }
        function enforceMinimumDuration() {
            var opt = preset.options[preset.selectedIndex];
            var minimum = minDuration(opt);
            if (!preset.value || !minimum) return;
            var startMinutes = timeToMinutes(startInput.value);
            var endMinutes = timeToMinutes(endInput.value);
            if (startMinutes === null) return;
            // Keep an existing longer duration; only extend when shorter than the course minimum.
            if (endMinutes === null || endMinutes - startMinutes < minimum) {
                endInput.value = minutesToTime(startMinutes + minimum);
                endInput.dispatchEvent(new Event('input', { bubbles: true }));
                endInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        function applyCourse() {
            var opt = preset.options[preset.selectedIndex];
            if (!preset.value || !opt) {
                if (hint) hint.textContent = '';
                return;
            }
            var mode = opt.getAttribute('data-mode') || '';
            var courseTitle = opt.getAttribute('data-title') || '';
            var minFee = opt.getAttribute('data-min-fee') || '';
            var minimum = minDuration(opt);
            titleInput.value = courseTitle;
            if (hint) {
                hint.textContent = mode
                    + (minFee !== '' ? ' · Minimum RM ' + Number(minFee).toFixed(2) + '/month' : '')
                    + (minimum ? ' · Minimum lesson ' + minimum + ' min' : '');
            }
            var rows = document.querySelectorAll('#schedPickedStudents tr[data-sid]');
            rows.forEach(function (row) {
                var feeInput = row.querySelector('.fee-input');
                var hiddenFee = row.querySelector('input[name^="fees["]');
                if (feeInput && hiddenFee && (feeInput.value === '' || Number(feeInput.value) === 0) && Number(minFee) > 0) {
                    feeInput.value = minFee;
                    hiddenFee.value = minFee;
                }
            });
            enforceMinimumDuration();
        }
        preset.addEventListener('change', applyCourse);
        startInput.addEventListener('input', enforceMinimumDuration);
        startInput.addEventListener('change', enforceMinimumDuration);
        endInput.addEventListener('change', enforceMinimumDuration);
    })();

    // Issue 55: a form date outside the shown week moves the timetable to include it.
    // The half-typed form is stashed first so nothing is lost across the reload.
    function mondayOf(ymd) {
        var d = new Date(ymd + 'T12:00:00');
        var dow = d.getDay();
        d.setDate(d.getDate() + (dow === 0 ? -6 : 1 - dow));
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function stashForm(formId, key) {
        var f = document.getElementById(formId);
        if (!f) return;
        var data = {};
        Array.prototype.slice.call(f.querySelectorAll('input, select, textarea')).forEach(function (el) {
            if (!el.name) return;
            if (el.type === 'checkbox') {
                if (el.checked) { (data[el.name] = data[el.name] || []).push(el.value); }
            } else if (el.type === 'radio') {
                if (el.checked) data[el.name] = el.value;
            } else {
                data[el.name] = el.value;
            }
        });
        try { sessionStorage.setItem(key, JSON.stringify(data)); } catch (e) {}
    }
    function restoreForm(formId, key) {
        var f = document.getElementById(formId);
        if (!f) return;
        var raw = null;
        try { raw = sessionStorage.getItem(key); sessionStorage.removeItem(key); } catch (e) {}
        if (!raw) return;
        var data;
        try { data = JSON.parse(raw); } catch (e) { return; }
        Array.prototype.slice.call(f.querySelectorAll('input, select, textarea')).forEach(function (el) {
            if (!el.name || !(el.name in data)) return;
            var v = data[el.name];
            if (el.type === 'checkbox') {
                el.checked = Array.isArray(v) && v.indexOf(el.value) !== -1;
            } else if (el.type === 'radio') {
                el.checked = (v === el.value);
            } else {
                el.value = v;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }
    function maybeJumpToDate(dateValue, formId, draftKey) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) return;
        if (dateValue >= schedWeekStart && dateValue <= schedWeekEnd) return; // already visible
        stashForm(formId, draftKey);
        var params = new URLSearchParams(window.location.search);
        params.set('month', dateValue.slice(0, 7));
        params.set('week', mondayOf(dateValue));
        window.location.href = window.location.pathname + '?' + params.toString() + '#schedGrid';
    }
    var schedDateInput = document.getElementById('schedDate');
    if (schedDateInput) schedDateInput.addEventListener('change', function () { maybeJumpToDate(schedDateInput.value, 'schedCreateForm', 'schedDraftCreate'); });
    var schedEditDateInput = document.getElementById('schedEditDate');
    if (schedEditDateInput) schedEditDateInput.addEventListener('change', function () { maybeJumpToDate(schedEditDateInput.value, 'schedEditForm', 'schedDraftEdit'); });
    restoreForm('schedCreateForm', 'schedDraftCreate');
    restoreForm('schedEditForm', 'schedDraftEdit');

    // Issue 90: create-form staged pickers (students + teacher). Picks are
    // staged as hidden inputs (not saved) until Save Timetable is pressed.
    (function () {
        var pickedBox = document.getElementById('schedPickedStudents');
        var pickedTeacherId = document.getElementById('schedPickedTeacherId');
        var pickedTeacherName = document.getElementById('schedPickedTeacherName');
        var pickedTeacherLabel = document.getElementById('schedPickedTeacherLabel');
        function stagedRow(sid, name, fee) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-sid', sid);
            var tdId = document.createElement('td');
            tdId.textContent = '#' + sid;
            var tdName = document.createElement('td');
            var nm = document.createElement('strong');
            nm.textContent = name;
            tdName.appendChild(nm);
            var tdFee = document.createElement('td');
            var feeIn = document.createElement('input');
            feeIn.type = 'number'; feeIn.min = '0'; feeIn.step = '0.01';
            feeIn.className = 'fee-input';
            feeIn.setAttribute('aria-label', 'Fee in RM per month for ' + name);
            feeIn.value = fee;
            var h1 = document.createElement('input');
            h1.type = 'hidden'; h1.name = 'student_ids[]'; h1.value = sid;
            var h2 = document.createElement('input');
            h2.type = 'hidden'; h2.name = 'fees[' + sid + ']'; h2.value = fee;
            var h3 = document.createElement('input');
            h3.type = 'hidden'; h3.name = 'student_names[' + sid + ']'; h3.value = name;
            feeIn.addEventListener('input', function () { h2.value = feeIn.value; });
            tdFee.appendChild(feeIn); tdFee.appendChild(h1); tdFee.appendChild(h2); tdFee.appendChild(h3);
            var tdAct = document.createElement('td');
            var rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'series-btn is-danger'; rm.textContent = 'Remove';
            rm.setAttribute('aria-label', 'Remove ' + name);
            rm.addEventListener('click', function () { tr.remove(); syncPickedMeta(); });
            tdAct.appendChild(rm);
            tr.appendChild(tdId); tr.appendChild(tdName); tr.appendChild(tdFee); tr.appendChild(tdAct);
            return tr;
        }
        function paintTeacher() {
            var id = pickedTeacherId ? pickedTeacherId.value : '';
            var nm = pickedTeacherName ? pickedTeacherName.value : '';
            if (pickedTeacherLabel) {
                pickedTeacherLabel.textContent = id ? '#' + id + ' · ' + nm : 'Unassigned';
                pickedTeacherLabel.className = id ? '' : 'sched-empty';
            }
        }
        function syncPickedMeta() {
            var count = document.getElementById('schedPickedCount');
            var empty = document.getElementById('schedPickedEmpty');
            var n = pickedBox ? pickedBox.querySelectorAll('tr[data-sid]').length : 0;
            if (count) count.textContent = n + ' picked';
            if (empty) empty.style.display = n ? 'none' : '';
        }
        function rebuildStaged() {
            var form = document.getElementById('schedCreateForm');
            if (!pickedBox || !form) return;
            var data = {};
            Array.prototype.forEach.call(form.querySelectorAll('input[name="student_ids[]"]'), function (h) {
                var sid = h.value;
                if (!sid || data[sid]) return;
                var feeH = form.querySelector('input[name="fees[' + sid + ']"]');
                var nameH = form.querySelector('input[name="student_names[' + sid + ']"]');
                data[sid] = { name: nameH ? nameH.value : ('#' + sid), fee: feeH ? feeH.value : '0' };
            });
            pickedBox.innerHTML = '';
            Object.keys(data).forEach(function (sid) { pickedBox.appendChild(stagedRow(sid, data[sid].name, data[sid].fee)); });
            paintTeacher();
            syncPickedMeta();
        }
        function stagedIds() {
            var out = {};
            if (!pickedBox) return out;
            Array.prototype.forEach.call(pickedBox.querySelectorAll('input[name="student_ids[]"]'), function (h) { out[h.value] = true; });
            return out;
        }
        function openCreateStudents() {
            var dlg = document.getElementById('createStudentDialog');
            var tbody = document.getElementById('createStudentRows');
            if (!dlg || !tbody) { PSMModal.toast('Student picker is unavailable.', 'error'); return; }
            tbody.innerHTML = '<tr><td colspan="3" class="sched-empty">Loading…</td></tr>';
            var fd = new FormData();
            fd.append('action', 'get_students');
            fd.append('group', '');
            fetch(apiSeriesUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        PSMModal.toast(data.error || 'Could not load students.', 'error');
                        return;
                    }
                    var have = stagedIds();
                    tbody.innerHTML = '';
                    data.data.students.forEach(function (s) {
                        var tr = document.createElement('tr');
                        tr.setAttribute('data-search', (s.username + ' ' + (s.email || '')).toLowerCase());
                        tr.innerHTML = '<td>#' + s.id + '</td><td><strong>' + seriesEsc(s.username) + '</strong>' +
                            (s.email ? '<span class="sched-mini-email">' + seriesEsc(s.email) + '</span>' : '') + '</td>';
                        var feeTd = document.createElement('td');
                        var fee = document.createElement('input');
                        fee.type = 'number'; fee.min = '0'; fee.step = '0.01';
                        fee.className = 'fee-input'; var selectedPreset = document.getElementById('schedClassPreset'); var presetOption = selectedPreset && selectedPreset.selectedIndex >= 0 ? selectedPreset.options[selectedPreset.selectedIndex] : null; var presetMinimum = presetOption ? Number(presetOption.getAttribute('data-min-fee') || 0) : 0; fee.value = presetMinimum.toFixed(2);
                        fee.setAttribute('aria-label', 'Fee in RM for ' + s.username);
                        feeTd.appendChild(fee);
                        var actTd = document.createElement('td');
                        var add = document.createElement('button');
                        add.type = 'button'; add.className = 'series-btn is-primary'; add.textContent = 'Add';
                        if (have[String(s.id)]) { add.disabled = true; add.textContent = 'Added'; }
                        add.addEventListener('click', function () {
                            if (!pickedBox) return;
                            pickedBox.appendChild(stagedRow(String(s.id), s.username, fee.value === '' ? '0' : fee.value));
                            add.disabled = true; add.textContent = 'Added';
                            syncPickedMeta();
                        });
                        actTd.appendChild(add);
                        tr.appendChild(feeTd);
                        tr.appendChild(actTd);
                        tbody.appendChild(tr);
                    });
                    if (!dlg.open) dlg.showModal();
                })
                .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        }
        function openCreateTeacher() {
            var dlg = document.getElementById('createTeacherDialog');
            var tbody = document.getElementById('createTeacherRows');
            if (!dlg || !tbody) { PSMModal.toast('Teacher picker is unavailable.', 'error'); return; }
            var ranges = [];
            var dEl = document.getElementById('schedDate');
            var sEl = document.getElementById('schedStart');
            var eEl = document.getElementById('schedEnd');
            var dv = dEl ? dEl.value : '', sv = sEl ? sEl.value : '', ev = eEl ? eEl.value : '';
            if (/^\d{4}-\d{2}-\d{2}$/.test(dv) && /^\d{2}:\d{2}$/.test(sv) && /^\d{2}:\d{2}$/.test(ev)) {
                var dt = new Date(dv + 'T12:00:00');
                if (!isNaN(dt)) {
                    var dow = dt.getDay();
                    dow = dow === 0 ? 7 : dow;
                    if (dow !== 7) ranges = [{ dow: dow, start: sv, end: ev }];
                }
            }
            var cur = pickedTeacherId ? pickedTeacherId.value : '';
            tbody.innerHTML = '<tr><td colspan="4" class="sched-empty">Loading…</td></tr>';
            var fd = new FormData();
            fd.append('action', 'get_teachers');
            fetch(apiSeriesUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        PSMModal.toast(data.error || 'Could not load teachers.', 'error');
                        return;
                    }
                    tbody.innerHTML = '';
                    function trow(t) {
                        var tr = document.createElement('tr');
                        if (String(t.id) === String(cur) && cur !== '') tr.className = 'is-current';
                        var cov = (t.id === 0) ? { text: '—', cls: 'is-muted' } : seriesCoverage(t.availability, ranges);
                        tr.innerHTML = '<td>#' + t.id + '</td><td><strong>' + seriesEsc(t.username) + '</strong>' +
                            (t.email ? '<span class="sched-mini-email">' + seriesEsc(t.email) + '</span>' : '') + '</td>' +
                            '<td>' + seriesEsc(t.commission || 'Default') + '</td>' +
                            '<td><span class="sched-pill ' + cov.cls + '">' + seriesEsc(cov.text) + '</span></td>';
                        var td = document.createElement('td');
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'series-btn' + ((String(t.id) === String(cur) && cur !== '') ? '' : ' is-primary');
                        b.textContent = (String(t.id) === String(cur) && cur !== '') ? 'Current' : (t.id === 0 ? 'Unassign' : 'Select');
                        if (String(t.id) === String(cur) && cur !== '') b.disabled = true;
                        b.addEventListener('click', function () {
                            if (pickedTeacherId) pickedTeacherId.value = t.id === 0 ? '' : String(t.id);
                            if (pickedTeacherName) pickedTeacherName.value = t.id === 0 ? '' : t.username;
                            paintTeacher();
                            dlg.close();
                        });
                        td.appendChild(b);
                        tr.appendChild(td);
                        return tr;
                    }
                    tbody.appendChild(trow({ id: 0, username: 'Unassigned', email: '', commission: '', availability: [] }));
                    data.data.teachers.forEach(function (t) { tbody.appendChild(trow(t)); });
                    if (!dlg.open) dlg.showModal();
                })
                .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        }
        var pickS = document.getElementById('schedPickStudentsBtn');
        if (pickS) pickS.addEventListener('click', openCreateStudents);
        var pickT = document.getElementById('schedPickTeacherBtn');
        if (pickT) pickT.addEventListener('click', openCreateTeacher);
        var createFilter = document.getElementById('createStudentFilter');
        if (createFilter) createFilter.addEventListener('input', function () {
            var q = createFilter.value.toLowerCase();
            document.querySelectorAll('#createStudentRows tr').forEach(function (tr) {
                var hay = tr.getAttribute('data-search') || '';
                tr.style.display = hay.indexOf(q) === -1 ? 'none' : '';
            });
        });
        rebuildStaged();
    })();

    // Edit form teacher picker — table popup like create mode (no date/time availability).
    (function () {
        var pickET = document.getElementById('schedEditPickTeacherBtn');
        if (!pickET) return;
        var hidId = document.getElementById('schedPickedTeacherId');
        var hidNm = document.getElementById('schedPickedTeacherName');
        var labelEl = document.getElementById('schedPickedTeacherLabel');
        pickET.addEventListener('click', function () {
            var dlg = document.getElementById('createTeacherDialog');
            var tbody = document.getElementById('createTeacherRows');
            if (!dlg || !tbody) { PSMModal.toast('Teacher picker is unavailable.', 'error'); return; }
            var curId = hidId ? hidId.value : '';
            tbody.innerHTML = '<tr><td colspan="5" class="sched-empty">Loading…</td></tr>';
            var fd = new FormData();
            fd.append('action', 'get_teachers');
            fetch(apiSeriesUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        PSMModal.toast(data.error || 'Could not load teachers.', 'error');
                        return;
                    }
                    tbody.innerHTML = '';
                    function trow(t) {
                        var tr = document.createElement('tr');
                        var isCur = String(t.id) === String(curId) && curId !== '';
                        if (isCur) tr.className = 'is-current';
                        tr.innerHTML = '<td>#' + t.id + '</td><td><strong>' + seriesEsc(t.username) + '</strong>' +
                            (t.email ? '<span class="sched-mini-email">' + seriesEsc(t.email) + '</span>' : '') +
                            '<td>' + seriesEsc(t.commission || 'Default') + '</td><td><span class="sched-pill is-muted">—</span></td>';
                        var td = document.createElement('td');
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'series-btn' + (isCur ? '' : ' is-primary');
                        b.textContent = isCur ? 'Assigned' : (t.id === 0 ? 'Unassign' : 'Assign');
                        if (isCur) b.disabled = true;
                        b.addEventListener('click', function () {
                            if (hidId) hidId.value = t.id === 0 ? '' : String(t.id);
                            if (hidNm) hidNm.value = t.id === 0 ? '' : t.username;
                            if (labelEl) labelEl.textContent = t.id === 0 ? 'Unassigned' : ('#' + t.id + ' · ' + t.username);
                            if (labelEl) labelEl.className = t.id === 0 ? 'sched-empty' : '';
                            dlg.close();
                        });
                        td.appendChild(b);
                        tr.appendChild(td);
                        return tr;
                    }
                    tbody.appendChild(trow({ id: 0, username: 'Unassigned', email: '', commission: '', availability: [] }));
                    data.data.teachers.forEach(function (t) { tbody.appendChild(trow(t)); });
                    if (!dlg.open) dlg.showModal();
                })
                .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        });
    })();

    // Teacher availability + clash per table row (admin create form, issue 36).
    var dateInput = document.getElementById('schedDate');
    var startInput = document.getElementById('schedStart');
    var endInput = document.getElementById('schedEnd');
    function refreshTeacherTable() {
        var rows = document.querySelectorAll('#schedTeacherTable tbody tr');
        if (!rows.length) return;
        var d = dateInput && dateInput.value ? dateInput.value : '';
        var s = startInput && startInput.value ? startInput.value : '';
        var e2 = endInput && endInput.value ? endInput.value : '';
        var okTime = function (t) { return /^\d{2}:\d{2}$/.test(t); };
        var dowIso = 0;
        if (d) { var dw = new Date(d + 'T12:00:00').getDay(); dowIso = dw === 0 ? 7 : dw; }
        var sm = okTime(s) ? toMin(s) : null;
        var em = okTime(e2) ? toMin(e2) : null;
        rows.forEach(function (row) {
            var tid = parseInt(row.getAttribute('data-teacher'), 10);
            var av = availMap[tid] || availMap[String(tid)] || [];
            var availCell = row.querySelector('.sched-t-avail');
            var statusCell = row.querySelector('.sched-t-status');
            var dayAv = d ? av.filter(function (x) { return parseInt(x.dow, 10) === dowIso; }) : av;
            if (availCell) availCell.textContent = dayAv.length
                ? dayAv.map(function (x) { return x.start + '-' + x.end; }).join(', ')
                : (d ? '—' : (av.length ? av.length + ' slot(s) set' : 'No info'));
            var covered = d && sm !== null && em !== null && dayAv.some(function (x) { return toMin(x.start) <= sm && toMin(x.end) >= em; });
            var clash = d && sm !== null && em !== null && lessonsWeek.some(function (l) {
                return l.teacher_id === tid && l.date === d && toMin(l.start) < em && toMin(l.end) > sm;
            });
            var rank, label, cls;
            if (clash) { rank = 0; label = 'Clash'; cls = 'is-bad'; }
            else if (d && sm !== null && em !== null) {
                rank = covered ? 2 : 1;
                label = covered ? 'Available' : (av.length ? 'Not available' : 'No info');
                cls = covered ? 'is-ok' : (av.length ? 'is-warn' : 'is-muted');
            }
            else { rank = 3; label = '—'; cls = 'is-muted'; }
            row.setAttribute('data-status', String(rank));
            if (statusCell) statusCell.innerHTML = '<span class="sched-pill ' + cls + '">' + label + '</span>';
            row.classList.toggle('is-clash', !!clash);
        });
    }
    ['change', 'input'].forEach(function (ev) {
        if (dateInput) dateInput.addEventListener(ev, refreshTeacherTable);
        if (startInput) startInput.addEventListener(ev, refreshTeacherTable);
        if (endInput) endInput.addEventListener(ev, refreshTeacherTable);
    });
    refreshTeacherTable();

    // Generic click-to-sort for .sched-sortable tables (issue 36).
    document.querySelectorAll('table.sched-sortable').forEach(function (table) {
        var heads = table.querySelectorAll('thead th.sortable');
        heads.forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sortkey');
                var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
                heads.forEach(function (h) { h.removeAttribute('data-dir'); });
                th.setAttribute('data-dir', dir);
                var tbody = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var va = a.getAttribute('data-' + key) || '';
                    var vb = b.getAttribute('data-' + key) || '';
                    var na = parseFloat(va), nb = parseFloat(vb);
                    var cmp = (key !== 'name' && !isNaN(na) && !isNaN(nb)) ? (na - nb) : String(va).localeCompare(String(vb));
                    return dir === 'asc' ? cmp : -cmp;
                });
                rows.forEach(function (r) { tbody.appendChild(r); });
            });
        });
    });

    // Calendar week rows are links (issue 34).
    document.querySelectorAll('.sched-cal-week[data-href]').forEach(function (row) {
        row.addEventListener('click', function () { window.location.href = row.getAttribute('data-href'); });
        row.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); window.location.href = row.getAttribute('data-href'); }
        });
    });

    // Create submit (admin).
    if (createForm) {
        createForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var checked = createForm.querySelectorAll('input[name="student_ids[]"]:checked, #schedPickedStudents input[name="student_ids[]"]');
            if (checked.length === 0) {
                PSMModal.confirm({
                    title: 'No Students Selected',
                    message: 'This lesson has no students assigned yet. Save it as an empty lesson anyway?',
                    confirmText: 'Save Anyway'
                }).then(function (yes) {
                    if (!yes) return;
                    doCreateSubmit();
                });
                return;
            }
            doCreateSubmit();
            function doCreateSubmit() {
                var fd = new FormData(createForm);
                fd.append('action', 'create');
                var btn = createForm.querySelector('button[type="submit"]');
                btn.disabled = true; btn.textContent = 'Saving...';
                fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            var skipped = (data.data && data.data.skipped) || [];
                            if (skipped.length) {
                                PSMModal.alert({
                                    title: 'Lesson Saved — Some Dates Skipped',
                                    message: 'Saved successfully. Skipped ' + skipped.length + ' date(s) that fall outside working hours: ' + skipped.join(', ')
                                }).then(function () { schedReload(); });
                            } else {
                                schedReload();
                            }
                        }
                        else { showMsg('schedCreateMsg', data.error || 'Save failed.', false); btn.disabled = false; btn.textContent = 'Save Timetable'; }
                    })
                    .catch(function (err) { showMsg('schedCreateMsg', 'Network error: ' + err.message, false); btn.disabled = false; btn.textContent = 'Save Timetable'; });
            }
        });
    }

    // Issue 33: edit submit (admin). Reload keeps ?lesson_id so the form stays in edit mode.
    if (editForm) {
        editForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var fd = new FormData(editForm);
            fd.append('action', 'update');
            var btn = editForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Saving...';
            fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) { schedReload(); }
                    else { showMsg('schedEditMsg', data.error || 'Save failed.', false); btn.disabled = false; btn.textContent = 'Save changes'; }
                })
                .catch(function (err) { showMsg('schedEditMsg', 'Network error: ' + err.message, false); btn.disabled = false; btn.textContent = 'Save changes'; });
        });
    }

    // Issue 42: repeat a completed lesson into new scheduled lessons.
    var repeatForm = document.getElementById('schedRepeatForm');
    if (repeatForm) {
        repeatForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var fd = new FormData(repeatForm);
            fd.append('action', 'create');
            var btn = repeatForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Creating...';
            fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var skipped = (data.data && data.data.skipped) || [];
                        var params = new URLSearchParams(window.location.search);
                        params.set('lesson_id', String((data.data.lesson_ids || [])[0] || ''));
                        var nextUrl = window.location.pathname + '?' + params.toString() + '#schedGrid';
                        if (skipped.length) {
                            PSMModal.alert({
                                title: 'Repeats Created — Some Dates Skipped',
                                message: 'Repeating lessons created successfully. Skipped ' + skipped.length + ' date(s) outside working hours: ' + skipped.join(', ')
                            }).then(function () { window.location.href = nextUrl; });
                        } else {
                            window.location.href = nextUrl;
                        }
                    }
                    else { showMsg('schedRepeatMsg', data.error || 'Repeat failed.', false); btn.disabled = false; btn.textContent = 'Create repeats as new scheduled lessons'; }
                })
                .catch(function (err) { showMsg('schedRepeatMsg', 'Network error: ' + err.message, false); btn.disabled = false; btn.textContent = 'Create repeats as new scheduled lessons'; });
        });
    }

    // Detail actions (admin).
    function postForm(url, fd, onOk) {
        fetch(url, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { schedReload(); }
                else { PSMModal.toast(data.error || 'Action failed. Please try again.', 'error'); }
            })
            .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
    }
    document.querySelectorAll('[data-del-lesson]').forEach(function (delBtn) {
        delBtn.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Delete Lesson?',
                message: 'This will permanently delete this lesson and all its student enrollments. This action cannot be undone.',
                danger: true,
                confirmText: 'Delete Lesson'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData(); fd.append('action', 'delete'); fd.append('lesson_id', String(selectedLessonId));
                postForm('<?= e(url_path('api/lessons_save.php')) ?>', fd);
            });
        });
    });
    // Issue 50: delete every lesson of the repeat series at once.
    var delSeriesBtn = document.getElementById('schedDeleteSeriesBtn');
    if (delSeriesBtn) delSeriesBtn.addEventListener('click', function () {
        var label = delSeriesBtn.textContent || 'this series';
        PSMModal.confirm({
            title: 'Delete Entire Series?',
            message: 'Delete ' + label.trim() + ' and all their enrollments? This action cannot be undone.',
            danger: true,
            confirmText: 'Delete Series'
        }).then(function (yes) {
            if (!yes) return;
            var fd = new FormData(); fd.append('action', 'delete_group'); fd.append('lesson_id', String(selectedLessonId));
            fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var params = new URLSearchParams(window.location.search);
                        params.delete('lesson_id');
                        window.location.href = window.location.pathname + '?' + params.toString() + '#schedGrid';
                    } else { PSMModal.toast(data.error || 'Failed to delete series.', 'error'); }
                })
                .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        });
    });
    // Edit form student picker — table-based popup, no fee until added.
    var _editPickedSids = [];
    var editPickStudentBtn = document.getElementById('schedEditAddStudentBtn');
    if (editPickStudentBtn) editPickStudentBtn.addEventListener('click', function () {
        var dlg = document.getElementById('schedEditStudentPicker');
        var tbody = document.getElementById('schedEditStudentRows');
        if (!dlg || !tbody) { PSMModal.toast('Student picker is unavailable.', 'error'); return; }
        tbody.innerHTML = '<tr><td colspan="3" class="sched-empty">Loading…</td></tr>';
        var fd = new FormData();
        fd.append('action', 'get_students');
        fd.append('group', '');
        fetch(apiSeriesUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    PSMModal.toast(data.error || 'Could not load students.', 'error');
                    return;
                }
                var curEnrolledSet = {};
                <?php foreach ($selectedEnrollments as $en): ?>
                curEnrolledSet[<?= (int) $en['student_id'] ?>] = true;
                <?php endforeach; ?>
                data.data.students.forEach(function (s) {
                    if (curEnrolledSet[s.id]) return;
                    var tr = document.createElement('tr');
                    tr.setAttribute('data-search', (s.username + ' ' + (s.email || '')).toLowerCase());
                    tr.innerHTML = '<td>#' + s.id + '</td><td><strong>' + seriesEsc(s.username) + '</strong>' +
                        (s.email ? '<span class="sched-mini-email">' + seriesEsc(s.email) + '</span>' : '') + '</td>';
                    var actTd = document.createElement('td');
                    var addBtn = document.createElement('button');
                    addBtn.type = 'button'; addBtn.className = 'series-btn is-primary'; addBtn.textContent = 'Add';
                    addBtn.addEventListener('click', function () {
                        if (!dlg) return; dlg.close();
                        insertEditStudentRow(s.id, s.username);
                    });
                    actTd.appendChild(addBtn);
                    tr.appendChild(actTd);
                    tbody.appendChild(tr);
                });
                if (tbody.children.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="3" class="sched-empty">All students are already enrolled.</td></tr>';
                }
                if (!dlg.open) dlg.showModal();
            })
            .catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
    });
    // Filter inside edit student picker
    (function () {
        var filter = document.getElementById('schedEditStudentFilter');
        if (!filter) return;
        filter.addEventListener('input', function () {
            var q = filter.value.toLowerCase();
            document.querySelectorAll('#schedEditStudentRows tr').forEach(function (tr) {
                var hay = tr.getAttribute('data-search') || '';
                tr.style.display = hay.indexOf(q) === -1 ? 'none' : '';
            });
        });
    })();

    // Insert a student row into the edit form's enrolled-students table (editable fee).
    function insertEditStudentRow(sid, name) {
        var sidInt = parseInt(sid, 10);
        if (_editPickedSids.indexOf(sidInt) !== -1) {
            PSMModal.toast(name + ' has already been picked for this lesson.', 'info');
            return;
        }
        var tbody = document.querySelector('[aria-label="Currently enrolled students"] tbody');
        if (!tbody) return;
        var existing = tbody.querySelector('[data-edit-pick-id="' + sid + '"]');
        if (existing) { PSMModal.toast(name + ' is already being edited.', 'info'); return; }

        var tr = document.createElement('tr');
        tr.setAttribute('data-edit-pick-id', String(sid));
        tr.setAttribute('data-search', (name).toLowerCase());
        tr.innerHTML = '<td>#' + sid + '</td>' +
            '<td><strong>' + seriesEsc(name) + '</strong></td>' +
            '<td><input type="number" class="fee-input" min="0" step="0.01" value="0" aria-label="Fee for ' + seriesEsc(name) + '" style="width:110px;"></td>' +
            '<td>—</td>' +
            '<td><button type="button" class="series-btn is-primary btn-save-fee" data-pick-sid="' + sid + '">Save</button> ' +
            '<button type="button" class="action-btn delete-btn btn-cancel-add" data-pick-sid="' + sid + '">Cancel</button></td>';
        tbody.appendChild(tr);
        _editPickedSids.push(sidInt);

        var legend = document.querySelector('.sched-group:has(#schedEditAddStudentBtn) legend');
        if (legend) {
            var n = tbody.querySelectorAll('tr[data-enroll-id], tr[data-edit-pick-id]').length;
            legend.textContent = 'Students (' + n + ')';
        }

        // Wire save/cancel on dynamic buttons
        var saveBtn = tr.querySelector('.btn-save-fee');
        if (saveBtn) saveBtn.addEventListener('click', function () {
            var feeInput = tr.querySelector('.fee-input');
            var feeVal = feeInput ? (parseFloat(feeInput.value) || 0) : 0;
            saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
            var f = new FormData();
            f.append('action', 'add'); f.append('lesson_id', String(selectedLessonId));
            f.append('student_id', String(sid)); f.append('fee_amount', feeVal);
            postForm('<?= e(url_path('api/enrollments_save.php')) ?>', f).then(function () {
                tr.removeAttribute('data-edit-pick-id');
                tr.querySelector('td:nth-child(3)').textContent = 'RM ' + Number(feeVal).toFixed(2);
                var cancelBtn = tr.querySelector('.btn-cancel-add');
                if (cancelBtn) cancelBtn.remove();
                saveBtn.textContent = 'Saved'; saveBtn.disabled = true;
                _editPickedSids = _editPickedSids.filter(function (x) { return x !== sidInt; });
                PSMModal.toast(name + ' enrolled at RM ' + Number(feeVal).toFixed(2) + '.', 'success');
            }).catch(function (err) {
                saveBtn.disabled = false; saveBtn.textContent = 'Save';
                PSMModal.toast(err && err.message ? err.message : 'Failed to enroll.', 'error');
            });
        });

        var cancelBtn = tr.querySelector('.btn-cancel-add');
        if (cancelBtn) cancelBtn.addEventListener('click', function () {
            tr.remove();
            _editPickedSids = _editPickedSids.filter(function (x) { return x !== sidInt; });
            var legend = document.querySelector('.sched-group:has(#schedEditAddStudentBtn) legend');
            if (legend) {
                var n = tbody.querySelectorAll('tr[data-enroll-id]').length;
                legend.textContent = 'Students (' + n + ')';
            }
        });
    }
    // Issue 65 follow-up: create a teacher account without leaving the lesson form.
    var newTToggle = document.getElementById('schedNewTeacherToggle');
    var newTBox = document.getElementById('schedNewTeacherBox');
    if (newTToggle && newTBox) newTToggle.addEventListener('click', function (ev) {
        ev.preventDefault();
        var open = newTBox.style.display === 'none';
        newTBox.style.display = open ? 'block' : 'none';
        newTToggle.textContent = open ? '– Hide new teacher form' : '＋ Create new teacher account';
    });
    var newTBtn = document.getElementById('schedNewTeacherCreate');
    if (newTBtn) newTBtn.addEventListener('click', function () {
        var u = document.getElementById('schedNewTeacherUsername').value.trim();
        var m = document.getElementById('schedNewTeacherEmail').value.trim();
        var p = document.getElementById('schedNewTeacherPassword').value;
        var msg = document.getElementById('schedNewTeacherMsg');
        function newTMsg(text, ok) {
            msg.style.display = 'block';
            msg.className = 'alert ' + (ok ? 'success' : 'error');
            msg.textContent = text;
        }
        if (u === '' || p === '') { newTMsg('Username and password are required (email may be left blank).', false); return; }
        newTBtn.disabled = true; newTBtn.textContent = 'Creating...';
        var fd = new FormData();
        fd.append('username', u); fd.append('email', m); fd.append('password', p); fd.append('role', 'TEACHER');
        fetch('<?= e(url_path('admin/user_create.php')) ?>', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                newTBtn.disabled = false; newTBtn.textContent = 'Create teacher';
                if (data.success && data.user) {
                    var tbody = document.querySelector('#schedTeacherTable tbody');
                    if (tbody) {
                        var tr = document.createElement('tr');
                        tr.setAttribute('data-teacher', String(data.user.user_id));
                        tr.setAttribute('data-name', String(data.user.username).toLowerCase());
                        tr.setAttribute('data-status', '3');
                        var radio = document.createElement('input');
                        radio.type = 'radio'; radio.name = 'teacher_id';
                        radio.value = String(data.user.user_id); radio.checked = true;
                        var lab = document.createElement('label');
                        lab.className = 'sched-pick-label';
                        lab.appendChild(radio);
                        lab.appendChild(document.createTextNode(' ' + data.user.username));
                        var tdName = document.createElement('td'); tdName.appendChild(lab);
                        var tdAv = document.createElement('td');
                        tdAv.className = 'sched-t-avail sched-empty'; tdAv.textContent = 'No info';
                        var tdSt = document.createElement('td');
                        tdSt.className = 'sched-t-status';
                        tdSt.innerHTML = '<span class="sched-pill is-muted">—</span>';
                        tr.appendChild(tdName); tr.appendChild(tdAv); tr.appendChild(tdSt);
                        tbody.appendChild(tr);
                    }
                    document.getElementById('schedNewTeacherUsername').value = '';
                    document.getElementById('schedNewTeacherEmail').value = '';
                    document.getElementById('schedNewTeacherPassword').value = '';
                    refreshTeacherTable();
                    newTMsg('Teacher account created and selected.', true);
                } else {
                    newTMsg(data.message || 'Account creation failed.', false);
                }
            })
            .catch(function (err) {
                newTBtn.disabled = false; newTBtn.textContent = 'Create teacher';
                newTMsg('Network error: ' + err.message, false);
            });
    });
    document.querySelectorAll('[data-enroll-remove]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Remove Student?',
                message: 'Remove this student from the lesson? Their enrollment record will be deleted.',
                danger: true,
                confirmText: 'Remove Student'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData(); fd.append('action', 'remove'); fd.append('enrollment_id', b.getAttribute('data-enroll-remove'));
                postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
            });
        });
    });
    document.querySelectorAll('[data-enroll-fee]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.prompt({
                title: 'Edit Student Fee',
                label: 'New fee amount for this student:',
                type: 'number',
                value: b.getAttribute('data-fee') || '0',
                placeholder: '0.00',
                confirmText: 'Save Fee'
            }).then(function (v) {
                if (v === null) return;
                var fd = new FormData(); fd.append('action', 'set_fee'); fd.append('enrollment_id', b.getAttribute('data-enroll-fee')); fd.append('fee_amount', v);
                postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
            });
        });
    });

    // Issue 57: whole series rows navigate to that schedule date.
    document.querySelectorAll('.sched-series-table tr[data-href]').forEach(function (row) {
        row.addEventListener('click', function () { window.location.href = row.getAttribute('data-href'); });
    });

    // Issue 60: click-to-edit fees and option-select statuses.
    function inlineFeeEdit(cell, current, onSave) {
        if (cell.querySelector('input')) return;
        var orig = cell.textContent;
        cell.textContent = '';
        var input = document.createElement('input');
        input.type = 'number'; input.min = '0'; input.step = '0.01'; input.value = current;
        input.className = 'sched-fee-input';
        input.style.width = '100%';
        input.setAttribute('aria-label', 'Fee in RM');
        cell.appendChild(input);
        input.focus();
        if (input.select) input.select();
        var done = false;
        function finish(save) {
            if (done) return; done = true;
            if (save && input.value !== '' && !isNaN(parseFloat(input.value)) && parseFloat(input.value) >= 0) {
                if (onSave(parseFloat(input.value).toFixed(2)) === false) { cell.textContent = orig; }
            } else {
                cell.textContent = orig;
            }
        }
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { finish(false); }
            ev.stopPropagation();
        });
        input.addEventListener('blur', function () { finish(true); });
        input.addEventListener('click', function (ev) { ev.stopPropagation(); });
    }
    function inlineStatusPick(cell, options, current, onPick) {
        if (cell.querySelector('select')) return;
        var orig = cell.innerHTML;
        var sel = document.createElement('select');
        sel.className = 'sched-inline-select';
        sel.setAttribute('aria-label', 'Status');
        options.forEach(function (o) {
            var op = document.createElement('option'); op.value = o; op.textContent = o;
            if (o === current) op.selected = true;
            sel.appendChild(op);
        });
        cell.innerHTML = '';
        cell.appendChild(sel);
        sel.focus();
        var done = false;
        function close(restore) { if (done) return; done = true; if (restore) cell.innerHTML = orig; }
        sel.addEventListener('change', function () { done = true; onPick(sel.value); });
        sel.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { ev.stopPropagation(); close(true); } ev.stopPropagation(); });
        sel.addEventListener('blur', function () { setTimeout(function () { close(true); }, 150); });
        sel.addEventListener('click', function (ev) { ev.stopPropagation(); });
    }
    var apiLessonsUrl = '<?= e(url_path('api/lessons_save.php')) ?>';
    var apiEnrollUrl = '<?= e(url_path('api/enrollments_save.php')) ?>';
    document.querySelectorAll('[data-enroll-fee-cell]').forEach(function (cell) {
        cell.addEventListener('click', function (ev) {
            ev.stopPropagation();
            inlineFeeEdit(cell, cell.getAttribute('data-fee') || '0', function (v) {
                var fd = new FormData(); fd.append('action', 'set_fee');
                fd.append('enrollment_id', cell.getAttribute('data-enroll-fee-cell'));
                fd.append('fee_amount', v);
                postForm(apiEnrollUrl, fd);
            });
        });
    });
    document.querySelectorAll('[data-lesson-fee]').forEach(function (cell) {
        cell.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var n = parseInt(cell.getAttribute('data-count') || '0', 10);
            if (!n) { PSMModal.toast('No enrolled students in this lesson.', 'info'); return; }
            inlineFeeEdit(cell, cell.getAttribute('data-fee') || '0', function (v) {
                PSMModal.confirm({
                    title: 'Set Fee for All Students?',
                    message: 'Set RM ' + v + ' as the fee for all ' + n + ' enrolled student(s) in this lesson?',
                    confirmText: 'Set Fee'
                }).then(function (yes) {
                    if (!yes) return false;
                    var fd = new FormData(); fd.append('action', 'set_lesson_fees');
                    fd.append('lesson_id', cell.getAttribute('data-lesson-fee'));
                    fd.append('fee_amount', v);
                    postForm(apiEnrollUrl, fd);
                });
                return true; // prevent inlineFeeEdit from cancelling
            });
        });
    });
    document.querySelectorAll('[data-lesson-status]').forEach(function (cell) {
        cell.addEventListener('click', function (ev) {
            ev.stopPropagation();
            inlineStatusPick(cell, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], cell.getAttribute('data-status') || 'SCHEDULED', function (v) {
                var fd = new FormData(); fd.append('action', 'update');
                fd.append('lesson_id', cell.getAttribute('data-lesson-status'));
                fd.append('status', v);
                postForm(apiLessonsUrl, fd);
            });
        });
    });

    // Teacher availability editor.
    var availRows = document.getElementById('schedAvailRows');
    function availRow(slot) {
        slot = slot || { dow: 1, start: '09:00', end: '12:00' };
        var div = document.createElement('div');
        div.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:6px;align-items:center;';
        var days = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat']];
        var sel = document.createElement('select');
        days.forEach(function (d) {
            var o = document.createElement('option'); o.value = d[0]; o.textContent = d[1];
            if (String(slot.dow) === d[0]) o.selected = true;
            sel.appendChild(o);
        });
        var s = document.createElement('input'); s.type = 'time'; s.step = '900'; s.value = slot.start || '09:00';
        var e = document.createElement('input'); e.type = 'time'; e.step = '900'; e.value = slot.end || '12:00';
        var x = document.createElement('button'); x.type = 'button'; x.className = 'action-btn delete-btn'; x.textContent = '×';
        x.addEventListener('click', function () { div.remove(); });
        div.appendChild(sel); div.appendChild(s); div.appendChild(e); div.appendChild(x);
        div._get = function () { return { dow: parseInt(sel.value, 10), start: s.value, end: e.value }; };
        return div;
    }
    if (availRows) {
        (myAvail && myAvail.length ? myAvail : [{ dow: 1, start: '09:00', end: '12:00' }]).forEach(function (s) { availRows.appendChild(availRow(s)); });
        document.getElementById('schedAvailAdd').addEventListener('click', function () { availRows.appendChild(availRow()); });
        document.getElementById('schedAvailSave').addEventListener('click', function () {
            var slots = Array.prototype.slice.call(availRows.children).map(function (r) { return r._get(); });
            fetch('<?= e(url_path('api/save_settings.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: { availability: slots } })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) { showMsg('schedAvailMsg', data.success ? 'Availability saved.' : (data.message || 'Save failed.'), !!data.success); })
                .catch(function (err) { showMsg('schedAvailMsg', 'Network error: ' + err.message, false); });
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
