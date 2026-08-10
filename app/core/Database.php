<?php
/**
 * کلاس پایه دیتابیس - مدیریت فایل‌های JSON
 * 
 * این کلاس مسئول خواندن و نوشتن داده‌ها در فایل‌های JSON است
 */

class Database {
    
    private static $instance = null;
    private $dataDir;
    
    private function __construct() {
        $this->dataDir = __DIR__ . '/../../data';
        
        // ایجاد پوشه data در صورت عدم وجود
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }
    
    /**
     * دریافت نمونه تکی (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * خواندن داده از فایل JSON
     * 
     * @param string $filename نام فایل (بدون پسوند)
     * @return array داده‌های خوانده‌شده
     */
    public function read($filename) {
        $filepath = $this->dataDir . '/' . $filename . '.json';
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $content = file_get_contents($filepath);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }
    
    /**
     * نوشتن داده در فایل JSON
     * 
     * @param string $filename نام فایل (بدون پسوند)
     * @param array $data داده‌هایی که باید نوشته شوند
     * @return bool نتیجه عملیات
     */
    public function write($filename, $data) {
        $filepath = $this->dataDir . '/' . $filename . '.json';
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return file_put_contents($filepath, $json) !== false;
    }
    
    /**
     * پیدا کردن رکورد بر اساس فیلد
     * 
     * @param string $filename نام فایل
     * @param string $field نام فیلد برای جستجو
     * @param mixed $value مقدار مورد نظر
     * @return array|null رکورد پیدا شده یا null
     */
    public function findBy($filename, $field, $value) {
        $data = $this->read($filename);
        
        foreach ($data as $item) {
            if (isset($item[$field]) && $item[$field] == $value) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * پیدا کردن تمام رکوردهای مطابق با شرط
     * 
     * @param string $filename نام فایل
     * @param string $field نام فیلد برای جستجو
     * @param mixed $value مقدار مورد نظر
     * @return array رکوردهای پیدا شده
     */
    public function findAllBy($filename, $field, $value) {
        $data = $this->read($filename);
        $result = [];
        
        foreach ($data as $item) {
            if (isset($item[$field]) && $item[$field] == $value) {
                $result[] = $item;
            }
        }
        
        return $result;
    }
    
    /**
     * درج رکورد جدید
     * 
     * @param string $filename نام فایل
     * @param array $record رکورد جدید
     * @return bool نتیجه عملیات
     */
    public function insert($filename, $record) {
        $data = $this->read($filename);
        $data[] = $record;
        return $this->write($filename, $data);
    }
    
    /**
     * به‌روزرسانی رکورد
     * 
     * @param string $filename نام فایل
     * @param string $idField نام فیلد کلید اصلی
     * @param mixed $idValue مقدار کلید اصلی
     * @param array $updates داده‌های جدید برای به‌روزرسانی
     * @return bool نتیجه عملیات
     */
    public function update($filename, $idField, $idValue, $updates) {
        $data = $this->read($filename);
        
        foreach ($data as $key => $item) {
            if (isset($item[$idField]) && $item[$idField] == $idValue) {
                $data[$key] = array_merge($item, $updates);
                return $this->write($filename, $data);
            }
        }
        
        return false;
    }
    
    /**
     * حذف رکورد
     * 
     * @param string $filename نام فایل
     * @param string $idField نام فیلد کلید اصلی
     * @param mixed $idValue مقدار کلید اصلی
     * @return bool نتیجه عملیات
     */
    public function delete($filename, $idField, $idValue) {
        $data = $this->read($filename);
        
        foreach ($data as $key => $item) {
            if (isset($item[$idField]) && $item[$idField] == $idValue) {
                unset($data[$key]);
                return $this->write($filename, array_values($data));
            }
        }
        
        return false;
    }
    
    /**
     * شمارش رکوردها
     * 
     * @param string $filename نام فایل
     * @return int تعداد رکوردها
     */
    public function count($filename) {
        return count($this->read($filename));
    }
}
