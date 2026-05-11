CREATE TABLE IF NOT EXISTS `ai_itinerary_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `destination` VARCHAR(120) NOT NULL,
  `start_location` VARCHAR(180) NOT NULL,
  `days` INT NOT NULL,
  `budget` VARCHAR(80) NOT NULL,
  `preferences` MEDIUMTEXT NOT NULL,
  `ai_response` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_itinerary_user` (`user_id`),
  KEY `idx_ai_itinerary_destination` (`destination`),
  KEY `idx_ai_itinerary_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
