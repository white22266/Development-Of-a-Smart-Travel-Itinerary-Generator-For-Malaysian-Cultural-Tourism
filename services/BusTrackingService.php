<?php
// services/BusTrackingService.php
// Where the buses and trains are right now, for the public transport side of the map.
//
// The positions are open data: every operator that reports to the national Open API
// (data.gov.my) is covered. In November 2026 that is Rapid KL buses and MRT feeder buses,
// Rapid Penang, the KTM trains, and the BAS.MY stage buses of Kangar, Alor Setar, Kota Bharu,
// Kuala Terengganu, Ipoh, Seremban, Melaka, Johor Bahru and Kuching. Licence: CC BY 4.0.
//
//   realtime feed    the vehicles' positions, renewed by the operators every 30 seconds
//   static feed      the timetable package of the same operator; route numbers, names,
//                    colours and the line each route follows are taken from it
//
// data.gov.my allows four requests a minute for each of the two. So a feed is asked for only
// when a traveller looks at its area, at most every 40 seconds, and all requests together stay
// inside the allowance; when it is used up the last known positions are shown a little longer.

require_once __DIR__ . '/OpenDataHttp.php';
require_once __DIR__ . '/GtfsFeedReader.php';
require_once __DIR__ . '/RouteService.php';

class BusTrackingService
{
    public const CREDIT = 'Live positions: Prasarana, KTMB and the BAS.MY operators, through data.gov.my (CC BY 4.0)';

    private const API = 'https://api.data.gov.my';

    /**
     * The feeds: id => [operator as shown, bus | train, feed path, area the operator serves as
     * west, south, east, north]. The realtime feed is at /gtfs-realtime/vehicle-position/<path>
     * and the timetable package at /gtfs-static/<path>.
     */
    private const FEEDS = [
        'rapid-bus-kl' => ['Rapid KL', 'bus', 'prasarana?category=rapid-bus-kl', [101.25, 2.85, 101.95, 3.45]],
        'rapid-bus-mrtfeeder' => ['Rapid KL MRT feeder', 'bus', 'prasarana?category=rapid-bus-mrtfeeder', [101.25, 2.85, 101.95, 3.45]],
        'rapid-bus-penang' => ['Rapid Penang', 'bus', 'prasarana?category=rapid-bus-penang', [100.10, 5.10, 100.60, 5.60]],
        'ktmb' => ['KTM', 'train', 'ktmb', [99.60, 1.20, 104.40, 6.80]],
        'mybas-kangar' => ['BAS.MY Kangar', 'bus', 'mybas-kangar', [100.00, 6.20, 100.50, 6.75]],
        'mybas-alor-setar' => ['BAS.MY Alor Setar', 'bus', 'mybas-alor-setar', [100.10, 5.80, 100.70, 6.45]],
        'mybas-kota-bharu' => ['BAS.MY Kota Bharu', 'bus', 'mybas-kota-bharu', [101.90, 5.70, 102.60, 6.30]],
        'mybas-kuala-terengganu' => ['BAS.MY Kuala Terengganu', 'bus', 'mybas-kuala-terengganu', [102.70, 4.90, 103.50, 5.70]],
        'mybas-ipoh' => ['BAS.MY Ipoh', 'bus', 'mybas-ipoh', [100.70, 4.20, 101.50, 5.00]],
        'mybas-seremban-a' => ['BAS.MY Seremban', 'bus', 'mybas-seremban-a', [101.60, 2.35, 102.40, 3.00]],
        'mybas-seremban-b' => ['BAS.MY Seremban', 'bus', 'mybas-seremban-b', [101.60, 2.35, 102.40, 3.00]],
        'mybas-melaka' => ['BAS.MY Melaka', 'bus', 'mybas-melaka', [101.90, 1.95, 102.70, 2.60]],
        'mybas-johor' => ['BAS.MY Johor Bahru', 'bus', 'mybas-johor', [103.30, 1.20, 104.20, 1.90]],
        'mybas-kuching' => ['BAS.MY Kuching', 'bus', 'mybas-kuching', [109.90, 1.10, 110.80, 1.90]],
    ];

    /** Rapid Penang's live trip ids lack the service prefix ("weekday_") the timetable gives them. */
    private const TRIP_ID_WITHOUT_PREFIX = ['rapid-bus-penang'];

    private const LIVE_TTL = 40;           // seconds a feed's positions are kept before asking again
    private const LIVE_MAX_AGE = 300;      // positions older than this are not shown
    private const STATIC_TTL = 259200;     // routes and lines are read again every three days
    private const REQUESTS_PER_MINUTE = 4; // the allowance of data.gov.my for each of the two feeds
    private const MAX_FEEDS = 3;           // feeds asked for one view of the map
    private const MAX_VIEW_KM = 150.0;     // a wider view than this shows no vehicles
    private const LINE_POINTS = 200;       // a route's line is thinned to about this many points

    /** @var array<string,array> feed id => what was read from its timetable package, kept for this request */
    private static array $timetables = [];

    /** Only one timetable package is fetched during a request: it takes a few seconds. */
    private static bool $fetchedPackage = false;

    /**
     * The vehicles inside a part of the map.
     *
     * @param array<int,float> $box west, south, east, north
     * @return array{vehicles:array<int,array>,operators:string[],note:string,credit:string,checked_at:string}
     *         A vehicle has: id (usually its number plate), feed, operator, kind (bus | train),
     *         route (the number on the bus), name (of the route), towards, color, lat, lng,
     *         bearing (degrees, or null), speed_kmh (or null), age_s (seconds since it reported),
     *         trip and route_id (for routeLine()).
     */
    public static function vehiclesIn(array $box): array
    {
        [$west, $south, $east, $north] = array_map('floatval', array_pad(array_values($box), 4, 0));
        $result = ['vehicles' => [], 'operators' => [], 'note' => '', 'credit' => self::CREDIT, 'checked_at' => date('c')];
        if ($west >= $east || $south >= $north || !RouteService::validCoord($south, $west) || !RouteService::validCoord($north, $east)) {
            $result['note'] = 'No part of the map was given.';
            return $result;
        }
        if (RouteService::haversineKm($south, $west, $north, $east) > self::MAX_VIEW_KM) {
            $result['note'] = 'Zoom in to see the buses and trains.';
            return $result;
        }

        // The feeds that serve this part of the map, the nearest first.
        $areas = self::areas();
        $distance = [];
        foreach (self::FEEDS as $id => $feed) {
            $area = $areas[$id] ?? $feed[3];
            if ($area[0] > $east || $area[2] < $west || $area[1] > $north || $area[3] < $south) continue;
            $distance[$id] = RouteService::haversineKm(($south + $north) / 2, ($west + $east) / 2, ($area[1] + $area[3]) / 2, ($area[0] + $area[2]) / 2);
        }
        asort($distance);
        if (!$distance) {
            $result['note'] = 'No operator publishes live positions for this part of Malaysia yet.';
            return $result;
        }

        $now = time();
        $unanswered = 0;
        foreach (array_slice(array_keys($distance), 0, self::MAX_FEEDS) as $id) {
            [$operator, $kind] = self::FEEDS[$id];
            $live = self::positions($id);
            if ($live === null) {
                $unanswered++;
                continue;
            }
            $timetable = self::timetable($id, true);
            $shown = 0;
            foreach ($live['vehicles'] as $vehicleId => [$lat, $lng, $seen, $bearing, $speed, $tripId, $routeId]) {
                if ($lat < $south || $lat > $north || $lng < $west || $lng > $east || $now - $seen > self::LIVE_MAX_AGE) continue;
                $trip = $timetable['trips'][$tripId] ?? null;                          // [route id, line id, headsign]
                $route = $timetable['routes'][$trip[0] ?? $routeId] ?? $timetable['routes'][$routeId] ?? null;   // [number, name, colour]
                $result['vehicles'][] = [
                    'id' => (string)$vehicleId,
                    'feed' => $id,
                    'operator' => $operator,
                    'kind' => $kind,
                    'route' => self::number($route, $routeId),
                    'name' => ($route[1] ?? '') !== self::number($route, $routeId) ? ($route[1] ?? '') : '',
                    'towards' => $trip[2] ?? '',
                    'color' => $route[2] ?? null,
                    'lat' => $lat,
                    'lng' => $lng,
                    'bearing' => $bearing,
                    'speed_kmh' => $speed,
                    'age_s' => max(0, $now - $seen),
                    'trip' => $tripId,
                    'route_id' => $trip[0] ?? $routeId,
                ];
                $shown++;
            }
            if ($shown > 0) $result['operators'][$operator] = true;
        }
        $result['operators'] = array_keys($result['operators']);
        $result['vehicles'] = array_slice($result['vehicles'], 0, 400);
        if (!$result['vehicles']) {
            $result['note'] = $unanswered > 0
                ? 'The live positions could not be loaded just now.'
                : 'No bus or train is reporting its position in this part of the map right now.';
        }
        return $result;
    }

    /**
     * The line a vehicle's route follows, to draw on the map.
     *
     * @return array{points:array<int,array{0:float,1:float}>,route:string,name:string,color:?string}|null
     */
    public static function routeLine(string $feedId, string $tripId, string $routeId): ?array
    {
        if (!isset(self::FEEDS[$feedId])) return null;
        $timetable = self::timetable($feedId, true);
        $trip = $timetable['trips'][$tripId] ?? null;
        $routeId = $trip[0] ?? $routeId;
        $lineId = ($trip[1] ?? '') !== '' ? $trip[1] : ($timetable['route_lines'][$routeId] ?? '');
        if ($lineId === '') return null;

        $file = OpenDataHttp::cachePath('buses', 'lines-' . $feedId . '.json');
        $lines = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $points = $lines[$lineId] ?? null;
        if (!is_array($points) || count($points) < 2) return null;
        $route = $timetable['routes'][$routeId] ?? null;
        return ['points' => $points, 'route' => self::number($route, $routeId), 'name' => $route[1] ?? '', 'color' => $route[2] ?? null];
    }

    /**
     * The last positions of a feed: from the cache while they are fresh, otherwise asked for
     * again. Speed and direction are worked out from how far a vehicle moved since the
     * positions before, because not every operator sends them.
     *
     * @return array{fetched_at:int,vehicles:array<string,array>}|null vehicles: id => [latitude,
     *         longitude, time measured, bearing or null, km/h or null, trip id, route id]
     */
    private static function positions(string $id): ?array
    {
        $file = OpenDataHttp::cachePath('buses', 'live-' . $id . '.json');
        $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $age = is_array($saved) ? time() - (int)($saved['fetched_at'] ?? 0) : PHP_INT_MAX;
        $lastKnown = $age < self::LIVE_MAX_AGE ? $saved : null;
        if ($age < self::LIVE_TTL || !self::allowed('realtime')) return $lastKnown;

        $bytes = OpenDataHttp::getBytes(self::API . '/gtfs-realtime/vehicle-position/' . self::FEEDS[$id][2], 12, 5000000);
        if ($bytes === null) return $lastKnown;

        $before = is_array($saved) ? (array)($saved['vehicles'] ?? []) : [];
        $vehicles = [];
        foreach (GtfsFeedReader::vehiclePositions($bytes) as $vehicle) {
            $bearing = null;
            $speed = null;
            $previous = $before[$vehicle['vehicle_id']] ?? null;
            $seconds = $previous !== null ? $vehicle['timestamp'] - (int)$previous[2] : -1;
            if ($seconds === 0) {
                [$bearing, $speed] = [$previous[3], $previous[4]];   // the same report as last time
            } elseif ($seconds >= 15 && $seconds <= self::LIVE_MAX_AGE) {
                $km = RouteService::haversineKm((float)$previous[0], (float)$previous[1], $vehicle['lat'], $vehicle['lng']);
                $kmh = $km / $seconds * 3600;
                if ($kmh <= 130) $speed = (int)round($kmh);          // faster than that is a jump of the GPS
                $bearing = $km >= 0.02 ? self::bearing((float)$previous[0], (float)$previous[1], $vehicle['lat'], $vehicle['lng']) : $previous[3];
            }
            // An operator's own bearing is used while the vehicle has not been seen moving. 0 means "not set".
            if ($bearing === null && $vehicle['bearing'] !== null && $vehicle['bearing'] != 0.0) $bearing = (int)round($vehicle['bearing']);
            $vehicles[$vehicle['vehicle_id']] = [$vehicle['lat'], $vehicle['lng'], $vehicle['timestamp'], $bearing, $speed, $vehicle['trip_id'], $vehicle['route_id']];
        }

        $snapshot = ['fetched_at' => time(), 'vehicles' => $vehicles];
        file_put_contents($file, json_encode($snapshot), LOCK_EX);
        return $snapshot;
    }

    /** Count a request to data.gov.my; false when this minute's allowance for that feed type is used up. */
    private static function allowed(string $type): bool
    {
        $handle = @fopen(OpenDataHttp::cachePath('buses', 'requests-' . $type . '.json'), 'c+');
        if (!$handle) return true;
        $allowed = true;
        if (flock($handle, LOCK_EX)) {
            $now = time();
            $recent = array_values(array_filter((array)json_decode((string)stream_get_contents($handle), true), static fn($at) => is_int($at) && $at > $now - 60));
            $allowed = count($recent) < self::REQUESTS_PER_MINUTE;
            if ($allowed) {
                $recent[] = $now;
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string)json_encode($recent));
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return $allowed;
    }

    /** Compass direction of travel from one point to the next, 0 to 359 degrees. */
    private static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));
        return ((int)round(rad2deg(atan2($y, $x))) + 360) % 360;
    }

    /** @return array<string,array<int,float>> feed id => the area its stops cover, for the feeds whose timetable was read */
    private static function areas(): array
    {
        $file = OpenDataHttp::cachePath('buses', 'areas.json');
        $areas = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($areas) ? $areas : [];
    }

    /**
     * What a feed's timetable package says about its routes: [] until it could be read.
     *
     * @return array{routes?:array<string,array>,trips?:array<string,array>,route_lines?:array<string,string>}
     *         routes: route id => [number, name, colour]; trips: trip id => [route id, line id, headsign];
     *         route_lines: route id => the id of a line of that route
     */
    private static function timetable(string $id, bool $mayFetch): array
    {
        if (isset(self::$timetables[$id])) return self::$timetables[$id];

        $file = OpenDataHttp::cachePath('buses', 'timetable-' . $id . '.json');
        $failed = OpenDataHttp::cachePath('buses', 'timetable-' . $id . '.failed');
        $stale = !is_file($file) || time() - filemtime($file) >= self::STATIC_TTL;
        $triedLately = is_file($failed) && time() - filemtime($failed) < 1800;   // after a failure, wait half an hour
        if ($stale && $mayFetch && !$triedLately && !self::$fetchedPackage && self::allowed('static')) {
            self::$fetchedPackage = true;
            if (!self::readPackage($id)) touch($failed);
        }
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return self::$timetables[$id] = is_array($data) ? $data : [];
    }

    /** Fetch a feed's timetable package and keep its routes, trips, lines and service area. */
    private static function readPackage(string $id): bool
    {
        $zip = OpenDataHttp::getBytes(self::API . '/gtfs-static/' . self::FEEDS[$id][2], 40, 40000000);
        if ($zip === null) return false;

        $routes = [];
        foreach (GtfsFeedReader::rows((string)GtfsFeedReader::zipFile($zip, 'routes.txt')) as $row) {
            $color = strtolower($row['route_color'] ?? '');
            $routes[$row['route_id'] ?? ''] = [
                GtfsFeedReader::name($row['route_short_name'] ?? ''),
                GtfsFeedReader::name($row['route_long_name'] ?? ''),
                preg_match('/^[0-9a-f]{6}$/', $color) ? '#' . $color : null,
            ];
        }
        unset($routes['']);
        if (!$routes) return false;

        $withoutPrefix = in_array($id, self::TRIP_ID_WITHOUT_PREFIX, true);
        $trips = [];
        $routeLines = [];
        foreach (GtfsFeedReader::rows((string)GtfsFeedReader::zipFile($zip, 'trips.txt')) as $row) {
            $tripId = $row['trip_id'] ?? '';
            if ($withoutPrefix && str_contains($tripId, '_')) $tripId = substr($tripId, strpos($tripId, '_') + 1);
            $routeId = $row['route_id'] ?? '';
            $lineId = $row['shape_id'] ?? '';
            if ($tripId === '' || isset($trips[$tripId])) continue;
            $trips[$tripId] = [$routeId, $lineId, GtfsFeedReader::name($row['trip_headsign'] ?? '')];
            if ($lineId !== '') $routeLines[$routeId] ??= $lineId;
        }

        $lines = [];
        foreach (GtfsFeedReader::rows((string)GtfsFeedReader::zipFile($zip, 'shapes.txt')) as $row) {
            $lat = (float)($row['shape_pt_lat'] ?? 0);
            $lng = (float)($row['shape_pt_lon'] ?? 0);
            if (RouteService::validCoord($lat, $lng)) $lines[$row['shape_id'] ?? ''][(int)($row['shape_pt_sequence'] ?? 0)] = [round($lat, 5), round($lng, 5)];
        }
        foreach ($lines as $lineId => $points) {
            ksort($points);
            $points = array_values($points);
            $step = max(1, (int)ceil(count($points) / self::LINE_POINTS));
            $lines[$lineId] = array_values(array_filter($points, static fn(int $index): bool => $index % $step === 0 || $index === count($points) - 1, ARRAY_FILTER_USE_KEY));
        }

        // The area the stops cover says where this feed is worth asking for.
        $lats = [];
        $lngs = [];
        foreach (GtfsFeedReader::rows((string)GtfsFeedReader::zipFile($zip, 'stops.txt')) as $row) {
            $lat = (float)($row['stop_lat'] ?? 0);
            $lng = (float)($row['stop_lon'] ?? 0);
            if (RouteService::validCoord($lat, $lng) && $lat > 0.5 && $lat < 8 && $lng > 99 && $lng < 120) {   // inside Malaysia
                $lats[] = $lat;
                $lngs[] = $lng;
            }
        }
        if ($lats) {
            $areas = self::areas();
            $areas[$id] = [round(min($lngs) - 0.03, 3), round(min($lats) - 0.03, 3), round(max($lngs) + 0.03, 3), round(max($lats) + 0.03, 3)];
            file_put_contents(OpenDataHttp::cachePath('buses', 'areas.json'), json_encode($areas), LOCK_EX);
        }

        file_put_contents(OpenDataHttp::cachePath('buses', 'lines-' . $id . '.json'), json_encode($lines), LOCK_EX);
        file_put_contents(OpenDataHttp::cachePath('buses', 'timetable-' . $id . '.json'), json_encode(['routes' => $routes, 'trips' => $trips, 'route_lines' => $routeLines], JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        return true;
    }

    /** The number shown on the vehicle. Some operators put it in the route's name, some only in its id. */
    private static function number(?array $route, string $routeId): string
    {
        if (($route[0] ?? '') !== '') return $route[0];
        return ($route[1] ?? '') !== '' && mb_strlen($route[1]) <= 10 ? $route[1] : $routeId;
    }
}
