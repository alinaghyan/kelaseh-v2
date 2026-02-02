<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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

function find_free_port(int $start = 18190, int $end = 18290): int
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
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

$server = null;
$adminId = null;

try {
    $port = find_free_port();
    $server = start_builtin_server(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), $port);
    $http = new HttpClient($server['base_url']);

    $username = 'test_admin_' . random_int(10000, 99999);
    $password = 'AdminPass_' . random_int(10000, 99999);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, 'تست', 'مدیرکل', '0912' , 'admin', NULL, 1, 1, 1, NOW())")
        ->execute([$username, $hash]);
    $adminId = (int)db()->lastInsertId();

    $sess = $http->postForm('core.php', ['action' => 'session']);
    $csrf = (string)($sess['json']['data']['csrf_token'] ?? '');
    assert_true($csrf !== '', 'csrf missing');

    $login = $http->postForm('core.php', ['action' => 'login', 'login' => $username, 'password' => $password]);
    assert_true(($login['json']['ok'] ?? false) === true, 'admin login failed');
    $csrf = (string)($login['json']['data']['csrf_token'] ?? $csrf);

    $run = $http->postForm('core.php', ['action' => 'admin.test.branch_admin_flow.run'], ['X-CSRF-Token: ' . $csrf]);
    assert_true(($run['json']['ok'] ?? false) === true, 'run test failed: ' . (string)($run['json']['message'] ?? ''));
    $dlUrl = (string)($run['json']['data']['download_url'] ?? '');
    assert_true($dlUrl !== '', 'download_url missing');

    $csv = $http->get($dlUrl);
    assert_true($csv['status'] === 200, 'download http ' . $csv['status']);
    assert_true(str_contains($csv['body'], 'کلاسه'), 'download body not csv');

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if ($adminId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
    } catch (Throwable $e) {
    }
    if ($server) stop_builtin_server($server);
}

exit(0);

