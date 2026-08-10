<?php
/**
 * کلاس پایه مدل
 * 
 * تمام مدل‌ها باید از این کلاس ارث‌بری کنند
 */

class Model {
    
    protected $db;
    protected $table;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * دریافت تمام رکوردها
     * 
     * @return array
     */
    public function all() {
        return $this->db->read($this->table);
    }
    
    /**
     * پیدا کردن رکورد بر اساس ID
     * 
     * @param string $id
     * @return array|null
     */
    public function find($id) {
        return $this->db->findBy($this->table, 'id', $id);
    }
    
    /**
     * پیدا کردن رکورد بر اساس فیلد دلخواه
     * 
     * @param string $field
     * @param mixed $value
     * @return array|null
     */
    public function findBy($field, $value) {
        return $this->db->findBy($this->table, $field, $value);
    }
    
    /**
     * پیدا کردن تمام رکوردهای مطابق با شرط
     * 
     * @param string $field
     * @param mixed $value
     * @return array
     */
    public function findAllBy($field, $value) {
        return $this->db->findAllBy($this->table, $field, $value);
    }
    
    /**
     * درج رکورد جدید
     * 
     * @param array $data
     * @return bool
     */
    public function create($data) {
        return $this->db->insert($this->table, $data);
    }
    
    /**
     * به‌روزرسانی رکورد
     * 
     * @param string $id
     * @param array $data
     * @return bool
     */
    public function update($id, $data) {
        return $this->db->update($this->table, 'id', $id, $data);
    }
    
    /**
     * حذف رکورد
     * 
     * @param string $id
     * @return bool
     */
    public function delete($id) {
        return $this->db->delete($this->table, 'id', $id);
    }
    
    /**
     * شمارش رکوردها
     * 
     * @return int
     */
    public function count() {
        return $this->db->count($this->table);
    }
}
