-- Planner inputs: day window, stops per day, party mix, accessibility tags, interest weights,
-- must-see / avoid places, and an optional end point of the trip.
-- Every column is nullable or has a default, so the present code keeps working.
-- Safe to re-run.

ALTER TABLE traveller_preferences
  ADD COLUMN IF NOT EXISTS day_start_time TIME NULL,
  ADD COLUMN IF NOT EXISTS day_end_time TIME NULL,
  ADD COLUMN IF NOT EXISTS stops_per_day TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS adults TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS children TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS seniors TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS accessibility_tags VARCHAR(120) NULL;

-- 1 = a little, 2 = like, 3 = love
ALTER TABLE traveller_preference_interests
  ADD COLUMN IF NOT EXISTS weight TINYINT UNSIGNED NOT NULL DEFAULT 2;

CREATE TABLE IF NOT EXISTS traveller_preference_places (
  preference_id INT(11) NOT NULL,
  place_id INT(11) NOT NULL,
  wish ENUM('must','avoid') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (preference_id, place_id),
  KEY idx_tpp_place (place_id),
  CONSTRAINT fk_tpp_preference FOREIGN KEY (preference_id) REFERENCES traveller_preferences (preference_id) ON DELETE CASCADE,
  CONSTRAINT fk_tpp_place FOREIGN KEY (place_id) REFERENCES cultural_places (place_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE itineraries
  ADD COLUMN IF NOT EXISTS end_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS end_lat DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS end_lng DECIMAL(10,7) NULL;
