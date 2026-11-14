-- Meal slots: an itinerary row that is a lunch or a dinner. Meal rows keep item_type 'food';
-- meal_slot says which meal it is. A meal at a place has its place_id, coordinates and legs;
-- a meal break without a place has place_id, coordinates, distance_km and travel_time_min
-- NULL. Visits (also visits to food places) keep meal_slot NULL.
-- The column is nullable, so the present code keeps working. Safe to re-run.

ALTER TABLE itinerary_items
  ADD COLUMN IF NOT EXISTS meal_slot ENUM('lunch','dinner') NULL DEFAULT NULL AFTER item_type;
