-- Days-overdue counter, maintained by the daily /tasks/daily-check cron. Kept as
-- its own column rather than overwriting maturity_date (a DATE column) with an
-- integer, which would corrupt its type for tasks that aren't yet overdue.
ALTER TABLE tasks ADD COLUMN days_overdue INT NULL DEFAULT NULL AFTER maturity_date;
