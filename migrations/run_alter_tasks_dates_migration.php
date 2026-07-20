<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo "Failed to connect to database\n";
    exit(1);
}

try {
    $sql = file_get_contents(__DIR__ . '/alter_tasks_dates.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 60) . "...\n";
        }
    }

    echo "\ntasks table altered successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
