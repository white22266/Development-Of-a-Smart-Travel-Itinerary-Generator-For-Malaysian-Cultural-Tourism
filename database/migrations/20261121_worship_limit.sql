-- The traveller's limit on places of worship (docs/PLANNER.md, "Place types"): NULL is no
-- preference, 'one' at most one place of worship a day, 'none' no place of worship at all. A
-- must-see place is still scheduled. When set it is a hard rule of the planner. Safe to run twice.

ALTER TABLE traveller_preferences
  ADD COLUMN IF NOT EXISTS worship_limit ENUM('one','none') NULL DEFAULT NULL AFTER accessibility_tags;
