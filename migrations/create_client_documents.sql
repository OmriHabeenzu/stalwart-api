-- Client Documents Table
-- Stores uploaded KYC-style documents (ID, proof of income, loan agreement, etc.) per loan account
CREATE TABLE IF NOT EXISTS client_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_account_id INT NOT NULL,
    document_type ENUM('passport_photo','id_document','receipt','loan_agreement','other') NOT NULL DEFAULT 'other',
    file_name VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_account_id) REFERENCES loan_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_loan_account (loan_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
