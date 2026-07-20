-- The live task_assignees table predates the app code, which already assumes
-- status/created_at/completed_at exist in several places (task list query, task
-- creation/edit, per-assignee status toggle, staff performance stats). The
-- original CREATE TABLE IF NOT EXISTS never ran (table already existed), so this
-- was silently broken. Purely additive — existing user_name/assigned_at columns
-- are left untouched.
ALTER TABLE task_assignees ADD COLUMN status ENUM('pending','in_progress','completed') DEFAULT 'pending' AFTER user_id;
ALTER TABLE task_assignees ADD COLUMN completed_at DATETIME NULL AFTER status;
ALTER TABLE task_assignees ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER completed_at;
