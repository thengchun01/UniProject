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

// ---- filters ----
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$lessonStatus = strtoupper(trim($_GET['lesson_status'] ?? ''));
$payStatus = strtoupper(trim($_GET['payment'] ?? ''));
$teacherFilter = (int) ($_GET['teacher_id'] ?? 0);
$lessonFilter = (int) ($_GET['lesson_id'] ?? 0);
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}
if (!in_array($lessonStatus, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
    $lessonStatus = '';
}
if (!in_array($payStatus, ['UNPAID', 'PENDING', 'PAID'], true)) {
    $payStatus = '';
}

$sort = $_GET['sort'] ?? 'lesson_date';
$order = strtoupper($_GET['order'] ?? 'DESC');
$allowedSort = ['lesson_date', 'student_name', 'teacher_name', 'fee_amount', 'payment_status', 'commission_status'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'lesson_date';
}
$order = $order === 'ASC' ? 'ASC' : 'DESC';
$nextOrder = $order === 'ASC' ? 'DESC' : 'ASC';

$rows = [];
$totals = ['fee' => 0.0, 'paid' => 0.0, 'unpaid_rows' => 0, 'lessons' => 0];
$defaultCommission = ['PERCENT', 0.0];
$teacherOverrides = [];
$teachers = [];
$proofLogs = [];

if ($txAvailable && is_database_connected()) {
    try {
        $db = db();
        $stmt = $db->query("SELECT user_id, username FROM users WHERE role = 'TEACHER' ORDER BY username ASC");
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $defaultCommission = $lessonsManager->getDefaultCommission();

        $stmt = $db->query('SELECT teacher_id, commission_type, commission_value FROM teacher_commission');
        $teacherOverrides = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $where = [];
        $params = [];
        if ($from !== '') {
            $where[] = 'l.lesson_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== '') {
            $where[] = 'l.lesson_date <= :to';
            $params['to'] = $to;
        }
        if ($lessonStatus !== '') {
            $where[] = 'l.status = :lstatus';
            $params['lstatus'] = $lessonStatus;
        }
        if ($payStatus !== '') {
            $where[] = 'e.payment_status = :pstatus';
            $params['pstatus'] = $payStatus;
        }
        if ($lessonFilter > 0) {
            $where[] = 'l.lesson_id = :lesson_id';
            $params['lesson_id'] = $lessonFilter;
        }
        if ($role === 'TEACHER') {
            $where[] = 'l.teacher_id = :me';
            $params['me'] = (int) $user['user_id'];
        } elseif ($role === 'STUDENT') {
            $where[] = 'e.student_id = :me';
            $params['me'] = (int) $user['user_id'];
        } elseif ($teacherFilter > 0) {
            $where[] = 'l.teacher_id = :tfilter';
            $params['tfilter'] = $teacherFilter;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $orderMap = [
            'lesson_date' => "l.lesson_date $order, l.start_time $order",
            'student_name' => "su.username $order",
            'teacher_name' => "tu.username $order",
            'fee_amount' => "e.fee_amount $order",
            'payment_status' => "e.payment_status $order",
            'commission_status' => "l.commission_status $order",
        ];

        $sql = "SELECT e.enrollment_id, e.lesson_id, e.student_id, e.fee_amount, e.payment_status,
                       e.proof_path, e.proof_uploaded_at,
                       l.lesson_date, l.start_time, l.end_time, l.status AS lesson_status, l.title,
                       l.teacher_id, l.commission_type, l.commission_value, l.commission_status, l.commission_proof_path,
                       su.username AS student_name, tu.username AS teacher_name
                FROM lesson_enrollments e
                INNER JOIN lessons l ON l.lesson_id = e.lesson_id
                INNER JOIN users su ON su.user_id = e.student_id
                LEFT JOIN users tu ON tu.user_id = l.teacher_id
                $whereSql ORDER BY {$orderMap[$sort]} LIMIT 500";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Per-lesson fee totals (for commission payout + header totals).
        $lessonTotals = [];
        $lessonIds = array_unique(array_map(function ($r) {
            return (int) $r['lesson_id'];
        }, $rows));
        if (!empty($lessonIds)) {
            $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
            $stmt = $db->prepare("SELECT lesson_id, COALESCE(SUM(fee_amount),0) AS total FROM lesson_enrollments WHERE lesson_id IN ($placeholders) GROUP BY lesson_id");
            $stmt->execute(array_values($lessonIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $lessonTotals[(int) $t['lesson_id']] = (float) $t['total'];
            }
        }
        foreach ($rows as &$r) {
            $lid = (int) $r['lesson_id'];
            $r['lesson_total'] = $lessonTotals[$lid] ?? 0.0;
            [$ct, $cv] = $lessonsManager->resolveCommission(
                $r['teacher_id'] !== null ? (int) $r['teacher_id'] : null,
                $r['commission_type'],
                $r['commission_value']
            );
            $r['resolved_ctype'] = $ct;
            $r['resolved_cvalue'] = (float) $cv;
            $r['payout'] = $lessonsManager->commissionPayout($ct, (float) $cv, (float) $r['lesson_total']);
            $totals['fee'] += (float) $r['fee_amount'];
            if ($r['payment_status'] === 'PAID') {
                $totals['paid'] += (float) $r['fee_amount'];
            } else {
                $totals['unpaid_rows']++;
            }
        }
        unset($r);
        $totals['lessons'] = count($lessonIds);

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

function txn_qs(array $overrides): string
{
    $base = [
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'lesson_status' => $_GET['lesson_status'] ?? '',
        'payment' => $_GET['payment'] ?? '',
        'teacher_id' => $_GET['teacher_id'] ?? '',
        'lesson_id' => $_GET['lesson_id'] ?? '',
        'sort' => $_GET['sort'] ?? 'lesson_date',
        'order' => $_GET['order'] ?? 'DESC',
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
        <div style="display:flex;gap:8px;">
            <a href="<?= e(url_path('schedule.php')); ?>" class="text-button">Open Schedule →</a>
            <a href="<?= e(url_path('account.php')); ?>" class="text-button">← Account</a>
        </div>
    </div>

    <?php if ($loadError): ?>
        <div class="alert error"><?= e($loadError) ?></div>
    <?php endif; ?>

    <div class="txn-totals">
        <div class="txn-total-card"><strong>RM <?= e(number_format($totals['fee'], 2)) ?></strong><span>Total fees (filtered rows)</span></div>
        <div class="txn-total-card"><strong>RM <?= e(number_format($totals['paid'], 2)) ?></strong><span>Collected (PAID rows)</span></div>
        <div class="txn-total-card"><strong><?= (int) $totals['unpaid_rows'] ?></strong><span>Unpaid / pending rows</span></div>
        <div class="txn-total-card"><strong><?= (int) $totals['lessons'] ?></strong><span>Lessons in filter</span></div>
    </div>

    <form class="txn-filters" method="get" action="<?= e(url_path('transactions.php')); ?>">
        <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
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
        <?php if ($lessonFilter > 0): ?>
            <label>Lesson<input type="number" name="lesson_id" value="<?= $lessonFilter ?>"></label>
        <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="primary-btn">Apply</button>
        <?php if ($lessonFilter > 0 || $from !== '' || $to !== '' || $lessonStatus !== '' || $payStatus !== '' || $teacherFilter > 0): ?>
            <a href="<?= e(url_path('transactions.php')); ?>" class="text-button">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-wrapper">
        <table class="data-table modern-table">
            <thead>
                <tr>
                    <?php
                    $cols = [
                        'lesson_date' => 'Lesson',
                        'student_name' => 'Student',
                        'teacher_name' => 'Teacher',
                        'fee_amount' => 'Fee (RM)',
                        'payment_status' => 'Payment',
                        'commission_status' => 'Commission',
                    ];
                    foreach ($cols as $key => $label):
                        $qs = txn_qs(['sort' => $key, 'order' => $sort === $key ? $nextOrder : 'ASC']);
                    ?>
                        <th class="sortable" data-href="<?= e(url_path('transactions.php?' . $qs)) ?>"><?= e($label) ?>
                            <?php if ($sort === $key): ?><span class="sort-icon"><?= $order === 'ASC' ? '↑' : '↓' ?></span><?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                    <th>Proofs</th>
                    <?php if ($role === 'ADMIN'): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= $role === 'ADMIN' ? 8 : 7 ?>">No transactions match this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(substr((string) $r['lesson_date'], 0, 10)) ?> <?= e(substr((string) $r['start_time'], 0, 5)) ?>–<?= e(substr((string) $r['end_time'], 0, 5)) ?><br><span style="color:#666;font-size:12px;"><?= e($r['title'] !== null && $r['title'] !== '' ? $r['title'] : 'Lesson #' . (int) $r['lesson_id']) ?> · <?= e($r['lesson_status']) ?> · total RM <?= e(number_format((float) $r['lesson_total'], 2)) ?></span></td>
                            <td><?= e($r['student_name']) ?></td>
                            <td><?= e($r['teacher_name'] ?? '—') ?></td>
                            <td><?= e(number_format((float) $r['fee_amount'], 2)) ?></td>
                            <td><span class="txn-badge <?= $r['payment_status'] === 'PAID' ? 'is-paid' : ($r['payment_status'] === 'PENDING' ? 'is-pending' : '') ?>"><?= e($r['payment_status']) ?></span></td>
                            <td><span class="txn-badge <?= $r['commission_status'] === 'PAID' ? 'is-paid' : ($r['commission_status'] === 'PENDING' ? 'is-pending' : '') ?>"><?= e($r['commission_status']) ?></span><br><span style="color:#666;font-size:12px;"><?= e($r['resolved_ctype']) ?> <?= e(number_format((float) $r['resolved_cvalue'], 2)) ?> → RM <?= e(number_format((float) $r['payout'], 2)) ?></span></td>
                            <td>
                                <?php if (!empty($r['proof_path'])): ?>
                                    <a class="txn-proof-link" href="<?= e(url_path($r['proof_path'])) ?>" target="_blank" rel="noopener">Fee proof</a>
                                <?php else: ?>
                                    <span style="color:#999;font-size:12px;">No fee proof</span>
                                <?php endif; ?>
                                <?php if (!empty($r['commission_proof_path'])): ?>
                                    <br><a class="txn-proof-link" href="<?= e(url_path($r['commission_proof_path'])) ?>" target="_blank" rel="noopener">Commission proof</a>
                                <?php endif; ?>
                            </td>
                            <?php if ($role === 'ADMIN'): ?>
                            <td>
                                <div class="txn-row-actions">
                                    <button type="button" class="action-btn edit-btn" data-txn-fee="<?= (int) $r['enrollment_id'] ?>" data-fee="<?= e(number_format((float) $r['fee_amount'], 2, '.', '')) ?>">Fee</button>
                                    <button type="button" class="action-btn view-btn" data-txn-pay="<?= (int) $r['enrollment_id'] ?>" data-status="<?= e($r['payment_status']) ?>">Pay-status</button>
                                    <button type="button" class="action-btn classroom-btn" data-txn-cstatus="<?= (int) $r['lesson_id'] ?>" data-status="<?= e($r['commission_status']) ?>">Comm-status</button>
                                    <label class="action-btn reset-btn" style="cursor:pointer;">Upload fee proof<input type="file" hidden data-txn-upload="FEE" data-lesson="<?= (int) $r['lesson_id'] ?>" data-enroll="<?= (int) $r['enrollment_id'] ?>" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
                                    <?php if (!empty($r['proof_path'])): ?>
                                        <button type="button" class="action-btn delete-btn" data-txn-delproof="FEE" data-lesson="<?= (int) $r['lesson_id'] ?>" data-enroll="<?= (int) $r['enrollment_id'] ?>">Del proof</button>
                                    <?php endif; ?>
                                    <label class="action-btn classroom-btn" style="cursor:pointer;">Upload comm. proof<input type="file" hidden data-txn-upload="COMMISSION" data-lesson="<?= (int) $r['lesson_id'] ?>" data-enroll="" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
                                    <?php if (!empty($r['commission_proof_path'])): ?>
                                        <button type="button" class="action-btn delete-btn" data-txn-delproof="COMMISSION" data-lesson="<?= (int) $r['lesson_id'] ?>" data-enroll="">Del comm. proof</button>
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
    var apiProofUp = '<?= e(url_path('api/proof_upload.php')) ?>';
    var apiProofDel = '<?= e(url_path('api/proof_delete.php')) ?>';
    var apiComm = '<?= e(url_path('api/commission_save.php')) ?>';

    document.querySelectorAll('[data-txn-fee]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = window.prompt('New fee (RM):', b.getAttribute('data-fee') || '0');
            if (v === null) return;
            var fd = new FormData(); fd.append('action', 'set_fee');
            fd.append('enrollment_id', b.getAttribute('data-txn-fee')); fd.append('fee_amount', v);
            postForm(apiEnroll, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
        });
    });
    document.querySelectorAll('[data-txn-pay]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = window.prompt('Payment status (UNPAID / PENDING / PAID):', b.getAttribute('data-status') || 'PAID');
            if (!v) return;
            var fd = new FormData(); fd.append('action', 'set_payment');
            fd.append('enrollment_id', b.getAttribute('data-txn-pay')); fd.append('status', v.toUpperCase());
            postForm(apiEnroll, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
        });
    });
    document.querySelectorAll('[data-txn-cstatus]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = window.prompt('Commission status for the whole lesson (UNPAID / PENDING / PAID):', b.getAttribute('data-status') || 'PAID');
            if (!v) return;
            var fd = new FormData(); fd.append('action', 'set_commission_status');
            fd.append('lesson_id', b.getAttribute('data-txn-cstatus')); fd.append('status', v.toUpperCase());
            postForm(apiEnroll, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
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
            postForm(apiProofUp, fd).then(function (d) { if (d.success) window.location.reload(); else { alert(d.error || 'Upload failed.'); input.disabled = false; } });
        });
    });
    document.querySelectorAll('[data-txn-delproof]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!window.confirm('Delete this proof file? The delete is logged.')) return;
            var fd = new FormData();
            fd.append('kind', b.getAttribute('data-txn-delproof'));
            fd.append('lesson_id', b.getAttribute('data-lesson'));
            fd.append('enrollment_id', b.getAttribute('data-enroll') || '');
            postForm(apiProofDel, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
        });
    });
    var defForm = document.getElementById('txnDefaultForm');
    if (defForm) defForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var fd = new FormData(defForm); fd.append('action', 'default');
        postForm(apiComm, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
    });
    var tForm = document.getElementById('txnTeacherForm');
    if (tForm) tForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var fd = new FormData(tForm); fd.append('action', 'teacher');
        postForm(apiComm, fd).then(function (d) { if (d.success) window.location.reload(); else alert(d.error || 'Failed.'); });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
