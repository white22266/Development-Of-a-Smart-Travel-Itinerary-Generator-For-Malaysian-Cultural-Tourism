<?php
/**
 * services/FoodRecommendationService.php
 *
 * FoodRecommendationService — Recommends nearby food places based on location, time, and budget.
 *
 * Steps:
 *   1. Query food_places table for nearby places (within radius)
 *   2. Filter: open now (optional), within radius, within budget
 *   3. Score: distance + price + cuisine match
 *   4. Sort by score descending
 *   5. Return top N results
 *
 * Usage:
 *   $frs = new FoodRecommendationService($conn);
 *   $foods = $frs->recommend($lat, $lng, $budget, $currentTime, $cuisinePreference, $radiusKm, $topN);
 *
 * Requires: food_places table in database (see SQL migration)
 */

class FoodRecommendationService
{
    /** @var mysqli Database connection */
    private $conn;

    /** Default search radius in km */
    private const DEFAULT_RADIUS_KM = 5.0;

    /** Default number of results */
    private const DEFAULT_TOP_N = 5;

    /** Scoring weights */
    private const WEIGHT_DISTANCE = 0.40;
    private const WEIGHT_PRICE    = 0.35;
    private const WEIGHT_CUISINE  = 0.25;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // =========================================================
    // PUBLIC: Main recommendation method
    // =========================================================

    /**
     * Recommend nearby food places.
     *
     * @param float       $lat              Reference latitude (attraction location)
     * @param float       $lng              Reference longitude
     * @param float       $budget           Max avg_price per meal (0 = no limit)
     * @param string|null $currentTime      Current time as "HH:MM" (null = skip open check)
     * @param string      $cuisinePreference Preferred cuisine type ('' = any)
     * @param float       $radiusKm         Search radius in km
     * @param int         $topN             Maximum results to return
     * @return array  Array of food place records with 'distance_km' and 'score'
     */
    public function recommend(
        float   $lat,
        float   $lng,
        float   $budget           = 0.0,
        ?string $currentTime      = null,
        string  $cuisinePreference = '',
        float   $radiusKm         = 0.0,
        int     $topN             = 0
    ): array {
        if ($radiusKm <= 0) $radiusKm = self::DEFAULT_RADIUS_KM;
        if ($topN <= 0)     $topN     = self::DEFAULT_TOP_N;

        // ---- 1. Fetch food places from database ----
        $places = $this->fetchFoodPlaces($lat, $lng, $radiusKm, $budget);

        if (empty($places)) {
            // Fallback: wider radius
            $places = $this->fetchFoodPlaces($lat, $lng, $radiusKm * 3, $budget);
        }

        if (empty($places)) return [];

        // ---- 2. Filter: open now ----
        if ($currentTime !== null) {
            $places = $this->filterOpenNow($places, $currentTime);
            // If all filtered out, skip time filter
            if (empty($places)) {
                $places = $this->fetchFoodPlaces($lat, $lng, $radiusKm, $budget);
            }
        }

        if (empty($places)) return [];

        // ---- 3. Score ----
        $maxDist  = max(array_column($places, 'distance_km'));
        $maxPrice = max(array_column($places, 'avg_price'));

        foreach ($places as &$p) {
            $cuisineMatch = (!empty($cuisinePreference) && !empty($p['cuisine_type']))
                ? (stripos($p['cuisine_type'], $cuisinePreference) !== false ? 1.0 : 0.0)
                : 0.5; // neutral if no preference

            $p['score'] = $this->score(
                (float)$p['distance_km'],
                (float)$p['avg_price'],
                $cuisineMatch,
                $maxDist,
                $maxPrice
            );
        }
        unset($p);

        // ---- 4. Sort by score descending ----
        usort($places, fn($a, $b) => $b['score'] <=> $a['score']);

        // ---- 5. Return top N ----
        return array_slice($places, 0, $topN);
    }

    /**
     * Recommend food places by state (fallback).
     *
     * @param string $state   Malaysian state name
     * @param float  $budget  Max avg_price
     * @param int    $topN    Max results
     * @return array
     */
    public function recommendByState(string $state, float $budget = 0.0, int $topN = 5): array
    {
        $sql = "SELECT food_id, name, state, district, latitude, longitude,
                       cuisine_type, avg_price, rating, opening_hour, is_active
                FROM food_places
                WHERE is_active = 1 AND state = ?";

        $params = [$state];
        $types  = 's';

        if ($budget > 0) {
            $sql    .= " AND avg_price <= ?";
            $params[] = $budget;
            $types   .= 'd';
        }

        $sql .= " ORDER BY rating DESC, avg_price ASC LIMIT ?";
        $params[] = $topN;
        $types   .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res    = $stmt->get_result();
        $places = [];
        while ($row = $res->fetch_assoc()) $places[] = $row;
        $stmt->close();

        return $places;
    }

    // =========================================================
    // PRIVATE: Database fetch
    // =========================================================

    private function fetchFoodPlaces(float $lat, float $lng, float $radiusKm, float $budget): array
    {
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / (111.0 * cos(deg2rad($lat)));

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        $sql = "SELECT food_id, name, state, district, latitude, longitude,
                       cuisine_type, avg_price, rating, opening_hour, is_active
                FROM food_places
                WHERE is_active = 1
                  AND latitude  BETWEEN ? AND ?
                  AND longitude BETWEEN ? AND ?";

        $params = [$minLat, $maxLat, $minLng, $maxLng];
        $types  = 'dddd';

        if ($budget > 0) {
            $sql    .= " AND avg_price <= ?";
            $params[] = $budget;
            $types   .= 'd';
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res    = $stmt->get_result();
        $places = [];

        while ($row = $res->fetch_assoc()) {
            $dist = $this->haversineKm($lat, $lng, (float)$row['latitude'], (float)$row['longitude']);
            if ($dist <= $radiusKm) {
                $row['distance_km'] = round($dist, 2);
                $places[] = $row;
            }
        }
        $stmt->close();

        return $places;
    }

    // =========================================================
    // PRIVATE: Open-now filter
    // =========================================================

    /**
     * Filter food places that are open at the given time.
     * Expects opening_hour format: "HH:MM - HH:MM" or "08:00 - 22:00"
     */
    private function filterOpenNow(array $places, string $currentTime): array
    {
        $now = strtotime($currentTime);
        if ($now === false) return $places; // can't parse time, skip filter

        $open = [];
        foreach ($places as $p) {
            $oh = trim((string)($p['opening_hour'] ?? ''));
            if ($oh === '' || $oh === '-') {
                $open[] = $p; // no hours info = assume open
                continue;
            }

            // Parse "HH:MM - HH:MM" or "HH:MM-HH:MM"
            if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $oh, $m)) {
                $openTime  = strtotime($m[1]);
                $closeTime = strtotime($m[2]);

                if ($openTime !== false && $closeTime !== false) {
                    // Handle overnight (e.g., 22:00 - 02:00)
                    if ($closeTime < $openTime) {
                        if ($now >= $openTime || $now <= $closeTime) {
                            $open[] = $p;
                        }
                    } else {
                        if ($now >= $openTime && $now <= $closeTime) {
                            $open[] = $p;
                        }
                    }
                    continue;
                }
            }

            // Can't parse hours — include anyway
            $open[] = $p;
        }

        return $open;
    }

    // =========================================================
    // PRIVATE: Scoring
    // =========================================================

    private function score(
        float $distKm,
        float $price,
        float $cuisineMatch,
        float $maxDist,
        float $maxPrice
    ): float {
        $distScore  = ($maxDist  > 0) ? (1 - $distKm / $maxDist)  : 1.0;
        $priceScore = ($maxPrice > 0) ? (1 - $price  / $maxPrice)  : 1.0;

        return self::WEIGHT_DISTANCE * $distScore
             + self::WEIGHT_PRICE    * $priceScore
             + self::WEIGHT_CUISINE  * $cuisineMatch;
    }

    // =========================================================
    // PRIVATE: Haversine helper
    // =========================================================

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
