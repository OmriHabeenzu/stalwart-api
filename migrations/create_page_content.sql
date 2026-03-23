-- Page Content CMS Table
-- Stores editable text/content for all public pages
-- Admins edit via the admin panel; public pages fetch & use with hardcoded fallbacks

CREATE TABLE IF NOT EXISTS page_content (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  page_key     VARCHAR(50)  NOT NULL,
  section_key  VARCHAR(100) NOT NULL,
  label        VARCHAR(150) NOT NULL,
  content      LONGTEXT,
  content_type ENUM('text','textarea') DEFAULT 'text',
  sort_order   INT DEFAULT 0,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_section (page_key, section_key)
);

-- Seed default content (matches current hardcoded values so nothing changes on first deploy)

INSERT INTO page_content (page_key, section_key, label, content, content_type, sort_order) VALUES

-- HOME PAGE
('home', 'hero_title',      'Hero Title',          'Conveniently Flexible Financing',                                                                       'text',     1),
('home', 'hero_subtitle',   'Hero Subtitle',        'Empowering your financial goals with transparent, accessible lending solutions tailored to your needs.', 'textarea', 2),
('home', 'stat1_value',     'Stat 1 – Value',       '10+',                    'text', 3),
('home', 'stat1_label',     'Stat 1 – Label',       'Years of Service',       'text', 4),
('home', 'stat2_value',     'Stat 2 – Value',       '5000+',                  'text', 5),
('home', 'stat2_label',     'Stat 2 – Label',       'Happy Clients',          'text', 6),
('home', 'stat3_value',     'Stat 3 – Value',       '98%',                    'text', 7),
('home', 'stat3_label',     'Stat 3 – Label',       'Satisfaction Rate',      'text', 8),
('home', 'stat4_value',     'Stat 4 – Value',       '24hr',                   'text', 9),
('home', 'stat4_label',     'Stat 4 – Label',       'Quick Approval',         'text', 10),
('home', 'loan1_title',     'Loan 1 – Title',       'Personal Loans',         'text', 11),
('home', 'loan1_desc',      'Loan 1 – Description', 'Flexible financing for your personal needs, quick approval and easy repayment options.', 'textarea', 12),
('home', 'loan2_title',     'Loan 2 – Title',       'Business Loans',         'text', 13),
('home', 'loan2_desc',      'Loan 2 – Description', 'Empower your business growth with affordable and scalable financing solutions.',         'textarea', 14),
('home', 'loan3_title',     'Loan 3 – Title',       'Education Loans',        'text', 15),
('home', 'loan3_desc',      'Loan 3 – Description', 'Invest in your future with education loans tailored to support your academic journey.',  'textarea', 16),

-- ABOUT PAGE
('about', 'hero_title',     'Hero Title',       'About Stalwart',                                                                                                     'text',     1),
('about', 'hero_subtitle',  'Hero Subtitle',    'Meet the team who make us today! Our team members are readily available to address your requirements.',               'textarea', 2),
('about', 'company_title',  'Section Title',    'Financial and Insurance Services',                                                                                    'text',     3),
('about', 'company_para1',  'Paragraph 1',      'Stalwart Services Ltd is a limited liability company that was incorporated in the Republic of Zambia on August 26, 2016. Its core business is in the financial and insurance services sector.', 'textarea', 4),
('about', 'company_para2',  'Paragraph 2',      'Organizations and individuals enjoy the unique and innovative upscale services the company (Stalwart Services Ltd) provides.', 'textarea', 5),

-- SERVICES PAGE
('services', 'hero_title',    'Hero Title',    'Our Services',                                                           'text',     1),
('services', 'hero_subtitle', 'Hero Subtitle', 'Comprehensive financial solutions designed to meet your unique needs',   'textarea', 2),
('services', 'loan1_title',   'Loan 1 – Title',       'Personal Loans',         'text', 3),
('services', 'loan1_desc',    'Loan 1 – Description', 'Flexible financing for your personal needs, quick approval and easy repayment options.', 'textarea', 4),
('services', 'loan2_title',   'Loan 2 – Title',       'Business Loans',         'text', 5),
('services', 'loan2_desc',    'Loan 2 – Description', 'Empower your business growth with affordable and scalable financing solutions.',         'textarea', 6),
('services', 'loan3_title',   'Loan 3 – Title',       'Education Loans',        'text', 7),
('services', 'loan3_desc',    'Loan 3 – Description', 'Invest in your future with education loans tailored to support your academic journey.',  'textarea', 8),

-- CONTACT PAGE
('contact', 'hero_title',     'Hero Title',       'Contact Us',                                                                         'text',     1),
('contact', 'hero_subtitle',  'Hero Subtitle',    'Get in touch with us. We\'re here to help and answer any questions you might have.',  'textarea', 2),
('contact', 'address_line1',  'Address Line 1',   'Second floor, Woodgate House',  'text', 3),
('contact', 'address_line2',  'Address Line 2',   'Along Cairo Rd, Lusaka, Zambia','text', 4),
('contact', 'address_po',     'PO Box',           'PO Box CA 136, Lusaka',         'text', 5),
('contact', 'phone_1',        'Phone 1',          '+260 976 054 486',              'text', 6),
('contact', 'phone_2',        'Phone 2',          '+260 761 818 101',              'text', 7),
('contact', 'phone_3',        'Phone 3',          '+260 954 169 145',              'text', 8),
('contact', 'email_1',        'Email 1',          'info@stalwartzm.com',           'text', 9),
('contact', 'email_2',        'Email 2',          'stalwartservicesltd@gmail.com', 'text', 10),
('contact', 'hours_days',     'Business Days',    'Monday to Friday',              'text', 11),
('contact', 'hours_time',     'Business Hours',   '09:00 AM - 05:00 PM',           'text', 12)

ON DUPLICATE KEY UPDATE label = VALUES(label);
-- NOTE: ON DUPLICATE KEY only updates label, not content, so existing edits are preserved on re-run
