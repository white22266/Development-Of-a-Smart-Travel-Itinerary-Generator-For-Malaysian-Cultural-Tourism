-- Supervisor Items Migration
-- Adds normalized preference junction tables and fields used by bulk import,
-- duplicate checks, map pin suggestions, reviews, and hotel costing.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

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

CALL add_column_if_missing('cultural_places', 'district', "`district` VARCHAR(80) DEFAULT NULL AFTER `state`");
CALL add_column_if_missing('cultural_places', 'entrance_fee', "`entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `estimated_cost`");
CALL add_column_if_missing('cultural_places', 'is_free', "`is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entrance_fee`");
CALL add_column_if_missing('cultural_places', 'website_url', "`website_url` VARCHAR(300) DEFAULT NULL");
CALL add_column_if_missing('cultural_places', 'phone_number', "`phone_number` VARCHAR(30) DEFAULT NULL");
CALL add_column_if_missing('cultural_places', 'avg_rating', "`avg_rating` DECIMAL(3,2) DEFAULT NULL");
CALL add_column_if_missing('cultural_places', 'rating', "`rating` DECIMAL(3,2) DEFAULT NULL");

CALL add_column_if_missing('cultural_place_suggestions', 'district', "`district` VARCHAR(80) DEFAULT NULL AFTER `state`");

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

UPDATE cultural_places
SET entrance_fee = COALESCE(estimated_cost, 0),
    is_free = CASE WHEN COALESCE(estimated_cost, 0) <= 0 THEN 1 ELSE 0 END
WHERE entrance_fee = 0 OR entrance_fee IS NULL;

UPDATE cultural_places
SET avg_rating = COALESCE(avg_rating, rating),
    rating = COALESCE(rating, avg_rating)
WHERE avg_rating IS NOT NULL OR rating IS NOT NULL;

DROP PROCEDURE IF EXISTS add_column_if_missing;
