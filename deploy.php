<?php
// Server-side deploy, triggered by the GitHub Action after a push to main.
// Downloads the latest index.php / .htaccess from the private
// stalwartservicesltd-design/stalwart-api repo through the GitHub API (not
// raw.githubusercontent.com, whose CDN served stale copies for minutes after
// a push), authenticated with a read-only fine-grained token kept in .env as
// GITHUB_DEPLOY_TOKEN. The trigger password can be moved to .env as
// DEPLOY_TOKEN; until then the old value keeps working.

$env = [];
foreach (@file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

header('Content-Type: application/json');
$expected = $env['DEPLOY_TOKEN'] ?? 'stalwart2026';
if (!hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$ghToken = $env['GITHUB_DEPLOY_TOKEN'] ?? '';
if ($ghToken === '') {
    http_response_code(500);
    exit(json_encode(['error' => 'GITHUB_DEPLOY_TOKEN is missing from .env']));
}

$repo    = 'stalwartservicesltd-design/stalwart-api';
$branch  = 'main';
$files   = ['index.php', '.htaccess'];
$results = [];

foreach ($files as $file) {
    $ch = curl_init("https://api.github.com/repos/{$repo}/contents/" . rawurlencode($file) . "?ref={$branch}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$ghToken}",
            'Accept: application/vnd.github.raw',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: stalwart-deploy',
        ],
    ]);
    $content = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($content === false || $code !== 200) { $results[$file] = "FAILED to fetch (HTTP {$code})"; continue; }
    // Never replace the live API with an empty or non-PHP response.
    if ($file === 'index.php' && strncmp(ltrim($content), '<?php', 5) !== 0) { $results[$file] = 'FAILED: download is not PHP — kept the current file'; continue; }

    $tmp = __DIR__ . '/' . $file . '.deploy-tmp';
    if (file_put_contents($tmp, $content) === false || !rename($tmp, __DIR__ . '/' . $file)) { @unlink($tmp); $results[$file] = 'FAILED to write'; continue; }
    $results[$file] = 'OK (' . strlen($content) . ' bytes)';
}

if (!is_dir(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0755, true);
file_put_contents(__DIR__ . '/logs/deploy.log', date('Y-m-d H:i:s') . "\n" . json_encode($results) . "\n---\n", FILE_APPEND);

$ok = !array_filter($results, fn($r) => str_starts_with($r, 'FAILED'));
if (!$ok) http_response_code(502);
echo json_encode(['success' => $ok, 'repo' => $repo, 'files' => $results, 'time' => date('Y-m-d H:i:s')]);
