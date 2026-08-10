<?php
/**
 * مدل کاربر
 * 
 * مدیریت عملیات مربوط به کاربران
 */

class User extends Model {
    
    protected $table = 'users';
    
    /**
     * پیدا کردن کاربر بر اساس نام کاربری
     * 
     * @param string $username
     * @return array|null
     */
    public function findByUsername($username) {
        return $this->findBy('username', strtolower($username));
    }
    
    /**
     * بررسی وجود نام کاربری
     * 
     * @param string $username
     * @param string|null $excludeId حذف این کاربر از بررسی
     * @return bool
     */
    public function usernameExists($username, $excludeId = null) {
        $user = $this->findByUsername($username);
        
        if ($user === null) {
            return false;
        }
        
        if ($excludeId !== null && $user['id'] === $excludeId) {
            return false;
        }
        
        return true;
    }
    
    /**
     * ایجاد کاربر جدید
     * 
     * @param array $data
     * @return array|false کاربر ایجاد شده یا false در صورت خطا
     */
    public function create($data) {
        // بررسی تکراری نبودن نام کاربری
        if ($this->usernameExists($data['username'])) {
            return false;
        }
        
        // هش کردن رمز عبور
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        // تولید ID یکتا
        $data['id'] = 'user_' . uniqid() . '_' . bin2hex(random_bytes(4));
        
        // تنظیم تاریخ ایجاد
        $data['created_at'] = date('Y-m-d H:i:s');
        
        // پیش‌فرض‌ها
        if (!isset($data['is_admin'])) {
            $data['is_admin'] = false;
        }
        
        if (!isset($data['avatar'])) {
            $data['avatar'] = null;
        }
        
        $this->db->insert($this->table, $data);
        
        return $this->findByUsername($data['username']);
    }
    
    /**
     * به‌روزرسانی اطلاعات کاربر
     * 
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function update($id, $data) {
        // اگر رمز عبور ارسال شده، هش شود
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        // بررسی تکراری نبودن نام کاربری در صورت تغییر
        if (isset($data['username'])) {
            if ($this->usernameExists($data['username'], $id)) {
                return false;
            }
            $data['username'] = strtolower($data['username']);
        }
        
        return parent::update($id, $data);
    }
    
    /**
     * احراز هویت کاربر
     * 
     * @param string $username
     * @param string $password
     * @return array|null کاربر یا null در صورت ناموفق بودن
     */
    public function authenticate($username, $password) {
        $user = $this->findByUsername($username);
        
        if ($user === null) {
            return null;
        }
        
        if (!isset($user['password_hash'])) {
            return null;
        }
        
        if (password_verify($password, $user['password_hash'])) {
            // حذف هش رمز عبور از خروجی
            unset($user['password_hash']);
            return $user;
        }
        
        return null;
    }
    
    /**
     * دریافت تمام کاربران به جز کاربر جاری
     * 
     * @param string|null $excludeId
     * @return array
     */
    public function getAllExcept($excludeId = null) {
        $users = $this->all();
        
        if ($excludeId === null) {
            return $users;
        }
        
        return array_filter($users, function($user) use ($excludeId) {
            return $user['id'] !== $excludeId;
        });
    }
    
    /**
     * شمارش تعداد ادمین‌ها
     * 
     * @return int
     */
    public function countAdmins() {
        $users = $this->all();
        $count = 0;
        
        foreach ($users as $user) {
            if (isset($user['is_admin']) && $user['is_admin']) {
                $count++;
            }
        }
        
        return $count;
    }
}
