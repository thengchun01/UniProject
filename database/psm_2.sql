-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2026-06-13 07:51:50
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
-- 数据库： `psm_2`
--
CREATE DATABASE psm_2;
USE psm_2;
-- --------------------------------------------------------

--
-- 表的结构 `midi_file`
--

CREATE TABLE `midi_file` (
  `midi_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `song_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `difficulty` varchar(50) DEFAULT NULL,
  `notes` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `performance_session`
--

CREATE TABLE `performance_session` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `midi_id` int(11) DEFAULT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `accuracy` decimal(5,2) DEFAULT NULL,
  `averageSpeed` decimal(8,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `song`
--

CREATE TABLE `song` (
  `song_id` int(11) NOT NULL,
  `song_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `tutorial_section`
--

CREATE TABLE `tutorial_section` (
  `section_id` int(11) NOT NULL,
  `tutorial_id` int(11) NOT NULL,
  `content` text DEFAULT NULL,
  `order_index` int(11) DEFAULT NULL,
  `expected_keys` varchar(255) DEFAULT NULL,
  `highlight_keys` varchar(255) DEFAULT NULL,
  `sheet_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `tutorial_topic`
--

CREATE TABLE `tutorial_topic` (
  `tutorial_id` int(11) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `difficulty` varchar(50) DEFAULT NULL,
  `order_index` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `role` varchar(50) DEFAULT 'student',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `active_day` int(11) DEFAULT 0,
  `time_spent` int(11) DEFAULT 0,
  `classroom` varchar(100) DEFAULT NULL,
  `classroom_status` enum('NONE','PENDING','APPROVED','REJECTED') DEFAULT NULL,
  `records` longtext DEFAULT NULL,
  `experience` int(11) DEFAULT 0,
  `level` int(11) DEFAULT 1,
  `classroom_settings` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_activity_log`
--

CREATE TABLE `user_activity_log` (
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(100) DEFAULT NULL,
  `activity_title` varchar(200) DEFAULT NULL,
  `attribute` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_progress`
--

CREATE TABLE `user_progress` (
  `progress_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_accessed` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `midi_file`
--
ALTER TABLE `midi_file`
  ADD PRIMARY KEY (`midi_id`),
  ADD KEY `user_id` (`user_id`);

--
-- 表的索引 `performance_session`
--
ALTER TABLE `performance_session`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `midi_id` (`midi_id`);

--
-- 表的索引 `song`
--
ALTER TABLE `song`
  ADD PRIMARY KEY (`song_id`);

--
-- 表的索引 `tutorial_section`
--
ALTER TABLE `tutorial_section`
  ADD PRIMARY KEY (`section_id`),
  ADD KEY `tutorial_id` (`tutorial_id`);

--
-- 表的索引 `tutorial_topic`
--
ALTER TABLE `tutorial_topic`
  ADD PRIMARY KEY (`tutorial_id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 表的索引 `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `user_id` (`user_id`);

--
-- 表的索引 `user_progress`
--
ALTER TABLE `user_progress`
  ADD PRIMARY KEY (`progress_id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`section_id`),
  ADD KEY `section_id` (`section_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `midi_file`
--
ALTER TABLE `midi_file`
  MODIFY `midi_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `performance_session`
--
ALTER TABLE `performance_session`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `song`
--
ALTER TABLE `song`
  MODIFY `song_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tutorial_section`
--
ALTER TABLE `tutorial_section`
  MODIFY `section_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tutorial_topic`
--
ALTER TABLE `tutorial_topic`
  MODIFY `tutorial_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

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
--
-- 限制表 `midi_file`
--
ALTER TABLE `midi_file`
  ADD CONSTRAINT `midi_file_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- 限制表 `performance_session`
--
ALTER TABLE `performance_session`
  ADD CONSTRAINT `performance_session_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performance_session_ibfk_2` FOREIGN KEY (`midi_id`) REFERENCES `midi_file` (`midi_id`) ON DELETE SET NULL;

--
-- 限制表 `tutorial_section`
--
ALTER TABLE `tutorial_section`
  ADD CONSTRAINT `tutorial_section_ibfk_1` FOREIGN KEY (`tutorial_id`) REFERENCES `tutorial_topic` (`tutorial_id`) ON DELETE CASCADE;

--
-- 限制表 `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- 限制表 `user_progress`
--
ALTER TABLE `user_progress`
  ADD CONSTRAINT `user_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_progress_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `tutorial_section` (`section_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
