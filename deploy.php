<?php
$token = $_GET['token'] ?? '';
if (!hash_equals('stalwart-deploy-2026', $token)) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

$files = ['index.php', '.htaccess'];
$repo = 'OmriHabeenzu/stalwart-api';
$branch = 'main';
$results = [];

foreach ($files as $file) {
    $url = "https://raw.githubusercontent.com/{$repo}/{$branch}/{$file}";
    $content = @file_get_contents($url);
    if ($content === false) {
        $results[$file] = 'FAILED to fetch';
        continue;
    }
    if (file_put_contents(__DIR__ . '/' . $file, $content) === false) {
        $results[$file] = 'FAILED to write';
        continue;
    }
    $results[$file] = 'OK (' . strlen($content) . ' bytes)';
}

echo json_encode(['success' => true, 'files' => $results, 'time' => date('Y-m-d H:i:s')]);
