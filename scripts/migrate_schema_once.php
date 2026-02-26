<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only run in CLI.\n";
    exit(1);
}

define('KELASEH_LIB_ONLY', true);
require __DIR__ . '/../core.php';

function mig_table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function mig_exec(string $sql): void
{
    db()->exec($sql);
}

function mig_add_column_if_missing(string $table, string $column, string $ddl): void
{
    if (!mig_table_exists($table)) {
        return;
    }
    if (mig_column_exists($table, $column)) {
        return;
    }
    mig_exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
}

function mig_create_missing_tables_and_columns(): void
{
    if (!mig_table_exists('kelaseh_yearly_counters')) {
        mig_exec("CREATE TABLE `kelaseh_yearly_counters` (
            `city_code` VARCHAR(10) NOT NULL,
            `yy` CHAR(2) NOT NULL,
            `seq_no` INT UNSIGNED NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`city_code`, `yy`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!mig_table_exists('kelaseh_sessions')) {
        mig_exec("CREATE TABLE `kelaseh_sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kelaseh_id` INT UNSIGNED NOT NULL,
            `session_key` VARCHAR(20) NOT NULL,
            `meeting_date` VARCHAR(20) NULL,
            `meeting_time` VARCHAR(5) NULL,
            `meeting_room` VARCHAR(3) NULL,
            `plaintiff_request` TEXT NULL,
            `verdict_text` TEXT NULL,
            `reps_govt` VARCHAR(255) NULL,
            `reps_worker` VARCHAR(255) NULL,
            `reps_employer` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ks_kelaseh` (`kelaseh_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    mig_add_column_if_missing('kelaseh_sessions', 'meeting_time', "`meeting_time` VARCHAR(5) NULL");
    mig_add_column_if_missing('kelaseh_sessions', 'meeting_room', "`meeting_room` VARCHAR(3) NULL");

    if (!mig_table_exists('kelaseh_edit_logs')) {
        mig_exec("CREATE TABLE `kelaseh_edit_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kelaseh_id` INT UNSIGNED NOT NULL,
            `editor_user_id` INT UNSIGNED NOT NULL,
            `editor_role` VARCHAR(30) NOT NULL,
            `editor_city_code` VARCHAR(10) NULL,
            `editor_name` VARCHAR(120) NULL,
            `office_name` VARCHAR(120) NULL,
            `action_type` VARCHAR(30) NOT NULL,
            `changed_fields` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_kelaseh_edit_logs_kelaseh` (`kelaseh_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!mig_table_exists('login_attempts')) {
        mig_exec("CREATE TABLE `login_attempts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `login_key` VARCHAR(120) NOT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `attempted_at` DATETIME NOT NULL,
            `success` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_login_attempts_login_time` (`login_key`, `attempted_at`),
            KEY `idx_login_attempts_ip_time` (`ip`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!mig_table_exists('manager_messages')) {
        mig_exec("CREATE TABLE `manager_messages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sender_id` INT UNSIGNED NOT NULL,
            `sender_role` VARCHAR(30) NOT NULL,
            `target_role` VARCHAR(30) NOT NULL,
            `target_city_code` VARCHAR(10) NULL,
            `target_user_id` INT UNSIGNED NULL,
            `title` VARCHAR(200) NOT NULL,
            `content` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_manager_messages_target` (`target_role`, `target_city_code`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    mig_add_column_if_missing('manager_messages', 'updated_at', "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    mig_add_column_if_missing('manager_messages', 'deleted_at', "`deleted_at` DATETIME NULL");

    if (!mig_table_exists('manager_message_reads')) {
        mig_exec("CREATE TABLE `manager_message_reads` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `read_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_manager_message_read` (`message_id`, `user_id`),
            KEY `idx_manager_message_reads_user` (`user_id`, `read_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Required by create/update/list flows.
    mig_add_column_if_missing('kelaseh_numbers', 'is_manual', "`is_manual` TINYINT(1) NOT NULL DEFAULT 0");
    mig_add_column_if_missing('kelaseh_numbers', 'is_manual_branch', "`is_manual_branch` TINYINT(1) NOT NULL DEFAULT 0");
    mig_add_column_if_missing('kelaseh_numbers', 'is_resolution', "`is_resolution` TINYINT(1) NOT NULL DEFAULT 0");
    mig_add_column_if_missing('kelaseh_numbers', 'new_case_code', "`new_case_code` VARCHAR(30) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'notice_number', "`notice_number` VARCHAR(100) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'plaintiff_address', "`plaintiff_address` TEXT NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'plaintiff_postal_code', "`plaintiff_postal_code` VARCHAR(20) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'defendant_address', "`defendant_address` TEXT NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'defendant_postal_code', "`defendant_postal_code` VARCHAR(20) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'dadnameh', "`dadnameh` VARCHAR(120) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'representatives_govt', "`representatives_govt` VARCHAR(255) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'representatives_worker', "`representatives_worker` VARCHAR(255) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'representatives_employer', "`representatives_employer` VARCHAR(255) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'plaintiff_request', "`plaintiff_request` TEXT NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'verdict_text', "`verdict_text` TEXT NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'print_type', "`print_type` VARCHAR(20) NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'last_printed_at', "`last_printed_at` DATETIME NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'last_notice_printed_at', "`last_notice_printed_at` DATETIME NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'last_invitation_printed_at', "`last_invitation_printed_at` DATETIME NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'last_verdict_notice_printed_at', "`last_verdict_notice_printed_at` DATETIME NULL");
    mig_add_column_if_missing('kelaseh_numbers', 'last_exec_form_printed_at', "`last_exec_form_printed_at` DATETIME NULL");
}

try {
    mig_create_missing_tables_and_columns();

    ensure_city_code_supports_variable_length();
    ensure_user_branches_table();
    ensure_kelaseh_daily_counters_table_v2();
    ensure_kelaseh_yearly_counters_table();
    ensure_kelaseh_sessions_table();
    ensure_kelaseh_edit_logs_table();
    ensure_login_attempts_table();
    ensure_sms_logs_supports_otp();
    ensure_kelaseh_numbers_code_supports_city_prefix();
    ensure_kelaseh_numbers_supports_manual_flag();
    ensure_kelaseh_numbers_supports_new_case_code();
    ensure_kelaseh_numbers_supports_resolution_flag();
    ensure_kelaseh_numbers_supports_extended_fields();
    ensure_manager_messages_tables();

    setting_set('schema.version', '2026-02-26-ddl-migrated');
    echo "Schema migration completed and runtime DDL disabled.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Schema migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
