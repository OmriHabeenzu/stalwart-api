-- Allow loan applications to be recorded before approval/disbursement
ALTER TABLE loan_accounts MODIFY loan_status ENUM('pending','active','paid_off','defaulted','suspended') DEFAULT 'active';
ALTER TABLE loan_accounts MODIFY disbursement_date DATE NULL;
ALTER TABLE loan_accounts MODIFY maturity_date DATE NULL;
