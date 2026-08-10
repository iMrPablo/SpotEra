<?php
/**
 * کلاس پایه کنترلر
 * 
 * تمام کنترلرها باید از این کلاس ارث‌بری کنند
 */

class Controller {
    
    protected $model;
    protected $view;
    
    /**
     * بارگذاری مدل
     * 
     * @param string $modelName نام مدل
     * @return object نمونه مدل
     */
    protected function loadModel($modelName) {
        $modelPath = __DIR__ . '/../models/' . $modelName . '.php';
        
        if (file_exists($modelPath)) {
            require_once $modelPath;
            return new $modelName();
        }
        
        throw new Exception("مدل {$modelName} یافت نشد.");
    }
    
    /**
     * رندر کردن ویو
     * 
     * @param string $viewName نام ویو
     * @param array $data داده‌هایی که به ویو ارسال می‌شوند
     * @return void
     */
    protected function render($viewName, $data = []) {
        extract($data);
        
        $viewPath = __DIR__ . '/../views/' . $viewName . '.php';
        
        if (file_exists($viewPath)) {
            require_once $viewPath;
        } else {
            throw new Exception("ویو {$viewName} یافت نشد.");
        }
    }
    
    /**
     * بازگشت پاسخ JSON
     * 
     * @param array $data داده‌ها
     * @param int $statusCode کد وضعیت HTTP
     * @return void
     */
    protected function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * ریدایرکت به آدرس دیگر
     * 
     * @param string $url آدرس مقصد
     * @return void
     */
    protected function redirect($url) {
        header("Location: {$url}");
        exit;
    }
    
    /**
     * بررسی درخواست AJAX
     * 
     * @return bool
     */
    protected function isAjax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
    
    /**
     * دریافت متد درخواست
     * 
     * @return string متد HTTP
     */
    protected function getRequestMethod() {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    /**
     * بررسی متد POST
     * 
     * @return bool
     */
    protected function isPost() {
        return $this->getRequestMethod() === 'POST';
    }
    
    /**
     * بررسی متد GET
     * 
     * @return bool
     */
    protected function isGet() {
        return $this->getRequestMethod() === 'GET';
    }
    
    /**
     * دریافت ورودی POST با sanitization
     * 
     * @param string $key نام فیلد
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed مقدار تمیزشده
     */
    protected function postInput($key, $default = null) {
        if (!isset($_POST[$key])) {
            return $default;
        }
        
        return htmlspecialchars(trim($_POST[$key]), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * دریافت ورودی GET با sanitization
     * 
     * @param string $key نام فیلد
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed مقدار تمیزشده
     */
    protected function getInput($key, $default = null) {
        if (!isset($_GET[$key])) {
            return $default;
        }
        
        return htmlspecialchars(trim($_GET[$key]), ENT_QUOTES, 'UTF-8');
    }
}
