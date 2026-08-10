<?php
/**
 * کنترلر احراز هویت
 * 
 * مدیریت ورود، خروج و ثبت‌نام کاربران
 */

class AuthController extends Controller {
    
    private $userModel;
    
    public function __construct() {
        $this->userModel = $this->loadModel('User');
    }
    
    /**
     * نمایش فرم ورود
     */
    public function showLogin() {
        // اگر کاربر لاگین است، به داشبورد هدایت شود
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/dashboard');
            return;
        }
        
        $this->render('auth/login', [
            'title' => 'ورود به اسپاتیرا',
            'error' => null
        ]);
    }
    
    /**
     * پردازش درخواست ورود
     */
    public function login() {
        if (!$this->isPost()) {
            $this->redirect('/login');
            return;
        }
        
        $username = $this->postInput('username');
        $password = $_POST['password'] ?? '';
        
        // اعتبارسنجی
        if (empty($username) || empty($password)) {
            $this->render('auth/login', [
                'title' => 'ورود به اسپاتیرا',
                'error' => 'نام کاربری و رمز عبور را وارد کنید.'
            ]);
            return;
        }
        
        // احراز هویت
        $user = $this->userModel->authenticate($username, $password);
        
        if ($user === null) {
            $this->render('auth/login', [
                'title' => 'ورود به اسپاتیرا',
                'error' => 'نام کاربری یا رمز عبور اشتباه است.'
            ]);
            return;
        }
        
        // ایجاد نشست
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['is_admin'] = $user['is_admin'] ?? false;
        $_SESSION['avatar'] = $user['avatar'] ?? null;
        
        // ریدایرکت به داشبورد
        $this->redirect('/dashboard');
    }
    
    /**
     * نمایش فرم ثبت‌نام
     */
    public function showRegister() {
        if (isset($_SESSION['user_id'])) {
            $this->redirect('/dashboard');
            return;
        }
        
        $this->render('auth/register', [
            'title' => 'ثبت‌نام در اسپاتیرا',
            'errors' => [],
            'old' => []
        ]);
    }
    
    /**
     * پردازش درخواست ثبت‌نام
     */
    public function register() {
        if (!$this->isPost()) {
            $this->redirect('/register');
            return;
        }
        
        $name = $this->postInput('name');
        $username = $this->postInput('username');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        
        $errors = [];
        $old = [
            'name' => $name,
            'username' => $username
        ];
        
        // اعتبارسنجی نام
        if (empty($name)) {
            $errors['name'] = 'نام الزامی است.';
        } elseif (strlen($name) < 3) {
            $errors['name'] = 'نام باید حداقل ۳ کاراکتر باشد.';
        }
        
        // اعتبارسنجی نام کاربری
        if (empty($username)) {
            $errors['username'] = 'نام کاربری الزامی است.';
        } elseif (strlen($username) < 3) {
            $errors['username'] = 'نام کاربری باید حداقل ۳ کاراکتر باشد.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors['username'] = 'نام کاربری فقط می‌تواند شامل حروف، اعداد و زیرخط باشد.';
        } elseif ($this->userModel->usernameExists($username)) {
            $errors['username'] = 'این نام کاربری قبلاً گرفته شده است.';
        }
        
        // اعتبارسنجی رمز عبور
        if (empty($password)) {
            $errors['password'] = 'رمز عبور الزامی است.';
        } elseif (strlen($password) < 6) {
            $errors['password'] = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        }
        
        // بررسی تطابق رمز عبور
        if ($password !== $password_confirm) {
            $errors['password_confirm'] = 'رمز عبور و تکرار آن مطابقت ندارند.';
        }
        
        // اگر خطا وجود دارد، فرم را دوباره نمایش بده
        if (!empty($errors)) {
            $this->render('auth/register', [
                'title' => 'ثبت‌نام در اسپاتیرا',
                'errors' => $errors,
                'old' => $old
            ]);
            return;
        }
        
        // ایجاد کاربر
        $userData = [
            'name' => $name,
            'username' => $username,
            'password' => $password
        ];
        
        $user = $this->userModel->create($userData);
        
        if ($user === false) {
            $this->render('auth/register', [
                'title' => 'ثبت‌نام در اسپاتیرا',
                'errors' => ['general' => 'خطا در ایجاد کاربر. لطفاً دوباره تلاش کنید.'],
                'old' => $old
            ]);
            return;
        }
        
        // ورود خودکار پس از ثبت‌نام
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['is_admin'] = $user['is_admin'] ?? false;
        $_SESSION['avatar'] = $user['avatar'] ?? null;
        
        $this->redirect('/dashboard');
    }
    
    /**
     * خروج کاربر
     */
    public function logout() {
        // حذف نشست
        session_unset();
        session_destroy();
        
        // شروع مجدد برای پیام خداحافظی
        session_start();
        $_SESSION['logged_out'] = true;
        
        $this->redirect('/login');
    }
}
