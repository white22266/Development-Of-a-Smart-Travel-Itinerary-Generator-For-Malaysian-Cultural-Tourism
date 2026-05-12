-- migration_seed_johor_cultural_places.sql
-- Purpose: Expand Johor cultural_places coverage to at least 4 tourism/cultural entries per district.
-- Sources used for place selection/verification: TripAdvisor place listings, Google Maps place entries,
-- and public tourism references. Coordinates/costs/opening hours are practical planning estimates and
-- should be reviewed by admin before final publication.

START TRANSACTION;

-- Correct an existing district mismatch so Pontian coverage and route grouping are more accurate.
UPDATE cultural_places
SET district = 'Pontian',
    updated_at = CURRENT_TIMESTAMP
WHERE state = 'Johor'
  AND name = 'Taman Negara Johor Pulau Kukup';

CREATE TEMPORARY TABLE tmp_johor_cultural_seed (
    state VARCHAR(60),
    district VARCHAR(80),
    category VARCHAR(30),
    name VARCHAR(150),
    description TEXT,
    address VARCHAR(255),
    latitude DECIMAL(10,7),
    longitude DECIMAL(10,7),
    opening_hours VARCHAR(1000),
    estimated_cost DECIMAL(10,2),
    entrance_fee DECIMAL(8,2),
    is_free TINYINT(1),
    halal_status TINYINT(1),
    is_outdoor TINYINT(1),
    visit_duration_min INT,
    best_time_to_visit VARCHAR(100),
    dress_code_required TINYINT(1),
    website_url VARCHAR(300)
) ENGINE=InnoDB;

INSERT INTO tmp_johor_cultural_seed
(state, district, category, name, description, address, latitude, longitude, opening_hours, estimated_cost, entrance_fee, is_free, halal_status, is_outdoor, visit_duration_min, best_time_to_visit, dress_code_required, website_url)
VALUES
-- Johor Bahru
('Johor','Johor Bahru','heritage','Sultan Abu Bakar State Mosque','Historic hilltop mosque overlooking the Johor Strait, suitable for heritage and architecture-focused trips.','Jalan Gertak Merah, 80000 Johor Bahru, Johor',1.4578000,103.7526000,'Daily, outside prayer times; visitors should verify before arrival',0.00,0.00,1,NULL,0,60,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Sultan+Abu+Bakar+State+Mosque+Johor+Bahru'),
('Johor','Johor Bahru','heritage','Arulmigu Sri Rajakaliamman Glass Temple','Distinctive Hindu temple known for glass mosaic interiors and cultural architecture.','Jalan Tebrau, Wadi Hana, 80300 Johor Bahru, Johor',1.4671000,103.7575000,'Usually daytime; verify current visiting hours',10.00,10.00,0,NULL,0,60,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Arulmigu+Sri+Rajakaliamman+Glass+Temple+Johor+Bahru'),
('Johor','Johor Bahru','heritage','Tan Hiok Nee Heritage Street','Old-town heritage street with traditional shophouses, cafes, murals, and local food stops.','Jalan Tan Hiok Nee, Bandar Johor Bahru, 80000 Johor Bahru, Johor',1.4564000,103.7637000,'Open area; shops vary by tenant',0.00,0.00,1,NULL,1,90,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Tan+Hiok+Nee+Heritage+Street+Johor+Bahru'),
('Johor','Johor Bahru','shopping','Pasar Karat Johor Bahru','Night market and flea-market area for local snacks, souvenirs, and street culture.','Jalan Segget, Bandar Johor Bahru, 80000 Johor Bahru, Johor',1.4574000,103.7639000,'Evening to late night; vendor hours vary',0.00,0.00,1,NULL,1,90,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Pasar+Karat+Johor+Bahru'),

-- Batu Pahat
('Johor','Batu Pahat','nature','Pantai Minyak Beku','Coastal viewpoint linked with Batu Pahat local history and sunset scenery.','Minyak Beku, 83000 Batu Pahat, Johor',1.7429000,102.8831000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,75,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Pantai+Minyak+Beku+Batu+Pahat'),
('Johor','Batu Pahat','culture','Dataran Penggaram Batu Pahat','Town square landmark suitable for short city orientation and local photography.','Jalan Rahmat, Kampung Pegawai, 83000 Batu Pahat, Johor',1.8544000,102.9320000,'Open area',0.00,0.00,1,NULL,1,45,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Dataran+Penggaram+Batu+Pahat'),
('Johor','Batu Pahat','culture','Batu Pahat Street Art','Old-town mural area for cultural walking routes and photo stops.','Bandar Penggaram, 83000 Batu Pahat, Johor',1.8502000,102.9343000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Batu+Pahat+Street+Art'),
('Johor','Batu Pahat','nature','Hutan Lipur Soga Perdana','Recreational forest trail close to town, suitable for relaxed outdoor visits.','Taman Soga, 83000 Batu Pahat, Johor',1.8645000,102.9550000,'Daytime; verify park notices',0.00,0.00,1,NULL,1,90,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Hutan+Lipur+Soga+Perdana+Batu+Pahat'),
('Johor','Batu Pahat','food','Nasi Beriani Mohd Shah 40 Hari','Well-known Batu Pahat biryani restaurant often included in local food itineraries.','Batu Pahat, Johor',1.8427000,102.9345000,'Lunch hours; verify branch hours',18.00,18.00,0,1,0,60,'Lunch',0,'https://www.google.com/maps/search/?api=1&query=Nasi+Beriani+Mohd+Shah+40+Hari+Batu+Pahat'),

-- Kluang
('Johor','Kluang','nature','Gunung Lambak Recreational Forest','Popular hill and forest trail near Kluang town for outdoor travellers.','Gunung Lambak, 86000 Kluang, Johor',2.0251000,103.3582000,'Daytime; morning hiking recommended',0.00,0.00,1,NULL,1,150,'Early morning',0,'https://www.google.com/maps/search/?api=1&query=Gunung+Lambak+Kluang'),
('Johor','Kluang','nature','UK Farm Agro Resort','Agro-tourism farm with animal activities and countryside experiences.','Projek Pertanian Moden Kluang, 86000 Kluang, Johor',2.1220000,103.4014000,'Usually daytime; booking/operating hours vary',47.00,47.00,0,NULL,1,180,'Morning',0,'https://www.google.com/maps/search/?api=1&query=UK+Farm+Agro+Resort+Kluang'),
('Johor','Kluang','nature','Zenxin Organic Park','Organic farm park with gardens, farm activities, and family-friendly outdoor areas.','47, Jalan Batu Pahat, 86000 Kluang, Johor',1.9807000,103.2830000,'Usually daytime; verify current hours',15.00,15.00,0,NULL,1,120,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Zenxin+Organic+Park+Kluang'),
('Johor','Kluang','food','Kluang Rail Coffee','Historic railway coffee stop associated with Kluang local food culture.','Kluang Railway Station, 86000 Kluang, Johor',2.0332000,103.3190000,'Morning to evening; verify current hours',15.00,15.00,0,NULL,0,60,'Breakfast',0,'https://www.google.com/maps/search/?api=1&query=Kluang+Rail+Coffee'),
('Johor','Kluang','culture','Kluang Street Art','Mural and old-town walking area near central Kluang.','Bandar Kluang, 86000 Kluang, Johor',2.0347000,103.3197000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Kluang+Street+Art'),

-- Kota Tinggi
('Johor','Kota Tinggi','nature','Kota Tinggi Waterfalls','Forest waterfall attraction suitable for nature and family itineraries.','Kota Tinggi Waterfalls, 81900 Kota Tinggi, Johor',1.8386000,103.8486000,'Daytime; verify ticket counter hours',10.00,10.00,0,NULL,1,150,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Kota+Tinggi+Waterfalls'),
('Johor','Kota Tinggi','nature','Kota Tinggi Firefly Park','Evening river cruise area known for firefly viewing.','Kota Tinggi, Johor',1.7358000,103.9017000,'Evening tours; booking recommended',25.00,25.00,0,NULL,1,90,'Night',0,'https://www.google.com/maps/search/?api=1&query=Kota+Tinggi+Firefly+Park'),
('Johor','Kota Tinggi','nature','Desaru Fruit Farm','Fruit farm attraction with guided agro-tourism activities.','92, 82200 Kota Tinggi, Johor',1.5604000,104.2095000,'Usually daytime; verify package hours',30.00,30.00,0,NULL,1,120,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Desaru+Fruit+Farm'),
('Johor','Kota Tinggi','nature','Teluk Sengat Crocodile Farm','Long-running crocodile farm attraction near Teluk Sengat.','Teluk Sengat, Kota Tinggi, Johor',1.5825000,104.0014000,'Usually daytime; verify current hours',12.00,12.00,0,NULL,1,75,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Teluk+Sengat+Crocodile+Farm'),
('Johor','Kota Tinggi','nature','Desaru Beach','Coastal beach area used for leisure, sunrise, and family travel plans.','Desaru, 81930 Kota Tinggi, Johor',1.5408000,104.2609000,'Open area; resort facilities vary',0.00,0.00,1,NULL,1,120,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Desaru+Beach+Johor'),

-- Kulai
('Johor','Kulai','heritage','Putuo Village','Buddhist cultural village with gardens, prayer halls, and photo spots.','PTD 210001, Jalan Felda Taib Andak, 81000 Kulai, Johor',1.6710000,103.5916000,'Usually daytime; verify current hours',5.00,5.00,0,NULL,1,90,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Putuo+Village+Kulai'),
('Johor','Kulai','nature','Gunung Pulai Recreational Forest','Forest reserve and waterfall area popular for hiking and nature visits.','Gunung Pulai, 81000 Kulai, Johor',1.5965000,103.5441000,'Daytime; trail access may change',0.00,0.00,1,NULL,1,180,'Early morning',0,'https://www.google.com/maps/search/?api=1&query=Gunung+Pulai+Recreational+Forest'),
('Johor','Kulai','nature','Hutan Bandar Putra Kulai','Urban recreational park suitable for family and relaxed outdoor stops.','Bandar Putra, 81000 Kulai, Johor',1.6566000,103.6038000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,60,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Hutan+Bandar+Putra+Kulai'),
('Johor','Kulai','shopping','Johor Premium Outlets','Major outlet shopping destination near Kulai and Senai.','Jalan Premium Outlets, Indahpura, 81000 Kulai, Johor',1.6039000,103.6275000,'Usually 10:00 AM - 10:00 PM; verify holiday hours',0.00,0.00,1,NULL,0,120,'Afternoon',0,'https://www.google.com/maps/search/?api=1&query=Johor+Premium+Outlets'),

-- Mersing
('Johor','Mersing','culture','Mersing Harbour Centre','Main harbour gateway area for island trips and local town orientation.','Jalan Abu Bakar, 86800 Mersing, Johor',2.4311000,103.8378000,'Usually daytime to evening; ferry hours vary',0.00,0.00,1,NULL,1,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Mersing+Harbour+Centre'),
('Johor','Mersing','nature','Pantai Air Papan','Beach area near Mersing for coastal scenery and family stops.','Air Papan, 86800 Mersing, Johor',2.5195000,103.8360000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,120,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Pantai+Air+Papan+Mersing'),
('Johor','Mersing','museum','Muzium Mersing','Local museum covering Mersing history and district background.','Bandar Mersing, 86800 Mersing, Johor',2.4319000,103.8411000,'Office hours; closed days may apply',2.00,2.00,0,NULL,0,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Muzium+Mersing'),
('Johor','Mersing','heritage','Masjid Jamek Bandar Mersing','Town mosque landmark close to the harbour and old town area.','Bandar Mersing, 86800 Mersing, Johor',2.4300000,103.8370000,'Daily, outside prayer times; visitors should verify before arrival',0.00,0.00,1,NULL,0,45,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Masjid+Jamek+Bandar+Mersing'),
('Johor','Mersing','nature','Pulau Besar Mersing','Island destination known for beaches and relaxed coastal scenery.','Pulau Besar, Mersing, Johor',2.4374000,103.9760000,'Ferry and resort schedules vary',0.00,0.00,1,NULL,1,240,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Pulau+Besar+Mersing'),

-- Muar
('Johor','Muar','heritage','Masjid Jamek Sultan Ibrahim Muar','Riverside royal mosque and major architectural landmark in Muar.','Jalan Petri, Tanjung Emas, 84000 Muar, Johor',2.0497000,102.5687000,'Daily, outside prayer times; visitors should verify before arrival',0.00,0.00,1,NULL,0,45,'Morning',1,'https://www.google.com/maps/search/?api=1&query=Masjid+Jamek+Sultan+Ibrahim+Muar'),
('Johor','Muar','nature','Tanjung Emas Park','Riverside park with sunset views and family recreation areas.','Tanjung Emas, 84000 Muar, Johor',2.0451000,102.5659000,'Open area',0.00,0.00,1,NULL,1,90,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Tanjung+Emas+Park+Muar'),
('Johor','Muar','culture','Maharani Mural Lane','Mural lane and old-town photo route in central Muar.','Bandar Maharani, 84000 Muar, Johor',2.0447000,102.5682000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Maharani+Mural+Lane+Muar'),
('Johor','Muar','culture','Muar Cultural Walk','Old-town walking route covering shophouses, local food streets, and riverside heritage.','Bandar Maharani, 84000 Muar, Johor',2.0467000,102.5684000,'Open area; shop hours vary',0.00,0.00,1,NULL,1,90,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Muar+Cultural+Walk'),
('Johor','Muar','food','Mee Bandung Abu Bakar Hanipah','Well-known Muar mee bandung restaurant often included in food itineraries.','Jalan Abdullah, 84000 Muar, Johor',2.0501000,102.5689000,'Meal hours; verify current branch hours',12.00,12.00,0,1,0,60,'Lunch',0,'https://www.google.com/maps/search/?api=1&query=Mee+Bandung+Abu+Bakar+Hanipah+Muar'),

-- Pontian
('Johor','Pontian','nature','Tanjung Piai National Park','Southernmost tip of mainland Asia with mangrove boardwalks and coastal ecology.','Tanjung Piai, 82030 Pontian, Johor',1.2676000,103.5080000,'Usually daytime; verify park hours',10.00,10.00,0,NULL,1,120,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Tanjung+Piai+National+Park'),
('Johor','Pontian','nature','Pulau Kukup National Park','Mangrove island national park known for boardwalks and coastal biodiversity.','Pulau Kukup, 82300 Kukup, Pontian, Johor',1.3217000,103.4428000,'Usually daytime; boat/park access varies',10.00,10.00,0,NULL,1,150,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Pulau+Kukup+National+Park'),
('Johor','Pontian','culture','Kukup Fishing Village','Traditional coastal fishing village with seafood, jetty views, and village culture.','Kukup, 82300 Pontian, Johor',1.3290000,103.4420000,'Open area; business hours vary',0.00,0.00,1,NULL,1,90,'Morning or evening',0,'https://www.google.com/maps/search/?api=1&query=Kukup+Fishing+Village'),
('Johor','Pontian','museum','Pineapple Museum Pontian','Museum highlighting pineapple cultivation history around Pekan Nanas.','Pekan Nanas, 81500 Pontian, Johor',1.5116000,103.5142000,'Office hours; verify opening days',2.00,2.00,0,NULL,0,60,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Pineapple+Museum+Pontian'),
('Johor','Pontian','culture','Pontian Seaside','Town seafront area for relaxed evening walks and local food stops.','Pontian Kechil, 82000 Pontian, Johor',1.4862000,103.3894000,'Open area',0.00,0.00,1,NULL,1,60,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Pontian+Seaside'),

-- Segamat
('Johor','Segamat','nature','Hutan Lipur Sungai Bantang','Recreational forest and waterfall area often used for nature trips from Segamat/Labis.','Bekok, Segamat District, Johor',2.3919000,102.9270000,'Daytime; verify park notices',0.00,0.00,1,NULL,1,150,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Hutan+Lipur+Sungai+Bantang+Segamat'),
('Johor','Segamat','culture','Dataran Segamat','Central town square landmark for short city orientation and local events.','Bandar Segamat, 85000 Segamat, Johor',2.5144000,102.8159000,'Open area',0.00,0.00,1,NULL,1,45,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Dataran+Segamat'),
('Johor','Segamat','heritage','Jambatan Putus Buloh Kasap','Historic broken bridge landmark associated with local wartime memory.','Buloh Kasap, 85000 Segamat, Johor',2.4876000,102.7779000,'Open area; daytime recommended',0.00,0.00,1,NULL,1,45,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Jambatan+Putus+Buloh+Kasap'),
('Johor','Segamat','nature','Rock Garden Segamat','Town garden and recreational landmark for relaxed short visits.','Bandar Segamat, 85000 Segamat, Johor',2.5135000,102.8208000,'Open area',0.00,0.00,1,NULL,1,60,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Rock+Garden+Segamat'),
('Johor','Segamat','food','Segamat Old Town Food Street','Local food area suitable for simple meal planning in Segamat town.','Bandar Segamat, 85000 Segamat, Johor',2.5140000,102.8120000,'Business hours vary by shop',15.00,15.00,0,NULL,0,60,'Lunch or dinner',0,'https://www.google.com/maps/search/?api=1&query=Segamat+Old+Town+Food+Street'),

-- Tangkak
('Johor','Tangkak','nature','Gunung Ledang National Park','Iconic mountain park linked with Johor folklore and hiking routes.','Taman Negara Johor Gunung Ledang, 84900 Tangkak, Johor',2.3408000,102.6135000,'Daytime; hiking permits and hours vary',10.00,10.00,0,NULL,1,180,'Early morning',0,'https://www.google.com/maps/search/?api=1&query=Gunung+Ledang+National+Park'),
('Johor','Tangkak','nature','Sagil Waterfall','Waterfall stop near Gunung Ledang for nature-focused itineraries.','Sagil, 84900 Tangkak, Johor',2.3175000,102.6170000,'Daytime; verify current access',5.00,5.00,0,NULL,1,120,'Morning',0,'https://www.google.com/maps/search/?api=1&query=Sagil+Waterfall+Tangkak'),
('Johor','Tangkak','shopping','Tangkak Textile Town','Textile and fabric shopping area associated with Tangkak town identity.','Bandar Tangkak, 84900 Tangkak, Johor',2.2665000,102.5450000,'Business hours vary by shop',0.00,0.00,1,NULL,0,90,'Afternoon',0,'https://www.google.com/maps/search/?api=1&query=Tangkak+Textile+Town'),
('Johor','Tangkak','culture','Dataran Tangkak','Town square and local landmark for short stops in Tangkak.','Bandar Tangkak, 84900 Tangkak, Johor',2.2675000,102.5455000,'Open area',0.00,0.00,1,NULL,1,45,'Evening',0,'https://www.google.com/maps/search/?api=1&query=Dataran+Tangkak'),
('Johor','Tangkak','food','Tangkak Beef Noodles','Recognised local noodle dish commonly associated with Tangkak food stops.','Bandar Tangkak, 84900 Tangkak, Johor',2.2679000,102.5461000,'Meal hours; verify current shop hours',15.00,15.00,0,NULL,0,60,'Lunch',0,'https://www.google.com/maps/search/?api=1&query=Tangkak+Beef+Noodles');

INSERT INTO cultural_places
(state, district, category, name, description, address, latitude, longitude, opening_hours,
 estimated_cost, entrance_fee, is_free, halal_status, is_outdoor, visit_duration_min,
 best_time_to_visit, dress_code_required, website_url, is_active)
SELECT t.state, t.district, t.category, t.name, t.description, t.address, t.latitude, t.longitude, t.opening_hours,
       t.estimated_cost, t.entrance_fee, t.is_free, t.halal_status, t.is_outdoor, t.visit_duration_min,
       t.best_time_to_visit, t.dress_code_required, t.website_url, 1
FROM tmp_johor_cultural_seed t
WHERE NOT EXISTS (
    SELECT 1
    FROM cultural_places cp
    WHERE cp.state = t.state
      AND COALESCE(cp.district, '') = COALESCE(t.district, '')
      AND LOWER(cp.name) = LOWER(t.name)
);

DROP TEMPORARY TABLE tmp_johor_cultural_seed;

COMMIT;

-- Verification:
-- SELECT district, COUNT(*) AS total FROM cultural_places WHERE state='Johor' GROUP BY district ORDER BY district;
