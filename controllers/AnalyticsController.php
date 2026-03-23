<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../utils/response.php';

class AnalyticsController {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function getDashboard() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;
        
        $stats = [];
        
        // Page views last 30 days
        $query = "SELECT DATE(created_at) as date, COUNT(*) as views 
                  FROM analytics_logs 
                  WHERE event_type = 'page_view' 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  GROUP BY DATE(created_at)
                  ORDER BY date ASC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['page_views'] = $stmt->fetchAll();
        
        // Total visitors (unique IPs)
        $query = "SELECT COUNT(DISTINCT ip_address) as count 
                  FROM analytics_logs 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['total_visitors'] = $stmt->fetch()['count'];
        
        // Top pages
        $query = "SELECT page_url, COUNT(*) as views 
                  FROM analytics_logs 
                  WHERE event_type = 'page_view' 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  GROUP BY page_url 
                  ORDER BY views DESC 
                  LIMIT 10";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['top_pages'] = $stmt->fetchAll();
        
        // Chat sessions
        $query = "SELECT COUNT(*) as total FROM chat_sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['chat_sessions'] = $stmt->fetch()['count'];
        
        // Tasks stats
        $query = "SELECT status, COUNT(*) as count FROM tasks GROUP BY status";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $stats['tasks'] = $stmt->fetchAll();
        
        Response::success($stats);
    }
    
    public function logEvent() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['event_type'])) {
            Response::validationError(['event_type' => 'Event type is required']);
        }
        
        $query = "INSERT INTO analytics_logs (event_type, page_url, user_agent, ip_address, referrer, session_id, metadata) 
                  VALUES (:event_type, :page_url, :user_agent, :ip_address, :referrer, :session_id, :metadata)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':event_type', $data['event_type']);
        
        $pageUrl = isset($data['page_url']) ? $data['page_url'] : null;
        $stmt->bindParam(':page_url', $pageUrl);
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt->bindParam(':user_agent', $userAgent);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $stmt->bindParam(':ip_address', $ipAddress);
        
        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
        $stmt->bindParam(':referrer', $referrer);
        
        $sessionId = isset($data['session_id']) ? $data['session_id'] : null;
        $stmt->bindParam(':session_id', $sessionId);
        
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        $stmt->bindParam(':metadata', $metadata);
        
        if ($stmt->execute()) {
            Response::created(null, "Event logged");
        } else {
            Response::error("Failed to log event", 500);
        }
    }

    // Google Analytics Data API Integration
    public function getGoogleAnalytics() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        // Get GA settings from database
        $query = "SELECT setting_value FROM settings WHERE setting_key IN ('ga_property_id', 'ga_credentials_path')";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key'] ?? ''] = $row['setting_value'] ?? '';
        }

        // Check if GA is configured
        if (empty($settings['ga_property_id'])) {
            Response::error("Google Analytics not configured. Please add GA Property ID in settings.", 400);
            return;
        }

        $propertyId = $settings['ga_property_id'];
        $credentialsPath = $settings['ga_credentials_path'] ?? __DIR__ . '/../config/ga-credentials.json';

        // Check if credentials file exists
        if (!file_exists($credentialsPath)) {
            Response::error("Google Analytics credentials file not found. Please upload ga-credentials.json", 400);
            return;
        }

        try {
            // Get date range from query params (default: last 30 days)
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $analytics = $this->fetchGoogleAnalyticsData($propertyId, $credentialsPath, $startDate, $endDate);
            Response::success($analytics);
        } catch (Exception $e) {
            error_log("Google Analytics error: " . $e->getMessage());
            Response::error("Failed to fetch Google Analytics data: " . $e->getMessage(), 500);
        }
    }

    private function fetchGoogleAnalyticsData($propertyId, $credentialsPath, $startDate, $endDate) {
        // Load credentials
        $credentials = json_decode(file_get_contents($credentialsPath), true);

        // Get access token using service account
        $accessToken = $this->getGoogleAccessToken($credentials);

        if (!$accessToken) {
            throw new Exception("Failed to obtain access token");
        }

        $apiUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";

        // Prepare request for multiple metrics
        $reports = [];

        // 1. Overview metrics
        $overviewRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'newUsers']
            ]
        ];
        $reports['overview'] = $this->makeGARequest($apiUrl, $overviewRequest, $accessToken);

        // 2. Daily page views (for chart)
        $dailyRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers']
            ],
            'orderBys' => [['dimension' => ['dimensionName' => 'date']]]
        ];
        $reports['daily'] = $this->makeGARequest($apiUrl, $dailyRequest, $accessToken);

        // 3. Top pages
        $topPagesRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'pagePath']],
            'metrics' => [['name' => 'screenPageViews']],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            'limit' => 10
        ];
        $reports['topPages'] = $this->makeGARequest($apiUrl, $topPagesRequest, $accessToken);

        // 4. Traffic sources
        $sourcesRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'sessionSource']],
            'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => 10
        ];
        $reports['sources'] = $this->makeGARequest($apiUrl, $sourcesRequest, $accessToken);

        // 5. Device categories
        $devicesRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => [['name' => 'activeUsers']]
        ];
        $reports['devices'] = $this->makeGARequest($apiUrl, $devicesRequest, $accessToken);

        // 6. Countries
        $countriesRequest = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'country']],
            'metrics' => [['name' => 'activeUsers']],
            'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
            'limit' => 10
        ];
        $reports['countries'] = $this->makeGARequest($apiUrl, $countriesRequest, $accessToken);

        return $this->formatGAResponse($reports);
    }

    private function getGoogleAccessToken($credentials) {
        $tokenUrl = 'https://oauth2.googleapis.com/token';

        // Create JWT
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claim = base64_encode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => $tokenUrl,
            'exp' => $now + 3600,
            'iat' => $now
        ]));

        // Sign JWT
        $signatureInput = $header . '.' . $claim;
        openssl_sign($signatureInput, $signature, $credentials['private_key'], 'SHA256');
        $jwt = $signatureInput . '.' . base64_encode($signature);

        // Exchange JWT for access token
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    private function makeGARequest($url, $body, $accessToken) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("GA API Error: " . $response);
            return null;
        }

        return json_decode($response, true);
    }

    private function formatGAResponse($reports) {
        $formatted = [
            'overview' => [],
            'daily' => [],
            'topPages' => [],
            'sources' => [],
            'devices' => [],
            'countries' => []
        ];

        // Format overview
        if (isset($reports['overview']['rows'][0]['metricValues'])) {
            $metrics = $reports['overview']['rows'][0]['metricValues'];
            $formatted['overview'] = [
                'activeUsers' => (int)($metrics[0]['value'] ?? 0),
                'sessions' => (int)($metrics[1]['value'] ?? 0),
                'pageViews' => (int)($metrics[2]['value'] ?? 0),
                'bounceRate' => round((float)($metrics[3]['value'] ?? 0) * 100, 2),
                'avgSessionDuration' => round((float)($metrics[4]['value'] ?? 0), 2),
                'newUsers' => (int)($metrics[5]['value'] ?? 0)
            ];
        }

        // Format daily data
        if (isset($reports['daily']['rows'])) {
            foreach ($reports['daily']['rows'] as $row) {
                $date = $row['dimensionValues'][0]['value'] ?? '';
                $formatted['daily'][] = [
                    'date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
                    'pageViews' => (int)($row['metricValues'][0]['value'] ?? 0),
                    'users' => (int)($row['metricValues'][1]['value'] ?? 0)
                ];
            }
        }

        // Format top pages
        if (isset($reports['topPages']['rows'])) {
            foreach ($reports['topPages']['rows'] as $row) {
                $formatted['topPages'][] = [
                    'page' => $row['dimensionValues'][0]['value'] ?? '',
                    'views' => (int)($row['metricValues'][0]['value'] ?? 0)
                ];
            }
        }

        // Format sources
        if (isset($reports['sources']['rows'])) {
            foreach ($reports['sources']['rows'] as $row) {
                $formatted['sources'][] = [
                    'source' => $row['dimensionValues'][0]['value'] ?? '',
                    'sessions' => (int)($row['metricValues'][0]['value'] ?? 0)
                ];
            }
        }

        // Format devices
        if (isset($reports['devices']['rows'])) {
            foreach ($reports['devices']['rows'] as $row) {
                $formatted['devices'][] = [
                    'device' => $row['dimensionValues'][0]['value'] ?? '',
                    'users' => (int)($row['metricValues'][0]['value'] ?? 0)
                ];
            }
        }

        // Format countries
        if (isset($reports['countries']['rows'])) {
            foreach ($reports['countries']['rows'] as $row) {
                $formatted['countries'][] = [
                    'country' => $row['dimensionValues'][0]['value'] ?? '',
                    'users' => (int)($row['metricValues'][0]['value'] ?? 0)
                ];
            }
        }

        return $formatted;
    }

    // Save GA settings
    public function saveGoogleAnalyticsSettings() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['property_id'])) {
            Response::validationError(['property_id' => 'GA Property ID is required']);
            return;
        }

        try {
            // Save property ID
            $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('ga_property_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$data['property_id'], $data['property_id']]);

            Response::success(null, "Google Analytics settings saved");
        } catch (Exception $e) {
            Response::error("Failed to save settings", 500);
        }
    }

    // Upload GA credentials
    public function uploadGoogleAnalyticsCredentials() {
        $auth = AuthMiddleware::requireAdmin();
        if (!$auth) return;

        if (!isset($_FILES['credentials'])) {
            Response::error("No credentials file uploaded", 400);
            return;
        }

        $file = $_FILES['credentials'];
        $targetPath = __DIR__ . '/../config/ga-credentials.json';

        // Validate JSON file
        $content = file_get_contents($file['tmp_name']);
        $json = json_decode($content, true);

        if (!$json || !isset($json['client_email']) || !isset($json['private_key'])) {
            Response::error("Invalid Google service account credentials file", 400);
            return;
        }

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Save path in settings
            $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('ga_credentials_path', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$targetPath, $targetPath]);

            Response::success(null, "Google Analytics credentials uploaded successfully");
        } else {
            Response::error("Failed to save credentials file", 500);
        }
    }
}
