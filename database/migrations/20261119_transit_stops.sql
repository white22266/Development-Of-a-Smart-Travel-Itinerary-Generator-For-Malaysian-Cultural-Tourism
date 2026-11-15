-- Public transport stops from OpenStreetMap (rail stations, bus stops, ferry terminals) and,
-- for every place, the straight-line distance to the nearest stop. Filled by
-- tools/sync_transit_stops.php. NULL distance = not worked out yet (transit_checked_at is NULL)
-- or no stop within the search box. Safe to run twice.

CREATE TABLE IF NOT EXISTS transit_stops (
  stop_id INT(11) NOT NULL AUTO_INCREMENT,
  osm_type ENUM('node','way','relation') NOT NULL,
  osm_id BIGINT(20) NOT NULL,
  name VARCHAR(200) NOT NULL DEFAULT '',
  kind ENUM('rail','bus','ferry') NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  state VARCHAR(60) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (stop_id),
  UNIQUE KEY uq_transit_osm (osm_type, osm_id),
  KEY idx_transit_lat_lng (latitude, longitude),
  KEY idx_transit_state_kind (state, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE cultural_places
  ADD COLUMN IF NOT EXISTS nearest_stop_m INT(11) NULL,
  ADD COLUMN IF NOT EXISTS nearest_rail_m INT(11) NULL,
  ADD COLUMN IF NOT EXISTS transit_checked_at DATETIME NULL;
