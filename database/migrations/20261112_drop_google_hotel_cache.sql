-- Hotel suggestions now come from the lodgings table (filled from OpenStreetMap by
-- tools/sync_places.php). The cache of Google Places results is no longer read or written.
-- Safe to re-run.
DROP TABLE IF EXISTS hotel_search_cache;
