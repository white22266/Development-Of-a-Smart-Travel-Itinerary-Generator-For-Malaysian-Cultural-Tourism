-- Supervisor Must-Fix Migration
-- Run this after the existing database import/update scripts.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

ALTER TABLE `cultural_places`
  MODIFY COLUMN `opening_hours` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `image_url` VARCHAR(1000) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `entrance_fee` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `estimated_cost`,
  ADD COLUMN IF NOT EXISTS `is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entrance_fee`,
  ADD COLUMN IF NOT EXISTS `halal_status` TINYINT(1) DEFAULT NULL AFTER `image_path`,
  ADD COLUMN IF NOT EXISTS `is_outdoor` TINYINT(1) DEFAULT NULL AFTER `halal_status`,
  ADD COLUMN IF NOT EXISTS `visit_duration_min` INT NOT NULL DEFAULT 90 AFTER `is_outdoor`,
  ADD COLUMN IF NOT EXISTS `best_time_to_visit` VARCHAR(100) DEFAULT NULL AFTER `visit_duration_min`,
  ADD COLUMN IF NOT EXISTS `dress_code_required` TINYINT(1) DEFAULT NULL AFTER `best_time_to_visit`,
  ADD COLUMN IF NOT EXISTS `website_url` VARCHAR(300) DEFAULT NULL AFTER `dress_code_required`,
  ADD COLUMN IF NOT EXISTS `phone_number` VARCHAR(20) DEFAULT NULL AFTER `website_url`,
  ADD COLUMN IF NOT EXISTS `avg_rating` DECIMAL(3,2) DEFAULT NULL AFTER `phone_number`;

ALTER TABLE `cultural_place_suggestions`
  MODIFY COLUMN `opening_hours` VARCHAR(1000) DEFAULT NULL,
  MODIFY COLUMN `image_url` VARCHAR(1000) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `district` VARCHAR(80) DEFAULT NULL AFTER `state`;

UPDATE `cultural_places`
SET
  `entrance_fee` = COALESCE(`entrance_fee`, `estimated_cost`, 0.00),
  `is_free` = CASE WHEN COALESCE(`entrance_fee`, `estimated_cost`, 0.00) <= 0 THEN 1 ELSE 0 END,
  `visit_duration_min` = CASE
    WHEN `visit_duration_min` IS NULL OR `visit_duration_min` <= 0 THEN
      CASE
        WHEN `category` = 'food' THEN 60
        WHEN `category` = 'festival' THEN 150
        WHEN `category` IN ('culture', 'heritage', 'museum', 'nature') THEN 120
        ELSE 90
      END
    ELSE `visit_duration_min`
  END;

CREATE TABLE IF NOT EXISTS `place_images` (
  `image_id` INT NOT NULL AUTO_INCREMENT,
  `place_id` INT NOT NULL,
  `image_url` VARCHAR(500) NOT NULL,
  `caption` VARCHAR(150) DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`image_id`),
  KEY `idx_place_images_place` (`place_id`),
  CONSTRAINT `fk_place_images_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ratings_reviews` (
  `review_id` INT NOT NULL AUTO_INCREMENT,
  `place_id` INT NOT NULL,
  `traveller_id` INT NOT NULL,
  `rating` TINYINT NOT NULL,
  `review_text` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_review_place_traveller` (`place_id`, `traveller_id`),
  KEY `idx_review_place` (`place_id`),
  KEY `idx_review_traveller` (`traveller_id`),
  CONSTRAINT `fk_reviews_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_review_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `wishlists` (
  `wishlist_id` INT NOT NULL AUTO_INCREMENT,
  `traveller_id` INT NOT NULL,
  `place_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`wishlist_id`),
  UNIQUE KEY `uq_wishlist_traveller_place` (`traveller_id`, `place_id`),
  KEY `idx_wishlist_place` (`place_id`),
  CONSTRAINT `fk_wishlist_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `shared_itineraries` (
  `share_id` INT NOT NULL AUTO_INCREMENT,
  `itinerary_id` INT NOT NULL,
  `share_token` VARCHAR(64) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`share_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_share_itinerary` (`itinerary_id`),
  CONSTRAINT `fk_shared_itinerary` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE
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
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `place_images` (`place_id`, `image_url`, `is_primary`)
SELECT `place_id`, `image_url`, 1
FROM `cultural_places`
WHERE `image_url` IS NOT NULL
  AND `image_url` <> ''
  AND NOT EXISTS (
    SELECT 1 FROM `place_images`
    WHERE `place_images`.`place_id` = `cultural_places`.`place_id`
      AND `place_images`.`image_url` = `cultural_places`.`image_url`
  );

SET @has_idx_item_place := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'itinerary_items'
    AND INDEX_NAME = 'idx_item_place'
);
SET @sql := IF(
  @has_idx_item_place = 0,
  'ALTER TABLE `itinerary_items` ADD KEY `idx_item_place` (`place_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk_item_place := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'itinerary_items'
    AND CONSTRAINT_NAME = 'fk_item_place'
);
SET @sql := IF(
  @has_fk_item_place = 0,
  'ALTER TABLE `itinerary_items` ADD CONSTRAINT `fk_item_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
