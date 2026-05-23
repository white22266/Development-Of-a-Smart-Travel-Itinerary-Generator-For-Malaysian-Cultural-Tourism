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
DROP PROCEDURE IF EXISTS migrate_image_path_to_image_url;
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

CREATE PROCEDURE migrate_image_path_to_image_url()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cultural_places'
          AND COLUMN_NAME = 'image_path'
    ) THEN
        SET @sql = CONCAT(
            'UPDATE `cultural_places` SET `image_url` = `image_path` ',
            'WHERE `image_path` IS NOT NULL AND TRIM(`image_path`) <> ',
            CHAR(39), CHAR(39)
        );
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        SET @sql = 'ALTER TABLE `cultural_places` DROP COLUMN `image_path`';
        PREPARE stmt FROM @sql;
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
  MODIFY COLUMN `image_url` VARCHAR(1000) DEFAULT NULL;

CALL migrate_image_path_to_image_url();

CALL add_column_if_missing('cultural_places', 'district', "`district` VARCHAR(80) DEFAULT NULL AFTER `state`");
CALL add_column_if_missing('cultural_places', 'entrance_fee', "`entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `estimated_cost`");
CALL add_column_if_missing('cultural_places', 'is_free', "`is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entrance_fee`");
CALL add_column_if_missing('cultural_places', 'halal_status', "`halal_status` TINYINT(1) DEFAULT NULL AFTER `image_url`");
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
-- 6.1 Johor food meal coverage for itinerary generation
-- ============================================================

DROP TEMPORARY TABLE IF EXISTS `tmp_johor_meal_places`;
CREATE TEMPORARY TABLE `tmp_johor_meal_places` (
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) NOT NULL,
  `category` VARCHAR(20) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `opening_hours` VARCHAR(1000) DEFAULT NULL,
  `estimated_cost` DECIMAL(10,2) DEFAULT NULL,
  `entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `is_free` TINYINT(1) NOT NULL DEFAULT 0,
  `halal_status` TINYINT(1) DEFAULT NULL,
  `is_outdoor` TINYINT(1) DEFAULT 0,
  `visit_duration_min` INT NOT NULL DEFAULT 60,
  `best_time_to_visit` VARCHAR(100) DEFAULT NULL,
  `website_url` VARCHAR(300) DEFAULT NULL,
  `rating` DECIMAL(3,2) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tmp_johor_meal_places`
(`state`,`district`,`category`,`name`,`description`,`address`,`latitude`,`longitude`,`opening_hours`,`estimated_cost`,`entrance_fee`,`is_free`,`halal_status`,`is_outdoor`,`visit_duration_min`,`best_time_to_visit`,`website_url`,`rating`,`is_active`)
VALUES
('Johor','Johor Bahru','food','Padi Kopitiam Jalan Trus','Johor Bahru kopitiam stop suitable for breakfast before city heritage visits.','82-B1, Jalan Trus, Bandar Johor Bahru, 80888 Johor Bahru, Johor',1.4634301,103.7615367,'Daily 08:00-20:00; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Padi+Kopitiam+Jalan+Trus+Johor+Bahru',4.40,1),
('Johor','Johor Bahru','food','JIBRIL JB','Johor Bahru lunch stop for travellers moving through Larkin and nearby city attractions.','51, Jalan Geroda 2/1, Larkin Jaya, 80350 Johor Bahru, Johor',1.4963368,103.7408865,'Daily 12:00-00:00; verify current hours',22.00,22.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=JIBRIL+JB+Johor+Bahru',4.80,1),
('Johor','Johor Bahru','food','SAMA Restaurant and Cafe Dining','Johor Bahru dinner stop suitable after a city day route.','36, Jalan Beringin, Taman Melodies, 80250 Johor Bahru, Johor',1.4900708,103.7619756,'Daily 11:00-21:00; verify current hours',28.00,28.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=SAMA+Restaurant+Cafe+Dining+Johor+Bahru',4.80,1),
('Johor','Batu Pahat','food','Din Fung Kee Hainan Kopitiam Batu Pahat','Batu Pahat kopitiam stop for an early local breakfast.','29, Jalan Flora Utama 6, Taman Flora Utama, 83000 Batu Pahat, Johor',1.8644678,102.9503177,'Daily 07:30-16:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Din+Fung+Kee+Hainan+Kopitiam+Batu+Pahat',4.70,1),
('Johor','Batu Pahat','food','Kafe Kiri Kanan','Batu Pahat cafe meal stop for lunch in the town route.','92, Jalan Rahmat, Kampung Pegawai, 83000 Batu Pahat, Johor',1.8494164,102.9297607,'Daily 09:30-22:00; verify current hours',20.00,20.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Kafe+Kiri+Kanan+Batu+Pahat',4.50,1),
('Johor','Batu Pahat','food','The Libertine Batu Pahat','Batu Pahat evening food stop for dinner after town visits.','60, Jalan Penjaja, Pusat Komersial Bentara, 83000 Batu Pahat, Johor',1.8583068,102.9298662,'Daily 15:00-03:00; verify current hours',28.00,28.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=The+Libertine+Batu+Pahat',4.90,1),
('Johor','Kluang','food','Kedai Kopi MKDL','Kluang breakfast stop for a town-based cultural route.','135, Jalan Seri Impian 8/1C, Bandar Seri Impian, 86000 Kluang, Johor',1.9879360,103.3669861,'Daily 08:00-18:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Kedai+Kopi+MKDL+Kluang',4.40,1),
('Johor','Kluang','food','Barneys DX Kluang','Kluang lunch stop near central town attractions.','7 and 8, Jalan Komersil Kluang 2, Kampung Masjid Lama, 86000 Kluang, Johor',2.0346098,103.3190242,'Daily 11:30-22:00; verify current hours',24.00,24.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Barneys+DX+Kluang',4.20,1),
('Johor','Kluang','food','Heidi Family Restaurant','Kluang dinner stop for an end-of-day meal.','60, Jalan Gunung Lambak 1, Taman Gunung Lambak, 86000 Kluang, Johor',2.0302299,103.3329247,'Daily 11:00-22:00; verify current hours',24.00,24.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Heidi+Family+Restaurant+Kluang',4.60,1),
('Johor','Kota Tinggi','food','Laughing Canteen Kota Tinggi','Kota Tinggi breakfast stop before museum, riverfront, or waterfall routes.','3A, Jalan Mawar 2, Bandar Kota Tinggi, 81900 Kota Tinggi, Johor',1.7333013,103.9005402,'Daily 07:30-16:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Laughing+Canteen+Kota+Tinggi',4.30,1),
('Johor','Kota Tinggi','food','Restoran Public Kota Tinggi','Kota Tinggi lunch stop for town and heritage routes.','Jalan Johor, Taman Desa Riang, 81900 Kota Tinggi, Johor',1.7214486,103.8975408,'Daily 11:00-22:00; verify current hours',18.00,18.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Restoran+Public+Kota+Tinggi',4.20,1),
('Johor','Kota Tinggi','food','Restoran Bin Abu','Kota Tinggi dinner stop for travellers returning to town.','2, Jalan Maju, Taman Mawai Jaya, 81900 Kota Tinggi, Johor',1.7339663,103.9062695,'Daily 12:00-23:00; verify current hours',20.00,20.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Restoran+Bin+Abu+Kota+Tinggi',3.70,1),
('Johor','Kulai','food','Good Morning Kulai','Kulai breakfast stop for an early town and Senai-area itinerary.','237, Jalan Kiambang 15, Bandar Indahpura, 81000 Kulai, Johor',1.6432447,103.6051418,'Daily 07:00-17:00; verify current hours',10.00,10.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Good+Morning+Kulai',4.10,1),
('Johor','Kulai','food','P Loko Kulai Branch','Kulai lunch stop for travellers around Indahpura.','123, Jalan Kenanga 29/6, Bandar Indahpura, 81000 Kulai, Johor',1.6422965,103.6196807,'Daily 11:00-22:00; verify current hours',22.00,22.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=P+Loko+Kulai+Branch',4.80,1),
('Johor','Kulai','food','Der Cabin Bistro Kulai','Kulai dinner stop for an end-of-day meal.','Taman Kulai Permai, 81000 Kulai, Johor',1.6585466,103.5856607,'Daily 17:00-23:30; verify current hours',26.00,26.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Der+Cabin+Bistro+Kulai',4.80,1),
('Johor','Mersing','food','Kedai Ucu Selera Kita','Mersing breakfast stop before harbour and coastal visits.','82, Jalan Makam, Kampung Mersing Kanan, 86800 Mersing, Johor',2.4382635,103.8379953,'Daily 08:00-17:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Kedai+Ucu+Selera+Kita+Mersing',4.20,1),
('Johor','Mersing','food','Restoran Makanan Laut An Ji','Mersing lunch stop near the harbour area.','8, Jalan Abu Bakar, Bandar Mersing, 86800 Mersing, Johor',2.4317355,103.8375119,'Daily 12:00-22:30; verify current hours',28.00,28.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Restoran+Makanan+Laut+An+Ji+Mersing',4.80,1),
('Johor','Mersing','food','T and K Seafood Restaurant Mersing','Mersing seafood dinner stop for town-based coastal routes.','Lot 7411-10, Jalan Jemaluang, Mersing Kechil, 86800 Mersing, Johor',2.4263143,103.8352215,'Daily 12:00-21:00; verify current hours',32.00,32.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=T+and+K+Seafood+Restaurant+Mersing',4.00,1),
('Johor','Muar','food','Eat Toast Muar','Muar breakfast stop before the heritage and riverfront route.','20, Jalan Gemilang Bakri 1, Pusat Komersial Gemilang Bakri, 84200 Muar, Johor',2.0492665,102.6006632,'Daily 07:30-21:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Eat+Toast+Muar',4.70,1),
('Johor','Muar','food','Sama Sama Cuisine Muar','Muar lunch stop for a town itinerary.','14-1, Jalan Abdul Rahman, Kampung Dato Bentara Dalam, 84000 Muar, Johor',2.0372266,102.5629902,'Daily 12:00-23:00; verify current hours',24.00,24.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Sama+Sama+Cuisine+Muar',4.90,1),
('Johor','Muar','food','Sin Kee Ting Restaurant','Muar dinner stop after riverside and old town visits.','68, Jalan Sungai Abong, Taman Sakeh Baru, 84000 Muar, Johor',2.0568060,102.5882470,'Daily 18:00-22:30; verify current hours',28.00,28.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Sin+Kee+Ting+Restaurant+Muar',4.20,1),
('Johor','Pontian','food','Warung An and Maiza','Pontian breakfast stop before town, market, or coastal visits.','Batu 35, Jalan Johor, Simpang Sungai Bunyi, 82000 Pontian, Johor',1.4844465,103.4175114,'Daily 07:30-11:30; verify current hours',10.00,10.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Warung+An+and+Maiza+Pontian',4.30,1),
('Johor','Pontian','food','Restoran Nasi Beriani Buluh Pontian','Pontian lunch stop near town routes.','22-G, Jalan Delima 7, Pontian Kecil, 82000 Pontian, Johor',1.4802953,103.3873915,'Daily 10:00-22:00; verify current hours',18.00,18.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Restoran+Nasi+Beriani+Buluh+Pontian',4.50,1),
('Johor','Pontian','food','Pontian Rooftop Restaurant','Pontian dinner stop for the end of a coastal itinerary.','1-2, Jalan Delima 13, Pontian Kechil, 82000 Pontian, Johor',1.4811536,103.3848993,'Daily 16:00-22:30; verify current hours',28.00,28.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Pontian+Rooftop+Restaurant',4.30,1),
('Johor','Segamat','food','Nan Yang Coffee Shop Segamat','Segamat breakfast stop for an early town itinerary.','8, Jalan Awang, Kampung Gubah, 85000 Segamat, Johor',2.5083164,102.8150854,'Daily 08:00-23:00; verify current hours',10.00,10.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Nan+Yang+Coffee+Shop+Segamat',3.60,1),
('Johor','Segamat','food','Tai Ah Restaurant Segamat','Segamat lunch stop before afternoon town visits.','10, Jalan Chia Chin Koon, Kampung Abdullah, 85000 Segamat, Johor',2.5012358,102.8297557,'Daily 11:00-22:00; verify current hours',20.00,20.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Tai+Ah+Restaurant+Segamat',4.00,1),
('Johor','Segamat','food','Mayels 19 Cafe and Dessert','Segamat dinner and evening meal stop.','Jalan Genuang, Bandar Seberang, 85000 Segamat, Johor',2.4957897,102.8351974,'Daily 16:00-23:00; verify current hours',22.00,22.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Mayels+19+Cafe+Segamat',4.20,1),
('Johor','Tangkak','food','Nanyang Kopitiam Tangkak','Tangkak breakfast stop before a Gunung Ledang or town route.','Jalan Abdul Ghani, Kampung Padang Lalang, 84900 Tangkak, Johor',2.2686376,102.5417457,'Daily 08:30-00:00; verify current hours',10.00,10.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Nanyang+Kopitiam+Tangkak',3.50,1),
('Johor','Tangkak','food','Restoran Do Do Do Tangkak','Tangkak lunch stop for textile town and nearby routes.','90 and 91, Jalan Teknologi 1, Kawasan Perindustrian Tangkak, 84900 Tangkak, Johor',2.2425670,102.5320392,'Daily 08:00-16:00; verify current hours',16.00,16.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Restoran+Do+Do+Do+Tangkak',4.10,1),
('Johor','Tangkak','food','Pak Maon Western Tangkak','Tangkak dinner stop for an evening route.','Lot 1607, Jalan Muar, Kampung Tanjong Batu 15, 84900 Tangkak, Johor',2.2568444,102.5324530,'Daily 17:00-23:00; verify current hours',24.00,24.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Pak+Maon+Western+Tangkak',4.50,1);

INSERT INTO `tmp_johor_meal_places`
(`state`,`district`,`category`,`name`,`description`,`address`,`latitude`,`longitude`,`opening_hours`,`estimated_cost`,`entrance_fee`,`is_free`,`halal_status`,`is_outdoor`,`visit_duration_min`,`best_time_to_visit`,`website_url`,`rating`,`is_active`)
VALUES
('Johor','Johor Bahru','food','Restoran Hua Mui JB','Classic Johor Bahru kopitiam meal stop close to the old town route.','131, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor',1.4573123,103.7642822,'Daily 08:00-17:00; verify current hours',14.00,14.00,0,NULL,0,60,'Breakfast or Lunch','https://www.google.com/maps/search/?api=1&query=Restoran+Hua+Mui+JB',4.30,1),
('Johor','Batu Pahat','food','Toast Garden Batu Pahat','Batu Pahat breakfast stop with a kopitiam-style morning route fit.','33, Jalan Setia Jaya Utama, Taman Setia Jaya, 83000 Batu Pahat, Johor',1.8679440,102.9440368,'Daily 07:30-16:30; verify current hours',12.00,12.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Toast+Garden+Batu+Pahat',4.60,1),
('Johor','Batu Pahat','food','The Ramen Man Batu Pahat','Batu Pahat evening food stop for a denser dinner pool.','31, Jalan Ismail, Kampung Pegawai, 83000 Batu Pahat, Johor',1.8505145,102.9279384,'Daily 16:30-00:00; verify current hours',24.00,24.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=The+Ramen+Man+Batu+Pahat',4.80,1),
('Johor','Kluang','food','Ngepot Kabin Kluang','Kluang meal stop that can support lunch or dinner routes.','37, Jalan Mad Lazim Saim, Kampung Masjid Lama, 86000 Kluang, Johor',2.0350323,103.3220420,'Daily 11:00-23:00; verify current hours',20.00,20.00,0,NULL,0,60,'Lunch or Dinner','https://www.google.com/maps/search/?api=1&query=Ngepot+Kabin+Kluang',4.30,1),
('Johor','Kota Tinggi','food','Warung Belangkas Mahkota','Kota Tinggi local meal stop for lunch route variety.','Jalan Tun Sri Lanang, Bandar Kota Tinggi, 81900 Kota Tinggi, Johor',1.7326506,103.9037907,'Daily 11:00-18:00; verify current hours',18.00,18.00,0,NULL,0,60,'Lunch','https://www.google.com/maps/search/?api=1&query=Warung+Belangkas+Mahkota+Kota+Tinggi',4.20,1),
('Johor','Kota Tinggi','food','Gebekk Western Foods Kota Tinggi','Kota Tinggi evening meal stop for dinner route variety.','Lot 19138, Jalan By Pass, 81900 Kota Tinggi, Johor',1.7261699,103.9120025,'Daily 15:30-23:00; verify current hours',24.00,24.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Gebekk+Western+Foods+Kota+Tinggi',4.60,1),
('Johor','Kulai','food','The Cloud Restaurant Kulai','Kulai daytime food stop that can support breakfast or lunch.','191, Jalan Kiambang 12, Bandar Indahpura, 81000 Kulai, Johor',1.6420810,103.6044377,'Daily 07:30-18:00; verify current hours',18.00,18.00,0,NULL,0,60,'Breakfast or Lunch','https://www.google.com/maps/search/?api=1&query=The+Cloud+Restaurant+Kulai',4.60,1),
('Johor','Kulai','food','Defortune Restaurant Kulai','Kulai dinner stop for additional evening meal coverage.','Lebuh Putra Utama, Bandar Putra Kulai, 81000 Kulai, Johor',1.6454103,103.6242646,'Daily 18:00-22:30; verify current hours',28.00,28.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=Defortune+Restaurant+Kulai',4.00,1),
('Johor','Mersing','food','One Mersing Cafe','Mersing cafe stop for breakfast or evening route variation.','41-7, Jalan Awang Daik, Kampung Mersing Kanan, 86800 Mersing, Johor',2.4373176,103.8362728,'Daily 08:30-22:00; verify current hours',16.00,16.00,0,NULL,0,60,'Breakfast or Dinner','https://www.google.com/maps/search/?api=1&query=One+Mersing+Cafe',4.70,1),
('Johor','Mersing','food','My Kitchen House Mersing','Mersing evening food stop for dinner pool depth.','Lot 1785, Jalan Padi Malinja 5, Kampung Sawah Dato, 86800 Mersing, Johor',2.4670458,103.8203930,'Daily 16:30-22:30; verify current hours',24.00,24.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=My+Kitchen+House+Mersing',4.50,1),
('Johor','Muar','food','Good Taste Restaurant Muar','Muar all-day food stop for breakfast, lunch, or dinner variety.','35, Jalan Gemilang Bakri 1, Pusat Komersial Gemilang Bakri, 84200 Muar, Johor',2.0503437,102.6008235,'Daily 08:00-21:00; verify current hours',18.00,18.00,0,NULL,0,60,'Any Meal','https://www.google.com/maps/search/?api=1&query=Good+Taste+Restaurant+Muar',4.80,1),
('Johor','Pontian','food','Kopitiam Kampung Pontian','Pontian daytime kopitiam stop for breakfast pool depth.','19, Jalan Delima 3, Pontian Kecil, 82000 Pontian, Johor',1.4777403,103.3906549,'Daily 08:00-19:00; verify current hours',10.00,10.00,0,NULL,0,60,'Breakfast','https://www.google.com/maps/search/?api=1&query=Kopitiam+Kampung+Pontian',3.20,1),
('Johor','Pontian','food','D Laut Kitchen Pontian','Pontian evening meal stop for dinner route variety.','21, Kampung Permata, Lorong Antarabangsa, 82000 Pontian, Johor',1.4959315,103.3829996,'Daily 17:30-00:00; verify current hours',26.00,26.00,0,NULL,0,60,'Dinner','https://www.google.com/maps/search/?api=1&query=D+Laut+Kitchen+Pontian',4.50,1),
('Johor','Segamat','food','Ngeteh Port Cafe Segamat','Segamat all-day cafe stop for denser food route selection.','Lebuh Persiaran Tun Khalil Yaakob, Taman Yayasan, 85000 Segamat, Johor',2.5475163,102.7929572,'Daily 07:30-23:30; verify current hours',18.00,18.00,0,NULL,0,60,'Any Meal','https://www.google.com/maps/search/?api=1&query=Ngeteh+Port+Cafe+Segamat',3.90,1),
('Johor','Tangkak','food','Tangkak Kopitiam','Tangkak meal stop with lunch and dinner coverage near town routes.','13, Jalan Hospital, Pusat Perniagaan Padang Lalang, 84900 Tangkak, Johor',2.2717350,102.5454145,'Daily 10:00-01:00; verify current hours',14.00,14.00,0,NULL,0,60,'Lunch or Dinner','https://www.google.com/maps/search/?api=1&query=Tangkak+Kopitiam',3.70,1);

INSERT INTO `cultural_places`
(`state`,`district`,`category`,`name`,`description`,`address`,`latitude`,`longitude`,`opening_hours`,`estimated_cost`,`entrance_fee`,`is_free`,`halal_status`,`is_outdoor`,`visit_duration_min`,`best_time_to_visit`,`website_url`,`rating`,`is_active`)
SELECT
  seed.`state`, seed.`district`, seed.`category`, seed.`name`, seed.`description`, seed.`address`,
  seed.`latitude`, seed.`longitude`, seed.`opening_hours`, seed.`estimated_cost`,
  seed.`entrance_fee`, seed.`is_free`, seed.`halal_status`, seed.`is_outdoor`,
  seed.`visit_duration_min`, seed.`best_time_to_visit`, seed.`website_url`,
  seed.`rating`, seed.`is_active`
FROM `tmp_johor_meal_places` seed
LEFT JOIN `cultural_places` cp
  ON cp.`state` = seed.`state`
 AND cp.`district` = seed.`district`
 AND cp.`name` = seed.`name`
WHERE cp.`place_id` IS NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_johor_meal_places`;

DELETE FROM `cultural_places`
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Tangkak Recreational Park';

DELETE FROM `cultural_places`
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Tangkak Local Food Street';

DELETE FROM `cultural_places`
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Tangkak Beef Noodles';

UPDATE `cultural_places`
SET
  `description` = 'Johor National Park at Gunung Ledang, the highest peak in Johor and a key Tangkak nature gateway. The site is linked to the Puteri Gunung Ledang legend and is suitable for hiking, waterfall visits, and family nature stops near the foothill area.',
  `address` = 'Taman Negara Johor Gunung Ledang, Sagil, 84900 Tangkak, Johor',
  `opening_hours` = 'Daytime; hiking permits and park access should be verified before arrival',
  `estimated_cost` = 5.00,
  `entrance_fee` = 5.00,
  `is_free` = 0,
  `is_outdoor` = 1,
  `visit_duration_min` = 180,
  `best_time_to_visit` = 'Early morning',
  `website_url` = 'https://johornationalparks.gov.my/ms/gunung-ledang-getting-there/'
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Gunung Ledang National Park';

UPDATE `cultural_places`
SET
  `category` = 'nature',
  `name` = 'Port Bergambar Viral Gunung Ledang',
  `description` = 'Scenic photo route from Sagil toward the Gunung Ledang park entrance, with the mountain as the background. Suitable as a short morning or evening photo stop before or after the national park visit.',
  `address` = 'Jalan Sagil - Gunung Ledang, Sagil, 84900 Tangkak, Johor',
  `latitude` = 2.3220000,
  `longitude` = 102.6100000,
  `opening_hours` = 'Open road viewpoint; daytime recommended',
  `estimated_cost` = 0.00,
  `entrance_fee` = 0.00,
  `is_free` = 1,
  `is_outdoor` = 1,
  `visit_duration_min` = 30,
  `best_time_to_visit` = 'Morning or evening',
  `website_url` = 'https://www.google.com/maps/search/?api=1&query=Port+Bergambar+Viral+Gunung+Ledang+Tangkak'
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Ledang Cultural Story Stop';

UPDATE `cultural_places`
SET
  `name` = 'Jalan Temenggong Heritage Street',
  `description` = 'Old Tangkak street area with traditional shophouses and small-town heritage atmosphere. Suitable for a relaxed walk, photography, and pairing with nearby local food stops.',
  `address` = 'Jalan Temenggong, Bandar Tangkak, 84900 Tangkak, Johor',
  `latitude` = 2.2670000,
  `longitude` = 102.5458000,
  `opening_hours` = 'Open street; shop hours vary',
  `estimated_cost` = 0.00,
  `entrance_fee` = 0.00,
  `is_free` = 1,
  `is_outdoor` = 1,
  `visit_duration_min` = 60,
  `best_time_to_visit` = 'Morning',
  `website_url` = 'https://www.google.com/maps/search/?api=1&query=Jalan+Temenggong+Tangkak'
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Tangkak Old Town';

UPDATE `cultural_places`
SET
  `name` = 'Restoran Do Do Do Tangkak Beef Noodles',
  `description` = 'Well-known Tangkak beef brisket noodle stop, locally associated with Fei Zai beef noodles and suitable as a lunch stop in a Tangkak food route.',
  `address` = '90 and 91, Jalan Teknologi 1, Kawasan Perindustrian Tangkak, 84900 Tangkak, Johor',
  `latitude` = 2.2425670,
  `longitude` = 102.5320392,
  `opening_hours` = 'Daily 08:00-16:00; verify current hours',
  `estimated_cost` = 16.00,
  `entrance_fee` = 16.00,
  `is_free` = 0,
  `is_outdoor` = 0,
  `visit_duration_min` = 60,
  `best_time_to_visit` = 'Lunch',
  `website_url` = 'https://www.google.com/maps/search/?api=1&query=Restoran+Do+Do+Do+Tangkak+Beef+Noodles'
WHERE `state` = 'Johor'
  AND `district` = 'Tangkak'
  AND `name` = 'Restoran Do Do Do Tangkak';

DROP TEMPORARY TABLE IF EXISTS `tmp_tangkak_extra_places`;
CREATE TEMPORARY TABLE `tmp_tangkak_extra_places` (
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) NOT NULL,
  `category` VARCHAR(20) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `opening_hours` VARCHAR(1000) DEFAULT NULL,
  `estimated_cost` DECIMAL(10,2) DEFAULT NULL,
  `entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `is_free` TINYINT(1) NOT NULL DEFAULT 0,
  `halal_status` TINYINT(1) DEFAULT NULL,
  `is_outdoor` TINYINT(1) DEFAULT 0,
  `visit_duration_min` INT NOT NULL DEFAULT 60,
  `best_time_to_visit` VARCHAR(100) DEFAULT NULL,
  `dress_code_required` TINYINT(1) DEFAULT 0,
  `website_url` VARCHAR(300) DEFAULT NULL,
  `phone_number` VARCHAR(20) DEFAULT NULL,
  `rating` DECIMAL(3,2) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tmp_tangkak_extra_places`
(`state`,`district`,`category`,`name`,`description`,`address`,`latitude`,`longitude`,`opening_hours`,`estimated_cost`,`entrance_fee`,`is_free`,`halal_status`,`is_outdoor`,`visit_duration_min`,`best_time_to_visit`,`dress_code_required`,`website_url`,`phone_number`,`rating`,`is_active`)
VALUES
('Johor','Tangkak','heritage','Kian Hoon Keng Temple Tangkak','Historic Tangkak Chinese temple founded in the late 19th century, known for its restored traditional architecture and photo-friendly temple grounds. Suitable for religious culture, heritage learning, and short cultural visits.','Jalan Padang Lalang, Kampung Padang Lalang, 84900 Tangkak, Johor',2.2713000,102.5447000,'Daytime; verify temple visiting hours',0.00,0.00,1,NULL,0,60,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Kian+Hoon+Keng+Temple+Tangkak',NULL,4.50,1),
('Johor','Tangkak','food','Jia Jia Bak Kut Teh Tangkak','Local bak kut teh restaurant in Tangkak, suitable for breakfast or early lunch in a food-focused itinerary.','38, Jalan Kemajuan off Jalan Payamas, 84900 Tangkak, Johor',2.2596000,102.5503000,'Daily 07:00-14:00; verify current hours',15.00,15.00,0,0,0,60,'Breakfast or Lunch',0,'https://www.google.com/maps/search/?api=1&query=Jia+Jia+Bak+Kut+Teh+Tangkak','019-6202626',4.00,1),
('Johor','Tangkak','food','Chop Hua Bee Bakery Tangkak','Traditional Tangkak bakery and souvenir stop known for local biscuits and pastries. Suitable for takeaway snacks or gifts after visiting the town area.','10, Jalan Solok, Kampung Padang Lalang, 84900 Tangkak, Johor',2.2709000,102.5440000,'Business hours vary; verify before visit',12.00,12.00,0,NULL,0,45,'Afternoon',0,'https://www.google.com/maps/search/?api=1&query=Chop+Hua+Bee+Bakery+Tangkak','012-6352327',4.40,1);

INSERT INTO `cultural_places`
(`state`,`district`,`category`,`name`,`description`,`address`,`latitude`,`longitude`,`opening_hours`,`estimated_cost`,`entrance_fee`,`is_free`,`halal_status`,`is_outdoor`,`visit_duration_min`,`best_time_to_visit`,`dress_code_required`,`website_url`,`phone_number`,`rating`,`is_active`)
SELECT
  seed.`state`, seed.`district`, seed.`category`, seed.`name`, seed.`description`, seed.`address`,
  seed.`latitude`, seed.`longitude`, seed.`opening_hours`, seed.`estimated_cost`,
  seed.`entrance_fee`, seed.`is_free`, seed.`halal_status`, seed.`is_outdoor`,
  seed.`visit_duration_min`, seed.`best_time_to_visit`, seed.`dress_code_required`,
  seed.`website_url`, seed.`phone_number`, seed.`rating`, seed.`is_active`
FROM `tmp_tangkak_extra_places` seed
LEFT JOIN `cultural_places` cp
  ON cp.`state` = seed.`state`
 AND cp.`district` = seed.`district`
 AND cp.`name` = seed.`name`
WHERE cp.`place_id` IS NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_tangkak_extra_places`;

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
DROP PROCEDURE IF EXISTS migrate_image_path_to_image_url;

-- ============================================================
-- Done
-- ============================================================
