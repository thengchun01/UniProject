<?php
// Transactions: per-student-per-lesson rows with totals, role-scoped.
require_once __DIR__ . '/includes/config.php';

$user = current_user();
if (!$user) {
    redirect_to('account.php?mode=login');
}
$role = normalize_role($user['role'] ?? 'GUEST');
if (!in_array($role, ['ADMIN', 'TEACHER', 'STUDENT'], true)) {
    redirect_to('account.php');
}

$txAvailable = ($lessonsManager !== null);
$loadError = $txAvailable ? null : 'Transaction tables are missing. Import database/psm_lessons.sql, then reload.';

// ---- filters (function.md §4: months multi-select replaces the time range) ----
$monthsRaw = $_GET['months'] ?? [];
if (!is_array($monthsRaw)) {
    $monthsRaw = [$monthsRaw];
}
$months = [];
foreach ($monthsRaw as $m) {
    $m = trim((string) $m);
    if (preg_match('/^\d{4}-\d{2}$/', $m) === 1) {
        $months[] = $m;
    }
}
$months = array_values(array_unique($months));
$curYm = date('Y-m');
$stripYear = trim($_GET['year'] ?? date('Y'));
if (preg_match('/^\d{4}$/', $stripYear) !== 1 || (int) $stripYear < 2000 || (int) $stripYear > 2100) {
    $stripYear = date('Y');
}
if ($months === []) {
    $months = [$stripYear === date('Y') ? $curYm : $stripYear . '-01']; // default: current month, else January
}
$lessonStatus = strtoupper(trim($_GET['lesson_status'] ?? ''));
$payStatus = strtoupper(trim($_GET['payment'] ?? ''));
$teacherFilter = (int) ($_GET['teacher_id'] ?? 0);
$lessonFilter = (int) ($_GET['lesson_id'] ?? 0);
$studentFilter = (int) ($_GET['student_id'] ?? 0);
if (!in_array($lessonStatus, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
    $lessonStatus = '';
}
if (!in_array($payStatus, ['UNPAID', 'PENDING', 'PAID'], true)) {
    $payStatus = '';
}

$rows = [];
$combos = [];
$totals = ['fee' => 0.0, 'paid' => 0.0, 'lessons' => 0, 'comm_due' => 0.0, 'comm_paid' => 0.0];
$incompleteLessons = [];
$defaultCommission = ['PERCENT', 0.0];
$teacherOverrides = [];
$teachers = [];
$students = [];
$studentFilterName = null;
$proofLogs = [];

if ($txAvailable && is_database_connected()) {
    try {
        $db = db();
        // Issue 32: keep statuses current before listing.
        $lessonsManager->autoCompletePast();
        $stmt = $db->query("SELECT user_id, username FROM users WHERE role = 'TEACHER' ORDER BY username ASC");
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $db->query("SELECT user_id, username FROM users WHERE role = 'STUDENT' ORDER BY username ASC");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($studentFilter > 0) {
            $stmt = $db->prepare('SELECT username FROM users WHERE user_id = :id AND role = \'STUDENT\' LIMIT 1');
            $stmt->execute(['id' => $studentFilter]);
            $studentFilterName = $stmt->fetchColumn();
            if ($studentFilterName === false) {
                $studentFilterName = null;
                $studentFilter = 0;
            }
        }
        $defaultCommission = $lessonsManager->getDefaultCommission();

        $stmt = $db->query('SELECT teacher_id, commission_type, commission_value FROM teacher_commission');
        $teacherOverrides = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $where = [];
        $params = [];
        $monthHolders = [];
        foreach ($months as $i => $m) {
            $monthHolders[] = ':month' . $i;
            $params['month' . $i] = $m;
        }
        $where[] = 'SUBSTR(l.lesson_date, 1, 7) IN (' . implode(',', $monthHolders) . ')';
        if ($role === 'TEACHER') {
            $where[] = 'l.teacher_id = :me';
            $params['me'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            // Students always see only their own rows, whatever the URL carries.
            $where[] = 'e.student_id = :me';
            $params['me'] = (int) $user['user_id'];
        } elseif ($teacherFilter > 0) {
            $where[] = 'l.teacher_id = :tfilter';
            $params['tfilter'] = $teacherFilter;
        }
        // Issue 62: filter to one student's transactions (admin/teacher scope applies above).
        if ($role !== 'STUDENT' && $studentFilter > 0) {
            $where[] = 'e.student_id = :sfilter';
            $params['sfilter'] = $studentFilter;
        }
        if ($payStatus !== '') {
            // Payment now lives on monthly records: keep combos having one in this state.
            $where[] = "EXISTS (SELECT 1 FROM monthly_fees mfq WHERE mfq.student_id = e.student_id AND mfq.month = SUBSTR(l.lesson_date, 1, 7) AND COALESCE(mfq.recurrence_group, '') = COALESCE(l.recurrence_group, '') AND mfq.status = :pstatus)";
            $params['pstatus'] = $payStatus;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT l.recurrence_group AS g, l.lesson_id, l.lesson_date, l.title, l.start_time, l.end_time,
                       l.teacher_id, l.commission_type, l.commission_value, l.commission_status, l.status AS lesson_status,
                       l.commission_proof_path, tu.username AS teacher_name,
                       e.enrollment_id, e.student_id, su.username AS student_name,
                       e.fee_amount, e.payment_status, e.proof_path,
                       mf.fee_id, mf.amount AS mamount, mf.status AS mstatus, mf.proof_path AS mproof
                FROM lesson_enrollments e
                INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                INNER JOIN users su ON su.user_id = e.student_id
                LEFT JOIN users tu ON tu.user_id = l.teacher_id
                LEFT JOIN monthly_fees mf ON mf.student_id = e.student_id
                    AND mf.month = SUBSTR(l.lesson_date, 1, 7)
                    AND COALESCE(mf.recurrence_group, '') = COALESCE(l.recurrence_group, '')
                $whereSql ORDER BY SUBSTR(l.lesson_date, 1, 7), l.recurrence_group, su.username, l.lesson_date";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Collapse to one row per student x class x month.
        $combos = [];
        foreach ($rows as $r) {
            $g = (string) ($r['g'] ?? '');
            $m = substr((string) $r['lesson_date'], 0, 7);
            $sid = (int) $r['student_id'];
            $key = $m . '|' . ($g !== '' ? 'G' . $g : 'GEN') . '|' . $sid;
            if (!isset($combos[$key])) {
                $combos[$key] = [
                    'month' => $m, 'g' => $g, 'sid' => $sid, 'sname' => (string) $r['student_name'],
                    'lessons' => [], 'titles' => [], 'teachers' => [], 'tids' => [], 'comm' => [],
                    'fee_id' => null, 'amount' => null, 'mstatus' => null, 'mproof' => null,
                    'enrollment_id' => null, 'cproof' => null, 'proof_lesson_id' => null,
                ];
            }
            $c = &$combos[$key];
            $lid = (int) $r['lesson_id'];
            $c['lessons'][$lid] = [
                'date' => substr((string) $r['lesson_date'], 0, 10),
                'status' => (string) $r['lesson_status'],
                'cstatus' => (string) ($r['commission_status'] ?? ''),
                'cproof' => $r['commission_proof_path'],
                'proof' => $r['proof_path'],
                'start' => substr((string) $r['start_time'], 0, 5),
                'end' => substr((string) $r['end_time'], 0, 5),
            ];
            if ($c['enrollment_id'] === null) $c['enrollment_id'] = (int) $r['enrollment_id'];
            if (!empty($r['commission_proof_path']) && empty($c['cproof'])) {
                $c['cproof'] = (string) $r['commission_proof_path'];
                $c['proof_lesson_id'] = $lid;
            } elseif ($c['proof_lesson_id'] === null) {
                $c['proof_lesson_id'] = $lid;
            }
            $t = trim((string) ($r['title'] ?? ''));
            if ($t !== '') {
                $c['titles'][$t] = ($c['titles'][$t] ?? 0) + 1;
            }
            $tn = trim((string) ($r['teacher_name'] ?? ''));
            if ($tn !== '') {
                $c['teachers'][$tn] = ($c['teachers'][$tn] ?? 0) + 1;
                $c['tids'][$tn] = $r['teacher_id'] !== null ? (int) $r['teacher_id'] : 0;
            }
            $ck = strtoupper(trim((string) ($r['commission_type'] ?? ''))) . '|' . trim((string) ($r['commission_value'] ?? ''));
            $c['comm'][$ck] = ($c['comm'][$ck] ?? 0) + 1;
            if ($r['fee_id'] !== null) {
                $c['fee_id'] = (int) $r['fee_id'];
                $c['amount'] = (float) $r['mamount'];
                $c['mstatus'] = (string) $r['mstatus'];
                $c['mproof'] = $r['mproof'];
            }
            unset($c);
        }
        // Lesson-status and single-lesson drill filters apply per combo.
        foreach ($combos as $key => $c) {
            if ($lessonStatus !== '') {
                $hit = false;
                foreach ($c['lessons'] as $li) {
                    if ($li['status'] === $lessonStatus) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    unset($combos[$key]);
                    continue;
                }
            }
            if ($lessonFilter > 0 && !isset($c['lessons'][$lessonFilter])) {
                unset($combos[$key]);
            }
        }
        // Monthly records without enrollment coverage stay visible too.
        try {
            $ows = [];
            $op = [];
            $omh = [];
            foreach ($months as $i => $m) {
                $omh[] = ':omonth' . $i;
                $op['omonth' . $i] = $m;
            }
            $ows[] = 'mf.month IN (' . implode(',', $omh) . ')';
            if ($role === 'TEACHER') {
                $ows[] = "EXISTS (SELECT 1 FROM lesson_enrollments e2 INNER JOIN lessons l2 ON l2.lesson_id = e2.lesson_id WHERE e2.student_id = mf.student_id AND SUBSTR(l2.lesson_date, 1, 7) = mf.month AND l2.teacher_id = :ome)";
                $op['ome'] = (int) $user['user_id'];
            } elseif ($role === 'STUDENT') {
                $ows[] = 'mf.student_id = :ome';
                $op['ome'] = (int) $user['user_id'];
            } elseif ($studentFilter > 0) {
                $ows[] = 'mf.student_id = :osf';
                $op['osf'] = $studentFilter;
            }
            $orphanWanted = ($payStatus === '' && $lessonStatus === '' && $lessonFilter <= 0);
            if ($payStatus !== '') {
                $ows[] = 'mf.status = :opstatus';
                $op['opstatus'] = $payStatus;
            }
            if ($orphanWanted) {
            $ostmt = $db->prepare(
                'SELECT mf.fee_id, mf.student_id, su.username AS student_name, mf.month,
                        mf.recurrence_group AS g, mf.amount, mf.status, mf.proof_path
                 FROM monthly_fees mf
                 INNER JOIN users su ON su.user_id = mf.student_id
                 LEFT JOIN lesson_enrollments e ON e.student_id = mf.student_id
                 LEFT JOIN lessons l ON l.lesson_id = e.lesson_id
                     AND SUBSTR(l.lesson_date, 1, 7) = mf.month
                     AND COALESCE(l.recurrence_group, \'\') = COALESCE(mf.recurrence_group, \'\')
                 WHERE ' . implode(' AND ', $ows) . '
                 GROUP BY mf.fee_id HAVING COUNT(e.enrollment_id) = 0'
            );
            $ostmt->execute($op);
            foreach ($ostmt->fetchAll(PDO::FETCH_ASSOC) as $orow) {
                $og = (string) ($orow['g'] ?? '');
                $okey = $orow['month'] . '|' . ($og !== '' ? 'G' . $og : 'GEN') . '|' . (int) $orow['student_id'];
                if (isset($combos[$okey])) {
                    continue;
                }
                $combos[$okey] = [
                    'month' => (string) $orow['month'], 'g' => $og, 'sid' => (int) $orow['student_id'],
                    'sname' => (string) $orow['student_name'], 'lessons' => [], 'titles' => [],
                    'teachers' => [], 'tids' => [], 'comm' => [],
                    'fee_id' => (int) $orow['fee_id'], 'amount' => (float) $orow['amount'],
                    'mstatus' => (string) $orow['status'], 'mproof' => $orow['proof_path'],
                    'enrollment_id' => null, 'cproof' => null, 'proof_lesson_id' => null,
                ];
            }
            }
        } catch (Throwable $e) {
            // Orphan visibility is best-effort; enrollment combos already loaded.
        }
        // Calculate commission per lesson using each student's saved monthly fee,
        // regardless of whether the student's transaction is PAID or UNPAID.
        // Commission due is separate from the student's payment status.
        // Distribute the monthly amount across lessons according to each lesson fee.
        $commissionByMonthGroup = [];
        $commissionMonths = array_values(array_unique($months));
        if ($commissionMonths !== []) {
            $commissionHolders = [];
            $commissionParams = [];
            foreach ($commissionMonths as $i => $commissionMonth) {
                $commissionHolders[] = ':commission_month_' . $i;
                $commissionParams['commission_month_' . $i] = $commissionMonth;
            }
            $commissionSql = "SELECT l.lesson_id, l.lesson_date, l.recurrence_group AS g, l.teacher_id,
                                     l.commission_type, l.commission_value,
                                     e.student_id, e.fee_amount,
                                     mf.amount AS monthly_amount, mf.status AS monthly_status
                              FROM lessons l
                              INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id
                              INNER JOIN monthly_fees mf ON mf.student_id = e.student_id
                                  AND mf.month = SUBSTR(l.lesson_date, 1, 7)
                                  AND COALESCE(mf.recurrence_group, '') = COALESCE(l.recurrence_group, '')
                              WHERE SUBSTR(l.lesson_date, 1, 7) IN (" . implode(',', $commissionHolders) . ")";
            $commissionStmt = $db->prepare($commissionSql);
            $commissionStmt->execute($commissionParams);
            $commissionRows = $commissionStmt->fetchAll(PDO::FETCH_ASSOC);

            $studentFeeTotals = [];
            $lessonCounts = [];
            $commissionLessons = [];
            foreach ($commissionRows as $commissionRow) {
                $commissionMonth = substr((string) $commissionRow['lesson_date'], 0, 7);
                $commissionGroup = (string) ($commissionRow['g'] ?? '');
                $groupKey = $commissionMonth . '|' . $commissionGroup;
                $studentKey = $groupKey . '|' . (int) $commissionRow['student_id'];
                $lessonKey = $groupKey . '|' . (int) $commissionRow['lesson_id'];
                $studentFeeTotals[$studentKey] = ($studentFeeTotals[$studentKey] ?? 0.0) + max(0.0, (float) $commissionRow['fee_amount']);
                $commissionLessons[$lessonKey] = $commissionRow;
                $lessonCounts[$groupKey][$lessonKey] = true;
            }
            $lessonPaidBases = [];
            $commissionRateSets = [];
            foreach ($commissionRows as $commissionRow) {
                $commissionMonth = substr((string) $commissionRow['lesson_date'], 0, 7);
                $commissionGroup = (string) ($commissionRow['g'] ?? '');
                $groupKey = $commissionMonth . '|' . $commissionGroup;
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
                [$commissionType, $commissionValue] = $lessonsManager->resolveCommission(
                    $commissionRow['teacher_id'] !== null ? (int) $commissionRow['teacher_id'] : null,
                    $commissionRow['commission_type'],
                    $commissionRow['commission_value']
                );
                $commissionMonth = substr((string) $commissionRow['lesson_date'], 0, 7);
                $commissionGroup = (string) ($commissionRow['g'] ?? '');
                $groupKey = $commissionMonth . '|' . $commissionGroup;
                $rateKey = $commissionType . '|' . number_format((float) $commissionValue, 2, '.', '');
                $commissionRateSets[$groupKey][$rateKey] = [$commissionType, (float) $commissionValue];
                if ($commissionType === 'PERCENT') {
                    // Sum the fee bases first, then round the percentage once per rate/month/group.
                    $percentBasesByRate[$groupKey][$rateKey] = ($percentBasesByRate[$groupKey][$rateKey] ?? 0.0) + $paidBasis;
                } else {
                    // Fixed commission is charged per lesson, so keep each lesson payout separate.
                    $commissionByMonthGroup[$groupKey] = ($commissionByMonthGroup[$groupKey] ?? 0.0)
                        + $lessonsManager->commissionPayout($commissionType, (float) $commissionValue, $paidBasis);
                }
            }
            foreach ($percentBasesByRate as $groupKey => $rates) {
                foreach ($rates as $rateKey => $totalBasis) {
                    [$commissionType, $commissionValue] = $commissionRateSets[$groupKey][$rateKey];
                    $commissionByMonthGroup[$groupKey] = ($commissionByMonthGroup[$groupKey] ?? 0.0)
                        + $lessonsManager->commissionPayout($commissionType, (float) $commissionValue, (float) $totalBasis);
                }
            }
            foreach ($combos as $comboKey => $combo) {
                $comboGroupKey = $combo['month'] . '|' . $combo['g'];
                $rates = array_values($commissionRateSets[$comboGroupKey] ?? []);
                if (count($rates) === 1) {
                    $combos[$comboKey]['rate'] = $rates[0];
                } elseif (count($rates) > 1) {
                    $combos[$comboKey]['rate_label'] = 'Mixed rates';
                }
            }
        }

        // Finalize display fields + header totals from monthly records.
        $seenFee = [];
        $seriesPaid = [];
        $seriesDue = [];
        foreach ($combos as $key => $c) {
            if (!empty($c['titles'])) {
                arsort($c['titles']);
                $combos[$key]['dname'] = (string) array_key_first($c['titles']);
            } else {
                $combos[$key]['dname'] = $c['g'] !== '' ? 'Series #' . substr($c['g'], 0, 8) : 'One-off lessons';
            }
            $tname = 'Unassigned';
            $tid = 0;
            if (!empty($c['teachers'])) {
                arsort($c['teachers']);
                $tname = (string) array_key_first($c['teachers']);
                $tid = (int) ($c['tids'][$tname] ?? 0);
            }
            $combos[$key]['tname'] = $tname;
            $combos[$key]['tid'] = $tid;
            $ctype = '';
            $cvalue = '';
            if (!empty($c['comm'])) {
                arsort($c['comm']);
                $parts = explode('|', (string) array_key_first($c['comm']), 2);
                $ctype = $parts[0] ?? '';
                $cvalue = $parts[1] ?? '';
            }
            $combos[$key]['ctype'] = $ctype;
            $combos[$key]['cvalue'] = $cvalue;
            [$rct, $rcv] = $lessonsManager->resolveCommission($tid > 0 ? $tid : null, $ctype !== '' ? $ctype : null, $cvalue !== '' ? $cvalue : null);
            $combos[$key]['rate'] = [$rct, (float) $rcv];
            if ($c['fee_id'] !== null && !isset($seenFee[$c['fee_id']])) {
                $seenFee[$c['fee_id']] = true;
                $totals['fee'] += (float) $c['amount'];
                if ($c['mstatus'] === 'PAID') {
                    $totals['paid'] += (float) $c['amount'];
                    $seriesPaid[$c['month'] . '|' . $c['g']] = ($seriesPaid[$c['month'] . '|' . $c['g']] ?? 0.0) + (float) $c['amount'];
                }
            }
        }
        foreach ($combos as $key => $c) {
            $skey = $c['month'] . '|' . $c['g'];
            $due = (float) ($commissionByMonthGroup[$skey] ?? 0.0);
            $combos[$key]['due'] = $due;
            if (!isset($seriesDue[$skey])) {
                $seriesDue[$skey] = $due;
                $totals['comm_due'] += $due;
                $open = false;
                foreach ($combos as $c2) {
                    if ($c2['month'] . '|' . $c2['g'] !== $skey) {
                        continue;
                    }
                    foreach ($c2['lessons'] as $li) {
                        if ($li['cstatus'] !== 'PAID') {
                            $open = true;
                            break 2;
                        }
                    }
                }
                $seriesDue[$skey . '_open'] = $open;
                if (!$open) {
                    $totals['comm_paid'] += $due;
                }
            }
            $combos[$key]['series_paid'] = !($seriesDue[$skey . '_open'] ?? false);
            if (($c['mstatus'] ?? null) !== 'PAID' || ($seriesDue[$skey . '_open'] ?? false)) {
                $incompleteLessons[$key] = true;
            }
        }
        uasort($combos, function ($a, $b) {
            $c = strcmp($a['month'], $b['month']);
            if ($c !== 0) {
                return $c;
            }
            $c = strcmp($a['dname'] ?? '', $b['dname'] ?? '');
            if ($c !== 0) {
                return $c;
            }
            return strcmp($a['sname'], $b['sname']);
        });
        $totals['lessons'] = count(array_unique(array_map(function ($c) {
            return $c['month'] . '|' . $c['g'];
        }, $combos)));

        if ($role === 'ADMIN') {
            $logSql = 'SELECT pl.log_id, pl.kind, pl.lesson_id, pl.enrollment_id, pl.file_path, pl.action, pl.created_at, u.username AS performer
                       FROM proof_logs pl LEFT JOIN users u ON u.user_id = pl.performed_by '
                . ($lessonFilter > 0 ? 'WHERE pl.lesson_id = :lid ' : '')
                . 'ORDER BY pl.created_at DESC, pl.log_id DESC LIMIT 50';
            $stmt = $db->prepare($logSql);
            if ($lessonFilter > 0) {
                $stmt->execute(['lid' => $lessonFilter]);
            } else {
                $stmt->execute();
            }
            $proofLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $loadError = 'Transaction data could not be loaded. Confirm database/psm_lessons.sql has been imported.';
        $rows = [];
    }
}

// Month strip aggregates (status colors), role-scoped like the rows.
// Incomplete = unpaid fees, paid-without-proof, or unpaid commission.
$monthStats = [];
if ($txAvailable && is_database_connected()) {
    try {
        $swhere = ['l.lesson_date >= :sy0', 'l.lesson_date <= :sy1'];
        $sparams = ['sy0' => $stripYear . '-01-01', 'sy1' => $stripYear . '-12-31'];
        if ($role === 'TEACHER') {
            $swhere[] = 'l.teacher_id = :sme';
            $sparams['sme'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            $swhere[] = 'e.student_id = :sme';
            $sparams['sme'] = (int) $user['user_id'];
        } else {
            if ($teacherFilter > 0) {
                $swhere[] = 'l.teacher_id = :stf';
                $sparams['stf'] = $teacherFilter;
            }
            if ($studentFilter > 0) {
                $swhere[] = 'e.student_id = :ssf';
                $sparams['ssf'] = $studentFilter;
            }
        }
        $stmt = db()->prepare(
            "SELECT SUBSTR(l.lesson_date, 1, 7) AS m,
                    COALESCE(SUM(CASE WHEN e.payment_status != 'PAID' THEN e.fee_amount ELSE 0 END), 0) AS unpaid,
                    SUM(CASE WHEN e.payment_status = 'PAID' AND (e.proof_path IS NULL OR e.proof_path = '') THEN 1 ELSE 0 END) AS paid_noproof,
                    SUM(CASE WHEN l.commission_status IS NULL OR l.commission_status != 'PAID' THEN 1 ELSE 0 END) AS comm_open
             FROM lesson_enrollments e
             INNER JOIN lessons l ON l.lesson_id = e.lesson_id
             WHERE " . implode(' AND ', $swhere) . ' GROUP BY m'
        );
        $stmt->execute($sparams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
            $monthStats[(string) $mr['m']] = [
                'unpaid' => (float) $mr['unpaid'],
                'paid_noproof' => (int) $mr['paid_noproof'],
                'comm_open' => (int) $mr['comm_open'],
            ];
        }
    } catch (Throwable $e) {
        $monthStats = [];
    }
}

function txn_qs(array $overrides): string
{
    global $months;
    $base = [
        'year' => $_GET['year'] ?? '',
        'lesson_status' => $_GET['lesson_status'] ?? '',
        'payment' => $_GET['payment'] ?? '',
        'teacher_id' => $_GET['teacher_id'] ?? '',
        'lesson_id' => $_GET['lesson_id'] ?? '',
        'student_id' => $_GET['student_id'] ?? '',
        'sort' => $_GET['sort'] ?? 'lesson_date',
        'order' => $_GET['order'] ?? 'DESC',
        'months' => $months,
    ];
    $q = array_merge($base, $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return http_build_query($q);
}

// Toggle one month in the multi-month filter (at least one stays selected).
function txn_qs_months(string $toggle): string
{
    global $months;
    $cur = is_array($months) ? $months : [];
    if (in_array($toggle, $cur, true)) {
        if (count($cur) > 1) {
            $cur = array_values(array_diff($cur, [$toggle]));
        }
    } else {
        $cur[] = $toggle;
        sort($cur);
    }
    return txn_qs(['months' => $cur]);
}

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/admin.css?v=' . filemtime(__DIR__ . '/assets/css/admin.css')); ?>">
<link rel="stylesheet" href="<?= e(BASE_URL . 'assets/css/transactions.css?v=' . filemtime(__DIR__ . '/assets/css/transactions.css')); ?>">

<div class="txn-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <p class="eyebrow">Finance</p>
            <h1 style="margin:0;">Transactions</h1>
            <p style="color:var(--text-muted,#666);margin:6px 0 0;">
                <?php if ($role === 'ADMIN'): ?>Per-student-per-lesson rows with lesson totals. Proofs are admin-uploaded; every upload/delete is date-logged below.<?php endif; ?>
                <?php if ($role === 'TEACHER'): ?>Rows for your lessons only.<?php endif; ?>
                <?php if ($role === 'STUDENT'): ?>Your lesson fees and payment status. Contact admin for receipts.<?php endif; ?>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <a href="<?= e(url_path('schedule.php')); ?>" class="btn secondary-btn" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;padding:8px 16px;">
                <span aria-hidden="true">📅</span> View Timetable Schedule
            </a>
            <a href="<?= e(url_path('account.php')); ?>" class="text-button">← Account</a>
        </div>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php endif; ?>

    <div class="txn-months">
        <div class="txn-months-now">
            <a class="txn-year-nav" href="<?= e(url_path('transactions.php?' . txn_qs(['year' => (string) max(2000, (int) $stripYear - 1), 'months' => []]))) ?>" aria-label="Previous year">‹</a>
            <span>Year <strong><?= e($stripYear) ?></strong> · Current: <strong><?= e(date('F Y')) ?></strong></span>
            <a class="txn-year-nav" href="<?= e(url_path('transactions.php?' . txn_qs(['year' => (string) min(2100, (int) $stripYear + 1), 'months' => []]))) ?>" aria-label="Next year">›</a>
        </div>
        <div class="txn-month-row" role="group" aria-label="Filter by month">
            <?php foreach (range(1, 12) as $mm): ?>
                <?php
                $mkey = sprintf('%04d-%02d', (int) $stripYear, $mm);
                $msel = in_array($mkey, $months, true);
                $mst = $monthStats[$mkey] ?? null;
                $bad = $mst !== null && ((float) $mst['unpaid'] > 0 || (int) $mst['paid_noproof'] > 0 || (int) $mst['comm_open'] > 0);
                $mcls = $mkey >= $curYm ? 'is-grey' : ($bad ? 'is-red' : 'is-green');
                ?>
                <a class="txn-month<?= $msel ? ' is-selected' : '' ?><?= $mkey === $curYm ? ' is-now' : '' ?> <?= $mcls ?>" href="<?= e(url_path('transactions.php?' . txn_qs_months($mkey))) ?>"><?= e(date('M', mktime(0, 0, 0, $mm, 1))) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="txn-totals">
        <div class="txn-total-card"><strong>RM <?= e(number_format($totals['paid'], 2)) ?></strong><span>of RM <?= e(number_format($totals['fee'], 2)) ?> fees received</span></div>
        <div class="txn-total-card"><strong>RM <?= e(number_format($totals['comm_paid'], 2)) ?></strong><span>of RM <?= e(number_format($totals['comm_due'], 2)) ?> commission paid</span></div>
        <div class="txn-total-card"><strong><?= (int) $totals['lessons'] ?></strong><span>Classes in filter</span></div>
        <div class="txn-total-card"><strong><?= count($incompleteLessons) ?></strong><span>Incomplete classes</span></div>
    </div>

    <form class="txn-filters" method="get" action="<?= e(url_path('transactions.php')); ?>">
        <?php foreach ($months as $m): ?>
            <input type="hidden" name="months[]" value="<?= e($m) ?>">
        <?php endforeach; ?>
        <label>Lesson status
            <select name="lesson_status">
                <option value="">All</option>
                <option value="SCHEDULED" <?= $lessonStatus === 'SCHEDULED' ? 'selected' : '' ?>>Scheduled</option>
                <option value="COMPLETED" <?= $lessonStatus === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                <option value="CANCELLED" <?= $lessonStatus === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </label>
        <label>Payment
            <select name="payment">
                <option value="">All</option>
                <option value="UNPAID" <?= $payStatus === 'UNPAID' ? 'selected' : '' ?>>Unpaid</option>
                <option value="PENDING" <?= $payStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                <option value="PAID" <?= $payStatus === 'PAID' ? 'selected' : '' ?>>Paid</option>
            </select>
        </label>
        <?php if ($role === 'ADMIN'): ?>
        <label>Teacher
            <select name="teacher_id">
                <option value="">All</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['user_id'] ?>" <?= $teacherFilter === (int) $t['user_id'] ? 'selected' : '' ?>><?= e($t['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Student
            <select name="student_id">
                <option value="">All</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?= (int) $s['user_id'] ?>" <?= $studentFilter === (int) $s['user_id'] ? 'selected' : '' ?>><?= e($s['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($lessonFilter > 0): ?>
            <label>Lesson<input type="number" name="lesson_id" value="<?= $lessonFilter ?>"></label>
        <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="primary-btn">Apply</button>
        <?php if ($lessonFilter > 0 || $studentFilter > 0 || $lessonStatus !== '' || $payStatus !== '' || $teacherFilter > 0 || $months !== [$curYm]): ?>
            <a href="<?= e(url_path('transactions.php')); ?>" class="text-button">Clear</a>
        <?php endif; ?>
    </form>
    <?php if ($studentFilterName !== null): ?>
        <div class="alert success">Showing all transactions for student <strong><?= e($studentFilterName) ?></strong>. <a href="<?= e(url_path('transactions.php?' . txn_qs(['student_id' => '']))) ?>">Show all</a></div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="data-table modern-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Class</th>
                    <th>Teacher</th>
                    <th>Commission (RM)</th>
                    <th>Student</th>
                    <th>Fee (RM)</th>
                    <th>Payment</th>
                    <th>Proof</th>
                    <?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($combos)): ?>
                    <tr><td colspan="<?= $role === 'ADMIN' ? 9 : 8 ?>">No transactions match this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($combos as $ckey => $c): ?>
                        <?php
                        $clsLabel = $c['g'] !== '' ? '#' . substr($c['g'], 0, 8) : $c['month'];
                        $clsName = $c['g'] !== '' ? ($c['dname'] ?? ('Series #' . substr($c['g'], 0, 8))) : 'One-off lessons';
                        ?>
                        <tr>
                            <td><?= $c['fee_id'] !== null ? (int) $c['fee_id'] : '—' ?></td>
                            <td><strong><?= e($clsName) ?></strong><br><span style="color:#666;font-size:12px;"><?= e($clsLabel) ?> · <?= e($c['month']) ?> · <?= count($c['lessons']) ?> lesson<?= count($c['lessons']) === 1 ? '' : 's' ?></span></td>
                            <td><?= e($c['tname']) ?><?= $c['tid'] > 0 ? ' <span style="color:#666;font-size:12px;">#' . (int) $c['tid'] . '</span>' : '' ?></td>
                            <td>
                                RM <?= e(number_format((float) $c['due'], 2)) ?>
                                <br><span style="color:#666;font-size:12px;"><?= e($c['rate_label'] ?? ($c['rate'][0] . ' ' . number_format((float) $c['rate'][1], 2))) ?><?= $c['series_paid'] ? '' : ' · open' ?></span>
                                <?php if ($role === 'ADMIN' && $c['g'] !== ''): ?>
                                    <details class="txn-rate-editor">
                                        <summary>Edit rate</summary>
                                        <div class="txn-rate-controls">
                                            <select data-comm-type data-group="<?= e($c['g']) ?>" aria-label="Commission type">
                                                <option value="">Default</option>
                                                <option value="PERCENT"<?= $c['ctype'] === 'PERCENT' ? ' selected' : '' ?>>%</option>
                                                <option value="FIXED"<?= $c['ctype'] === 'FIXED' ? ' selected' : '' ?>>RM</option>
                                            </select>
                                            <input type="number" data-comm-value data-group="<?= e($c['g']) ?>" min="0" step="0.01" value="<?= e($c['cvalue']) ?>" aria-label="Commission value">
                                            <button type="button" class="action-btn edit-btn" data-comm-save data-group="<?= e($c['g']) ?>">Save</button>
                                        </div>
                                        <small>Applies to the whole series.</small>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= e($c['sname']) ?> <span style="color:#666;font-size:12px;">#<?= (int) $c['sid'] ?></span></td>
                            <td>
                                <?php if ($role === 'ADMIN'): ?>
                                    <div class="txn-fee-editor">
                                        <input type="number" class="sched-fee-input" style="width:90px;" min="0" step="0.01" value="<?= e($c['fee_id'] !== null ? number_format((float) $c['amount'], 2, '.', '') : '') ?>" placeholder="0.00" aria-label="Monthly fee in RM" data-mfee-amount data-group="<?= e($c['g']) ?>" data-sid="<?= (int) $c['sid'] ?>" data-month="<?= e($c['month']) ?>">
                                        <button type="button" class="action-btn edit-btn" data-mfee-save data-group="<?= e($c['g']) ?>" data-sid="<?= (int) $c['sid'] ?>" data-month="<?= e($c['month']) ?>">Save</button>
                                    </div>
                                <?php else: ?>
                                    <?= $c['fee_id'] !== null ? 'RM ' . e(number_format((float) $c['amount'], 2)) : '—' ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="txn-payment-cell">
                                    <?php if ($c['fee_id'] === null): ?>
                                        <span class="txn-badge">No record</span>
                                    <?php else: ?>
                                        <span class="txn-badge <?= $c['mstatus'] === 'PAID' ? 'is-paid' : ($c['mstatus'] === 'PENDING' ? 'is-pending' : '') ?>"><?= e($c['mstatus']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($role === 'ADMIN' && $c['fee_id'] !== null): ?>
                                        <?php if (!empty($c['mproof'])): ?>
                                            <div class="txn-file-pill">
                                                <a href="<?= e(url_path($c['mproof'])) ?>" target="_blank" rel="noopener" title="Open payment proof">Payment proof</a>
                                                <button type="button" data-mproof-del data-feeid="<?= (int) $c['fee_id'] ?>" aria-label="Remove payment proof" title="Remove proof">×</button>
                                            </div>
                                        <?php else: ?>
                                            <label class="txn-upload-button">Upload file<input type="file" hidden data-mproof-upload data-feeid="<?= (int) $c['fee_id'] ?>" accept=".jpg,.jpeg,.png,.webp,.pdf, image/jpeg,image/png,image/webp,application/pdf"></label>
                                        <?php endif; ?>
                                    <?php elseif (!empty($c['mproof'])): ?>
                                        <a class="txn-file-pill txn-file-readonly" href="<?= e(url_path($c['mproof'])) ?>" target="_blank" rel="noopener">Payment proof</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($role === 'ADMIN' && !empty($c['proof_lesson_id'])): ?>
                                    <?php if (!empty($c['cproof'])): ?>
                                        <div class="txn-file-pill">
                                            <a href="<?= e(url_path($c['cproof'])) ?>" target="_blank" rel="noopener" title="Open commission proof">Commission proof</a>
                                            <button type="button" data-txn-delproof="COMMISSION" data-lesson="<?= (int) $c['proof_lesson_id'] ?>" aria-label="Remove commission proof" title="Remove proof">×</button>
                                        </div>
                                    <?php else: ?>
                                        <label class="txn-upload-button">Upload file<input type="file" hidden data-txn-upload="COMMISSION" data-lesson="<?= (int) $c['proof_lesson_id'] ?>" accept=".jpg,.jpeg,.png,.webp,.pdf, image/jpeg,image/png,image/webp,application/pdf"></label>
                                    <?php endif; ?>
                                <?php elseif (!empty($c['cproof'])): ?>
                                    <a class="txn-file-pill txn-file-readonly" href="<?= e(url_path($c['cproof'])) ?>" target="_blank" rel="noopener">Commission proof</a>
                                <?php else: ?>
                                    <span style="color:#999;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($role === 'ADMIN'): ?>
                            <td>
                                <div class="txn-row-actions">
                                    <a class="action-btn view-btn" style="text-decoration:none;" href="<?= e(url_path('transaction_detail.php?group=' . urlencode($c['g']) . '&month=' . urlencode($c['month']))) ?>">Details</a>
                                    <?php if ($c['fee_id'] !== null): ?>
                                        <button type="button" class="action-btn delete-btn" data-mfee-del data-feeid="<?= (int) $c['fee_id'] ?>">Delete</button>
                                    <?php elseif (!empty($c['enrollment_id']) && !empty($c['proof_lesson_id'])): ?>
                                        <button type="button" class="action-btn delete-btn" data-txn-del="<?= (int) $c['enrollment_id'] ?>" data-lesson="<?= (int) $c['proof_lesson_id'] ?>" data-group="<?= e($c['g']) ?>">Delete</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($role === 'ADMIN'): ?>
    <div class="txn-commission">
        <div class="txn-panel">
            <h2>Commission defaults</h2>
            <p style="color:#666;font-size:13px;">Global default: <?= e($defaultCommission[0]) ?> <?= e(number_format((float) $defaultCommission[1], 2)) ?>. Per-lesson values (schedule) override per-teacher, which override this default.</p>
            <form id="txnDefaultForm">
                <select name="commission_type">
                    <option value="PERCENT" <?= $defaultCommission[0] === 'PERCENT' ? 'selected' : '' ?>>Percent %</option>
                    <option value="FIXED" <?= $defaultCommission[0] === 'FIXED' ? 'selected' : '' ?>>Fixed RM</option>
                </select>
                <input type="number" name="commission_value" min="0" step="0.01" value="<?= e(number_format((float) $defaultCommission[1], 2, '.', '')) ?>" required>
                <button type="submit" class="primary-btn">Save default</button>
            </form>
            <form id="txnTeacherForm" style="margin-top:12px;">
                <select name="teacher_id" required>
                    <option value="">— teacher —</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int) $t['user_id'] ?>"><?= e($t['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="commission_type">
                    <option value="PERCENT">Percent %</option>
                    <option value="FIXED">Fixed RM</option>
                </select>
                <input type="number" name="commission_value" min="0" step="0.01" value="0" required>
                <button type="submit" class="secondary-btn">Save teacher rate</button>
            </form>
            <?php if (!empty($teacherOverrides)): ?>
            <div class="table-wrapper" style="margin-top:12px;">
                <table class="data-table modern-table">
                    <thead><tr><th>Teacher ID</th><th>Type</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php foreach ($teacherOverrides as $ov): ?>
                            <tr><td><?= (int) $ov['teacher_id'] ?></td><td><?= e($ov['commission_type']) ?></td><td><?= e(number_format((float) $ov['commission_value'], 2)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="txn-panel">
            <h2>Proof activity log</h2>
            <p style="color:#666;font-size:13px;">Every upload/delete with date<?= $lessonFilter > 0 ? ' for lesson #' . $lessonFilter : '' ?>.</p>
            <?php if (empty($proofLogs)): ?>
                <p style="color:#999;">No proof activity yet.</p>
            <?php else: ?>
    <div class="table-wrapper">
                <table class="data-table modern-table">
                    <thead><tr><th>Date</th><th>Kind</th><th>Action</th><th>File</th><th>By</th></tr></thead>
                    <tbody>
                        <?php foreach ($proofLogs as $pl): ?>
                            <tr>
                                <td><?= e($pl['created_at']) ?></td>
                                <td><?= e($pl['kind']) ?></td>
                                <td><?= e($pl['action']) ?></td>
                                <td><span style="font-size:12px;"><?= e($pl['file_path']) ?></span><?= $pl['lesson_id'] !== null ? '<br><span style="font-size:12px;color:#666;">lesson #' . (int) $pl['lesson_id'] . '</span>' : '' ?></td>
                                <td><?= e($pl['performer'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('th.sortable[data-href]').forEach(function (th) {
        th.addEventListener('click', function () { window.location.href = th.getAttribute('data-href'); });
    });
    function postForm(url, fd) {
        return fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }
    var apiEnroll = '<?= e(url_path('api/enrollments_save.php')) ?>';
    var apiLessons = '<?= e(url_path('api/lessons_save.php')) ?>';
    var apiProofUp = '<?= e(url_path('api/proof_upload.php')) ?>';
    var apiMfee = '<?= e(url_path('api/monthly_fees_save.php')) ?>';
    var apiProofDel = '<?= e(url_path('api/proof_delete.php')) ?>';
    var apiSeries = '<?= e(url_path('api/series_save.php')) ?>';
    var apiComm = '<?= e(url_path('api/commission_save.php')) ?>';

    document.querySelectorAll('[data-txn-fee]').forEach(function (b) {
        b.addEventListener('click', function () {
            var curr = b.getAttribute('data-fee') || '0';
            PSMModal.prompt({
                title: 'Update Enrollment Fee',
                label: 'New fee amount in RM:',
                value: curr,
                type: 'number',
                confirmText: 'Save Fee'
            }).then(function (v) {
                if (v === null || v === '') return;
                var fd = new FormData(); fd.append('action', 'set_fee');
                fd.append('enrollment_id', b.getAttribute('data-txn-fee')); fd.append('fee_amount', v);
                postForm(apiEnroll, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Fee updated successfully!', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to update fee.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-txn-pay]').forEach(function (b) {
        b.addEventListener('click', function () {
            var curr = (b.getAttribute('data-status') || 'PAID').toUpperCase();
            PSMModal.select({
                title: 'Update Payment Status',
                label: 'Choose payment status for this student:',
                current: curr,
                options: [
                    { value: 'UNPAID', label: 'UNPAID', desc: 'No payment made yet', color: 'bad' },
                    { value: 'PENDING', label: 'PENDING', desc: 'Payment awaiting verification/receipt', color: 'warn' },
                    { value: 'PAID', label: 'PAID', desc: 'Payment received and confirmed', color: 'ok' }
                ],
                confirmText: 'Update Status'
            }).then(function (v) {
                if (!v) return;
                var fd = new FormData(); fd.append('action', 'set_payment');
                fd.append('enrollment_id', b.getAttribute('data-txn-pay')); fd.append('status', v.toUpperCase());
                postForm(apiEnroll, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Payment status updated to ' + v + '!', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to update payment status.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-txn-cstatus]').forEach(function (b) {
        b.addEventListener('click', function () {
            var curr = (b.getAttribute('data-status') || 'PAID').toUpperCase();
            PSMModal.select({
                title: 'Update Lesson Commission Status',
                label: 'Choose commission status for the whole lesson:',
                current: curr,
                options: [
                    { value: 'UNPAID', label: 'UNPAID', desc: 'Commission not yet paid to teacher', color: 'bad' },
                    { value: 'PENDING', label: 'PENDING', desc: 'Commission pending payout', color: 'warn' },
                    { value: 'PAID', label: 'PAID', desc: 'Commission paid to teacher', color: 'ok' }
                ],
                confirmText: 'Update Commission Status'
            }).then(function (v) {
                if (!v) return;
                var fd = new FormData(); fd.append('action', 'set_commission_status');
                fd.append('lesson_id', b.getAttribute('data-txn-cstatus')); fd.append('status', v.toUpperCase());
                postForm(apiEnroll, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Commission status updated to ' + v + '!', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to update commission status.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-txn-upload]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.files.length) return;
            var fd = new FormData();
            fd.append('kind', input.getAttribute('data-txn-upload'));
            fd.append('lesson_id', input.getAttribute('data-lesson'));
            fd.append('enrollment_id', input.getAttribute('data-enroll') || '');
            fd.append('proof', input.files[0]);
            input.disabled = true;
            PSMModal.toast('Uploading proof file...', 'info', 2000);
            postForm(apiProofUp, fd).then(function (d) {
                if (d && d.success) {
                    PSMModal.toast('Proof file uploaded successfully!', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    PSMModal.toast((d && d.error) || 'Upload failed.', 'error');
                    input.disabled = false;
                }
            }).catch(function (err) {
                PSMModal.toast('Upload error: ' + err.message, 'error');
                input.disabled = false;
            });
        });
    });

    document.querySelectorAll('[data-mfee-save]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tr = b.closest('tr');
            var amt = tr ? tr.querySelector('[data-mfee-amount]') : null;
            var st = tr ? tr.querySelector('[data-mfee-status]') : null;
            var fd = new FormData();
            fd.append('group', b.getAttribute('data-group') || '');
            fd.append('month', b.getAttribute('data-month') || '');
            fd.append('fees[' + b.getAttribute('data-sid') + ']', amt ? amt.value : '');
            if (st) fd.append('status[' + b.getAttribute('data-sid') + ']', st.value);
            postForm(apiMfee, fd).then(function (d) {
                if (d && d.success) {
                    PSMModal.toast('Monthly fee saved.', 'success');
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    PSMModal.toast((d && d.error) || 'Save failed.', 'error');
                }
            }).catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        });
    });

    document.querySelectorAll('[data-mproof-upload]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.files.length) return;
            var fd = new FormData();
            fd.append('kind', 'MONTHLY_FEE');
            fd.append('fee_id', input.getAttribute('data-feeid'));
            fd.append('proof', input.files[0]);
            input.disabled = true;
            PSMModal.toast('Uploading proof file...', 'info', 2000);
            postForm(apiProofUp, fd).then(function (d) {
                if (d && d.success) {
                    PSMModal.toast('Proof file uploaded successfully!', 'success');
                    setTimeout(function () { window.location.reload(); }, 500);
                } else {
                    PSMModal.toast((d && d.error) || 'Upload failed.', 'error');
                    input.disabled = false;
                }
            }).catch(function (err) {
                PSMModal.toast('Upload error: ' + err.message, 'error');
                input.disabled = false;
            });
        });
    });

    document.querySelectorAll('[data-mproof-del]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Delete Proof File?',
                message: 'Delete this monthly receipt permanently? This deletion will be logged in the audit trail.',
                danger: true,
                confirmText: 'Delete Proof'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData();
                fd.append('kind', 'MONTHLY_FEE');
                fd.append('fee_id', b.getAttribute('data-feeid'));
                postForm(apiProofDel, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Proof file deleted.', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to delete proof.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-mfee-del]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Delete Monthly Record?',
                message: 'Delete this transaction and its linked class schedule? For a recurring class, the entire series and all related monthly records will be deleted. For standalone classes, the student’s ungrouped sessions in this month will be deleted for all enrolled students; a student’s monthly record is removed only if no other ungrouped sessions remain that month. This cannot be undone.',
                danger: true,
                confirmText: 'Delete Record'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData();
                fd.append('action', 'delete');
                fd.append('fee_id', b.getAttribute('data-feeid'));
                postForm(apiMfee, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Transaction and linked class schedule deleted.', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to delete record.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-comm-save]').forEach(function (b) {
        b.addEventListener('click', function () {
            var cell = b.closest('td');
            var typeEl = cell ? cell.querySelector('[data-comm-type]') : null;
            var valEl = cell ? cell.querySelector('[data-comm-value]') : null;
            var fd = new FormData();
            fd.append('action', 'set_commission');
            fd.append('group', b.getAttribute('data-group'));
            fd.append('commission_type', typeEl ? typeEl.value : '');
            fd.append('commission_value', valEl ? valEl.value : '');
            postForm(apiSeries, fd).then(function (d) {
                if (d && d.success) {
                    PSMModal.toast('Commission rate saved for the whole series.', 'success');
                    setTimeout(function () { window.location.reload(); }, 400);
                } else {
                    PSMModal.toast((d && d.error) || 'Failed to save rate.', 'error');
                }
            }).catch(function (err) { PSMModal.toast('Network error: ' + err.message, 'error'); });
        });
    });

    document.querySelectorAll('[data-txn-delproof]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Delete Proof File?',
                message: 'Delete this proof file permanently? This deletion will be logged in the audit trail.',
                danger: true,
                confirmText: 'Delete Proof'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData();
                fd.append('kind', b.getAttribute('data-txn-delproof'));
                fd.append('lesson_id', b.getAttribute('data-lesson'));
                fd.append('enrollment_id', b.getAttribute('data-enroll') || '');
                postForm(apiProofDel, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Proof file deleted.', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to delete proof.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    document.querySelectorAll('[data-txn-del]').forEach(function (b) {
        b.addEventListener('click', function () {
            PSMModal.confirm({
                title: 'Delete Transaction?',
                message: 'Are you sure you want to delete this transaction? The student enrollment, fee, and any attached proof file will be permanently removed.',
                danger: true,
                confirmText: 'Delete Transaction'
            }).then(function (yes) {
                if (!yes) return;
                var fd = new FormData();
                var lessonId = b.getAttribute('data-lesson');
                var groupId = b.getAttribute('data-group') || '';
                fd.append('action', groupId ? 'delete_group' : 'delete');
                fd.append('lesson_id', lessonId);
                postForm(apiLessons, fd).then(function (d) {
                    if (d && d.success) {
                        PSMModal.toast('Transaction and linked class schedule deleted.', 'success');
                        setTimeout(function () { window.location.reload(); }, 400);
                    } else {
                        PSMModal.toast((d && d.error) || 'Failed to delete transaction.', 'error');
                    }
                }).catch(function (err) {
                    PSMModal.toast('Network error: ' + err.message, 'error');
                });
            });
        });
    });

    var defForm = document.getElementById('txnDefaultForm');
    if (defForm) defForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var fd = new FormData(defForm); fd.append('action', 'default');
        postForm(apiComm, fd).then(function (d) {
            if (d && d.success) {
                PSMModal.toast('Default commission rate saved!', 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                PSMModal.toast((d && d.error) || 'Failed to save default rate.', 'error');
            }
        }).catch(function (err) {
            PSMModal.toast('Network error: ' + err.message, 'error');
        });
    });

    var tForm = document.getElementById('txnTeacherForm');
    if (tForm) tForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var fd = new FormData(tForm); fd.append('action', 'teacher');
        postForm(apiComm, fd).then(function (d) {
            if (d && d.success) {
                PSMModal.toast('Teacher commission rate saved!', 'success');
                setTimeout(function () { window.location.reload(); }, 500);
            } else {
                PSMModal.toast((d && d.error) || 'Failed to save teacher rate.', 'error');
            }
        }).catch(function (err) {
            PSMModal.toast('Network error: ' + err.message, 'error');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
