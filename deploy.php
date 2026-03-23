<?php
/**
 * GitHub Webhook Deploy Handler
 * Called by GitHub on every push to main branch.
 * Runs git pull to update the server files.
 *
 * Setup:
 *   1. Upload this file to api.stalwartzm.com/deploy.php
 *   2. In GitHub repo → Settings → Webhooks → Add webhook
 *      Payload URL: https://api.stalwartzm.com/deploy.php
 *      Content type: application/json
 *      Secret: (same value as DEPLOY_SECRET below)
 *      Events: Just the push event
 */

define('DEPLOY_SECRET', 'stw_deploy_2026_x9k2m');
define('DEPLOY_BRANCH', 'refs/heads/main');
define('REPO_DIR',      __DIR__);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Verify GitHub signature
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_SECRET);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

$data = json_decode($payload, true);
$ref  = $data['ref'] ?? '';

// Only deploy on push to main
if ($ref !== DEPLOY_BRANCH) {
    http_response_code(200);
    exit("Ignored branch: $ref");
}

// Run git pull
$output = shell_exec('cd ' . escapeshellarg(REPO_DIR) . ' && git pull origin main 2>&1');

$log = date('Y-m-d H:i:s') . " | Deploy triggered\n$output\n---\n";
file_put_contents(__DIR__ . '/logs/deploy.log', $log, FILE_APPEND);

http_response_code(200);
echo "Deployed OK\n$output";
