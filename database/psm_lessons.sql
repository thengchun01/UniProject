-- PSM lessons / transactions additive migration
-- Keeps the old classroom scheme (users.classroom, classroom_status,
-- classroom_settings) untouched. Import after database/psm_schema.sql.
-- MariaDB 10.4 compatible.
--
-- Tables:
--   lessons              one row per scheduled lesson (1 teacher, N students)
--   lesson_enrollments   per-student fee + payment status + proof path
--   commission_settings  global default commission (single 'default' row)
--   teacher_commission   per-teacher commission override
--   proof_logs           audit log for every proof UPLOAD / DELETE (date kept)

CREATE TABLE IF NOT EXISTS `lessons` (
  `lesson_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `title` varchar(200) DEFAULT NULL,
  `lesson_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('SCHEDULED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
  `recurrence_group` varchar(64) DEFAULT NULL,
  `commission_type` enum('PERCENT','FIXED') DEFAULT NULL,
  `commission_value` decimal(10,2) DEFAULT NULL,
  `commission_status` enum('UNPAID','PENDING','PAID') NOT NULL DEFAULT 'UNPAID',
  `commission_proof_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`lesson_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `lesson_date` (`lesson_date`),
  KEY `status` (`status`),
  KEY `recurrence_group` (`recurrence_group`),
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `lessons_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lesson_enrollments` (
  `enrollment_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('UNPAID','PENDING','PAID') NOT NULL DEFAULT 'UNPAID',
  `proof_path` varchar(255) DEFAULT NULL,
  `proof_uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `lesson_student` (`lesson_id`,`student_id`),
  KEY `lesson_id` (`lesson_id`),
  KEY `student_id` (`student_id`),
  KEY `payment_status` (`payment_status`),
  CONSTRAINT `lesson_enrollments_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE,
  CONSTRAINT `lesson_enrollments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `commission_settings` (
  `setting_key` varchar(64) NOT NULL,
  `commission_type` enum('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
  `commission_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `commission_settings` (`setting_key`, `commission_type`, `commission_value`)
VALUES ('default', 'PERCENT', 0.00);

CREATE TABLE IF NOT EXISTS `teacher_commission` (
  `teacher_id` int(11) NOT NULL,
  `commission_type` enum('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
  `commission_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`teacher_id`),
  CONSTRAINT `teacher_commission_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proof_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` enum('FEE','COMMISSION') NOT NULL,
  `lesson_id` int(11) DEFAULT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `action` enum('UPLOAD','DELETE') NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `lesson_id` (`lesson_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `kind` (`kind`),
  CONSTRAINT `proof_logs_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE,
  CONSTRAINT `proof_logs_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
