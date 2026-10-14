-- Link existing lessons whose title exactly matches a course name.
-- Safe to run after the Courses migration has created courses and lessons.course_id.
-- Custom titles that do not exactly match a course are intentionally left unchanged.
UPDATE lessons AS l
INNER JOIN courses AS c ON c.course_name = l.title
SET l.course_id = c.course_id
WHERE l.course_id IS NULL;
