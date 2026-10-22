-- Festival dates on traveller place suggestions.
-- travel_itinerary_db.sql already contains these columns; run this only on an
-- older database. Safe to re-run (IF NOT EXISTS).

ALTER TABLE cultural_place_suggestions
  ADD COLUMN IF NOT EXISTS festival_start_date DATE NULL AFTER opening_hours,
  ADD COLUMN IF NOT EXISTS festival_end_date DATE NULL AFTER festival_start_date;

ALTER TABLE cultural_places
  ADD COLUMN IF NOT EXISTS festival_start_date DATE NULL AFTER opening_hours,
  ADD COLUMN IF NOT EXISTS festival_end_date DATE NULL AFTER festival_start_date;
