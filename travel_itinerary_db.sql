-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: travel_itinerary_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `travel_itinerary_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `travel_itinerary_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `travel_itinerary_db`;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(128) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'Admin','adminpeck2222@gmail.com','$2y$10$LBMEG72pdE..zMt9mhynsO0u8XmNXnWcAjEmSnw/G5lcanabmiKGW','2025-12-13 07:31:55',NULL,NULL);
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ai_chat_logs`
--

DROP TABLE IF EXISTS `ai_chat_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_chat_logs` (
  `chat_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `user_message` text NOT NULL,
  `ai_response` mediumtext NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'unknown',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`chat_id`),
  KEY `idx_ai_chat_itinerary` (`itinerary_id`),
  KEY `idx_ai_chat_traveller` (`traveller_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ai_chat_logs`
--

LOCK TABLES `ai_chat_logs` WRITE;
/*!40000 ALTER TABLE `ai_chat_logs` DISABLE KEYS */;
INSERT INTO `ai_chat_logs` VALUES (1,49,11,'Write my route','Route writing for 2D Culture & Food Highlights | Johor using public transport:\nDay 1: Kacang Pool Haji Restauran (Johor Bahru) -> Johor Bahru Old Chinese Temple (柔佛古庙).\nUse the map route panel for exact live directions and traffic/transit updates.','local_fallback','2026-05-10 18:06:48'),(2,49,11,'Write my itinerary route in simple steps','Route writing for 2D Culture & Food Highlights | Johor using public transport:\nDay 1: Kacang Pool Haji Restauran (Johor Bahru) -> Johor Bahru Old Chinese Temple (柔佛古庙).\nUse the map route panel for exact live directions and traffic/transit updates.','local_fallback','2026-05-10 18:13:19'),(3,49,11,'hihi','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 03:56:52'),(4,49,11,'Suggest improvements for this itinerary','Improvement suggestions:\nThe itinerary looks balanced. Check opening hours and live traffic before travel.','local_fallback','2026-05-11 03:57:08'),(5,49,11,'Explain the cultural value of this itinerary','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 03:57:13'),(6,49,11,'hhihi','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 03:57:17'),(7,49,11,'hihi','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 08:03:18'),(8,49,11,'tell','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 08:03:23'),(9,49,11,'Suggest ways to reduce the total cost','Estimated total cost is RM 230.70. Traveller budget is RM 1,000.00. Hotel cost is included only when a hotel was selected during review. For exact details, open Trip Summary.','local_fallback','2026-05-11 08:03:29'),(10,49,11,'Explain my trip cost breakdown','Estimated total cost is RM 230.70. Traveller budget is RM 1,000.00. Hotel cost is included only when a hotel was selected during review. For exact details, open Trip Summary.','local_fallback','2026-05-11 08:03:32'),(11,49,11,'Write my itinerary route in simple steps','Route writing for 2D Culture & Food Highlights | Johor using public transport:\nDay 1: Kacang Pool Haji Restauran (Johor Bahru) -> Johor Bahru Old Chinese Temple (柔佛古庙).\nUse the map route panel for exact live directions and traffic/transit updates.','local_fallback','2026-05-11 08:03:36'),(12,49,11,'Write my route in one short sentence','Route writing for 2D Culture & Food Highlights | Johor using public transport:\nDay 1: Kacang Pool Haji Restauran (Johor Bahru) -> Johor Bahru Old Chinese Temple (柔佛古庙).\nUse the map route panel for exact live directions and traffic/transit updates.','local_fallback','2026-05-11 08:04:34'),(13,49,11,'hih','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 08:08:39'),(14,49,11,'hi','2D Culture & Food Highlights | Johor contains 2 scheduled stop(s) across 1 day(s), using public transport. Ask me to write the route, explain cultural value, check budget, or suggest improvements.','local_fallback','2026-05-11 08:08:52'),(15,49,11,'Act like an AI bot. Suggest one improvement for my itinerary in Chinese.','Improvement suggestions:\nThe itinerary looks balanced. Check opening hours and live traffic before travel.','local_fallback','2026-05-11 08:30:55'),(16,49,11,'Suggest one improvement in Chinese.','Gemini API error (429): Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing. Learn more at https://ai.google.dev/gemini-api/docs/billing#prepay. ','gemini','2026-05-11 08:32:21'),(17,49,11,'Answer like a travel AI bot. Suggest one improvement for this itinerary in Chinese.','Gemini API error (429): Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing. Learn more at https://ai.google.dev/gemini-api/docs/billing#prepay. ','gemini','2026-05-11 08:35:56'),(18,49,11,'Reply in one short sentence: what is this trip about?','This trip focuses on culture, food, nature, shopping, museums, heritage, and a festival in Johor Bahru, Johor.','ollama','2026-05-11 11:43:56'),(19,49,11,'Reply in one short sentence: what is this trip about?','AI service is currently unavailable. Please make sure Ollama is running.','ollama','2026-05-11 11:46:31'),(20,49,11,'Reply in five words only.','Culture, Food, Heritage, Nature, Shopping','ollama','2026-05-11 11:48:21'),(21,47,11,'hihi','Hello! It seems there might have been a typo in your message. How can I assist you with your itinerary for the 2D Culture & Food Getaway in Johor and Kelantan? Are you looking to understand or improve any part of your planned route?','ollama','2026-05-11 12:04:03'),(22,49,11,'Reply as an AI chatbot in one short sentence.','Your route is planned to visit Kacang Pool Haji Restauran at 10:33 AM for food, followed by a visit to Johor Bahru Old Chinese Temple at 12:13 PM for heritage attraction. The travel time between these two points is estimated to be around 10 minutes.','ollama','2026-05-11 12:06:11'),(23,47,11,'hihi','Hello! It seems there might have been a typo in your message. How can I assist you with your itinerary for the 2D Culture & Food Getaway in Johor and Kelantan? Here’s a brief overview of your planned activities:\n\n- **Day 1:**\n  - Visit Taman Negara Johor Pulau Kukup (Nature Park) in Johor Bahru. Opening hours are from 9:00 AM to 4:00 PM.\n  - For lunch, head to Mee Rebus (Johor Style), located near the nature park. It\'s open from 7:30 AM to 9:30 PM.\n\n- **Day 2:**\n  - Enjoy a meal at Kacang Pool Haji Restauran in Johor Bahru. They are open from 7:00 AM to 12:00 AM.\n  \nIf you need more detailed route instructions or have any questions, please let me know!','ollama','2026-05-11 12:09:36'),(24,47,11,'Act as my travel AI chatbot and explain this itinerary.','Here\'s a breakdown of your 2-day cultural and food getaway itinerary in Johor and Kelantan:\n\n- **Day 1:**\n  - **Morning:** Visit Taman Negara Johor Pulau Kukup (Nature Park) located near Johor Bahru. It\'s open from 9 AM to 4 PM.\n  - Afternoon: Enjoy Mee Rebus (Johor Style), a local restaurant known for its authentic dishes, in Johor Bahru. The restaurant is open from 7:30 AM to 9:30 PM.\n  - Evening: Explore Kacang Pool Haji Restauran for more culinary delights in Johor Bahru, which operates from 7 AM to midnight.\n\n- **Day 2:** \n  - No specific attractions are planned for Day 2. Consider visiting other places of interest or relaxing based on your preferences.\n\nPlease note the distances and travel times are estimated. You might want to check a map for precise directions. Enjoy your trip!','ollama','2026-05-11 12:09:50'),(25,47,11,'How can I improve this itinerary within my budget?','To stay within your $200 budget, consider the following improvements:\n\n- Reduce travel time from Taman Negara Johor Pulau Kukup to Mee Rebus (Johor Style) by 49 minutes.\n- Lower food costs: Skip one of the meal stops or reduce its cost by choosing a less expensive restaurant option.\n\nYou can also explore nearby attractions within Johor for free, like parks and beaches.','ollama','2026-05-11 12:10:15'),(26,47,11,'what is the suggestion for hotel','For your itinerary, there are no specific recommendations for hotels included in the provided context. Here are some suggestions based on your preferences:\n\n- For overnight stays, consider staying in Johor Bahru or other cities within Johor and Kelantan.\n- You could look into budget-friendly options like hostels or guesthouses near attractions such as Taman Negara Johor Pulau Kukup and Mee Rebus (Johor Style).\n- For a more comfortable stay, you might consider booking mid-range hotels in Johor Bahru.\n\nPlease check reviews and amenities to find the best fit for your budget and preferences.','ollama','2026-05-11 12:10:37');
/*!40000 ALTER TABLE `ai_chat_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ai_itinerary_logs`
--

DROP TABLE IF EXISTS `ai_itinerary_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_itinerary_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `destination` varchar(120) NOT NULL,
  `start_location` varchar(180) NOT NULL,
  `days` int(11) NOT NULL,
  `budget` varchar(80) NOT NULL,
  `preferences` mediumtext NOT NULL,
  `ai_response` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_itinerary_user` (`user_id`),
  KEY `idx_ai_itinerary_destination` (`destination`),
  KEY `idx_ai_itinerary_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ai_itinerary_logs`
--

LOCK TABLES `ai_itinerary_logs` WRITE;
/*!40000 ALTER TABLE `ai_itinerary_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_itinerary_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_admin` (`admin_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cultural_place_suggestions`
--

DROP TABLE IF EXISTS `cultural_place_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cultural_place_suggestions` (
  `suggestion_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(1000) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(1000) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_place_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  `review_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`suggestion_id`),
  KEY `idx_status` (`status`),
  KEY `idx_state_category` (`state`,`category`),
  KEY `fk_sugg_traveller` (`traveller_id`),
  KEY `fk_sugg_admin` (`approved_by_admin_id`),
  KEY `fk_suggestions_approved_place` (`approved_place_id`),
  CONSTRAINT `fk_sugg_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sugg_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_suggestions_approved_place` FOREIGN KEY (`approved_place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cultural_place_suggestions`
--

LOCK TABLES `cultural_place_suggestions` WRITE;
/*!40000 ALTER TABLE `cultural_place_suggestions` DISABLE KEYS */;
INSERT INTO `cultural_place_suggestions` VALUES (1,1,'Johor',NULL,'nature','Taman Negara Johor Pulau Kukup','Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.','Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim',1.3227011,103.4283061,'9.00 am–4.00 pm',25.00,'uploads/suggestions/suggest_1766515341_6595.webp','rejected',1,NULL,'2025-12-23 18:42:21',NULL,NULL),(2,1,'Johor',NULL,'nature','Taman Negara Johor Pulau Kukup','Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.','Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim',1.3225724,103.4283275,'9.00 am–4.00 pm',25.00,'uploads/suggestions/suggest_1766562623_1316.webp','approved',1,NULL,'2025-12-24 07:50:23',NULL,NULL),(3,1,'Johor',NULL,'heritage','Johor Bahru Old Chinese Temple (柔佛古庙)','One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）','Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim',1.4606803,103.7630595,'7:00 AM – 6:00 PM',20.00,'uploads/places/suggest_1766814848_5261.webp','approved',1,NULL,'2025-12-27 05:54:08','2025-12-26 23:01:47',NULL),(4,1,'Sarawak',NULL,'culture','Sarawak Cultural Village','Sarawak Cultural Village is a living museum that showcases the traditional lifestyles, houses, and customs of Sarawak’s major ethnic groups such as the Iban, Bidayuh, Orang Ulu, Melanau, Malay, and Chinese. Visitors can explore authentic longhouses, watch cultural dance performances, and learn about traditional crafts, music, and daily practices. The village provides an immersive cultural experience that helps preserve and promote Sarawak’s diverse heritage.','Pantai Damai, Santubong, Kuching, Sarawak, Malaysia',1.7389000,110.3205000,'Everyday 09:00-17:00',0.00,'uploads/places/suggest_1767885223_8329.webp','approved',1,25,'2026-01-08 15:13:43','2026-01-08 08:14:53',NULL),(5,11,'Penang',NULL,'heritage','George Town (UNESCO World Heritage)','George Town, the capital of the Malaysian state of Penang, is a UNESCO World Heritage Site recognized for its unique, multicultural architectural and cultural townscape, which developed over 500 years of East-West trade in the Straits of Malacca. It was jointly inscribed on the World Heritage List in 2008 with Melaka.','159, Lbh Chulia, George Town, 10200 George Town, Pulau Pinang',5.4171319,100.3379453,'Monday to Friday, 9 am–5 pm',0.00,'uploads/places/suggest_1768412143_8335.webp','pending',NULL,NULL,'2026-01-14 17:35:43',NULL,NULL),(6,11,'Melaka',NULL,'heritage','The Red Square Historic Melaka (Stadthuys)','The \"Red House\" in Melaka refers to the iconic Stadthuys, a massive terracotta-red complex located at the heart of the historic Dutch Square. Built by the Dutch in 1650 on the ruins of a Portuguese fort, it is widely recognized as the oldest surviving Dutch colonial building in the East. For over 300 years, this striking structure—spanning nearly 50,000 square feet—served as the official residence of Dutch governors and as the administrative center for successive colonial governments. While it is famous today for its bold crimson walls, the building was actually painted white during the Dutch era; the characteristic red hue was later introduced by the British in the 19th century to create aesthetic uniformity across the district. \r\nToday, the Stadthuys serves as the History and Ethnography Museum, preserving Melaka\'s multi-layered heritage through a vast collection of artifacts, traditional costumes, and dioramas. Visitors can still admire the original Dutch architecture, characterized by thick masonry walls, heavy wooden beams, and louvered windows. The building anchors a vibrant tourist hub that includes other red-themed landmarks like the Christ Church, the Melaka Clock Tower, and the Queen Victoria Fountain, all of which contribute to the area\'s designation as a UNESCO World Heritage site. Surrounded by brightly decorated trishaws and located just steps from Jonker Street, it remains the most recognizable symbol of Melaka’s colonial past.','31, Jalan Laksamana, Banda Hilir, 75000 Melaka',2.1941880,102.2490770,'Sunday, 9 am–9 pm | Monday, Closed | Tuesday, 9 am–5:30 pm | Wednesday, 9 am–5:30 pm | Thursday, 9 am–5:30 pm | Friday, ',0.00,'uploads/places/suggest_1768412766_2760.jpg','approved',1,47,'2026-01-14 17:46:06','2026-01-14 18:21:41',NULL);
/*!40000 ALTER TABLE `cultural_place_suggestions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cultural_places`
--

DROP TABLE IF EXISTS `cultural_places`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cultural_places` (
  `place_id` int(11) NOT NULL AUTO_INCREMENT,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `category` enum('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `opening_hours` varchar(1000) DEFAULT NULL,
  `festival_start_date` date DEFAULT NULL,
  `festival_end_date` date DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `entrance_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `is_free` tinyint(1) NOT NULL DEFAULT 0,
  `image_url` varchar(1000) DEFAULT NULL,
  `image_path` varchar(1000) DEFAULT NULL,
  `halal_status` tinyint(1) DEFAULT NULL,
  `is_outdoor` tinyint(1) DEFAULT NULL,
  `visit_duration_min` int(11) NOT NULL DEFAULT 90,
  `best_time_to_visit` varchar(100) DEFAULT NULL,
  `dress_code_required` tinyint(1) DEFAULT NULL,
  `website_url` varchar(300) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `avg_rating` decimal(3,2) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`place_id`),
  KEY `idx_state_category` (`state`,`category`),
  KEY `fk_place_admin` (`created_by_admin_id`),
  KEY `idx_cultural_festival_dates` (`category`,`festival_start_date`,`festival_end_date`),
  CONSTRAINT `fk_place_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cultural_places`
--

LOCK TABLES `cultural_places` WRITE;
/*!40000 ALTER TABLE `cultural_places` DISABLE KEYS */;
INSERT INTO `cultural_places` VALUES (1,'Johor','Johor Bahru','food','Kacang Pool Haji Restauran (Johor Bahru)','Local Johor breakfast dish, commonly served with bread.Kacang Pool Haji Restaurant is an iconic and must-visit culinary destination in Johor Bahru, famous for its signature dish, Kacang Pool Haji, which has been captivating the palates of many visitors since 2009. The restaurant offers a rich, rich, and satisfying Johor dining experience, perfect for breakfast, lunch, dinner, or even a late-night snack.','12, Jalan Dato Jaafar, Taman Dato Onn, 80350 Johor Bahru, Johor Darul Ta\'zim',1.4907395,103.7515533,'7am - 12am',NULL,NULL,8.00,8.00,0,'uploads/places/place_1766837821_4033.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-05-09 16:48:45'),(2,'Johor','Johor Bahru','food','Mee Rebus (Johor Style)','Mee Rebus Selera Johor (Warisan Keluarga Hj. Wahid) is famous for its authentic Johor dishes based on family recipes passed down through generations since 1948. It serves a variety of iconic Malaysian dishes, with a main focus on the mee rebus with a thick and flavorful peanut sauce. Traditional Johor-style mee rebus with rich gravy.','Johor Bahru, Johor',1.4927000,103.7414000,'7:30 am–9:30 pm',NULL,NULL,15.00,15.00,0,'uploads/places/place_1766835810_7693.png',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-05-09 16:48:45'),(3,'Johor','Batu Pahat','heritage','Chong Long Gong Temple','Historic temple representing Chinese community heritage.Chong Long Gong Temple is a unique and vibrant Chinese temple located by the sea in the Kampung Segenting fishing village of Batu Pahat, Johor, Malaysia. It is famous for its large arapaima fish, believed to bring good fortune to those who touch them.','81, Kampung Segenting, Batu Pahat, 83030 Bandar Penggaram, Johor',1.7836877,102.8926308,'9:00 AM - 6:00 PM',NULL,NULL,0.00,0.00,1,'uploads/places/place_1766836350_7759.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-04-25 17:44:50'),(4,'Johor','Johor Bahru','museum','Johor Bahru Chinese Heritage Museum','Museum showcasing Johor Bahru Chinese heritage and history.The Johor Bahru Chinese Heritage Museum (Malay: Muzium Warisan Tionghua Johor Bahru) is a museum in Johor Bahru, Johor, Malaysia. The museum is about the history of Chinese community in Johor Bahru. Collections in the museum include documents, music instruments, old money, photos, porcelain etc. It showcases the early days of the Chinese settlement in Johor Bahru, their history, culture, traditions and occupations.','Johor Bahru, Johor',1.4579000,103.7646000,'9.00 a.m. - 5.00 p.m. except Mondays',NULL,NULL,6.00,6.00,0,'uploads/places/place_1766836057_2242.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-05-09 16:48:45'),(5,'Kedah','Kota Setar','nature','Gunung Jerai','Popular mountain attraction with scenic views. Gunung Jerai is the highest peak in Kedah, Malaysia, a unique island-shaped mountain (inselberg) that is easily visible from afar, serving as a landmark and sea navigation since ancient times, now a popular tourist destination with a resort at the top, recreational activities such as hiking, cycling, paragliding, as well as rich geological and botanical treasures, with panoramic views towards the rice fields and the Straits of Malacca.','Yan, Kedah',5.7887000,100.4246000,'08:00-18:00',NULL,NULL,0.00,0.00,1,'uploads/places/place_1766835360_5565.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-04-25 17:44:50'),(6,'Kedah','Kota Setar','museum','Kedah State Museum','Museum presenting history and culture of Kedah. The history of the establishment of the Kedah State Museum began on 3 February 1957, known as the Kedah History Museum. The museum, located on the ground floor of the Balai Besar, Alor Setar, was officiated by YAB Tan Sri Tunku Ismail bin Tunku Yahya, the 2nd Menteri Besar of Kedah Darul Aman. The increase in the number of collections caused the museum to be moved to its own building (next to the new museum building) on ​​the Darul Aman Highway, Bakar Bata on 30 December 1961. In July 1964, the Kedah History Museum was changed its name to the Kedah State Museum.\r\n\r\nGiven the good response from the public, the State Government agreed to build the current building in 1997. This building houses an exhibition hall, workshop, library, and mini theatrette. There are 10 permanent exhibition galleries such as the Cultural Hall, the History Hall, the Nature Hall, the Heroes Hall, the Transport Hall, the Weapons Hall, the Manuscript Hall, the Textile Hall, the Arts and Crafts Hall and the Language and Literature Corner. Apart from that, its facilities have also been improved by providing an elevator, cafeteria, car parking and public toilets.','Alor Setar, Kedah',6.1210000,100.3680000,'09:00-17:00',NULL,NULL,5.00,5.00,0,'uploads/places/place_1766835098_6695.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-05-09 16:48:45'),(7,'Kedah','Kota Setar','food','Din Laksa Teluk Kechai','Famous Kedah laksa experience.This legendary Laksa in 1967 when it was sold by Din himself on his gerek going from house to house.','No 246 Batu 4 1/4 jalan kuala kedah, Alor Setar 06600 Malaysia',6.0949606,100.3206236,'3:00 PM - 8:00 PM',NULL,NULL,15.00,15.00,0,'uploads/places/place_1766831415_1464.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2025-12-15 01:26:21','2026-05-09 16:48:45'),(8,'Johor','Johor Bahru','nature','Taman Negara Johor Pulau Kukup','Pulau Kukup is one of the world’s largest uninhabited mangrove islands and a protected national park in Johor. The island is well known for its wooden boardwalks, rich mangrove ecosystem, and traditional fishing village culture, offering visitors a unique combination of natural conservation and cultural heritage.','Lot 1319, Mukim, 82300 Kukup, Johor Darul Ta\'zim',1.3225724,103.4283275,'9.00 am–4.00 pm',NULL,NULL,25.00,25.00,0,'uploads/places/place_1766813513_1914.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-23 23:50:41','2026-05-09 16:48:45'),(9,'Johor','Johor Bahru','heritage','Johor Bahru Old Chinese Temple (柔佛古庙)','One of the oldest Chinese temples in Johor Bahru, serving as an important spiritual and cultural landmark for the local Chinese community. It is closely associated with the annual Johor Bahru Chingay procession, where different Chinese clans and associations participate in traditional rituals, performances, and parades. The temple reflects the city’s multicultural heritage and is often visited by travellers who want to experience local religious traditions, community history, and the atmosphere of the old town area.（柔佛新山历史悠久的华人庙宇之一，是当地华人社群重要的信仰与文化地标。它与每年新山古庙游神（Chingay）密切相关，活动期间各籍贯与会馆参与传统祭祀、表演与游行，展现地方社群的凝聚力与多元文化。游客可在此了解本地宗教习俗、社区历史，并感受老城区的文化氛围。）','Lot 653, Jalan Trus, Bandar Johor Bahru, 80000 Johor Bahru, Johor Darul Ta\'zim',1.4606803,103.7630595,'7:00 AM – 6:00 PM',NULL,NULL,20.00,20.00,0,'uploads/places/suggest_1766814848_5261.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-26 21:54:44','2026-05-09 16:48:45'),(10,'Selangor','Petaling Jaya','nature','Forest Research Institute Malaysia (FRIM)','Forest park with canopy walk, nature trails, and educational eco-tourism experiences.','Forest Research Institute Malaysia (FRIM), 52109 Kepong, Selangor, Malaysia',3.2353390,101.6342690,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_10_169294a803_20260105_120730_5e4700.jpg','uploads/places/place_10_169294a803_20260105_120730_5e4700.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(11,'Selangor','Petaling Jaya','festival','Thaipusam at Batu Caves','Major religious-cultural festival with pilgrimages, rituals, and vibrant community participation.','Batu Caves Temple, Gombak, 68100 Batu Caves, Selangor, Malaysia',3.2374000,101.6839070,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_11_30123df278_20260105_120731_8f5f34.jpg','uploads/places/place_11_30123df278_20260105_120731_8f5f34.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(12,'Sarawak','Miri','heritage','Niah Caves (Niah National Park)','Archaeological and cultural heritage caves complex known for early human history and cave exploration.','Niah National Park, Batu Niah, 98200 Miri, Sarawak, Malaysia',3.8083000,113.7755000,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_12_85ade11fe5_20260105_120734_5496bb.jpg','uploads/places/place_12_85ade11fe5_20260105_120734_5496bb.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(13,'Sarawak','Kuching','culture','Sarawak Cultural Village','Living museum showcasing Sarawak ethnic groups with traditional houses and cultural performances.','Sarawak Cultural Village, Pantai Damai, Santubong, 93752 Kuching, Sarawak, Malaysia',1.7497100,110.3169800,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_13_1dada0c29d_20260105_120735_8c494b.jpg','uploads/places/place_13_1dada0c29d_20260105_120735_8c494b.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(14,'Sarawak','Kuching','festival','Rainforest World Music Festival','Signature music and cultural festival combining world music showcases with local cultural elements.','Sarawak Cultural Village, Pantai Damai, Santubong, 93752 Kuching, Sarawak, Malaysia',1.7497100,110.3169800,'',NULL,NULL,0.00,0.00,1,'uploads/places/place_1766958248_6687.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(15,'Sarawak','Kuching','nature','Gunung Mulu National Park','UNESCO natural site famous for limestone karst formations, rainforest, and extensive cave systems.','Gunung Mulu National Park HQ, 98070 Mulu, Sarawak, Malaysia',4.1320000,114.9190000,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_15_d451eb69ae_20260105_121459_5c8d18.jpg','uploads/places/place_15_d451eb69ae_20260105_121459_5c8d18.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(16,'Perak','Kinta','heritage','Archaeological Heritage of the Lenggong Valley','UNESCO-listed archaeological landscape featuring significant prehistoric findings and sites.','Lenggong Valley (Lembah Lenggong), 33400 Lenggong, Perak, Malaysia',NULL,NULL,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_16_28a30c79d0_20260105_121504_08dcd3.jpg','uploads/places/place_16_28a30c79d0_20260105_121504_08dcd3.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(17,'Penang','Timur Laut','heritage','Cheong Fatt Tze Mansion (The Blue Mansion)','Straits Chinese heritage mansion offering guided tours highlighting architecture and Peranakan influence.','14, Leith Street, 10200 George Town, Penang, Malaysia',5.4213194,100.3352500,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_17_0f1d7f3b50_20260105_120749_69e67c.jpg','uploads/places/place_17_0f1d7f3b50_20260105_120749_69e67c.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(18,'Penang','Timur Laut','festival','George Town Festival','Annual arts and culture festival featuring performances, exhibitions, and heritage-focused programs.','George Town Festival Office, 1st Floor, 86 Lebuh Armenian, 10200 George Town, Penang, Malaysia',5.4154392,100.3370691,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_18_fe4e4116bd_20260105_120753_530144.jpg','uploads/places/place_18_fe4e4116bd_20260105_120753_530144.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(19,'Melaka','Melaka Tengah','museum','Baba & Nyonya Heritage Museum','Peranakan house museum showcasing traditional lifestyle, artifacts, and cultural history in historic Melaka.','48 & 50, Jalan Tun Tan Cheng Lock, 75200 Melaka, Malaysia',2.1952670,102.2466570,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_19_c023100102_20260105_120755_1fe0c4.jpg','uploads/places/place_19_c023100102_20260105_120755_1fe0c4.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(20,'Kuala Lumpur','City Centre (KLCC)','museum','Islamic Arts Museum Malaysia (IAMM)','Major museum showcasing Islamic art, design, and cultural heritage collections.','Islamic Arts Museum Malaysia, Jalan Lembah Perdana, 50480 Kuala Lumpur, Malaysia',3.1418340,101.6886180,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_20_4fd8bba161_20260105_121510_b26c9a.jpg','uploads/places/place_20_4fd8bba161_20260105_121510_b26c9a.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(21,'Kuala Lumpur','City Centre (KLCC)','museum','Muzium Negara (National Museum)','National museum presenting Malaysia history, culture, and nation-building narratives.','Muzium Negara, Jalan Damansara, 50566 Kuala Lumpur, Malaysia',3.1379960,101.6870430,'09:00-17:00',NULL,NULL,0.00,0.00,1,'uploads/places/place_21_60f3eb5978_20260105_121514_5d3aa2.jpg','uploads/places/place_21_60f3eb5978_20260105_121514_5d3aa2.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(22,'Terengganu','Kuala Terengganu','shopping','Pasar Payang','Traditional market for local products such as textiles, snacks, souvenirs, and crafts.','Pasar Payang, Jalan Sultan Zainal Abidin, 20200 Kuala Terengganu, Terengganu, Malaysia',5.3300000,103.1380000,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_22_c8b317e8dd_20260105_120808_88a923.jpg','uploads/places/place_22_c8b317e8dd_20260105_120808_88a923.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(23,'Sabah','Kota Kinabalu','culture','Monsopiad Heritage Village','The Monsopiad Heritage Village is a private living museum in Penampang, Sabah, established on the historical site where the legendary Kadazandusun warrior and headhunter, Monsopiad, lived over 300 years ago. Managed by his direct descendants, the village serves to preserve and document the traditions, beliefs, and rich heritage of Sabah’s largest indigenous group. Visitors are offered a deep dive into the past through immersive storytelling, traditional architecture, and interactive displays that showcase the lifestyle and spiritual practices of the Kadazandusun community.\r\n \r\nKey Highlights:\r\n-House of Skulls (Siou Do Mohoing): The village\'s centerpiece, which houses 42 \"trophies\" — the actual skulls of enemies defeated by Monsopiad.\r\n-Massive Monolith (Gintutun do Mohoing): A sacred stone standing four meters tall, believed to be inhabited by spirits and used in grand rituals.\r\n-Cultural Performances: Traditional music and dance shows, including the Sumazau and Magunatip (bamboo) dances, often featuring audience participation.\r\n-Interactive Activities: Hands-on experiences such as blowpipe shooting, fire-making, catapult use, and walking on bamboo stilts.\r\n-Traditional Delicacies: Opportunities to sample local rice wine (lihing or tapai), traditional snacks, and for the adventurous, live sago worms (butod).\r\n-Heritage Structures: Authentic replicas of traditional dwellings, including a grainery (tangkob) and the main house dedicated to Monsopiad’s legacy.','Kampung Kuai Kandazon, Jalan Putatan Ramayah, 89507 Penampang, Sabah',5.8888540,116.0792531,'Monday to Sunday, 9 am–4:30 pm',NULL,NULL,225.00,225.00,0,'uploads/places/place_1768401621_9972.jpg','uploads/places/place_23_95717a344d_20260105_120813_bd78dc.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-05-09 16:48:45'),(24,'Sabah','Kota Kinabalu','nature','Kinabalu Park','UNESCO natural site known for biodiversity and as the gateway to Mount Kinabalu.','Kinabalu Park Headquarters, 89307 Ranau, Sabah, Malaysia',6.0055351,116.5422225,NULL,NULL,NULL,0.00,0.00,1,'uploads/places/place_24_7be148a719_20260105_120836_02a8a1.jpg','uploads/places/place_24_7be148a719_20260105_120836_02a8a1.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2025-12-27 01:36:07','2026-04-25 17:44:50'),(25,'Sarawak','Kuching','culture','Sarawak Cultural Village','Sarawak Cultural Village is a living museum that showcases the traditional lifestyles, houses, and customs of Sarawak’s major ethnic groups such as the Iban, Bidayuh, Orang Ulu, Melanau, Malay, and Chinese. Visitors can explore authentic longhouses, watch cultural dance performances, and learn about traditional crafts, music, and daily practices. The village provides an immersive cultural experience that helps preserve and promote Sarawak’s diverse heritage.','Pantai Damai, Santubong, Kuching, Sarawak, Malaysia',1.7389000,110.3205000,'Everyday 09:00-17:00',NULL,NULL,0.00,0.00,1,'uploads/places/suggest_1767885223_8329.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-08 15:14:53','2026-04-25 17:44:50'),(26,'Selangor','Petaling Jaya','heritage','Batu Caves','Major Hindu temple complex inside limestone caves; iconic Murugan statue and long stairway.','Gombak, 68100 Batu Caves, Selangor, Malaysia',3.2374000,101.6839070,'Daily 6:00 AM–9:00 PM',NULL,NULL,0.00,0.00,1,'uploads/places/place_26_ecff8ff002_20260114_152513_f8eacd.jpg','uploads/places/place_26_ecff8ff002_20260114_152513_f8eacd.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-04-25 17:44:50'),(27,'Kuala Lumpur','City Centre (KLCC)','museum','Muzium Negara (National Museum of Malaysia)','Malaysia’s national museum showcasing history, culture, ethnography, and national heritage galleries.','Jalan Damansara, 50566 Kuala Lumpur, Malaysia',3.1380960,101.6867280,'Daily 9:00 AM–5:00 PM (Last entry 4:30 PM)',NULL,NULL,5.00,5.00,0,'uploads/places/place_27_380596f94e_20260114_152520_1a15c1.jpg','uploads/places/place_27_380596f94e_20260114_152520_1a15c1.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(28,'Kelantan','Kota Bharu','museum','Istana Jahar (Kelantan Traditional Royal Ceremonies Museum)','Royal palace museum featuring Kelantanese royal ceremonies, traditional customs, and cultural exhibits.','Jalan Sultan, Bandar Kota Bharu, 15000 Kota Bharu, Kelantan, Malaysia',6.1315200,102.2371300,'Daily 8:30 AM–5:45 PM (Closed Fridays)',NULL,NULL,2.00,2.00,0,'uploads/places/place_28_7acb892af4_20260114_152526_98645c.jpg','uploads/places/place_28_7acb892af4_20260114_152526_98645c.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(29,'Sarawak','Kuching','culture','Sarawak Cultural Village','Living museum with traditional longhouses of Sarawak ethnic groups and scheduled cultural performances.','Pantai Damai, Santubong, 93752 Kuching, Sarawak, Malaysia',1.7497100,110.3169800,'Daily 9:00 AM–5:00 PM',NULL,NULL,90.00,90.00,0,'uploads/places/place_29_ec942f8173_20260114_152529_539b4c.jpg','uploads/places/place_29_ec942f8173_20260114_152529_539b4c.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(30,'Sabah','Kota Kinabalu','culture','Mari Mari Cultural Village','Interactive cultural village showcasing Sabah indigenous traditions, houses, crafts, and local food experiences.','Kionsom Road, Inanam, 88450 Kota Kinabalu, Sabah, Malaysia',5.9738777,116.2034420,'Daily (Sessions) 9:00 AM–1:00 PM & 2:00 PM–5:00 PM',NULL,NULL,110.00,110.00,0,'uploads/places/place_30_bce6ca8f95_20260114_152538_2b7104.jpg','uploads/places/place_30_bce6ca8f95_20260114_152538_2b7104.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(31,'Kuala Lumpur','City Centre (KLCC)','heritage','Kampung Baru','Traditional Malay enclave in central Kuala Lumpur known for heritage village atmosphere and local Malay food.','Jalan Raja Abdullah, Kampung Baru, 50300 Kuala Lumpur, Malaysia',3.1630240,101.7061401,'Always open (public area)',NULL,NULL,0.00,0.00,1,'uploads/places/place_31_0beb0a3e93_20260114_152539_e627be.jpg','uploads/places/place_31_0beb0a3e93_20260114_152539_e627be.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-04-25 17:44:50'),(32,'Penang','Timur Laut','heritage','Kek Lok Si Temple','One of the largest Buddhist temple complexes in Malaysia, located at Ayer Itam with pagoda and hilltop statue.','Jalan Balik Pulau, 11500 Ayer Itam, Penang, Malaysia',5.4002200,100.2738400,'Daily 8:30 AM–5:30 PM',NULL,NULL,0.00,0.00,1,'uploads/places/place_1768401054_5697.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-04-25 17:44:50'),(33,'Melaka','Melaka Tengah','shopping','Jonker Street (Jalan Hang Jebat)','Historic street famous for Peranakan heritage vibe, street food, and weekend night market scene.','Jalan Hang Jebat (Jonker Street), 75200 Melaka, Malaysia',2.1953220,102.2479640,'Street: Always open; Night Market: Fri–Sun (Evening)',NULL,NULL,0.00,0.00,1,'uploads/places/place_33_7f5298026e_20260114_152625_508faa.jpg','uploads/places/place_33_7f5298026e_20260114_152625_508faa.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-04-25 17:44:50'),(34,'Melaka','Melaka Tengah','museum','Melaka Sultanate Palace Museum (Muzium Istana Kesultanan Melaka)','Replica palace museum based on Malay Annals, exhibiting Melaka Sultanate history and cultural artifacts.','Kompleks Warisan Melaka, Jalan Kota, 75000 Melaka, Malaysia',2.1928200,102.2505000,'Sat–Thu 9:00 AM–5:00 PM; Fri 9:00 AM–12:15 PM & 2:45 PM–5:00 PM',NULL,NULL,5.00,5.00,0,'uploads/places/place_34_219d1e7044_20260114_152632_ad2a34.jpg','uploads/places/place_34_219d1e7044_20260114_152632_ad2a34.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(35,'Penang','Timur Laut','museum','Pinang Peranakan Mansion','Historic Straits Chinese mansion museum showcasing Peranakan (Baba-Nyonya) culture, antiques, and interior décor.','29, Church Street, 10200 George Town, Penang, Malaysia',5.4179990,100.3411920,'Daily 9:30 AM–5:30 PM',NULL,NULL,25.00,25.00,0,'uploads/places/place_35_559f960576_20260114_152648_534b6e.jpg','uploads/places/place_35_559f960576_20260114_152648_534b6e.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 07:09:09','2026-05-09 16:48:45'),(36,'Putrajaya','Putrajaya','heritage','Masjid Putra (Putra Mosque)','Ikon seni bina Putrajaya dengan kubah merah jambu di tepi Tasik Putrajaya. Sesuai untuk lawatan warisan dan fotografi.','Persiaran Persekutuan, Presint 1, 62502 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9363059,101.6892061,'Daily 09:00–12:30, 14:00–16:00 (visitor hours; closed during prayer times)',NULL,NULL,0.00,0.00,1,'uploads/places/place_36_27c635b67a_20260114_162240_71b368.jpg','uploads/places/place_36_27c635b67a_20260114_162240_71b368.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(37,'Putrajaya','Putrajaya','culture','Astaka Morocco (Moroccan Pavilion Putrajaya)','Pavilion berinspirasi seni bina Maghribi dengan mozek dan gerbang yang unik. Lokasi popular untuk pengalaman budaya dan fotografi.','Persiaran Sultan Salahuddin Abdul Aziz Shah, Presint 1, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9431000,101.6934000,'Daily 09:00–18:00',NULL,NULL,0.00,0.00,1,'uploads/places/place_1768404589_4164.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(38,'Putrajaya','Putrajaya','museum','Muzium & Galeri Putrajaya','Ruang pameran yang memaparkan bahan berkaitan sejarah, pembangunan dan identiti Putrajaya melalui pameran berkala.','Presint 4, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9149000,101.6839000,'Mon–Sun 09:00–17:00 (may vary by event/season)',NULL,NULL,0.00,0.00,1,'uploads/places/place_1768404549_3397.webp',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(39,'Putrajaya','Putrajaya','food','Medan Selera Presint 9 (Putrajaya)','Lokasi makanan tempatan dengan pelbagai gerai: masakan Melayu, mi, nasi, dan minuman. Sesuai untuk pengalaman gastronomi harian dengan bajet sederhana.','Presint 9, 62250 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.8968000,101.6909000,'Daily 08:00–22:00',NULL,NULL,20.00,20.00,0,'uploads/places/place_1768404351_3690.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-05-09 16:48:45'),(40,'Putrajaya','Putrajaya','festival','Anjung Floria Putrajaya','Lokasi utama penganjuran Royal Floria Putrajaya (festival bunga) dan acara bermusim. Kawasan landskap sesuai untuk pameran dan fotografi.','Jalan Anjung Floria, Presint 4, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9126110,101.6771720,'Daily 07:00–22:00 (park area; event schedules are seasonal)',NULL,NULL,0.00,0.00,1,'uploads/places/place_40_b6b991191b_20260114_162259_a0fc88.jpg','uploads/places/place_40_b6b991191b_20260114_162259_a0fc88.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(41,'Putrajaya','Putrajaya','nature','Taman Botani Putrajaya (Putrajaya Botanical Garden)','Taman botani yang luas dengan zon landskap, denai berjalan kaki dan ruang rekreasi. Sesuai untuk riadah dan pembelajaran flora.','Lebuh Perdana Barat, Presint 1, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9448069,101.6955157,'Daily 07:00–19:00',NULL,NULL,0.00,0.00,1,'uploads/places/place_41_c88b978354_20260114_162303_bd4af5.jpg','uploads/places/place_41_c88b978354_20260114_162303_bd4af5.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(42,'Putrajaya','Putrajaya','nature','Taman Wetland Putrajaya (Putrajaya Wetlands Park)','Kawasan wetland buatan yang menyokong biodiversiti dan pendidikan ekologi. Sesuai untuk pemerhatian alam dan aktiviti santai.','Jalan P11, Presint 13, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9618472,101.6955194,'Daily 07:00–19:00',NULL,NULL,10.00,10.00,0,'uploads/places/place_42_2af8e4dc28_20260114_162305_f02425.jpg','uploads/places/place_42_2af8e4dc28_20260114_162305_f02425.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-05-09 16:48:45'),(43,'Putrajaya','Putrajaya','shopping','Alamanda Shopping Centre','Pusat beli-belah utama di Putrajaya dengan pelbagai kedai runcit, F&B dan kemudahan keluarga.','Jalan Alamanda 2, Presint 1, 62000 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9395140,101.7111320,'Daily 10:00–22:00',NULL,NULL,0.00,0.00,1,'uploads/places/place_43_8fa0ae2b38_20260114_162309_10fc4c.jpg','uploads/places/place_43_8fa0ae2b38_20260114_162309_10fc4c.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(44,'Putrajaya','Putrajaya','culture','Masjid Tuanku Mizan Zainal Abidin (Iron Mosque)','Masjid moden berstruktur keluli yang ikonik, terkenal dengan reka bentuk kontemporari. Sesuai untuk lawatan budaya dan fotografi.','Persiaran Persekutuan, Presint 3, 62502 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9191718,101.6811676,'Daily 09:00–17:00 (visitor hours; closed during prayer times)',NULL,NULL,0.00,0.00,1,'uploads/places/place_44_aad8660b51_20260114_162310_1cebe3.jpg','uploads/places/place_44_aad8660b51_20260114_162310_1cebe3.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-04-25 17:44:50'),(45,'Putrajaya','Putrajaya','heritage','Taman Warisan Pertanian (Agriculture Heritage Park)','Taman berkonsep warisan pertanian yang mempamerkan elemen tanaman dan pendidikan agro. Sesuai untuk lawatan keluarga dan pembelajaran asas pertanian.','Presint 16, 62150 Putrajaya, Wilayah Persekutuan Putrajaya, Malaysia',2.9279160,101.7003360,'Daily 09:00–17:00',NULL,NULL,5.00,5.00,0,'uploads/places/place_1768404637_5000.jpg','uploads/places/place_45_a8fc59b2fd_20260114_162313_0068cd.jpg',NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 15:22:24','2026-05-09 16:48:45'),(46,'Selangor','Shah Alam','museum','Shah Alam Museum','The Shah Alam Museum, also known as the Sultan Alam Shah Museum, is a cultural gem nestled in the heart of Selangor’s capital, offering a deep dive into the state’s rich history, heritage, and traditions. Housed in a striking modern building, the museum features a wide range of exhibits, including artifacts from Selangor’s royal family, traditional Malay weapons, ancient pottery, and displays on the state’s natural history and industrial development. The museum also showcases Selangor’s multicultural identity, with exhibits on the contributions of the Malay, Chinese, Indian, and indigenous communities to the state’s growth. Whether you’re a history enthusiast or simply curious about Selangor’s past, the Shah Alam Museum is a must-visit for an enriching and educational experience.','Persiaran Bandaraya, Seksyen 14, 40000 Shah Alam, Selangor',0.0000000,0.0000000,'Tuesday-Sunday, 9:30 am–4:30 pm | Monday, Closed',NULL,NULL,5.00,5.00,0,'uploads/places/place_1768413945_3233.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,'2026-01-14 18:05:45','2026-05-09 16:48:45'),(47,'Melaka','Melaka Tengah','heritage','The Red Square Historic Melaka (Stadthuys)','The \"Red House\" in Melaka refers to the iconic Stadthuys, a massive terracotta-red complex located at the heart of the historic Dutch Square. Built by the Dutch in 1650 on the ruins of a Portuguese fort, it is widely recognized as the oldest surviving Dutch colonial building in the East. For over 300 years, this striking structure—spanning nearly 50,000 square feet—served as the official residence of Dutch governors and as the administrative center for successive colonial governments. While it is famous today for its bold crimson walls, the building was actually painted white during the Dutch era; the characteristic red hue was later introduced by the British in the 19th century to create aesthetic uniformity across the district. \r\nToday, the Stadthuys serves as the History and Ethnography Museum, preserving Melaka\'s multi-layered heritage through a vast collection of artifacts, traditional costumes, and dioramas. Visitors can still admire the original Dutch architecture, characterized by thick masonry walls, heavy wooden beams, and louvered windows. The building anchors a vibrant tourist hub that includes other red-themed landmarks like the Christ Church, the Melaka Clock Tower, and the Queen Victoria Fountain, all of which contribute to the area\'s designation as a UNESCO World Heritage site. Surrounded by brightly decorated trishaws and located just steps from Jonker Street, it remains the most recognizable symbol of Melaka’s colonial past.','31, Jalan Laksamana, Banda Hilir, 75000 Melaka',2.1941880,102.2490770,'Sunday, 9 am–9 pm | Monday, Closed | Tuesday, 9 am–5:30 pm | Wednesday, 9 am–5:30 pm | Thursday, 9 am–5:30 pm | Friday, ',NULL,NULL,0.00,0.00,1,'uploads/places/suggest_1768412766_2760.jpg',NULL,NULL,NULL,90,NULL,NULL,NULL,NULL,NULL,NULL,1,1,'2026-01-15 01:21:41','2026-04-25 17:44:50');
/*!40000 ALTER TABLE `cultural_places` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `food_places`
--

DROP TABLE IF EXISTS `food_places`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `food_places` (
  `food_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `cuisine_type` varchar(80) DEFAULT NULL,
  `avg_price` decimal(10,2) DEFAULT 15.00,
  `rating` decimal(3,1) DEFAULT 3.5,
  `opening_hour` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`food_id`),
  KEY `idx_state` (`state`),
  KEY `idx_coords` (`latitude`,`longitude`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `food_places`
--

LOCK TABLES `food_places` WRITE;
/*!40000 ALTER TABLE `food_places` DISABLE KEYS */;
INSERT INTO `food_places` VALUES (1,'Kacang Pool Haji Restauran','Johor','Johor Bahru',1.4600000,103.7600000,'Malay',8.00,4.2,'07:00 - 22:00',1,'2026-04-13 15:10:43'),(2,'Mee Rebus Haji Wahid','Johor','Johor Bahru',1.4620000,103.7580000,'Malay',7.00,4.3,'07:00 - 15:00',1,'2026-04-13 15:10:43'),(3,'Nasi Kandar Pelita','Kuala Lumpur','City Centre (KLCC)',3.1580000,101.7200000,'Indian',12.00,4.2,'24 hours',1,'2026-04-13 15:10:43'),(4,'Restoran Yut Kee','Kuala Lumpur','Chow Kit',3.1650000,101.7010000,'Chinese',18.00,4.4,'07:30 - 16:00',1,'2026-04-13 15:10:43'),(5,'Penang Road Famous Teochew Cendol','Penang','Timur Laut',5.4160000,100.3340000,'Dessert',5.00,4.6,'10:30 - 18:00',1,'2026-04-13 15:10:43'),(6,'Lorong Baru Char Koay Teow','Penang','Timur Laut',5.4180000,100.3360000,'Chinese',8.00,4.5,'10:00 - 18:00',1,'2026-04-13 15:10:43'),(7,'Jonker Street Night Market','Melaka','Melaka Tengah',2.1960000,102.2490000,'Mixed',10.00,4.4,'18:00 - 23:00',1,'2026-04-13 15:10:43'),(8,'Nasi Kerabu Kak Yah','Kelantan','Kota Bharu',6.1230000,102.2360000,'Malay',6.00,4.5,'07:00 - 14:00',1,'2026-04-13 15:10:43'),(9,'Nasi Lemak Antarabangsa Alor Setar','Kedah','Kota Setar',6.1220000,100.3720000,'Malay',5.00,4.2,'06:00 - 14:00',1,'2026-04-13 15:10:43'),(10,'Restoran Sri Paandi','Selangor','Shah Alam',3.0850000,101.5320000,'Indian',12.00,4.3,'07:00 - 22:00',1,'2026-04-13 15:10:43'),(11,'Restoran Ikan Bakar Kuantan','Pahang','Kuantan',3.8050000,103.3250000,'Seafood',25.00,4.3,'11:00 - 22:00',1,'2026-04-13 15:10:43'),(12,'Restoran Sri Melaka Kota Kinabalu','Sabah','Kota Kinabalu',5.9780000,116.0750000,'Malay',15.00,4.1,'10:00 - 22:00',1,'2026-04-13 15:10:43'),(13,'Top Spot Food Court Kuching','Sarawak','Kuching',1.5580000,110.3430000,'Seafood',25.00,4.4,'17:00 - 23:00',1,'2026-04-13 15:10:43'),(14,'Nasi Dagang Atas Tol','Terengganu','Kuala Terengganu',5.3300000,103.1400000,'Malay',6.00,4.5,'07:00 - 14:00',1,'2026-04-13 15:10:43'),(15,'Restoran Foh San Ipoh','Perak','Ipoh',4.5980000,101.0880000,'Chinese',15.00,4.5,'06:00 - 14:00',1,'2026-04-13 15:10:43'),(16,'Restoran Seri Menanti','Negeri Sembilan','Seremban',2.7300000,101.9400000,'Malay',10.00,4.2,'08:00 - 22:00',1,'2026-04-13 15:10:43'),(17,'Nasi Kandar Perlis','Perlis','Kangar',6.4400000,100.1900000,'Indian',8.00,4.1,'07:00 - 22:00',1,'2026-04-13 15:10:43'),(18,'Kacang Pool Haji Restauran','Johor','Johor Bahru',1.4600000,103.7600000,'Malay',8.00,4.2,'07:00 - 22:00',1,'2026-04-14 19:53:55'),(19,'Mee Rebus Haji Wahid','Johor','Johor Bahru',1.4620000,103.7580000,'Malay',7.00,4.3,'07:00 - 15:00',1,'2026-04-14 19:53:55'),(20,'Nasi Kandar Pelita','Kuala Lumpur','City Centre (KLCC)',3.1580000,101.7200000,'Indian',12.00,4.2,'24 hours',1,'2026-04-14 19:53:55'),(21,'Restoran Yut Kee','Kuala Lumpur','Chow Kit',3.1650000,101.7010000,'Chinese',18.00,4.4,'07:30 - 16:00',1,'2026-04-14 19:53:55'),(22,'Penang Road Famous Teochew Cendol','Penang','Timur Laut',5.4160000,100.3340000,'Dessert',5.00,4.6,'10:30 - 18:00',1,'2026-04-14 19:53:55'),(23,'Lorong Baru Char Koay Teow','Penang','Timur Laut',5.4180000,100.3360000,'Chinese',8.00,4.5,'10:00 - 18:00',1,'2026-04-14 19:53:55'),(24,'Jonker Street Night Market','Melaka','Melaka Tengah',2.1960000,102.2490000,'Mixed',10.00,4.4,'18:00 - 23:00',1,'2026-04-14 19:53:55'),(25,'Nasi Kerabu Kak Yah','Kelantan','Kota Bharu',6.1230000,102.2360000,'Malay',6.00,4.5,'07:00 - 14:00',1,'2026-04-14 19:53:55'),(26,'Nasi Lemak Antarabangsa Alor Setar','Kedah','Kota Setar',6.1220000,100.3720000,'Malay',5.00,4.2,'06:00 - 14:00',1,'2026-04-14 19:53:55'),(27,'Restoran Sri Paandi','Selangor','Shah Alam',3.0850000,101.5320000,'Indian',12.00,4.3,'07:00 - 22:00',1,'2026-04-14 19:53:55'),(28,'Restoran Ikan Bakar Kuantan','Pahang','Kuantan',3.8050000,103.3250000,'Seafood',25.00,4.3,'11:00 - 22:00',1,'2026-04-14 19:53:55'),(29,'Restoran Sri Melaka Kota Kinabalu','Sabah','Kota Kinabalu',5.9780000,116.0750000,'Malay',15.00,4.1,'10:00 - 22:00',1,'2026-04-14 19:53:55'),(30,'Top Spot Food Court Kuching','Sarawak','Kuching',1.5580000,110.3430000,'Seafood',25.00,4.4,'17:00 - 23:00',1,'2026-04-14 19:53:55'),(31,'Nasi Dagang Atas Tol','Terengganu','Kuala Terengganu',5.3300000,103.1400000,'Malay',6.00,4.5,'07:00 - 14:00',1,'2026-04-14 19:53:55'),(32,'Restoran Foh San Ipoh','Perak','Ipoh',4.5980000,101.0880000,'Chinese',15.00,4.5,'06:00 - 14:00',1,'2026-04-14 19:53:55'),(33,'Restoran Seri Menanti','Negeri Sembilan','Seremban',2.7300000,101.9400000,'Malay',10.00,4.2,'08:00 - 22:00',1,'2026-04-14 19:53:55'),(34,'Nasi Kandar Perlis','Perlis','Kangar',6.4400000,100.1900000,'Indian',8.00,4.1,'07:00 - 22:00',1,'2026-04-14 19:53:55');
/*!40000 ALTER TABLE `food_places` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hotels`
--

DROP TABLE IF EXISTS `hotels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hotels` (
  `hotel_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `price_per_night` decimal(10,2) DEFAULT 100.00,
  `rating` decimal(3,1) DEFAULT 3.5,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`hotel_id`),
  KEY `idx_state` (`state`),
  KEY `idx_coords` (`latitude`,`longitude`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hotels`
--

LOCK TABLES `hotels` WRITE;
/*!40000 ALTER TABLE `hotels` DISABLE KEYS */;
INSERT INTO `hotels` VALUES (1,'Citrus Hotel Johor Bahru','Johor','Johor Bahru',1.4655000,103.7578000,120.00,3.8,1,'2026-04-13 15:10:43'),(2,'Thistle Johor Bahru','Johor','Johor Bahru',1.4655000,103.7578000,250.00,4.2,1,'2026-04-13 15:10:43'),(3,'Berjaya Waterfront Hotel','Johor','Johor Bahru',1.4600000,103.7680000,180.00,4.0,1,'2026-04-13 15:10:43'),(4,'Mandarin Oriental Kuala Lumpur','Kuala Lumpur','City Centre (KLCC)',3.1570000,101.7120000,450.00,4.7,1,'2026-04-13 15:10:43'),(5,'Berjaya Times Square Hotel KL','Kuala Lumpur','City Centre (KLCC)',3.1420000,101.7100000,200.00,4.1,1,'2026-04-13 15:10:43'),(6,'Eastern & Oriental Hotel','Penang','Timur Laut',5.4185000,100.3368000,380.00,4.6,1,'2026-04-13 15:10:43'),(7,'Hard Rock Hotel Penang','Penang','Timur Laut',5.4700000,100.2400000,320.00,4.4,1,'2026-04-13 15:10:43'),(8,'Hotel Equatorial Melaka','Melaka','Melaka Tengah',2.1940000,102.2501000,200.00,4.1,1,'2026-04-13 15:10:43'),(9,'Hatten Hotel Melaka','Melaka','Melaka Tengah',2.1930000,102.2510000,180.00,4.0,1,'2026-04-13 15:10:43'),(10,'Renaissance Kota Bharu Hotel','Kelantan','Kota Bharu',6.1248000,102.2382000,220.00,4.2,1,'2026-04-13 15:10:43'),(11,'Langkawi Lagoon Resort','Kedah','Langkawi',6.3500000,99.8500000,280.00,4.3,1,'2026-04-13 15:10:43'),(12,'Sunway Resort Hotel','Selangor','Subang Jaya',3.0730000,101.6060000,320.00,4.4,1,'2026-04-13 15:10:43'),(13,'Shah Alam Convention Centre Hotel','Selangor','Shah Alam',3.0850000,101.5320000,150.00,3.9,1,'2026-04-13 15:10:43'),(14,'Hyatt Regency Kuantan Resort','Pahang','Kuantan',3.8000000,103.3300000,280.00,4.3,1,'2026-04-13 15:10:43'),(15,'Shangri-La Tanjung Aru Resort','Sabah','Kota Kinabalu',5.9630000,116.0720000,380.00,4.6,1,'2026-04-13 15:10:43'),(16,'Hilton Kuching','Sarawak','Kuching',1.5590000,110.3440000,280.00,4.3,1,'2026-04-13 15:10:43'),(17,'Sutra Beach Resort','Terengganu','Setiu',5.6500000,102.8000000,200.00,4.1,1,'2026-04-13 15:10:43'),(18,'Casuarina@Meru Hotel','Perak','Ipoh',4.6000000,101.0900000,160.00,4.0,1,'2026-04-13 15:10:43'),(19,'Allson Klana Resort Seremban','Negeri Sembilan','Seremban',2.7300000,101.9400000,150.00,3.9,1,'2026-04-13 15:10:43'),(20,'Hotel Seri Malaysia Kangar','Perlis','Kangar',6.4400000,100.1900000,100.00,3.6,1,'2026-04-13 15:10:43'),(21,'Citrus Hotel Johor Bahru','Johor','Johor Bahru',1.4655000,103.7578000,120.00,3.8,1,'2026-04-14 19:53:55'),(22,'Thistle Johor Bahru','Johor','Johor Bahru',1.4655000,103.7578000,250.00,4.2,1,'2026-04-14 19:53:55'),(23,'Berjaya Waterfront Hotel','Johor','Johor Bahru',1.4600000,103.7680000,180.00,4.0,1,'2026-04-14 19:53:55'),(24,'Mandarin Oriental Kuala Lumpur','Kuala Lumpur','City Centre (KLCC)',3.1570000,101.7120000,450.00,4.7,1,'2026-04-14 19:53:55'),(25,'Berjaya Times Square Hotel KL','Kuala Lumpur','City Centre (KLCC)',3.1420000,101.7100000,200.00,4.1,1,'2026-04-14 19:53:55'),(26,'Eastern & Oriental Hotel','Penang','Timur Laut',5.4185000,100.3368000,380.00,4.6,1,'2026-04-14 19:53:55'),(27,'Hard Rock Hotel Penang','Penang','Timur Laut',5.4700000,100.2400000,320.00,4.4,1,'2026-04-14 19:53:55'),(28,'Hotel Equatorial Melaka','Melaka','Melaka Tengah',2.1940000,102.2501000,200.00,4.1,1,'2026-04-14 19:53:55'),(29,'Hatten Hotel Melaka','Melaka','Melaka Tengah',2.1930000,102.2510000,180.00,4.0,1,'2026-04-14 19:53:55'),(30,'Renaissance Kota Bharu Hotel','Kelantan','Kota Bharu',6.1248000,102.2382000,220.00,4.2,1,'2026-04-14 19:53:55'),(31,'Langkawi Lagoon Resort','Kedah','Langkawi',6.3500000,99.8500000,280.00,4.3,1,'2026-04-14 19:53:55'),(32,'Sunway Resort Hotel','Selangor','Subang Jaya',3.0730000,101.6060000,320.00,4.4,1,'2026-04-14 19:53:55'),(33,'Shah Alam Convention Centre Hotel','Selangor','Shah Alam',3.0850000,101.5320000,150.00,3.9,1,'2026-04-14 19:53:55'),(34,'Hyatt Regency Kuantan Resort','Pahang','Kuantan',3.8000000,103.3300000,280.00,4.3,1,'2026-04-14 19:53:55'),(35,'Shangri-La Tanjung Aru Resort','Sabah','Kota Kinabalu',5.9630000,116.0720000,380.00,4.6,1,'2026-04-14 19:53:55'),(36,'Hilton Kuching','Sarawak','Kuching',1.5590000,110.3440000,280.00,4.3,1,'2026-04-14 19:53:55'),(37,'Sutra Beach Resort','Terengganu','Setiu',5.6500000,102.8000000,200.00,4.1,1,'2026-04-14 19:53:55'),(38,'Casuarina@Meru Hotel','Perak','Ipoh',4.6000000,101.0900000,160.00,4.0,1,'2026-04-14 19:53:55'),(39,'Allson Klana Resort Seremban','Negeri Sembilan','Seremban',2.7300000,101.9400000,150.00,3.9,1,'2026-04-14 19:53:55'),(40,'Hotel Seri Malaysia Kangar','Perlis','Kangar',6.4400000,100.1900000,100.00,3.6,1,'2026-04-14 19:53:55');
/*!40000 ALTER TABLE `hotels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itineraries`
--

DROP TABLE IF EXISTS `itineraries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itineraries` (
  `itinerary_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `preference_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `start_date` date DEFAULT NULL,
  `total_days` int(11) NOT NULL,
  `items_per_day` int(11) NOT NULL DEFAULT 3,
  `selected_hotel_id` int(11) DEFAULT NULL,
  `selected_hotel_name` varchar(150) DEFAULT NULL,
  `selected_hotel_nights` int(11) NOT NULL DEFAULT 0,
  `selected_hotel_total_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_estimated_cost` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`itinerary_id`),
  KEY `fk_it_traveller` (`traveller_id`),
  KEY `fk_it_pref` (`preference_id`),
  KEY `idx_itinerary_selected_hotel` (`selected_hotel_id`),
  CONSTRAINT `fk_it_pref` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`),
  CONSTRAINT `fk_it_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itineraries`
--

LOCK TABLES `itineraries` WRITE;
/*!40000 ALTER TABLE `itineraries` DISABLE KEYS */;
INSERT INTO `itineraries` VALUES (40,1,6,'3D Culture & Food Journey: Malaysia','2026-02-22',3,3,NULL,NULL,0,0.00,74.00,'2026-01-05 11:30:21'),(42,1,4,'1D Food Explorer Route — Johor','2026-02-22',1,2,NULL,NULL,0,0.00,23.00,'2026-01-05 11:39:02'),(43,11,7,'2D Culture & Food Getaway — Johor & Kelantan','2026-02-22',2,2,NULL,NULL,0,0.00,23.00,'2026-01-08 14:22:00'),(44,1,4,'1D Food Trail — Johor','2026-02-22',1,2,NULL,NULL,0,0.00,23.00,'2026-01-08 14:41:02'),(45,11,7,'2D Culture & Food Escape: Johor & Kelantan','2026-02-22',2,3,NULL,NULL,0,0.00,23.00,'2026-01-14 14:03:13'),(46,11,8,'1D Culture & Food Highlights | Johor','2026-02-22',1,2,NULL,NULL,0,0.00,23.00,'2026-01-15 01:17:06'),(47,11,9,'2D Culture & Food Getaway — Johor & Kelantan','2026-02-26',2,3,NULL,NULL,0,0.00,48.00,'2026-01-15 01:25:31'),(48,11,8,'1D Culture & Food Highlights | Johor','2026-05-22',1,3,NULL,NULL,0,0.00,23.00,'2026-04-13 18:13:37'),(49,11,11,'2D Culture & Food Highlights | Johor','2026-05-22',2,3,NULL,NULL,0,0.00,230.70,'2026-05-10 17:53:18');
/*!40000 ALTER TABLE `itineraries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itinerary_items`
--

DROP TABLE IF EXISTS `itinerary_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itinerary_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `day_no` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL,
  `item_type` enum('attraction','food','festival','transport','hotel','note') DEFAULT 'attraction',
  `place_id` int(11) DEFAULT NULL,
  `hotel_id` int(11) DEFAULT NULL,
  `item_title` varchar(150) NOT NULL,
  `item_latitude` decimal(10,7) DEFAULT NULL,
  `item_longitude` decimal(10,7) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT 0.00,
  `distance_km` decimal(8,2) DEFAULT NULL,
  `travel_time_min` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `idx_itinerary_day` (`itinerary_id`,`day_no`,`sequence_no`),
  KEY `idx_item_place` (`place_id`),
  KEY `idx_item_hotel` (`hotel_id`),
  CONSTRAINT `fk_item_hotel` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`hotel_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_item_it` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itinerary_items`
--

LOCK TABLES `itinerary_items` WRITE;
/*!40000 ALTER TABLE `itinerary_items` DISABLE KEYS */;
INSERT INTO `itinerary_items` VALUES (17,40,1,1,'attraction',3,NULL,'Chong Long Gong Temple',1.7836877,102.8926308,NULL,NULL,0.00,NULL,NULL,'State: Johor | Category: heritage'),(18,40,2,1,'attraction',8,NULL,'Taman Negara Johor Pulau Kukup',1.3225724,103.4283275,NULL,NULL,25.00,NULL,NULL,'State: Johor | Category: nature'),(19,40,2,2,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,49.44,50,'State: Johor | Category: food'),(20,40,2,3,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,2.18,5,'State: Johor | Category: food'),(21,40,3,1,'attraction',4,NULL,'Johor Bahru Chinese Heritage Museum',1.4579000,103.7646000,NULL,NULL,6.00,NULL,NULL,'State: Johor | Category: museum'),(22,40,3,2,'attraction',9,NULL,'Johor Bahru Old Chinese Temple (柔佛古庙)',1.4606803,103.7630595,NULL,NULL,20.00,1.12,5,'State: Johor | Category: heritage'),(25,42,1,1,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,NULL,NULL,'State: Johor | Category: food'),(26,42,1,2,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,NULL,NULL,'State: Johor | Category: food'),(27,43,1,1,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,NULL,NULL,'State: Johor | Category: food'),(28,43,1,2,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,1.75,3,'State: Johor | Category: food'),(29,44,1,1,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,NULL,NULL,'State: Johor | Category: food'),(30,44,1,2,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,2.18,5,'State: Johor | Category: food'),(31,45,1,1,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,NULL,NULL,'State: Johor | Category: food'),(32,45,1,2,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,NULL,NULL,'State: Johor | Category: food'),(33,46,1,1,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,NULL,NULL,'State: Johor | Category: food'),(34,46,1,2,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,2.18,6,'State: Johor | Category: food'),(35,47,1,1,'attraction',8,NULL,'Taman Negara Johor Pulau Kukup',1.3225724,103.4283275,NULL,NULL,25.00,NULL,NULL,'State: Johor | Category: nature'),(36,47,1,2,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,49.44,49,'State: Johor | Category: food'),(37,47,1,3,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,2.18,6,'State: Johor | Category: food'),(38,48,1,1,'food',2,NULL,'Mee Rebus (Johor Style)',1.4927000,103.7414000,NULL,NULL,15.00,99.00,83,'State: Johor | District: Johor Bahru | Category: food'),(39,48,1,2,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,NULL,NULL,8.00,2.18,6,'State: Johor | District: Johor Bahru | Category: food'),(41,49,1,1,'food',1,NULL,'Kacang Pool Haji Restauran (Johor Bahru)',1.4907395,103.7515533,'10:33:00','12:03:00',8.00,NULL,NULL,'State: Johor | District: Johor Bahru | Category: food'),(42,49,1,2,'attraction',9,NULL,'Johor Bahru Old Chinese Temple (柔佛古庙)',1.4606803,103.7630595,'12:13:00','13:43:00',20.00,4.65,10,'State: Johor | District: Johor Bahru | Category: heritage');
/*!40000 ALTER TABLE `itinerary_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `malaysia_districts`
--

DROP TABLE IF EXISTS `malaysia_districts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `malaysia_districts` (
  `district_id` int(11) NOT NULL AUTO_INCREMENT,
  `state` varchar(60) NOT NULL,
  `district` varchar(80) NOT NULL,
  PRIMARY KEY (`district_id`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB AUTO_INCREMENT=263 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `malaysia_districts`
--

LOCK TABLES `malaysia_districts` WRITE;
/*!40000 ALTER TABLE `malaysia_districts` DISABLE KEYS */;
INSERT INTO `malaysia_districts` VALUES (1,'Johor','Johor Bahru'),(2,'Johor','Kluang'),(3,'Johor','Kota Tinggi'),(4,'Johor','Mersing'),(5,'Johor','Muar'),(6,'Johor','Batu Pahat'),(7,'Johor','Pontian'),(8,'Johor','Segamat'),(9,'Johor','Kulai'),(10,'Johor','Tangkak'),(11,'Kedah','Kota Setar'),(12,'Kedah','Kubang Pasu'),(13,'Kedah','Padang Terap'),(14,'Kedah','Sik'),(15,'Kedah','Baling'),(16,'Kedah','Kulim'),(17,'Kedah','Bandar Baharu'),(18,'Kedah','Kuala Muda'),(19,'Kedah','Yan'),(20,'Kedah','Langkawi'),(21,'Kedah','Pokok Sena'),(22,'Kedah','Pendang'),(23,'Kelantan','Kota Bharu'),(24,'Kelantan','Bachok'),(25,'Kelantan','Pasir Mas'),(26,'Kelantan','Tumpat'),(27,'Kelantan','Pasir Puteh'),(28,'Kelantan','Machang'),(29,'Kelantan','Tanah Merah'),(30,'Kelantan','Kuala Krai'),(31,'Kelantan','Gua Musang'),(32,'Kelantan','Jeli'),(33,'Melaka','Melaka Tengah'),(34,'Melaka','Alor Gajah'),(35,'Melaka','Jasin'),(36,'Negeri Sembilan','Seremban'),(37,'Negeri Sembilan','Port Dickson'),(38,'Negeri Sembilan','Rembau'),(39,'Negeri Sembilan','Tampin'),(40,'Negeri Sembilan','Jempol'),(41,'Negeri Sembilan','Jelebu'),(42,'Negeri Sembilan','Kuala Pilah'),(43,'Pahang','Kuantan'),(44,'Pahang','Temerloh'),(45,'Pahang','Bentong'),(46,'Pahang','Cameron Highlands'),(47,'Pahang','Raub'),(48,'Pahang','Jerantut'),(49,'Pahang','Lipis'),(50,'Pahang','Maran'),(51,'Pahang','Bera'),(52,'Pahang','Rompin'),(53,'Pahang','Pekan'),(54,'Penang','Timur Laut'),(55,'Penang','Barat Daya'),(56,'Penang','Seberang Perai Utara'),(57,'Penang','Seberang Perai Tengah'),(58,'Penang','Seberang Perai Selatan'),(59,'Perak','Ipoh'),(60,'Perak','Kinta'),(61,'Perak','Larut, Matang & Selama'),(62,'Perak','Manjung'),(63,'Perak','Kerian'),(64,'Perak','Hilir Perak'),(65,'Perak','Hulu Perak'),(66,'Perak','Batang Padang'),(67,'Perak','Perak Tengah'),(68,'Perak','Kampar'),(69,'Perlis','Kangar'),(70,'Perlis','Arau'),(71,'Perlis','Padang Besar'),(72,'Sabah','Kota Kinabalu'),(73,'Sabah','Sandakan'),(74,'Sabah','Tawau'),(75,'Sabah','Lahad Datu'),(76,'Sabah','Keningau'),(77,'Sabah','Semporna'),(78,'Sabah','Kunak'),(79,'Sabah','Papar'),(80,'Sabah','Beaufort'),(81,'Sabah','Kota Belud'),(82,'Sabah','Ranau'),(83,'Sabah','Kudat'),(84,'Sabah','Kinabatangan'),(85,'Sabah','Tuaran'),(86,'Sabah','Penampang'),(87,'Sabah','Putatan'),(88,'Sabah','Sipitang'),(89,'Sabah','Tambunan'),(90,'Sabah','Nabawan'),(91,'Sabah','Tongod'),(92,'Sabah','Beluran'),(93,'Sabah','Kota Marudu'),(94,'Sabah','Pitas'),(95,'Sabah','Tenom'),(96,'Sabah','Kuala Penyu'),(97,'Sarawak','Kuching'),(98,'Sarawak','Miri'),(99,'Sarawak','Sibu'),(100,'Sarawak','Bintulu'),(101,'Sarawak','Sri Aman'),(102,'Sarawak','Sarikei'),(103,'Sarawak','Kapit'),(104,'Sarawak','Limbang'),(105,'Sarawak','Mukah'),(106,'Sarawak','Betong'),(107,'Sarawak','Serian'),(108,'Sarawak','Kota Samarahan'),(109,'Selangor','Petaling Jaya'),(110,'Selangor','Shah Alam'),(111,'Selangor','Klang'),(112,'Selangor','Subang Jaya'),(113,'Selangor','Gombak'),(114,'Selangor','Hulu Langat'),(115,'Selangor','Hulu Selangor'),(116,'Selangor','Kuala Langat'),(117,'Selangor','Sabak Bernam'),(118,'Terengganu','Kuala Terengganu'),(119,'Terengganu','Kemaman'),(120,'Terengganu','Dungun'),(121,'Terengganu','Besut'),(122,'Terengganu','Setiu'),(123,'Terengganu','Hulu Terengganu'),(124,'Terengganu','Marang'),(125,'Kuala Lumpur','City Centre (KLCC)'),(126,'Kuala Lumpur','Chow Kit'),(127,'Kuala Lumpur','Brickfields'),(128,'Kuala Lumpur','Bangsar'),(129,'Kuala Lumpur','Cheras'),(130,'Kuala Lumpur','Kepong'),(131,'Kuala Lumpur','Setapak'),(132,'Kuala Lumpur','Wangsa Maju'),(133,'Kuala Lumpur','Titiwangsa'),(134,'Kuala Lumpur','Bukit Jalil'),(135,'Kuala Lumpur','Segambut'),(136,'Putrajaya','Putrajaya'),(137,'Labuan','Victoria'),(138,'Labuan','Labuan Town'),(139,'Johor','Johor Bahru'),(140,'Johor','Kluang'),(141,'Johor','Kota Tinggi'),(142,'Johor','Muar'),(143,'Johor','Batu Pahat'),(144,'Johor','Pontian'),(145,'Johor','Segamat'),(146,'Johor','Kulai'),(147,'Kedah','Kota Setar'),(148,'Kedah','Langkawi'),(149,'Kedah','Kuala Muda'),(150,'Kedah','Kulim'),(151,'Kelantan','Kota Bharu'),(152,'Kelantan','Bachok'),(153,'Kelantan','Pasir Mas'),(154,'Kelantan','Tumpat'),(155,'Melaka','Melaka Tengah'),(156,'Melaka','Alor Gajah'),(157,'Melaka','Jasin'),(158,'Negeri Sembilan','Seremban'),(159,'Negeri Sembilan','Port Dickson'),(160,'Negeri Sembilan','Rembau'),(161,'Pahang','Kuantan'),(162,'Pahang','Temerloh'),(163,'Pahang','Bentong'),(164,'Pahang','Cameron Highlands'),(165,'Penang','Timur Laut'),(166,'Penang','Barat Daya'),(167,'Penang','Seberang Perai Utara'),(168,'Penang','Seberang Perai Tengah'),(169,'Penang','Seberang Perai Selatan'),(170,'Perak','Ipoh'),(171,'Perak','Kinta'),(172,'Perak','Manjung'),(173,'Perak','Hilir Perak'),(174,'Perlis','Kangar'),(175,'Perlis','Arau'),(176,'Perlis','Padang Besar'),(177,'Sabah','Kota Kinabalu'),(178,'Sabah','Sandakan'),(179,'Sabah','Tawau'),(180,'Sabah','Ranau'),(181,'Sarawak','Kuching'),(182,'Sarawak','Miri'),(183,'Sarawak','Sibu'),(184,'Sarawak','Bintulu'),(185,'Selangor','Petaling Jaya'),(186,'Selangor','Shah Alam'),(187,'Selangor','Klang'),(188,'Selangor','Subang Jaya'),(189,'Selangor','Gombak'),(190,'Terengganu','Kuala Terengganu'),(191,'Terengganu','Kemaman'),(192,'Terengganu','Dungun'),(193,'Kuala Lumpur','City Centre (KLCC)'),(194,'Kuala Lumpur','Bukit Bintang'),(195,'Kuala Lumpur','Chow Kit'),(196,'Kuala Lumpur','Brickfields'),(197,'Kuala Lumpur','Bangsar'),(198,'Putrajaya','Putrajaya'),(199,'Labuan','Victoria'),(200,'Labuan','Labuan Town'),(201,'Johor','Johor Bahru'),(202,'Johor','Kluang'),(203,'Johor','Kota Tinggi'),(204,'Johor','Muar'),(205,'Johor','Batu Pahat'),(206,'Johor','Pontian'),(207,'Johor','Segamat'),(208,'Johor','Kulai'),(209,'Kedah','Kota Setar'),(210,'Kedah','Langkawi'),(211,'Kedah','Kuala Muda'),(212,'Kedah','Kulim'),(213,'Kelantan','Kota Bharu'),(214,'Kelantan','Bachok'),(215,'Kelantan','Pasir Mas'),(216,'Kelantan','Tumpat'),(217,'Melaka','Melaka Tengah'),(218,'Melaka','Alor Gajah'),(219,'Melaka','Jasin'),(220,'Negeri Sembilan','Seremban'),(221,'Negeri Sembilan','Port Dickson'),(222,'Negeri Sembilan','Rembau'),(223,'Pahang','Kuantan'),(224,'Pahang','Temerloh'),(225,'Pahang','Bentong'),(226,'Pahang','Cameron Highlands'),(227,'Penang','Timur Laut'),(228,'Penang','Barat Daya'),(229,'Penang','Seberang Perai Utara'),(230,'Penang','Seberang Perai Tengah'),(231,'Penang','Seberang Perai Selatan'),(232,'Perak','Ipoh'),(233,'Perak','Kinta'),(234,'Perak','Manjung'),(235,'Perak','Hilir Perak'),(236,'Perlis','Kangar'),(237,'Perlis','Arau'),(238,'Perlis','Padang Besar'),(239,'Sabah','Kota Kinabalu'),(240,'Sabah','Sandakan'),(241,'Sabah','Tawau'),(242,'Sabah','Ranau'),(243,'Sarawak','Kuching'),(244,'Sarawak','Miri'),(245,'Sarawak','Sibu'),(246,'Sarawak','Bintulu'),(247,'Selangor','Petaling Jaya'),(248,'Selangor','Shah Alam'),(249,'Selangor','Klang'),(250,'Selangor','Subang Jaya'),(251,'Selangor','Gombak'),(252,'Terengganu','Kuala Terengganu'),(253,'Terengganu','Kemaman'),(254,'Terengganu','Dungun'),(255,'Kuala Lumpur','City Centre (KLCC)'),(256,'Kuala Lumpur','Bukit Bintang'),(257,'Kuala Lumpur','Chow Kit'),(258,'Kuala Lumpur','Brickfields'),(259,'Kuala Lumpur','Bangsar'),(260,'Putrajaya','Putrajaya'),(261,'Labuan','Victoria'),(262,'Labuan','Labuan Town');
/*!40000 ALTER TABLE `malaysia_districts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `malaysia_states`
--

DROP TABLE IF EXISTS `malaysia_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `malaysia_states` (
  `state_id` int(11) NOT NULL AUTO_INCREMENT,
  `state_name` varchar(60) NOT NULL,
  PRIMARY KEY (`state_id`),
  UNIQUE KEY `uq_malaysia_state_name` (`state_name`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `malaysia_states`
--

LOCK TABLES `malaysia_states` WRITE;
/*!40000 ALTER TABLE `malaysia_states` DISABLE KEYS */;
INSERT INTO `malaysia_states` VALUES (1,'Johor'),(2,'Kedah'),(3,'Kelantan'),(14,'Kuala Lumpur'),(16,'Labuan'),(4,'Melaka'),(5,'Negeri Sembilan'),(6,'Pahang'),(7,'Penang'),(8,'Perak'),(9,'Perlis'),(15,'Putrajaya'),(10,'Sabah'),(11,'Sarawak'),(12,'Selangor'),(13,'Terengganu');
/*!40000 ALTER TABLE `malaysia_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `place_images`
--

DROP TABLE IF EXISTS `place_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `place_images` (
  `image_id` int(11) NOT NULL AUTO_INCREMENT,
  `place_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `caption` varchar(150) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`image_id`),
  KEY `idx_place_images_place` (`place_id`),
  CONSTRAINT `fk_place_images_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `place_images`
--

LOCK TABLES `place_images` WRITE;
/*!40000 ALTER TABLE `place_images` DISABLE KEYS */;
INSERT INTO `place_images` VALUES (1,1,'uploads/places/place_1766837821_4033.jpg',NULL,1,'2026-04-25 17:44:51'),(2,2,'uploads/places/place_1766835810_7693.png',NULL,1,'2026-04-25 17:44:51'),(3,3,'uploads/places/place_1766836350_7759.webp',NULL,1,'2026-04-25 17:44:51'),(4,4,'uploads/places/place_1766836057_2242.webp',NULL,1,'2026-04-25 17:44:51'),(5,5,'uploads/places/place_1766835360_5565.jpg',NULL,1,'2026-04-25 17:44:51'),(6,6,'uploads/places/place_1766835098_6695.webp',NULL,1,'2026-04-25 17:44:51'),(7,7,'uploads/places/place_1766831415_1464.jpg',NULL,1,'2026-04-25 17:44:51'),(8,8,'uploads/places/place_1766813513_1914.webp',NULL,1,'2026-04-25 17:44:51'),(9,9,'uploads/places/suggest_1766814848_5261.webp',NULL,1,'2026-04-25 17:44:51'),(10,10,'uploads/places/place_10_169294a803_20260105_120730_5e4700.jpg',NULL,1,'2026-04-25 17:44:51'),(11,11,'uploads/places/place_11_30123df278_20260105_120731_8f5f34.jpg',NULL,1,'2026-04-25 17:44:51'),(12,12,'uploads/places/place_12_85ade11fe5_20260105_120734_5496bb.jpg',NULL,1,'2026-04-25 17:44:51'),(13,13,'uploads/places/place_13_1dada0c29d_20260105_120735_8c494b.jpg',NULL,1,'2026-04-25 17:44:51'),(14,14,'uploads/places/place_1766958248_6687.jpg',NULL,1,'2026-04-25 17:44:51'),(15,15,'uploads/places/place_15_d451eb69ae_20260105_121459_5c8d18.jpg',NULL,1,'2026-04-25 17:44:51'),(16,16,'uploads/places/place_16_28a30c79d0_20260105_121504_08dcd3.jpg',NULL,1,'2026-04-25 17:44:51'),(17,17,'uploads/places/place_17_0f1d7f3b50_20260105_120749_69e67c.jpg',NULL,1,'2026-04-25 17:44:51'),(18,18,'uploads/places/place_18_fe4e4116bd_20260105_120753_530144.jpg',NULL,1,'2026-04-25 17:44:51'),(19,19,'uploads/places/place_19_c023100102_20260105_120755_1fe0c4.jpg',NULL,1,'2026-04-25 17:44:51'),(20,20,'uploads/places/place_20_4fd8bba161_20260105_121510_b26c9a.jpg',NULL,1,'2026-04-25 17:44:51'),(21,21,'uploads/places/place_21_60f3eb5978_20260105_121514_5d3aa2.jpg',NULL,1,'2026-04-25 17:44:51'),(22,22,'uploads/places/place_22_c8b317e8dd_20260105_120808_88a923.jpg',NULL,1,'2026-04-25 17:44:51'),(23,23,'uploads/places/place_1768401621_9972.jpg',NULL,1,'2026-04-25 17:44:51'),(24,24,'uploads/places/place_24_7be148a719_20260105_120836_02a8a1.jpg',NULL,1,'2026-04-25 17:44:51'),(25,25,'uploads/places/suggest_1767885223_8329.webp',NULL,1,'2026-04-25 17:44:51'),(26,26,'uploads/places/place_26_ecff8ff002_20260114_152513_f8eacd.jpg',NULL,1,'2026-04-25 17:44:51'),(27,27,'uploads/places/place_27_380596f94e_20260114_152520_1a15c1.jpg',NULL,1,'2026-04-25 17:44:51'),(28,28,'uploads/places/place_28_7acb892af4_20260114_152526_98645c.jpg',NULL,1,'2026-04-25 17:44:51'),(29,29,'uploads/places/place_29_ec942f8173_20260114_152529_539b4c.jpg',NULL,1,'2026-04-25 17:44:51'),(30,30,'uploads/places/place_30_bce6ca8f95_20260114_152538_2b7104.jpg',NULL,1,'2026-04-25 17:44:51'),(31,31,'uploads/places/place_31_0beb0a3e93_20260114_152539_e627be.jpg',NULL,1,'2026-04-25 17:44:51'),(32,32,'uploads/places/place_1768401054_5697.jpg',NULL,1,'2026-04-25 17:44:51'),(33,33,'uploads/places/place_33_7f5298026e_20260114_152625_508faa.jpg',NULL,1,'2026-04-25 17:44:51'),(34,34,'uploads/places/place_34_219d1e7044_20260114_152632_ad2a34.jpg',NULL,1,'2026-04-25 17:44:51'),(35,35,'uploads/places/place_35_559f960576_20260114_152648_534b6e.jpg',NULL,1,'2026-04-25 17:44:51'),(36,36,'uploads/places/place_36_27c635b67a_20260114_162240_71b368.jpg',NULL,1,'2026-04-25 17:44:51'),(37,37,'uploads/places/place_1768404589_4164.webp',NULL,1,'2026-04-25 17:44:51'),(38,38,'uploads/places/place_1768404549_3397.webp',NULL,1,'2026-04-25 17:44:51'),(39,39,'uploads/places/place_1768404351_3690.jpg',NULL,1,'2026-04-25 17:44:51'),(40,40,'uploads/places/place_40_b6b991191b_20260114_162259_a0fc88.jpg',NULL,1,'2026-04-25 17:44:51'),(41,41,'uploads/places/place_41_c88b978354_20260114_162303_bd4af5.jpg',NULL,1,'2026-04-25 17:44:51'),(42,42,'uploads/places/place_42_2af8e4dc28_20260114_162305_f02425.jpg',NULL,1,'2026-04-25 17:44:51'),(43,43,'uploads/places/place_43_8fa0ae2b38_20260114_162309_10fc4c.jpg',NULL,1,'2026-04-25 17:44:51'),(44,44,'uploads/places/place_44_aad8660b51_20260114_162310_1cebe3.jpg',NULL,1,'2026-04-25 17:44:51'),(45,45,'uploads/places/place_1768404637_5000.jpg',NULL,1,'2026-04-25 17:44:51'),(46,46,'uploads/places/place_1768413945_3233.jpg',NULL,1,'2026-04-25 17:44:51'),(47,47,'uploads/places/suggest_1768412766_2760.jpg',NULL,1,'2026-04-25 17:44:51');
/*!40000 ALTER TABLE `place_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `preference_districts`
--

DROP TABLE IF EXISTS `preference_districts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preference_districts` (
  `preference_id` int(11) NOT NULL,
  `state` varchar(60) DEFAULT NULL,
  `district` varchar(80) NOT NULL,
  PRIMARY KEY (`preference_id`,`district`),
  KEY `idx_pref_district` (`district`),
  KEY `idx_pref_district_state` (`state`,`district`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `preference_districts`
--

LOCK TABLES `preference_districts` WRITE;
/*!40000 ALTER TABLE `preference_districts` DISABLE KEYS */;
INSERT INTO `preference_districts` VALUES (11,'Johor','Johor Bahru');
/*!40000 ALTER TABLE `preference_districts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `preference_interests`
--

DROP TABLE IF EXISTS `preference_interests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preference_interests` (
  `preference_id` int(11) NOT NULL,
  `interest` enum('culture','heritage','food','museum','nature','shopping','festival') NOT NULL,
  PRIMARY KEY (`preference_id`,`interest`),
  KEY `idx_pref_interest` (`interest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `preference_interests`
--

LOCK TABLES `preference_interests` WRITE;
/*!40000 ALTER TABLE `preference_interests` DISABLE KEYS */;
INSERT INTO `preference_interests` VALUES (1,'food'),(2,'food'),(3,'heritage'),(4,'food'),(5,'culture'),(5,'museum'),(6,'culture'),(6,'heritage'),(6,'food'),(6,'museum'),(6,'nature'),(6,'shopping'),(6,'festival'),(7,'culture'),(7,'food'),(8,'culture'),(8,'food'),(9,'culture'),(9,'food'),(9,'nature'),(10,'culture'),(10,'museum'),(10,'nature'),(10,'festival'),(11,'culture'),(11,'heritage'),(11,'food'),(11,'museum'),(11,'nature'),(11,'shopping'),(11,'festival');
/*!40000 ALTER TABLE `preference_interests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `preference_states`
--

DROP TABLE IF EXISTS `preference_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `preference_states` (
  `preference_id` int(11) NOT NULL,
  `state` varchar(60) NOT NULL,
  PRIMARY KEY (`preference_id`,`state`),
  KEY `idx_pref_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `preference_states`
--

LOCK TABLES `preference_states` WRITE;
/*!40000 ALTER TABLE `preference_states` DISABLE KEYS */;
INSERT INTO `preference_states` VALUES (1,'Johor'),(1,'Kedah'),(2,'Johor'),(3,'Johor'),(4,'Johor'),(5,'Johor'),(5,'Kelantan'),(7,'Johor'),(7,'Kelantan'),(8,'Johor'),(9,'Johor'),(9,'Kelantan'),(11,'Johor');
/*!40000 ALTER TABLE `preference_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ratings_reviews`
--

DROP TABLE IF EXISTS `ratings_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ratings_reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `place_id` int(11) NOT NULL,
  `traveller_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `review_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_review_place_traveller` (`place_id`,`traveller_id`),
  KEY `idx_review_place` (`place_id`),
  KEY `idx_review_traveller` (`traveller_id`),
  CONSTRAINT `fk_reviews_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_review_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ratings_reviews`
--

LOCK TABLES `ratings_reviews` WRITE;
/*!40000 ALTER TABLE `ratings_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `ratings_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remember_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_role` enum('traveller','admin') NOT NULL,
  `user_id` int(11) NOT NULL,
  `selector` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_role`,`user_id`),
  KEY `idx_remember_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remember_tokens`
--

LOCK TABLES `remember_tokens` WRITE;
/*!40000 ALTER TABLE `remember_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shared_itineraries`
--

DROP TABLE IF EXISTS `shared_itineraries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shared_itineraries` (
  `share_id` int(11) NOT NULL AUTO_INCREMENT,
  `itinerary_id` int(11) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`share_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_share_itinerary` (`itinerary_id`),
  CONSTRAINT `fk_shared_itinerary` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`itinerary_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shared_itineraries`
--

LOCK TABLES `shared_itineraries` WRITE;
/*!40000 ALTER TABLE `shared_itineraries` DISABLE KEYS */;
INSERT INTO `shared_itineraries` VALUES (1,48,'e0baa44a854289bba5c285137099f927',1,'2026-05-10 12:50:45'),(2,49,'093ffa995eee592ad876ad4068992a52',1,'2026-05-11 07:37:27');
/*!40000 ALTER TABLE `shared_itineraries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `travel_interests`
--

DROP TABLE IF EXISTS `travel_interests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `travel_interests` (
  `interest_id` int(11) NOT NULL AUTO_INCREMENT,
  `interest_code` varchar(40) NOT NULL,
  `interest_label` varchar(80) NOT NULL,
  PRIMARY KEY (`interest_id`),
  UNIQUE KEY `uq_travel_interest_code` (`interest_code`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `travel_interests`
--

LOCK TABLES `travel_interests` WRITE;
/*!40000 ALTER TABLE `travel_interests` DISABLE KEYS */;
INSERT INTO `travel_interests` VALUES (1,'culture','Culture'),(2,'heritage','Heritage'),(3,'food','Food'),(4,'museum','Museum'),(5,'nature','Nature'),(6,'shopping','Shopping'),(7,'festival','Festival');
/*!40000 ALTER TABLE `travel_interests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `traveller_preference_interests`
--

DROP TABLE IF EXISTS `traveller_preference_interests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `traveller_preference_interests` (
  `preference_id` int(11) NOT NULL,
  `interest_id` int(11) NOT NULL,
  PRIMARY KEY (`preference_id`,`interest_id`),
  KEY `idx_tpi_interest` (`interest_id`),
  CONSTRAINT `fk_tpi_interest` FOREIGN KEY (`interest_id`) REFERENCES `travel_interests` (`interest_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `traveller_preference_interests`
--

LOCK TABLES `traveller_preference_interests` WRITE;
/*!40000 ALTER TABLE `traveller_preference_interests` DISABLE KEYS */;
INSERT INTO `traveller_preference_interests` VALUES (1,3),(2,3),(3,2),(4,3),(5,1),(5,4),(6,1),(6,2),(6,3),(6,4),(6,5),(6,6),(6,7),(7,1),(7,3),(8,1),(8,3),(9,1),(9,3),(9,5),(10,1),(10,4),(10,5),(10,7),(11,1),(11,2),(11,3),(11,4),(11,5),(11,6),(11,7);
/*!40000 ALTER TABLE `traveller_preference_interests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `traveller_preference_states`
--

DROP TABLE IF EXISTS `traveller_preference_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `traveller_preference_states` (
  `preference_id` int(11) NOT NULL,
  `state_id` int(11) NOT NULL,
  PRIMARY KEY (`preference_id`,`state_id`),
  KEY `idx_tps_state` (`state_id`),
  CONSTRAINT `fk_tps_preference` FOREIGN KEY (`preference_id`) REFERENCES `traveller_preferences` (`preference_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tps_state` FOREIGN KEY (`state_id`) REFERENCES `malaysia_states` (`state_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `traveller_preference_states`
--

LOCK TABLES `traveller_preference_states` WRITE;
/*!40000 ALTER TABLE `traveller_preference_states` DISABLE KEYS */;
INSERT INTO `traveller_preference_states` VALUES (1,1),(1,2),(2,1),(3,1),(4,1),(5,1),(5,3),(7,1),(7,3),(8,1),(9,1),(9,3),(11,1);
/*!40000 ALTER TABLE `traveller_preference_states` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `traveller_preferences`
--

DROP TABLE IF EXISTS `traveller_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `traveller_preferences` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `trip_days` int(11) NOT NULL,
  `budget` decimal(10,2) NOT NULL,
  `budget_tier` enum('budget','normal','luxury') NOT NULL DEFAULT 'normal',
  `transport_type` enum('car','public_transport','walking','motorcycle') DEFAULT 'car',
  `traveller_type` enum('solo','couple','family','group') NOT NULL DEFAULT 'solo',
  `travel_pace` enum('relaxed','normal','packed') NOT NULL DEFAULT 'normal',
  `dietary_preference` enum('none','halal','vegetarian') NOT NULL DEFAULT 'none',
  `preferred_visit_time` enum('any','morning','afternoon','evening') NOT NULL DEFAULT 'any',
  `accessibility_needs` varchar(120) DEFAULT NULL,
  `interests` set('culture','food','nature','shopping','museum','heritage','festival') NOT NULL,
  `preferred_states` varchar(255) DEFAULT NULL,
  `preferred_districts` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`preference_id`),
  KEY `fk_pref_traveller` (`traveller_id`),
  CONSTRAINT `fk_pref_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `traveller_preferences`
--

LOCK TABLES `traveller_preferences` WRITE;
/*!40000 ALTER TABLE `traveller_preferences` DISABLE KEYS */;
INSERT INTO `traveller_preferences` VALUES (1,1,4,1000.00,'normal','public_transport','solo','normal','none','any',NULL,'food','Johor,Kedah',NULL,'2025-12-14 14:29:01'),(2,1,5,1000.00,'normal','car','solo','normal','none','any',NULL,'food','Johor',NULL,'2025-12-15 09:15:24'),(3,1,3,3000.00,'normal','car','solo','normal','none','any',NULL,'heritage','Johor',NULL,'2025-12-15 10:17:21'),(4,1,1,2222.00,'normal','car','solo','normal','none','any',NULL,'food','Johor',NULL,'2025-12-15 15:15:45'),(5,7,5,2000.00,'normal','car','solo','normal','none','any',NULL,'culture,museum','Johor,Kelantan',NULL,'2025-12-22 02:35:40'),(6,1,3,3000.00,'normal','car','solo','normal','none','any',NULL,'culture,food,nature,shopping,museum,heritage,festival','',NULL,'2025-12-23 11:59:13'),(7,11,2,200.00,'normal','car','solo','normal','none','any',NULL,'culture,food','Johor,Kelantan',NULL,'2026-01-08 14:21:01'),(8,11,1,200.00,'normal','car','solo','normal','none','any',NULL,'culture,food','Johor',NULL,'2026-01-15 01:15:47'),(9,11,2,200.00,'normal','car','solo','normal','none','any',NULL,'culture,food,nature','Johor,Kelantan',NULL,'2026-01-15 01:24:48'),(10,11,3,1000.00,'normal','car','solo','normal','none','any',NULL,'culture,nature,museum,festival','','','2026-04-14 06:38:32'),(11,11,2,1000.00,'normal','public_transport','solo','normal','none','any',NULL,'culture,food,nature,shopping,museum,heritage,festival','Johor','Johor Bahru','2026-05-10 17:52:48');
/*!40000 ALTER TABLE `traveller_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `travellers`
--

DROP TABLE IF EXISTS `travellers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `travellers` (
  `traveller_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `activation_token` varchar(128) DEFAULT NULL,
  `activation_expires` datetime DEFAULT NULL,
  `reset_token` varchar(128) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`traveller_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `travellers`
--

LOCK TABLES `travellers` WRITE;
/*!40000 ALTER TABLE `travellers` DISABLE KEYS */;
INSERT INTO `travellers` VALUES (1,'PECK JIAN HAO','ai230119@student.uthm.edu.my','$2y$10$l0MkWAxDthB48lFi50W5POA6b9/Gf5pTeCn2i4betazI.ggdvhmrS',0,'0123231123','2025-12-13 06:56:11',1,NULL,NULL,NULL,NULL),(3,'WONG','peckjianhao0227@gmail.com','$2y$10$HjX9/FRqOmURvF7paXPfJO5a8f3UL9Ns9i2M3rtalQchIKFvOaOKW',0,'0123122332','2025-12-15 19:17:59',1,NULL,NULL,NULL,NULL),(4,'Pearly','peckjianhao0228@gmail.com','$2y$10$2Yl3pw9YV.YgPTWP5zekpOY/LTdMjij5F2mcKjrk141QXGrkC1Ahu',0,'0114437898','2025-12-15 19:29:52',1,NULL,NULL,NULL,NULL),(5,'Harry','peckjianhao0229@gmail.com','$2y$10$zDEvGjOl9P6ouSFGJvDkh.JYJyNY.LahepDB5IJ/wxlpIedOpVp5C',0,'0114433433','2025-12-15 19:32:13',1,NULL,NULL,NULL,NULL),(6,'TAN','peckjianhao02299@gmail.com','$2y$10$N0Cea.rqV028HtD8xHVS0.Blt0vaEh0jYYBNZBWjAR7K/VNLmN4y.',0,'0114433433','2025-12-15 19:33:33',1,NULL,NULL,NULL,NULL),(7,'Justin','peckjianhao0221@gmail.com','$2y$10$79i8VgjKSdh7R27EDlskCuK1jnEwHfQQlP3l08X1cVm.jukTrMBEy',1,'0116789885','2025-12-15 19:56:32',1,NULL,NULL,NULL,NULL),(11,'PECK JIAN HAO','peckjianhao0226@gmail.com','$2y$10$YyZPHUcc0WAz2NKb7d0C1e4Wnewp4l1FuJKjepzQ0OigxIx7ykgaS',0,'0114433433','2026-01-08 14:19:26',1,NULL,NULL,'e74f42cd420291c480eff1c86764e0676d0b6528f23b0f3c1728df7667b02c39','2026-01-15 03:19:46');
/*!40000 ALTER TABLE `travellers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `validation_logs`
--

DROP TABLE IF EXISTS `validation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `validation_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `action` enum('approved','rejected') NOT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `fk_log_sub` (`submission_id`),
  KEY `fk_log_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `validation_logs`
--

LOCK TABLES `validation_logs` WRITE;
/*!40000 ALTER TABLE `validation_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `validation_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wishlists`
--

DROP TABLE IF EXISTS `wishlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wishlists` (
  `wishlist_id` int(11) NOT NULL AUTO_INCREMENT,
  `traveller_id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wishlist_id`),
  UNIQUE KEY `uq_wishlist_traveller_place` (`traveller_id`,`place_id`),
  KEY `idx_wishlist_place` (`place_id`),
  CONSTRAINT `fk_wishlist_place` FOREIGN KEY (`place_id`) REFERENCES `cultural_places` (`place_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_traveller` FOREIGN KEY (`traveller_id`) REFERENCES `travellers` (`traveller_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wishlists`
--

LOCK TABLES `wishlists` WRITE;
/*!40000 ALTER TABLE `wishlists` DISABLE KEYS */;
/*!40000 ALTER TABLE `wishlists` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-12 15:52:37
