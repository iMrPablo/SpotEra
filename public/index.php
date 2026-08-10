<?php
/**
 * نقطه ورود اصلی برنامه
 * 
 * این فایل تمام درخواست‌ها را دریافت و به Router هدایت می‌کند
 */

// شروع نشست
session_start();

// تنظیمات خطا
error_reporting(E_ALL);
ini_set('display_errors', 0); // در تولید غیرفعال شود

// تعریف ثابت‌ها
define('ROOT_PATH', __DIR__);
define('APP_PATH', __DIR__ . '/app');
define('PUBLIC_PATH', __DIR__ . '/public');
define('DATA_PATH', __DIR__ . '/data');

// بارگذاری کلاس‌های هسته
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Router.php';

// ایجاد نمونه Router
$router = new Router();

// تعریف مسیرها
// مسیرهای احراز هویت
$router->get('/login', ['AuthController', 'showLogin']);
$router->post('/login', ['AuthController', 'login']);
$router->get('/register', ['AuthController', 'showRegister']);
$router->post('/register', ['AuthController', 'register']);
$router->get('/logout', ['AuthController', 'logout']);

// مسیر پیش‌فرض
if (!isset($_SESSION['user_id'])) {
    $router->get('/', ['AuthController', 'showLogin']);
} else {
    $router->get('/', function() {
        header('Location: /dashboard');
        exit;
    });
}

// پردازش درخواست
try {
    $router->dispatch();
} catch (Exception $e) {
    http_response_code(500);
    echo "خطا: " . $e->getMessage();
}
