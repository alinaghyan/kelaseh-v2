<?php
define('KELASEH_LIB_ONLY', true);
require_once 'core.php';

try {
    $pdo = db();
    
    // Create kelaseh_sessions table
    $sql = "CREATE TABLE IF NOT EXISTS `kelaseh_sessions` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `kelaseh_id` INT(11) UNSIGNED NOT NULL,
        `session_key` VARCHAR(20) NOT NULL COMMENT 'session1...session5, resolution',
        `meeting_date` VARCHAR(20) NULL DEFAULT NULL,
        `plaintiff_request` TEXT NULL,
        `verdict_text` TEXT NULL,
        `reps_govt` TEXT NULL,
        `reps_worker` TEXT NULL,
        `reps_employer` TEXT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_kelaseh_session` (`kelaseh_id`, `session_key`),
        CONSTRAINT `fk_kelaseh_sessions_id` FOREIGN KEY (`kelaseh_id`) REFERENCES `kelaseh_numbers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;";
    
    $pdo->exec($sql);
    echo "Table 'kelaseh_sessions' created or already exists.<br>";
    
    echo "Database update completed successfully.";
    
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
