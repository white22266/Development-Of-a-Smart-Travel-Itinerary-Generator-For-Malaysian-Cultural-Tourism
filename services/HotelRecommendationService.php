<?php
// services/HotelRecommendationService.php
// Places to stay near a point, read from the lodgings table. That table is filled from
// OpenStreetMap by tools/sync_places.php, so nothing outside this server is called while a
// traveller waits.
//
// OpenStreetMap knows where a hotel is and what kind of place it is, but not what a night
// costs. The prices here are planning estimates, worked out from the kind of lodging, its
// star class, its name and where it is. Show them as estimates, never as offers.

class HotelRecommendationService
{
    private const DEFAULT_RADIUS_KM = 10.0;
    private const DEFAULT_TOP_N = 6;
    /** When too few places are found nearby, the search circle grows up to this size. */
    private const MAX_RADIUS_KM = 60.0;

    /** kind => [label, typical price of a room per night in RM, quality from 0 to 1 when no star class is known] */
    private const KINDS = [
        'hotel'       => ['Hotel', 150.0, 0.70],
        'resort'      => ['Resort', 320.0, 0.80],
        'apartment'   => ['Serviced apartment', 170.0, 0.65],
        'chalet'      => ['Chalet', 120.0, 0.55],
        'guest_house' => ['Guest house', 85.0, 0.55],
        'motel'       => ['Motel', 80.0, 0.45],
        'hostel'      => ['Hostel', 45.0, 0.40],
    ];

    /** Typical price of a hotel room per night by star class. */
    private const STAR_PRICE = [1 => 70.0, 2 => 100.0, 3 => 160.0, 4 => 280.0, 5 => 480.0];

    /** Hotels without a star class on the map: well-known names say a lot about the price. */
    private const LUXURY_NAMES = '/\b(ritz|four seasons|mandarin oriental|st\.? regis|shangri|grand hyatt|banyan tree|the datai|eastern (&|and) oriental|majestic|w kuala lumpur|one&only|rosewood|park hyatt)\b/i';
    private const UPSCALE_NAMES = '/\b(hilton|marriott|hyatt|sheraton|westin|renaissance|intercontinental|pullman|sofitel|le meridien|doubletree|traders|parkroyal|pan pacific|hard rock|equatorial|concorde|royale chulan|amari|novotel|mercure|courtyard|four points|holiday inn|ramada|dorsett|hatten|avani|ascott|somerset|impiana|berjaya|thistle|lexis|sama-sama|sunway|swiss-garden|g hotel|pangkor laut|casa del)\b/i';
    private const BUDGET_NAMES = '/\b(budget|tune|oyo|hotel 99|spot on|capsule|pod|backpackers?|rumah tumpangan|homestay|inn|lodge|rest house)\b/i';

    /** Rooms cost more in the big cities and on the resort islands. */
    private const STATE_FACTOR = ['Kuala Lumpur' => 1.25, 'Putrajaya' => 1.15, 'Penang' => 1.12, 'Selangor' => 1.05, 'Sabah' => 1.05];
    private const RESORT_AREA = '/\b(langkawi|pulau|island|tioman|redang|perhentian|pangkor|sipadan|mabul|genting|cameron)\b/i';

    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Lodging near a coordinate, best first.
     *
     * @param float $budget   what the traveller can spend on a room per night (0 = unknown). It only
     *                        affects the order, never the price shown.
     * @param float $radiusKm where to look first; the circle grows when too little is found
     * @return array<int,array> see describe() for the fields of each entry
     */
    public function recommend(float $lat, float $lng, float $budget = 0.0, float $radiusKm = 0.0, int $topN = 0): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) return [];
        $radiusKm = $radiusKm > 0 ? $radiusKm : self::DEFAULT_RADIUS_KM;
        $topN = $topN > 0 ? $topN : self::DEFAULT_TOP_N;

        try {
            for ($radius = $radiusKm; ; $radius = min(self::MAX_RADIUS_KM, $radius * 2)) {
                $latSpan = $radius / 111.0;
                $lngSpan = $radius / (111.0 * max(0.2, cos(deg2rad($lat))));
                $south = $lat - $latSpan;
                $north = $lat + $latSpan;
                $west = $lng - $lngSpan;
                $east = $lng + $lngSpan;
                $stmt = $this->conn->prepare("
                    SELECT * FROM lodgings
                    WHERE is_active = 1 AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
                ");
                $stmt->bind_param('dddd', $south, $north, $west, $east);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $hotels = [];
                foreach ($rows as $row) {
                    $km = self::haversineKm($lat, $lng, (float)$row['latitude'], (float)$row['longitude']);
                    if ($km <= $radius) $hotels[] = $this->describe($row, $budget, $km, $radius);
                }
                if (count($hotels) >= $topN || $radius >= self::MAX_RADIUS_KM) break;
            }
        } catch (mysqli_sql_exception $e) {
            error_log('HotelRecommendationService: ' . $e->getMessage());   // the lodgings table is missing until the first sync
            return [];
        }

        usort($hotels, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($hotels, 0, $topN);
    }

    /** Lodging anywhere in a state, for when the itinerary has no usable coordinates. */
    public function recommendByState(string $state, float $budget = 0.0, int $topN = 5): array
    {
        $state = trim($state);
        if ($state === '') return [];
        $topN = $topN > 0 ? $topN : self::DEFAULT_TOP_N;

        try {
            $stmt = $this->conn->prepare("SELECT * FROM lodgings WHERE is_active = 1 AND state = ?");
            $stmt->bind_param('s', $state);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            error_log('HotelRecommendationService: ' . $e->getMessage());
            return [];
        }

        $hotels = array_map(fn(array $row) => $this->describe($row, $budget, null, 0.0), $rows);
        usort($hotels, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($hotels, 0, $topN);
    }

    /** One lodging by its id (the hotel_id of an entry returned earlier), or null. */
    public function find(int $lodgingId, float $budget = 0.0): ?array
    {
        if ($lodgingId <= 0) return null;
        try {
            $stmt = $this->conn->prepare("SELECT * FROM lodgings WHERE lodging_id = ? AND is_active = 1");
            $stmt->bind_param('i', $lodgingId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            error_log('HotelRecommendationService: ' . $e->getMessage());
            return null;
        }
        return $row ? $this->describe($row, $budget, null, 0.0) : null;
    }

    /**
     * What the rest of the app knows about a lodging.
     *
     * @return array{hotel_id:int,name:string,kind:string,kind_label:string,stars:?float,state:string,address:string,
     *               latitude:float,longitude:float,price_per_night:float,price_source:string,distance_km:?float,
     *               map_url:string,website:?string,phone:?string,source:string,score:float}
     */
    private function describe(array $row, float $budget, ?float $distanceKm, float $radiusKm): array
    {
        $kind = isset(self::KINDS[$row['kind']]) ? (string)$row['kind'] : 'hotel';
        $stars = $row['stars'] !== null ? (float)$row['stars'] : null;
        $price = $this->estimateNightlyRate($row, $kind, $stars);
        $lat = (float)$row['latitude'];
        $lng = (float)$row['longitude'];

        $distanceScore = ($distanceKm !== null && $radiusKm > 0) ? max(0.0, 1 - $distanceKm / $radiusKm) : 0.5;
        $priceScore = self::budgetFit($price, $budget);
        $quality = $stars !== null ? min(1.0, $stars / 5) : self::KINDS[$kind][2];
        // A website, a phone number and an address on the map point to an established business.
        $known = (!empty($row['website']) ? 0.5 : 0.0) + (!empty($row['phone']) ? 0.25 : 0.0) + (!empty($row['address']) ? 0.25 : 0.0);

        return [
            'hotel_id' => (int)$row['lodging_id'],
            'name' => (string)$row['name'],
            'kind' => $kind,
            'kind_label' => self::KINDS[$kind][0],
            'stars' => $stars,
            'state' => (string)$row['state'],
            'address' => (string)($row['address'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lng,
            'price_per_night' => $price,
            'price_source' => 'planning_estimate',
            'distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
            'map_url' => 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng,
            'website' => !empty($row['website']) ? (string)$row['website'] : null,
            'phone' => !empty($row['phone']) ? (string)$row['phone'] : null,
            'source' => 'openstreetmap',
            'score' => round(0.45 * $distanceScore + 0.25 * $priceScore + 0.20 * $quality + 0.10 * $known, 4),
        ];
    }

    /**
     * How well a price suits a nightly budget, from 0 to 1. A room that uses most of the budget
     * suits it best; a much cheaper one is a step down in comfort, and one above the budget
     * quickly stops being an option.
     */
    private static function budgetFit(float $price, float $budget): float
    {
        if ($budget <= 0) return 0.6;
        $share = $price / $budget;
        if ($share <= 1.0) return 0.6 + 0.4 * min(1.0, $share / 0.6);
        return max(0.0, 1.0 - ($share - 1.0) * 2.5);
    }

    /** A planning estimate of a room per night, the same for a place every time it is shown. */
    private function estimateNightlyRate(array $row, string $kind, ?float $stars): float
    {
        $name = (string)$row['name'];
        $base = self::KINDS[$kind][1];
        $starClass = $stars !== null ? (int)round($stars) : 0;

        if ($kind === 'hotel' || $kind === 'resort') {
            if (isset(self::STAR_PRICE[$starClass])) {
                $base = self::STAR_PRICE[$starClass] * ($kind === 'resort' ? 1.25 : 1.0);
            } elseif (preg_match(self::LUXURY_NAMES, $name)) {
                $base = self::STAR_PRICE[5];
            } elseif (preg_match(self::UPSCALE_NAMES, $name)) {
                $base = self::STAR_PRICE[4];
            } elseif ($kind === 'hotel' && preg_match(self::BUDGET_NAMES, $name)) {
                $base = 85.0;
            }
        }

        $where = self::STATE_FACTOR[(string)$row['state']] ?? 1.0;
        if ($where < 1.15 && preg_match(self::RESORT_AREA, $name . ' ' . ($row['address'] ?? ''))) $where = 1.18;
        $variation = 0.90 + (crc32((string)$row['osm_ref']) % 21) / 100;   // 0.90 to 1.10

        return round(max(35.0, $base * $where * $variation) / 5) * 5;
    }

    /**
     * What one room may cost per night: 30% of the trip budget goes to lodging, shared over
     * the nights and the rooms the party needs. 0 when the budget is unknown.
     */
    public static function nightlyRoomBudget(float $tripBudget, int $totalDays, int $rooms = 1): float
    {
        if ($tripBudget <= 0) return 0.0;
        return ($tripBudget * 0.30) / max(1, $totalDays - 1) / max(1, $rooms);
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
