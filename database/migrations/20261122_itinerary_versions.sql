-- Alternative versions of a plan (docs/PLANNER.md, "Versions of a plan"). plan_style is the style
-- the itinerary was planned in (relaxed, budget or interest; NULL for the usual plan). version_of
-- marks an itinerary as a version of another one, made on request on the review page; such a row
-- is not one of the traveller's trips until it is chosen, when version_of is set back to NULL. A
-- version goes with the itinerary it belongs to (ON DELETE CASCADE). Safe to run twice.

ALTER TABLE itineraries
  ADD COLUMN IF NOT EXISTS plan_style VARCHAR(16) NULL DEFAULT NULL AFTER stay_at_start,
  ADD COLUMN IF NOT EXISTS version_of INT(11) NULL DEFAULT NULL AFTER plan_style,
  ADD INDEX IF NOT EXISTS idx_itinerary_version_of (version_of);

ALTER TABLE itineraries
  ADD CONSTRAINT fk_itinerary_version_of FOREIGN KEY IF NOT EXISTS (version_of) REFERENCES itineraries (itinerary_id) ON DELETE CASCADE;
