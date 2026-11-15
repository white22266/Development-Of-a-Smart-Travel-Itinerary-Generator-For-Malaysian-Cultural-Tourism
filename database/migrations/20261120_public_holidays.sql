-- Public holidays that the admin keeps (admin/admin_holidays.php). Malaysia's moving holidays
-- (Chinese New Year, Hari Raya, Wesak, Deepavali, state rulers' birthdays and so on) are entered
-- from the official announcement; PlannerCalendar::loadHolidays reads them. states is NULL for
-- the whole country, otherwise a comma-separated list of state names as in
-- OsmDataService::STATE_ISO. Safe to run twice.

CREATE TABLE IF NOT EXISTS public_holidays (
  holiday_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL,
  states VARCHAR(255) NULL DEFAULT NULL,
  created_by_admin_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (holiday_id),
  UNIQUE KEY uq_public_holidays_date_name (holiday_date, name),
  KEY idx_public_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
