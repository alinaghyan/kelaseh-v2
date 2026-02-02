<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Morilog\Jalali\Jalalian;

function cfg(): array
{
    $configPath = __DIR__ . '/../config.php';
    return is_file($configPath) ? (require $configPath) : [];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = cfg();
    $db = $cfg['db'] ?? [];
    $host = $db['host'] ?? 'localhost';
    $port = $db['port'] ?? 3306;
    $name = $db['name'] ?? 'kelaseh_db';
    $user = $db['user'] ?? 'root';
    $pass = $db['pass'] ?? '';
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function normalize_city_code(?string $code): ?string
{
    if ($code === null) return null;
    $code = trim($code);
    if ($code === '') return null;
    if (!preg_match('/^[0-9]{1,10}$/', $code)) return null;
    if (strlen($code) <= 4) return str_pad($code, 4, '0', STR_PAD_LEFT);
    return $code;
}

function ensure_support_tables(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS user_branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        branch_no INT NOT NULL,
        UNIQUE KEY unique_user_branch (user_id, branch_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS office_branch_capacities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        city_code VARCHAR(10) NOT NULL,
        branch_no INT NOT NULL,
        capacity INT NOT NULL DEFAULT 15,
        UNIQUE KEY unique_city_branch (city_code, branch_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function find_free_port(int $start = 18080, int $end = 18180): int
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

function start_builtin_server(string $docRoot, int $port): array
{
    $cmd = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docRoot);
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes, $docRoot);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start PHP built-in server');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $baseUrl = 'http://127.0.0.1:' . $port . '/';

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        try {
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
        } catch (Throwable $e) {
        }
        usleep(100_000);
    }

    $err = stream_get_contents($pipes[2]);
    proc_terminate($proc);
    throw new RuntimeException('PHP server did not become ready: ' . $err);
}

function stop_builtin_server(array $server): void
{
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
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'kelaseh_cookie_') ?: (sys_get_temp_dir() . '/kelaseh_cookie.txt');
    }

    public function postForm(string $path, array $data, array $headers = []): array
    {
        $url = $this->baseUrl . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $err);
        }
        curl_close($ch);
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Non-JSON response (HTTP ' . $code . '): ' . substr((string)$body, 0, 300));
        }
        return ['status' => $code, 'json' => $json];
    }

    public function get(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl . ltrim($path, '/');
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $err);
        }
        curl_close($ch);
        return ['status' => $code, 'body' => (string)$body, 'url' => $url];
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }
}

function random_national_code(): string
{
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= (string)random_int(0, 9);
    return $s;
}

function random_mobile(): string
{
    $s = '09';
    for ($i = 0; $i < 9; $i++) $s .= (string)random_int(0, 9);
    return $s;
}

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

$server = null;
$created = [
    'city_code' => null,
    'user_id' => null,
    'username' => null,
    'branches' => [1, 2, 3],
    'codes' => [],
];

try {
    ensure_support_tables();
    $cityCode = normalize_city_code((string)random_int(9000, 9999));
    assert_true($cityCode !== null, 'Failed to generate city code');
    $created['city_code'] = $cityCode;

    db()->prepare('INSERT INTO isfahan_cities (code, name) VALUES (?, ?)')->execute([$cityCode, 'شهر تست']);

    $username = 'test_branch_' . random_int(10000, 99999);
    $passwordPlain = 'TestPass_' . random_int(10000, 99999);
    $created['username'] = $username;

    $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, ?, ?, ?, 'branch_admin', ?, 1, 1, 1, ?)")
        ->execute([$username, $hash, 'تست', 'مدیر شعبه', random_mobile(), $cityCode, date('Y-m-d H:i:s')]);

    $userId = (int)db()->lastInsertId();
    $created['user_id'] = $userId;

    db()->prepare('INSERT INTO user_branches (user_id, branch_no) VALUES (?, ?), (?, ?), (?, ?)')
        ->execute([$userId, 1, $userId, 2, $userId, 3]);

    foreach ([1, 2, 3] as $b) {
        db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)')
            ->execute([$cityCode, $b, 10]);
    }

    $port = find_free_port();
    $server = start_builtin_server(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), $port);
    $baseUrl = $server['base_url'];
    $http = new HttpClient($baseUrl);

    $sess = $http->postForm('core.php', ['action' => 'session']);
    assert_true(($sess['json']['ok'] ?? false) === true, 'session failed');
    $csrf = (string)(($sess['json']['data']['csrf_token'] ?? '') ?: '');
    assert_true($csrf !== '', 'csrf missing');

    $login = $http->postForm('core.php', ['action' => 'login', 'login' => $username, 'password' => $passwordPlain]);
    assert_true(($login['json']['ok'] ?? false) === true, 'login failed');
    $csrf = (string)(($login['json']['data']['csrf_token'] ?? '') ?: $csrf);
    assert_true($csrf !== '', 'csrf missing after login');

    $branchCounts = [1 => 0, 2 => 0, 3 => 0];
    for ($i = 1; $i <= 30; $i++) {
        $payload = [
            'action' => 'kelaseh.create',
            'plaintiff_name' => 'خواهان تست ' . $i,
            'plaintiff_national_code' => random_national_code(),
            'plaintiff_mobile' => random_mobile(),
            'defendant_name' => '',
            'defendant_national_code' => '',
            'defendant_mobile' => '',
        ];
        $res = $http->postForm('core.php', $payload, ['X-CSRF-Token: ' . $csrf]);
        assert_true(($res['json']['ok'] ?? false) === true, 'kelaseh.create failed at #' . $i . ': ' . (($res['json']['message'] ?? '') ?: ''));
        $code = (string)(($res['json']['data']['code'] ?? '') ?: '');
        assert_true($code !== '', 'missing code at #' . $i);
        $created['codes'][] = $code;

        $bn = (int)($res['json']['data']['branch_no'] ?? 0);
        assert_true(in_array($bn, [1, 2, 3], true), 'unexpected branch_no ' . $bn . ' at #' . $i);
        $branchCounts[$bn]++;
    }
    assert_true($branchCounts[1] === 10 && $branchCounts[2] === 10 && $branchCounts[3] === 10, 'branch distribution mismatch: ' . json_encode($branchCounts, JSON_UNESCAPED_UNICODE));

    $csv = $http->get('core.php', ['action' => 'kelaseh.export.csv', 'csrf_token' => $csrf, 'q' => '']);
    assert_true($csv['status'] === 200, 'export.csv HTTP ' . $csv['status']);
    assert_true(str_contains($csv['body'], "code,city_code,branch_no"), 'export.csv missing header');
    foreach (array_slice($created['codes'], 0, 5) as $sample) {
        assert_true(str_contains($csv['body'], $sample), 'export.csv missing code sample ' . $sample);
    }

    $outDir = __DIR__ . '/output';
    if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
    $outFile = $outDir . '/branch_admin_export.csv';
    file_put_contents($outFile, $csv['body']);

    echo "OK\n";
    echo "base_url: $baseUrl\n";
    echo "username: $username\n";
    echo "export: $outFile\n";

    $created['ok'] = true;
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    $created['ok'] = false;
} finally {
    try {
        if (!empty($created['codes']) && $created['user_id']) {
            $in = implode(',', array_fill(0, count($created['codes']), '?'));
            $params = $created['codes'];
            array_unshift($params, $created['user_id']);
            db()->prepare("DELETE FROM kelaseh_numbers WHERE owner_id = ? AND code IN ($in)")->execute($params);
        }

        if ($created['city_code']) {
            $j = Jalalian::now();
            $jalaliYmd = sprintf('%02d%02d%02d', ((int)$j->format('Y')) % 100, (int)$j->format('m'), (int)$j->format('d'));
            foreach (['kelaseh_daily_counters', 'kelaseh_daily_counters_v2'] as $t) {
                try {
                    db()->prepare("DELETE FROM {$t} WHERE city_code = ? AND jalali_ymd = ? AND branch_no IN (1,2,3)")->execute([$created['city_code'], $jalaliYmd]);
                } catch (Throwable $e) {
                }
            }
        }

        if ($created['user_id']) {
            db()->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$created['user_id']]);
        }
        if ($created['city_code']) {
            db()->prepare('DELETE FROM office_branch_capacities WHERE city_code = ? AND branch_no IN (1,2,3)')->execute([$created['city_code']]);
        }
        if ($created['user_id']) {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$created['user_id']]);
        }
        if ($created['city_code']) {
            db()->prepare('DELETE FROM isfahan_cities WHERE code = ?')->execute([$created['city_code']]);
        }
    } catch (Throwable $cleanupErr) {
        fwrite(STDERR, "CLEANUP FAIL: " . $cleanupErr->getMessage() . "\n");
    }

    if ($server) stop_builtin_server($server);
}

exit(($created['ok'] ?? false) ? 0 : 1);

