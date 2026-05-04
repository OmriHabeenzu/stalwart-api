<?php
// STALWART API - EMERGENCY MINIMAL VERSION
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

// 404
sendResponse('error','Route not found',['path'=>$path,'method'=>$method],404);
?>
