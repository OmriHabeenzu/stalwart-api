-- Loan Accounts Table
-- Stores active loan records that customers can look up for repayment
CREATE TABLE IF NOT EXISTS loan_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_reference VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    customer_email VARCHAR(255) DEFAULT NULL,
    national_id_last4 VARCHAR(4) NOT NULL,
    loan_amount DECIMAL(12,2) NOT NULL,
    total_repayable DECIMAL(12,2) NOT NULL,
    amount_paid DECIMAL(12,2) DEFAULT 0.00,
    outstanding_balance DECIMAL(12,2) NOT NULL,
    monthly_installment DECIMAL(12,2) NOT NULL,
    next_payment_date DATE DEFAULT NULL,
    loan_status ENUM('active', 'paid_off', 'defaulted', 'suspended') DEFAULT 'active',
    disbursement_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reference (loan_reference),
    INDEX idx_phone (customer_phone),
    INDEX idx_status (loan_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan Payments Table
-- Records every payment attempt and result
CREATE TABLE IF NOT EXISTS loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_account_id INT NOT NULL,
    payment_reference VARCHAR(100) UNIQUE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    gateway_provider VARCHAR(50) DEFAULT NULL,
    gateway_transaction_id VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    status_message TEXT DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_account_id) REFERENCES loan_accounts(id) ON DELETE RESTRICT,
    INDEX idx_loan_account (loan_account_id),
    INDEX idx_payment_ref (payment_reference),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
