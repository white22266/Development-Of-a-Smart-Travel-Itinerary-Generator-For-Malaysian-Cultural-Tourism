-- Reference rows: states, travel interests and the migration files already applied.
-- Written by tools/export_schema.php. Load it after database/schema.sql.

SET NAMES utf8mb4;

-- malaysia_states
INSERT IGNORE INTO `malaysia_states` (`state_id`, `state_name`) VALUES
  ('1', 'Johor'),
  ('2', 'Kedah'),
  ('3', 'Kelantan'),
  ('4', 'Melaka'),
  ('5', 'Negeri Sembilan'),
  ('6', 'Pahang'),
  ('7', 'Penang'),
  ('8', 'Perak'),
  ('9', 'Perlis'),
  ('10', 'Sabah'),
  ('11', 'Sarawak'),
  ('12', 'Selangor'),
  ('13', 'Terengganu'),
  ('14', 'Kuala Lumpur'),
  ('15', 'Putrajaya'),
  ('16', 'Labuan');

-- travel_interests
INSERT IGNORE INTO `travel_interests` (`interest_id`, `interest_code`, `interest_label`) VALUES
  ('1', 'culture', 'Culture'),
  ('2', 'heritage', 'Heritage'),
  ('3', 'food', 'Food'),
  ('4', 'museum', 'Museum'),
  ('5', 'nature', 'Nature'),
  ('6', 'shopping', 'Shopping'),
  ('7', 'festival', 'Festival');

-- schema_migrations
INSERT IGNORE INTO `schema_migrations` (`file_name`) VALUES
  ('20260605_part2_strict_festival_and_party_size.sql'),
  ('20260618_festival_dates_on_suggestions.sql'),
  ('20261111_open_place_data.sql'),
  ('20261112_drop_google_hotel_cache.sql'),
  ('20261115_planner_inputs.sql'),
  ('20261116_meal_slots.sql'),
  ('20261117_itinerary_days.sql'),
  ('20261118_longer_item_notes.sql');

