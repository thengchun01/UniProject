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

$week = trim($_GET['week'] ?? '');
if (!in_array($week, ['1', '2', '3', '4', '5'], true)) {
    // default to the week containing today when viewing current month
    if ($month === date('Y-m')) {
        $today = (int) date('j');
        $week = (string) min(5, (int) ceil($today / 7));
    } else {
        $week = '1';
    }
}
$weekNum = (int) $week;

$statusFilter = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($statusFilter, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
    $statusFilter = '';
}
$teacherFilter = (int) ($_GET['teacher_id'] ?? 0);
$selectedLessonId = (int) ($_GET['lesson_id'] ?? 0);

// ---- month / week day lists ----
$daysInMonth = (int) date('t', strtotime($month . '-01'));
$weekRanges = [1 => [1, 7], 2 => [8, 14], 3 => [15, 21], 4 => [22, 28], 5 => [29, $daysInMonth]];
[$wStart, $wEnd] = $weekRanges[$weekNum];
$weekDays = [];
for ($d = $wStart; $d <= $wEnd; $d++) {
    $ymd = sprintf('%04d-%02d-%02d', $year, $mon, $d);
    $dow = (int) date('N', strtotime($ymd)); // 1 Mon .. 7 Sun
    if ($dow === 7) {
        continue; // closed Sunday
    }
    $weekDays[] = ['ymd' => $ymd, 'day' => $d, 'dow' => $dow, 'label' => date('D j', strtotime($ymd))];
}
$monthStart = sprintf('%04d-%02d-01', $year, $mon);
$monthEnd = sprintf('%04d-%02d-%02d', $year, $mon, $daysInMonth);

// ---- reference data ----
$teachers = [];
$students = [];
$lessons = [];
$selectedLesson = null;
$selectedEnrollments = [];
$selectedTotals = ['student_count' => 0, 'total_fee' => 0, 'paid_total' => 0, 'unpaid_count' => 0];
$resolvedCommission = ['PERCENT', 0.0];
$myAvailability = [];

if ($lessonsAvailable && is_database_connected()) {
    try {
        $db = db();
        $stmt = $db->query("SELECT user_id, username FROM users WHERE role = 'TEACHER' ORDER BY username ASC");
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $db->query("SELECT user_id, username, email FROM users WHERE role = 'STUDENT' ORDER BY username ASC");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $filters = ['from' => $monthStart, 'to' => $monthEnd];
        if ($statusFilter !== '') {
            $filters['status'] = $statusFilter;
        }
        if ($role === 'TEACHER') {
            $filters['teacher_id'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            $filters['student_id'] = (int) $user['user_id'];
        } elseif ($teacherFilter > 0) {
            $filters['teacher_id'] = $teacherFilter;
        }
        $lessons = $lessonsManager->listLessons($filters, 'lesson_date', 'ASC', 500, 0);

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
// index lessons by date for the grid
$lessonsByDate = [];
foreach ($lessons as $l) {
    $d = substr((string) ($l['lesson_date'] ?? ''), 0, 10);
    if ($d < $monthStart || $d > $monthEnd) {
        continue;
    }
    $lessonsByDate[$d][] = $l;
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
                <?php if ($role === 'ADMIN'): ?>30-min slots · weekday 9am–10pm · Sat 9am–6:30pm · closed Sun. Click a lesson for details; click an empty cell to prefill the create form.<?php endif; ?>
                <?php if ($role === 'TEACHER'): ?>Your lessons only. Availability below is used by admin to avoid clashes.<?php endif; ?>
                <?php if ($role === 'STUDENT'): ?>Your enrolled lessons only.<?php endif; ?>
            </p>
        </div>
        <a href="<?= e(url_path('account.php')); ?>" class="text-button">← Back to Account</a>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php endif; ?>

    <form class="sched-filters" method="get" action="<?= e(url_path('schedule.php')); ?>">
        <label>Month
            <input type="month" name="month" value="<?= e($month) ?>">
        </label>
        <label>Week
            <select name="week">
                <option value="1" <?= $weekNum === 1 ? 'selected' : '' ?>>Week 1 (1–7)</option>
                <option value="2" <?= $weekNum === 2 ? 'selected' : '' ?>>Week 2 (8–14)</option>
                <option value="3" <?= $weekNum === 3 ? 'selected' : '' ?>>Week 3 (15–21)</option>
                <option value="4" <?= $weekNum === 4 ? 'selected' : '' ?>>Week 4 (22–28)</option>
                <option value="5" <?= $weekNum === 5 ? 'selected' : '' ?>>Week 5 (29–end)</option>
            </select>
        </label>
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
        <?php endif; ?>
        <button type="submit" class="primary-btn">Apply</button>
    </form>

    <div class="sched-grid" role="region" aria-label="Weekly timetable">
        <table class="sched-table">
            <thead>
                <tr>
                    <th>Slot</th>
                    <?php foreach ($weekDays as $day): ?>
                        <th><?= e($day['label']) ?></th>
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
                                        ?>
                                        <a class="sched-chip <?= $cancelled ? 'is-cancelled' : '' ?>" href="<?= e(url_path('schedule.php?' . $qs)) ?>">
                                            <strong><?= e(substr((string) $l['start_time'], 0, 5)) ?>–<?= e(substr((string) $l['end_time'], 0, 5)) ?></strong><br>
                                            <?= e($l['title'] !== null && $l['title'] !== '' ? $l['title'] : 'Lesson') ?><br>
                                            <span><?= e($l['teacher_name'] ?? '—') ?> · <?= (int) ($l['student_count'] ?? 0) ?> student(s) · <?= e($l['status']) ?></span>
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

    <div class="sched-panels">
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
                ?>
                <div class="sched-detail-list">
                    <div><strong><?= e($sl['title'] !== null && $sl['title'] !== '' ? $sl['title'] : 'Lesson') ?></strong>
                        <span class="role-badge role-student"><?= e($sl['status']) ?></span></div>
                    <div>Date: <?= e(substr((string) $sl['lesson_date'], 0, 10)) ?> · <?= e(substr((string) $sl['start_time'], 0, 5)) ?>–<?= e(substr((string) $sl['end_time'], 0, 5)) ?></div>
                    <div>Teacher: <?= e($sl['teacher_name'] ?? '—') ?></div>
                    <div>Students: <?= (int) ($selectedTotals['student_count'] ?? 0) ?> · Total fee: RM <?= e(number_format((float) ($selectedTotals['total_fee'] ?? 0), 2)) ?> · Paid: RM <?= e(number_format((float) ($selectedTotals['paid_total'] ?? 0), 2)) ?> · Unpaid rows: <?= (int) ($selectedTotals['unpaid_count'] ?? 0) ?></div>
                    <div>Commission: <?= e($resolvedCommission[0]) ?> <?= e(number_format((float) $resolvedCommission[1], 2)) ?> → payout RM <?= e(number_format($payout, 2)) ?> (status: <?= e($sl['commission_status']) ?>)</div>
                </div>
                <div class="table-wrapper" style="margin-top:12px;">
                    <table class="data-table modern-table">
                        <thead><tr><th>Student</th><th>Fee (RM)</th><th>Payment</th><?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif; ?></tr></thead>
                        <tbody>
                            <?php if (empty($selectedEnrollments)): ?>
                                <tr><td colspan="<?= $role === 'ADMIN' ? 4 : 3 ?>">No students yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($selectedEnrollments as $en): ?>
                                    <tr>
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

        <div class="sched-panel">
            <?php if ($role === 'ADMIN'): ?>
                <h2>Create lesson</h2>
                <div id="schedCreateMsg" class="alert" style="display:none;"></div>
                <form id="schedCreateForm">
                    <div class="form-field"><label>Title (optional)<input type="text" name="title" maxlength="200" placeholder="e.g. Beginner piano"></label></div>
                    <div class="form-field"><label>Date<input type="date" name="lesson_date" id="schedDate" required></label></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div class="form-field"><label>Start<input type="time" name="start_time" id="schedStart" step="1800" required></label></div>
                        <div class="form-field"><label>End<input type="time" name="end_time" id="schedEnd" step="1800" required></label></div>
                    </div>
                    <div class="form-field"><label>Teacher
                        <select name="teacher_id" id="schedTeacher" required>
                            <option value="">— choose —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int) $t['user_id'] ?>"><?= e($t['username']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <div class="sched-teacher-note" id="schedTeacherNote">Pick a teacher to see availability and clash warnings.</div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div class="form-field"><label>Commission (blank = default chain)<select name="commission_type"><option value="">Default</option><option value="PERCENT">Percent %</option><option value="FIXED">Fixed RM</option></select></label></div>
                        <div class="form-field"><label>Value<input type="number" name="commission_value" min="0" step="0.01" placeholder="e.g. 20"></label></div>
                    </div>
                    <div class="form-field"><label>Students + fee each (RM)</label>
                        <div style="max-height:180px;overflow:auto;border:1px solid var(--border-color,#eaeaea);border-radius:10px;padding:8px;">
                            <?php if (empty($students)): ?>
                                <span class="sched-empty">No students yet — create accounts in Admin → Manage Users first.</span>
                            <?php else: ?>
                                <?php foreach ($students as $s): ?>
                                    <div class="sched-student-fee">
                                        <input type="checkbox" name="student_ids[]" value="<?= (int) $s['user_id'] ?>" id="st<?= (int) $s['user_id'] ?>">
                                        <label for="st<?= (int) $s['user_id'] ?>" style="flex:1;"><?= e($s['username']) ?></label>
                                        <input type="number" name="fees[<?= (int) $s['user_id'] ?>]" min="0" step="0.01" value="0" aria-label="Fee">
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-field"><label>Repeat same weekday/time in <?= e($month) ?> (optional)</label>
                        <div class="sched-checks">
                            <label><input type="checkbox" class="sched-recur" value="1"> Week 1</label>
                            <label><input type="checkbox" class="sched-recur" value="2"> Week 2</label>
                            <label><input type="checkbox" class="sched-recur" value="3"> Week 3</label>
                            <label><input type="checkbox" class="sched-recur" value="4"> Week 4</label>
                            <label><input type="checkbox" class="sched-recur" value="5"> Week 5</label>
                        </div>
                    </div>
                    <button type="submit" class="primary-btn" style="width:100%;">Save timetable</button>
                </form>
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
    </div>
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
    var viewMonth = <?= json_encode($month, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var selectedLessonId = <?= (int) ($selectedLesson !== null ? $selectedLesson['lesson_id'] : 0) ?>;

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

    // Empty-cell click prefills the create form (admin).
    var createForm = document.getElementById('schedCreateForm');
    document.querySelectorAll('.sched-cell').forEach(function (cell) {
        cell.addEventListener('dblclick', function () {
            if (!createForm) return;
            var d = document.getElementById('schedDate');
            var s = document.getElementById('schedStart');
            if (d) d.value = cell.getAttribute('data-date') || '';
            if (s && cell.getAttribute('data-slot')) s.value = cell.getAttribute('data-slot');
            document.getElementById('schedDetailPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    // Teacher clash + availability note (admin create form).
    var teacherSel = document.getElementById('schedTeacher');
    var dateInput = document.getElementById('schedDate');
    var startInput = document.getElementById('schedStart');
    var endInput = document.getElementById('schedEnd');
    var note = document.getElementById('schedTeacherNote');
    function refreshTeacherNote() {
        if (!teacherSel || !note) return;
        var tid = parseInt(teacherSel.value, 10) || 0;
        if (!tid) { note.textContent = 'Pick a teacher to see availability and clash warnings.'; note.classList.remove('is-clash'); return; }
        var av = availMap[tid] || availMap[String(tid)] || [];
        var d = dateInput && dateInput.value ? dateInput.value : '';
        var dow = d ? new Date(d + 'T12:00:00').getDay() : 0; // 0 Sun..6 Sat
        var dowIso = dow === 0 ? 7 : dow;
        var avText = 'No availability saved for this teacher.';
        if (av.length) {
            var mine = av.filter(function (s) { return !d || parseInt(s.dow, 10) === dowIso; });
            var show = d ? mine : av;
            avText = show.length
                ? 'Available: ' + show.map(function (s) { return 'D' + s.dow + ' ' + s.start + '-' + s.end; }).join(', ')
                : 'Teacher is not marked available on this weekday.';
        }
        var clash = false;
        if (d && startInput.value && endInput.value) {
            var sm = toMin(startInput.value), em = toMin(endInput.value);
            clash = lessonsWeek.some(function (l) {
                return l.date === d && l.teacher_id === tid && toMin(l.start) < em && toMin(l.end) > sm;
            });
        }
        note.textContent = avText + (clash ? ' WARNING: overlaps another lesson for this teacher (still allowed).' : d ? ' No overlap on this slot.' : '');
        note.classList.toggle('is-clash', clash);
    }
    ['change', 'input'].forEach(function (ev) {
        if (teacherSel) teacherSel.addEventListener(ev, refreshTeacherNote);
        if (dateInput) dateInput.addEventListener(ev, refreshTeacherNote);
        if (startInput) startInput.addEventListener(ev, refreshTeacherNote);
        if (endInput) endInput.addEventListener(ev, refreshTeacherNote);
    });
    refreshTeacherNote();

    // Create submit (admin).
    if (createForm) {
        createForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var checked = createForm.querySelectorAll('input[name="student_ids[]"]:checked');
            if (checked.length === 0 && !window.confirm('This lesson has no students. Save anyway?')) return;
            var fd = new FormData(createForm);
            fd.append('action', 'create');
            // Convert ticked recurrence weeks -> extra dates (same weekday in viewed month).
            var base = dateInput.value;
            var ticks = Array.prototype.slice.call(document.querySelectorAll('.sched-recur:checked')).map(function (c) { return parseInt(c.value, 10); });
            if (base && ticks.length) {
                var parts = viewMonth.split('-');
                var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
                var dim = new Date(y, m, 0).getDate();
                var ranges = { 1: [1, 7], 2: [8, 14], 3: [15, 21], 4: [22, 28], 5: [29, dim] };
                var baseDow = new Date(base + 'T12:00:00').getDay();
                var extra = [];
                ticks.forEach(function (w) {
                    var r = ranges[w];
                    for (var d = r[0]; d <= Math.min(r[1], dim); d++) {
                        var iso = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                        if (iso === base) continue;
                        if (new Date(iso + 'T12:00:00').getDay() === baseDow) extra.push(iso);
                    }
                });
                if (extra.length) fd.append('recurrence_dates', extra.join(','));
            }
            var btn = createForm.querySelector('button[type="submit"]');
            btn.disabled = true; btn.textContent = 'Saving...';
            fetch('<?= e(url_path('api/lessons_save.php')) ?>', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) { window.location.reload(); }
                    else { showMsg('schedCreateMsg', data.error || 'Save failed.', false); btn.disabled = false; btn.textContent = 'Save timetable'; }
                })
                .catch(function (err) { showMsg('schedCreateMsg', 'Network error: ' + err.message, false); btn.disabled = false; btn.textContent = 'Save timetable'; });
        });
    }

    // Detail actions (admin).
    function postForm(url, fd, onOk) {
        fetch(url, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.success) { window.location.reload(); } else { alert(data.error || 'Failed.'); } })
            .catch(function (err) { alert('Network error: ' + err.message); });
    }
    var delBtn = document.getElementById('schedDeleteBtn');
    if (delBtn) delBtn.addEventListener('click', function () {
        if (!window.confirm('Delete this lesson and its enrollments?')) return;
        var fd = new FormData(); fd.append('action', 'delete'); fd.append('lesson_id', String(selectedLessonId));
        postForm('<?= e(url_path('api/lessons_save.php')) ?>', fd);
    });
    var stBtn = document.getElementById('schedStatusBtn');
    if (stBtn) stBtn.addEventListener('click', function () {
        var v = window.prompt('Status (SCHEDULED / COMPLETED / CANCELLED):', 'COMPLETED');
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
