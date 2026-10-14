<?php
// Dedicated class details page: metadata on top, teacher block left,
// student block right. One series (or one-off group) x one month.
// Display-only; edits happen on transactions.php and schedule.php.

require_once __DIR__ . '/includes/config.php';

$user = current_user();
if (!$user) {
    redirect_to('account.php?mode=login');
}
$role = normalize_role($user['role'] ?? 'GUEST');
if (!in_array($role, ['ADMIN', 'TEACHER', 'STUDENT'], true)) {
    redirect_to('account.php');
}

$group = trim($_GET['group'] ?? '');
if ($group !== '' && preg_match('/^[0-9a-fA-F]{1,64}$/', $group) !== 1) {
    $group = '';
}
$month = trim($_GET['month'] ?? date('Y-m'));
if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    $month = date('Y-m');
}

$loadError = null;
$series = ['name' => $group !== '' ? 'Series #' . substr($group, 0, 8) : 'One-off lessons', 'pattern' => [], 'teacher' => null, 'teacher_id' => 0, 'rate' => ['PERCENT', 0.0], 'comm_due' => 0.0, 'comm_open' => false, 'cproofs' => []];
$students = [];
$feeTotal = 0.0;
$feePaid = 0.0;

if (!is_database_connected()) {
    $loadError = 'Transaction tables are missing. Import database/psm_lessons.sql, then reload.';
} else {
    try {
        $db = db();
        // Lessons of this series in this month.
        $sql = 'SELECT l.lesson_id, l.lesson_date, l.title, l.start_time, l.end_time, l.status,
                       l.teacher_id, l.commission_type, l.commission_value, l.commission_status, l.commission_proof_path,
                       tu.username AS teacher_name, tu.email AS teacher_email
                FROM lessons l LEFT JOIN users tu ON tu.user_id = l.teacher_id
                WHERE SUBSTR(l.lesson_date, 1, 7) = :month';
        $params = ['month' => $month];
        if ($group !== '') {
            $sql .= ' AND l.recurrence_group = :g';
            $params['g'] = $group;
        } else {
            $sql .= " AND (l.recurrence_group IS NULL OR l.recurrence_group = '')";
        }
        if ($role === 'TEACHER') {
            $sql .= ' AND l.teacher_id = :me';
            $params['me'] = (int) $user['user_id'];
        }
        $sql .= ' ORDER BY l.lesson_date ASC, l.start_time ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($role === 'TEACHER' && $group !== '' && $lessons === []) {
            $loadError = 'This class is not assigned to you.';
        } else {
            $dows = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $titles = [];
            $teachers = [];
            $commVotes = [];
            foreach ($lessons as $l) {
                $t = trim((string) ($l['title'] ?? ''));
                if ($t !== '') {
                    $titles[$t] = ($titles[$t] ?? 0) + 1;
                }
                if (!empty($l['teacher_name'])) {
                    $teachers[$l['teacher_name']] = ($teachers[$l['teacher_name']] ?? 0) + 1;
                    if (empty($series['teacher'])) {
                        $series['teacher'] = $l['teacher_name'];
                        $series['teacher_id'] = (int) $l['teacher_id'];
                        $series['teacher_email'] = $l['teacher_email'] ?? '';
                    }
                }
                $ck = strtoupper(trim((string) ($l['commission_type'] ?? ''))) . '|' . trim((string) ($l['commission_value'] ?? ''));
                $commVotes[$ck] = ($commVotes[$ck] ?? 0) + 1;
                $dow = (int) date('N', strtotime(substr((string) $l['lesson_date'], 0, 10) . 'T12:00:00'));
                $slot = substr((string) $l['start_time'], 0, 5) . '–' . substr((string) $l['end_time'], 0, 5);
                $series['pattern'][$dows[$dow - 1]] = $slot;
                if (($l['commission_status'] ?? '') !== 'PAID') {
                    $series['comm_open'] = true;
                }
                if (!empty($l['commission_proof_path'])) {
                    $series['cproofs'][$l['commission_proof_path']] = substr((string) $l['lesson_date'], 0, 10);
                }
            }
            if (!empty($titles)) {
                arsort($titles);
                $series['name'] = (string) array_key_first($titles);
            }
            $rateType = '';
            $rateVal = '';
            if (!empty($commVotes)) {
                arsort($commVotes);
                $parts = explode('|', (string) array_key_first($commVotes), 2);
                $rateType = $parts[0] ?? '';
                $rateVal = $parts[1] ?? '';
            }
            if ($lessonsManager !== null) {
                [$rct, $rcv] = $lessonsManager->resolveCommission(
                    $series['teacher_id'] > 0 ? $series['teacher_id'] : null,
                    $rateType !== '' ? $rateType : null,
                    $rateVal !== '' ? $rateVal : null
                );
                $series['rate'] = [$rct, (float) $rcv];
            }
            // Monthly records + enrolled roster.
            $stmt = $db->prepare(
                'SELECT mf.student_id, su.username AS student_name, mf.amount, mf.status, mf.proof_path
                 FROM monthly_fees mf INNER JOIN users su ON su.user_id = mf.student_id
                 WHERE mf.month = :month AND COALESCE(mf.recurrence_group, \'\') = :g'
            );
            $stmt->execute(['month' => $month, 'g' => $group]);
            $monthly = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $monthly[(int) $row['student_id']] = $row;
                $feeTotal += (float) $row['amount'];
                if ($row['status'] === 'PAID') {
                    $feePaid += (float) $row['amount'];
                }
            }
            $stmt = $db->prepare(
                'SELECT DISTINCT e.student_id, su.username AS student_name
                 FROM lesson_enrollments e
                 INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                 INNER JOIN users su ON su.user_id = e.student_id
                 WHERE SUBSTR(l.lesson_date, 1, 7) = :month'
                . ($group !== '' ? ' AND l.recurrence_group = :g' : " AND (l.recurrence_group IS NULL OR l.recurrence_group = '')")
            );
            $rp = ['month' => $month];
            if ($group !== '') {
                $rp['g'] = $group;
            }
            $stmt->execute($rp);
            $roster = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($role === 'STUDENT') {
                $roster = array_values(array_filter($roster, function ($r) use ($user) {
                    return (int) $r['student_id'] === (int) $user['user_id'];
                }));
            } elseif ($role === 'TEACHER') {
                $mine = [];
                $stmt2 = $db->prepare(
                    'SELECT DISTINCT e.student_id FROM lesson_enrollments e
                     INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                     WHERE SUBSTR(l.lesson_date, 1, 7) = :month AND l.teacher_id = :me'
                    . ($group !== '' ? ' AND l.recurrence_group = :g' : " AND (l.recurrence_group IS NULL OR l.recurrence_group = '')")
                );
                $tp = ['month' => $month, 'me' => (int) $user['user_id']];
                if ($group !== '') {
                    $tp['g'] = $group;
                }
                $stmt2->execute($tp);
                foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    $mine[(int) $sid] = true;
                }
                $roster = array_values(array_filter($roster, function ($r) use ($mine) {
                    return isset($mine[(int) $r['student_id']]);
                }));
            }
            foreach ($roster as $r) {
                $sid = (int) $r['student_id'];
                $m = $monthly[$sid] ?? null;
                // Lesson proofs for this student in this series+month.
                $lp = $db->prepare(
                    'SELECT DISTINCT e.proof_path, l.lesson_date FROM lesson_enrollments e
                     INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                     WHERE e.student_id = :sid AND SUBSTR(l.lesson_date, 1, 7) = :month'
                    . ($group !== '' ? ' AND l.recurrence_group = :g' : " AND (l.recurrence_group IS NULL OR l.recurrence_group = '')")
                    . " AND e.proof_path IS NOT NULL AND e.proof_path != '' ORDER BY l.lesson_date ASC"
                );
                $lpw = ['sid' => $sid, 'month' => $month];
                if ($group !== '') {
                    $lpw['g'] = $group;
                }
                $lp->execute($lpw);
                $lproofs = $lp->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $students[] = [
                    'id' => $sid,
                    'name' => (string) $r['student_name'],
                    'amount' => $m !== null ? (float) $m['amount'] : null,
                    'status' => $m !== null ? (string) $m['status'] : null,
                    'proof' => ($m !== null && !empty($m['proof_path'])) ? (string) $m['proof_path'] : null,
                    'lproofs' => $lproofs,
                ];
            }
            $series['comm_due'] = $lessonsManager !== null
                ? $lessonsManager->commissionPayout($series['rate'][0], $series['rate'][1], $feePaid)
                : 0.0;
            if ($role === 'STUDENT' && $students === []) {
                $loadError = 'No records for you in this class and month.';
            }
        }
    } catch (Throwable $e) {
        $loadError = 'Class details could not be loaded.';
    }
}

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/admin.css?v=' . filemtime(__DIR__ . '/assets/css/admin.css')); ?>">
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/transactions.css?v=' . filemtime(__DIR__ . '/assets/css/transactions.css')); ?>">

<div class="txn-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <p class="eyebrow">Class Details</p>
            <h1 style="margin:0;"><?= e($series['name']) ?> · <?= e($month) ?></h1>
        </div>
        <a href="<?= e(url_path('transactions.php')) ?>" class="text-button">← Transactions</a>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php else: ?>
        <div class="txn-panel">
            <h2>Class info</h2>
            <p style="margin:0;font-size:14px;">
                <?= $group !== '' ? 'Series <strong>#' . e(substr($group, 0, 8)) . '</strong> · ' : '' ?>Schedule:
                <strong><?= empty($series['pattern']) ? '—' : e(implode(', ', array_map(function ($d, $s) { return $d . ' ' . $s; }, array_keys($series['pattern']), $series['pattern']))) ?></strong>
                · Teacher: <strong><?= e($series['teacher'] ?? 'Unassigned') ?></strong>
                · Month fees: <strong>RM <?= e(number_format($feeTotal, 2)) ?></strong> (RM <?= e(number_format($feePaid, 2)) ?> received)
                · Commission due: <strong>RM <?= e(number_format((float) $series['comm_due'], 2)) ?></strong>
            </p>
        </div>

        <div class="txn-commission" style="margin-top:16px;">
            <div class="txn-panel">
                <h2>Teacher</h2>
                <p style="margin:0 0 6px;font-size:14px;"><strong><?= e($series['teacher'] ?? 'Unassigned') ?></strong></p>
                <p style="margin:0 0 6px;font-size:13px;color:#666;">Commission: <?= e($series['rate'][0]) ?> <?= e(number_format((float) $series['rate'][1], 2)) ?> → RM <?= e(number_format((float) $series['comm_due'], 2)) ?> due (<?= $series['comm_open'] ? 'open' : 'paid' ?>)</p>
                <?php if (empty($series['cproofs'])): ?>
                    <p style="color:#999;font-size:12px;">No commission proofs.</p>
                <?php else: ?>
                    <?php foreach ($series['cproofs'] as $p => $d): ?>
                        <a class="txn-proof-link" href="<?= e(url_path($p)) ?>" target="_blank" rel="noopener">Commission proof (<?= e($d) ?>)</a><br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="txn-panel">
                <h2>Students (<?= count($students) ?>)</h2>
                <?php if (empty($students)): ?>
                    <p style="color:#999;font-size:13px;">No students.</p>
                <?php else: ?>
                    <table class="data-table modern-table">
                        <thead><tr><th>Student</th><th>Fee (RM)</th><th>Status</th><th>Proof</th></tr></thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr>
                                    <td><?= e($s['name']) ?></td>
                                    <td><?= $s['amount'] !== null ? 'RM ' . e(number_format((float) $s['amount'], 2)) : '—' ?></td>
                                    <td><?= $s['status'] !== null ? e($s['status']) : '—' ?></td>
                                    <td>
                                        <?= $s['proof'] !== null ? '<a class="txn-proof-link" href="' . e(url_path($s['proof'])) . '" target="_blank" rel="noopener">Receipt</a>' : '—' ?>
                                        <?php foreach ($s['lproofs'] as $lp): ?>
                                            <br><a class="txn-proof-link" href="<?= e(url_path($lp['proof_path'])) ?>" target="_blank" rel="noopener">Lesson <?= e(substr((string) $lp['lesson_date'], 0, 10)) ?></a>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
