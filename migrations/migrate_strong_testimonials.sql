-- ============================================================
-- Strong Testimonials → Stalwart Testimonials Migration
-- ============================================================
-- Run this on the WordPress database FIRST to export,
-- then run the INSERT block on the Stalwart database.
--
-- STEP 1: Run on the WordPress DB to inspect the data
-- (change `wordpress` to your actual WP database name)
-- ============================================================

-- Preview what will be migrated
SELECT
    p.ID                                                        AS wp_post_id,
    p.post_title                                                AS reviewer_name,
    p.post_content                                              AS review_text,
    p.post_date                                                 AS submitted_at,
    MAX(CASE WHEN pm.meta_key = 'strong_review_rating'   THEN pm.meta_value END) AS rating,
    MAX(CASE WHEN pm.meta_key = 'strong_review_company'  THEN pm.meta_value END) AS company,
    MAX(CASE WHEN pm.meta_key = 'strong_review_position' THEN pm.meta_value END) AS job_title,
    MAX(CASE WHEN pm.meta_key = 'strong_review_email'    THEN pm.meta_value END) AS reviewer_email
FROM wordpress.wp_posts p
LEFT JOIN wordpress.wp_postmeta pm ON pm.post_id = p.ID
WHERE p.post_type   = 'wpm-testimonial'
  AND p.post_status IN ('publish', 'pending')
GROUP BY p.ID, p.post_title, p.post_content, p.post_date
ORDER BY p.post_date DESC;


-- ============================================================
-- STEP 2: Run on the Stalwart DB to import
-- Change `wordpress` to your actual WP database name.
-- Change `stalwart`  to your Stalwart database name if different.
-- ============================================================

INSERT INTO stalwart.testimonials
    (name, position, company, content, rating, status, created_at)
SELECT
    p.post_title                                                            AS name,
    MAX(CASE WHEN pm.meta_key = 'strong_review_position' THEN pm.meta_value ELSE '' END) AS position,
    MAX(CASE WHEN pm.meta_key = 'strong_review_company'  THEN pm.meta_value ELSE '' END) AS company,
    p.post_content                                                          AS content,
    COALESCE(
        NULLIF(
            CAST(MAX(CASE WHEN pm.meta_key = 'strong_review_rating' THEN pm.meta_value END) AS UNSIGNED),
            0
        ),
        5
    )                                                                       AS rating,
    CASE p.post_status WHEN 'publish' THEN 'approved' ELSE 'pending' END   AS status,
    p.post_date                                                             AS created_at
FROM wordpress.wp_posts p
LEFT JOIN wordpress.wp_postmeta pm ON pm.post_id = p.ID
WHERE p.post_type   = 'wpm-testimonial'
  AND p.post_status IN ('publish', 'pending')
  AND p.post_content != ''
GROUP BY p.ID, p.post_title, p.post_content, p.post_date, p.post_status
ORDER BY p.post_date ASC;


-- ============================================================
-- STEP 3: Verify the migration
-- ============================================================

SELECT
    id,
    name,
    company,
    rating,
    status,
    LEFT(content, 80) AS preview,
    created_at
FROM stalwart.testimonials
ORDER BY created_at DESC
LIMIT 50;


-- ============================================================
-- NOTES
-- ============================================================
-- 1. The Strong Testimonials plugin stores testimonials in
--    wp_posts (post_type = 'wpm-testimonial') with extra
--    fields in wp_postmeta (strong_review_*).
--
-- 2. The target `testimonials` table schema expected:
--      id INT AUTO_INCREMENT PRIMARY KEY
--      name VARCHAR(255)
--      position VARCHAR(255)
--      company VARCHAR(255)
--      content TEXT
--      rating TINYINT (1-5)
--      status ENUM('pending','approved','rejected')
--      created_at DATETIME
--
-- 3. Profile images from Strong Testimonials are stored as
--    WordPress attachments. To migrate them you would need to:
--      a) Copy the uploads folder from WordPress to the new server
--      b) Update image URLs in the testimonials table manually
--    For now the `avatar_url` column (if it exists) is left NULL.
--
-- 4. If both databases are on different servers, export with:
--      mysqldump -u root wordpress wp_posts wp_postmeta > wp_export.sql
--    Import into the new server, then run STEP 2.
