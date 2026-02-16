<?php
define('KELASEH_LIB_ONLY', true);
require __DIR__ . '/../core.php';

ensure_kelaseh_numbers_supports_new_case_code();
ensure_user_branches_table();

$cityCode = '98';
$cityName = 'اداره تست (98)';

try {
    db()->prepare('INSERT IGNORE INTO isfahan_cities (code, name) VALUES (?, ?)')->execute([$cityCode, $cityName]);
} catch (Throwable $e) {
}

$username = 'test_ba_' . bin2hex(random_bytes(3));
$passwordPlain = '123456';
$hash = password_hash($passwordPlain, PASSWORD_DEFAULT);

db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
    VALUES (?, ?, ?, ?, ?, 'branch_admin', ?, 1, 2, 1, ?)")
    ->execute([$username, $hash, 'تست', 'شعبه', '0912' . random_int(1000000, 9999999), $cityCode, now_mysql()]);

$userId = (int)db()->lastInsertId();

db()->prepare('INSERT INTO user_branches (user_id, branch_no) VALUES (?, ?), (?, ?)')->execute([$userId, 1, $userId, 2]);

foreach ([1, 2] as $b) {
    db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)')
        ->execute([$cityCode, $b, 200]);
}

$user = [
    'id' => $userId,
    'role' => 'branch_admin',
    'city_code' => $cityCode,
    'branches' => [1, 2],
    'branch_start_no' => 1,
    'branch_count' => 2,
];

echo "username=$username\n";
echo "password=$passwordPlain\n";
echo "user_id=$userId\n";
echo "city_code=$cityCode\n\n";

for ($i = 1; $i <= 10; $i++) {
    $branch = ($i % 2 === 1) ? 1 : 2;
    $payload = [
        'branch_no' => $branch,
        'plaintiff_name' => 'خواهان تست دو شعبه ' . $i,
        'plaintiff_national_code' => strval(1000000000 + $i),
        'plaintiff_mobile' => '0912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT),
        'defendant_name' => '',
        'defendant_national_code' => '',
        'defendant_mobile' => '',
    ];

    $res = kelaseh_create_internal($user, $payload);
    $oldCode = (string)($res['code'] ?? '');

    $stmt = db()->prepare('SELECT new_case_code FROM kelaseh_numbers WHERE owner_id = ? AND code = ? LIMIT 1');
    $stmt->execute([$userId, $oldCode]);
    $newCaseCode = (string)($stmt->fetchColumn() ?: '');

    echo sprintf("%02d) branch=%d old=%s new=%s\n", $i, $branch, $oldCode, $newCaseCode);
}

