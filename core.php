<?php
require_once 'config.php';

/**
 * اتصال به پایگاه داده
 */
function dbConnect() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
    }
}

/**
 * بررسی صحت نام کاربری و رمز عبور
 */
function login($username, $password) {
    $pdo = dbConnect();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return true;
    }
    return false;
}

/**
 * بررسی وضعیت لاگین بودن کاربر
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * خروج کاربر
 */
function logout() {
    session_destroy();
    unset($_SESSION['user_id']);
    unset($_SESSION['username']);
}

/**
 * ارسال پاسخ JSON
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}
