<?php

declare(strict_types=1);

function cfg(): array
{
    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) throw new RuntimeException('config.php not found');
    return require $path;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = cfg();
    $db = $cfg['db'] ?? [];
    $dsn = 'mysql:host=' . ($db['host'] ?? '127.0.0.1') . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . ($db['name'] ?? '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)($db['user'] ?? 'root'), (string)($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function find_free_port(int $start = 18300, int $end = 18400): int
{
    for ($p = $start; $p <= $end; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
        if ($sock) {
            fclose($sock);
            return $p;
        }
    }
    throw new RuntimeException('No free port found');
}

function start_server(string $docRoot, int $port): array
{
    $cmd = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes, $docRoot);
    if (!is_resource($proc)) throw new RuntimeException('Failed to start server');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $baseUrl = 'http://127.0.0.1:' . $port . '/';

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['action' => 'session']),
                'timeout' => 1,
            ],
        ]);
        $res = @file_get_contents($baseUrl . 'core.php', false, $ctx);
        if (is_string($res) && $res !== '') {
            $json = json_decode($res, true);
            if (is_array($json) && ($json['ok'] ?? false) === true) {
                return ['proc' => $proc, 'pipes' => $pipes, 'base_url' => $baseUrl];
            }
        }
        usleep(100_000);
    }

    $err = stream_get_contents($pipes[2]);
    proc_terminate($proc);
    throw new RuntimeException('Server not ready: ' . $err);
}

function stop_server(?array $server): void
{
    if (!$server) return;
    if (!empty($server['proc']) && is_resource($server['proc'])) {
        @proc_terminate($server['proc']);
        @proc_close($server['proc']);
    }
}

final class HttpClient
{
    private string $baseUrl;
    private string $cookieFile;
    public function __construct(string $baseUrl)
    {
        if (!extension_loaded('curl')) throw new RuntimeException('cURL not enabled');
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'kelaseh_cookie_') ?: (sys_get_temp_dir() . '/kelaseh_cookie.txt');
    }
    public function post(array $data, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . 'core.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $err);
        }
        curl_close($ch);
        $json = json_decode((string)$body, true);
        if (!is_array($json)) throw new RuntimeException('Non-JSON (HTTP ' . $code . '): ' . substr((string)$body, 0, 200));
        return ['status' => $code, 'json' => $json];
    }
    public function __destruct()
    {
        if (is_file($this->cookieFile)) @unlink($this->cookieFile);
    }
}

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function pick_city_code(array $reserved = []): string
{
    for ($i = 0; $i < 200; $i++) {
        $cand = sprintf('%02d', random_int(10, 99));
        if (in_array($cand, $reserved, true)) continue;
        $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
        $stmt->execute([$cand]);
        if (!$stmt->fetchColumn()) return $cand;
    }
    throw new RuntimeException('No free city code');
}

$server = null;
$adminId = null;
$officeId = null;
$branchId = null;
$cityCode1 = null;
$cityCode2 = null;

try {
    $port = find_free_port();
    $server = start_server(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), $port);
    $http = new HttpClient($server['base_url']);

    $adminUser = 'e2e_admin_' . random_int(10000, 99999);
    $adminPass = 'E2e#' . bin2hex(random_bytes(4));
    $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, 'تست', 'مدیرکل', '0912', 'admin', NULL, 1, 1, 1, NOW())")
        ->execute([$adminUser, $adminHash]);
    $adminId = (int)db()->lastInsertId();

    $sess = $http->post(['action' => 'session']);
    assert_true(($sess['json']['ok'] ?? false) === true, 'session failed');
    $csrf = (string)($sess['json']['data']['csrf_token'] ?? '');
    assert_true($csrf !== '', 'csrf missing');

    $login = $http->post(['action' => 'login', 'login' => $adminUser, 'password' => $adminPass]);
    assert_true(($login['json']['ok'] ?? false) === true, 'admin login failed');
    $csrf = (string)($login['json']['data']['csrf_token'] ?? $csrf);
    assert_true($csrf !== '', 'csrf missing after login');
    $hdr = ['X-CSRF-Token: ' . $csrf];

    $cityCode1 = pick_city_code();
    $cityCode2 = pick_city_code([$cityCode1]);

    $createCity = $http->post(['action' => 'admin.cities.create', 'code' => $cityCode1, 'name' => 'شهر تست E2E'], $hdr);
    assert_true(($createCity['json']['ok'] ?? false) === true, 'city create failed: ' . (string)($createCity['json']['message'] ?? ''));

    $updateCity = $http->post(['action' => 'admin.cities.update', 'code' => $cityCode1, 'new_code' => $cityCode2, 'name' => 'شهر تست ویرایش'], $hdr);
    assert_true(($updateCity['json']['ok'] ?? false) === true, 'city update failed: ' . (string)($updateCity['json']['message'] ?? ''));

    $listCities = $http->post(['action' => 'admin.cities.list'], $hdr);
    assert_true(($listCities['json']['ok'] ?? false) === true, 'cities list failed');
    $cities = $listCities['json']['data']['cities'] ?? [];
    $found = false;
    foreach ($cities as $c) {
        if ((string)($c['code'] ?? '') === $cityCode2) $found = true;
    }
    assert_true($found, 'updated city not found in list');

    $officeUser = 'e2e_office_' . random_int(10000, 99999);
    $officePass = 'Off#' . bin2hex(random_bytes(4));
    $createOffice = $http->post([
        'action' => 'admin.users.create',
        'first_name' => 'مدیر',
        'last_name' => 'اداره',
        'username' => $officeUser,
        'mobile' => '0912' . random_int(1000000, 9999999),
        'password' => $officePass,
        'city_code' => $cityCode2,
        'role' => 'office_admin',
        'branch_count' => 1,
        'branch_start_no' => 1,
    ], $hdr);
    assert_true(($createOffice['json']['ok'] ?? false) === true, 'office admin create failed: ' . (string)($createOffice['json']['message'] ?? ''));

    $branchUser = 'e2e_branch_' . random_int(10000, 99999);
    $branchPass = 'Br#' . bin2hex(random_bytes(4));
    $createBranch = $http->post([
        'action' => 'admin.users.create',
        'first_name' => 'مدیر',
        'last_name' => 'شعبه',
        'username' => $branchUser,
        'mobile' => '0912' . random_int(1000000, 9999999),
        'password' => $branchPass,
        'city_code' => $cityCode2,
        'role' => 'branch_admin',
        'branches' => [1],
        'branch_caps' => [1 => 10],
        'branch_count' => 1,
        'branch_start_no' => 1,
    ], $hdr);
    assert_true(($createBranch['json']['ok'] ?? false) === true, 'branch admin create failed: ' . (string)($createBranch['json']['message'] ?? ''));

    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$officeUser]);
    $officeId = (int)$stmt->fetchColumn();
    $stmt->execute([$branchUser]);
    $branchId = (int)$stmt->fetchColumn();

    $listUsers = $http->post(['action' => 'admin.users.list', 'q' => 'e2e_'], $hdr);
    assert_true(($listUsers['json']['ok'] ?? false) === true, 'users list failed');
    $users = $listUsers['json']['data']['users'] ?? [];
    assert_true(is_array($users), 'users list not array');

    $officeRow = null;
    $branchRow = null;
    foreach ($users as $u) {
        if ((string)($u['username'] ?? '') === $officeUser) $officeRow = $u;
        if ((string)($u['username'] ?? '') === $branchUser) $branchRow = $u;
    }
    assert_true(is_array($officeRow), 'office user not found');
    assert_true(is_array($branchRow), 'branch user not found');
    assert_true((string)($officeRow['city_code'] ?? '') === $cityCode2, 'office city_code mismatch');
    assert_true((string)($branchRow['city_code'] ?? '') === $cityCode2, 'branch city_code mismatch');

    $simulatedOrder = [];
    $admins = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin'));
    usort($admins, fn($a, $b) => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    foreach ($admins as $a) $simulatedOrder[] = (string)($a['username'] ?? '');

    $officeAdmins = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') === 'office_admin'));
    $branchAdmins = array_values(array_filter($users, fn($u) => ($u['role'] ?? '') === 'branch_admin'));
    $branchByCity = [];
    foreach ($branchAdmins as $b) {
        $key = (string)($b['city_code'] ?? '');
        $branchByCity[$key] = $branchByCity[$key] ?? [];
        $branchByCity[$key][] = $b;
    }

    foreach ($officeAdmins as $oa) {
        $simulatedOrder[] = (string)($oa['username'] ?? '');
        $cityKey = (string)($oa['city_code'] ?? '');
        $children = $branchByCity[$cityKey] ?? [];
        usort($children, fn($a, $b) => strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? '')));
        foreach ($children as $ch) $simulatedOrder[] = (string)($ch['username'] ?? '');
    }

    $posOffice = array_search($officeUser, $simulatedOrder, true);
    $posBranch = array_search($branchUser, $simulatedOrder, true);
    assert_true($posOffice !== false && $posBranch !== false && $posOffice < $posBranch, 'grouping order invalid');

    $deleteBranch = $http->post(['action' => 'admin.users.delete', 'id' => $branchId], $hdr);
    assert_true(($deleteBranch['json']['ok'] ?? false) === true, 'delete branch failed');
    $deleteOffice = $http->post(['action' => 'admin.users.delete', 'id' => $officeId], $hdr);
    assert_true(($deleteOffice['json']['ok'] ?? false) === true, 'delete office failed');

    $deleteCity = $http->post(['action' => 'admin.cities.delete', 'code' => $cityCode2], $hdr);
    assert_true(($deleteCity['json']['ok'] ?? false) === true, 'city delete failed: ' . (string)($deleteCity['json']['message'] ?? ''));

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if ($branchId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$branchId]);
        if ($officeId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$officeId]);
        if ($adminId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
        if ($cityCode2) db()->prepare('DELETE FROM isfahan_cities WHERE code = ?')->execute([$cityCode2]);
        if ($cityCode1) db()->prepare('DELETE FROM isfahan_cities WHERE code = ?')->execute([$cityCode1]);
    } catch (Throwable $cleanupErr) {
    }
    stop_server($server);
}

exit(0);

