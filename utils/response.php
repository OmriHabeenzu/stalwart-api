<?php
// Response utility
class Response {
    public static function success($data = null, $message = null, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
    
    public static function error($message, $statusCode = 400, $errors = null) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ]);
        exit;
    }
    
    public static function created($data = null, $message = "Created successfully") {
        self::success($data, $message, 201);
    }
    
    public static function updated($data = null, $message = "Updated successfully") {
        self::success($data, $message, 200);
    }
    
    public static function deleted($message = "Deleted successfully") {
        self::success(null, $message, 200);
    }
    
    public static function notFound($message = "Resource not found") {
        self::error($message, 404);
    }
    
    public static function unauthorized($message = "Unauthorized") {
        self::error($message, 401);
    }
    
    public static function forbidden($message = "Forbidden") {
        self::error($message, 403);
    }
    
    public static function validationError($errors, $message = "Validation failed") {
        self::error($message, 422, $errors);
    }
}
