-- A finer type for every place than the seven categories (mosque, hindu_temple, beach, ...).
-- place_type_source says who set it: 'rule' (services/PlaceTypeClassifier.php) or 'admin' (typed in
-- the admin form; the classifier never overwrites those). Both columns are nullable, so the present
-- code keeps working. Fill the column with tools/classify_places.php. Safe to run twice.

ALTER TABLE cultural_places
  ADD COLUMN IF NOT EXISTS place_type VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS place_type_source ENUM('rule','admin') NULL;

CREATE INDEX IF NOT EXISTS idx_cultural_places_type ON cultural_places (place_type);
