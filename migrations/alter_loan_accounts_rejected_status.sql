-- Add a 'rejected' status so admins can decline pending applications
ALTER TABLE loan_accounts MODIFY loan_status ENUM('pending','active','rejected','paid_off','defaulted','suspended') DEFAULT 'active';
