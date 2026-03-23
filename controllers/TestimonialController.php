<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';

class TestimonialController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getAll() {
        $query = "SELECT * FROM testimonials WHERE is_approved = 1 ORDER BY is_featured DESC, created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        Response::success($stmt->fetchAll());
    }
    
    public function getPending() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "SELECT * FROM testimonials WHERE is_approved = 0 ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        Response::success($stmt->fetchAll());
    }
    
    public function approve($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "UPDATE testimonials SET is_approved = 1 WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::updated(null, "Testimonial approved");
        } else {
            Response::error("Failed to approve testimonial", 500);
        }
    }
    
    public function delete($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "DELETE FROM testimonials WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::deleted("Testimonial deleted");
        } else {
            Response::error("Failed to delete testimonial", 500);
        }
    }
    
    public function toggleFeatured($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "UPDATE testimonials SET is_featured = NOT is_featured WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::updated(null, "Featured status toggled");
        } else {
            Response::error("Failed to update featured status", 500);
        }
    }
}
