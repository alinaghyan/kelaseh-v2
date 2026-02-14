<?php
require_once __DIR__ . '/core.php';

try {
    $pdo = db();
    
    $cols = [
        'notice_number' => 'VARCHAR(50) DEFAULT NULL',
        'session1_date' => 'VARCHAR(20) DEFAULT NULL',
        'session2_date' => 'VARCHAR(20) DEFAULT NULL',
        'session3_date' => 'VARCHAR(20) DEFAULT NULL',
        'session4_date' => 'VARCHAR(20) DEFAULT NULL',
        'session5_date' => 'VARCHAR(20) DEFAULT NULL',
        'dispute_resolution_date' => 'VARCHAR(20) DEFAULT NULL'
    ];

    foreach ($cols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE kelaseh_numbers ADD COLUMN $col $def");
            echo "Added $col to kelaseh_numbers\n";
        } catch (PDOException $e) {
            echo "$col already exists or error: " . $e->getMessage() . "\n";
        }
    }

    echo "Database updates completed.\n";

} catch (Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
