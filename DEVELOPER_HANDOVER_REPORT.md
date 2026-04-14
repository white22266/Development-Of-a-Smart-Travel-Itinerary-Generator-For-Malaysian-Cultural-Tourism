# Developer Handover Report: Smart Travel Itinerary Generator for Malaysian Cultural Tourism

**Date:** April 14, 2026  
**Project:** Smart Travel Itinerary Generator (PSM Final Report Implementation)  
**Author:** Manus AI

---

## 1. Project Overview

This project is a web-based system designed to automate the generation of travel itineraries with a strong focus on promoting Malaysian cultural tourism. The system provides an end-to-end experience for travellers: from capturing their preferences (budget, transport mode, interests, preferred states/districts) to generating an optimized, day-by-day itinerary complete with cultural attractions, nearby hotels, local food recommendations, and full transit routing.

During the recent development phase, the core rule-based itinerary generation logic was heavily expanded into a modular Object-Oriented (OOP) architecture. Major features added include a Moovit-style public transport routing engine, a post-generation interactive review system, district-level geographic granularity, and a fully interactive multi-day Google Maps integration.

---

## 2. System Architecture & New Services

The backend logic has been refactored out of monolithic procedural scripts into dedicated OOP service classes located in the `/services/` directory. These services are auto-loaded where needed and handle specific domains of the generation algorithm.

### 2.1 Core Services

| Service Class | Responsibility |
|---|---|
| `RecommendationEngine.php` | The main brain of the system. It filters places by state/district, scores them based on category match, budget, and rating, and builds the day-by-day schedule enforcing diversity constraints (no consecutive identical categories). |
| `RouteService.php` | Calculates travel distance and time between two points. It attempts to use the Google Maps Distance Matrix API first, falling back to the offline Haversine formula if the API fails or is unconfigured. |
| `TransitService.php` | The Moovit-style public transport engine. It calls the Google Maps Directions API (Transit mode) and parses the response into detailed step-by-step instructions, classifying Malaysian transit lines (MRT, LRT, KTM, Monorail, Rapid KL Bus) with correct icons and colours. |
| `CostEstimationService.php` | Provides a comprehensive 4-component cost breakdown for the trip: Attraction entrance fees, Transport (RM/km by mode), Accommodation (nights × hotel rate), and Food (days × meals × price). It returns a `within_budget` flag and exact variance. |
| `HotelRecommendationService.php` | Recommends nearby hotels using a composite scoring algorithm (40% distance + 30% price + 30% rating). It uses a bounding-box SQL pre-filter followed by exact Haversine distance checking. |
| `FoodRecommendationService.php` | Recommends nearby food places using a similar composite score (40% distance + 35% price + 25% cuisine match), ensuring the place is currently open based on `opening_hours`. |

---

## 3. Key Features Implemented

### 3.1 Interactive Itinerary Review System
Instead of forcing the generated itinerary onto the user, the system now redirects to `itinerary_review.php` immediately after generation.
- **Match Scores:** Every generated place displays a composite match score (Excellent/Good/Fair/Low) based on category fit, budget, and rating.
- **Accept/Reject/Replace:** Users can explicitly accept or remove places. Clicking "Replace" triggers an AJAX call to `review_replace.php` which finds the next best alternative in the database.
- **Hotel Selection:** Users select their preferred hotel from a list of algorithm-recommended nearby hotels.

### 3.2 Multi-Day Google Maps Integration
The `itinerary_view.php` page was completely rebuilt to provide a Google Maps experience.
- **Colour-Coded Routes:** Every day of the itinerary is rendered simultaneously on a single map, with each day assigned a unique colour (e.g., Day 1 = Red, Day 2 = Blue).
- **Origin-Aware Routing:** Day 1 routes begin from the user's actual GPS location (detected via browser geolocation), while subsequent days begin from the previous night's hotel.
- **Transport Mode Switching:** Users can instantly toggle between Car, Motorcycle, Public Transport, and Walking. The map and all travel time estimates recalculate immediately.

### 3.3 Moovit-Style Public Transport Routing
A major addition for Malaysian users is the detailed public transport panel.
- **Step-by-Step Directions:** Between every two stops in the timetable, users can click "Show Route" to expand an AJAX-loaded panel detailing the exact transit steps.
- **Line Classification:** The system parses Google's raw transit data and visually classifies Malaysian lines (e.g., MRT Putrajaya Line appears with its official purple colour and icon).
- **Transfer Details:** Includes walk segments, number of stops, departure/arrival times, and estimated fares.

### 3.4 District-Level Granularity
The system now supports 138 districts across all 16 Malaysian states and federal territories.
- **Cascading Dropdowns:** The preference form uses JavaScript to enable the district dropdown only after a state is selected.
- **Database Support:** The `malaysia_districts` reference table was added, and the `district` column was appended to the `cultural_places`, `hotels`, and `food_places` tables.

---

## 4. Database Schema Updates

To support the new features, several tables were added or modified. The `COMPLETE_DATABASE_UPDATE.sql` file contains all these changes and should be run on any new environment.

### 4.1 New Tables
- **`hotels`**: Stores hotel name, state, district, latitude, longitude, price per night, and star rating.
- **`food_places`**: Stores restaurant name, state, district, latitude, longitude, cuisine type, average price, rating, and opening hours.
- **`malaysia_districts`**: A reference table containing all 138 districts mapped to their respective states.

### 4.2 Modified Tables
- **`cultural_places`**: Added `district` column.
- **`traveller_preferences`**: Added `preferred_districts` column.
- **`itinerary_items`**: The `distance_km` and `travel_time_min` columns are now actively populated by the `RouteService` during generation.

---

## 5. File Inventory & Directory Structure

```text
travel-itinerary/
├── admin/                        # Admin dashboard and KB management
│   ├── admin_cultural_kb.php     # Updated with district dropdowns and filters
│   └── admin_cultural_kb_process.php
├── auth/                         # Authentication (login/register)
├── config/
│   ├── api_keys.php              # Contains GOOGLE_MAPS_API_KEY and OPENWEATHER_API_KEY
│   └── db_connect.php
├── cultural/                     # Cultural guide pages
│   ├── cultural_guide.php        # Updated with district filters
│   └── cultural_guide_detail.php # Enhanced with "Nearby Hotels" and "Nearby Food"
├── itinerary/                    # Core itinerary features
│   ├── generate_itinerary.php    # The generation controller (redirects to review)
│   ├── itinerary_review.php      # NEW: Post-generation review UI
│   ├── review_replace.php        # NEW: AJAX endpoint for place replacement
│   ├── itinerary_view.php        # REBUILT: Full Google Maps and Moovit UI
│   ├── transit_route.php         # NEW: AJAX endpoint for transit directions
│   ├── trip_summary.php          # Enhanced with full 4-component cost breakdown
│   ├── select_preference.php     # Updated with geolocation and single route strategy
│   └── geocode_origin.php        # NEW: Forward/reverse geocoding proxy
├── preference/
│   ├── preference_form.php       # Updated with cascading State -> District dropdowns
│   └── preference_process.php
├── services/                     # NEW: OOP Backend Services
│   ├── RecommendationEngine.php
│   ├── RouteService.php
│   ├── TransitService.php
│   ├── CostEstimationService.php
│   ├── HotelRecommendationService.php
│   └── FoodRecommendationService.php
├── COMPLETE_DATABASE_UPDATE.sql  # Master SQL file for all schema changes and seed data
└── CHANGES_DOCUMENTATION.md      # Summary of changes made during the sprint
```

---

## 6. Setup & Deployment Instructions

For the next developer taking over the environment, follow these steps to ensure the system runs correctly:

1. **Database Initialization:**
   - Import the original `travel_itinerary_db.sql` via phpMyAdmin or CLI.
   - Immediately import `COMPLETE_DATABASE_UPDATE.sql`. This will create the `hotels`, `food_places`, and `malaysia_districts` tables, alter existing tables to add `district` columns, and seed the database with sample data.

2. **API Keys Configuration:**
   - Open `config/api_keys.php`.
   - Ensure a valid `GOOGLE_MAPS_API_KEY` is provided. The key must have the following APIs enabled in the Google Cloud Console:
     - Maps JavaScript API
     - Directions API (critical for `TransitService`)
     - Distance Matrix API (critical for `RouteService`)
     - Geocoding API (critical for `geocode_origin.php`)
   - Ensure a valid `OPENWEATHER_API_KEY` is provided for the weather chip on the map view.

3. **Fallback Behaviour:**
   - If the Google Maps API key is missing or quota is exceeded, the backend services (`RouteService`, `TransitService`) will gracefully fall back to offline Haversine formula calculations. The UI will display warnings indicating that times and distances are estimated via straight-line mathematics.

---

## 7. Remaining Work & Future Enhancements

While the core PSM requirements are complete, the following areas can be targeted for future optimization:

1. **Live Traffic Integration:** The `RouteService` currently requests standard driving times. It could be enhanced to request `departure_time=now` to factor in live traffic conditions for the `car` and `motorcycle` modes.
2. **User-Submitted Hotels & Food:** Currently, users can suggest cultural places via `suggest_place.php`. This workflow could be expanded to allow users to suggest local restaurants and boutique hotels.
3. **PDF Export Enhancements:** The `export_pdf.php` uses DomPDF. It currently exports a basic table. It could be enhanced to capture the static map image via the Google Maps Static API and embed it directly into the PDF.
4. **Pagination for Transit Caching:** The `itinerary_view.php` caches transit routes client-side in the `transitCache` object. For very long itineraries (e.g., 14 days), this could be moved to `sessionStorage` to persist across page reloads.
