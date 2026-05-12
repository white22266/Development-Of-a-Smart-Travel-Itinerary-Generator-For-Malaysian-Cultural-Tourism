-- Lightweight supervisor features: remember-me and account verification support.
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

CALL add_column_if_missing('travellers', 'is_active', "`is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `phone`");
CALL add_column_if_missing('travellers', 'activation_token', "`activation_token` VARCHAR(100) DEFAULT NULL AFTER `is_active`");
CALL add_column_if_missing('travellers', 'activation_expires', "`activation_expires` DATETIME DEFAULT NULL AFTER `activation_token`");
CALL add_column_if_missing('travellers', 'reset_token', "`reset_token` VARCHAR(100) DEFAULT NULL AFTER `activation_expires`");
CALL add_column_if_missing('travellers', 'reset_expires', "`reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`");
CALL add_column_if_missing('admins', 'reset_token', "`reset_token` VARCHAR(100) DEFAULT NULL AFTER `password_hash`");
CALL add_column_if_missing('admins', 'reset_expires', "`reset_expires` DATETIME DEFAULT NULL AFTER `reset_token`");

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `token_id` INT NOT NULL AUTO_INCREMENT,
  `user_role` ENUM('traveller','admin') NOT NULL,
  `user_id` INT NOT NULL,
  `selector` VARCHAR(32) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_role`, `user_id`),
  KEY `idx_remember_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE `travellers` SET `is_active` = 1 WHERE `is_active` IS NULL;

DROP PROCEDURE IF EXISTS add_column_if_missing;
