-- Start Date (when work should begin) and Maturity Date (hard final deadline),
-- alongside the existing (softer) due_date/due_time.
ALTER TABLE tasks ADD COLUMN start_date DATE NULL AFTER due_time;
ALTER TABLE tasks ADD COLUMN maturity_date DATE NULL AFTER start_date;
