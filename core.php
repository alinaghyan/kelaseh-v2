<?php

declare(strict_types=1);

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/php-error.log');

// [BOOTSTRAP] بارگذاری تنظیمات و Composer
function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $loaded = is_file($configPath) ? require $configPath : [];
    $config = is_array($loaded) ? $loaded : [];

    $autoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    $tz = $config['app']['timezone'] ?? 'Asia/Tehran';
    date_default_timezone_set((string) $tz);

    return $config;
}

// [SESSION] آغاز session با تنظیمات امن
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = app_config();

    $name = (string)($cfg['app']['session_name'] ?? 'app_session');
    session_name($name);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// [DB] اتصال PDO به MySQL
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config();
    $db = $cfg['db'] ?? [];

    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '+03:30'");

    return $pdo;
}

function ensure_app_settings_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS `app_settings` ( `k` VARCHAR(80) NOT NULL, `v` TEXT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`k`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");
}

function get_app_setting(string $key, ?string $default = null): ?string
{
    try {
        ensure_app_settings_table();
        $stmt = db()->prepare('SELECT v FROM app_settings WHERE k = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['v'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function set_app_setting(string $key, ?string $value): void
{
    ensure_app_settings_table();
    $now = now_mysql();
    db()->prepare('INSERT INTO app_settings (k,v,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)')
        ->execute([$key, $value, $now]);
}

function render_sms_template(string $template, array $vars): string
{
    $map = [];
    foreach ($vars as $k => $v) {
        $map['{' . $k . '}'] = (string)$v;
    }
    return strtr($template, $map);
}

function kavenegar_send(string $apiKey, string $receptor, string $message, ?string $sender = null): array
{
    $url = 'https://api.kavenegar.com/v1/' . rawurlencode($apiKey) . '/sms/send.json';
    $fields = [
        'receptor' => $receptor,
        'message' => $message,
    ];
    if ($sender !== null && trim($sender) !== '') {
        $fields['sender'] = trim($sender);
    }

    $body = http_build_query($fields);
    $raw = null;
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => $status, 'error' => $err];
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'error' => 'پاسخ نامعتبر از سرویس پیامک'];
    }

    $ret = $decoded['return']['status'] ?? null;
    if ((int)$ret === 200) {
        return ['ok' => true, 'status' => 200, 'data' => $decoded];
    }
    $msg = $decoded['return']['message'] ?? 'خطا در ارسال پیامک';
    return ['ok' => false, 'status' => (int)$ret, 'error' => (string)$msg, 'data' => $decoded];
}

// [RESPONSE] خروجی JSON استاندارد
function json_response(bool $ok, array $payload = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $out = ['ok' => $ok] + $payload;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// [INPUT] دریافت داده‌های ورودی (POST یا JSON)
function request_data(): array
{
    $data = $_POST;

    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    return is_array($data) ? $data : [];
}

// [CSRF] ساخت یا دریافت توکن CSRF
function csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

// [CSRF] اعتبارسنجی توکن CSRF
function csrf_require_valid(): void
{
    app_session_start();
    $cfg = app_config();
    $headerName = (string)($cfg['app']['csrf_header'] ?? 'X-CSRF-Token');

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    $headerValue = (string)($_SERVER[$serverKey] ?? '');
    $posted = (string)($_REQUEST['csrf_token'] ?? '');
    $token = $headerValue !== '' ? $headerValue : $posted;

    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        json_response(false, ['message' => 'درخواست نامعتبر است.'], 403);
    }
}

// [AUTH] دریافت شناسه کاربر فعلی
function current_user_id(): ?int
{
    app_session_start();
    $id = $_SESSION['user_id'] ?? null;
    if ($id === null) {
        return null;
    }
    $intId = filter_var($id, FILTER_VALIDATE_INT);
    return $intId === false ? null : (int)$intId;
}

// [AUTH] دریافت اطلاعات کاربر فعلی از دیتابیس
function current_user(): ?array
{
    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT u.id,u.email,u.username,u.role,u.display_name,u.first_name,u.last_name,u.mobile,u.national_code,u.city_code,c.name AS city_name,u.branch_count,u.branch_start_no,u.is_active,u.created_at,u.last_login_at FROM users u LEFT JOIN isfahan_cities c ON c.code = u.city_code WHERE u.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['is_active'] !== 1) {
        return null;
    }
    return $user;
}

// [AUTH] الزام ورود
function auth_require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(false, ['message' => 'ابتدا وارد شوید.'], 401);
    }
    return $user;
}

// [AUTH] الزام نقش مدیر کل
function auth_require_admin(array $user): void
{
    if (($user['role'] ?? 'user') !== 'admin') {
        json_response(false, ['message' => 'دسترسی غیرمجاز.'], 403);
    }
}

// [VALIDATION] اعتبارسنجی ایمیل
function validate_email(string $email): ?string
{
    $email = trim($email);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

// [VALIDATION] اعتبارسنجی رمز
function validate_password(string $password): bool
{
    $cfg = app_config();
    $min = (int)($cfg['security']['password_min_length'] ?? 8);
    return mb_strlen($password) >= $min;
}

// [VALIDATION] اعتبارسنجی نام کاربری
function validate_username(string $username): ?string
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
        return null;
    }
    if (preg_match('/^[a-zA-Z0-9_.-]+$/', $username) !== 1) {
        return null;
    }
    return $username;
}

// [VALIDATION] اعتبارسنجی تعداد شعبه
function validate_branch_count($value): ?int
{
    $n = filter_var($value, FILTER_VALIDATE_INT);
    if ($n === false) {
        return null;
    }
    $n = (int)$n;
    return ($n >= 1 && $n <= 9) ? $n : null;
}

function validate_branch_start_no($value): ?int
{
    $n = filter_var($value, FILTER_VALIDATE_INT);
    if ($n === false) {
        return null;
    }
    $n = (int)$n;
    return ($n >= 1 && $n <= 9) ? $n : null;
}

// [VALIDATION] اعتبارسنجی کد شهر (استان اصفهان)
function validate_isfahan_city_code(?string $code): ?string
{
    if ($code === null) {
        return null;
    }
    $code = trim($code);
    if (preg_match('/^\d{2}$/', $code) !== 1) {
        return null;
    }

    $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    return $stmt->fetch() ? $code : null;
}

// [VALIDATION] اعتبارسنجی شماره موبایل ایرانی (با کتابخانه و fallback)
function validate_ir_mobile(?string $mobile): ?string
{
    if ($mobile === null) {
        return null;
    }
    $mobile = preg_replace('/\s+/', '', $mobile);
    if ($mobile === null) {
        return null;
    }
    $mobile = trim($mobile);

    $candidates = [
        'PersianValidator\\Mobile\\Mobile',
        'PersianValidator\\Mobile',
        'Mobile',
    ];

    foreach ($candidates as $fqcn) {
        if (class_exists($fqcn) && method_exists($fqcn, 'make')) {
            $obj = $fqcn::make($mobile);
            if (is_object($obj) && method_exists($obj, 'isValid') && $obj->isValid()) {
                return $mobile;
            }
            return null;
        }
    }

    if (preg_match('/^(\+98|0)?9\d{9}$/', $mobile) !== 1) {
        return null;
    }
    if (strlen($mobile) === 10) {
        return '0' . $mobile;
    }
    if (str_starts_with($mobile, '+98')) {
        return '0' . substr($mobile, 3);
    }
    return $mobile;
}

// [VALIDATION] نرمال‌سازی کدملی/شناسه ملی (بدون اعتبارسنجی)
function validate_national_code(?string $code): ?string
{
    if ($code === null) {
        return null;
    }
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $code);
    if (!is_string($digits) || $digits === '') {
        return null;
    }
    if (strlen($digits) > 20) {
        $digits = substr($digits, 0, 20);
    }
    return $digits;
}

// [UTIL] گرفتن زمان حال به‌صورت DATETIME سازگار با MySQL
function now_mysql(): string
{
    return date('Y-m-d H:i:s');
}

// [UTIL] تبدیل تاریخ میلادی به نمایش جلالی
function format_jalali_datetime(string $mysqlDatetime): string
{
    $ts = strtotime($mysqlDatetime);
    if ($ts === false) {
        return $mysqlDatetime;
    }

    if (class_exists('Morilog\\Jalali\\Jalalian')) {
        $j = \Morilog\Jalali\Jalalian::fromDateTime(date('Y-m-d H:i:s', $ts));
        return $j->format('Y/m/d H:i');
    }

    return date('Y/m/d H:i', $ts);
}

function jalali_now_string(): string
{
    if (class_exists('Morilog\\Jalali\\Jalalian')) {
        $j = \Morilog\Jalali\Jalalian::now(new DateTimeZone('Asia/Tehran'));
        return $j->format('Y/m/d H:i');
    }

    $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $gy = (int)$dt->format('Y');
    $gm = (int)$dt->format('m');
    $gd = (int)$dt->format('d');
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    $h = $dt->format('H');
    $i = $dt->format('i');
    return sprintf('%04d/%02d/%02d %s:%s', $jy, $jm, $jd, $h, $i);
}

function jalali_now_parts(): array
{
    if (class_exists('Morilog\\Jalali\\Jalalian')) {
        $j = \Morilog\Jalali\Jalalian::now(new DateTimeZone('Asia/Tehran'));
        $y = (int)$j->format('Y');
        $m = (int)$j->format('m');
        $d = (int)$j->format('d');
        return ['y' => $y, 'm' => $m, 'd' => $d];
    }

    $dt = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $gy = (int)$dt->format('Y');
    $gm = (int)$dt->format('m');
    $gd = (int)$dt->format('d');
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    return ['y' => $jy, 'm' => $jm, 'd' => $jd];
}

function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gy - 1600;
    $gm2 = $gm - 1;
    $gd2 = $gd - 1;
    $g_day_no = 365 * $gy2 + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400);
    $g_day_no += $g_d_m[$gm2] + $gd2;
    if ($gm2 > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0))) {
        $g_day_no++;
    }
    $j_day_no = $g_day_no - 79;
    $j_np = (int)($j_day_no / 12053);
    $j_day_no = $j_day_no % 12053;
    $jy = 979 + 33 * $j_np + 4 * (int)($j_day_no / 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    if ($j_day_no < 186) {
        $jm = 1 + (int)($j_day_no / 31);
        $jd = 1 + ($j_day_no % 31);
    } else {
        $jm = 7 + (int)(($j_day_no - 186) / 30);
        $jd = 1 + (($j_day_no - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function parse_jalali_full_ymd(?string $input): ?string
{
    if ($input === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $input);
    if (!is_string($digits)) {
        return null;
    }
    $digits = trim($digits);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 8) {
        return $digits;
    }
    if (strlen($digits) === 6) {
        return '14' . $digits;
    }
    return null;
}

// [AUDIT] ثبت لاگ فعالیت
function audit_log(?int $actorId, string $action, string $entity, ?int $entityId = null, ?int $targetUserId = null): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, entity, entity_id, target_user_id, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $actorId,
        $action,
        $entity,
        $entityId,
        $targetUserId,
        (string)($_SERVER['REMOTE_ADDR'] ?? null),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? null), 0, 255),
        now_mysql(),
    ]);
}

// [SETUP] ساخت مدیر کل اولیه از config (فقط وقتی جدول users خالی است)
function bootstrap_admin_if_needed(): void
{
    $cfg = app_config();
    $b = $cfg['bootstrap_admin'] ?? [];
    if (!is_array($b) || empty($b['enabled'])) {
        return;
    }

    $count = (int)db()->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
    if ($count > 0) {
        return;
    }

    $email = validate_email((string)($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    if ($email === null || !validate_password($password)) {
        return;
    }

    $defaultUsername = strtolower((string)preg_replace('/[^a-zA-Z0-9_.-]+/', '', (string)strstr($email, '@', true)));
    if ($defaultUsername === '') {
        $defaultUsername = 'admin';
    }
    $username = validate_username((string)($b['username'] ?? $defaultUsername));
    if ($username === null) {
        $username = 'admin';
    }

    $displayName = trim((string)($b['display_name'] ?? 'مدیر کل'));
    $mobile = validate_ir_mobile(isset($b['mobile']) ? (string)$b['mobile'] : null);
    $nationalCode = validate_national_code(isset($b['national_code']) ? (string)$b['national_code'] : null);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (email,username,password_hash,role,display_name,first_name,last_name,mobile,national_code,city_code,branch_count,is_active,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $email,
        $username,
        $hash,
        'admin',
        $displayName,
        null,
        null,
        $mobile,
        $nationalCode,
        null,
        null,
        1,
        now_mysql(),
        null,
    ]);
}

// [HANDLER] ثبت‌نام کاربر
function action_register(array $data): void
{
    csrf_require_valid();
    bootstrap_admin_if_needed();

    $email = validate_email((string)($data['email'] ?? ''));
    $username = validate_username((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $displayName = trim((string)($data['display_name'] ?? ''));
    $mobile = validate_ir_mobile(isset($data['mobile']) ? (string)$data['mobile'] : null);
    $nationalCode = validate_national_code(isset($data['national_code']) ? (string)$data['national_code'] : null);

    if ($email === null) {
        json_response(false, ['message' => 'ایمیل نامعتبر است.'], 422);
    }
    if ($username === null) {
        $defaultUsername = strtolower((string)preg_replace('/[^a-zA-Z0-9_.-]+/', '', (string)strstr($email, '@', true)));
        $username = validate_username($defaultUsername);
    }
    if ($username === null) {
        json_response(false, ['message' => 'نام کاربری نامعتبر است.'], 422);
    }
    if (!validate_password($password)) {
        json_response(false, ['message' => 'رمز عبور کوتاه است.'], 422);
    }
    if ($displayName === '') {
        $displayName = 'کاربر';
    }
    if (isset($data['mobile']) && $data['mobile'] !== '' && $mobile === null) {
        json_response(false, ['message' => 'شماره موبایل نامعتبر است.'], 422);
    }
    if (isset($data['national_code']) && $data['national_code'] !== '' && $nationalCode === null) {
        json_response(false, ['message' => 'کد ملی نامعتبر است.'], 422);
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        json_response(false, ['message' => 'این کاربر قبلاً وجود دارد.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (email,username,password_hash,role,display_name,first_name,last_name,mobile,national_code,city_code,branch_count,is_active,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $email,
        $username,
        $hash,
        'user',
        $displayName,
        null,
        null,
        $mobile,
        $nationalCode,
        null,
        null,
        1,
        now_mysql(),
        null,
    ]);

    $userId = (int)db()->lastInsertId();
    audit_log($userId, 'register', 'user', $userId, $userId);

    app_session_start();
    $_SESSION['user_id'] = $userId;

    json_response(true, ['message' => 'ثبت‌نام انجام شد.', 'data' => ['csrf_token' => csrf_token(), 'user' => current_user()]]);
}

// [HANDLER] ورود
function action_login(array $data): void
{
    csrf_require_valid();
    bootstrap_admin_if_needed();

    $login = trim((string)($data['login'] ?? ($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    if ($login === '' || $password === '') {
        json_response(false, ['message' => 'نام کاربری/رمز نامعتبر است.'], 422);
    }

    app_session_start();
    $lastTry = (int)($_SESSION['last_login_try'] ?? 0);
    $cfg = app_config();
    $throttle = (int)($cfg['security']['login_throttle_seconds'] ?? 2);
    if ($lastTry > 0 && (time() - $lastTry) < $throttle) {
        json_response(false, ['message' => 'کمی بعد دوباره تلاش کنید.'], 429);
    }
    $_SESSION['last_login_try'] = time();

    if (str_contains($login, '@')) {
        $email = validate_email($login);
        if ($email === null) {
            json_response(false, ['message' => 'نام کاربری/رمز اشتباه است.'], 401);
        }
        $stmt = db()->prepare('SELECT id,password_hash,is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
    } else {
        $username = validate_username($login);
        if ($username === null) {
            json_response(false, ['message' => 'نام کاربری/رمز اشتباه است.'], 401);
        }
        $stmt = db()->prepare('SELECT id,password_hash,is_active FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
    }
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'نام کاربری/رمز اشتباه است.'], 401);
    }
    if ((int)$row['is_active'] !== 1) {
        json_response(false, ['message' => 'حساب شما غیرفعال است.'], 403);
    }
    if (!password_verify($password, (string)$row['password_hash'])) {
        json_response(false, ['message' => 'ایمیل/رمز اشتباه است.'], 401);
    }

    $_SESSION['user_id'] = (int)$row['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_mysql(), (int)$row['id']]);
    audit_log((int)$row['id'], 'login', 'user', (int)$row['id'], (int)$row['id']);

    json_response(true, ['message' => 'ورود انجام شد.', 'data' => ['csrf_token' => csrf_token(), 'user' => current_user()]]);
}

// [HANDLER] خروج
function action_logout(): void
{
    csrf_require_valid();
    $user = current_user();
    if ($user) {
        audit_log((int)$user['id'], 'logout', 'user', (int)$user['id'], (int)$user['id']);
    }
    app_session_start();
    $_SESSION = [];
    session_destroy();
    json_response(true, ['message' => 'خروج انجام شد.', 'data' => ['csrf_token' => csrf_token(), 'user' => null]]);
}

// [HANDLER] وضعیت نشست
function action_session(): void
{
    $user = null;
    try {
        bootstrap_admin_if_needed();
        $user = current_user();
    } catch (Throwable $e) {
        $user = null;
    }

    json_response(true, ['data' => ['csrf_token' => csrf_token(), 'user' => $user]]);
}

function action_time_now(): void
{
    csrf_require_valid();
    json_response(true, ['data' => ['now_jalali' => jalali_now_string()]]);
}

// [HANDLER] لیست آیتم‌ها
function action_items_list(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $q = trim((string)($data['q'] ?? ''));
    $params = [(int)$user['id']];
    $sql = 'SELECT id,title,content,created_at,updated_at FROM items WHERE owner_id = ?';
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR content LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    foreach ($items as &$it) {
        $it['created_at_jalali'] = format_jalali_datetime((string)$it['created_at']);
        $it['updated_at_jalali'] = format_jalali_datetime((string)$it['updated_at']);
    }
    unset($it);

    json_response(true, ['data' => ['items' => $items]]);
}

// [HANDLER] ایجاد آیتم
function action_items_create(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $title = trim((string)($data['title'] ?? ''));
    $content = trim((string)($data['content'] ?? ''));
    if ($title === '') {
        json_response(false, ['message' => 'عنوان الزامی است.'], 422);
    }

    $now = now_mysql();
    $stmt = db()->prepare('INSERT INTO items (owner_id,title,content,created_at,updated_at) VALUES (?,?,?,?,?)');
    $stmt->execute([(int)$user['id'], $title, $content, $now, $now]);
    $id = (int)db()->lastInsertId();
    audit_log((int)$user['id'], 'create', 'item', $id, null);

    json_response(true, ['message' => 'ثبت شد.', 'data' => ['id' => $id]]);
}

// [HANDLER] ویرایش آیتم
function action_items_update(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $id = (int)($data['id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    $content = trim((string)($data['content'] ?? ''));
    if ($id <= 0) {
        json_response(false, ['message' => 'شناسه نامعتبر است.'], 422);
    }
    if ($title === '') {
        json_response(false, ['message' => 'عنوان الزامی است.'], 422);
    }

    $stmt = db()->prepare('SELECT owner_id FROM items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }
    if ((int)$row['owner_id'] !== (int)$user['id']) {
        json_response(false, ['message' => 'دسترسی غیرمجاز.'], 403);
    }

    db()->prepare('UPDATE items SET title = ?, content = ?, updated_at = ? WHERE id = ?')->execute([$title, $content, now_mysql(), $id]);
    audit_log((int)$user['id'], 'update', 'item', $id, null);
    json_response(true, ['message' => 'به‌روزرسانی شد.']);
}

// [HANDLER] حذف آیتم
function action_items_delete(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(false, ['message' => 'شناسه نامعتبر است.'], 422);
    }

    $stmt = db()->prepare('SELECT owner_id FROM items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }
    if ((int)$row['owner_id'] !== (int)$user['id']) {
        json_response(false, ['message' => 'دسترسی غیرمجاز.'], 403);
    }

    db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
    audit_log((int)$user['id'], 'delete', 'item', $id, null);
    json_response(true, ['message' => 'حذف شد.']);
}

function action_kelaseh_list(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $national = trim((string)($data['national_code'] ?? ''));
    $fromRaw = trim((string)($data['from'] ?? ''));
    $toRaw = trim((string)($data['to'] ?? ''));
    $from = parse_jalali_full_ymd($fromRaw !== '' ? $fromRaw : null);
    $to = parse_jalali_full_ymd($toRaw !== '' ? $toRaw : null);
    if ($fromRaw !== '' && $from === null) {
        json_response(false, ['message' => 'تاریخ شروع نامعتبر است.'], 422);
    }
    if ($toRaw !== '' && $to === null) {
        json_response(false, ['message' => 'تاریخ پایان نامعتبر است.'], 422);
    }

    $params = [(int)$user['id']];
    $sql = 'SELECT code,branch_no,jalali_full_ymd,seq_no,plaintiff_name,defendant_name,plaintiff_national_code,defendant_national_code,plaintiff_mobile,defendant_mobile,status,created_at,updated_at FROM kelaseh_numbers WHERE owner_id = ?';

    if ($national !== '') {
        $nc = validate_national_code($national);
        if ($nc === null) {
            json_response(false, ['message' => 'کد ملی نامعتبر است.'], 422);
        }
        $sql .= ' AND (plaintiff_national_code = ? OR defendant_national_code = ?)';
        $params[] = $nc;
        $params[] = $nc;
    }
    if ($from !== null) {
        $sql .= ' AND jalali_full_ymd >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND jalali_full_ymd <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime((string)$r['created_at']);
        $r['updated_at_jalali'] = format_jalali_datetime((string)$r['updated_at']);
    }
    unset($r);

    json_response(true, ['data' => ['kelaseh' => $rows]]);
}

function action_kelaseh_create(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $plaintiffName = trim((string)($data['plaintiff_name'] ?? ''));
    $defendantName = trim((string)($data['defendant_name'] ?? ''));
    $plaintiffNc = validate_national_code(isset($data['plaintiff_national_code']) ? (string)$data['plaintiff_national_code'] : null);
    $defendantNc = validate_national_code(isset($data['defendant_national_code']) ? (string)$data['defendant_national_code'] : null);
    $plaintiffMobile = validate_ir_mobile(isset($data['plaintiff_mobile']) ? (string)$data['plaintiff_mobile'] : null);
    $defendantMobile = validate_ir_mobile(isset($data['defendant_mobile']) ? (string)$data['defendant_mobile'] : null);

    if ($plaintiffName === '' || $defendantName === '') {
        json_response(false, ['message' => 'نام و نام خانوادگی خواهان و خوانده الزامی است.'], 422);
    }
    if ($plaintiffNc === null || $defendantNc === null) {
        json_response(false, ['message' => 'کدملی/شناسه ملی خواهان/خوانده نامعتبر است.'], 422);
    }
    if ($plaintiffMobile === null || $defendantMobile === null) {
        json_response(false, ['message' => 'شماره تماس خواهان/خوانده نامعتبر است.'], 422);
    }

    $branchCount = (int)($user['branch_count'] ?? 1);
    if ($branchCount < 1) {
        $branchCount = 1;
    }
    if ($branchCount > 9) {
        $branchCount = 9;
    }

    $branchStart = (int)($user['branch_start_no'] ?? 1);
    if ($branchStart < 1) {
        $branchStart = 1;
    }
    if ($branchStart > 9) {
        $branchStart = 9;
    }
    $branchEnd = $branchStart + $branchCount - 1;
    if ($branchEnd > 9) {
        $branchEnd = 9;
    }

    $p = jalali_now_parts();
    $yy = str_pad((string)($p['y'] % 100), 2, '0', STR_PAD_LEFT);
    $mm = str_pad((string)$p['m'], 2, '0', STR_PAD_LEFT);
    $dd = str_pad((string)$p['d'], 2, '0', STR_PAD_LEFT);
    $ymd = $yy . $mm . $dd;
    $fullYmd = str_pad((string)$p['y'], 4, '0', STR_PAD_LEFT) . $mm . $dd;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT branch_no,seq_no FROM kelaseh_daily_counters WHERE owner_id = ? AND jalali_ymd = ? FOR UPDATE');
        $stmt->execute([(int)$user['id'], $ymd]);
        $row = $stmt->fetch();

        $branchNo = $branchStart;
        $seqNo = 1;
        if ($row) {
            $branchNo = (int)$row['branch_no'];
            $seqNo = (int)$row['seq_no'];
            $seqNo += 1;
            if ($seqNo > 10) {
                $seqNo = 1;
                $branchNo += 1;
            }
        }

        if ($branchNo > $branchEnd) {
            $pdo->rollBack();
            json_response(false, ['message' => 'سقف تولید کلاسه امروز تکمیل شد.'], 409);
        }

        $dup = $pdo->prepare('SELECT id FROM kelaseh_numbers WHERE owner_id = ? AND plaintiff_national_code = ? AND jalali_full_ymd = ? LIMIT 1');
        $dup->execute([(int)$user['id'], $plaintiffNc, $fullYmd]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            json_response(false, ['message' => 'این پرونده امروز برای این کد ملی قبلاً ثبت شده است.'], 409);
        }

        $branch = str_pad((string)$branchNo, 2, '0', STR_PAD_LEFT);
        $seq = str_pad((string)$seqNo, 2, '0', STR_PAD_LEFT);
        $code = $branch . $ymd . $seq;

        $now = now_mysql();
        $pdo->prepare('INSERT INTO kelaseh_numbers (owner_id,code,branch_no,jalali_ymd,jalali_full_ymd,seq_no,plaintiff_name,defendant_name,plaintiff_national_code,defendant_national_code,plaintiff_mobile,defendant_mobile,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$user['id'], $code, $branchNo, $ymd, $fullYmd, $seqNo, $plaintiffName, $defendantName, $plaintiffNc, $defendantNc, $plaintiffMobile, $defendantMobile, 'active', $now, $now]);

        if ($row) {
            $pdo->prepare('UPDATE kelaseh_daily_counters SET branch_no = ?, seq_no = ?, updated_at = ? WHERE owner_id = ? AND jalali_ymd = ?')
                ->execute([$branchNo, $seqNo, $now, (int)$user['id'], $ymd]);
        } else {
            $pdo->prepare('INSERT INTO kelaseh_daily_counters (owner_id,jalali_ymd,branch_no,seq_no,updated_at) VALUES (?,?,?,?,?)')
                ->execute([(int)$user['id'], $ymd, $branchNo, $seqNo, $now]);
        }

        audit_log((int)$user['id'], 'kelaseh_create', 'kelaseh_number', null, null);
        $pdo->commit();
        json_response(true, ['message' => 'کلاسه ایجاد شد.', 'data' => ['code' => $code, 'branch_no' => $branchNo, 'seq_no' => $seqNo, 'jalali_ymd' => $ymd, 'jalali_full_ymd' => $fullYmd]]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sqlState = (string)$e->getCode();
        if ($sqlState === '23000') {
            json_response(false, ['message' => 'پرونده تکراری است.'], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function action_kelaseh_update(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    if (preg_match('/^\d{10}$/', $code) !== 1) {
        json_response(false, ['message' => 'شناسه پرونده نامعتبر است.'], 422);
    }

    $plaintiffName = trim((string)($data['plaintiff_name'] ?? ''));
    $defendantName = trim((string)($data['defendant_name'] ?? ''));
    $plaintiffNc = validate_national_code(isset($data['plaintiff_national_code']) ? (string)$data['plaintiff_national_code'] : null);
    $defendantNc = validate_national_code(isset($data['defendant_national_code']) ? (string)$data['defendant_national_code'] : null);
    $plaintiffMobile = validate_ir_mobile(isset($data['plaintiff_mobile']) ? (string)$data['plaintiff_mobile'] : null);
    $defendantMobile = validate_ir_mobile(isset($data['defendant_mobile']) ? (string)$data['defendant_mobile'] : null);

    if ($plaintiffName === '' || $defendantName === '') {
        json_response(false, ['message' => 'نام و نام خانوادگی خواهان و خوانده الزامی است.'], 422);
    }
    if ($plaintiffNc === null || $defendantNc === null) {
        json_response(false, ['message' => 'کدملی/شناسه ملی خواهان/خوانده نامعتبر است.'], 422);
    }
    if ($plaintiffMobile === null || $defendantMobile === null) {
        json_response(false, ['message' => 'شماره تماس خواهان/خوانده نامعتبر است.'], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id,jalali_full_ymd FROM kelaseh_numbers WHERE owner_id = ? AND code = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([(int)$user['id'], $code]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            json_response(false, ['message' => 'یافت نشد.'], 404);
        }

        $jalaliFull = (string)$row['jalali_full_ymd'];
        $dup = $pdo->prepare('SELECT id FROM kelaseh_numbers WHERE owner_id = ? AND plaintiff_national_code = ? AND jalali_full_ymd = ? AND code <> ? LIMIT 1');
        $dup->execute([(int)$user['id'], $plaintiffNc, $jalaliFull, $code]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            json_response(false, ['message' => 'این پرونده امروز برای این کد ملی قبلاً ثبت شده است.'], 409);
        }

        $now = now_mysql();
        $pdo->prepare('UPDATE kelaseh_numbers SET plaintiff_name = ?, defendant_name = ?, plaintiff_national_code = ?, defendant_national_code = ?, plaintiff_mobile = ?, defendant_mobile = ?, updated_at = ? WHERE owner_id = ? AND code = ?')
            ->execute([$plaintiffName, $defendantName, $plaintiffNc, $defendantNc, $plaintiffMobile, $defendantMobile, $now, (int)$user['id'], $code]);

        audit_log((int)$user['id'], 'kelaseh_update', 'kelaseh_number', null, null);
        $pdo->commit();
        json_response(true, ['message' => 'ویرایش شد.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sqlState = (string)$e->getCode();
        if ($sqlState === '23000') {
            json_response(false, ['message' => 'پرونده تکراری است.'], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function action_kelaseh_set_status(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    $status = (string)($data['status'] ?? '');
    if (preg_match('/^\d{10}$/', $code) !== 1) {
        json_response(false, ['message' => 'شناسه پرونده نامعتبر است.'], 422);
    }
    if (!in_array($status, ['active', 'inactive', 'voided'], true)) {
        json_response(false, ['message' => 'وضعیت نامعتبر است.'], 422);
    }

    $now = now_mysql();
    $stmt = db()->prepare('UPDATE kelaseh_numbers SET status = ?, updated_at = ? WHERE owner_id = ? AND code = ?');
    $stmt->execute([$status, $now, (int)$user['id'], $code]);
    if ($stmt->rowCount() < 1) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }

    audit_log((int)$user['id'], 'kelaseh_set_status', 'kelaseh_number', null, null);
    json_response(true, ['message' => 'وضعیت به‌روزرسانی شد.']);
}

function action_kelaseh_print(): void
{
    $user = auth_require_login();
    $code = trim((string)($_REQUEST['code'] ?? ''));
    if (preg_match('/^\d{10}$/', $code) !== 1) {
        json_response(false, ['message' => 'شناسه پرونده نامعتبر است.'], 422);
    }

    $stmt = db()->prepare('SELECT code,plaintiff_name,defendant_name,created_at FROM kelaseh_numbers WHERE owner_id = ? AND code = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $code]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }

    header('Content-Type: text/html; charset=utf-8');
    $codeHtml = htmlspecialchars((string)$row['code'], ENT_QUOTES, 'UTF-8');
    $pHtml = htmlspecialchars((string)$row['plaintiff_name'], ENT_QUOTES, 'UTF-8');
    $dHtml = htmlspecialchars((string)$row['defendant_name'], ENT_QUOTES, 'UTF-8');
    $dtHtml = htmlspecialchars(format_jalali_datetime((string)$row['created_at']), ENT_QUOTES, 'UTF-8');

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8" />';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1" />';
    echo '<title>پرونده ' . $codeHtml . '</title>';
    echo '<style>body{font-family:Tahoma,Arial,sans-serif;margin:24px} .box{border:1px solid #000;padding:16px;max-width:520px} .code{font-size:24px;font-weight:700;margin-bottom:12px} .line{border-bottom:1px solid #000;padding-bottom:8px;margin:12px 0;font-size:18px} .muted{color:#444;font-size:12px;margin-top:8px} @media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<button onclick="window.print()">چاپ / ذخیره PDF</button>';
    echo '<div class="box">';
    echo '<div class="code">کلاسه پرونده: ' . $codeHtml . '</div>';
    echo '<div class="line">خواهان: ' . $pHtml . '</div>';
    echo '<div class="line">خوانده: ' . $dHtml . '</div>';
    echo '<div class="muted">تاریخ ثبت: ' . $dtHtml . '</div>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

function action_kelaseh_label(): void
{
    $user = auth_require_login();
    $code = trim((string)($_REQUEST['code'] ?? ''));
    $codesRaw = trim((string)($_REQUEST['codes'] ?? ''));

    $codes = [];
    if ($code !== '') {
        $codes = [$code];
    } elseif ($codesRaw !== '') {
        $parts = preg_split('/\s*,\s*/', $codesRaw);
        if (is_array($parts)) {
            foreach ($parts as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $codes[] = $p;
                }
            }
        }
    }

    $clean = [];
    foreach ($codes as $c) {
        if (preg_match('/^\d{10}$/', $c) === 1) {
            $clean[] = $c;
        }
        if (count($clean) >= 50) {
            break;
        }
    }
    if (!$clean) {
        json_response(false, ['message' => 'شناسه پرونده نامعتبر است.'], 422);
    }

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $params = array_merge([(int)$user['id']], $clean);
    $stmt = db()->prepare("SELECT code,plaintiff_name,defendant_name FROM kelaseh_numbers WHERE owner_id = ? AND code IN ($placeholders)");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }

    $byCode = [];
    foreach ($rows as $r) {
        $byCode[(string)$r['code']] = $r;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8" />';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1" />';
    echo '<title>چاپ لیبل پوشه</title>';
    echo '<style>body{font-family:Tahoma,Arial,sans-serif;margin:18px} .label{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:12px} .code{font-size:28px;font-weight:800;letter-spacing:1px} .names{font-size:18px;font-weight:700;text-align:center} @media print{button{display:none} .label{page-break-after:always}}</style>';
    echo '</head><body>';
    echo '<button onclick="window.print()">چاپ لیبل پوشه</button>';

    foreach ($clean as $c) {
        if (!isset($byCode[$c])) {
            continue;
        }
        $row = $byCode[$c];
        $codeHtml = htmlspecialchars((string)$row['code'], ENT_QUOTES, 'UTF-8');
        $pHtml = htmlspecialchars((string)$row['plaintiff_name'], ENT_QUOTES, 'UTF-8');
        $dHtml = htmlspecialchars((string)$row['defendant_name'], ENT_QUOTES, 'UTF-8');
        echo '<div class="label">';
        echo '<div class="code">' . $codeHtml . '</div>';
        echo '<div class="names">خواهان: ' . $pHtml . ' — خوانده: ' . $dHtml . '</div>';
        echo '</div>';
    }

    echo '</body></html>';
    exit;
}

function action_kelaseh_export_print(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $national = trim((string)($data['national_code'] ?? ''));
    $fromRaw = trim((string)($data['from'] ?? ''));
    $toRaw = trim((string)($data['to'] ?? ''));
    $from = parse_jalali_full_ymd($fromRaw !== '' ? $fromRaw : null);
    $to = parse_jalali_full_ymd($toRaw !== '' ? $toRaw : null);
    if ($fromRaw !== '' && $from === null) {
        json_response(false, ['message' => 'تاریخ شروع نامعتبر است.'], 422);
    }
    if ($toRaw !== '' && $to === null) {
        json_response(false, ['message' => 'تاریخ پایان نامعتبر است.'], 422);
    }

    $params = [(int)$user['id']];
    $sql = 'SELECT code,plaintiff_name,defendant_name,plaintiff_national_code,defendant_national_code,created_at,status FROM kelaseh_numbers WHERE owner_id = ?';
    if ($national !== '') {
        $nc = validate_national_code($national);
        if ($nc === null) {
            json_response(false, ['message' => 'کد ملی نامعتبر است.'], 422);
        }
        $sql .= ' AND (plaintiff_national_code = ? OR defendant_national_code = ?)';
        $params[] = $nc;
        $params[] = $nc;
    }
    if ($from !== null) {
        $sql .= ' AND jalali_full_ymd >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND jalali_full_ymd <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY id DESC LIMIT 2000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8" />';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1" />';
    echo '<title>خروجی پرونده‌ها</title>';
    echo '<style>body{font-family:Tahoma,Arial,sans-serif;margin:24px} table{width:100%;border-collapse:collapse} th,td{border:1px solid #333;padding:6px;font-size:12px} th{background:#f5f5f5} @media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<button onclick="window.print()">چاپ / ذخیره PDF</button>';
    echo '<h3>لیست پرونده‌ها</h3>';
    echo '<table><thead><tr><th>کلاسه</th><th>خواهان</th><th>خوانده</th><th>کدملی/شناسه ملی خواهان</th><th>کدملی/شناسه ملی خوانده</th><th>تاریخ</th><th>وضعیت</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $codeHtml = htmlspecialchars((string)$r['code'], ENT_QUOTES, 'UTF-8');
        $pHtml = htmlspecialchars((string)$r['plaintiff_name'], ENT_QUOTES, 'UTF-8');
        $dHtml = htmlspecialchars((string)$r['defendant_name'], ENT_QUOTES, 'UTF-8');
        $pncHtml = htmlspecialchars((string)$r['plaintiff_national_code'], ENT_QUOTES, 'UTF-8');
        $dncHtml = htmlspecialchars((string)$r['defendant_national_code'], ENT_QUOTES, 'UTF-8');
        $dtHtml = htmlspecialchars(format_jalali_datetime((string)$r['created_at']), ENT_QUOTES, 'UTF-8');
        $st = (string)$r['status'];
        $stFa = $st === 'voided' ? 'ابطال' : ($st === 'inactive' ? 'غیرفعال' : 'فعال');
        $stHtml = htmlspecialchars($stFa, ENT_QUOTES, 'UTF-8');
        echo '<tr><td>' . $codeHtml . '</td><td>' . $pHtml . '</td><td>' . $dHtml . '</td><td>' . $pncHtml . '</td><td>' . $dncHtml . '</td><td>' . $dtHtml . '</td><td>' . $stHtml . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</body></html>';
    exit;
}

function action_kelaseh_export_csv(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $national = trim((string)($data['national_code'] ?? ''));
    $fromRaw = trim((string)($data['from'] ?? ''));
    $toRaw = trim((string)($data['to'] ?? ''));
    $from = parse_jalali_full_ymd($fromRaw !== '' ? $fromRaw : null);
    $to = parse_jalali_full_ymd($toRaw !== '' ? $toRaw : null);
    if ($fromRaw !== '' && $from === null) {
        json_response(false, ['message' => 'تاریخ شروع نامعتبر است.'], 422);
    }
    if ($toRaw !== '' && $to === null) {
        json_response(false, ['message' => 'تاریخ پایان نامعتبر است.'], 422);
    }

    $params = [(int)$user['id']];
    $sql = 'SELECT code,branch_no,plaintiff_name,defendant_name,plaintiff_national_code,defendant_national_code,plaintiff_mobile,defendant_mobile,status,created_at FROM kelaseh_numbers WHERE owner_id = ?';
    if ($national !== '') {
        $nc = validate_national_code($national);
        if ($nc === null) {
            json_response(false, ['message' => 'کد ملی نامعتبر است.'], 422);
        }
        $sql .= ' AND (plaintiff_national_code = ? OR defendant_national_code = ?)';
        $params[] = $nc;
        $params[] = $nc;
    }
    if ($from !== null) {
        $sql .= ' AND jalali_full_ymd >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $sql .= ' AND jalali_full_ymd <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY id DESC LIMIT 10000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kelaseh.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['کلاسه', 'شعبه', 'خواهان', 'خوانده', 'کدملی/شناسه ملی خواهان', 'کدملی/شناسه ملی خوانده', 'تماس خواهان', 'تماس خوانده', 'وضعیت', 'تاریخ ثبت']);
    foreach ($rows as $r) {
        $st = (string)$r['status'];
        $stFa = $st === 'voided' ? 'ابطال' : ($st === 'inactive' ? 'غیرفعال' : 'فعال');
        fputcsv($out, [
            (string)$r['code'],
            str_pad((string)$r['branch_no'], 2, '0', STR_PAD_LEFT),
            (string)$r['plaintiff_name'],
            (string)$r['defendant_name'],
            (string)$r['plaintiff_national_code'],
            (string)$r['defendant_national_code'],
            (string)$r['plaintiff_mobile'],
            (string)$r['defendant_mobile'],
            $stFa,
            format_jalali_datetime((string)$r['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

// [HANDLER] لیست کاربران (مدیر)
function action_admin_users_list(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = 'SELECT u.id,u.email,u.username,u.role,u.display_name,u.first_name,u.last_name,u.mobile,u.city_code,c.name AS city_name,u.branch_count,u.branch_start_no,u.is_active,u.created_at,u.last_login_at FROM users u LEFT JOIN isfahan_cities c ON c.code = u.city_code';
    if ($q !== '') {
        $sql .= ' WHERE u.email LIKE ? OR u.username LIKE ? OR u.display_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ?';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY u.id DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    foreach ($users as &$u) {
        $u['created_at_jalali'] = format_jalali_datetime((string)$u['created_at']);
        $u['last_login_at_jalali'] = $u['last_login_at'] ? format_jalali_datetime((string)$u['last_login_at']) : null;
    }
    unset($u);

    json_response(true, ['data' => ['users' => $users]]);
}

// [HANDLER] لیست شهرهای اصفهان (مدیر)
function action_admin_cities_list(): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $stmt = db()->prepare('SELECT code,name FROM isfahan_cities ORDER BY name ASC');
    $stmt->execute();
    $cities = $stmt->fetchAll();
    json_response(true, ['data' => ['cities' => $cities]]);
}

// [HANDLER] ایجاد شهر (مدیر)
function action_admin_cities_create(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));

    if (preg_match('/^\d{2}$/', $code) !== 1) {
        json_response(false, ['message' => 'کد شهر باید دو رقمی باشد.'], 422);
    }
    if ($name === '') {
        json_response(false, ['message' => 'نام شهر الزامی است.'], 422);
    }

    $stmt = db()->prepare('SELECT code FROM isfahan_cities WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        json_response(false, ['message' => 'کد شهر تکراری است.'], 409);
    }

    db()->prepare('INSERT INTO isfahan_cities (code,name) VALUES (?,?)')->execute([$code, $name]);
    audit_log((int)$user['id'], 'admin_city_create', 'isfahan_city', null, null);
    json_response(true, ['message' => 'شهر ایجاد شد.']);
}

// [HANDLER] ویرایش شهر (مدیر)
function action_admin_cities_update(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    if (preg_match('/^\d{2}$/', $code) !== 1) {
        json_response(false, ['message' => 'کد شهر نامعتبر است.'], 422);
    }
    if ($name === '') {
        json_response(false, ['message' => 'نام شهر الزامی است.'], 422);
    }

    $stmt = db()->prepare('UPDATE isfahan_cities SET name = ? WHERE code = ?');
    $stmt->execute([$name, $code]);
    if ($stmt->rowCount() < 1) {
        json_response(false, ['message' => 'شهر یافت نشد.'], 404);
    }

    audit_log((int)$user['id'], 'admin_city_update', 'isfahan_city', null, null);
    json_response(true, ['message' => 'ویرایش شد.']);
}

// [HANDLER] حذف شهر (مدیر)
function action_admin_cities_delete(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $code = trim((string)($data['code'] ?? ''));
    if (preg_match('/^\d{2}$/', $code) !== 1) {
        json_response(false, ['message' => 'کد شهر نامعتبر است.'], 422);
    }

    $check = db()->prepare('SELECT COUNT(*) AS c FROM users WHERE city_code = ?');
    $check->execute([$code]);
    $count = (int)($check->fetch()['c'] ?? 0);
    if ($count > 0) {
        json_response(false, ['message' => 'این شهر برای بعضی کاربران ثبت شده و قابل حذف نیست.'], 409);
    }

    $stmt = db()->prepare('DELETE FROM isfahan_cities WHERE code = ?');
    $stmt->execute([$code]);
    if ($stmt->rowCount() < 1) {
        json_response(false, ['message' => 'شهر یافت نشد.'], 404);
    }

    audit_log((int)$user['id'], 'admin_city_delete', 'isfahan_city', null, null);
    json_response(true, ['message' => 'حذف شد.']);
}

// [HANDLER] ایجاد کاربر جدید (مدیر)
function action_admin_users_create(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName = trim((string)($data['last_name'] ?? ''));
    $username = validate_username((string)($data['username'] ?? ''));
    $mobile = validate_ir_mobile(isset($data['mobile']) ? (string)$data['mobile'] : null);
    $password = (string)($data['password'] ?? '');
    $cityCode = validate_isfahan_city_code(isset($data['city_code']) ? (string)$data['city_code'] : null);
    $branchCount = validate_branch_count($data['branch_count'] ?? null);
    $branchStart = validate_branch_start_no($data['branch_start_no'] ?? 1);
    $role = (string)($data['role'] ?? 'user');

    if ($firstName === '' || $lastName === '') {
        json_response(false, ['message' => 'نام و نام خانوادگی الزامی است.'], 422);
    }
    if ($username === null) {
        json_response(false, ['message' => 'نام کاربری نامعتبر است.'], 422);
    }
    if ($mobile === null) {
        json_response(false, ['message' => 'شماره تماس نامعتبر است.'], 422);
    }
    if (!validate_password($password)) {
        json_response(false, ['message' => 'رمز عبور کوتاه است.'], 422);
    }
    if ($cityCode === null) {
        json_response(false, ['message' => 'شهر نامعتبر است.'], 422);
    }
    if ($branchCount === null) {
        json_response(false, ['message' => 'تعداد شعبه نامعتبر است.'], 422);
    }
    if ($branchStart === null) {
        json_response(false, ['message' => 'شناسه شعبه نامعتبر است.'], 422);
    }
    if (!in_array($role, ['user', 'admin'], true)) {
        $role = 'user';
    }

    $exists = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetch()) {
        json_response(false, ['message' => 'نام کاربری تکراری است.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $displayName = trim($firstName . ' ' . $lastName);

    $stmt = db()->prepare('INSERT INTO users (email,username,password_hash,role,display_name,first_name,last_name,mobile,national_code,city_code,branch_count,branch_start_no,is_active,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        null,
        $username,
        $hash,
        $role,
        $displayName,
        $firstName,
        $lastName,
        $mobile,
        null,
        $cityCode,
        $branchCount,
        $branchStart,
        1,
        now_mysql(),
        null,
    ]);
    $newId = (int)db()->lastInsertId();
    audit_log((int)$user['id'], 'admin_create', 'user', $newId, $newId);
    json_response(true, ['message' => 'کاربر ایجاد شد.']);
}

// [HANDLER] تغییر نقش کاربر (مدیر)
function action_admin_users_set_role(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $targetId = (int)($data['id'] ?? 0);
    $role = (string)($data['role'] ?? 'user');
    if ($targetId <= 0) {
        json_response(false, ['message' => 'شناسه نامعتبر است.'], 422);
    }
    if (!in_array($role, ['user', 'admin'], true)) {
        json_response(false, ['message' => 'نقش نامعتبر است.'], 422);
    }

    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
    audit_log((int)$user['id'], 'set_role', 'user', $targetId, $targetId);
    json_response(true, ['message' => 'نقش به‌روزرسانی شد.']);
}

// [HANDLER] فعال/غیرفعال کردن کاربر (مدیر)
function action_admin_users_set_active(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $targetId = (int)($data['id'] ?? 0);
    $active = (int)($data['is_active'] ?? 1);
    if ($targetId <= 0) {
        json_response(false, ['message' => 'شناسه نامعتبر است.'], 422);
    }
    $active = $active === 1 ? 1 : 0;

    db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active, $targetId]);
    audit_log((int)$user['id'], $active ? 'activate' : 'deactivate', 'user', $targetId, $targetId);
    json_response(true, ['message' => 'وضعیت به‌روزرسانی شد.']);
}

// [HANDLER] لیست لاگ‌ها (مدیر)
function action_admin_audit_list(): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $stmt = db()->prepare("SELECT a.id,a.actor_id,COALESCE(u.display_name,u.username,u.email) AS actor_key,a.action,a.entity,a.entity_id,a.target_user_id,a.ip,a.user_agent,a.created_at FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 200");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    foreach ($logs as &$l) {
        $l['created_at_jalali'] = format_jalali_datetime((string)$l['created_at']);
    }
    unset($l);

    json_response(true, ['data' => ['logs' => $logs]]);
}

function action_admin_kelaseh_stats(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $fromRaw = trim((string)($data['from'] ?? ''));
    $toRaw = trim((string)($data['to'] ?? ''));
    $from = parse_jalali_full_ymd($fromRaw !== '' ? $fromRaw : null);
    $to = parse_jalali_full_ymd($toRaw !== '' ? $toRaw : null);
    if ($fromRaw !== '' && $from === null) {
        json_response(false, ['message' => 'تاریخ شروع نامعتبر است.'], 422);
    }
    if ($toRaw !== '' && $to === null) {
        json_response(false, ['message' => 'تاریخ پایان نامعتبر است.'], 422);
    }

    $where = '';
    $params = [];
    if ($from !== null) {
        $where .= ($where === '' ? ' WHERE' : ' AND') . ' kn.jalali_full_ymd >= ?';
        $params[] = $from;
    }
    if ($to !== null) {
        $where .= ($where === '' ? ' WHERE' : ' AND') . ' kn.jalali_full_ymd <= ?';
        $params[] = $to;
    }

    $sqlCity = "SELECT u.city_code, c.name AS city_name, COUNT(*) AS total, SUM(CASE WHEN kn.status = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN kn.status = 'inactive' THEN 1 ELSE 0 END) AS inactive, SUM(CASE WHEN kn.status = 'voided' THEN 1 ELSE 0 END) AS voided FROM kelaseh_numbers kn INNER JOIN users u ON u.id = kn.owner_id LEFT JOIN isfahan_cities c ON c.code = u.city_code" . $where . " GROUP BY u.city_code, c.name ORDER BY u.city_code";
    $stmt = db()->prepare($sqlCity);
    $stmt->execute($params);
    $cities = $stmt->fetchAll();

    $sqlUser = "SELECT u.id AS user_id, u.username, u.display_name, u.city_code, c.name AS city_name, COUNT(*) AS total, SUM(CASE WHEN kn.status = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN kn.status = 'inactive' THEN 1 ELSE 0 END) AS inactive, SUM(CASE WHEN kn.status = 'voided' THEN 1 ELSE 0 END) AS voided FROM kelaseh_numbers kn INNER JOIN users u ON u.id = kn.owner_id LEFT JOIN isfahan_cities c ON c.code = u.city_code" . $where . " GROUP BY u.id, u.username, u.display_name, u.city_code, c.name ORDER BY total DESC, u.id DESC";
    $stmt = db()->prepare($sqlUser);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $sqlTotal = "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive, SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) AS voided FROM kelaseh_numbers" . str_replace('kn.', '', $where);
    $stmt = db()->prepare($sqlTotal);
    $stmt->execute($params);
    $totals = $stmt->fetch() ?: ['total' => 0, 'active' => 0, 'inactive' => 0, 'voided' => 0];

    json_response(true, ['data' => ['totals' => $totals, 'cities' => $cities, 'users' => $users]]);
}

function action_admin_sms_settings_get(): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $enabled = (int)(get_app_setting('sms_enabled', '0') ?? '0');
    $sender = (string)(get_app_setting('sms_sender', '') ?? '');
    $tplPlaintiff = (string)(get_app_setting('sms_tpl_plaintiff', 'پرونده شما با شماره {code} ثبت شد. خواهان: {plaintiff_name} خوانده: {defendant_name}') ?? '');
    $tplDefendant = (string)(get_app_setting('sms_tpl_defendant', 'پرونده شما با شماره {code} ثبت شد. خواهان: {plaintiff_name} خوانده: {defendant_name}') ?? '');
    $apiKey = (string)(get_app_setting('sms_api_key', '') ?? '');

    json_response(true, [
        'data' => [
            'settings' => [
                'enabled' => $enabled ? 1 : 0,
                'sender' => $sender,
                'tpl_plaintiff' => $tplPlaintiff,
                'tpl_defendant' => $tplDefendant,
                'api_key_present' => $apiKey !== '' ? 1 : 0,
            ],
        ],
    ]);
}

function action_admin_sms_settings_set(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $enabled = (int)($data['enabled'] ?? 0);
    $sender = trim((string)($data['sender'] ?? ''));
    $apiKey = trim((string)($data['api_key'] ?? ''));
    $tplPlaintiff = trim((string)($data['tpl_plaintiff'] ?? ''));
    $tplDefendant = trim((string)($data['tpl_defendant'] ?? ''));

    if ($tplPlaintiff === '' || $tplDefendant === '') {
        json_response(false, ['message' => 'متن پیامک خواهان و خوانده الزامی است.'], 422);
    }
    if (mb_strlen($tplPlaintiff) > 1000 || mb_strlen($tplDefendant) > 1000) {
        json_response(false, ['message' => 'متن پیامک بیش از حد طولانی است.'], 422);
    }

    set_app_setting('sms_enabled', $enabled ? '1' : '0');
    set_app_setting('sms_sender', $sender);
    set_app_setting('sms_tpl_plaintiff', $tplPlaintiff);
    set_app_setting('sms_tpl_defendant', $tplDefendant);
    if ($apiKey !== '') {
        set_app_setting('sms_api_key', $apiKey);
    }

    audit_log((int)$user['id'], 'sms_settings_update', 'app_settings', null, null);
    json_response(true, ['message' => 'تنظیمات پیامک ذخیره شد.']);
}

function action_kelaseh_sms_send(array $data): void
{
    $user = auth_require_login();
    csrf_require_valid();

    $enabled = (int)(get_app_setting('sms_enabled', '0') ?? '0');
    if ($enabled !== 1) {
        json_response(false, ['message' => 'ارسال پیامک غیرفعال است.'], 409);
    }
    $apiKey = trim((string)(get_app_setting('sms_api_key', '') ?? ''));
    if ($apiKey === '') {
        json_response(false, ['message' => 'کلید API پیامک تنظیم نشده است.'], 422);
    }

    $code = trim((string)($data['code'] ?? ''));
    if (preg_match('/^\d{10}$/', $code) !== 1) {
        json_response(false, ['message' => 'شناسه پرونده نامعتبر است.'], 422);
    }

    $toPlaintiff = (int)($data['to_plaintiff'] ?? 0) === 1;
    $toDefendant = (int)($data['to_defendant'] ?? 0) === 1;
    if (!$toPlaintiff && !$toDefendant) {
        json_response(false, ['message' => 'حداقل یک گیرنده را انتخاب کنید.'], 422);
    }

    $stmt = db()->prepare('SELECT code,plaintiff_name,defendant_name,plaintiff_mobile,defendant_mobile FROM kelaseh_numbers WHERE owner_id = ? AND code = ? LIMIT 1');
    $stmt->execute([(int)$user['id'], $code]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }

    $sender = trim((string)(get_app_setting('sms_sender', '') ?? ''));
    $tplPlaintiff = (string)(get_app_setting('sms_tpl_plaintiff', '') ?? '');
    $tplDefendant = (string)(get_app_setting('sms_tpl_defendant', '') ?? '');

    $vars = [
        'code' => (string)$row['code'],
        'plaintiff_name' => (string)$row['plaintiff_name'],
        'defendant_name' => (string)$row['defendant_name'],
    ];

    $sent = [];
    $errors = [];

    if ($toPlaintiff) {
        $m = validate_ir_mobile((string)$row['plaintiff_mobile']);
        if ($m === null) {
            $errors[] = 'شماره تماس خواهان نامعتبر است.';
        } else {
            $msg = render_sms_template($tplPlaintiff, $vars);
            $res = kavenegar_send($apiKey, $m, $msg, $sender);
            if ($res['ok'] ?? false) {
                $sent[] = 'خواهان';
            } else {
                $errors[] = 'ارسال به خواهان ناموفق بود: ' . (string)($res['error'] ?? '');
            }
        }
    }
    if ($toDefendant) {
        $m = validate_ir_mobile((string)$row['defendant_mobile']);
        if ($m === null) {
            $errors[] = 'شماره تماس خوانده نامعتبر است.';
        } else {
            $msg = render_sms_template($tplDefendant, $vars);
            $res = kavenegar_send($apiKey, $m, $msg, $sender);
            if ($res['ok'] ?? false) {
                $sent[] = 'خوانده';
            } else {
                $errors[] = 'ارسال به خوانده ناموفق بود: ' . (string)($res['error'] ?? '');
            }
        }
    }

    if ($sent) {
        audit_log((int)$user['id'], 'sms_send', 'kelaseh_number', null, null);
    }

    if ($errors) {
        json_response(false, ['message' => implode(' ', $errors), 'data' => ['sent' => $sent]], $sent ? 207 : 422);
    }

    json_response(true, ['message' => 'پیامک ارسال شد: ' . implode(' و ', $sent) . '.', 'data' => ['sent' => $sent]]);
}

// [HANDLER] لیست همه آیتم‌ها (مدیر)
function action_admin_items_list(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = 'SELECT i.id,i.owner_id,COALESCE(u.username, u.email) AS owner_key,i.title,i.content,i.created_at,i.updated_at FROM items i JOIN users u ON u.id = i.owner_id';
    if ($q !== '') {
        $sql .= ' WHERE (u.email LIKE ? OR i.title LIKE ? OR i.content LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY i.id DESC LIMIT 200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    foreach ($items as &$it) {
        $it['created_at_jalali'] = format_jalali_datetime((string)$it['created_at']);
        $it['updated_at_jalali'] = format_jalali_datetime((string)$it['updated_at']);
    }
    unset($it);

    json_response(true, ['data' => ['items' => $items]]);
}

// [HANDLER] حذف آیتم توسط مدیر (مدیر)
function action_admin_items_delete(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(false, ['message' => 'شناسه نامعتبر است.'], 422);
    }

    $stmt = db()->prepare('SELECT id, owner_id FROM items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'یافت نشد.'], 404);
    }

    db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
    audit_log((int)$user['id'], 'admin_delete', 'item', $id, (int)$row['owner_id']);
    json_response(true, ['message' => 'حذف شد.']);
}

// [ROUTER] مسیردهی درخواست AJAX
function handle_request(): void
{
    $action = (string)($_REQUEST['action'] ?? '');
    $data = request_data();

    try {
        switch ($action) {
            case 'session':
                action_session();
                break;
            case 'time.now':
                action_time_now();
                break;
            case 'register':
                $cfg = app_config();
                $allowed = (bool)($cfg['security']['allow_register'] ?? false);
                if (!$allowed) {
                    json_response(false, ['message' => 'ثبت‌نام غیرفعال است.'], 403);
                }
                action_register($data);
                break;
            case 'login':
                action_login($data);
                break;
            case 'logout':
                action_logout();
                break;
            case 'items.list':
                action_items_list($data);
                break;
            case 'items.create':
                action_items_create($data);
                break;
            case 'items.update':
                action_items_update($data);
                break;
            case 'items.delete':
                action_items_delete($data);
                break;
            case 'kelaseh.list':
                action_kelaseh_list($data);
                break;
            case 'kelaseh.create':
                action_kelaseh_create($data);
                break;
            case 'kelaseh.update':
                action_kelaseh_update($data);
                break;
            case 'kelaseh.set_status':
                action_kelaseh_set_status($data);
                break;
            case 'kelaseh.print':
                action_kelaseh_print();
                break;
            case 'kelaseh.label':
                action_kelaseh_label();
                break;
            case 'kelaseh.export.print':
                action_kelaseh_export_print($_REQUEST);
                break;
            case 'kelaseh.export.csv':
                action_kelaseh_export_csv($_REQUEST);
                break;
            case 'kelaseh.sms.send':
                action_kelaseh_sms_send($data);
                break;
            case 'admin.users.list':
                action_admin_users_list($data);
                break;
            case 'admin.cities.list':
                action_admin_cities_list();
                break;
            case 'admin.cities.create':
                action_admin_cities_create($data);
                break;
            case 'admin.cities.update':
                action_admin_cities_update($data);
                break;
            case 'admin.cities.delete':
                action_admin_cities_delete($data);
                break;
            case 'admin.users.create':
                action_admin_users_create($data);
                break;
            case 'admin.users.set_role':
                action_admin_users_set_role($data);
                break;
            case 'admin.users.set_active':
                action_admin_users_set_active($data);
                break;
            case 'admin.audit.list':
                action_admin_audit_list();
                break;
            case 'admin.kelaseh.stats':
                action_admin_kelaseh_stats($data);
                break;
            case 'admin.sms.settings.get':
                action_admin_sms_settings_get();
                break;
            case 'admin.sms.settings.set':
                action_admin_sms_settings_set($data);
                break;
            case 'admin.items.list':
                action_admin_items_list($data);
                break;
            case 'admin.items.delete':
                action_admin_items_delete($data);
                break;
            default:
                json_response(false, ['message' => 'اکشن نامعتبر است.'], 404);
        }
    } catch (PDOException $e) {
        error_log((string)$e);
        $sqlState = (string)($e->getCode());
        if ($sqlState === '42S02') {
            json_response(false, ['message' => 'جداول دیتابیس ساخته نشده‌اند. ابتدا فایل schema.sql را در MySQL اجرا کنید.'], 500);
        }
        json_response(false, ['message' => 'خطای دیتابیس رخ داد. تنظیمات config.php را بررسی کنید.'], 500);
    } catch (Throwable $e) {
        error_log((string)$e);
        json_response(false, ['message' => 'خطای داخلی رخ داد.'], 500);
    }
}

if (php_sapi_name() !== 'cli' && isset($_REQUEST['action'])) {
    handle_request();
}
