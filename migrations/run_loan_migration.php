<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo "Failed to connect to database\n";
    exit(1);
}

try {
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/create_loan_tables.sql');

    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 60) . "...\n";
        }
    }

    echo "\nLoan tables created successfully!\n";

    // Insert a test loan account
    $stmt = $pdo->prepare("
        INSERT INTO loan_accounts (loan_reference, customer_name, customer_phone, customer_email, national_id_last4, loan_amount, total_repayable, amount_paid, outstanding_balance, monthly_installment, next_payment_date, loan_status, disbursement_date, maturity_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
    ");
    $stmt->execute([
        'STW-2026-00001',
        'John Mwanza',
        '0971234567',
        'john@example.com',
        '4567',
        5000.00,
        8000.00,
        2000.00,
        6000.00,
        1000.00,
        '2026-03-15',
        '2025-09-15',
        '2026-09-15'
    ]);

    echo "Test loan account inserted: STW-2026-00001 (NRC last 4: 4567)\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
