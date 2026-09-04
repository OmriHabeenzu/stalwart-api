<?php
// STALWART API v2.1

// CORS — must come before require_once so headers are sent even if vendor is missing
$allowedOrigins = ['http://localhost:3000','http://localhost:5173','https://stalwartzm.com','https://www.stalwartzm.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(0); }

require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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

// SCHEMA MIGRATIONS — guarded so they only run once per hour, not on every request
$_migFile = sys_get_temp_dir() . '/stalwart_mig_' . date('YmdH') . '.lock';
if (!file_exists($_migFile)) { @file_put_contents($_migFile, '1');
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
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_content (id INT AUTO_INCREMENT PRIMARY KEY, page_key VARCHAR(100) NOT NULL, section_key VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, content TEXT, content_type VARCHAR(50) DEFAULT 'text', sort_order INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_page_section (page_key, section_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_token (token), INDEX idx_email (email))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, type VARCHAR(50) DEFAULT 'info', pinned TINYINT DEFAULT 0, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE notices MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE notices ADD COLUMN created_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE notices ADD COLUMN is_active TINYINT DEFAULT 1"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_submissions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(50) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, message TEXT NOT NULL, ip_address VARCHAR(100) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, is_spam TINYINT DEFAULT 0, spam_reason VARCHAR(255) DEFAULT NULL, is_read TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try { $pdo->exec("ALTER TABLE contact_submissions MODIFY id INT NOT NULL AUTO_INCREMENT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN is_read TINYINT DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN phone VARCHAR(50) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN subject VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_completions (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, completed_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_completion_assignees (id INT AUTO_INCREMENT PRIMARY KEY, completion_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_time_entries (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, user_id INT NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME NULL, duration_seconds INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_task (task_id), INDEX idx_user_running (user_id, ended_at))");
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN category VARCHAR(50) DEFAULT 'general'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN due_time TIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN recurrence VARCHAR(20) DEFAULT 'none'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN color VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN parent_task_id INT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD INDEX idx_parent_task_id (parent_task_id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN last_recurred_at DATETIME NULL"); } catch (\Throwable $e) {}
    // These were previously only applied via one-off migration scripts run
    // manually against the local dev DB — never against production, which is
    // why live task creation failed with "Unknown column 'start_date'".
    // Moved inline so every deploy self-heals the live schema too.
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN start_date DATE NULL AFTER due_time"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN maturity_date DATE NULL AFTER start_date"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE tasks ADD COLUMN days_overdue INT NULL DEFAULT NULL AFTER maturity_date"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_assignees ADD COLUMN status ENUM('pending','in_progress','completed') DEFAULT 'pending' AFTER user_id"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_assignees ADD COLUMN completed_at DATETIME NULL AFTER status"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_assignees ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER completed_at"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_documents (id INT AUTO_INCREMENT PRIMARY KEY, loan_account_id INT NOT NULL, document_type ENUM('passport_photo','id_document','receipt','loan_agreement','other') NOT NULL DEFAULT 'other', file_name VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size INT NOT NULL, mime_type VARCHAR(100) NOT NULL, uploaded_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (loan_account_id) REFERENCES loan_accounts(id) ON DELETE CASCADE, FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_loan_account (loan_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY loan_status ENUM('pending','active','rejected','paid_off','defaulted','suspended') DEFAULT 'active'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY disbursement_date DATE NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY maturity_date DATE NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts ADD PRIMARY KEY (id)"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY customer_phone VARCHAR(20) NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE loan_accounts MODIFY national_id_last4 VARCHAR(4) NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_comments MODIFY COLUMN user_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_comments ADD COLUMN user_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_attachments ADD COLUMN mime_type VARCHAR(100) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_attachments MODIFY COLUMN uploaded_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE task_attachments ADD COLUMN uploaded_by_name VARCHAR(255) DEFAULT NULL"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_listings (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, department VARCHAR(100) DEFAULT NULL, location VARCHAR(100) DEFAULT NULL, type VARCHAR(50) DEFAULT 'full-time', description TEXT, requirements TEXT, salary_range VARCHAR(100) DEFAULT NULL, is_active TINYINT DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_applications (id INT AUTO_INCREMENT PRIMARY KEY, job_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(50) DEFAULT NULL, cover_letter TEXT, status VARCHAR(50) DEFAULT 'pending', notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
} catch (\Throwable $e) {}
} // end migration guard

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
// Emails every current assignee of a task with a short list of what changed —
// used on creation (new assignment) and on update (status/reassignment/date
// changes), never on every field edit (title/description/etc. stay silent).
function emailTaskAssignees($pdo, $taskId, $title, $subject, $changeLines) {
    if (empty($changeLines)) return;
    try {
        $stmt = $pdo->prepare("SELECT u.email, u.name FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE ta.task_id=? AND u.email IS NOT NULL AND u.email!=''");
        $stmt->execute([$taskId]);
        $items = implode('', array_map(fn($l) => '<li>' . htmlspecialchars($l) . '</li>', $changeLines));
        foreach ($stmt->fetchAll() as $a) {
            $html = "<p>Hi {$a['name']},</p><p>Task <strong>" . htmlspecialchars($title) . "</strong> has been updated:</p><ul>{$items}</ul><p>— Stalwart Zambia</p>";
            sendEmail($a['email'], $a['name'], $subject, $html);
        }
    } catch (\Throwable $e) {}
}
// Shared by the daily cron (/tasks/daily-check) AND the admin's manual
// "Send Daily Reminders" button (/admin/send-daily-reminders) — finds
// overdue and due-today incomplete tasks and, unless $dryRun, sends one
// in-app + email reminder per assignee (plus bumps days_overdue). Returns
// the raw task rows either way so both callers can report counts/details.
function sendTaskDueReminders($pdo, $dryRun = false) {
    $overdue = $pdo->query("SELECT id, title, due_date, DATEDIFF(CURDATE(), due_date) AS days_overdue FROM tasks WHERE due_date < CURDATE() AND status != 'completed'")->fetchAll();
    $dueToday = $pdo->query("SELECT id, title, due_date FROM tasks WHERE due_date = CURDATE() AND status != 'completed'")->fetchAll();

    if (!$dryRun) {
        $updateStmt = $pdo->prepare("UPDATE tasks SET days_overdue=?, updated_at=NOW() WHERE id=?");
        foreach ($overdue as $t) {
            $updateStmt->execute([$t['days_overdue'], $t['id']]);
            $assignees = $pdo->prepare("SELECT u.id, u.name, u.email FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE ta.task_id=?");
            $assignees->execute([$t['id']]);
            foreach ($assignees->fetchAll() as $a) {
                createNotification($pdo, $a['id'], 'task_overdue', "Task overdue: {$t['title']}", "This task is now {$t['days_overdue']} day(s) overdue.", '/dashboard/tasks');
            }
            emailTaskAssignees($pdo, $t['id'], $t['title'], "Task Overdue: {$t['title']}", ["This task is still pending and is now {$t['days_overdue']} day(s) overdue."]);
        }
        foreach ($dueToday as $t) {
            $assignees = $pdo->prepare("SELECT u.id, u.name, u.email FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE ta.task_id=?");
            $assignees->execute([$t['id']]);
            foreach ($assignees->fetchAll() as $a) {
                createNotification($pdo, $a['id'], 'task_due_today', "Task due today: {$t['title']}", "This task is due today.", '/dashboard/tasks');
            }
            emailTaskAssignees($pdo, $t['id'], $t['title'], "Task Due Today: {$t['title']}", ["This task is due today."]);
        }
    }

    return ['overdue' => $overdue, 'due_today' => $dueToday];
}
// Shared by POST /tasks and POST /tasks/{id}/subtasks — inserts a task row
// (optionally as a child via $parentTaskId), assigns users, and emails them.
// Returns the new task id, or null if title is blank.
function createTaskRow($pdo, $user, $data, $parentTaskId = null) {
    $title = trim($data['title'] ?? '');
    if (empty($title)) return null;
    $dueDate = !empty($data['due_date'] ?? $data['dueDate'] ?? '') ? ($data['due_date'] ?? $data['dueDate']) : null;
    $dueTime = !empty($data['due_time'] ?? $data['dueTime'] ?? '') ? ($data['due_time'] ?? $data['dueTime']) : null;
    $startDate = !empty($data['start_date'] ?? $data['startDate'] ?? '') ? ($data['start_date'] ?? $data['startDate']) : null;
    $maturityDate = !empty($data['maturity_date'] ?? $data['maturityDate'] ?? '') ? ($data['maturity_date'] ?? $data['maturityDate']) : null;
    $status = $data['status'] ?? 'pending';
    $pdo->prepare("INSERT INTO tasks (title,description,status,priority,category,due_date,due_time,start_date,maturity_date,recurrence,color,created_by,parent_task_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$title,$data['description']??'',$status,$data['priority']??'medium',$data['category']??'general',$dueDate,$dueTime,$startDate,$maturityDate,$data['recurrence']??'none',$data['color']??null,$user['id'],$parentTaskId]);
    $taskId = $pdo->lastInsertId();
    $assignees = $data['assignees'] ?? $data['assignee_ids'] ?? [];
    if (!empty($assignees)) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id,user_id,status) VALUES (?,?,'pending')");
        foreach ($assignees as $uid) { $uid=(int)$uid; if ($uid>0) $stmt->execute([$taskId,$uid]); }
        emailTaskAssignees($pdo, $taskId, $title, "New Task Assigned: {$title}", ["You've been assigned to this task."]);
    }
    return $taskId;
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
        header('Cache-Control: public, max-age=31536000, immutable');
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
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) $ip = trim(explode(',', $ip)[0]);
        try { $pdo->prepare("UPDATE users SET last_login=NOW(), last_login_ip=? WHERE id=?")->execute([$ip, $user['id']]); } catch (\Throwable $e) {}
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

if ($path === '/auth/profile' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id,name,email,role,is_active,created_at,last_login,last_login_ip,profile_image,phone,department FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
        if (!$profile) sendResponse('error','Not found',null,404);
        sendResponse('success','Profile retrieved',['profile'=>$profile]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/auth/profile/photo' && $method === 'POST') {
    $user = requireAuth($pdo);
    if (empty($_FILES['photo'])) sendResponse('error','No file uploaded',null,400);
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) sendResponse('error','Upload error',null,400);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($file['type'], $allowed)) sendResponse('error','Only image files allowed',null,400);
    if ($file['size'] > 5 * 1024 * 1024) sendResponse('error','File too large (max 5MB)',null,400);
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $filename = 'avatar_' . $user['id'] . '_' . uniqid() . '.jpg';
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) sendResponse('error','Failed to save file',null,500);
    if (function_exists('imagecreatefromstring')) {
        $src = imagecreatefromstring(file_get_contents($targetPath));
        if ($src) {
            $ow = imagesx($src); $oh = imagesy($src);
            $size = min($ow, $oh);
            $cx = (int)(($ow - $size) / 2); $cy = (int)(($oh - $size) / 2);
            $dst = imagecreatetruecolor(400, 400);
            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, 400, 400, $size, $size);
            imagedestroy($src);
            imagejpeg($dst, $targetPath, 85);
            imagedestroy($dst);
        }
    }
    $filePath = 'uploads/' . $filename;
    try {
        $pdo->prepare("UPDATE users SET profile_image=? WHERE id=?")->execute([$filePath, $user['id']]);
        sendResponse('success','Photo updated', ['profile_image' => $filePath]);
    } catch (\Throwable $e) { sendResponse('error','Failed to save',null,500); }
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
// FORGOT / RESET PASSWORD
// ==========================================
if ($path === '/auth/forgot-password' && $method === 'POST') {
    $data = getRequestData();
    $email = trim(strtolower($data['email'] ?? ''));
    if (empty($email)) sendResponse('error','Email required',null,400);
    try {
        $stmt = $pdo->prepare("SELECT id,name FROM users WHERE email=? AND is_active=1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Always return success to prevent email enumeration
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("DELETE FROM password_resets WHERE email=?")->execute([$email]);
            $pdo->prepare("INSERT INTO password_resets (email,token,expires_at) VALUES (?,?,?)")->execute([$email,$token,$expires]);
            $siteRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_url' LIMIT 1")->fetchColumn();
            $baseUrl = $siteRow ?: 'https://stalwartzm.com';
            $resetLink = rtrim($baseUrl,'/') . '/reset-password?token=' . $token;
            $html = "<p>Hi {$user['name']},</p><p>You requested a password reset. Click the link below to set a new password. This link expires in 1 hour.</p><p><a href='{$resetLink}' style='background:#4f46e5;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;'>Reset Password</a></p><p>If you did not request this, ignore this email.</p><p>— Stalwart Zambia</p>";
            sendEmail($email, $user['name'], 'Reset your Stalwart password', $html);
        }
        sendResponse('success','If that email is registered you will receive a reset link shortly');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/auth/reset-password' && $method === 'POST') {
    $data = getRequestData();
    $token = trim($data['token'] ?? '');
    $newPass = $data['new_password'] ?? '';
    if (empty($token) || empty($newPass)) sendResponse('error','Token and new password required',null,400);
    if (strlen($newPass) < 8) sendResponse('error','Password must be at least 8 characters',null,400);
    try {
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        if (!$reset) sendResponse('error','Invalid or expired reset link',null,400);
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1");
        $userStmt->execute([$reset['email']]);
        $user = $userStmt->fetch();
        if (!$user) sendResponse('error','User not found',null,404);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPass,PASSWORD_DEFAULT),$user['id']]);
        $pdo->prepare("UPDATE password_resets SET used=1 WHERE token=?")->execute([$token]);
        sendResponse('success','Password reset successfully. You can now log in.');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// USERS
// ==========================================
if ($path === '/users' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $users = $pdo->query("SELECT id,name,email,role,is_active,can_manage_calls,phone,department,created_at,last_login FROM users ORDER BY name")->fetchAll();
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
        if (isset($data['email']))            { $fields[]='email=?';            $vals[]=$data['email']; }
        if (isset($data['role']))             { $fields[]='role=?';             $vals[]=$data['role']; }
        if (isset($data['is_active']))        { $fields[]='is_active=?';        $vals[]=(int)$data['is_active']; }
        if (isset($data['status']))           { $fields[]='is_active=?';        $vals[]=$data['status']==='active'?1:0; }
        if (isset($data['can_manage_calls'])) { $fields[]='can_manage_calls=?'; $vals[]=(int)$data['can_manage_calls']; }
        if (isset($data['phone']))            { $fields[]='phone=?';            $vals[]=$data['phone']; }
        if (isset($data['department']))       { $fields[]='department=?';       $vals[]=$data['department']; }
        if (!empty($data['password']))        { $fields[]='password=?';         $vals[]=password_hash($data['password'],PASSWORD_DEFAULT); }
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
        $typeFilter  = $_GET['type']   ?? '';
        $unreadOnly  = ($_GET['unread'] ?? '') === 'true';
        $limit       = min((int)($_GET['limit']  ?? 50), 100);
        $offset      = (int)($_GET['offset'] ?? 0);
        // Always exclude 'message' type (chat notifications) from default view
        $conditions = ['user_id=?'];
        $params     = [$user['id']];
        if ($typeFilter) { $conditions[] = 'type=?'; $params[] = $typeFilter; }
        else             { $conditions[] = "type != 'message'"; }
        if ($unreadOnly)  { $conditions[] = 'is_read=0'; }
        $where = implode(' AND ', $conditions);
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND type != 'message'");
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

if ($path === '/admin/run-schema-fix' && $method === 'POST') {
    // One-time unconditional escape hatch: the hourly migration-guard lock
    // file (keyed by date('YmdH')) can be created by a request that ran
    // BEFORE a schema fix was deployed, which then blocks that same fix from
    // running for the rest of that hour with no way to clear the lock file
    // on a live server without shell access. This bypasses the lock entirely.
    requireAdmin($pdo);
    $stmts = [
        "ALTER TABLE tasks ADD COLUMN start_date DATE NULL AFTER due_time",
        "ALTER TABLE tasks ADD COLUMN maturity_date DATE NULL AFTER start_date",
        "ALTER TABLE tasks ADD COLUMN days_overdue INT NULL DEFAULT NULL AFTER maturity_date",
        "ALTER TABLE tasks ADD COLUMN parent_task_id INT DEFAULT NULL",
        "ALTER TABLE tasks ADD INDEX idx_parent_task_id (parent_task_id)",
        "ALTER TABLE tasks ADD COLUMN last_recurred_at DATETIME NULL",
        "ALTER TABLE task_assignees ADD COLUMN status ENUM('pending','in_progress','completed') DEFAULT 'pending' AFTER user_id",
        "ALTER TABLE task_assignees ADD COLUMN completed_at DATETIME NULL AFTER status",
        "ALTER TABLE task_assignees ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER completed_at",
        "CREATE TABLE IF NOT EXISTS task_completions (id INT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, completed_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS client_documents (id INT AUTO_INCREMENT PRIMARY KEY, loan_account_id INT NOT NULL, document_type ENUM('passport_photo','id_document','receipt','loan_agreement','other') NOT NULL DEFAULT 'other', file_name VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, file_size INT NOT NULL, mime_type VARCHAR(100) NOT NULL, uploaded_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (loan_account_id) REFERENCES loan_accounts(id) ON DELETE CASCADE, FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_loan_account (loan_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE loan_accounts MODIFY loan_status ENUM('pending','active','rejected','paid_off','defaulted','suspended') DEFAULT 'active'",
        "ALTER TABLE loan_accounts MODIFY disbursement_date DATE NULL",
        "ALTER TABLE loan_accounts MODIFY maturity_date DATE NULL",
        "ALTER TABLE loan_accounts ADD PRIMARY KEY (id)",
        "ALTER TABLE loan_accounts MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT",
        "CREATE TABLE IF NOT EXISTS task_completion_assignees (id INT AUTO_INCREMENT PRIMARY KEY, completion_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE loan_accounts MODIFY customer_phone VARCHAR(20) NULL",
        "ALTER TABLE loan_accounts MODIFY national_id_last4 VARCHAR(4) NULL",
    ];
    $results = [];
    foreach ($stmts as $sql) {
        try { $pdo->exec($sql); $results[] = "OK: $sql"; }
        catch (\Throwable $e) { $results[] = "SKIP (".$e->getMessage()."): $sql"; }
    }
    sendResponse('success', 'Schema fix applied', ['results' => $results]);
}

// One-time data repair: tasks completed before the assignee-sync fix existed
// never got their task_assignees.status flipped to 'completed', so Staff
// Performance showed 0 for real historical work this month. Credits every
// assignee of a currently-completed task whose completed_at falls in the
// current calendar month and whose personal status isn't already synced.
// Idempotent — safe to call more than once, only touches rows still pending.
if ($path === '/admin/backfill-task-credits' && $method === 'POST') {
    requireAdmin($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE task_assignees ta JOIN tasks t ON t.id = ta.task_id SET ta.status='completed', ta.completed_at=COALESCE(ta.completed_at, t.completed_at) WHERE t.status='completed' AND YEAR(t.completed_at)=YEAR(CURDATE()) AND MONTH(t.completed_at)=MONTH(CURDATE()) AND ta.status!='completed'");
        $stmt->execute();
        $oneOffRows = $stmt->rowCount();

        // Second gap: task_completions rows logged between when that table was
        // built and when task_completion_assignees was added — those recurring
        // completions have no credit rows at all. Backfill using each task's
        // CURRENT assignees as the best available proxy for who was assigned
        // at completion time.
        $stmt2 = $pdo->prepare("INSERT INTO task_completion_assignees (completion_id, user_id) SELECT tc.id, ta.user_id FROM task_completions tc JOIN task_assignees ta ON ta.task_id = tc.task_id LEFT JOIN task_completion_assignees tca ON tca.completion_id = tc.id AND tca.user_id = ta.user_id WHERE tca.id IS NULL");
        $stmt2->execute();
        $recurringRows = $stmt2->rowCount();

        sendResponse('success', 'Backfill applied', ['task_assignees_updated' => $oneOffRows, 'task_completion_assignees_inserted' => $recurringRows]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/admin/test-email' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    $to = trim($data['email'] ?? '');
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) sendResponse('error','Valid email required',null,400);
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpUser','smtpPassword','smtpFromEmail','smtpFromName','smtpEncryption')");
        $cfg = []; foreach ($stmt->fetchAll() as $row) $cfg[$row['setting_key']] = $row['setting_value'];
        $host = trim($cfg['smtpHost']??''); $user = trim($cfg['smtpUser']??''); $pass = $cfg['smtpPassword']??'';
        if (empty($host)||empty($user)||empty($pass)) {
            @mail($to,'Stalwart Zambia — Test Email','<p>This is a test email sent via PHP mail() (no SMTP configured).</p>',"Content-Type: text/html\r\nFrom: Stalwart <noreply@stalwartzm.com>");
            sendResponse('success','Test email sent via PHP mail() fallback (no SMTP configured)');
        }
        $mail = new PHPMailer(true); $mail->isSMTP(); $mail->Host=$host; $mail->Port=(int)($cfg['smtpPort']??587);
        $mail->SMTPAuth=true; $mail->Username=$user; $mail->Password=$pass; $mail->CharSet='UTF-8'; $mail->Timeout=15;
        $enc = strtolower($cfg['smtpEncryption']??'tls');
        if ($enc==='ssl') $mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS;
        elseif ($enc==='tls') $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom(trim($cfg['smtpFromEmail']??'')?: $user, trim($cfg['smtpFromName']??'')?: 'Stalwart Zambia');
        $mail->addAddress($to); $mail->isHTML(true);
        $mail->Subject = 'Stalwart Zambia — Test Email';
        $mail->Body = '<p>This is a test email confirming your SMTP settings are working.</p>';
        $mail->send();
        sendResponse('success','Test email sent successfully');
    } catch (PHPMailerException $e) {
        sendResponse('error','Send failed: '.$e->getMessage(),null,500);
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
    $user = requireAuth($pdo);
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT cs.role, cs.user_id, u.name as user_name FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date=? ORDER BY cs.role, u.name");
        $stmt->execute([$today]);
        $rows = $stmt->fetchAll();
        $callers   = array_values(array_filter($rows, fn($r) => $r['role']==='caller'));
        $followups = array_values(array_filter($rows, fn($r) => $r['role']==='followup'));
        $myRole = null;
        foreach ($callers   as $c) { if ((int)$c['user_id']===(int)$user['id']) { $myRole='caller';   break; } }
        foreach ($followups as $f) { if ((int)$f['user_id']===(int)$user['id']) { $myRole='followup'; break; } }
        // Wrap in 'today' key — frontend reads res.data.data.today
        sendResponse('success','Today schedule',['today'=>['callers'=>$callers,'followups'=>$followups,'my_role'=>$myRole,'date'=>$today]]);
    } catch (\Throwable $e) { sendResponse('success','OK',['today'=>['callers'=>[],'followups'=>[],'my_role'=>null,'date'=>date('Y-m-d')]]); }
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

// Returns ALL names from reports for a date (answered + unanswered) so we can detect skipped clients
if ($path === '/call-reports/today-names' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("SELECT cre.customer_name, cre.status FROM call_report_entries cre JOIN call_reports cr ON cre.report_id = cr.id WHERE cr.report_date = ? ORDER BY cre.sort_order");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        $unanswered = array_values(array_map(fn($r)=>$r['customer_name'], array_filter($rows, fn($r)=>$r['status']==='unanswered')));
        $answered   = array_values(array_map(fn($r)=>$r['customer_name'], array_filter($rows, fn($r)=>$r['status']==='answered')));
        sendResponse('success','Names retrieved',['unanswered'=>$unanswered,'answered'=>$answered,'total'=>count($rows)]);
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
        $token = getGCalToken($sa);
        if (!$token) sendResponse('success','Could not get calendar token',['events'=>[]]);
        $date = $_GET['date'] ?? date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=500";
        $evData = curlGetGCalRetry($url, $token);
        if ($evData === null) sendResponse('error','Could not reach Google Calendar to list events. Please try again.',null,502);
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
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);
        $date = $_GET['date'] ?? date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=500";
        $evData = curlGetGCalRetry($url, $token);
        if ($evData === null) sendResponse('error','Could not reach Google Calendar to list events. Please try again.',null,502);
        $events = array_map(fn($e) => ['name'=>$e['summary']??'','event_id'=>$e['id']??''], $evData['items']??[]);

        // Server-side caller split — prevents two callers ever seeing the same names
        $user = requireAuth($pdo);
        $isAdmin = ($user['role'] === 'admin');
        $callerPosition = null;
        $totalCallers = 0;

        $totalEventsInCalendar = count($events); // for debug

        if (!$isAdmin) {
            // Find ordered list of callers scheduled for this specific date
            $stmt = $pdo->prepare(
                "SELECT user_id FROM call_schedule WHERE schedule_date=? AND role='caller' ORDER BY id ASC"
            );
            $stmt->execute([$date]);
            $callerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $totalCallers = count($callerIds);

            if ($totalCallers >= 2) {
                $pos = array_search((int)$user['id'], array_map('intval', $callerIds));
                if ($pos !== false) {
                    $callerPosition = (int)$pos;
                    // N-way split: divide evenly among all callers
                    $total = count($events);
                    $chunkSize = (int)ceil($total / $totalCallers);
                    $start = $callerPosition * $chunkSize;
                    $events = array_slice($events, $start, $chunkSize);
                }
            }
        }

        sendResponse('success','Events retrieved',[
            'events'              => $events,
            'total_callers'       => $totalCallers,
            'your_position'       => $callerPosition,
            'total_in_calendar'   => $totalEventsInCalendar, // debug: raw count before split
        ]);
    } catch (\Throwable $e) { sendResponse('error','Calendar error: '.$e->getMessage(),null,500); }
}

// ==========================================
// EVENT PLANNER — shared helpers
// ==========================================
function getGCalToken($sa) {
    $now = time();
    $h = base64url_enc(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $p = base64url_enc(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/calendar','aud'=>'https://oauth2.googleapis.com/token','exp'=>$now+3600,'iat'=>$now]));
    $input = "$h.$p"; openssl_sign($input,$sig,$sa['private_key'],'SHA256');
    $jwt = $input.'.'.base64url_enc($sig);

    // The connection to Google's token endpoint is intermittently flaky from this
    // network — some attempts hang until timeout while immediately-retried ones
    // succeed in ~1-2s. Retry a few times with a short per-attempt timeout rather
    // than making the caller wait out one long timeout and fail outright.
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>5]);
        $result = curl_exec($ch);
        curl_close($ch);
        $d = json_decode($result, true);
        if (isset($d['access_token'])) return $d['access_token'];
    }
    return null;
}

// Same intermittent-network problem affects the actual Calendar API calls, not
// just the token exchange. A plain curl_exec() that fails (timeout/DNS/reset)
// returns false, and json_decode(false) is null — which every call site here
// used to treat identically to "Google returned zero items", silently masking
// real connectivity failures as empty results. This distinguishes the two:
// returns the decoded response on success (even if genuinely empty), or null
// only when every retry truly failed to reach Google at all.
function curlGetGCalRetry($url, $token, $maxAttempts = 4, $timeout = 6) {
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}"],CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>$timeout]);
        $result = curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);
        if ($ok) {
            $decoded = json_decode($result, true);
            if ($decoded !== null) return $decoded;
        }
    }
    return null;
}
function getGCalSettings($pdo) {
    return $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('google_calendar_id','google_service_account_json')")->fetchAll(\PDO::FETCH_KEY_PAIR);
}
function parseKBalance($desc) {
    if (!$desc) return null;
    preg_match_all('/K([\d,]+\.?\d*)/i',$desc,$m);
    if (empty($m[1])) return null;
    return max(array_map(fn($a)=>floatval(str_replace(',','',$a)),$m[1]));
}
// Same "due on <date>" / "due date: <date>" pattern updateDateInDesc() writes
// (~line 999) — this reads it back out, for pulling a real next-payment date
// out of a calendar reminder's description instead of leaving it blank.
function parseDueDateFromDesc($desc) {
    if (!$desc) return null;
    $pat = '\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+,?\s*\d{4}';
    foreach (["/due on\s+({$pat})/i", "/due\s*date\s*:?\s*({$pat})/i"] as $re) {
        if (preg_match($re, $desc, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false) return date('Y-m-d', $ts);
        }
    }
    return null;
}
// Real calendar descriptions never carry a distinct "matures on <date>" line —
// the only date present is the same "due on <date>" collection date. When the
// description explicitly labels the loan "past maturity" (checked across all
// live events: "REDUCING BALANCE"/"ROLL OVER MATTERS" reminders never use this
// wording, only genuinely-matured loans do), that due date IS the maturity
// date being chased, so it's reused here rather than left blank.
function parseMaturityDateFromDesc($desc) {
    if (!$desc || !preg_match('/matur/i', $desc)) return null;
    return parseDueDateFromDesc($desc);
}
function replaceKBalance($desc,$newBal) {
    preg_match_all('/K([\d,]+\.?\d*)/',$desc,$m,PREG_OFFSET_CAPTURE);
    if (empty($m[0])) return trim($desc)."\nCurrent balance: K".number_format($newBal,2);
    $maxAmt=0;$maxIdx=0;
    foreach ($m[1] as $i=>$match) { $amt=floatval(str_replace(',','',$match[0])); if($amt>$maxAmt){$maxAmt=$amt;$maxIdx=$i;} }
    return substr_replace($desc,'K'.number_format($newBal,2),$m[0][$maxIdx][1],strlen($m[0][$maxIdx][0]));
}
// Mirrors the frontend's isCleared()/getLoanCategory() (EventPlannerPage.jsx /
// ClientsPage.jsx) — same naming convention embedded in calendar event titles:
// "." = stagnant, "," = active, else paid (only if explicitly marked
// cleared/K0) or active otherwise. Standby is NOT part of this — it's a
// same-session, admin-only visual flag on the Reschedule list and is never
// written to the calendar (previously used a "?" marker; removed since
// Standby never needs to survive past the current browser session).
function isEventCleared($name, $desc = '') {
    if (stripos($name, 'cleared') !== false) return true;
    if (stripos($desc, 'cleared') !== false) return true;
    if (preg_match('/K\s*0+(\.0+)?\b/i', $desc)) return true;
    return false;
}
function getEventCategory($name, $desc = '') {
    if (strpos($name, '.') !== false) return 'stagnant';
    if (strpos($name, ',') !== false) return 'active';
    if (isEventCleared($name, $desc)) return 'paid';
    return 'active';
}
function formatOrdinalDate($dateStr) {
    $d=new \DateTime($dateStr); $day=(int)$d->format('j');
    $s=match(true){in_array($day,[11,12,13])=>'th',$day%10===1=>'st',$day%10===2=>'nd',$day%10===3=>'rd',default=>'th'};
    return "{$day}{$s} ".$d->format('F, Y');
}
function updateDateInDesc($desc,$newDate) {
    $fmt=formatOrdinalDate($newDate);
    $pat='\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]+,?\s*\d{4}';
    foreach(["/(due on\s+){$pat}/i","/(due\s*date\s*:?\s*){$pat}/i"] as $re) {
        if (preg_match($re,$desc)) return preg_replace($re,'${1}'.$fmt,$desc);
    }
    return $desc;
}

// ==========================================
// EVENT PLANNER — list events (write scope)
// ==========================================
if ($path === '/planner/events' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $date = $_GET['date'] ?? date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=2500";
        $evData = curlGetGCalRetry($url, $token);
        if ($evData === null) sendResponse('error','Could not reach Google Calendar to list events. Please try again.',null,502);

        $includeDesc = ($_GET['desc'] ?? '') === '1';
        $events = [];
        foreach ($evData['items'] ?? [] as $e) {
            $name = trim($e['summary'] ?? '');
            // Busy-marker events (any variant — "Busy", "Busy Day", etc.) aren't
            // real clients — exclude them so they never show up as a fake
            // selectable name in the Reschedule list or Clients headcount.
            if (!$name || stripos($name, 'busy') !== false) continue;
            $item = ['id'=>$e['id'],'name'=>$name];
            if ($includeDesc) $item['description'] = $e['description'] ?? '';
            $events[] = $item;
        }
        usort($events, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
        sendResponse('success','Events loaded',['events'=>$events,'total'=>count($events)]);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
}

// ==========================================
// EVENT PLANNER — reschedule events
// ==========================================
if ($path === '/planner/reschedule' && $method === 'POST') {
    requireAdmin($pdo);
    try {
        // Frontend sends one event at a time — no sleep needed here; delay is handled client-side
        $body = json_decode(file_get_contents('php://input'), true);
        // Accept either {event_id, new_date, new_balance?} (single) or {events:[{id,new_balance?}], new_date} (batch)
        $newDate = $body['new_date'] ?? '';
        if (!$newDate || !\DateTime::createFromFormat('Y-m-d', $newDate)) sendResponse('error','Invalid or missing new_date',null,400);

        // Normalise to array of {id, new_balance?}
        if (isset($body['event_id'])) {
            $events = [['id'=>$body['event_id'], 'new_balance'=>$body['new_balance']??null]];
        } else {
            $events = $body['events'] ?? [];
            // backward compat: plain event_ids array
            if (empty($events) && !empty($body['event_ids'])) {
                $events = array_map(fn($id)=>['id'=>$id,'new_balance'=>null], $body['event_ids']);
            }
        }
        if (empty($events)) sendResponse('error','No events provided',null,400);

        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $result = rescheduleEvents($token, $calendarId, $events, $newDate);
        sendResponse('success','Reschedule complete',$result);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
}

// Shared by /planner/reschedule (manual, admin-initiated) and /planner/auto-reschedule
// (cron-triggered daily sweep) — moves each event's date to $newDate, optionally
// updating its balance, and reports back per-item success/failure. $events is
// [{id, new_balance?}].
function rescheduleEvents($token, $calendarId, $events, $newDate) {
    $endDate = date('Y-m-d', strtotime($newDate . ' +1 day'));
    $success = 0; $failed = [];
    $baseUrl = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events/";

    foreach ($events as $ev) {
        $eventId   = $ev['id'] ?? '';
        $newBalance= isset($ev['new_balance']) && $ev['new_balance'] > 0 ? floatval($ev['new_balance']) : null;
        if (!$eventId) continue;

        // GET event to read current description
        $evData = curlGetGCalRetry($baseUrl.urlencode($eventId), $token);
        if ($evData === null) {
            $failed[] = "{$eventId}: could not reach Google Calendar to read this event — skipped";
            continue;
        }

        $desc = updateDateInDesc($evData['description'] ?? '', $newDate);
        if ($newBalance !== null) $desc = replaceKBalance($desc, $newBalance);

        // Preserve the original time format: timed events use dateTime, all-day use date.
        // Google silently ignores date changes when the format doesn't match the original.
        if (isset($evData['start']['dateTime'])) {
            // Timed event — replace only the date portion (first 10 chars), keep the time+tz suffix
            $tSuffix  = substr($evData['start']['dateTime'], 10);
            $endSuffix= isset($evData['end']['dateTime']) ? substr($evData['end']['dateTime'], 10) : $tSuffix;
            $payload = json_encode([
                'start'       => ['dateTime' => $newDate . $tSuffix,   'timeZone' => $evData['start']['timeZone'] ?? 'Africa/Lusaka'],
                'end'         => ['dateTime' => $newDate . $endSuffix, 'timeZone' => $evData['end']['timeZone']   ?? 'Africa/Lusaka'],
                'description' => $desc,
            ]);
        } else {
            $payload = json_encode(['start'=>['date'=>$newDate],'end'=>['date'=>$endDate],'description'=>$desc]);
        }
        $ch = curl_init($baseUrl.urlencode($eventId).'?sendUpdates=all');
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}","Content-Type: application/json"],CURLOPT_TIMEOUT=>15]);
        $res = json_decode(curl_exec($ch),true);
        $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

        if ($code===200 && isset($res['id'])) {
            // Verify Google actually moved the event — 200 doesn't guarantee the date changed
            $returnedDate = $res['start']['date'] ?? substr($res['start']['dateTime'] ?? '', 0, 10);
            if ($returnedDate === $newDate) {
                $success++;
            } else {
                $evName = $evData['summary'] ?? $eventId;
                $failed[] = $evName . ': date not updated (still ' . ($returnedDate ?: 'unknown') . ') — format mismatch?';
            }
        } else {
            $errMsg = $res['error']['message'] ?? "HTTP $code";
            $failed[] = ($evData['summary']??$eventId).': '.$errMsg;
        }
    }
    return ['success'=>$success,'failed'=>$failed,'total'=>count($events)];
}

// ==========================================
// EVENT PLANNER — automated daily reschedule (cron-triggered, not an admin session)
// ==========================================
// Reschedules everything to tomorrow — Standby no longer exists as a calendar
// marker (it's a same-session, admin-only visual flag on the Reschedule list
// now), so there's nothing left for this cron to skip.
if ($path === '/planner/auto-reschedule' && $method === 'GET') {
    $providedToken = $_GET['token'] ?? '';
    $cronSecret = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='cron_secret'")->fetchColumn();
    if (!$cronSecret || !is_string($providedToken) || !hash_equals((string)$cronSecret, $providedToken)) {
        sendResponse('error','Unauthorized',null,401);
    }

    $dryRun = ($_GET['dry_run'] ?? '') === '1';

    try {
        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $gToken = getGCalToken($sa);
        if (!$gToken) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $today = date('Y-m-d');
        $tz = new \DateTimeZone('Africa/Lusaka');
        $timeMin = urlencode((new \DateTime($today.' 00:00:00',$tz))->format(\DateTime::RFC3339));
        $timeMax = urlencode((new \DateTime($today.' 23:59:59',$tz))->format(\DateTime::RFC3339));
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=2500";
        $evData = curlGetGCalRetry($url, $gToken);
        if ($evData === null) sendResponse('error','Could not reach Google Calendar to list events. Please try again.',null,502);

        $eligible = [];
        foreach ($evData['items'] ?? [] as $e) {
            $name = trim($e['summary'] ?? '');
            // Any variant containing "busy" (not just an exact "Busy" match) —
            // real calendar entries have drifted to "Busy Day", "Busy " etc,
            // which an exact strcasecmp() silently let through as real clients.
            if (!$name || stripos($name, 'busy') !== false) continue;
            $eligible[] = ['id'=>$e['id'], 'name'=>$name, 'new_balance'=>null];
        }

        $newDate = date('Y-m-d', strtotime($today.' +1 day'));

        if ($dryRun) {
            sendResponse('success','Dry run — nothing was changed',[
                'date'=>$today, 'new_date'=>$newDate,
                'would_reschedule'=>count($eligible), 'eligible_names'=>array_column($eligible,'name'),
            ]);
        }

        $result = rescheduleEvents($gToken, $calendarId, $eligible, $newDate);

        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id,username,action,description,created_at) VALUES (NULL,'cron',?,?,NOW())")
                ->execute(["auto_reschedule", json_encode(['date'=>$today,'new_date'=>$newDate,'success'=>$result['success'],'failed'=>count($result['failed'])])]);
        } catch (\Throwable $e) {}

        sendResponse('success','Auto-reschedule complete', $result);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
}

// ==========================================
// EVENT PLANNER — loans (balances)
// ==========================================
if ($path === '/planner/loans' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $date = $_GET['date'] ?? date('Y-m-d');
        $tz = new \DateTimeZone('Africa/Lusaka');
        $timeMin = urlencode((new \DateTime($date.' 00:00:00',$tz))->format(\DateTime::RFC3339));
        $timeMax = urlencode((new \DateTime($date.' 23:59:59',$tz))->format(\DateTime::RFC3339));
        $baseUrl = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events";

        $loans = []; $pageToken = '';
        do {
            $url = "{$baseUrl}?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=2500".($pageToken?"&pageToken={$pageToken}":'');
            $evData = curlGetGCalRetry($url, $token);
            if ($evData === null) sendResponse('error','Could not reach Google Calendar to list loans. Please try again.',null,502);
            foreach ($evData['items']??[] as $e) {
                $title = trim($e['summary']??'');
                // Skip stagnant loans (. or , in title), busy (any variant), blank
                if (!$title || stripos($title,'busy')!==false) continue;
                if (strpos($title,'.')!==false || strpos($title,',')!==false) continue;
                $desc    = $e['description'] ?? '';
                $balance = parseKBalance($desc);
                $start   = $e['start']['date'] ?? substr($e['start']['dateTime']??'',0,10);
                $loans[] = ['id'=>$e['id'],'name'=>$title,'balance'=>$balance,'description'=>$desc,'date'=>$start];
            }
            $pageToken = $evData['nextPageToken'] ?? '';
        } while ($pageToken);

        usort($loans, function($a,$b) {
            if (($a['balance']===null)!==($b['balance']===null)) return $a['balance']===null?1:-1;
            return strcasecmp($a['name'],$b['name']);
        });
        $withBal   = array_values(array_filter($loans,fn($l)=>$l['balance']!==null));
        $withoutBal= array_values(array_filter($loans,fn($l)=>$l['balance']===null));
        $portfolio = array_sum(array_column($withBal,'balance'));
        sendResponse('success','Loans loaded',['loans'=>$loans,'with_balance'=>$withBal,'without_balance'=>$withoutBal,'stats'=>['total'=>count($loans),'with_balance'=>count($withBal),'without_balance'=>count($withoutBal),'total_portfolio'=>round($portfolio,2)]]);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
}

// ==========================================
// EVENT PLANNER — update loan balances
// ==========================================
if ($path === '/planner/loans/update' && $method === 'POST') {
    requireAdmin($pdo);
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        $updates = $body['updates'] ?? []; // [{event_id, name, new_balance, description}]
        if (empty($updates)) sendResponse('error','No updates provided',null,400);

        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $success = 0; $failed = [];
        $baseUrl = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events/";

        foreach ($updates as $u) {
            $eventId    = $u['event_id']    ?? '';
            $newBalance = floatval($u['new_balance'] ?? 0);
            $currentDesc= $u['description'] ?? '';
            if (!$eventId || $newBalance <= 0) continue;

            $newDesc = replaceKBalance($currentDesc, $newBalance);
            $payload = json_encode(['description'=>$newDesc]);

            $ch = curl_init($baseUrl.urlencode($eventId).'?sendUpdates=none');
            curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>["Authorization: Bearer {$token}","Content-Type: application/json"],CURLOPT_TIMEOUT=>15]);
            $res = json_decode(curl_exec($ch),true);
            $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);

            if ($code===200 && isset($res['id'])) {
                $success++;
            } else {
                $failed[] = ($u['name']??$eventId).': '.($res['error']['message']??"HTTP $code");
            }
            usleep(200000); // 0.2s — no notifications, so fast is fine
        }

        sendResponse('success','Balances updated',['success'=>$success,'failed'=>$failed,'total'=>count($updates)]);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
}

// ==========================================
// DASHBOARD STATS
// ==========================================
if ($path === '/analytics/stats' && $method === 'GET') {
    requireAdmin($pdo);
    // 'completed' is scoped to a single month (default: current) so the
    // figure reads as "completed in August" rather than an ever-growing
    // lifetime total that gets less meaningful the longer the app is used.
    $month = $_GET['month'] ?? date('Y-m');
    [$year, $mon] = explode('-', $month);
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
        try {
            $tasks['total']=(int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
            $completedStmt = $pdo->prepare(
                "SELECT (SELECT COUNT(*) FROM tasks WHERE status='completed' AND recurrence='none' AND YEAR(completed_at)=? AND MONTH(completed_at)=?)
                       + (SELECT COUNT(*) FROM task_completions WHERE YEAR(created_at)=? AND MONTH(created_at)=?)"
            );
            $completedStmt->execute([$year, $mon, $year, $mon]);
            $tasks['completed']=(int)$completedStmt->fetchColumn();
            $tasks['pending']=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn();
        } catch(Exception $e){}
        try { $loans['active_accounts']=(int)$pdo->query("SELECT COUNT(*) FROM loan_accounts WHERE loan_status='active'")->fetchColumn(); $loans['total_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments")->fetchColumn(); $loans['pending_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments WHERE status='pending'")->fetchColumn(); $loans['total_revenue']=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='completed'")->fetchColumn(); } catch(Exception $e){}
        sendResponse('success','Stats retrieved',['month'=>$month,'users'=>['total'=>$users],'logs'=>['total'=>$logs,'last_7_days'=>$logs7],'applications'=>$apps,'testimonials'=>$tests,'chats'=>$chats,'tasks'=>$tasks,'loans'=>$loans]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// TASKS
// ==========================================

if ($path === '/tasks' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $sel = "t.*, GROUP_CONCAT(DISTINCT CONCAT(ta.user_id,'|',REPLACE(COALESCE(u.name,''),'|',''),'|',COALESCE(ta.status,'pending')) ORDER BY u.name SEPARATOR ';;') as assignee_details, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as assignees, (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id=t.id) as comment_count, (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id=t.id) as subtask_total, (SELECT COUNT(*) FROM tasks st WHERE st.parent_task_id=t.id AND st.status='completed') as subtask_done, (SELECT COUNT(*) FROM task_completions tc2 WHERE tc2.task_id=t.id) as completion_count";
        $order = "ORDER BY FIELD(t.status,'in_progress','pending','completed'), (t.last_recurred_at IS NULL), t.last_recurred_at DESC, t.due_date ASC, t.created_at DESC";
        if ($user['role'] === 'admin') {
            $tasks = $pdo->query("SELECT $sel FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE t.parent_task_id IS NULL GROUP BY t.id $order")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT $sel FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE t.parent_task_id IS NULL AND (EXISTS (SELECT 1 FROM task_assignees ta2 WHERE ta2.task_id=t.id AND ta2.user_id=?) OR t.created_by=?) GROUP BY t.id $order");
            $stmt->execute([$user['id'],$user['id']]); $tasks = $stmt->fetchAll();
        }
        // Subtasks assigned to the current user don't appear in the flat list
        // above (parent_task_id IS NULL there) — surface them separately, tagged
        // with their parent's title, so "My Tasks" can show work delegated to
        // someone via a subtask even if they aren't assigned the parent task.
        $subSel = "t.*, GROUP_CONCAT(DISTINCT CONCAT(ta.user_id,'|',REPLACE(COALESCE(u.name,''),'|',''),'|',COALESCE(ta.status,'pending')) ORDER BY u.name SEPARATOR ';;') as assignee_details, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as assignees, (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id=t.id) as comment_count, pt.title as parent_title";
        $subStmt = $pdo->prepare("SELECT $subSel FROM tasks t JOIN tasks pt ON pt.id=t.parent_task_id LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE t.parent_task_id IS NOT NULL AND EXISTS (SELECT 1 FROM task_assignees ta2 WHERE ta2.task_id=t.id AND ta2.user_id=?) GROUP BY t.id ORDER BY FIELD(t.status,'in_progress','pending','completed'), t.due_date ASC, t.created_at DESC");
        $subStmt->execute([$user['id']]);
        $mySubtasks = $subStmt->fetchAll();
        // Flat log of every recurring-task completion event, so the Completed
        // section can show a strikethrough entry per cycle in addition to the
        // live task (which has already reset back to pending) — same source
        // of truth as GET /tasks/{id}/completions, just merged across tasks.
        $logSel = "tc.id as completion_id, tc.task_id, tc.created_at as completed_at, t.title, t.recurrence, t.priority, t.category, cu.name as completed_by_name, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as assignees";
        if ($user['role'] === 'admin') {
            $completionLog = $pdo->query("SELECT $logSel FROM task_completions tc JOIN tasks t ON t.id=tc.task_id LEFT JOIN users cu ON cu.id=tc.completed_by LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id GROUP BY tc.id ORDER BY tc.created_at DESC")->fetchAll();
        } else {
            $logStmt = $pdo->prepare("SELECT $logSel FROM task_completions tc JOIN tasks t ON t.id=tc.task_id LEFT JOIN users cu ON cu.id=tc.completed_by LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE EXISTS (SELECT 1 FROM task_assignees ta2 WHERE ta2.task_id=t.id AND ta2.user_id=?) OR t.created_by=? GROUP BY tc.id ORDER BY tc.created_at DESC");
            $logStmt->execute([$user['id'],$user['id']]);
            $completionLog = $logStmt->fetchAll();
        }
        sendResponse('success','Tasks retrieved',['tasks'=>$tasks,'my_subtasks'=>$mySubtasks,'completion_log'=>$completionLog]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/tasks' && $method === 'POST') {
    $user = requireAdmin($pdo);
    $data = getRequestData();
    if (empty(trim($data['title']??''))) sendResponse('error','Title required',null,400);
    try {
        $taskId = createTaskRow($pdo, $user, $data, null);
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
    // Status transitions (Start/Complete/Pending) stay open to any assignee —
    // full edits (title, dates, priority, reassignment, etc.) are admin-only.
    $editOnlyFields = ['title','description','priority','category','recurrence','color',
        'due_date','dueDate','due_time','dueTime','start_date','startDate',
        'maturity_date','maturityDate','assignees','assignee_ids'];
    foreach ($editOnlyFields as $f) {
        if (array_key_exists($f, $data) && $user['role'] !== 'admin') {
            sendResponse('error','Only admins can edit task details',null,403);
        }
    }
    try {
        // Snapshot before/after so we only email on what the user actually asked
        // to change (status, dates, reassignment) — never on every field edit.
        $before = $pdo->prepare("SELECT status,due_date,start_date,maturity_date,title,description,priority,category,color,due_time,recurrence FROM tasks WHERE id=?");
        $before->execute([$taskId]);
        $old = $before->fetch();
        if (!$old) sendResponse('error','Task not found',null,404);
        $oldAssignees = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id=?");
        $oldAssignees->execute([$taskId]);
        $oldAssigneeIds = $oldAssignees->fetchAll(PDO::FETCH_COLUMN);

        $fields=[]; $vals=[];
        if (isset($data['status']))       { $fields[]='status=?';       $vals[]=$data['status']; }
        if (isset($data['title']))        { $fields[]='title=?';        $vals[]=$data['title']; }
        if (isset($data['description']))  { $fields[]='description=?';  $vals[]=$data['description']; }
        if (isset($data['priority']))     { $fields[]='priority=?';     $vals[]=$data['priority']; }
        if (isset($data['category']))     { $fields[]='category=?';     $vals[]=$data['category']; }
        if (isset($data['recurrence']))   { $fields[]='recurrence=?';   $vals[]=$data['recurrence']; }
        if (isset($data['color']))        { $fields[]='color=?';        $vals[]=$data['color']; }
        if (array_key_exists('due_date',$data)||array_key_exists('dueDate',$data)) { $raw=$data['due_date']??$data['dueDate']??''; $fields[]='due_date=?'; $vals[]=!empty($raw)?$raw:null; }
        if (array_key_exists('due_time',$data)||array_key_exists('dueTime',$data)) { $raw=$data['due_time']??$data['dueTime']??''; $fields[]='due_time=?'; $vals[]=!empty($raw)?$raw:null; }
        if (array_key_exists('start_date',$data)||array_key_exists('startDate',$data)) { $raw=$data['start_date']??$data['startDate']??''; $fields[]='start_date=?'; $vals[]=!empty($raw)?$raw:null; }
        if (array_key_exists('maturity_date',$data)||array_key_exists('maturityDate',$data)) { $raw=$data['maturity_date']??$data['maturityDate']??''; $fields[]='maturity_date=?'; $vals[]=!empty($raw)?$raw:null; }
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

        // Keep each assignee's personal "done" checkbox in sync with the task's
        // own status — previously these only synced if someone separately used
        // the per-assignee toggle, which nobody did, so Dashboard/Staff
        // Performance (which read task_assignees.status) always saw 0.
        if (isset($data['status']) && $data['status'] === 'completed' && $old['status'] !== 'completed') {
            $pdo->prepare("UPDATE task_assignees SET status='completed', completed_at=NOW() WHERE task_id=?")->execute([$taskId]);
        }

        // Completion-triggered recurrence: only for tasks that already have a
        // Recurrence set (not 'none') — a plain one-off task just stays completed.
        // Resets the SAME row back to pending with a shifted due date, logging
        // the completion to task_completions, instead of spawning a new row —
        // avoids accumulating a new duplicate task on every single cycle.
        $recurrenceReset = false;
        $recurrenceNextDue = null;
        if (isset($data['status']) && $data['status'] === 'completed' && $old['status'] !== 'completed' && $old['recurrence'] !== 'none') {
            $intervalMap = ['daily'=>'+1 day','weekly'=>'+1 week','monthly'=>'+1 month','quarterly'=>'+3 months','yearly'=>'+1 year'];
            $interval = $intervalMap[$old['recurrence']] ?? '+1 day';
            $recurrenceNextDue = date('Y-m-d', strtotime($interval));
            $pdo->prepare("INSERT INTO task_completions (task_id, completed_by) VALUES (?, ?)")->execute([$taskId, $user['id']]);
            $completionId = $pdo->lastInsertId();
            $cycleAssignees = $pdo->prepare("SELECT user_id FROM task_assignees WHERE task_id=?");
            $cycleAssignees->execute([$taskId]);
            $cycleAssigneeIds = $cycleAssignees->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($cycleAssigneeIds)) {
                $caStmt = $pdo->prepare("INSERT INTO task_completion_assignees (completion_id, user_id) VALUES (?, ?)");
                foreach ($cycleAssigneeIds as $uid) $caStmt->execute([$completionId, $uid]);
            }
            // The new cycle starts back in To Do — someone has to click Start
            // to move it into In Progress, same as any other task.
            $pdo->prepare("UPDATE tasks SET status='pending', due_date=?, start_date=?, maturity_date=NULL, days_overdue=NULL, completed_at=NULL, last_recurred_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$recurrenceNextDue, $recurrenceNextDue, $taskId]);
            // Each cycle starts fresh — reset the live per-assignee checkboxes
            // too, matching the task's own reset (historical credit for this
            // cycle is preserved above in task_completion_assignees).
            $pdo->prepare("UPDATE task_assignees SET status='pending', completed_at=NULL WHERE task_id=?")->execute([$taskId]);
            $recurrenceReset = true;
        }

        // Build the change summary and email current assignees once, if anything
        // notification-worthy actually changed.
        $changeLines = [];
        if ($recurrenceReset) {
            $changeLines[] = "Completed for this cycle — automatically reset and now due {$recurrenceNextDue} for its next occurrence.";
        } elseif (isset($data['status']) && $data['status'] !== $old['status']) {
            $labels = ['pending'=>'Pending To Do','in_progress'=>'In Progress','completed'=>'Completed'];
            $changeLines[] = 'Status changed to ' . ($labels[$data['status']] ?? $data['status']) . '.';
        }
        if (isset($data['assignees'])) {
            $newAssigneeIds = array_map('intval', $data['assignees']);
            $newlyAdded = array_diff($newAssigneeIds, $oldAssigneeIds);
            if (!empty($newlyAdded)) $changeLines[] = "You've been assigned to this task.";
        }
        foreach ([['due_date','dueDate','Due date'], ['start_date','startDate','Start date'], ['maturity_date','maturityDate','Maturity date']] as [$snake,$camel,$label]) {
            if (array_key_exists($snake,$data) || array_key_exists($camel,$data)) {
                $raw = $data[$snake] ?? $data[$camel] ?? '';
                $newVal = !empty($raw) ? $raw : null;
                if ($newVal !== $old[$snake]) $changeLines[] = "{$label} changed to " . ($newVal ?: 'none') . '.';
            }
        }
        if (!empty($changeLines)) {
            $title = $data['title'] ?? $old['title'];
            $subject = $recurrenceReset ? "Task Completed & Reset: {$title}" : "Task Updated: {$title}";
            emailTaskAssignees($pdo, $taskId, $title, $subject, $changeLines);
        }

        sendResponse('success','Task updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    $taskId = (int)$m[1];
    try {
        $children = $pdo->prepare("SELECT id FROM tasks WHERE parent_task_id=?");
        $children->execute([$taskId]);
        $childIds = $children->fetchAll(PDO::FETCH_COLUMN);
        foreach ($childIds as $cid) {
            $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$cid]);
            $pdo->prepare("DELETE FROM task_comments WHERE task_id=?")->execute([$cid]);
            $pdo->prepare("DELETE FROM task_attachments WHERE task_id=?")->execute([$cid]);
            $pdo->prepare("DELETE tca FROM task_completion_assignees tca JOIN task_completions tc ON tc.id=tca.completion_id WHERE tc.task_id=?")->execute([$cid]);
            $pdo->prepare("DELETE FROM task_completions WHERE task_id=?")->execute([$cid]);
        }
        if (!empty($childIds)) {
            $in = implode(',', array_fill(0, count($childIds), '?'));
            $pdo->prepare("DELETE FROM tasks WHERE id IN ($in)")->execute($childIds);
        }
        $pdo->prepare("DELETE FROM task_assignees WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task_comments WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task_attachments WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE tca FROM task_completion_assignees tca JOIN task_completions tc ON tc.id=tca.completion_id WHERE tc.task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task_completions WHERE task_id=?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$taskId]);
        sendResponse('success','Task deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/subtasks$#',$path,$m) && $method === 'GET') {
    requireAuth($pdo);
    $parentId = (int)$m[1];
    try {
        $sel = "t.*, GROUP_CONCAT(DISTINCT CONCAT(ta.user_id,'|',REPLACE(COALESCE(u.name,''),'|',''),'|',COALESCE(ta.status,'pending')) ORDER BY u.name SEPARATOR ';;') as assignee_details, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') as assignees, (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id=t.id) as comment_count";
        $stmt = $pdo->prepare("SELECT $sel FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id WHERE t.parent_task_id=? GROUP BY t.id ORDER BY t.created_at ASC");
        $stmt->execute([$parentId]);
        sendResponse('success','Subtasks retrieved',['subtasks'=>$stmt->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/subtasks$#',$path,$m) && $method === 'POST') {
    $user = requireAdmin($pdo);
    $data = getRequestData();
    $parentId = (int)$m[1];
    if (empty(trim($data['title']??''))) sendResponse('error','Title required',null,400);
    try {
        $parent = $pdo->prepare("SELECT id, parent_task_id FROM tasks WHERE id=?");
        $parent->execute([$parentId]);
        $p = $parent->fetch();
        if (!$p) sendResponse('error','Parent task not found',null,404);
        if ($p['parent_task_id'] !== null) sendResponse('error','Cannot create a subtask of a subtask',null,400);
        $taskId = createTaskRow($pdo, $user, $data, $parentId);
        sendResponse('success','Subtask created',['id'=>$taskId],201);
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

// ==========================================
// TASKS — time tracker. One running timer per user at a time; starting a new
// one auto-stops whatever else that user left running.
// ==========================================

if ($path === '/tasks/time/active' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT te.id, te.task_id, te.started_at, t.title as task_title FROM task_time_entries te JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.ended_at IS NULL ORDER BY te.started_at DESC LIMIT 1");
        $stmt->execute([$user['id']]);
        $active = $stmt->fetch();
        sendResponse('success','Active timer retrieved', $active ?: null);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// Timesheet — the current user's own logged time, most recent first.
if ($path === '/tasks/time/log' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT te.*, t.title as task_title FROM task_time_entries te JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? ORDER BY te.started_at DESC LIMIT 200");
        $stmt->execute([$user['id']]);
        $entries = $stmt->fetchAll();
        $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))),0) as total_seconds FROM task_time_entries WHERE user_id=?");
        $totalStmt->execute([$user['id']]);
        $totalSeconds = (int)$totalStmt->fetch()['total_seconds'];
        sendResponse('success','Time log retrieved', ['entries'=>$entries,'total_seconds'=>$totalSeconds]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/time$#',$path,$m) && $method === 'GET') {
    $user = requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT te.*, u.name as user_name FROM task_time_entries te LEFT JOIN users u ON u.id=te.user_id WHERE te.task_id=? ORDER BY te.started_at DESC");
        $stmt->execute([$taskId]);
        $entries = $stmt->fetchAll();
        $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))),0) as total_seconds FROM task_time_entries WHERE task_id=?");
        $totalStmt->execute([$taskId]);
        $totalSeconds = (int)$totalStmt->fetch()['total_seconds'];
        sendResponse('success','Time entries retrieved', ['entries'=>$entries,'total_seconds'=>$totalSeconds]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/time/start$#',$path,$m) && $method === 'POST') {
    $user = requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $exists = $pdo->prepare("SELECT id FROM tasks WHERE id=?");
        $exists->execute([$taskId]);
        if (!$exists->fetch()) sendResponse('error','Task not found',null,404);
        $running = $pdo->prepare("SELECT id FROM task_time_entries WHERE user_id=? AND ended_at IS NULL");
        $running->execute([$user['id']]);
        foreach ($running->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $pdo->prepare("UPDATE task_time_entries SET ended_at=NOW(), duration_seconds=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id=?")->execute([$rid]);
        }
        $pdo->prepare("INSERT INTO task_time_entries (task_id, user_id, started_at) VALUES (?, ?, NOW())")->execute([$taskId, $user['id']]);
        $entryId = $pdo->lastInsertId();
        $startedStmt = $pdo->prepare("SELECT started_at FROM task_time_entries WHERE id=?");
        $startedStmt->execute([$entryId]);
        $started = $startedStmt->fetch()['started_at'];
        sendResponse('success','Timer started', ['id'=>(int)$entryId,'task_id'=>$taskId,'started_at'=>$started], 201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)/time/stop$#',$path,$m) && $method === 'POST') {
    $user = requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT id FROM task_time_entries WHERE task_id=? AND user_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$taskId, $user['id']]);
        $entry = $stmt->fetch();
        if (!$entry) sendResponse('error','No running timer for this task',null,404);
        $pdo->prepare("UPDATE task_time_entries SET ended_at=NOW(), duration_seconds=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id=?")->execute([$entry['id']]);
        $durStmt = $pdo->prepare("SELECT duration_seconds FROM task_time_entries WHERE id=?");
        $durStmt->execute([$entry['id']]);
        $duration = (int)$durStmt->fetch()['duration_seconds'];
        sendResponse('success','Timer stopped', ['id'=>(int)$entry['id'],'duration_seconds'=>$duration]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// TASKS — daily overdue + due-today check (cron, mirrors
// /planner/auto-reschedule's token pattern). Does NOT move due_date anymore
// — instead sets days_overdue (a dedicated column; see
// migrations/alter_tasks_days_overdue.sql for why this isn't stored in
// maturity_date) and sends one reminder (in-app + email) per assignee per
// day a task stays overdue and incomplete, PLUS a same-day heads-up (in-app
// + email) the day a task is actually due, before it has a chance to slip
// into overdue.
// ==========================================
if ($path === '/tasks/daily-check' && $method === 'GET') {
    $providedToken = $_GET['token'] ?? '';
    $cronSecret = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='cron_secret'")->fetchColumn();
    if (!$cronSecret || !is_string($providedToken) || !hash_equals((string)$cronSecret, $providedToken)) {
        sendResponse('error','Unauthorized',null,401);
    }
    try {
        $dryRun = ($_GET['dry_run'] ?? '') === '1';

        // Some hosting panels don't offer a way to delete a stray/duplicate
        // cron row, so this endpoint can end up hit several times within
        // the same minute for what's meant to be a single scheduled run.
        // Collapse near-simultaneous hits into one actual send — the real
        // 9am/1pm/3pm slots are hours apart, well outside this window, so
        // the intended schedule still fires normally either way.
        if (!$dryRun) {
            $lastRun = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='daily_check_last_run'")->fetchColumn();
            if ($lastRun && (time() - strtotime($lastRun)) < 300) {
                sendResponse('success','Skipped — daily check already ran within the last 5 minutes', ['skipped'=>true]);
            }
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('daily_check_last_run',?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$now, $now]);
        }

        $result = sendTaskDueReminders($pdo, $dryRun);
        $overdue = $result['overdue']; $dueToday = $result['due_today'];

        if ($dryRun) {
            sendResponse('success','Dry run — nothing was changed', [
                'would_remind' => count($overdue),
                'would_remind_due_today' => count($dueToday),
                'tasks' => array_map(fn($t) => ['id'=>$t['id'],'title'=>$t['title'],'due_date'=>$t['due_date'],'days_overdue'=>(int)$t['days_overdue']], $overdue),
                'due_today_tasks' => array_map(fn($t) => ['id'=>$t['id'],'title'=>$t['title'],'due_date'=>$t['due_date']], $dueToday),
            ]);
        }

        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id,username,action,description,created_at) VALUES (NULL,'cron',?,?,NOW())")
                ->execute(['tasks_daily_check', json_encode(['reminded'=>count($overdue),'reminded_due_today'=>count($dueToday),'task_ids'=>array_column($overdue,'id'),'due_today_task_ids'=>array_column($dueToday,'id')])]);
        } catch (\Throwable $e) {}

        sendResponse('success','Daily check complete', ['reminded'=>count($overdue),'reminded_due_today'=>count($dueToday)]);
    } catch (\Throwable $e) { sendResponse('error',$e->getMessage(),null,500); }
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

if (preg_match('#^/tasks/(\d+)/completions$#',$path,$m) && $method === 'GET') {
    requireAuth($pdo);
    $taskId = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT tcpl.*,u.name as completed_by_name FROM task_completions tcpl LEFT JOIN users u ON u.id=tcpl.completed_by WHERE tcpl.task_id=? ORDER BY tcpl.created_at DESC");
        $stmt->execute([$taskId]);
        sendResponse('success','Completions retrieved',['completions'=>$stmt->fetchAll()]);
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
        $sessionId = $pdo->lastInsertId();
        // Notify all active staff about new chat
        try {
            $staffIds = $pdo->query("SELECT id FROM users WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($staffIds as $sid) {
                createNotification($pdo, $sid, 'chat', "New chat from {$customerName} — check the Chat page");
            }
        } catch (\Throwable $e2) {}
        sendResponse('success','Chat session created',['id'=>$sessionId]);
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
    $today=date('Y-m-d'); $weekday=(int)date('N');
    $dayNames=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
    $dayName=$dayNames[$weekday]??date('l');
    $emailsSent=0; $scheduleEntries=0;
    try {
        if ($weekday>=1&&$weekday<=5) {
            $stmt=$pdo->prepare("SELECT cs.role,cs.user_id,u.name,u.email FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date=?");
            $stmt->execute([$today]); $scheduled=$stmt->fetchAll();
            $scheduleEntries=count($scheduled);
            $callerIndex=0; $totalCallers=count(array_filter($scheduled,fn($r)=>$r['role']==='caller'));
            foreach ($scheduled as $r) {
                $roleLabel=$r['role']==='caller'?'making calls':'follow-up on unanswered calls';
                $callerNote='';
                if ($r['role']==='caller'&&$totalCallers>=2) { $half=$callerIndex===0?'first half':'second half'; $callerNote=" (you are responsible for the {$half} of today's client list)"; $callerIndex++; }
                createNotification($pdo,(int)$r['user_id'],'reminder',"You are on {$dayName} call duty","You are assigned to {$roleLabel} today{$callerNote}.","/dashboard/call-report");
                if (!empty($r['email'])) { sendEmail($r['email'],$r['name'],"📞 Call Duty Reminder — {$dayName}","<p>Hello {$r['name']},</p><p>You are scheduled for <strong>{$roleLabel}</strong> today{$callerNote}.</p>"); $emailsSent++; }
            }
        }
        $dueTasks=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE due_date='{$today}' AND status!='completed'")->fetchColumn();
        sendResponse('success',"Reminders sent to {$scheduleEntries} staff member(s)",['emails_sent'=>$emailsSent,'schedule_entries'=>$scheduleEntries,'due_tasks'=>$dueTasks]);
    } catch (\Throwable $e) { sendResponse('error','Failed to send reminders: '.$e->getMessage(),null,500); }
}

// Independent of call-schedule reminders above — lives on the Tasks page
// itself (admin-only button) so an admin managing tasks can trigger
// overdue/due-today email reminders on the spot, same logic as the
// automatic daily cron (/tasks/daily-check).
if ($path === '/admin/send-task-reminders' && $method === 'POST') {
    requireAdmin($pdo);
    try {
        $result = sendTaskDueReminders($pdo, false);
        $overdueTasks = count($result['overdue']);
        $dueTodayTasks = count($result['due_today']);
        sendResponse('success',"Task reminders sent for {$overdueTasks} overdue and {$dueTodayTasks} due-today task(s)",[
            'overdue_tasks'=>$overdueTasks,
            'due_tasks'=>$dueTodayTasks,
        ]);
    } catch (\Throwable $e) { sendResponse('error','Failed to send task reminders: '.$e->getMessage(),null,500); }
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

if (preg_match('#^/content/team/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try {
        $pdo->prepare("DELETE FROM team_members WHERE id=?")->execute([$m[1]]);
        sendResponse('success','Team member deleted');
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
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
function ensureCareersTables($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_listings (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, department VARCHAR(100) DEFAULT NULL, location VARCHAR(100) DEFAULT NULL, type VARCHAR(50) DEFAULT 'Full-time', job_type VARCHAR(50) DEFAULT 'Full-time', description TEXT, requirements TEXT, responsibilities TEXT, salary_range VARCHAR(100) DEFAULT NULL, deadline DATE DEFAULT NULL, is_active TINYINT DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_applications (id INT AUTO_INCREMENT PRIMARY KEY, job_id INT DEFAULT NULL, full_name VARCHAR(255) DEFAULT '', email VARCHAR(255) DEFAULT '', phone VARCHAR(50) DEFAULT NULL, cover_letter TEXT, qualification VARCHAR(100) DEFAULT NULL, qualification_other VARCHAR(255) DEFAULT NULL, cv_path VARCHAR(500) DEFAULT NULL, cv_filename VARCHAR(255) DEFAULT NULL, qualification_files TEXT DEFAULT NULL, status VARCHAR(50) DEFAULT 'pending', notes TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    // Rename legacy 'name' column to 'full_name' if it exists
    try { $pdo->exec("ALTER TABLE job_applications CHANGE COLUMN `name` full_name VARCHAR(255) DEFAULT ''"); } catch (\Throwable $e) {}
    // Add any missing columns (silently ignores if already present or dialect unsupported)
    foreach (['job_type VARCHAR(50) DEFAULT \'Full-time\'','deadline DATE DEFAULT NULL','responsibilities TEXT'] as $col) {
        try { $pdo->exec("ALTER TABLE job_listings ADD COLUMN $col"); } catch (\Throwable $e) {}
    }
    foreach (['full_name VARCHAR(255) DEFAULT \'\'','qualification VARCHAR(100) DEFAULT NULL','qualification_other VARCHAR(255) DEFAULT NULL','cv_path VARCHAR(500) DEFAULT NULL','cv_filename VARCHAR(255) DEFAULT NULL','qualification_files TEXT DEFAULT NULL'] as $col) {
        try { $pdo->exec("ALTER TABLE job_applications ADD COLUMN $col"); } catch (\Throwable $e) {}
    }
}

function notifyAdmins($pdo, $type, $title, $message, $link = null) {
    try {
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) { createNotification($pdo, (int)$adminId, $type, $title, $message, $link); }
    } catch (\Throwable $e) {}
}

function saveApplicationFiles($fileKey, $subdir) {
    $results = [];
    if (!isset($_FILES[$fileKey])) return $results;
    $uploadDir = __DIR__ . '/uploads/' . $subdir . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $f = $_FILES[$fileKey];
    // Normalise single-file vs multi-file structure
    if (!is_array($f['name'])) { $f = array_map(fn($v) => [$v], $f); }
    for ($i = 0; $i < count($f['name']); $i++) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['name'][$i])) continue;
        $ext = strtolower(pathinfo($f['name'][$i], PATHINFO_EXTENSION));
        $stored = uniqid($fileKey . '_', true) . '.' . $ext;
        if (move_uploaded_file($f['tmp_name'][$i], $uploadDir . $stored)) {
            $results[] = ['path' => 'uploads/' . $subdir . '/' . $stored, 'filename' => $f['name'][$i]];
        }
    }
    return $results;
}

if ($path === '/careers' && $method === 'GET') {
    try {
        ensureCareersTables($pdo);
        // Auto-deactivate jobs whose deadline has passed
        try { $pdo->exec("UPDATE job_listings SET is_active=0 WHERE is_active=1 AND deadline IS NOT NULL AND deadline < CURDATE()"); } catch (\Throwable $e2) {}
        $jobs = $pdo->query("SELECT *, COALESCE(NULLIF(job_type,''),type) as job_type FROM job_listings WHERE is_active=1 ORDER BY created_at DESC")->fetchAll();
        sendResponse('success','Jobs retrieved',['jobs'=>$jobs]);
    } catch (\Throwable $e) { sendResponse('success','OK',['jobs'=>[]]); }
}

if ($path === '/careers/admin/jobs' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        ensureCareersTables($pdo);
        $jobs = $pdo->query("SELECT *, COALESCE(NULLIF(job_type,''),type) as job_type FROM job_listings ORDER BY created_at DESC")->fetchAll();
        sendResponse('success','Jobs retrieved',['jobs'=>$jobs]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/careers/applications/count' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        ensureCareersTables($pdo);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status='pending'")->fetchColumn();
        sendResponse('success','OK',['total'=>$total,'pending'=>$pending]);
    } catch (\Throwable $e) { sendResponse('success','OK',['total'=>0,'pending'=>0]); }
}

if ($path === '/careers' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        ensureCareersTables($pdo);
        $jobType = $data['job_type'] ?? $data['type'] ?? 'Full-time';
        $deadline = !empty($data['deadline']) ? $data['deadline'] : null;
        $pdo->prepare("INSERT INTO job_listings (title,department,location,type,job_type,description,requirements,responsibilities,salary_range,deadline,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1)")
            ->execute([$data['title']??'',$data['department']??'',$data['location']??'',$jobType,$jobType,$data['description']??'',$data['requirements']??'',$data['responsibilities']??'',$data['salary_range']??'',$deadline]);
        sendResponse('success','Job created',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/careers/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        ensureCareersTables($pdo);
        $fields=[]; $vals=[];
        foreach (['title','department','location','description','requirements','responsibilities','salary_range','is_active'] as $f) {
            if (array_key_exists($f,$data)) { $fields[]="$f=?"; $vals[]=$data[$f]; }
        }
        if (array_key_exists('job_type',$data)||array_key_exists('type',$data)) {
            $jt=$data['job_type']??$data['type'];
            $fields[]='type=?'; $vals[]=$jt; $fields[]='job_type=?'; $vals[]=$jt;
        }
        if (array_key_exists('deadline',$data)) { $fields[]='deadline=?'; $vals[]=!empty($data['deadline'])?$data['deadline']:null; }
        $vals[]=(int)$m[1];
        $pdo->prepare("UPDATE job_listings SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
        sendResponse('success','Updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/careers/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try { $pdo->prepare("UPDATE job_listings SET is_active=0 WHERE id=?")->execute([$m[1]]); sendResponse('success','Deleted'); }
    catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/careers/applications' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        ensureCareersTables($pdo);
        $apps = $pdo->query("SELECT ja.*, COALESCE(ja.full_name,'') as full_name, jl.title as job_title FROM job_applications ja LEFT JOIN job_listings jl ON jl.id=ja.job_id ORDER BY ja.created_at DESC")->fetchAll();
        sendResponse('success','Applications retrieved',['applications'=>$apps]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/careers/(\d+)/apply$#',$path,$m) && $method === 'POST') {
    $data = $_POST;
    try {
        ensureCareersTables($pdo);
        $cvFiles = saveApplicationFiles('cv', 'applications');
        $cvPath = $cvFiles[0]['path'] ?? null; $cvFilename = $cvFiles[0]['filename'] ?? null;
        $qualFiles = saveApplicationFiles('qualification_files', 'applications');
        $qualFilesJson = !empty($qualFiles) ? json_encode($qualFiles) : null;
        $pdo->prepare("INSERT INTO job_applications (job_id,full_name,email,phone,cover_letter,qualification,qualification_other,cv_path,cv_filename,qualification_files,status) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$m[1],$data['full_name']??'',$data['email']??'',$data['phone']??'',$data['cover_letter']??'',$data['qualification']??'',$data['qualification_other']??'',$cvPath,$cvFilename,$qualFilesJson]);
        notifyAdmins($pdo,'application','New Job Application','New application from '.($data['full_name']??'Applicant').' for job #'.$m[1].'.', '/dashboard/careers');
        sendResponse('success','Application submitted',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if ($path === '/careers/general/apply' && $method === 'POST') {
    $data = $_POST;
    try {
        ensureCareersTables($pdo);
        $cvFiles = saveApplicationFiles('cv', 'applications');
        $cvPath = $cvFiles[0]['path'] ?? null; $cvFilename = $cvFiles[0]['filename'] ?? null;
        $qualFiles = saveApplicationFiles('qualification_files', 'applications');
        $qualFilesJson = !empty($qualFiles) ? json_encode($qualFiles) : null;
        $pdo->prepare("INSERT INTO job_applications (job_id,full_name,email,phone,cover_letter,qualification,qualification_other,cv_path,cv_filename,qualification_files,status) VALUES (NULL,?,?,?,?,?,?,?,?,?,'pending')")
            ->execute([$data['full_name']??'',$data['email']??'',$data['phone']??'',$data['cover_letter']??'',$data['qualification']??'',$data['qualification_other']??'',$cvPath,$cvFilename,$qualFilesJson]);
        notifyAdmins($pdo,'application','New General Application','New general application from '.($data['full_name']??'Applicant').'.', '/dashboard/careers');
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

// Mirrors the tiered rate in frontend Calculator.jsx/ClientsPage.jsx — kept in sync
// intentionally, since this public endpoint must compute totals itself and can't
// trust client-submitted amounts.
function loanInterestRate($amount) {
    if ($amount <= 3000) return 0.10;
    if ($amount <= 7499) return 0.15;
    if ($amount <= 9999) return 0.20;
    return 0.30;
}

if ($path === '/loans/apply' && $method === 'POST') {
    $data = $_POST;
    // Honeypot: bots fill every field, humans never see this one.
    if (!empty($data['website'])) sendResponse('success','Application submitted',['loan_reference'=>null],201);

    $name = trim($data['customer_name']??'');
    $phone = trim($data['customer_phone']??'');
    $nid = trim($data['national_id_last4']??'');
    $email = trim($data['customer_email']??'');
    $amount = (float)($data['loan_amount']??0);
    $duration = max(1, (int)($data['duration_months']??1));

    if (empty($name)||empty($phone)||strlen($nid)!==4||$amount<100||$amount>50000||$duration<1||$duration>24) {
        sendResponse('error','Please check all required fields and try again',null,400);
    }
    if (empty($_FILES['passport_photo']['name']??'') || empty($_FILES['id_document']['name']??'')) {
        sendResponse('error','Passport photo/selfie and ID document are both required',null,400);
    }

    try {
        $rate = loanInterestRate($amount);
        $totalRepayable = round($amount + 200 + ($amount * $rate * $duration), 2);
        $monthlyInstallment = round($totalRepayable / $duration, 2);

        $loanRef = null;
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'STW-'.date('Y').'-'.str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $exists = $pdo->prepare("SELECT id FROM loan_accounts WHERE loan_reference=?");
            $exists->execute([$candidate]);
            if (!$exists->fetch()) { $loanRef = $candidate; break; }
        }
        if (!$loanRef) sendResponse('error','Failed to generate a reference, please try again',null,500);

        // Validate and save every file BEFORE writing any DB row, so a rejected
        // upload can never leave an orphaned loan_accounts record behind.
        $docSpecs = [['field'=>'passport_photo','type'=>'passport_photo'],['field'=>'id_document','type'=>'id_document'],['field'=>'other','type'=>'other']];
        $savedDocs = [];
        foreach ($docSpecs as $spec) {
            if (empty($_FILES[$spec['field']]['name']??'')) continue;
            $saved = saveClientDocument($_FILES[$spec['field']]);
            if (isset($saved['error'])) {
                foreach ($savedDocs as $prior) { $p = __DIR__.'/'.$prior['file_name']; if (is_file($p)) @unlink($p); }
                sendResponse('error',$saved['error'],null,400);
            }
            $savedDocs[] = ['type'=>$spec['type']] + $saved;
        }

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO loan_accounts (loan_reference,customer_name,customer_phone,customer_email,national_id_last4,loan_amount,total_repayable,amount_paid,outstanding_balance,monthly_installment,loan_status,disbursement_date,maturity_date) VALUES (?,?,?,?,?,?,?,0,?,?,'pending',NULL,NULL)")
            ->execute([$loanRef,$name,$phone,$email,$nid,$amount,$totalRepayable,$totalRepayable,$monthlyInstallment]);
        $loanAccountId = $pdo->lastInsertId();
        foreach ($savedDocs as $doc) {
            $pdo->prepare("INSERT INTO client_documents (loan_account_id,document_type,file_name,original_filename,file_size,mime_type,uploaded_by) VALUES (?,?,?,?,?,?,NULL)")
                ->execute([$loanAccountId,$doc['type'],$doc['file_name'],$doc['original_filename'],$doc['file_size'],$doc['mime_type']]);
        }
        $pdo->commit();

        notifyAdmins($pdo,'application','New Loan Application',"New loan application from {$name} for K".number_format($amount,2).".",'/dashboard/clients');
        sendResponse('success','Application submitted',['loan_reference'=>$loanRef],201);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode()==23000) sendResponse('error','Please try submitting again',null,409);
        sendResponse('error','Failed: '.$e->getMessage(),null,500);
    }
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

// Same fuzzy name-matching used client-side in ClientsPage.jsx (nameSimilarity/
// levenshtein, 70% threshold) — reimplemented server-side using PHP's built-in
// levenshtein() instead of hand-rolling the row-by-row algorithm the JS version
// needed (no native levenshtein in browsers).
function loanClientNameSimilarity($name1, $name2) {
    $clean = function($s) {
        $s = strtolower(trim($s));
        $s = preg_replace('/\b(mr|mrs|ms|dr|miss)\.?\s*/', '', $s);
        $s = preg_replace('/[.,]/', '', $s);
        return trim($s);
    };
    $n1 = $clean($name1); $n2 = $clean($name2);
    if ($n1 === $n2) return 100;
    if (strpos($n2, $n1) !== false || strpos($n1, $n2) !== false) return 90;
    $w1 = explode(' ', $n1); $w2 = explode(' ', $n2);
    $matched = 0;
    foreach ($w1 as $a) foreach ($w2 as $b) if ($a === $b && strlen($a) > 2) $matched++;
    if ($matched > 0) return min(85, $matched * 40);
    $maxLen = max(strlen($n1), strlen($n2));
    if (!$maxLen) return 0;
    return round((1 - levenshtein($n1, $n2) / $maxLen) * 100);
}

if ($path === '/loans/admin/accounts/sync-calendar' && $method === 'POST') {
    requireAdmin($pdo);
    try {
        $cfg = getGCalSettings($pdo);
        $calendarId = $cfg['google_calendar_id'] ?? '';
        $sa = json_decode($cfg['google_service_account_json'] ?? '', true);
        if (!$sa || !$calendarId) sendResponse('error','Calendar not configured',null,400);
        $token = getGCalToken($sa);
        if (!$token) sendResponse('error','Could not authenticate with Google Calendar',null,500);

        $date = date('Y-m-d');
        $timeMin = urlencode($date.'T00:00:00+02:00');
        $timeMax = urlencode($date.'T23:59:59+02:00');
        $url = "https://www.googleapis.com/calendar/v3/calendars/".urlencode($calendarId)."/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=2500";
        $evData = curlGetGCalRetry($url, $token);
        if ($evData === null) sendResponse('error','Could not reach Google Calendar. Please try again.',null,502);

        $existing = $pdo->query("SELECT id, customer_name, customer_email, next_payment_date, maturity_date FROM loan_accounts")->fetchAll();

        $created = 0; $updated = 0; $unchanged = 0; $scanned = 0;
        foreach ($evData['items'] ?? [] as $e) {
            $name = trim($e['summary'] ?? '');
            if (!$name || stripos($name, 'busy') !== false) continue;
            $scanned++;

            $desc = $e['description'] ?? '';
            $balance = parseKBalance($desc) ?? 0;
            $dueDate = parseDueDateFromDesc($desc);
            $maturityDate = parseMaturityDateFromDesc($desc);
            $guestEmail = null;
            foreach ($e['attendees'] ?? [] as $a) {
                if (empty($a['organizer']) && empty($a['self']) && !empty($a['email'])) { $guestEmail = $a['email']; break; }
            }

            $best = null; $bestScore = 0;
            foreach ($existing as $c) {
                $score = loanClientNameSimilarity($c['customer_name'], $name);
                if ($score > $bestScore) { $bestScore = $score; $best = $c; }
            }

            if ($best && $bestScore >= 70) {
                $fields = []; $vals = [];
                if ($guestEmail && empty($best['customer_email'])) { $fields[] = 'customer_email=?'; $vals[] = $guestEmail; }
                if ($dueDate && empty($best['next_payment_date'])) { $fields[] = 'next_payment_date=?'; $vals[] = $dueDate; }
                if ($maturityDate && empty($best['maturity_date'])) { $fields[] = 'maturity_date=?'; $vals[] = $maturityDate; }
                if (!empty($fields)) {
                    $vals[] = $best['id'];
                    $pdo->prepare("UPDATE loan_accounts SET ".implode(',', $fields)." WHERE id=?")->execute($vals);
                    $updated++;
                } else {
                    $unchanged++;
                }
                continue;
            }

            $loanReference = null;
            for ($i = 0; $i < 5; $i++) {
                $candidate = 'STW-'.date('Y').'-'.str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                $refCheck = $pdo->prepare("SELECT id FROM loan_accounts WHERE loan_reference=?");
                $refCheck->execute([$candidate]);
                if (!$refCheck->fetch()) { $loanReference = $candidate; break; }
            }
            if (!$loanReference) continue;
            // Phone/national ID/disbursement date are never present in a
            // calendar reminder's text (confirmed against all live event
            // descriptions) — left NULL rather than guessed. next_payment_date
            // and maturity_date ARE reliably derivable and pulled above.
            $pdo->prepare("INSERT INTO loan_accounts (loan_reference,customer_name,customer_phone,customer_email,national_id_last4,loan_amount,total_repayable,amount_paid,outstanding_balance,monthly_installment,next_payment_date,loan_status,disbursement_date,maturity_date) VALUES (?,?,NULL,?,NULL,?,?,0,?,?,?,?,NULL,?)")
                ->execute([$loanReference, $name, $guestEmail, $balance, $balance, $balance, 0, $dueDate, 'pending', $maturityDate]);
            $existing[] = ['id' => $pdo->lastInsertId(), 'customer_name' => $name, 'customer_email' => $guestEmail, 'next_payment_date' => $dueDate, 'maturity_date' => $maturityDate];
            $created++;
        }

        sendResponse('success','Calendar sync complete', ['created'=>$created,'updated'=>$updated,'unchanged'=>$unchanged,'scanned'=>$scanned]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
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
// CLIENT PROFILES (admin)
// ==========================================
if (preg_match('#^/loans/admin/accounts/(\d+)$#',$path,$m) && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM loan_accounts WHERE id=?");
        $stmt->execute([$m[1]]);
        $account = $stmt->fetch();
        if (!$account) sendResponse('error','Client not found',null,404);
        $payments = $pdo->prepare("SELECT * FROM loan_payments WHERE loan_account_id=? ORDER BY created_at DESC");
        $payments->execute([$m[1]]);
        $docs = $pdo->prepare("SELECT * FROM client_documents WHERE loan_account_id=? ORDER BY created_at DESC");
        $docs->execute([$m[1]]);
        sendResponse('success','Client retrieved',['account'=>$account,'payments'=>$payments->fetchAll(),'documents'=>$docs->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/loans/admin/accounts/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $stmt=$pdo->prepare("SELECT id FROM loan_accounts WHERE id=?"); $stmt->execute([$m[1]]);
        if (!$stmt->fetch()) sendResponse('error','Client not found',null,404);
        $pdo->prepare("UPDATE loan_accounts SET customer_name=?,customer_phone=?,customer_email=?,national_id_last4=?,loan_amount=?,total_repayable=?,monthly_installment=?,loan_status=?,disbursement_date=?,maturity_date=?,next_payment_date=? WHERE id=?")
            ->execute([
                $data['customer_name'],$data['customer_phone'],$data['customer_email']??'',$data['national_id_last4'],
                $data['loan_amount'],$data['total_repayable'],$data['monthly_installment'],$data['loan_status'],
                $data['disbursement_date']?:null,$data['maturity_date']?:null,$data['next_payment_date']?:null,$m[1]
            ]);
        sendResponse('success','Client updated');
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/loans/admin/accounts/(\d+)/status$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    $action = $data['action'] ?? '';
    if (!in_array($action, ['approve','reject','mark_paid'])) sendResponse('error','Invalid action',null,400);
    try {
        $stmt=$pdo->prepare("SELECT * FROM loan_accounts WHERE id=?"); $stmt->execute([$m[1]]);
        $account = $stmt->fetch();
        if (!$account) sendResponse('error','Client not found',null,404);

        if ($action === 'approve') {
            $pdo->prepare("UPDATE loan_accounts SET loan_status='active' WHERE id=?")->execute([$m[1]]);
            sendResponse('success','Application approved');
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE loan_accounts SET loan_status='rejected' WHERE id=?")->execute([$m[1]]);
            sendResponse('success','Application rejected');
        } else { // mark_paid
            $pdo->prepare("UPDATE loan_accounts SET loan_status='paid_off',amount_paid=total_repayable,outstanding_balance=0 WHERE id=?")->execute([$m[1]]);
            sendResponse('success','Marked as paid');
        }
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

function saveClientDocument($file) {
    // Content is validated, not the client-supplied extension/MIME header — see security audit.
    $maxSize = 10 * 1024 * 1024;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['error'=>'Upload failed'];
    if ($file['size'] > $maxSize) return ['error'=>'File exceeds 10MB limit'];

    $tmpPath = $file['tmp_name'];
    $ext = null;
    $imgInfo = @getimagesize($tmpPath);
    if ($imgInfo && in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG])) {
        $ext = $imgInfo[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    } else {
        $head = file_get_contents($tmpPath, false, null, 0, 4);
        if ($head === '%PDF') $ext = 'pdf';
    }
    if (!$ext) return ['error'=>'Only JPG, PNG, or PDF files are allowed'];

    $uploadDir = __DIR__ . '/uploads/client_documents/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $htaccess = $uploadDir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n");
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $uploadDir . $storedName)) return ['error'=>'Failed to save file'];

    $mimeMap = ['jpg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf'];
    return [
        'file_name' => 'uploads/client_documents/' . $storedName,
        'original_filename' => $file['name'],
        'file_size' => $file['size'],
        'mime_type' => $mimeMap[$ext],
    ];
}

if (preg_match('#^/loans/admin/accounts/(\d+)/documents$#',$path,$m) && $method === 'POST') {
    $user = requireAdmin($pdo);
    try {
        $stmt=$pdo->prepare("SELECT id FROM loan_accounts WHERE id=?"); $stmt->execute([$m[1]]);
        if (!$stmt->fetch()) sendResponse('error','Client not found',null,404);
        if (empty($_FILES['file'])) sendResponse('error','No file provided',null,400);
        $docType = in_array($_POST['document_type']??'', ['passport_photo','id_document','receipt','loan_agreement','other']) ? $_POST['document_type'] : 'other';
        $saved = saveClientDocument($_FILES['file']);
        if (isset($saved['error'])) sendResponse('error',$saved['error'],null,400);
        $pdo->prepare("INSERT INTO client_documents (loan_account_id,document_type,file_name,original_filename,file_size,mime_type,uploaded_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$m[1],$docType,$saved['file_name'],$saved['original_filename'],$saved['file_size'],$saved['mime_type'],$user['id']??null]);
        sendResponse('success','Document uploaded',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/loans/admin/documents/(\d+)$#',$path,$m) && $method === 'DELETE') {
    requireAdmin($pdo);
    try {
        $stmt=$pdo->prepare("SELECT * FROM client_documents WHERE id=?"); $stmt->execute([$m[1]]);
        $doc=$stmt->fetch();
        if (!$doc) sendResponse('error','Document not found',null,404);
        $pdo->prepare("DELETE FROM client_documents WHERE id=?")->execute([$m[1]]);
        $filePath = __DIR__ . '/' . $doc['file_name'];
        if (is_file($filePath)) @unlink($filePath);
        sendResponse('success','Document deleted');
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
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $pdo->prepare("INSERT INTO contact_submissions (name,email,phone,subject,message,ip_address,user_agent,is_spam) VALUES (?,?,?,?,?,?,?,0)")
            ->execute([$name,$email,$data['phone']??'',$data['subject']??'General Enquiry',$message,$ip,$ua]);
        sendResponse('success','Message sent. We will get back to you soon.');
    } catch (\Throwable $e) { sendResponse('error','Failed to submit: '.$e->getMessage(),null,500); }
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
    // Convert PNG/GIF to JPEG for smaller size (except SVG/WebP which stay as-is)
    $convertToJpeg = in_array($file['type'], ['image/png','image/gif']);
    $outExt = ($convertToJpeg || $file['type']==='image/jpeg') ? 'jpg' : $ext;
    $filename = uniqid('img_', true) . '.' . $outExt;
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) sendResponse('error','Failed to save file',null,500);
    // Resize + compress with GD if available
    if (function_exists('imagecreatefromstring') && in_array($file['type'], ['image/jpeg','image/png','image/gif'])) {
        $src = imagecreatefromstring(file_get_contents($targetPath));
        if ($src) {
            $ow = imagesx($src); $oh = imagesy($src);
            $maxW = 1920; $maxH = 1920;
            if ($ow > $maxW || $oh > $maxH) {
                $ratio = min($maxW/$ow, $maxH/$oh);
                $nw = (int)round($ow*$ratio); $nh = (int)round($oh*$ratio);
                $dst = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$ow,$oh);
                imagedestroy($src); $src = $dst;
            }
            imagejpeg($src, $targetPath, 82);
            imagedestroy($src);
            clearstatcache(true, $targetPath);
        }
    }
    $filePath = 'uploads/' . $filename;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM media")->fetchAll(), 'Field');
        $actualSize = file_exists($targetPath) ? filesize($targetPath) : $file['size'];
        $insert = ['file_name'=>$filename,'original_filename'=>$file['name'],'file_path'=>$filePath,'file_type'=>'image/jpeg','file_size'=>$actualSize];
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
            // Recurring tasks reset task_assignees back to pending every cycle
            // (so history doesn't survive there) — their completions instead
            // live in task_completion_assignees, one row per assignee per
            // cycle, which is where the "count every cycle" numbers come from.
            $taskStmt=$pdo->prepare("SELECT COUNT(DISTINCT t.id) AS total_assigned,SUM(CASE WHEN ta.status='completed' AND t.recurrence='none' THEN 1 ELSE 0 END) AS total_completed,SUM(CASE WHEN ta.status!='completed' AND t.status='pending' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN ta.status!='completed' AND t.status='in_progress' THEN 1 ELSE 0 END) AS in_progress,SUM(CASE WHEN ta.status='completed' AND t.recurrence='none' AND YEAR(ta.completed_at)=? AND MONTH(ta.completed_at)=? THEN 1 ELSE 0 END) AS completed_this_month FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id WHERE ta.user_id=?");
            $taskStmt->execute([$year,$mon,$u['id']]); $taskData=$taskStmt->fetch();
            $recurStmt=$pdo->prepare("SELECT COUNT(*) AS recurring_total, SUM(CASE WHEN YEAR(tc.created_at)=? AND MONTH(tc.created_at)=? THEN 1 ELSE 0 END) AS recurring_this_month FROM task_completion_assignees tca JOIN task_completions tc ON tc.id=tca.completion_id WHERE tca.user_id=?");
            $recurStmt->execute([$year,$mon,$u['id']]); $recurData=$recurStmt->fetch();
            $taskData['total_completed'] = (int)($taskData['total_completed'] ?? 0) + (int)($recurData['recurring_total'] ?? 0);
            $taskData['completed_this_month'] = (int)($taskData['completed_this_month'] ?? 0) + (int)($recurData['recurring_this_month'] ?? 0);
            $callStmt=$pdo->prepare("SELECT COUNT(*) AS report_days,COALESCE(SUM(total_count),0) AS total_calls,COALESCE(SUM(answered_count),0) AS answered_calls,COALESCE(SUM(unanswered_count),0) AS unanswered_calls,ROUND(COALESCE(SUM(answered_count),0)/NULLIF(SUM(total_count),0)*100,1) AS answer_rate FROM call_reports WHERE (staff_id=? OR (staff_id IS NULL AND staff_name=?)) AND YEAR(report_date)=? AND MONTH(report_date)=?");
            $callStmt->execute([$u['id'],$u['name'],$year,$mon]); $callData=$callStmt->fetch();
            $performance[]=['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'tasks'=>$taskData,'calls'=>$callData];
        }
        $months=$pdo->query("SELECT DISTINCT DATE_FORMAT(report_date,'%Y-%m') AS month FROM call_reports ORDER BY month DESC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);

        // Team-wide totals must count DISTINCT tasks/completion-events, not sum
        // each staff member's individual credit — a task with 4 assignees
        // credits all 4 (correct for their own cards) but is still only ONE
        // completed task, so summing the per-staff numbers overcounts it 4x.
        $teamStmt = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM tasks WHERE parent_task_id IS NULL AND status='pending') AS pending,
            (SELECT COUNT(*) FROM tasks WHERE parent_task_id IS NULL AND status='in_progress') AS in_progress,
            (SELECT COUNT(*) FROM tasks WHERE parent_task_id IS NULL AND status='completed' AND recurrence='none' AND YEAR(completed_at)=? AND MONTH(completed_at)=?)
            + (SELECT COUNT(*) FROM task_completions WHERE YEAR(created_at)=? AND MONTH(created_at)=?) AS completed_this_month");
        $teamStmt->execute([$year,$mon,$year,$mon]);
        $teamTotals = $teamStmt->fetch();

        sendResponse('success','Staff performance retrieved',['month'=>$month,'staff'=>$performance,'available_months'=>$months,'team_totals'=>$teamTotals]);
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
        sendResponse('success','OK',['propertyId'=>$propertyId,'overview'=>$ov,'daily'=>$dailyFmt,'topPages'=>$fmt($topPages,'page','views'),'sources'=>$fmt($sources,'source','sessions'),'devices'=>$fmt($devices,'device','users'),'countries'=>$fmt($countries,'country','users')]);
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
    try {
        $today = date('Y-m-d'); $weekday = (int)date('N');
        // Call duty reminder — only weekdays, only if not already sent today
        if ($weekday >= 1 && $weekday <= 5) {
            $stmt = $pdo->prepare("SELECT cs.role FROM call_schedule cs WHERE cs.user_id=? AND cs.schedule_date=?");
            $stmt->execute([$user['id'], $today]);
            $mySchedule = $stmt->fetch();
            if ($mySchedule) {
                $already = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND type='reminder' AND link='/dashboard/call-report' AND DATE(created_at)=?");
                $already->execute([$user['id'], $today]);
                if (!$already->fetch()) {
                    $dayName = date('l');
                    $roleLabel = $mySchedule['role']==='caller' ? 'making calls' : 'follow-up on unanswered calls';
                    createNotification($pdo,(int)$user['id'],'reminder',"You're on call duty today","You are scheduled for {$roleLabel} today ({$dayName}).","/dashboard/call-report");
                }
            }
        }
        // Due tasks reminder — only if not already sent today
        $alreadyTask = $pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND type='task' AND DATE(created_at)=? AND title LIKE '%due today%'");
        $alreadyTask->execute([$user['id'], $today]);
        if (!$alreadyTask->fetch()) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id WHERE ta.user_id=? AND t.due_date=? AND t.status!='completed'");
            $stmt->execute([$user['id'], $today]);
            $dueTasks = (int)$stmt->fetchColumn();
            if ($dueTasks > 0) {
                createNotification($pdo,(int)$user['id'],'task',"{$dueTasks} task(s) due today","You have {$dueTasks} task(s) due today. Check your task list.","/dashboard/tasks");
            }
        }
    } catch (\Throwable $e) {}
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

// FRONTEND ASSET DEPLOY (upload tar.gz of dist/ to public_html)
// ==========================================
if ($path === '/deploy-frontend' && $method === 'POST') {
    if (($_GET['token'] ?? '') !== 'stalwart2026') { echo json_encode(['error'=>'Unauthorized']); exit; }
    $frontendDir = __DIR__ . '/../public_html';
    if (!is_dir($frontendDir)) {
        echo json_encode(['error'=>'Frontend dir not found','tried'=>$frontendDir]); exit;
    }
    if (!isset($_FILES['archive']) || $_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error'=>'No archive uploaded or upload error','code'=>($_FILES['archive']['error'] ?? 'missing')]); exit;
    }
    $tmp = $_FILES['archive']['tmp_name'];
    $output = [];
    $exitCode = 0;
    exec('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg(realpath($frontendDir)) . ' 2>&1', $output, $exitCode);
    if (!is_dir(__DIR__.'/logs')) mkdir(__DIR__.'/logs', 0755, true);
    file_put_contents(__DIR__.'/logs/deploy-frontend.log', date('Y-m-d H:i:s')."\nexitCode=$exitCode\n".implode("\n",$output)."\n---\n", FILE_APPEND);
    if ($exitCode !== 0) {
        echo json_encode(['error'=>'Extract failed','output'=>$output,'exitCode'=>$exitCode]); exit;
    }
    echo json_encode(['success'=>true,'time'=>date('Y-m-d H:i:s')]); exit;
}

// 404
sendResponse('error','Route not found',['path'=>$path,'method'=>$method],404);
?>
