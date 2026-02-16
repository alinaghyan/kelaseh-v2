<?php
/**
 * هسته اصلی پردازش‌های برنامه (Backend)
 * شامل توابع کمکی، اتصال به دیتابیس، مدیریت نشست‌ها (Session) و پردازش درخواست‌های AJAX
 */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($isHttps) ini_set('session.cookie_secure', '1');
session_start();
date_default_timezone_set('Asia/Tehran');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/vendor/autoload.php';
use Morilog\Jalali\Jalalian;

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = app_config();
        $db = $cfg['db'] ?? null;
        if (!is_array($db)) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'تنظیمات دیتابیس در config.php یافت نشد.']);
            exit;
        }

        $host = (string)($db['host'] ?? '');
        $name = (string)($db['name'] ?? '');
        $user = (string)($db['user'] ?? '');
        $pass = (string)($db['pass'] ?? '');
        $port = (int)($db['port'] ?? 3306);
        if ($host === '' || $name === '' || $user === '') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'تنظیمات دیتابیس در config.php ناقص است.']);
            exit;
        }

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => is_debug() ? ('خطای اتصال به دیتابیس: ' . $e->getMessage()) : 'خطای اتصال به دیتابیس']);
            exit;
        }
    }
    return $pdo;
}

function app_config(): array
{
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    return is_file($configPath) ? require $configPath : [];
}

function is_debug(): bool
{
    $cfg = app_config();
    return (bool)(($cfg['app']['debug'] ?? false));
}

function tests_enabled(): bool
{
    $cfg = app_config();
    $env = getenv('KELASEH_ENABLE_TESTS');
    return is_debug() || (bool)(($cfg['app']['enable_tests'] ?? false)) || $env === '1';
}

function admin_test_require_enabled(): void
{
    if (!tests_enabled()) {
        json_response(false, ['message' => 'تست در این محیط غیرفعال است. برای فعال‌سازی `app.enable_tests=true` یا `app.debug=true` را در config.php تنظیم کنید.'], 403);
    }
}

function json_response(bool $ok, array $data = [], int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $data));
    exit;
}

function request_data(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }
    return $_POST;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_require_valid(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$token || $token !== csrf_token()) {
        json_response(false, ['message' => 'نشست نامعتبر است (CSRF). صفحه را رفرش کنید.'], 403);
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.mobile, u.role, u.is_active, u.city_code, u.branch_count, u.branch_start_no, c.name as city_name, CONCAT(u.first_name, " ", u.last_name) as display_name FROM users u LEFT JOIN isfahan_cities c ON c.code = u.city_code WHERE u.id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if ($u) {
        $u['branches'] = user_branches_get((int)$u['id']);
    }
    return $u ?: null;
}

function user_branches_get(int $userId): array
{
    ensure_user_branches_table();
    $stmt = db()->prepare('SELECT branch_no FROM user_branches WHERE user_id = ? ORDER BY branch_no ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function user_branches_set(int $userId, array $branches): void
{
    ensure_user_branches_table();
    db()->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$userId]);
    if (empty($branches)) return;
    $sql = 'INSERT INTO user_branches (user_id, branch_no) VALUES ';
    $vals = [];
    $params = [];
    foreach ($branches as $b) {
        $vals[] = '(?, ?)';
        $params[] = $userId;
        $params[] = $b;
    }
    db()->prepare($sql . implode(', ', $vals))->execute($params);
}

function ensure_kelaseh_sessions_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS kelaseh_sessions (
        id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        kelaseh_id INT(11) UNSIGNED NOT NULL,
        session_key VARCHAR(20) NOT NULL COMMENT 'session1...session5, resolution',
        meeting_date VARCHAR(20) NULL DEFAULT NULL,
        plaintiff_request TEXT NULL,
        verdict_text TEXT NULL,
        reps_govt TEXT NULL,
        reps_worker TEXT NULL,
        reps_employer TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_kelaseh_session (kelaseh_id, session_key),
        KEY fk_kelaseh_id (kelaseh_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_user_branches_table(): void
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

function auth_require_login(): array
{
    $user = current_user();
    if (!$user) json_response(false, ['message' => 'لطفاً وارد شوید.'], 401);
    if ((int)$user['is_active'] !== 1) {
        action_logout();
        json_response(false, ['message' => 'حساب شما غیرفعال شده است.'], 403);
    }
    return $user;
}

function auth_require_admin(array $user): void
{
    if ($user['role'] !== 'admin') json_response(false, ['message' => 'دسترسی غیرمجاز (فقط مدیر کل).'], 403);
}

function auth_require_office_admin(array $user): void
{
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز (فقط مدیر اداره).'], 403);
}

function to_persian_digits($str): string
{
    if ($str === null) return '';
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $per = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($eng, $per, (string)$str);
}

function now_mysql(): string
{
    return date('Y-m-d H:i:s');
}

function format_jalali_datetime(?string $gDate): string
{
    if (!$gDate) return '';
    try {
        return Jalalian::fromCarbon(\Carbon\Carbon::parse($gDate))->format('Y/m/d H:i:s');
    } catch (Throwable $e) {
        return $gDate;
    }
}

function parse_jalali_full_ymd(?string $jDate): ?string
{
    if (!$jDate) return null;
    return parse_jalali_to_gregorian($jDate);
}

function parse_jalali_to_gregorian(string $jDate): ?string
{
    $jDate = to_english_digits($jDate);
    $parts = preg_split('/[\/\-]/', $jDate);
    if (count($parts) !== 3) return null;
    $y = (int)$parts[0]; $m = (int)$parts[1]; $d = (int)$parts[2];
    try {
        return (new Jalalian($y, $m, $d))->toCarbon()->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function to_english_digits($str): string
{
    if ($str === null) return '';
    $per = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($per, $eng, (string)$str);
}

function normalize_city_code(?string $code): ?string
{
    if ($code === null) return null;
    $code = trim(to_english_digits($code));
    if ($code === '') return null;
    if (!preg_match('/^[0-9]{1,10}$/', $code)) return null;
    // Removed automatic padding to allow variable length city codes (e.g. "01", "1", "001")
    return $code;
}

function resolve_city_code_fk(?string $input): ?string
{
    if ($input === null) return null;
    $raw = trim(to_english_digits($input));
    if ($raw === '') return null;

    static $cache = [];
    if (array_key_exists($raw, $cache)) return $cache[$raw];

    $candidates = [];
    $candidates[] = $raw;
    $norm = normalize_city_code($raw);
    if ($norm) $candidates[] = $norm;
    if (preg_match('/^[0-9]+$/', $raw)) {
        $candidates[] = ltrim($raw, '0') === '' ? '0' : ltrim($raw, '0');
    }

    $seen = [];
    foreach ($candidates as $cand) {
        if ($cand === '' || isset($seen[$cand])) continue;
        $seen[$cand] = true;
        $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
        $stmt->execute([$cand]);
        $row = $stmt->fetchColumn();
        if ($row !== false) {
            $cache[$raw] = (string)$row;
            return $cache[$raw];
        }
    }

    $cache[$raw] = null;
    return null;
}

function validate_ir_mobile(?string $mobile): ?string
{
    if (!$mobile) return null;
    $mobile = to_english_digits($mobile);
    if (preg_match('/^09[0-9]{9}$/', $mobile)) return $mobile;
    return null;
}

function validate_national_code(?string $code): ?string
{
    if (!$code) return null;
    $code = to_english_digits($code);
    if (!preg_match('/^[0-9]{10}$/', $code)) return null;
    return $code; // Simplified validation
}

function ensure_kelaseh_daily_counters_table_v2(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS kelaseh_daily_counters_v2 (
        city_code VARCHAR(10) NOT NULL,
        jalali_ymd CHAR(6) NOT NULL,
        branch_no INT NOT NULL,
        seq_no INT NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (city_code, jalali_ymd, branch_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function kelaseh_daily_counters_table(): string
{
    static $name = null;
    if (is_string($name)) return $name;
    $name = 'kelaseh_daily_counters_v2';
    ensure_kelaseh_daily_counters_table_v2();
    return $name;
}

function jalali_today_parts(): array
{
    try {
        $j = Jalalian::now();
        $jy = (int)$j->format('Y');
        $jm = (int)$j->format('m');
        $jd = (int)$j->format('d');
        $ymd = sprintf('%02d%02d%02d', $jy % 100, $jm, $jd);
        $full = sprintf('%04d%02d%02d', $jy, $jm, $jd);
        return ['jalali_ymd' => $ymd, 'jalali_full_ymd' => $full, 'jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    } catch (Throwable $e) {
        $gy = (int)date('Y');
        $gm = (int)date('m');
        $gd = (int)date('d');
        $ymd = sprintf('%02d%02d%02d', $gy % 100, $gm, $gd);
        $full = sprintf('%04d%02d%02d', $gy, $gm, $gd);
        return ['jalali_ymd' => $ymd, 'jalali_full_ymd' => $full, 'jy' => $gy, 'jm' => $gm, 'jd' => $gd];
    }
}

function validate_username(string $u): ?string
{
    $u = trim($u);
    if (preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $u)) return $u;
    if (filter_var($u, FILTER_VALIDATE_EMAIL)) return $u;
    return null;
}

function validate_branch_count($cnt): int
{
    $c = (int)$cnt;
    if ($c < 1) $c = 1;
    if ($c > 15) $c = 15;
    return $c;
}

function validate_branch_start_no($no): ?int
{
    if ($no === null || $no === '') return null;
    $n = (int)$no;
    if ($n < 1) return 1;
    if ($n > 99) return 99;
    return $n;
}

function audit_log(int $actorId, string $action, string $entity, ?int $entityId, ?int $targetUserId): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $sql = "INSERT INTO audit_logs (actor_id, action, entity, entity_id, target_user_id, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    db()->prepare($sql)->execute([$actorId, $action, $entity, $entityId, $targetUserId, $ip, $ua]);
}

function ensure_sms_logs_supports_otp(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("ALTER TABLE sms_logs MODIFY `type` ENUM('plaintiff','defendant','otp') NOT NULL");
    } catch (Throwable $e) {
    }
}

function ensure_kelaseh_numbers_code_supports_city_prefix(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('ALTER TABLE kelaseh_numbers MODIFY `code` VARCHAR(30) NOT NULL');
    } catch (Throwable $e) {
    }
}

function ensure_kelaseh_numbers_supports_manual_flag(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `is_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    } catch (Throwable $e) {}
    try {
        db()->exec('ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `is_manual_branch` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_manual`');
    } catch (Throwable $e) {}
}

function ensure_kelaseh_numbers_supports_new_case_code(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `new_case_code` VARCHAR(30) NULL DEFAULT NULL AFTER `code`');
    } catch (Throwable $e) {}
}

function ensure_kelaseh_numbers_supports_extended_fields(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'notice_number' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `notice_number` VARCHAR(50) NULL DEFAULT NULL AFTER `is_manual`",
        'plaintiff_address' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `plaintiff_address` TEXT NULL AFTER `plaintiff_mobile`",
        'plaintiff_postal_code' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `plaintiff_postal_code` VARCHAR(20) NULL DEFAULT NULL AFTER `plaintiff_address`",
        'defendant_address' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `defendant_address` TEXT NULL AFTER `defendant_mobile`",
        'defendant_postal_code' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `defendant_postal_code` VARCHAR(20) NULL DEFAULT NULL AFTER `defendant_address`",
        'dadnameh' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `dadnameh` VARCHAR(80) NULL DEFAULT NULL AFTER `defendant_postal_code`",
        'representatives_govt' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `representatives_govt` TEXT NULL AFTER `dadnameh`",
        'representatives_worker' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `representatives_worker` TEXT NULL AFTER `representatives_govt`",
        'representatives_employer' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `representatives_employer` TEXT NULL AFTER `representatives_worker`",
        'plaintiff_request' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `plaintiff_request` TEXT NULL AFTER `representatives_employer`",
        'verdict_text' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `verdict_text` TEXT NULL AFTER `plaintiff_request`",
        'print_type' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `print_type` VARCHAR(20) NULL DEFAULT NULL AFTER `status`",
        'last_printed_at' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `last_printed_at` DATETIME NULL DEFAULT NULL AFTER `print_type`",
        'last_notice_printed_at' => "ALTER TABLE kelaseh_numbers ADD COLUMN IF NOT EXISTS `last_notice_printed_at` DATETIME NULL DEFAULT NULL AFTER `last_printed_at`",
    ];

    foreach ($cols as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
        }
    }
}

function ensure_city_code_supports_variable_length(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('SET FOREIGN_KEY_CHECKS=0');
    } catch (Throwable $e) {
    }

    try {
        db()->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_city');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE users DROP FOREIGN KEY users_ibfk_1');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE office_branch_capacities DROP FOREIGN KEY fk_office_branch_capacities_city');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE office_branch_capacities DROP FOREIGN KEY office_branch_capacities_ibfk_1');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE isfahan_cities MODIFY `code` VARCHAR(10) NOT NULL');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE users MODIFY `city_code` VARCHAR(10) NULL');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE office_branch_capacities MODIFY `city_code` VARCHAR(10) NOT NULL');
    } catch (Throwable $e) {
    }

    try {
        db()->exec('ALTER TABLE users ADD CONSTRAINT fk_users_city FOREIGN KEY (city_code) REFERENCES isfahan_cities(code) ON UPDATE CASCADE');
    } catch (Throwable $e) {
    }
    try {
        db()->exec('ALTER TABLE office_branch_capacities ADD CONSTRAINT fk_office_branch_capacities_city FOREIGN KEY (city_code) REFERENCES isfahan_cities(code) ON UPDATE CASCADE');
    } catch (Throwable $e) {
    }

    try {
        db()->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
    }
}

function is_test_mode_enabled(): bool
{
    $v = getenv('KELASEH_ENABLE_TESTS');
    return $v === '1' || strtolower((string)$v) === 'true';
}

function sms_send_via_kavenegar(string $mobile, string $message): bool
{
    $apiKey = trim((string)(setting_get('sms.api_key', '') ?? ''));
    if ($apiKey === '') return false;
    if (!extension_loaded('curl')) return false;
    if (is_test_mode_enabled()) return true;

    $sender = trim((string)(setting_get('sms.sender', '') ?? ''));
    $url = 'https://api.kavenegar.com/v1/' . rawurlencode($apiKey) . '/sms/send.json';
    $post = [
        'receptor' => $mobile,
        'message' => $message,
    ];
    if ($sender !== '') $post['sender'] = $sender;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $err) return false;
    if ($code < 200 || $code >= 300) return false;
    $json = json_decode((string)$body, true);
    if (!is_array($json)) return false;
    if ((int)($json['return']['status'] ?? 0) !== 200) return false;
    return true;
}

function jalali_now_string(): string
{
    try {
        return Jalalian::now()->format('Y/m/d H:i:s');
    } catch (Throwable $e) {
        return date('Y/m/d H:i:s');
    }
}

// HANDLERS

function finish_login(array $row): void
{
    $_SESSION['user_id'] = (int)$row['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    audit_log((int)$row['id'], 'login', 'user', (int)$row['id'], (int)$row['id']);
    json_response(true, ['message' => 'ورود موفق.', 'data' => ['csrf_token' => csrf_token(), 'user' => current_user()]]);
}

function action_login(array $data): void
{
    $cfg = app_config();
    $throttle = (int)($cfg['security']['login_throttle_seconds'] ?? 0);
    if ($throttle > 0) {
        $lastAttempt = $_SESSION['last_login_attempt'] ?? 0;
        $diff = time() - $lastAttempt;
        if ($diff < $throttle) {
            json_response(false, ['message' => "لطفاً " . ($throttle - $diff) . " ثانیه صبر کنید."], 429);
        }
    }
    $_SESSION['last_login_attempt'] = time();

    $login = trim((string)($data['login'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($login === '' || $password === '') json_response(false, ['message' => 'نام کاربری و رمز عبور الزامی است.'], 422);

    $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL);
    if ($isEmail) {
        $stmt = db()->prepare('SELECT id,password_hash,is_active,role,mobile FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$login]);
    } else {
        $stmt = db()->prepare('SELECT id,password_hash,is_active,role,mobile FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$login]);
    }
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        json_response(false, ['message' => 'نام کاربری یا رمز عبور اشتباه است.'], 401);
    }
    if ((int)$row['is_active'] !== 1) json_response(false, ['message' => 'حساب غیرفعال است.'], 403);

    $otpEnabled = (int)(setting_get('sms.otp.enabled', '0') ?? '0') === 1;
    $role = (string)($row['role'] ?? '');
    $isManager = in_array($role, ['admin', 'office_admin', 'branch_admin'], true);

    if ($otpEnabled && $isManager) {
        $apiKey = trim((string)(setting_get('sms.api_key', '') ?? ''));
        if ($apiKey === '') json_response(false, ['message' => 'ارسال کد تایید فعال است اما کلید API پیامک تنظیم نشده است.'], 422);
        $mobile = validate_ir_mobile($row['mobile'] ?? null);
        if (!$mobile) json_response(false, ['message' => 'ارسال کد تایید فعال است اما شماره موبایل کاربر نامعتبر/خالی است.'], 422);

        $len = (int)(setting_get('sms.otp.len', '6') ?? '6');
        if ($len < 4) $len = 4;
        if ($len > 8) $len = 8;
        $ttlMin = (int)(setting_get('sms.otp.ttl', '5') ?? '5');
        if ($ttlMin < 1) $ttlMin = 1;
        if ($ttlMin > 30) $ttlMin = 30;
        $maxTries = (int)(setting_get('sms.otp.max_tries', '5') ?? '5');
        if ($maxTries < 1) $maxTries = 1;
        if ($maxTries > 10) $maxTries = 10;

        $max = (10 ** $len) - 1;
        $otp = str_pad((string)random_int(0, $max), $len, '0', STR_PAD_LEFT);
        $_SESSION['otp_pending'] = [
            'user_id' => (int)$row['id'],
            'code' => password_hash($otp, PASSWORD_DEFAULT),
            'expires_at' => time() + ($ttlMin * 60),
            'tries' => 0,
            'max_tries' => $maxTries,
        ];

        $cfg = app_config();
        $appName = (string)($cfg['app']['name'] ?? 'کلاسه');
        $tpl = (string)(setting_get('sms.otp.tpl', '') ?? '');
        if ($tpl === '') $tpl = 'کد تایید ورود {app_name}: {otp}';
        $msg = str_replace(['{otp}', '{app_name}'], [$otp, $appName], $tpl);
        ensure_sms_logs_supports_otp();
        $sent = sms_send_via_kavenegar($mobile, $msg);
        db()->prepare('INSERT INTO sms_logs (recipient_mobile, message, type, status, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$mobile, $msg, 'otp', $sent ? 'sent' : 'failed', now_mysql()]);

        $masked = $mobile;
        if (strlen($masked) >= 7) {
            $masked = substr($masked, 0, 4) . '***' . substr($masked, -4);
        }
        json_response(true, ['message' => 'کد تایید ارسال شد.', 'data' => ['csrf_token' => csrf_token(), 'otp_required' => 1, 'otp_expires_in' => $ttlMin * 60, 'mobile_hint' => $masked]]);
    }

    finish_login($row);
}

function action_login_otp_verify(array $data): void
{
    csrf_require_valid();
    $otp = trim(to_english_digits((string)($data['otp'] ?? '')));
    if ($otp === '' || !preg_match('/^[0-9]{4,8}$/', $otp)) json_response(false, ['message' => 'کد تایید نامعتبر است.'], 422);

    $pending = $_SESSION['otp_pending'] ?? null;
    if (!is_array($pending) || empty($pending['user_id']) || empty($pending['code']) || empty($pending['expires_at'])) {
        json_response(false, ['message' => 'درخواست کد تایید معتبر نیست.'], 409);
    }
    if (time() > (int)$pending['expires_at']) {
        unset($_SESSION['otp_pending']);
        json_response(false, ['message' => 'کد تایید منقضی شده است. دوباره وارد شوید.'], 410);
    }
    $tries = (int)($pending['tries'] ?? 0);
    $maxTries = (int)($pending['max_tries'] ?? 5);
    if ($maxTries < 1) $maxTries = 1;
    if ($maxTries > 10) $maxTries = 10;
    if ($tries >= $maxTries) {
        unset($_SESSION['otp_pending']);
        json_response(false, ['message' => 'تعداد تلاش‌ها بیش از حد مجاز است. دوباره وارد شوید.'], 429);
    }

    if (!password_verify($otp, (string)$pending['code'])) {
        $_SESSION['otp_pending']['tries'] = $tries + 1;
        json_response(false, ['message' => 'کد تایید اشتباه است.'], 401);
    }

    $userId = (int)$pending['user_id'];
    unset($_SESSION['otp_pending']);
    
    $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) json_response(false, ['message' => 'کاربر یافت نشد.'], 404);
    
    finish_login($row);
}

function action_logout(): void
{
    $_SESSION = [];
    session_destroy();
    json_response(true, ['message' => 'خروج موفق.']);
}

function action_session(): void
{
    ensure_city_code_supports_variable_length();
    $user = null;
    try { $user = current_user(); } catch (Throwable $e) {}
    json_response(true, ['data' => ['csrf_token' => csrf_token(), 'user' => $user]]);
}

function action_time_now(): void
{
    json_response(true, ['data' => ['now_jalali' => jalali_now_string()]]);
}

function action_kelaseh_search_by_nc(array $data): void {
    $user = auth_require_login();
    // Only for branch_admin or user (or admins)
    
    $nc = trim(to_english_digits((string)($data['nc'] ?? '')));
    if (strlen($nc) < 4) json_response(true, ['data' => []]); // Min 4 chars

    $params = [];
    $sql = "SELECT code, plaintiff_name, defendant_name, plaintiff_national_code, defendant_national_code FROM kelaseh_numbers WHERE (plaintiff_national_code LIKE ? OR defendant_national_code LIKE ?)";
    $like = "%$nc%";
    $params[] = $like;
    $params[] = $like;

    // Filter by user access
    if (in_array($user['role'], ['branch_admin', 'user'], true)) {
        $sql .= " AND owner_id = ?";
        $params[] = $user['id'];
    } elseif ($user['role'] === 'office_admin') {
        $sql .= " AND owner_id IN (SELECT id FROM users WHERE city_code = ? OR LPAD(city_code, 4, '0') = ?)";
        $params[] = $user['city_code'];
        $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
    }
    
    $sql .= " ORDER BY id DESC LIMIT 10";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    json_response(true, ['data' => $rows]);
}

function action_kelaseh_get_by_code(array $data): void {
    $user = auth_require_login();
    $code = trim((string)($data['code'] ?? ''));
    if (!$code) json_response(false, ['message' => 'کلاسه الزامی است'], 422);
    $sql = "SELECT k.*, u.city_code FROM kelaseh_numbers k JOIN users u ON u.id = k.owner_id WHERE (k.code = ? OR k.new_case_code = ?)";
    $params = [$code, $code];

    if (in_array($user['role'], ['branch_admin', 'user'], true)) {
        $cityNorm = normalize_city_code($user['city_code']) ?? $user['city_code'];
        $sql .= " AND (k.owner_id = ? OR u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
        array_push($params, $user['id'], $user['city_code'], $cityNorm);
    } elseif ($user['role'] === 'office_admin') {
        $sql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
        $params[] = $user['city_code'];
        $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    
    if (!$row) {
        json_response(false, ['message' => 'پرونده یافت نشد یا دسترسی مجاز نیست.'], 404);
        return;
    }

    $row['created_at_jalali'] = format_jalali_datetime($row['created_at']);

    ensure_kelaseh_sessions_table();
    // Fetch sessions
    $stmtS = db()->prepare("SELECT * FROM kelaseh_sessions WHERE kelaseh_id = ?");
    $stmtS->execute([$row['id']]);
    $sessions = $stmtS->fetchAll();
    $sessionsMap = [];
    foreach ($sessions as $s) {
        $sessionsMap[$s['session_key']] = $s;
    }
    $row['sessions'] = $sessionsMap;

    json_response(true, ['data' => $row]);
}

function action_heyat_tashkhis_save(array $data): void {
    $user = auth_require_login();
    csrf_require_valid();
    if (!in_array($user['role'], ['branch_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز (فقط مدیر شعبه)'], 403);

    ensure_kelaseh_numbers_supports_extended_fields();
    ensure_kelaseh_numbers_supports_new_case_code();

    $code = trim((string)($data['code'] ?? ''));
    if (!$code || strlen($code) < 5) json_response(false, ['message' => 'کلاسه پرونده معتبر نیست (حداقل ۵ کاراکتر).'], 422);

    // Common fields
    $noticeNum = trim((string)($data['notice_number'] ?? ''));
    $pName = trim((string)($data['plaintiff_name'] ?? ''));
    $dName = trim((string)($data['defendant_name'] ?? ''));
    $pAddress = trim((string)($data['plaintiff_address'] ?? ''));
    $pPostal = trim((string)($data['plaintiff_postal_code'] ?? ''));
    $dAddress = trim((string)($data['defendant_address'] ?? ''));
    $dPostal = trim((string)($data['defendant_postal_code'] ?? ''));
    $pNC = validate_national_code($data['plaintiff_national_code'] ?? null);
    $dNC = validate_national_code($data['defendant_national_code'] ?? null);
    
    // Check if exists by old or new code
    $stmt = db()->prepare("SELECT id, owner_id, code, new_case_code FROM kelaseh_numbers WHERE code = ? OR new_case_code = ? LIMIT 1");
    $stmt->execute([$code, $code]);
    $existing = $stmt->fetch();

    $now = now_mysql();
    $kelasehId = 0;

    if ($existing) {
        if ((int)$existing['owner_id'] !== (int)$user['id']) {
            $stmtCity = db()->prepare("SELECT city_code FROM users WHERE id = ? LIMIT 1");
            $stmtCity->execute([(int)$existing['owner_id']]);
            $ownCity = (string)($stmtCity->fetchColumn() ?: '');
            $ownCityNorm = normalize_city_code($ownCity) ?? $ownCity;
            $userCityNorm = normalize_city_code($user['city_code']) ?? $user['city_code'];
            if (!($ownCity === $user['city_code'] || $ownCityNorm === $userCityNorm)) {
                json_response(false, ['message' => 'این پرونده متعلق به اداره شما نیست.'], 403);
            }
        }
        $kelasehId = $existing['id'];
        // Normalize $code to stored old code if we looked up by new_case_code
        $code = $existing['code'] ?? $code;
        
        $sql = "UPDATE kelaseh_numbers SET 
                notice_number = ?,
                plaintiff_name = ?, defendant_name = ?,
                plaintiff_address = ?, plaintiff_postal_code = ?,
                defendant_address = ?, defendant_postal_code = ?,
                plaintiff_national_code = ?, defendant_national_code = ?,
                updated_at = ?
                WHERE id = ?";
        db()->prepare($sql)->execute([
            $noticeNum, $pName, $dName, $pAddress, $pPostal, $dAddress, $dPostal, $pNC, $dNC,
            $now, $kelasehId
        ]);
    } else {
        // Create new
        $sql = "INSERT INTO kelaseh_numbers (
            owner_id, code, branch_no, status, is_manual, 
            notice_number, plaintiff_name, defendant_name, plaintiff_address, plaintiff_postal_code,
            defendant_address, defendant_postal_code,
            plaintiff_national_code, defendant_national_code,
            created_at, updated_at
        ) VALUES (
            ?, ?, 1, 'active', 1,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?
        )";
        
        db()->prepare($sql)->execute([
            (int)$user['id'], $code, 
            $noticeNum, $pName, $dName, $pAddress, $pPostal,
            $dAddress, $dPostal,
            $pNC, $dNC,
            $now, $now
        ]);
        $kelasehId = db()->lastInsertId();
    }
    
    ensure_kelaseh_sessions_table();
    // Save Sessions
    if (isset($data['sessions']) && is_array($data['sessions'])) {
        $sessStmt = db()->prepare("INSERT INTO kelaseh_sessions 
            (kelaseh_id, session_key, meeting_date, plaintiff_request, verdict_text, reps_govt, reps_worker, reps_employer) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
            meeting_date=VALUES(meeting_date), plaintiff_request=VALUES(plaintiff_request), verdict_text=VALUES(verdict_text), 
            reps_govt=VALUES(reps_govt), reps_worker=VALUES(reps_worker), reps_employer=VALUES(reps_employer), updated_at=NOW()");
            
        foreach ($data['sessions'] as $key => $sData) {
            $sessStmt->execute([
                $kelasehId,
                $key,
                trim((string)($sData['date'] ?? '')),
                trim((string)($sData['plaintiff_request'] ?? '')),
                trim((string)($sData['verdict_text'] ?? '')),
                trim((string)($sData['reps_govt'] ?? '')),
                trim((string)($sData['reps_worker'] ?? '')),
                trim((string)($sData['reps_employer'] ?? ''))
            ]);
        }
    }
    
    json_response(true, ['message' => 'اطلاعات پرونده و جلسات ذخیره شد.']);
}

function action_kelaseh_list(array $data): void {
    $user = auth_require_login();
    $page = max(1, (int)($data['page'] ?? 1));
    $limit = max(1, min(500, (int)($data['limit'] ?? 100)));
    $offset = ($page - 1) * $limit;

    $filters = [
        'national_code' => $data['national_code'] ?? '',
        'q' => $data['q'] ?? '',
        'owner_id' => $data['owner_id'] ?? 0,
        'city_code' => $data['city_code'] ?? null,
        'from' => parse_jalali_full_ymd($data['from'] ?? null),
        'to' => parse_jalali_full_ymd($data['to'] ?? null),
    ];
    
    $result = kelaseh_fetch_rows_paginated($user, $filters, $limit, $offset);
    json_response(true, [
        'data' => [
            'kelaseh' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit
        ]
    ]);
}

function action_kelaseh_list_today(array $data): void {
    $user = auth_require_login();
    // Show last 50 records of the user so they can always see their latest work
    $sql = "SELECT k.*, u.username, u.first_name, u.last_name, u.city_code, c.name as city_name
            FROM kelaseh_numbers k
            JOIN users u ON u.id = k.owner_id
            LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
            WHERE k.owner_id = ?
            ORDER BY k.id DESC LIMIT 50";
    
    $stmt = db()->prepare($sql);
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at'] ?? null);
        $r['full_code'] = (string)($r['code'] ?? '');
        $r['owner_name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? '');
        $r['is_manual'] = (int)($r['is_manual'] ?? 0);
        $r['is_manual_branch'] = (int)($r['is_manual_branch'] ?? 0);
    }
    
    json_response(true, ['data' => ['kelaseh' => $rows]]);
}

class HttpError extends Exception
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function kelaseh_create_internal(array $user, array $data): array
{
    if (!in_array($user['role'], ['branch_admin', 'user'], true)) {
        throw new HttpError(403, 'دسترسی غیرمجاز');
    }

    $branches = $user['branches'] ?? [];
    if (empty($branches)) {
        $start = (int)($user['branch_start_no'] ?? 1);
        $count = (int)($user['branch_count'] ?? 1);
        $branches = range($start, $start + $count - 1);
    }

    $cityCode = resolve_city_code_fk($user['city_code'] ?? null) ?? ($user['city_code'] ?? '');
    if (!$cityCode) throw new HttpError(422, 'کد اداره کاربر تنظیم نشده است.');
    
    $requestedBranch = isset($data['branch_no']) ? (int)to_english_digits((string)$data['branch_no']) : 0;
    $isManualBranch = $requestedBranch > 0 ? 1 : 0;

    $defaultCap = 10;
    $notices = [];
    $selectedCap = null;
    $lastFullBranch = null;

    // Support manual date
    $mY = trim((string)($data['manual_year'] ?? ''));
    $mM = trim((string)($data['manual_month'] ?? ''));
    $mD = trim((string)($data['manual_day'] ?? ''));
    
    $manualY = (int)to_english_digits($mY);
    $manualM = (int)to_english_digits($mM);
    $manualD = (int)to_english_digits($mD);
    
    $today = date('Y-m-d');
    $j = jalali_today_parts();
    $createdAt = now_mysql();
    $isManual = 0;

    if ($manualY > 0 && $manualM > 0 && $manualD > 0) {
        // If manual year is 2 digits (e.g. 04), convert to 4 digits (1404)
        if ($manualY < 100) {
            $manualY += 1400;
        }

        if ($manualY >= 1300 && $manualM >= 1 && $manualM <= 12 && $manualD >= 1 && $manualD <= 31) {
            try {
                if (!\Morilog\Jalali\CalendarUtils::checkDate($manualY, $manualM, $manualD)) {
                    throw new Exception("تاریخ شمسی وارد شده در تقویم وجود ندارد.");
                }
                $jalalian = new Jalalian($manualY, $manualM, $manualD);
                $gDate = $jalalian->toCarbon();
                $today = $gDate->format('Y-m-d');
                // Use manual date but keep current time
                $createdAt = $gDate->format('Y-m-d') . ' ' . date('H:i:s');
                $isManual = 1;
                $j = [
                    'jy' => $manualY,
                    'jm' => $manualM,
                    'jd' => $manualD,
                    'jalali_ymd' => sprintf('%02d%02d%02d', $manualY % 100, $manualM, $manualD),
                    'jalali_full_ymd' => sprintf('%04d%02d%02d', $manualY, $manualM, $manualD)
                ];
                $notices[] = 'ثبت با تاریخ دستی: ' . sprintf('%04d/%02d/%02d', $manualY, $manualM, $manualD);
            } catch (Throwable $e) {
                throw new HttpError(422, 'خطا در پردازش تاریخ: ' . $e->getMessage());
            }
        } else {
            throw new HttpError(422, 'فرمت تاریخ دستی (سال، ماه یا روز) اشتباه است.');
        }
    }

    $branches = array_values(array_unique(array_map('intval', $branches)));
    sort($branches);

    $pNC = validate_national_code($data['plaintiff_national_code'] ?? null);
    $pMob = validate_ir_mobile($data['plaintiff_mobile'] ?? null);
    $pName = trim($data['plaintiff_name'] ?? '');
    $pAddress = trim($data['plaintiff_address'] ?? '');
    $pPostal = trim($data['plaintiff_postal_code'] ?? '');
    $dadnameh = trim($data['dadnameh'] ?? '');
    $dNCInput = trim((string)($data['defendant_national_code'] ?? ''));
    $dMobInput = trim((string)($data['defendant_mobile'] ?? ''));
    $dName = trim((string)($data['defendant_name'] ?? ''));
    $dAddress = trim((string)($data['defendant_address'] ?? ''));
    $dPostal = trim((string)($data['defendant_postal_code'] ?? ''));
    $repGovt = trim((string)($data['representatives_govt'] ?? ''));
    $repWorker = trim((string)($data['representatives_worker'] ?? ''));
    $repEmployer = trim((string)($data['representatives_employer'] ?? ''));
    $pRequest = trim((string)($data['plaintiff_request'] ?? ''));
    $vText = trim((string)($data['verdict_text'] ?? ''));

    $dNC = $dNCInput === '' ? '' : (validate_national_code($dNCInput) ?? null);
    $dMob = $dMobInput === '' ? '' : (validate_ir_mobile($dMobInput) ?? null);

    if (!$pNC || !$pMob) throw new HttpError(422, 'اطلاعات خواهان نامعتبر است.');
    if ($dNC === null) throw new HttpError(422, 'کد ملی خوانده نامعتبر است.');
    if ($dMob === null) throw new HttpError(422, 'موبایل خوانده نامعتبر است.');

    $counterTable = kelaseh_daily_counters_table();
    $jalaliYmd = $j['jalali_ymd'];
    $jalaliFull = $j['jalali_full_ymd'];

    ensure_kelaseh_numbers_code_supports_city_prefix();
    ensure_kelaseh_numbers_supports_manual_flag();
    ensure_kelaseh_numbers_supports_new_case_code();
    ensure_kelaseh_numbers_supports_extended_fields();

    $selectedBranch = null;

    $searchBranches = $branches;
    if ($requestedBranch > 0) {
        $searchBranches = array_unique(array_merge([$requestedBranch], $branches));
    }

    $lastFullBranch = null;
    $finalCode = null;
    $finalBranch = null;
    $finalSeq = null;

    foreach ($searchBranches as $b) {
        $stmt = db()->prepare('SELECT capacity FROM office_branch_capacities WHERE city_code = ? AND branch_no = ?');
        $stmt->execute([$cityCode, $b]);
        $cap = $stmt->fetchColumn();
        if ($cap === false) $cap = $defaultCap;
        $cap = (int)$cap;
        if ($cap < 1) $cap = $defaultCap;

        db()->beginTransaction();
        try {
            $stmt = db()->prepare("SELECT seq_no FROM {$counterTable} WHERE city_code = ? AND jalali_ymd = ? AND branch_no = ? FOR UPDATE");
            $stmt->execute([$cityCode, $jalaliYmd, (int)$b]);
            $cur = $stmt->fetchColumn();
            
            if ($cur === false) {
                $stmt2 = db()->prepare('SELECT COALESCE(MAX(k.seq_no), 0)
                    FROM kelaseh_numbers k
                    JOIN users u ON u.id = k.owner_id
                    WHERE (u.city_code = ? OR LPAD(u.city_code, 4, "0") = ?)
                      AND k.branch_no = ?
                      AND k.jalali_ymd = ?');
                $stmt2->execute([$user['city_code'], $cityCode, (int)$b, $jalaliYmd]);
                $cur = (int)$stmt2->fetchColumn();
            }

            $seqNo = (int)$cur + 1;
            if ($seqNo > $cap) {
                db()->rollBack();
                if ($requestedBranch > 0 && (int)$b === $requestedBranch) {
                    $notices[] = 'ظرفیت شعبه ' . sprintf('%02d', $b) . ' تکمیل بود و سیستم به دنبال شعبه جایگزین گشت.';
                }
                $lastFullBranch = (int)$b;
                continue;
            }

            db()->prepare("INSERT INTO {$counterTable} (city_code, jalali_ymd, branch_no, seq_no, updated_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE seq_no = VALUES(seq_no), updated_at = VALUES(updated_at)")
                ->execute([$cityCode, $jalaliYmd, (int)$b, $seqNo, now_mysql()]);

            $branchNo2 = sprintf('%02d', (int)$b);
            $seqPart = sprintf('%02d', (int)$seqNo);
            $cityPart = str_pad((string)$cityCode, 4, '0', STR_PAD_LEFT);
            
            $code = $cityPart
                . '-' . $branchNo2
                . sprintf('%02d', $j['jy'] % 100)
                . sprintf('%02d', $j['jm'])
                . sprintf('%02d', $j['jd'])
                . $seqPart;

            $yy2 = sprintf('%02d', $j['jy'] % 100);
            $mm2 = sprintf('%02d', $j['jm']);
            $monthlyPrefix = $cityPart . '-' . $branchNo2 . $yy2 . $mm2;

            $yearLike = $cityPart . '-__' . $yy2 . '______';
            $yearSeq = 1;
            try {
                $stmtSeq = db()->prepare('SELECT MAX(CAST(RIGHT(new_case_code, 4) AS UNSIGNED)) FROM kelaseh_numbers WHERE new_case_code LIKE ?');
                $stmtSeq->execute([$yearLike]);
                $curYear = $stmtSeq->fetchColumn();
                if ($curYear !== false && $curYear !== null) {
                    $yearSeq = ((int)$curYear) + 1;
                    if ($yearSeq < 1) $yearSeq = 1;
                }
            } catch (Throwable $e) {
                $yearSeq = 1;
            }

            if ($yearSeq > 9999) {
                db()->rollBack();
                throw new HttpError(429, 'سقف کلاسه جدید در این سال تکمیل شده است.');
            }

            $newCaseCode = $monthlyPrefix . sprintf('%04d', (int)$yearSeq);

            $sql = "INSERT INTO kelaseh_numbers (owner_id, code, new_case_code, branch_no, jalali_ymd, jalali_full_ymd, seq_no, plaintiff_name, plaintiff_national_code, plaintiff_mobile, plaintiff_address, plaintiff_postal_code, defendant_name, defendant_national_code, defendant_mobile, defendant_address, defendant_postal_code, dadnameh, representatives_govt, representatives_worker, representatives_employer, plaintiff_request, verdict_text, status, is_manual, is_manual_branch, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)";
            db()->prepare($sql)->execute([(int)$user['id'], $code, $newCaseCode, (int)$b, $jalaliYmd, $jalaliFull, $seqNo, $pName, $pNC, $pMob, $pAddress, $pPostal, $dName, (string)$dNC, (string)$dMob, $dAddress, $dPostal, $dadnameh, $repGovt, $repWorker, $repEmployer, $pRequest, $vText, $isManual, $isManualBranch, $createdAt, $createdAt]);
            
            db()->commit();
            $finalCode = $code;
            $finalBranch = (int)$b;
            $finalSeq = $seqNo;

            if ($requestedBranch > 0 && $finalBranch === $requestedBranch) {
                $notices[] = 'ثبت در شعبه انتخابی ' . sprintf('%02d', $finalBranch);
            } elseif ($lastFullBranch !== null) {
                $notices[] = 'ظرفیت شعبه ' . sprintf('%02d', $lastFullBranch) . ' تمام شد و به شعبه ' . $branchNo2 . ' انتقال داد.';
            } else {
                $notices[] = 'در حال ثبت و ذخیره در شعبه ' . $branchNo2;
            }

            return ['code' => $finalCode, 'branch_no' => $finalBranch, 'seq_no' => $finalSeq, 'notices' => $notices];

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }

    throw new HttpError(429, 'ظرفیت تمامی شعب مجاز شما تکمیل شده است.');
}

function action_kelaseh_create(array $data): void {
    $user = auth_require_login();
    csrf_require_valid();

    try {
        $res = kelaseh_create_internal($user, $data);
        audit_log((int)$user['id'], 'kelaseh_create', 'kelaseh', null, null);
        json_response(true, ['message' => 'پرونده ایجاد شد.', 'data' => $res]);
    } catch (HttpError $e) {
        json_response(false, ['message' => $e->getMessage()], $e->status);
    }
}

function action_kelaseh_history_check(array $data): void {
    $user = auth_require_login();
    $rawNC = to_english_digits($data['national_code'] ?? '');
    $nc = validate_national_code($rawNC) ?? (preg_match('/^[0-9]{10}$/', $rawNC) ? $rawNC : null);
    if (!$nc) json_response(false, ['message' => 'کد ملی وارد شده نامعتبر است.']);

    $filters = ['national_code' => $nc];
    $rows = kelaseh_fetch_rows($user, $filters, 10);
    $p = [];
    $d = [];
    foreach ($rows as $r) {
        if (($r['plaintiff_national_code'] ?? '') === $nc) $p[] = $r;
        if (($r['defendant_national_code'] ?? '') === $nc) $d[] = $r;
    }
    $p = array_slice($p, 0, 5);
    $d = array_slice($d, 0, 5);
    json_response(true, ['data' => ['plaintiff' => $p, 'defendant' => $d]]);
}

function action_office_capacities_get(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);

    $cityCode = resolve_city_code_fk($user['city_code'] ?? null) ?? (string)($user['city_code'] ?? '');
    if ($user['role'] === 'admin') {
        $cityCode = normalize_city_code($data['city_code'] ?? null) ?? $cityCode;
    }
    if ($cityCode === '') json_response(false, ['message' => 'کد اداره تنظیم نشده است.'], 422);
    $stmt = db()->prepare('SELECT branch_no, capacity FROM office_branch_capacities WHERE city_code = ?');
    $stmt->execute([$cityCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['branch_no']] = $r['capacity'];
    
    $result = [];
    foreach (range(1, 15) as $b) {
        $result[] = ['branch_no' => $b, 'capacity' => $map[$b] ?? 15];
    }
    json_response(true, ['capacities' => $result]);
}

function action_kelaseh_label(array $data): void {
    $user = auth_require_login();
    $codes = [];
    
    // Check both GET and POST
    $inputCodes = $data['codes'] ?? $_GET['codes'] ?? null;
    $inputCode = $data['code'] ?? $_GET['code'] ?? null;

    if (!empty($inputCodes)) {
        $codes = explode(',', $inputCodes);
    } elseif (!empty($inputCode)) {
        $codes = [$inputCode];
    }
    
    if (empty($codes)) {
        echo "کدی ارائه نشده است.";
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($codes), '?'));

    // Update print status in database
    try {
        $printType = count($codes) === 1 ? 'single' : 'bulk';
        $now = now_mysql();
        $updateSql = "UPDATE kelaseh_numbers SET print_type = ?, last_printed_at = ? WHERE code IN ($placeholders)";
        $updateParams = array_merge([$printType, $now], $codes);
        db()->prepare($updateSql)->execute($updateParams);
    } catch (Exception $e) {
        // Silently fail if columns don't exist yet to prevent breaking print
    } catch (Error $e) {
        // Handle PHP 7+ Error if Throwable is not used
    }

    // Fetch details with join to get city information
    $sql = "SELECT k.*, u.city_code, c.name as city_name, CONCAT(u.first_name, ' ', u.last_name) as owner_name 
            FROM kelaseh_numbers k 
            JOIN users u ON u.id = k.owner_id 
            LEFT JOIN isfahan_cities c ON c.code = u.city_code 
            WHERE k.code IN ($placeholders)";
            
    // If not admin, restrict access based on role
    $params = $codes;
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
         $sql .= " AND k.owner_id = ?";
         $params[] = (int)$user['id'];
    } elseif ($user['role'] === 'office_admin') {
         $cityCode = $user['city_code'] ?? '';
         $sql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
         $params[] = $cityCode;
         $params[] = str_pad($cityCode, 4, '0', STR_PAD_LEFT);
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fetchedRows = $stmt->fetchAll();
    
    // Format and maintain requested order
    $rowsMap = [];
    foreach ($fetchedRows as $r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['full_code'] = ($r['city_code'] ?? '') . '-' . $r['code'];
        $rowsMap[(string)$r['code']] = $r;
    }
    
    $rows = [];
    foreach ($codes as $c) {
        if (isset($rowsMap[(string)$c])) {
            $rows[] = $rowsMap[(string)$c];
        }
    }
    
    if (empty($rows)) {
        echo "پرونده‌ای یافت نشد یا دسترسی محدود است.";
        exit;
    }
    
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at'] ?? null);
        $r['full_code'] = (string)($r['code'] ?? '');
    }

    $buildHistory = function (array $viewer, array $row): array {
        $limit = 7;
        $code = (string)($row['code'] ?? '');

        $pnc = validate_national_code($row['plaintiff_national_code'] ?? null);
        $dnc = validate_national_code($row['defendant_national_code'] ?? null);

        $pOut = [];
        $dOut = [];

        if ($pnc) {
            $list = kelaseh_fetch_rows($viewer, ['national_code' => $pnc, 'from' => null, 'to' => null, 'q' => '', 'owner_id' => 0, 'city_code' => null], $limit);
            foreach ($list as $it) {
                if ((string)($it['code'] ?? '') === $code) continue;
                if ((string)($it['plaintiff_national_code'] ?? '') !== $pnc) continue;
                $pOut[] = [
                    'code' => (string)($it['full_code'] ?? $it['code'] ?? ''),
                    'city' => (string)($it['city_name'] ?? ''),
                    'created_at_jalali' => (string)($it['created_at_jalali'] ?? '')
                ];
                if (count($pOut) >= 5) break;
            }
        }
        if ($dnc) {
            $list = kelaseh_fetch_rows($viewer, ['national_code' => $dnc, 'from' => null, 'to' => null, 'q' => '', 'owner_id' => 0, 'city_code' => null], $limit);
            foreach ($list as $it) {
                if ((string)($it['code'] ?? '') === $code) continue;
                if ((string)($it['defendant_national_code'] ?? '') !== $dnc) continue;
                $dOut[] = [
                    'code' => (string)($it['full_code'] ?? $it['code'] ?? ''),
                    'city' => (string)($it['city_name'] ?? ''),
                    'created_at_jalali' => (string)($it['created_at_jalali'] ?? '')
                ];
                if (count($dOut) >= 5) break;
            }
        }

        return ['plaintiff' => $pOut, 'defendant' => $dOut];
    };

    foreach ($rows as &$r) {
        $hist = $buildHistory($user, $r);
        $r['history_plaintiff'] = $hist['plaintiff'];
        $r['history_defendant'] = $hist['defendant'];
    }

    // Now load the HTML template
    $html = file_get_contents(__DIR__ . '/print_labels_new.html');
    
    // We need to inject the data into the HTML. 
    // The HTML currently looks for localStorage 'print_queue'.
    // We can inject a script that sets this variable or directly renders the items.
    // Let's modify the HTML to accept a global variable `INJECTED_DATA`.
    
    $jsonData = json_encode($rows);
    
    // Inject the data into the HTML via a script that populates localStorage
    $script = '<script>(function(){ const data = ' . $jsonData . '; localStorage.setItem("print_queue", JSON.stringify(data)); })();</script>';
    
    // Insert before the head
    $html = str_replace('<head>', "<head>$script", $html);
    
    echo $html;
    exit;
}

function action_kelaseh_label_new(array $data): void {
    $user = auth_require_login();
    $codes = [];
    $inputCodes = $data['codes'] ?? $_GET['codes'] ?? null;
    $inputCode = $data['code'] ?? $_GET['code'] ?? null;
    if (!empty($inputCodes)) {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string)$inputCodes))));
    } elseif (!empty($inputCode)) {
        $codes = [trim((string)$inputCode)];
    }
    if (empty($codes)) {
        echo "کدی ارائه نشده است.";
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT k.*, u.city_code, c.name as city_name, CONCAT(u.first_name, ' ', u.last_name) as owner_name 
            FROM kelaseh_numbers k 
            JOIN users u ON u.id = k.owner_id 
            LEFT JOIN isfahan_cities c ON c.code = u.city_code 
            WHERE k.new_case_code IN ($placeholders)";
    $params = $codes;
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
        $sql .= " AND k.owner_id = ?";
        $params[] = (int)$user['id'];
    } elseif ($user['role'] === 'office_admin') {
        $cityCode = $user['city_code'] ?? '';
        $sql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
        $params[] = $cityCode;
        $params[] = str_pad($cityCode, 4, '0', STR_PAD_LEFT);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fetchedRows = $stmt->fetchAll();
    if (empty($fetchedRows)) {
        echo "پرونده‌ای یافت نشد یا دسترسی محدود است.";
        exit;
    }
    try {
        $printType = count($codes) === 1 ? 'single' : 'bulk';
        $now = now_mysql();
        // Update for new_case_code
        $updateSql1 = "UPDATE kelaseh_numbers SET print_type = ?, last_printed_at = ? WHERE new_case_code IN ($placeholders)";
        $updateParams1 = array_merge([$printType, $now], $codes);
        db()->prepare($updateSql1)->execute($updateParams1);
        // Also update corresponding old codes
        $oldCodes = array_values(array_unique(array_filter(array_map(function($r){ return (string)($r['code'] ?? ''); }, $fetchedRows))));
        if (!empty($oldCodes)) {
            $ph2 = implode(',', array_fill(0, count($oldCodes), '?'));
            $updateSql2 = "UPDATE kelaseh_numbers SET print_type = ?, last_printed_at = ? WHERE code IN ($ph2)";
            $updateParams2 = array_merge([$printType, $now], $oldCodes);
            db()->prepare($updateSql2)->execute($updateParams2);
        }
    } catch (Throwable $e) {
    }
    foreach ($fetchedRows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at'] ?? null);
        $r['full_code'] = (string)($r['new_case_code'] ?? '');
    }
    $buildHistory = function (array $viewer, array $row): array {
        $limit = 7;
        $pnc = validate_national_code($row['plaintiff_national_code'] ?? null);
        $dnc = validate_national_code($row['defendant_national_code'] ?? null);
        $pOut = [];
        $dOut = [];
        if ($pnc) {
            $list = kelaseh_fetch_rows($viewer, ['national_code' => $pnc, 'from' => null, 'to' => null, 'q' => '', 'owner_id' => 0, 'city_code' => null], $limit);
            foreach ($list as $it) {
                $pOut[] = [
                    'code' => (string)($it['full_code'] ?? $it['code'] ?? ''),
                    'city' => (string)($it['city_name'] ?? ''),
                    'created_at_jalali' => (string)($it['created_at_jalali'] ?? '')
                ];
                if (count($pOut) >= 5) break;
            }
        }
        if ($dnc) {
            $list = kelaseh_fetch_rows($viewer, ['national_code' => $dnc, 'from' => null, 'to' => null, 'q' => '', 'owner_id' => 0, 'city_code' => null], $limit);
            foreach ($list as $it) {
                $dOut[] = [
                    'code' => (string)($it['full_code'] ?? $it['code'] ?? ''),
                    'city' => (string)($it['city_name'] ?? ''),
                    'created_at_jalali' => (string)($it['created_at_jalali'] ?? '')
                ];
                if (count($dOut) >= 5) break;
            }
        }
        return ['plaintiff' => $pOut, 'defendant' => $dOut];
    };
    foreach ($fetchedRows as &$r) {
        $hist = $buildHistory($user, $r);
        $r['history_plaintiff'] = $hist['plaintiff'];
        $r['history_defendant'] = $hist['defendant'];
    }
    $html = file_get_contents(__DIR__ . '/print_labels.html');
    $jsonData = json_encode($fetchedRows);
    $script = '<script>(function(){ const data = ' . $jsonData . '; localStorage.setItem(\"print_queue\", JSON.stringify(data)); })();</script>';
    $html = str_replace('<head>', "<head>$script", $html);
    echo $html;
    exit;
}
function action_kelaseh_notice(array $data): void {
    try {
        $user = auth_require_login();
        $codes = [];

        $inputCodes = $data['codes'] ?? $_GET['codes'] ?? null;
        $inputCode = $data['code'] ?? $_GET['code'] ?? null;

        if (!empty($inputCodes)) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string)$inputCodes))));
        } elseif (!empty($inputCode)) {
            $codes = [trim((string)$inputCode)];
        }

        if (empty($codes)) {
            echo "کدی ارائه نشده است.";
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        try {
            $now = now_mysql();
            $updateSql = "UPDATE kelaseh_numbers SET last_notice_printed_at = ? WHERE code IN ($placeholders)";
            $updateParams = array_merge([$now], $codes);
            db()->prepare($updateSql)->execute($updateParams);
        } catch (Throwable $e) {
            // ignore update failure
        }

        $sql = "SELECT k.*, u.city_code, c.name as city_name, CONCAT(u.first_name, ' ', u.last_name) as owner_name 
                FROM kelaseh_numbers k 
                JOIN users u ON u.id = k.owner_id 
                LEFT JOIN isfahan_cities c ON c.code = u.city_code 
                WHERE k.code IN ($placeholders)";

        $params = $codes;
        if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
            $sql .= " AND k.owner_id = ?";
            $params[] = (int)$user['id'];
        } elseif ($user['role'] === 'office_admin') {
            $cityCode = $user['city_code'] ?? '';
            $sql .= " AND (u.city_code = ? OR LPAD(IFNULL(u.city_code,''), 4, '0') = ?)";
            $params[] = $cityCode;
            $params[] = str_pad($cityCode, 4, '0', STR_PAD_LEFT);
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $fetchedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ensure_kelaseh_sessions_table();
        $kelasehIds = array_values(array_unique(array_filter(array_column($fetchedRows, 'id'))));
        $sessionsMap = [];
        if (!empty($kelasehIds)) {
            $inQuery = implode(',', array_fill(0, count($kelasehIds), '?'));
            $stmtS = db()->prepare("SELECT * FROM kelaseh_sessions WHERE kelaseh_id IN ($inQuery) ORDER BY id ASC");
            $stmtS->execute($kelasehIds);
            $allSessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allSessions as $s) {
                $kid = $s['kelaseh_id'] ?? null;
                if ($kid !== null) {
                    $sessionsMap[$kid][] = $s;
                }
            }
        }

        $rowsMap = [];
        foreach ($fetchedRows as $r) {
            $r = is_array($r) ? $r : [];
            $r['created_at_jalali'] = format_jalali_datetime($r['created_at'] ?? null);
            $kid = $r['id'] ?? null;
            $rSessions = ($kid !== null && isset($sessionsMap[$kid])) ? $sessionsMap[$kid] : [];
            $sessionsToPrint = [];

            foreach ($rSessions as $s) {
                if (!empty($s['meeting_date']) || !empty($s['verdict_text'])) {
                    $sessionsToPrint[] = $s;
                }
            }

            if (empty($sessionsToPrint)) {
                $sessionsToPrint[] = [
                    'session_key' => '',
                    'meeting_date' => '',
                    'verdict_text' => '',
                    'plaintiff_request' => '',
                    'reps_govt' => '',
                    'reps_worker' => '',
                    'reps_employer' => ''
                ];
            }

            foreach ($sessionsToPrint as $sessionData) {
                $rowForPrint = $r;
                $rowForPrint['verdict_text'] = (string)($sessionData['verdict_text'] ?? '');
                $rowForPrint['plaintiff_request'] = (string)($sessionData['plaintiff_request'] ?? '');
                $rowForPrint['representatives_govt'] = (string)($sessionData['reps_govt'] ?? '');
                $rowForPrint['representatives_worker'] = (string)($sessionData['reps_worker'] ?? '');
                $rowForPrint['representatives_employer'] = (string)($sessionData['reps_employer'] ?? '');
                $rowForPrint['meeting_date_jalali'] = !empty($sessionData['meeting_date']) ? (string)$sessionData['meeting_date'] : '';
                $sKey = $sessionData['session_key'] ?? '';
                $sMap = [
                    'session1' => 'اول',
                    'session2' => 'دوم',
                    'session3' => 'سوم',
                    'session4' => 'چهارم',
                    'session5' => 'پنجم',
                    'resolution' => 'حل اختلاف'
                ];
                $rowForPrint['session_name'] = $sMap[$sKey] ?? '';
                $rowsMap[] = $rowForPrint;
            }
        }

        $rows = $rowsMap;

        if (empty($rows)) {
            echo "پرونده‌ای یافت نشد یا دسترسی محدود است.";
            exit;
        }

        $htmlPath = __DIR__ . DIRECTORY_SEPARATOR . 'print_minutes.html';
        if (!is_file($htmlPath) || !is_readable($htmlPath)) {
            echo "قالب چاپ (print_minutes.html) یافت نشد.";
            exit;
        }
        $html = file_get_contents($htmlPath);
        if ($html === false) {
            echo "خطا در خواندن قالب چاپ.";
            exit;
        }

        $jsonData = json_encode($rows, JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            echo "خطا در آماده‌سازی داده برای چاپ.";
            exit;
        }
        $script = '<script>(function(){ var data = ' . $jsonData . '; try { localStorage.setItem("print_queue", JSON.stringify(data)); } catch(e) {} })();</script>';
        $html = str_replace('<head>', "<head>$script", $html);
        echo $html;
        exit;
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطا</title></head><body><p>خطا در چاپ رای: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        exit;
    }
}

function action_kelaseh_print_minutes(array $data): void {
    $user = auth_require_login();
    $codes = [];
    
    $inputCodes = $data['codes'] ?? $_GET['codes'] ?? null;
    $inputCode = $data['code'] ?? $_GET['code'] ?? null;

    if (!empty($inputCodes)) {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string)$inputCodes))));
    } elseif (!empty($inputCode)) {
        $codes = [trim((string)$inputCode)];
    }
    
    if (empty($codes)) {
        echo "کدی ارائه نشده است.";
        exit;
    }
    
    $placeholders = implode(',', array_fill(0, count($codes), '?'));

    $sql = "SELECT k.*, u.city_code, c.name as city_name, CONCAT(u.first_name, ' ', u.last_name) as owner_name 
            FROM kelaseh_numbers k 
            JOIN users u ON u.id = k.owner_id 
            LEFT JOIN isfahan_cities c ON c.code = u.city_code 
            WHERE k.code IN ($placeholders)";
            
    $params = $codes;
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
         $sql .= " AND k.owner_id = ?";
         $params[] = (int)$user['id'];
    } elseif ($user['role'] === 'office_admin') {
         $cityCode = $user['city_code'] ?? '';
         $sql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
         $params[] = $cityCode;
         $params[] = str_pad($cityCode, 4, '0', STR_PAD_LEFT);
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fetchedRows = $stmt->fetchAll();
    
    $rowsMap = [];
    foreach ($fetchedRows as $r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['city_address'] = '';
        $r['city_postal_code'] = '';
        $rowsMap[(string)$r['code']] = $r;
    }
    
    $rows = [];
    foreach ($codes as $c) {
        if (isset($rowsMap[(string)$c])) {
            $rows[] = $rowsMap[(string)$c];
        }
    }
    
    if (empty($rows)) {
        echo "پرونده‌ای یافت نشد یا دسترسی محدود است.";
        exit;
    }

    $html = file_get_contents(__DIR__ . '/print_notice.html');
    if ($html === false) {
        echo "قالب چاپ یافت نشد.";
        exit;
    }
    $jsonData = json_encode($rows);
    $script = '<script>(function(){ const data = ' . $jsonData . '; localStorage.setItem("print_queue", JSON.stringify(data)); })();</script>';
    $html = str_replace('<head>', "<head>$script", $html);
    
    echo $html;
    exit;
}

function action_kelaseh_notice2(array $data): void {
    $user = auth_require_login();
    $codes = [];

    $inputCodes = $data['codes'] ?? $_GET['codes'] ?? null;
    $inputCode = $data['code'] ?? $_GET['code'] ?? null;

    if (!empty($inputCodes)) {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string)$inputCodes))));
    } elseif (!empty($inputCode)) {
        $codes = [trim((string)$inputCode)];
    }

    if (empty($codes)) {
        echo "کدی ارائه نشده است.";
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));

    $sql = "SELECT k.*, u.city_code, c.name as city_name, CONCAT(u.first_name, ' ', u.last_name) as owner_name
            FROM kelaseh_numbers k
            JOIN users u ON u.id = k.owner_id
            LEFT JOIN isfahan_cities c ON c.code = u.city_code
            WHERE k.code IN ($placeholders)";

    $params = $codes;
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
         $sql .= " AND k.owner_id = ?";
         $params[] = (int)$user['id'];
    } elseif ($user['role'] === 'office_admin') {
         $cityCode = $user['city_code'] ?? '';
         $sql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
         $params[] = $cityCode;
         $params[] = str_pad($cityCode, 4, '0', STR_PAD_LEFT);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $fetchedRows = $stmt->fetchAll();

    $rowsMap = [];
    foreach ($fetchedRows as $r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['city_address'] = '';
        $r['city_postal_code'] = '';
        $rowsMap[(string)$r['code']] = $r;
    }

    $rows = [];
    foreach ($codes as $c) {
        if (isset($rowsMap[(string)$c])) {
            $rows[] = $rowsMap[(string)$c];
        }
    }

    if (empty($rows)) {
        echo "پرونده‌ای یافت نشد یا دسترسی محدود است.";
        exit;
    }

    $html = file_get_contents(__DIR__ . '/print_notice2.html');
    if ($html === false) {
        echo "قالب چاپ یافت نشد.";
        exit;
    }
    $jsonData = json_encode($rows);
    $script = '<script>(function(){ const data = ' . $jsonData . '; localStorage.setItem("print_queue", JSON.stringify(data)); })();</script>';
    $html = str_replace('<head>', "<head>$script", $html);

    echo $html;
    exit;
}

function action_kelaseh_print(array $data): void {
     // Similar to label but maybe different template?
     // For now, reuse label logic or redirect.
     // The user asked for 'label' specifically.
     action_kelaseh_label($data);
}


function action_office_capacities_update(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    csrf_require_valid();
    
    $branch = isset($data['branch_no']) ? (int)$data['branch_no'] : 0;
    $cap = isset($data['capacity']) ? (int)$data['capacity'] : 0;
    
    if ($branch < 1 || $branch > 15 || $cap < 0) json_response(false, ['message' => 'اطلاعات نامعتبر'], 422);
    
    $cityCode = resolve_city_code_fk($user['city_code'] ?? null) ?? (string)($user['city_code'] ?? '');
    if ($user['role'] === 'admin') {
        $cityCode = normalize_city_code($data['city_code'] ?? null) ?? $cityCode;
        if (!$cityCode) json_response(false, ['message' => 'کد اداره الزامی است.'], 422);
    }
    if ($cityCode === '') json_response(false, ['message' => 'کد اداره تنظیم نشده است.'], 422);
    $stmt = db()->prepare('SELECT id FROM office_branch_capacities WHERE city_code = ? AND branch_no = ?');
    $stmt->execute([$cityCode, $branch]);
    if ($stmt->fetch()) {
        db()->prepare('UPDATE office_branch_capacities SET capacity = ? WHERE city_code = ? AND branch_no = ?')->execute([$cap, $cityCode, $branch]);
    } else {
        db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?)')->execute([$cityCode, $branch, $cap]);
    }
    json_response(true, ['message' => 'ظرفیت ذخیره شد.']);
}

function action_office_stats(): void
{
    $user = auth_require_login();
    if ($user['role'] !== 'office_admin') json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);

    $cityCode = resolve_city_code_fk($user['city_code'] ?? null) ?? ($user['city_code'] ?? '');
    if ($cityCode === '') json_response(false, ['message' => 'کد اداره تنظیم نشده است.'], 422);

    $sqlTotals = "SELECT
        COUNT(*) as total,
        SUM(k.status='active') as active,
        SUM(k.status='inactive') as inactive,
        SUM(k.status='voided') as voided
        FROM kelaseh_numbers k
        JOIN users u ON u.id = k.owner_id
        WHERE (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
    $stmt = db()->prepare($sqlTotals);
    $stmt->execute([$cityCode, normalize_city_code($cityCode) ?? $cityCode]);
    $totals = $stmt->fetch() ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'voided' => 0];

    $sqlBranches = "SELECT k.branch_no,
        COUNT(*) as total,
        SUM(k.status='active') as active,
        SUM(k.status='inactive') as inactive,
        SUM(k.status='voided') as voided
        FROM kelaseh_numbers k
        JOIN users u ON u.id = k.owner_id
        WHERE (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)
        GROUP BY k.branch_no
        ORDER BY k.branch_no ASC";
    $stmt = db()->prepare($sqlBranches);
    $stmt->execute([$cityCode, normalize_city_code($cityCode) ?? $cityCode]);
    $branches = $stmt->fetchAll();

    $sqlUsers = "SELECT u.id, u.username, u.first_name, u.last_name, COUNT(k.id) as total
        FROM users u
        LEFT JOIN kelaseh_numbers k ON k.owner_id = u.id
        WHERE (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?) AND u.role = 'branch_admin'
        GROUP BY u.id
        ORDER BY total DESC
        LIMIT 100";
    $stmt = db()->prepare($sqlUsers);
    $stmt->execute([$cityCode, normalize_city_code($cityCode) ?? $cityCode]);
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['display_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? '');
    }

    json_response(true, ['data' => ['totals' => $totals, 'branches' => $branches, 'users' => $users]]);
}

function setting_get(string $key, $default = null)
{
    $stmt = db()->prepare('SELECT v FROM app_settings WHERE k = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return ($v === false) ? $default : $v;
}

function setting_set(string $key, ?string $value): void
{
    $stmt = db()->prepare('INSERT INTO app_settings (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_mysql()]);
}

function action_kelaseh_update(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    if ($code === '') json_response(false, ['message' => 'کلاسه الزامی است.'], 422);

    $pNC = validate_national_code($data['plaintiff_national_code'] ?? null);
    $pMob = validate_ir_mobile($data['plaintiff_mobile'] ?? null);
    $pName = trim((string)($data['plaintiff_name'] ?? ''));

    $dNCInput = trim((string)($data['defendant_national_code'] ?? ''));
    $dMobInput = trim((string)($data['defendant_mobile'] ?? ''));
    $dName = trim((string)($data['defendant_name'] ?? ''));
    $dNC = $dNCInput; // اختیاری و بدون الزام به 10 رقم
    $dMob = $dMobInput; // اختیاری؛ بدون اعتبارسنجی

    if (!$pNC || !$pMob) json_response(false, ['message' => 'اطلاعات خواهان نامعتبر است.'], 422);
    // فیلدهای خوانده اختیاری هستند؛ عدم اعتبارسنجی سخت‌گیرانه

    $params = [$pName, $dName, $pNC, (string)$dNC, $pMob, (string)$dMob, now_mysql()];
    $where = 'code = ?';
    $params[] = $code;

    if (in_array($user['role'], ['branch_admin', 'user'], true)) {
        $where .= ' AND owner_id = ?';
        $params[] = $user['id'];
    } elseif ($user['role'] === 'office_admin') {
        $where .= ' AND owner_id IN (SELECT id FROM users WHERE city_code = ? OR LPAD(city_code, 4, "0") = ?)';
        $params[] = $user['city_code'];
        $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
    } elseif ($user['role'] !== 'admin') {
        json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    }

    $sql = "UPDATE kelaseh_numbers SET plaintiff_name = ?, defendant_name = ?, plaintiff_national_code = ?, defendant_national_code = ?, plaintiff_mobile = ?, defendant_mobile = ?, updated_at = ? WHERE $where";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() < 1) json_response(false, ['message' => 'پرونده یافت نشد یا دسترسی ندارید.'], 404);
    audit_log($user['id'], 'kelaseh_update', 'kelaseh', null, null);
    json_response(true, ['message' => 'ویرایش شد']);
}

function action_kelaseh_set_status(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();
    $code = trim((string)($data['code'] ?? ''));
    $status = (string)($data['status'] ?? '');
    if ($code === '' || !in_array($status, ['active', 'inactive', 'voided'], true)) {
        json_response(false, ['message' => 'اطلاعات نامعتبر'], 422);
    }

    $params = [$status, now_mysql()];
    $where = 'code = ?';
    $params[] = $code;

    if (in_array($user['role'], ['branch_admin', 'user'], true)) {
        $where .= ' AND owner_id = ?';
        $params[] = $user['id'];
    } elseif ($user['role'] === 'office_admin') {
        $where .= ' AND owner_id IN (SELECT id FROM users WHERE city_code = ? OR LPAD(city_code, 4, "0") = ?)';
        $params[] = $user['city_code'];
        $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
    } elseif ($user['role'] !== 'admin') {
        json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    }

    $stmt = db()->prepare("UPDATE kelaseh_numbers SET status = ?, updated_at = ? WHERE $where");
    $stmt->execute($params);
    if ($stmt->rowCount() < 1) json_response(false, ['message' => 'پرونده یافت نشد یا دسترسی ندارید.'], 404);
    audit_log($user['id'], 'kelaseh_set_status', 'kelaseh', null, null);
    json_response(true, ['message' => 'وضعیت ذخیره شد']);
}

function action_kelaseh_sms_send(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    $toPlaintiff = (int)($data['to_plaintiff'] ?? 0) === 1;
    $toDefendant = (int)($data['to_defendant'] ?? 0) === 1;
    if ($code === '' || (!$toPlaintiff && !$toDefendant)) json_response(false, ['message' => 'اطلاعات نامعتبر'], 422);

    $row = null;
    $sql = "SELECT k.*, u.city_code, c.name as city_name
            FROM kelaseh_numbers k
            JOIN users u ON u.id = k.owner_id
            LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
            WHERE k.code = ?";
    $params = [$code];
    if (in_array($user['role'], ['branch_admin', 'user'], true)) {
        $sql .= ' AND k.owner_id = ?';
        $params[] = $user['id'];
    } elseif ($user['role'] === 'office_admin') {
        $sql .= ' AND (u.city_code = ? OR LPAD(u.city_code, 4, "0") = ?)';
        $params[] = $user['city_code'];
        $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
    } elseif ($user['role'] === 'admin') {
        $sql .= ' ORDER BY k.id DESC';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) json_response(false, ['message' => 'پرونده یافت نشد.'], 404);

    $enabled = (int)(setting_get('sms.enabled', '0') ?? '0');
    if ($enabled !== 1) json_response(false, ['message' => 'ارسال پیامک غیرفعال است.'], 422);

    $rawCode = (string)($row['code'] ?? '');
    $fullCode = str_contains($rawCode, '-') ? $rawCode : (($row['city_code'] ?? '') ? ((string)$row['city_code'] . '-') : '') . $rawCode;
    $vars = [
        'code' => $rawCode,
        'full_code' => $fullCode,
        'city_name' => (string)($row['city_name'] ?? ''),
        'branch_no' => (string)($row['branch_no'] ?? ''),
        'seq_no' => (string)($row['seq_no'] ?? ''),
        'date' => (string)format_jalali_datetime($row['created_at'] ?? null),
        'plaintiff_name' => (string)($row['plaintiff_name'] ?? ''),
        'plaintiff_national_code' => (string)($row['plaintiff_national_code'] ?? ''),
        'plaintiff_mobile' => (string)($row['plaintiff_mobile'] ?? ''),
        'defendant_name' => (string)($row['defendant_name'] ?? ''),
        'defendant_national_code' => (string)($row['defendant_national_code'] ?? ''),
        'defendant_mobile' => (string)($row['defendant_mobile'] ?? ''),
    ];

    $render = function (string $tpl) use ($vars): string {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', $v, $out);
        }
        return $out;
    };

    $now = now_mysql();
    if ($toPlaintiff && !empty($row['plaintiff_mobile'])) {
        $tpl = (string)(setting_get('sms.tpl_plaintiff', '') ?? '');
        if ($tpl === '') $tpl = 'اطلاع‌رسانی پرونده {full_code}';
        $message = $render($tpl);
        db()->prepare('INSERT INTO sms_logs (recipient_mobile, message, type, status, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$row['plaintiff_mobile'], $message, 'plaintiff', 'sent', $now]);
    }
    if ($toDefendant && !empty($row['defendant_mobile'])) {
        $tpl = (string)(setting_get('sms.tpl_defendant', '') ?? '');
        if ($tpl === '') $tpl = 'اطلاع‌رسانی پرونده {full_code}';
        $message = $render($tpl);
        db()->prepare('INSERT INTO sms_logs (recipient_mobile, message, type, status, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$row['defendant_mobile'], $message, 'defendant', 'sent', $now]);
    }
    audit_log($user['id'], 'sms_send', 'kelaseh', null, null);
    json_response(true, ['message' => 'در صف ارسال ثبت شد.']);
}

function action_kelaseh_export_csv(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $national = trim((string)($_REQUEST['national_code'] ?? $data['national_code'] ?? ''));
    $from = parse_jalali_full_ymd($_REQUEST['from'] ?? $data['from'] ?? null);
    $to = parse_jalali_full_ymd($_REQUEST['to'] ?? $data['to'] ?? null);
    $q = trim((string)($_REQUEST['q'] ?? $data['q'] ?? ''));
    $ownerIdFilter = isset($_REQUEST['owner_id']) ? (int)$_REQUEST['owner_id'] : (isset($data['owner_id']) ? (int)$data['owner_id'] : 0);
    $cityFilter = normalize_city_code($_REQUEST['city_code'] ?? $data['city_code'] ?? null);

    $filters = ['national_code' => $national, 'from' => $from, 'to' => $to, 'q' => $q, 'owner_id' => $ownerIdFilter, 'city_code' => $cityFilter];
    $rows = kelaseh_fetch_rows($user, $filters, 2000);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kelaseh_export.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['کلاسه', 'اداره', 'کاربر', 'شعبه', 'ردیف', 'کد ملی خواهان', 'کد ملی خوانده', 'نام خواهان', 'نام خوانده', 'وضعیت', 'تاریخ ثبت']);
    foreach ($rows as $r) {
        $status = (string)($r['status'] ?? '');
        $statusFa = $status === 'voided' ? 'ابطال' : ($status === 'inactive' ? 'غیرفعال' : 'فعال');
        fputcsv($out, [
            $r['full_code'] ?? ($r['code'] ?? ''),
            $r['city_name'] ?? ($r['city_code'] ?? ''),
            $r['owner_name'] ?? '',
            $r['branch_no'] ?? '',
            $r['seq_no'] ?? '',
            $r['plaintiff_national_code'] ?? '',
            $r['defendant_national_code'] ?? '',
            $r['plaintiff_name'] ?? '',
            $r['defendant_name'] ?? '',
            $statusFa,
            $r['created_at_jalali'] ?? ($r['created_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

function action_kelaseh_export_print(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $national = trim((string)($_REQUEST['national_code'] ?? $data['national_code'] ?? ''));
    $from = parse_jalali_full_ymd($_REQUEST['from'] ?? $data['from'] ?? null);
    $to = parse_jalali_full_ymd($_REQUEST['to'] ?? $data['to'] ?? null);
    $q = trim((string)($_REQUEST['q'] ?? $data['q'] ?? ''));
    $ownerIdFilter = isset($_REQUEST['owner_id']) ? (int)$_REQUEST['owner_id'] : (isset($data['owner_id']) ? (int)$data['owner_id'] : 0);
    $cityFilter = normalize_city_code($_REQUEST['city_code'] ?? $data['city_code'] ?? null);

    $filters = ['national_code' => $national, 'from' => $from, 'to' => $to, 'q' => $q, 'owner_id' => $ownerIdFilter, 'city_code' => $cityFilter];
    $rows = kelaseh_fetch_rows($user, $filters, 2000);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>لیست پرونده‌ها</title>';
    echo '<style>body{font-family:Tahoma,Arial,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#f3f3f3}</style>';
    echo '</head><body>';
    echo '<table><thead><tr><th>کلاسه</th><th>اداره</th><th>کاربر</th><th>شعبه</th><th>خواهان</th><th>کد ملی خواهان</th><th>خوانده</th><th>تاریخ</th><th>وضعیت</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $fullCode = htmlspecialchars((string)($r['full_code'] ?? $r['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $city = htmlspecialchars((string)($r['city_name'] ?? $r['city_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $owner = htmlspecialchars((string)($r['owner_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $branch = htmlspecialchars((string)($r['branch_no'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pn = htmlspecialchars((string)($r['plaintiff_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pnc = htmlspecialchars((string)($r['plaintiff_national_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $dn = htmlspecialchars((string)($r['defendant_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $dt = htmlspecialchars((string)($r['created_at_jalali'] ?? $r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        $manualText = '';
        if (($r['is_manual'] ?? 0) && ($r['is_manual_branch'] ?? 0)) {
            $manualText = ' - شعبه و تاریخ دستی';
        } elseif ($r['is_manual'] ?? 0) {
            $manualText = ' - تاریخ دستی';
        } elseif ($r['is_manual_branch'] ?? 0) {
            $manualText = ' - شعبه دستی';
        }
        $dt .= $manualText;

        $st = htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo "<tr><td dir=\"ltr\">$fullCode</td><td>$city</td><td>$owner</td><td>$branch</td><td>$pn</td><td dir=\"ltr\">$pnc</td><td>$dn</td><td>$dt</td><td>$st</td></tr>";
    }
    echo '</tbody></table>';
    echo '<script>window.print()</script>';
    echo '</body></html>';
    exit;
}

function kelaseh_fetch_rows_paginated(array $user, array $filters, int $limit, int $offset): array
{
    $national = trim((string)($filters['national_code'] ?? ''));
    $from = $filters['from'] ?? null;
    $to = $filters['to'] ?? null;
    $q = trim((string)($filters['q'] ?? ''));
    $qEng = to_english_digits($q);
    $ownerIdFilter = (int)($filters['owner_id'] ?? 0);
    $cityFilter = $filters['city_code'] ?? null;

    $baseSql = "FROM kelaseh_numbers k
                JOIN users u ON u.id = k.owner_id
                LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
                WHERE 1=1";
    $params = [];

    $nationalExact = $national !== '' ? validate_national_code($national) : null;
    $isGlobalSearch = ($nationalExact !== null) || (strlen($national) === 10 && preg_match('/^[0-9]{10}$/', $national));

    if (!$isGlobalSearch) {
        if (in_array($user['role'], ['branch_admin', 'user'], true)) {
            $codePrefix = null;
            if ($qEng !== '' && preg_match('/^(\d{4})-/', $qEng, $m)) {
                $codePrefix = $m[1] ?? null;
            }
            $userCityNorm = normalize_city_code($user['city_code']) ?? $user['city_code'];
            if ($codePrefix && ($codePrefix === $userCityNorm)) {
                $baseSql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
                $params[] = $user['city_code'];
                $params[] = $userCityNorm;
            } else {
                $baseSql .= " AND k.owner_id = ?";
                $params[] = $user['id'];
            }
        } elseif ($user['role'] === 'office_admin') {
            $baseSql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
            $params[] = $user['city_code'];
            $params[] = normalize_city_code($user['city_code']) ?? $user['city_code'];
            if ($ownerIdFilter > 0) {
                $baseSql .= " AND k.owner_id = ?";
                $params[] = $ownerIdFilter;
            }
        } elseif ($user['role'] === 'admin') {
            if ($cityFilter) {
                $baseSql .= " AND (u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
                $params[] = $cityFilter;
                $params[] = ltrim((string)$cityFilter, '0') === '' ? '0' : ltrim((string)$cityFilter, '0');
            }
            if ($ownerIdFilter > 0) {
                $baseSql .= " AND k.owner_id = ?";
                $params[] = $ownerIdFilter;
            }
        } else {
            return ['rows' => [], 'total' => 0];
        }
    }

    if ($national !== '') {
        $national = to_english_digits($national);
        $like = "%$national%";
        
        // Priority logic: if it looks like a national code (10 digits) or legal ID (11 digits)
        if (preg_match('/^[0-9]{10,11}$/', $national)) {
            $baseSql .= " AND (k.plaintiff_national_code = ? OR k.defendant_national_code = ? OR k.plaintiff_name LIKE ? OR k.defendant_name LIKE ? OR k.code LIKE ? OR k.new_case_code LIKE ? OR k.plaintiff_mobile LIKE ? OR k.defendant_mobile LIKE ? OR c.name LIKE ? OR k.branch_no LIKE ?)";
            array_push($params, $national, $national, $like, $like, $like, $like, $like, $like, $like, $like);
        } else {
            $baseSql .= " AND (k.plaintiff_national_code LIKE ? OR k.defendant_national_code LIKE ? OR k.plaintiff_name LIKE ? OR k.defendant_name LIKE ? OR k.code LIKE ? OR k.new_case_code LIKE ? OR k.plaintiff_mobile LIKE ? OR k.defendant_mobile LIKE ? OR c.name LIKE ? OR k.branch_no LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
    }

    if ($q !== '') {
        $qEng = to_english_digits($q);
        $like = "%$qEng%";
        $baseSql .= " AND (k.code LIKE ? OR k.new_case_code LIKE ? OR k.plaintiff_national_code LIKE ? OR k.defendant_national_code LIKE ? OR k.plaintiff_name LIKE ? OR k.defendant_name LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($from) {
        $baseSql .= " AND k.created_at >= ?";
        $params[] = "$from 00:00:00";
    }
    if ($to) {
        $baseSql .= " AND k.created_at <= ?";
        $params[] = "$to 23:59:59";
    }

    // Count total
    $countSql = "SELECT COUNT(*) " . $baseSql;
    $stmtCount = db()->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch rows
    $sql = "SELECT k.*, u.username, u.first_name, u.last_name, u.city_code, c.name as city_name " . $baseSql;
    
    $orderSql = "COALESCE(CAST(RIGHT(k.new_case_code, 4) AS UNSIGNED), k.id) ASC";
    if ($national !== '' && preg_match('/^[0-9]{10,11}$/', $national)) {
        // Priority to exact matches on national code or legal ID
        $orderSql = "CASE WHEN k.plaintiff_national_code = ? OR k.defendant_national_code = ? THEN 0 ELSE 1 END, COALESCE(CAST(RIGHT(k.new_case_code, 4) AS UNSIGNED), k.id) ASC";
        array_push($params, $national, $national);
    }
    
    $sql .= " ORDER BY " . $orderSql . " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at'] ?? null);
        $r['full_code'] = (string)($r['code'] ?? '');
        $r['owner_name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? '');
        $r['is_manual'] = (int)($r['is_manual'] ?? 0);
        $r['is_manual_branch'] = (int)($r['is_manual_branch'] ?? 0);
    }
    return ['rows' => $rows, 'total' => $total];
}

function kelaseh_fetch_rows(array $user, array $filters, int $limit): array
{
    $res = kelaseh_fetch_rows_paginated($user, $filters, $limit, 0);
    return $res['rows'];
}

function action_admin_items_list(array $data): void
{
    auth_require_admin(auth_require_login());
    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = "SELECT i.*, u.username as owner_key FROM items i LEFT JOIN users u ON u.id = i.owner_id";
    if ($q !== '') {
        $sql .= " WHERE (i.title LIKE ? OR i.content LIKE ?)";
        $like = "%$q%";
        $params = [$like, $like];
    }
    $sql .= " ORDER BY i.updated_at DESC LIMIT 100";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    foreach ($items as &$it) {
        $it['updated_at_jalali'] = format_jalali_datetime($it['updated_at'] ?? null);
    }
    json_response(true, ['data' => ['items' => $items]]);
}

function action_admin_items_delete(array $data): void
{
    auth_require_admin(auth_require_login());
    csrf_require_valid();
    $id = (int)($data['id'] ?? 0);
    if ($id < 1) json_response(false, ['message' => 'شناسه نامعتبر'], 422);
    db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
    audit_log((int)$_SESSION['user_id'], 'delete', 'item', $id, null);
    json_response(true, ['message' => 'حذف شد']);
}

function action_admin_sms_settings_get(): void
{
    auth_require_admin(auth_require_login());
    $enabled = (int)(setting_get('sms.enabled', '0') ?? '0');
    $otpEnabled = (int)(setting_get('sms.otp.enabled', '0') ?? '0');
    $otpTpl = (string)(setting_get('sms.otp.tpl', '') ?? '');
    $otpLen = (int)(setting_get('sms.otp.len', '6') ?? '6');
    $otpTtl = (int)(setting_get('sms.otp.ttl', '5') ?? '5');
    $otpMaxTries = (int)(setting_get('sms.otp.max_tries', '5') ?? '5');
    $sender = (string)(setting_get('sms.sender', '') ?? '');
    $tplP = (string)(setting_get('sms.tpl_plaintiff', '') ?? '');
    $tplD = (string)(setting_get('sms.tpl_defendant', '') ?? '');
    $apiKey = (string)(setting_get('sms.api_key', '') ?? '');
    json_response(true, ['data' => ['settings' => [
        'enabled' => $enabled,
        'otp_enabled' => $otpEnabled,
        'tpl_otp' => $otpTpl,
        'otp_len' => $otpLen,
        'otp_ttl' => $otpTtl,
        'otp_max_tries' => $otpMaxTries,
        'sender' => $sender,
        'tpl_plaintiff' => $tplP,
        'tpl_defendant' => $tplD,
        'api_key_present' => $apiKey !== '' ? 1 : 0,
    ]]]);
}

function action_admin_sms_settings_set(array $data): void
{
    auth_require_admin(auth_require_login());
    csrf_require_valid();
    $enabled = (int)($data['enabled'] ?? 0) === 1 ? '1' : '0';
    $otpEnabled = (int)($data['otp_enabled'] ?? 0) === 1 ? '1' : '0';
    $otpTpl = (string)($data['tpl_otp'] ?? '');
    $otpLen = (int)($data['otp_len'] ?? 6);
    if ($otpLen < 4) $otpLen = 4;
    if ($otpLen > 8) $otpLen = 8;
    $otpTtl = (int)($data['otp_ttl'] ?? 5);
    if ($otpTtl < 1) $otpTtl = 1;
    if ($otpTtl > 30) $otpTtl = 30;
    $otpMaxTries = (int)($data['otp_max_tries'] ?? 5);
    if ($otpMaxTries < 1) $otpMaxTries = 1;
    if ($otpMaxTries > 10) $otpMaxTries = 10;
    $sender = trim((string)($data['sender'] ?? ''));
    $tplP = (string)($data['tpl_plaintiff'] ?? '');
    $tplD = (string)($data['tpl_defendant'] ?? '');
    $apiKey = trim((string)($data['api_key'] ?? ''));

    setting_set('sms.enabled', $enabled);
    setting_set('sms.otp.enabled', $otpEnabled);
    setting_set('sms.otp.tpl', $otpTpl);
    setting_set('sms.otp.len', (string)$otpLen);
    setting_set('sms.otp.ttl', (string)$otpTtl);
    setting_set('sms.otp.max_tries', (string)$otpMaxTries);
    setting_set('sms.sender', $sender);
    setting_set('sms.tpl_plaintiff', $tplP);
    setting_set('sms.tpl_defendant', $tplD);
    if ($apiKey !== '') setting_set('sms.api_key', $apiKey);
    audit_log((int)$_SESSION['user_id'], 'sms_settings_update', 'app_settings', null, null);
    json_response(true, ['message' => 'تنظیمات ذخیره شد']);
}

function action_admin_kelaseh_stats(array $data): void
{
    auth_require_admin(auth_require_login());
    $from = parse_jalali_full_ymd($data['from'] ?? null);
    $to = parse_jalali_full_ymd($data['to'] ?? null);
    $params = [];
    $where = [];
    if ($from) {
        $where[] = 'k.created_at >= ?';
        $params[] = "$from 00:00:00";
    }
    if ($to) {
        $where[] = 'k.created_at <= ?';
        $params[] = "$to 23:59:59";
    }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totSql = "SELECT COUNT(*) as total,
        SUM(k.status='active') as active,
        SUM(k.status='inactive') as inactive,
        SUM(k.status='voided') as voided
        FROM kelaseh_numbers k $w";
    $stmt = db()->prepare($totSql);
    $stmt->execute($params);
    $totals = $stmt->fetch() ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'voided' => 0];

    $citySql = "SELECT u.city_code as city_code, c.name as city_name,
        COUNT(*) as total,
        SUM(k.status='active') as active,
        SUM(k.status='inactive') as inactive,
        SUM(k.status='voided') as voided
        FROM kelaseh_numbers k
        JOIN users u ON u.id = k.owner_id
        LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
        $w
        GROUP BY u.city_code, c.name
        ORDER BY total DESC";
    $stmt = db()->prepare($citySql);
    $stmt->execute($params);
    $cities = $stmt->fetchAll();

    $userSql = "SELECT u.id, u.username, u.first_name, u.last_name, c.name as city_name, u.city_code,
        COUNT(*) as total,
        SUM(k.status='active') as active,
        SUM(k.status='inactive') as inactive,
        SUM(k.status='voided') as voided
        FROM kelaseh_numbers k
        JOIN users u ON u.id = k.owner_id
        LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
        $w
        GROUP BY u.id
        ORDER BY total DESC
        LIMIT 200";
    $stmt = db()->prepare($userSql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['display_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? '');
    }

    json_response(true, ['data' => ['totals' => $totals, 'cities' => $cities, 'users' => $users]]);
}

function action_admin_detailed_stats(): void
{
    auth_require_admin(auth_require_login());
    $sql = "SELECT u.city_code, c.name as city_name, u.role, u.username, u.first_name, u.last_name, COUNT(k.id) as total
            FROM users u
            LEFT JOIN kelaseh_numbers k ON k.owner_id = u.id
            LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, '0'))
            WHERE u.role IN ('office_admin', 'branch_admin')
            GROUP BY u.id
            ORDER BY u.city_code ASC, u.role ASC, total DESC
            LIMIT 400";
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['display_name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? '');
    }
    json_response(true, ['data' => ['stats' => $rows]]);
}

function action_admin_kelaseh_search(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    
    $q = trim((string)($data['q'] ?? ''));
    if ($q === '') json_response(true, ['data' => ['results' => []]]);
    
    $filters = [
        'q' => $q,
        'city_code' => $data['city_code'] ?? null,
    ];
    
    $rows = kelaseh_fetch_rows($user, $filters, 200);
    json_response(true, ['data' => ['results' => $rows]]);
}

function action_admin_kelaseh_backfill_new_case_code(): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    ensure_kelaseh_numbers_supports_new_case_code();

    $sql = "SELECT k.id, k.new_case_code, k.jalali_full_ymd, k.branch_no, u.city_code, k.created_at
            FROM kelaseh_numbers k
            JOIN users u ON u.id = k.owner_id
            WHERE k.new_case_code IS NULL
            ORDER BY k.created_at ASC, k.id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        json_response(true, ['message' => 'رکوردی برای به‌روزرسانی یافت نشد.']);
    }

    $updateStmt = db()->prepare("UPDATE kelaseh_numbers SET new_case_code = ? WHERE id = ?");
    $yearCounters = [];

    foreach ($rows as $r) {
        $cityCodeRaw = $r['city_code'] ?? '';
        $cityCode = resolve_city_code_fk($cityCodeRaw) ?? $cityCodeRaw;
        $cityPart = str_pad((string)$cityCode, 4, '0', STR_PAD_LEFT);

        $branchNo = (int)($r['branch_no'] ?? 0);
        $branchNo2 = sprintf('%02d', $branchNo);

        $jalaliFull = (string)($r['jalali_full_ymd'] ?? '');
        if (strlen($jalaliFull) !== 8) {
            continue;
        }

        $jy = (int)substr($jalaliFull, 0, 4);
        $jm = (int)substr($jalaliFull, 4, 2);

        if ($jy < 1300 || $jm < 1 || $jm > 12) {
            continue;
        }

        $yy2 = sprintf('%02d', $jy % 100);
        $mm2 = sprintf('%02d', $jm);
        $prefix = $cityPart . '-' . $branchNo2 . $yy2 . $mm2;

        $yearKey = $cityPart . '-' . $yy2;
        if (!isset($yearCounters[$yearKey])) {
            $yearCounters[$yearKey] = 0;
            try {
                $like = $cityPart . '-__' . $yy2 . '______';
                $stmtMax = db()->prepare('SELECT MAX(CAST(RIGHT(new_case_code, 4) AS UNSIGNED)) FROM kelaseh_numbers WHERE new_case_code LIKE ?');
                $stmtMax->execute([$like]);
                $maxSeq = $stmtMax->fetchColumn();
                $yearCounters[$yearKey] = ($maxSeq !== false && $maxSeq !== null) ? (int)$maxSeq : 0;
                if ($yearCounters[$yearKey] < 0) $yearCounters[$yearKey] = 0;
            } catch (Throwable $e) {
                $yearCounters[$yearKey] = 0;
            }
        }

        $yearCounters[$yearKey]++;
        $seq = (int)$yearCounters[$yearKey];
        if ($seq > 9999) {
            continue;
        }
        $newCaseCode = $prefix . sprintf('%04d', $seq);

        $updateStmt->execute([$newCaseCode, (int)$r['id']]);
    }

    json_response(true, ['message' => 'کلاسه‌های جدید برای رکوردهای قدیمی محاسبه و ذخیره شد.']);
}

// ADMIN ACTIONS RESTORED

function action_admin_users_list(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    
    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = "SELECT u.*, c.name as city_name, GROUP_CONCAT(ub.branch_no) as branches FROM users u LEFT JOIN isfahan_cities c ON c.code = u.city_code LEFT JOIN user_branches ub ON ub.user_id = u.id";
    $where = [];
    if ($user['role'] === 'office_admin') {
        $cityCode = resolve_city_code_fk($user['city_code'] ?? null) ?? (string)($user['city_code'] ?? '');
        if ($cityCode !== '') {
            $where[] = "(u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
            $params[] = $cityCode;
            $params[] = normalize_city_code($cityCode) ?? $cityCode;
        }
    } elseif ($user['role'] === 'admin') {
        $cityFilter = $data['city_code'] ?? null;
        if ($cityFilter) {
            $where[] = "(u.city_code = ? OR LPAD(u.city_code, 4, '0') = ?)";
            $params[] = $cityFilter;
            $params[] = normalize_city_code($cityFilter) ?? $cityFilter;
        }
    }
    if ($q !== '') {
        $where[] = "(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ?)";
        $like = "%$q%";
        array_push($params, $like, $like, $like, $like);
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);

    $limit = ($user['role'] === 'admin') ? 500 : 200;
    $sql .= " GROUP BY u.id ORDER BY CASE u.role WHEN 'admin' THEN 0 WHEN 'office_admin' THEN 1 WHEN 'branch_admin' THEN 2 ELSE 9 END, u.city_code ASC, u.id DESC LIMIT " . (int)$limit;
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    // Fetch branch capacities for each user's city
    $cityCaps = [];
    foreach ($users as &$u) {
        $cCode = $u['city_code'];
        if ($cCode && !isset($cityCaps[$cCode])) {
            $stmtCap = db()->prepare('SELECT branch_no, capacity FROM office_branch_capacities WHERE city_code = ?');
            $stmtCap->execute([$cCode]);
            $cityCaps[$cCode] = $stmtCap->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $u['branch_capacities'] = $cityCaps[$cCode] ?? [];
        $u['last_login_at_jalali'] = format_jalali_datetime($u['last_login_at'] ?? null);
    }
    
    json_response(true, ['data' => ['users' => $users]]);
}

function action_admin_users_create(array $data): void {
    $user = auth_require_login();
    $isOfficeAdmin = $user['role'] === 'office_admin';
    if (!$isOfficeAdmin) auth_require_admin($user);
    if ($isOfficeAdmin && ($data['role'] ?? '') !== 'branch_admin') json_response(false, ['message' => 'مجاز نیستید'], 403);
    csrf_require_valid();

    $cfg = app_config();
    $minLen = (int)($cfg['security']['password_min_length'] ?? 8);
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < $minLen) {
        json_response(false, ['message' => "رمز عبور باید حداقل $minLen کاراکتر باشد."], 422);
    }
    
    $username = validate_username($data['username'] ?? '');
    if (!$username) json_response(false, ['message' => 'نام کاربری نامعتبر'], 422);
    
    // Check duplicate username
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) json_response(false, ['message' => 'نام کاربری تکراری است'], 409);
    
    $passHash = password_hash($data['password'], PASSWORD_DEFAULT);
    $role = $isOfficeAdmin ? 'branch_admin' : ($data['role'] ?? 'user');

    $cityCode = null;
    if ($isOfficeAdmin) {
        $cityCode = resolve_city_code_fk($user['city_code'] ?? null);
        if (!$cityCode) json_response(false, ['message' => 'کد اداره مدیر اداره معتبر نیست.'], 422);
    } else {
        $cityCode = resolve_city_code_fk($data['city_code'] ?? null);
    }
    
    db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)")
        ->execute([$username, $passHash, $data['first_name'], $data['last_name'], $data['mobile'], $role, $cityCode, $data['branch_count']??1, $data['branch_start_no']??1, now_mysql()]);
        
    $newId = db()->lastInsertId();
    
    if ($role === 'branch_admin' && !empty($data['branches'])) {
        $branches = $data['branches'];
        // if array of strings/ints
        user_branches_set($newId, $branches);
        
        // capacities
        if (!empty($data['branch_caps'])) {
            foreach ($data['branch_caps'] as $b => $cap) {
                db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)')
                    ->execute([$cityCode, $b, $cap]);
            }
        }
    }
    
    json_response(true, ['message' => 'کاربر ایجاد شد']);
}

function action_admin_users_update(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    csrf_require_valid();
    
    $id = (int)($data['id'] ?? 0);
    $target = db()->prepare("SELECT * FROM users WHERE id = ?");
    $target->execute([$id]);
    $u = $target->fetch();
    if (!$u) json_response(false, ['message' => 'کاربر یافت نشد'], 404);
    
    if ($user['role'] === 'office_admin') {
        $officeCity = resolve_city_code_fk($user['city_code'] ?? null) ?? ($user['city_code'] ?? '');
        $targetCity = resolve_city_code_fk($u['city_code'] ?? null) ?? ($u['city_code'] ?? '');
        if ($officeCity === '' || $targetCity === '' || $officeCity !== $targetCity) json_response(false, ['message' => 'دسترسی ندارید'], 403);
        if (($u['role'] ?? '') !== 'branch_admin') json_response(false, ['message' => 'مجاز نیستید'], 403);
    }
    
    $updates = []; $params = [];
    if (!empty($data['first_name'])) { $updates[] = "first_name=?"; $params[] = $data['first_name']; }
    if (!empty($data['last_name'])) { $updates[] = "last_name=?"; $params[] = $data['last_name']; }
    if (!empty($data['mobile'])) { $updates[] = "mobile=?"; $params[] = $data['mobile']; }
    if (!empty($data['password'])) { 
        $cfg = app_config();
        $minLen = (int)($cfg['security']['password_min_length'] ?? 8);
        if (strlen((string)$data['password']) < $minLen) {
            json_response(false, ['message' => "رمز عبور باید حداقل $minLen کاراکتر باشد."], 422);
        }
        $updates[] = "password_hash=?"; 
        $params[] = password_hash($data['password'], PASSWORD_DEFAULT); 
    }
    if (isset($data['is_active'])) { $updates[] = "is_active=?"; $params[] = (int)$data['is_active']; }
    if (isset($data['branch_count'])) { $updates[] = "branch_count=?"; $params[] = (int)$data['branch_count']; }
    
    if ($updates) {
        $params[] = $id;
        db()->prepare("UPDATE users SET " . implode(',', $updates) . " WHERE id=?")->execute($params);
    }
    
    if (($u['role'] === 'branch_admin' || ($data['role']??'') === 'branch_admin') && isset($data['branches'])) {
        $branches = [];
        $caps = [];
        foreach ($data['branches'] as $item) {
            if (is_array($item)) {
                $branches[] = $item['branch'];
                if (isset($item['capacity'])) $caps[$item['branch']] = $item['capacity'];
            } else {
                $branches[] = $item;
            }
        }
        user_branches_set($id, $branches);
        foreach ($caps as $b => $cap) {
             db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)')
                    ->execute([$u['city_code'], $b, $cap]);
        }
    }
    
    json_response(true, ['message' => 'ویرایش شد']);
}

function action_kelaseh_edit(array $data): void {
    $user = auth_require_login();
    csrf_require_valid();
    
    $code = (string)($data['code'] ?? '');
    if (!$code) json_response(false, ['message' => 'کد پرونده الزامی است.']);
    
    $pName = trim((string)($data['plaintiff_name'] ?? ''));
    $dName = trim((string)($data['defendant_name'] ?? ''));
    $pNC = validate_national_code($data['plaintiff_national_code'] ?? null);
    $dNC = validate_national_code($data['defendant_national_code'] ?? null);
    $pMob = validate_ir_mobile($data['plaintiff_mobile'] ?? null);
    $dMob = validate_ir_mobile($data['defendant_mobile'] ?? null);
    $pAddress = trim((string)($data['plaintiff_address'] ?? ''));
    $pPostal = trim((string)($data['plaintiff_postal_code'] ?? ''));
    $dAddress = trim((string)($data['defendant_address'] ?? ''));
    $dPostal = trim((string)($data['defendant_postal_code'] ?? ''));
    $dadnameh = trim((string)($data['dadnameh'] ?? ''));
    $repGovt = trim((string)($data['representatives_govt'] ?? ''));
    $repWorker = trim((string)($data['representatives_worker'] ?? ''));
    $repEmployer = trim((string)($data['representatives_employer'] ?? ''));
    $pRequest = trim((string)($data['plaintiff_request'] ?? ''));
    $vText = trim((string)($data['verdict_text'] ?? ''));
    
    if (!$dNC || !$dMob) json_response(false, ['message' => 'اطلاعات خوانده نامعتبر است.']);
    
    $sql = "UPDATE kelaseh_numbers SET 
            plaintiff_name = ?, 
            plaintiff_national_code = ?, 
            plaintiff_mobile = ?, 
            plaintiff_address = ?,
            plaintiff_postal_code = ?,
            defendant_name = ?, 
            defendant_national_code = ?, 
            defendant_mobile = ?,
            defendant_address = ?,
            defendant_postal_code = ?,
            dadnameh = ?,
            representatives_govt = ?,
            representatives_worker = ?,
            representatives_employer = ?,
            plaintiff_request = ?,
            verdict_text = ?,
            updated_at = ?
            WHERE code = ?";
            
    $params = [$pName, $pNC, $pMob, $pAddress, $pPostal, $dName, $dNC, $dMob, $dAddress, $dPostal, $dadnameh, $repGovt, $repWorker, $repEmployer, $pRequest, $vText, now_mysql(), $code];
    
    // Check ownership if not admin
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
        $sql .= " AND owner_id = ?";
        $params[] = $user['id'];
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() === 0) {
        json_response(false, ['message' => 'پرونده یافت نشد یا شما اجازه ویرایش ندارید.']);
    }
    
    json_response(true, ['message' => 'پرونده با موفقیت ویرایش شد.']);
}

function action_admin_users_delete(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    csrf_require_valid();
    $id = (int)$data['id'];
    if ($id == $user['id']) json_response(false, ['message' => 'حذف خود ممکن نیست'], 422);
    if ($user['role'] === 'office_admin') {
        $target = db()->prepare('SELECT id, role, city_code FROM users WHERE id = ?');
        $target->execute([$id]);
        $u = $target->fetch();
        if (!$u) json_response(false, ['message' => 'کاربر یافت نشد'], 404);
        $officeCity = resolve_city_code_fk($user['city_code'] ?? null) ?? ($user['city_code'] ?? '');
        $targetCity = resolve_city_code_fk($u['city_code'] ?? null) ?? ($u['city_code'] ?? '');
        if ($officeCity === '' || $targetCity === '' || $officeCity !== $targetCity) json_response(false, ['message' => 'دسترسی ندارید'], 403);
        if (($u['role'] ?? '') !== 'branch_admin') json_response(false, ['message' => 'مجاز نیستید'], 403);
    }
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    json_response(true, ['message' => 'حذف شد']);
}

function action_admin_test_branch_admin_flow_run(array $data): void
{
    $admin = auth_require_login();
    auth_require_admin($admin);
    csrf_require_valid();
    admin_test_require_enabled();

    ensure_user_branches_table();

    $cityCode = null;
    $cityName = 'اداره تست';
    for ($i = 0; $i < 200; $i++) {
        $candidate = sprintf('%02d', random_int(10, 99));
        $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            $cityCode = $candidate;
            break;
        }
    }
    if (!$cityCode) json_response(false, ['message' => 'امکان ساخت اداره تست وجود ندارد.'], 500);

    $username = 'test_branch_' . random_int(10000, 99999);
    $passwordPlain = 'TestPass_' . random_int(10000, 99999);
    $branches = [1, 2, 3];
    $codes = [];
    $userId = null;
    $exportPath = null;
    $exportToken = null;
    $branchCounts = [1 => 0, 2 => 0, 3 => 0];

    $generateNc = function (): string {
        while (true) {
            $n9 = str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            if (preg_match('/^(\d)\1{8}$/', $n9)) continue;
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int)$n9[$i]) * (10 - $i);
            }
            $rem = $sum % 11;
            $c = ($rem < 2) ? $rem : (11 - $rem);
            return $n9 . (string)$c;
        }
    };

    $fetchUser = function (int $id): ?array {
        $stmt = db()->prepare('SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.mobile, u.role, u.is_active, u.city_code, u.branch_count, u.branch_start_no, c.name as city_name, CONCAT(u.first_name, " ", u.last_name) as display_name
            FROM users u
            LEFT JOIN isfahan_cities c ON (c.code = u.city_code OR c.code = LPAD(u.city_code, 4, "0"))
            WHERE u.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) $u['branches'] = user_branches_get((int)$u['id']);
        return $u ?: null;
    };

    try {
        $step = 'create_city';
        db()->prepare('INSERT INTO isfahan_cities (code, name) VALUES (?, ?)')->execute([$cityCode, $cityName]);

        $step = 'create_user';
        $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
        db()->prepare("INSERT INTO users (username, password_hash, first_name, last_name, mobile, role, city_code, is_active, branch_count, branch_start_no, created_at)
            VALUES (?, ?, ?, ?, ?, 'branch_admin', ?, 1, 1, 1, ?)")
            ->execute([$username, $hash, 'تست', 'مدیر شعبه', '0912' . random_int(1000000, 9999999), $cityCode, now_mysql()]);
        $userId = (int)db()->lastInsertId();

        $step = 'set_user_branches';
        db()->prepare('INSERT INTO user_branches (user_id, branch_no) VALUES (?, ?), (?, ?), (?, ?)')
            ->execute([$userId, 1, $userId, 2, $userId, 3]);

        $step = 'set_capacities';
        foreach ($branches as $b) {
            db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)')
                ->execute([$cityCode, $b, 10]);
        }

        $step = 'fetch_user';
        $testUser = $fetchUser((int)$userId);
        if (!$testUser) throw new RuntimeException('test user missing');

        $step = 'create_30';
        for ($i = 1; $i <= 30; $i++) {
            $payload = [
                'plaintiff_name' => 'خواهان تست ' . $i,
                'plaintiff_national_code' => $generateNc(),
                'plaintiff_mobile' => '09' . random_int(100000000, 999999999),
                'defendant_name' => '',
                'defendant_national_code' => '',
                'defendant_mobile' => '',
            ];
            $res = kelaseh_create_internal($testUser, $payload);
            $code = (string)($res['code'] ?? '');
            if ($code === '') throw new RuntimeException('missing code at #' . $i);
            $codes[] = $code;
            $bn = (int)($res['branch_no'] ?? 0);
            if (!in_array($bn, $branches, true)) throw new RuntimeException('unexpected branch_no at #' . $i);
            $branchCounts[$bn] = ($branchCounts[$bn] ?? 0) + 1;
        }

        if (($branchCounts[1] ?? 0) !== 10 || ($branchCounts[2] ?? 0) !== 10 || ($branchCounts[3] ?? 0) !== 10) {
            throw new RuntimeException('branch distribution mismatch');
        }

        $step = 'export_csv';
        $rows = kelaseh_fetch_rows($testUser, ['national_code' => '', 'from' => null, 'to' => null, 'q' => '', 'owner_id' => 0, 'city_code' => null], 2000);
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['کلاسه', 'اداره', 'شعبه', 'ردیف', 'کد ملی خواهان', 'کد ملی خوانده', 'نام خواهان', 'نام خوانده', 'وضعیت', 'تاریخ ثبت']);
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $statusFa = $status === 'voided' ? 'ابطال' : ($status === 'inactive' ? 'غیرفعال' : 'فعال');
            fputcsv($fp, [
                $r['full_code'] ?? ($r['code'] ?? ''),
                $r['city_name'] ?? ($r['city_code'] ?? ''),
                $r['branch_no'] ?? '',
                $r['seq_no'] ?? '',
                $r['plaintiff_national_code'] ?? '',
                $r['defendant_national_code'] ?? '',
                $r['plaintiff_name'] ?? '',
                $r['defendant_name'] ?? '',
                $statusFa,
                $r['created_at_jalali'] ?? ($r['created_at'] ?? ''),
            ]);
        }
        rewind($fp);
        $body = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($body) || !str_contains($body, 'کلاسه')) throw new RuntimeException('export.csv missing header');
        foreach (array_slice($codes, 0, 5) as $sample) {
            if (!str_contains($body, $sample)) throw new RuntimeException('export.csv missing sample');
        }

        $outDir = __DIR__ . '/tests/output';
        if (!is_dir($outDir)) @mkdir($outDir, 0777, true);
        $name = 'branch_admin_export_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $exportPath = $outDir . '/' . $name;
        file_put_contents($exportPath, $body);

        $exportToken = bin2hex(random_bytes(16));
        if (empty($_SESSION['test_exports']) || !is_array($_SESSION['test_exports'])) $_SESSION['test_exports'] = [];
        $_SESSION['test_exports'][$exportToken] = ['path' => $exportPath, 'name' => $name, 'expires_at' => time() + 600];

        json_response(true, [
            'message' => 'تست با موفقیت انجام شد و داده‌ها پاکسازی شدند.',
            'data' => [
                'download_url' => 'core.php?action=admin.test.download&token=' . $exportToken . '&csrf_token=' . csrf_token(),
                'branch_counts' => $branchCounts,
                'expires_in' => 600,
            ],
        ]);
    } catch (Throwable $e) {
        $msg = 'خطا در اجرای تست.';
        if (tests_enabled() || is_debug()) {
            $msg = 'خطای تست' . (isset($step) ? (' (مرحله: ' . $step . ')') : '') . ': ' . $e->getMessage();
        }
        json_response(false, ['message' => $msg], 500);
    } finally {
        try {
            if (!empty($codes) && $userId) {
                $in = implode(',', array_fill(0, count($codes), '?'));
                $params = $codes;
                array_unshift($params, $userId);
                db()->prepare("DELETE FROM kelaseh_numbers WHERE owner_id = ? AND code IN ($in)")->execute($params);
            }

            $j = jalali_today_parts();
            $jalaliYmd = $j['jalali_ymd'] ?? null;
            if ($jalaliYmd && $cityCode) {
                foreach (['kelaseh_daily_counters', 'kelaseh_daily_counters_v2'] as $t) {
                    try {
                        db()->prepare("DELETE FROM {$t} WHERE city_code = ? AND jalali_ymd = ? AND branch_no IN (1,2,3)")->execute([$cityCode, $jalaliYmd]);
                    } catch (Throwable $e) {
                    }
                }
            }

            if ($userId) db()->prepare('DELETE FROM user_branches WHERE user_id = ?')->execute([$userId]);
            if ($cityCode) db()->prepare('DELETE FROM office_branch_capacities WHERE city_code = ? AND branch_no IN (1,2,3)')->execute([$cityCode]);
            if ($userId) db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            if ($cityCode) db()->prepare('DELETE FROM isfahan_cities WHERE code = ?')->execute([$cityCode]);
        } catch (Throwable $cleanupErr) {
        }

    }
}

function action_admin_test_download(): void
{
    $admin = auth_require_login();
    auth_require_admin($admin);
    csrf_require_valid();
    admin_test_require_enabled();

    $token = (string)($_GET['token'] ?? '');
    if ($token === '' || empty($_SESSION['test_exports']) || !is_array($_SESSION['test_exports']) || empty($_SESSION['test_exports'][$token])) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $meta = $_SESSION['test_exports'][$token];
    $path = (string)($meta['path'] ?? '');
    $name = (string)($meta['name'] ?? 'export.csv');
    $expires = (int)($meta['expires_at'] ?? 0);
    if ($expires > 0 && time() > $expires) {
        unset($_SESSION['test_exports'][$token]);
        if ($path && is_file($path)) @unlink($path);
        http_response_code(410);
        echo 'Expired';
        exit;
    }

    $real = $path ? realpath($path) : false;
    $allowedDir = realpath(__DIR__ . '/tests/output');
    if (!$real || !$allowedDir || strncmp($real, $allowedDir, strlen($allowedDir)) !== 0 || !is_file($real)) {
        unset($_SESSION['test_exports'][$token]);
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($name) . '"');
    header('Content-Length: ' . filesize($real));
    readfile($real);
    unset($_SESSION['test_exports'][$token]);
    @unlink($real);
    exit;
}

function action_admin_cities_list(): void {
    auth_require_admin(auth_require_login());
    $rows = db()->query("SELECT * FROM isfahan_cities")->fetchAll();
    json_response(true, ['data' => ['cities' => $rows]]);
}

function action_admin_cities_create(array $data): void {
    auth_require_admin(auth_require_login());
    csrf_require_valid();

    $code = trim(to_english_digits((string)($data['code'] ?? '')));
    $resolved = resolve_city_code_fk($code);
    if ($resolved !== null) {
        json_response(false, ['message' => 'این کد اداره قبلاً ثبت شده است.'], 409);
    }
    if (!preg_match('/^[0-9]{1,10}$/', $code)) json_response(false, ['message' => 'کد اداره نامعتبر است.'], 422);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') json_response(false, ['message' => 'نام اداره الزامی است.'], 422);
    $address = trim((string)($data['address'] ?? ''));
    $postal = trim((string)($data['postal_code'] ?? ''));
    
    $stmt = db()->prepare("SELECT code FROM isfahan_cities WHERE code = ?");
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
         json_response(false, ['message' => 'این کد اداره قبلاً ثبت شده است.'], 409);
    }
    
    try {
        db()->prepare("INSERT INTO isfahan_cities (code, name, address, postal_code) VALUES (?, ?, ?, ?)")->execute([$code, $name, $address, $postal]);
    } catch (Throwable $e) {
        json_response(false, ['message' => 'خطا در ثبت اداره: ' . $e->getMessage()], 500);
    }
    json_response(true, ['message' => 'اداره ایجاد شد']);
}

function action_admin_cities_update(array $data): void {
    auth_require_admin(auth_require_login());
    csrf_require_valid();

    $oldCodeRaw = $data['code'] ?? null;
    $newCode = trim(to_english_digits((string)($data['new_code'] ?? '')));
    if (!preg_match('/^[0-9]{1,10}$/', $newCode)) json_response(false, ['message' => 'کد اداره نامعتبر است.'], 422);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') json_response(false, ['message' => 'نام اداره الزامی است.'], 422);
    $address = trim((string)($data['address'] ?? ''));
    $postal = trim((string)($data['postal_code'] ?? ''));
    
    // Check if new code exists and is not the same as old code
    $oldNormalized = trim(to_english_digits((string)$oldCodeRaw));
    if ($oldNormalized !== $newCode) {
        $stmt = db()->prepare("SELECT code FROM isfahan_cities WHERE code = ?");
        $stmt->execute([$newCode]);
        if ($stmt->fetch()) json_response(false, ['message' => 'این کد اداره قبلاً ثبت شده است.'], 409);
        $resolved = resolve_city_code_fk($newCode);
        if ($resolved !== null) json_response(false, ['message' => 'این کد اداره قبلاً ثبت شده است.'], 409);
    }

    try {
        $stmt = db()->prepare("UPDATE isfahan_cities SET code=?, name=?, address=?, postal_code=? WHERE code=?");
        $stmt->execute([$newCode, $name, $address, $postal, (string)$oldCodeRaw]);
        if ($stmt->rowCount() < 1) {
            if ($oldNormalized && $oldNormalized !== (string)$oldCodeRaw) {
                $stmt2 = db()->prepare("UPDATE isfahan_cities SET code=?, name=?, address=?, postal_code=? WHERE code=?");
                $stmt2->execute([$newCode, $name, $address, $postal, $oldNormalized]);
            }
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry'))) {
            json_response(false, ['message' => 'این کد اداره قبلاً ثبت شده است.'], 409);
        }
        json_response(false, ['message' => 'خطای سیستم: ' . $e->getMessage()], 500);
    }
    json_response(true, ['message' => 'اداره ویرایش شد']);
}

function action_admin_cities_delete(array $data): void {
    auth_require_admin(auth_require_login());
    csrf_require_valid();
    $code = normalize_city_code($data['code'] ?? null) ?? (string)($data['code'] ?? '');
    if ($code === '') json_response(false, ['message' => 'کد اداره نامعتبر است.'], 422);
    try {
        db()->prepare("DELETE FROM isfahan_cities WHERE code=?")->execute([$code]);
    } catch (Throwable $e) {
        if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'foreign key constraint'))) {
            json_response(false, ['message' => 'این اداره دارای وابستگی (کاربر/داده) است و قابل حذف نیست.'], 409);
        }
        json_response(false, ['message' => is_debug() ? ('خطای سیستم: ' . $e->getMessage()) : 'خطای سیستم'], 500);
    }
    json_response(true, ['message' => 'اداره حذف شد']);
}

function action_admin_audit_list(): void {
    auth_require_admin(auth_require_login());
    
    // Clean up any zero dates that might cause display issues
    db()->exec("UPDATE audit_logs SET created_at = NOW() WHERE created_at = '0000-00-00 00:00:00' OR created_at IS NULL");
    db()->exec("UPDATE users SET last_login_at = NOW() WHERE last_login_at = '0000-00-00 00:00:00'");

    $logs = db()->query("SELECT a.*, u.username as actor_key FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 100")->fetchAll();
    foreach($logs as &$l) {
        $dt = $l['created_at'] ?? '';
        if ($dt === '0000-00-00 00:00:00') $dt = '';
        $l['created_at_jalali'] = format_jalali_datetime($dt);
    }
    json_response(true, ['data' => ['logs' => $logs]]);
}

function handle_request(): void
{
    $action = (string)($_REQUEST['action'] ?? '');
    $data = array_merge($_GET, request_data());

    try {
        switch ($action) {
            case 'session': action_session(); break;
            case 'time.now': action_time_now(); break;
            case 'login': action_login($data); break;
            case 'login.otp.verify': action_login_otp_verify($data); break;
            case 'logout': action_logout(); break;

            case 'kelaseh.list': action_kelaseh_list($data); break;
            case 'kelaseh.list.today': action_kelaseh_list_today($data); break;
            case 'kelaseh.create': action_kelaseh_create($data); break;
            case 'kelaseh.history.check': action_kelaseh_history_check($data); break;
            case 'kelaseh.update': action_kelaseh_update($data); break;
            case 'kelaseh.set_status': action_kelaseh_set_status($data); break;
            case 'kelaseh.sms.send': action_kelaseh_sms_send($data); break;

            case 'kelaseh.label': 
                action_kelaseh_label($data); 
                break;
            case 'kelaseh.notice': 
                action_kelaseh_notice($data); 
                break;
            case 'kelaseh.print': 
                action_kelaseh_print($data); 
                break;
            case 'kelaseh.print.minutes': 
                action_kelaseh_print_minutes($data); 
                break;
            case 'kelaseh.notice2':
                action_kelaseh_notice2($data);
                break;
            case 'kelaseh.export.csv': action_kelaseh_export_csv($data); break;
            case 'kelaseh.export.print': action_kelaseh_export_print($data); break;
            case 'kelaseh.label.new': action_kelaseh_label_new($data); break;
            
            case 'kelaseh.search.by_nc': action_kelaseh_search_by_nc($data); break;
            case 'kelaseh.get': action_kelaseh_get_by_code($data); break;
            case 'kelaseh.get.by_code': action_kelaseh_get_by_code($data); break;
            case 'heyat.tashkhis.save': action_heyat_tashkhis_save($data); break;

            case 'office.capacities.get': action_office_capacities_get($data); break;
            case 'office.capacities.update': action_office_capacities_update($data); break;
            case 'office.stats': action_office_stats(); break;

            // Admin / Office Admin
            case 'admin.users.list': action_admin_users_list($data); break;
            case 'admin.users.create': action_admin_users_create($data); break;
            case 'admin.users.update': action_admin_users_update($data); break;
            case 'admin.users.delete': action_admin_users_delete($data); break;
            case 'admin.test.branch_admin_flow.run': action_admin_test_branch_admin_flow_run($data); break;
            case 'admin.test.download': action_admin_test_download(); break;

            case 'admin.cities.list': action_admin_cities_list(); break;
            case 'admin.cities.create': action_admin_cities_create($data); break;
            case 'admin.cities.update': action_admin_cities_update($data); break;
            case 'admin.cities.delete': action_admin_cities_delete($data); break;

            case 'admin.audit.list': action_admin_audit_list(); break;
            case 'admin.items.list': action_admin_items_list($data); break;
            case 'admin.items.delete': action_admin_items_delete($data); break;
            case 'admin.kelaseh.stats': action_admin_kelaseh_stats($data); break;
            case 'admin.kelaseh.backfill_new_case_code': action_admin_kelaseh_backfill_new_case_code(); break;
            case 'admin.sms.settings.get': action_admin_sms_settings_get(); break;
            case 'admin.sms.settings.set': action_admin_sms_settings_set($data); break;
            case 'admin.detailed.stats': action_admin_detailed_stats(); break;
            case 'admin.kelaseh.search': action_admin_kelaseh_search($data); break;

            default:
                json_response(false, ['message' => 'اکشن نامعتبر یا پیاده‌سازی نشده.'], 404);
        }
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => is_debug() ? ('خطای سیستم: ' . $e->getMessage()) : 'خطای سیستم']);
    }
}

if (!defined('KELASEH_LIB_ONLY')) {
    handle_request();
}
