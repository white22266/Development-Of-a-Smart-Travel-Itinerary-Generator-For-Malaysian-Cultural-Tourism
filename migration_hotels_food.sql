-- ============================================================
-- migration_hotels_food.sql
-- Add hotels and food_places tables to travel_itinerary_db
-- Run this in phpMyAdmin or MySQL CLI after importing the main SQL
-- ============================================================

-- ============================================================
-- Table: hotels
-- ============================================================
CREATE TABLE IF NOT EXISTS `hotels` (
  `hotel_id`        int(11)        NOT NULL AUTO_INCREMENT,
  `name`            varchar(150)   NOT NULL,
  `state`           varchar(60)    NOT NULL,
  `district`        varchar(60)    DEFAULT NULL,
  `latitude`        decimal(10,7)  DEFAULT NULL,
  `longitude`       decimal(10,7)  DEFAULT NULL,
  `price_per_night` decimal(10,2)  DEFAULT 100.00,
  `rating`          decimal(3,1)   DEFAULT 3.5,
  `is_active`       tinyint(1)     DEFAULT 1,
  `created_at`      timestamp      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`hotel_id`),
  KEY `idx_state` (`state`),
  KEY `idx_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Table: food_places
-- ============================================================
CREATE TABLE IF NOT EXISTS `food_places` (
  `food_id`       int(11)        NOT NULL AUTO_INCREMENT,
  `name`          varchar(150)   NOT NULL,
  `state`         varchar(60)    NOT NULL,
  `district`      varchar(60)    DEFAULT NULL,
  `latitude`      decimal(10,7)  DEFAULT NULL,
  `longitude`     decimal(10,7)  DEFAULT NULL,
  `cuisine_type`  varchar(80)    DEFAULT NULL,
  `avg_price`     decimal(10,2)  DEFAULT 15.00,
  `rating`        decimal(3,1)   DEFAULT 3.5,
  `opening_hour`  varchar(120)   DEFAULT NULL,
  `is_active`     tinyint(1)     DEFAULT 1,
  `created_at`    timestamp      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`food_id`),
  KEY `idx_state` (`state`),
  KEY `idx_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Sample hotel data for major Malaysian states
-- ============================================================
INSERT INTO `hotels` (`name`, `state`, `district`, `latitude`, `longitude`, `price_per_night`, `rating`) VALUES
-- Johor
('Citrus Hotel Johor Bahru',         'Johor', 'Johor Bahru', 1.4655,  103.7578, 120.00, 3.8),
('Thistle Johor Bahru',              'Johor', 'Johor Bahru', 1.4655,  103.7578, 250.00, 4.2),
('Berjaya Waterfront Hotel',         'Johor', 'Johor Bahru', 1.4600,  103.7680, 180.00, 4.0),
('Hotel Granada Johor Bahru',        'Johor', 'Johor Bahru', 1.4700,  103.7600, 95.00,  3.6),
('Tune Hotel Johor Bahru',           'Johor', 'Johor Bahru', 1.4580,  103.7550, 70.00,  3.5),

-- Kuala Lumpur
('Mandarin Oriental Kuala Lumpur',   'Kuala Lumpur', 'City Centre', 3.1570, 101.7120, 450.00, 4.7),
('Berjaya Times Square Hotel KL',    'Kuala Lumpur', 'Bukit Bintang', 3.1420, 101.7100, 200.00, 4.1),
('Hotel Istana Kuala Lumpur',        'Kuala Lumpur', 'City Centre', 3.1490, 101.7130, 280.00, 4.3),
('Tune Hotel KLIA2',                 'Kuala Lumpur', 'Sepang', 2.7456, 101.7072, 80.00,  3.6),
('Sunway Putra Hotel',               'Kuala Lumpur', 'Chow Kit', 3.1650, 101.7000, 160.00, 4.0),

-- Penang
('Eastern & Oriental Hotel',         'Penang', 'Georgetown', 5.4185, 100.3368, 380.00, 4.6),
('Hard Rock Hotel Penang',           'Penang', 'Batu Ferringhi', 5.4700, 100.2400, 320.00, 4.4),
('Bayview Hotel Georgetown',         'Penang', 'Georgetown', 5.4150, 100.3300, 150.00, 3.9),
('Cititel Penang',                   'Penang', 'Georgetown', 5.4120, 100.3290, 130.00, 3.8),
('Tune Hotel Georgetown',            'Penang', 'Georgetown', 5.4100, 100.3310, 75.00,  3.5),

-- Melaka
('Hotel Equatorial Melaka',          'Melaka', 'Melaka Tengah', 2.1940, 102.2501, 200.00, 4.1),
('Hatten Hotel Melaka',              'Melaka', 'Melaka Tengah', 2.1930, 102.2510, 180.00, 4.0),
('Bayview Hotel Melaka',             'Melaka', 'Melaka Tengah', 2.1960, 102.2490, 140.00, 3.8),
('Novotel Melaka',                   'Melaka', 'Melaka Tengah', 2.1950, 102.2505, 220.00, 4.2),
('Tune Hotel Melaka',                'Melaka', 'Melaka Tengah', 2.1920, 102.2480, 70.00,  3.5),

-- Kelantan
('Renaissance Kota Bharu Hotel',     'Kelantan', 'Kota Bharu', 6.1248, 102.2382, 220.00, 4.2),
('Hotel Perdana Kota Bharu',         'Kelantan', 'Kota Bharu', 6.1200, 102.2350, 130.00, 3.7),
('Tune Hotel Kota Bharu',            'Kelantan', 'Kota Bharu', 6.1180, 102.2370, 70.00,  3.5),

-- Kedah
('Langkawi Lagoon Resort',           'Kedah', 'Langkawi', 6.3500, 99.8500, 280.00, 4.3),
('The Danna Langkawi',               'Kedah', 'Langkawi', 6.3800, 99.7200, 450.00, 4.7),
('Tune Hotel Alor Setar',            'Kedah', 'Alor Setar', 6.1200, 100.3700, 70.00, 3.5),

-- Selangor
('Sunway Resort Hotel',              'Selangor', 'Subang Jaya', 3.0730, 101.6060, 320.00, 4.4),
('Shah Alam Convention Centre Hotel','Selangor', 'Shah Alam', 3.0850, 101.5320, 150.00, 3.9),
('Tune Hotel Shah Alam',             'Selangor', 'Shah Alam', 3.0800, 101.5300, 70.00,  3.5),

-- Pahang
('Berjaya Hills Resort',             'Pahang', 'Bentong', 3.5500, 101.8300, 250.00, 4.2),
('Hyatt Regency Kuantan Resort',     'Pahang', 'Kuantan', 3.8000, 103.3300, 280.00, 4.3),
('Swiss-Inn Kuantan',                'Pahang', 'Kuantan', 3.8100, 103.3200, 120.00, 3.7),

-- Sabah
('Shangri-La Tanjung Aru Resort',    'Sabah', 'Kota Kinabalu', 5.9630, 116.0720, 380.00, 4.6),
('Gaya Island Resort',               'Sabah', 'Kota Kinabalu', 6.0100, 116.0400, 450.00, 4.7),
('Tune Hotel Kota Kinabalu',         'Sabah', 'Kota Kinabalu', 5.9780, 116.0750, 80.00,  3.5),

-- Sarawak
('Hilton Kuching',                   'Sarawak', 'Kuching', 1.5590, 110.3440, 280.00, 4.3),
('Merdeka Palace Hotel Kuching',     'Sarawak', 'Kuching', 1.5570, 110.3420, 180.00, 4.0),
('Tune Hotel Kuching',               'Sarawak', 'Kuching', 1.5550, 110.3410, 75.00,  3.5),

-- Terengganu
('Sutra Beach Resort',               'Terengganu', 'Setiu', 5.6500, 102.8000, 200.00, 4.1),
('Primula Beach Hotel',              'Terengganu', 'Kuala Terengganu', 5.3300, 103.1400, 150.00, 3.8),

-- Perak
('Casuarina@Meru Hotel',             'Perak', 'Ipoh', 4.6000, 101.0900, 160.00, 4.0),
('M Boutique Hotel Ipoh',            'Perak', 'Ipoh', 4.5970, 101.0870, 120.00, 3.8),

-- Negeri Sembilan
('Allson Klana Resort Seremban',     'Negeri Sembilan', 'Seremban', 2.7300, 101.9400, 150.00, 3.9),
('Tune Hotel Seremban',              'Negeri Sembilan', 'Seremban', 2.7280, 101.9380, 70.00,  3.5),

-- Perlis
('Hotel Seri Malaysia Kangar',       'Perlis', 'Kangar', 6.4400, 100.1900, 100.00, 3.6);

-- ============================================================
-- Sample food place data for major Malaysian states
-- ============================================================
INSERT INTO `food_places` (`name`, `state`, `district`, `latitude`, `longitude`, `cuisine_type`, `avg_price`, `rating`, `opening_hour`) VALUES
-- Johor
('Kacang Pool Haji Restauran',       'Johor', 'Johor Bahru', 1.4600, 103.7600, 'Malay', 8.00,  4.2, '07:00 - 22:00'),
('Mee Rebus Haji Wahid',             'Johor', 'Johor Bahru', 1.4620, 103.7580, 'Malay', 7.00,  4.3, '07:00 - 15:00'),
('Restoran Hua Mui',                 'Johor', 'Johor Bahru', 1.4650, 103.7560, 'Chinese', 15.00, 4.1, '07:00 - 21:00'),
('Restoran Tepian Tebrau',           'Johor', 'Johor Bahru', 1.4680, 103.7620, 'Seafood', 25.00, 4.0, '11:00 - 22:00'),
('Warung Nasi Lemak Johor',          'Johor', 'Johor Bahru', 1.4590, 103.7540, 'Malay', 6.00,  4.0, '06:00 - 14:00'),

-- Kuala Lumpur
('Nasi Kandar Pelita',               'Kuala Lumpur', 'Ampang', 3.1580, 101.7200, 'Indian', 12.00, 4.2, '24 hours'),
('Restoran Yut Kee',                 'Kuala Lumpur', 'Chow Kit', 3.1650, 101.7010, 'Chinese', 18.00, 4.4, '07:30 - 16:00'),
('Village Park Restaurant',          'Kuala Lumpur', 'Damansara', 3.1300, 101.6600, 'Malay', 10.00, 4.5, '07:00 - 17:00'),
('Jalan Alor Hawker Street',         'Kuala Lumpur', 'Bukit Bintang', 3.1450, 101.7100, 'Chinese', 20.00, 4.3, '17:00 - 02:00'),
('Brickfields Banana Leaf',          'Kuala Lumpur', 'Brickfields', 3.1300, 101.6900, 'Indian', 15.00, 4.1, '10:00 - 22:00'),

-- Penang
('Penang Road Famous Teochew Cendol','Penang', 'Georgetown', 5.4160, 100.3340, 'Dessert', 5.00,  4.6, '10:30 - 18:00'),
('Lorong Baru Char Koay Teow',       'Penang', 'Georgetown', 5.4180, 100.3360, 'Chinese', 8.00,  4.5, '10:00 - 18:00'),
('Nasi Kandar Line Clear',           'Penang', 'Georgetown', 5.4150, 100.3350, 'Indian', 12.00, 4.4, '24 hours'),
('Gurney Drive Hawker Centre',       'Penang', 'Georgetown', 5.4380, 100.3100, 'Mixed', 15.00, 4.3, '17:00 - 23:00'),
('Sup Hameed',                       'Penang', 'Georgetown', 5.4140, 100.3320, 'Indian', 10.00, 4.2, '18:00 - 04:00'),

-- Melaka
('Jonker Street Night Market',       'Melaka', 'Melaka Tengah', 2.1960, 102.2490, 'Mixed', 10.00, 4.4, '18:00 - 23:00'),
('Restoran Peranakan',               'Melaka', 'Melaka Tengah', 2.1950, 102.2500, 'Nyonya', 20.00, 4.3, '11:00 - 22:00'),
('Nancy Kitchen Nyonya Food',        'Melaka', 'Melaka Tengah', 2.1940, 102.2510, 'Nyonya', 18.00, 4.5, '11:00 - 17:00'),
('Satay Celup Capitol',              'Melaka', 'Melaka Tengah', 2.1930, 102.2480, 'Malay', 25.00, 4.2, '17:00 - 23:00'),
('Selvam Restaurant Melaka',         'Melaka', 'Melaka Tengah', 2.1920, 102.2470, 'Indian', 12.00, 4.0, '07:00 - 22:00'),

-- Kelantan
('Nasi Kerabu Kak Yah',              'Kelantan', 'Kota Bharu', 6.1230, 102.2360, 'Malay', 6.00,  4.5, '07:00 - 14:00'),
('Soto Kak Wah',                     'Kelantan', 'Kota Bharu', 6.1210, 102.2340, 'Malay', 5.00,  4.4, '07:00 - 13:00'),
('Pasar Siti Khadijah Food Stalls',  'Kelantan', 'Kota Bharu', 6.1250, 102.2380, 'Malay', 8.00,  4.3, '06:00 - 18:00'),

-- Kedah
('Nasi Lemak Antarabangsa Alor Setar','Kedah', 'Alor Setar', 6.1220, 100.3720, 'Malay', 5.00,  4.2, '06:00 - 14:00'),
('Restoran Tomyam Langkawi',         'Kedah', 'Langkawi', 6.3500, 99.8500, 'Thai-Malay', 20.00, 4.1, '11:00 - 22:00'),

-- Selangor
('Restoran Sri Paandi',              'Selangor', 'Shah Alam', 3.0850, 101.5320, 'Indian', 12.00, 4.3, '07:00 - 22:00'),
('Nasi Lemak Antarabangsa Subang',   'Selangor', 'Subang Jaya', 3.0730, 101.6060, 'Malay', 6.00,  4.2, '06:00 - 14:00'),

-- Pahang
('Restoran Ikan Bakar Kuantan',      'Pahang', 'Kuantan', 3.8050, 103.3250, 'Seafood', 25.00, 4.3, '11:00 - 22:00'),
('Nasi Dagang Pak Su',               'Pahang', 'Kuantan', 3.8100, 103.3300, 'Malay', 7.00,  4.4, '07:00 - 14:00'),

-- Sabah
('Restoran Sri Melaka Kota Kinabalu','Sabah', 'Kota Kinabalu', 5.9780, 116.0750, 'Malay', 15.00, 4.1, '10:00 - 22:00'),
('Kedai Kopi Fatt Kee',              'Sabah', 'Kota Kinabalu', 5.9760, 116.0730, 'Chinese', 12.00, 4.2, '06:00 - 15:00'),

-- Sarawak
('Top Spot Food Court Kuching',      'Sarawak', 'Kuching', 1.5580, 110.3430, 'Seafood', 25.00, 4.4, '17:00 - 23:00'),
('Chong Choon Cafe Kuching',         'Sarawak', 'Kuching', 1.5560, 110.3410, 'Chinese', 10.00, 4.3, '07:00 - 17:00'),

-- Terengganu
('Nasi Dagang Atas Tol',             'Terengganu', 'Kuala Terengganu', 5.3300, 103.1400, 'Malay', 6.00, 4.5, '07:00 - 14:00'),
('Keropok Lekor Stall Terengganu',   'Terengganu', 'Kuala Terengganu', 5.3280, 103.1380, 'Malay', 5.00, 4.3, '09:00 - 18:00'),

-- Perak
('Restoran Foh San Ipoh',            'Perak', 'Ipoh', 4.5980, 101.0880, 'Chinese', 15.00, 4.5, '06:00 - 14:00'),
('Nasi Ganja Ipoh',                  'Perak', 'Ipoh', 4.5960, 101.0860, 'Malay', 8.00,  4.4, '07:00 - 15:00'),
('Ipoh White Coffee',                'Perak', 'Ipoh', 4.5970, 101.0870, 'Chinese', 8.00,  4.3, '07:00 - 18:00'),

-- Negeri Sembilan
('Restoran Seri Menanti',            'Negeri Sembilan', 'Seremban', 2.7300, 101.9400, 'Malay', 10.00, 4.2, '08:00 - 22:00'),
('Beef Noodle Seremban',             'Negeri Sembilan', 'Seremban', 2.7280, 101.9380, 'Chinese', 8.00,  4.3, '07:00 - 16:00'),

-- Perlis
('Nasi Kandar Perlis',               'Perlis', 'Kangar', 6.4400, 100.1900, 'Indian', 8.00, 4.1, '07:00 - 22:00');
