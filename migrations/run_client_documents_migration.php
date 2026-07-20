<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo "Failed to connect to database\n";
    exit(1);
}

try {
    $sql = file_get_contents(__DIR__ . '/create_client_documents.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 60) . "...\n";
        }
    }

    echo "\nclient_documents table created successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
