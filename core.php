<?php

declare(strict_types=1);

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
    $posted = (string)($_POST['csrf_token'] ?? '');
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
    $stmt = db()->prepare('SELECT id,email,role,display_name,mobile,national_code,is_active,created_at,last_login_at FROM users WHERE id = ? LIMIT 1');
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

// [VALIDATION] اعتبارسنجی کد ملی (با کتابخانه و fallback)
function validate_national_code(?string $code): ?string
{
    if ($code === null) {
        return null;
    }
    $code = preg_replace('/\s+/', '', $code);
    if ($code === null) {
        return null;
    }
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $candidates = [
        'PersianValidator\\NationalCode\\NationalCode',
        'PersianValidator\\NationalCode',
        'NationalCode',
    ];

    foreach ($candidates as $fqcn) {
        if (class_exists($fqcn) && method_exists($fqcn, 'make')) {
            $obj = $fqcn::make($code);
            if (is_object($obj) && method_exists($obj, 'isValid') && $obj->isValid()) {
                return str_pad($code, 10, '0', STR_PAD_LEFT);
            }
            return null;
        }
    }

    $code = str_pad($code, 10, '0', STR_PAD_LEFT);
    if (preg_match('/^\d{10}$/', $code) !== 1) {
        return null;
    }
    if (preg_match('/^(\d)\1{9}$/', $code) === 1) {
        return null;
    }
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += ((int)$code[$i]) * (10 - $i);
    }
    $r = (int)$code[9];
    $c = $sum % 11;
    $ok = ($c < 2 && $r === $c) || ($c >= 2 && $r === (11 - $c));
    return $ok ? $code : null;
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

    $displayName = trim((string)($b['display_name'] ?? 'مدیر کل'));
    $mobile = validate_ir_mobile(isset($b['mobile']) ? (string)$b['mobile'] : null);
    $nationalCode = validate_national_code(isset($b['national_code']) ? (string)$b['national_code'] : null);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (email,password_hash,role,display_name,mobile,national_code,is_active,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $email,
        $hash,
        'admin',
        $displayName,
        $mobile,
        $nationalCode,
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
    $password = (string)($data['password'] ?? '');
    $displayName = trim((string)($data['display_name'] ?? ''));
    $mobile = validate_ir_mobile(isset($data['mobile']) ? (string)$data['mobile'] : null);
    $nationalCode = validate_national_code(isset($data['national_code']) ? (string)$data['national_code'] : null);

    if ($email === null) {
        json_response(false, ['message' => 'ایمیل نامعتبر است.'], 422);
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

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_response(false, ['message' => 'این ایمیل قبلاً ثبت شده است.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (email,password_hash,role,display_name,mobile,national_code,is_active,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $email,
        $hash,
        'user',
        $displayName,
        $mobile,
        $nationalCode,
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

    $email = validate_email((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($email === null || $password === '') {
        json_response(false, ['message' => 'ایمیل/رمز نامعتبر است.'], 422);
    }

    app_session_start();
    $lastTry = (int)($_SESSION['last_login_try'] ?? 0);
    $cfg = app_config();
    $throttle = (int)($cfg['security']['login_throttle_seconds'] ?? 2);
    if ($lastTry > 0 && (time() - $lastTry) < $throttle) {
        json_response(false, ['message' => 'کمی بعد دوباره تلاش کنید.'], 429);
    }
    $_SESSION['last_login_try'] = time();

    $stmt = db()->prepare('SELECT id,password_hash,is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(false, ['message' => 'ایمیل/رمز اشتباه است.'], 401);
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

// [HANDLER] لیست کاربران (مدیر)
function action_admin_users_list(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = 'SELECT id,email,role,display_name,is_active,created_at,last_login_at FROM users';
    if ($q !== '') {
        $sql .= ' WHERE email LIKE ? OR display_name LIKE ?';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';

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

    $stmt = db()->prepare('SELECT id,actor_id,action,entity,entity_id,target_user_id,ip,user_agent,created_at FROM audit_logs ORDER BY id DESC LIMIT 200');
    $stmt->execute();
    $logs = $stmt->fetchAll();
    foreach ($logs as &$l) {
        $l['created_at_jalali'] = format_jalali_datetime((string)$l['created_at']);
    }
    unset($l);

    json_response(true, ['data' => ['logs' => $logs]]);
}

// [HANDLER] لیست همه آیتم‌ها (مدیر)
function action_admin_items_list(array $data): void
{
    $user = auth_require_login();
    auth_require_admin($user);
    csrf_require_valid();

    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = 'SELECT i.id,i.owner_id,u.email AS owner_email,i.title,i.content,i.created_at,i.updated_at FROM items i JOIN users u ON u.id = i.owner_id';
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
            case 'admin.users.list':
                action_admin_users_list($data);
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
        $sqlState = (string)($e->getCode());
        if ($sqlState === '42S02') {
            json_response(false, ['message' => 'جداول دیتابیس ساخته نشده‌اند. ابتدا فایل schema.sql را در MySQL اجرا کنید.'], 500);
        }
        json_response(false, ['message' => 'خطای دیتابیس رخ داد. تنظیمات config.php را بررسی کنید.'], 500);
    } catch (Throwable $e) {
        json_response(false, ['message' => 'خطای داخلی رخ داد.'], 500);
    }
}

if (php_sapi_name() !== 'cli' && isset($_REQUEST['action'])) {
    handle_request();
}
