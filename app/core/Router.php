<?php
/**
 * Router - مسیریابی درخواست‌ها
 * 
 * این کلاس مسئول هدایت درخواست‌ها به کنترلر و متد مناسب است
 */

class Router {
    
    private $routes = [];
    private $basePath = '';
    
    /**
     * تنظیم مسیر پایه
     * 
     * @param string $path
     */
    public function setBasePath($path) {
        $this->basePath = rtrim($path, '/');
    }
    
    /**
     * تعریف مسیر GET
     * 
     * @param string $path
     * @param array $handler [controller, method]
     */
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * تعریف مسیر POST
     * 
     * @param string $path
     * @param array $handler [controller, method]
     */
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * تعریف مسیر برای همه متدها
     * 
     * @param string $path
     * @param array $handler [controller, method]
     */
    public function match($path, $handler) {
        $this->addRoute('*', $path, $handler);
    }
    
    /**
     * افزودن مسیر به لیست
     * 
     * @param string $method
     * @param string $path
     * @param array $handler
     */
    private function addRoute($method, $path, $handler) {
        $path = $this->basePath . '/' . trim($path, '/');
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ];
    }
    
    /**
     * پردازش درخواست جاری
     */
    public function dispatch() {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // حذف query string
        if (($pos = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $pos);
        }
        
        $requestUri = '/' . trim($requestUri, '/');
        
        // جستجوی مسیر تطبیق‌دار
        foreach ($this->routes as $route) {
            if ($this->matchRoute($route, $requestMethod, $requestUri)) {
                $this->executeHandler($route['handler']);
                return;
            }
        }
        
        // اگر مسیری پیدا نشد، خطای 404
        http_response_code(404);
        echo "صفحه مورد نظر یافت نشد.";
    }
    
    /**
     * بررسی تطابق مسیر
     * 
     * @param array $route
     * @param string $method
     * @param string $uri
     * @return bool
     */
    private function matchRoute($route, $method, $uri) {
        // بررسی متد
        if ($route['method'] !== '*' && $route['method'] !== $method) {
            return false;
        }
        
        // بررسی مسیر
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '([^/]+)', $route['path']);
        $pattern = '#^' . $pattern . '$#';
        
        return (bool) preg_match($pattern, $uri);
    }
    
    /**
     * اجرای کنترلر و متد
     * 
     * @param array $handler [controller, method]
     */
    private function executeHandler($handler) {
        list($controllerName, $methodName) = $handler;
        
        $controllerFile = __DIR__ . '/../controllers/' . $controllerName . '.php';
        
        if (!file_exists($controllerFile)) {
            throw new Exception("کنترلر {$controllerName} یافت نشد.");
        }
        
        require_once $controllerFile;
        
        if (!class_exists($controllerName)) {
            throw new Exception("کلاس {$controllerName} یافت نشد.");
        }
        
        $controller = new $controllerName();
        
        if (!method_exists($controller, $methodName)) {
            throw new Exception("متد {$methodName} در کنترلر {$controllerName} یافت نشد.");
        }
        
        call_user_func_array([$controller, $methodName], []);
    }
    
    /**
     * بارگذاری فایل routeها
     * 
     * @param string $file
     */
    public function loadRoutes($file) {
        if (file_exists($file)) {
            require_once $file;
        }
    }
}
