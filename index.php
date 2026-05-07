<?php
// STALWART API v2.1
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// CORS
$allowedOrigins = ['http://localhost:3000','http://localhost:5173','https://stalwartzm.com','https://www.stalwartzm.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(0); }

error_reporting(E_ALL); ini_set('display_errors', 0); ini_set('log_errors', 1);

// ENV
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
// JWT — inlined to avoid require_once deploy issues; fixes base64url decode bug
class JWT {
    private static function key() {
        $k = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');
        return $k ?: 'sk_live_stalwart_7f8a9b2c4d5e6f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5';
    }
    private static function b64u_enc($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    private static function b64u_dec($d) {
        $d = strtr($d, '-_', '+/');
        $pad = 4 - strlen($d) % 4; if ($pad < 4) $d .= str_repeat('=', $pad);
        return base64_decode($d);
    }
    public static function encode($payload) {
        $h = self::b64u_enc(json_encode(['typ'=>'JWT','alg'=>'HS256']));
        $p = self::b64u_enc(json_encode($payload));
        $s = self::b64u_enc(hash_hmac('sha256', "$h.$p", self::key(), true));
        return "$h.$p.$s";
    }
    public static function decode($jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $sig] = $parts;
        $expected = self::b64u_enc(hash_hmac('sha256', "$h.$p", self::key(), true));
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(self::b64u_dec($p), true);
        if (!$payload) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;
        return $payload;
    }
}

define('VAPID_PUBLIC_KEY',  $_ENV['VAPID_PUBLIC_KEY']  ?? 'BPEjZwuRl0g09cq4hPgwt8vwQMM9dCUZjUSz5uy0ChQxHafU4R_pjkX2wSEqEEXWnCLGEBp9sYjS0ZjpUHWqTH4');
define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? 'wd0oVmTeuX1zg98EVtXGr1d4nfkpwZBMC5M-YWNsjbs');
define('VAPID_SUBJECT',     $_ENV['VAPID_SUBJECT']     ?? 'mailto:admin@stalwartzm.com');

// DB
try {
    $pdo = new PDO("mysql:host=".($_ENV['DB_HOST']??'localhost').";dbname=".($_ENV['DB_NAME']??'stalwart').";charset=utf8mb4", $_ENV['DB_USER']??'root', $_ENV['DB_PASS']??'');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'Database connection failed']); exit(1);
}

// SCHEMA MIGRATIONS
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (id INT AUTO_INCREMENT PRIMARY KEY, customer_name VARCHAR(255) NOT NULL, customer_email VARCHAR(255), customer_phone VARCHAR(50), status VARCHAR(50) DEFAULT 'active', last_message TEXT, last_message_time DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, message TEXT NOT NULL, sender_type VARCHAR(50) DEFAULT 'customer', sender_name VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE chat_sessions ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE chat_sessions MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE chat_messages ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE chat_messages MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS media (id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255) NOT NULL, original_filename VARCHAR(255), file_path VARCHAR(500) NOT NULL, file_type VARCHAR(100), file_size INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE media ADD COLUMN file_name VARCHAR(255) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media ADD COLUMN original_filename VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media ADD COLUMN file_path VARCHAR(500) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media ADD COLUMN file_type VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media ADD COLUMN file_size INT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN filename VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN uploaded_by INT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN title VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN description TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN url VARCHAR(500) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN mime_type VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE media MODIFY COLUMN size INT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(500) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, type VARCHAR(50) DEFAULT 'info', pinned TINYINT DEFAULT 0, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE notices MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE notices ADD COLUMN created_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE notices ADD COLUMN is_active TINYINT DEFAULT 1"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN is_read TINYINT DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN phone VARCHAR(50) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_manage_calls TINYINT DEFAULT 0"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(50) DEFAULT 'info', title VARCHAR(255) NOT NULL, message TEXT, link VARCHAR(500), is_read TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE notifications ADD INDEX idx_user_id (user_id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN media_id INT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN education TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN specialties TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN image_url VARCHAR(500) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN linkedin_url VARCHAR(500) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE team_members ADD COLUMN sort_order INT DEFAULT 0"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, position VARCHAR(255), bio TEXT, image_url VARCHAR(500), linkedin_url VARCHAR(500), sort_order INT DEFAULT 0, is_active TINYINT DEFAULT 1, media_id INT DEFAULT NULL, education TEXT, specialties TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    // testimonials: existing tables may use 'testimonial' column instead of 'content' — normalise both
    $pdo->exec("CREATE TABLE IF NOT EXISTS testimonials (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, position VARCHAR(255), company VARCHAR(255), content TEXT, testimonial TEXT, rating INT DEFAULT 5, is_approved TINYINT DEFAULT 0, is_featured TINYINT DEFAULT 0, image VARCHAR(500), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE testimonials ADD COLUMN content TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE testimonials ADD COLUMN testimonial TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE testimonials ADD COLUMN image VARCHAR(500) DEFAULT NULL"); } catch (\Throwable $e) {}
    // copy legacy 'testimonial' → 'content' and vice-versa so both columns carry data
    try { $pdo->exec("UPDATE testimonials SET content=testimonial WHERE content IS NULL AND testimonial IS NOT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("UPDATE testimonials SET testimonial=content WHERE testimonial IS NULL AND content IS NOT NULL"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(255), action VARCHAR(100), description TEXT, ip_address VARCHAR(45), user_agent TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE activity_logs ADD INDEX idx_action (action)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE activity_logs ADD INDEX idx_created (created_at)"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_assignees (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, status ENUM('pending','in_progress','completed') DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_task_user (task_id,user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_comments (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, comment TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_attachments (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, uploaded_by INT NOT NULL, file_name VARCHAR(255) NOT NULL, file_path VARCHAR(500) NOT NULL, file_size INT, mime_type VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN category VARCHAR(50) DEFAULT 'general'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN due_time TIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN recurrence VARCHAR(20) DEFAULT 'none'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN color VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN parent_task_id INT DEFAULT NULL"); } catch (\Throwable $e) {}
} catch (\Throwable $e) {}

// HELPERS
function sendResponse($status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $r = ['status'=>$status,'message'=>$message];
    if ($data !== null) $r['data'] = $data;
    echo json_encode($r); exit();
}
function getRequestData() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function getUserFromToken() {
    $auth = '';
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'authorization') { $auth = $v; break; }
    }
    if (empty($auth)) $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($auth)) return null;
    preg_match('/Bearer\s+(.*)$/i', $auth, $m);
    $token = trim($m[1] ?? '');
    if (empty($token)) return null;
    $payload = JWT::decode($token);
    if (!$payload) return null;
    return ['id'=>$payload['user_id']??null,'email'=>$payload['email']??null,'role'=>$payload['role']??null];
}
function requireAuth($pdo = null) {
    $user = getUserFromToken();
    if (!$user) sendResponse('error','Unauthorized',null,401);
    return $user;
}
function requireAdmin($pdo = null) {
    $user = getUserFromToken();
    if (!$user) sendResponse('error','Unauthorized',null,401);
    if ($user['role'] !== 'admin') sendResponse('error','Forbidden',null,403);
    return $user;
}
function requireCallManager($pdo) {
    $user = getUserFromToken();
    if (!$user) sendResponse('error','Unauthorized',null,401);
    if ($user['role'] === 'admin') return $user;
    $stmt = $pdo->prepare("SELECT can_manage_calls FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row || !$row['can_manage_calls']) sendResponse('error','Forbidden',null,403);
    return $user;
}
function logActivity($pdo, $userId, $username, $action, $description) {
    try {
        $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $username, $action, $description, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null]);
    } catch (Exception $e) {}
}
function createNotification($pdo, $userId, $type, $title, $message, $link = null) {
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $type, $title, $message, $link]);
    } catch (Exception $e) {}
}
function sendEmail($to, $toName, $subject, $htmlBody) {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpUser','smtpPassword','smtpFromEmail','smtpFromName','smtpEncryption')");
        $cfg = []; foreach ($stmt->fetchAll() as $row) $cfg[$row['setting_key']] = $row['setting_value'];
        $host = trim($cfg['smtpHost']??''); $user = trim($cfg['smtpUser']??''); $pass = $cfg['smtpPassword']??'';
        if (empty($host)||empty($user)||empty($pass)) { @mail($to,$subject,$htmlBody,"Content-Type: text/html\r\nFrom: Stalwart <noreply@stalwartzm.com>"); return; }
        $mail = new PHPMailer(true); $mail->isSMTP(); $mail->Host=$host; $mail->Port=(int)($cfg['smtpPort']??587);
        $mail->SMTPAuth=true; $mail->Username=$user; $mail->Password=$pass; $mail->CharSet='UTF-8'; $mail->Timeout=15;
        $enc = strtolower($cfg['smtpEncryption']??'tls');
        if ($enc==='ssl') $mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS;
        elseif ($enc==='tls') $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom(trim($cfg['smtpFromEmail']??'')?: $user, trim($cfg['smtpFromName']??'')?: 'Stalwart Zambia');
        $mail->addAddress($to,$toName); $mail->isHTML(true); $mail->Subject=$subject; $mail->Body=$htmlBody;
        $mail->send();
    } catch (Exception $e) { error_log('Mail error: '.$e->getMessage()); }
}
function base64url_enc($d) { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = '/' . trim(str_replace('/stalwart-api', '', $path), '/');
$method = $_SERVER['REQUEST_METHOD'];

// Serve static uploads directly (works around OLS not always honouring .htaccess !-f)
if ($method === 'GET' && preg_match('#^/uploads/#', $path)) {
    $file = __DIR__ . $path;
    if (file_exists($file) && is_file($file)) {
        $mime = mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    http_response_code(404); exit;
}

// ==========================================
// AUTH
// ==========================================
if ($path === '/auth/login' && $method === 'POST') {
    $data = getRequestData();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    if (empty($email) || empty($password)) sendResponse('error','Email and password required',null,400);
    try {
        $stmt = $pdo->prepare("SELECT id,name,email,password,role,is_active,can_manage_calls,profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) sendResponse('error','Invalid credentials',null,401);
        if (!$user['is_active']) sendResponse('error','Account is inactive',null,403);
        $token = JWT::encode(['user_id'=>$user['id'],'email'=>$user['email'],'role'=>$user['role'],'exp'=>time()+86400*7]);
        unset($user['password']);
        logActivity($pdo,$user['id'],$user['email'],'login','User logged in');
        sendResponse('success','Login successful',['token'=>$token,'user'=>$user]);
    } catch (\Throwable $e) { sendResponse('error','Login failed: '.$e->getMessage(),null,500); }
}

if ($path === '/auth/me' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id,name,email,role,is_active,can_manage_calls,profile_image,phone,department FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $u = $stmt->fetch();
        if (!$u) sendResponse('error','User not found',null,404);
        sendResponse('success','User retrieved',['user'=>$u]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/auth/logout' && $method === 'POST') {
    $user = requireAuth($pdo);
    logActivity($pdo,$user['id'],$user['email'],'logout','User logged out');
    sendResponse('success','Logged out');
}

if ($path === '/auth/refresh' && $method === 'POST') {
    $user = requireAuth($pdo);
    $token = JWT::encode(['user_id'=>$user['id'],'email'=>$user['email'],'role'=>$user['role'],'exp'=>time()+86400*7]);
    sendResponse('success','Token refreshed',['token'=>$token]);
}

if ($path === '/auth/change-password' && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $current = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    if (empty($current)||empty($new)) sendResponse('error','Both passwords required',null,400);
    if (strlen($new)<8) sendResponse('error','Password must be at least 8 characters',null,400);
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?"); $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row||!password_verify($current,$row['password'])) sendResponse('error','Current password incorrect',null,401);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$user['id']]);
        sendResponse('success','Password changed');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// USERS
// ==========================================
if ($path === '/users' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $users = $pdo->query("SELECT id,name,email,role,is_active,can_manage_calls,phone,department,created_at FROM users ORDER BY name")->fetchAll();
        sendResponse('success','Users retrieved',['users'=>$users]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/users/staff' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $users = $pdo->query("SELECT id,name,email,role,can_manage_calls FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
        sendResponse('success','Staff retrieved',['users'=>$users]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/users/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $uid = (int)$m[1];
    $data = getRequestData();
    try {
        $fields = []; $vals = [];
        if (isset($data['name']))             { $fields[]='name=?';             $vals[]=$data['name']; }
        if (isset($data['role']))             { $fields[]='role=?';             $vals[]=$data['role']; }
        if (isset($data['is_active']))        { $fields[]='is_active=?';        $vals[]=(int)$data['is_active']; }
        if (isset($data['can_manage_calls'])) { $fields[]='can_manage_calls=?'; $vals[]=(int)$data['can_manage_calls']; }
        if (isset($data['phone']))            { $fields[]='phone=?';            $vals[]=$data['phone']; }
        if (isset($data['department']))       { $fields[]='department=?';       $vals[]=$data['department']; }
        if (empty($fields)) sendResponse('error','Nothing to update',null,400);
        $vals[] = $uid;
        $pdo->prepare("UPDATE users SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        sendResponse('success','User updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// NOTIFICATIONS
// ==========================================
if ($path === '/notifications' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll();
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $unreadStmt->execute([$user['id']]);
        $unread = (int)$unreadStmt->fetchColumn();
        sendResponse('success','Notifications retrieved',['notifications'=>$notifications,'unread_count'=>$unread]);
    } catch (\Throwable $e) { sendResponse('success','OK',['notifications'=>[],'unread_count'=>0]); }
}

if (preg_match('#^/notifications/(\d+)/read$#',$path,$m) && $method === 'PUT') {
    $user = requireAuth($pdo);
    try {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$m[1],$user['id']]);
        sendResponse('success','Marked read');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/notifications/read-all' && $method === 'PUT') {
    $user = requireAuth($pdo);
    try {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
        sendResponse('success','All marked read');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// PUSH SUBSCRIPTIONS
// ==========================================
if ($path === '/push/vapid-public-key' && $method === 'GET') {
    sendResponse('success','VAPID key',['publicKey'=>VAPID_PUBLIC_KEY]);
}
if ($path === '/push/subscribe' && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, endpoint TEXT NOT NULL, p256dh TEXT, auth TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->prepare("INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh),auth=VALUES(auth)")
            ->execute([$user['id'],$data['endpoint']??'',$data['p256dh']??'',$data['auth']??'']);
        sendResponse('success','Subscribed');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// SETTINGS (minimal)
// ==========================================
if ($path === '/settings' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
        sendResponse('success','Settings retrieved',['settings'=>$settings]);
    } catch (\Throwable $e) { sendResponse('success','OK',['settings'=>[]]); }
}

if ($path === '/settings' && ($method === 'POST' || $method === 'PUT')) {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        foreach ($data as $key => $value) {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$key,$value,$value]);
        }
        sendResponse('success','Settings saved');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// CALL SCHEDULE
// ==========================================
if ($path === '/call-schedule' && $method === 'GET') {
    requireCallManager($pdo);
    try {
        $month = $_GET['month'] ?? date('Y-m');
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $rows  = $pdo->prepare("SELECT cs.*, u.name as user_name, u.email as user_email FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date BETWEEN ? AND ? ORDER BY cs.schedule_date, u.name");
        $rows->execute([$start, $end]);
        $schedule = $rows->fetchAll();
        $staff = $pdo->query("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
        sendResponse('success','Schedule retrieved',['schedule'=>$schedule,'staff'=>$staff]);
    } catch (\Throwable $e) { sendResponse('error','Failed to load schedule: '.$e->getMessage(),null,500); }
}

if ($path === '/call-schedule' && $method === 'POST') {
    requireCallManager($pdo);
    $data = getRequestData();
    try {
        $pdo->exec("DELETE FROM call_schedule WHERE schedule_date IS NOT NULL AND schedule_date >= CURDATE()");
        $stmt = $pdo->prepare("INSERT INTO call_schedule (user_id, role, schedule_date) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)");
        // Handle array format: [{ user_id, schedule_date, role }] (from CallSchedulePage)
        $assignments = $data['assignments'] ?? [];
        if (is_array($assignments) && isset($assignments[0])) {
            foreach ($assignments as $item) {
                if (!empty($item['role']) && !empty($item['schedule_date'])) {
                    $stmt->execute([(int)$item['user_id'], $item['role'], $item['schedule_date']]);
                }
            }
        } else {
            // Fallback: nested object format { date: { userId: role } }
            $scheduleData = $data['schedule'] ?? [];
            foreach ($scheduleData as $date => $dayMap) {
                foreach ($dayMap as $userId => $role) {
                    if (!empty($role)) $stmt->execute([$userId, $role, $date]);
                }
            }
        }
        sendResponse('success','Schedule saved');
    } catch (\Throwable $e) { sendResponse('error','Failed to save schedule: '.$e->getMessage(),null,500); }
}

if ($path === '/call-schedule/today' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT cs.role, cs.user_id, u.name as user_name FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date=? ORDER BY cs.role, u.name");
        $stmt->execute([$today]);
        $rows = $stmt->fetchAll();
        $callers = array_values(array_filter($rows, fn($r) => $r['role']==='caller'));
        $followups = array_values(array_filter($rows, fn($r) => $r['role']==='followup'));
        sendResponse('success','Today schedule',['callers'=>$callers,'followups'=>$followups,'date'=>$today]);
    } catch (\Throwable $e) { sendResponse('success','OK',['callers'=>[],'followups'=>[],'date'=>date('Y-m-d')]); }
}

// ==========================================
// CALL REPORTS
// ==========================================
if ($path === '/call-reports' && $method === 'GET') {
    $user = requireAuth($pdo);
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    try {
        if ($user['role'] === 'admin') {
            $reports = $pdo->query("SELECT cr.*, u.name as user_name FROM call_reports cr LEFT JOIN users u ON u.id=cr.staff_id ORDER BY cr.report_date DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT * FROM call_reports WHERE staff_id=? ORDER BY report_date DESC LIMIT {$limit} OFFSET {$offset}");
            $stmt->execute([$user['id']]);
            $reports = $stmt->fetchAll();
        }
        sendResponse('success','Reports retrieved',['reports'=>$reports]);
    } catch (\Throwable $e) { sendResponse('error','Failed to fetch reports: '.$e->getMessage(),null,500); }
}

if ($path === '/call-reports' && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $reportDate   = $data['report_date'] ?? date('Y-m-d');
    $staffName    = $data['staff_name'] ?? '';
    $entries      = $data['entries'] ?? [];
    $notes        = $data['notes'] ?? '';
    $totalCount   = count($entries);
    $answeredCount = count(array_filter($entries, fn($e) => ($e['status']??'') === 'answered'));
    $unansweredCount = $totalCount - $answeredCount;
    try {
        $existing = $pdo->prepare("SELECT id FROM call_reports WHERE staff_id=? AND report_date=?");
        $existing->execute([$user['id'],$reportDate]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            $reportId = $existingRow['id'];
            $pdo->prepare("UPDATE call_reports SET staff_name=?,total_count=?,answered_count=?,unanswered_count=?,notes=?,updated_at=NOW() WHERE id=?")
                ->execute([$staffName,$totalCount,$answeredCount,$unansweredCount,$notes,$reportId]);
            $pdo->prepare("DELETE FROM call_report_entries WHERE report_id=?")->execute([$reportId]);
        } else {
            $pdo->prepare("INSERT INTO call_reports (report_date,staff_id,staff_name,total_count,answered_count,unanswered_count,notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$reportDate,$user['id'],$staffName,$totalCount,$answeredCount,$unansweredCount,$notes]);
            $reportId = $pdo->lastInsertId();
        }
        $entryStmt = $pdo->prepare("INSERT INTO call_report_entries (report_id,customer_name,customer_phone,status,notes,sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($entries as $i => $entry) {
            $status = in_array($entry['status']??'',['answered','unanswered']) ? $entry['status'] : 'unanswered';
            $entryStmt->execute([$reportId,trim($entry['customer_name']??''),trim($entry['customer_phone']??'')?:null,$status,trim($entry['notes']??'')?:null,$i]);
        }
        logActivity($pdo,$user['id'],$user['email'],'call_report_created',"Call report saved for {$reportDate}");
        sendResponse('success','Report saved',['id'=>$reportId]);
    } catch (\Throwable $e) { sendResponse('error','Failed to save report: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/call-reports/(\d+)$#',$path,$m) && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM call_reports WHERE id=?"); $stmt->execute([$m[1]]);
        $report = $stmt->fetch();
        if (!$report) sendResponse('error','Not found',null,404);
        $stmt2 = $pdo->prepare("SELECT * FROM call_report_entries WHERE report_id=? ORDER BY sort_order");
        $stmt2->execute([$m[1]]);
        sendResponse('success','Report retrieved',['report'=>$report,'entries'=>$stmt2->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/call-reports/today-unanswered' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("SELECT cre.customer_name, cre.customer_phone, cre.notes FROM call_report_entries cre JOIN call_reports cr ON cre.report_id = cr.id WHERE cr.report_date = ? AND cre.status = 'unanswered' ORDER BY cre.sort_order");
        $stmt->execute([$date]);
        sendResponse('success','Unanswered calls retrieved',['entries'=>$stmt->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// GOOGLE CALENDAR
// ==========================================
if ($path === '/calendar/events' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('google_calendar_id','google_service_account_json')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $calendarId = $rows['google_calendar_id'] ?? '';
        $serviceAccountJson = $rows['google_service_account_json'] ?? '';
        if (empty($calendarId)||empty($serviceAccountJson)) sendResponse('success','No calendar configured',['events'=>[]]);
        $sa = json_decode($serviceAccountJson, true);
        if (!$sa) sendResponse('success','Invalid service account',['events'=>[]]);
        $now = time();
        $h = base64url_enc(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $p = base64url_enc(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/calendar.readonly','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3600,'iat'=>$now]));
        $input = "$h.$p";
        openssl_sign($input,$sig,$sa['private_key'],'SHA256');
        $jwt = $input.'.'.base64url_enc($sig);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>15]);
        $tokenData = json_decode(curl_exec($ch),true); curl_close($ch);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) sendResponse('success','Could not get calendar token',['events'=>[]]);
        $date = $_GET['date'] ?? date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=500";
        $ch2 = curl_init($url);
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"],CURLOPT_TIMEOUT=>30]);
        $evData = json_decode(curl_exec($ch2),true); curl_close($ch2);
        sendResponse('success','Events retrieved',['events'=>$evData['items']??[]]);
    } catch (\Throwable $e) { sendResponse('success','Calendar error: '.$e->getMessage(),['events'=>[]]);  }
}

if ($path === '/calendar/today' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('google_calendar_id','google_service_account_json')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $calendarId = $rows['google_calendar_id'] ?? '';
        $serviceAccountJson = $rows['google_service_account_json'] ?? '';
        $hasSA = !empty($serviceAccountJson);
        $hasCal = !empty($calendarId);
        if (!$hasSA && !$hasCal) sendResponse('error','Calendar not configured',['missing'=>'both'],400);
        if (!$hasSA) sendResponse('error','Service account not configured',['missing'=>'service_account'],400);
        if (!$hasCal) sendResponse('error','Calendar ID not configured',['missing'=>'calendar_id'],400);
        $sa = json_decode($serviceAccountJson, true);
        if (!$sa) sendResponse('error','Invalid service account JSON',['missing'=>'service_account'],400);
        $now = time();
        $h = base64url_enc(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $p = base64url_enc(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/calendar.readonly','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3600,'iat'=>$now]));
        $input = "$h.$p";
        openssl_sign($input,$sig,$sa['private_key'],'SHA256');
        $jwt = $input.'.'.base64url_enc($sig);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>15]);
        $tokenData = json_decode(curl_exec($ch),true); curl_close($ch);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);
        $date = $_GET['date'] ?? date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=500";
        $ch2 = curl_init($url);
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"],CURLOPT_TIMEOUT=>30]);
        $evData = json_decode(curl_exec($ch2),true); curl_close($ch2);
        $events = array_map(fn($e) => ['name'=>$e['summary']??'','event_id'=>$e['id']??''], $evData['items']??[]);
        sendResponse('success','Events retrieved',['events'=>$events]);
    } catch (\Throwable $e) { sendResponse('error','Calendar error: '.$e->getMessage(),null,500); }
}

// ==========================================
// DASHBOARD STATS
// ==========================================
if ($path === '/analytics/stats' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
        $logs  = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        $logs7 = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $apps  = ['total'=>0,'pending'=>0,'this_month'=>0];
        $tests = ['total'=>0,'approved'=>0,'pending'=>0];
        $chats = ['total'=>0,'active'=>0,'messages'=>0];
        $tasks = ['total'=>0,'completed'=>0,'pending'=>0];
        $loans = ['active_accounts'=>0,'total_payments'=>0,'pending_payments'=>0,'total_revenue'=>0];
        try { $apps['total']=(int)$pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn(); $apps['pending']=(int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status='pending'")->fetchColumn(); $apps['this_month']=(int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(); } catch(Exception $e){}
        try { $tests['total']=(int)$pdo->query("SELECT COUNT(*) FROM testimonials")->fetchColumn(); $tests['approved']=(int)$pdo->query("SELECT COUNT(*) FROM testimonials WHERE is_approved=1")->fetchColumn(); $tests['pending']=$tests['total']-$tests['approved']; } catch(Exception $e){}
        try { $chats['total']=(int)$pdo->query("SELECT COUNT(*) FROM chat_sessions")->fetchColumn(); $chats['active']=(int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status='active'")->fetchColumn(); $chats['messages']=(int)$pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn(); } catch(Exception $e){}
        try { $tasks['total']=(int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn(); $tasks['completed']=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn(); $tasks['pending']=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn(); } catch(Exception $e){}
        try { $loans['active_accounts']=(int)$pdo->query("SELECT COUNT(*) FROM loan_accounts WHERE loan_status='active'")->fetchColumn(); $loans['total_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments")->fetchColumn(); $loans['pending_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments WHERE status='pending'")->fetchColumn(); $loans['total_revenue']=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='completed'")->fetchColumn(); } catch(Exception $e){}
        sendResponse('success','Stats retrieved',['users'=>['total'=>$users],'logs'=>['total'=>$logs,'last_7_days'=>$logs7],'applications'=>$apps,'testimonials'=>$tests,'chats'=>$chats,'tasks'=>$tasks,'loans'=>$loans]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// TASKS
// ==========================================

if ($path === '/tasks' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $sel = "t.*, GROUP_CONCAT(DISTINCT CONCAT(ta.user_id,'|',REPLACE(COALESCE(u.name,''),'|',''),'|',COALESCE(ta.status,'pending')) ORDER BY u.name SEPARATOR ';;') as assignee_details, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as assignees, (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id=t.id) as comment_count";
        if ($user['role'] === 'admin') {
            $tasks = $pdo->query("SELECT $sel FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id GROUP BY t.id ORDER BY FIELD(t.status,'in_progress','pending','completed'), t.due_date ASC, t.created_at DESC")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT $sel FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE EXISTS (SELECT 1 FROM task_assignees ta2 WHERE ta2.task_id=t.id AND ta2.user_id=?) OR t.created_by=? GROUP BY t.id ORDER BY FIELD(t.status,'in_progress','pending','completed'), t.due_date ASC, t.created_at DESC");
            $stmt->execute([$user['id'],$user['id']]); $tasks = $stmt->fetchAll();
        }
        sendResponse('success','Tasks retrieved',['tasks'=>$tasks]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/tasks' && $method === 'POST') {
    $user = requireAdmin($pdo);
    $data = getRequestData();
    $title = trim($data['title']??'');
    if (empty($title)) sendResponse('error','Title required',null,400);
    try {
        $dueDate = $data['due_date'] ?? $data['dueDate'] ?? null;
        $dueTime = $data['due_time'] ?? $data['dueTime'] ?? null;
        $status  = $data['status'] ?? 'pending';
        $pdo->prepare("INSERT INTO tasks (title,description,status,priority,category,due_date,due_time,recurrence,color,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$title,$data['description']??'',$status,$data['priority']??'medium',$data['category']??'general',$dueDate,$dueTime,$data['recurrence']??'none',$data['color']??null,$user['id']]);
        $taskId = $pdo->lastInsertId();
        $assignees = $data['assignees'] ?? $data['assignee_ids'] ?? [];
        if (!empty($assignees)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,user_id,status) VALUES (?,?,'pending')");
            foreach ($assignees as $uid) { $uid=(int)$uid; if ($uid>0) $stmt->execute([$taskId,$uid]); }
        }
        sendResponse('success','Task created',['id'=>$taskId],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/tasks/bulk-assign' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    $taskIds = $data['task_ids'] ?? [];
    $userId  = (int)($data['user_id'] ?? 0);
    if (empty($taskIds) || !$userId) sendResponse('error','task_ids and user_id required',null,400);
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,user_id,status) VALUES (?,?,'pending')");
        foreach ($taskIds as $tid) $stmt->execute([(int)$tid,$userId]);
        sendResponse('success','Bulk assigned');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)$#',$path,$m) && $method === 'PUT') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $taskId = (int)$m[1];
    try {
        $fields=[]; $vals=[];
        if (isset($data['status']))       { $fields[]='status=?';       $vals[]=$data['status']; }
        if (isset($data['title']))        { $fields[]='title=?';        $vals[]=$data['title']; }
        if (isset($data['description']))  { $fields[]='description=?';  $vals[]=$data['description']; }
        if (isset($data['priority']))     { $fields[]='priority=?';     $vals[]=$data['priority']; }
        if (isset($data['category']))     { $fields[]='category=?';     $vals[]=$data['category']; }
        if (isset($data['recurrence']))   { $fields[]='recurrence=?';   $vals[]=$data['recurrence']; }
        if (isset($data['color']))        { $fields[]='color=?';        $vals[]=$data['color']; }
        if (array_key_exists('due_date',$data)||array_key_exists('dueDate',$data)) { $fields[]='due_date=?'; $vals[]=$data['due_date']??$data['dueDate']??null; }
        if (array_key_exists('due_time',$data)||array_key_exists('dueTime',$data)) { $fields[]='due_time=?'; $vals[]=$data['due_time']??$data['dueTime']??null; }
        if (isset($data['status']) && $data['status']==='completed') { $fields[]='completed_at=NOW()'; }
        elseif (isset($data['status']) && $data['status']!=='completed') { $fields[]='completed_at=NULL'; }
        if (!empty($fields)) { $vals[]=$taskId; $pdo->prepare("UPDATE tasks SET ".implode(',',$fields).",updated_at=NOW() WHERE id=?")->execute($vals); }
        if (isset($data['assignees'])) {
            $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$taskId]);
            if (!empty($data['assignees'])) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,user_id,status) VALUES (?,?,'pending')");
                foreach ($data['assignees'] as $uid) { $uid=(int)$uid; if ($uid>0) $stmt->execute([$taskId,$uid]); }
            }
        }
        sendResponse('success','Task updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    $taskId = (int)$m[1];
    try {
        $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task_comments WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task_attachments WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$taskId]);
        sendResponse('success','Task deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/assignee-status$#',$path,$m) && $method === 'PATCH') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $taskId = (int)$m[1];
    $userId = (int)($data['user_id'] ?? $user['id']);
    $status = $data['status'] ?? 'pending';
    if ($user['role']!=='admin' && $userId!==(int)$user['id']) sendResponse('error','Forbidden',null,403);
    try {
        $pdo->prepare("UPDATE task_assignees SET status=? WHERE task_id=? AND user_id=?")->execute([$status,$taskId,$userId]);
        sendResponse('success','Status updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/comments$#',$path,$m) && $method === 'GET') {
    requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT tc.*,u.name as user_name FROM task_comments tc LEFT JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.created_at ASC");
        $stmt->execute([$taskId]);
        sendResponse('success','Comments retrieved',['comments'=>$stmt->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/comments$#',$path,$m) && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $taskId = (int)$m[1];
    $comment = trim($data['comment'] ?? '');
    if (empty($comment)) sendResponse('error','Comment required',null,400);
    try {
        $pdo->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")->execute([$taskId,$user['id'],$comment]);
        sendResponse('success','Comment added',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/attachments$#',$path,$m) && $method === 'GET') {
    requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT ta.*,u.name as uploaded_by_name FROM task_attachments ta LEFT JOIN users u ON u.id=ta.uploaded_by WHERE ta.task_id=? ORDER BY ta.created_at DESC");
        $stmt->execute([$taskId]);
        $atts = $stmt->fetchAll();
        $base = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
        foreach ($atts as &$a) { $a['url'] = $base.'/'.ltrim($a['file_path'],'/'); }
        sendResponse('success','Attachments retrieved',['attachments'=>$atts]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/attachments$#',$path,$m) && $method === 'POST') {
    $user = requireAuth($pdo);
    $taskId = (int)$m[1];
    if (empty($_FILES['file'])) sendResponse('error','No file uploaded',null,400);
    $file = $_FILES['file'];
    if ($file['error']!==UPLOAD_ERR_OK) sendResponse('error','Upload error',null,400);
    $ext = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','zip'];
    if (!in_array($ext,$allowed)) sendResponse('error','File type not allowed',null,400);
    $dir = __DIR__.'/uploads/tasks/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $fname = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','_',$file['name']);
    if (!move_uploaded_file($file['tmp_name'],$dir.$fname)) sendResponse('error','Failed to save file',null,500);
    try {
        $pdo->prepare("INSERT INTO task_attachments (task_id,uploaded_by,file_name,file_path,file_size,mime_type) VALUES (?,?,?,?,?,?)")
            ->execute([$taskId,$user['id'],$file['name'],'uploads/tasks/'.$fname,$file['size'],$file['type']]);
        sendResponse('success','File uploaded',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/attachments/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAuth($pdo);
    $taskId=(int)$m[1]; $attId=(int)$m[2];
    try {
        $stmt = $pdo->prepare("SELECT file_path FROM task_attachments WHERE id=? AND task_id=?");
        $stmt->execute([$attId,$taskId]); $att=$stmt->fetch();
        if (!$att) sendResponse('error','Not found',null,404);
        @unlink(__DIR__.'/'.$att['file_path']);
        $pdo->prepare("DELETE FROM task_attachments WHERE id=?")->execute([$attId]);
        sendResponse('success','Attachment deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// CHAT SESSIONS
// ==========================================
if ($path === '/chat/sessions' && $method === 'POST') {
    $data = getRequestData();
    $customerName = trim($data['customer_name']??'');
    if (empty($customerName)) sendResponse('error','Name required',null,400);
    try {
        $pdo->prepare("INSERT INTO chat_sessions (customer_name,customer_email,customer_phone,status,last_message_time) VALUES (?,?,?,?,NOW())")
            ->execute([$customerName,$data['customer_email']??'',$data['customer_phone']??'','active']);
        sendResponse('success','Chat session created',['id'=>$pdo->lastInsertId()]);
    } catch (\Throwable $e) { sendResponse('error','Failed to create chat session: '.$e->getMessage(),null,500); }
}

if ($path === '/chat/sessions' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $sessions = $pdo->query("SELECT * FROM chat_sessions ORDER BY updated_at DESC")->fetchAll();
        sendResponse('success','Sessions retrieved',['sessions'=>$sessions]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/chat/summary' && $method === 'GET') {
    try {
        $active=(int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status='active'")->fetchColumn();
        sendResponse('success','Summary',['active'=>$active,'waiting'=>0]);
    } catch (\Throwable $e) { sendResponse('success','OK',['active'=>0,'waiting'=>0]); }
}

if (preg_match('#^/chat/(\d+)$#',$path,$m) && $method === 'GET') {
    try {
        $stmt=$pdo->prepare("SELECT * FROM chat_sessions WHERE id=?"); $stmt->execute([$m[1]]);
        $session=$stmt->fetch();
        if (!$session) sendResponse('error','Not found',null,404);
        $msgs=$pdo->prepare("SELECT * FROM chat_messages WHERE session_id=? ORDER BY created_at ASC");
        $msgs->execute([$m[1]]);
        sendResponse('success','Session retrieved',['session'=>$session,'messages'=>$msgs->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/chat/(\d+)/messages$#',$path,$m) && $method === 'POST') {
    $data=getRequestData();
    $message=trim($data['message']??'');
    $senderType=$data['sender_type']??'customer';
    $senderName=$data['sender_name']??'';
    if (empty($message)) sendResponse('error','Message required',null,400);
    try {
        $pdo->prepare("INSERT INTO chat_messages (session_id,message,sender_type,sender_name) VALUES (?,?,?,?)")
            ->execute([$m[1],$message,$senderType,$senderName]);
        $pdo->prepare("UPDATE chat_sessions SET last_message=?,last_message_time=NOW(),updated_at=NOW() WHERE id=?")->execute([$message,$m[1]]);
        sendResponse('success','Message sent',['id'=>$pdo->lastInsertId()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/chat/(\d+)/staff-message$#',$path,$m) && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $message = trim($data['message'] ?? '');
    if (empty($message)) sendResponse('error','Message required',null,400);
    try {
        $nameStmt = $pdo->prepare("SELECT name FROM users WHERE id=?");
        $nameStmt->execute([$user['id']]);
        $senderName = $nameStmt->fetchColumn() ?: $user['email'];
        $pdo->prepare("INSERT INTO chat_messages (session_id,message,sender_type,sender_name) VALUES (?,?,?,?)")
            ->execute([$m[1],$message,'staff',$senderName]);
        $pdo->prepare("UPDATE chat_sessions SET last_message=?,last_message_time=NOW(),updated_at=NOW() WHERE id=?")->execute([$message,$m[1]]);
        sendResponse('success','Message sent',['id'=>$pdo->lastInsertId()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/chat/(\d+)/close$#',$path,$m) && $method === 'PUT') {
    try {
        $pdo->prepare("UPDATE chat_sessions SET status='closed',updated_at=NOW() WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Session closed');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// PROFILE
// ==========================================
if ($path === '/profile' && $method === 'PUT') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    try {
        $fields=[]; $vals=[];
        if (isset($data['name']))  { $fields[]='name=?';  $vals[]=$data['name']; }
        if (isset($data['phone'])) { $fields[]='phone=?'; $vals[]=$data['phone']; }
        if (empty($fields)) sendResponse('error','Nothing to update',null,400);
        $vals[]=$user['id'];
        $pdo->prepare("UPDATE users SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        sendResponse('success','Profile updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// ADMIN: SEND REMINDERS
// ==========================================
if ($path === '/admin/send-daily-reminders' && $method === 'POST') {
    requireAdmin($pdo);
    $today=$date=date('Y-m-d'); $weekday=(int)date('N');
    $dayNames=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
    $dayName=$dayNames[$weekday]??date('l');
    $sent=0;
    try {
        if ($weekday>=1&&$weekday<=5) {
            $stmt=$pdo->prepare("SELECT cs.role,cs.user_id,u.name,u.email FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date=?");
            $stmt->execute([$today]); $scheduled=$stmt->fetchAll();
            $callerIndex=0; $totalCallers=count(array_filter($scheduled,fn($r)=>$r['role']==='caller'));
            foreach ($scheduled as $r) {
                $roleLabel=$r['role']==='caller'?'making calls':'follow-up on unanswered calls';
                $callerNote='';
                if ($r['role']==='caller'&&$totalCallers>=2) { $half=$callerIndex===0?'first half':'second half'; $callerNote=" (you are responsible for the {$half} of today's client list)"; $callerIndex++; }
                createNotification($pdo,(int)$r['user_id'],'reminder',"Reminder: You are on {$dayName} call duty","You are assigned to {$roleLabel} today{$callerNote}.","/dashboard/call-report");
                if (!empty($r['email'])) { sendEmail($r['email'],$r['name'],"📞 Call Duty Reminder — {$dayName}","<p>Hello {$r['name']},</p><p>You are scheduled for <strong>{$roleLabel}</strong> today{$callerNote}.</p>"); $sent++; }
            }
        }
        sendResponse('success',"Reminders sent",['sent'=>$sent]);
    } catch (\Throwable $e) { sendResponse('error','Failed to send reminders: '.$e->getMessage(),null,500); }
}

// ==========================================
// TESTIMONIALS (public + admin)
// ==========================================
if ($path === '/testimonials' && $method === 'GET') {
    try {
        $authUser = getUserFromToken();
        $isAdmin = ($authUser && in_array($authUser['role'],['admin','super_admin','manager']));
        $sel = "id,name,position,company,COALESCE(content,testimonial,'') AS content,COALESCE(content,testimonial,'') AS testimonial,rating,image,is_approved,is_featured,created_at,updated_at";
        if ($isAdmin) {
            $rows = $pdo->query("SELECT {$sel} FROM testimonials ORDER BY created_at DESC")->fetchAll();
        } else {
            $rows = $pdo->query("SELECT {$sel} FROM testimonials WHERE is_approved=1 ORDER BY created_at DESC LIMIT 20")->fetchAll();
        }
        sendResponse('success','Testimonials retrieved',['testimonials'=>$rows]);
    } catch (\Throwable $e) { sendResponse('success','OK',['testimonials'=>[]]); }
}

if ($path === '/testimonials/all' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $sel = "id,name,position,company,COALESCE(content,testimonial,'') AS content,COALESCE(content,testimonial,'') AS testimonial,rating,image,is_approved,is_featured,created_at,updated_at";
        $all = $pdo->query("SELECT {$sel} FROM testimonials ORDER BY created_at DESC")->fetchAll();
        sendResponse('success','All testimonials',['testimonials'=>$all]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/testimonials' && $method === 'POST') {
    $data=getRequestData();
    $name=trim($data['name']??''); $content=trim($data['content']??$data['testimonial']??'');
    if (empty($name)||empty($content)) sendResponse('error','Name and content required',null,400);
    try {
        $pdo->prepare("INSERT INTO testimonials (name,position,company,content,testimonial,rating,is_approved) VALUES (?,?,?,?,?,?,0)")
            ->execute([$name,$data['position']??'',$data['company']??'',$content,$content,(int)($data['rating']??5)]);
        sendResponse('success','Testimonial submitted',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/testimonials/(\d+)/approve$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    try { $pdo->prepare("UPDATE testimonials SET is_approved=1 WHERE id=?")->execute([$m[1]]); sendResponse('success','Approved'); }
    catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/testimonials/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $status = $data['status'] ?? null;
        if ($status === 'approved') {
            $pdo->prepare("UPDATE testimonials SET is_approved=1 WHERE id=?")->execute([$m[1]]);
            sendResponse('success','Approved');
        } elseif ($status === 'rejected') {
            $pdo->prepare("UPDATE testimonials SET is_approved=0 WHERE id=?")->execute([$m[1]]);
            sendResponse('success','Rejected');
        }
        sendResponse('error','Unknown status',null,400);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/testimonials/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try { $pdo->prepare("DELETE FROM testimonials WHERE id=?")->execute([$m[1]]); sendResponse('success','Deleted'); }
    catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// SETTINGS PUBLIC
// ==========================================
if ($path === '/settings/public' && $method === 'GET') {
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('siteName','siteEmail','sitePhone','siteAddress','main_logo_id','footer_logo_id','favicon_id','loan_repayment_enabled')")->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
        // Resolve logo URLs
        foreach (['main_logo_id','footer_logo_id','favicon_id'] as $key) {
            if (!empty($settings[$key])) {
                try {
                    $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id=?");
                    $stmt->execute([$settings[$key]]);
                    $media = $stmt->fetch();
                    if ($media) {
                        $fp = $media['file_path'];
                        if (strpos($fp,'http')===0) { $p=parse_url($fp); $fp=ltrim($p['path']??$fp,'/'); }
                        $settings[$key] = $fp; // replace numeric ID with relative path
                    }
                } catch(Exception $e) {}
            }
        }
        sendResponse('success','Public settings',['settings'=>$settings]);
    } catch (\Throwable $e) { sendResponse('success','OK',['settings'=>[]]); }
}

// ==========================================
// CONTENT - PUBLIC
// ==========================================
if ($path === '/content/team' && $method === 'GET') {
    try {
        $authUser = getUserFromToken();
        $isAdmin = ($authUser && in_array($authUser['role'],['admin','super_admin','manager']));
        // Admins see all members (including inactive) so they can manage them
        $where = $isAdmin ? '' : 'WHERE t.is_active=1';
        // order_position is the original column name; sort_order is our migration alias
        $team = $pdo->query("SELECT t.*, m.file_path FROM team_members t LEFT JOIN media m ON m.id=t.media_id {$where} ORDER BY COALESCE(t.order_position, t.sort_order, 0) ASC, t.name ASC")->fetchAll();
        $members = array_map(function($row) {
            if (!empty($row['file_path']) && strpos($row['file_path'],'http')===0) {
                $p = parse_url($row['file_path']);
                $row['file_path'] = ltrim($p['path']??$row['file_path'],'/');
            }
            // Preserve existing 'image' column; only fall back to image_url if image is empty
            if (empty($row['image'])) {
                $row['image'] = $row['image_url'] ?? null;
            }
            return $row;
        }, $team);
        sendResponse('success','Team retrieved',['members'=>$members]);
    } catch (\Throwable $e) { sendResponse('success','OK',['members'=>[]]); }
}

if ($path === '/content/team' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO team_members (name,position,bio,image_url,linkedin_url,sort_order,is_active,education,specialties,media_id) VALUES (?,?,?,?,?,?,1,?,?,?)")
            ->execute([$data['name']??'',$data['position']??'',$data['bio']??'',$data['image_url']??'',$data['linkedin_url']??'',(int)($data['sort_order']??0),$data['education']??'',$data['specialties']??'',!empty($data['media_id'])?(int)$data['media_id']:null]);
        sendResponse('success','Team member added',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/content/team/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $fields=[]; $vals=[];
        foreach (['name','position','bio','image_url','linkedin_url','sort_order','is_active','education','specialties'] as $f) {
            if (isset($data[$f])) { $fields[]="$f=?"; $vals[]=$data[$f]; }
        }
        if (array_key_exists('media_id',$data)) { $fields[]="media_id=?"; $vals[]=!empty($data['media_id'])?(int)$data['media_id']:null; }
        if (empty($fields)) sendResponse('error','Nothing to update',null,400);
        $vals[]=(int)$m[1];
        $pdo->prepare("UPDATE team_members SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        sendResponse('success','Updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/content/homepage' && $method === 'GET') {
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('homepage_hero_image_id','homepage_why_choose_image_id')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $images = ['hero_image'=>null,'why_choose_image'=>null];
        foreach (['homepage_hero_image_id'=>'hero_image','homepage_why_choose_image_id'=>'why_choose_image'] as $key=>$imgKey) {
            if (!empty($rows[$key])) {
                try {
                    $stmt=$pdo->prepare("SELECT file_path FROM media WHERE id=?"); $stmt->execute([$rows[$key]]);
                    $media=$stmt->fetch();
                    if ($media) {
                        $fp = $media['file_path'];
                        if (strpos($fp,'http')===0) { $p=parse_url($fp); $fp=ltrim($p['path']??$fp,'/'); }
                        $images[$imgKey] = $fp;
                    }
                } catch(Exception $e){}
            }
        }
        sendResponse('success','Homepage images',['images'=>$images]);
    } catch (\Throwable $e) { sendResponse('success','OK',['images'=>['hero_image'=>null,'why_choose_image'=>null]]); }
}

if ($path === '/content/page-images' && $method === 'GET') {
    try {
        // Read all page image settings (keys like page_about_intro_image_id, page_services_village_image_id, etc.)
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'page_%_id' OR setting_key LIKE 'page_%_image_id'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $images = [];
        foreach ($rows as $key => $mediaId) {
            if (empty($mediaId)) continue;
            try {
                $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id=?");
                $stmt->execute([$mediaId]);
                $media = $stmt->fetch();
                if ($media) {
                    $fp = $media['file_path'];
                    if (strpos($fp,'http')===0) { $p=parse_url($fp); $fp=ltrim($p['path']??$fp,'/'); }
                    $images[$key] = $fp;
                }
            } catch (\Exception $e) {}
        }
        sendResponse('success','Page images',['images'=>$images]);
    } catch (\Throwable $e) { sendResponse('success','OK',['images'=>[]]); }
}

if ($path === '/content/page-images' && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        foreach ($data as $key => $value) {
            if (strpos($key,'page_') === 0) $stmt->execute([$key,$value,$value]);
        }
        sendResponse('success','Page images saved');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/content/page-text' && $method === 'GET') {
    $page = $_GET['page'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT * FROM page_content WHERE page_key=?"); $stmt->execute([$page]);
        $rows = $stmt->fetchAll();
        sendResponse('success','Page text',['content'=>$rows]);
    } catch (\Throwable $e) { sendResponse('success','OK',['content'=>[]]); }
}

if ($path === '/content/page-text' && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pageKey = $data['page_key']??''; $sections = $data['sections']??[];
        $stmt = $pdo->prepare("INSERT INTO page_content (page_key,section_key,content) VALUES (?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content)");
        foreach ($sections as $sectionKey => $content) $stmt->execute([$pageKey,$sectionKey,$content]);
        sendResponse('success','Content saved');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/content/page-text/schema' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $rows = $pdo->query("SELECT DISTINCT page_key FROM page_content ORDER BY page_key")->fetchAll(PDO::FETCH_COLUMN);
        sendResponse('success','Schema',['pages'=>$rows]);
    } catch (\Throwable $e) { sendResponse('success','OK',['pages'=>[]]); }
}

// ==========================================
// CAREERS
// ==========================================
if ($path === '/careers' && $method === 'GET') {
    try {
        $jobs = $pdo->query("SELECT * FROM job_listings WHERE is_active=1 ORDER BY created_at DESC")->fetchAll();
        sendResponse('success','Jobs retrieved',['jobs'=>$jobs]);
    } catch (\Throwable $e) { sendResponse('success','OK',['jobs'=>[]]); }
}

if ($path === '/careers' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO job_listings (title,department,location,type,description,requirements,salary_range,is_active) VALUES (?,?,?,?,?,?,?,1)")
            ->execute([$data['title']??'',$data['department']??'',$data['location']??'',$data['type']??'full-time',$data['description']??'',$data['requirements']??'',$data['salary_range']??'']);
        sendResponse('success','Job created',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/careers/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $fields=[]; $vals=[];
        foreach (['title','department','location','type','description','requirements','salary_range','is_active'] as $f) {
            if (isset($data[$f])) { $fields[]="$f=?"; $vals[]=$data[$f]; }
        }
        $vals[]=(int)$m[1];
        $pdo->prepare("UPDATE job_listings SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        sendResponse('success','Updated');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/careers/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try { $pdo->prepare("UPDATE job_listings SET is_active=0 WHERE id=?")->execute([$m[1]]); sendResponse('success','Deleted'); }
    catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/careers/applications' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $apps = $pdo->query("SELECT ja.*,jl.title as job_title FROM job_applications ja LEFT JOIN job_listings jl ON jl.id=ja.job_id ORDER BY ja.created_at DESC")->fetchAll();
        sendResponse('success','Applications retrieved',['applications'=>$apps]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/careers/(\d+)/apply$#',$path,$m) && $method === 'POST') {
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO job_applications (job_id,name,email,phone,cover_letter,status) VALUES (?,?,?,?,?,'pending')")
            ->execute([$m[1],$data['name']??'',$data['email']??'',$data['phone']??'',$data['cover_letter']??'']);
        sendResponse('success','Application submitted',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/careers/general/apply' && $method === 'POST') {
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO job_applications (job_id,name,email,phone,cover_letter,status) VALUES (NULL,?,?,?,?,'pending')")
            ->execute([$data['name']??'',$data['email']??'',$data['phone']??'',$data['cover_letter']??'']);
        sendResponse('success','Application submitted',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/careers/applications/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pdo->prepare("UPDATE job_applications SET status=?,notes=? WHERE id=?")->execute([$data['status']??'pending',$data['notes']??'',(int)$m[1]]);
        sendResponse('success','Updated');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// LOAN REPAYMENT (public)
// ==========================================
if ($path === '/loans/lookup' && $method === 'GET') {
    $ref = trim($_GET['ref']??''); $nid = trim($_GET['nid']??'');
    if (empty($ref)||empty($nid)) sendResponse('error','Loan reference and NRC required',null,400);
    try {
        $stmt = $pdo->prepare("SELECT id,loan_reference,customer_name,loan_amount,total_repayable,outstanding_balance,loan_status,disbursement_date,maturity_date FROM loan_accounts WHERE loan_reference=? AND national_id_last4=? AND loan_status='active'");
        $stmt->execute([$ref,$nid]);
        $loan = $stmt->fetch();
        if (!$loan) sendResponse('error','No active loan found. Please check your details.',null,404);
        $nameParts = explode(' ',$loan['customer_name']);
        $loan['customer_name'] = $nameParts[0].' '.substr(end($nameParts),0,1).'***';
        $payments = $pdo->prepare("SELECT SUM(amount) as total FROM loan_payments WHERE loan_account_id=? AND status='completed'");
        $payments->execute([$loan['id']]);
        $paid = $payments->fetch()['total'] ?? 0;
        unset($loan['id']);
        sendResponse('success','Loan found',['loan'=>$loan,'total_paid'=>$paid]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/loans/payments' && $method === 'POST') {
    $data = getRequestData();
    $loanRef = trim($data['loan_reference']??''); $amount = (float)($data['amount']??0);
    $paymentMethod = trim($data['payment_method']??''); $nid = trim($data['nrc_last4']??'');
    if (empty($loanRef)||$amount<=0||empty($paymentMethod)||empty($nid)) sendResponse('error','All fields required',null,400);
    try {
        $stmt = $pdo->prepare("SELECT id,customer_name,outstanding_balance FROM loan_accounts WHERE loan_reference=? AND national_id_last4=? AND loan_status='active'");
        $stmt->execute([$loanRef,$nid]); $loan=$stmt->fetch();
        if (!$loan) sendResponse('error','Loan not found',null,404);
        if ($amount>$loan['outstanding_balance']) sendResponse('error','Amount exceeds outstanding balance',null,400);
        $ref = 'PAY-'.strtoupper(uniqid());
        $pdo->prepare("INSERT INTO loan_payments (loan_account_id,payment_reference,amount,payment_method,status,customer_name) VALUES (?,?,?,?,'pending',?)")
            ->execute([$loan['id'],$ref,$amount,$paymentMethod,$loan['customer_name']]);
        sendResponse('success','Payment submitted',['reference'=>$ref],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/loans/payments/([A-Z0-9\-]+)$#',$path,$m) && $method === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT lp.*,la.loan_reference FROM loan_payments lp JOIN loan_accounts la ON lp.loan_account_id=la.id WHERE lp.payment_reference=?");
        $stmt->execute([$m[1]]); $payment=$stmt->fetch();
        if (!$payment) sendResponse('error','Not found',null,404);
        sendResponse('success','Payment status',$payment);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// Admin loan routes
if ($path === '/loans/admin/accounts' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $status = $_GET['status']??'';
        $sql = "SELECT * FROM loan_accounts";
        if ($status) { $stmt=$pdo->prepare($sql." WHERE loan_status=?"); $stmt->execute([$status]); }
        else $stmt=$pdo->query($sql." ORDER BY created_at DESC");
        sendResponse('success','Accounts retrieved',['accounts'=>$stmt->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/loans/admin/accounts' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO loan_accounts (loan_reference,customer_name,customer_phone,customer_email,national_id_last4,loan_amount,total_repayable,amount_paid,outstanding_balance,monthly_installment,loan_status,disbursement_date,maturity_date) VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?)")
            ->execute([$data['loan_reference'],$data['customer_name'],$data['customer_phone'],$data['customer_email']??'',$data['national_id_last4'],$data['loan_amount'],$data['total_repayable'],$data['total_repayable'],$data['monthly_installment'],'active',$data['disbursement_date'],$data['maturity_date']]);
        sendResponse('success','Account created',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) {
        if ($e->getCode()==23000) sendResponse('error','A loan with that reference already exists',null,409);
        sendResponse('error','Failed: '.$e->getMessage(),null,500);
    }
}

if (preg_match('#^/loans/admin/payments/(\d+)/confirm$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    try {
        $stmt=$pdo->prepare("SELECT * FROM loan_payments WHERE id=? AND status='pending'"); $stmt->execute([$m[1]]);
        $payment=$stmt->fetch();
        if (!$payment) sendResponse('error','Payment not found or already processed',null,404);
        $pdo->prepare("UPDATE loan_payments SET status='completed',paid_at=NOW() WHERE id=?")->execute([$m[1]]);
        $pdo->prepare("UPDATE loan_accounts SET amount_paid=amount_paid+?,outstanding_balance=outstanding_balance-? WHERE id=?")->execute([$payment['amount'],$payment['amount'],$payment['loan_account_id']]);
        $balance=$pdo->prepare("SELECT outstanding_balance FROM loan_accounts WHERE id=?"); $balance->execute([$payment['loan_account_id']]); $bal=$balance->fetch();
        if ($bal&&$bal['outstanding_balance']<=0) $pdo->prepare("UPDATE loan_accounts SET loan_status='paid_off',outstanding_balance=0 WHERE id=?")->execute([$payment['loan_account_id']]);
        sendResponse('success','Payment confirmed');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// CONTACT FORM
// ==========================================
if ($path === '/contact' && $method === 'POST') {
    $data = getRequestData();
    $name=trim($data['name']??''); $email=trim($data['email']??''); $message=trim($data['message']??'');
    if (empty($name)||empty($email)||empty($message)) sendResponse('error','All fields required',null,400);
    try {
        $pdo->prepare("INSERT INTO contact_submissions (name,email,phone,subject,message) VALUES (?,?,?,?,?)")
            ->execute([$name,$email,$data['phone']??'',$data['subject']??'General Enquiry',$message]);
        sendResponse('success','Message sent. We will get back to you soon.');
    } catch (\Throwable $e) { sendResponse('error','Failed to submit',null,500); }
}

// ==========================================
// MEDIA (basic)
// ==========================================
if ($path === '/media' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $media = $pdo->query("SELECT id,file_name,file_path,file_type,file_size,created_at FROM media ORDER BY created_at DESC LIMIT 200")->fetchAll();
        // Normalize: strip domain prefix if file_path was stored as a full URL
        foreach ($media as &$m) {
            if (isset($m['file_path']) && strpos($m['file_path'], 'http') === 0) {
                $parsed = parse_url($m['file_path']);
                $m['file_path'] = ltrim($parsed['path'] ?? $m['file_path'], '/');
            }
        }
        unset($m);
        sendResponse('success','Media retrieved',['media'=>$media]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if (preg_match('#^/media/(\d+)$#',$path,$m) && $method === 'GET') {
    try {
        $stmt=$pdo->prepare("SELECT * FROM media WHERE id=?"); $stmt->execute([$m[1]]);
        $media=$stmt->fetch();
        if (!$media) sendResponse('error','Not found',null,404);
        sendResponse('success','Media retrieved',$media);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/media/upload' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (empty($_FILES['file'])) sendResponse('error','No file uploaded',null,400);
    $file = $_FILES['file'];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
    if (!in_array($file['type'], $allowed)) sendResponse('error','Invalid file type',null,400);
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid('img_', true) . '.' . $ext;
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) sendResponse('error','Failed to save file',null,500);
    $filePath = 'uploads/' . $filename;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM media")->fetchAll(), 'Field');
        $insert = ['file_name'=>$filename,'original_filename'=>$file['name'],'file_path'=>$filePath,'file_type'=>$file['type'],'file_size'=>$file['size']];
        if (in_array('filename', $cols)) $insert['filename'] = $filename;
        if (in_array('uploaded_by', $cols)) $insert['uploaded_by'] = $user['id'];
        if (in_array('title', $cols)) $insert['title'] = $file['name'];
        if (in_array('mime_type', $cols)) $insert['mime_type'] = $file['type'];
        if (in_array('size', $cols)) $insert['size'] = $file['size'];
        $keys = implode(',', array_keys($insert));
        $placeholders = implode(',', array_fill(0, count($insert), '?'));
        $pdo->prepare("INSERT INTO media ($keys) VALUES ($placeholders)")->execute(array_values($insert));
        $scheme = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http';
        sendResponse('success','Uploaded',['id'=>$pdo->lastInsertId(),'file_path'=>$filePath,'url'=>$scheme.'://'.$_SERVER['HTTP_HOST'].'/'.$filePath],201);
    } catch (\Throwable $e) { @unlink($targetPath); sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/media/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAuth($pdo);
    try {
        $stmt=$pdo->prepare("SELECT * FROM media WHERE id=?"); $stmt->execute([$m[1]]);
        $media=$stmt->fetch();
        if (!$media) sendResponse('error','Not found',null,404);
        $filePath = __DIR__.'/'.$media['file_path'];
        if (file_exists($filePath)) unlink($filePath);
        $pdo->prepare("DELETE FROM media WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// ACTIVITY LOGS
// ==========================================
if ($path === '/logs' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $limit = min((int)($_GET['limit']??50),200);
        $logs = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT {$limit}")->fetchAll();
        sendResponse('success','Logs retrieved',['logs'=>$logs,'total'=>count($logs)]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// USERS MANAGEMENT (admin)
// ==========================================
if ($path === '/users' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    $name=trim($data['name']??''); $email=trim($data['email']??''); $password=$data['password']??''; $role=$data['role']??'staff';
    if (empty($name)||empty($email)||empty($password)) sendResponse('error','Name, email and password required',null,400);
    try {
        $pdo->prepare("INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,?,1)")
            ->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
        sendResponse('success','User created',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) {
        if ($e->getCode()==23000) sendResponse('error','Email already exists',null,409);
        sendResponse('error','Failed: '.$e->getMessage(),null,500);
    }
}

if (preg_match('#^/users/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try {
        $pdo->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$m[1]]);
        sendResponse('success','User deactivated');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// ANALYTICS STAFF + CALLS
// ==========================================
if ($path === '/analytics/calls' && $method === 'GET') {
    requireAdmin($pdo);
    $month=$_GET['month']??date('Y-m'); [$year,$mon]=explode('-',$month);
    try {
        $summary=$pdo->prepare("SELECT COUNT(*) AS total_reports,COALESCE(SUM(total_count),0) AS total_calls,COALESCE(SUM(answered_count),0) AS answered_calls,COALESCE(SUM(unanswered_count),0) AS unanswered_calls FROM call_reports WHERE YEAR(report_date)=? AND MONTH(report_date)=?");
        $summary->execute([$year,$mon]); $summaryData=$summary->fetch();
        $byStaff=$pdo->prepare("SELECT staff_name,COUNT(*) AS report_days,COALESCE(SUM(total_count),0) AS total_calls,COALESCE(SUM(answered_count),0) AS answered_calls,ROUND(COALESCE(SUM(answered_count),0)/NULLIF(SUM(total_count),0)*100,1) AS answer_rate FROM call_reports WHERE YEAR(report_date)=? AND MONTH(report_date)=? GROUP BY staff_name ORDER BY total_calls DESC");
        $byStaff->execute([$year,$mon]);
        $byDay=$pdo->prepare("SELECT report_date,SUM(total_count) AS total_calls,SUM(answered_count) AS answered_calls FROM call_reports WHERE YEAR(report_date)=? AND MONTH(report_date)=? GROUP BY report_date ORDER BY report_date ASC");
        $byDay->execute([$year,$mon]);
        $months=$pdo->query("SELECT DISTINCT DATE_FORMAT(report_date,'%Y-%m') AS month FROM call_reports ORDER BY month DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
        sendResponse('success','Call analytics retrieved',['month'=>$month,'summary'=>$summaryData,'by_staff'=>$byStaff->fetchAll(),'by_day'=>$byDay->fetchAll(),'available_months'=>$months]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/analytics/staff' && $method === 'GET') {
    requireAdmin($pdo);
    $month=$_GET['month']??date('Y-m'); [$year,$mon]=explode('-',$month);
    try {
        $users=$pdo->query("SELECT id,name,email,role FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
        $performance=[];
        foreach ($users as $u) {
            $taskStmt=$pdo->prepare("SELECT COUNT(DISTINCT t.id) AS total_assigned,SUM(CASE WHEN ta.status='completed' THEN 1 ELSE 0 END) AS total_completed,SUM(CASE WHEN ta.status!='completed' AND t.status='pending' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN ta.status!='completed' AND t.status='in_progress' THEN 1 ELSE 0 END) AS in_progress,SUM(CASE WHEN ta.status='completed' AND YEAR(ta.completed_at)=? AND MONTH(ta.completed_at)=? THEN 1 ELSE 0 END) AS completed_this_month FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id WHERE ta.user_id=?");
            $taskStmt->execute([$year,$mon,$u['id']]); $taskData=$taskStmt->fetch();
            $callStmt=$pdo->prepare("SELECT COUNT(*) AS report_days,COALESCE(SUM(total_count),0) AS total_calls,COALESCE(SUM(answered_count),0) AS answered_calls,COALESCE(SUM(unanswered_count),0) AS unanswered_calls,ROUND(COALESCE(SUM(answered_count),0)/NULLIF(SUM(total_count),0)*100,1) AS answer_rate FROM call_reports WHERE (staff_id=? OR (staff_id IS NULL AND staff_name=?)) AND YEAR(report_date)=? AND MONTH(report_date)=?");
            $callStmt->execute([$u['id'],$u['name'],$year,$mon]); $callData=$callStmt->fetch();
            $performance[]=['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'tasks'=>$taskData,'calls'=>$callData];
        }
        $months=$pdo->query("SELECT DISTINCT DATE_FORMAT(report_date,'%Y-%m') AS month FROM call_reports ORDER BY month DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
        sendResponse('success','Staff performance retrieved',['month'=>$month,'staff'=>$performance,'available_months'=>$months]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// GOOGLE ANALYTICS
// ==========================================
if ($path === '/analytics/google' && $method === 'GET') {
    requireAdmin($pdo);
    $startDate=$_GET['start_date']??date('Y-m-d',strtotime('-30 days'));
    $endDate=$_GET['end_date']??date('Y-m-d');
    try {
        $rows=$pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('ga_property_id','ga_credentials_path')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $propertyId=$rows['ga_property_id']??''; $credPath=$rows['ga_credentials_path']??__DIR__.'/config/ga-credentials.json';
        if (empty($propertyId)) sendResponse('error','Google Analytics not configured.',null,400);
        if (!file_exists($credPath)) sendResponse('error','Credentials file not found.',null,400);
        $credentials=json_decode(file_get_contents($credPath),true);
        if (!$credentials||empty($credentials['client_email'])||empty($credentials['private_key'])) sendResponse('error','Invalid credentials file.',null,400);
        $b64u=fn($d)=>rtrim(strtr(base64_encode($d),'+/','-_'),'=');
        $header=$b64u(json_encode(['alg'=>'RS256','typ'=>'JWT'])); $now=time();
        $claim=$b64u(json_encode(['iss'=>$credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/analytics.readonly','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3600,'iat'=>$now]));
        $sigInput="$header.$claim"; openssl_sign($sigInput,$sig,$credentials['private_key'],'SHA256');
        $jwt="$sigInput.".$b64u($sig);
        $ch=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $tokenResp=json_decode(curl_exec($ch),true); curl_close($ch);
        $accessToken=$tokenResp['access_token']??null;
        if (!$accessToken) sendResponse('error','Could not get access token: '.($tokenResp['error_description']??'unknown'),null,500);
        $gaReq=function($body) use ($propertyId,$accessToken) {
            $ch=curl_init("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport");
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$accessToken]]);
            $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            $data=json_decode($resp,true);
            if ($code!==200) throw new \Exception($data['error']['message']??"GA API returned HTTP {$code}");
            return $data;
        };
        $dr=[['startDate'=>$startDate,'endDate'=>$endDate]];
        $overview=$gaReq(['dateRanges'=>$dr,'metrics'=>[['name'=>'activeUsers'],['name'=>'sessions'],['name'=>'screenPageViews'],['name'=>'bounceRate'],['name'=>'averageSessionDuration'],['name'=>'newUsers']]]);
        $daily=$gaReq(['dateRanges'=>$dr,'dimensions'=>[['name'=>'date']],'metrics'=>[['name'=>'screenPageViews'],['name'=>'activeUsers']],'orderBys'=>[['dimension'=>['dimensionName'=>'date']]]]);
        $topPages=$gaReq(['dateRanges'=>$dr,'dimensions'=>[['name'=>'pagePath']],'metrics'=>[['name'=>'screenPageViews']],'orderBys'=>[['metric'=>['metricName'=>'screenPageViews'],'desc'=>true]],'limit'=>10]);
        $sources=$gaReq(['dateRanges'=>$dr,'dimensions'=>[['name'=>'sessionSource']],'metrics'=>[['name'=>'sessions']],'orderBys'=>[['metric'=>['metricName'=>'sessions'],'desc'=>true]],'limit'=>10]);
        $devices=$gaReq(['dateRanges'=>$dr,'dimensions'=>[['name'=>'deviceCategory']],'metrics'=>[['name'=>'activeUsers']]]);
        $countries=$gaReq(['dateRanges'=>$dr,'dimensions'=>[['name'=>'country']],'metrics'=>[['name'=>'activeUsers']],'orderBys'=>[['metric'=>['metricName'=>'activeUsers'],'desc'=>true]],'limit'=>10]);
        $ov=[];
        if (!empty($overview['rows'][0]['metricValues'])) { $mv=$overview['rows'][0]['metricValues']; $ov=['activeUsers'=>(int)$mv[0]['value'],'sessions'=>(int)$mv[1]['value'],'pageViews'=>(int)$mv[2]['value'],'bounceRate'=>round((float)$mv[3]['value']*100,1),'avgSessionDuration'=>round((float)$mv[4]['value'],1),'newUsers'=>(int)$mv[5]['value']]; }
        $dailyFmt=[];
        foreach ($daily['rows']??[] as $row) { $d=$row['dimensionValues'][0]['value']; $dailyFmt[]=['date'=>substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2),'pageViews'=>(int)$row['metricValues'][0]['value'],'users'=>(int)$row['metricValues'][1]['value']]; }
        $fmt=fn($rows,$dk,$mk)=>array_map(fn($r)=>[$dk=>$r['dimensionValues'][0]['value'],$mk=>(int)$r['metricValues'][0]['value']],$rows['rows']??[]);
        sendResponse('success','OK',['overview'=>$ov,'daily'=>$dailyFmt,'topPages'=>$fmt($topPages,'page','views'),'sources'=>$fmt($sources,'source','sessions'),'devices'=>$fmt($devices,'device','users'),'countries'=>$fmt($countries,'country','users')]);
    } catch (\Throwable $e) { sendResponse('error','Google Analytics error: '.$e->getMessage(),null,500); }
}

if ($path === '/analytics/google/settings' && $method === 'POST') {
    requireAdmin($pdo);
    $data=getRequestData(); $propertyId=trim($data['property_id']??'');
    if (!$propertyId) sendResponse('error','Property ID required',null,400);
    try { $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('ga_property_id',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$propertyId,$propertyId]); sendResponse('success','Saved'); }
    catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/analytics/google/credentials' && $method === 'POST') {
    requireAdmin($pdo);
    if (empty($_FILES['credentials'])) sendResponse('error','No file uploaded',null,400);
    $content=file_get_contents($_FILES['credentials']['tmp_name']);
    $json=json_decode($content,true);
    if (!$json||empty($json['client_email'])||empty($json['private_key'])) sendResponse('error','Invalid service account JSON',null,400);
    $targetPath=__DIR__.'/config/ga-credentials.json';
    if (!move_uploaded_file($_FILES['credentials']['tmp_name'],$targetPath)) sendResponse('error','Failed to save file',null,500);
    try { $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('ga_credentials_path',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$targetPath,$targetPath]); sendResponse('success','Credentials uploaded'); }
    catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// VILLAGE BANKING
// ==========================================
if ($path === '/village-banking/withdrawal' && $method === 'POST') {
    $data=getRequestData();
    $fullName=trim($data['full_name']??''); $nrc=trim($data['nrc_number']??''); $phone=trim($data['phone']??''); $groupName=trim($data['group_name']??'');
    if (empty($fullName)||empty($nrc)||empty($phone)||empty($groupName)) sendResponse('error','Required fields missing',null,400);
    try {
        $pdo->prepare("INSERT INTO village_banking_requests (full_name,nrc_number,phone,email,group_name,group_location,leader_name,leader_phone,request_type,amount,reason,meeting_date,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$fullName,$nrc,$phone,$data['email']??'',$groupName,$data['group_location']??'',$data['leader_name']??'',$data['leader_phone']??'',$data['request_type']??'withdrawal',$data['amount']??'',$data['reason']??'',$data['meeting_date']??'',$data['notes']??'']);
        sendResponse('success','Request submitted successfully.',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed to submit request.',null,500); }
}

// ==========================================
// NOTICES
// ==========================================
if ($path === '/notices' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $notices = $pdo->query("SELECT * FROM notices ORDER BY pinned DESC, created_at DESC LIMIT 50")->fetchAll();
        sendResponse('success','Notices retrieved',['notices'=>$notices]);
    } catch (\Throwable $e) { sendResponse('success','OK',['notices'=>[]]); }
}
if ($path === '/notices' && $method === 'POST') {
    $user = requireAdmin($pdo);
    $data = getRequestData();
    try {
        // Fetch poster's name from DB (getUserFromToken only returns id/email/role)
        $nameRow = $pdo->prepare("SELECT name FROM users WHERE id=?");
        $nameRow->execute([$user['id']]);
        $posterName = $nameRow->fetchColumn() ?: $user['email'];
        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM notices")->fetchColumn();
        $pdo->prepare("INSERT INTO notices (id,title,message,type,pinned,created_by_name) VALUES (?,?,?,?,?,?)")
            ->execute([$nextId,trim($data['title']??''),trim($data['message']??''),$data['type']??'info',(int)($data['pinned']??0),$posterName]);
        sendResponse('success','Notice posted',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}
if (preg_match('#^/notices/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try {
        $pdo->prepare("DELETE FROM notices WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Notice deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// ADMIN CONTACTS
// ==========================================
if ($path === '/admin/contacts' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $limit = min((int)($_GET['limit']??50),200);
        $filter = $_GET['filter']??'all';
        $sql = "SELECT * FROM contact_submissions";
        if ($filter === 'unread') $sql .= " WHERE is_read=0";
        elseif ($filter === 'real') $sql .= " WHERE (phone IS NOT NULL AND phone != '') OR (subject != 'General Enquiry')";
        $sql .= " ORDER BY created_at DESC LIMIT {$limit}";
        $contacts = $pdo->query($sql)->fetchAll();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM contact_submissions")->fetchColumn();
        sendResponse('success','Contacts retrieved',['contacts'=>$contacts,'total'=>$total]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}
if (preg_match('#^/admin/contacts/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try {
        $pdo->prepare("DELETE FROM contact_submissions WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}
if (preg_match('#^/admin/contacts/(\d+)/read$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    try {
        $pdo->prepare("UPDATE contact_submissions SET is_read=1 WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Marked as read');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

// ==========================================
// ACTIVITY LOGS (alias)
// ==========================================
if ($path === '/activity-logs' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $limit = min((int)($_GET['limit']??100),200);
        $offset = (int)($_GET['offset']??0);
        $where = []; $params = [];
        if (!empty($_GET['action'])) { $where[] = 'action=?'; $params[] = $_GET['action']; }
        if (!empty($_GET['date_from'])) { $where[] = 'DATE(created_at)>=?'; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to'])) { $where[] = 'DATE(created_at)<=?'; $params[] = $_GET['date_to']; }
        $wSql = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $stmt = $pdo->prepare("SELECT * FROM activity_logs {$wSql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs {$wSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $actions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
        sendResponse('success','Logs retrieved',['logs'=>$logs,'total'=>$total,'actions'=>$actions]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// AUTH UTILITIES
// ==========================================
if ($path === '/auth/check-reminders' && $method === 'POST') {
    $user = requireAuth($pdo);
    sendResponse('success','OK');
}

// ==========================================
// SITE HEALTH
// ==========================================
if ($path === '/health' && $method === 'GET') {
    $start = microtime(true);
    try { $pdo->query("SELECT 1"); $dbOk = true; } catch (\Exception $e) { $dbOk = false; }
    sendResponse('success','OK',['db'=>$dbOk?'ok':'error','response_ms'=>round((microtime(true)-$start)*1000)]);
}

if ($path === '/admin/health/metrics' && $method === 'GET') {
    requireAdmin($pdo);
    $start = microtime(true);
    $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbName    = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $dbSize    = (int)$pdo->query("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE table_schema=DATABASE()")->fetchColumn();
    $tableCount= (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema=DATABASE()")->fetchColumn();
    $diskPath  = __DIR__;
    $diskTotal = (int)@disk_total_space($diskPath);
    $diskFree  = (int)@disk_free_space($diskPath);
    $diskUsed  = $diskTotal - $diskFree;
    $upPct = 100; $totalChecks = 0; $avgMs = 0;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM uptime_logs WHERE checked_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();
        $up    = (int)$pdo->query("SELECT COUNT(*) FROM uptime_logs WHERE status='up' AND checked_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();
        if ($total > 0) $upPct = round(($up/$total)*100,2);
        $totalChecks = $total;
        $avgMs = (int)$pdo->query("SELECT COALESCE(AVG(response_time_ms),0) FROM uptime_logs WHERE checked_at >= DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetchColumn();
    } catch (\Exception $e) {}
    sendResponse('success','Metrics',['server'=>['php_version'=>phpversion(),'os'=>PHP_OS_FAMILY,'hostname'=>gethostname(),'max_upload'=>ini_get('upload_max_filesize'),'max_post'=>ini_get('post_max_size'),'extensions'=>['PDO'=>extension_loaded('pdo'),'pdo_mysql'=>extension_loaded('pdo_mysql'),'openssl'=>extension_loaded('openssl'),'curl'=>extension_loaded('curl'),'gd'=>extension_loaded('gd'),'mbstring'=>extension_loaded('mbstring'),'json'=>extension_loaded('json'),'zip'=>extension_loaded('zip')]],'memory'=>['used_bytes'=>memory_get_usage(true),'peak_bytes'=>memory_get_peak_usage(true),'limit'=>ini_get('memory_limit')],'disk'=>['total_bytes'=>$diskTotal,'free_bytes'=>$diskFree,'used_bytes'=>$diskUsed,'used_percent'=>$diskTotal>0?round(($diskUsed/$diskTotal)*100,1):0],'database'=>['status'=>'ok','version'=>$dbVersion,'name'=>$dbName,'size_bytes'=>$dbSize,'table_count'=>$tableCount],'api_response_ms'=>round((microtime(true)-$start)*1000),'uptime'=>['percent_30d'=>$upPct,'total_checks'=>$totalChecks,'avg_response_ms'=>$avgMs]]);
}

if ($path === '/admin/uptime-logs' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS uptime_logs (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20) DEFAULT 'up', response_time_ms INT, notes VARCHAR(500), checked_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $start = microtime(true);
        try { $pdo->query("SELECT 1"); $status='up'; $notes=''; } catch (\Exception $e) { $status='down'; $notes=$e->getMessage(); }
        $ms = round((microtime(true)-$start)*1000);
        $pdo->prepare("INSERT INTO uptime_logs (status,response_time_ms,notes) VALUES (?,?,?)")->execute([$status,$ms,$notes]);
        $pdo->exec("DELETE FROM uptime_logs WHERE checked_at < DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $logs = $pdo->query("SELECT * FROM uptime_logs ORDER BY checked_at DESC LIMIT 200")->fetchAll();
        sendResponse('success','Logs',$data=['logs'=>$logs]);
    } catch (\Throwable $e) { sendResponse('success','OK',['logs'=>[]]); }
}

if ($path === '/admin/backups' && $method === 'GET') {
    requireAdmin($pdo);
    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) { sendResponse('success','OK',['backups'=>[]]); exit; }
    $files = glob($backupDir . '*.sql*') ?: [];
    usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
    $backups = array_map(fn($f) => ['id'=>basename($f),'filename'=>basename($f),'size_bytes'=>filesize($f),'created_at'=>date('Y-m-d H:i:s',filemtime($f)),'google_drive_link'=>null], $files);
    sendResponse('success','Backups',['backups'=>$backups]);
}

if ($path === '/admin/backup' && $method === 'POST') {
    requireAdmin($pdo);
    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    $filename = 'stalwart_backup_' . date('Y-m-d_His') . '.sql';
    $filepath = $backupDir . $filename;
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- Stalwart DB Backup " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $table) {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n";
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_NUM);
            if ($rows) {
                $cols = array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
                foreach ($rows as $row) {
                    $vals = array_map(fn($v) => $v===null?'NULL':"'".addslashes($v)."'", $row);
                    $sql .= "INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).");\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($filepath, $sql);
        sendResponse('success','Backup created',['filename'=>$filename,'size_bytes'=>filesize($filepath),'notes'=>null]);
    } catch (\Throwable $e) { @unlink($filepath); sendResponse('error','Backup failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/admin/backups/download/(.+)$#',$path,$bm) && $method === 'GET') {
    requireAdmin($pdo);
    $filename = basename($bm[1]);
    $filepath = __DIR__ . '/backups/' . $filename;
    if (!file_exists($filepath) || !preg_match('/\.sql/', $filename)) sendResponse('error','Not found',null,404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath); exit;
}

if ($path === '/admin/error-logs' && $method === 'GET') {
    requireAdmin($pdo);
    $logFile = __DIR__ . '/logs/errors.log';
    $exists = file_exists($logFile);
    $size   = $exists ? filesize($logFile) : 0;
    $entries = []; $summary = ['fatal'=>0,'warning'=>0,'notice'=>0,'deprecated'=>0,'info'=>0];
    if ($exists && $size > 0) {
        $lines = array_reverse(array_filter(explode("\n", file_get_contents($logFile))));
        foreach (array_slice($lines, 0, 500) as $line) {
            if (empty(trim($line))) continue;
            $severity = 'info';
            if (stripos($line,'fatal') !== false) $severity = 'fatal';
            elseif (stripos($line,'warning') !== false) $severity = 'warning';
            elseif (stripos($line,'notice') !== false) $severity = 'notice';
            elseif (stripos($line,'deprecated') !== false) $severity = 'deprecated';
            $summary[$severity]++;
            preg_match('/\[(\d{2}-\w+-\d{4} \d{2}:\d{2}:\d{2})[^\]]*\]/', $line, $tm);
            preg_match('/in (.+?) on line (\d+)/', $line, $fm);
            $entries[] = [
                'message'        => preg_replace('/\[\d{2}-\w+-\d{4}[^\]]*\]\s*(PHP\s+)?/', '', $line),
                'file'           => $fm[1] ?? null,
                'line'           => $fm[2] ?? null,
                'timestamp'      => $tm[1] ?? null,
                'severity'       => $severity,
                'recommendation' => null,
            ];
        }
    }
    sendResponse('success','Error logs',['entries'=>$entries,'total'=>count($entries),'summary'=>$summary,'log_file_exists'=>$exists,'log_file_size'=>$size]);
}

if ($path === '/admin/error-logs/clear' && $method === 'POST') {
    requireAdmin($pdo);
    $logFile = __DIR__ . '/logs/errors.log';
    if (file_exists($logFile)) file_put_contents($logFile, '');
    sendResponse('success','Log cleared');
}

// ==========================================
// DIAGNOSTICS
// ==========================================
if ($path === '/diagnostics' && $method === 'GET') {
    requireAdmin($pdo);
    $report = [];

    // DB tables + counts
    $tables = ['users','call_reports','call_report_entries','call_schedule','media','notices','contact_submissions','testimonials','settings','tasks','chat_sessions','chat_messages','activity_logs','push_subscriptions','notifications'];
    foreach ($tables as $t) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $report['tables'][$t] = $count;
        } catch (\Throwable $e) {
            $report['tables'][$t] = 'MISSING: '.$e->getMessage();
        }
    }

    // Users table columns
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $report['users_columns'] = $cols;
    } catch (\Throwable $e) { $report['users_columns'] = 'ERROR'; }

    // Sample media records
    try {
        $report['media_sample'] = $pdo->query("SELECT id,file_name,file_path,file_type FROM media LIMIT 5")->fetchAll();
    } catch (\Throwable $e) { $report['media_sample'] = []; }

    // Uploads directory
    $uploadDir = __DIR__ . '/uploads/';
    $report['uploads_dir_exists'] = is_dir($uploadDir);
    $report['uploads_dir_writable'] = is_writable($uploadDir);
    $report['uploads_files'] = is_dir($uploadDir) ? count(glob($uploadDir.'*')) : 0;

    // Key settings
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $report['settings_keys'] = array_keys($rows);
        $report['has_google_sa'] = !empty($rows['google_service_account']);
        $report['has_calendar_id'] = !empty($rows['google_calendar_id']);
    } catch (\Throwable $e) { $report['settings'] = 'ERROR: '.$e->getMessage(); }

    // PHP + DB version
    $report['php_version'] = PHP_VERSION;
    try { $report['mysql_version'] = $pdo->query("SELECT VERSION()")->fetchColumn(); } catch(\Throwable $e){}

    sendResponse('success', 'Diagnostics', $report);
}

// ==========================================
// BOOTSTRAP PULL (deploy extra files from GitHub)
// ==========================================
if ($path === '/deploy/pull' && $method === 'POST') {
    if (($_GET['token'] ?? '') !== 'stalwart2026') sendResponse('error','Forbidden',null,403);
    $data = getRequestData();
    $allowed = ['diagnostic.php', 'deploy.php'];
    $files = array_filter((array)($data['files'] ?? []), fn($f) => in_array($f, $allowed, true));
    $results = [];
    foreach ($files as $file) {
        $content = @file_get_contents("https://raw.githubusercontent.com/OmriHabeenzu/stalwart-api/main/{$file}?t=".time());
        if ($content === false) { $results[$file] = 'FAILED to fetch'; continue; }
        file_put_contents(__DIR__ . '/' . $file, $content);
        $results[$file] = 'OK (' . strlen($content) . ' bytes)';
    }
    sendResponse('success', 'Pulled', $results);
}

// FRONTEND ASSET DEPLOY (upload built files to public_html)
// ==========================================
if ($path === '/deploy-frontend' && $method === 'POST') {
    if (($_GET['token'] ?? '') !== 'stalwart2026') { echo json_encode(['error'=>'Unauthorized']); exit; }
    // API is at /home/stalwartzm.com/api.stalwartzm.com/ — frontend is at ../public_html/
    $frontendDir = realpath(__DIR__ . '/../public_html');
    if (!$frontendDir) {
        echo json_encode(['error'=>'Frontend dir not found','tried'=>__DIR__.'/../public_html']); exit;
    }
    // PHP converts '.' and '-' in field names to '_' in $_FILES keys
    // Map: php_key => [subdir, real_filename]
    $map = [
        'index_html'           => ['', 'index.html'],
        'sw_js'                => ['', 'sw.js'],
        'manifest_webmanifest' => ['', 'manifest.webmanifest'],
        'index_js'             => ['assets/', 'index.js'],
        'index_css'            => ['assets/', 'index.css'],
        'workbox_js'           => ['assets/', 'workbox-window.prod.es5.js'],
    ];
    $results = [];
    foreach ($_FILES as $key => $file) {
        if (!isset($map[$key])) { $results[$key] = 'SKIPPED'; continue; }
        if ($file['error'] !== UPLOAD_ERR_OK) { $results[$key] = 'UPLOAD_ERR '.$file['error']; continue; }
        [$subdir, $realName] = $map[$key];
        $destDir = $frontendDir . '/' . $subdir;
        if ($subdir && !is_dir($destDir)) mkdir($destDir, 0755, true);
        $dest = $destDir . $realName;
        $results[$realName] = move_uploaded_file($file['tmp_name'], $dest)
            ? 'OK (' . $file['size'] . ' bytes)'
            : 'FAILED to write (dest: '.$dest.')';
    }
    if (!is_dir(__DIR__.'/logs')) mkdir(__DIR__.'/logs', 0755, true);
    file_put_contents(__DIR__.'/logs/deploy-frontend.log', date('Y-m-d H:i:s')."\n".json_encode($results)."\n---\n", FILE_APPEND);
    echo json_encode(['success'=>true,'files'=>$results,'time'=>date('Y-m-d H:i:s')]); exit;
}

// 404
sendResponse('error','Route not found',['path'=>$path,'method'=>$method],404);
?>
