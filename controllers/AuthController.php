<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/../utils/response.php';

class AuthController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function login() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['email']) || !isset($data['password'])) {
            Response::validationError(['email' => 'Email and password required']);
        }
        
        $query = "SELECT id, name, email, password, role, profile_image, can_chat FROM users 
                  WHERE email = :email AND is_active = 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($data['password'], $user['password'])) {
            Response::error("Invalid credentials", 401);
        }
        
        // Update last login
        $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
        $updateStmt = $this->db->prepare($updateQuery);
        $updateStmt->bindParam(':id', $user['id']);
        $updateStmt->execute();
        
        $token = JWT::generateToken($user['id'], $user['email'], $user['role']);
        
        unset($user['password']);
        
        Response::success([
            'token' => $token,
            'user' => $user
        ], "Login successful");
    }
    
    public function me() {
        require_once __DIR__ . '/../middleware/auth.php';
        $auth = AuthMiddleware::authenticate();
        
        if (!$auth) {
            return;
        }
        
        $query = "SELECT id, name, email, role, profile_image, can_chat, last_login, created_at 
                  FROM users WHERE id = :id AND is_active = 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $auth['user_id']);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if (!$user) {
            Response::notFound("User not found");
        }
        
        Response::success($user);
    }
    
    public function changePassword() {
        require_once __DIR__ . '/../middleware/auth.php';
        $auth = AuthMiddleware::authenticate();
        
        if (!$auth) {
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['current_password']) || !isset($data['new_password'])) {
            Response::validationError(['password' => 'Current and new password required']);
        }
        
        // Verify current password
        $query = "SELECT password FROM users WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $auth['user_id']);
        $stmt->execute();
        $user = $stmt->fetch();
        
        if (!password_verify($data['current_password'], $user['password'])) {
            Response::error("Current password is incorrect", 400);
        }
        
        // Update password
        $newPasswordHash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        $updateQuery = "UPDATE users SET password = :password WHERE id = :id";
        $updateStmt = $this->db->prepare($updateQuery);
        $updateStmt->bindParam(':password', $newPasswordHash);
        $updateStmt->bindParam(':id', $auth['user_id']);
        
        if ($updateStmt->execute()) {
            Response::success(null, "Password changed successfully");
        } else {
            Response::error("Failed to change password", 500);
        }
    }
}
