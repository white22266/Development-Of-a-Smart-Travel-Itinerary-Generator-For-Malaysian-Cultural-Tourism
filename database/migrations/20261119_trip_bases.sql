-- Where the traveller sleeps (docs/PLANNER.md, "Trip layout"). itinerary_days gets the base of
-- each day: the area the day belongs to and where the night after it is spent (name and
-- coordinates; the last day keeps the base of its area, though no night follows), and the day's
-- group: the bearings (degrees clockwise from north, from and to) of the sector around the base
-- that the day's places were drawn from, and the radius of the area in km. itineraries gets
-- stay_at_start: the traveller sleeps at the starting point every night. Safe to run twice.

ALTER TABLE itinerary_days
  ADD COLUMN IF NOT EXISTS base_name VARCHAR(255) NULL DEFAULT NULL AFTER start_time,
  ADD COLUMN IF NOT EXISTS base_lat DECIMAL(10,7) NULL DEFAULT NULL AFTER base_name,
  ADD COLUMN IF NOT EXISTS base_lng DECIMAL(10,7) NULL DEFAULT NULL AFTER base_lat,
  ADD COLUMN IF NOT EXISTS group_from SMALLINT NULL DEFAULT NULL AFTER base_lng,
  ADD COLUMN IF NOT EXISTS group_to SMALLINT NULL DEFAULT NULL AFTER group_from,
  ADD COLUMN IF NOT EXISTS area_km DECIMAL(6,2) NULL DEFAULT NULL AFTER group_to;

ALTER TABLE itineraries
  ADD COLUMN IF NOT EXISTS stay_at_start TINYINT(1) NOT NULL DEFAULT 0 AFTER end_lng;
