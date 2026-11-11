<?php
// services/RouteService.php
// Distances and travel times between itinerary stops, without any paid API.
//
//   getSegment()  a quick estimate from the straight-line distance. No network. The planner
//                 uses it, because it compares hundreds of possible legs for one itinerary.
//   roadRoute()   the real road route through a list of points, from an OSRM routing server
//                 (an open-source router running on OpenStreetMap data). One request for a
//                 whole chain of stops, cached on disk. Used to draw the map and to show
//                 road distance and travel time.
//
// By default the public OSRM servers of the German OpenStreetMap community (FOSSGIS) are
// used. To use your own OSRM server, set ROUTING_CAR_URL and ROUTING_FOOT_URL in .env.

require_once __DIR__ . '/OpenDataHttp.php';

class RouteService
{
    /** Average door-to-door speeds in km/h, used for estimates. */
    private const SPEED_MAP = [
        'car'              => 60,
        'motorcycle'       => 55,
        'public_transport' => 30,
        'walking'          => 5,
    ];

    /** Roads are longer than the straight line between two points. */
    private const ROAD_FACTOR = 1.3;

    private const DEFAULT_CAR_URL = 'https://routing.openstreetmap.de/routed-car';
    private const DEFAULT_FOOT_URL = 'https://routing.openstreetmap.de/routed-foot';

    /** Longest chain of points sent in one request. */
    private const MAX_ROUTE_POINTS = 60;

    /** @var string car | motorcycle | public_transport | walking */
    private string $transportType;

    public function __construct(string $transportType = 'car')
    {
        $this->transportType = self::normalizeTransportType($transportType);
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

    /**
     * Estimated distance and travel time between two coordinates. No network access.
     *
     * @return array{distance_km: float, travel_time_min: int, method: string}
     */
    public function getSegment(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $roadKm = self::haversineKm($lat1, $lng1, $lat2, $lng2) * self::ROAD_FACTOR;
        return [
            'distance_km'     => round($roadKm, 2),
            'travel_time_min' => $this->minutesFor($roadKm),
            'method'          => 'estimate',
        ];
    }

    /**
     * The road route through a list of points, in the order given.
     *
     * Public transport has no free routing service: its legs follow the roads, and their
     * times are estimated from the road distance.
     *
     * @param array<int,array{0:float,1:float}> $points [latitude, longitude] pairs
     * @param bool $withDetails also return, for every leg, the line to draw, the main roads
     *                          and turn-by-turn steps
     * @return array{distance_km:float,travel_time_min:int,time_is_estimate:bool,
     *               legs:array<int,array{distance_km:float,travel_time_min:int,via?:string,
     *                    geometry?:array<int,array{0:float,1:float}>,steps?:array<int,array{text:string,distance_m:int}>}>}|null
     *         null when there are fewer than two usable points or no routing server answered.
     *         legs has one entry per consecutive pair of points.
     */
    public function roadRoute(array $points, bool $withDetails = true): ?array
    {
        $points = array_values(array_filter($points, static fn($p) => is_array($p) && self::validCoord((float)($p[0] ?? 0), (float)($p[1] ?? 0))));
        if (count($points) < 2 || count($points) > self::MAX_ROUTE_POINTS) return null;

        $onFoot = $this->transportType === 'walking';
        $base = rtrim((string)(getenv($onFoot ? 'ROUTING_FOOT_URL' : 'ROUTING_CAR_URL') ?: ($onFoot ? self::DEFAULT_FOOT_URL : self::DEFAULT_CAR_URL)), '/');
        $coordinates = implode(';', array_map(
            static fn($p) => round((float)$p[1], 5) . ',' . round((float)$p[0], 5),   // OSRM wants longitude first
            $points
        ));
        $url = $base . '/route/v1/' . ($onFoot ? 'foot' : 'driving') . '/' . $coordinates
            . '?alternatives=false&overview=false&steps=' . ($withDetails ? 'true&geometries=geojson' : 'false');

        $json = OpenDataHttp::getJson($url, 2592000, [   // roads change slowly: 30 days
            'namespace' => 'routes',
            'timeout' => 15,
            'validate' => static fn(array $j): bool => ($j['code'] ?? '') === 'Ok' && isset($j['routes'][0]['legs']),
        ]);
        $route = $json['routes'][0] ?? null;
        if (!is_array($route) || count($route['legs']) !== count($points) - 1) return null;

        $timeIsEstimate = $this->transportType === 'public_transport';
        $legs = [];
        $totalKm = 0.0;
        $totalMin = 0;
        foreach ($route['legs'] as $leg) {
            $km = (float)($leg['distance'] ?? 0) / 1000.0;
            $minutes = $timeIsEstimate ? $this->minutesFor($km) : (int)ceil((float)($leg['duration'] ?? 0) / 60.0);
            $entry = ['distance_km' => round($km, 2), 'travel_time_min' => $minutes];
            if ($withDetails) {
                $entry['via'] = trim((string)($leg['summary'] ?? ''));
                $entry['geometry'] = [];
                $entry['steps'] = [];
                foreach (($leg['steps'] ?? []) as $step) {
                    foreach (($step['geometry']['coordinates'] ?? []) as $pair) {
                        if (is_array($pair) && count($pair) >= 2) $entry['geometry'][] = [round((float)$pair[1], 5), round((float)$pair[0], 5)];
                    }
                    $text = self::stepText($step);
                    if ($text !== null) $entry['steps'][] = ['text' => $text, 'distance_m' => (int)round((float)($step['distance'] ?? 0))];
                }
            }
            $legs[] = $entry;
            $totalKm += $km;
            $totalMin += $minutes;
        }

        return [
            'distance_km' => round($totalKm, 2),
            'travel_time_min' => $totalMin,
            'time_is_estimate' => $timeIsEstimate,
            'legs' => $legs,
        ];
    }

    /** One step of a route in words ("Turn left onto Jalan Kota"), or null for a step not worth a line. */
    private static function stepText(array $step): ?string
    {
        $type = (string)($step['maneuver']['type'] ?? '');
        $modifier = (string)($step['maneuver']['modifier'] ?? '');
        $name = trim((string)($step['name'] ?? ''));
        $onto = $name !== '' ? ' onto ' . $name : '';

        if ($type === 'arrive') return 'Arrive';
        if ((float)($step['distance'] ?? 0) < 10) return null;   // a few metres between two turns
        $turn = match ($modifier) {
            'uturn' => 'Make a U-turn',
            'straight' => 'Continue straight',
            'slight left' => 'Keep left',
            'slight right' => 'Keep right',
            'sharp left' => 'Turn sharp left',
            'sharp right' => 'Turn sharp right',
            'left', 'right' => 'Turn ' . $modifier,
            default => 'Continue',
        };
        return match ($type) {
            'depart' => 'Start' . ($name !== '' ? ' on ' . $name : ''),
            'turn', 'end of road', 'fork', 'continue' => $turn . $onto,
            'new name' => 'Continue' . $onto,
            'merge' => 'Merge' . $onto,
            'on ramp' => 'Take the ramp' . $onto,
            'off ramp' => 'Take the exit' . $onto,
            'roundabout', 'rotary' => 'At the roundabout, take exit ' . (int)($step['maneuver']['exit'] ?? 1) . $onto,
            'roundabout turn' => 'At the roundabout, ' . lcfirst($turn) . $onto,
            'exit roundabout', 'exit rotary', 'notification' => null,
            default => 'Continue' . $onto,
        };
    }

    public static function validCoord(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng)
            && !($lat === 0.0 && $lng === 0.0)
            && $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180;
    }

    /** Great-circle distance in kilometres. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** Minutes needed for a road distance at the usual speed of this transport type. */
    private function minutesFor(float $roadKm): int
    {
        $speed = self::SPEED_MAP[$this->transportType] ?? 60;
        return (int)ceil(($roadKm / $speed) * 60);
    }
}
