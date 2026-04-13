-- ============================================================
-- COMPLETE DATABASE UPDATE SCRIPT
-- For Smart Travel Itinerary Generator for Malaysian Cultural Tourism
-- 
-- INSTRUCTIONS:
-- Run this script in phpMyAdmin AFTER importing your original 
-- travel_itinerary_db.sql file.
-- 
-- This script contains ALL missing tables, new columns, and 
-- reference data needed for the completed system.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================
-- PART 1: NEW TABLES (hotels & food_places)
-- ============================================================

CREATE TABLE IF NOT EXISTS `hotels` (
  `hotel_id`        int(11)        NOT NULL AUTO_INCREMENT,
  `name`            varchar(150)   NOT NULL,
  `state`           varchar(60)    NOT NULL,
  `district`        varchar(80)    DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS `food_places` (
  `food_id`       int(11)        NOT NULL AUTO_INCREMENT,
  `name`          varchar(150)   NOT NULL,
  `state`         varchar(60)    NOT NULL,
  `district`      varchar(80)    DEFAULT NULL,
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
-- PART 2: ALTER EXISTING TABLES
-- ============================================================

-- Add district to cultural_places
ALTER TABLE `cultural_places`
  ADD COLUMN IF NOT EXISTS `district` varchar(80) DEFAULT NULL AFTER `state`;

-- Add preferred_districts to traveller_preferences
ALTER TABLE `traveller_preferences`
  ADD COLUMN IF NOT EXISTS `preferred_districts` varchar(500) DEFAULT NULL AFTER `preferred_states`;

-- ============================================================
-- PART 3: MALAYSIA DISTRICTS REFERENCE TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS `malaysia_districts` (
  `district_id`  int(11)      NOT NULL AUTO_INCREMENT,
  `state`        varchar(60)  NOT NULL,
  `district`     varchar(80)  NOT NULL,
  PRIMARY KEY (`district_id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Clear existing data if re-running
TRUNCATE TABLE `malaysia_districts`;

INSERT INTO `malaysia_districts` (`state`, `district`) VALUES
-- Johor (10 districts)
('Johor', 'Johor Bahru'), ('Johor', 'Kluang'), ('Johor', 'Kota Tinggi'), ('Johor', 'Mersing'), ('Johor', 'Muar'), ('Johor', 'Batu Pahat'), ('Johor', 'Pontian'), ('Johor', 'Segamat'), ('Johor', 'Kulai'), ('Johor', 'Tangkak'),
-- Kedah (12 districts)
('Kedah', 'Kota Setar'), ('Kedah', 'Kubang Pasu'), ('Kedah', 'Padang Terap'), ('Kedah', 'Sik'), ('Kedah', 'Baling'), ('Kedah', 'Kulim'), ('Kedah', 'Bandar Baharu'), ('Kedah', 'Kuala Muda'), ('Kedah', 'Yan'), ('Kedah', 'Langkawi'), ('Kedah', 'Pokok Sena'), ('Kedah', 'Pendang'),
-- Kelantan (10 districts)
('Kelantan', 'Kota Bharu'), ('Kelantan', 'Bachok'), ('Kelantan', 'Pasir Mas'), ('Kelantan', 'Tumpat'), ('Kelantan', 'Pasir Puteh'), ('Kelantan', 'Machang'), ('Kelantan', 'Tanah Merah'), ('Kelantan', 'Kuala Krai'), ('Kelantan', 'Gua Musang'), ('Kelantan', 'Jeli'),
-- Melaka (3 districts)
('Melaka', 'Melaka Tengah'), ('Melaka', 'Alor Gajah'), ('Melaka', 'Jasin'),
-- Negeri Sembilan (7 districts)
('Negeri Sembilan', 'Seremban'), ('Negeri Sembilan', 'Port Dickson'), ('Negeri Sembilan', 'Rembau'), ('Negeri Sembilan', 'Tampin'), ('Negeri Sembilan', 'Jempol'), ('Negeri Sembilan', 'Jelebu'), ('Negeri Sembilan', 'Kuala Pilah'),
-- Pahang (11 districts)
('Pahang', 'Kuantan'), ('Pahang', 'Temerloh'), ('Pahang', 'Bentong'), ('Pahang', 'Cameron Highlands'), ('Pahang', 'Raub'), ('Pahang', 'Jerantut'), ('Pahang', 'Lipis'), ('Pahang', 'Maran'), ('Pahang', 'Bera'), ('Pahang', 'Rompin'), ('Pahang', 'Pekan'),
-- Penang (5 districts)
('Penang', 'Timur Laut'), ('Penang', 'Barat Daya'), ('Penang', 'Seberang Perai Utara'), ('Penang', 'Seberang Perai Tengah'), ('Penang', 'Seberang Perai Selatan'),
-- Perak (10 districts)
('Perak', 'Ipoh'), ('Perak', 'Kinta'), ('Perak', 'Larut, Matang & Selama'), ('Perak', 'Manjung'), ('Perak', 'Kerian'), ('Perak', 'Hilir Perak'), ('Perak', 'Hulu Perak'), ('Perak', 'Batang Padang'), ('Perak', 'Perak Tengah'), ('Perak', 'Kampar'),
-- Perlis (3 districts)
('Perlis', 'Kangar'), ('Perlis', 'Arau'), ('Perlis', 'Padang Besar'),
-- Sabah (25 districts)
('Sabah', 'Kota Kinabalu'), ('Sabah', 'Sandakan'), ('Sabah', 'Tawau'), ('Sabah', 'Lahad Datu'), ('Sabah', 'Keningau'), ('Sabah', 'Semporna'), ('Sabah', 'Kunak'), ('Sabah', 'Papar'), ('Sabah', 'Beaufort'), ('Sabah', 'Kota Belud'), ('Sabah', 'Ranau'), ('Sabah', 'Kudat'), ('Sabah', 'Kinabatangan'), ('Sabah', 'Tuaran'), ('Sabah', 'Penampang'), ('Sabah', 'Putatan'), ('Sabah', 'Sipitang'), ('Sabah', 'Tambunan'), ('Sabah', 'Nabawan'), ('Sabah', 'Tongod'), ('Sabah', 'Beluran'), ('Sabah', 'Kota Marudu'), ('Sabah', 'Pitas'), ('Sabah', 'Tenom'), ('Sabah', 'Kuala Penyu'),
-- Sarawak (12 divisions)
('Sarawak', 'Kuching'), ('Sarawak', 'Miri'), ('Sarawak', 'Sibu'), ('Sarawak', 'Bintulu'), ('Sarawak', 'Sri Aman'), ('Sarawak', 'Sarikei'), ('Sarawak', 'Kapit'), ('Sarawak', 'Limbang'), ('Sarawak', 'Mukah'), ('Sarawak', 'Betong'), ('Sarawak', 'Serian'), ('Sarawak', 'Kota Samarahan'),
-- Selangor (9 districts)
('Selangor', 'Petaling Jaya'), ('Selangor', 'Shah Alam'), ('Selangor', 'Klang'), ('Selangor', 'Subang Jaya'), ('Selangor', 'Gombak'), ('Selangor', 'Hulu Langat'), ('Selangor', 'Hulu Selangor'), ('Selangor', 'Kuala Langat'), ('Selangor', 'Sabak Bernam'),
-- Terengganu (7 districts)
('Terengganu', 'Kuala Terengganu'), ('Terengganu', 'Kemaman'), ('Terengganu', 'Dungun'), ('Terengganu', 'Besut'), ('Terengganu', 'Setiu'), ('Terengganu', 'Hulu Terengganu'), ('Terengganu', 'Marang'),
-- Kuala Lumpur (11 areas)
('Kuala Lumpur', 'City Centre (KLCC)'), ('Kuala Lumpur', 'Chow Kit'), ('Kuala Lumpur', 'Brickfields'), ('Kuala Lumpur', 'Bangsar'), ('Kuala Lumpur', 'Cheras'), ('Kuala Lumpur', 'Kepong'), ('Kuala Lumpur', 'Setapak'), ('Kuala Lumpur', 'Wangsa Maju'), ('Kuala Lumpur', 'Titiwangsa'), ('Kuala Lumpur', 'Bukit Jalil'), ('Kuala Lumpur', 'Segambut'),
-- Putrajaya
('Putrajaya', 'Putrajaya'),
-- Labuan
('Labuan', 'Victoria'), ('Labuan', 'Labuan Town');

-- ============================================================
-- PART 4: SEED DATA FOR HOTELS
-- ============================================================

INSERT INTO `hotels` (`name`, `state`, `district`, `latitude`, `longitude`, `price_per_night`, `rating`) VALUES
('Citrus Hotel Johor Bahru',         'Johor', 'Johor Bahru', 1.4655,  103.7578, 120.00, 3.8),
('Thistle Johor Bahru',              'Johor', 'Johor Bahru', 1.4655,  103.7578, 250.00, 4.2),
('Berjaya Waterfront Hotel',         'Johor', 'Johor Bahru', 1.4600,  103.7680, 180.00, 4.0),
('Mandarin Oriental Kuala Lumpur',   'Kuala Lumpur', 'City Centre (KLCC)', 3.1570, 101.7120, 450.00, 4.7),
('Berjaya Times Square Hotel KL',    'Kuala Lumpur', 'City Centre (KLCC)', 3.1420, 101.7100, 200.00, 4.1),
('Eastern & Oriental Hotel',         'Penang', 'Timur Laut', 5.4185, 100.3368, 380.00, 4.6),
('Hard Rock Hotel Penang',           'Penang', 'Timur Laut', 5.4700, 100.2400, 320.00, 4.4),
('Hotel Equatorial Melaka',          'Melaka', 'Melaka Tengah', 2.1940, 102.2501, 200.00, 4.1),
('Hatten Hotel Melaka',              'Melaka', 'Melaka Tengah', 2.1930, 102.2510, 180.00, 4.0),
('Renaissance Kota Bharu Hotel',     'Kelantan', 'Kota Bharu', 6.1248, 102.2382, 220.00, 4.2),
('Langkawi Lagoon Resort',           'Kedah', 'Langkawi', 6.3500, 99.8500, 280.00, 4.3),
('Sunway Resort Hotel',              'Selangor', 'Subang Jaya', 3.0730, 101.6060, 320.00, 4.4),
('Shah Alam Convention Centre Hotel','Selangor', 'Shah Alam', 3.0850, 101.5320, 150.00, 3.9),
('Hyatt Regency Kuantan Resort',     'Pahang', 'Kuantan', 3.8000, 103.3300, 280.00, 4.3),
('Shangri-La Tanjung Aru Resort',    'Sabah', 'Kota Kinabalu', 5.9630, 116.0720, 380.00, 4.6),
('Hilton Kuching',                   'Sarawak', 'Kuching', 1.5590, 110.3440, 280.00, 4.3),
('Sutra Beach Resort',               'Terengganu', 'Setiu', 5.6500, 102.8000, 200.00, 4.1),
('Casuarina@Meru Hotel',             'Perak', 'Ipoh', 4.6000, 101.0900, 160.00, 4.0),
('Allson Klana Resort Seremban',     'Negeri Sembilan', 'Seremban', 2.7300, 101.9400, 150.00, 3.9),
('Hotel Seri Malaysia Kangar',       'Perlis', 'Kangar', 6.4400, 100.1900, 100.00, 3.6);

-- ============================================================
-- PART 5: SEED DATA FOR FOOD PLACES
-- ============================================================

INSERT INTO `food_places` (`name`, `state`, `district`, `latitude`, `longitude`, `cuisine_type`, `avg_price`, `rating`, `opening_hour`) VALUES
('Kacang Pool Haji Restauran',       'Johor', 'Johor Bahru', 1.4600, 103.7600, 'Malay', 8.00,  4.2, '07:00 - 22:00'),
('Mee Rebus Haji Wahid',             'Johor', 'Johor Bahru', 1.4620, 103.7580, 'Malay', 7.00,  4.3, '07:00 - 15:00'),
('Nasi Kandar Pelita',               'Kuala Lumpur', 'City Centre (KLCC)', 3.1580, 101.7200, 'Indian', 12.00, 4.2, '24 hours'),
('Restoran Yut Kee',                 'Kuala Lumpur', 'Chow Kit', 3.1650, 101.7010, 'Chinese', 18.00, 4.4, '07:30 - 16:00'),
('Penang Road Famous Teochew Cendol','Penang', 'Timur Laut', 5.4160, 100.3340, 'Dessert', 5.00,  4.6, '10:30 - 18:00'),
('Lorong Baru Char Koay Teow',       'Penang', 'Timur Laut', 5.4180, 100.3360, 'Chinese', 8.00,  4.5, '10:00 - 18:00'),
('Jonker Street Night Market',       'Melaka', 'Melaka Tengah', 2.1960, 102.2490, 'Mixed', 10.00, 4.4, '18:00 - 23:00'),
('Nasi Kerabu Kak Yah',              'Kelantan', 'Kota Bharu', 6.1230, 102.2360, 'Malay', 6.00,  4.5, '07:00 - 14:00'),
('Nasi Lemak Antarabangsa Alor Setar','Kedah', 'Kota Setar', 6.1220, 100.3720, 'Malay', 5.00,  4.2, '06:00 - 14:00'),
('Restoran Sri Paandi',              'Selangor', 'Shah Alam', 3.0850, 101.5320, 'Indian', 12.00, 4.3, '07:00 - 22:00'),
('Restoran Ikan Bakar Kuantan',      'Pahang', 'Kuantan', 3.8050, 103.3250, 'Seafood', 25.00, 4.3, '11:00 - 22:00'),
('Restoran Sri Melaka Kota Kinabalu','Sabah', 'Kota Kinabalu', 5.9780, 116.0750, 'Malay', 15.00, 4.1, '10:00 - 22:00'),
('Top Spot Food Court Kuching',      'Sarawak', 'Kuching', 1.5580, 110.3430, 'Seafood', 25.00, 4.4, '17:00 - 23:00'),
('Nasi Dagang Atas Tol',             'Terengganu', 'Kuala Terengganu', 5.3300, 103.1400, 'Malay', 6.00, 4.5, '07:00 - 14:00'),
('Restoran Foh San Ipoh',            'Perak', 'Ipoh', 4.5980, 101.0880, 'Chinese', 15.00, 4.5, '06:00 - 14:00'),
('Restoran Seri Menanti',            'Negeri Sembilan', 'Seremban', 2.7300, 101.9400, 'Malay', 10.00, 4.2, '08:00 - 22:00'),
('Nasi Kandar Perlis',               'Perlis', 'Kangar', 6.4400, 100.1900, 'Indian', 8.00, 4.1, '07:00 - 22:00');

-- ============================================================
-- PART 6: UPDATE EXISTING CULTURAL PLACES WITH DISTRICTS
-- (Heuristic mapping based on address)
-- ============================================================

UPDATE `cultural_places` SET `district` = 'Johor Bahru' WHERE `state` = 'Johor' AND `address` LIKE '%Johor Bahru%';
UPDATE `cultural_places` SET `district` = 'Muar' WHERE `state` = 'Johor' AND `address` LIKE '%Muar%';
UPDATE `cultural_places` SET `district` = 'Batu Pahat' WHERE `state` = 'Johor' AND `address` LIKE '%Batu Pahat%';
UPDATE `cultural_places` SET `district` = 'Johor Bahru' WHERE `state` = 'Johor' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Langkawi' WHERE `state` = 'Kedah' AND `address` LIKE '%Langkawi%';
UPDATE `cultural_places` SET `district` = 'Kota Setar' WHERE `state` = 'Kedah' AND `address` LIKE '%Alor Setar%';
UPDATE `cultural_places` SET `district` = 'Kota Setar' WHERE `state` = 'Kedah' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Kota Bharu' WHERE `state` = 'Kelantan' AND (`address` LIKE '%Kota Bharu%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Melaka Tengah' WHERE `state` = 'Melaka' AND (`address` LIKE '%Melaka%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Seremban' WHERE `state` = 'Negeri Sembilan' AND (`address` LIKE '%Seremban%' OR `district` IS NULL);

UPDATE `cultural_places` SET `district` = 'Cameron Highlands' WHERE `state` = 'Pahang' AND `address` LIKE '%Cameron%';
UPDATE `cultural_places` SET `district` = 'Kuantan' WHERE `state` = 'Pahang' AND `address` LIKE '%Kuantan%';
UPDATE `cultural_places` SET `district` = 'Kuantan' WHERE `state` = 'Pahang' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Timur Laut' WHERE `state` = 'Penang' AND (`address` LIKE '%Georgetown%' OR `address` LIKE '%George Town%');
UPDATE `cultural_places` SET `district` = 'Timur Laut' WHERE `state` = 'Penang' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Kinta' WHERE `state` = 'Perak' AND (`address` LIKE '%Ipoh%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Kangar' WHERE `state` = 'Perlis' AND (`district` IS NULL OR `address` LIKE '%Kangar%');

UPDATE `cultural_places` SET `district` = 'Kota Kinabalu' WHERE `state` = 'Sabah' AND (`address` LIKE '%Kota Kinabalu%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Sandakan' WHERE `state` = 'Sabah' AND `address` LIKE '%Sandakan%';

UPDATE `cultural_places` SET `district` = 'Kuching' WHERE `state` = 'Sarawak' AND (`address` LIKE '%Kuching%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Miri' WHERE `state` = 'Sarawak' AND `address` LIKE '%Miri%';

UPDATE `cultural_places` SET `district` = 'Petaling Jaya' WHERE `state` = 'Selangor' AND `address` LIKE '%Petaling%';
UPDATE `cultural_places` SET `district` = 'Shah Alam' WHERE `state` = 'Selangor' AND `address` LIKE '%Shah Alam%';
UPDATE `cultural_places` SET `district` = 'Petaling Jaya' WHERE `state` = 'Selangor' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Kuala Terengganu' WHERE `state` = 'Terengganu' AND (`address` LIKE '%Kuala Terengganu%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Kemaman' WHERE `state` = 'Terengganu' AND `address` LIKE '%Kemaman%';

UPDATE `cultural_places` SET `district` = 'City Centre (KLCC)' WHERE `state` = 'Kuala Lumpur' AND (`address` LIKE '%KLCC%' OR `address` LIKE '%Petronas%');
UPDATE `cultural_places` SET `district` = 'Brickfields' WHERE `state` = 'Kuala Lumpur' AND `address` LIKE '%Brickfields%';
UPDATE `cultural_places` SET `district` = 'City Centre (KLCC)' WHERE `state` = 'Kuala Lumpur' AND `district` IS NULL;

UPDATE `cultural_places` SET `district` = 'Putrajaya' WHERE `state` = 'Putrajaya' AND `district` IS NULL;
UPDATE `cultural_places` SET `district` = 'Victoria' WHERE `state` = 'Labuan' AND `district` IS NULL;

COMMIT;
