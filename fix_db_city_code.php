<?php
require 'core.php';
try {
    db()->exec("ALTER TABLE isfahan_cities MODIFY code VARCHAR(10) NOT NULL");
    echo "Success: Column 'code' updated to VARCHAR(10).\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
