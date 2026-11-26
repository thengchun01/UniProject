-- Upgrade existing course_requests tables to use real student and schedule records.
-- Run once in phpMyAdmin against psm_2 after database/course_requests.sql.
-- Existing enquiries remain intact; student_id stays NULL for older requests.

ALTER TABLE course_requests
    ADD COLUMN IF NOT EXISTS student_id INT(11) NULL AFTER request_id,
    ADD COLUMN IF NOT EXISTS assigned_teacher_id INT(11) NULL AFTER student_id,
    ADD COLUMN IF NOT EXISTS scheduled_lesson_id INT(11) NULL AFTER assigned_teacher_id;

ALTER TABLE course_requests
    ADD KEY IF NOT EXISTS idx_course_requests_student (student_id),
    ADD KEY IF NOT EXISTS idx_course_requests_teacher (assigned_teacher_id),
    ADD UNIQUE KEY IF NOT EXISTS uq_course_requests_scheduled_lesson (scheduled_lesson_id);
