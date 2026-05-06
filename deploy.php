<?php
$token = $_GET['token'] ?? '';
if ($token !== 'stalwart2026') {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$repo    = 'OmriHabeenzu/stalwart-api';
$branch  = 'main';
$files   = ['index.php', '.htaccess'];
$results = [];

foreach ($files as $file) {
    $url     = "https://raw.githubusercontent.com/{$repo}/{$branch}/{$file}";
    $content = @file_get_contents($url);
    if ($content === false) { $results[$file] = 'FAILED to fetch'; continue; }
    if (file_put_contents(__DIR__ . '/' . $file, $content) === false) { $results[$file] = 'FAILED to write'; continue; }
    $results[$file] = 'OK (' . strlen($content) . ' bytes)';
}

if (!is_dir(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0755, true);
file_put_contents(__DIR__ . '/logs/deploy.log', date('Y-m-d H:i:s') . "\n" . json_encode($results) . "\n---\n", FILE_APPEND);

echo json_encode(['success' => true, 'files' => $results, 'time' => date('Y-m-d H:i:s')]);
