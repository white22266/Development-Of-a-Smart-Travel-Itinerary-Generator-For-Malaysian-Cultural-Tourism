# Smart Travel Itinerary Generator — Changes Documentation

> **PSM Project Enhancement Report**
> Completed by: Manus AI Agent
> Date: April 2026

---

## Overview

This document describes all **new files created** and **existing files modified** to complete the Smart Travel Itinerary Generator system for Malaysian Cultural Tourism. All changes have been pushed to the GitHub repository.

---

## New Files Created

### 1. `services/RouteService.php`

**Function:** Calculates travel distance and time between two GPS coordinates.

**How it works:**
- **Primary mode:** Calls Google Maps Distance Matrix API to get real road distance (km) and travel time (minutes) for a given transport type (car, motorcycle, public_transport, walking).
- **Fallback mode:** If Google Maps API is unavailable or fails, uses the **Haversine formula** to compute straight-line distance × 1.3 road factor.

**Key methods:**
| Method | Description |
|---|---|
| `getSegment($lat1, $lng1, $lat2, $lng2)` | Returns `['distance_km', 'travel_time_min', 'method']` for one segment |
| `buildRoute($places)` | Returns array of segments for an ordered list of places |
| `getTotalRoute($places)` | Returns total distance, total time, and all segments |

**Used by:** `generate_itinerary.php` to save `distance_km` and `travel_time_min` into each `itinerary_items` row.

---

### 2. `services/CostEstimationService.php`

**Function:** Calculates a full 4-component trip cost breakdown.

**Cost components:**
| Component | Formula |
|---|---|
| Attraction / Entrance Fees | Sum of all `itinerary_items.estimated_cost` |
| Transport | `total_distance_km × rate_per_km` (by transport type) |
| Accommodation | `(trip_days - 1) nights × hotel_rate_per_night` |
| Food & Meals | `trip_days × 3 meals × avg_meal_price (RM 15)` |

**Transport rates (RM/km):**
- Car: RM 0.60/km
- Motorcycle: RM 0.30/km
- Public Transport: RM 0.15/km
- Walking: RM 0.00/km

**Key methods:**
| Method | Description |
|---|---|
| `calculate($items, $distKm, $hotelRate)` | Returns full breakdown array including `within_budget` and `budget_difference` |
| `perDayBreakdown($itemsByDay)` | Returns per-day cost summary |
| `formatRM($amount)` | Returns formatted "RM X.XX" string |

**Used by:** `itinerary/trip_summary.php`

---

### 3. `services/HotelRecommendationService.php`

**Function:** Recommends nearby hotels based on location, budget, and rating.

**Algorithm:**
1. Bounding-box pre-filter on `hotels` table (fast SQL)
2. Haversine exact distance filter (within radius)
3. Composite scoring: 40% distance + 30% price + 30% rating
4. Sort by score descending, return top N

**Key methods:**
| Method | Description |
|---|---|
| `recommend($lat, $lng, $budget, $radiusKm, $topN)` | Coordinate-based recommendation |
| `recommendByState($state, $budget, $topN)` | State-based fallback |

**Used by:** `itinerary/trip_summary.php` and `cultural/cultural_guide_detail.php`

---

### 4. `services/FoodRecommendationService.php`

**Function:** Recommends nearby food places based on location, time, budget, and cuisine preference.

**Algorithm:**
1. Bounding-box pre-filter on `food_places` table
2. Haversine exact distance filter
3. Optional open-now filter (parses `opening_hour` field "HH:MM - HH:MM")
4. Composite scoring: 40% distance + 35% price + 25% cuisine match
5. Sort by score descending, return top N

**Key methods:**
| Method | Description |
|---|---|
| `recommend($lat, $lng, $budget, $time, $cuisine, $radiusKm, $topN)` | Full recommendation with all filters |
| `recommendByState($state, $budget, $topN)` | State-based fallback |

**Used by:** `cultural/cultural_guide_detail.php`

---

### 5. `services/RecommendationEngine.php`

**Function:** Modular OOP service for the core itinerary generation logic.

**This is the refactored version of the inline logic in `generate_itinerary.php`, designed as a standalone class for:**
- Filtering cultural places by category, state, and active status
- Scoring places using composite weights (category match, cultural relevance, distance, budget)
- Building day-by-day itinerary with constraints (no repeat, geographic coherence, daily distance limit)

**Scoring weights:**
| Factor | Weight |
|---|---|
| Category match | 35% |
| Cultural relevance | 25% |
| Distance from origin | 25% |
| Budget suitability | 15% |

**Cultural relevance scores by category:**
- Heritage: 1.0 (highest)
- Museum / Culture: 0.9
- Festival: 0.8
- Food: 0.7
- Nature: 0.5
- Shopping: 0.3

**Usage:** Can be used as an alternative to the inline logic in `generate_itinerary.php`:
```php
$engine = new RecommendationEngine($conn, $preferences);
$result = $engine->generate();
// Returns: ['days' => [...], 'total_cost' => float, 'total_places' => int, 'warnings' => [...]]
```

---

### 6. `itinerary/geocode_origin.php`

**Function:** Server-side geocoding proxy that converts a location name to GPS coordinates.

**How it works:**
- Receives `?q=Kuala+Lumpur,+Malaysia` via GET
- Calls Google Maps Geocoding API
- Returns `{"lat": 3.139, "lng": 101.686, "formatted_address": "..."}`

**Used by:** JavaScript in `select_preference.php` to auto-geocode the user's typed origin location.

---

### 7. `migration_hotels_food.sql`

**Function:** Database migration script to create and populate the `hotels` and `food_places` tables.

**Tables created:**
- `hotels` — 45 sample hotels across all Malaysian states
- `food_places` — 45 sample food places across all Malaysian states

**To run:** Import this SQL file in phpMyAdmin after the main `travel_itinerary_db.sql`.

---

## Modified Files

### 8. `itinerary/generate_itinerary.php` (Modified)

**Changes:**
1. **Added `require_once "../services/RouteService.php"`** at the top
2. **Added origin location capture** from POST: `origin_lat`, `origin_lng`, `origin_name`
3. **Updated INSERT statement** to include `distance_km` and `travel_time_min` columns
4. **Instantiates `RouteService`** with the user's transport type and Google Maps API key
5. **Tracks `$prevLat` / `$prevLng`** — starts from user's origin (if provided), then updates to each successive place
6. **Calls `$routeSvc->getSegment()`** for each itinerary item to calculate real distance and travel time
7. **Saves distance and time** into each `itinerary_items` row

**Impact:** Every generated itinerary now has accurate distance and travel time data per item, enabling the trip summary to show real route statistics.

---

### 9. `itinerary/trip_summary.php` (Major Rewrite)

**Before:** Only showed a simple table of items with a grand total cost.

**After — New features:**
1. **Trip metadata grid** — Duration, transport type, start date, total distance, budget, states
2. **Full 4-component cost breakdown** — Attraction fees + Transport + Accommodation + Food
3. **Budget comparison badge** — Green "✓ Within Budget" or Red "⚠ Over Budget" with remaining/over amount
4. **Day-by-day table** — Shows state, category chip, distance, and travel time per item
5. **Hotel recommendations section** — Top 5 nearby hotels with star ratings, price/night, and Google Maps link
6. **Integrates** `CostEstimationService` and `HotelRecommendationService`

---

### 10. `itinerary/select_preference.php` (Modified)

**Change:** Added a new **"Your Starting Location"** input field to the itinerary generation form.

- Text input for city/address name
- Hidden fields for `origin_lat` and `origin_lng`
- JavaScript auto-geocodes the typed location via `geocode_origin.php` after 800ms debounce
- Passes origin coordinates to `generate_itinerary.php` via POST

---

### 11. `cultural/cultural_guide_detail.php` (Modified)

**Before:** Showed only place details (image, info, description).

**After — New sections added:**
1. **"Nearby Food & Restaurants"** — Top 5 nearby food places with cuisine type, price/meal, rating stars, and Google Maps link
2. **"Nearby Hotels & Accommodation"** — Top 5 nearby hotels with price/night, rating stars, and Google Maps link
3. **Integrates** `FoodRecommendationService` and `HotelRecommendationService`
4. **Fallback by state** if no nearby results found by coordinates

---

## Database Changes Required

Run `migration_hotels_food.sql` in phpMyAdmin to add:

```sql
-- Two new tables needed by the services:
CREATE TABLE hotels (...)       -- 45 rows of sample data
CREATE TABLE food_places (...)  -- 45 rows of sample data
```

The `itinerary_items` table already has `distance_km` and `travel_time_min` columns in the existing schema — no migration needed for those.

---

## How the System Now Works (Complete Flow)

```
Traveller fills Preference Form
        ↓
select_preference.php
  → Choose preference
  → Enter starting location (optional, auto-geocoded)
  → Choose start date, items/day, route strategy
        ↓
generate_itinerary.php
  → Loads preference (budget, transport, states, interests)
  → Fetches cultural places from DB
  → Groups by state, builds day-by-day schedule
  → For each item: calls RouteService to get distance + travel time
  → Saves itinerary + items (with distance/time) to DB
        ↓
itinerary_view.php
  → Shows Google Maps with markers + route polyline
  → Weather widget per day
        ↓
trip_summary.php
  → Loads items (with distance/time from DB)
  → CostEstimationService: 4-component cost breakdown
  → HotelRecommendationService: top 5 nearby hotels
  → Budget comparison badge (within/over)
  → Export PDF button
        ↓
cultural_guide_detail.php
  → Place details + cultural background
  → FoodRecommendationService: top 5 nearby food places
  → HotelRecommendationService: top 5 nearby hotels
```

---

## GitHub Repository

All changes have been committed and pushed:
- **Commit:** `feat: Add missing service classes, enhanced UI pages, and origin-aware routing`
- **Files changed:** 11 files, 2,358 insertions
