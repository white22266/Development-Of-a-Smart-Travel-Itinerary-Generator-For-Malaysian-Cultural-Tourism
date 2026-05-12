-- Supervisor alignment patch: personalization fields + normalized preference junction tables.
-- Safe to run more than once.

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

CALL add_column_if_missing('traveller_preferences', 'traveller_type', "`traveller_type` ENUM('solo','couple','family','group') NOT NULL DEFAULT 'solo' AFTER `transport_type`");
CALL add_column_if_missing('traveller_preferences', 'travel_pace', "`travel_pace` ENUM('relaxed','normal','packed') NOT NULL DEFAULT 'normal' AFTER `traveller_type`");
CALL add_column_if_missing('traveller_preferences', 'budget_tier', "`budget_tier` ENUM('budget','normal','luxury') NOT NULL DEFAULT 'normal' AFTER `budget`");
CALL add_column_if_missing('traveller_preferences', 'dietary_preference', "`dietary_preference` ENUM('none','halal','vegetarian') NOT NULL DEFAULT 'none' AFTER `travel_pace`");
CALL add_column_if_missing('traveller_preferences', 'preferred_visit_time', "`preferred_visit_time` ENUM('any','morning','afternoon','evening') NOT NULL DEFAULT 'any' AFTER `dietary_preference`");
CALL add_column_if_missing('traveller_preferences', 'accessibility_needs', "`accessibility_needs` VARCHAR(120) DEFAULT NULL AFTER `preferred_visit_time`");

CREATE TABLE IF NOT EXISTS `travel_interests` (
  `interest_id` INT NOT NULL AUTO_INCREMENT,
  `interest_code` VARCHAR(40) NOT NULL,
  `interest_label` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`interest_id`),
  UNIQUE KEY `uq_travel_interest_code` (`interest_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `travel_interests` (`interest_code`, `interest_label`) VALUES
('culture', 'Culture'),
('heritage', 'Heritage'),
('food', 'Food'),
('museum', 'Museum'),
('nature', 'Nature'),
('shopping', 'Shopping'),
('festival', 'Festival');

CREATE TABLE IF NOT EXISTS `malaysia_states` (
  `state_id` INT NOT NULL AUTO_INCREMENT,
  `state_name` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`state_id`),
  UNIQUE KEY `uq_malaysia_state_name` (`state_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `malaysia_states` (`state_name`) VALUES
('Johor'), ('Kedah'), ('Kelantan'), ('Melaka'), ('Negeri Sembilan'), ('Pahang'),
('Penang'), ('Perak'), ('Perlis'), ('Sabah'), ('Sarawak'), ('Selangor'),
('Terengganu'), ('Kuala Lumpur'), ('Putrajaya'), ('Labuan');

CREATE TABLE IF NOT EXISTS `traveller_preference_interests` (
  `preference_id` INT NOT NULL,
  `interest_id` INT NOT NULL,
  PRIMARY KEY (`preference_id`, `interest_id`),
  KEY `idx_tpi_interest` (`interest_id`),
  CONSTRAINT `fk_tpi_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_interest` FOREIGN KEY (`interest_id`) REFERENCES `travel_interests` (`interest_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `traveller_preference_states` (
  `preference_id` INT NOT NULL,
  `state_id` INT NOT NULL,
  PRIMARY KEY (`preference_id`, `state_id`),
  KEY `idx_tps_state` (`state_id`),
  CONSTRAINT `fk_tps_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tps_state` FOREIGN KEY (`state_id`) REFERENCES `malaysia_states` (`state_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `traveller_preference_interests` (`preference_id`, `interest_id`)
SELECT pi.preference_id, ti.interest_id
FROM `preference_interests` pi
JOIN `travel_interests` ti ON ti.interest_code = pi.interest;

INSERT IGNORE INTO `traveller_preference_interests` (`preference_id`, `interest_id`)
SELECT tp.preference_id, ti.interest_id
FROM `traveller_preferences` tp
JOIN `travel_interests` ti ON FIND_IN_SET(ti.interest_code, tp.interests);

INSERT IGNORE INTO `traveller_preference_states` (`preference_id`, `state_id`)
SELECT ps.preference_id, ms.state_id
FROM `preference_states` ps
JOIN `malaysia_states` ms ON ms.state_name = ps.state;

INSERT IGNORE INTO `traveller_preference_states` (`preference_id`, `state_id`)
SELECT tp.preference_id, ms.state_id
FROM `traveller_preferences` tp
JOIN `malaysia_states` ms ON FIND_IN_SET(ms.state_name, tp.preferred_states);

DROP PROCEDURE IF EXISTS add_column_if_missing;
