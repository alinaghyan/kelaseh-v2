<?php
// تنظیمات پایگاه داده
define('DB_HOST', 'localhost');
define('DB_NAME', 'app_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// تنظیمات کلی برنامه
define('APP_NAME', 'سیستم مدیریت');
// آدرس پایه را بر اساس مسیر نصب خود تنظیم کنید
define('BASE_URL', 'http://localhost/pro4/pro4/'); 

// گزارش خطاها (در محیط عملیاتی خاموش شود)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// تنظیم منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// شروع سشن
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
