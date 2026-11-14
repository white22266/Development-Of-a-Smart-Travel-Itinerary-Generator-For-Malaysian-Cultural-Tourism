-- Per-day settings of a stored itinerary. start_time: the time the traveller chose for that
-- day to begin (ItineraryEditService::setDayStart()); every later re-timing of the day starts
-- there instead of at the start of the preference's day window. A day without a row starts
-- as before. Rows go with their itinerary. Safe to re-run.

CREATE TABLE IF NOT EXISTS itinerary_days (
  itinerary_id INT(11) NOT NULL,
  day_no INT(11) NOT NULL,
  start_time TIME NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (itinerary_id, day_no),
  CONSTRAINT fk_itinerary_days_itinerary FOREIGN KEY (itinerary_id)
    REFERENCES itineraries (itinerary_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
