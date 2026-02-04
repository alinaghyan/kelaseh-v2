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

function find_free_port(int $start = 18410, int $end = 18510): int
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
    public function get(string $url): array
    {
        $ch = curl_init($this->baseUrl . ltrim($url, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
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
        return ['status' => $code, 'body' => (string)$body];
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

function pick_city_code(): string
{
    for ($i = 0; $i < 200; $i++) {
        $cand = sprintf('%02d', random_int(10, 99));
        $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
        $stmt->execute([$cand]);
        if ($stmt->fetchColumn()) continue;
        $stmt2 = db()->prepare('SELECT id FROM users WHERE city_code = ? OR LPAD(city_code, 4, "0") = ? LIMIT 1');
        $stmt2->execute([$cand, $cand]);
        if ($stmt2->fetchColumn()) continue;
        return $cand;
    }
    throw new RuntimeException('No free city code');
}

$server = null;
$cityCode = null;
$adminId = null;
$branchId = null;
$code1 = null;
$code2 = null;

try {
    $port = find_free_port();
    $server = start_server(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), $port);
    $http = new HttpClient($server['base_url']);

    $adminUser = 'lbl_admin_' . random_int(10000, 99999);
    $adminPass = 'Lbl#' . bin2hex(random_bytes(4));
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, 'تست', 'مدیرکل', '0912', 'admin', NULL, 1, 1, 1, NOW())")
        ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);
    $adminId = (int)db()->lastInsertId();

    $sess = $http->post(['action' => 'session']);
    $csrf = (string)($sess['json']['data']['csrf_token'] ?? '');
    assert_true($csrf !== '', 'csrf missing');

    $login = $http->post(['action' => 'login', 'login' => $adminUser, 'password' => $adminPass]);
    assert_true(($login['json']['ok'] ?? false) === true, 'admin login failed');
    $csrf = (string)($login['json']['data']['csrf_token'] ?? $csrf);
    $hdr = ['X-CSRF-Token: ' . $csrf];

    $cityCode = pick_city_code();
    $createCity = $http->post(['action' => 'admin.cities.create', 'code' => $cityCode, 'name' => 'اداره تست لیبل'], $hdr);
    assert_true(($createCity['json']['ok'] ?? false) === true, 'create city failed');

    $branchUser = 'lbl_branch_' . random_int(10000, 99999);
    $branchPass = 'Br#' . bin2hex(random_bytes(4));
    $createBranch = $http->post([
        'action' => 'admin.users.create',
        'first_name' => 'مدیر',
        'last_name' => 'شعبه',
        'username' => $branchUser,
        'mobile' => '0912' . random_int(1000000, 9999999),
        'password' => $branchPass,
        'city_code' => $cityCode,
        'role' => 'branch_admin',
        'branches' => [1],
        'branch_caps' => [1 => 10],
        'branch_count' => 1,
        'branch_start_no' => 1,
    ], $hdr);
    assert_true(($createBranch['json']['ok'] ?? false) === true, 'create branch admin failed');
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$branchUser]);
    $branchId = (int)$stmt->fetchColumn();
    assert_true($branchId > 0, 'branch user id missing');

    $login2 = $http->post(['action' => 'login', 'login' => $branchUser, 'password' => $branchPass]);
    assert_true(($login2['json']['ok'] ?? false) === true, 'branch login failed');
    $csrf2 = (string)($login2['json']['data']['csrf_token'] ?? '');
    $hdr2 = ['X-CSRF-Token: ' . $csrf2];

    $dnc = '0012345678';
    $create1 = $http->post([
        'action' => 'kelaseh.create',
        'plaintiff_name' => 'خواهان ۱',
        'plaintiff_national_code' => '0912345678',
        'plaintiff_mobile' => '0912' . random_int(1000000, 9999999),
        'defendant_name' => 'خوانده مشترک',
        'defendant_national_code' => $dnc,
        'defendant_mobile' => '0912' . random_int(1000000, 9999999),
    ], $hdr2);
    assert_true(($create1['json']['ok'] ?? false) === true, 'create #1 failed: ' . (string)($create1['json']['message'] ?? ''));
    $code1 = (string)($create1['json']['data']['code'] ?? '');
    assert_true($code1 !== '', 'code1 missing');

    $create2 = $http->post([
        'action' => 'kelaseh.create',
        'plaintiff_name' => 'خواهان ۲',
        'plaintiff_national_code' => '0812345678',
        'plaintiff_mobile' => '0912' . random_int(1000000, 9999999),
        'defendant_name' => 'خوانده مشترک',
        'defendant_national_code' => $dnc,
        'defendant_mobile' => '0912' . random_int(1000000, 9999999),
    ], $hdr2);
    assert_true(($create2['json']['ok'] ?? false) === true, 'create #2 failed: ' . (string)($create2['json']['message'] ?? ''));
    $code2 = (string)($create2['json']['data']['code'] ?? '');
    assert_true($code2 !== '', 'code2 missing');

    $html = $http->get('core.php?action=kelaseh.label&code=' . urlencode($code2));
    assert_true($html['status'] === 200, 'label http ' . $html['status']);
    assert_true(str_contains($html['body'], 'history_defendant'), 'history_defendant missing in html');
    assert_true(str_contains($html['body'], $code1), 'previous code not present');
    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if ($code2) db()->prepare('DELETE FROM kelaseh_numbers WHERE code = ?')->execute([$code2]);
        if ($code1) db()->prepare('DELETE FROM kelaseh_numbers WHERE code = ?')->execute([$code1]);
        if ($branchId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$branchId]);
        if ($adminId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
        if ($cityCode) db()->prepare('DELETE FROM isfahan_cities WHERE code = ?')->execute([$cityCode]);
    } catch (Throwable $cleanupErr) {
    }
    stop_server($server);
}

exit(0);
