-- ============================================================
-- WordPress → Stalwart Testimonials Import
-- All records were 'publish' on WP → is_approved = 1
-- Photo records → is_featured = 1
-- No-photo records with unknown names → 'Stalwart Client'
-- ============================================================

INSERT INTO testimonials (name, company, position, testimonial, image, rating, is_approved, is_featured, created_at) VALUES

-- 2025 reviews (no photos)
('Stalwart Client', NULL, NULL, 'Available when needed and a very responsive team', NULL, 5, 1, 0, '2025-07-30 13:01:29'),
('Stalwart Client', NULL, NULL, 'Very good company. Very effective, understanding and helpful.', NULL, 5, 1, 0, '2025-07-28 12:39:36'),
('Happy Client', NULL, NULL, 'Excellent Customer service always. Keep up the Stunning service!!!!', NULL, 5, 1, 0, '2025-07-28 12:38:16'),
('Stalwart Client', NULL, NULL, 'Excellent and Exceptional entrepreneurship, on point feedback, safe and secure saving platform...', NULL, 5, 1, 0, '2025-07-25 10:56:27'),
('Stalwart Client', NULL, NULL, 'Very proffessional, breath of fresh air with the customer service!! Will definitely recommend their services!', NULL, 5, 1, 0, '2025-07-25 07:50:35'),
('Stalwart Client', NULL, NULL, 'I would just like to say thank you to the team, extremely realistic rates and payment plans and very polite team. Shout out to Ms. Mutinta who always helps find a reasonable solution that works. Ever so grateful for the team, also great communication skills. I cannot wait for you to get global, cause that is where you belong.', NULL, 5, 1, 0, '2025-07-25 07:48:18'),

-- 2024 reviews WITH photos (is_featured = 1)
('Vera', NULL, NULL, 'Very reliable and professional service. Kudos!', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Vera-300x300.jpg', 5, 1, 1, '2024-06-24 21:13:14'),
('Matilda', NULL, NULL, 'Amazing customer service, quick responses and very understanding. Always a pleasure doing business with you!', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Matilda-202x300.jpg', 5, 1, 1, '2024-06-24 21:12:35'),
('Charles M', NULL, NULL, 'Their application is great and easy to use. I had an exciting journey with the team. Always great doing business with you.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Charles-M-277x300.jpg', 5, 1, 1, '2024-06-24 21:10:09'),
('Selina', NULL, NULL, 'I had a great customer experience. Your online platform is easy to access and use.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Selina-298x300.jpg', 5, 1, 1, '2024-06-24 21:09:03'),
('Noel', NULL, NULL, 'This is the best credit facility online platform I have used. Continue with the efficiency of service.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Noel-233x300.jpg', 5, 1, 1, '2024-06-24 21:08:24'),
('Musonda', NULL, NULL, 'I love the efficiency and politeness of the Stalwart team, keep it up.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Musonda-282x300.jpg', 5, 1, 1, '2024-06-24 21:07:36'),
('Namatama', NULL, NULL, 'Very efficient with excellent communication.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Namatama-136x300.jpg', 5, 1, 1, '2024-06-24 21:05:52'),
('Wiggan', NULL, NULL, 'The customer service is excellent, I just love how the staff treat their clients.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Wiggan-253x300.jpg', 5, 1, 1, '2024-06-24 21:05:06'),

-- 2024 without photos
('Stalwart Client', NULL, NULL, 'Proper application process.', NULL, 5, 1, 0, '2024-06-24 20:59:22'),
('Stalwart Client', NULL, NULL, 'Created an account within a few minutes! This is awesome.', NULL, 5, 1, 0, '2024-06-24 20:58:22'),
('Stalwart Client', NULL, NULL, 'Excellent customer service.', NULL, 5, 1, 0, '2024-06-24 20:57:40'),
('Stalwart Client', NULL, NULL, 'Excellent service and communication.', NULL, 5, 1, 0, '2024-06-24 20:56:59'),

-- 2024 with photo
('Mercy', NULL, NULL, 'Exceptional service and professionalism.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Mercy-199x300.jpg', 5, 1, 1, '2024-06-24 20:56:08'),

-- without photo
('A Happy Client', NULL, NULL, 'Excellent service and professional staff.', NULL, 5, 1, 0, '2024-06-24 20:55:31'),
('Stalwart Client', NULL, NULL, 'Easy procedure.', NULL, 5, 1, 0, '2024-06-24 20:54:31'),

-- with photos
('Delphine', NULL, NULL, 'Stalwart has great and exceptional services.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Delphine-177x300.jpg', 5, 1, 1, '2024-06-24 20:53:52'),
('Justin M', NULL, NULL, 'Excellent customer service.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Justin-M-239x300.jpg', 5, 1, 1, '2024-06-24 20:52:57'),

-- without photo
('Stalwart Client', NULL, NULL, 'Awesome! I like the professionalism and speed at which you operate. Keep it up.', NULL, 5, 1, 0, '2024-06-24 20:51:52'),

-- with photo
('Pauline', NULL, NULL, 'Very efficient and good customer care services.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Pauline-143x300.jpg', 5, 1, 1, '2024-06-24 20:50:42'),

-- without photo
('Stalwart Client', NULL, NULL, 'The innovation is simply the best. The flexibility and transparency in your service is out of this world.', NULL, 5, 1, 0, '2024-06-24 20:50:13'),

-- with photos
('Nelson', NULL, NULL, 'Great services.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Nelson-275x300.jpg', 5, 1, 1, '2024-06-24 20:49:38'),
('Sibajene', NULL, NULL, 'Excellent service with flexible rates. Thank you so so much!', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Sibajene-225x300.jpg', 5, 1, 1, '2024-06-24 20:48:55'),

-- without photo
('Stalwart Client', NULL, NULL, 'It is the best service I have received in this field.', NULL, 5, 1, 0, '2024-06-24 20:48:09'),
('Stalwart Client', NULL, NULL, 'Excellent client services.', NULL, 5, 1, 0, '2024-06-24 20:47:33'),

-- with photos
('Groy', NULL, NULL, 'Really appreciate your excellent customer service. I have no doubt that you are destined for greatness as a company. Keep it up.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Groy-300x281.jpg', 5, 1, 1, '2024-06-24 20:46:28'),
('Timothy', NULL, NULL, 'Excellent customer service. Excellent communication skills. On point with paper work and very minimal required. I must say, simply the best! Keep it up!!!', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Timothy-239x300.jpg', 5, 1, 1, '2024-06-24 20:45:39'),
('Beenzu', NULL, NULL, 'Excellent customer service. Keep it up.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Beenzu-257x300.jpg', 5, 1, 1, '2024-06-24 20:44:40'),
('Mwendalubi', NULL, NULL, 'As a company, you are very transparent and you have excellent communication skills. Keep it up.', 'https://www.stalwartzm.com/wp-content/uploads/2024/06/Mwendalubi-300x300.jpg', 5, 1, 1, '2024-06-24 20:43:17');
