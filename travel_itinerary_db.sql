-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2025-12-27 07:16:35
-- 服务器版本： 10.4.27-MariaDB
-- PHP 版本： 7.4.33

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
(1, 'Admin', 'ai230119@student.uthm.edu.my', '$2y$10$LBMEG72pdE..zMt9mhynsO0u8XmNXnWcAjEmSnw/G5lcanabmiKGW', '2025-12-13 07:31:55'),
(2, 'Ali', 'peckjianhao0226@gmail.com', '$2y$10$2Lk5XVk02wq9RuwCTMaRcemg.ZVY8JAcqqquWd5pbqNC2ejV2ixtq', '2025-12-15 19:50:31');

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
  `is_active` tinyint(1) DEFAULT 1,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `cultural_places`
--

INSERT INTO `cultural_places` (`place_id`, `state`, `category`, `name`, `description`, `address`, `latitude`, `longitude`, `opening_hours`, `estimated_cost`, `image_url`, `is_active`, `created_by_admin_id`, `created_at`, `updated_at`) VALUES
(1, 'Johor', 'food', 'Kacang Pool (Johor Bahru)', 'Local Johor breakfast dish, commonly served with bread.', 'Johor Bahru, Johor', '1.4927000', '103.7414000', '08:00-18:00', '8.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(2, 'Johor', 'food', 'Mee Rebus (Johor Style)', 'Traditional Johor-style mee rebus with rich gravy.', 'Johor Bahru, Johor', '1.4927000', '103.7414000', '10:00-20:00', '9.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(3, 'Johor', 'heritage', 'Johor Bahru Old Chinese Temple', 'Historic temple representing Chinese community heritage.', 'Johor Bahru, Johor', '1.4572000', '103.7637000', '07:00-17:00', '0.00', NULL, 1, NULL, '2025-12-15 09:26:21', '2025-12-16 06:34:37'),
(4, 'Johor', 'museum', 'Johor Bahru Chinese Heritage Museum', 'Museum showcasing Johor Bahru Chinese heritage and history.', 'Johor Bahru, Johor', '1.4579000', '103.7646000', '09:00-17:00', '6.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(5, 'Kedah', 'nature', 'Gunung Jerai', 'Popular mountain attraction with scenic views.', 'Yan, Kedah', '5.7887000', '100.4246000', '08:00-18:00', '0.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(6, 'Kedah', 'museum', 'Kedah State Museum', 'Museum presenting history and culture of Kedah.', 'Alor Setar, Kedah', '6.1210000', '100.3680000', '09:00-17:00', '5.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(7, 'Kedah', 'food', 'Laksa Kedah', 'Famous Kedah laksa experience.', 'Alor Setar, Kedah', '6.1210000', '100.3680000', '10:00-18:00', '8.00', NULL, 1, NULL, '2025-12-15 09:26:21', NULL),
(8, 'Johor', 'shopping', 'AEON BiG Batu Pahat', 'Shopping mall', '1B, Jalan Persiaran Flora Utama, Taman Flora Utama, 83000 Batu Pahat, Johor Darul Ta\'zim', '1.8657186', '102.9483805', '9.00 am–10.00 pm', '0.00', 'uploads/places/place_1765891527_7083.jpg', 1, NULL, '2025-12-16 13:07:42', '2025-12-16 13:25:27'),
(12, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', '1.3225724', '103.4283275', '9.00 am–4.00 pm', '25.00', 'uploads/places/place_1766813513_1914.webp', 1, 1, '2025-12-24 07:50:41', '2025-12-27 05:31:53'),
(13, 'Johor', 'heritage', 'Johor Bahru Old Chinese Temple (柔佛古庙)', 'One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）', 'Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim', '1.4606803', '103.7630595', '7:00 AM – 6:00 PM', '20.00', 'uploads/places/suggest_1766814848_5261.webp', 1, 1, '2025-12-27 05:54:44', NULL);

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
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `cultural_place_suggestions`
--

INSERT INTO `cultural_place_suggestions` (`suggestion_id`, `traveller_id`, `state`, `category`, `name`, `description`, `address`, `latitude`, `longitude`, `opening_hours`, `estimated_cost`, `image_url`, `status`, `approved_by_admin_id`, `approved_place_id`, `created_at`, `approved_at`) VALUES
(1, 1, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', '1.3227011', '103.4283061', '9.00 am–4.00 pm', '25.00', 'uploads/suggestions/suggest_1766515341_6595.webp', 'rejected', 1, NULL, '2025-12-23 18:42:21', NULL),
(2, 1, 'Johor', 'nature', 'Taman Negara Johor Pulau Kukup', 'Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.', 'Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim', '1.3225724', '103.4283275', '9.00 am–4.00 pm', '25.00', 'uploads/suggestions/suggest_1766562623_1316.webp', 'approved', 1, 12, '2025-12-24 07:50:23', NULL),
(3, 1, 'Johor', 'heritage', 'Johor Bahru Old Chinese Temple (柔佛古庙)', 'One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）', 'Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim', '1.4606803', '103.7630595', '7:00 AM – 6:00 PM', '20.00', 'uploads/places/suggest_1766814848_5261.webp', 'approved', 1, NULL, '2025-12-27 05:54:08', '2025-12-26 23:01:47');

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
(1, 1, 2, '5D Cultural Itinerary - Johor', NULL, 5, 3, '17.00', 'saved', '2025-12-15 09:26:28'),
(2, 1, 3, '3D Cultural Itinerary - Johor', NULL, 3, 3, '0.00', 'saved', '2025-12-23 11:44:37'),
(30, 1, 4, '1D Cultural Itinerary - Johor', NULL, 1, 3, '17.00', 'saved', '2025-12-26 10:58:55'),
(31, 1, 4, '1D Cultural Itinerary - Johor', NULL, 1, 3, '17.00', 'saved', '2025-12-26 11:38:37'),
(32, 1, 4, '1D Cultural Itinerary - Johor', NULL, 1, 3, '17.00', 'saved', '2025-12-26 12:47:02'),
(33, 1, 6, '3D Cultural Itinerary - Malaysia', NULL, 3, 3, '61.00', 'saved', '2025-12-26 13:08:37'),
(34, 1, 4, '1D Cultural Itinerary - Johor', NULL, 1, 3, '8.00', 'saved', '2025-12-26 13:36:52'),
(35, 1, 1, '4D Cultural Itinerary - Johor, Kedah', NULL, 4, 3, '25.00', 'saved', '2025-12-26 13:38:33');

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
(1, 1, 1, 1, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', NULL, NULL, 'State: Johor | Category: food'),
(2, 1, 1, 2, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', '0.00', 0, 'State: Johor | Category: food'),
(86, 30, 1, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food'),
(87, 30, 1, 2, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', NULL, NULL, 'State: Johor | Category: food'),
(88, 31, 1, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food'),
(89, 31, 1, 2, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', '0.00', 0, 'State: Johor | Category: food'),
(90, 32, 1, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food'),
(91, 32, 1, 2, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', '0.00', 0, 'State: Johor | Category: food'),
(92, 33, 1, 1, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', NULL, NULL, 'State: Johor | Category: food'),
(93, 33, 1, 2, 'attraction', 3, 'Johor Bahru Old Chinese Temple', NULL, NULL, '0.00', '5.84', 13, 'State: Johor | Category: heritage'),
(94, 33, 1, 3, 'attraction', 12, 'Taman Negara Johor Pulau Kukup', NULL, NULL, '25.00', '50.77', 51, 'State: Johor | Category: nature'),
(95, 33, 2, 1, 'food', 7, 'Laksa Kedah', NULL, NULL, '8.00', NULL, NULL, 'State: Kedah | Category: food'),
(96, 33, 2, 2, 'attraction', 5, 'Gunung Jerai', NULL, NULL, '0.00', '59.06', 73, 'State: Kedah | Category: nature'),
(97, 33, 2, 3, 'attraction', 6, 'Kedah State Museum', NULL, NULL, '5.00', '58.47', 71, 'State: Kedah | Category: museum'),
(98, 33, 3, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food'),
(99, 33, 3, 2, 'attraction', 8, 'AEON BiG Batu Pahat', NULL, NULL, '0.00', '115.94', 100, 'State: Johor | Category: shopping'),
(100, 33, 3, 3, 'attraction', 4, 'Johor Bahru Chinese Heritage Museum', NULL, NULL, '6.00', '120.19', 106, 'State: Johor | Category: museum'),
(101, 34, 1, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food'),
(102, 35, 1, 1, 'food', 2, 'Mee Rebus (Johor Style)', NULL, NULL, '9.00', NULL, NULL, 'State: Johor | Category: food'),
(103, 35, 2, 1, 'food', 7, 'Laksa Kedah', NULL, NULL, '8.00', NULL, NULL, 'State: Kedah | Category: food'),
(104, 35, 3, 1, 'food', 1, 'Kacang Pool (Johor Bahru)', NULL, NULL, '8.00', NULL, NULL, 'State: Johor | Category: food');

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
(1, 'PECK JIAN HAO', 'peckjianhao0226@gmail.com', '$2y$10$lYfcjI.sxFWbZbJbFYJT5utbhYjQRIzOA93T//vXT4XXQFiylK6Xe', 0, '0123231123', '2025-12-13 06:56:11'),
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
(1, 1, 4, '1000.00', 'public_transport', 'food', 'Johor,Kedah', '2025-12-14 14:29:01'),
(2, 1, 5, '1000.00', 'car', 'food', 'Johor', '2025-12-15 09:15:24'),
(3, 1, 3, '3000.00', 'car', 'heritage', 'Johor', '2025-12-15 10:17:21'),
(4, 1, 1, '2222.00', 'car', 'food', 'Johor', '2025-12-15 15:15:45'),
(5, 7, 5, '2000.00', 'car', 'culture,museum', 'Johor,Kelantan', '2025-12-22 02:35:40'),
(6, 1, 3, '3000.00', 'car', 'culture,food,nature,shopping,museum,heritage,festival', '', '2025-12-23 11:59:13');

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
  MODIFY `place_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- 使用表AUTO_INCREMENT `cultural_place_suggestions`
--
ALTER TABLE `cultural_place_suggestions`
  MODIFY `suggestion_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用表AUTO_INCREMENT `itineraries`
--
ALTER TABLE `itineraries`
  MODIFY `itinerary_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- 使用表AUTO_INCREMENT `itinerary_items`
--
ALTER TABLE `itinerary_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

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

--
-- 限制表 `validation_logs`
--
ALTER TABLE `validation_logs`
  ADD CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_log_sub` FOREIGN KEY (`submission_id`) REFERENCES `content_submissions` (`submission_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
