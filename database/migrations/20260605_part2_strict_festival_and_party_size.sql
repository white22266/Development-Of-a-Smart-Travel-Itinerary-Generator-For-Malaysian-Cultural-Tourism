-- Part 2 migration: strict festival dates and party_size schema
-- Apply this migration before using the updated Preference Form and planner.

START TRANSACTION;

-- party_size must be managed by schema migration, never by a web request.
ALTER TABLE traveller_preferences
    ADD COLUMN IF NOT EXISTS party_size INT NOT NULL DEFAULT 1 AFTER traveller_type;

UPDATE traveller_preferences
SET party_size = CASE LOWER(TRIM(traveller_type))
    WHEN 'couple' THEN 2
    WHEN 'family' THEN 4
    WHEN 'group' THEN 5
    ELSE 1
END
WHERE party_size IS NULL OR party_size < 1;

ALTER TABLE traveller_preferences
    ADD CONSTRAINT IF NOT EXISTS chk_traveller_preferences_party_size
    CHECK (party_size BETWEEN 1 AND 1000);

-- A festival without verified start and end dates must not be recommended.
UPDATE cultural_places
SET is_active = 0
WHERE LOWER(TRIM(category)) = 'festival'
  AND (
      festival_start_date IS NULL
      OR festival_end_date IS NULL
      OR festival_start_date = '0000-00-00'
      OR festival_end_date = '0000-00-00'
      OR festival_end_date < festival_start_date
  );

COMMIT;

-- Enforce the same rule for future inserts and updates.
DROP TRIGGER IF EXISTS trg_cultural_places_festival_date_insert;
DROP TRIGGER IF EXISTS trg_cultural_places_festival_date_update;

DELIMITER $$

CREATE TRIGGER trg_cultural_places_festival_date_insert
BEFORE INSERT ON cultural_places
FOR EACH ROW
BEGIN
    IF LOWER(TRIM(COALESCE(NEW.category, ''))) = 'festival'
       AND (
           NEW.festival_start_date IS NULL
           OR NEW.festival_end_date IS NULL
           OR NEW.festival_start_date = '0000-00-00'
           OR NEW.festival_end_date = '0000-00-00'
           OR NEW.festival_end_date < NEW.festival_start_date
       ) THEN
        SET NEW.is_active = 0;
    END IF;
END$$

CREATE TRIGGER trg_cultural_places_festival_date_update
BEFORE UPDATE ON cultural_places
FOR EACH ROW
BEGIN
    IF LOWER(TRIM(COALESCE(NEW.category, ''))) = 'festival'
       AND (
           NEW.festival_start_date IS NULL
           OR NEW.festival_end_date IS NULL
           OR NEW.festival_start_date = '0000-00-00'
           OR NEW.festival_end_date = '0000-00-00'
           OR NEW.festival_end_date < NEW.festival_start_date
       ) THEN
        SET NEW.is_active = 0;
    END IF;
END$$

DELIMITER ;
