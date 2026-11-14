-- Schema of the Smart Travel Itinerary Generator: structure only, no rows.
-- Written by tools/export_schema.php. Load it into an empty database, then database/seed_reference.sql.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Table admins
CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(128) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table ai_chat_logs
CREATE TABLE `ai_chat_logs` (
  `chat_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `user_message` text NOT NULL,
  `ai_response` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`chat_id`),
  KEY `idx_ai_chat_itinerary` (`itinerary_id`),
  KEY `idx_ai_chat_traveller` (`traveller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table audit_logs
CREATE TABLE `audit_logs` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_admin` (`admin_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table cultural_place_suggestions
CREATE TABLE `cultural_place_suggestions` (
  `suggestion_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(1000) DEFAULT NULL,
  `festival_start_date` date DEFAULT NULL,
  `festival_end_date` date DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(1000) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_place_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`suggestion_id`),
  KEY `idx_status` (`status`),
  KEY `idx_state_category` (`state`,`category`),
  KEY `fk_sugg_traveller` (`traveller_id`),
  KEY `fk_sugg_admin` (`approved_by_admin_id`),
  KEY `fk_suggestions_approved_place` (`approved_place_id`),
  CONSTRAINT `fk_sugg_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sugg_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_suggestions_approved_place` FOREIGN KEY (`approved_place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table cultural_places
CREATE TABLE `cultural_places` (
  `place_id` int(11) NOT NULL AUTO_INCREMENT,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(1000) DEFAULT NULL,
  `festival_start_date` date DEFAULT NULL,
  `festival_end_date` date DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `entrance_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `is_free` tinyint(1) NOT NULL DEFAULT 0,
  `image_url` varchar(1000) DEFAULT NULL,
  `halal_status` tinyint(1) DEFAULT NULL,
  `is_outdoor` tinyint(1) DEFAULT NULL,
  `visit_duration_min` int(11) NOT NULL DEFAULT 90,
  `best_time_to_visit` varchar(100) DEFAULT NULL,
  `dress_code_required` tinyint(1) DEFAULT NULL,
  `website_url` varchar(300) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `avg_rating` decimal(3,2) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`place_id`),
  KEY `idx_state_category` (`state`,`category`),
  KEY `fk_place_admin` (`created_by_admin_id`),
  KEY `idx_cultural_festival_dates` (`category`,`festival_start_date`,`festival_end_date`),
  CONSTRAINT `fk_place_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table food_places
CREATE TABLE `food_places` (
  `food_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `cuisine_type` varchar(80) DEFAULT NULL,
  `avg_price` decimal(10,2) DEFAULT 15.00,
  `rating` decimal(3,1) DEFAULT 3.5,
  `opening_hour` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`food_id`),
  KEY `idx_state` (`state`),
  KEY `idx_coords` (`latitude`,`longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table hotel_search_cache
CREATE TABLE `hotel_search_cache` (
  `cache_id` int(11) NOT NULL AUTO_INCREMENT,
  `cache_key` char(64) NOT NULL,
  `final_stop_name` varchar(255) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'unknown',
  `hotels_json` longtext NOT NULL,
  `search_count` int(11) NOT NULL DEFAULT 1,
  `last_searched_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cache_id`),
  UNIQUE KEY `cache_key` (`cache_key`),
  KEY `idx_hotel_cache_location` (`latitude`,`longitude`),
  KEY `idx_hotel_cache_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table itineraries
CREATE TABLE `itineraries` (
  `itinerary_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `preference_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `start_date` date DEFAULT NULL,
  `total_days` int(11) NOT NULL,
  `origin_name` varchar(255) DEFAULT NULL,
  `origin_lat` decimal(10,7) DEFAULT NULL,
  `origin_lng` decimal(10,7) DEFAULT NULL,
  `selected_hotel_id` int(11) DEFAULT NULL,
  `selected_hotel_name` varchar(150) DEFAULT NULL,
  `selected_hotel_nights` int(11) NOT NULL DEFAULT 0,
  `selected_hotel_total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_estimated_cost` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_name` varchar(255) DEFAULT NULL,
  `end_lat` decimal(10,7) DEFAULT NULL,
  `end_lng` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`itinerary_id`),
  KEY `fk_it_traveller` (`traveller_id`),
  KEY `fk_it_pref` (`preference_id`),
  KEY `idx_itinerary_selected_hotel` (`selected_hotel_id`),
  CONSTRAINT `fk_it_pref` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`),
  CONSTRAINT `fk_it_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table itinerary_days
CREATE TABLE `itinerary_days` (
  `itinerary_id` int(11) NOT NULL,
  `day_no` int(11) NOT NULL,
  `start_time` time DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`itinerary_id`,`day_no`),
  CONSTRAINT `fk_itinerary_days_itinerary` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table itinerary_items
CREATE TABLE `itinerary_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `day_no` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL,
  `item_type` enum('attraction','food','festival','transport','hotel','note') DEFAULT 'attraction',
  `meal_slot` enum('lunch','dinner') DEFAULT NULL,
  `place_id` int(11) DEFAULT NULL,
  `hotel_id` int(11) DEFAULT NULL,
  `item_title` varchar(150) NOT NULL,
  `item_latitude` decimal(10,7) DEFAULT NULL,
  `item_longitude` decimal(10,7) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `travel_time_min` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `idx_itinerary_day` (`itinerary_id`,`day_no`,`sequence_no`),
  KEY `idx_item_place` (`place_id`),
  KEY `idx_item_hotel` (`hotel_id`),
  CONSTRAINT `fk_item_it` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table lodgings
CREATE TABLE `lodgings` (
  `lodging_id` int(11) NOT NULL AUTO_INCREMENT,
  `osm_ref` varchar(32) NOT NULL,
  `name` varchar(200) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `stars` decimal(2,1) DEFAULT NULL,
  `state` varchar(60) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`lodging_id`),
  UNIQUE KEY `uq_lodgings_osm` (`osm_ref`),
  KEY `idx_lodgings_geo` (`latitude`,`longitude`),
  KEY `idx_lodgings_state` (`state`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table malaysia_states
CREATE TABLE `malaysia_states` (
  `state_id` int(11) NOT NULL AUTO_INCREMENT,
  `state_name` varchar(60) NOT NULL,
  PRIMARY KEY (`state_id`),
  UNIQUE KEY `uq_malaysia_state_name` (`state_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table place_change_log
CREATE TABLE `place_change_log` (
  `change_id` int(11) NOT NULL AUTO_INCREMENT,
  `place_id` int(11) NOT NULL,
  `field` varchar(40) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `source` varchar(20) NOT NULL,
  `source_ref` varchar(400) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reverted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`change_id`),
  KEY `idx_place_change_place` (`place_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table place_import_candidates
CREATE TABLE `place_import_candidates` (
  `candidate_id` int(11) NOT NULL AUTO_INCREMENT,
  `osm_ref` varchar(32) NOT NULL,
  `wikidata_id` varchar(20) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `payload_json` mediumtext NOT NULL,
  `status` enum('pending','published','rejected','duplicate','linked') NOT NULL DEFAULT 'pending',
  `published_place_id` int(11) DEFAULT NULL,
  `duplicate_of_place_id` int(11) DEFAULT NULL,
  `reviewed_by_admin_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`candidate_id`),
  UNIQUE KEY `uq_candidate_osm` (`osm_ref`),
  KEY `idx_candidate_status` (`status`,`state`,`score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table place_sources
CREATE TABLE `place_sources` (
  `place_id` int(11) NOT NULL,
  `origin` varchar(20) NOT NULL DEFAULT 'manual',
  `osm_ref` varchar(32) DEFAULT NULL,
  `wikidata_id` varchar(20) DEFAULT NULL,
  `wikipedia_url` varchar(400) DEFAULT NULL,
  `description_source` varchar(20) DEFAULT NULL,
  `image_source_url` varchar(500) DEFAULT NULL,
  `image_credit` varchar(300) DEFAULT NULL,
  `image_license` varchar(80) DEFAULT NULL,
  `wheelchair` varchar(10) DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`place_id`),
  UNIQUE KEY `uq_place_sources_osm` (`osm_ref`),
  KEY `idx_place_sources_wikidata` (`wikidata_id`),
  CONSTRAINT `fk_place_sources_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table place_sync_runs
CREATE TABLE `place_sync_runs` (
  `run_id` int(11) NOT NULL AUTO_INCREMENT,
  `state` varchar(60) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `osm_places` int(11) NOT NULL DEFAULT 0,
  `linked` int(11) NOT NULL DEFAULT 0,
  `fields_filled` int(11) NOT NULL DEFAULT 0,
  `staged` int(11) NOT NULL DEFAULT 0,
  `published` int(11) NOT NULL DEFAULT 0,
  `lodgings` int(11) NOT NULL DEFAULT 0,
  `message` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  KEY `idx_sync_runs_state` (`state`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table ratings_reviews
CREATE TABLE `ratings_reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `place_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `review_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_review_place_traveller` (`place_id`,`traveller_id`),
  KEY `idx_review_place` (`place_id`),
  KEY `idx_review_traveller` (`traveller_id`),
  CONSTRAINT `fk_reviews_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_review_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table remember_tokens
CREATE TABLE `remember_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_role` enum('traveller','admin') NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_role`,`user_id`),
  KEY `idx_remember_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table schema_migrations
CREATE TABLE `schema_migrations` (
  `file_name` varchar(190) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`file_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table shared_itineraries
CREATE TABLE `shared_itineraries` (
  `share_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`share_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_share_itinerary` (`itinerary_id`),
  CONSTRAINT `fk_shared_itinerary` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table travel_interests
CREATE TABLE `travel_interests` (
  `interest_id` int(11) NOT NULL AUTO_INCREMENT,
  `interest_code` varchar(40) NOT NULL,
  `interest_label` varchar(80) NOT NULL,
  PRIMARY KEY (`interest_id`),
  UNIQUE KEY `uq_travel_interest_code` (`interest_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table traveller_preference_interests
CREATE TABLE `traveller_preference_interests` (
  `preference_id` int(11) NOT NULL,
  `interest_id` int(11) NOT NULL,
  `weight` tinyint(3) unsigned NOT NULL DEFAULT 2,
  PRIMARY KEY (`preference_id`,`interest_id`),
  KEY `idx_tpi_interest` (`interest_id`),
  CONSTRAINT `fk_tpi_interest` FOREIGN KEY (`interest_id`) REFERENCES `travel_interests` (`interest_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table traveller_preference_places
CREATE TABLE `traveller_preference_places` (
  `preference_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  `wish` enum('must','avoid') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`preference_id`,`place_id`),
  KEY `idx_tpp_place` (`place_id`),
  CONSTRAINT `fk_tpp_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpp_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table traveller_preference_states
CREATE TABLE `traveller_preference_states` (
  `preference_id` int(11) NOT NULL,
  `state_id` int(11) NOT NULL,
  PRIMARY KEY (`preference_id`,`state_id`),
  KEY `idx_tps_state` (`state_id`),
  CONSTRAINT `fk_tps_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tps_state` FOREIGN KEY (`state_id`) REFERENCES `malaysia_states` (`state_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table traveller_preferences
CREATE TABLE `traveller_preferences` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `trip_days` int(11) NOT NULL,
  `budget` decimal(10,2) NOT NULL,
  `budget_tier` enum('budget','normal','luxury') NOT NULL DEFAULT 'normal',
  `transport_type` enum('car','public_transport','walking','motorcycle') DEFAULT 'car',
  `traveller_type` enum('solo','couple','family','group') NOT NULL DEFAULT 'solo',
  `party_size` int(11) NOT NULL DEFAULT 1,
  `travel_pace` enum('relaxed','normal','packed') NOT NULL DEFAULT 'normal',
  `dietary_preference` enum('none','halal','vegetarian') NOT NULL DEFAULT 'none',
  `preferred_visit_time` enum('any','morning','afternoon','evening') NOT NULL DEFAULT 'any',
  `accessibility_needs` varchar(120) DEFAULT NULL,
  `interests` set('culture','food','nature','shopping','museum','heritage','festival') NOT NULL,
  `preferred_states` varchar(255) DEFAULT NULL,
  `preferred_districts` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `day_start_time` time DEFAULT NULL,
  `day_end_time` time DEFAULT NULL,
  `stops_per_day` tinyint(3) unsigned DEFAULT NULL,
  `adults` tinyint(3) unsigned DEFAULT NULL,
  `children` tinyint(3) unsigned DEFAULT NULL,
  `seniors` tinyint(3) unsigned DEFAULT NULL,
  `accessibility_tags` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`preference_id`),
  KEY `fk_pref_traveller` (`traveller_id`),
  CONSTRAINT `fk_pref_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table travellers
CREATE TABLE `travellers` (
  `traveller_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `activation_token` varchar(128) DEFAULT NULL,
  `activation_expires` datetime DEFAULT NULL,
  `reset_token` varchar(128) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`traveller_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
