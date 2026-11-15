-- A short note stored with a day of an itinerary (docs/PLANNER.md, "Rules about time"): today the
-- public holiday a trip date falls on in the state of that day, with the warning that opening
-- hours may differ. The itinerary page shows it under the day's heading. Safe to run twice.

ALTER TABLE itinerary_days
  ADD COLUMN IF NOT EXISTS day_note VARCHAR(255) NULL DEFAULT NULL AFTER area_km;
