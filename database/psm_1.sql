-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2026-06-11 07:00:15
-- 服务器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `psm_1`
--

-- --------------------------------------------------------

--
-- 表的结构 `custom_song_list`
--

CREATE TABLE `custom_song_list` (
  `song_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `midi_file_id` int(11) NOT NULL,
  `song_title` varchar(150) DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `midi_files`
--

CREATE TABLE `midi_files` (
  `file_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_name` varchar(100) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `tempo` int(11) DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `performance_sessions`
--

CREATE TABLE `performance_sessions` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `midi_file_id` int(11) DEFAULT NULL,
  `client_session_id` varchar(100) DEFAULT NULL,
  `song_title` varchar(150) DEFAULT NULL,
  `input_mode` enum('KEYBOARD','MOUSE','TOUCH','MIDI') DEFAULT 'KEYBOARD',
  `session_date` datetime DEFAULT current_timestamp(),
  `accuracy` decimal(5,2) DEFAULT 0.00,
  `average_speed` decimal(6,2) DEFAULT 0.00,
  `avg_timing_ms` int(11) DEFAULT 0,
  `best_streak` int(11) DEFAULT 0,
  `score` int(11) DEFAULT 0,
  `duration_seconds` int(11) DEFAULT 0,
  `expected_notes` int(11) DEFAULT 0,
  `total_notes` int(11) DEFAULT 0,
  `notes_played` longtext DEFAULT NULL,
  `analysis_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `tutorial_sections`
--

CREATE TABLE `tutorial_sections` (
  `section_id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `section_key` varchar(100) NOT NULL,
  `title` varchar(150) NOT NULL,
  `content` text DEFAULT NULL,
  `code_example` text DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `tutorial_sections`
--

INSERT INTO `tutorial_sections` (`section_id`, `topic_id`, `section_key`, `title`, `content`, `code_example`, `order_index`, `created_at`) VALUES
(1, 1, 'course_01_12_keys', '12 Piano Keys', 'Piano keys repeat in groups of 12 notes.', NULL, 1, '2026-06-01 04:23:09'),
(2, 1, 'course_01_find_c', 'Where is C?', 'C is the white key immediately to the left of two black keys.', NULL, 2, '2026-06-01 04:23:09'),
(3, 1, 'course_01_middle_c', 'Middle C', 'Middle C is the central reference note on the piano.', NULL, 3, '2026-06-01 04:23:09'),
(4, 1, 'course_01_staff', 'Staff', 'Music is written on lines and spaces called the staff.', NULL, 4, '2026-06-01 04:23:09'),
(5, 1, 'course_01_octaves', 'Octaves', 'An octave is the distance between one note and the next note with the same name.', NULL, 5, '2026-06-01 04:23:09'),
(6, 1, 'course_01_practice_c_keys', 'Interactive Practice', 'Practice finding all C keys on the piano.', NULL, 6, '2026-06-01 04:23:09');

-- --------------------------------------------------------

--
-- 表的结构 `tutorial_topics`
--

CREATE TABLE `tutorial_topics` (
  `topic_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `order_index` int(11) DEFAULT 0,
  `difficulty_level` varchar(20) DEFAULT 'BEGINNER',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `tutorial_topics`
--

INSERT INTO `tutorial_topics` (`topic_id`, `title`, `description`, `order_index`, `difficulty_level`, `created_at`) VALUES
(1, 'Where is C Key?', 'Keys, octaves, staff basics, and C-key recognition.', 1, 'BEGINNER', '2026-06-01 04:23:09');

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('ADMIN','STUDENT','TEACHER','GUEST') NOT NULL DEFAULT 'STUDENT',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `created_at`, `updated_at`, `last_login`) VALUES
(1, 'admin', 'admin@gmail.com', '$2y$10$xkAJqlT2eWK..zLe55xV8.oCpuwLAM1BsAXdnzYmbmfPHKjutoc02', 'ADMIN', '2026-06-04 11:19:24', '2026-06-09 10:56:04', '2026-06-09 10:56:04'),
(2, 'thengchun', 'thengchun@gmail.com', '$2y$10$GOfyFEcCaxQIT9YyAluJYuDsELpOEJ037MPq2UALGv4uAdRodFusm', 'STUDENT', '2026-06-04 14:16:32', '2026-06-04 14:21:36', '2026-06-04 14:21:36');

-- --------------------------------------------------------

--
-- 表的结构 `user_activity_log`
--

CREATE TABLE `user_activity_log` (
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` enum('PIANO_PLAY','GAME') DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `mode_key` varchar(80) DEFAULT NULL,
  `shortcut_url` varchar(255) DEFAULT NULL,
  `related_session_id` int(11) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `accuracy` decimal(5,2) DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `detail_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_progress`
--

CREATE TABLE `user_progress` (
  `progress_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `last_accessed` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `custom_song_list`
--
ALTER TABLE `custom_song_list`
  ADD PRIMARY KEY (`song_id`),
  ADD UNIQUE KEY `uq_user_midi` (`user_id`,`midi_file_id`),
  ADD KEY `midi_file_id` (`midi_file_id`);

--
-- 表的索引 `midi_files`
--
ALTER TABLE `midi_files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `user_id` (`user_id`);

--
-- 表的索引 `performance_sessions`
--
ALTER TABLE `performance_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `midi_file_id` (`midi_file_id`);

--
-- 表的索引 `tutorial_sections`
--
ALTER TABLE `tutorial_sections`
  ADD PRIMARY KEY (`section_id`),
  ADD UNIQUE KEY `section_key` (`section_key`),
  ADD KEY `topic_id` (`topic_id`);

--
-- 表的索引 `tutorial_topics`
--
ALTER TABLE `tutorial_topics`
  ADD PRIMARY KEY (`topic_id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- 表的索引 `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `related_session_id` (`related_session_id`);

--
-- 表的索引 `user_progress`
--
ALTER TABLE `user_progress`
  ADD PRIMARY KEY (`progress_id`),
  ADD UNIQUE KEY `uq_user_section` (`user_id`,`section_id`),
  ADD KEY `section_id` (`section_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `custom_song_list`
--
ALTER TABLE `custom_song_list`
  MODIFY `song_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `midi_files`
--
ALTER TABLE `midi_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `performance_sessions`
--
ALTER TABLE `performance_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tutorial_sections`
--
ALTER TABLE `tutorial_sections`
  MODIFY `section_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- 使用表AUTO_INCREMENT `tutorial_topics`
--
ALTER TABLE `tutorial_topics`
  MODIFY `topic_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- 使用表AUTO_INCREMENT `user_activity_log`
--
ALTER TABLE `user_activity_log`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `user_progress`
--
ALTER TABLE `user_progress`
  MODIFY `progress_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `custom_song_list`
--
ALTER TABLE `custom_song_list`
  ADD CONSTRAINT `custom_song_list_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `custom_song_list_ibfk_2` FOREIGN KEY (`midi_file_id`) REFERENCES `midi_files` (`file_id`) ON DELETE CASCADE;

--
-- 限制表 `midi_files`
--
ALTER TABLE `midi_files`
  ADD CONSTRAINT `midi_files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- 限制表 `performance_sessions`
--
ALTER TABLE `performance_sessions`
  ADD CONSTRAINT `performance_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performance_sessions_ibfk_2` FOREIGN KEY (`midi_file_id`) REFERENCES `midi_files` (`file_id`) ON DELETE SET NULL;

--
-- 限制表 `tutorial_sections`
--
ALTER TABLE `tutorial_sections`
  ADD CONSTRAINT `tutorial_sections_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `tutorial_topics` (`topic_id`) ON DELETE CASCADE;

--
-- 限制表 `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_activity_log_ibfk_2` FOREIGN KEY (`related_session_id`) REFERENCES `performance_sessions` (`session_id`) ON DELETE SET NULL;

--
-- 限制表 `user_progress`
--
ALTER TABLE `user_progress`
  ADD CONSTRAINT `user_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_progress_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `tutorial_sections` (`section_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
