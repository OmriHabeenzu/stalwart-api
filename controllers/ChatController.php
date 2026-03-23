<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/antispam.php';

class ChatController {
    private $db;
    private $antiSpam;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->antiSpam = new AntiSpam($this->db);
    }
    
    // Customer initiates chat
    public function startChat() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validation
        $errors = [];
        if (!isset($data['name']) || empty($data['name'])) {
            $errors['name'] = 'Name is required';
        }
        if (!isset($data['email']) || empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required';
        }
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Anti-spam check
        $spamCheck = $this->antiSpam->checkSpam($data['email'], $ipAddress, 'chat', $userAgent);
        
        if ($spamCheck['is_spam']) {
            Response::error("Too many chat requests. Please try again later.", 429);
        }
        
        // Create chat session
        $query = "INSERT INTO chat_sessions (visitor_name, visitor_email, ip_address, user_agent, status) 
                  VALUES (:name, :email, :ip, :user_agent, 'active')";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':ip', $ipAddress);
        $stmt->bindParam(':user_agent', $userAgent);
        
        if ($stmt->execute()) {
            $sessionId = $this->db->lastInsertId();
            
            // Add initial message if provided
            if (isset($data['message']) && !empty($data['message'])) {
                $msgQuery = "INSERT INTO chat_messages (session_id, sender_type, message) 
                             VALUES (:session_id, 'visitor', :message)";
                $msgStmt = $this->db->prepare($msgQuery);
                $msgStmt->bindParam(':session_id', $sessionId);
                $msgStmt->bindParam(':message', $data['message']);
                $msgStmt->execute();
            }
            
            Response::created(['session_id' => $sessionId], "Chat session started");
        } else {
            Response::error("Failed to start chat session", 500);
        }
    }
    
    // Customer sends message
    public function sendMessage($sessionId) {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['message']) || empty($data['message'])) {
            Response::validationError(['message' => 'Message is required']);
        }
        
        // Verify session exists and is active
        $checkQuery = "SELECT id, status FROM chat_sessions WHERE id = :id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->bindParam(':id', $sessionId);
        $checkStmt->execute();
        $session = $checkStmt->fetch();
        
        if (!$session) {
            Response::notFound("Chat session not found");
        }
        
        if ($session['status'] !== 'active') {
            Response::error("Chat session is closed", 400);
        }
        
        $query = "INSERT INTO chat_messages (session_id, sender_type, message) 
                  VALUES (:session_id, 'visitor', :message)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $sessionId);
        $stmt->bindParam(':message', $data['message']);
        
        if ($stmt->execute()) {
            $messageId = $this->db->lastInsertId();
            
            $getMessage = "SELECT * FROM chat_messages WHERE id = :id";
            $getStmt = $this->db->prepare($getMessage);
            $getStmt->bindParam(':id', $messageId);
            $getStmt->execute();
            
            Response::created($getStmt->fetch(), "Message sent");
        } else {
            Response::error("Failed to send message", 500);
        }
    }
    
    // Customer gets messages (polling)
    public function getMessages($sessionId) {
        $lastId = isset($_GET['last_id']) ? $_GET['last_id'] : 0;
        
        $query = "SELECT cm.*, u.name as staff_name 
                  FROM chat_messages cm
                  LEFT JOIN users u ON cm.sender_id = u.id
                  WHERE cm.session_id = :session_id AND cm.id > :last_id
                  ORDER BY cm.created_at ASC";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $sessionId);
        $stmt->bindParam(':last_id', $lastId);
        $stmt->execute();
        
        $messages = $stmt->fetchAll();
        Response::success($messages);
    }
    
    // Staff: Get all active chats
    public function getActiveSessions() {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT cs.*, 
                  u.name as assigned_to_name, u.email as assigned_to_email,
                  (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.id AND sender_type = 'visitor' AND is_read = 0) as unread_count,
                  (SELECT message FROM chat_messages WHERE session_id = cs.id ORDER BY created_at DESC LIMIT 1) as last_message,
                  (SELECT created_at FROM chat_messages WHERE session_id = cs.id ORDER BY created_at DESC LIMIT 1) as last_message_time
                  FROM chat_sessions cs
                  LEFT JOIN users u ON cs.assigned_to = u.id
                  WHERE cs.status = 'active'
                  ORDER BY last_message_time DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        Response::success($stmt->fetchAll());
    }
    
    // Staff: Get chat details with messages
    public function getSessionDetails($sessionId) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "SELECT cs.*, u.name as assigned_to_name, u.email as assigned_to_email
                  FROM chat_sessions cs
                  LEFT JOIN users u ON cs.assigned_to = u.id
                  WHERE cs.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $sessionId);
        $stmt->execute();
        
        $session = $stmt->fetch();
        
        if (!$session) {
            Response::notFound("Chat session not found");
        }
        
        // Get messages
        $messagesQuery = "SELECT cm.*, u.name as staff_name, u.profile_image as staff_image
                          FROM chat_messages cm
                          LEFT JOIN users u ON cm.sender_id = u.id
                          WHERE cm.session_id = :session_id
                          ORDER BY cm.created_at ASC";
        
        $messagesStmt = $this->db->prepare($messagesQuery);
        $messagesStmt->bindParam(':session_id', $sessionId);
        $messagesStmt->execute();
        
        $session['messages'] = $messagesStmt->fetchAll();
        
        // Mark visitor messages as read
        $markReadQuery = "UPDATE chat_messages SET is_read = 1 
                          WHERE session_id = :session_id AND sender_type = 'visitor' AND is_read = 0";
        $markReadStmt = $this->db->prepare($markReadQuery);
        $markReadStmt->bindParam(':session_id', $sessionId);
        $markReadStmt->execute();
        
        Response::success($session);
    }
    
    // Staff: Send message to customer
    public function staffSendMessage($sessionId) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['message']) || empty($data['message'])) {
            Response::validationError(['message' => 'Message is required']);
        }
        
        // Check if user has chat permissions
        $userQuery = "SELECT can_chat FROM users WHERE id = :id";
        $userStmt = $this->db->prepare($userQuery);
        $userStmt->bindParam(':id', $auth['user_id']);
        $userStmt->execute();
        $user = $userStmt->fetch();
        
        if (!$user || !$user['can_chat']) {
            Response::forbidden("You don't have permission to chat");
        }
        
        $query = "INSERT INTO chat_messages (session_id, sender_type, sender_id, message) 
                  VALUES (:session_id, 'staff', :sender_id, :message)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':session_id', $sessionId);
        $stmt->bindParam(':sender_id', $auth['user_id']);
        $stmt->bindParam(':message', $data['message']);
        
        if ($stmt->execute()) {
            $messageId = $this->db->lastInsertId();
            
            $getMessage = "SELECT cm.*, u.name as staff_name 
                           FROM chat_messages cm
                           LEFT JOIN users u ON cm.sender_id = u.id
                           WHERE cm.id = :id";
            $getStmt = $this->db->prepare($getMessage);
            $getStmt->bindParam(':id', $messageId);
            $getStmt->execute();
            
            Response::created($getStmt->fetch(), "Message sent");
        } else {
            Response::error("Failed to send message", 500);
        }
    }
    
    // Staff: Assign chat to staff member
    public function assignSession($sessionId) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        $assignedTo = isset($data['assigned_to']) ? $data['assigned_to'] : $auth['user_id'];
        
        $query = "UPDATE chat_sessions SET assigned_to = :assigned_to WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':assigned_to', $assignedTo);
        $stmt->bindParam(':id', $sessionId);
        
        if ($stmt->execute()) {
            Response::updated(null, "Chat assigned successfully");
        } else {
            Response::error("Failed to assign chat", 500);
        }
    }
    
    // Staff: Close chat session
    public function closeSession($sessionId) {
        $auth = AuthMiddleware::requireStaff();
        if (!$auth) return;
        
        $query = "UPDATE chat_sessions SET status = 'closed', closed_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $sessionId);
        
        if ($stmt->execute()) {
            Response::updated(null, "Chat closed successfully");
        } else {
            Response::error("Failed to close chat", 500);
        }
    }
    
    // Cleanup old chat sessions (run periodically via cron)
    public function cleanupOldSessions() {
        $query = "DELETE FROM chat_sessions 
                  WHERE status = 'closed' 
                  AND closed_at < DATE_SUB(NOW(), INTERVAL 2 MONTH)";
        
        $stmt = $this->db->prepare($query);
        
        if ($stmt->execute()) {
            $deleted = $stmt->rowCount();
            Response::success(['deleted_count' => $deleted], "Cleanup completed");
        } else {
            Response::error("Failed to cleanup sessions", 500);
        }
    }
}
