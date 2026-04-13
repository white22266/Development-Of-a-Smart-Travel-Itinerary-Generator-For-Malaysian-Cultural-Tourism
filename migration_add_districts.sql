-- ============================================================
-- migration_add_districts.sql
-- Adds district-level granularity to the Smart Travel
-- Itinerary Generator for Malaysian Cultural Tourism.
--
-- Run AFTER travel_itinerary_db.sql and migration_hotels_food.sql
-- ============================================================

-- ============================================================
-- 1. Add `district` column to existing tables
-- ============================================================

ALTER TABLE `cultural_places`
  ADD COLUMN `district` varchar(80) DEFAULT NULL AFTER `state`;

ALTER TABLE `hotels`
  ADD COLUMN `district` varchar(80) DEFAULT NULL AFTER `state`;

ALTER TABLE `food_places`
  ADD COLUMN `district` varchar(80) DEFAULT NULL AFTER `state`;

-- Add `preferred_districts` to traveller_preferences
ALTER TABLE `traveller_preferences`
  ADD COLUMN `preferred_districts` varchar(500) DEFAULT NULL AFTER `preferred_states`;

-- ============================================================
-- 2. Create malaysia_districts reference table
-- ============================================================

CREATE TABLE IF NOT EXISTS `malaysia_districts` (
  `district_id`  int(11)      NOT NULL AUTO_INCREMENT,
  `state`        varchar(60)  NOT NULL,
  `district`     varchar(80)  NOT NULL,
  PRIMARY KEY (`district_id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 3. Seed all districts for all 16 states / federal territories
-- ============================================================

INSERT INTO `malaysia_districts` (`state`, `district`) VALUES

-- Johor (10 districts)
('Johor', 'Johor Bahru'),
('Johor', 'Kluang'),
('Johor', 'Kota Tinggi'),
('Johor', 'Mersing'),
('Johor', 'Muar'),
('Johor', 'Batu Pahat'),
('Johor', 'Pontian'),
('Johor', 'Segamat'),
('Johor', 'Kulai'),
('Johor', 'Tangkak'),

-- Kedah (12 districts)
('Kedah', 'Kota Setar'),
('Kedah', 'Kubang Pasu'),
('Kedah', 'Padang Terap'),
('Kedah', 'Sik'),
('Kedah', 'Baling'),
('Kedah', 'Kulim'),
('Kedah', 'Bandar Baharu'),
('Kedah', 'Kuala Muda'),
('Kedah', 'Yan'),
('Kedah', 'Langkawi'),
('Kedah', 'Pokok Sena'),
('Kedah', 'Pendang'),

-- Kelantan (10 districts)
('Kelantan', 'Kota Bharu'),
('Kelantan', 'Bachok'),
('Kelantan', 'Pasir Mas'),
('Kelantan', 'Tumpat'),
('Kelantan', 'Pasir Puteh'),
('Kelantan', 'Machang'),
('Kelantan', 'Tanah Merah'),
('Kelantan', 'Kuala Krai'),
('Kelantan', 'Gua Musang'),
('Kelantan', 'Jeli'),

-- Melaka (3 districts)
('Melaka', 'Melaka Tengah'),
('Melaka', 'Alor Gajah'),
('Melaka', 'Jasin'),

-- Negeri Sembilan (7 districts)
('Negeri Sembilan', 'Seremban'),
('Negeri Sembilan', 'Port Dickson'),
('Negeri Sembilan', 'Rembau'),
('Negeri Sembilan', 'Tampin'),
('Negeri Sembilan', 'Jempol'),
('Negeri Sembilan', 'Jelebu'),
('Negeri Sembilan', 'Kuala Pilah'),

-- Pahang (11 districts)
('Pahang', 'Kuantan'),
('Pahang', 'Temerloh'),
('Pahang', 'Bentong'),
('Pahang', 'Cameron Highlands'),
('Pahang', 'Raub'),
('Pahang', 'Jerantut'),
('Pahang', 'Lipis'),
('Pahang', 'Maran'),
('Pahang', 'Bera'),
('Pahang', 'Rompin'),
('Pahang', 'Pekan'),

-- Penang (2 districts)
('Penang', 'Timur Laut'),
('Penang', 'Barat Daya'),
('Penang', 'Seberang Perai Utara'),
('Penang', 'Seberang Perai Tengah'),
('Penang', 'Seberang Perai Selatan'),

-- Perak (10 districts)
('Perak', 'Ipoh'),
('Perak', 'Kinta'),
('Perak', 'Larut, Matang & Selama'),
('Perak', 'Manjung'),
('Perak', 'Kerian'),
('Perak', 'Hilir Perak'),
('Perak', 'Hulu Perak'),
('Perak', 'Batang Padang'),
('Perak', 'Perak Tengah'),
('Perak', 'Kampar'),

-- Perlis (1 district)
('Perlis', 'Kangar'),
('Perlis', 'Arau'),
('Perlis', 'Padang Besar'),

-- Sabah (25 districts)
('Sabah', 'Kota Kinabalu'),
('Sabah', 'Sandakan'),
('Sabah', 'Tawau'),
('Sabah', 'Lahad Datu'),
('Sabah', 'Keningau'),
('Sabah', 'Semporna'),
('Sabah', 'Kunak'),
('Sabah', 'Papar'),
('Sabah', 'Beaufort'),
('Sabah', 'Kota Belud'),
('Sabah', 'Ranau'),
('Sabah', 'Kudat'),
('Sabah', 'Kinabatangan'),
('Sabah', 'Tuaran'),
('Sabah', 'Penampang'),
('Sabah', 'Putatan'),
('Sabah', 'Sipitang'),
('Sabah', 'Tambunan'),
('Sabah', 'Nabawan'),
('Sabah', 'Tongod'),
('Sabah', 'Beluran'),
('Sabah', 'Kota Marudu'),
('Sabah', 'Pitas'),
('Sabah', 'Tenom'),
('Sabah', 'Kuala Penyu'),

-- Sarawak (12 divisions / major districts)
('Sarawak', 'Kuching'),
('Sarawak', 'Miri'),
('Sarawak', 'Sibu'),
('Sarawak', 'Bintulu'),
('Sarawak', 'Sri Aman'),
('Sarawak', 'Sarikei'),
('Sarawak', 'Kapit'),
('Sarawak', 'Limbang'),
('Sarawak', 'Mukah'),
('Sarawak', 'Betong'),
('Sarawak', 'Serian'),
('Sarawak', 'Kota Samarahan'),

-- Selangor (9 districts)
('Selangor', 'Petaling Jaya'),
('Selangor', 'Shah Alam'),
('Selangor', 'Klang'),
('Selangor', 'Subang Jaya'),
('Selangor', 'Gombak'),
('Selangor', 'Hulu Langat'),
('Selangor', 'Hulu Selangor'),
('Selangor', 'Kuala Langat'),
('Selangor', 'Sabak Bernam'),

-- Terengganu (7 districts)
('Terengganu', 'Kuala Terengganu'),
('Terengganu', 'Kemaman'),
('Terengganu', 'Dungun'),
('Terengganu', 'Besut'),
('Terengganu', 'Setiu'),
('Terengganu', 'Hulu Terengganu'),
('Terengganu', 'Marang'),

-- Kuala Lumpur (11 districts / sub-areas)
('Kuala Lumpur', 'City Centre (KLCC)'),
('Kuala Lumpur', 'Chow Kit'),
('Kuala Lumpur', 'Brickfields'),
('Kuala Lumpur', 'Bangsar'),
('Kuala Lumpur', 'Cheras'),
('Kuala Lumpur', 'Kepong'),
('Kuala Lumpur', 'Setapak'),
('Kuala Lumpur', 'Wangsa Maju'),
('Kuala Lumpur', 'Titiwangsa'),
('Kuala Lumpur', 'Bukit Jalil'),
('Kuala Lumpur', 'Segambut'),

-- Putrajaya
('Putrajaya', 'Putrajaya'),

-- Labuan
('Labuan', 'Victoria'),
('Labuan', 'Labuan Town');

-- ============================================================
-- 4. Update existing cultural_places rows with districts
--    (best-effort based on address / state)
-- ============================================================

-- Johor rows
UPDATE `cultural_places` SET `district` = 'Johor Bahru'
  WHERE `state` = 'Johor' AND `address` LIKE '%Johor Bahru%';
UPDATE `cultural_places` SET `district` = 'Muar'
  WHERE `state` = 'Johor' AND `address` LIKE '%Muar%';
UPDATE `cultural_places` SET `district` = 'Batu Pahat'
  WHERE `state` = 'Johor' AND `address` LIKE '%Batu Pahat%';
UPDATE `cultural_places` SET `district` = 'Kluang'
  WHERE `state` = 'Johor' AND `address` LIKE '%Kluang%';
UPDATE `cultural_places` SET `district` = 'Segamat'
  WHERE `state` = 'Johor' AND `address` LIKE '%Segamat%';
UPDATE `cultural_places` SET `district` = 'Johor Bahru'
  WHERE `state` = 'Johor' AND `district` IS NULL;

-- Kedah rows
UPDATE `cultural_places` SET `district` = 'Langkawi'
  WHERE `state` = 'Kedah' AND `address` LIKE '%Langkawi%';
UPDATE `cultural_places` SET `district` = 'Kota Setar'
  WHERE `state` = 'Kedah' AND `address` LIKE '%Alor Setar%';
UPDATE `cultural_places` SET `district` = 'Kulim'
  WHERE `state` = 'Kedah' AND `address` LIKE '%Kulim%';
UPDATE `cultural_places` SET `district` = 'Kota Setar'
  WHERE `state` = 'Kedah' AND `district` IS NULL;

-- Kelantan rows
UPDATE `cultural_places` SET `district` = 'Kota Bharu'
  WHERE `state` = 'Kelantan' AND (`address` LIKE '%Kota Bharu%' OR `district` IS NULL);

-- Melaka rows
UPDATE `cultural_places` SET `district` = 'Melaka Tengah'
  WHERE `state` = 'Melaka' AND (`address` LIKE '%Melaka%' OR `district` IS NULL);

-- Negeri Sembilan rows
UPDATE `cultural_places` SET `district` = 'Seremban'
  WHERE `state` = 'Negeri Sembilan' AND (`address` LIKE '%Seremban%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Port Dickson'
  WHERE `state` = 'Negeri Sembilan' AND `address` LIKE '%Port Dickson%';

-- Pahang rows
UPDATE `cultural_places` SET `district` = 'Cameron Highlands'
  WHERE `state` = 'Pahang' AND `address` LIKE '%Cameron%';
UPDATE `cultural_places` SET `district` = 'Kuantan'
  WHERE `state` = 'Pahang' AND `address` LIKE '%Kuantan%';
UPDATE `cultural_places` SET `district` = 'Bentong'
  WHERE `state` = 'Pahang' AND `address` LIKE '%Bentong%';
UPDATE `cultural_places` SET `district` = 'Kuantan'
  WHERE `state` = 'Pahang' AND `district` IS NULL;

-- Penang rows
UPDATE `cultural_places` SET `district` = 'Timur Laut'
  WHERE `state` = 'Penang' AND (`address` LIKE '%Georgetown%' OR `address` LIKE '%George Town%');
UPDATE `cultural_places` SET `district` = 'Seberang Perai Tengah'
  WHERE `state` = 'Penang' AND `address` LIKE '%Butterworth%';
UPDATE `cultural_places` SET `district` = 'Timur Laut'
  WHERE `state` = 'Penang' AND `district` IS NULL;

-- Perak rows
UPDATE `cultural_places` SET `district` = 'Kinta'
  WHERE `state` = 'Perak' AND (`address` LIKE '%Ipoh%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Manjung'
  WHERE `state` = 'Perak' AND `address` LIKE '%Manjung%';
UPDATE `cultural_places` SET `district` = 'Larut, Matang & Selama'
  WHERE `state` = 'Perak' AND `address` LIKE '%Taiping%';

-- Perlis rows
UPDATE `cultural_places` SET `district` = 'Kangar'
  WHERE `state` = 'Perlis' AND (`district` IS NULL OR `address` LIKE '%Kangar%');

-- Sabah rows
UPDATE `cultural_places` SET `district` = 'Kota Kinabalu'
  WHERE `state` = 'Sabah' AND (`address` LIKE '%Kota Kinabalu%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Sandakan'
  WHERE `state` = 'Sabah' AND `address` LIKE '%Sandakan%';
UPDATE `cultural_places` SET `district` = 'Tawau'
  WHERE `state` = 'Sabah' AND `address` LIKE '%Tawau%';
UPDATE `cultural_places` SET `district` = 'Semporna'
  WHERE `state` = 'Sabah' AND `address` LIKE '%Semporna%';

-- Sarawak rows
UPDATE `cultural_places` SET `district` = 'Kuching'
  WHERE `state` = 'Sarawak' AND (`address` LIKE '%Kuching%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Miri'
  WHERE `state` = 'Sarawak' AND `address` LIKE '%Miri%';
UPDATE `cultural_places` SET `district` = 'Sibu'
  WHERE `state` = 'Sarawak' AND `address` LIKE '%Sibu%';

-- Selangor rows
UPDATE `cultural_places` SET `district` = 'Petaling Jaya'
  WHERE `state` = 'Selangor' AND `address` LIKE '%Petaling%';
UPDATE `cultural_places` SET `district` = 'Shah Alam'
  WHERE `state` = 'Selangor' AND `address` LIKE '%Shah Alam%';
UPDATE `cultural_places` SET `district` = 'Klang'
  WHERE `state` = 'Selangor' AND `address` LIKE '%Klang%';
UPDATE `cultural_places` SET `district` = 'Subang Jaya'
  WHERE `state` = 'Selangor' AND `address` LIKE '%Subang%';
UPDATE `cultural_places` SET `district` = 'Petaling Jaya'
  WHERE `state` = 'Selangor' AND `district` IS NULL;

-- Terengganu rows
UPDATE `cultural_places` SET `district` = 'Kuala Terengganu'
  WHERE `state` = 'Terengganu' AND (`address` LIKE '%Kuala Terengganu%' OR `district` IS NULL);
UPDATE `cultural_places` SET `district` = 'Kemaman'
  WHERE `state` = 'Terengganu' AND `address` LIKE '%Kemaman%';

-- Kuala Lumpur rows
UPDATE `cultural_places` SET `district` = 'City Centre (KLCC)'
  WHERE `state` = 'Kuala Lumpur' AND (`address` LIKE '%KLCC%' OR `address` LIKE '%Petronas%');
UPDATE `cultural_places` SET `district` = 'Brickfields'
  WHERE `state` = 'Kuala Lumpur' AND `address` LIKE '%Brickfields%';
UPDATE `cultural_places` SET `district` = 'Chow Kit'
  WHERE `state` = 'Kuala Lumpur' AND `address` LIKE '%Chow Kit%';
UPDATE `cultural_places` SET `district` = 'City Centre (KLCC)'
  WHERE `state` = 'Kuala Lumpur' AND `district` IS NULL;

-- Putrajaya rows
UPDATE `cultural_places` SET `district` = 'Putrajaya'
  WHERE `state` = 'Putrajaya' AND `district` IS NULL;

-- Labuan rows
UPDATE `cultural_places` SET `district` = 'Victoria'
  WHERE `state` = 'Labuan' AND `district` IS NULL;

-- ============================================================
-- 5. Update hotels and food_places with districts similarly
-- ============================================================

UPDATE `hotels` SET `district` = 'Johor Bahru'  WHERE `state` = 'Johor'           AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kota Setar'   WHERE `state` = 'Kedah'           AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kota Bharu'   WHERE `state` = 'Kelantan'        AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Melaka Tengah' WHERE `state` = 'Melaka'         AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Seremban'     WHERE `state` = 'Negeri Sembilan' AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kuantan'      WHERE `state` = 'Pahang'          AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Timur Laut'   WHERE `state` = 'Penang'          AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kinta'        WHERE `state` = 'Perak'           AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kangar'       WHERE `state` = 'Perlis'          AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kota Kinabalu' WHERE `state` = 'Sabah'          AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kuching'      WHERE `state` = 'Sarawak'         AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Petaling Jaya' WHERE `state` = 'Selangor'       AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Kuala Terengganu' WHERE `state` = 'Terengganu'  AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'City Centre (KLCC)' WHERE `state` = 'Kuala Lumpur' AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Putrajaya'    WHERE `state` = 'Putrajaya'       AND `district` IS NULL;
UPDATE `hotels` SET `district` = 'Victoria'     WHERE `state` = 'Labuan'          AND `district` IS NULL;

UPDATE `food_places` SET `district` = 'Johor Bahru'  WHERE `state` = 'Johor'           AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kota Setar'   WHERE `state` = 'Kedah'           AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kota Bharu'   WHERE `state` = 'Kelantan'        AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Melaka Tengah' WHERE `state` = 'Melaka'         AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Seremban'     WHERE `state` = 'Negeri Sembilan' AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kuantan'      WHERE `state` = 'Pahang'          AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Timur Laut'   WHERE `state` = 'Penang'          AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kinta'        WHERE `state` = 'Perak'           AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kangar'       WHERE `state` = 'Perlis'          AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kota Kinabalu' WHERE `state` = 'Sabah'          AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kuching'      WHERE `state` = 'Sarawak'         AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Petaling Jaya' WHERE `state` = 'Selangor'       AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Kuala Terengganu' WHERE `state` = 'Terengganu'  AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'City Centre (KLCC)' WHERE `state` = 'Kuala Lumpur' AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Putrajaya'    WHERE `state` = 'Putrajaya'       AND `district` IS NULL;
UPDATE `food_places` SET `district` = 'Victoria'     WHERE `state` = 'Labuan'          AND `district` IS NULL;
