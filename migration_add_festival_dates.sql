USE travel_itinerary_db;

ALTER TABLE cultural_places
  ADD COLUMN festival_start_date DATE NULL AFTER opening_hours,
  ADD COLUMN festival_end_date DATE NULL AFTER festival_start_date,
  ADD INDEX idx_cultural_festival_dates (category, festival_start_date, festival_end_date);

