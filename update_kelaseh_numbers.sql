DROP TABLE IF EXISTS `kelaseh_numbers`;
DROP TABLE IF EXISTS `kelaseh_daily_counters`;

CREATE TABLE `kelaseh_numbers` (
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

CREATE TABLE `kelaseh_daily_counters` (
  `owner_id` INT UNSIGNED NOT NULL,
  `jalali_ymd` CHAR(6) NOT NULL,
  `branch_no` TINYINT UNSIGNED NOT NULL,
  `seq_no` TINYINT UNSIGNED NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`owner_id`, `jalali_ymd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
