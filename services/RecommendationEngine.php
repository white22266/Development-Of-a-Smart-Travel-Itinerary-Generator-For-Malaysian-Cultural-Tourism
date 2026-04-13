<?php
/**
 * services/RecommendationEngine.php
 *
 * RecommendationEngine — Core OOP service for cultural place filtering, scoring,
 * and itinerary building logic.
 *
 * This class implements the hybrid rule-based recommendation logic described in
 * the system requirements:
 *
 *   Step 1: Filter places (state, district, category, is_active)
 *   Step 2: Score places (category match, cultural relevance, distance, budget)
 *   Step 3: Sort by score
 *   Step 4: Build itinerary day by day with constraints
 *
 * Usage:
 *   $engine = new RecommendationEngine($conn, $preferences);
 *   $itinerary = $engine->generate();
 *
 * @author  Smart Travel Itinerary Generator
 */

class RecommendationEngine
{
    /** @var mysqli */
    private $conn;

    /** @var array Traveller preferences */
    private array $prefs;

    // ---- Scoring weights ----
    private const W_CATEGORY   = 0.35;
    private const W_CULTURAL   = 0.25;
    private const W_DISTANCE   = 0.25;
    private const W_BUDGET     = 0.15;

    /** Cultural relevance score per category (higher = more culturally significant) */
    private const CULTURAL_SCORE = [
        'heritage'  => 1.0,
        'museum'    => 0.9,
        'culture'   => 0.9,
        'festival'  => 0.8,
        'food'      => 0.7,
        'nature'    => 0.5,
        'shopping'  => 0.3,
    ];

    /** Max items per day limit */
    private const MAX_ITEMS_PER_DAY = 5;

    /** Max travel distance per day by transport type (km) */
    private const MAX_DAY_KM = [
        'car'              => 45.0,
        'motorcycle'       => 40.0,
        'public_transport' => 25.0,
        'walking'          => 5.0,
    ];

    public function __construct($conn, array $preferences)
    {
        $this->conn  = $conn;
        $this->prefs = $preferences;
    }

    // =========================================================
    // PUBLIC: Main generation entry point
    // =========================================================

    /**
     * Generate a full itinerary.
     *
     * @return array{
     *   days: array,
     *   total_cost: float,
     *   total_places: int,
     *   warnings: array
     * }
     */
    public function generate(): array
    {
        $tripDays    = (int)($this->prefs['trip_days']    ?? 1);
        $itemsPerDay = (int)($this->prefs['items_per_day'] ?? 3);
        $itemsPerDay = max(1, min($itemsPerDay, self::MAX_ITEMS_PER_DAY));

        // ---- Step 1: Filter places ----
        $places = $this->filterPlaces();

        if (empty($places)) {
            return [
                'days'          => [],
                'total_cost'    => 0.0,
                'total_places'  => 0,
                'warnings'      => ['No cultural places matched your preferences.'],
            ];
        }

        // ---- Step 2: Score places ----
        $places = $this->scorePlaces($places);

        // ---- Step 3: Sort by score descending ----
        usort($places, fn($a, $b) => $b['_score'] <=> $a['_score']);

        // ---- Step 4: Build itinerary ----
        return $this->buildItinerary($places, $tripDays, $itemsPerDay);
    }

    // =========================================================
    // STEP 1: Filter places
    // =========================================================

    /**
     * Filter cultural places from the database based on preferences.
     */
    private function filterPlaces(): array
    {
        $categories = $this->getCategories();
        $states     = $this->getStates();

        $where  = "is_active = 1";
        $params = [];
        $types  = "";

        // Category filter
        if (!empty($categories)) {
            $ph     = implode(',', array_fill(0, count($categories), '?'));
            $where .= " AND category IN ($ph)";
            $types .= str_repeat('s', count($categories));
            $params = array_merge($params, $categories);
        }

        // State filter
        if (!empty($states)) {
            $ph     = implode(',', array_fill(0, count($states), '?'));
            $where .= " AND state IN ($ph)";
            $types .= str_repeat('s', count($states));
            $params = array_merge($params, $states);
        }

        $sql  = "SELECT place_id, state, category, name, description, address,
                        latitude, longitude, opening_hours, estimated_cost
                 FROM cultural_places
                 WHERE $where
                 ORDER BY estimated_cost ASC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        if (!empty($params)) {
            $bind = [$types];
            for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }

        $stmt->execute();
        $res    = $stmt->get_result();
        $places = [];
        while ($row = $res->fetch_assoc()) $places[] = $row;
        $stmt->close();

        return $places;
    }

    // =========================================================
    // STEP 2: Score places
    // =========================================================

    /**
     * Assign a composite score to each place.
     */
    private function scorePlaces(array $places): array
    {
        $budget         = (float)($this->prefs['budget'] ?? 0);
        $originLat      = (float)($this->prefs['origin_lat'] ?? 0);
        $originLng      = (float)($this->prefs['origin_lng'] ?? 0);
        $preferredCats  = $this->getCategories();
        $hasOrigin      = $this->validCoord($originLat, $originLng);

        // Get max values for normalization
        $maxCost = max(array_column($places, 'estimated_cost') ?: [1]);
        $maxDist = 1.0;

        if ($hasOrigin) {
            $dists = [];
            foreach ($places as $p) {
                if ($this->validCoord((float)$p['latitude'], (float)$p['longitude'])) {
                    $dists[] = $this->haversineKm($originLat, $originLng, (float)$p['latitude'], (float)$p['longitude']);
                }
            }
            if (!empty($dists)) $maxDist = max($dists);
        }

        foreach ($places as &$p) {
            $cat = strtolower((string)($p['category'] ?? ''));

            // Category match score
            $catScore = in_array($cat, $preferredCats, true) ? 1.0 : 0.3;

            // Cultural relevance score
            $culturalScore = self::CULTURAL_SCORE[$cat] ?? 0.3;

            // Distance score (closer = better)
            $distScore = 1.0;
            if ($hasOrigin && $this->validCoord((float)$p['latitude'], (float)$p['longitude'])) {
                $dist      = $this->haversineKm($originLat, $originLng, (float)$p['latitude'], (float)$p['longitude']);
                $distScore = ($maxDist > 0) ? (1 - $dist / $maxDist) : 1.0;
            }

            // Budget suitability score
            $cost        = (float)($p['estimated_cost'] ?? 0);
            $budgetScore = 1.0;
            if ($budget > 0 && $maxCost > 0) {
                // Places with cost well within budget score higher
                $budgetScore = ($cost <= $budget) ? (1 - $cost / $maxCost) : 0.0;
            }

            $p['_score'] = self::W_CATEGORY * $catScore
                         + self::W_CULTURAL * $culturalScore
                         + self::W_DISTANCE * $distScore
                         + self::W_BUDGET   * $budgetScore;
        }
        unset($p);

        return $places;
    }

    // =========================================================
    // STEP 4: Build itinerary
    // =========================================================

    /**
     * Build the day-by-day itinerary from scored places.
     *
     * Rules:
     *   - No place repeated across days
     *   - Avoid same category on consecutive days where possible
     *   - Respect daily distance limit
     *   - Start Day 1 from user origin (if provided)
     */
    private function buildItinerary(array $places, int $tripDays, int $itemsPerDay): array
    {
        $transport   = strtolower((string)($this->prefs['transport_type'] ?? 'car'));
        $maxDayKm    = self::MAX_DAY_KM[$transport] ?? 35.0;
        $originLat   = (float)($this->prefs['origin_lat'] ?? 0);
        $originLng   = (float)($this->prefs['origin_lng'] ?? 0);
        $hasOrigin   = $this->validCoord($originLat, $originLng);

        $usedIds       = [];
        $days          = [];
        $totalCost     = 0.0;
        $warnings      = [];
        $prevCategory  = null;

        // Group places by state for geographic coherence
        $byState = [];
        foreach ($places as $p) {
            $st = (string)($p['state'] ?? 'Unknown');
            $byState[$st][] = $p;
        }

        // Determine starting state
        $currentState = $this->pickBestState($byState);

        for ($day = 1; $day <= $tripDays; $day++) {
            $dayPlaces   = [];
            $dayDistKm   = 0.0;
            $prevLat     = $hasOrigin ? $originLat : null;
            $prevLng     = $hasOrigin ? $originLng : null;

            // Get candidates for this day (same state → neighbors → others)
            $candidates = $this->getDayCandidates($currentState, $byState, $usedIds);

            $attempts = 0;
            while (count($dayPlaces) < $itemsPerDay && !empty($candidates) && $attempts < 100) {
                $attempts++;
                $best    = null;
                $bestIdx = -1;

                foreach ($candidates as $idx => $p) {
                    $pid = (int)$p['place_id'];
                    if (isset($usedIds[$pid])) continue;

                    // Distance check
                    if ($prevLat !== null && $this->validCoord((float)$p['latitude'], (float)$p['longitude'])) {
                        $dist = $this->haversineKm($prevLat, $prevLng, (float)$p['latitude'], (float)$p['longitude']);
                        if ($dayDistKm + $dist > $maxDayKm) continue;
                    }

                    // Category diversity: avoid same category as previous item
                    $cat = strtolower((string)($p['category'] ?? ''));
                    if ($prevCategory !== null && $cat === $prevCategory && count($candidates) > 3) continue;

                    if ($best === null || $p['_score'] > $best['_score']) {
                        $best    = $p;
                        $bestIdx = $idx;
                    }
                }

                if ($best === null) {
                    // Relax constraints: pick any unused candidate
                    foreach ($candidates as $idx => $p) {
                        $pid = (int)$p['place_id'];
                        if (!isset($usedIds[$pid])) {
                            $best    = $p;
                            $bestIdx = $idx;
                            break;
                        }
                    }
                }

                if ($best === null) break;

                // Add to day
                $pid = (int)$best['place_id'];
                $usedIds[$pid] = true;
                unset($candidates[$bestIdx]);

                // Update distance tracking
                if ($prevLat !== null && $this->validCoord((float)$best['latitude'], (float)$best['longitude'])) {
                    $dist       = $this->haversineKm($prevLat, $prevLng, (float)$best['latitude'], (float)$best['longitude']);
                    $dayDistKm += $dist;
                }

                $prevLat      = (float)$best['latitude'];
                $prevLng      = (float)$best['longitude'];
                $prevCategory = strtolower((string)($best['category'] ?? ''));

                $dayPlaces[]  = $best;
                $totalCost   += (float)($best['estimated_cost'] ?? 0);
            }

            if (empty($dayPlaces)) {
                $warnings[] = "Day $day: No suitable places found.";
            }

            $days[$day] = $dayPlaces;

            // Remove used places from byState pools
            foreach ($byState as $st => &$pool) {
                $pool = array_values(array_filter($pool, fn($p) => !isset($usedIds[(int)$p['place_id']])));
            }
            unset($pool);

            // Move to best remaining state
            $currentState = $this->pickBestState($byState) ?? $currentState;
        }

        return [
            'days'         => $days,
            'total_cost'   => round($totalCost, 2),
            'total_places' => count($usedIds),
            'warnings'     => $warnings,
        ];
    }

    // =========================================================
    // PRIVATE: Helpers
    // =========================================================

    private function getDayCandidates(string $currentState, array $byState, array $usedIds): array
    {
        $order = [$currentState];

        // Add neighbors
        $neighbors = $this->stateNeighbors();
        $canon     = $this->canonicalState($currentState);
        foreach ($neighbors[$canon] ?? [] as $nb) {
            foreach (array_keys($byState) as $k) {
                if ($this->canonicalState($k) === $nb && !in_array($k, $order, true)) {
                    $order[] = $k;
                }
            }
        }

        // Add remaining states
        foreach (array_keys($byState) as $k) {
            if (!in_array($k, $order, true)) $order[] = $k;
        }

        $candidates = [];
        foreach ($order as $st) {
            foreach ($byState[$st] ?? [] as $p) {
                $pid = (int)$p['place_id'];
                if (!isset($usedIds[$pid])) $candidates[] = $p;
            }
            if (count($candidates) >= 20) break; // enough candidates
        }

        return $candidates;
    }

    private function pickBestState(array $byState): ?string
    {
        $best = null;
        $bestCount = -1;
        foreach ($byState as $st => $pool) {
            $c = count($pool);
            if ($c > $bestCount) {
                $bestCount = $c;
                $best      = $st;
            }
        }
        return $best;
    }

    private function getCategories(): array
    {
        $allowed = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];
        $raw     = trim((string)($this->prefs['interests'] ?? ''));
        if ($raw === '') return $allowed;

        $cats = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($c) => in_array($c, $allowed, true)
        ));

        return empty($cats) ? $allowed : $cats;
    }

    private function getStates(): array
    {
        $raw = trim((string)($this->prefs['preferred_states'] ?? ''));
        if ($raw === '') return [];

        $states = array_values(array_filter(array_map('trim', explode(',', $raw))));

        // If "Malaysia" is in list, treat as no filter
        foreach ($states as $s) {
            if (strtolower($s) === 'malaysia') return [];
        }

        return $states;
    }

    private function canonicalState(string $state): string
    {
        $s = strtolower(trim($state));
        if ($s === 'pulau pinang') return 'penang';
        if ($s === 'malacca')      return 'melaka';
        return $s;
    }

    private function stateNeighbors(): array
    {
        return [
            'perlis'          => ['kedah'],
            'kedah'           => ['perlis', 'penang', 'perak'],
            'penang'          => ['kedah', 'perak'],
            'perak'           => ['kedah', 'penang', 'kelantan', 'pahang', 'selangor'],
            'selangor'        => ['perak', 'pahang', 'negeri sembilan', 'kuala lumpur', 'putrajaya'],
            'kuala lumpur'    => ['selangor'],
            'putrajaya'       => ['selangor'],
            'negeri sembilan' => ['selangor', 'melaka', 'pahang', 'johor'],
            'melaka'          => ['negeri sembilan', 'johor'],
            'johor'           => ['melaka', 'negeri sembilan', 'pahang'],
            'pahang'          => ['perak', 'selangor', 'negeri sembilan', 'terengganu', 'kelantan', 'johor'],
            'terengganu'      => ['pahang', 'kelantan'],
            'kelantan'        => ['terengganu', 'pahang', 'perak'],
            'sabah'           => ['sarawak', 'labuan'],
            'sarawak'         => ['sabah'],
            'labuan'          => ['sabah'],
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function validCoord(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng)
            && !($lat === 0.0 && $lng === 0.0)
            && $lat >= -90  && $lat <= 90
            && $lng >= -180 && $lng <= 180;
    }
}
