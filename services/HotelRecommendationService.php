<?php
/**
 * services/HotelRecommendationService.php
 *
 * HotelRecommendationService — Recommends nearby hotels based on location, budget, and rating.
 *
 * Steps:
 *   1. Query hotels table for nearby hotels (within radius)
 *   2. Filter by budget and minimum rating
 *   3. Score each hotel (distance + price + rating)
 *   4. Sort by score descending
 *   5. Return top N results
 *
 * Usage:
 *   $hrs = new HotelRecommendationService($conn);
 *   $hotels = $hrs->recommend($lat, $lng, $budget, $radiusKm, $topN);
 *
 * Requires: hotels table in database (see SQL migration below)
 */

class HotelRecommendationService
{
    /** @var mysqli Database connection */
    private $conn;

    /** Minimum rating threshold (out of 5) */
    private const MIN_RATING = 3.0;

    /** Default search radius in km */
    private const DEFAULT_RADIUS_KM = 20.0;

    /** Default number of results */
    private const DEFAULT_TOP_N = 5;

    /** Scoring weights */
    private const WEIGHT_DISTANCE = 0.40;
    private const WEIGHT_PRICE    = 0.30;
    private const WEIGHT_RATING   = 0.30;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // =========================================================
    // PUBLIC: Main recommendation method
    // =========================================================

    /**
     * Recommend nearby hotels.
     *
     * @param float  $lat        Reference latitude (e.g., last itinerary place)
     * @param float  $lng        Reference longitude
     * @param float  $budget     Max price per night (0 = no limit)
     * @param float  $radiusKm   Search radius in km
     * @param int    $topN       Maximum results to return
     * @return array  Array of hotel records with added 'distance_km' and 'score'
     */
    public function recommend(
        float $lat,
        float $lng,
        float $budget    = 0.0,
        float $radiusKm  = 0.0,
        int   $topN      = 0
    ): array {
        if ($radiusKm <= 0) $radiusKm = self::DEFAULT_RADIUS_KM;
        if ($topN <= 0)     $topN     = self::DEFAULT_TOP_N;

        // ---- 1. Fetch hotels from database ----
        $hotels = $this->fetchHotels($lat, $lng, $radiusKm, $budget);

        if (empty($hotels)) {
            // Fallback: try wider radius
            $hotels = $this->fetchHotels($lat, $lng, $radiusKm * 2, $budget);
        }

        $hotels = $this->dedupeHotels($hotels);
        if (empty($hotels)) return [];

        // ---- 2. Score each hotel ----
        $maxDist   = max(array_column($hotels, 'distance_km'));
        $maxPrice  = max(array_column($hotels, 'price_per_night'));
        $maxRating = 5.0;

        foreach ($hotels as &$h) {
            $h['score'] = $this->score(
                (float)$h['distance_km'],
                (float)$h['price_per_night'],
                (float)$h['rating'],
                $maxDist,
                $maxPrice,
                $maxRating
            );
        }
        unset($h);

        // ---- 3. Sort by score descending ----
        usort($hotels, fn($a, $b) => $b['score'] <=> $a['score']);

        // ---- 4. Return top N ----
        return array_slice($hotels, 0, $topN);
    }

    /**
     * Recommend hotels for a specific state (fallback when no nearby hotels found).
     *
     * @param string $state   Malaysian state name
     * @param float  $budget  Max price per night
     * @param int    $topN    Max results
     * @return array
     */
    public function recommendByState(string $state, float $budget = 0.0, int $topN = 5): array
    {
        $sql = "SELECT hotel_id, name, state, district, latitude, longitude,
                       price_per_night, rating, is_active
                FROM hotels
                WHERE is_active = 1 AND state = ?";

        $params = [$state];
        $types  = 's';

        if ($budget > 0) {
            $sql    .= " AND price_per_night <= ?";
            $params[] = $budget;
            $types   .= 'd';
        }

        $sql .= " AND rating >= ? ORDER BY rating DESC, price_per_night ASC LIMIT ?";
        $params[] = self::MIN_RATING;
        $params[] = $topN;
        $types   .= 'di';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res    = $stmt->get_result();
        $hotels = [];
        while ($row = $res->fetch_assoc()) $hotels[] = $row;
        $stmt->close();

        return array_slice($this->dedupeHotels($hotels), 0, $topN);
    }

    // =========================================================
    // PRIVATE: Database fetch
    // =========================================================

    /**
     * Fetch hotels within radius using Haversine bounding box pre-filter.
     */
    private function fetchHotels(float $lat, float $lng, float $radiusKm, float $budget): array
    {
        // Bounding box for fast pre-filter
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / (111.0 * cos(deg2rad($lat)));

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        $sql = "SELECT hotel_id, name, state, district, latitude, longitude,
                       price_per_night, rating, is_active
                FROM hotels
                WHERE is_active = 1
                  AND latitude  BETWEEN ? AND ?
                  AND longitude BETWEEN ? AND ?
                  AND rating >= ?";

        $params = [$minLat, $maxLat, $minLng, $maxLng, self::MIN_RATING];
        $types  = 'ddddd';

        if ($budget > 0) {
            $sql    .= " AND price_per_night <= ?";
            $params[] = $budget;
            $types   .= 'd';
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res    = $stmt->get_result();
        $hotels = [];

        while ($row = $res->fetch_assoc()) {
            $dist = $this->haversineKm($lat, $lng, (float)$row['latitude'], (float)$row['longitude']);
            if ($dist <= $radiusKm) {
                $row['distance_km'] = round($dist, 2);
                $hotels[] = $row;
            }
        }
        $stmt->close();

        return $this->dedupeHotels($hotels);
    }

    /**
     * Keep one hotel per real-world location. This protects all UI screens even
     * if seed/import SQL accidentally inserts the same hotel twice.
     */
    private function dedupeHotels(array $hotels): array
    {
        $seen = [];
        $unique = [];

        foreach ($hotels as $hotel) {
            $key = strtolower(trim((string)($hotel['name'] ?? ''))) . '|'
                . strtolower(trim((string)($hotel['state'] ?? ''))) . '|'
                . strtolower(trim((string)($hotel['district'] ?? ''))) . '|'
                . number_format((float)($hotel['latitude'] ?? 0), 5, '.', '') . '|'
                . number_format((float)($hotel['longitude'] ?? 0), 5, '.', '');

            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $hotel;
        }

        return $unique;
    }

    // =========================================================
    // PRIVATE: Scoring
    // =========================================================

    /**
     * Score a hotel on a 0–1 scale.
     * Lower distance = better, lower price = better, higher rating = better.
     */
    private function score(
        float $distKm,
        float $price,
        float $rating,
        float $maxDist,
        float $maxPrice,
        float $maxRating
    ): float {
        $distScore   = ($maxDist  > 0) ? (1 - $distKm / $maxDist)   : 1.0;
        $priceScore  = ($maxPrice > 0) ? (1 - $price  / $maxPrice)   : 1.0;
        $ratingScore = ($maxRating > 0) ? ($rating / $maxRating)      : 0.0;

        return self::WEIGHT_DISTANCE * $distScore
             + self::WEIGHT_PRICE    * $priceScore
             + self::WEIGHT_RATING   * $ratingScore;
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
