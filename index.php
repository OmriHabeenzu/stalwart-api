<?php
// ==========================================
// STALWART API - COMPLETE REAL DATABASE VERSION
// v2 - deployed via GitHub Actions
// ==========================================

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// === 1. CORS HEADERS ===
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'https://stalwartzm.com',
    'https://www.stalwartzm.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
}
// Unknown origins receive no CORS header — browser blocks them
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// === 2. ERROR REPORTING ===
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// === 3. LOAD .ENV + JWT UTILITY ===
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
require_once __DIR__ . '/utils/jwt.php';

// VAPID keys for Web Push (override in .env)
define('VAPID_PUBLIC_KEY',  $_ENV['VAPID_PUBLIC_KEY']  ?? 'BPEjZwuRl0g09cq4hPgwt8vwQMM9dCUZjUSz5uy0ChQxHafU4R_pjkX2wSEqEEXWnCLGEBp9sYjS0ZjpUHWqTH4');
define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? 'wd0oVmTeuX1zg98EVtXGr1d4nfkpwZBMC5M-YWNsjbs');
define('VAPID_SUBJECT',     $_ENV['VAPID_SUBJECT']     ?? 'mailto:admin@stalwartzm.com');

// === 4. DATABASE CONNECTION ===
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'stalwart';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit(1);
}

// === 5. HELPER FUNCTIONS ===
function sendResponse($status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit();
}

function getRequestData() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function getUserFromToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (empty($authHeader)) {
        return null;
    }

    // Extract token from "Bearer <token>" format
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        $token = $authHeader;
    }

    // Decode and validate JWT token
    $payload = JWT::decode($token);

    if (!$payload) {
        return null;
    }

    // Return user data from token payload
    return [
        'id' => $payload['user_id'] ?? null,
        'email' => $payload['email'] ?? null,
        'role' => $payload['role'] ?? null
    ];
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePassword($password) {
    // Password must be at least 12 characters with uppercase, lowercase, number, and special char
    if (strlen($password) < 12) {
        return ['valid' => false, 'message' => 'Password must be at least 12 characters long'];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one number'];
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one special character'];
    }
    return ['valid' => true];
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sendEmail($to, $toName, $subject, $htmlBody) {
    global $pdo;

    // Load SMTP settings from database
    $smtpCfg = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpUser','smtpPassword','smtpFromEmail','smtpFromName','smtpEncryption')");
        foreach ($stmt->fetchAll() as $row) {
            $smtpCfg[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}

    $host       = trim($smtpCfg['smtpHost'] ?? '');
    $port       = (int)($smtpCfg['smtpPort'] ?? 587);
    $user       = trim($smtpCfg['smtpUser'] ?? '');
    $pass       = $smtpCfg['smtpPassword'] ?? '';
    $fromEmail  = trim($smtpCfg['smtpFromEmail'] ?? '') ?: ($user ?: 'noreply@stalwartzm.com');
    $fromName   = trim($smtpCfg['smtpFromName'] ?? '') ?: 'Stalwart Zambia';
    $encryption = strtolower(trim($smtpCfg['smtpEncryption'] ?? 'tls'));

    // Fallback to PHP mail() if SMTP not configured
    if (empty($host) || empty($user) || empty($pass)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <$fromEmail>\r\n";
        $headers .= "Reply-To: info@stalwartzm.com\r\n";
        return @mail($to, $subject, $htmlBody, $headers);
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        // TLS/SSL encryption
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // Disable peer verification to handle shared-hosting certificate CN mismatches
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $toName ?: $to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;

    } catch (PHPMailerException $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

// Get notification email(s) for a given key, fallback to default
function getNotifyEmail($pdo, $key, $default = 'info@stalwartzm.com') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = $row ? trim($row['setting_value']) : '';
        return $val ?: $default;
    } catch (Exception $e) {
        return $default;
    }
}


// ==========================================
// HELPER: Base64url encode (for Google JWT)
// ==========================================
function base64url_enc($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ==========================================
// HELPER: PHP-based MySQL dump (no exec needed)
// ==========================================
function phpMysqlDump($pdo) {
    $out  = "-- Stalwart Database Backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s T') . "\n";
    $out .= "-- -----------------------------------------\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $out .= "-- Table: `{$table}`\nDROP TABLE IF EXISTS `{$table}`;\n";
        $out .= $createRow[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                $out .= "INSERT INTO `{$table}` ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

// ==========================================
// HELPER: Google Drive Service Account auth
// ==========================================
function getGoogleAccessToken($serviceAccountJson) {
    $sa = json_decode($serviceAccountJson, true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) return null;

    $now     = time();
    $header  = base64url_enc(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_enc(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $toSign = $header . '.' . $payload;

    $pkey = openssl_pkey_get_private($sa['private_key']);
    if (!$pkey) return null;
    openssl_sign($toSign, $sig, $pkey, OPENSSL_ALGO_SHA256);
    $jwt = $toSign . '.' . base64url_enc($sig);

    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true)['access_token'] ?? null;
}

// ==========================================
// HELPER: Upload file to Google Drive
// ==========================================
function uploadToDrive($filePath, $filename, $folderId, $token) {
    $content  = file_get_contents($filePath);
    $boundary = 'stalwart_bk_' . uniqid();
    $meta     = json_encode(['name' => $filename, 'parents' => [$folderId]]);
    $body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
              . "--{$boundary}\r\nContent-Type: application/gzip\r\n\r\n{$content}\r\n"
              . "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Content-Type: multipart/related; boundary={$boundary}",
        ],
    ]);
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['_error' => 'cURL error: ' . $err];
    $decoded = json_decode($resp, true);
    // If Drive API returned an error object, propagate it
    if (isset($decoded['error'])) {
        $msg = $decoded['error']['message'] ?? 'Unknown Drive API error';
        return ['_error' => $msg];
    }
    return $decoded;
}

function logActivity($pdo, $userId, $username, $action, $description) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action (action),
            INDEX idx_created (created_at),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $username, $action, $description, $ipAddress, $userAgent]);
    } catch (PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// ── Web Push (pure PHP, no Composer) ──────────────────────────────────────
function wpB64d($s) {
    return base64_decode(str_pad(strtr($s, '-_', '+/'), strlen($s) + (4 - strlen($s) % 4) % 4, '='));
}
function wpB64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function wpHkdf($salt, $ikm, $info, $length) {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $t = ''; $okm = '';
    for ($i = 1; strlen($okm) < $length; $i++) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $okm .= $t;
    }
    return substr($okm, 0, $length);
}

function wpVapidJwt($endpoint) {
    $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $h = wpB64e(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = wpB64e(json_encode(['aud' => $origin, 'exp' => time() + 43200, 'sub' => VAPID_SUBJECT]));
    $input = "$h.$p";

    // Build PKCS8 DER: fixed P-256 header + 32-byte private scalar
    $privScalar = wpB64d(VAPID_PRIVATE_KEY);
    $der = hex2bin('308141020100301306072a8648ce3d020106082a8648ce3d030107042730250201010420') . $privScalar;
    $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----";
    $key = openssl_pkey_get_private($pem);
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

    // DER ECDSA signature → raw r|s (64 bytes)
    $pos = 2;
    if (ord($sig[1]) & 0x80) $pos += ord($sig[1]) & 0x7f;
    $pos++; $rLen = ord($sig[$pos++]);
    $r = ltrim(substr($sig, $pos, $rLen), "\x00"); $pos += $rLen;
    $pos++; $sLen = ord($sig[$pos++]);
    $s = ltrim(substr($sig, $pos, $sLen), "\x00");
    $rawSig = str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return "$input." . wpB64e($rawSig);
}

function wpEncrypt($payload, $p256dhB64u, $authB64u) {
    $uaPub      = wpB64d($p256dhB64u);   // 65 bytes: 0x04 || x || y
    $authSecret = wpB64d($authB64u);      // 16 bytes
    $salt       = random_bytes(16);

    // Ephemeral ECDH key pair
    $ephKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $ec     = openssl_pkey_get_details($ephKey)['ec'];
    $asPub  = "\x04" . str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT)
                     . str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);

    // Load receiver's public key via SubjectPublicKeyInfo DER
    $spki  = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPub;
    $uaKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----");

    // ECDH shared secret (32 bytes)
    $ecdhSecret = openssl_pkey_derive($uaKey, $ephKey, 32);

    // RFC 8291 key derivation
    $ikm   = wpHkdf($authSecret, $ecdhSecret, "WebPush: info\x00" . $uaPub . $asPub, 32);
    $cek   = wpHkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wpHkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // AES-128-GCM (single record; \x02 = end-of-padding delimiter)
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

    // Record header: salt(16) + rs=4096(4 BE) + idlen=65(1) + asPub(65)
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ciphertext . $tag;
}

function sendWebPush($endpoint, $p256dh, $auth, $title, $body, $url = '/dashboard/notifications') {
    try {
        $jwt    = wpVapidJwt($endpoint);
        $record = wpEncrypt(json_encode(['title' => $title, 'body' => $body, 'url' => $url]), $p256dh, $auth);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . $jwt . ',k=' . VAPID_PUBLIC_KEY,
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($record),
                'TTL: 86400',
                'Urgency: normal',
            ],
            CURLOPT_POSTFIELDS => $record,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($code === 410) { // subscription expired — remove it
            global $pdo;
            $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
        }
        return $code >= 200 && $code < 300;
    } catch (\Throwable $e) {
        error_log("WebPush error: " . $e->getMessage());
        return false;
    }
}

function sendPushToUser($pdo, $userId, $title, $body, $url = '/dashboard/notifications') {
    try {
        $stmt = $pdo->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $sub) {
            sendWebPush($sub['endpoint'], $sub['p256dh'], $sub['auth'], $title, $body, $url);
        }
    } catch (\Throwable $e) {
        error_log("sendPushToUser error: " . $e->getMessage());
    }
}
// ── End Web Push ───────────────────────────────────────────────────────────

function createNotification($pdo, $userId, $type, $title, $message, $link = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $type, $title, $message, $link]);
        sendPushToUser($pdo, $userId, $title, $message, $link ?? '/dashboard/notifications');
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return false;
    }
}

function requireAuth($pdo = null) {
    $user = getUserFromToken();
    if (!$user) {
        sendResponse('error', 'Unauthorized', null, 401);
    }
    return $user;
}

function requireAdmin($pdo = null) {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Forbidden', null, 403);
    }
    return $user;
}

function requireCallManager($pdo) {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);
    if ($user['role'] === 'admin') return $user;
    $row = $pdo->prepare("SELECT can_manage_calls FROM users WHERE id = ? LIMIT 1");
    $row->execute([$user['id']]);
    $data = $row->fetch();
    if (!$data || !$data['can_manage_calls']) sendResponse('error', 'Forbidden', null, 403);
    return $user;
}

function createNotificationForAllStaff($pdo, $type, $title, $message, $link = null) {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin')");
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($users as $userId) {
            createNotification($pdo, $userId, $type, $title, $message, $link);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Bulk notification error: " . $e->getMessage());
        return false;
    }
}

// === 6. SCHEMA MIGRATIONS (run once, safe to repeat) ===
try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS can_manage_calls TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS position VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks DROP PRIMARY KEY"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks MODIFY COLUMN id INT NOT NULL, ADD PRIMARY KEY (id)"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'general'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS due_time TIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS recurrence VARCHAR(20) DEFAULT 'none'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS parent_task_id INT DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_assignees ADD PRIMARY KEY (id)"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_assignees MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_reminders ADD PRIMARY KEY (id)"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_reminders MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_assignees ADD COLUMN IF NOT EXISTS status ENUM('pending','completed') NOT NULL DEFAULT 'pending'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE task_assignees ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
// Backfill: assignees of already-completed tasks get marked complete
try { $pdo->exec("UPDATE task_assignees ta JOIN tasks t ON t.id = ta.task_id SET ta.status = 'completed', ta.completed_at = t.updated_at WHERE t.status = 'completed' AND ta.status = 'pending'"); } catch (Exception $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_endpoint (endpoint(500)),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        reminder_type VARCHAR(50) NOT NULL,
        reminder_time DATETIME NOT NULL,
        sent TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {}

// === 7. ROUTING ===
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$path = str_replace('/stalwart-api', '', $path);
$path = rtrim($path, '/');
$segments = explode('/', trim($path, '/'));

// ==========================================
// AUTH ROUTES
// ==========================================

if ($path === '/auth/login' && $method === 'POST') {
    $data = getRequestData();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($email) || empty($password)) {
        sendResponse('error', 'Email and password required', null, 400);
    }

    if (!validateEmail($email)) {
        sendResponse('error', 'Invalid email format', null, 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            logActivity($pdo, null, $email, 'login_failed', "Login failed: no account found for $email");
            sendResponse('error', 'Invalid email or password', null, 401);
        }
        if (!password_verify($password, $user['password'])) {
            $activeLabel = $user['is_active'] ? '' : ' (account is inactive)';
            logActivity($pdo, $user['id'], $email, 'login_failed', "Login failed: wrong password for $email{$activeLabel}");
            sendResponse('error', 'Invalid email or password', null, 401);
        }
        if (!$user['is_active']) {
            logActivity($pdo, $user['id'], $email, 'login_failed', "Login failed: account is inactive for $email");
            sendResponse('error', 'Your account has been deactivated. Please contact your administrator.', null, 403);
        }

        // Update last login + IP (with safe fallback if column doesn't exist yet)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) { $ip = trim(explode(',', $ip)[0]); }
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) DEFAULT NULL");
            $pdo->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ? WHERE id = ?")->execute([$ip, $user['id']]);
        } catch (PDOException $e) {
            // Column may not exist yet — fall back to updating just last_login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        }

        // Log successful login
        logActivity($pdo, $user['id'], $user['name'], 'login', "User logged in successfully from IP: " . ($ip ?? 'unknown'));

        // Generate JWT token with user data
        $token = JWT::generateToken($user['id'], $user['email'], $user['role']);
        unset($user['password']);

        sendResponse('success', 'Login successful', [
            'token' => $token,
            'user' => $user
        ]);
    } catch (PDOException $e) {
        sendResponse('error', 'Database error', null, 500);
    }
}

// POST /auth/register — staff self-registration, auto-login
if ($path === '/auth/register' && $method === 'POST') {
    $data     = getRequestData();
    $name     = trim($data['name']     ?? '');
    $email    = strtolower(trim($data['email'] ?? ''));
    $password = $data['password']      ?? '';
    $phone    = trim($data['phone']    ?? '');
    $position = trim($data['position'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        sendResponse('error', 'Name, email and password are required', null, 400);
    }
    if (!validateEmail($email)) {
        sendResponse('error', 'Invalid email format', null, 400);
    }
    if (strlen($password) < 6) {
        sendResponse('error', 'Password must be at least 6 characters', null, 400);
    }

    try {
        // Check email not already in use
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            sendResponse('error', 'An account with this email already exists', null, 409);
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, position, role, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, 'staff', 1, NOW())");
        $stmt->execute([$name, $email, $hashed, $phone, $position]);
        $userId = (int)$pdo->lastInsertId();

        logActivity($pdo, $userId, $name, 'register', "New staff account registered: $email");

        // Notify admins
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            createNotification($pdo, $adminId, 'info', 'New Staff Registered', "$name ($email) has created an account.", '/dashboard/users');
        }

        // Auto-login: return token
        $token = JWT::generateToken($userId, $email, 'staff');
        $user  = $pdo->prepare("SELECT id, name, email, role, is_active, can_manage_calls, last_login, created_at FROM users WHERE id = ? LIMIT 1");
        $user->execute([$userId]);
        $userData = $user->fetch();

        sendResponse('success', 'Account created successfully', ['token' => $token, 'user' => $userData]);
    } catch (PDOException $e) {
        sendResponse('error', 'Registration failed', null, 500);
    }
}

if ($path === '/auth/me' && $method === 'GET') {
    $tokenUser = getUserFromToken();
    if (!$tokenUser) sendResponse('error', 'Unauthorized', null, 401);
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, role, is_active, can_manage_calls, last_login, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$tokenUser['id']]);
        $user = $stmt->fetch();
        if (!$user) sendResponse('error', 'User not found', null, 404);
        sendResponse('success', 'User retrieved', ['user' => $user]);
    } catch (PDOException $e) {
        sendResponse('error', 'Database error', null, 500);
    }
}

// ==========================================
// FORGOT / RESET PASSWORD
// ==========================================

if ($path === '/auth/forgot-password' && $method === 'POST') {
    $data  = getRequestData();
    $email = trim(strtolower($data['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse('error', 'Valid email is required', null, 400);
    }

    try {
        // Ensure reset table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $userRow = $stmt->fetch();

        // Always return success to avoid email enumeration
        if ($userRow) {
            // Invalidate existing tokens
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userRow['id']]);

            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$userRow['id'], $token, $expiresAt]);

            // Build reset link — detect frontend origin from request
            $origin    = $_SERVER['HTTP_ORIGIN'] ?? 'https://stalwartzm.com';
            $resetLink = rtrim($origin, '/') . "/reset-password?token=$token";

            $html = "
            <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
              <div style='background:#1e40af;padding:24px;text-align:center;border-radius:8px 8px 0 0'>
                <h1 style='color:white;margin:0;font-size:22px'>Stalwart Zambia</h1>
              </div>
              <div style='background:#f9fafb;padding:32px;border-radius:0 0 8px 8px'>
                <h2 style='color:#1e293b;margin-top:0'>Password Reset Request</h2>
                <p style='color:#475569'>Hi {$userRow['name']},</p>
                <p style='color:#475569'>We received a request to reset your password. Click the button below to choose a new password. This link expires in <strong>1 hour</strong>.</p>
                <div style='text-align:center;margin:32px 0'>
                  <a href='$resetLink' style='background:#1e40af;color:white;padding:14px 28px;text-decoration:none;border-radius:6px;font-weight:600;display:inline-block'>Reset My Password</a>
                </div>
                <p style='color:#94a3b8;font-size:13px'>If you did not request this, you can safely ignore this email. Your password will not change.</p>
                <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0'>
                <p style='color:#94a3b8;font-size:12px;text-align:center'>Stalwart Zambia &bull; Woodgate House, Cairo Rd, Lusaka</p>
              </div>
            </div>";

            sendEmail($userRow['email'], $userRow['name'], 'Password Reset – Stalwart Zambia', $html);
        }

        sendResponse('success', 'If that email is registered, a reset link has been sent.');
    } catch (PDOException $e) {
        error_log('forgot-password error: ' . $e->getMessage());
        sendResponse('error', 'Server error', null, 500);
    }
}

if ($path === '/auth/reset-password' && $method === 'POST') {
    $data        = getRequestData();
    $token       = trim($data['token'] ?? '');
    $newPassword = $data['password'] ?? '';

    if (!$token || !$newPassword) {
        sendResponse('error', 'Token and new password are required', null, 400);
    }

    $validation = validatePassword($newPassword);
    if (!$validation['valid']) {
        sendResponse('error', $validation['message'], null, 400);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT prt.id, prt.user_id, u.name, u.email
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            sendResponse('error', 'Invalid or expired reset link', null, 400);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $row['user_id']]);
        $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?")->execute([$row['id']]);

        sendResponse('success', 'Password updated successfully. You can now log in.');
    } catch (PDOException $e) {
        error_log('reset-password error: ' . $e->getMessage());
        sendResponse('error', 'Server error', null, 500);
    }
}

// ==========================================
// TEST EMAIL (admin only)
// ==========================================

if ($path === '/admin/test-email' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 403);

    $data = getRequestData();
    $to   = trim($data['email'] ?? '');

    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        sendResponse('error', 'Valid email address required', null, 400);
    }

    $html = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
      <div style='background:#1e40af;padding:24px;text-align:center;border-radius:8px 8px 0 0'>
        <h1 style='color:white;margin:0;font-size:22px'>Stalwart Zambia</h1>
      </div>
      <div style='background:#f9fafb;padding:32px;border-radius:0 0 8px 8px'>
        <h2 style='color:#1e293b;margin-top:0'>Test Email</h2>
        <p style='color:#475569'>This is a test email to verify your SMTP configuration is working correctly.</p>
        <p style='color:#475569'>Sent at: <strong>" . date('Y-m-d H:i:s T') . "</strong></p>
        <p style='color:#10b981;font-weight:600'>Your SMTP settings are working!</p>
      </div>
    </div>";

    // Check what SMTP settings are actually in the DB
    $smtpCfg = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtpHost','smtpPort','smtpUser','smtpPassword','smtpEncryption')");
        foreach ($stmt->fetchAll() as $row) {
            $smtpCfg[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}

    $smtpConfigured = !empty(trim($smtpCfg['smtpHost'] ?? '')) && !empty(trim($smtpCfg['smtpUser'] ?? '')) && !empty($smtpCfg['smtpPassword'] ?? '');

    if (!$smtpConfigured) {
        sendResponse('error', 'SMTP not configured — save your settings first. Found in DB: host=' . ($smtpCfg['smtpHost'] ?? 'MISSING') . ', user=' . ($smtpCfg['smtpUser'] ?? 'MISSING'), null, 400);
    }

    // Use PHPMailer directly here so we can capture the exact error
    $host       = trim($smtpCfg['smtpHost'] ?? '');
    $port       = (int)($smtpCfg['smtpPort'] ?? 587);
    $user       = trim($smtpCfg['smtpUser'] ?? '');
    $pass       = $smtpCfg['smtpPassword'] ?? '';
    $fromEmail  = trim($smtpCfg['smtpFromEmail'] ?? '') ?: $user;
    $fromName   = trim($smtpCfg['smtpFromName'] ?? '') ?: 'Stalwart Zambia';
    $encryption = strtolower(trim($smtpCfg['smtpEncryption'] ?? 'tls'));

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->Port     = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->CharSet  = 'UTF-8';
        $mail->Timeout  = 15;

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom($fromEmail ?: $user, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Test Email – Stalwart Zambia';
        $mail->Body    = $html;
        $mail->send();

        sendResponse('success', "Test email sent to $to via SMTP ($host:$port, $encryption)");
    } catch (PHPMailerException $e) {
        $detail = $e->getMessage();
        error_log('Test email PHPMailer error: ' . $detail);
        sendResponse('error', 'SMTP error: ' . $detail, [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'encryption' => $encryption,
        ], 500);
    }
}

// ==========================================
// DASHBOARD STATS
// ==========================================

if ($path === '/dashboard/stats' && $method === 'GET') {
    try {
        // Get real stats from database
        $totalLoans = $pdo->query("SELECT COUNT(*) as count FROM tasks WHERE category = 'loans'")->fetch()['count'];
        $pendingLoans = $pdo->query("SELECT COUNT(*) as count FROM tasks WHERE category = 'loans' AND status = 'pending'")->fetch()['count'];
        $activeChats = $pdo->query("SELECT COUNT(*) as count FROM chat_sessions WHERE status = 'active'")->fetch()['count'];
        $totalUsers = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_active = 1")->fetch()['count'];
        
        // Calculate approval rate
        $approved = $pdo->query("SELECT COUNT(*) as count FROM tasks WHERE category = 'loans' AND status = 'completed'")->fetch()['count'];
        $approvalRate = $totalLoans > 0 ? round(($approved / $totalLoans) * 100) : 0;
        
        sendResponse('success', 'Stats retrieved', [
            'totalLoans' => (int)$totalLoans,
            'pendingLoans' => (int)$pendingLoans,
            'activeChats' => (int)$activeChats,
            'totalUsers' => (int)$totalUsers,
            'monthlyRevenue' => 45000, // Calculate from actual loan data
            'approvalRate' => (int)$approvalRate
        ]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch stats', null, 500);
    }
}

if ($path === '/dashboard/activity' && $method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT 'task' as type, CONCAT('New task: ', title) as message, created_at as time 
            FROM tasks 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $activity = $stmt->fetchAll();
        sendResponse('success', 'Activity retrieved', ['activity' => $activity]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch activity', null, 500);
    }
}

// ==========================================
// USERS MANAGEMENT
// ==========================================

if ($path === '/users' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name, email, role, is_active as status, can_manage_calls, last_login, created_at FROM users ORDER BY created_at DESC");
        $users = $stmt->fetchAll();
        sendResponse('success', 'Users retrieved', ['users' => $users]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch users', null, 500);
    }
}

if ($path === '/users' && $method === 'POST') {
    $data = getRequestData();
    $name = sanitizeInput($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? 'staff';
    $status = $data['status'] ?? 'active';

    if (empty($name) || empty($email) || empty($password)) {
        sendResponse('error', 'Name, email and password required', null, 400);
    }

    if (!validateEmail($email)) {
        sendResponse('error', 'Invalid email format', null, 400);
    }

    $passwordValidation = validatePassword($password);
    if (!$passwordValidation['valid']) {
        sendResponse('error', $passwordValidation['message'], null, 400);
    }

    if (!in_array($role, ['admin', 'staff', 'user'])) {
        sendResponse('error', 'Invalid role', null, 400);
    }

    try {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $hashedPassword, $role, $status === 'active' ? 1 : 0]);
        $newUserId = $pdo->lastInsertId();

        $actor = getUserFromToken();
        logActivity($pdo, $actor['id'] ?? null, $actor['name'] ?? 'Admin', 'user_created', "Created user: $name ($email) with role: $role");

        sendResponse('success', 'User created', ['id' => $newUserId]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            sendResponse('error', 'Email already exists', null, 409);
        }
        sendResponse('error', 'Failed to create user', null, 500);
    }
}

if (preg_match('/^\/users\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $id = $matches[1];
    $data = getRequestData();
    
    try {
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = trim($data['name']);
        }
        if (isset($data['email'])) {
            $updates[] = "email = ?";
            $params[] = trim($data['email']);
        }
        if (!empty($data['password'])) {
            $updates[] = "password = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (isset($data['role'])) {
            $updates[] = "role = ?";
            $params[] = $data['role'];
        }
        if (isset($data['status'])) {
            $updates[] = "is_active = ?";
            $params[] = $data['status'] === 'active' ? 1 : 0;
        }
        if (isset($data['can_manage_calls'])) {
            $updates[] = "can_manage_calls = ?";
            $params[] = $data['can_manage_calls'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            sendResponse('error', 'No fields to update', null, 400);
        }
        
        $params[] = $id;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $actor = getUserFromToken();
        $targetUser = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $targetUser->execute([$id]);
        $targetRow = $targetUser->fetch();
        $targetName = $targetRow ? $targetRow['name'] . ' (' . $targetRow['email'] . ')' : "ID $id";
        logActivity($pdo, $actor['id'] ?? null, $actor['name'] ?? 'Admin', 'user_updated', "Updated user: $targetName");

        sendResponse('success', 'User updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update user', null, 500);
    }
}

if (preg_match('/^\/users\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $id = $matches[1];
    
    try {
        $targetUser = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $targetUser->execute([$id]);
        $targetRow = $targetUser->fetch();
        $targetName = $targetRow ? $targetRow['name'] . ' (' . $targetRow['email'] . ')' : "ID $id";

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        $actor = getUserFromToken();
        logActivity($pdo, $actor['id'] ?? null, $actor['name'] ?? 'Admin', 'user_deleted', "Deleted user: $targetName");

        sendResponse('success', 'User deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete user', null, 500);
    }
}

// ==========================================
// TASKS MANAGEMENT
// ==========================================

// ==========================================
// TASKS MANAGEMENT (FULL QUIRE-STYLE)
// ==========================================

// GET ALL TASKS (with assignees and comments count)
if ($path === '/tasks' && $method === 'GET') {
    try {
        $sql = "
            SELECT
                t.*,
                COUNT(DISTINCT ta.user_id) as assignee_count,
                COUNT(DISTINCT tc.id) as comment_count,
                GROUP_CONCAT(DISTINCT ta.user_name ORDER BY ta.user_name SEPARATOR ', ') as assignees,
                GROUP_CONCAT(
                    CONCAT(ta.user_id, '|', ta.user_name, '|', COALESCE(ta.status,'pending'))
                    ORDER BY ta.user_name SEPARATOR ';;'
                ) as assignee_details
            FROM tasks t
            LEFT JOIN task_assignees ta ON t.id = ta.task_id
            LEFT JOIN task_comments tc ON t.id = tc.id
            GROUP BY t.id
            ORDER BY 
                CASE t.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'in_progress' THEN 2 
                    WHEN 'completed' THEN 3 
                END,
                t.due_date ASC,
                t.due_time ASC
        ";
        $stmt = $pdo->query($sql);
        $tasks = $stmt->fetchAll();
        sendResponse('success', 'Tasks retrieved', ['tasks' => $tasks]);
    } catch (PDOException $e) {
        error_log("Get tasks error: " . $e->getMessage());
        sendResponse('error', 'Failed to fetch tasks', null, 500);
    }
}

// CREATE NEW TASK
if ($path === '/tasks' && $method === 'POST') {
    $data = getRequestData();
    $title = sanitizeInput($data['title'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $priority = $data['priority'] ?? 'medium';
    $category = $data['category'] ?? 'general';
    $dueDate = $data['dueDate'] ?? null;
    $dueTime = $data['dueTime'] ?? null;
    $recurrence = $data['recurrence'] ?? 'none';
    $assignees = $data['assignees'] ?? [];
    $createdBy = 1; // Get from auth token in production

    if (empty($title)) {
        sendResponse('error', 'Title is required', null, 400);
    }

    if (strlen($title) > 255) {
        sendResponse('error', 'Title too long (max 255 characters)', null, 400);
    }

    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
        sendResponse('error', 'Invalid priority value', null, 400);
    }

    if (!in_array($recurrence, ['none', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'])) {
        sendResponse('error', 'Invalid recurrence value', null, 400);
    }

    if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        sendResponse('error', 'Invalid date format (use YYYY-MM-DD)', null, 400);
    }

    if ($dueTime && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $dueTime)) {
        sendResponse('error', 'Invalid time format (use HH:MM)', null, 400);
    }

    try {
        $pdo->beginTransaction();

        // Insert task
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, description, priority, category, due_date, due_time, recurrence, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $description, $priority, $category, $dueDate, $dueTime, $recurrence, $createdBy]);
        $taskId = $pdo->lastInsertId();

        // Insert assignees + queue emails (send AFTER response to avoid SMTP timeout blocking)
        $emailQueue = [];
        if (!empty($assignees) && is_array($assignees)) {
            $stmtAssign = $pdo->prepare("
                INSERT INTO task_assignees (task_id, user_id, user_name)
                SELECT ?, u.id, u.name FROM users u WHERE u.id = ?
            ");
            foreach ($assignees as $userId) {
                $stmtAssign->execute([$taskId, $userId]);
                $dueDateText = $dueDate ? " (Due: $dueDate" . ($dueTime ? " at $dueTime" : "") . ")" : "";
                createNotification($pdo, $userId, 'task_assigned',
                    "New task assigned: $title",
                    "You have been assigned a new $priority priority task$dueDateText",
                    "/dashboard/tasks?task=$taskId"
                );
                // Collect email data — send after DB commit and HTTP response
                $assigneeRow = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                $assigneeRow->execute([$userId]);
                $assignee = $assigneeRow->fetch();
                if ($assignee && !empty($assignee['email'])) {
                    $dueLine = $dueDate ? "<p><strong>Due:</strong> $dueDate" . ($dueTime ? " at $dueTime" : "") . "</p>" : "";
                    $emailQueue[] = [
                        'to'      => $assignee['email'],
                        'name'    => $assignee['name'],
                        'subject' => "New Task Assigned: $title",
                        'body'    => "<p>Hello {$assignee['name']},</p>"
                                   . "<p>You have been assigned a new <strong>$priority priority</strong> task:</p>"
                                   . "<p><strong>$title</strong></p>"
                                   . ($description ? "<p>$description</p>" : "")
                                   . $dueLine
                                   . "<p>Log in to view and manage your tasks.</p>",
                    ];
                }
            }
        }

        // Create reminders if due date/time set
        if ($dueDate) {
            $morningTime = $dueDate . ' 08:00:00';
            $pdo->prepare("INSERT INTO task_reminders (task_id, reminder_type, reminder_time) VALUES (?, 'morning', ?)")
                ->execute([$taskId, $morningTime]);
            if ($dueTime) {
                $dateTime = new DateTime("$dueDate $dueTime");
                $dateTime->modify('-1 hour');
                $pdo->prepare("INSERT INTO task_reminders (task_id, reminder_type, reminder_time) VALUES (?, '1hour_before', ?)")
                    ->execute([$taskId, $dateTime->format('Y-m-d H:i:s')]);
            }
        }

        $pdo->commit();

        $actor = getUserFromToken();
        logActivity($pdo, $actor['id'] ?? null, $actor['email'] ?? 'Staff', 'task_created', "Created task: $title (priority: $priority)");

        // Send HTTP response immediately so the browser isn't blocked by SMTP
        $responseJson = json_encode(['status' => 'success', 'message' => 'Task created successfully!', 'data' => ['id' => $taskId]]);
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Length: ' . strlen($responseJson));
        header('Connection: close');
        echo $responseJson;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush(); @ob_flush(); flush();
        }

        // Now send queued emails in the background (client already received success)
        ignore_user_abort(true);
        foreach ($emailQueue as $em) {
            sendEmail($em['to'], $em['name'], $em['subject'], $em['body']);
        }
        exit();

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Create task error: " . $e->getMessage());
        sendResponse('error', 'Failed to create task: ' . $e->getMessage(), null, 500);
    }
}

// UPDATE TASK
if (preg_match('/^\/tasks\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $id = $matches[1];
    $data = getRequestData();
    
    try {
        $pdo->beginTransaction();
        
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = trim($data['title']);
        }
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = $data['description'];
        }
        if (isset($data['priority'])) {
            $updates[] = "priority = ?";
            $params[] = $data['priority'];
        }
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
            
            // If completing task, check for recurrence
            if ($data['status'] === 'completed') {
                $updates[] = "completed_at = NOW()";
                
                // Get task details
                $task = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
                $task->execute([$id]);
                $taskData = $task->fetch();
                
                // Create next occurrence if recurring
                if ($taskData && $taskData['recurrence'] !== 'none') {
                    $nextDate = calculateNextOccurrence($taskData['due_date'], $taskData['recurrence']);
                    
                    // Create new task
                    $stmt = $pdo->prepare("
                        INSERT INTO tasks (title, description, priority, category, due_date, due_time, recurrence, parent_task_id, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $taskData['title'],
                        $taskData['description'],
                        $taskData['priority'],
                        $taskData['category'],
                        $nextDate,
                        $taskData['due_time'],
                        $taskData['recurrence'],
                        $id,
                        $taskData['created_by']
                    ]);
                    $newTaskId = $pdo->lastInsertId();
                    
                    // Copy assignees
                    $pdo->prepare("
                        INSERT INTO task_assignees (task_id, user_id, user_name)
                        SELECT ?, user_id, user_name FROM task_assignees WHERE task_id = ?
                    ")->execute([$newTaskId, $id]);
                    
                    // Create reminders for new task
                    if ($nextDate) {
                        $morningTime = $nextDate . ' 08:00:00';
                        $pdo->prepare("INSERT INTO task_reminders (task_id, reminder_type, reminder_time) VALUES (?, 'morning', ?)")
                            ->execute([$newTaskId, $morningTime]);
                    }
                }
            }
        }
        if (isset($data['category'])) {
            $updates[] = "category = ?";
            $params[] = $data['category'];
        }
        if (isset($data['dueDate'])) {
            $updates[] = "due_date = ?";
            $params[] = $data['dueDate'];
        }
        if (isset($data['dueTime'])) {
            $updates[] = "due_time = ?";
            $params[] = $data['dueTime'];
        }
        if (isset($data['recurrence'])) {
            $updates[] = "recurrence = ?";
            $params[] = $data['recurrence'];
        }
        
        if (!empty($updates)) {
            $params[] = $id;
            $sql = "UPDATE tasks SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        // Update assignees if provided
        if (isset($data['assignees']) && is_array($data['assignees'])) {
            // Delete old assignees
            $pdo->prepare("DELETE FROM task_assignees WHERE task_id = ?")->execute([$id]);
            
            // Insert new assignees
            $stmtAssign = $pdo->prepare("
                INSERT INTO task_assignees (task_id, user_id, user_name) 
                SELECT ?, u.id, u.name FROM users u WHERE u.id = ?
            ");
            foreach ($data['assignees'] as $userId) {
                $stmtAssign->execute([$id, $userId]);
            }
        }
        
        $taskRow = $pdo->prepare("SELECT title FROM tasks WHERE id = ?");
        $taskRow->execute([$id]);
        $taskTitle = $taskRow->fetchColumn() ?: "ID $id";

        $pdo->commit();

        $actor = getUserFromToken();
        $action = (isset($data['status']) && $data['status'] === 'completed') ? 'task_completed' : 'task_updated';
        logActivity($pdo, $actor['id'] ?? null, $actor['name'] ?? 'Staff', $action, "Task \"$taskTitle\" " . ($action === 'task_completed' ? 'completed' : 'updated'));

        sendResponse('success', 'Task updated successfully! ✨');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Update task error: " . $e->getMessage());
        sendResponse('error', 'Failed to update task', null, 500);
    }
}

// DELETE TASK
if (preg_match('/^\/tasks\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $id = $matches[1];
    
    try {
        $taskRow = $pdo->prepare("SELECT title FROM tasks WHERE id = ?");
        $taskRow->execute([$id]);
        $taskTitle = $taskRow->fetchColumn() ?: "ID $id";

        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$id]);

        $actor = getUserFromToken();
        logActivity($pdo, $actor['id'] ?? null, $actor['name'] ?? 'Staff', 'task_deleted', "Deleted task: \"$taskTitle\"");

        sendResponse('success', 'Task deleted! 🗑️');
    } catch (PDOException $e) {
        error_log("Delete task error: " . $e->getMessage());
        sendResponse('error', 'Failed to delete task', null, 500);
    }
}

// POST /tasks/bulk-assign — add a user as assignee to multiple tasks
if ($path === '/tasks/bulk-assign' && $method === 'POST') {
    $actor = getUserFromToken();
    if (!$actor) sendResponse('error', 'Unauthorized', null, 401);

    $data    = getRequestData();
    $taskIds = array_filter(array_map('intval', $data['task_ids'] ?? []), fn($id) => $id > 0);
    $userId  = (int)($data['user_id'] ?? 0);

    if (empty($taskIds) || !$userId) sendResponse('error', 'task_ids and user_id required', null, 400);

    try {
        // Verify user exists
        $userRow = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND is_active = 1");
        $userRow->execute([$userId]);
        $assignee = $userRow->fetch();
        if (!$assignee) sendResponse('error', 'User not found', null, 404);

        $inserted = 0;
        $stmt = $pdo->prepare("INSERT IGNORE INTO task_assignees (task_id, user_id, user_name) VALUES (?, ?, ?)");
        foreach ($taskIds as $taskId) {
            $stmt->execute([$taskId, $assignee['id'], $assignee['name']]);
            $inserted += $stmt->rowCount();
        }

        logActivity($pdo, $actor['id'], $actor['name'] ?? $actor['email'], 'task_updated',
            "Bulk assigned {$assignee['name']} to " . count($taskIds) . " tasks");

        sendResponse('success', "Assigned {$assignee['name']} to " . count($taskIds) . " tasks", [
            'assigned_to' => $assignee['name'],
            'task_count'  => count($taskIds),
            'new_entries' => $inserted,
        ]);
    } catch (PDOException $e) {
        error_log("Bulk assign error: " . $e->getMessage());
        sendResponse('error', 'Failed to bulk assign tasks', null, 500);
    }
}

// PATCH /tasks/:id/assignee-status — update one assignee's personal completion status
if (preg_match('/^\/tasks\/(\d+)\/assignee-status$/', $path, $m) && $method === 'PATCH') {
    $taskId = (int)$m[1];
    $actor  = getUserFromToken();
    if (!$actor) sendResponse('error', 'Unauthorized', null, 401);

    $data         = getRequestData();
    $targetUserId = (int)($data['user_id'] ?? 0);
    $newStatus    = $data['status'] ?? '';

    if (!in_array($newStatus, ['pending', 'completed'])) {
        sendResponse('error', 'Invalid status', null, 400);
    }
    // Staff can only update their own row; admin can update anyone
    if ($actor['role'] !== 'admin' && (int)$actor['id'] !== $targetUserId) {
        sendResponse('error', 'Forbidden', null, 403);
    }

    try {
        $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE task_assignees SET status = ?, completed_at = ? WHERE task_id = ? AND user_id = ?")
            ->execute([$newStatus, $completedAt, $taskId, $targetUserId]);

        // Auto-complete the overall task when every assignee is done
        if ($newStatus === 'completed') {
            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM task_assignees WHERE task_id = ?");
            $totalStmt->execute([$taskId]);
            $total = (int)$totalStmt->fetchColumn();

            $doneStmt = $pdo->prepare("SELECT COUNT(*) FROM task_assignees WHERE task_id = ? AND status = 'completed'");
            $doneStmt->execute([$taskId]);
            $done = (int)$doneStmt->fetchColumn();

            if ($total > 0 && $done >= $total) {
                $pdo->prepare("UPDATE tasks SET status = 'completed', updated_at = NOW() WHERE id = ? AND status != 'completed'")
                    ->execute([$taskId]);
            }
        }

        sendResponse('success', 'Assignment status updated');
    } catch (PDOException $e) {
        error_log("Assignee status update error: " . $e->getMessage());
        sendResponse('error', 'Failed to update status', null, 500);
    }
}

// GET TASK COMMENTS
if (preg_match('/^\/tasks\/(\d+)\/comments$/', $path, $matches) && $method === 'GET') {
    $taskId = $matches[1];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM task_comments WHERE task_id = ? ORDER BY created_at DESC");
        $stmt->execute([$taskId]);
        $comments = $stmt->fetchAll();
        sendResponse('success', 'Comments retrieved', ['comments' => $comments]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch comments', null, 500);
    }
}

// ADD TASK COMMENT
if (preg_match('/^\/tasks\/(\d+)\/comments$/', $path, $matches) && $method === 'POST') {
    $taskId = $matches[1];
    $data = getRequestData();
    $comment = trim($data['comment'] ?? '');
    $userId = 1; // Get from auth token
    
    if (empty($comment)) {
        sendResponse('error', 'Comment cannot be empty', null, 400);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO task_comments (task_id, user_id, user_name, comment) 
            SELECT ?, u.id, u.name, ? FROM users u WHERE u.id = ?
        ");
        $stmt->execute([$taskId, $comment, $userId]);
        sendResponse('success', 'Comment added! 💬', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to add comment', null, 500);
    }
}

// Helper function for calculating next occurrence
function calculateNextOccurrence($currentDate, $recurrence) {
    if (!$currentDate) return null;
    
    $date = new DateTime($currentDate);
    
    switch ($recurrence) {
        case 'daily':
            $date->modify('+1 day');
            break;
        case 'weekly':
            $date->modify('+1 week');
            break;
        case 'monthly':
            $date->modify('+1 month');
            break;
        case 'quarterly':
            $date->modify('+3 months');
            break;
        case 'yearly':
            $date->modify('+1 year');
            break;
        default:
            return null;
    }
    
    return $date->format('Y-m-d');
}
// ==========================================
// TASK ATTACHMENTS
// ==========================================

// GET ATTACHMENTS FOR A TASK
if (preg_match('/^\/tasks\/(\d+)\/attachments$/', $path, $matches) && $method === 'GET') {
    $taskId = $matches[1];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC");
        $stmt->execute([$taskId]);
        $attachments = $stmt->fetchAll();
        sendResponse('success', 'Attachments retrieved', ['attachments' => $attachments]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch attachments', null, 500);
    }
}

// UPLOAD ATTACHMENT
if (preg_match('/^\/tasks\/(\d+)\/attachments$/', $path, $matches) && $method === 'POST') {
    $taskId = $matches[1];
    
    // Handle file upload
    if (!isset($_FILES['file'])) {
        sendResponse('error', 'No file uploaded', null, 400);
    }
    
    $file = $_FILES['file'];
    $userId = 1; // Get from auth token
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads/tasks/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO task_attachments (task_id, file_name, file_path, file_size, file_type, uploaded_by, uploaded_by_name)
                SELECT ?, ?, ?, ?, ?, u.id, u.name FROM users u WHERE u.id = ?
            ");
            $stmt->execute([
                $taskId,
                $file['name'],
                'uploads/tasks/' . $fileName,
                $file['size'],
                $file['type'],
                $userId
            ]);
            
            sendResponse('success', 'File uploaded! 📎', [
                'id' => $pdo->lastInsertId(),
                'file_name' => $file['name'],
                'file_path' => 'uploads/tasks/' . $fileName
            ]);
        } catch (PDOException $e) {
            sendResponse('error', 'Failed to save attachment', null, 500);
        }
    } else {
        sendResponse('error', 'Failed to upload file', null, 500);
    }
}

// DELETE ATTACHMENT
if (preg_match('/^\/tasks\/\d+\/attachments\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $attachmentId = $matches[1];
    
    try {
        // Get file path
        $stmt = $pdo->prepare("SELECT file_path FROM task_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        $attachment = $stmt->fetch();
        
        if ($attachment) {
            // Delete file
            $fullPath = __DIR__ . '/' . $attachment['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            
            // Delete from database
            $pdo->prepare("DELETE FROM task_attachments WHERE id = ?")->execute([$attachmentId]);
            sendResponse('success', 'Attachment deleted');
        } else {
            sendResponse('error', 'Attachment not found', null, 404);
        }
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete attachment', null, 500);
    }
}
// ==========================================
// TESTIMONIALS
// ==========================================

if ($path === '/testimonials' && $method === 'GET') {
    $approvedOnly = isset($_GET['status']) && $_GET['status'] === 'approved';

    try {
        if ($approvedOnly) {
            $stmt = $pdo->query("SELECT * FROM testimonials WHERE is_approved = 1 ORDER BY is_featured DESC, created_at DESC");
        } else {
            // Admin view - all testimonials
            $user = getUserFromToken();
            if (!$user) {
                // Public fallback - only approved
                $stmt = $pdo->query("SELECT * FROM testimonials WHERE is_approved = 1 ORDER BY is_featured DESC, created_at DESC");
            } else {
                $stmt = $pdo->query("SELECT * FROM testimonials ORDER BY created_at DESC");
            }
        }

        $testimonials = $stmt->fetchAll();
        sendResponse('success', 'Testimonials retrieved', ['testimonials' => $testimonials]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch testimonials', null, 500);
    }
}

// POST /testimonials - Public submission (pending approval)
if ($path === '/testimonials' && $method === 'POST') {
    // Support both multipart/form-data (with photo) and JSON
    $isMultipart = isset($_FILES['photo']) || !empty($_POST);
    if ($isMultipart) {
        $data = $_POST;
    } else {
        $data = getRequestData();
    }

    if (empty($data['name']) || empty($data['testimonial'])) {
        sendResponse('error', 'Name and testimonial are required', null, 400);
    }

    // Handle optional photo upload
    $imageUrl = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photoFile = $_FILES['photo'];
        $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        $mime      = finfo_file($finfo, $photoFile['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            sendResponse('error', 'Photo must be a JPG, PNG, GIF or WebP image', null, 400);
        }
        if ($photoFile['size'] > 3 * 1024 * 1024) {
            sendResponse('error', 'Photo must be under 3MB', null, 400);
        }
        $uploadDir = __DIR__ . '/uploads/testimonials/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext      = pathinfo($photoFile['name'], PATHINFO_EXTENSION);
        $filename = uniqid('tphoto_') . '_' . time() . '.' . $ext;
        if (move_uploaded_file($photoFile['tmp_name'], $uploadDir . $filename)) {
            $imageUrl = 'uploads/testimonials/' . $filename;
        }
    }

    try {
        $rating = isset($data['rating']) ? max(1, min(5, (int)$data['rating'])) : 5;

        $stmt = $pdo->prepare("
            INSERT INTO testimonials (name, company, position, testimonial, rating, image, is_approved, is_featured)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([
            htmlspecialchars(trim($data['name'])),
            htmlspecialchars(trim($data['company'] ?? '')),
            htmlspecialchars(trim($data['position'] ?? '')),
            htmlspecialchars(trim($data['testimonial'])),
            $rating,
            $imageUrl
        ]);

        $id = $pdo->lastInsertId();
        logActivity($pdo, null, $data['name'], 'testimonial_submitted', "New testimonial from {$data['name']} - pending approval");

        // In-app notification for all admins
        $company = !empty($data['company']) ? " ({$data['company']})" : '';
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            createNotification($pdo, $adminId, 'info', 'New Testimonial Submitted', "{$data['name']}{$company} submitted a testimonial — pending your approval.", '/dashboard/testimonials');
        }

        // Notify admin
        $adminHtml = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#1e40af;padding:25px;text-align:center;'>
                <h1 style='color:white;margin:0;'>New Testimonial Submitted</h1>
            </div>
            <div style='padding:25px;background:#f9fafb;'>
                <p><strong>From:</strong> {$data['name']}" . (!empty($data['company']) ? " ({$data['company']})" : "") . "</p>
                <p><strong>Testimonial:</strong> {$data['testimonial']}</p>
                <p>Please review and approve it in the admin dashboard.</p>
            </div>
        </div>";
        $notifyEmail = getNotifyEmail($pdo, 'notify_email_testimonials');
        sendEmail($notifyEmail, 'Admin', 'New Testimonial Pending Review', $adminHtml);

        sendResponse('success', 'Testimonial submitted! It will appear on the site after review.', ['id' => $id], 201);
    } catch (PDOException $e) {
        error_log("Testimonial submit error: " . $e->getMessage());
        sendResponse('error', 'Failed to submit testimonial', null, 500);
    }
}

if (preg_match('/^\/testimonials\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $id = $matches[1];
    $data = getRequestData();
    
    try {
        if (isset($data['status'])) {
            $isApproved = ($data['status'] === 'approved') ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE testimonials SET is_approved = ? WHERE id = ?");
            $stmt->execute([$isApproved, $id]);
            sendResponse('success', 'Testimonial updated');
        } elseif (isset($data['is_featured'])) {
            $stmt = $pdo->prepare("UPDATE testimonials SET is_featured = ? WHERE id = ?");
            $stmt->execute([$data['is_featured'] ? 1 : 0, $id]);
            sendResponse('success', 'Testimonial updated');
        } else {
            sendResponse('error', 'No update data provided', null, 400);
        }
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update testimonial', null, 500);
    }
}

if (preg_match('/^\/testimonials\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $id = $matches[1];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM testimonials WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse('success', 'Testimonial deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete testimonial', null, 500);
    }
}

// ==========================================
// CHAT
// ==========================================

// ==========================================
// CHAT
// ==========================================

// CREATE NEW CHAT SESSION
if ($path === '/chat/sessions' && $method === 'POST') {
    $data = getRequestData();
    $customerName = trim($data['customer_name'] ?? '');
    $customerEmail = $data['customer_email'] ?? '';
    $customerPhone = $data['customer_phone'] ?? '';
    $status = $data['status'] ?? 'active';
    
    if (empty($customerName)) {
        sendResponse('error', 'Customer name required', null, 400);
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (customer_name, customer_email, customer_phone, status, last_message_time) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$customerName, $customerEmail, $customerPhone, $status]);
        $sessionId = $pdo->lastInsertId();

        // Email staff
        $detail = $customerEmail ? " | Email: {$customerEmail}" : '';
        $detail .= $customerPhone ? " | Phone: {$customerPhone}" : '';
        $chatHtml = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
          <div style='background:#1e40af;padding:20px;text-align:center;'>
            <h1 style='color:white;margin:0;font-size:20px;'>&#128172; New Live Chat Started</h1>
          </div>
          <div style='padding:25px;background:#f9fafb;border:1px solid #e5e7eb;'>
            <p style='margin:0 0 10px;'><strong>Customer:</strong> {$customerName}</p>"
            . ($customerEmail ? "<p style='margin:0 0 10px;'><strong>Email:</strong> {$customerEmail}</p>" : "")
            . ($customerPhone ? "<p style='margin:0 0 10px;'><strong>Phone:</strong> {$customerPhone}</p>" : "") . "
            <p style='margin:20px 0 0;color:#374151;'>Please log in to the admin dashboard to respond promptly.</p>
          </div>
        </div>";
        // Fire-and-forget notifications (never let these break the response)
        try {
            $notifyEmailChat = getNotifyEmail($pdo, 'notify_email_chat');
            sendEmail($notifyEmailChat, 'Support Team', "New Chat: {$customerName}", $chatHtml);
        } catch (Exception $e) { error_log("Chat notify email error: " . $e->getMessage()); }

        // In-app notification for staff
        try {
            $notifMsg = "New chat from {$customerName}" . ($customerPhone ? " ({$customerPhone})" : '');
            $pdo->prepare("INSERT INTO notifications (type, title, message, link) VALUES ('chat', 'New Live Chat', ?, '/dashboard/chat')")
                ->execute([$notifMsg]);
        } catch (Exception $e) {}

        // Confirm to customer if they gave an email
        if ($customerEmail) {
            try {
                $custHtml = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                  <div style='background:#1e40af;padding:20px;text-align:center;'>
                    <h1 style='color:white;margin:0;font-size:20px;'>Chat Started — Stalwart Zambia</h1>
                  </div>
                  <div style='padding:25px;background:#f9fafb;border:1px solid #e5e7eb;'>
                    <p>Hello <strong>{$customerName}</strong>,</p>
                    <p>Your chat session has started. A member of our team will respond shortly.</p>
                    <p>You can also reach us directly at <a href='mailto:info@stalwartzm.com'>info@stalwartzm.com</a>.</p>
                  </div>
                </div>";
                sendEmail($customerEmail, $customerName, 'Your Chat with Stalwart Zambia', $custHtml);
            } catch (Exception $e) { error_log("Chat customer email error: " . $e->getMessage()); }
        }

        sendResponse('success', 'Chat session created', ['id' => $sessionId]);
    } catch (PDOException $e) {
        error_log("Chat session error: " . $e->getMessage());
        sendResponse('error', 'Failed to create chat session', null, 500);
    }
}

// GET /chat/summary — lightweight badge counts for the sidebar
if ($path === '/chat/summary' && $method === 'GET') {
    try {
        $active = (int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active'")->fetchColumn();

        // Sessions where the most recent message came from the customer (waiting for staff reply)
        $waiting = (int)$pdo->query("
            SELECT COUNT(*) FROM chat_sessions cs
            WHERE cs.status = 'active'
            AND (
                SELECT sender_type FROM chat_messages cm
                WHERE cm.session_id = cs.id
                ORDER BY cm.created_at DESC
                LIMIT 1
            ) NOT IN ('staff', 'admin', 'agent')
        ")->fetchColumn();

        sendResponse('success', 'Chat summary', ['active' => $active, 'waiting' => $waiting]);
    } catch (PDOException $e) {
        sendResponse('success', 'Chat summary', ['active' => 0, 'waiting' => 0]);
    }
}

// GET ALL CHAT SESSIONS
if ($path === '/chat/sessions' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM chat_sessions ORDER BY updated_at DESC");
        $sessions = $stmt->fetchAll();
        sendResponse('success', 'Chat sessions retrieved', ['sessions' => $sessions]);
    } catch (PDOException $e) {
        error_log("Get sessions error: " . $e->getMessage());
        sendResponse('error', 'Failed to fetch chat sessions', null, 500);
    }
}

// GET SESSION WITH MESSAGES (for admin dashboard)
if (preg_match('/^\/chat\/(\d+)$/', $path, $matches) && $method === 'GET') {
    $sessionId = $matches[1];

    try {
        // Get session details
        $stmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            sendResponse('error', 'Session not found', null, 404);
        }

        // Get messages
        $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC");
        $stmt->execute([$sessionId]);
        $messages = $stmt->fetchAll();

        $session['messages'] = $messages;
        sendResponse('success', 'Session retrieved', $session);
    } catch (PDOException $e) {
        error_log("Get session error: " . $e->getMessage());
        sendResponse('error', 'Failed to fetch session', null, 500);
    }
}

// GET MESSAGES FOR A SESSION
if (preg_match('/^\/chat\/sessions\/(\d+)\/messages$/', $path, $matches) && $method === 'GET') {
    $sessionId = $matches[1];

    try {
        $stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC");
        $stmt->execute([$sessionId]);
        $messages = $stmt->fetchAll();
        sendResponse('success', 'Messages retrieved', ['messages' => $messages]);
    } catch (PDOException $e) {
        error_log("Get messages error: " . $e->getMessage());
        sendResponse('error', 'Failed to fetch messages', null, 500);
    }
}

// SEND STAFF MESSAGE (for admin dashboard)
if (preg_match('/^\/chat\/(\d+)\/staff-message$/', $path, $matches) && $method === 'POST') {
    $sessionId = $matches[1];
    $data = getRequestData();
    $message = $data['message'] ?? '';

    if (empty($message)) {
        sendResponse('error', 'Message is required', null, 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, 'staff', 'Admin', ?)");
        $stmt->execute([$sessionId, $message]);

        // Update session
        $pdo->prepare("UPDATE chat_sessions SET last_message = ?, last_message_time = NOW(), updated_at = NOW() WHERE id = ?")->execute([$message, $sessionId]);

        sendResponse('success', 'Message sent', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        error_log("Send staff message error: " . $e->getMessage());
        sendResponse('error', 'Failed to send message', null, 500);
    }
}

// SEND MESSAGE - UPDATE TO HANDLE CUSTOMER MESSAGES
if (preg_match('/^\/chat\/sessions\/(\d+)\/messages$/', $path, $matches) && $method === 'POST') {
    $sessionId = $matches[1];
    $data = getRequestData();
    $message = $data['message'] ?? '';
    $senderType = $data['sender_type'] ?? 'staff';
    $senderName = $data['sender_name'] ?? 'Staff';

    if (empty($message)) {
        sendResponse('error', 'Message is required', null, 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, sender_type, sender_name, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sessionId, $senderType, $senderName, $message]);

        // Update session last_message (best-effort — column may vary across installations)
        try {
            $pdo->prepare("UPDATE chat_sessions SET last_message = ?, last_message_time = NOW() WHERE id = ?")->execute([$message, $sessionId]);
        } catch (PDOException $e) {
            try { $pdo->prepare("UPDATE chat_sessions SET last_message_time = NOW() WHERE id = ?")->execute([$sessionId]); } catch (PDOException $e2) {}
        }

        // Send notification to all staff when customer sends a message
        if ($senderType === 'customer') {
            $messagePreview = strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message;
            createNotificationForAllStaff(
                $pdo,
                'chat',
                "New message from $senderName",
                $messagePreview,
                "/dashboard/chat?session=$sessionId"
            );
        }

        sendResponse('success', 'Message sent', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to send message', null, 500);
    }
}

// ==========================================
// TEAM CONTENT
// ==========================================

if ($path === '/content/team' && $method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT
                tm.*,
                m.file_path,
                m.original_filename as media_filename
            FROM team_members tm
            LEFT JOIN media m ON tm.media_id = m.id
            ORDER BY tm.order_position ASC
        ");
        $members = $stmt->fetchAll();
        sendResponse('success', 'Team members retrieved', ['members' => $members]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch team members', null, 500);
    }
}

if (preg_match('/^\/content\/team\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $id = $matches[1];
    $data = getRequestData();
    
    try {
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['position'])) {
            $updates[] = "position = ?";
            $params[] = $data['position'];
        }
        if (isset($data['bio'])) {
            $updates[] = "bio = ?";
            $params[] = $data['bio'];
        }
        if (isset($data['education'])) {
            $updates[] = "education = ?";
            $params[] = $data['education'];
        }
        if (isset($data['specialties'])) {
            $updates[] = "specialties = ?";
            $params[] = is_array($data['specialties']) ? implode(',', $data['specialties']) : $data['specialties'];
        }
        if (isset($data['image'])) {
            $updates[] = "image = ?";
            $params[] = $data['image'];
        }
        if (isset($data['media_id'])) {
            $updates[] = "media_id = ?";
            $params[] = $data['media_id'];
        }
        
        if (empty($updates)) {
            sendResponse('error', 'No fields to update', null, 400);
        }
        
        $params[] = $id;
        $sql = "UPDATE team_members SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        sendResponse('success', 'Team member updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update team member', null, 500);
    }
}

if ($path === '/content/team' && $method === 'POST') {
    $data = getRequestData();

    try {
        // Generate slug from name
        $slug = strtolower(str_replace(' ', '-', $data['name']));

        $stmt = $pdo->prepare("
            INSERT INTO team_members (name, slug, position, bio, education, specialties, media_id, order_position, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");

        $orderPosition = $data['order_position'] ?? 999;
        $mediaId = isset($data['media_id']) && $data['media_id'] !== '' ? $data['media_id'] : null;

        $stmt->execute([
            $data['name'],
            $slug,
            $data['position'] ?? '',
            $data['bio'] ?? '',
            $data['education'] ?? '',
            $data['specialties'] ?? '',
            $mediaId,
            $orderPosition
        ]);

        sendResponse('success', 'Team member created', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to create team member', null, 500);
    }
}

// ==========================================
// SETTINGS
// ==========================================

// GET /settings/public - Public settings (no auth required)
if ($path === '/settings/public' && $method === 'GET') {
    try {
        // Get logo settings with media file paths
        $stmt = $pdo->query("
            SELECT s.setting_key, s.setting_value, m.file_path
            FROM settings s
            LEFT JOIN media m ON s.setting_value = m.id
            WHERE s.setting_key IN ('main_logo_id', 'footer_logo_id', 'favicon_id')
        ");

        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['file_path']) {
                $settings[$row['setting_key']] = $row['file_path'];
            }
        }

        // Include feature flags
        $featureStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'loan_repayment_enabled'");
        $featureRow = $featureStmt->fetch();
        $settings['loan_repayment_enabled'] = $featureRow ? $featureRow['setting_value'] : 'false';

        sendResponse('success', 'Public settings retrieved', ['settings' => $settings]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch settings', null, 500);
    }
}

if ($path === '/settings' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        sendResponse('success', 'Settings retrieved', ['settings' => $settings]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch settings', null, 500);
    }
}

if ($path === '/settings' && $method === 'PUT') {
    $data = getRequestData();
    
    try {
        $pdo->beginTransaction();
        
        foreach ($data as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $pdo->commit();
        sendResponse('success', 'Settings updated');
    } catch (PDOException $e) {
        $pdo->rollBack();
        sendResponse('error', 'Failed to update settings', null, 500);
    }
}

// ==========================================
// HOMEPAGE CONTENT
// ==========================================

if ($path === '/content/homepage' && $method === 'GET') {
    try {
        // Get homepage image settings
        $stmt = $pdo->query("
            SELECT setting_key, setting_value
            FROM settings
            WHERE setting_key IN ('homepage_hero_image_id', 'homepage_why_choose_image_id')
        ");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $images = [];

        // Fetch hero image
        if (!empty($settings['homepage_hero_image_id'])) {
            $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id = ?");
            $stmt->execute([$settings['homepage_hero_image_id']]);
            $heroImage = $stmt->fetch();
            if ($heroImage) {
                $images['hero_image'] = $heroImage['file_path'];
            }
        }

        // Fetch why choose us image
        if (!empty($settings['homepage_why_choose_image_id'])) {
            $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id = ?");
            $stmt->execute([$settings['homepage_why_choose_image_id']]);
            $whyChooseImage = $stmt->fetch();
            if ($whyChooseImage) {
                $images['why_choose_image'] = $whyChooseImage['file_path'];
            }
        }

        sendResponse('success', 'Homepage content retrieved', ['images' => $images]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch homepage content', null, 500);
    }
}

// GET /content/page-images — All configurable page images (public)
if ($path === '/content/page-images' && $method === 'GET') {
    try {
        $keys = [
            'page_about_intro_image_id',
            'page_about_section2_image_id',
            'page_services_village_image_id',
            'page_services_insurance_image_id',
            'page_village_banking_hero_id',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("
            SELECT s.setting_key, m.file_path
            FROM settings s
            LEFT JOIN media m ON m.id = CAST(s.setting_value AS UNSIGNED)
            WHERE s.setting_key IN ($placeholders)
              AND s.setting_value != ''
        ");
        $stmt->execute($keys);
        $images = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['file_path']) {
                $images[$row['setting_key']] = $row['file_path'];
            }
        }
        sendResponse('success', 'Page images retrieved', ['images' => $images]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch page images', null, 500);
    }
}

// ==========================================
// PAGE CONTENT (CMS) ROUTES
// ==========================================

// GET /content/page-text?page=home — public, returns key=>value map for a page
if ($path === '/content/page-text' && $method === 'GET') {
    $pageKey = trim($_GET['page'] ?? '');
    if (empty($pageKey)) sendResponse('error', 'page parameter required', null, 400);

    try {
        $stmt = $pdo->prepare("SELECT section_key, content FROM page_content WHERE page_key = ? ORDER BY sort_order ASC");
        $stmt->execute([$pageKey]);
        $content = [];
        foreach ($stmt->fetchAll() as $row) {
            $content[$row['section_key']] = $row['content'];
        }
        sendResponse('success', 'Content retrieved', ['content' => $content]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch page content', null, 500);
    }
}

// GET /content/page-text/schema — admin, returns all pages with labels for the editor
if ($path === '/content/page-text/schema' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 403);

    try {
        $stmt = $pdo->query("SELECT page_key, section_key, label, content, content_type, sort_order FROM page_content ORDER BY page_key, sort_order");
        $rows = $stmt->fetchAll();

        $pages = [];
        foreach ($rows as $row) {
            $pages[$row['page_key']][] = [
                'section_key'  => $row['section_key'],
                'label'        => $row['label'],
                'content'      => $row['content'],
                'content_type' => $row['content_type'],
                'sort_order'   => (int)$row['sort_order'],
            ];
        }
        sendResponse('success', 'Schema retrieved', ['pages' => $pages]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch schema', null, 500);
    }
}

// PUT /content/page-text — admin, save sections for one page
if ($path === '/content/page-text' && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 403);

    $data    = getRequestData();
    $pageKey = trim($data['page_key'] ?? '');
    $updates = $data['sections'] ?? [];

    if (empty($pageKey) || !is_array($updates)) sendResponse('error', 'page_key and sections required', null, 400);

    try {
        $stmt = $pdo->prepare("UPDATE page_content SET content = ? WHERE page_key = ? AND section_key = ?");
        foreach ($updates as $sectionKey => $content) {
            $stmt->execute([$content, $pageKey, $sectionKey]);
        }
        sendResponse('success', 'Content saved');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to save content', null, 500);
    }
}

// ==========================================
// FAQ ROUTES (for floating chat widget)
// ==========================================

// Get FAQs for public chat widget
if ($path === '/faq' && $method === 'GET') {
    try {
        $category = $_GET['category'] ?? null;
        $search = $_GET['search'] ?? null;

        $sql = "SELECT id, question, answer, category, order_position FROM faqs WHERE is_active = 1";
        $params = [];

        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($search) {
            $sql .= " AND (question LIKE ? OR answer LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= " ORDER BY order_position ASC, id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $faqs = $stmt->fetchAll();

        sendResponse('success', 'FAQs retrieved', ['faqs' => $faqs]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch FAQs', null, 500);
    }
}

// Get FAQ categories
if ($path === '/faq/categories' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT DISTINCT category FROM faqs WHERE is_active = 1 AND category IS NOT NULL ORDER BY category");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sendResponse('success', 'Categories retrieved', ['categories' => $categories]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch categories', null, 500);
    }
}

// Admin: Get all FAQs
if ($path === '/admin/faq' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM faqs ORDER BY order_position ASC, id ASC");
        $faqs = $stmt->fetchAll();
        sendResponse('success', 'FAQs retrieved', ['faqs' => $faqs]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch FAQs', null, 500);
    }
}

// Admin: Create FAQ
if ($path === '/admin/faq' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $data = getRequestData();
    $question = sanitizeInput($data['question'] ?? '');
    $answer = $data['answer'] ?? '';
    $category = sanitizeInput($data['category'] ?? '');
    $orderPosition = (int)($data['order_position'] ?? 0);
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    if (empty($question) || empty($answer)) {
        sendResponse('error', 'Question and answer are required', null, 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO faqs (question, answer, category, order_position, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$question, $answer, $category ?: null, $orderPosition, $isActive]);
        sendResponse('success', 'FAQ created', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to create FAQ', null, 500);
    }
}

// Admin: Update FAQ
if (preg_match('/^\/admin\/faq\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $id = $matches[1];
    $data = getRequestData();

    try {
        $updates = [];
        $params = [];

        if (isset($data['question'])) {
            $updates[] = "question = ?";
            $params[] = trim($data['question']);
        }
        if (isset($data['answer'])) {
            $updates[] = "answer = ?";
            $params[] = $data['answer'];
        }
        if (isset($data['category'])) {
            $updates[] = "category = ?";
            $params[] = $data['category'] ?: null;
        }
        if (isset($data['order_position'])) {
            $updates[] = "order_position = ?";
            $params[] = (int)$data['order_position'];
        }
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = (int)$data['is_active'];
        }

        if (empty($updates)) {
            sendResponse('error', 'No fields to update', null, 400);
        }

        $params[] = $id;
        $sql = "UPDATE faqs SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        sendResponse('success', 'FAQ updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update FAQ', null, 500);
    }
}

// Admin: Delete FAQ
if (preg_match('/^\/admin\/faq\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = ?");
        $stmt->execute([$id]);
        sendResponse('success', 'FAQ deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete FAQ', null, 500);
    }
}

// Admin: Reorder FAQs
if ($path === '/admin/faq/reorder' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $data = getRequestData();
    $orders = $data['orders'] ?? [];

    if (empty($orders)) {
        sendResponse('error', 'Orders array is required', null, 400);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE faqs SET order_position = ? WHERE id = ?");

        foreach ($orders as $order) {
            $stmt->execute([$order['position'], $order['id']]);
        }

        $pdo->commit();
        sendResponse('success', 'FAQs reordered');
    } catch (PDOException $e) {
        $pdo->rollBack();
        sendResponse('error', 'Failed to reorder FAQs', null, 500);
    }
}

// ==========================================
// MEDIA MANAGEMENT ROUTES
// ==========================================

// Upload media file
if ($path === '/media/upload' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    if (!isset($_FILES['file'])) {
        sendResponse('error', 'No file uploaded', null, 400);
    }

    $file = $_FILES['file'];

    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        sendResponse('error', 'Invalid file type. Only images allowed', null, 400);
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        sendResponse('error', 'File too large. Maximum size is 5MB', null, 400);
    }

    // Create upload directory
    $uploadDir = __DIR__ . '/uploads/media/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $filename;
    $relativePath = 'uploads/media/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        try {
            // Save to database (using your existing table structure)
            $stmt = $pdo->prepare("
                INSERT INTO media (filename, original_filename, file_path, file_type, file_size, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $filename,
                $file['name'],
                $relativePath,
                $file['type'],
                $file['size'],
                $user['id']
            ]);

            sendResponse('success', 'File uploaded successfully', [
                'id' => $pdo->lastInsertId(),
                'filename' => $filename,
                'path' => $relativePath,
                'url' => '/' . $relativePath
            ]);
        } catch (PDOException $e) {
            unlink($filePath); // Delete file if database insert fails
            sendResponse('error', 'Failed to save file info', null, 500);
        }
    } else {
        sendResponse('error', 'Failed to upload file', null, 500);
    }
}

// Get all media
if ($path === '/media' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        $stmt = $pdo->query("SELECT * FROM media ORDER BY created_at DESC");
        $media = $stmt->fetchAll();
        sendResponse('success', 'Media retrieved', ['media' => $media]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch media', null, 500);
    }
}

// Get single media item
if (preg_match('/^\/media\/(\d+)$/', $path, $matches) && $method === 'GET') {
    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ?");
        $stmt->execute([$id]);
        $media = $stmt->fetch();

        if (!$media) {
            sendResponse('error', 'Media not found', null, 404);
        }

        sendResponse('success', 'Media retrieved', ['media' => $media]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch media', null, 500);
    }
}

// Update media filename (limited update since table has minimal fields)
if (preg_match('/^\/media\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $id = $matches[1];
    $data = getRequestData();

    try {
        if (isset($data['filename'])) {
            $stmt = $pdo->prepare("UPDATE media SET filename = ? WHERE id = ?");
            $stmt->execute([sanitizeInput($data['filename']), $id]);
            sendResponse('success', 'Media updated');
        } else {
            sendResponse('error', 'No filename provided', null, 400);
        }
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update media', null, 500);
    }
}

// Delete media
if (preg_match('/^\/media\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $id = $matches[1];

    try {
        // Get file path
        $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id = ?");
        $stmt->execute([$id]);
        $media = $stmt->fetch();

        if ($media) {
            // Delete file
            $fullPath = __DIR__ . '/' . $media['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            // Delete from database
            $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);
            sendResponse('success', 'Media deleted');
        } else {
            sendResponse('error', 'Media not found', null, 404);
        }
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete media', null, 500);
    }
}

// ==========================================
// CAREERS / JOB POSTINGS
// ==========================================

// Get all active job postings (public)
if ($path === '/careers' && $method === 'GET') {
    try {
        $sql = "SELECT * FROM job_postings WHERE is_active = 1";

        // Admin can see all jobs
        $user = getUserFromToken();
        if ($user && $user['role'] === 'admin') {
            $sql = "SELECT * FROM job_postings";
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->query($sql);
        $jobs = $stmt->fetchAll();

        sendResponse('success', 'Job postings retrieved', ['jobs' => $jobs]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch job postings', null, 500);
    }
}

// Create new job posting (admin only)
if ($path === '/careers' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 403);
    }

    $data = getRequestData();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO job_postings (title, department, location, job_type, description, requirements, responsibilities, salary_range, deadline, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['title'],
            $data['department'] ?? null,
            $data['location'] ?? 'Lusaka, Zambia',
            $data['job_type'] ?? 'Full-time',
            $data['description'],
            $data['requirements'],
            $data['responsibilities'] ?? null,
            $data['salary_range'] ?? null,
            $data['deadline'] ?? null,
            $user['id']
        ]);

        logActivity($pdo, $user['id'], $user['email'], 'job_created', "Created job posting: {$data['title']}");
        sendResponse('success', 'Job posting created', ['id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to create job posting', null, 500);
    }
}

// Update job posting (admin only)
if (preg_match('/^\/careers\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 403);
    }

    $id = $matches[1];
    $data = getRequestData();

    try {
        $updates = [];
        $params = [];

        if (isset($data['title'])) { $updates[] = "title = ?"; $params[] = $data['title']; }
        if (isset($data['department'])) { $updates[] = "department = ?"; $params[] = $data['department']; }
        if (isset($data['location'])) { $updates[] = "location = ?"; $params[] = $data['location']; }
        if (isset($data['job_type'])) { $updates[] = "job_type = ?"; $params[] = $data['job_type']; }
        if (isset($data['description'])) { $updates[] = "description = ?"; $params[] = $data['description']; }
        if (isset($data['requirements'])) { $updates[] = "requirements = ?"; $params[] = $data['requirements']; }
        if (isset($data['responsibilities'])) { $updates[] = "responsibilities = ?"; $params[] = $data['responsibilities']; }
        if (isset($data['salary_range'])) { $updates[] = "salary_range = ?"; $params[] = $data['salary_range']; }
        if (isset($data['deadline'])) { $updates[] = "deadline = ?"; $params[] = $data['deadline']; }
        if (isset($data['is_active'])) { $updates[] = "is_active = ?"; $params[] = $data['is_active']; }

        if (empty($updates)) {
            sendResponse('error', 'No fields to update', null, 400);
        }

        $params[] = $id;
        $sql = "UPDATE job_postings SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        logActivity($pdo, $user['id'], $user['email'], 'job_updated', "Updated job posting ID: $id");
        sendResponse('success', 'Job posting updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update job posting', null, 500);
    }
}

// Delete job posting (admin only)
if (preg_match('/^\/careers\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 403);
    }

    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("DELETE FROM job_postings WHERE id = ?");
        $stmt->execute([$id]);

        logActivity($pdo, $user['id'], $user['email'], 'job_deleted', "Deleted job posting ID: $id");
        sendResponse('success', 'Job posting deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete job posting', null, 500);
    }
}

// Submit job application (public)
if (preg_match('/^\/careers\/(\d+)\/apply$/', $path, $matches) && $method === 'POST') {
    $jobId = $matches[1];

    if (!isset($_FILES['cv'])) {
        sendResponse('error', 'CV file is required', null, 400);
    }

    $data    = $_POST;
    $cvFile  = $_FILES['cv'];
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    if (!in_array($cvFile['type'], $allowedTypes)) {
        sendResponse('error', 'Invalid file type. Please upload PDF or Word document', null, 400);
    }
    if ($cvFile['size'] > 5 * 1024 * 1024) {
        sendResponse('error', 'File too large. Maximum size is 5MB', null, 400);
    }

    // Validate academic doc if provided
    $academicFile = $_FILES['academic_doc'] ?? null;
    if ($academicFile && $academicFile['error'] === UPLOAD_ERR_OK) {
        if (!in_array($academicFile['type'], $allowedTypes)) {
            sendResponse('error', 'Academic document must be PDF or Word', null, 400);
        }
        if ($academicFile['size'] > 5 * 1024 * 1024) {
            sendResponse('error', 'Academic document too large. Maximum size is 5MB', null, 400);
        }
    }

    try {
        $uploadDir = __DIR__ . '/uploads/cvs/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Save CV
        $cvExt      = pathinfo($cvFile['name'], PATHINFO_EXTENSION);
        $cvFilename = uniqid('cv_') . '_' . time() . '.' . $cvExt;
        if (!move_uploaded_file($cvFile['tmp_name'], $uploadDir . $cvFilename)) {
            sendResponse('error', 'Failed to upload CV', null, 500);
        }

        // Save academic doc if provided
        $academicFilename = null;
        $academicFilepath = null;
        if ($academicFile && $academicFile['error'] === UPLOAD_ERR_OK) {
            $acadExt          = pathinfo($academicFile['name'], PATHINFO_EXTENSION);
            $academicFilename = uniqid('acad_') . '_' . time() . '.' . $acadExt;
            if (!move_uploaded_file($academicFile['tmp_name'], $uploadDir . $academicFilename)) {
                sendResponse('error', 'Failed to upload academic document', null, 500);
            }
            $academicFilepath = 'uploads/cvs/' . $academicFilename;
        }

        // Resolve qualification label
        $qualification = $data['qualification'] ?? null;
        $qualOther     = ($qualification === 'other') ? ($data['qualification_other'] ?? null) : null;

        $stmt = $pdo->prepare("
            INSERT INTO job_applications
                (job_id, full_name, email, phone, qualification, qualification_other, cover_letter,
                 cv_filename, cv_filepath, academic_doc_filename, academic_doc_filepath)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $jobId,
            $data['full_name'],
            $data['email'],
            $data['phone'],
            $qualification,
            $qualOther,
            $data['cover_letter'] ?? null,
            $cvFile['name'],
            'uploads/cvs/' . $cvFilename,
            $academicFile ? $academicFile['name'] : null,
            $academicFilepath
        ]);

        $applicationId = $pdo->lastInsertId();

        // Get job title for email
        $jobStmt = $pdo->prepare("SELECT title FROM job_listings WHERE id = ?");
        $jobStmt->execute([$jobId]);
        $jobTitle = $jobStmt->fetchColumn() ?: 'Position #' . $jobId;

        // Send confirmation email to applicant
        $qualLabel = $qualification ? ucfirst(str_replace('_', ' ', $qualification)) : 'N/A';
        if ($qualification === 'other' && $qualOther) $qualLabel = $qualOther;

        $applicantHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #1e40af; padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Application Received</h1>
            </div>
            <div style='padding: 30px; background: #f9fafb;'>
                <p style='font-size: 16px;'>Dear <strong>{$data['full_name']}</strong>,</p>
                <p>Thank you for applying to <strong>Stalwart Zambia</strong>. We have received your application for the position of <strong>$jobTitle</strong>.</p>
                <div style='background: white; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #1e40af;'>
                    <p style='margin: 5px 0;'><strong>Position:</strong> $jobTitle</p>
                    <p style='margin: 5px 0;'><strong>Qualification:</strong> $qualLabel</p>
                    <p style='margin: 5px 0;'><strong>Reference:</strong> APP-$applicationId</p>
                </div>
                <p>Our team will review your application and contact you within <strong>5-7 business days</strong> if your profile matches our requirements.</p>
                <p>If you have any questions, please contact us at <a href='mailto:info@stalwartzm.com'>info@stalwartzm.com</a> or call <strong>+260 976 054 486</strong>.</p>
                <p>Best regards,<br><strong>Stalwart Zambia HR Team</strong></p>
            </div>
            <div style='background: #374151; padding: 15px; text-align: center;'>
                <p style='color: #9ca3af; font-size: 12px; margin: 0;'>Stalwart Services Ltd &bull; Second Floor, Woodgate House, Cairo Rd, Lusaka</p>
            </div>
        </div>";

        sendEmail($data['email'], $data['full_name'], "Application Received - $jobTitle | Stalwart Zambia", $applicantHtml);

        // Notify HR/admin
        $adminHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #1e40af; padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>New Job Application</h1>
            </div>
            <div style='padding: 30px; background: #f9fafb;'>
                <p>A new application has been submitted for <strong>$jobTitle</strong>.</p>
                <div style='background: white; border-radius: 8px; padding: 20px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Applicant:</strong> {$data['full_name']}</p>
                    <p style='margin: 5px 0;'><strong>Email:</strong> {$data['email']}</p>
                    <p style='margin: 5px 0;'><strong>Phone:</strong> {$data['phone']}</p>
                    <p style='margin: 5px 0;'><strong>Qualification:</strong> $qualLabel</p>
                    <p style='margin: 5px 0;'><strong>Reference:</strong> APP-$applicationId</p>
                </div>
                <p>Log in to the admin dashboard to review this application.</p>
            </div>
        </div>";

        $notifyEmailJobs = getNotifyEmail($pdo, 'notify_email_jobs');
        sendEmail($notifyEmailJobs, 'HR Team', "New Application: $jobTitle from {$data['full_name']}", $adminHtml);

        logActivity($pdo, null, $data['email'], 'job_application', "New application (APP-$applicationId) for '$jobTitle' from {$data['full_name']}");
        sendResponse('success', 'Application submitted successfully', ['id' => $applicationId]);

    } catch (PDOException $e) {
        error_log("Job application error: " . $e->getMessage());
        sendResponse('error', 'Failed to submit application', null, 500);
    }
}

// Submit general/unsolicited application (public, no job_id required)
if ($path === '/careers/general/apply' && $method === 'POST') {
    if (!isset($_FILES['cv'])) {
        sendResponse('error', 'CV file is required', null, 400);
    }

    $data    = $_POST;
    $cvFile  = $_FILES['cv'];
    $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    if (!in_array($cvFile['type'], $allowedTypes)) {
        sendResponse('error', 'Invalid file type. Please upload PDF or Word document', null, 400);
    }
    if ($cvFile['size'] > 5 * 1024 * 1024) {
        sendResponse('error', 'File too large. Maximum size is 5MB', null, 400);
    }

    $academicFile = $_FILES['academic_doc'] ?? null;
    if ($academicFile && $academicFile['error'] === UPLOAD_ERR_OK) {
        if (!in_array($academicFile['type'], $allowedTypes)) {
            sendResponse('error', 'Academic document must be PDF or Word', null, 400);
        }
        if ($academicFile['size'] > 5 * 1024 * 1024) {
            sendResponse('error', 'Academic document too large. Maximum size is 5MB', null, 400);
        }
    }

    try {
        $uploadDir = __DIR__ . '/uploads/cvs/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $cvExt      = pathinfo($cvFile['name'], PATHINFO_EXTENSION);
        $cvFilename = uniqid('cv_') . '_' . time() . '.' . $cvExt;
        if (!move_uploaded_file($cvFile['tmp_name'], $uploadDir . $cvFilename)) {
            sendResponse('error', 'Failed to upload CV', null, 500);
        }

        $academicFilename = null;
        $academicFilepath = null;
        if ($academicFile && $academicFile['error'] === UPLOAD_ERR_OK) {
            $acadExt          = pathinfo($academicFile['name'], PATHINFO_EXTENSION);
            $academicFilename = uniqid('acad_') . '_' . time() . '.' . $acadExt;
            if (!move_uploaded_file($academicFile['tmp_name'], $uploadDir . $academicFilename)) {
                sendResponse('error', 'Failed to upload academic document', null, 500);
            }
            $academicFilepath = 'uploads/cvs/' . $academicFilename;
        }

        $qualification = $data['qualification'] ?? null;
        $qualOther     = ($qualification === 'other') ? ($data['qualification_other'] ?? null) : null;

        // job_id is NULL for general applications
        $stmt = $pdo->prepare("
            INSERT INTO job_applications
                (job_id, full_name, email, phone, qualification, qualification_other, cover_letter,
                 cv_filename, cv_filepath, academic_doc_filename, academic_doc_filepath)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['full_name'],
            $data['email'],
            $data['phone'],
            $qualification,
            $qualOther,
            $data['cover_letter'] ?? null,
            $cvFile['name'],
            'uploads/cvs/' . $cvFilename,
            $academicFile ? $academicFile['name'] : null,
            $academicFilepath
        ]);

        $applicationId = $pdo->lastInsertId();

        $qualLabel = $qualification ? ucfirst(str_replace('_', ' ', $qualification)) : 'N/A';
        if ($qualification === 'other' && $qualOther) $qualLabel = $qualOther;

        $applicantHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #1e40af; padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Application Received</h1>
            </div>
            <div style='padding: 30px; background: #f9fafb;'>
                <p style='font-size: 16px;'>Dear <strong>{$data['full_name']}</strong>,</p>
                <p>Thank you for your interest in joining <strong>Stalwart Zambia</strong>. We have received your general application and will keep your details on file.</p>
                <div style='background: white; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #1e40af;'>
                    <p style='margin: 5px 0;'><strong>Application Type:</strong> General / Unsolicited</p>
                    <p style='margin: 5px 0;'><strong>Qualification:</strong> $qualLabel</p>
                    <p style='margin: 5px 0;'><strong>Reference:</strong> APP-$applicationId</p>
                </div>
                <p>We will keep your profile on file and reach out when a suitable position becomes available. This typically happens within <strong>3–6 months</strong>.</p>
                <p>If you have any questions, contact us at <a href='mailto:info@stalwartzm.com'>info@stalwartzm.com</a> or call <strong>+260 976 054 486</strong>.</p>
                <p>Best regards,<br><strong>Stalwart Zambia HR Team</strong></p>
            </div>
            <div style='background: #374151; padding: 15px; text-align: center;'>
                <p style='color: #9ca3af; font-size: 12px; margin: 0;'>Stalwart Services Ltd &bull; Second Floor, Woodgate House, Cairo Rd, Lusaka</p>
            </div>
        </div>";

        sendEmail($data['email'], $data['full_name'], 'General Application Received | Stalwart Zambia', $applicantHtml);

        $notifyEmailJobs = getNotifyEmail($pdo, 'notify_email_jobs');
        $adminHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: #1e40af; padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>New General Application</h1>
            </div>
            <div style='padding: 30px; background: #f9fafb;'>
                <p>A new unsolicited/general application has been submitted.</p>
                <div style='background: white; border-radius: 8px; padding: 20px; margin: 20px 0;'>
                    <p style='margin: 5px 0;'><strong>Applicant:</strong> {$data['full_name']}</p>
                    <p style='margin: 5px 0;'><strong>Email:</strong> {$data['email']}</p>
                    <p style='margin: 5px 0;'><strong>Phone:</strong> {$data['phone']}</p>
                    <p style='margin: 5px 0;'><strong>Qualification:</strong> $qualLabel</p>
                    <p style='margin: 5px 0;'><strong>Reference:</strong> APP-$applicationId</p>
                </div>
            </div>
        </div>";

        sendEmail($notifyEmailJobs, 'HR Team', "New General Application from {$data['full_name']}", $adminHtml);

        logActivity($pdo, null, $data['email'], 'job_application', "General application (APP-$applicationId) from {$data['full_name']}");
        sendResponse('success', 'Application submitted successfully', ['id' => $applicationId]);

    } catch (PDOException $e) {
        error_log("General application error: " . $e->getMessage());
        sendResponse('error', 'Failed to submit application', null, 500);
    }
}


// Get all applications (admin only)
if ($path === '/careers/applications' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 403);
    }

    try {
        $sql = "
            SELECT ja.*, jp.title as job_title
            FROM job_applications ja
            LEFT JOIN job_postings jp ON ja.job_id = jp.id
            ORDER BY ja.created_at DESC
        ";
        $stmt = $pdo->query($sql);
        $applications = $stmt->fetchAll();

        sendResponse('success', 'Applications retrieved', ['applications' => $applications]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch applications', null, 500);
    }
}

// Update application status (admin only)
if (preg_match('/^\/careers\/applications\/(\d+)$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 403);
    }

    $id = $matches[1];
    $data = getRequestData();

    try {
        $stmt = $pdo->prepare("
            UPDATE job_applications
            SET status = ?, notes = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $data['status'] ?? 'pending',
            $data['notes'] ?? null,
            $user['id'],
            $id
        ]);

        logActivity($pdo, $user['id'], $user['email'], 'application_reviewed', "Reviewed job application ID: $id");
        sendResponse('success', 'Application updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update application', null, 500);
    }
}

// ==========================================
// ACTIVITY LOGS
// ==========================================

if ($path === '/activity-logs' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 403);

    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action (action),
            INDEX idx_created (created_at),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $limit  = min(200, (int)($_GET['limit']  ?? 100));
        $offset = (int)($_GET['offset'] ?? 0);
        $action = $_GET['action']   ?? null;
        $userId = $_GET['user_id']  ?? null;
        $dateFrom = $_GET['date_from'] ?? null; // YYYY-MM-DD
        $dateTo   = $_GET['date_to']   ?? null;

        $where  = "WHERE 1=1";
        $params = [];

        if ($action)   { $where .= " AND action = ?";            $params[] = $action; }
        if ($userId)   { $where .= " AND user_id = ?";           $params[] = $userId; }
        if ($dateFrom) { $where .= " AND DATE(created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where .= " AND DATE(created_at) <= ?"; $params[] = $dateTo; }

        $logs = $pdo->prepare("SELECT * FROM activity_logs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $logs->execute($params);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $where");
        $countStmt->execute($params);

        // Unique actions for filter dropdown
        $actions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

        sendResponse('success', 'Activity logs retrieved', [
            'logs'    => $logs->fetchAll(),
            'total'   => (int)$countStmt->fetchColumn(),
            'limit'   => $limit,
            'offset'  => $offset,
            'actions' => $actions,
        ]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch activity logs', null, 500);
    }
}

// ==========================================
// ANALYTICS STATS (REAL DATA)
// ==========================================

if ($path === '/analytics/stats' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user) {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        // Job applications
        $totalApplications = (int)$pdo->query("SELECT COUNT(*) FROM job_applications")->fetchColumn();
        $pendingApplications = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'pending' OR status IS NULL")->fetchColumn();

        // Testimonials
        $totalTestimonials = (int)$pdo->query("SELECT COUNT(*) FROM testimonials")->fetchColumn();
        $approvedTestimonials = (int)$pdo->query("SELECT COUNT(*) FROM testimonials WHERE is_approved = 1")->fetchColumn();
        $pendingTestimonials = (int)$pdo->query("SELECT COUNT(*) FROM testimonials WHERE is_approved = 0")->fetchColumn();

        // Chat sessions
        $totalChats = (int)$pdo->query("SELECT COUNT(*) FROM chat_sessions")->fetchColumn();
        $activeChats = (int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active'")->fetchColumn();
        $totalMessages = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn();

        // Users
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

        // Tasks
        $totalTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
        $completedTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'")->fetchColumn();
        $pendingTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'pending'")->fetchColumn();
        $inProgressTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'in_progress'")->fetchColumn();

        // Loan repayments (if tables exist)
        $loanAccounts = 0;
        $loanPayments = 0;
        $pendingPayments = 0;
        $confirmedPayments = 0;
        $totalRevenue = 0;
        try {
            $loanAccounts    = (int)$pdo->query("SELECT COUNT(*) FROM loan_accounts WHERE loan_status = 'active'")->fetchColumn();
            $loanPayments    = (int)$pdo->query("SELECT COUNT(*) FROM loan_payments")->fetchColumn();
            $pendingPayments = (int)$pdo->query("SELECT COUNT(*) FROM loan_payments WHERE status = 'pending'")->fetchColumn();
            $confirmedPayments = (int)$pdo->query("SELECT COUNT(*) FROM loan_payments WHERE status = 'completed'")->fetchColumn();
            $totalRevenue    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status = 'completed'")->fetchColumn();
        } catch (PDOException $e) { /* tables may not exist */ }

        // Activity logs count
        $totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

        // Recent activity (last 7 days)
        $recentActivity = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

        // Monthly applications (current month)
        $monthlyApplications = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())")->fetchColumn();

        sendResponse('success', 'Analytics stats retrieved', [
            'applications'         => ['total' => $totalApplications, 'pending' => $pendingApplications, 'this_month' => $monthlyApplications],
            'testimonials'         => ['total' => $totalTestimonials, 'approved' => $approvedTestimonials, 'pending' => $pendingTestimonials],
            'chats'                => ['total' => $totalChats, 'active' => $activeChats, 'messages' => $totalMessages],
            'users'                => ['total' => $totalUsers],
            'tasks'                => ['total' => $totalTasks, 'completed' => $completedTasks, 'pending' => $pendingTasks, 'in_progress' => $inProgressTasks],
            'loans'                => ['active_accounts' => $loanAccounts, 'total_payments' => $loanPayments, 'pending_payments' => $pendingPayments, 'confirmed_payments' => $confirmedPayments, 'total_revenue' => $totalRevenue],
            'logs'                 => ['total' => $totalLogs, 'last_7_days' => $recentActivity]
        ]);
    } catch (PDOException $e) {
        error_log("Analytics stats error: " . $e->getMessage());
        sendResponse('error', 'Failed to retrieve analytics', null, 500);
    }
}

// ==========================================
// CALL ANALYTICS
// ==========================================

if ($path === '/analytics/calls' && $method === 'GET') {
    $user = requireAdmin($pdo);

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    [$year, $mon] = explode('-', $month);

    try {
        // Month summary
        $summary = $pdo->prepare("
            SELECT
                COUNT(*)                          AS total_reports,
                COALESCE(SUM(total_count),      0) AS total_calls,
                COALESCE(SUM(answered_count),   0) AS answered_calls,
                COALESCE(SUM(unanswered_count), 0) AS unanswered_calls
            FROM call_reports
            WHERE YEAR(report_date) = ? AND MONTH(report_date) = ?
        ");
        $summary->execute([$year, $mon]);
        $summaryData = $summary->fetch();

        // Per-staff breakdown ranked by total calls
        $staffStmt = $pdo->prepare("
            SELECT
                staff_name,
                COUNT(*)                           AS report_days,
                COALESCE(SUM(total_count),      0) AS total_calls,
                COALESCE(SUM(answered_count),   0) AS answered_calls,
                COALESCE(SUM(unanswered_count), 0) AS unanswered_calls,
                ROUND(COALESCE(SUM(answered_count),0) / NULLIF(SUM(total_count),0) * 100, 1) AS answer_rate
            FROM call_reports
            WHERE YEAR(report_date) = ? AND MONTH(report_date) = ?
            GROUP BY staff_name
            ORDER BY total_calls DESC
        ");
        $staffStmt->execute([$year, $mon]);
        $byStaff = $staffStmt->fetchAll();

        // Daily totals for bar/line chart
        $dailyStmt = $pdo->prepare("
            SELECT
                report_date,
                SUM(total_count)      AS total_calls,
                SUM(answered_count)   AS answered_calls,
                SUM(unanswered_count) AS unanswered_calls
            FROM call_reports
            WHERE YEAR(report_date) = ? AND MONTH(report_date) = ?
            GROUP BY report_date
            ORDER BY report_date ASC
        ");
        $dailyStmt->execute([$year, $mon]);
        $byDay = $dailyStmt->fetchAll();

        // Available months for the picker
        $availableMonths = $pdo->query("
            SELECT DISTINCT DATE_FORMAT(report_date, '%Y-%m') AS month
            FROM call_reports ORDER BY month DESC LIMIT 24
        ")->fetchAll(PDO::FETCH_COLUMN);

        sendResponse('success', 'Call analytics retrieved', [
            'month'             => $month,
            'summary'           => $summaryData,
            'by_staff'          => $byStaff,
            'by_day'            => $byDay,
            'available_months'  => $availableMonths,
        ]);
    } catch (PDOException $e) {
        error_log("Call analytics error: " . $e->getMessage());
        sendResponse('error', 'Failed to retrieve call analytics', null, 500);
    }
}

// ==========================================
// STAFF PERFORMANCE
// ==========================================

if ($path === '/analytics/staff' && $method === 'GET') {
    $user = requireAdmin($pdo);

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    [$year, $mon] = explode('-', $month);

    try {
        $users = $pdo->query("SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

        $performance = [];
        foreach ($users as $u) {
            // Tasks assigned to this user (all-time totals + completed this month)
            $taskStmt = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT t.id) AS total_assigned,
                    SUM(CASE WHEN ta.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
                    SUM(CASE WHEN ta.status != 'completed' AND t.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN ta.status != 'completed' AND t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ta.status = 'completed'
                        AND YEAR(ta.completed_at) = ? AND MONTH(ta.completed_at) = ? THEN 1 ELSE 0 END) AS completed_this_month
                FROM tasks t
                JOIN task_assignees ta ON ta.task_id = t.id
                WHERE ta.user_id = ?
            ");
            $taskStmt->execute([$year, $mon, $u['id']]);
            $taskData = $taskStmt->fetch();

            // Call reports submitted by this user this month
            $callStmt = $pdo->prepare("
                SELECT
                    COUNT(*)                           AS report_days,
                    COALESCE(SUM(total_count),      0) AS total_calls,
                    COALESCE(SUM(answered_count),   0) AS answered_calls,
                    COALESCE(SUM(unanswered_count), 0) AS unanswered_calls,
                    ROUND(COALESCE(SUM(answered_count),0) / NULLIF(SUM(total_count),0) * 100, 1) AS answer_rate
                FROM call_reports
                WHERE staff_id = ? AND YEAR(report_date) = ? AND MONTH(report_date) = ?
            ");
            $callStmt->execute([$u['id'], $year, $mon]);
            $callData = $callStmt->fetch();

            $performance[] = [
                'id'    => $u['id'],
                'name'  => $u['name'],
                'email' => $u['email'],
                'role'  => $u['role'],
                'tasks' => $taskData,
                'calls' => $callData,
            ];
        }

        // Available months
        $availableMonths = $pdo->query("
            SELECT DISTINCT DATE_FORMAT(report_date, '%Y-%m') AS month
            FROM call_reports ORDER BY month DESC LIMIT 24
        ")->fetchAll(PDO::FETCH_COLUMN);

        sendResponse('success', 'Staff performance retrieved', [
            'month'             => $month,
            'staff'             => $performance,
            'available_months'  => $availableMonths,
        ]);
    } catch (PDOException $e) {
        error_log("Staff performance error: " . $e->getMessage());
        sendResponse('error', 'Failed to retrieve staff performance', null, 500);
    }
}

// ==========================================
// GOOGLE ANALYTICS ROUTES
// ==========================================

if ($path === '/analytics/google' && $method === 'GET') {
    require_once __DIR__ . '/controllers/AnalyticsController.php';
    $controller = new AnalyticsController();
    $controller->getGoogleAnalytics();
    exit();
}

if ($path === '/analytics/google/settings' && $method === 'POST') {
    require_once __DIR__ . '/controllers/AnalyticsController.php';
    $controller = new AnalyticsController();
    $controller->saveGoogleAnalyticsSettings();
    exit();
}

if ($path === '/analytics/google/credentials' && $method === 'POST') {
    require_once __DIR__ . '/controllers/AnalyticsController.php';
    $controller = new AnalyticsController();
    $controller->uploadGoogleAnalyticsCredentials();
    exit();
}

// ==========================================
// VILLAGE BANKING WITHDRAWAL CHECK
// ==========================================

// POST /village-banking/withdrawal - Public submission
if ($path === '/village-banking/withdrawal' && $method === 'POST') {
    $data = getRequestData();

    $fullName      = trim($data['full_name']        ?? '');
    $nrcNumber     = trim($data['nrc_number']        ?? '');
    $phone         = trim($data['phone']             ?? '');
    $email         = trim($data['email']             ?? '');
    $groupName     = trim($data['group_name']        ?? '');
    $groupLocation = trim($data['group_location']    ?? '');
    $leaderName    = trim($data['leader_name']       ?? '');
    $leaderPhone   = trim($data['leader_phone']      ?? '');
    $requestType   = trim($data['request_type']      ?? 'withdrawal');
    $amount        = trim($data['amount']            ?? '');
    $reason        = trim($data['reason']            ?? '');
    $meetingDate   = trim($data['meeting_date']      ?? '');
    $notes         = trim($data['notes']             ?? '');
    $honeypot      = $data['website']                ?? '';
    $timestamp     = $data['timestamp']              ?? 0;

    // Validation
    if (empty($fullName) || empty($nrcNumber) || empty($phone) || empty($groupName)) {
        sendResponse('error', 'Full name, NRC number, phone number, and group name are required', null, 400);
    }

    // Spam checks
    if (!empty($honeypot)) {
        sendResponse('success', 'Request received. Our team will contact you shortly.');
    }
    if ($timestamp > 0 && (time() - $timestamp) < 3) {
        sendResponse('success', 'Request received. Our team will contact you shortly.');
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // Auto-create table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS village_banking_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reference VARCHAR(20) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            nrc_number VARCHAR(30) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            email VARCHAR(150) NULL,
            group_name VARCHAR(100) NOT NULL,
            group_location VARCHAR(100) NULL,
            leader_name VARCHAR(100) NULL,
            leader_phone VARCHAR(30) NULL,
            request_type ENUM('withdrawal','balance_check','account_inquiry') DEFAULT 'withdrawal',
            amount DECIMAL(15,2) NULL,
            reason VARCHAR(100) NULL,
            meeting_date DATE NULL,
            notes TEXT NULL,
            status ENUM('pending','processing','completed','rejected') DEFAULT 'pending',
            ip_address VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* already exists */ }

    // Generate reference number: VB-YYYYMMDD-XXXXXX
    $reference = 'VB-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    try {
        $stmt = $pdo->prepare("
            INSERT INTO village_banking_requests
                (reference, full_name, nrc_number, phone, email, group_name, group_location,
                 leader_name, leader_phone, request_type, amount, reason, meeting_date, notes, ip_address)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $reference, $fullName, $nrcNumber, $phone,
            $email ?: null, $groupName, $groupLocation ?: null,
            $leaderName ?: null, $leaderPhone ?: null,
            in_array($requestType, ['withdrawal','balance_check','account_inquiry']) ? $requestType : 'withdrawal',
            is_numeric($amount) ? (float)$amount : null,
            $reason ?: null,
            $meetingDate ?: null,
            $notes ?: null,
            $ipAddress
        ]);

        // Notify admin
        $notifyEmail = getNotifyEmail($pdo, 'notify_email_jobs', 'info@stalwartzm.com');
        $typeLabel = ['withdrawal' => 'Withdrawal Request', 'balance_check' => 'Balance Check', 'account_inquiry' => 'Account Inquiry'][$requestType] ?? 'Request';
        sendEmail($notifyEmail, 'Stalwart Team', "Village Banking — New {$typeLabel}", "
            <h2>Village Banking {$typeLabel}</h2>
            <p><strong>Reference:</strong> {$reference}</p>
            <p><strong>Name:</strong> {$fullName}</p>
            <p><strong>NRC:</strong> {$nrcNumber}</p>
            <p><strong>Phone:</strong> {$phone}</p>
            <p><strong>Group:</strong> {$groupName}" . ($groupLocation ? " ({$groupLocation})" : "") . "</p>
            " . ($leaderName ? "<p><strong>Group Leader:</strong> {$leaderName} — {$leaderPhone}</p>" : "") . "
            " . ($amount ? "<p><strong>Amount:</strong> ZMW " . number_format((float)$amount, 2) . "</p>" : "") . "
            " . ($reason ? "<p><strong>Reason:</strong> {$reason}</p>" : "") . "
            " . ($meetingDate ? "<p><strong>Meeting Date:</strong> {$meetingDate}</p>" : "") . "
            " . ($notes ? "<p><strong>Notes:</strong> {$notes}</p>" : "") . "
        ");

        sendResponse('success', 'Request submitted successfully', ['reference' => $reference]);
    } catch (PDOException $e) {
        error_log("Village banking request error: " . $e->getMessage());
        sendResponse('error', 'Failed to submit request. Please try again.', null, 500);
    }
}

// GET /admin/village-banking - List all requests (admin)
if ($path === '/admin/village-banking' && $method === 'GET') {
    $user = requireAdmin($pdo);
    try {
        $status = $_GET['status'] ?? 'all';
        $where = $status !== 'all' ? "WHERE status = " . $pdo->quote($status) : '';
        $stmt = $pdo->query("SELECT * FROM village_banking_requests {$where} ORDER BY created_at DESC LIMIT 200");
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse('success', 'OK', ['requests' => $requests]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch requests', null, 500);
    }
}

// PUT /admin/village-banking/:id/status - Update status (admin)
if (preg_match('#^/admin/village-banking/(\d+)/status$#', $path, $m) && $method === 'PUT') {
    $user = requireAdmin($pdo);
    $id = (int)$m[1];
    $data = getRequestData();
    $status = $data['status'] ?? 'pending';
    if (!in_array($status, ['pending','processing','completed','rejected'])) {
        sendResponse('error', 'Invalid status', null, 400);
    }
    try {
        $pdo->prepare("UPDATE village_banking_requests SET status = ? WHERE id = ?")->execute([$status, $id]);
        sendResponse('success', 'Status updated');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update', null, 500);
    }
}

// ==========================================
// CONTACT FORM
// ==========================================

// GET /admin/contacts - List all contact submissions (admin)
if ($path === '/admin/contacts' && $method === 'GET') {
    requireAuth($pdo);
    requireAdmin($pdo);
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $filter = $_GET['filter'] ?? 'all'; // all | real | spam
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];
    if ($filter === 'real') { $where[] = 'is_spam = 0'; }
    if ($filter === 'spam') { $where[] = 'is_spam = 1'; }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)';
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_submissions $whereSQL");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM contact_submissions $whereSQL ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'OK', ['contacts' => $contacts, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch contacts', null, 500);
    }
}

// DELETE /admin/contacts/:id - Delete a contact submission
if (preg_match('#^/admin/contacts/(\d+)$#', $path, $m) && $method === 'DELETE') {
    requireAuth($pdo);
    requireAdmin($pdo);
    $id = (int)$m[1];
    try {
        $pdo->prepare("DELETE FROM contact_submissions WHERE id = ?")->execute([$id]);
        sendResponse('success', 'Deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete', null, 500);
    }
}

// PUT /admin/contacts/:id/read - Mark as read
if (preg_match('#^/admin/contacts/(\d+)/read$#', $path, $m) && $method === 'PUT') {
    requireAuth($pdo);
    requireAdmin($pdo);
    $id = (int)$m[1];
    try {
        // Add is_read column if it doesn't exist (safe migration)
        try { $pdo->exec("ALTER TABLE contact_submissions ADD COLUMN is_read TINYINT(1) DEFAULT 0"); } catch(Exception $ex) {}
        $pdo->prepare("UPDATE contact_submissions SET is_read = 1 WHERE id = ?")->execute([$id]);
        sendResponse('success', 'Marked as read');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed', null, 500);
    }
}

// POST /contact - Submit contact form with spam protection
if ($path === '/contact' && $method === 'POST') {
    $data = getRequestData();
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $subject = trim($data['subject'] ?? '');
    $message = trim($data['message'] ?? '');
    $honeypot = $data['website'] ?? ''; // Honeypot field
    $timestamp = $data['timestamp'] ?? 0; // Form open timestamp

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Basic validation
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        sendResponse('error', 'All fields are required', null, 400);
    }

    if (!validateEmail($email)) {
        sendResponse('error', 'Invalid email address', null, 400);
    }

    $isSpam = false;
    $spamReasons = [];

    // 1. HONEYPOT CHECK - If honeypot field is filled, it's a bot
    if (!empty($honeypot)) {
        $isSpam = true;
        $spamReasons[] = 'Honeypot field filled';
    }

    // 2. TIME-BASED CHECK - Form must be open for at least 3 seconds
    if ($timestamp > 0) {
        $timeTaken = time() - $timestamp;
        if ($timeTaken < 3) {
            $isSpam = true;
            $spamReasons[] = "Form submitted too quickly ({$timeTaken}s)";
        }
    }

    // 3. RATE LIMITING - Check submissions from same IP in last hour
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM contact_submissions
            WHERE ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ipAddress]);
        $recentCount = $stmt->fetchColumn();

        if ($recentCount >= 3) {
            sendResponse('error', 'Too many submissions. Please try again later.', null, 429);
        }
    } catch (PDOException $e) {
        // Continue even if rate limit check fails
    }

    // 4. SPAM PATTERN DETECTION
    $spamKeywords = ['viagra', 'casino', 'lottery', 'bitcoin', 'crypto', 'pharmacy', 'pills'];
    $combinedText = strtolower($name . ' ' . $email . ' ' . $subject . ' ' . $message);

    foreach ($spamKeywords as $keyword) {
        if (strpos($combinedText, $keyword) !== false) {
            $isSpam = true;
            $spamReasons[] = "Contains spam keyword: $keyword";
            break;
        }
    }

    // Check for excessive links (more than 3 links is suspicious)
    $linkCount = preg_match_all('/(https?:\/\/|www\.)/i', $message);
    if ($linkCount > 3) {
        $isSpam = true;
        $spamReasons[] = "Excessive links detected ($linkCount)";
    }

    // Check for excessive CAPS (more than 50% uppercase is suspicious)
    $uppercaseCount = preg_match_all('/[A-Z]/', $message);
    $totalLetters = preg_match_all('/[a-zA-Z]/', $message);
    if ($totalLetters > 10 && ($uppercaseCount / $totalLetters) > 0.5) {
        $isSpam = true;
        $spamReasons[] = 'Excessive uppercase letters';
    }

    // Save submission to database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contact_submissions (name, email, subject, message, ip_address, user_agent, is_spam, spam_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $email,
            $subject,
            $message,
            $ipAddress,
            $userAgent,
            $isSpam ? 1 : 0,
            $isSpam ? implode('; ', $spamReasons) : null
        ]);

        // If not spam, create notification for staff
        if (!$isSpam) {
            createNotificationForAllStaff(
                $pdo,
                'contact_form',
                "New contact form submission from $name",
                substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
                null
            );

            // Log activity
            logActivity($pdo, null, $name, 'contact_form', "Contact form submission: $subject");
        }

        // Always return success to avoid giving information to spammers
        sendResponse('success', 'Thank you! Your message has been received.', [
            'id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        error_log("Contact form error: " . $e->getMessage());
        sendResponse('error', 'Failed to submit message. Please try again.', null, 500);
    }
}

// ==========================================
// NOTIFICATIONS
// ==========================================

// GET /notifications - Get user's notifications
if ($path === '/notifications' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user) {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === 'true';

        $typeFilter = $_GET['type'] ?? null;

        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$user['id']];

        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        if ($typeFilter) {
            $sql .= " AND type = ?";
            $params[] = $typeFilter;
        }

        $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        // Get unread count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $countStmt->execute([$user['id']]);
        $unreadCount = $countStmt->fetchColumn();

        sendResponse('success', 'Notifications retrieved', [
            'notifications' => $notifications,
            'unread_count' => (int)$unreadCount,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (PDOException $e) {
        error_log("Notification fetch error: " . $e->getMessage());
        sendResponse('error', 'Failed to fetch notifications: ' . $e->getMessage(), null, 500);
    }
}

// PUT /notifications/:id/read - Mark notification as read
if (preg_match('/^\/notifications\/(\d+)\/read$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user) {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $notificationId = $matches[1];

    try {
        // Verify notification belongs to user
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $user['id']]);
        $notification = $stmt->fetch();

        if (!$notification) {
            sendResponse('error', 'Notification not found', null, 404);
        }

        // Mark as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$notificationId]);

        sendResponse('success', 'Notification marked as read');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update notification', null, 500);
    }
}

// PUT /notifications/read-all - Mark all notifications as read
if ($path === '/notifications/read-all' && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user) {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        $affectedRows = $stmt->rowCount();

        sendResponse('success', "Marked $affectedRows notifications as read", [
            'affected_count' => $affectedRows
        ]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to update notifications', null, 500);
    }
}

// ==========================================
// LOAN REPAYMENT ROUTES
// ==========================================

// GET /loans/lookup - Look up a loan by reference + NRC last 4 digits
if ($path === '/loans/lookup' && $method === 'GET') {
    $ref = isset($_GET['ref']) ? trim($_GET['ref']) : '';
    $nid = isset($_GET['nid']) ? trim($_GET['nid']) : '';

    if (empty($ref) || empty($nid)) {
        sendResponse('error', 'Loan reference and NRC last 4 digits are required', null, 400);
    }

    if (strlen($nid) !== 4 || !ctype_digit($nid)) {
        sendResponse('error', 'NRC last 4 digits must be exactly 4 numbers', null, 400);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, loan_reference, customer_name, loan_amount, total_repayable,
                   amount_paid, outstanding_balance, monthly_installment, next_payment_date,
                   loan_status, disbursement_date, maturity_date
            FROM loan_accounts
            WHERE loan_reference = ? AND national_id_last4 = ? AND loan_status = 'active'
        ");
        $stmt->execute([$ref, $nid]);
        $loan = $stmt->fetch();

        if (!$loan) {
            sendResponse('error', 'No active loan found with that reference and NRC. Please check your details and try again.', null, 404);
        }

        // Mask customer name for privacy (show first name + last initial)
        $nameParts = explode(' ', $loan['customer_name']);
        if (count($nameParts) > 1) {
            $loan['customer_name'] = $nameParts[0] . ' ' . substr(end($nameParts), 0, 1) . '***';
        }

        // Get recent completed payments
        $payStmt = $pdo->prepare("
            SELECT payment_reference, amount, payment_method, status, paid_at, created_at
            FROM loan_payments
            WHERE loan_account_id = ? AND status = 'completed'
            ORDER BY created_at DESC LIMIT 5
        ");
        $payStmt->execute([$loan['id']]);
        $recentPayments = $payStmt->fetchAll();

        // Remove id from response
        unset($loan['id']);

        sendResponse('success', 'Loan found', [
            'loan' => $loan,
            'recent_payments' => $recentPayments
        ]);
    } catch (PDOException $e) {
        error_log("Loan lookup error: " . $e->getMessage());
        sendResponse('error', 'Failed to look up loan', null, 500);
    }
}

// POST /loans/payments - Initiate a payment
if ($path === '/loans/payments' && $method === 'POST') {
    $data = getRequestData();

    $loanRef = trim($data['loan_reference'] ?? '');
    $amount = floatval($data['amount'] ?? 0);
    $paymentMethod = trim($data['payment_method'] ?? '');
    $customerPhone = trim($data['customer_phone'] ?? '');
    $nid = trim($data['nid'] ?? '');

    // Validate inputs
    if (empty($loanRef) || $amount <= 0 || empty($paymentMethod) || empty($nid)) {
        sendResponse('error', 'Loan reference, amount, payment method, and NRC verification are required', null, 400);
    }

    try {
        // Verify loan exists and is active
        $stmt = $pdo->prepare("
            SELECT id, customer_name, outstanding_balance, loan_status
            FROM loan_accounts
            WHERE loan_reference = ? AND national_id_last4 = ? AND loan_status = 'active'
        ");
        $stmt->execute([$loanRef, $nid]);
        $loan = $stmt->fetch();

        if (!$loan) {
            sendResponse('error', 'Loan not found or not active', null, 404);
        }

        if ($amount > $loan['outstanding_balance']) {
            sendResponse('error', 'Payment amount exceeds outstanding balance of K' . number_format($loan['outstanding_balance'], 2), null, 400);
        }

        // Generate unique payment reference
        $paymentReference = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        // Get client IP
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Create payment record
        $stmt = $pdo->prepare("
            INSERT INTO loan_payments (loan_account_id, payment_reference, amount, payment_method, status, customer_phone, customer_name, ip_address, status_message)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, 'Awaiting payment confirmation')
        ");
        $stmt->execute([
            $loan['id'],
            $paymentReference,
            $amount,
            $paymentMethod,
            $customerPhone,
            $loan['customer_name'],
            $ipAddress
        ]);

        // Build instructions based on payment method
        $instructions = '';
        switch ($paymentMethod) {
            case 'mobile_money':
                $instructions = "You will receive a payment prompt on your phone. If you do not receive it, please contact us with your payment reference.";
                break;
            case 'bank_transfer':
                $instructions = "Please transfer K" . number_format($amount, 2) . " to Stalwart Zambia, Account: XXXX-XXXX-XXXX at Zanaco Bank. Use reference: $paymentReference";
                break;
            case 'office':
                $instructions = "Please visit our office at Woodgate House, Cairo Road, Lusaka with your payment reference: $paymentReference";
                break;
            default:
                $instructions = "Please complete your payment using reference: $paymentReference";
        }

        sendResponse('success', 'Payment initiated successfully', [
            'payment_reference' => $paymentReference,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'instructions' => $instructions,
            'flow_type' => 'manual'
        ]);
    } catch (PDOException $e) {
        error_log("Payment initiation error: " . $e->getMessage());
        sendResponse('error', 'Failed to process payment', null, 500);
    }
}

// GET /loans/payments/:ref - Check payment status
if (preg_match('/^\/loans\/payments\/([A-Z0-9\-]+)$/', $path, $matches) && $method === 'GET') {
    $paymentRef = $matches[1];

    try {
        $stmt = $pdo->prepare("
            SELECT lp.payment_reference, lp.amount, lp.payment_method, lp.status,
                   lp.status_message, lp.paid_at, lp.created_at,
                   la.loan_reference
            FROM loan_payments lp
            JOIN loan_accounts la ON lp.loan_account_id = la.id
            WHERE lp.payment_reference = ?
        ");
        $stmt->execute([$paymentRef]);
        $payment = $stmt->fetch();

        if (!$payment) {
            sendResponse('error', 'Payment not found', null, 404);
        }

        sendResponse('success', 'Payment status retrieved', ['payment' => $payment]);
    } catch (PDOException $e) {
        error_log("Payment status error: " . $e->getMessage());
        sendResponse('error', 'Failed to retrieve payment status', null, 500);
    }
}

// POST /loans/admin/accounts - Create a loan account (Admin only)
if ($path === '/loans/admin/accounts' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $data = getRequestData();

    $required = ['loan_reference', 'customer_name', 'customer_phone', 'national_id_last4',
                 'loan_amount', 'total_repayable', 'monthly_installment', 'disbursement_date', 'maturity_date'];

    foreach ($required as $field) {
        if (empty($data[$field])) {
            sendResponse('error', "Field '$field' is required", null, 400);
        }
    }

    try {
        $outstandingBalance = floatval($data['total_repayable']) - floatval($data['amount_paid'] ?? 0);

        $stmt = $pdo->prepare("
            INSERT INTO loan_accounts (loan_reference, customer_name, customer_phone, customer_email, national_id_last4,
                                       loan_amount, total_repayable, amount_paid, outstanding_balance, monthly_installment,
                                       next_payment_date, disbursement_date, maturity_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['loan_reference'],
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
            $data['national_id_last4'],
            $data['loan_amount'],
            $data['total_repayable'],
            $data['amount_paid'] ?? 0,
            $outstandingBalance,
            $data['monthly_installment'],
            $data['next_payment_date'] ?? null,
            $data['disbursement_date'],
            $data['maturity_date']
        ]);

        sendResponse('success', 'Loan account created', ['id' => $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        error_log("Create loan account error: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            sendResponse('error', 'A loan with that reference already exists', null, 409);
        }
        sendResponse('error', 'Failed to create loan account', null, 500);
    }
}

// GET /loans/admin/accounts - List all loan accounts (Admin only)
if ($path === '/loans/admin/accounts' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    try {
        $status = isset($_GET['status']) ? $_GET['status'] : null;

        $sql = "SELECT * FROM loan_accounts";
        $params = [];

        if ($status) {
            $sql .= " WHERE loan_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll();

        sendResponse('success', 'Loan accounts retrieved', ['accounts' => $accounts, 'total' => count($accounts)]);
    } catch (PDOException $e) {
        error_log("List loan accounts error: " . $e->getMessage());
        sendResponse('error', 'Failed to retrieve loan accounts', null, 500);
    }
}

// PUT /loans/admin/payments/:id/confirm - Manually confirm a payment (Admin only)
if (preg_match('/^\/loans\/admin\/payments\/(\d+)\/confirm$/', $path, $matches) && $method === 'PUT') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') {
        sendResponse('error', 'Unauthorized', null, 401);
    }

    $paymentId = $matches[1];

    try {
        $pdo->beginTransaction();

        // Get the payment
        $stmt = $pdo->prepare("SELECT * FROM loan_payments WHERE id = ? AND status = 'pending'");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            $pdo->rollBack();
            sendResponse('error', 'Payment not found or already processed', null, 404);
        }

        // Update payment status
        $stmt = $pdo->prepare("
            UPDATE loan_payments SET status = 'completed', paid_at = NOW(), status_message = 'Payment confirmed by admin'
            WHERE id = ?
        ");
        $stmt->execute([$paymentId]);

        // Update loan account balances
        $stmt = $pdo->prepare("
            UPDATE loan_accounts
            SET amount_paid = amount_paid + ?,
                outstanding_balance = outstanding_balance - ?
            WHERE id = ?
        ");
        $stmt->execute([$payment['amount'], $payment['amount'], $payment['loan_account_id']]);

        // Check if loan is fully paid off
        $stmt = $pdo->prepare("SELECT outstanding_balance FROM loan_accounts WHERE id = ?");
        $stmt->execute([$payment['loan_account_id']]);
        $remaining = $stmt->fetchColumn();

        if ($remaining <= 0) {
            $stmt = $pdo->prepare("UPDATE loan_accounts SET loan_status = 'paid_off', outstanding_balance = 0 WHERE id = ?");
            $stmt->execute([$payment['loan_account_id']]);
        }

        $pdo->commit();

        sendResponse('success', 'Payment confirmed successfully', [
            'payment_id' => $paymentId,
            'amount' => $payment['amount'],
            'remaining_balance' => max(0, $remaining)
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Confirm payment error: " . $e->getMessage());
        sendResponse('error', 'Failed to confirm payment', null, 500);
    }
}

// ==========================================
// HEALTH & UPTIME
// ==========================================

// GET /health — Public health check (used by external monitors like UptimeRobot)
// Returns HTTP 200 when healthy, HTTP 503 when unhealthy so monitors detect outages correctly
if ($path === '/health' && $method === 'GET') {
    $t0 = microtime(true);
    try { $pdo->query("SELECT 1"); $dbOk = true; } catch (Exception $e) { $dbOk = false; }
    $ms = round((microtime(true) - $t0) * 1000, 2);
    $status = $dbOk ? 'up' : 'down';

    // Auto-create uptime_logs table
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS uptime_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status ENUM('up','down','degraded') DEFAULT 'up',
            response_time_ms INT DEFAULT NULL,
            checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL,
            INDEX idx_checked_at (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Check the last recorded status to detect recovery
        $lastLog = $pdo->query("SELECT status FROM uptime_logs ORDER BY checked_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        // Log this check
        $pdo->prepare("INSERT INTO uptime_logs (status, response_time_ms) VALUES (?, ?)")->execute([$status, $ms]);

        // Trim to 1000 records
        $pdo->query("DELETE FROM uptime_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM uptime_logs ORDER BY checked_at DESC LIMIT 1000) AS t)");

        // RECOVERY DETECTION: last check was 'down', current is 'up' → send recovery alert
        if ($lastLog && $lastLog['status'] === 'down' && $status === 'up') {
            $recoveryEmail = getNotifyEmail($pdo, 'notify_email_uptime');
            $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'stalwartzm.com');
            $recoveryHtml = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                <div style='background:#16a34a;padding:25px;text-align:center;'>
                    <h1 style='color:white;margin:0;font-size:22px;'>&#9989; Site Recovered</h1>
                </div>
                <div style='padding:25px;background:#f0fdf4;border-left:4px solid #16a34a;'>
                    <p style='font-size:16px;color:#166534;margin-top:0;'><strong>Good news!</strong> Your Stalwart Zambia website is back online.</p>
                    <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                        <tr><td style='padding:8px 12px;color:#6b7280;background:#fff;'>Site URL</td><td style='padding:8px 12px;font-weight:bold;background:#fff;'>{$siteUrl}</td></tr>
                        <tr><td style='padding:8px 12px;color:#6b7280;'>Recovery Time</td><td style='padding:8px 12px;font-weight:bold;'>" . date('d M Y, H:i:s T') . "</td></tr>
                        <tr><td style='padding:8px 12px;color:#6b7280;background:#fff;'>Response Time</td><td style='padding:8px 12px;font-weight:bold;background:#fff;'>{$ms}ms</td></tr>
                        <tr><td style='padding:8px 12px;color:#6b7280;'>Database</td><td style='padding:8px 12px;font-weight:bold;color:#16a34a;'>Connected &#10003;</td></tr>
                    </table>
                    <p style='color:#555;margin-top:15px;font-size:13px;'>Log in to your admin dashboard to review the uptime history.</p>
                </div>
                <div style='padding:12px;background:#f9fafb;text-align:center;border-top:1px solid #e5e7eb;'>
                    <p style='color:#9ca3af;font-size:11px;margin:0;'>Stalwart Zambia Uptime Monitor &middot; Automated alert &middot; Do not reply</p>
                </div>
            </div>";
            sendEmail($recoveryEmail, 'Admin', '✅ RECOVERED: Stalwart Zambia is back online', $recoveryHtml);
        }
    } catch (Exception $e) {}

    // UptimeRobot (and all monitors) expect HTTP 200 for "up", non-200 for "down"
    $httpCode = $dbOk ? 200 : 503;
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'           => $status,
        'database'         => $dbOk ? 'ok' : 'error',
        'response_time_ms' => $ms,
        'timestamp'        => date('c'),
        'server'           => 'Stalwart Zambia API'
    ]);
    exit;
}

// GET /admin/health/metrics — Detailed server metrics (admin only)
if ($path === '/admin/health/metrics' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);

    $t0 = microtime(true);
    $diskPath  = __DIR__;
    $diskTotal = @disk_total_space($diskPath) ?: 0;
    $diskFree  = @disk_free_space($diskPath)  ?: 0;
    $diskUsed  = $diskTotal - $diskFree;

    $dbVersion  = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbName     = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $dbSize     = (int)$pdo->query("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
    $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();

    $totalChecks = 0; $upChecks = 0; $avgMs = 0;
    try {
        $totalChecks = (int)$pdo->query("SELECT COUNT(*) FROM uptime_logs WHERE checked_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $upChecks    = (int)$pdo->query("SELECT COUNT(*) FROM uptime_logs WHERE status='up' AND checked_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $avgMs       = (float)$pdo->query("SELECT COALESCE(AVG(response_time_ms),0) FROM uptime_logs WHERE checked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Exception $e) {}

    $uptimePct = $totalChecks > 0 ? round(($upChecks / $totalChecks) * 100, 2) : 100.0;

    sendResponse('success', 'Health metrics', [
        'server'   => [
            'php_version' => PHP_VERSION,
            'os'          => PHP_OS_FAMILY,
            'hostname'    => gethostname(),
            'max_upload'  => ini_get('upload_max_filesize'),
            'max_post'    => ini_get('post_max_size'),
            'extensions'  => ['curl' => function_exists('curl_init'), 'zlib' => function_exists('gzcompress'), 'openssl' => function_exists('openssl_sign')],
        ],
        'memory'   => ['used_bytes' => memory_get_usage(true), 'peak_bytes' => memory_get_peak_usage(true), 'limit' => ini_get('memory_limit')],
        'disk'     => ['total_bytes' => $diskTotal, 'free_bytes' => $diskFree, 'used_bytes' => $diskUsed, 'used_percent' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0],
        'database' => ['status' => 'ok', 'version' => $dbVersion, 'name' => $dbName, 'size_bytes' => $dbSize, 'table_count' => $tableCount],
        'uptime'   => ['percent_30d' => $uptimePct, 'total_checks' => $totalChecks, 'avg_response_ms' => round($avgMs, 1)],
        'api_response_ms' => round((microtime(true) - $t0) * 1000, 2),
    ]);
}

// GET /admin/error-logs — reads and parses the PHP error log
if ($path === '/admin/error-logs' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 401);

    $logFile = __DIR__ . '/logs/error.log';
    $entries = [];

    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) $lines = [];

        // Read last 500 lines max
        $lines = array_slice($lines, -500);

        foreach ($lines as $line) {
            // Parse: [DD-Mon-YYYY HH:MM:SS UTC] PHP Severity: message in /file.php on line N
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\] (PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards|Catchable fatal error)|Activity log error|SMTP [^\:]+|[^\:]+):\s*(.+)$/i', $line, $m)) {
                $rawType = trim($m[3] ?: $m[2]);
                $message = trim($m[4]);

                // Determine severity
                if (preg_match('/fatal|parse error/i', $rawType)) {
                    $severity = 'fatal';
                } elseif (preg_match('/warning/i', $rawType)) {
                    $severity = 'warning';
                } elseif (preg_match('/notice/i', $rawType)) {
                    $severity = 'notice';
                } elseif (preg_match('/deprecated/i', $rawType)) {
                    $severity = 'deprecated';
                } else {
                    $severity = 'info';
                }

                // Extract file + line
                $file = null;
                $lineNo = null;
                if (preg_match('/in\s+(.+?)\s+on\s+line\s+(\d+)/i', $message, $fm)) {
                    $fullPath = $fm[1];
                    $lineNo   = (int)$fm[2];
                    // Strip the server absolute path prefix for brevity
                    $file = preg_replace('#^.*(stalwart-api|stalwart)#', '.../$1', $fullPath);
                    $message = trim(preg_replace('/\s+in\s+.+\s+on\s+line\s+\d+\.?$/i', '', $message));
                }

                // Fix recommendation
                $recommendation = '';
                if ($severity === 'fatal' || $severity === 'parse error') {
                    $recommendation = 'Check syntax in the file shown above. Run php -l <file> to validate.';
                } elseif (preg_match('/undefined variable|uninitialized string offset/i', $message)) {
                    $recommendation = 'Initialize the variable before use or add isset() / null coalescing check.';
                } elseif (preg_match('/undefined index|undefined array key/i', $message)) {
                    $recommendation = "Use isset(\$array['key']) or \$array['key'] ?? null before accessing.";
                } elseif (preg_match('/Call to undefined (function|method)/i', $message)) {
                    $recommendation = 'Check the function/method name spelling and ensure it is defined or its file is included.';
                } elseif (preg_match('/Cannot redeclare/i', $message)) {
                    $recommendation = 'Wrap the function/class in if(!function_exists()) or check for duplicate includes.';
                } elseif (preg_match('/include|require/i', $message)) {
                    $recommendation = 'Verify the file path exists. Check relative vs absolute path resolution.';
                } elseif (preg_match('/Class .+ not found/i', $message)) {
                    $recommendation = 'Ensure the class file is included/required or check autoloader configuration.';
                } elseif (preg_match('/memory.*exhausted/i', $message)) {
                    $recommendation = 'Increase memory_limit in php.ini or optimise the operation to use less memory.';
                } elseif (preg_match('/maximum execution time/i', $message)) {
                    $recommendation = 'Increase max_execution_time in php.ini or optimise the slow operation.';
                } elseif (preg_match('/deprecated/i', $message) || $severity === 'deprecated') {
                    $recommendation = 'Update to the non-deprecated API shown in the PHP docs for this version.';
                } elseif (preg_match('/CN=.*did not match|certificate.*mismatch|peer certificate/i', $message)) {
                    $recommendation = 'Certificate hostname mismatch. Your SMTP host does not match the server\'s SSL certificate. Use the hosting server\'s actual hostname (e.g. mail.hostingprovider.com) instead of your domain name, or contact your host for the correct mail server address.';
                } elseif (preg_match('/wrong username or password|App Password/i', $message)) {
                    $recommendation = 'Wrong email/password. For Gmail or Google Workspace: go to myaccount.google.com → Security → App passwords, generate one, and use it as the SMTP password instead of your account password.';
                } elseif (preg_match('/502|command not recognized/i', $message)) {
                    $recommendation = 'SMTP server did not recognise the AUTH command. This is now auto-corrected (AUTH LOGIN → AUTH PLAIN fallback). Re-test via Settings → Email → Send Test Email.';
                } elseif (preg_match('/SMTP/i', $message)) {
                    $recommendation = 'Check SMTP credentials in Settings → Email. Verify host, port, and encryption.';
                } elseif ($severity === 'warning') {
                    $recommendation = 'Review the warning above and add defensive checks (isset, type casting, etc.).';
                } elseif ($severity === 'notice') {
                    $recommendation = 'Low-priority: add a null/undefined check or initialise the value.';
                }

                $entries[] = [
                    'timestamp'      => $m[1] . ' UTC',
                    'severity'       => $severity,
                    'type'           => $rawType,
                    'message'        => $message,
                    'file'           => $file,
                    'line'           => $lineNo,
                    'recommendation' => $recommendation,
                    'raw'            => $line,
                ];
            } else {
                // Non-standard line — include as-is (stack traces, etc.)
                // Skip stack trace lines that start with # or whitespace
                if (!preg_match('/^(\s+|Stack trace|#\d)/i', $line)) {
                    $entries[] = [
                        'timestamp'      => null,
                        'severity'       => 'info',
                        'type'           => 'Log',
                        'message'        => $line,
                        'file'           => null,
                        'line'           => null,
                        'recommendation' => '',
                        'raw'            => $line,
                    ];
                }
            }
        }
    }

    // Reverse so newest first
    $entries = array_reverse($entries);

    // Summary counts
    $summary = ['fatal' => 0, 'warning' => 0, 'notice' => 0, 'deprecated' => 0, 'info' => 0];
    foreach ($entries as $e) {
        if (isset($summary[$e['severity']])) $summary[$e['severity']]++;
    }

    sendResponse('success', 'Error logs retrieved', [
        'entries' => array_slice($entries, 0, 200),
        'total'   => count($entries),
        'summary' => $summary,
        'log_file_exists' => file_exists($logFile),
        'log_file_size'   => file_exists($logFile) ? filesize($logFile) : 0,
    ]);
}

// POST /admin/error-logs/clear — clear the error log (admin only)
if ($path === '/admin/error-logs/clear' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 401);

    $logFile = __DIR__ . '/logs/error.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    logActivity($pdo, $user['id'], $user['email'], 'settings_updated', 'PHP error log cleared');
    sendResponse('success', 'Error log cleared');
}

// GET /admin/uptime-logs
if ($path === '/admin/uptime-logs' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);
    $limit = min(100, (int)($_GET['limit'] ?? 50));
    try {
        $logs = $pdo->query("SELECT id, status, response_time_ms, checked_at, notes FROM uptime_logs ORDER BY checked_at DESC LIMIT $limit")->fetchAll();
        sendResponse('success', 'Uptime logs', ['logs' => $logs]);
    } catch (Exception $e) {
        sendResponse('success', 'No uptime data yet', ['logs' => []]);
    }
}

// ==========================================
// BACKUPS
// ==========================================

// GET /admin/backups
if ($path === '/admin/backups' && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);
    try {
        $pdo->query("CREATE TABLE IF NOT EXISTS backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NULL,
            size_bytes BIGINT DEFAULT 0,
            google_drive_id VARCHAR(255) NULL,
            google_drive_link VARCHAR(500) NULL,
            type ENUM('manual','scheduled') DEFAULT 'manual',
            status ENUM('success','failed') DEFAULT 'success',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $backups = $pdo->query("SELECT * FROM backups ORDER BY created_at DESC LIMIT 50")->fetchAll();
        sendResponse('success', 'Backups', ['backups' => $backups]);
    } catch (Exception $e) {
        sendResponse('success', 'No backups yet', ['backups' => []]);
    }
}

// POST /admin/send-daily-reminders — send today's call schedule + due task reminders to all affected staff
if ($path === '/admin/send-daily-reminders' && $method === 'POST') {
    $user = requireAdmin($pdo);
    $weekday = (int)date('N'); // 1=Mon … 5=Fri
    $today   = date('Y-m-d');
    $dayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
    $dayName  = $dayNames[$weekday] ?? 'Today';
    $sent = 0; $errors = [];

    try {
        // ── 1. Call schedule reminders ──────────────────────────────────────
        if ($weekday >= 1 && $weekday <= 5) {
            $schedStmt = $pdo->prepare("
                SELECT cs.role, cs.user_id, u.name, u.email
                FROM call_schedule cs JOIN users u ON u.id = cs.user_id
                WHERE cs.weekday = ?
            ");
            $schedStmt->execute([$weekday]);
            $scheduled = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

            $callerIndex = 0;
            $totalCallers = count(array_filter($scheduled, fn($r) => $r['role'] === 'caller'));

            foreach ($scheduled as $r) {
                $roleLabel = $r['role'] === 'caller' ? 'making calls' : 'follow-up on unanswered calls';
                $callerNote = '';
                if ($r['role'] === 'caller' && $totalCallers >= 2) {
                    $half = $callerIndex === 0 ? 'first half' : 'second half';
                    $callerNote = " (you are responsible for the {$half} of today's client list)";
                    $callerIndex++;
                }

                createNotification($pdo, (int)$r['user_id'], 'reminder',
                    "Reminder: You are on {$dayName} call duty",
                    "You are assigned to {$roleLabel} today{$callerNote}. Log in to the Call Report to get started.",
                    "/dashboard/call-report"
                );

                if (!empty($r['email'])) {
                    sendEmail($r['email'], $r['name'],
                        "📞 Call Duty Reminder — {$dayName}",
                        "<p>Hello {$r['name']},</p>"
                        . "<p>This is your reminder that you are scheduled for <strong>{$roleLabel}</strong> today (<strong>{$dayName}, {$today}</strong>){$callerNote}.</p>"
                        . "<p>Please log in to the Call Report section to load your client list and begin.</p>"
                    );
                    $sent++;
                }
            }
        }

        // ── 2. Task due/overdue reminders ───────────────────────────────────
        $taskStmt = $pdo->query("
            SELECT t.id, t.title, t.due_date, t.priority,
                   GROUP_CONCAT(DISTINCT ta.user_id SEPARATOR ',') as assignee_ids
            FROM tasks t
            JOIN task_assignees ta ON ta.task_id = t.id
            WHERE t.status != 'completed'
              AND t.due_date IS NOT NULL
              AND t.due_date <= CURDATE()
            GROUP BY t.id
        ");
        $dueTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dueTasks as $task) {
            $isOverdue = $task['due_date'] < $today;
            $label = $isOverdue ? "OVERDUE" : "due today";
            $ids = array_filter(explode(',', $task['assignee_ids'] ?? ''));

            foreach ($ids as $uid) {
                $uid = (int)$uid;
                createNotification($pdo, $uid, 'reminder',
                    ($isOverdue ? "⚠️ Overdue: " : "📅 Due today: ") . $task['title'],
                    "Task \"{$task['title']}\" ({$task['priority']} priority) is {$label}.",
                    "/dashboard/tasks"
                );

                $uRow = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
                $uRow->execute([$uid]);
                $uData = $uRow->fetch();
                if ($uData && !empty($uData['email'])) {
                    $subject = $isOverdue ? "⚠️ Overdue Task: {$task['title']}" : "📅 Task Due Today: {$task['title']}";
                    sendEmail($uData['email'], $uData['name'], $subject,
                        "<p>Hello {$uData['name']},</p>"
                        . "<p>This task is <strong>{$label}</strong>:</p>"
                        . "<p><strong>{$task['title']}</strong> ({$task['priority']} priority, due {$task['due_date']})</p>"
                        . "<p>Please log in and take action.</p>"
                    );
                    $sent++;
                }
            }
        }

        logActivity($pdo, $user['id'], $user['name'] ?? 'Admin', 'reminders_sent', "Daily reminders sent: {$sent} emails");
        sendResponse('success', "Reminders sent ({$sent} emails dispatched)", [
            'emails_sent' => $sent,
            'schedule_entries' => count($scheduled ?? []),
            'due_tasks' => count($dueTasks),
        ]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to send reminders: ' . $e->getMessage(), null, 500);
    }
}

// POST /admin/backup — Create DB backup + optional Google Drive upload
if ($path === '/admin/backup' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);

    $data          = getRequestData();
    $uploadToGoogle = !empty($data['upload_to_google']);

    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

    $ts         = date('Ymd_His');
    $gzFilename = "stalwart_db_{$ts}.sql.gz";
    $gzPath     = $backupDir . $gzFilename;

    try {
        $sql    = phpMysqlDump($pdo);
        $gzData = gzencode($sql, 6);
        if ($gzData === false) throw new Exception('gzencode failed — zlib extension may be disabled');
        file_put_contents($gzPath, $gzData);
        $gzSize = filesize($gzPath);

        $driveId = null; $driveLink = null; $notes = null;
        if ($uploadToGoogle) {
            $saJson   = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='google_service_account_json'")->fetchColumn();
            $folderId = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='google_drive_folder_id'")->fetchColumn();

            if ($saJson && $folderId) {
                $token = getGoogleAccessToken($saJson);
                if ($token) {
                    $result = uploadToDrive($gzPath, $gzFilename, $folderId, $token);
                    $driveId   = $result['id'] ?? null;
                    $driveLink = $result['webViewLink'] ?? null;
                    if (!$driveId) {
                        $apiErr = $result['_error'] ?? 'Unknown error';
                        $notes  = 'Drive upload failed: ' . $apiErr;
                    }
                } else {
                    $notes = 'Could not authenticate with Google — check service account JSON';
                }
            } elseif (!$saJson) {
                $notes = 'Google Drive not configured — paste service account JSON in Site Health';
            } else {
                $notes = 'Google Drive Folder ID not set — enter it in Site Health → Backup Configuration';
            }
        }

        $pdo->query("CREATE TABLE IF NOT EXISTS backups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NULL,
            size_bytes BIGINT DEFAULT 0,
            google_drive_id VARCHAR(255) NULL,
            google_drive_link VARCHAR(500) NULL,
            type ENUM('manual','scheduled') DEFAULT 'manual',
            status ENUM('success','failed') DEFAULT 'success',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->query("ALTER TABLE backups MODIFY id INT NOT NULL AUTO_INCREMENT"); } catch (Exception $e) {}

        $pdo->prepare("INSERT INTO backups (filename, file_path, size_bytes, google_drive_id, google_drive_link, type, status, notes) VALUES (?,?,?,?,?,'manual','success',?)")
            ->execute([$gzFilename, "backups/{$gzFilename}", $gzSize, $driveId, $driveLink, $notes]);

        logActivity($pdo, $user['id'], $user['email'] ?? 'Admin', 'backup_created', "DB backup created: {$gzFilename}");

        sendResponse('success', 'Backup created', ['filename' => $gzFilename, 'size_bytes' => $gzSize, 'drive_link' => $driveLink, 'drive_id' => $driveId, 'notes' => $notes]);
    } catch (Exception $e) {
        error_log("Backup error: " . $e->getMessage());
        sendResponse('error', 'Backup failed: ' . $e->getMessage(), null, 500);
    }
}

// GET /admin/backups/download/:filename — Download a backup file
if (preg_match('#^/admin/backups/download/(.+)$#', $path, $m) && $method === 'GET') {
    $user = getUserFromToken();
    if (!$user || $user['role'] !== 'admin') sendResponse('error', 'Unauthorized', null, 401);

    // Sanitize filename — only allow safe characters, no path traversal
    $filename = basename($m[1]);
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $filename)) {
        sendResponse('error', 'Invalid filename', null, 400);
    }

    $filePath = __DIR__ . '/backups/' . $filename;
    if (!file_exists($filePath)) sendResponse('error', 'File not found', null, 404);

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache');
    readfile($filePath);
    exit;
}

// ==========================================
// CALL REPORTS
// ==========================================

// Auto-create tables on first use
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS call_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL,
        staff_id INT NULL,
        staff_name VARCHAR(100) NOT NULL DEFAULT 'Staff',
        total_count INT DEFAULT 0,
        answered_count INT DEFAULT 0,
        unanswered_count INT DEFAULT 0,
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS call_report_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_phone VARCHAR(30) NULL,
        status ENUM('answered','unanswered') DEFAULT 'unanswered',
        notes TEXT NULL,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (report_id) REFERENCES call_reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* tables may already exist */ }

// Ensure columns added after initial deploy exist
try { $pdo->exec("ALTER TABLE call_reports ADD COLUMN IF NOT EXISTS staff_id INT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE call_reports ADD COLUMN IF NOT EXISTS answered_count INT DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE call_reports ADD COLUMN IF NOT EXISTS unanswered_count INT DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE call_reports ADD COLUMN IF NOT EXISTS total_count INT DEFAULT 0"); } catch (Exception $e) {}
// Fix id column missing AUTO_INCREMENT (if table was created before migrations)
try {
    $pdo->exec("ALTER TABLE call_reports MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
} catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE call_reports MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT"); } catch (Exception $e2) {}
}

// GET /call-reports - list recent reports
if ($path === '/call-reports' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $stmt = $pdo->prepare("
            SELECT id, report_date, staff_name, total_count, answered_count, unanswered_count, notes, created_at
            FROM call_reports
            ORDER BY report_date DESC, created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse('success', 'Reports retrieved', ['reports' => $reports]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch reports', null, 500);
    }
}

// POST /call-reports - save a new report
if ($path === '/call-reports' && $method === 'POST') {
    $user = requireAuth($pdo);
    $data = getRequestData();

    $reportDate   = $data['report_date'] ?? date('Y-m-d');
    $staffName    = trim($data['staff_name'] ?? 'Staff');
    $entries      = $data['entries'] ?? [];
    $notes        = $data['notes'] ?? null;

    $totalCount     = count($entries);
    $answeredCount  = count(array_filter($entries, fn($e) => ($e['status'] ?? '') === 'answered'));
    $unansweredCount = $totalCount - $answeredCount;

    try {
        // Upsert: update if this staff already has a report for this date
        $existing = $pdo->prepare("SELECT id FROM call_reports WHERE staff_id = ? AND report_date = ?");
        $existing->execute([$user['id'], $reportDate]);
        $existingRow = $existing->fetch();

        if ($existingRow) {
            $reportId = $existingRow['id'];
            $pdo->prepare("
                UPDATE call_reports SET staff_name=?, total_count=?, answered_count=?, unanswered_count=?, notes=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$staffName, $totalCount, $answeredCount, $unansweredCount, $notes, $reportId]);
            $pdo->prepare("DELETE FROM call_report_entries WHERE report_id=?")->execute([$reportId]);
        } else {
            $pdo->prepare("
                INSERT INTO call_reports (report_date, staff_id, staff_name, total_count, answered_count, unanswered_count, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$reportDate, $user['id'], $staffName, $totalCount, $answeredCount, $unansweredCount, $notes]);
            $reportId = $pdo->lastInsertId();
        }

        $entryStmt = $pdo->prepare("
            INSERT INTO call_report_entries (report_id, customer_name, customer_phone, status, notes, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($entries as $i => $entry) {
            $status = in_array($entry['status'] ?? '', ['answered', 'unanswered']) ? $entry['status'] : 'unanswered';
            $entryStmt->execute([
                $reportId,
                trim($entry['customer_name'] ?? ''),
                trim($entry['customer_phone'] ?? '') ?: null,
                $status,
                trim($entry['notes'] ?? '') ?: null,
                $i
            ]);
        }

        logActivity($pdo, $user['id'], $user['email'], 'call_report_created', "Call report saved for {$reportDate}");
        sendResponse('success', 'Report saved', ['id' => $reportId]);
    } catch (\Throwable $e) {
        error_log("Call report save error: " . $e->getMessage());
        sendResponse('error', 'Failed to save report: ' . $e->getMessage(), null, 500);
    }
}

// GET /call-reports/:id - get single report with entries
if (preg_match('#^/call-reports/(\d+)$#', $path, $m) && $method === 'GET') {
    $user = requireAuth($pdo);
    $id = (int)$m[1];
    try {
        $stmt = $pdo->prepare("SELECT * FROM call_reports WHERE id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) sendResponse('error', 'Not found', null, 404);

        $eStmt = $pdo->prepare("SELECT * FROM call_report_entries WHERE report_id = ? ORDER BY sort_order");
        $eStmt->execute([$id]);
        $entries = $eStmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Report retrieved', ['report' => $report, 'entries' => $entries]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch report', null, 500);
    }
}

// GET /call-reports/today-unanswered — follow-up staff: fetch unanswered calls from today's submitted reports
if ($path === '/call-reports/today-unanswered' && $method === 'GET') {
    $user = requireAuth($pdo);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            SELECT cre.customer_name, cre.customer_phone, cre.notes, cr.staff_name
            FROM call_report_entries cre
            JOIN call_reports cr ON cr.id = cre.report_id
            WHERE cr.report_date = ? AND cre.status = 'unanswered'
            ORDER BY cr.staff_name, cre.sort_order
        ");
        $stmt->execute([$date]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse('success', 'Unanswered calls', ['entries' => $entries, 'date' => $date, 'count' => count($entries)]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch unanswered calls', null, 500);
    }
}

// DELETE /call-reports/:id - delete a report (admin only)
if (preg_match('#^/call-reports/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $user = requireAdmin($pdo);
    $id = (int)$m[1];
    try {
        $pdo->prepare("DELETE FROM call_reports WHERE id = ?")->execute([$id]);
        sendResponse('success', 'Report deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete', null, 500);
    }
}

// ==========================================
// GOOGLE CALENDAR HELPER
// ==========================================

function getGoogleCalendarToken($serviceAccountJson) {
    $sa = json_decode($serviceAccountJson, true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) return null;
    $now     = time();
    $header  = base64url_enc(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_enc(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $toSign = $header . '.' . $payload;
    $pkey = openssl_pkey_get_private($sa['private_key']);
    if (!$pkey) return null;
    openssl_sign($toSign, $sig, $pkey, OPENSSL_ALGO_SHA256);
    $jwt = $toSign . '.' . base64url_enc($sig);
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return json_decode($resp, true)['access_token'] ?? null;
}

// GET /calendar/today?date=YYYY-MM-DD — fetch events from shared Google Calendar
if ($path === '/calendar/today' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $saJson     = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='google_service_account_json'")->fetchColumn();
        $calendarId = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='google_calendar_id'")->fetchColumn();
        if (!$saJson && !$calendarId) {
            sendResponse('error', 'Google service account JSON and Calendar ID are both missing.', ['setup_needed' => true, 'missing' => 'both'], 422);
        }
        if (!$saJson) {
            sendResponse('error', 'Google service account JSON not configured. Go to Site Health → Google Drive Backup to add it.', ['setup_needed' => true, 'missing' => 'service_account'], 422);
        }
        if (!$calendarId) {
            sendResponse('error', 'Calendar ID not saved. Go to Settings → Integrations and click Save Changes.', ['setup_needed' => true, 'missing' => 'calendar_id'], 422);
        }
        $token = getGoogleCalendarToken($saJson);
        if (!$token) {
            sendResponse('error', 'Could not authenticate with Google. Check service account JSON in Settings.', null, 500);
        }
        $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
        $timeMin = urlencode($date . 'T00:00:00Z');
        $timeMax = urlencode($date . 'T23:59:59Z');
        $calEnc  = rawurlencode($calendarId);
        $url     = "https://www.googleapis.com/calendar/v3/calendars/{$calEnc}/events?timeMin={$timeMin}&timeMax={$timeMax}&singleEvents=true&orderBy=startTime&maxResults=2500";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"], CURLOPT_TIMEOUT => 15]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            $errMsg = json_decode($resp, true)['error']['message'] ?? 'Check Calendar ID and service account permissions';
            sendResponse('error', "Calendar API error: {$errMsg}", null, 500);
        }
        $items  = json_decode($resp, true)['items'] ?? [];
        $events = [];
        foreach ($items as $item) {
            if (!empty($item['summary'])) {
                $events[] = ['name' => trim($item['summary']), 'start' => $item['start']['dateTime'] ?? $item['start']['date'] ?? null];
            }
        }
        sendResponse('success', 'Events retrieved', ['events' => $events]);
    } catch (PDOException $e) {
        sendResponse('error', 'Database error', null, 500);
    }
}

// ==========================================
// CALL SCHEDULE
// ==========================================

$pdo->exec("CREATE TABLE IF NOT EXISTS call_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    weekday TINYINT NOT NULL COMMENT '1=Mon 2=Tue 3=Wed 4=Thu 5=Fri',
    role ENUM('caller','followup') NOT NULL,
    UNIQUE KEY unique_day_user (user_id, weekday)
)");

// GET /call-schedule — admin or call manager: full weekly schedule + staff list
if ($path === '/call-schedule' && $method === 'GET') {
    $user = requireCallManager($pdo);
    try {
        $schedule = $pdo->query("SELECT cs.weekday, cs.role, cs.user_id, u.name as user_name FROM call_schedule cs JOIN users u ON u.id = cs.user_id ORDER BY cs.weekday, cs.role, u.name")->fetchAll();
        $staff    = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
        sendResponse('success', 'Schedule retrieved', ['schedule' => $schedule, 'staff' => $staff]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch schedule', null, 500);
    }
}

// POST /call-schedule — admin or call manager: save/replace weekly schedule
if ($path === '/call-schedule' && $method === 'POST') {
    $user        = requireCallManager($pdo);
    $data        = getRequestData();
    $assignments = $data['assignments'] ?? [];
    try {
        $pdo->exec("DELETE FROM call_schedule");
        $stmt = $pdo->prepare("INSERT IGNORE INTO call_schedule (user_id, weekday, role) VALUES (?, ?, ?)");
        foreach ($assignments as $a) {
            if (!isset($a['user_id'], $a['weekday'], $a['role'])) continue;
            if (!in_array($a['role'], ['caller', 'followup'])) continue;
            $stmt->execute([(int)$a['user_id'], (int)$a['weekday'], $a['role']]);
        }
        logActivity($pdo, $user['id'], $user['name'] ?? 'Admin', 'schedule_update', 'Updated weekly call schedule');

        // Notify each assigned staff member
        $dayNames = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
        $notified = []; // avoid duplicate notifications per user
        foreach ($assignments as $a) {
            if (!isset($a['user_id'], $a['weekday'], $a['role'])) continue;
            $uid     = (int)$a['user_id'];
            $dayName = $dayNames[$a['weekday']] ?? 'Day ' . $a['weekday'];
            $roleLabel = $a['role'] === 'caller' ? 'making calls' : 'follow-up on unanswered calls';
            $key = "{$uid}_{$a['weekday']}";
            if (isset($notified[$key])) continue;
            $notified[$key] = true;

            createNotification($pdo, $uid, 'schedule',
                "Call schedule: {$dayName}",
                "You are assigned to {$roleLabel} on {$dayName} this week.",
                "/dashboard/call-report"
            );

            // Email the staff member
            $uRow = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
            $uRow->execute([$uid]);
            $uData = $uRow->fetch();
            if ($uData && !empty($uData['email'])) {
                sendEmail($uData['email'], $uData['name'],
                    "Call Schedule Update — {$dayName}",
                    "<p>Hello {$uData['name']},</p>"
                    . "<p>You have been scheduled for <strong>{$roleLabel}</strong> on <strong>{$dayName}</strong> this week.</p>"
                    . "<p>Log in to the system to view your full schedule and prepare your client list.</p>"
                );
            }
        }
        sendResponse('success', 'Schedule saved');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to save schedule', null, 500);
    }
}

// GET /call-schedule/today — any auth user: who's calling and who's on follow-up today
if ($path === '/call-schedule/today' && $method === 'GET') {
    $user    = requireAuth($pdo);
    $weekday = (int)date('N'); // 1=Mon … 7=Sun
    if ($weekday > 5) {
        sendResponse('success', 'Weekend — no schedule', ['today' => null]);
    }
    try {
        $stmt = $pdo->prepare("SELECT cs.role, cs.user_id, u.name as user_name FROM call_schedule cs JOIN users u ON u.id = cs.user_id WHERE cs.weekday = ? ORDER BY cs.role, u.name");
        $stmt->execute([$weekday]);
        $rows     = $stmt->fetchAll();
        $callers  = array_values(array_filter($rows, fn($r) => $r['role'] === 'caller'));
        $followups = array_values(array_filter($rows, fn($r) => $r['role'] === 'followup'));
        $myRole   = null;
        foreach ($rows as $r) { if ($r['user_id'] == $user['id']) { $myRole = $r['role']; break; } }
        sendResponse('success', 'Today\'s schedule', ['today' => ['weekday' => $weekday, 'my_role' => $myRole, 'callers' => $callers, 'followups' => $followups]]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to get schedule', null, 500);
    }
}

// ==========================================
// NOTICES (sticky notice board)
// ==========================================

$pdo->exec("CREATE TABLE IF NOT EXISTS notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','warning','success','urgent') DEFAULT 'info',
    created_by_name VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    pinned TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// GET /notices — authenticated
if ($path === '/notices' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->query("SELECT * FROM notices WHERE is_active = 1 ORDER BY pinned DESC, created_at DESC LIMIT 20");
        sendResponse('success', 'Notices retrieved', ['notices' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch notices', null, 500);
    }
}

// POST /notices — admin only
if ($path === '/notices' && $method === 'POST') {
    $user = requireAdmin($pdo);
    $data = getRequestData();
    $title   = trim($data['title'] ?? '');
    $message = trim($data['message'] ?? '');
    $type    = in_array($data['type'] ?? '', ['info','warning','success','urgent']) ? $data['type'] : 'info';
    $pinned  = !empty($data['pinned']) ? 1 : 0;
    if (empty($title) || empty($message)) {
        sendResponse('error', 'Title and message are required', null, 400);
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO notices (title, message, type, pinned, created_by_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $message, $type, $pinned, $user['name'] ?? 'Admin']);
        $id = $pdo->lastInsertId();
        $notice = $pdo->query("SELECT * FROM notices WHERE id = $id")->fetch();
        sendResponse('success', 'Notice posted', ['notice' => $notice]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to post notice', null, 500);
    }
}

// DELETE /notices/:id — admin only
if (preg_match('#^/notices/(\d+)$#', $path, $m) && $method === 'DELETE') {
    $user = requireAdmin($pdo);
    $id = (int)$m[1];
    try {
        $pdo->prepare("DELETE FROM notices WHERE id = ?")->execute([$id]);
        sendResponse('success', 'Notice deleted');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to delete notice', null, 500);
    }
}

// ==========================================
// CHANGE PASSWORD
// ==========================================

// POST /auth/check-reminders — called silently on login; creates in-app notifications for due/overdue tasks
if ($path === '/auth/check-reminders' && $method === 'POST') {
    $user = requireAuth($pdo);
    $today = date('Y-m-d');
    try {
        // Tasks assigned to this user that are due today or overdue and not yet completed
        $stmt = $pdo->prepare("
            SELECT t.id, t.title, t.due_date, t.priority
            FROM tasks t
            JOIN task_assignees ta ON ta.task_id = t.id
            WHERE ta.user_id = ?
              AND t.status != 'completed'
              AND t.due_date IS NOT NULL
              AND t.due_date <= ?
        ");
        $stmt->execute([$user['id'], $today]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Avoid duplicate notifications: check if we already created one today for this task
        $existingStmt = $pdo->prepare("
            SELECT link FROM notifications
            WHERE user_id = ? AND type = 'reminder' AND DATE(created_at) = ?
        ");
        $existingStmt->execute([$user['id'], $today]);
        $alreadyNotified = array_column($existingStmt->fetchAll(PDO::FETCH_ASSOC), 'link');

        $created = 0;
        foreach ($tasks as $task) {
            $link = "/dashboard/tasks";
            if (in_array($link, $alreadyNotified)) continue; // rough dedupe per task
            $isOverdue  = $task['due_date'] < $today;
            $label      = $isOverdue ? "Overdue" : "Due today";
            createNotification($pdo, $user['id'], 'reminder',
                ($isOverdue ? "⚠️ Overdue: " : "📅 Due today: ") . $task['title'],
                "Task \"{$task['title']}\" ({$task['priority']} priority) is {$label}.",
                "/dashboard/tasks"
            );
            $created++;
        }
        sendResponse('success', 'Reminders checked', ['notifications_created' => $created]);
    } catch (PDOException $e) {
        sendResponse('success', 'OK', []); // fail silently
    }
}

// PUT /auth/change-password — authenticated
if ($path === '/auth/change-password' && $method === 'PUT') {
    $user = requireAuth($pdo);
    $data = getRequestData();
    $currentPassword = $data['current_password'] ?? '';
    $newPassword     = $data['new_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword)) {
        sendResponse('error', 'Current and new password are required', null, 400);
    }

    $validation = validatePassword($newPassword);
    if (!$validation['valid']) {
        sendResponse('error', $validation['message'], null, 400);
    }

    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($currentPassword, $row['password'])) {
            sendResponse('error', 'Current password is incorrect', null, 400);
        }
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user['id']]);
        logActivity($pdo, $user['id'], $user['name'] ?? $user['email'], 'password_change', 'User changed their password');
        sendResponse('success', 'Password changed successfully');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to change password', null, 500);
    }
}

// GET /auth/profile — authenticated (returns full profile incl last_login_ip)
if ($path === '/auth/profile' && $method === 'GET') {
    $user = requireAuth($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, role, is_active, last_login, last_login_ip, created_at FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
        sendResponse('success', 'Profile retrieved', ['profile' => $profile]);
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to fetch profile', null, 500);
    }
}

// ==========================================
// PUSH NOTIFICATION ROUTES
// ==========================================

// GET /push/vapid-public-key  — public, returns VAPID public key
if ($path === '/push/vapid-public-key' && $method === 'GET') {
    sendResponse('success', 'VAPID public key', ['publicKey' => VAPID_PUBLIC_KEY]);
}

// POST /push/subscribe  — authenticated, saves push subscription
if ($path === '/push/subscribe' && $method === 'POST') {
    $user = getUserFromToken();
    if (!$user) sendResponse('error', 'Unauthorized', null, 401);
    $data = json_decode(file_get_contents('php://input'), true);
    $sub  = $data['subscription'] ?? null;
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        sendResponse('error', 'Invalid subscription', null, 400);
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), updated_at = NOW()");
        $stmt->execute([$user['id'], $sub['endpoint'], $sub['keys']['p256dh'], $sub['keys']['auth']]);
        sendResponse('success', 'Subscribed');
    } catch (PDOException $e) {
        sendResponse('error', 'Failed to save subscription', null, 500);
    }
}

// ==========================================
// TEST ROUTES
// ==========================================

if ($path === '/test' && $method === 'GET') {
    sendResponse('success', 'API is working!', [
        'database' => $db,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// ==========================================
// 404
// ==========================================
sendResponse('error', 'Route not found', ['path' => $path, 'method' => $method], 404);
?>
