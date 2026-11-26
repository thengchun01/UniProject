-- Course enquiries submitted from the public course catalogue.
-- Run this once in phpMyAdmin against the psm_2 database.

CREATE TABLE IF NOT EXISTS course_requests (
    request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id INT UNSIGNED NULL,
    course_name VARCHAR(120) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    student_age VARCHAR(30) NULL,
    class_mode VARCHAR(60) NULL,
    lesson_duration VARCHAR(40) NULL,
    preferred_days VARCHAR(255) NOT NULL,
    preferred_time_start TIME NOT NULL,
    preferred_time_end TIME NOT NULL,
    preferred_start_date DATE NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    admin_note TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_course_requests_status_created (status, created_at),
    KEY idx_course_requests_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
