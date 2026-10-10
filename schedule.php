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
$selectedLessonId = (int) ($_GET['lesson_id'] ?? 0);

// ---- issue 29: create-form defaults (next open day, next :00/:30 slot, +1 hour) ----
$todayYmd = date('Y-m-d');
$nowMin = (int) date('G') * 60 + (int) date('i');
$nextSlotToday = (int) (floor($nowMin / 30) + 1) * 30; // strictly next boundary
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
    if ($openStart + 60 <= $closeMin) {
        $defaultDate = $probeDate;
        $defaultStartMin = $openStart;
        break;
    }
}
$defaultStart = sprintf('%02d:%02d', (int) ($defaultStartMin / 60), $defaultStartMin % 60);
$defaultEndMin = $defaultStartMin + 60;
$defaultEnd = sprintf('%02d:%02d', (int) ($defaultEndMin / 60), $defaultEndMin % 60);
// half-hour options for the typable time datalist (issue 29)
$timeOptions = [];
for ($m = 9 * 60; $m <= 22 * 60; $m += 30) {
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

// ---- timetable slot rows (30-min, 09:00–22:00) ----
$slotRows = [];
for ($m = 9 * 60; $m < 22 * 60; $m += 30) {
    $slotRows[] = $m;
}
function sched_min(string $t): int
{
    $t = substr(trim($t), 0, 5);
    $p = explode(':', $t);
    return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
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
                <?php if ($role === 'ADMIN'): ?>30-min slots · weekday 9am–10pm · Sat 9am–6:30pm · closed Sun. Drag across empty cells to schedule lessons; click any lesson chip for details and fees.<?php endif; ?>
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

    <!-- Controls Deck: Mini-Calendar & Filters -->
    <div class="sched-controls-deck">
        <div class="sched-panel sched-cal-panel">
            <div class="sched-cal-head">
                <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $prevMonth, 'week' => '', 'lesson_id' => ''])) . '#schedGrid') ?>" aria-label="Previous month">‹ Prev</a>
                <strong><?= e(date('F Y', strtotime($monthStart . 'T12:00:00'))) ?></strong>
                <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $nextMonth, 'week' => '', 'lesson_id' => ''])) . '#schedGrid') ?>" aria-label="Next month">Next ›</a>
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

    <!-- Main Stage: Weekly Timetable -->
    <div class="sched-panel sched-main-stage" id="schedGrid" role="region" aria-label="Weekly timetable">
        <div class="sched-stage-header">
            <div class="sched-week-nav">
                <a class="btn secondary-btn" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => substr($prevWeekMonday, 0, 7), 'week' => $prevWeekMonday, 'lesson_id' => ''])) . '#schedGrid') ?>" title="Previous week">‹ Prev Week</a>
                <div class="sched-week-title">
                    <span class="sched-week-icon" aria-hidden="true">📅</span>
                    <strong>Week of <?= e($weekLabel) ?></strong>
                    <span class="sched-week-chip">Week <?= $weekOfMonth ?> of <?= e($weekOfMonthName) ?></span>
                </div>
                <a class="btn secondary-btn" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => substr($nextWeekMonday, 0, 7), 'week' => $nextWeekMonday, 'lesson_id' => ''])) . '#schedGrid') ?>" title="Next week">Next Week ›</a>
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

        <!-- HORIZONTAL TIMELINE VIEW (Days as rows, Time as columns) -->
        <div class="sched-view-horizontal" id="schedViewHorizontal">
            <div class="sched-h-scroll">
                <table class="sched-h-table">
                    <thead>
                        <tr>
                            <th class="sched-h-corner">Day / Time</th>
                            <?php foreach ($slotRows as $slotMin): ?>
                                <?php $slotLabel = sprintf('%02d:%02d', (int) ($slotMin / 60), $slotMin % 60); ?>
                                <th class="sched-h-time-head"><?= e($slotLabel) ?></th>
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
                                        <?php if ($isToday): ?>
                                            <span class="sched-today-pill">TODAY</span>
                                        <?php endif; ?>
                                    </div>
                                </th>
                                <?php foreach ($slotRows as $slotMin): ?>
                                    <?php
                                    $slotLabel = sprintf('%02d:%02d', (int) ($slotMin / 60), $slotMin % 60);
                                    $slotEnd = $slotMin + 30;
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
                                                $chipDur = sched_min((string) $l['end_time']) - sched_min((string) $l['start_time']);
                                                $chipTitle = $l['title'] !== null && $l['title'] !== '' ? $l['title'] : 'Lesson';
                                                $chipStCls = $l['status'] === 'COMPLETED' ? 'is-ok' : ($l['status'] === 'CANCELLED' ? 'is-bad' : 'is-muted');
                                                $chipEmpty = ((int) ($l['student_count'] ?? 0) === 0);
                                                ?>
                                                <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?><?= $chipEmpty ? ' is-empty' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs) . '#schedGrid') ?>">
                                                    <span class="sched-chip-time"><?= e(substr((string) $l['start_time'], 0, 5)) ?>–<?= e(substr((string) $l['end_time'], 0, 5)) ?> · <?= $chipDur ?>m · <?= e($chipTitle) ?></span>
                                                    <span class="sched-chip-students"><?= $chipShown ? e(implode(', ', $chipShown)) . ($chipMore > 0 ? e(' +' . $chipMore . ' more') : '') : 'No students' ?></span>
                                                    <span class="sched-chip-teacher"><?= !empty($l['teacher_name']) ? e($l['teacher_name']) : 'Unassigned' ?></span>
                                                    <span class="sched-chip-status <?= $chipStCls ?>"><?= e($l['status']) ?></span>
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
                                <th<?= $thCls !== '' ? ' class="' . $thCls . '"' : '' ?><?= $isToday ? ' id="schedTodayCol"' : '' ?>><span class="sched-h-day"><?= e($day['dowLabel']) ?></span> <span class="sched-h-date"><?= e($day['dateLabel']) ?></span></th>
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
                                                $chipDur = sched_min((string) $l['end_time']) - sched_min((string) $l['start_time']);
                                                $chipTitle = $l['title'] !== null && $l['title'] !== '' ? $l['title'] : 'Lesson';
                                                $chipStCls = $l['status'] === 'COMPLETED' ? 'is-ok' : ($l['status'] === 'CANCELLED' ? 'is-bad' : 'is-muted');
                                                $chipEmpty = ((int) ($l['student_count'] ?? 0) === 0);
                                                ?>
                                                <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?><?= $chipEmpty ? ' is-empty' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs) . '#schedGrid') ?>">
                                                    <span class="sched-chip-time"><?= e(substr((string) $l['start_time'], 0, 5)) ?>–<?= e(substr((string) $l['end_time'], 0, 5)) ?> · <?= $chipDur ?>m · <?= e($chipTitle) ?></span>
                                                    <span class="sched-chip-students"><?= $chipShown ? e(implode(', ', $chipShown)) . ($chipMore > 0 ? e(' +' . $chipMore . ' more') : '') : 'No students' ?></span>
                                                    <span class="sched-chip-teacher"><?= !empty($l['teacher_name']) ? e($l['teacher_name']) : 'Unassigned' ?></span>
                                                    <span class="sched-chip-status <?= $chipStCls ?>"><?= e($l['status']) ?></span>
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
        <div class="sched-panel sched-detail-panel" id="schedDetailPanel">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <h2 style="margin:0;">Lesson Details</h2>
                <?php if ($selectedLesson !== null): ?>
                    <a href="<?= e(url_path('transactions.php?lesson_id=' . (int) $selectedLesson['lesson_id'])) ?>" class="sched-txn-btn" style="padding:5px 12px;font-size:12.5px;">
                        <span aria-hidden="true">💳</span> View Lesson in Transactions
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($selectedLesson === null): ?>
                <p style="color:var(--text-muted,#666);font-size:13.5px;">Select any lesson chip in the timetable above to view student enrollments, fees, payment statuses, and teacher commission.</p>
            <?php else: ?>
                <?php
                $sl = $selectedLesson;
                $payout = $lessonsManager !== null
                    ? $lessonsManager->commissionPayout($resolvedCommission[0], (float) $resolvedCommission[1], (float) ($selectedTotals['total_fee'] ?? 0))
                    : 0.0;
                $glFirst = $groupSummary[0] ?? null;
                $glNext = null;
                foreach ($groupSummary as $gs) {
                    if ($gs['status'] === 'SCHEDULED' && strtotime($gs['lesson_date'] . ' ' . $gs['end_time']) >= time()) {
                        $glNext = $gs;
                        break;
                    }
                }
                ?>
                <table class="sched-detail-table">
                    <tbody>
                        <tr><th>Lesson</th><td><strong><?= e($sl['title'] !== null && $sl['title'] !== '' ? $sl['title'] : 'Lesson') ?></strong> <span class="role-badge role-student"><?= e($sl['status']) ?></span></td></tr>
                        <tr><th>Date</th><td><?= e(substr((string) $sl['lesson_date'], 0, 10)) ?> · <?= e(substr((string) $sl['start_time'], 0, 5)) ?>–<?= e(substr((string) $sl['end_time'], 0, 5)) ?></td></tr>
                        <tr><th>Teacher</th><td><?= !empty($sl['teacher_name']) ? e($sl['teacher_name']) : 'Unassigned' ?></td></tr>
                        <tr><th>Fees</th><td><?= (int) ($selectedTotals['student_count'] ?? 0) ?> student(s) · Total RM <?= e(number_format((float) ($selectedTotals['total_fee'] ?? 0), 2)) ?> · Paid RM <?= e(number_format((float) ($selectedTotals['paid_total'] ?? 0), 2)) ?> · Unpaid <?= (int) ($selectedTotals['unpaid_count'] ?? 0) ?></td></tr>
                        <tr><th>Comm.</th><td><?= e($resolvedCommission[0]) ?> <?= e(number_format((float) $resolvedCommission[1], 2)) ?> → RM <?= e(number_format($payout, 2)) ?> (<?= e($sl['commission_status']) ?>)</td></tr>
                        <?php if (!empty($groupSummary)): ?>
                        <tr><th>Series</th><td>#<?= e(substr((string) $sl['recurrence_group'], 0, 8)) ?> · <?= count($groupSummary) ?> lessons · First <?= $glFirst ? e(substr((string) $glFirst['lesson_date'], 0, 10)) : '—' ?> · Next <?= $glNext ? e(substr((string) $glNext['lesson_date'], 0, 10)) : '—' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($groupSummary)): ?>
                <table class="sched-detail-table sched-series-table">
                    <thead><tr><th>Date</th><th>Time</th><th>Students</th><th>Fee</th><th>Paid</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($groupSummary as $gs): ?>
                            <?php
                            $gsDow = (int) date('N', strtotime($gs['lesson_date'] . 'T12:00:00'));
                            $gsMon = date('Y-m-d', strtotime($gs['lesson_date'] . ' -' . ($gsDow - 1) . ' days'));
                            $gsUrl = url_path('schedule.php?' . sched_qs(['month' => substr((string) $gs['lesson_date'], 0, 7), 'week' => $gsMon, 'lesson_id' => (int) $gs['lesson_id']])) . '#schedGrid';
                            ?>
                            <?php $gsCls = trim(((int) $gs['lesson_id'] === (int) $sl['lesson_id'] ? 'is-this ' : '') . ((int) $gs['student_count'] === 0 ? 'is-empty' : '')); ?>
                            <tr data-href="<?= e($gsUrl) ?>"<?= $gsCls !== '' ? ' class="' . $gsCls . '"' : '' ?>>
                                <td><a href="<?= e($gsUrl) ?>"><?= e(substr((string) $gs['lesson_date'], 0, 10)) ?></a></td>
                                <td><?= e(substr((string) $gs['start_time'], 0, 5)) ?>–<?= e(substr((string) $gs['end_time'], 0, 5)) ?></td>
                                <td><?= $gs['student_names'] !== null && $gs['student_names'] !== '' ? e($gs['student_names']) : '—' ?></td>
                                <?php if ($role === 'ADMIN'): ?>
                                <td class="sched-editable" data-lesson-fee="<?= (int) $gs['lesson_id'] ?>" data-fee="<?= e(number_format((float) $gs['total_fee'] / max(1, (int) $gs['student_count']), 2, '.', '')) ?>" data-count="<?= (int) $gs['student_count'] ?>" title="Click to set fee"><?= e(number_format((float) $gs['total_fee'], 2)) ?></td>
                                <?php else: ?>
                                <td><?= e(number_format((float) $gs['total_fee'], 2)) ?></td>
                                <?php endif; ?>
                                <td><?= (int) $gs['paid_count'] ?>/<?= (int) $gs['student_count'] ?></td>
                                <?php if ($role === 'ADMIN'): ?>
                                <td class="sched-editable" data-lesson-status="<?= (int) $gs['lesson_id'] ?>" data-status="<?= e($gs['status']) ?>" title="Click to change status"><?= e($gs['status']) ?></td>
                                <?php else: ?>
                                <td><?= e($gs['status']) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($role === 'ADMIN'): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                    <button type="button" class="btn-outline-danger" data-del-lesson>Delete lesson</button>
                    <button type="button" class="btn-outline-danger" id="schedDeleteSeriesBtn">Delete entire series (<?= count($groupSummary) ?>)</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <h3 class="sched-detail-sub" style="margin-top:18px;">Students &amp; Payments</h3>
                <div class="table-wrapper" style="margin-top:8px;">
                    <table class="data-table modern-table sched-sortable">
                        <thead><tr><th class="sortable" data-sortkey="name">Student <span class="sort-arrow">↕</span></th><th class="sortable" data-sortkey="fee">Fee (RM) <span class="sort-arrow">↕</span></th><th class="sortable" data-sortkey="pay">Payment <span class="sort-arrow">↕</span></th><?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php if (empty($selectedEnrollments)): ?>
                                <tr><td colspan="<?= $role === 'ADMIN' ? 4 : 3 ?>" style="text-align:center;color:var(--text-muted,#666);">No students enrolled yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($selectedEnrollments as $en): ?>
                                    <tr data-name="<?= e(strtolower($en['student_name'])) ?>" data-fee="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>" data-pay="<?= e($en['payment_status']) ?>">
                                        <td><a href="<?= e(url_path('transactions.php?student_id=' . (int) $en['student_id'])) ?>" title="All transactions for this student"><?= e($en['student_name']) ?></a></td>
                                        <?php if ($role === 'ADMIN'): ?>
                                        <td class="sched-editable" data-enroll-fee-cell="<?= (int) $en['enrollment_id'] ?>" data-fee="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>" title="Click to edit fee"><?= e(number_format((float) $en['fee_amount'], 2)) ?></td>
                                        <td><?= e($en['payment_status']) ?><?= !empty($en['proof_path']) ? ' · <a href="' . e(url_path($en['proof_path'])) . '" target="_blank" rel="noopener">proof</a>' : '' ?></td>
                                        <td>
                                            <a class="action-btn view-btn" href="<?= e(url_path('transactions.php?lesson_id=' . (int) $sl['lesson_id'])) ?>" title="Manage payment in Transactions">Pay</a>
                                            <button type="button" class="action-btn delete-btn" data-enroll-remove="<?= (int) $en['enrollment_id'] ?>">Remove</button>
                                        </td>
                                        <?php else: ?>
                                        <td><?= e(number_format((float) $en['fee_amount'], 2)) ?></td>
                                        <td><?= e($en['payment_status']) ?><?= !empty($en['proof_path']) ? ' · <a href="' . e(url_path($en['proof_path'])) . '" target="_blank" rel="noopener">proof</a>' : '' ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($role === 'ADMIN'): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    <button type="button" class="secondary-btn" id="schedAddStudentBtn">＋ Add Student</button>
                    <?php if (empty($groupSummary)): ?>
                    <button type="button" class="btn-outline-danger" data-del-lesson>Delete lesson</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Right: Form Deck / Actions -->
        <div class="sched-panel sched-side">
            <?php if ($role === 'ADMIN'): ?>
                <?php if ($selectedLesson !== null): ?>
                <div class="sched-edit-head">
                    <h2><?= e($selectedLesson['title'] !== null && $selectedLesson['title'] !== '' ? $selectedLesson['title'] : 'Edit Lesson') ?></h2>
                    <a class="sched-x-btn" href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => ''])) . '#schedGrid') ?>" title="Close edit mode and create a new lesson">×</a>
                </div>
                <p style="margin:-6px 0 12px;color:var(--text-muted,#666);font-size:13px;">Lesson #<?= (int) $selectedLesson['lesson_id'] ?> · <?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?> <?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>–<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?></p>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <button type="button" class="btn-outline-danger" data-del-lesson>Delete</button>
                </div>
                <div id="schedEditMsg" class="alert" style="display:none;"></div>
                <form id="schedEditForm">
                    <input type="hidden" name="lesson_id" value="<?= (int) $selectedLesson['lesson_id'] ?>">
                    <fieldset class="sched-group">
                        <legend>Time &amp; Schedule</legend>
                        <div class="form-field"><label>Title (optional)<input type="text" name="title" maxlength="200" value="<?= e($selectedLesson['title'] ?? '') ?>"></label></div>
                        <div class="form-field"><label>Date<input type="date" name="lesson_date" id="schedEditDate" value="<?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?>" required></label><div class="sched-date-day" id="schedEditDateDay"><?= e(date('D, j M Y', strtotime(substr((string) $selectedLesson['lesson_date'], 0, 10) . 'T12:00:00'))) ?></div></div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-field"><label>Start (HH:MM)<input type="text" name="start_time" id="schedEditStart" value="<?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                            <div class="form-field"><label>End (HH:MM)<input type="text" name="end_time" id="schedEditEnd" value="<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?>" pattern="^\d{2}:\d{2}$" placeholder="HH:MM" list="schedTimeList" inputmode="numeric" required></label></div>
                        </div>
                        <div class="form-field"><label>Status
                            <select name="status">
                                <?php foreach (['SCHEDULED', 'COMPLETED', 'CANCELLED'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $selectedLesson['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select></label></div>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Teacher &amp; Commission</legend>
                        <div class="form-field"><label>Teacher
                            <select name="teacher_id" required>
                                <option value="" <?= $selectedLesson['teacher_id'] === null ? 'selected' : '' ?>>— Unassigned —</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= (int) $t['user_id'] ?>" <?= (int) $selectedLesson['teacher_id'] === (int) $t['user_id'] ? 'selected' : '' ?>><?= e($t['username']) ?></option>
                                <?php endforeach; ?>
                            </select></label></div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-field"><label>Commission<select name="commission_type"><option value="">Default</option><option value="PERCENT" <?= $selectedLesson['commission_type'] === 'PERCENT' ? 'selected' : '' ?>>Percent %</option><option value="FIXED" <?= $selectedLesson['commission_type'] === 'FIXED' ? 'selected' : '' ?>>Fixed RM</option></select></label></div>
                            <div class="form-field"><label>Value<input type="number" name="commission_value" min="0" step="0.01" value="<?= e($selectedLesson['commission_value'] !== null ? number_format((float) $selectedLesson['commission_value'], 2, '.', '') : '') ?>" placeholder="blank = default"></label></div>
                        </div>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Students (<?= count($selectedEnrollments) ?>)</legend>
                        <?php if (empty($selectedEnrollments)): ?>
                            <span class="sched-empty">No students enrolled yet.</span>
                        <?php else: ?>
                            <div class="sched-detail-list">
                                <?php foreach ($selectedEnrollments as $en): ?>
                                    <div class="sched-student-row">
                                        <span><?= e($en['student_name']) ?> · RM <?= e(number_format((float) $en['fee_amount'], 2)) ?> · <?= e($en['payment_status']) ?><?= !empty($en['proof_path']) ? ' · <a href="' . e(url_path($en['proof_path'])) . '" target="_blank" rel="noopener">proof</a>' : '' ?></span>
                                        <span class="sched-row-actions">
                                            <button type="button" class="action-btn edit-btn" data-enroll-fee="<?= (int) $en['enrollment_id'] ?>" data-fee="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>">Fee</button>
                                            <button type="button" class="action-btn delete-btn" data-enroll-remove="<?= (int) $en['enrollment_id'] ?>">Remove</button>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="secondary-btn" id="schedEditAddStudentBtn" style="margin-top:8px;">＋ Add Student</button>
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
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div class="form-field"><label>Repeat<select name="repeat_mode">
                                <option value="weekly">Every week</option>
                                <option value="biweekly">Every 2 weeks</option>
                            </select></label></div>
                            <div class="form-field"><label>Until month<input type="month" name="repeat_until" value="<?= e(substr((string) $selectedLesson['lesson_date'], 0, 7)) ?>"></label></div>
                        </div>
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
                        <div class="form-field"><label>Title (optional)<input type="text" name="title" maxlength="200" placeholder="e.g. Beginner piano"></label></div>
                        <div class="form-field"><label>Date<input type="date" name="lesson_date" id="schedDate" value="<?= e($defaultDate) ?>" required></label><div class="sched-date-day" id="schedDateDay"><?= e(date('D, j M Y', strtotime($defaultDate . 'T12:00:00'))) ?></div></div>
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
                            <div>
                                <table class="sched-pick-table sched-sortable" id="schedStudentTable" aria-label="Pick students">
                                    <thead><tr><th class="sortable" data-sortkey="name">Student <span class="sort-arrow">↕</span></th><th>Fee (RM)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($students as $s): ?>
                                            <tr data-name="<?= e(strtolower($s['username'])) ?>">
                                                <td><label class="sched-pick-label"><input type="checkbox" name="student_ids[]" value="<?= (int) $s['user_id'] ?>"> <?= e($s['username']) ?></label></td>
                                                <td><input type="number" class="sched-fee-input" name="fees[<?= (int) $s['user_id'] ?>]" min="0" step="0.01" value="0" aria-label="Fee for <?= e($s['username']) ?>"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                    <fieldset class="sched-group">
                        <legend>Teacher &amp; Commission</legend>
                        <?php if (empty($teachers)): ?>
                            <span class="sched-empty">No teachers yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <div>
                                <table class="sched-pick-table sched-sortable" id="schedTeacherTable" aria-label="Pick teacher">
                                    <thead><tr><th class="sortable" data-sortkey="name">Teacher <span class="sort-arrow">↕</span></th><th>Availability</th><th class="sortable" data-sortkey="status">Status <span class="sort-arrow">↕</span></th></tr></thead>
                                    <tbody>
                                        <tr data-teacher="0" data-name="~" data-status="3">
                                            <td><label class="sched-pick-label"><input type="radio" name="teacher_id" value="" checked> <em>— Leave unassigned —</em></label></td>
                                            <td class="sched-t-avail sched-empty">—</td>
                                            <td class="sched-t-status"><span class="sched-pill is-muted">—</span></td>
                                        </tr>
                                        <?php foreach ($teachers as $t): ?>
                                            <tr data-teacher="<?= (int) $t['user_id'] ?>" data-name="<?= e(strtolower($t['username'])) ?>" data-status="3">
                                                <td><label class="sched-pick-label"><input type="radio" name="teacher_id" value="<?= (int) $t['user_id'] ?>" required> <?= e($t['username']) ?></label></td>
                                                <td class="sched-t-avail sched-empty">—</td>
                                                <td class="sched-t-status"><span class="sched-pill is-muted">—</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div class="form-field"><label>Repeat<select name="repeat_mode" id="schedRepeat">
                            <option value="once">Just once</option>
                            <option value="weekly">Every week</option>
                            <option value="biweekly">Every 2 weeks</option>
                        </select></label></div>
                        <div class="form-field"><label>Until month<input type="month" name="repeat_until" value="<?= e($month) ?>"></label></div>
                    </div>
                    <p class="sched-empty" style="margin:0 0 12px;font-size:12px;">Same weekday and time each occurrence. Dates outside working hours are skipped automatically.</p>
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
                <p style="color:var(--text-muted,#666);font-size:13px;">Mon–Sat only, :00/:30 slots. Administrators see this when scheduling your lessons.</p>
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

<?php if ($role === 'ADMIN' && $selectedLesson !== null): ?>
<dialog class="sched-dialog" id="schedAddStudentDialog" aria-labelledby="schedAddStudentTitle">
    <form method="dialog" id="schedAddStudentDialogForm">
        <h2 id="schedAddStudentTitle">Add student</h2>
        <div class="form-field"><label>Student
            <select id="schedAddStudentSelect" required>
                <?php $hasOptions = false; ?>
                <?php foreach ($students as $s): ?>
                    <?php if (in_array((int) $s['user_id'], $enrolledIds, true)) continue; ?>
                    <?php $hasOptions = true; ?>
                    <option value="<?= (int) $s['user_id'] ?>"><?= e($s['username']) ?><?= $s['email'] !== '' ? ' (' . e($s['email']) . ')' : '' ?></option>
                <?php endforeach; ?>
                <?php if (!$hasOptions): ?>
                    <option value="">No more students to add</option>
                <?php endif; ?>
            </select></label></div>
        <div class="form-field"><label>Fee (RM)<input type="number" id="schedAddStudentFee" min="0" step="0.01" value="0" required></label></div>
        <p style="margin:0 0 4px;font-size:13px;"><a href="#" id="schedNewStudentToggle">＋ Create new student account</a></p>
        <div id="schedNewStudentBox" style="display:none;border-top:1px solid var(--border-color,#eaeaea);padding-top:12px;margin-top:4px;">
            <div id="schedNewStudentMsg" class="alert" style="display:none;"></div>
            <div class="form-field"><label>Username<input type="text" id="schedNewUsername" maxlength="100" autocomplete="off"></label></div>
            <div class="form-field"><label>Email (optional — may be left blank)<input type="email" id="schedNewEmail" maxlength="100" autocomplete="off"></label></div>
            <div class="form-field"><label>Password (min 6 characters)<input type="password" id="schedNewPassword" autocomplete="new-password"></label></div>
            <button type="button" class="secondary-btn" id="schedNewStudentCreate" style="width:100%;">Create account</button>
        </div>
        <div class="sched-dialog-actions">
            <button type="button" class="secondary-btn" id="schedAddStudentCancel">Cancel</button>
            <button type="submit" class="primary-btn" <?= $hasOptions ? '' : 'disabled' ?>>Add</button>
        </div>
    </form>
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
        t.end.value = fmtMin(Math.max(a, b) + 30);
        t.start.dispatchEvent(new Event('input', { bubbles: true }));
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
        return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
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
            var checked = createForm.querySelectorAll('input[name="student_ids[]"]:checked');
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
    // Issue 63: pick from the student list instead of typing an ID manually.
    var addDialog = document.getElementById('schedAddStudentDialog');
    document.querySelectorAll('#schedAddStudentBtn, #schedEditAddStudentBtn').forEach(function (addBtn) {
        addBtn.addEventListener('click', function () {
            if (addDialog && typeof addDialog.showModal === 'function') { addDialog.showModal(); }
        });
    });
    var addCancel = document.getElementById('schedAddStudentCancel');
    if (addCancel) addCancel.addEventListener('click', function () { if (addDialog) addDialog.close(); });
    var addDialogForm = document.getElementById('schedAddStudentDialogForm');
    if (addDialogForm) addDialogForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var sid = document.getElementById('schedAddStudentSelect').value;
        var fee = document.getElementById('schedAddStudentFee').value;
        if (!sid) return;
        if (addDialog) addDialog.close();
        var fd = new FormData(); fd.append('action', 'add'); fd.append('lesson_id', String(selectedLessonId));
        fd.append('student_id', sid); fd.append('fee_amount', fee);
        postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
    });
    // Issue 63 follow-up: create a student account without leaving the dialog.
    var newToggle = document.getElementById('schedNewStudentToggle');
    var newBox = document.getElementById('schedNewStudentBox');
    if (newToggle && newBox) newToggle.addEventListener('click', function (ev) {
        ev.preventDefault();
        var open = newBox.style.display === 'none';
        newBox.style.display = open ? 'block' : 'none';
        newToggle.textContent = open ? '– Hide new account form' : '＋ Create new student account';
    });
    var newCreateBtn = document.getElementById('schedNewStudentCreate');
    if (newCreateBtn) newCreateBtn.addEventListener('click', function () {
        var u = document.getElementById('schedNewUsername').value.trim();
        var m = document.getElementById('schedNewEmail').value.trim();
        var p = document.getElementById('schedNewPassword').value;
        var msg = document.getElementById('schedNewStudentMsg');
        function newMsg(text, ok) {
            msg.style.display = 'block';
            msg.className = 'alert ' + (ok ? 'success' : 'error');
            msg.textContent = text;
        }
        if (u === '' || p === '') { newMsg('Username and password are required (email may be left blank).', false); return; }
        newCreateBtn.disabled = true; newCreateBtn.textContent = 'Creating...';
        var fd = new FormData();
        fd.append('username', u); fd.append('email', m); fd.append('password', p); fd.append('role', 'STUDENT');
        fetch('<?= e(url_path('admin/user_create.php')) ?>', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                newCreateBtn.disabled = false; newCreateBtn.textContent = 'Create account';
                if (data.success && data.user) {
                    var sel = document.getElementById('schedAddStudentSelect');
                    var opt = document.createElement('option');
                    opt.value = String(data.user.user_id);
                    opt.textContent = data.user.username + ' (' + data.user.email + ')';
                    sel.appendChild(opt);
                    sel.value = String(data.user.user_id);
                    document.getElementById('schedNewUsername').value = '';
                    document.getElementById('schedNewEmail').value = '';
                    document.getElementById('schedNewPassword').value = '';
                    newMsg('Account created — press Add to enroll ' + data.user.username + '.', true);
                } else {
                    newMsg(data.message || 'Account creation failed.', false);
                }
            })
            .catch(function (err) {
                newCreateBtn.disabled = false; newCreateBtn.textContent = 'Create account';
                newMsg('Network error: ' + err.message, false);
            });
    });
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
        var s = document.createElement('input'); s.type = 'time'; s.step = '1800'; s.value = slot.start || '09:00';
        var e = document.createElement('input'); e.type = 'time'; e.step = '1800'; e.value = slot.end || '12:00';
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
