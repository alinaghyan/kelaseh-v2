CREATE TABLE IF NOT EXISTS `isfahan_cities` (
  `code` CHAR(2) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `users`
  ADD COLUMN `username` VARCHAR(50) NULL AFTER `email`,
  ADD COLUMN `first_name` VARCHAR(60) NULL AFTER `display_name`,
  ADD COLUMN `last_name` VARCHAR(60) NULL AFTER `first_name`,
  ADD COLUMN `city_code` CHAR(2) NULL AFTER `national_code`,
  ADD COLUMN `branch_count` TINYINT UNSIGNED NULL AFTER `city_code`;

ALTER TABLE `users`
  ADD COLUMN `branch_start_no` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `branch_count`;

UPDATE `users`
SET `city_code` = NULL
WHERE `city_code` IS NOT NULL
  AND `city_code` NOT IN ('01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19');

DELETE FROM `isfahan_cities`
WHERE `code` NOT IN ('01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19');

INSERT INTO `isfahan_cities` (`code`, `name`) VALUES
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
('19','خور و بیابانک')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

UPDATE `users`
SET `username` = CONCAT('user', `id`)
WHERE `username` IS NULL OR `username` = '';

ALTER TABLE `users`
  MODIFY COLUMN `username` VARCHAR(50) NOT NULL,
  ADD UNIQUE KEY `uniq_users_username` (`username`),
  ADD KEY `idx_users_city_code` (`city_code`);

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_city` FOREIGN KEY (`city_code`) REFERENCES `isfahan_cities` (`code`) ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS `kelaseh_numbers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` INT UNSIGNED NOT NULL,
  `code` CHAR(10) NOT NULL,
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
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kelaseh_numbers_code` (`owner_id`, `code`),
  UNIQUE KEY `uniq_kelaseh_plaintiff_code` (`owner_id`, `plaintiff_national_code`, `code`),
  UNIQUE KEY `uniq_kelaseh_plaintiff_date` (`owner_id`, `plaintiff_national_code`, `jalali_full_ymd`),
  KEY `idx_kelaseh_numbers_owner_date` (`owner_id`, `jalali_full_ymd`, `branch_no`, `seq_no`),
  KEY `idx_kelaseh_numbers_created_at` (`created_at`),
  KEY `idx_kelaseh_numbers_plaintiff` (`plaintiff_national_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `kelaseh_daily_counters` (
  `owner_id` INT UNSIGNED NOT NULL,
  `jalali_ymd` CHAR(6) NOT NULL,
  `branch_no` TINYINT UNSIGNED NOT NULL,
  `seq_no` TINYINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`owner_id`, `jalali_ymd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
