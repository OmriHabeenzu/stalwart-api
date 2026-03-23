<?php
require_once __DIR__ . '/../utils/jwt.php';

class AuthMiddleware {
    public static function authenticate() {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            self::unauthorized("No authorization token provided");
            return null;
        }
        
        $authHeader = $headers['Authorization'];
        $token = str_replace('Bearer ', '', $authHeader);
        
        $decoded = JWT::decode($token);
        
        if (!$decoded) {
            self::unauthorized("Invalid or expired token");
            return null;
        }
        
        return $decoded;
    }
    
    public static function requireAdmin() {
        $user = self::authenticate();
        
        if (!$user) {
            return null;
        }
        
        if ($user['role'] !== 'admin') {
            self::forbidden("Admin access required");
            return null;
        }
        
        return $user;
    }
    
    public static function requireStaff() {
        $user = self::authenticate();
        
        if (!$user) {
            return null;
        }
        
        if (!in_array($user['role'], ['admin', 'staff'])) {
            self::forbidden("Staff access required");
            return null;
        }
        
        return $user;
    }
    
    private static function unauthorized($message) {
        http_response_code(401);
        echo json_encode(['error' => $message]);
        exit;
    }
    
    private static function forbidden($message) {
        http_response_code(403);
        echo json_encode(['error' => $message]);
        exit;
    }
}
