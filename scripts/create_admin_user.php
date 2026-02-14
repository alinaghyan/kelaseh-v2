<?php

declare(strict_types=1);

$cfgPath = __DIR__ . '/../config.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(1);
}

$cfg = require $cfgPath;
$db = $cfg['db'] ?? [];

$dsn = 'mysql:host=' . ($db['host'] ?? '127.0.0.1') . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . ($db['name'] ?? '') . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)($db['user'] ?? 'root'), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$username = (string)($argv[1] ?? 'admin_master');
$password = (string)($argv[2] ?? '');
if ($password === '') {
    $password = 'Kela#' . bin2hex(random_bytes(5));
}

$first = 'مدیر';
$last = 'کل';
$mobile = '0912' . random_int(1000000, 9999999);
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$id = $stmt->fetchColumn();

if ($id) {
    $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?")
        ->execute([$hash, (int)$id]);
} else {
    $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, ?, ?, ?, 'admin', NULL, 1, 1, 1, NOW())")
        ->execute([$username, $hash, $first, $last, $mobile]);
    $id = (int)$pdo->lastInsertId();
}

echo "ADMIN_ID=$id\n";
echo "USERNAME=$username\n";
echo "PASSWORD=$password\n";

