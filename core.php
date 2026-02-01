<?php
session_start();
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/vendor/autoload.php';
use Morilog\Jalali\Jalalian;

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
        $cfg = is_file($configPath) ? require $configPath : [];
        $db = $cfg['db'] ?? [];

        $host = $db['host'] ?? 'localhost';
        $port = $db['port'] ?? 3306;
        $name = $db['name'] ?? 'kelaseh_db';
        $user = $db['user'] ?? 'root';
        $pass = $db['pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'خطای اتصال به دیتابیس: ' . $e->getMessage()]);
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
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
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
    $sql = "INSERT INTO audit_logs (actor_id, action, entity, entity_id, target_user_id, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)";
    db()->prepare($sql)->execute([$actorId, $action, $entity, $entityId, $targetUserId, $ip, $ua]);
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

function action_login(array $data): void
{
    $login = trim((string)($data['login'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($login === '' || $password === '') json_response(false, ['message' => 'نام کاربری و رمز عبور الزامی است.'], 422);

    $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL);
    if ($isEmail) {
        $stmt = db()->prepare('SELECT id,password_hash,is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$login]);
    } else {
        $stmt = db()->prepare('SELECT id,password_hash,is_active FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$login]);
    }
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        json_response(false, ['message' => 'نام کاربری یا رمز عبور اشتباه است.'], 401);
    }
    if ((int)$row['is_active'] !== 1) json_response(false, ['message' => 'حساب غیرفعال است.'], 403);

    $_SESSION['user_id'] = (int)$row['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_mysql(), (int)$row['id']]);
    audit_log((int)$row['id'], 'login', 'user', (int)$row['id'], (int)$row['id']);
    json_response(true, ['message' => 'ورود موفق.', 'data' => ['csrf_token' => csrf_token(), 'user' => current_user()]]);
}

function action_logout(): void
{
    $_SESSION = [];
    session_destroy();
    json_response(true, ['message' => 'خروج موفق.']);
}

function action_session(): void
{
    $user = null;
    try { $user = current_user(); } catch (Throwable $e) {}
    json_response(true, ['data' => ['csrf_token' => csrf_token(), 'user' => $user]]);
}

function action_time_now(): void
{
    json_response(true, ['data' => ['now_jalali' => jalali_now_string()]]);
}

function action_kelaseh_list(array $data): void {
    $user = auth_require_login();
    $national = trim((string)($data['national_code'] ?? ''));
    $from = parse_jalali_full_ymd($data['from'] ?? null);
    $to = parse_jalali_full_ymd($data['to'] ?? null);

    $sql = "SELECT * FROM kelaseh_numbers WHERE owner_id = ?";
    $params = [$user['id']];

    if ($national !== '') {
        $sql .= " AND (plaintiff_national_code LIKE ? OR defendant_national_code LIKE ?)";
        $params[] = "%$national%";
        $params[] = "%$national%";
    }
    if ($from) {
        $sql .= " AND created_at >= ?";
        $params[] = "$from 00:00:00";
    }
    if ($to) {
        $sql .= " AND created_at <= ?";
        $params[] = "$to 23:59:59";
    }
    $sql .= " ORDER BY id DESC LIMIT 100";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['full_code'] = ($user['city_code'] ?? '') . '-' . $r['code'];
        $r['city_name'] = $user['city_name'] ?? '';
    }
    json_response(true, ['data' => ['kelaseh' => $rows]]);
}

function action_kelaseh_list_today(array $data): void {
    $user = auth_require_login();
    $today = date('Y-m-d');
    $sql = "SELECT * FROM kelaseh_numbers WHERE owner_id = ? AND DATE(created_at) = ? ORDER BY id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$user['id'], $today]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['full_code'] = ($user['city_code'] ?? '') . '-' . $r['code'];
        $r['city_name'] = $user['city_name'] ?? '';
    }
    json_response(true, ['data' => ['kelaseh' => $rows]]);
}

function action_kelaseh_create(array $data): void {
    $user = auth_require_login();
    csrf_require_valid();
    
    $branches = $user['branches'] ?? [];
    if (empty($branches)) {
        $start = (int)($user['branch_start_no'] ?? 1);
        $count = (int)($user['branch_count'] ?? 1);
        $branches = range($start, $start + $count - 1);
    }
    
    $cityCode = $user['city_code'];
    $today = date('Y-m-d');
    $selectedBranch = null;
    
    foreach ($branches as $b) {
        $stmt = db()->prepare('SELECT capacity FROM office_branch_capacities WHERE city_code = ? AND branch_no = ?');
        $stmt->execute([$cityCode, $b]);
        $cap = $stmt->fetchColumn();
        if ($cap === false) $cap = 15;
        
        $stmt = db()->prepare('SELECT COUNT(*) FROM kelaseh_numbers WHERE owner_id = ? AND branch_no = ? AND DATE(created_at) = ? AND status != "voided"');
        $stmt->execute([$user['id'], $b, $today]);
        $used = $stmt->fetchColumn();
        
        if ($used < $cap) {
            $selectedBranch = $b;
            break;
        }
    }
    
    if ($selectedBranch === null) json_response(false, ['message' => 'ظرفیت تکمیل شده است.'], 429);
    
    $pNC = validate_national_code($data['plaintiff_national_code'] ?? null);
    $pMob = validate_ir_mobile($data['plaintiff_mobile'] ?? null);
    $pName = trim($data['plaintiff_name'] ?? '');
    $dNC = validate_national_code($data['defendant_national_code'] ?? null);
    $dMob = validate_ir_mobile($data['defendant_mobile'] ?? null);
    $dName = trim($data['defendant_name'] ?? '');
    
    if (!$pNC || !$pMob) json_response(false, ['message' => 'اطلاعات خواهان نامعتبر است.'], 422);

    do {
        $code = mt_rand(100000, 999999);
        $stmt = db()->prepare('SELECT id FROM kelaseh_numbers WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    
    $sql = "INSERT INTO kelaseh_numbers (owner_id, code, branch_no, plaintiff_name, plaintiff_national_code, plaintiff_mobile, defendant_name, defendant_national_code, defendant_mobile, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    db()->prepare($sql)->execute([$user['id'], $code, $selectedBranch, $pName, $pNC, $pMob, $dName, $dNC, $dMob, now_mysql()]);
    audit_log($user['id'], 'kelaseh_create', 'kelaseh', null, null);
    json_response(true, ['message' => 'پرونده ایجاد شد.', 'data' => ['code' => $code]]);
}

function action_kelaseh_history_check(array $data): void {
    $nc = validate_national_code($data['national_code'] ?? null);
    if (!$nc) json_response(false, ['message' => 'Invalid NC']);
    
    $stmt = db()->prepare("SELECT * FROM kelaseh_numbers WHERE plaintiff_national_code = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$nc]);
    $p = $stmt->fetchAll();
    foreach($p as &$r) $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
    
    $stmt = db()->prepare("SELECT * FROM kelaseh_numbers WHERE defendant_national_code = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$nc]);
    $d = $stmt->fetchAll();
    foreach($d as &$r) $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
    
    json_response(true, ['data' => ['plaintiff' => $p, 'defendant' => $d]]);
}

function action_office_capacities_get(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['office_admin', 'branch_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    
    $cityCode = $user['city_code'];
    $stmt = db()->prepare('SELECT branch_no, capacity FROM office_branch_capacities WHERE city_code = ?');
    $stmt->execute([$cityCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['branch_no']] = $r['capacity'];
    
    $result = [];
    $allowedBranches = ($user['role'] === 'branch_admin') ? ($user['branches'] ?? []) : range(1, 15);
    foreach ($allowedBranches as $b) {
        $result[] = ['branch_no' => $b, 'capacity' => $map[$b] ?? 15];
    }
    json_response(true, ['capacities' => $result]);
}

function action_kelaseh_label(array $data): void {
    // Note: This action renders HTML, not JSON.
    // It is used to add items to the print queue in localStorage and show the print page.
    // However, since we are on server-side PHP, we can't write to localStorage directly.
    // The previous implementation used a JS-based approach where the button clicked opened a window with a URL.
    // That URL was `core.php?action=kelaseh.label&code=...`.
    // The print_labels.html expects items in localStorage.
    // A better approach: Render print_labels.html and inject the data directly.
    
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
        echo "No codes provided.";
        exit;
    }
    
    // Fetch details
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    // We need to fetch details. We can join with cities to get city name.
    $sql = "SELECT k.*, c.name as city_name 
            FROM kelaseh_numbers k 
            LEFT JOIN users u ON u.id = k.owner_id 
            LEFT JOIN isfahan_cities c ON c.code = u.city_code 
            WHERE k.code IN ($placeholders) AND k.owner_id = ?";
            
    // Add owner_id to params to ensure security (can only print own records or admin?)
    // Actually, maybe admin needs to print too. Let's stick to owner check for now, or allow if admin.
    $params = $codes;
    $params[] = $user['id'];
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    // Format dates
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        $r['full_code'] = ($r['city_code'] ?? '') . '-' . $r['code']; // Note: city_code isn't in kelaseh_numbers, it's on user.
        // But we joined users table? No, we joined users on owner_id.
        // Let's correct the query to fetch city_code from users if needed, or rely on what we have.
        // Wait, the previous list query fetched city_code from user session.
        // Let's fetch city_code from the joined user table.
    }
    
    // Re-fetch with better join if needed, or just use what we have.
    // Actually, let's fix the SQL to be robust.
    $sql = "SELECT k.*, u.city_code, c.name as city_name 
            FROM kelaseh_numbers k 
            JOIN users u ON u.id = k.owner_id 
            LEFT JOIN isfahan_cities c ON c.code = u.city_code 
            WHERE k.code IN ($placeholders)";
            
    // If not admin, restrict to owner
    $params = $codes;
    if ($user['role'] !== 'admin' && $user['role'] !== 'office_admin') {
         $sql .= " AND k.owner_id = ?";
         $params[] = $user['id'];
    } elseif ($user['role'] === 'office_admin') {
         $sql .= " AND u.city_code = ?";
         $params[] = $user['city_code'];
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    foreach ($rows as &$r) {
        $r['created_at_jalali'] = format_jalali_datetime($r['created_at']);
        // Swap: Code - CityCode (so CityCode appears on the right/first in RTL reading if that's the intent, or just logical swap)
        $r['full_code'] = $r['code'] . '-' . ($r['city_code'] ?? '');
    }

    // Now load the HTML template
    $html = file_get_contents(__DIR__ . '/print_labels.html');
    
    // We need to inject the data into the HTML. 
    // The HTML currently looks for localStorage 'print_queue'.
    // We can inject a script that sets this variable or directly renders the items.
    // Let's modify the HTML to accept a global variable `INJECTED_DATA`.
    
    $jsonData = json_encode($rows);
    
    // Replace the JS part that reads localStorage with our data
    $script = "
    <script>
        const injectedData = $jsonData;
        // Override localStorage logic for this request
        localStorage.setItem('print_queue', JSON.stringify(injectedData));
    </script>
    ";
    
    // Insert before the existing script or head
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
    if (!in_array($user['role'], ['office_admin', 'branch_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    csrf_require_valid();
    
    $branch = isset($data['branch_no']) ? (int)$data['branch_no'] : 0;
    $cap = isset($data['capacity']) ? (int)$data['capacity'] : 0;
    
    if ($branch < 1 || $branch > 15 || $cap < 0) json_response(false, ['message' => 'اطلاعات نامعتبر'], 422);
    
    if ($user['role'] === 'branch_admin') {
        $allowed = $user['branches'] ?? [];
        if (!in_array($branch, $allowed)) json_response(false, ['message' => 'دسترسی غیرمجاز به شعبه'], 403);
    }
    
    $cityCode = $user['city_code'];
    $stmt = db()->prepare('SELECT id FROM office_branch_capacities WHERE city_code = ? AND branch_no = ?');
    $stmt->execute([$cityCode, $branch]);
    if ($stmt->fetch()) {
        db()->prepare('UPDATE office_branch_capacities SET capacity = ? WHERE city_code = ? AND branch_no = ?')->execute([$cap, $cityCode, $branch]);
    } else {
        db()->prepare('INSERT INTO office_branch_capacities (city_code, branch_no, capacity) VALUES (?, ?, ?)')->execute([$cityCode, $branch, $cap]);
    }
    json_response(true, ['message' => 'ظرفیت ذخیره شد.']);
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
        $where[] = "u.city_code = ?";
        $params[] = $user['city_code'];
    }
    if ($q !== '') {
        $where[] = "(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ?)";
        $like = "%$q%";
        array_push($params, $like, $like, $like, $like);
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " GROUP BY u.id ORDER BY u.id DESC LIMIT 50";
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    json_response(true, ['data' => ['users' => $users]]);
}

function action_admin_users_create(array $data): void {
    $user = auth_require_login();
    $isOfficeAdmin = $user['role'] === 'office_admin';
    if (!$isOfficeAdmin) auth_require_admin($user);
    if ($isOfficeAdmin && ($data['role'] ?? '') !== 'branch_admin') json_response(false, ['message' => 'مجاز نیستید'], 403);
    csrf_require_valid();
    
    $username = validate_username($data['username'] ?? '');
    if (!$username) json_response(false, ['message' => 'نام کاربری نامعتبر'], 422);
    
    // Check duplicate
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ? OR mobile = ?');
    $stmt->execute([$username, $data['mobile'] ?? '']);
    if ($stmt->fetch()) json_response(false, ['message' => 'کاربر تکراری است'], 409);
    
    $passHash = password_hash($data['password'], PASSWORD_DEFAULT);
    $role = $isOfficeAdmin ? 'branch_admin' : ($data['role'] ?? 'user');
    $cityCode = $isOfficeAdmin ? $user['city_code'] : ($data['city_code'] ?? null);
    
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
    
    if ($user['role'] === 'office_admin' && $u['city_code'] !== $user['city_code']) json_response(false, ['message' => 'دسترسی ندارید'], 403);
    
    $updates = []; $params = [];
    if (!empty($data['first_name'])) { $updates[] = "first_name=?"; $params[] = $data['first_name']; }
    if (!empty($data['last_name'])) { $updates[] = "last_name=?"; $params[] = $data['last_name']; }
    if (!empty($data['mobile'])) { $updates[] = "mobile=?"; $params[] = $data['mobile']; }
    if (!empty($data['password'])) { $updates[] = "password_hash=?"; $params[] = password_hash($data['password'], PASSWORD_DEFAULT); }
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

function action_admin_users_delete(array $data): void {
    $user = auth_require_login();
    if (!in_array($user['role'], ['admin', 'office_admin'], true)) json_response(false, ['message' => 'دسترسی غیرمجاز'], 403);
    csrf_require_valid();
    $id = (int)$data['id'];
    if ($id == $user['id']) json_response(false, ['message' => 'حذف خود ممکن نیست'], 422);
    db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    json_response(true, ['message' => 'حذف شد']);
}

function action_admin_cities_list(): void {
    auth_require_admin(auth_require_login());
    $rows = db()->query("SELECT * FROM isfahan_cities")->fetchAll();
    json_response(true, ['data' => ['cities' => $rows]]);
}

function action_admin_cities_create(array $data): void {
    auth_require_admin(auth_require_login());
    
    $stmt = db()->prepare("SELECT code FROM isfahan_cities WHERE code = ?");
    $stmt->execute([$data['code']]);
    if ($stmt->fetch()) {
         json_response(false, ['message' => 'این کد شهر قبلاً ثبت شده است.'], 409);
    }
    
    db()->prepare("INSERT INTO isfahan_cities (code, name) VALUES (?, ?)")->execute([$data['code'], $data['name']]);
    json_response(true, ['message' => 'شهر ایجاد شد']);
}

function action_admin_cities_update(array $data): void {
    auth_require_admin(auth_require_login());
    
    // Check if new code exists and is not the same as old code
    if ($data['new_code'] !== $data['code']) {
        $stmt = db()->prepare("SELECT code FROM isfahan_cities WHERE code = ?");
        $stmt->execute([$data['new_code']]);
        if ($stmt->fetch()) {
             json_response(false, ['message' => 'این کد شهر قبلاً ثبت شده است.'], 409);
        }
    }

    db()->prepare("UPDATE isfahan_cities SET code=?, name=? WHERE code=?")->execute([$data['new_code'], $data['name'], $data['code']]);
    json_response(true, ['message' => 'شهر ویرایش شد']);
}

function action_admin_cities_delete(array $data): void {
    auth_require_admin(auth_require_login());
    db()->prepare("DELETE FROM isfahan_cities WHERE code=?")->execute([$data['code']]);
    json_response(true, ['message' => 'شهر حذف شد']);
}

function action_admin_audit_list(): void {
    auth_require_admin(auth_require_login());
    $logs = db()->query("SELECT a.*, u.username as actor_key FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 100")->fetchAll();
    foreach($logs as &$l) $l['created_at_jalali'] = format_jalali_datetime($l['created_at']);
    json_response(true, ['data' => ['logs' => $logs]]);
}

function handle_request(): void
{
    $action = (string)($_REQUEST['action'] ?? '');
    $data = request_data();

    try {
        switch ($action) {
            case 'session': action_session(); break;
            case 'time.now': action_time_now(); break;
            case 'login': action_login($data); break;
            case 'logout': action_logout(); break;
            case 'kelaseh.list': action_kelaseh_list($data); break;
            case 'kelaseh.list.today': action_kelaseh_list_today($data); break;
            case 'kelaseh.create': action_kelaseh_create($data); break;
            case 'kelaseh.history.check': action_kelaseh_history_check($data); break;
            case 'office.capacities.get': action_office_capacities_get($data); break;
            case 'office.capacities.update': action_office_capacities_update($data); break;
            case 'kelaseh.label': action_kelaseh_label($data); break;
            case 'kelaseh.print': action_kelaseh_print($data); break;
            
            // Admin / Office Admin
            case 'admin.users.list': action_admin_users_list($data); break;
            case 'admin.users.create': action_admin_users_create($data); break;
            case 'admin.users.update': action_admin_users_update($data); break;
            case 'admin.users.delete': action_admin_users_delete($data); break;
            case 'admin.cities.list': action_admin_cities_list(); break;
            case 'admin.cities.create': action_admin_cities_create($data); break;
            case 'admin.cities.update': action_admin_cities_update($data); break;
            case 'admin.cities.delete': action_admin_cities_delete($data); break;
            case 'admin.audit.list': action_admin_audit_list(); break;
            
            default:
                json_response(false, ['message' => 'اکشن نامعتبر یا پیاده‌سازی نشده.'], 404);
        }
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'خطای سیستم: ' . $e->getMessage()]);
    }
}

handle_request();
