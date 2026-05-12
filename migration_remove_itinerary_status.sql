-- Remove obsolete itinerary status field.
-- The application now treats generated itineraries as saved records directly.

SET @status_column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'itineraries'
    AND COLUMN_NAME = 'status'
);

SET @drop_status_sql := IF(
  @status_column_exists > 0,
  'ALTER TABLE `itineraries` DROP COLUMN `status`',
  'SELECT ''itineraries.status already removed'' AS message'
);

PREPARE drop_status_stmt FROM @drop_status_sql;
EXECUTE drop_status_stmt;
DEALLOCATE PREPARE drop_status_stmt;
