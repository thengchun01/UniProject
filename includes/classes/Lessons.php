<?php
// Lessons / transactions helper (additive — old classroom scheme untouched).
// All SQL uses prepared statements. Role checks stay in the calling page/API.

class Lessons
{
    private $db;

    public function __construct($pdo)
    {
        $this->db = $pdo;
    }

    // ---------- working hours ----------
    // Weekday Mon–Fri 09:00–22:00, Sat 09:00–18:30, Sun closed.
    // Times must align to 30-min boundaries and end after start.
    // Returns [bool $ok, string $error].
    public function checkSlot(string $date, string $start, string $end): array
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return [false, 'Invalid lesson date.'];
        }
        $dow = (int) date('N', $ts); // 1 Mon .. 7 Sun
        if ($dow === 7) {
            return [false, 'Lessons are closed on Sunday.'];
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            return [false, 'Invalid time format.'];
        }
        $s = substr($start, 0, 5);
        $e = substr($end, 0, 5);
        [$sh, $sm] = array_map('intval', explode(':', $s));
        [$eh, $em] = array_map('intval', explode(':', $e));
        if (!in_array($sm, [0, 30], true) || !in_array($em, [0, 30], true)) {
            return [false, 'Slots must start/end on the hour or half hour.'];
        }
        $startMin = $sh * 60 + $sm;
        $endMin = $eh * 60 + $em;
        if ($endMin <= $startMin) {
            return [false, 'End time must be after start time.'];
        }
        if ((($endMin - $startMin) % 30) !== 0) {
            return [false, 'Duration must be a multiple of 30 minutes.'];
        }
        if ($dow >= 1 && $dow <= 5) {
            if ($startMin < 9 * 60 || $endMin > 22 * 60) {
                return [false, 'Weekday lessons must be within 9:00am–10:00pm.'];
            }
        } else { // Saturday
            if ($startMin < 9 * 60 || $endMin > 18 * 60 + 30) {
                return [false, 'Saturday lessons must be within 9:00am–6:30pm.'];
            }
        }
        return [true, ''];
    }

    // ---------- commission ----------
    public function getDefaultCommission(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT commission_type, commission_value FROM commission_settings WHERE setting_key = 'default' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [$row['commission_type'], (float) $row['commission_value']];
            }
        } catch (Throwable $e) {
            // table may not be migrated yet
        }
        return ['PERCENT', 0.0];
    }

    public function getTeacherCommission(int $teacherId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT commission_type, commission_value FROM teacher_commission WHERE teacher_id = :id LIMIT 1");
            $stmt->execute(['id' => $teacherId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [$row['commission_type'], (float) $row['commission_value']];
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    // Lesson override wins, else teacher override, else global default.
    public function resolveCommission(?int $teacherId, ?string $lessonType, $lessonValue): array
    {
        if ($lessonType !== null && $lessonValue !== null && $lessonType !== '' && is_numeric($lessonValue)) {
            $t = strtoupper(trim((string) $lessonType));
            if (in_array($t, ['PERCENT', 'FIXED'], true)) {
                return [$t, (float) $lessonValue];
            }
        }
        if ($teacherId !== null && $teacherId > 0) {
            $tc = $this->getTeacherCommission($teacherId);
            if ($tc !== null) {
                return $tc;
            }
        }
        return $this->getDefaultCommission();
    }

    // Commission payout for a lesson given total collected fees.
    public function commissionPayout(string $type, float $value, float $totalFees): float
    {
        if ($type === 'FIXED') {
            return round(max(0, $value), 2);
        }
        return round(max(0, $totalFees * max(0, $value) / 100), 2);
    }

    // ---------- teacher availability (stored in user_settings JSON) ----------
    public function getTeacherAvailability(int $teacherId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT settings_json FROM user_settings WHERE user_id = :id");
            $stmt->execute(['id' => $teacherId]);
            $raw = $stmt->fetchColumn();
            if (!$raw) {
                return [];
            }
            $doc = json_decode((string) $raw, true);
            if (!is_array($doc)) {
                return [];
            }
            $av = $doc['availability'] ?? [];
            return is_array($av) ? $av : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---------- lesson CRUD ----------
    public function createLesson(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lessons (teacher_id, title, lesson_date, start_time, end_time, status, recurrence_group,
              commission_type, commission_value, commission_status, created_by)
             VALUES (:teacher_id, :title, :lesson_date, :start_time, :end_time, :status, :recurrence_group,
              :commission_type, :commission_value, 'UNPAID', :created_by)"
        );
        $stmt->execute([
            'teacher_id' => $data['teacher_id'] ?? null,
            'title' => $data['title'] ?? null,
            'lesson_date' => $data['lesson_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'status' => $data['status'] ?? 'SCHEDULED',
            'recurrence_group' => $data['recurrence_group'] ?? null,
            'commission_type' => $data['commission_type'] ?? null,
            'commission_value' => $data['commission_value'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getLesson(int $lessonId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT l.*, tu.username AS teacher_name
             FROM lessons l
             LEFT JOIN users tu ON tu.user_id = l.teacher_id
             WHERE l.lesson_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $lessonId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getEnrollments(int $lessonId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, su.username AS student_name, su.email AS student_email
             FROM lesson_enrollments e
             INNER JOIN users su ON su.user_id = e.student_id
             WHERE e.lesson_id = :id ORDER BY su.username ASC"
        );
        $stmt->execute(['id' => $lessonId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function lessonTotals(int $lessonId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS student_count,
                    COALESCE(SUM(fee_amount), 0) AS total_fee,
                    SUM(CASE WHEN payment_status = 'PAID' THEN fee_amount ELSE 0 END) AS paid_total,
                    SUM(CASE WHEN payment_status != 'PAID' THEN 1 ELSE 0 END) AS unpaid_count
             FROM lesson_enrollments WHERE lesson_id = :id"
        );
        $stmt->execute(['id' => $lessonId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['student_count' => 0, 'total_fee' => 0, 'paid_total' => 0, 'unpaid_count' => 0];
    }

    // Role-scoped lesson list. Caller passes one of: admin (no filter),
    // teacher (teacher_id), student (student_id via enrollment join).
    public function listLessons(array $filters = [], string $sort = 'lesson_date', string $order = 'DESC', int $limit = 200, int $offset = 0): array
    {
        $allowed = ['lesson_id', 'lesson_date', 'start_time', 'status', 'teacher_name', 'total_fee'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'lesson_date';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $orderBy = $sort === 'teacher_name' ? "tu.username $order" : ($sort === 'total_fee' ? "total_fee $order" : "l.$sort $order");

        $where = [];
        $params = [];
        if (!empty($filters['teacher_id'])) {
            $where[] = 'l.teacher_id = :teacher_id';
            $params['teacher_id'] = (int) $filters['teacher_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'l.lesson_date >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'l.lesson_date <= :to';
            $params['to'] = $filters['to'];
        }
        $joinEnrollment = !empty($filters['student_id']);
        if ($joinEnrollment) {
            $where[] = 'e.student_id = :student_id';
            $params['student_id'] = (int) $filters['student_id'];
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT l.*, tu.username AS teacher_name,
                       (SELECT COUNT(*) FROM lesson_enrollments WHERE lesson_id = l.lesson_id) AS student_count,
                       (SELECT COALESCE(SUM(fee_amount),0) FROM lesson_enrollments WHERE lesson_id = l.lesson_id) AS total_fee
                FROM lessons l
                LEFT JOIN users tu ON tu.user_id = l.teacher_id "
            . ($joinEnrollment ? "INNER JOIN lesson_enrollments e ON e.lesson_id = l.lesson_id " : "")
            . "$whereSql ORDER BY $orderBy LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addEnrollment(int $lessonId, int $studentId, float $fee): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lesson_enrollments (lesson_id, student_id, fee_amount)
             VALUES (:lesson_id, :student_id, :fee)
             ON DUPLICATE KEY UPDATE fee_amount = VALUES(fee_amount)"
        );
        $stmt->execute(['lesson_id' => $lessonId, 'student_id' => $studentId, 'fee' => $fee]);
    }

    // Issue 32: SCHEDULED lessons whose end time has passed become COMPLETED.
    // CANCELLED lessons are never touched. Idempotent; safe to call on page load.
    public function autoCompletePast(): int
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE lessons SET status = 'COMPLETED'
                 WHERE status = 'SCHEDULED' AND TIMESTAMP(lesson_date, end_time) < NOW()"
            );
            $stmt->execute();
            return (int) $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // Issue 50: all lessons sharing one repeat series, ordered first to last.
    public function getGroupLessons(string $group): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT lesson_id, lesson_date, start_time, end_time, status
                 FROM lessons WHERE recurrence_group = :g
                 ORDER BY lesson_date ASC, start_time ASC'
            );
            $stmt->execute(['g' => $group]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function logProof(string $kind, ?int $lessonId, ?int $enrollmentId, string $filePath, string $action, ?int $performedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO proof_logs (kind, lesson_id, enrollment_id, file_path, action, performed_by)
             VALUES (:kind, :lesson_id, :enrollment_id, :file_path, :action, :performed_by)"
        );
        $stmt->execute([
            'kind' => $kind,
            'lesson_id' => $lessonId,
            'enrollment_id' => $enrollmentId,
            'file_path' => $filePath,
            'action' => $action,
            'performed_by' => $performedBy,
        ]);
    }
}
