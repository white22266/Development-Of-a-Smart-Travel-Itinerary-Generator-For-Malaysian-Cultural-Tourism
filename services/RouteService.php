<?php
/**
 * services/RouteService.php
 *
 * RouteService — Calculates route segments between itinerary places.
 *
 * Supports two modes:
 *   1. Haversine formula  — fast, offline straight-line distance estimation
 *   2. Google Maps API    — real road distance & travel time (requires API key)
 *
 * Usage:
 *   $rs = new RouteService('car', GOOGLE_MAPS_API_KEY);
 *   $segment = $rs->getSegment($lat1, $lng1, $lat2, $lng2);
 *   // Returns: ['distance_km' => float, 'travel_time_min' => int]
 *
 *   $route = $rs->buildRoute($places);
 *   // Returns: array of segments for each consecutive pair
 */

class RouteService
{
    /** @var string Transport type: car | public_transport | walking | motorcycle */
    private string $transportType;

    /** @var string|null Google Maps API key (null = Haversine only) */
    private ?string $apiKey;

    /** Average speeds in km/h per transport mode */
    private const SPEED_MAP = [
        'car'              => 60,
        'motorcycle'       => 55,
        'public_transport' => 30,
        'walking'          => 5,
    ];

    /** Google Maps travel mode mapping */
    private const GOOGLE_MODE_MAP = [
        'car'              => 'driving',
        'motorcycle'       => 'driving',
        'public_transport' => 'transit',
        'walking'          => 'walking',
    ];

    public function __construct(string $transportType = 'car', ?string $apiKey = null)
    {
        $this->transportType = self::normalizeTransportType($transportType);
        $this->apiKey        = $apiKey;
    }

    public static function normalizeTransportType(string $transportType): string
    {
        $t = strtolower(trim(str_replace('-', '_', $transportType)));
        $t = preg_replace('/\s+/', '_', $t) ?? $t;

        return match ($t) {
            'public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train' => 'public_transport',
            'walk' => 'walking',
            'drive', 'driving' => 'car',
            'motorbike', 'bike' => 'motorcycle',
            default => array_key_exists($t, self::SPEED_MAP) ? $t : 'car',
        };
    }

    // =========================================================
    // PUBLIC: Get a single segment distance + time
    // =========================================================

    /**
     * Get route segment between two coordinates.
     *
     * @param float $lat1  Origin latitude
     * @param float $lng1  Origin longitude
     * @param float $lat2  Destination latitude
     * @param float $lng2  Destination longitude
     * @return array{distance_km: float, travel_time_min: int}
     */
    public function getSegment(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        // Try Google Maps first if API key is available
        if (!empty($this->apiKey)) {
            $result = $this->googleMapsSegment($lat1, $lng1, $lat2, $lng2);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: Haversine formula
        return $this->haversineSegment($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Build a full route for an ordered list of places.
     *
     * @param array $places  Each element must have 'latitude', 'longitude', 'name'
     * @return array  Array of segments: [['from'=>name,'to'=>name,'distance_km'=>float,'travel_time_min'=>int], ...]
     */
    public function buildRoute(array $places): array
    {
        $segments = [];
        $n = count($places);

        for ($i = 0; $i < $n - 1; $i++) {
            $from = $places[$i];
            $to   = $places[$i + 1];

            $lat1 = (float)($from['latitude']  ?? 0);
            $lng1 = (float)($from['longitude'] ?? 0);
            $lat2 = (float)($to['latitude']    ?? 0);
            $lng2 = (float)($to['longitude']   ?? 0);

            if (!$this->validCoord($lat1, $lng1) || !$this->validCoord($lat2, $lng2)) {
                $segments[] = [
                    'from'             => $from['name'] ?? "Place " . ($i + 1),
                    'to'               => $to['name']   ?? "Place " . ($i + 2),
                    'distance_km'      => 0.0,
                    'travel_time_min'  => 0,
                    'method'           => 'unavailable',
                ];
                continue;
            }

            $seg = $this->getSegment($lat1, $lng1, $lat2, $lng2);
            $segments[] = array_merge([
                'from'   => $from['name'] ?? "Place " . ($i + 1),
                'to'     => $to['name']   ?? "Place " . ($i + 2),
            ], $seg);
        }

        return $segments;
    }

    /**
     * Calculate total distance and time for a route.
     *
     * @param array $places  Ordered list of places with lat/lng
     * @return array{total_distance_km: float, total_time_min: int, segments: array}
     */
    public function getTotalRoute(array $places): array
    {
        $segments     = $this->buildRoute($places);
        $totalDist    = 0.0;
        $totalTime    = 0;

        foreach ($segments as $seg) {
            $totalDist += (float)($seg['distance_km']     ?? 0);
            $totalTime += (int)($seg['travel_time_min']   ?? 0);
        }

        return [
            'total_distance_km' => round($totalDist, 2),
            'total_time_min'    => $totalTime,
            'segments'          => $segments,
        ];
    }

    // =========================================================
    // PRIVATE: Haversine formula
    // =========================================================

    /**
     * Haversine great-circle distance between two coordinates.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371.0; // Earth radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
           * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }

    /**
     * Build a segment result using Haversine distance.
     *
     * @return array{distance_km: float, travel_time_min: int, method: string}
     */
    private function haversineSegment(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $distKm  = $this->haversineKm($lat1, $lng1, $lat2, $lng2);

        // Apply road factor: straight-line × 1.3 approximates road distance
        $roadKm  = $distKm * 1.3;

        $speedKmh = self::SPEED_MAP[$this->transportType] ?? 60;
        $timeMin  = ($speedKmh > 0) ? (int)ceil(($roadKm / $speedKmh) * 60) : 0;

        return [
            'distance_km'     => round($roadKm, 2),
            'travel_time_min' => $timeMin,
            'method'          => 'haversine',
        ];
    }

    // =========================================================
    // PRIVATE: Google Maps Distance Matrix API
    // =========================================================

    /**
     * Query Google Maps Distance Matrix API for a single segment.
     *
     * @return array|null  Returns segment array or null on failure
     */
    private function googleMapsSegment(float $lat1, float $lng1, float $lat2, float $lng2): ?array
    {
        $mode    = self::GOOGLE_MODE_MAP[$this->transportType] ?? 'driving';
        $origins = "{$lat1},{$lng1}";
        $dests   = "{$lat2},{$lng2}";

        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?"
             . http_build_query([
                 'origins'      => $origins,
                 'destinations' => $dests,
                 'mode'         => $mode,
                 'key'          => $this->apiKey,
             ]);

        $json = $this->httpGet($url);
        if ($json === null) return null;

        $data = json_decode($json, true);
        if (!is_array($data)) return null;

        $status = $data['status'] ?? '';
        if ($status !== 'OK') return null;

        $element = $data['rows'][0]['elements'][0] ?? null;
        if (!is_array($element)) return null;

        $elemStatus = $element['status'] ?? '';
        if ($elemStatus !== 'OK') return null;

        $distM   = (int)($element['distance']['value'] ?? 0);
        $durS    = (int)($element['duration']['value'] ?? 0);

        $distKm  = round($distM / 1000.0, 2);
        $timeMin = (int)ceil($durS / 60.0);

        return [
            'distance_km'     => $distKm,
            'travel_time_min' => $timeMin,
            'method'          => 'google_maps',
        ];
    }

    // =========================================================
    // PRIVATE: HTTP helper
    // =========================================================

    /**
     * Simple HTTP GET using cURL or file_get_contents.
     */
    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $err  = curl_errno($ch);
            curl_close($ch);
            if ($err || $body === false) return null;
            return $body;
        }

        // Fallback: file_get_contents
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false) ? $body : null;
    }

    // =========================================================
    // PRIVATE: Coordinate validation
    // =========================================================

    private function validCoord(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng)
            && !($lat === 0.0 && $lng === 0.0)
            && $lat >= -90  && $lat <= 90
            && $lng >= -180 && $lng <= 180;
    }
}
