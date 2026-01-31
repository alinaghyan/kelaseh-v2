<?php
require_once 'core.php';

// فقط درخواست‌های POST پذیرفته می‌شود
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'روش درخواست نامعتبر است.');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(false, 'لطفا نام کاربری و رمز عبور را وارد کنید.');
        }

        if (login($username, $password)) {
            jsonResponse(true, 'ورود با موفقیت انجام شد.', ['redirect' => 'index.php']);
        } else {
            jsonResponse(false, 'نام کاربری یا رمز عبور اشتباه است.');
        }
        break;
        
    case 'logout':
        logout();
        jsonResponse(true, 'خروج موفقیت آمیز بود.', ['redirect' => 'login.php']);
        break;

    default:
        jsonResponse(false, 'عملیات نامعتبر است.');
}
