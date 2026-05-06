<?php
// STALWART API
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
require_once __DIR__ . '/utils/jwt.php';

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

// HELPERS
function sendResponse($status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $r = ['status'=>$status,'message'=>$message];
    if ($data !== null) $r['data'] = $data;
    echo json_encode($r); exit();
}
function getRequestData() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function getUserFromToken() {
    $h = getallheaders(); $auth = $h['Authorization'] ?? '';
    if (empty($auth)) return null;
    preg_match('/Bearer\s+(.*)$/i', $auth, $m);
    $token = $m[1] ?? $auth;
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
        sendResponse('success','User retrieved',$u);
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
        sendResponse('success','Notifications retrieved',['notifications'=>$stmt->fetchAll()]);
    } catch (\Throwable $e) { sendResponse('success','OK',['notifications'=>[]]); }
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

if ($path === '/settings' && $method === 'POST') {
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
        $rows = $pdo->query("SELECT cs.*, u.name as user_name, u.email as user_email FROM call_schedule cs JOIN users u ON u.id=cs.user_id WHERE cs.schedule_date IS NOT NULL ORDER BY cs.schedule_date, u.name")->fetchAll();
        sendResponse('success','Schedule retrieved',['schedule'=>$rows]);
    } catch (\Throwable $e) { sendResponse('error','Failed to load schedule: '.$e->getMessage(),null,500); }
}

if ($path === '/call-schedule' && $method === 'POST') {
    requireCallManager($pdo);
    $data = getRequestData();
    $scheduleData = $data['schedule'] ?? [];
    try {
        $pdo->exec("DELETE FROM call_schedule WHERE schedule_date IS NOT NULL AND schedule_date >= CURDATE()");
        $stmt = $pdo->prepare("INSERT INTO call_schedule (user_id, role, schedule_date) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)");
        foreach ($scheduleData as $date => $assignments) {
            foreach ($assignments as $userId => $role) {
                if (!empty($role)) $stmt->execute([$userId,$role,$date]);
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

// ==========================================
// GOOGLE CALENDAR
// ==========================================
if ($path === '/calendar/events' && $method === 'GET') {
    requireAuth($pdo);
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('google_calendar_id','google_service_account')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $calendarId = $rows['google_calendar_id'] ?? '';
        $serviceAccountJson = $rows['google_service_account'] ?? '';
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
        try { $tests['total']=(int)$pdo->query("SELECT COUNT(*) FROM testimonials")->fetchColumn(); $tests['approved']=(int)$pdo->query("SELECT COUNT(*) FROM testimonials WHERE approved=1")->fetchColumn(); $tests['pending']=$tests['total']-$tests['approved']; } catch(Exception $e){}
        try { $chats['total']=(int)$pdo->query("SELECT COUNT(*) FROM chat_sessions")->fetchColumn(); $chats['active']=(int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status='active'")->fetchColumn(); $chats['messages']=(int)$pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn(); } catch(Exception $e){}
        try { $tasks['total']=(int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn(); $tasks['completed']=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='completed'")->fetchColumn(); $tasks['pending']=(int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn(); } catch(Exception $e){}
        try { $loans['active_accounts']=(int)$pdo->query("SELECT COUNT(*) FROM loan_accounts WHERE loan_status='active'")->fetchColumn(); $loans['total_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments")->fetchColumn(); $loans['pending_payments']=(int)$pdo->query("SELECT COUNT(*) FROM loan_payments WHERE status='pending'")->fetchColumn(); $loans['total_revenue']=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='completed'")->fetchColumn(); } catch(Exception $e){}
        sendResponse('success','Stats retrieved',['users'=>['total'=>$users],'logs'=>['total'=>$logs,'last_7_days'=>$logs7],'applications'=>$apps,'testimonials'=>$tests,'chats'=>$chats,'tasks'=>$tasks,'loans'=>$loans]);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

// ==========================================
// TASKS (basic)
// ==========================================
if ($path === '/tasks' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        if ($user['role']==='admin') {
            $tasks = $pdo->query("SELECT t.*,GROUP_CONCAT(u.name SEPARATOR ', ') as assignee_names FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u ON u.id=ta.user_id GROUP BY t.id ORDER BY t.created_at DESC")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT t.*,GROUP_CONCAT(u.name SEPARATOR ', ') as assignee_names FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN users u2 ON u2.id=ta.user_id LEFT JOIN users u ON u.id=ta.user_id WHERE ta.user_id=? GROUP BY t.id ORDER BY t.created_at DESC");
            $stmt->execute([$user['id']]); $tasks = $stmt->fetchAll();
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
        $pdo->prepare("INSERT INTO tasks (title,description,status,priority,due_date,created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$title,$data['description']??'','pending',$data['priority']??'medium',$data['due_date']??null,$user['id']]);
        $taskId = $pdo->lastInsertId();
        if (!empty($data['assignee_ids'])) {
            $stmt = $pdo->prepare("INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)");
            foreach ($data['assignee_ids'] as $uid) $stmt->execute([$taskId,$uid]);
        }
        sendResponse('success','Task created',['id'=>$taskId],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/tasks/(\d+)$#',$path,$m) && $method === 'PUT') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    try {
        $fields=[]; $vals=[];
        if (isset($data['status']))      { $fields[]='status=?';      $vals[]=$data['status']; }
        if (isset($data['title']))       { $fields[]='title=?';       $vals[]=$data['title']; }
        if (isset($data['description'])) { $fields[]='description=?'; $vals[]=$data['description']; }
        if (isset($data['priority']))    { $fields[]='priority=?';    $vals[]=$data['priority']; }
        if (isset($data['due_date']))    { $fields[]='due_date=?';    $vals[]=$data['due_date']; }
        if (empty($fields)) sendResponse('error','Nothing to update',null,400);
        $vals[]=(int)$m[1];
        $pdo->prepare("UPDATE tasks SET ".implode(',',$fields).",updated_at=NOW() WHERE id=?")->execute($vals);
        sendResponse('success','Task updated');
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
        $approved = $pdo->query("SELECT id,name,position,company,content,rating,created_at FROM testimonials WHERE approved=1 ORDER BY created_at DESC LIMIT 20")->fetchAll();
        sendResponse('success','Testimonials retrieved',['testimonials'=>$approved]);
    } catch (\Throwable $e) { sendResponse('success','OK',['testimonials'=>[]]); }
}

if ($path === '/testimonials/all' && $method === 'GET') {
    requireAdmin($pdo);
    try {
        $all = $pdo->query("SELECT * FROM testimonials ORDER BY created_at DESC")->fetchAll();
        sendResponse('success','All testimonials',['testimonials'=>$all]);
    } catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
}

if ($path === '/testimonials' && $method === 'POST') {
    $data=getRequestData();
    $name=trim($data['name']??''); $content=trim($data['content']??'');
    if (empty($name)||empty($content)) sendResponse('error','Name and content required',null,400);
    try {
        $pdo->prepare("INSERT INTO testimonials (name,position,company,content,rating,approved) VALUES (?,?,?,?,?,0)")
            ->execute([$name,$data['position']??'',$data['company']??'',$content,(int)($data['rating']??5)]);
        sendResponse('success','Testimonial submitted',null,201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/testimonials/(\d+)/approve$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    try { $pdo->prepare("UPDATE testimonials SET approved=1 WHERE id=?")->execute([$m[1]]); sendResponse('success','Approved'); }
    catch (\Throwable $e) { sendResponse('error','Failed',null,500); }
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
        $apiBase = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
        foreach (['main_logo_id','footer_logo_id','favicon_id'] as $key) {
            if (!empty($settings[$key])) {
                try {
                    $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id=?");
                    $stmt->execute([$settings[$key]]);
                    $media = $stmt->fetch();
                    if ($media) $settings[str_replace('_id','',$key).'_url'] = $apiBase.'/'.$media['file_path'];
                } catch(Exception $e) {}
            }
        }
        sendResponse('success','Public settings',$settings);
    } catch (\Throwable $e) { sendResponse('success','OK',[]); }
}

// ==========================================
// CONTENT - PUBLIC
// ==========================================
if ($path === '/content/team' && $method === 'GET') {
    try {
        $team = $pdo->query("SELECT * FROM team_members WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll();
        sendResponse('success','Team retrieved',['team'=>$team]);
    } catch (\Throwable $e) { sendResponse('success','OK',['team'=>[]]); }
}

if ($path === '/content/team' && $method === 'POST') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $pdo->prepare("INSERT INTO team_members (name,position,bio,image_url,linkedin_url,sort_order,is_active) VALUES (?,?,?,?,?,?,1)")
            ->execute([$data['name']??'',$data['position']??'',$data['bio']??'',$data['image_url']??'',$data['linkedin_url']??'',(int)($data['sort_order']??0)]);
        sendResponse('success','Team member added',['id'=>$pdo->lastInsertId()],201);
    } catch (\Throwable $e) { sendResponse('error','Failed: '.$e->getMessage(),null,500); }
}

if (preg_match('#^/content/team/(\d+)$#',$path,$m) && $method === 'PUT') {
    requireAdmin($pdo);
    $data = getRequestData();
    try {
        $fields=[]; $vals=[];
        foreach (['name','position','bio','image_url','linkedin_url','sort_order','is_active'] as $f) {
            if (isset($data[$f])) { $fields[]="$f=?"; $vals[]=$data[$f]; }
        }
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
        $apiBase = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
        foreach (['homepage_hero_image_id'=>'hero_image','homepage_why_choose_image_id'=>'why_choose_image'] as $key=>$imgKey) {
            if (!empty($rows[$key])) {
                try {
                    $stmt=$pdo->prepare("SELECT file_path FROM media WHERE id=?"); $stmt->execute([$rows[$key]]);
                    $media=$stmt->fetch(); if ($media) $images[$imgKey]=$apiBase.'/'.$media['file_path'];
                } catch(Exception $e){}
            }
        }
        sendResponse('success','Homepage images',['images'=>$images]);
    } catch (\Throwable $e) { sendResponse('success','OK',['images'=>['hero_image'=>null,'why_choose_image'=>null]]); }
}

if ($path === '/content/page-images' && $method === 'GET') {
    try {
        $rows = $pdo->query("SELECT * FROM page_images ORDER BY page_key, sort_order")->fetchAll();
        sendResponse('success','Page images',['images'=>$rows]);
    } catch (\Throwable $e) { sendResponse('success','OK',['images'=>[]]); }
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

// 404
sendResponse('error','Route not found',['path'=>$path,'method'=>$method],404);
?>
