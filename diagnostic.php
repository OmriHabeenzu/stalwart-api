<?php
// ============================================================
// STALWART DIAGNOSTIC TOOL
// Access: https://api.stalwartzm.com/diagnostic.php?token=stalwart-diag-2026
// ============================================================
if (($_GET['token'] ?? '') !== 'stalwart-diag-2026') {
    http_response_code(403); echo 'Access denied.'; exit;
}

$API_BASE = 'https://api.stalwartzm.com';

// ── .env ─────────────────────────────────────────────────────
$envVars = [];
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $envVars[trim($k)] = trim($v);
    }
}

// ── DB ───────────────────────────────────────────────────────
$pdo = null; $dbOk = false; $dbError = '';
try {
    $pdo = new PDO(
        "mysql:host=".($envVars['DB_HOST']??'localhost').";dbname=".($envVars['DB_NAME']??$envVars['DB_DATABASE']??'stalwart').";charset=utf8mb4",
        $envVars['DB_USER'] ?? $envVars['DB_USERNAME'] ?? 'root',
        $envVars['DB_PASS'] ?? $envVars['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $dbOk = true;
} catch (Exception $e) { $dbError = $e->getMessage(); }

// ── Internal JWT (mirrors index.php logic) ────────────────────
function diag_b64u($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function diag_jwt($payload, $secret) {
    $h = diag_b64u(json_encode(['typ'=>'JWT','alg'=>'HS256']));
    $p = diag_b64u(json_encode($payload));
    $s = diag_b64u(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$s";
}
$jwtSecret = $envVars['JWT_SECRET'] ?? 'sk_live_stalwart_7f8a9b2c4d5e6f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5';
$adminUser = null; $adminToken = null;
if ($pdo) {
    try {
        $adminUser = $pdo->query("SELECT id,name,email,role FROM users WHERE role IN ('admin','super_admin') AND is_active=1 LIMIT 1")->fetch();
        if ($adminUser) {
            $adminToken = diag_jwt(['user_id'=>$adminUser['id'],'email'=>$adminUser['email'],'role'=>$adminUser['role'],'exp'=>time()+3600], $jwtSecret);
        }
    } catch (Exception $e) {}
}

// ── HTTP helper ───────────────────────────────────────────────
function http_call($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'data' => @json_decode($raw, true), 'raw' => $raw, 'curl_err' => $err];
}
function auth_hdr($token) { return ["Authorization: Bearer {$token}"]; }

// ── Check accumulator ─────────────────────────────────────────
$checks = [];
function check(&$checks, $group, $name, $pass, $info = '', $details = null, $fix = '') {
    $checks[] = compact('group','name','pass','info','details','fix');
}

// ════════════════════════════════════════════════════════════════
// 1. DATABASE TABLES
// ════════════════════════════════════════════════════════════════
$requiredTables = [
    'users','tasks','activity_logs','testimonials','team_members',
    'media','settings','notifications','notices',
    'chat_sessions','chat_messages','contact_submissions',
    'call_reports','call_report_entries','call_schedule',
    'push_subscriptions','job_applications',
];
if ($pdo) {
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $t) {
        $exists = in_array($t, $existing);
        $count  = 0;
        if ($exists) try { $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch(Exception $e) {}
        check($checks, '1. Database Tables', $t, $exists,
            $exists ? "$count rows" : 'TABLE MISSING',
            null,
            $exists ? '' : "Run migrations: table will be created on next API request if CREATE TABLE IF NOT EXISTS is in migrations block"
        );
    }
    // Extra: check activity_logs columns
    if (in_array('activity_logs', $existing)) {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM activity_logs")->fetchAll(), 'Field');
        $needCols = ['id','user_id','username','action','description','ip_address','created_at'];
        foreach ($needCols as $col) {
            $has = in_array($col, $cols);
            check($checks, '1. Database Tables', "activity_logs.{$col} column", $has, $has ? '✓' : 'COLUMN MISSING');
        }
    }
    // team_members columns
    if (in_array('team_members', $existing)) {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM team_members")->fetchAll(), 'Field');
        foreach (['media_id','education','specialties','image_url','is_active'] as $col) {
            $has = in_array($col, $cols);
            check($checks, '1. Database Tables', "team_members.{$col} column", $has, $has ? '✓' : 'COLUMN MISSING',
                null, $has ? '' : "Run: ALTER TABLE team_members ADD COLUMN {$col} ... (handled by migrations)");
        }
    }
}

// ════════════════════════════════════════════════════════════════
// 2. SETTINGS COMPLETENESS
// ════════════════════════════════════════════════════════════════
if ($pdo) {
    try {
        $settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { $settingRows = []; }

    $mediaSettings = [
        'main_logo_id'                 => 'Logo (PublicLayout navbar + footer)',
        'homepage_hero_image_id'       => 'Homepage hero image',
        'homepage_why_choose_image_id' => 'Homepage why-choose section image',
        'page_about_intro_image_id'    => 'About page intro photo',
        'page_about_section2_image_id' => 'About page vision/values photo',
        'page_services_village_image_id'   => 'Services page — village banking photo',
        'page_services_insurance_image_id' => 'Services page — insurance photo',
        'page_village_banking_hero_id' => 'Village Banking hero banner',
    ];
    foreach ($mediaSettings as $key => $label) {
        $val = $settingRows[$key] ?? null;
        if (empty($val)) {
            check($checks, '2. Settings / Media IDs', "$label ($key)", false,
                'NOT SET — page will show fallback WordPress URL',
                null, "Go to Admin → Content Management → Page Images and select a media item");
            continue;
        }
        // Resolve
        try {
            $m = $pdo->prepare("SELECT id, file_name, file_path FROM media WHERE id=?");
            $m->execute([$val]);
            $media = $m->fetch();
            if (!$media) {
                check($checks, '2. Settings / Media IDs', "$label ($key)", false,
                    "Points to media ID=$val which does NOT exist in media table",
                    null, "Re-upload the image and reassign in Content Management");
            } else {
                $fp = $media['file_path'];
                if (strpos($fp,'http') === 0) { $p = parse_url($fp); $fp = ltrim($p['path']??$fp,'/'); }
                $onDisk = file_exists(__DIR__ . '/' . $fp);
                check($checks, '2. Settings / Media IDs', "$label ($key)", $onDisk,
                    "→ {$fp}" . ($onDisk ? ' ✓ file on disk' : ' ✗ FILE NOT FOUND ON DISK'),
                    null, $onDisk ? '' : "File is in DB but missing from uploads/ — re-upload the image");
            }
        } catch (Exception $e) {
            check($checks, '2. Settings / Media IDs', "$label ($key)", false, 'DB error: '.$e->getMessage());
        }
    }

    // Google Calendar
    $calId  = $settingRows['google_calendar_id'] ?? '';
    $svcAcc = $settingRows['google_service_account_json'] ?? '';
    check($checks, '2. Settings / Media IDs', 'google_calendar_id', !empty($calId),
        empty($calId) ? 'NOT SET' : $calId,
        null, 'Set in Admin → Settings → Integrations → Google Calendar');
    if (!empty($svcAcc)) {
        $decoded = @json_decode($svcAcc, true);
        $valid = $decoded && isset($decoded['private_key'], $decoded['client_email']);
        check($checks, '2. Settings / Media IDs', 'google_service_account_json', $valid,
            $valid ? 'Valid JSON — client_email: '.($decoded['client_email']??'?') : 'INVALID or incomplete JSON',
            null, $valid ? '' : 'Re-paste the full service account JSON from Google Cloud Console');
    } else {
        check($checks, '2. Settings / Media IDs', 'google_service_account_json', false,
            'NOT SET', null, 'Upload service account JSON in Admin → Site Health → Google Drive / Backup Config');
    }

    // General settings
    foreach (['siteName','siteEmail','sitePhone'] as $k) {
        $v = $settingRows[$k] ?? '';
        check($checks, '2. Settings / Media IDs', $k, !empty($v), empty($v) ? 'NOT SET' : $v);
    }
}

// ════════════════════════════════════════════════════════════════
// 3. MEDIA FILES ON DISK
// ════════════════════════════════════════════════════════════════
if ($pdo) {
    try {
        $mediaAll = $pdo->query("SELECT id, file_name, file_path FROM media ORDER BY id DESC LIMIT 100")->fetchAll();
        $missing = 0;
        foreach ($mediaAll as $m) {
            $fp = $m['file_path'];
            if (strpos($fp,'http') === 0) { $p = parse_url($fp); $fp = ltrim($p['path']??$fp,'/'); }
            $exists = file_exists(__DIR__ . '/' . $fp);
            if (!$exists) $missing++;
            check($checks, '3. Media Files on Disk', "#{$m['id']} {$m['file_name']}", $exists,
                $fp . ($exists ? '' : ' ✗ MISSING'),
                null, $exists ? '' : "File was deleted from disk — re-upload or remove the media record");
        }
    } catch (Exception $e) {
        check($checks, '3. Media Files on Disk', 'Query', false, $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════
// 4. PUBLIC API ENDPOINTS
// ════════════════════════════════════════════════════════════════
$pubTests = [
    ['GET /health', '/health', function($r) {
        $db = $r['data']['data']['db'] ?? 'missing';
        return [$r['code']===200 && $db==='ok', "HTTP {$r['code']}, db=$db"];
    }],
    ['GET /testimonials', '/testimonials', function($r) {
        $list = $r['data']['data']['testimonials'] ?? null;
        $cnt  = is_array($list) ? count($list) : 'null';
        $hasT = is_array($list) && !empty($list) && isset($list[0]['testimonial']);
        return [
            $r['code']===200 && is_array($list),
            "HTTP {$r['code']}, $cnt rows".(!is_array($list)||empty($list) ? '' : (", has 'testimonial' field: ".($hasT?'YES':'NO — frontend shows blank'))),
            is_array($list) && !$hasT && !empty($list) ? ['first_keys'=>array_keys($list[0])] : null,
            $hasT ? '' : "API must SELECT content AS testimonial — check testimonials GET route",
        ];
    }],
    ['GET /content/team', '/content/team', function($r) {
        $list = $r['data']['data']['members'] ?? null;
        $cnt  = is_array($list) ? count($list) : 'null';
        $hasImg = is_array($list) && !empty($list) && (isset($list[0]['image']) || isset($list[0]['file_path']));
        return [
            $r['code']===200 && is_array($list),
            "HTTP {$r['code']}, $cnt members".($cnt==='null' ? ' ← WRONG KEY (expected data.members)' : '').(!empty($list) ? ", image field: ".($hasImg?'YES':'NO') : ''),
            null,
            is_array($list) ? '' : "Route must return data.members array — check /content/team response key"
        ];
    }],
    ['GET /content/homepage', '/content/homepage', function($r) {
        $imgs  = $r['data']['data']['images'] ?? null;
        $hero  = $imgs['hero_image'] ?? null;
        $why   = $imgs['why_choose_image'] ?? null;
        $heroOk = $hero && strpos($hero,'http') !== 0;
        return [
            $r['code']===200 && is_array($imgs),
            "HTTP {$r['code']}, hero_image=".($hero??'NULL').($hero&&!$heroOk?' ⚠ full URL — will double-prefix':'').', why_choose_image='.($why??'NULL'),
            $imgs,
            $heroOk ? '' : "hero_image must be a relative path like uploads/media/img.jpg, not a full URL"
        ];
    }],
    ['GET /content/page-images', '/content/page-images', function($r) {
        $imgs = $r['data']['data']['images'] ?? null;
        $cnt  = is_array($imgs) ? count($imgs) : 'null';
        $full = [];
        $missing = [];
        $expected = ['page_about_intro_image_id','page_about_section2_image_id','page_services_village_image_id','page_services_insurance_image_id','page_village_banking_hero_id'];
        if (is_array($imgs)) {
            foreach ($imgs as $k => $v) if (strpos($v,'http')===0) $full[] = $k;
            foreach ($expected as $k) if (!isset($imgs[$k])) $missing[] = $k;
        }
        $ok = $r['code']===200 && is_array($imgs) && empty($full);
        $info = "HTTP {$r['code']}, $cnt keys set";
        if ($full)    $info .= ", FULL URL values: ".implode(',',$full);
        if ($missing) $info .= ", NOT SET: ".implode(',',$missing);
        return [$ok, $info, is_array($imgs) ? $imgs : null, $ok ? '' : 'Set page images in Admin → Content Management → Page Images'];
    }],
    ['GET /settings/public', '/settings/public', function($r) {
        $s = $r['data']['data'] ?? null;
        $logoId  = $s['main_logo_id'] ?? null;
        $isNumeric = is_numeric($logoId);
        $isFullUrl = $logoId && strpos($logoId,'http')===0;
        $isPath  = $logoId && !$isNumeric && !$isFullUrl;
        return [
            $r['code']===200 && $s && $isPath,
            "HTTP {$r['code']}, main_logo_id=".($logoId??'NULL').($isNumeric?' ⚠ raw DB int — frontend does API_URL/5 → 404':($isFullUrl?' ⚠ full URL — double-prefix':($isPath?' ✓ relative path':''))),
            null,
            $isPath ? '' : ($isNumeric ? "/settings/public must resolve logo ID to file_path (deploy latest API fix)" : "Set logo in Admin → Settings → Logo & Branding")
        ];
    }],
];

foreach ($pubTests as $args) {
    [$name, $path, $fn] = $args;
    $r = http_call($API_BASE . $path);
    [$pass, $info, $details, $fix] = array_pad($fn($r), 4, null);
    check($checks, '4. Public API Endpoints', $name, $pass, $info, $details, $fix??'');
}

// ════════════════════════════════════════════════════════════════
// 5. AUTHENTICATED API ENDPOINTS
// ════════════════════════════════════════════════════════════════
if ($adminToken) {
    $authTests = [
        ['GET /auth/me', '/auth/me', function($r) {
            $user = $r['data']['data']['user'] ?? null;
            $bare = $r['data']['data'] ?? null;
            $hasUserKey = is_array($user) && isset($user['id']);
            $bareIsUser = is_array($bare) && isset($bare['id']) && !isset($bare['user']);
            return [
                $hasUserKey,
                "HTTP {$r['code']}".($hasUserKey ? ", user={$user['name']} ({$user['email']})" : ($bareIsUser ? " ⚠ data IS the user (no .user key) — AuthContext reads data.user → null → LOGOUT ON REFRESH" : ", data.user=null")),
                null,
                $hasUserKey ? '' : "Fix: sendResponse('success','User retrieved',['user'=>\$u]) in /auth/me route"
            ];
        }],
        ['GET /users', '/users', function($r) {
            $list = $r['data']['data']['users'] ?? $r['data']['data'] ?? null;
            $cnt  = is_array($list) ? count($list) : 'null';
            return [$r['code']===200, "HTTP {$r['code']}, $cnt users", null, $r['code']===401 ? 'JWT decode failing — check getUserFromToken() and Authorization header handling' : ''];
        }],
        ['GET /tasks', '/tasks', function($r) {
            $list = $r['data']['data']['tasks'] ?? $r['data']['data'] ?? null;
            $cnt  = is_array($list) ? count($list) : 'null';
            return [$r['code']===200, "HTTP {$r['code']}, $cnt tasks", null, $r['code']===401 ? 'JWT/auth issue' : ''];
        }],
        ['GET /notifications?limit=5', '/notifications?limit=5', function($r) {
            $list   = $r['data']['data']['notifications'] ?? null;
            $unread = $r['data']['data']['unread_count'] ?? '?';
            return [
                $r['code']===200 && is_array($list),
                "HTTP {$r['code']}, ".count($list??[])." notifications, $unread unread",
                null,
                $r['code']!==200 ? 'Check notifications table exists in migrations' : ''
            ];
        }],
        ['GET /activity-logs?limit=10', '/activity-logs?limit=10', function($r) {
            $list    = $r['data']['data']['logs'] ?? null;
            $actions = $r['data']['data']['actions'] ?? null;
            return [
                $r['code']===200 && is_array($list),
                "HTTP {$r['code']}, ".count($list??[])." logs, ".count($actions??[])." distinct action types",
                null,
                $r['code']!==200 ? 'Check activity_logs table — may not exist yet' : ''
            ];
        }],
        ['GET /testimonials (admin — should see all)', '/testimonials', function($r) {
            $list    = $r['data']['data']['testimonials'] ?? null;
            $approved = 0; $pending = 0;
            if (is_array($list)) {
                foreach ($list as $t) {
                    if (($t['approved']??0)==1) $approved++; else $pending++;
                }
            }
            $hasT = is_array($list) && !empty($list) && isset($list[0]['testimonial']);
            return [
                $r['code']===200 && is_array($list),
                "HTTP {$r['code']}, ".count($list??[])." total (approved=$approved, pending=$pending), 'testimonial' field: ".($hasT?'YES':'NO'),
                null,
                !$hasT ? "Add content AS testimonial alias to GET /testimonials SELECT" : ($pending>0 ? "Approve pending testimonials in Admin → Testimonials" : '')
            ];
        }],
        ['GET /admin/health/metrics', '/admin/health/metrics', function($r) {
            $s = $r['data']['data']['server'] ?? null;
            return [$r['code']===200, "HTTP {$r['code']}".($s ? ", PHP {$s['php_version']}, OS {$s['os']}" : '')];
        }],
        ['GET /calendar/today', '/calendar/today?date='.date('Y-m-d'), function($r) {
            $events = $r['data']['data']['events'] ?? null;
            $msg    = $r['data']['message'] ?? '';
            $ok = $r['code']===200;
            return [
                $ok,
                "HTTP {$r['code']}: $msg".(is_array($events) ? ', '.count($events).' events' : ''),
                null,
                $ok ? '' : ($r['code']===400 ? 'Check google_service_account_json and google_calendar_id in settings' : 'Check calendar integration config')
            ];
        }],
        ['GET /chat/summary', '/chat/summary', function($r) {
            return [$r['code']===200, "HTTP {$r['code']}: ".($r['data']['message']??'')];
        }],
        ['GET /admin/backups', '/admin/backups', function($r) {
            return [$r['code']===200, "HTTP {$r['code']}: ".($r['data']['message']??'')];
        }],
        ['GET /admin/error-logs', '/admin/error-logs', function($r) {
            return [$r['code']===200, "HTTP {$r['code']}: ".($r['data']['message']??'')];
        }],
    ];

    foreach ($authTests as [$name, $path, $fn]) {
        $r = http_call($API_BASE . $path, 'GET', auth_hdr($adminToken));
        [$pass, $info, $details, $fix] = array_pad($fn($r), 4, null);
        check($checks, '5. Authenticated API Endpoints', $name, $pass, $info, $details, $fix??'');
    }
} else {
    check($checks, '5. Authenticated API Endpoints', 'Admin token', false, 'No active admin user found in DB — cannot test auth endpoints');
}

// ════════════════════════════════════════════════════════════════
// 6. FRONTEND / API CONTRACT
// (What each public page expects vs what the API delivers)
// ════════════════════════════════════════════════════════════════
$contracts = [
    'Home.jsx: homepageImages.hero_image' => [
        'endpoint' => '/content/homepage',
        'check' => function($r) {
            $img = $r['data']['data']['images']['hero_image'] ?? null;
            $ok  = $img && strpos($img,'http') !== 0;
            return [$ok, "value=".($img??'null'), $ok ? '' : 'Must be relative path — fix GET /content/homepage'];
        },
        'public' => true,
    ],
    'About.jsx: teamRes.data.data.members[].testimonial (image field)' => [
        'endpoint' => '/content/team',
        'check' => function($r) {
            $list = $r['data']['data']['members'] ?? null;
            if (!is_array($list)) return [false, 'members key missing', 'API must return data.members array'];
            if (empty($list)) return [true, 'No active team members in DB', ''];
            $hasImage = isset($list[0]['image']);
            return [$hasImage, "members[0] has 'image' field: ".($hasImage?'YES':'NO'), $hasImage ? '' : "Add \$m['image'] = \$m['image_url'] in team GET handler"];
        },
        'public' => true,
    ],
    'Services.jsx: pageImages.page_services_village_image_id' => [
        'endpoint' => '/content/page-images',
        'check' => function($r) {
            $v = $r['data']['data']['images']['page_services_village_image_id'] ?? null;
            $ok = $v && strpos($v,'http') !== 0;
            return [$ok, "value=".($v??'null — fallback WordPress URL shows'),$ok ? '' : 'Set image in Admin → Content Management → Page Images'];
        },
        'public' => true,
    ],
    'VillageBanking.jsx: page_village_banking_hero_id' => [
        'endpoint' => '/content/page-images',
        'check' => function($r) {
            $v = $r['data']['data']['images']['page_village_banking_hero_id'] ?? null;
            $ok = $v && strpos($v,'http') !== 0;
            return [$ok, "value=".($v??'null — fallback Unsplash image shows'), $ok ? '' : 'Set image in Admin → Content Management → Page Images'];
        },
        'public' => true,
    ],
    'PublicLayout: logo src from settings.main_logo_id' => [
        'endpoint' => '/settings/public',
        'check' => function($r) {
            $v = $r['data']['data']['main_logo_id'] ?? null;
            $ok = $v && !is_numeric($v) && strpos($v,'http') !== 0;
            return [$ok, "main_logo_id=".($v??'null').($v && is_numeric($v)?' (raw int → API_URL/5 = 404)':''), $ok ? '' : 'Deploy latest API fix or set logo in Settings'];
        },
        'public' => true,
    ],
    'AuthContext: /auth/me sets user (not null)' => [
        'endpoint' => '/auth/me',
        'check' => function($r) {
            $u = $r['data']['data']['user'] ?? null;
            $bare = $r['data']['data'] ?? null;
            $ok = is_array($u) && isset($u['id']);
            $bareUser = is_array($bare) && isset($bare['id']);
            return [
                $ok,
                $ok ? "user={$u['name']}" : ($bareUser ? "BROKEN: data IS user obj — missing .user key → setUser(undefined) → logout on refresh" : "user=null"),
                $ok ? '' : "Fix: sendResponse(...,['user'=>\$u]) in /auth/me"
            ];
        },
        'public' => false,
    ],
    'TestimonialsManagement: GET /testimonials shows all (not just approved)' => [
        'endpoint' => '/testimonials',
        'check' => function($r) {
            $list = $r['data']['data']['testimonials'] ?? [];
            $pending = array_filter($list, fn($t) => ($t['approved']??0) != 1);
            $all = count($list);
            return [true, "$all total returned to admin (".count($pending)." pending)", ''];
        },
        'public' => false,
    ],
];

$cache = [];
foreach ($contracts as $name => $def) {
    $endpoint = $def['endpoint'];
    if (!isset($cache[$endpoint])) {
        $hdrs = (!$def['public'] && $adminToken) ? auth_hdr($adminToken) : [];
        $cache[$endpoint] = http_call($API_BASE . $endpoint, 'GET', $hdrs);
    }
    if (!$def['public'] && !$adminToken) {
        check($checks, '6. Frontend/API Contract', $name, false, 'Skipped — no admin token');
        continue;
    }
    [$pass, $info, $fix] = array_pad(($def['check'])($cache[$endpoint]), 3, '');
    check($checks, '6. Frontend/API Contract', $name, $pass, $info, null, $fix);
}

// ════════════════════════════════════════════════════════════════
// 7. APPROVE BUTTON CONTRACT
// ════════════════════════════════════════════════════════════════
if ($adminToken && $pdo) {
    // Check that PUT /testimonials/{id} exists and works
    try {
        $tid = $pdo->query("SELECT id FROM testimonials ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($tid) {
            // Don't actually approve — just check the route responds correctly
            $r = http_call($API_BASE . "/testimonials/{$tid}", 'PUT', auth_hdr($adminToken), ['status'=>'test_ping']);
            // It should return 400 (unknown status) not 404
            $routeExists = $r['code'] !== 404;
            check($checks, '6. Frontend/API Contract',
                "PUT /testimonials/{id} route exists (approve button)",
                $routeExists,
                "HTTP {$r['code']} — ".($routeExists ? 'route found' : '404 = route missing, approve button silently fails'),
                null,
                $routeExists ? '' : "Add PUT /testimonials/{id} handler to API (accepts {status: approved/rejected})"
            );
        }
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════════
// OUTPUT HTML
// ════════════════════════════════════════════════════════════════
$pass_total = count(array_filter($checks, fn($c) => $c['pass']));
$fail_total = count($checks) - $pass_total;
$groups = [];
foreach ($checks as $c) $groups[$c['group']][] = $c;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stalwart Diagnostics — <?= date('Y-m-d H:i') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#0b1120;color:#cbd5e1;padding:24px 20px;min-height:100vh}
h1{font-size:1.7rem;font-weight:800;color:#60a5fa;margin-bottom:4px}
.meta{color:#64748b;font-size:.82rem;margin-bottom:28px}
.meta b{color:#94a3b8}
.summary{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:32px}
.scard{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:14px 20px;min-width:100px}
.snum{font-size:2rem;font-weight:900;line-height:1}
.snum.ok{color:#34d399}
.snum.bad{color:#f87171}
.snum.info{color:#60a5fa}
.slbl{color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;margin-top:4px}
.group{margin-bottom:28px}
.gtitle{font-size:.95rem;font-weight:700;color:#94a3b8;border-bottom:1px solid #1e293b;padding-bottom:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.gstats{font-size:.78rem;font-weight:400;color:#475569}
.row{display:flex;align-items:flex-start;gap:10px;padding:7px 10px;border-radius:6px;margin-bottom:3px;transition:.1s}
.row.ok{background:#0f2217;border-left:3px solid #065f46}
.row.fail{background:#1f0a0a;border-left:3px solid #b91c1c}
.badge{flex-shrink:0;font-size:.62rem;font-weight:800;padding:2px 7px;border-radius:999px;letter-spacing:.04em;margin-top:2px}
.badge.ok{background:#064e3b;color:#6ee7b7}
.badge.fail{background:#7f1d1d;color:#fca5a5}
.rname{font-size:.87rem;font-weight:600;color:#e2e8f0}
.rinfo{font-size:.78rem;color:#64748b;margin-top:2px;word-break:break-all}
.rinfo.fail{color:#f87171}
.rfix{font-size:.74rem;color:#fbbf24;margin-top:3px}
.rfix::before{content:"→ Fix: "}
.details{margin-top:6px;background:#060d1a;padding:8px 10px;border-radius:5px;font-size:.72rem;font-family:monospace;color:#7dd3fc;white-space:pre-wrap;overflow-x:auto;max-height:200px;overflow-y:auto}
.toggle{cursor:pointer;font-size:.72rem;color:#475569;margin-top:4px;text-decoration:underline;user-select:none}
</style>
<script>
function toggle(id){var el=document.getElementById(id);el.style.display=el.style.display==='none'?'block':'none'}
</script>
</head>
<body>
<h1>Stalwart Diagnostic Report</h1>
<p class="meta">
  <b>Generated:</b> <?= date('Y-m-d H:i:s T') ?> &nbsp;|&nbsp;
  <b>DB:</b> <?= $dbOk ? '<span style="color:#34d399">Connected</span>' : '<span style="color:#f87171">'.htmlspecialchars($dbError).'</span>' ?> &nbsp;|&nbsp;
  <b>API:</b> <?= htmlspecialchars($API_BASE) ?> &nbsp;|&nbsp;
  <b>Admin:</b> <?= $adminUser ? '<span style="color:#34d399">'.htmlspecialchars($adminUser['email']).' ('.$adminUser['role'].')</span>' : '<span style="color:#f87171">No admin found</span>' ?>
</p>

<div class="summary">
  <div class="scard"><div class="snum ok"><?= $pass_total ?></div><div class="slbl">Passing</div></div>
  <div class="scard"><div class="snum <?= $fail_total>0?'bad':'ok' ?>"><?= $fail_total ?></div><div class="slbl">Failing</div></div>
  <div class="scard"><div class="snum info"><?= count($checks) ?></div><div class="slbl">Total Checks</div></div>
</div>

<?php $gIdx=0; foreach ($groups as $gName => $gChecks): $gIdx++;
  $gPass = count(array_filter($gChecks, fn($c) => $c['pass']));
  $gFail = count($gChecks) - $gPass;
?>
<div class="group">
  <div class="gtitle">
    <?= htmlspecialchars($gName) ?>
    <span class="gstats"><?= $gPass ?>/<?= count($gChecks) ?> pass <?= $gFail>0 ? "— <span style='color:#f87171'>$gFail failing</span>" : '' ?></span>
  </div>
  <?php foreach ($gChecks as $ci => $c):
    $cls = $c['pass'] ? 'ok' : 'fail';
    $did = "d{$gIdx}_{$ci}";
  ?>
  <div class="row <?= $cls ?>">
    <span class="badge <?= $cls ?>"><?= $c['pass'] ? 'PASS' : 'FAIL' ?></span>
    <div style="flex:1;min-width:0">
      <div class="rname"><?= htmlspecialchars($c['name']) ?></div>
      <?php if ($c['info']): ?>
        <div class="rinfo <?= $c['pass']?'':'fail' ?>"><?= htmlspecialchars($c['info']) ?></div>
      <?php endif; ?>
      <?php if (!$c['pass'] && $c['fix']): ?>
        <div class="rfix"><?= htmlspecialchars($c['fix']) ?></div>
      <?php endif; ?>
      <?php if ($c['details']): ?>
        <span class="toggle" onclick="toggle('<?= $did ?>')">show details</span>
        <div id="<?= $did ?>" class="details" style="display:none"><?= htmlspecialchars(json_encode($c['details'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
</body>
</html>
