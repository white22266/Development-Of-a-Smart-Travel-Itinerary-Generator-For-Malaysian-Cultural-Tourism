-- ============================================================
-- Smart Travel Itinerary Generator
-- Complete Upload / Migration Fix SQL
-- ------------------------------------------------------------
-- How to use:
-- 1. Import travel_itinerary_db.sql first if this is a fresh database.
-- 2. Then import this file in phpMyAdmin.
-- 3. This file is additive: it adds missing tables/columns/indexes and
--    backfills safe default data. It does not truncate user data.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- ============================================================
-- Helper: add column only when missing
-- ============================================================

DROP PROCEDURE IF EXISTS add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN ', p_column_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ============================================================
-- 1. Auth columns required by current PHP code
-- ============================================================

CALL add_column_if_missing('travellers', 'is_active', "`is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `phone`");
CALL add_column_if_missing('travellers', 'activation_token', "`activation_token` VARCHAR(100) DEFAULT NULL AFTER `is_active`");
CALL add_column_if_missing('travellers', 'activation_expires', "`activation_expires` DATETIME DEFAULT NULL AFTER `activation_token`");
CALL add_column_if_missing('travellers', 'reset_token', "`reset_token` VARCHAR(100) DEFAULT NULL AFTER `activation_expires`");
CALL add_column_if_missing('travellers', 'reset_expires', "`reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`");

CALL add_column_if_missing('admins', 'reset_token', "`reset_token` VARCHAR(100) DEFAULT NULL AFTER `password_hash`");
CALL add_column_if_missing('admins', 'reset_expires', "`reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`");

UPDATE `travellers`
SET `is_active` = 1
WHERE `is_active` IS NULL;

-- ============================================================
-- 2. Cultural place / suggestion columns required by current UI
-- ============================================================

ALTER TABLE `cultural_places`
  MODIFY COLUMN `opening_hours` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `image_url` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `image_path` VARCHAR(1000) DEFAULT NULL;

CALL add_column_if_missing('cultural_places', 'district', "`district` VARCHAR(80) DEFAULT NULL AFTER `state`");
CALL add_column_if_missing('cultural_places', 'entrance_fee', "`entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `estimated_cost`");
CALL add_column_if_missing('cultural_places', 'is_free', "`is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entrance_fee`");
CALL add_column_if_missing('cultural_places', 'halal_status', "`halal_status` TINYINT(1) DEFAULT NULL AFTER `image_path`");
CALL add_column_if_missing('cultural_places', 'is_outdoor', "`is_outdoor` TINYINT(1) DEFAULT NULL AFTER `halal_status`");
CALL add_column_if_missing('cultural_places', 'visit_duration_min', "`visit_duration_min` INT NOT NULL DEFAULT 90 AFTER `is_outdoor`");
CALL add_column_if_missing('cultural_places', 'best_time_to_visit', "`best_time_to_visit` VARCHAR(100) DEFAULT NULL AFTER `visit_duration_min`");
CALL add_column_if_missing('cultural_places', 'dress_code_required', "`dress_code_required` TINYINT(1) DEFAULT NULL AFTER `best_time_to_visit`");
CALL add_column_if_missing('cultural_places', 'website_url', "`website_url` VARCHAR(300) DEFAULT NULL AFTER `dress_code_required`");
CALL add_column_if_missing('cultural_places', 'phone_number', "`phone_number` VARCHAR(30) DEFAULT NULL AFTER `website_url`");
CALL add_column_if_missing('cultural_places', 'avg_rating', "`avg_rating` DECIMAL(3,2) DEFAULT NULL AFTER `phone_number`");

-- Some itinerary review code expects cp.rating. Keep both names available.
CALL add_column_if_missing('cultural_places', 'rating', "`rating` DECIMAL(3,2) DEFAULT NULL AFTER `avg_rating`");

ALTER TABLE `cultural_place_suggestions`
  MODIFY COLUMN `opening_hours` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `image_url` VARCHAR(1000) DEFAULT NULL;

CALL add_column_if_missing('cultural_place_suggestions', 'district', "`district` VARCHAR(80) DEFAULT NULL AFTER `state`");

UPDATE `cultural_places`
SET
  `entrance_fee` = COALESCE(NULLIF(`entrance_fee`, 0), `estimated_cost`, 0.00),
  `is_free` = CASE WHEN COALESCE(NULLIF(`entrance_fee`, 0), `estimated_cost`, 0.00) <= 0 THEN 1 ELSE 0 END,
  `visit_duration_min` = CASE
    WHEN `visit_duration_min` IS NULL OR `visit_duration_min` <= 0 THEN
      CASE
        WHEN `category` = 'food' THEN 60
        WHEN `category` = 'festival' THEN 150
        WHEN `category` IN ('culture', 'heritage', 'museum', 'nature') THEN 120
        WHEN `category` = 'shopping' THEN 90
        ELSE 90
      END
    ELSE `visit_duration_min`
  END,
  `rating` = COALESCE(`rating`, `avg_rating`);

-- ============================================================
-- 3. Hotels / Food tables
-- ============================================================

CREATE TABLE IF NOT EXISTS `hotels` (
  `hotel_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `price_per_night` DECIMAL(10,2) DEFAULT 100.00,
  `rating` DECIMAL(3,1) DEFAULT 3.5,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hotel_id`),
  KEY `idx_hotels_state` (`state`),
  KEY `idx_hotels_district` (`district`),
  KEY `idx_hotels_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `food_places` (
  `food_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `cuisine_type` VARCHAR(80) DEFAULT NULL,
  `avg_price` DECIMAL(10,2) DEFAULT 15.00,
  `rating` DECIMAL(3,1) DEFAULT 3.5,
  `opening_hour` VARCHAR(120) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`food_id`),
  KEY `idx_food_state` (`state`),
  KEY `idx_food_district` (`district`),
  KEY `idx_food_coords` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed only when table is empty to avoid duplicate hotel/food rows.
INSERT INTO `hotels` (`name`, `state`, `district`, `latitude`, `longitude`, `price_per_night`, `rating`)
SELECT * FROM (
  SELECT 'Citrus Hotel Johor Bahru', 'Johor', 'Johor Bahru', 1.4655000, 103.7578000, 120.00, 3.8 UNION ALL
  SELECT 'Thistle Johor Bahru', 'Johor', 'Johor Bahru', 1.4655000, 103.7578000, 250.00, 4.2 UNION ALL
  SELECT 'Mandarin Oriental Kuala Lumpur', 'Kuala Lumpur', 'City Centre (KLCC)', 3.1570000, 101.7120000, 450.00, 4.7 UNION ALL
  SELECT 'Berjaya Times Square Hotel KL', 'Kuala Lumpur', 'Bukit Bintang', 3.1420000, 101.7100000, 200.00, 4.1 UNION ALL
  SELECT 'Eastern & Oriental Hotel', 'Penang', 'Timur Laut', 5.4185000, 100.3368000, 380.00, 4.6 UNION ALL
  SELECT 'Hatten Hotel Melaka', 'Melaka', 'Melaka Tengah', 2.1930000, 102.2510000, 180.00, 4.0 UNION ALL
  SELECT 'Renaissance Kota Bharu Hotel', 'Kelantan', 'Kota Bharu', 6.1248000, 102.2382000, 220.00, 4.2 UNION ALL
  SELECT 'Langkawi Lagoon Resort', 'Kedah', 'Langkawi', 6.3500000, 99.8500000, 280.00, 4.3 UNION ALL
  SELECT 'Sunway Resort Hotel', 'Selangor', 'Subang Jaya', 3.0730000, 101.6060000, 320.00, 4.4 UNION ALL
  SELECT 'Hyatt Regency Kuantan Resort', 'Pahang', 'Kuantan', 3.8000000, 103.3300000, 280.00, 4.3 UNION ALL
  SELECT 'Shangri-La Tanjung Aru Resort', 'Sabah', 'Kota Kinabalu', 5.9630000, 116.0720000, 380.00, 4.6 UNION ALL
  SELECT 'Hilton Kuching', 'Sarawak', 'Kuching', 1.5590000, 110.3440000, 280.00, 4.3 UNION ALL
  SELECT 'Sutra Beach Resort', 'Terengganu', 'Setiu', 5.6500000, 102.8000000, 200.00, 4.1 UNION ALL
  SELECT 'Casuarina@Meru Hotel', 'Perak', 'Ipoh', 4.6000000, 101.0900000, 160.00, 4.0 UNION ALL
  SELECT 'Allson Klana Resort Seremban', 'Negeri Sembilan', 'Seremban', 2.7300000, 101.9400000, 150.00, 3.9 UNION ALL
  SELECT 'Hotel Seri Malaysia Kangar', 'Perlis', 'Kangar', 6.4400000, 100.1900000, 100.00, 3.6
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `hotels` LIMIT 1);

INSERT INTO `food_places` (`name`, `state`, `district`, `latitude`, `longitude`, `cuisine_type`, `avg_price`, `rating`, `opening_hour`)
SELECT * FROM (
  SELECT 'Kacang Pool Haji Restauran', 'Johor', 'Johor Bahru', 1.4600000, 103.7600000, 'Malay', 8.00, 4.2, '07:00 - 22:00' UNION ALL
  SELECT 'Mee Rebus Haji Wahid', 'Johor', 'Johor Bahru', 1.4620000, 103.7580000, 'Malay', 7.00, 4.3, '07:00 - 15:00' UNION ALL
  SELECT 'Nasi Kandar Pelita', 'Kuala Lumpur', 'City Centre (KLCC)', 3.1580000, 101.7200000, 'Indian', 12.00, 4.2, '24 hours' UNION ALL
  SELECT 'Restoran Yut Kee', 'Kuala Lumpur', 'Chow Kit', 3.1650000, 101.7010000, 'Chinese', 18.00, 4.4, '07:30 - 16:00' UNION ALL
  SELECT 'Penang Road Famous Teochew Cendol', 'Penang', 'Timur Laut', 5.4160000, 100.3340000, 'Dessert', 5.00, 4.6, '10:30 - 18:00' UNION ALL
  SELECT 'Jonker Street Night Market', 'Melaka', 'Melaka Tengah', 2.1960000, 102.2490000, 'Mixed', 10.00, 4.4, '18:00 - 23:00' UNION ALL
  SELECT 'Nasi Kerabu Kak Yah', 'Kelantan', 'Kota Bharu', 6.1230000, 102.2360000, 'Malay', 6.00, 4.5, '07:00 - 14:00' UNION ALL
  SELECT 'Restoran Sri Paandi', 'Selangor', 'Shah Alam', 3.0850000, 101.5320000, 'Indian', 12.00, 4.3, '07:00 - 22:00' UNION ALL
  SELECT 'Restoran Ikan Bakar Kuantan', 'Pahang', 'Kuantan', 3.8050000, 103.3250000, 'Seafood', 25.00, 4.3, '11:00 - 22:00' UNION ALL
  SELECT 'Top Spot Food Court Kuching', 'Sarawak', 'Kuching', 1.5580000, 110.3430000, 'Seafood', 25.00, 4.4, '17:00 - 23:00'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `food_places` LIMIT 1);

-- ============================================================
-- 4. Itinerary hotel support
-- ============================================================

CALL add_column_if_missing('itineraries', 'selected_hotel_id', "`selected_hotel_id` INT DEFAULT NULL AFTER `items_per_day`");
CALL add_column_if_missing('itineraries', 'selected_hotel_name', "`selected_hotel_name` VARCHAR(150) DEFAULT NULL AFTER `selected_hotel_id`");
CALL add_column_if_missing('itineraries', 'selected_hotel_nights', "`selected_hotel_nights` INT NOT NULL DEFAULT 0 AFTER `selected_hotel_name`");
CALL add_column_if_missing('itineraries', 'selected_hotel_total_cost', "`selected_hotel_total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `selected_hotel_nights`");

CALL add_column_if_missing('itinerary_items', 'hotel_id', "`hotel_id` INT DEFAULT NULL AFTER `place_id`");
CALL add_column_if_missing('itinerary_items', 'item_latitude', "`item_latitude` DECIMAL(10,7) DEFAULT NULL AFTER `item_title`");
CALL add_column_if_missing('itinerary_items', 'item_longitude', "`item_longitude` DECIMAL(10,7) DEFAULT NULL AFTER `item_latitude`");

UPDATE `itinerary_items` ii
LEFT JOIN `cultural_places` cp ON cp.place_id = ii.place_id
SET
  ii.item_latitude = COALESCE(ii.item_latitude, cp.latitude),
  ii.item_longitude = COALESCE(ii.item_longitude, cp.longitude)
WHERE ii.place_id IS NOT NULL;

-- Backfill itinerary selected hotel columns from existing hotel item rows.
UPDATE `itineraries` i
LEFT JOIN (
    SELECT itinerary_id,
           MAX(item_title) AS hotel_name,
           SUM(estimated_cost) AS hotel_total
    FROM `itinerary_items`
    WHERE item_type = 'hotel'
    GROUP BY itinerary_id
) h ON h.itinerary_id = i.itinerary_id
SET
  i.selected_hotel_name = COALESCE(i.selected_hotel_name, h.hotel_name),
  i.selected_hotel_total_cost = COALESCE(NULLIF(i.selected_hotel_total_cost, 0), h.hotel_total, 0)
WHERE h.itinerary_id IS NOT NULL;

-- ============================================================
-- 5. Malaysia districts reference table
-- ============================================================

CREATE TABLE IF NOT EXISTS `malaysia_districts` (
  `district_id` INT NOT NULL AUTO_INCREMENT,
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`district_id`),
  UNIQUE KEY `uq_state_district` (`state`, `district`),
  KEY `idx_district_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `malaysia_districts` (`state`, `district`) VALUES
('Johor', 'Johor Bahru'), ('Johor', 'Kluang'), ('Johor', 'Kota Tinggi'), ('Johor', 'Muar'), ('Johor', 'Batu Pahat'), ('Johor', 'Pontian'), ('Johor', 'Segamat'), ('Johor', 'Kulai'),
('Kedah', 'Kota Setar'), ('Kedah', 'Langkawi'), ('Kedah', 'Kuala Muda'), ('Kedah', 'Kulim'),
('Kelantan', 'Kota Bharu'), ('Kelantan', 'Bachok'), ('Kelantan', 'Pasir Mas'), ('Kelantan', 'Tumpat'),
('Melaka', 'Melaka Tengah'), ('Melaka', 'Alor Gajah'), ('Melaka', 'Jasin'),
('Negeri Sembilan', 'Seremban'), ('Negeri Sembilan', 'Port Dickson'), ('Negeri Sembilan', 'Rembau'),
('Pahang', 'Kuantan'), ('Pahang', 'Temerloh'), ('Pahang', 'Bentong'), ('Pahang', 'Cameron Highlands'),
('Penang', 'Timur Laut'), ('Penang', 'Barat Daya'), ('Penang', 'Seberang Perai Utara'), ('Penang', 'Seberang Perai Tengah'), ('Penang', 'Seberang Perai Selatan'),
('Perak', 'Ipoh'), ('Perak', 'Kinta'), ('Perak', 'Manjung'), ('Perak', 'Hilir Perak'),
('Perlis', 'Kangar'), ('Perlis', 'Arau'), ('Perlis', 'Padang Besar'),
('Sabah', 'Kota Kinabalu'), ('Sabah', 'Sandakan'), ('Sabah', 'Tawau'), ('Sabah', 'Ranau'),
('Sarawak', 'Kuching'), ('Sarawak', 'Miri'), ('Sarawak', 'Sibu'), ('Sarawak', 'Bintulu'),
('Selangor', 'Petaling Jaya'), ('Selangor', 'Shah Alam'), ('Selangor', 'Klang'), ('Selangor', 'Subang Jaya'), ('Selangor', 'Gombak'),
('Terengganu', 'Kuala Terengganu'), ('Terengganu', 'Kemaman'), ('Terengganu', 'Dungun'),
('Kuala Lumpur', 'City Centre (KLCC)'), ('Kuala Lumpur', 'Bukit Bintang'), ('Kuala Lumpur', 'Chow Kit'), ('Kuala Lumpur', 'Brickfields'), ('Kuala Lumpur', 'Bangsar'),
('Putrajaya', 'Putrajaya'),
('Labuan', 'Victoria'), ('Labuan', 'Labuan Town');

-- ============================================================
-- 6. Extra supervisor tables
-- ============================================================

CREATE TABLE IF NOT EXISTS `place_images` (
  `image_id` INT NOT NULL AUTO_INCREMENT,
  `place_id` INT NOT NULL,
  `image_url` VARCHAR(1000) NOT NULL,
  `caption` VARCHAR(150) DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`image_id`),
  KEY `idx_place_images_place` (`place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ratings_reviews` (
  `review_id` INT NOT NULL AUTO_INCREMENT,
  `place_id` INT NOT NULL,
  `traveller_id` INT NOT NULL,
  `rating` TINYINT NOT NULL,
  `review_text` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_review_place_traveller` (`place_id`, `traveller_id`),
  KEY `idx_review_place` (`place_id`),
  KEY `idx_review_traveller` (`traveller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CALL add_column_if_missing('ratings_reviews', 'updated_at', "`updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");

-- Normalized preference junction tables required by supervisor feedback.
CALL add_column_if_missing('traveller_preferences', 'preferred_districts', "`preferred_districts` VARCHAR(255) DEFAULT NULL AFTER `preferred_states`");

CREATE TABLE IF NOT EXISTS `preference_interests` (
  `preference_id` INT NOT NULL,
  `interest` ENUM('culture','heritage','food','museum','nature','shopping','festival') NOT NULL,
  PRIMARY KEY (`preference_id`, `interest`),
  KEY `idx_pref_interest` (`interest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `preference_states` (
  `preference_id` INT NOT NULL,
  `state` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`preference_id`, `state`),
  KEY `idx_pref_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `preference_districts` (
  `preference_id` INT NOT NULL,
  `state` VARCHAR(60) DEFAULT NULL,
  `district` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`preference_id`, `district`),
  KEY `idx_pref_district` (`district`),
  KEY `idx_pref_district_state` (`state`, `district`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `preference_interests` (`preference_id`, `interest`)
SELECT tp.preference_id, jt.interest
FROM traveller_preferences tp
JOIN (
    SELECT 'culture' interest UNION ALL SELECT 'heritage' UNION ALL SELECT 'food'
    UNION ALL SELECT 'museum' UNION ALL SELECT 'nature' UNION ALL SELECT 'shopping'
    UNION ALL SELECT 'festival'
) jt ON FIND_IN_SET(jt.interest, tp.interests);

INSERT IGNORE INTO `preference_states` (`preference_id`, `state`)
SELECT tp.preference_id, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(tp.preferred_states, ',', n.n), ',', -1)) AS state
FROM traveller_preferences tp
JOIN (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
) n ON n.n <= 1 + LENGTH(COALESCE(tp.preferred_states,'')) - LENGTH(REPLACE(COALESCE(tp.preferred_states,''), ',', ''))
WHERE TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(tp.preferred_states, ',', n.n), ',', -1)) <> '';

INSERT IGNORE INTO `preference_districts` (`preference_id`, `state`, `district`)
SELECT tp.preference_id, ps.state,
       TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(tp.preferred_districts, ',', n.n), ',', -1)) AS district
FROM traveller_preferences tp
LEFT JOIN preference_states ps ON ps.preference_id = tp.preference_id
JOIN (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
) n ON n.n <= 1 + LENGTH(COALESCE(tp.preferred_districts,'')) - LENGTH(REPLACE(COALESCE(tp.preferred_districts,''), ',', ''))
WHERE TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(tp.preferred_districts, ',', n.n), ',', -1)) <> '';

CREATE TABLE IF NOT EXISTS `wishlists` (
  `wishlist_id` INT NOT NULL AUTO_INCREMENT,
  `traveller_id` INT NOT NULL,
  `place_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`wishlist_id`),
  UNIQUE KEY `uq_wishlist_traveller_place` (`traveller_id`, `place_id`),
  KEY `idx_wishlist_place` (`place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `shared_itineraries` (
  `share_id` INT NOT NULL AUTO_INCREMENT,
  `itinerary_id` INT NOT NULL,
  `share_token` VARCHAR(64) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`share_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_share_itinerary` (`itinerary_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ai_chat_logs` (
  `chat_id` INT NOT NULL AUTO_INCREMENT,
  `itinerary_id` INT NOT NULL,
  `traveller_id` INT NOT NULL,
  `user_message` TEXT NOT NULL,
  `ai_response` MEDIUMTEXT NOT NULL,
  `source` VARCHAR(40) NOT NULL DEFAULT 'unknown',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`chat_id`),
  KEY `idx_ai_chat_itinerary` (`itinerary_id`),
  KEY `idx_ai_chat_traveller` (`traveller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `audit_id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(80) NOT NULL,
  `entity_id` INT DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_admin` (`admin_id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `place_images` (`place_id`, `image_url`, `is_primary`)
SELECT cp.`place_id`, cp.`image_url`, 1
FROM `cultural_places` cp
WHERE cp.`image_url` IS NOT NULL
  AND cp.`image_url` <> ''
  AND NOT EXISTS (
    SELECT 1
    FROM `place_images` pi
    WHERE pi.`place_id` = cp.`place_id`
      AND pi.`image_url` = cp.`image_url`
  );

-- ============================================================
-- 7. Safe indexes and foreign keys
-- ============================================================

UPDATE `itinerary_items` ii
LEFT JOIN `cultural_places` cp ON cp.`place_id` = ii.`place_id`
SET ii.`place_id` = NULL
WHERE ii.`place_id` IS NOT NULL
  AND cp.`place_id` IS NULL;

UPDATE `itinerary_items` ii
LEFT JOIN `hotels` h ON h.`hotel_id` = ii.`hotel_id`
SET ii.`hotel_id` = NULL
WHERE ii.`hotel_id` IS NOT NULL
  AND h.`hotel_id` IS NULL;

UPDATE `itineraries` i
LEFT JOIN `hotels` h ON h.`hotel_id` = i.`selected_hotel_id`
SET i.`selected_hotel_id` = NULL
WHERE i.`selected_hotel_id` IS NOT NULL
  AND h.`hotel_id` IS NULL;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itinerary_items' AND INDEX_NAME = 'idx_item_place'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE `itinerary_items` ADD KEY `idx_item_place` (`place_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itinerary_items' AND INDEX_NAME = 'idx_item_hotel'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE `itinerary_items` ADD KEY `idx_item_hotel` (`hotel_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itineraries' AND INDEX_NAME = 'idx_itinerary_selected_hotel'
);
SET @sql := IF(@has_idx = 0, 'ALTER TABLE `itineraries` ADD KEY `idx_itinerary_selected_hotel` (`selected_hotel_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'itinerary_items' AND CONSTRAINT_NAME = 'fk_item_place'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE `itinerary_items` ADD CONSTRAINT `fk_item_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'itinerary_items' AND CONSTRAINT_NAME = 'fk_item_hotel'
);
SET @sql := IF(
  @has_fk = 0,
  'ALTER TABLE `itinerary_items` ADD CONSTRAINT `fk_item_hotel` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`hotel_id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS add_column_if_missing;

-- ============================================================
-- Done
-- ============================================================
