-- Media Management Table
CREATE TABLE IF NOT EXISTS `media` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_type` VARCHAR(100) NOT NULL,
  `file_size` INT NOT NULL,
  `category` VARCHAR(50) NOT NULL DEFAULT 'general',
  `title` VARCHAR(255) NULL,
  `alt_text` VARCHAR(255) NULL,
  `uploaded_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_category` (`category`),
  INDEX `idx_uploaded_by` (`uploaded_by`),
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add image column to testimonials table if not exists
ALTER TABLE `testimonials`
ADD COLUMN IF NOT EXISTS `image` VARCHAR(500) NULL AFTER `company`;

-- Update team_members table to use media system
ALTER TABLE `team_members`
ADD COLUMN IF NOT EXISTS `media_id` INT NULL AFTER `image`,
ADD FOREIGN KEY (`media_id`) REFERENCES `media`(`id`) ON DELETE SET NULL;
