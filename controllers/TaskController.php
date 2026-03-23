<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';

class TaskController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getAll() {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT t.*, 
                  u1.name as assigned_to_name, u1.email as assigned_to_email,
                  u2.name as created_by_name, u2.email as created_by_email
                  FROM tasks t
                  LEFT JOIN users u1 ON t.assigned_to = u1.id
                  LEFT JOIN users u2 ON t.created_by = u2.id
                  ORDER BY 
                    FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                    t.due_date ASC,
                    t.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        $tasks = $stmt->fetchAll();
        Response::success($tasks);
    }
    
    public function getMyTasks() {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT t.*, 
                  u1.name as assigned_to_name, u1.email as assigned_to_email,
                  u2.name as created_by_name, u2.email as created_by_email
                  FROM tasks t
                  LEFT JOIN users u1 ON t.assigned_to = u1.id
                  LEFT JOIN users u2 ON t.created_by = u2.id
                  WHERE (t.assigned_to = :user_id OR t.created_by = :user_id)
                  ORDER BY 
                    FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                    t.due_date ASC,
                    t.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $auth['user_id']);
        $stmt->execute();
        
        $tasks = $stmt->fetchAll();
        Response::success($tasks);
    }
    
    public function getById($id) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT t.*, 
                  u1.name as assigned_to_name, u1.email as assigned_to_email, u1.profile_image as assigned_to_image,
                  u2.name as created_by_name, u2.email as created_by_email, u2.profile_image as created_by_image
                  FROM tasks t
                  LEFT JOIN users u1 ON t.assigned_to = u1.id
                  LEFT JOIN users u2 ON t.created_by = u2.id
                  WHERE t.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $task = $stmt->fetch();
        
        if (!$task) {
            Response::notFound("Task not found");
        }
        
        // Get comments
        $commentsQuery = "SELECT tc.*, u.name as user_name, u.email as user_email, u.profile_image
                          FROM task_comments tc
                          LEFT JOIN users u ON tc.user_id = u.id
                          WHERE tc.task_id = :task_id
                          ORDER BY tc.created_at ASC";
        
        $commentsStmt = $this->db->prepare($commentsQuery);
        $commentsStmt->bindParam(':task_id', $id);
        $commentsStmt->execute();
        
        $task['comments'] = $commentsStmt->fetchAll();
        
        Response::success($task);
    }
    
    public function create() {
    $auth = AuthMiddleware::requireAdmin();
    if (!$auth) return;
    
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Handle frontend field name variations
    $data['assigned_to'] = $data['assignedTo'] ?? $data['assigned_to'] ?? null;
    $data['due_date'] = $data['dueDate'] ?? $data['due_date'] ?? null;
    
    // Validation
    $errors = [];
    if (!isset($data['title']) || empty($data['title'])) {
        $errors['title'] = 'Title is required';
    }
    
    if (!empty($errors)) {
        Response::validationError($errors);
        return;
    }
    
    $query = "INSERT INTO tasks (title, description, assigned_to, created_by, status, priority, due_date) 
              VALUES (:title, :description, :assigned_to, :created_by, :status, :priority, :due_date)";
    
    $stmt = $this->db->prepare($query);
    $stmt->bindParam(':title', $data['title']);
    
    $description = $data['description'] ?? null;
    $stmt->bindParam(':description', $description);
    
    $assignedTo = $data['assigned_to'];
    $stmt->bindParam(':assigned_to', $assignedTo);
    
    $stmt->bindParam(':created_by', $auth['user_id']);
    
    $status = $data['status'] ?? 'pending';
    $stmt->bindParam(':status', $status);
    
    $priority = $data['priority'] ?? 'medium';
    $stmt->bindParam(':priority', $priority);
    
    $dueDate = $data['due_date'];
    $stmt->bindParam(':due_date', $dueDate);
    
    if ($stmt->execute()) {
        $taskId = $this->db->lastInsertId();
        
        $getTask = "SELECT t.*, 
                    u1.name as assigned_to_name, u1.email as assigned_to_email,
                    u2.name as created_by_name, u2.email as created_by_email
                    FROM tasks t
                    LEFT JOIN users u1 ON t.assigned_to = u1.id
                    LEFT JOIN users u2 ON t.created_by = u2.id
                    WHERE t.id = :id";
        
        $getTaskStmt = $this->db->prepare($getTask);
        $getTaskStmt->bindParam(':id', $taskId);
        $getTaskStmt->execute();
        
        Response::created($getTaskStmt->fetch(), "Task created successfully");
    } else {
        Response::error("Failed to create task: " . implode(', ', $stmt->errorInfo()), 500);
    }
}

    
    public function update($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Check if task exists
        $checkQuery = "SELECT id, status FROM tasks WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        $existingTask = $checkStmt->fetch();
        
        if (!$existingTask) {
            Response::notFound("Task not found");
        }
        
        $updates = [];
        $params = [':id' => $id];
        
        if (isset($data['title'])) {
            $updates[] = "title = :title";
            $params[':title'] = $data['title'];
        }
        if (isset($data['description'])) {
            $updates[] = "description = :description";
            $params[':description'] = $data['description'];
        }
        if (isset($data['assigned_to'])) {
            $updates[] = "assigned_to = :assigned_to";
            $params[':assigned_to'] = $data['assigned_to'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = :status";
            $params[':status'] = $data['status'];
            
            // If status is completed, set completed_at
            if ($data['status'] === 'completed' && $existingTask['status'] !== 'completed') {
                $updates[] = "completed_at = NOW()";
            }
        }
        if (isset($data['priority'])) {
            $updates[] = "priority = :priority";
            $params[':priority'] = $data['priority'];
        }
        if (isset($data['due_date'])) {
            $updates[] = "due_date = :due_date";
            $params[':due_date'] = $data['due_date'];
        }
        
        if (empty($updates)) {
            Response::error("No fields to update", 400);
        }
        
        $query = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            $getTask = "SELECT t.*, 
                        u1.name as assigned_to_name, u1.email as assigned_to_email,
                        u2.name as created_by_name, u2.email as created_by_email
                        FROM tasks t
                        LEFT JOIN users u1 ON t.assigned_to = u1.id
                        LEFT JOIN users u2 ON t.created_by = u2.id
                        WHERE t.id = :id";
            
            $getTaskStmt = $this->db->prepare($getTask);
            $getTaskStmt->bindParam(':id', $id);
            $getTaskStmt->execute();
            
            Response::updated($getTaskStmt->fetch(), "Task updated successfully");
        } else {
            Response::error("Failed to update task", 500);
        }
    }
    
    public function updateStatus($id) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['status'])) {
            Response::validationError(['status' => 'Status is required']);
        }
        
        $checkQuery = "SELECT id, status FROM tasks WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        $existingTask = $checkStmt->fetch();
        
        if (!$existingTask) {
            Response::notFound("Task not found");
        }
        
        $query = "UPDATE tasks SET status = :status";
        
        if ($data['status'] === 'completed' && $existingTask['status'] !== 'completed') {
            $query .= ", completed_at = NOW()";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::updated(null, "Task status updated successfully");
        } else {
            Response::error("Failed to update task status", 500);
        }
    }
    
    public function delete($id) {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $checkQuery = "SELECT id FROM tasks WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            Response::notFound("Task not found");
        }
        
        $query = "DELETE FROM tasks WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            Response::deleted("Task deleted successfully");
        } else {
            Response::error("Failed to delete task", 500);
        }
    }
    
    public function addComment($taskId) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['comment']) || empty($data['comment'])) {
            Response::validationError(['comment' => 'Comment is required']);
        }
        
        $checkQuery = "SELECT id FROM tasks WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $taskId);
        $checkStmt->execute();
        
        if (!$checkStmt->fetch()) {
            Response::notFound("Task not found");
        }
        
        $query = "INSERT INTO task_comments (task_id, user_id, comment) VALUES (:task_id, :user_id, :comment)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':task_id', $taskId);
        $stmt->bindParam(':user_id', $auth['user_id']);
        $stmt->bindParam(':comment', $data['comment']);
        
        if ($stmt->execute()) {
            $commentId = $this->db->lastInsertId();
            
            $getComment = "SELECT tc.*, u.name as user_name, u.email as user_email, u.profile_image
                           FROM task_comments tc
                           LEFT JOIN users u ON tc.user_id = u.id
                           WHERE tc.id = :id";
            
            $getCommentStmt = $this->db->prepare($getComment);
            $getCommentStmt->bindParam(':id', $commentId);
            $getCommentStmt->execute();
            
            Response::created($getCommentStmt->fetch(), "Comment added successfully");
        } else {
            Response::error("Failed to add comment", 500);
        }
    }
    
    public function getStats() {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $userId = $auth['user_id'];
        
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN due_date < NOW() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue
                  FROM tasks 
                  WHERE assigned_to = :user_id OR created_by = :user_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        Response::success($stmt->fetch());
    }
}
