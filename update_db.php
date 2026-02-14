<?php
require_once __DIR__ . '/core.php';

try {
    $pdo = db();
    
    // Add columns to isfahan_cities
    try {
        $pdo->exec("ALTER TABLE isfahan_cities ADD COLUMN address TEXT DEFAULT NULL");
        echo "Added address to isfahan_cities\n";
    } catch (PDOException $e) {
        echo "address already exists or error: " . $e->getMessage() . "\n";
    }

    try {
        $pdo->exec("ALTER TABLE isfahan_cities ADD COLUMN postal_code VARCHAR(20) DEFAULT NULL");
        echo "Added postal_code to isfahan_cities\n";
    } catch (PDOException $e) {
        echo "postal_code already exists or error: " . $e->getMessage() . "\n";
    }

    // Add columns to kelaseh_numbers
    $cols = [
        'representatives_govt' => 'TEXT DEFAULT NULL',
        'representatives_worker' => 'TEXT DEFAULT NULL',
        'representatives_employer' => 'TEXT DEFAULT NULL',
        'plaintiff_request' => 'TEXT DEFAULT NULL',
        'verdict_text' => 'TEXT DEFAULT NULL'
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
