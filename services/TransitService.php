<?php
/**
 * TransitService.php
 *
 * Calls Google Maps Directions API and parses the response into a
 * Moovit-style step-by-step transit itinerary for Malaysia.
 *
 * Supports: MRT, LRT, KTM, Monorail, ERL, Bus, Walk, Drive, Motorcycle
 *
 * Usage:
 *   $svc = new TransitService(GOOGLE_MAPS_API_KEY);
 *   $result = $svc->getRoute($originLat, $originLng, $destLat, $destLng, 'transit');
 */
class TransitService
{
    private string $apiKey;
    private int    $timeout = 12;

    // ---- Malaysian transit line classifier ----
    // Maps short_name / vehicle_type / line_name keywords → icon + colour + type label
    private const LINE_MAP = [
        // Klang Valley MRT
        'MRT Putrajaya'   => ['icon' => '🟣', 'color' => '#7B2D8B', 'type' => 'MRT'],
        'MRT Kajang'      => ['icon' => '🔵', 'color' => '#1E5CB3', 'type' => 'MRT'],
        'Putrajaya Line'  => ['icon' => '🟣', 'color' => '#7B2D8B', 'type' => 'MRT'],
        'Kajang Line'     => ['icon' => '🔵', 'color' => '#1E5CB3', 'type' => 'MRT'],
        // LRT
        'Ampang Line'     => ['icon' => '🟡', 'color' => '#F5A623', 'type' => 'LRT'],
        'Sri Petaling'    => ['icon' => '🟡', 'color' => '#F5A623', 'type' => 'LRT'],
        'Kelana Jaya'     => ['icon' => '🔴', 'color' => '#E8192C', 'type' => 'LRT'],
        // KTM
        'KTM Komuter'     => ['icon' => '🚂', 'color' => '#0072BC', 'type' => 'KTM'],
        'KTM ETS'         => ['icon' => '🚄', 'color' => '#0072BC', 'type' => 'KTM ETS'],
        'KTM Intercity'   => ['icon' => '🚃', 'color' => '#0072BC', 'type' => 'KTM'],
        // Monorail
        'KL Monorail'     => ['icon' => '🚝', 'color' => '#E60026', 'type' => 'Monorail'],
        'Monorail'        => ['icon' => '🚝', 'color' => '#E60026', 'type' => 'Monorail'],
        // ERL / KLIA
        'ERL'             => ['icon' => '✈️', 'color' => '#C8102E', 'type' => 'ERL'],
        'KLIA Ekspres'    => ['icon' => '✈️', 'color' => '#C8102E', 'type' => 'ERL'],
        'KLIA Transit'    => ['icon' => '✈️', 'color' => '#C8102E', 'type' => 'ERL'],
        // Penang
        'Penang Hill'     => ['icon' => '🚠', 'color' => '#2E7D32', 'type' => 'Funicular'],
        // Rapid KL Bus
        'Rapid KL'        => ['icon' => '🚌', 'color' => '#E8192C', 'type' => 'Bus'],
        'RapidKL'         => ['icon' => '🚌', 'color' => '#E8192C', 'type' => 'Bus'],
        // Generic fallbacks
        'BRT'             => ['icon' => '🚌', 'color' => '#FF6B00', 'type' => 'BRT'],
        'Bus'             => ['icon' => '🚌', 'color' => '#4CAF50', 'type' => 'Bus'],
        'MRT'             => ['icon' => '🚇', 'color' => '#1E5CB3', 'type' => 'MRT'],
        'LRT'             => ['icon' => '🚈', 'color' => '#E8192C', 'type' => 'LRT'],
        'KTM'             => ['icon' => '🚂', 'color' => '#0072BC', 'type' => 'KTM'],
        'Train'           => ['icon' => '🚆', 'color' => '#0072BC', 'type' => 'Train'],
        'Subway'          => ['icon' => '🚇', 'color' => '#1E5CB3', 'type' => 'Subway'],
        'Tram'            => ['icon' => '🚊', 'color' => '#009688', 'type' => 'Tram'],
        'Ferry'           => ['icon' => '⛴️', 'color' => '#0288D1', 'type' => 'Ferry'],
    ];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get a full route between two GPS points.
     *
     * @param float  $oLat   Origin latitude
     * @param float  $oLng   Origin longitude
     * @param float  $dLat   Destination latitude
     * @param float  $dLng   Destination longitude
     * @param string $mode   'transit' | 'driving' | 'walking' | 'bicycling'
     * @param string $departureTime  Unix timestamp or 'now'
     *
     * @return array  [
     *   'status'        => 'ok' | 'error',
     *   'message'       => string (on error),
     *   'total_duration'=> int (minutes),
     *   'total_distance'=> float (km),
     *   'summary'       => string,
     *   'steps'         => [ ...parsed step objects... ],
     *   'legs'          => [ ...raw leg summaries... ],
     *   'fare'          => string|null,
     * ]
     */
    public function getRoute(
        float  $oLat,
        float  $oLng,
        float  $dLat,
        float  $dLng,
        string $mode = 'transit',
        string $departureTime = 'now'
    ): array {
        if (empty($this->apiKey)) {
            return $this->err('Google Maps API key not configured.');
        }

        $params = [
            'origin'      => "{$oLat},{$oLng}",
            'destination' => "{$dLat},{$dLng}",
            'mode'        => $mode,
            'key'         => $this->apiKey,
            'language'    => 'en',
            'region'      => 'MY',
        ];

        if ($mode === 'transit') {
            $params['transit_routing_preference'] = 'fewer_transfers';
            $ts = ($departureTime === 'now') ? time() : (int)$departureTime;
            $params['departure_time'] = $ts;
        }

        $url  = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);
        $json = $this->httpGet($url);

        if ($json === null) {
            return $this->err('Failed to reach Google Maps API.');
        }

        $status = $json['status'] ?? 'UNKNOWN';
        if ($status !== 'OK') {
            $msg = $json['error_message'] ?? "Directions API returned: $status";
            // ZERO_RESULTS means no transit route — return haversine fallback
            if ($status === 'ZERO_RESULTS') {
                return $this->haversineFallback($oLat, $oLng, $dLat, $dLng, $mode);
            }
            return $this->err($msg);
        }

        $route = $json['routes'][0] ?? null;
        if (!$route) {
            return $this->haversineFallback($oLat, $oLng, $dLat, $dLng, $mode);
        }

        return $this->parseRoute($route, $mode);
    }

    /**
     * Get routes for all legs of an itinerary day.
     * Returns an array indexed by leg index (0-based).
     */
    public function getDayRoutes(array $stops, string $mode = 'transit'): array
    {
        $results = [];
        for ($i = 0; $i < count($stops) - 1; $i++) {
            $from = $stops[$i];
            $to   = $stops[$i + 1];
            if (!$this->validCoord($from['lat'] ?? null, $from['lng'] ?? null) ||
                !$this->validCoord($to['lat'] ?? null, $to['lng'] ?? null)) {
                $results[$i] = $this->err('Missing coordinates');
                continue;
            }
            $results[$i] = $this->getRoute(
                (float)$from['lat'], (float)$from['lng'],
                (float)$to['lat'],  (float)$to['lng'],
                $mode
            );
        }
        return $results;
    }

    // =========================================================
    // PRIVATE: Parse Google Directions response
    // =========================================================
    private function parseRoute(array $route, string $mode): array
    {
        $legs = $route['legs'] ?? [];

        $totalDurationSec = 0;
        $totalDistanceM   = 0;
        $parsedSteps      = [];
        $legSummaries     = [];

        foreach ($legs as $leg) {
            $totalDurationSec += (int)($leg['duration']['value'] ?? 0);
            $totalDistanceM   += (int)($leg['distance']['value'] ?? 0);

            $legSummaries[] = [
                'from'          => $leg['start_address'] ?? '',
                'to'            => $leg['end_address']   ?? '',
                'duration_min'  => (int)round(($leg['duration']['value'] ?? 0) / 60),
                'distance_km'   => round(($leg['distance']['value'] ?? 0) / 1000, 2),
                'departure_time'=> $leg['departure_time']['text'] ?? null,
                'arrival_time'  => $leg['arrival_time']['text']   ?? null,
            ];

            foreach ($leg['steps'] ?? [] as $step) {
                $parsedSteps[] = $this->parseStep($step, $mode);
            }
        }

        // Fare
        $fare = null;
        if (isset($route['fare'])) {
            $fare = $route['fare']['text'] ?? null;
        }

        // Summary
        $summary = $route['summary'] ?? '';
        if ($mode === 'transit' && empty($summary)) {
            // Build from transit lines used
            $lines = array_filter(array_column($parsedSteps, 'line_name'));
            $summary = implode(' → ', array_unique($lines));
        }

        return [
            'status'         => 'ok',
            'total_duration' => (int)round($totalDurationSec / 60),
            'total_distance' => round($totalDistanceM / 1000, 2),
            'summary'        => $summary,
            'steps'          => $parsedSteps,
            'legs'           => $legSummaries,
            'fare'           => $fare,
            'warnings'       => $route['warnings'] ?? [],
        ];
    }

    private function parseStep(array $step, string $mode): array
    {
        $travelMode = strtolower($step['travel_mode'] ?? 'walking');
        $durationMin = (int)round(($step['duration']['value'] ?? 0) / 60);
        $distanceKm  = round(($step['distance']['value'] ?? 0) / 1000, 2);
        $instruction = strip_tags($step['html_instructions'] ?? '');

        $base = [
            'travel_mode'    => $travelMode,
            'duration_min'   => $durationMin,
            'distance_km'    => $distanceKm,
            'instruction'    => $instruction,
            'start_location' => $step['start_location'] ?? null,
            'end_location'   => $step['end_location']   ?? null,
            // Transit-specific (filled below)
            'line_name'      => null,
            'line_short'     => null,
            'vehicle_type'   => null,
            'vehicle_icon'   => null,
            'line_color'     => null,
            'type_label'     => null,
            'depart_stop'    => null,
            'arrive_stop'    => null,
            'departure_time' => null,
            'arrival_time'   => null,
            'num_stops'      => null,
            'headsign'       => null,
            'agency'         => null,
            'sub_steps'      => [],
        ];

        if ($travelMode === 'transit') {
            $td = $step['transit_details'] ?? [];
            $line = $td['line'] ?? [];

            $lineName  = $line['name']       ?? '';
            $lineShort = $line['short_name'] ?? '';
            $vehicle   = $line['vehicle']    ?? [];
            $vType     = $vehicle['name']    ?? 'Bus';
            $vTypeRaw  = strtolower($vehicle['type'] ?? '');

            // Classify the line
            $classified = $this->classifyLine($lineName, $lineShort, $vType, $vTypeRaw);

            $base['line_name']      = $lineName  ?: $lineShort ?: $vType;
            $base['line_short']     = $lineShort;
            $base['vehicle_type']   = $vType;
            $base['vehicle_icon']   = $classified['icon'];
            $base['line_color']     = $classified['color'];
            $base['type_label']     = $classified['type'];
            $base['depart_stop']    = $td['departure_stop']['name']  ?? null;
            $base['arrive_stop']    = $td['arrival_stop']['name']    ?? null;
            $base['departure_time'] = $td['departure_time']['text']  ?? null;
            $base['arrival_time']   = $td['arrival_time']['text']    ?? null;
            $base['num_stops']      = (int)($td['num_stops'] ?? 0);
            $base['headsign']       = $td['headsign'] ?? null;
            $base['agency']         = $line['agencies'][0]['name'] ?? null;
        }

        // Walking sub-steps
        if ($travelMode === 'walking' && !empty($step['steps'])) {
            foreach ($step['steps'] as $sub) {
                $base['sub_steps'][] = [
                    'instruction' => strip_tags($sub['html_instructions'] ?? ''),
                    'distance_km' => round(($sub['distance']['value'] ?? 0) / 1000, 2),
                    'duration_min'=> (int)round(($sub['duration']['value'] ?? 0) / 60),
                ];
            }
        }

        return $base;
    }

    private function classifyLine(string $name, string $short, string $vType, string $vTypeRaw): array
    {
        $haystack = $name . ' ' . $short . ' ' . $vType;

        // Check LINE_MAP keys
        foreach (self::LINE_MAP as $keyword => $info) {
            if (stripos($haystack, $keyword) !== false) {
                return $info;
            }
        }

        // Fallback by vehicle type
        if (in_array($vTypeRaw, ['subway', 'metro_rail', 'heavy_rail'])) {
            return ['icon' => '🚇', 'color' => '#1E5CB3', 'type' => 'MRT/LRT'];
        }
        if (in_array($vTypeRaw, ['commuter_train', 'rail', 'long_distance_train'])) {
            return ['icon' => '🚂', 'color' => '#0072BC', 'type' => 'KTM'];
        }
        if ($vTypeRaw === 'monorail') {
            return ['icon' => '🚝', 'color' => '#E60026', 'type' => 'Monorail'];
        }
        if ($vTypeRaw === 'tram') {
            return ['icon' => '🚊', 'color' => '#009688', 'type' => 'Tram'];
        }
        if ($vTypeRaw === 'ferry') {
            return ['icon' => '⛴️', 'color' => '#0288D1', 'type' => 'Ferry'];
        }
        if (in_array($vTypeRaw, ['bus', 'intercity_bus', 'trolleybus'])) {
            return ['icon' => '🚌', 'color' => '#4CAF50', 'type' => 'Bus'];
        }

        return ['icon' => '🚌', 'color' => '#4CAF50', 'type' => 'Transit'];
    }

    // =========================================================
    // PRIVATE: Haversine fallback (no transit API result)
    // =========================================================
    private function haversineFallback(
        float $oLat, float $oLng,
        float $dLat, float $dLng,
        string $mode
    ): array {
        $R    = 6371.0;
        $dLa  = deg2rad($dLat - $oLat);
        $dLo  = deg2rad($dLng - $oLng);
        $a    = sin($dLa / 2) ** 2 + cos(deg2rad($oLat)) * cos(deg2rad($dLat)) * sin($dLo / 2) ** 2;
        $dist = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        $speeds = [
            'transit'   => 30,
            'driving'   => 60,
            'walking'   => 5,
            'bicycling' => 15,
        ];
        $speed   = $speeds[$mode] ?? 30;
        $durMin  = (int)ceil(($dist / $speed) * 60);

        $icon = match($mode) {
            'transit'   => '🚌',
            'driving'   => '🚗',
            'walking'   => '🚶',
            'bicycling' => '🚲',
            default     => '🚌',
        };

        return [
            'status'         => 'ok',
            'total_duration' => $durMin,
            'total_distance' => round($dist, 2),
            'summary'        => ucfirst($mode),
            'fare'           => null,
            'warnings'       => ['Route estimated using straight-line distance. No transit route found.'],
            'legs'           => [],
            'steps'          => [[
                'travel_mode'    => $mode,
                'duration_min'   => $durMin,
                'distance_km'    => round($dist, 2),
                'instruction'    => "Travel " . round($dist, 1) . " km via " . ucfirst($mode),
                'start_location' => ['lat' => $oLat, 'lng' => $oLng],
                'end_location'   => ['lat' => $dLat, 'lng' => $dLng],
                'line_name'      => null,
                'line_short'     => null,
                'vehicle_type'   => ucfirst($mode),
                'vehicle_icon'   => $icon,
                'line_color'     => '#64748b',
                'type_label'     => ucfirst($mode),
                'depart_stop'    => null,
                'arrive_stop'    => null,
                'departure_time' => null,
                'arrival_time'   => null,
                'num_stops'      => null,
                'headsign'       => null,
                'agency'         => null,
                'sub_steps'      => [],
            ]],
        ];
    }

    // =========================================================
    // PRIVATE: Helpers
    // =========================================================
    private function httpGet(string $url): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) return null;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function validCoord($lat, $lng): bool
    {
        if ($lat === null || $lng === null) return false;
        $lat = (float)$lat;
        $lng = (float)$lng;
        return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0);
    }

    private function err(string $message): array
    {
        return ['status' => 'error', 'message' => $message, 'steps' => [], 'legs' => []];
    }
}
