<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';

class UserController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getAll() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "SELECT id, name, email, role, profile_image, can_chat, is_active, last_login, created_at 
                  FROM users ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        $users = $stmt->fetchAll();
        Response::success($users);
    }
    
    public function getStaffList() {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT id, name, email, profile_image, can_chat, is_active 
                  FROM users WHERE is_active = 1 ORDER BY name ASC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        $users = $stmt->fetchAll();
        Response::success($users);
    }
    
    public function create() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validation
        $errors = [];
        if (!isset($data['name']) || empty($data['name'])) {
            $errors['name'] = 'Name is required';
        }
        if (!isset($data['email']) || empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required';
        }
        if (!isset($data['password']) || strlen($data['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters';
        }
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        // Check if email exists
        $checkQuery = "SELECT id FROM users WHERE email = :email";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':email', $data['email']);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            Response::error("Email already exists", 400);
        }
        
        // Create user
        $query = "INSERT INTO users (name, email, password, role, can_chat, is_active) 
                  VALUES (:name, :email, :password, :role, :can_chat, :is_active)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        
        $password = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt->bindParam(':password', $password);
        
        $role = isset($data['role']) ? $data['role'] : 'staff';
        $stmt->bindParam(':role', $role);
        
        $canChat = isset($data['can_chat']) ? $data['can_chat'] : 0;
        $stmt->bindParam(':can_chat', $canChat);
        
        $isActive = isset($data['is_active']) ? $data['is_active'] : 1;
        $stmt->bindParam(':is_active', $isActive);
        
        if ($stmt->execute()) {
            $userId = $this->db->lastInsertId();
            
            $getUser = "SELECT id, name, email, role, can_chat, is_active, created_at FROM users WHERE id = :id";
            $getUserStmt = $this->db->prepare($getUser);
            $getUserStmt->bindParam(':id', $userId);
            $getUserStmt->execute();
            
            Response::created($getUserStmt->fetch(), "User created successfully");
        } else {
            Response::error("Failed to create user", 500);
        }
    }
    
    public function update($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Check if user exists
        $checkQuery = "SELECT id FROM users WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            Response::notFound("User not found");
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['name'])) {
            $updates[] = "name = :name";
            $params[':name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $updates[] = "email = :email";
            $params[':email'] = $data['email'];
        }
        if (isset($data['role'])) {
            $updates[] = "role = :role";
            $params[':role'] = $data['role'];
        }
        if (isset($data['can_chat'])) {
            $updates[] = "can_chat = :can_chat";
            $params[':can_chat'] = $data['can_chat'];
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = :is_active";
            $params[':is_active'] = $data['is_active'];
        }
        
        if (empty($updates)) {
            Response::error("No fields to update", 400);
        }
        
        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            $getUser = "SELECT id, name, email, role, can_chat, is_active, created_at FROM users WHERE id = :id";
            $getUserStmt = $this->db->prepare($getUser);
            $getUserStmt->bindParam(':id', $id);
            $getUserStmt->execute();
            
            Response::updated($getUserStmt->fetch(), "User updated successfully");
        } else {
            Response::error("Failed to update user", 500);
        }
    }
    
    public function delete($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        // Check if user exists
        $checkQuery = "SELECT id FROM users WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            Response::notFound("User not found");
        }
        
        // Soft delete by deactivating
        $query = "UPDATE users SET is_active = 0 WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::deleted("User deactivated successfully");
        } else {
            Response::error("Failed to delete user", 500);
        }
    }
}
