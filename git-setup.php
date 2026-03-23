<?php
/**
 * ONE-TIME Git Setup Script
 * Upload to api.stalwartzm.com, visit it once in browser, then DELETE it.
 */

// Basic security — change this before uploading
define('SETUP_KEY', 'stalwart_setup_2026');

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    die('Access denied. Add ?key=stalwart_setup_2026 to the URL.');
}

$dir   = __DIR__;
$token = $_GET['token'] ?? '';
if (empty($token)) die('Add &token=YOUR_GITHUB_PAT to the URL');
$repo  = "https://$token@github.com/OmriHabeenzu/stalwart-api.git";

$commands = [
    "cd $dir && git init",
    "cd $dir && git remote remove origin 2>/dev/null; git remote add origin $repo",
    "cd $dir && git fetch origin main",
    "cd $dir && git checkout -f main",
    "cd $dir && git pull origin main",
];

echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px'>";
echo "=== Git Setup ===\n\n";

foreach ($commands as $cmd) {
    echo "$ $cmd\n";
    $output = shell_exec($cmd . ' 2>&1');
    echo $output . "\n";
}

echo "\n=== Done! ===\n";
echo "IMPORTANT: Delete this file from the server now!\n";
echo "</pre>";
