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
// grid days: Mon-Sat of the selected week (closed Sunday skipped)
$weekDays = [];
foreach (range(0, 5) as $i) {
    $ymd = date('Y-m-d', strtotime($weekMonday . " +$i days"));
    $weekDays[] = ['ymd' => $ymd, 'dow' => $i + 1, 'dowLabel' => date('D', strtotime($ymd . 'T12:00:00')), 'dateLabel' => date('j M', strtotime($ymd . 'T12:00:00'))];
}

// ---- reference data ----
$teachers = [];
$students = [];
$teacherStudents = [];
$lessonStudents = [];
$lessons = [];
$selectedLesson = null;
$selectedEnrollments = [];
$groupLessons = [];
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

        $filters = ['from' => $calStart, 'to' => $calEnd];
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
                // Issue 50: sibling lessons of the same repeat series.
                if (!empty($selectedLesson['recurrence_group'])) {
                    $groupLessons = $lessonsManager->getGroupLessons((string) $selectedLesson['recurrence_group']);
                }
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
// index lessons by date for the grid + calendar dots (issue 34)
$lessonsByDate = [];
$dayCounts = [];
foreach ($lessons as $l) {
    $d = substr((string) ($l['lesson_date'] ?? ''), 0, 10);
    if ($d < $calStart || $d > $calEnd) {
        continue;
    }
    $lessonsByDate[$d][] = $l;
    $dayCounts[$d] = ($dayCounts[$d] ?? 0) + 1;
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
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <p class="eyebrow">Timetable</p>
            <h1 style="margin:0;">Schedule</h1>
            <p style="color:var(--text-muted,#666);margin:6px 0 0;">
                <?php if ($role === 'ADMIN'): ?>30-min slots · weekday 9am–10pm · Sat 9am–6:30pm · closed Sun. Drag across empty cells of a day to fill the form; click a lesson for details.<?php endif; ?>
                <?php if ($role === 'TEACHER'): ?>Your lessons only. Availability below is used by admin to avoid clashes.<?php endif; ?>
                <?php if ($role === 'STUDENT'): ?>Your enrolled lessons only.<?php endif; ?>
            </p>
        </div>
        <a href="<?= e(url_path('account.php')); ?>" class="text-button">← Back to Account</a>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php endif; ?>

    <div class="sched-layout">
    <form class="sched-filters" method="get" action="<?= e(url_path('schedule.php')); ?>">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <input type="hidden" name="week" value="<?= e($weekMonday) ?>">
        <label>Status
            <select name="status">
                <option value="">All</option>
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
        <button type="submit" class="primary-btn">Apply</button>
        <a href="<?= e(url_path('schedule.php?month=' . $todayMonth . '&week=' . $todayMonday)) ?>" class="text-button">Today</a>
    </form>
    <div class="sched-panel sched-cal-panel">
        <div class="sched-cal-head">
            <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $prevMonth, 'week' => '', 'lesson_id' => '']))) ?>" aria-label="Previous month">‹ Prev</a>
            <strong><?= e(date('F Y', strtotime($monthStart . 'T12:00:00'))) ?></strong>
            <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $nextMonth, 'week' => '', 'lesson_id' => '']))) ?>" aria-label="Next month">Next ›</a>
        </div>
        <table class="sched-cal" aria-label="Month calendar">
            <thead><tr><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th><th>Su</th></tr></thead>
            <tbody>
                <?php foreach ($calWeeks as $cw): ?>
                    <?php $cwSel = ($cw['monday'] === $weekMonday); ?>
                    <tr class="sched-cal-week<?= $cwSel ? ' is-selected' : '' ?>" data-href="<?= e(url_path('schedule.php?' . sched_qs(['month' => $month, 'week' => $cw['monday'], 'lesson_id' => '']))) ?>" tabindex="0" title="Show week of <?= e($cw['monday']) ?>">
                        <?php foreach ($cw['days'] as $cdd): ?>
                            <?php
                            $cdDow = (int) date('N', strtotime($cdd . 'T12:00:00'));
                            $cdCls = 'sched-cal-day' . (substr($cdd, 0, 7) === $month ? '' : ' is-dim') . ($cdDow === 7 ? ' is-sun' : '') . ($cdd === $todayYmd ? ' is-today' : '');
                            $cdCnt = $dayCounts[$cdd] ?? 0;
                            ?>
                            <td class="<?= $cdCls ?>"><span class="sched-cal-num"><?= e((int) substr($cdd, 8, 2)) ?></span><?php if ($cdCnt > 0): ?><span class="sched-cal-dot"><?= $cdCnt ?>•</span><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="sched-empty" style="margin:8px 0 0;">Click a week to view it.</p>
    </div>
    <div class="sched-panel" id="schedDetailPanel">
        <h2>Lesson details</h2>
        <?php if ($selectedLesson === null): ?>
            <p style="color:var(--text-muted,#666);">Select a lesson from the grid to see students, fees and commission.</p>
        <?php else: ?>
            <?php
            $sl = $selectedLesson;
            $payout = $lessonsManager !== null
                ? $lessonsManager->commissionPayout($resolvedCommission[0], (float) $resolvedCommission[1], (float) ($selectedTotals['total_fee'] ?? 0))
                : 0.0;
            // Issue 50: first and next upcoming lesson of the series.
            $glFirst = $groupLessons[0] ?? null;
            $glNext = null;
            foreach ($groupLessons as $gl) {
                if ($gl['status'] === 'SCHEDULED' && strtotime($gl['lesson_date'] . ' ' . $gl['end_time']) >= time()) {
                    $glNext = $gl;
                    break;
                }
            }
            ?>
            <div class="sched-detail-list">
                <div><strong><?= e($sl['title'] !== null && $sl['title'] !== '' ? $sl['title'] : 'Lesson') ?></strong>
                    <span class="role-badge role-student"><?= e($sl['status']) ?></span></div>
                <div>Date: <?= e(substr((string) $sl['lesson_date'], 0, 10)) ?> · <?= e(substr((string) $sl['start_time'], 0, 5)) ?>–<?= e(substr((string) $sl['end_time'], 0, 5)) ?></div>
                <div>Teacher: <?= e($sl['teacher_name'] ?? '—') ?></div>
                <div>Students: <?= (int) ($selectedTotals['student_count'] ?? 0) ?> · Total fee: RM <?= e(number_format((float) ($selectedTotals['total_fee'] ?? 0), 2)) ?> · Paid: RM <?= e(number_format((float) ($selectedTotals['paid_total'] ?? 0), 2)) ?> · Unpaid rows: <?= (int) ($selectedTotals['unpaid_count'] ?? 0) ?></div>
                <div>Commission: <?= e($resolvedCommission[0]) ?> <?= e(number_format((float) $resolvedCommission[1], 2)) ?> → payout RM <?= e(number_format($payout, 2)) ?> (status: <?= e($sl['commission_status']) ?>)</div>
            </div>
            <?php if (!empty($groupLessons)): ?>
            <div style="margin-top:10px;padding:10px;border:1px solid var(--border-color,#eaeaea);border-radius:10px;">
                <strong>Repeat series (<?= count($groupLessons) ?> lessons · #<?= e(substr((string) $sl['recurrence_group'], 0, 8)) ?>)</strong>
                <div class="sched-empty">First: <?= $glFirst ? e(substr((string) $glFirst['lesson_date'], 0, 10)) : '—' ?> · Next: <?= $glNext ? e(substr((string) $glNext['lesson_date'], 0, 10) . ' ' . substr((string) $glNext['start_time'], 0, 5)) : '—' ?></div>
                <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px;font-size:13px;">
                    <?php foreach ($groupLessons as $gl): ?>
                        <div><a href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => (int) $gl['lesson_id']]))) ?>"><?= e(substr((string) $gl['lesson_date'], 0, 10)) ?> <?= e(substr((string) $gl['start_time'], 0, 5)) ?>–<?= e(substr((string) $gl['end_time'], 0, 5)) ?></a> · <?= e($gl['status']) ?><?= (int) $gl['lesson_id'] === (int) $sl['lesson_id'] ? ' (this lesson)' : '' ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($role === 'ADMIN'): ?>
                    <button type="button" class="btn-outline-danger" id="schedDeleteSeriesBtn" style="margin-top:8px;">Delete entire series (<?= count($groupLessons) ?>)</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="table-wrapper" style="margin-top:12px;">
                <table class="data-table modern-table sched-sortable">
                    <thead><tr><th class="sortable" data-sortkey="name">Student <span class="sort-arrow">↕</span></th><th class="sortable" data-sortkey="fee">Fee (RM) <span class="sort-arrow">↕</span></th><th class="sortable" data-sortkey="pay">Payment <span class="sort-arrow">↕</span></th><?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php if (empty($selectedEnrollments)): ?>
                            <tr><td colspan="<?= $role === 'ADMIN' ? 4 : 3 ?>">No students yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($selectedEnrollments as $en): ?>
                                <tr data-name="<?= e(strtolower($en['student_name'])) ?>" data-fee="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>" data-pay="<?= e($en['payment_status']) ?>">
                                    <td><?= e($en['student_name']) ?></td>
                                    <td><?= e(number_format((float) $en['fee_amount'], 2)) ?></td>
                                    <td><?= e($en['payment_status']) ?><?= !empty($en['proof_path']) ? ' · <a href="' . e(url_path($en['proof_path'])) . '" target="_blank" rel="noopener">proof</a>' : '' ?></td>
                                    <?php if ($role === 'ADMIN'): ?>
                                    <td>
                                        <button type="button" class="action-btn edit-btn" data-enroll-fee="<?= (int) $en['enrollment_id'] ?>" data-fee="<?= e(number_format((float) $en['fee_amount'], 2, '.', '')) ?>">Fee</button>
                                        <button type="button" class="action-btn delete-btn" data-enroll-remove="<?= (int) $en['enrollment_id'] ?>">Remove</button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($role === 'ADMIN'): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                <button type="button" class="secondary-btn" id="schedStatusBtn">Set status</button>
                <button type="button" class="secondary-btn" id="schedAddStudentBtn">Add student</button>
                <button type="button" class="btn-outline-danger" id="schedDeleteBtn">Delete lesson</button>
                <a class="text-button" href="<?= e(url_path('transactions.php?lesson_id=' . (int) $sl['lesson_id'])) ?>">Open in Transactions →</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="sched-grid" role="region" aria-label="Weekly timetable">
        <table class="sched-table">
            <thead>
                <tr>
                    <th>Slot</th>
                    <?php foreach ($weekDays as $day): ?>
                        <?php $isToday = ($day['ymd'] === $todayYmd); ?>
                        <th<?= $isToday ? ' class="sched-today" id="schedTodayCol"' : '' ?>><span class="sched-h-day"><?= e($day['dowLabel']) ?></span><span class="sched-h-date"><?= e($day['dateLabel']) ?></span></th>
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
                        <?php foreach ($weekDays as $day): ?>
                            <?php
                            $isSatLate = ($day['dow'] === 6 && $slotMin >= 18 * 60 + 30);
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
                                <?php if ($isSatLate): ?>
                                    <span class="sched-empty">Closed</span>
                                <?php elseif (empty($cellLessons)): ?>
                                    <span class="sched-empty">—</span>
                                <?php else: ?>
                                    <?php foreach ($cellLessons as $l): ?>
                                        <?php
                                        $lid = (int) $l['lesson_id'];
                                        $cancelled = ($l['status'] === 'CANCELLED');
                                        $qs = sched_qs(['lesson_id' => $lid]);
                                        // Issue 41 priority: small time, big students, middle teacher, small status.
                                        $chipNames = $lessonStudents[$lid] ?? [];
                                        $chipShown = array_slice($chipNames, 0, 3);
                                        $chipMore = count($chipNames) - count($chipShown);
                                        $chipDur = sched_min((string) $l['end_time']) - sched_min((string) $l['start_time']);
                                        $chipTitle = $l['title'] !== null && $l['title'] !== '' ? $l['title'] : 'Lesson';
                                        $chipStCls = $l['status'] === 'COMPLETED' ? 'is-ok' : ($l['status'] === 'CANCELLED' ? 'is-bad' : 'is-muted');
                                        ?>
                                        <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs)) ?>">
                                            <span class="sched-chip-time"><?= e(substr((string) $l['start_time'], 0, 5)) ?>–<?= e(substr((string) $l['end_time'], 0, 5)) ?> · <?= $chipDur ?>m · <?= e($chipTitle) ?></span>
                                            <span class="sched-chip-students"><?= $chipShown ? e(implode(', ', $chipShown)) . ($chipMore > 0 ? e(' +' . $chipMore . ' more') : '') : 'No students' ?></span>
                                            <span class="sched-chip-teacher"><?= e($l['teacher_name'] ?? '—') ?></span>
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

        <div class="sched-panel sched-side">
            <?php if ($role === 'ADMIN'): ?>
                <?php if ($selectedLesson !== null): ?>
                <h2><?= e($selectedLesson['title'] !== null && $selectedLesson['title'] !== '' ? $selectedLesson['title'] : 'Untitled lesson') ?></h2>
                <p style="margin:-6px 0 12px;color:var(--text-muted,#666);font-size:13px;">Lesson #<?= (int) $selectedLesson['lesson_id'] ?> · <?= e(substr((string) $selectedLesson['lesson_date'], 0, 10)) ?> <?= e(substr((string) $selectedLesson['start_time'], 0, 5)) ?>–<?= e(substr((string) $selectedLesson['end_time'], 0, 5)) ?></p>
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <a class="text-button" href="<?= e(url_path('schedule.php?' . sched_qs(['lesson_id' => '']))) ?>">＋ New lesson</a>
                    <button type="button" class="btn-outline-danger" id="schedEditDeleteBtn">Delete</button>
                </div>
                <div id="schedEditMsg" class="alert" style="display:none;"></div>
                <form id="schedEditForm">
                    <input type="hidden" name="lesson_id" value="<?= (int) $selectedLesson['lesson_id'] ?>">
                    <fieldset class="sched-group">
                        <legend>Time</legend>
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
                        <legend>Teacher &amp; commission</legend>
                        <div class="form-field"><label>Teacher
                            <select name="teacher_id" required>
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
                                    <div><?= e($en['student_name']) ?> · RM <?= e(number_format((float) $en['fee_amount'], 2)) ?> · <?= e($en['payment_status']) ?><?= !empty($en['proof_path']) ? ' · <a href="' . e(url_path($en['proof_path'])) . '" target="_blank" rel="noopener">proof</a>' : '' ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p class="sched-empty" style="margin:8px 0 0;">Fees and removals are managed in Lesson details below.</p>
                    </fieldset>
                    <button type="submit" class="primary-btn" style="width:100%;">Save changes</button>
                </form>
                <?php if ($selectedLesson['status'] === 'COMPLETED'): ?>
                <fieldset class="sched-group" style="margin-top:12px;">
                    <legend>Repeat from this lesson</legend>
                    <div id="schedRepeatMsg" class="alert" style="display:none;"></div>
                    <form id="schedRepeatForm">
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
                <p style="color:var(--text-muted,#666);font-size:13px;">Students and fees are managed in Lesson details below.</p>
                <?php else: ?>
                <h2>Create lesson</h2>
                <div id="schedCreateMsg" class="alert" style="display:none;"></div>
                <form id="schedCreateForm">
                    <fieldset class="sched-group">
                        <legend>Time</legend>
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
                        <legend>Teacher &amp; commission</legend>
                        <?php if (empty($teachers)): ?>
                            <span class="sched-empty">No teachers yet — create accounts in Admin → Manage Users first.</span>
                        <?php else: ?>
                            <div>
                                <table class="sched-pick-table sched-sortable" id="schedTeacherTable" aria-label="Pick teacher">
                                    <thead><tr><th class="sortable" data-sortkey="name">Teacher <span class="sort-arrow">↕</span></th><th>Availability</th><th class="sortable" data-sortkey="status">Status <span class="sort-arrow">↕</span></th></tr></thead>
                                    <tbody>
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
                    </fieldset>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div class="form-field"><label>Repeat<select name="repeat_mode" id="schedRepeat">
                            <option value="once">Just once</option>
                            <option value="weekly">Every week</option>
                            <option value="biweekly">Every 2 weeks</option>
                        </select></label></div>
                        <div class="form-field"><label>Until month<input type="month" name="repeat_until" value="<?= e($month) ?>"></label></div>
                    </div>
                    <p class="sched-empty" style="margin:0 0 12px;">Same weekday and time each occurrence. Set Just once, or pick a repeat with an until-month. Dates outside working hours are skipped.</p>
                    <button type="submit" class="primary-btn" style="width:100%;">Save timetable</button>
                </form>
                <?php endif; ?>
                <datalist id="schedTimeList">
                    <?php foreach ($timeOptions as $opt): ?>
                        <option value="<?= e($opt) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php elseif ($role === 'TEACHER'): ?>
                <h2>My availability</h2>
                <p style="color:var(--text-muted,#666);font-size:13px;">Mon–Sat only, :00/:30 slots. Admin sees this when assigning you.</p>
                <div id="schedAvailMsg" class="alert" style="display:none;"></div>
                <div id="schedAvailRows" style="display:flex;flex-direction:column;gap:8px;"></div>
                <div style="display:flex;gap:8px;margin-top:10px;">
                    <button type="button" class="secondary-btn" id="schedAvailAdd">+ Slot</button>
                    <button type="button" class="primary-btn" id="schedAvailSave">Save availability</button>
                </div>
            <?php else: ?>
                <h2>How scheduling works</h2>
                <p style="color:var(--text-muted,#666);font-size:14px;">Request lessons with the admin (about once a week). New slots you are enrolled in appear in the grid above.</p>
            <?php endif; ?>
        </div>
    </div><!-- /.sched-layout -->
</div>

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
    var schedWeekEnd = <?= json_encode($weekDays[count($weekDays) - 1]['ymd'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

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

    // Issue 29: scroll the grid to today's column when visible.
    var todayCol = document.getElementById('schedTodayCol');
    if (todayCol && todayCol.scrollIntoView) {
        try { todayCol.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' }); } catch (e) {}
    }

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
        window.location.search = params.toString();
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
            if (checked.length === 0 && !window.confirm('This lesson has no students. Save anyway?')) return;
            var fd = new FormData(createForm);
            fd.append('action', 'create');
            var btn = createForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Saving...';
            fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var skipped = (data.data && data.data.skipped) || [];
                        if (skipped.length) { alert('Saved. Skipped ' + skipped.length + ' date(s) outside working hours: ' + skipped.join(', ')); }
                        window.location.reload();
                    }
                    else { showMsg('schedCreateMsg', data.error || 'Save failed.', false); btn.disabled = false; btn.textContent = 'Save timetable'; }
                })
                .catch(function (err) { showMsg('schedCreateMsg', 'Network error: ' + err.message, false); btn.disabled = false; btn.textContent = 'Save timetable'; });
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
                    if (data.success) { window.location.reload(); }
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
                        if (skipped.length) { alert('Repeats created. Skipped ' + skipped.length + ' date(s): ' + skipped.join(', ')); }
                        var params = new URLSearchParams(window.location.search);
                        params.set('lesson_id', String((data.data.lesson_ids || [])[0] || ''));
                        window.location.search = params.toString();
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
            .then(function (data) { if (data.success) { window.location.reload(); } else { alert(data.error || 'Failed.'); } })
            .catch(function (err) { alert('Network error: ' + err.message); });
    }
    document.querySelectorAll('#schedDeleteBtn, #schedEditDeleteBtn').forEach(function (delBtn) {
        delBtn.addEventListener('click', function () {
            if (!window.confirm('Delete this lesson and its enrollments?')) return;
            var fd = new FormData(); fd.append('action', 'delete'); fd.append('lesson_id', String(selectedLessonId));
            postForm('<?= e(url_path('api/lessons_save.php')) ?>', fd);
        });
    });
    // Issue 50: delete every lesson of the repeat series at once.
    var delSeriesBtn = document.getElementById('schedDeleteSeriesBtn');
    if (delSeriesBtn) delSeriesBtn.addEventListener('click', function () {
        var label = delSeriesBtn.textContent || 'this series';
        if (!window.confirm('Delete ' + label.trim() + ' and all their enrollments? This cannot be undone.')) return;
        var fd = new FormData(); fd.append('action', 'delete_group'); fd.append('lesson_id', String(selectedLessonId));
        fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var params = new URLSearchParams(window.location.search);
                    params.delete('lesson_id');
                    window.location.search = params.toString();
                } else { alert(data.error || 'Failed.'); }
            })
            .catch(function (err) { alert('Network error: ' + err.message); });
    });
    var stBtn = document.getElementById('schedStatusBtn');
    if (stBtn) stBtn.addEventListener('click', function () {
        var v = window.prompt('Status (SCHEDULED / COMPLETED / CANCELLED). COMPLETED only works after the end time:', 'COMPLETED');
        if (!v) return;
        var fd = new FormData(); fd.append('action', 'update'); fd.append('lesson_id', String(selectedLessonId));
        fd.append('status', v.toUpperCase());
        var sl = <?= json_encode($selectedLesson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        if (sl) { fd.append('teacher_id', String(sl.teacher_id || '')); fd.append('lesson_date', (sl.lesson_date || '').substr(0, 10)); fd.append('start_time', (sl.start_time || '').substr(0, 5)); fd.append('end_time', (sl.end_time || '').substr(0, 5)); }
        postForm('<?= e(url_path('api/lessons_save.php')) ?>', fd);
    });
    var addBtn = document.getElementById('schedAddStudentBtn');
    if (addBtn) addBtn.addEventListener('click', function () {
        var sid = window.prompt('Student user ID to add:');
        if (!sid) return;
        var fee = window.prompt('Fee (RM):', '0');
        if (fee === null) return;
        var fd = new FormData(); fd.append('action', 'add'); fd.append('lesson_id', String(selectedLessonId));
        fd.append('student_id', sid); fd.append('fee_amount', fee);
        postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
    });
    document.querySelectorAll('[data-enroll-remove]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!window.confirm('Remove this student from the lesson?')) return;
            var fd = new FormData(); fd.append('action', 'remove'); fd.append('enrollment_id', b.getAttribute('data-enroll-remove'));
            postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
        });
    });
    document.querySelectorAll('[data-enroll-fee]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = window.prompt('New fee (RM):', b.getAttribute('data-fee') || '0');
            if (v === null) return;
            var fd = new FormData(); fd.append('action', 'set_fee'); fd.append('enrollment_id', b.getAttribute('data-enroll-fee')); fd.append('fee_amount', v);
            postForm('<?= e(url_path('api/enrollments_save.php')) ?>', fd);
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
