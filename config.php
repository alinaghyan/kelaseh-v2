<?php
/**
 * فایل تنظیمات اصلی برنامه
 * شامل اطلاعات اتصال به دیتابیس، نام برنامه و تنظیمات امنیتی
 */

return [
    'app' => [
        'name' => 'کلاسه',
        'timezone' => 'Asia/Tehran',
        'session_name' => 'kelaseh_session',
        'csrf_header' => 'X-CSRF-Token',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'kelaseh_v2',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'password_min_length' => 8,
        'login_throttle_seconds' => 2,
        'allow_register' => false,
    ],
    'bootstrap_admin' => [
        'enabled' => true,
        'email' => 'mehdi.alinaghyan@gmail.com',
        'password' => 'alinaghyan',
        'display_name' => 'مدیر کل',
        'mobile' => '09137396114',
        'national_code' => null,
    ],
];
