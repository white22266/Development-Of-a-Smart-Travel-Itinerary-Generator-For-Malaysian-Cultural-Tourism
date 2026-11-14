-- A stop's note holds the planner's reasons. 255 characters cut the longer ones short,
-- so the column is widened. Safe to run twice.
ALTER TABLE itinerary_items MODIFY notes VARCHAR(1000) DEFAULT NULL;
