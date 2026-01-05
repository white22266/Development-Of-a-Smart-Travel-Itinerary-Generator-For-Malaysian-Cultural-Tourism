-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2026-01-05 12:33:51
-- 服务器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `travel_itinerary_db`
--

-- --------------------------------------------------------

--
-- 表的结构 `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `admins`
--

INSERT INTO `admins` (`admin_id`, `username`, `email`, `password_hash`, `created_at`) VALUES
(1, 'Admin', 'admin@gmail.com', '$2y$10$LBMEG72pdE..zMt9mhynsO0u8XmNXnWcAjEmSnw/G5lcanabmiKGW', '2025-12-13 07:31:55');

-- --------------------------------------------------------

--
-- 表的结构 `cultural_places`
--

CREATE TABLE `cultural_places` (
  `place_id` int(11) NOT NULL,
  `state` varchar(60) NOT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(120) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `cultural_places`
--

INSERT INTO `cultural_places` (`place_id`, `state`, `category`, `name`, `description`, `address`, `latitude`, `longitude`, `opening_hours`, `estimated_cost`, `image_url`, `image_path`, `is_active`, `created_by_admin_id`, `created_at`, `updated_at`) VALUES
(1, 'Johor', 'food', 'Kacang Pool Haji Restauran (Johor Bahru)', 'Local Johor breakfast dish, commonly served with bread.Kacang Pool Haji Restaurant is an iconic and must-visit culinary destination in Johor Bahru, famous for its signature dish, Kacang Pool Haji, which has been captivating the palates of many visitors since 2009. The restaurant offers a rich, rich, and satisfying Johor dining experience, perfect for breakfast, lunch, dinner, or even a late-night snack.', '12, Jalan Dato Jaafar, Taman Dato Onn, 80350 Johor Bahru, Johor Darul Ta\'zim', 1.4907395, 103.7515533, '7am - 12am', 8.00, 'uploads/places/place_1766837821_4033.jpg', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 04:17:01'),
(2, 'Johor', 'food', 'Mee Rebus (Johor Style)', 'Mee Rebus Selera Johor (Warisan Keluarga Hj. Wahid) is famous for its authentic Johor dishes based on family recipes passed down through generations since 1948. It serves a variety of iconic Malaysian dishes, with a main focus on the mee rebus with a thick and flavorful peanut sauce. Traditional Johor-style mee rebus with rich gravy.', 'Johor Bahru, Johor', 1.4927000, 103.7414000, '7:30 am–9:30 pm', 15.00, 'uploads/places/place_1766835810_7693.png', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 03:43:30'),
(3, 'Johor', 'heritage', 'Chong Long Gong Temple', 'Historic temple representing Chinese community heritage.Chong Long Gong Temple is a unique and vibrant Chinese temple located by the sea in the Kampung Segenting fishing village of Batu Pahat, Johor, Malaysia. It is famous for its large arapaima fish, believed to bring good fortune to those who touch them.', '81, Kampung Segenting, Batu Pahat, 83030 Bandar Penggaram, Johor', 1.7836877, 102.8926308, '9:00 AM - 6:00 PM', 0.00, 'uploads/places/place_1766836350_7759.webp', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 03:52:30'),
(4, 'Johor', 'museum', 'Johor Bahru Chinese Heritage Museum', 'Museum showcasing Johor Bahru Chinese heritage and history.The Johor Bahru Chinese Heritage Museum (Malay: Muzium Warisan Tionghua Johor Bahru) is a museum in Johor Bahru, Johor, Malaysia. The museum is about the history of Chinese community in Johor Bahru. Collections in the museum include documents, music instruments, old money, photos, porcelain etc. It showcases the early days of the Chinese settlement in Johor Bahru, their history, culture, traditions and occupations.', 'Johor Bahru, Johor', 1.4579000, 103.7646000, '9.00 a.m. - 5.00 p.m. except Mondays', 6.00, 'uploads/places/place_1766836057_2242.webp', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 03:47:37'),
(5, 'Kedah', 'nature', 'Gunung Jerai', 'Popular mountain attraction with scenic views. Gunung Jerai is the highest peak in Kedah, Malaysia, a unique island-shaped mountain (inselberg) that is easily visible from afar, serving as a landmark and sea navigation since ancient times, now a popular tourist destination with a resort at the top, recreational activities such as hiking, cycling, paragliding, as well as rich geological and botanical treasures, with panoramic views towards the rice fields and the Straits of Malacca.', 'Yan, Kedah', 5.7887000, 100.4246000, '08:00-18:00', 0.00, 'uploads/places/place_1766835360_5565.jpg', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 03:37:07'),
(6, 'Kedah', 'museum', 'Kedah State Museum', 'Museum presenting history and culture of Kedah. The history of the establishment of the Kedah State Museum began on 3 February 1957, known as the Kedah History Museum. The museum, located on the ground floor of the Balai Besar, Alor Setar, was officiated by YAB Tan Sri Tunku Ismail bin Tunku Yahya, the 2nd Menteri Besar of Kedah Darul Aman. The increase in the number of collections caused the museum to be moved to its own building (next to the new museum building) on ​​the Darul Aman Highway, Bakar Bata on 30 December 1961. In July 1964, the Kedah History Museum was changed its name to the Kedah State Museum.\r\n\r\nGiven the good response from the public, the State Government agreed to build the current building in 1997. This building houses an exhibition hall, workshop, library, and mini theatrette. There are 10 permanent exhibition galleries such as the Cultural Hall, the History Hall, the Nature Hall, the Heroes Hall, the Transport Hall, the Weapons Hall, the Manuscript Hall, the Textile Hall, the Arts and Crafts Hall and the Language and Literature Corner. Apart from that, its facilities have also been improved by providing an elevator, cafeteria, car parking and public toilets.', 'Alor Setar, Kedah', 6.1210000, 100.3680000, '09:00-17:00', 5.00, 'uploads/places/place_1766835098_6695.webp', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 03:31:38'),
(7, 'Kedah', 'food', 'Din Laksa Teluk Kechai', 'Famous Kedah laksa experience.This legendary Laksa in 1967 when it was sold by Din himself on his gerek going from house to house.', 'No 246 Batu 4 1/4 jalan kuala kedah, Alor Setar 06600 Malaysia', 6.0949606, 100.3206236, '3:00 PM - 8:00 PM', 15.00, 'uploads/places/place_1766831415_1464.jpg', NULL, 1, NULL, '2025-12-15 01:26:21', '2025-12-27 02:30:15'),
(8, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', 1.3225724, 103.4283275, '9.00 am–4.00 pm', 25.00, 'uploads/places/place_1766813513_1914.webp', NULL, 1, 1, '2025-12-23 23:50:41', '2025-12-27 07:21:10'),
(9, 'Johor', 'heritage', 'Johor Bahru Old Chinese Temple (柔佛古庙)', 'One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）', 'Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim', 1.4606803, 103.7630595, '7:00 AM – 6:00 PM', 20.00, 'uploads/places/suggest_1766814848_5261.webp', NULL, 1, 1, '2025-12-26 21:54:44', '2025-12-27 07:21:15'),
(10, 'Selangor', 'nature', 'Forest Research Institute Malaysia (FRIM)', 'Forest park with canopy walk, nature trails, and educational eco-tourism experiences.', 'Forest Research Institute Malaysia (FRIM), 52109 Kepong, Selangor, Malaysia', 3.2353390, 101.6342690, NULL, 0.00, 'uploads/places/place_10_169294a803_20260105_120730_5e4700.jpg', 'uploads/places/place_10_169294a803_20260105_120730_5e4700.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:30'),
(11, 'Selangor', 'festival', 'Thaipusam at Batu Caves', 'Major religious-cultural festival with pilgrimages, rituals, and vibrant community participation.', 'Batu Caves Temple, Gombak, 68100 Batu Caves, Selangor, Malaysia', 3.2374000, 101.6839070, NULL, 0.00, 'uploads/places/place_11_30123df278_20260105_120731_8f5f34.jpg', 'uploads/places/place_11_30123df278_20260105_120731_8f5f34.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:31'),
(12, 'Sarawak', 'heritage', 'Niah Caves (Niah National Park)', 'Archaeological and cultural heritage caves complex known for early human history and cave exploration.', 'Niah National Park, Batu Niah, 98200 Miri, Sarawak, Malaysia', 3.8083000, 113.7755000, NULL, 0.00, 'uploads/places/place_12_85ade11fe5_20260105_120734_5496bb.jpg', 'uploads/places/place_12_85ade11fe5_20260105_120734_5496bb.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:34'),
(13, 'Sarawak', 'culture', 'Sarawak Cultural Village', 'Living museum showcasing Sarawak ethnic groups with traditional houses and cultural performances.', 'Sarawak Cultural Village, Pantai Damai, Santubong, 93752 Kuching, Sarawak, Malaysia', 1.7497100, 110.3169800, NULL, 0.00, 'uploads/places/place_13_1dada0c29d_20260105_120735_8c494b.jpg', 'uploads/places/place_13_1dada0c29d_20260105_120735_8c494b.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:35'),
(14, 'Sarawak', 'festival', 'Rainforest World Music Festival', 'Signature music and cultural festival combining world music showcases with local cultural elements.', 'Sarawak Cultural Village, Pantai Damai, Santubong, 93752 Kuching, Sarawak, Malaysia', 1.7497100, 110.3169800, '', 0.00, 'uploads/places/place_1766958248_6687.jpg', NULL, 1, 1, '2025-12-27 01:36:07', '2025-12-28 13:44:08'),
(15, 'Sarawak', 'nature', 'Gunung Mulu National Park', 'UNESCO natural site famous for limestone karst formations, rainforest, and extensive cave systems.', 'Gunung Mulu National Park HQ, 98070 Mulu, Sarawak, Malaysia', 4.1320000, 114.9190000, NULL, 0.00, 'uploads/places/place_15_d451eb69ae_20260105_121459_5c8d18.jpg', 'uploads/places/place_15_d451eb69ae_20260105_121459_5c8d18.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:14:59'),
(16, 'Perak', 'heritage', 'Archaeological Heritage of the Lenggong Valley', 'UNESCO-listed archaeological landscape featuring significant prehistoric findings and sites.', 'Lenggong Valley (Lembah Lenggong), 33400 Lenggong, Perak, Malaysia', NULL, NULL, NULL, 0.00, 'uploads/places/place_16_28a30c79d0_20260105_121504_08dcd3.jpg', 'uploads/places/place_16_28a30c79d0_20260105_121504_08dcd3.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:15:04'),
(17, 'Penang', 'heritage', 'Cheong Fatt Tze Mansion (The Blue Mansion)', 'Straits Chinese heritage mansion offering guided tours highlighting architecture and Peranakan influence.', '14, Leith Street, 10200 George Town, Penang, Malaysia', 5.4213194, 100.3352500, NULL, 0.00, 'uploads/places/place_17_0f1d7f3b50_20260105_120749_69e67c.jpg', 'uploads/places/place_17_0f1d7f3b50_20260105_120749_69e67c.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:49'),
(18, 'Penang', 'festival', 'George Town Festival', 'Annual arts and culture festival featuring performances, exhibitions, and heritage-focused programs.', 'George Town Festival Office, 1st Floor, 86 Lebuh Armenian, 10200 George Town, Penang, Malaysia', 5.4154392, 100.3370691, NULL, 0.00, 'uploads/places/place_18_fe4e4116bd_20260105_120753_530144.jpg', 'uploads/places/place_18_fe4e4116bd_20260105_120753_530144.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:53'),
(19, 'Melaka', 'museum', 'Baba & Nyonya Heritage Museum', 'Peranakan house museum showcasing traditional lifestyle, artifacts, and cultural history in historic Melaka.', '48 & 50, Jalan Tun Tan Cheng Lock, 75200 Melaka, Malaysia', 2.1952670, 102.2466570, NULL, 0.00, 'uploads/places/place_19_c023100102_20260105_120755_1fe0c4.jpg', 'uploads/places/place_19_c023100102_20260105_120755_1fe0c4.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:07:55'),
(20, 'Kuala Lumpur', 'museum', 'Islamic Arts Museum Malaysia (IAMM)', 'Major museum showcasing Islamic art, design, and cultural heritage collections.', 'Islamic Arts Museum Malaysia, Jalan Lembah Perdana, 50480 Kuala Lumpur, Malaysia', 3.1418340, 101.6886180, NULL, 0.00, 'uploads/places/place_20_4fd8bba161_20260105_121510_b26c9a.jpg', 'uploads/places/place_20_4fd8bba161_20260105_121510_b26c9a.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:15:10'),
(21, 'Kuala Lumpur', 'museum', 'Muzium Negara (National Museum)', 'National museum presenting Malaysia history, culture, and nation-building narratives.', 'Muzium Negara, Jalan Damansara, 50566 Kuala Lumpur, Malaysia', 3.1379960, 101.6870430, '09:00-17:00', 0.00, 'uploads/places/place_21_60f3eb5978_20260105_121514_5d3aa2.jpg', 'uploads/places/place_21_60f3eb5978_20260105_121514_5d3aa2.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:15:14'),
(22, 'Terengganu', 'shopping', 'Pasar Payang', 'Traditional market for local products such as textiles, snacks, souvenirs, and crafts.', 'Pasar Payang, Jalan Sultan Zainal Abidin, 20200 Kuala Terengganu, Terengganu, Malaysia', 5.3300000, 103.1380000, NULL, 0.00, 'uploads/places/place_22_c8b317e8dd_20260105_120808_88a923.jpg', 'uploads/places/place_22_c8b317e8dd_20260105_120808_88a923.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:08:08'),
(23, 'Sabah', 'culture', 'Mari Mari Cultural Village', 'Living culture village experience featuring Sabah ethnic traditions, demonstrations, and performances.', 'Mari Mari Cultural Village, Jalan Kionsom, Inanam, 88450 Kota Kinabalu, Sabah, Malaysia', 5.9732639, 116.2023167, NULL, 0.00, 'uploads/places/place_23_95717a344d_20260105_120813_bd78dc.jpg', 'uploads/places/place_23_95717a344d_20260105_120813_bd78dc.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:08:13'),
(24, 'Sabah', 'nature', 'Kinabalu Park', 'UNESCO natural site known for biodiversity and as the gateway to Mount Kinabalu.', 'Kinabalu Park Headquarters, 89307 Ranau, Sabah, Malaysia', 6.0055351, 116.5422225, NULL, 0.00, 'uploads/places/place_24_7be148a719_20260105_120836_02a8a1.jpg', 'uploads/places/place_24_7be148a719_20260105_120836_02a8a1.jpg', 1, 1, '2025-12-27 01:36:07', '2026-01-05 11:08:36');

-- --------------------------------------------------------

--
-- 表的结构 `cultural_place_suggestions`
--

CREATE TABLE `cultural_place_suggestions` (
  `suggestion_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `state` varchar(60) NOT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(120) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_place_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `cultural_place_suggestions`
--

INSERT INTO `cultural_place_suggestions` (`suggestion_id`, `traveller_id`, `state`, `category`, `name`, `description`, `address`, `latitude`, `longitude`, `opening_hours`, `estimated_cost`, `image_url`, `status`, `approved_by_admin_id`, `approved_place_id`, `created_at`, `approved_at`, `review_note`) VALUES
(1, 1, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', 1.3227011, 103.4283061, '9.00 am–4.00 pm', 25.00, 'uploads/suggestions/suggest_1766515341_6595.webp', 'rejected', 1, NULL, '2025-12-23 18:42:21', NULL, NULL),
(2, 1, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', 1.3225724, 103.4283275, '9.00 am–4.00 pm', 25.00, 'uploads/suggestions/suggest_1766562623_1316.webp', 'approved', 1, NULL, '2025-12-24 07:50:23', NULL, NULL),
(3, 1, 'Johor', 'heritage', 'Johor Bahru Old Chinese Temple (柔佛古庙)', 'One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）', 'Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim', 1.4606803, 103.7630595, '7:00 AM – 6:00 PM', 20.00, 'uploads/places/suggest_1766814848_5261.webp', 'approved', 1, NULL, '2025-12-27 05:54:08', '2025-12-26 23:01:47', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `itineraries`
--

CREATE TABLE `itineraries` (
  `itinerary_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `preference_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `start_date` date DEFAULT NULL,
  `total_days` int(11) NOT NULL,
  `items_per_day` int(11) NOT NULL DEFAULT 3,
  `total_estimated_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('draft','saved','exported') DEFAULT 'saved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `itineraries`
--

INSERT INTO `itineraries` (`itinerary_id`, `traveller_id`, `preference_id`, `title`, `start_date`, `total_days`, `items_per_day`, `total_estimated_cost`, `status`, `created_at`) VALUES
(40, 1, 6, '3D Culture & Food Journey: Malaysia', '2026-02-22', 3, 3, 74.00, 'saved', '2026-01-05 11:30:21'),
(41, 1, 4, '1D Food Journey: Johor', '2026-02-22', 1, 2, 23.00, 'saved', '2026-01-05 11:32:17');

-- --------------------------------------------------------

--
-- 表的结构 `itinerary_items`
--

CREATE TABLE `itinerary_items` (
  `item_id` int(11) NOT NULL,
  `itinerary_id` int(11) NOT NULL,
  `day_no` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL,
  `item_type` enum('attraction','food','festival','transport','hotel','note') DEFAULT 'attraction',
  `place_id` int(11) DEFAULT NULL,
  `item_title` varchar(150) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `travel_time_min` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `itinerary_items`
--

INSERT INTO `itinerary_items` (`item_id`, `itinerary_id`, `day_no`, `sequence_no`, `item_type`, `place_id`, `item_title`, `start_time`, `end_time`, `estimated_cost`, `distance_km`, `travel_time_min`, `notes`) VALUES
(17, 40, 1, 1, 'attraction', 3, 'Chong Long Gong Temple', NULL, NULL, 0.00, NULL, NULL, 'State: Johor | Category: heritage'),
(18, 40, 2, 1, 'attraction', 8, 'Taman Negara Johor Pulau Kukup', NULL, NULL, 25.00, NULL, NULL, 'State: Johor | Category: nature'),
(19, 40, 2, 2, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, 15.00, 49.44, 50, 'State: Johor | Category: food'),
(20, 40, 2, 3, 'food', 1, 'Kacang Pool Haji Restauran (Johor Bahru)', NULL, NULL, 8.00, 2.18, 5, 'State: Johor | Category: food'),
(21, 40, 3, 1, 'attraction', 4, 'Johor Bahru Chinese Heritage Museum', NULL, NULL, 6.00, NULL, NULL, 'State: Johor | Category: museum'),
(22, 40, 3, 2, 'attraction', 9, 'Johor Bahru Old Chinese Temple (柔佛古庙)', NULL, NULL, 20.00, 1.12, 5, 'State: Johor | Category: heritage'),
(23, 41, 1, 1, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, 15.00, NULL, NULL, 'State: Johor | Category: food'),
(24, 41, 1, 2, 'food', 1, 'Kacang Pool Haji Restauran (Johor Bahru)', NULL, NULL, 8.00, 2.18, 5, 'State: Johor | Category: food');

-- --------------------------------------------------------

--
-- 表的结构 `travellers`
--

CREATE TABLE `travellers` (
  `traveller_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `travellers`
--

INSERT INTO `travellers` (`traveller_id`, `full_name`, `email`, `password_hash`, `force_password_change`, `phone`, `created_at`) VALUES
(1, 'PECK JIAN HAO', 'ai230119@student.uthm.edu.my', '$2y$10$z1JkXPqBGJx/s3RCaegb/edGHQZt/Jv1FnnrdzF90xk1P/kfPE3oq', 0, '0123231123', '2025-12-13 06:56:11'),
(2, 'YAP', 'waterwhite455@gmail.com', '$2y$10$edaq3qxL77q/m1O51AnhAOXkYwQBN8OpEdGhLTR9h.neLmuiD1OTu', 0, '0114433433', '2025-12-15 17:21:35'),
(3, 'WONG', 'peckjianhao0227@gmail.com', '$2y$10$HjX9/FRqOmURvF7paXPfJO5a8f3UL9Ns9i2M3rtalQchIKFvOaOKW', 0, '0123122332', '2025-12-15 19:17:59'),
(4, 'Pearly', 'peckjianhao0228@gmail.com', '$2y$10$2Yl3pw9YV.YgPTWP5zekpOY/LTdMjij5F2mcKjrk141QXGrkC1Ahu', 0, '0114437898', '2025-12-15 19:29:52'),
(5, 'Harry', 'peckjianhao0229@gmail.com', '$2y$10$zDEvGjOl9P6ouSFGJvDkh.JYJyNY.LahepDB5IJ/wxlpIedOpVp5C', 0, '0114433433', '2025-12-15 19:32:13'),
(6, 'TAN', 'peckjianhao02299@gmail.com', '$2y$10$N0Cea.rqV028HtD8xHVS0.Blt0vaEh0jYYBNZBWjAR7K/VNLmN4y.', 0, '0114433433', '2025-12-15 19:33:33'),
(7, 'Justin', 'peckjianhao0221@gmail.com', '$2y$10$79i8VgjKSdh7R27EDlskCuK1jnEwHfQQlP3l08X1cVm.jukTrMBEy', 1, '0116789885', '2025-12-15 19:56:32');

-- --------------------------------------------------------

--
-- 表的结构 `traveller_preferences`
--

CREATE TABLE `traveller_preferences` (
  `preference_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `trip_days` int(11) NOT NULL,
  `budget` decimal(10,2) NOT NULL,
  `transport_type` enum('car','public_transport','walking','motorcycle') DEFAULT 'car',
  `interests` set('culture','food','nature','shopping','museum','heritage','festival') NOT NULL,
  `preferred_states` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `traveller_preferences`
--

INSERT INTO `traveller_preferences` (`preference_id`, `traveller_id`, `trip_days`, `budget`, `transport_type`, `interests`, `preferred_states`, `created_at`) VALUES
(1, 1, 4, 1000.00, 'public_transport', 'food', 'Johor,Kedah', '2025-12-14 14:29:01'),
(2, 1, 5, 1000.00, 'car', 'food', 'Johor', '2025-12-15 09:15:24'),
(3, 1, 3, 3000.00, 'car', 'heritage', 'Johor', '2025-12-15 10:17:21'),
(4, 1, 1, 2222.00, 'car', 'food', 'Johor', '2025-12-15 15:15:45'),
(5, 7, 5, 2000.00, 'car', 'culture,museum', 'Johor,Kelantan', '2025-12-22 02:35:40'),
(6, 1, 3, 3000.00, 'car', 'culture,food,nature,shopping,museum,heritage,festival', '', '2025-12-23 11:59:13');

-- --------------------------------------------------------

--
-- 表的结构 `validation_logs`
--

CREATE TABLE `validation_logs` (
  `log_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` enum('approved','rejected') NOT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- 表的索引 `cultural_places`
--
ALTER TABLE `cultural_places`
  ADD PRIMARY KEY (`place_id`),
  ADD KEY `idx_state_category` (`state`,`category`),
  ADD KEY `fk_place_admin` (`created_by_admin_id`);

--
-- 表的索引 `cultural_place_suggestions`
--
ALTER TABLE `cultural_place_suggestions`
  ADD PRIMARY KEY (`suggestion_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_state_category` (`state`,`category`),
  ADD KEY `fk_sugg_traveller` (`traveller_id`),
  ADD KEY `fk_sugg_admin` (`approved_by_admin_id`),
  ADD KEY `fk_suggestions_approved_place` (`approved_place_id`);

--
-- 表的索引 `itineraries`
--
ALTER TABLE `itineraries`
  ADD PRIMARY KEY (`itinerary_id`),
  ADD KEY `fk_it_traveller` (`traveller_id`),
  ADD KEY `fk_it_pref` (`preference_id`);

--
-- 表的索引 `itinerary_items`
--
ALTER TABLE `itinerary_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_itinerary_day` (`itinerary_id`,`day_no`,`sequence_no`);

--
-- 表的索引 `travellers`
--
ALTER TABLE `travellers`
  ADD PRIMARY KEY (`traveller_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- 表的索引 `traveller_preferences`
--
ALTER TABLE `traveller_preferences`
  ADD PRIMARY KEY (`preference_id`),
  ADD KEY `fk_pref_traveller` (`traveller_id`);

--
-- 表的索引 `validation_logs`
--
ALTER TABLE `validation_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_log_sub` (`submission_id`),
  ADD KEY `fk_log_admin` (`admin_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `cultural_places`
--
ALTER TABLE `cultural_places`
  MODIFY `place_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- 使用表AUTO_INCREMENT `cultural_place_suggestions`
--
ALTER TABLE `cultural_place_suggestions`
  MODIFY `suggestion_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用表AUTO_INCREMENT `itineraries`
--
ALTER TABLE `itineraries`
  MODIFY `itinerary_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- 使用表AUTO_INCREMENT `itinerary_items`
--
ALTER TABLE `itinerary_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- 使用表AUTO_INCREMENT `travellers`
--
ALTER TABLE `travellers`
  MODIFY `traveller_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- 使用表AUTO_INCREMENT `traveller_preferences`
--
ALTER TABLE `traveller_preferences`
  MODIFY `preference_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- 使用表AUTO_INCREMENT `validation_logs`
--
ALTER TABLE `validation_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `cultural_places`
--
ALTER TABLE `cultural_places`
  ADD CONSTRAINT `fk_place_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

--
-- 限制表 `cultural_place_suggestions`
--
ALTER TABLE `cultural_place_suggestions`
  ADD CONSTRAINT `fk_sugg_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sugg_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_suggestions_approved_place` FOREIGN KEY (`approved_place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- 限制表 `itineraries`
--
ALTER TABLE `itineraries`
  ADD CONSTRAINT `fk_it_pref` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`),
  ADD CONSTRAINT `fk_it_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE;

--
-- 限制表 `itinerary_items`
--
ALTER TABLE `itinerary_items`
  ADD CONSTRAINT `fk_item_it` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE;

--
-- 限制表 `traveller_preferences`
--
ALTER TABLE `traveller_preferences`
  ADD CONSTRAINT `fk_pref_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
