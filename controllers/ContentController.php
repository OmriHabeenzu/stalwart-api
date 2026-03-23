<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';

class ContentController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    // Pages
    public function getPages() {
        $query = "SELECT * FROM page_content WHERE is_published = 1 ORDER BY page_slug ASC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        Response::success($stmt->fetchAll());
    }
    
    public function getPage($slug) {
        $query = "SELECT * FROM page_content WHERE page_slug = :slug";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        
        $page = $stmt->fetch();
        if (!$page) {
            Response::notFound("Page not found");
        }
        Response::success($page);
    }
    
    public function updatePage($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['page_title'])) {
            $updates[] = "page_title = :page_title";
            $params[':page_title'] = $data['page_title'];
        }
        if (isset($data['content'])) {
            $updates[] = "content = :content";
            $params[':content'] = $data['content'];
        }
        if (isset($data['meta_description'])) {
            $updates[] = "meta_description = :meta_description";
            $params[':meta_description'] = $data['meta_description'];
        }
        
        if (empty($updates)) {
            Response::error("No fields to update", 400);
        }
        
        $query = "UPDATE page_content SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            Response::updated(null, "Page updated successfully");
        } else {
            Response::error("Failed to update page", 500);
        }
    }
    
    // Posts/Blog
    public function getPosts() {
        $query = "SELECT p.*, u.name as author_name 
                  FROM posts p 
                  LEFT JOIN users u ON p.author_id = u.id 
                  WHERE p.status = 'published' 
                  ORDER BY p.published_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        Response::success($stmt->fetchAll());
    }
    
    public function getPost($slug) {
        $query = "SELECT p.*, u.name as author_name, u.email as author_email 
                  FROM posts p 
                  LEFT JOIN users u ON p.author_id = u.id 
                  WHERE p.slug = :slug";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        
        $post = $stmt->fetch();
        if (!$post) {
            Response::notFound("Post not found");
        }
        Response::success($post);
    }
    
    public function createPost() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['title']) || !isset($data['content'])) {
            Response::validationError(['title' => 'Title and content are required']);
        }
        
        $slug = isset($data['slug']) ? $data['slug'] : $this->generateSlug($data['title']);
        
        $query = "INSERT INTO posts (title, slug, content, excerpt, featured_image, author_id, status, published_at) 
                  VALUES (:title, :slug, :content, :excerpt, :featured_image, :author_id, :status, :published_at)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':slug', $slug);
        $stmt->bindParam(':content', $data['content']);
        
        $excerpt = isset($data['excerpt']) ? $data['excerpt'] : substr(strip_tags($data['content']), 0, 200);
        $stmt->bindParam(':excerpt', $excerpt);
        
        $featuredImage = isset($data['featured_image']) ? $data['featured_image'] : null;
        $stmt->bindParam(':featured_image', $featuredImage);
        
        $stmt->bindParam(':author_id', $auth['user_id']);
        
        $status = isset($data['status']) ? $data['status'] : 'draft';
        $stmt->bindParam(':status', $status);
        
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $stmt->bindParam(':published_at', $publishedAt);
        
        if ($stmt->execute()) {
            $postId = $this->db->lastInsertId();
            Response::created(['id' => $postId], "Post created successfully");
        } else {
            Response::error("Failed to create post", 500);
        }
    }
    
    public function updatePost($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['content'])) {
            $updates[] = "content = :content";
            $params[':content'] = $data['content'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
            
            if ($data['status'] === 'published') {
                $updates[] = "published_at = NOW()";
            }
        }
        
        if (empty($updates)) {
            Response::error("No fields to update", 400);
        }
        
        $query = "UPDATE posts SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            Response::updated(null, "Post updated successfully");
        } else {
            Response::error("Failed to update post", 500);
        }
    }
    
    public function deletePost($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $query = "DELETE FROM posts WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::deleted("Post deleted successfully");
        } else {
            Response::error("Failed to delete post", 500);
        }
    }
    
    private function generateSlug($title) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        return $slug . '-' . time();
    }
}
