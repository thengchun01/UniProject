-- =========================================
-- 1. CREATE DATABASE
-- =========================================
CREATE DATABASE IF NOT EXISTS PSM
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE PSM;

-- =========================================
-- 2. USERS TABLE
-- =========================================
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN', 'STUDENT', 'TEACHER', 'GUEST') NOT NULL DEFAULT 'STUDENT',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL
);

INSERT INTO users (
    user_id, username, email, password_hash, role, created_at, updated_at, last_login
) VALUES
(1, 'admin', 'admin@gmail.com',
 '$2y$10$xkAJqlT2eWK..zLe55xV8.oCpuwLAM1BsAXdnzYmbmfPHKjutoc02',
 'ADMIN', '2026-06-04 19:19:24', '2026-06-07 13:17:38', '2026-06-07 13:17:38'),

(2, 'thengchun', 'thengchun@gmail.com',
 '$2y$10$GOfyFEcCaxQIT9YyAluJYuDsELpOEJ037MPq2UALGv4uAdRodFusm',
 'STUDENT', '2026-06-04 22:16:32', '2026-06-04 22:21:36', '2026-06-04 22:21:36');

-- =========================================
-- 3. TUTORIAL TOPICS
-- =========================================
CREATE TABLE IF NOT EXISTS tutorial_topics (
    topic_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(255),
    order_index INT DEFAULT 0,
    difficulty_level VARCHAR(20) DEFAULT 'BEGINNER',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tutorial_topics (
    topic_id, title, description, order_index, difficulty_level, created_at
) VALUES
(1, 'Where is C Key?', 'Keys, octaves, staff basics, and C-key recognition.', 1, 'BEGINNER', '2026-06-01 12:23:09');

-- =========================================
-- 4. TUTORIAL SECTIONS
-- =========================================
CREATE TABLE IF NOT EXISTS tutorial_sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    topic_id INT NOT NULL,
    section_key VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    content TEXT,
    code_example TEXT,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (topic_id) REFERENCES tutorial_topics(topic_id)
        ON DELETE CASCADE
);

INSERT INTO tutorial_sections (
    section_id, topic_id, section_key, title, content, code_example, order_index, created_at
) VALUES
(1, 1, 'course_01_12_keys', '12 Piano Keys',
 'Piano keys repeat in groups of 12 notes.', NULL, 1, '2026-06-01 12:23:09'),

(2, 1, 'course_01_find_c', 'Where is C?',
 'C is the white key immediately to the left of two black keys.', NULL, 2, '2026-06-01 12:23:09'),

(3, 1, 'course_01_middle_c', 'Middle C',
 'Middle C is the central reference note on the piano.', NULL, 3, '2026-06-01 12:23:09'),

(4, 1, 'course_01_staff', 'Staff',
 'Music is written on lines and spaces called the staff.', NULL, 4, '2026-06-01 12:23:09'),

(5, 1, 'course_01_octaves', 'Octaves',
 'An octave is the distance between one note and the next note with the same name.', NULL, 5, '2026-06-01 12:23:09'),

(6, 1, 'course_01_practice_c_keys', 'Interactive Practice',
 'Practice finding all C keys on the piano.', NULL, 6, '2026-06-01 12:23:09');

-- =========================================
-- 5. USER PROGRESS
-- =========================================
CREATE TABLE IF NOT EXISTS user_progress (
    progress_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    section_id INT NOT NULL,
    is_completed TINYINT(1) DEFAULT 0,
    completed_at DATETIME NULL,
    last_accessed DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_section (user_id, section_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES tutorial_sections(section_id) ON DELETE CASCADE
);

-- Example UPDATE usage
UPDATE user_progress
SET is_completed = 1,
    completed_at = NOW()
WHERE user_id = 2 AND section_id = 1;

-- =========================================
-- 6. MIDI FILES
-- =========================================
CREATE TABLE IF NOT EXISTS midi_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_name VARCHAR(100),
    file_path VARCHAR(255),
    file_size INT DEFAULT 0,
    tempo INT,
    duration_seconds INT,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- =========================================
-- 7. PERFORMANCE SESSIONS
-- =========================================
CREATE TABLE IF NOT EXISTS performance_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    midi_file_id INT NULL,
    client_session_id VARCHAR(100),
    song_title VARCHAR(150),
    input_mode ENUM('KEYBOARD','MOUSE','TOUCH','MIDI') DEFAULT 'KEYBOARD',
    session_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    accuracy DECIMAL(5,2) DEFAULT 0,
    average_speed DECIMAL(6,2) DEFAULT 0,
    avg_timing_ms INT DEFAULT 0,
    best_streak INT DEFAULT 0,
    score INT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    expected_notes INT DEFAULT 0,
    total_notes INT DEFAULT 0,
    notes_played LONGTEXT,
    analysis_json LONGTEXT,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (midi_file_id) REFERENCES midi_files(file_id) ON DELETE SET NULL
);

-- Example UPDATE
UPDATE performance_sessions
SET score = 95,
    accuracy = 92.5
WHERE session_id = 1;

-- =========================================
-- 8. USER ACTIVITY LOG
-- =========================================
CREATE TABLE IF NOT EXISTS user_activity_log (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    activity_type ENUM('PIANO_PLAY','GAME'),
    title VARCHAR(150),
    mode_key VARCHAR(80),
    shortcut_url VARCHAR(255),
    related_session_id INT NULL,
    score INT,
    accuracy DECIMAL(5,2),
    duration_seconds INT,
    summary_json LONGTEXT,
    detail_json LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (related_session_id) REFERENCES performance_sessions(session_id)
    ON DELETE SET NULL
);

-- Example UPDATE
UPDATE user_activity_log
SET score = 88,
    accuracy = 90
WHERE activity_id = 1;

-- =========================================
-- 9. CUSTOM SONG LIST
-- =========================================
CREATE TABLE IF NOT EXISTS custom_song_list (
    song_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    midi_file_id INT NOT NULL,
    song_title VARCHAR(150),
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_midi (user_id, midi_file_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (midi_file_id) REFERENCES midi_files(file_id) ON DELETE CASCADE
);