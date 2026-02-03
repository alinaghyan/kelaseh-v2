CREATE DATABASE IF NOT EXISTS `kelaseh_v2` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
USE `kelaseh_v2`;

-- --------------------------------------------------------
-- ساختار جدول شهرها
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `isfahan_cities` (
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- داده‌های اولیه شهرها
INSERT IGNORE INTO `isfahan_cities` (`code`, `name`) VALUES
('01','اصفهان (اداره کل)'),
('02','اصفهان (جنوب)'),
('03','اصفهان (شمال)'),
('04','خمینی‌شهر'),
('05','نجف‌آباد'),
('06','شاهین‌شهر و میمه'),
('07','کاشان'),
('08','آران و بیدگل'),
('09','نایین'),
('10','نطنز'),
('11','سمیرم'),
('12','شهرضا'),
('13','دهاقان'),
('14','لنجان (زرین‌شهر)'),
('15','فلاورجان'),
('16','فریدن (داران)'),
('17','فریدون‌شهر'),
('18','چادگان'),
('19','خور و بیابانک');

-- --------------------------------------------------------
-- ساختار جدول کاربران
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NULL,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','office_admin','branch_admin','user') NOT NULL DEFAULT 'user',
  `display_name` VARCHAR(100) NULL,
  `first_name` VARCHAR(60) NULL,
  `last_name` VARCHAR(60) NULL,
  `mobile` VARCHAR(20) NULL,
  `national_code` VARCHAR(20) NULL,
  `city_code` VARCHAR(10) NULL,
  `branch_count` TINYINT UNSIGNED NULL,
  `branch_start_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `branch_capacity` INT UNSIGNED NOT NULL DEFAULT 10,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `last_login_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_username` (`username`),
  KEY `idx_users_city_code` (`city_code`),
  CONSTRAINT `fk_users_city` FOREIGN KEY (`city_code`) REFERENCES `isfahan_cities` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ایجاد مدیر کل پیش‌فرض
-- نام کاربری: alinaghyan | رمز عبور: 4646232323
INSERT INTO `users` (`username`, `password_hash`, `role`, `is_active`, `created_at`)
VALUES ('alinaghyan', '$2y$10$oZmFDnIhMkPDR8gycMIVyOvDtL5efAaVSxj4d7mF5WwLLr6/W.JPu', 'admin', 1, NOW())
ON DUPLICATE KEY UPDATE `role` = 'admin';

-- --------------------------------------------------------
-- ساختار جدول شعب کاربران
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_branches` (
  `user_id` INT UNSIGNED NOT NULL,
  `branch_no` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `branch_no`),
  CONSTRAINT `fk_user_branches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول ظرفیت شعب ادارات
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `office_branch_capacities` (
  `city_code` VARCHAR(10) NOT NULL,
  `branch_no` TINYINT UNSIGNED NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 15,
  PRIMARY KEY (`city_code`, `branch_no`),
  CONSTRAINT `fk_office_branch_capacities_city` FOREIGN KEY (`city_code`) REFERENCES `isfahan_cities` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول تنظیمات برنامه
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `k` VARCHAR(80) NOT NULL,
  `v` TEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول داده‌ها (Items)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_owner_id` (`owner_id`),
  KEY `idx_items_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول شماره‌های کلاسه
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelaseh_numbers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `branch_no` TINYINT UNSIGNED NOT NULL,
  `jalali_ymd` CHAR(6) NOT NULL,
  `jalali_full_ymd` CHAR(8) NOT NULL,
  `seq_no` TINYINT UNSIGNED NOT NULL,
  `plaintiff_name` VARCHAR(120) NOT NULL,
  `defendant_name` VARCHAR(120) NOT NULL,
  `plaintiff_national_code` VARCHAR(20) NOT NULL,
  `defendant_national_code` VARCHAR(20) NOT NULL,
  `plaintiff_mobile` VARCHAR(20) NOT NULL,
  `defendant_mobile` VARCHAR(20) NOT NULL,
  `status` ENUM('active','inactive','voided') NOT NULL DEFAULT 'active',
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kelaseh_numbers_code` (`owner_id`, `code`),
  KEY `idx_kelaseh_numbers_owner_date` (`owner_id`, `jalali_full_ymd`, `branch_no`, `seq_no`),
  KEY `idx_kelaseh_numbers_created_at` (`created_at`),
  KEY `idx_kelaseh_numbers_plaintiff` (`plaintiff_national_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول شمارنده‌های روزانه
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kelaseh_daily_counters_v2` (
  `city_code` VARCHAR(10) NOT NULL,
  `jalali_ymd` CHAR(6) NOT NULL,
  `branch_no` INT NOT NULL,
  `seq_no` INT NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`city_code`, `jalali_ymd`, `branch_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- ساختار جدول گزارشات (Audit Logs)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED NULL,
  `action` VARCHAR(30) NOT NULL,
  `entity` VARCHAR(30) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `target_user_id` INT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_actor_id` (`actor_id`),
  KEY `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- --------------------------------------------------------
-- ساختار جدول لاگ‌های پیامک
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_mobile` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('plaintiff','defendant','otp') NOT NULL,
  `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
