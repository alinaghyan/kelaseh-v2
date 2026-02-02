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

function find_free_port(int $start = 18520, int $end = 18620): int
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

$server = null;
$adminId = null;
$oldOtp = null;
$oldApi = null;

try {
    putenv('KELASEH_ENABLE_TESTS=1');

    $oldOtp = db()->query("SELECT v FROM app_settings WHERE k='sms.otp.enabled' LIMIT 1")->fetchColumn();
    $oldApi = db()->query("SELECT v FROM app_settings WHERE k='sms.api_key' LIMIT 1")->fetchColumn();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.otp.enabled','1',NOW()) ON DUPLICATE KEY UPDATE v='1', updated_at=NOW()")->execute();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.otp.len','6',NOW()) ON DUPLICATE KEY UPDATE v='6', updated_at=NOW()")->execute();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.otp.ttl','5',NOW()) ON DUPLICATE KEY UPDATE v='5', updated_at=NOW()")->execute();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.otp.max_tries','5',NOW()) ON DUPLICATE KEY UPDATE v='5', updated_at=NOW()")->execute();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.otp.tpl','کد تایید ورود {app_name}: {otp}',NOW()) ON DUPLICATE KEY UPDATE v='کد تایید ورود {app_name}: {otp}', updated_at=NOW()")->execute();
    db()->prepare("INSERT INTO app_settings (k,v,updated_at) VALUES ('sms.api_key','TEST',NOW()) ON DUPLICATE KEY UPDATE v='TEST', updated_at=NOW()")->execute();

    $port = find_free_port();
    $server = start_server(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), $port);
    $http = new HttpClient($server['base_url']);

    $username = 'otp_admin_' . random_int(10000, 99999);
    $password = 'OtpPass_' . random_int(10000, 99999);
    $mobile = '0912' . random_int(1000000, 9999999);
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
        VALUES (?, ?, 'تست', 'مدیرکل', ?, 'admin', NULL, 1, 1, 1, NOW())")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $mobile]);
    $adminId = (int)db()->lastInsertId();

    $sess = $http->post(['action' => 'session']);
    $csrf = (string)($sess['json']['data']['csrf_token'] ?? '');
    assert_true($csrf !== '', 'csrf missing');

    $login = $http->post(['action' => 'login', 'login' => $username, 'password' => $password]);
    assert_true(($login['json']['ok'] ?? false) === true, 'login failed');
    assert_true((int)($login['json']['data']['otp_required'] ?? 0) === 1, 'otp_required missing');
    $csrf = (string)($login['json']['data']['csrf_token'] ?? $csrf);

    $stmt = db()->prepare("SELECT message FROM sms_logs WHERE recipient_mobile = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$mobile]);
    $msg = (string)($stmt->fetchColumn() ?: '');
    assert_true($msg !== '', 'otp sms log missing');
    preg_match('/(\d{6})/', $msg, $m);
    $otp = $m[1] ?? '';
    assert_true($otp !== '', 'otp code not found in message');

    $verify = $http->post(['action' => 'login.otp.verify', 'otp' => $otp], ['X-CSRF-Token: ' . $csrf]);
    assert_true(($verify['json']['ok'] ?? false) === true, 'otp verify failed: ' . (string)($verify['json']['message'] ?? ''));
    $user = $verify['json']['data']['user'] ?? null;
    assert_true(is_array($user) && ($user['role'] ?? '') === 'admin', 'user not logged in');

    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        if ($adminId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$adminId]);
        db()->prepare("DELETE FROM sms_logs WHERE recipient_mobile LIKE '0912%'")->execute();

        if ($oldOtp === false || $oldOtp === null) {
            db()->prepare("DELETE FROM app_settings WHERE k='sms.otp.enabled'")->execute();
        } else {
            db()->prepare("UPDATE app_settings SET v=?, updated_at=NOW() WHERE k='sms.otp.enabled'")->execute([(string)$oldOtp]);
        }
        if ($oldApi === false || $oldApi === null) {
            db()->prepare("DELETE FROM app_settings WHERE k='sms.api_key'")->execute();
        } else {
            db()->prepare("UPDATE app_settings SET v=?, updated_at=NOW() WHERE k='sms.api_key'")->execute([(string)$oldApi]);
        }
    } catch (Throwable $cleanupErr) {
    }
    putenv('KELASEH_ENABLE_TESTS');
    stop_server($server);
}

exit(0);
