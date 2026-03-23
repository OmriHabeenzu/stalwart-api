<?php
// JWT Authentication Utility
class JWT {
    private static function getSecretKey() {
        $key = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
        if (!$key) {
            // Fallback for servers without .env support — set this in Apache/Nginx env
            $key = 'sk_live_stalwart_7f8a9b2c4d5e6f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5';
        }
        return $key;
    }
    private static $encrypt_algo = 'HS256';
    
    public static function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => self::$encrypt_algo]);
        $payload = json_encode($payload);
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecretKey(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    public static function decode($jwt) {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) {
            return null;
        }
        
        $header = base64_decode($tokenParts[0]);
        $payload = base64_decode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];
        
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::getSecretKey(), true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        if ($base64UrlSignature !== $signatureProvided) {
            return null;
        }
        
        $payload = json_decode($payload, true);
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    private static function base64UrlEncode($text) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }
    
    public static function generateToken($userId, $email, $role) {
        $issuedAt = time();
        $expire = $issuedAt + (60 * 60 * 24 * 7); // 7 days
        
        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'user_id' => $userId,
            'email' => $email,
            'role' => $role
        ];
        
        return self::encode($payload);
    }
}
