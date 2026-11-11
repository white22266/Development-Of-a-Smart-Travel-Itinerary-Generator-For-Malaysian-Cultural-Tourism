<?php
// services/ItineraryRouteService.php
// The route of one day of an itinerary as the map shows it: where the day starts, the stops
// in order, and the route between them for the chosen way of travelling.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/TransitRoutingService.php';

class ItineraryRouteService
{
    /**
     * Where a day starts and which points it visits.
     *
     * Day 1 starts at the trip's starting location. A later day starts where the day before
     * ended: at its hotel if one was chosen, otherwise at its last stop.
     *
     * @return array{date:?string,start:?array{name:string,lat:float,lng:float,source:string},
     *               stops:array<int,array{item_id:int,title:string,type:string,lat:float,lng:float,
     *                     start:?string,end:?string,travel_min:?int}>}
     *         date is the day's date (Y-m-d); source is origin | hotel | previous_stop;
     *         start and end are a stop's planned times ("09:30:00")
     */
    public static function dayChain(mysqli $conn, int $itineraryId, int $dayNo): array
    {
        $previousDay = $dayNo - 1;
        $stmt = $conn->prepare('
            SELECT ii.item_id, ii.day_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time, ii.travel_time_min,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no IN (?, ?)
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ');
        $stmt->bind_param('iii', $itineraryId, $previousDay, $dayNo);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stops = [];
        $start = null;
        foreach ($rows as $row) {
            if ($row['latitude'] === null || $row['longitude'] === null) continue;
            $lat = (float)$row['latitude'];
            $lng = (float)$row['longitude'];
            if (!RouteService::validCoord($lat, $lng)) continue;
            $type = strtolower((string)$row['item_type']);

            if ((int)$row['day_no'] === $dayNo) {
                $stops[] = [
                    'item_id' => (int)$row['item_id'], 'title' => (string)$row['item_title'], 'type' => $type, 'lat' => $lat, 'lng' => $lng,
                    'start' => $row['start_time'], 'end' => $row['end_time'],
                    'travel_min' => $row['travel_time_min'] !== null ? (int)$row['travel_time_min'] : null,
                ];
            } elseif ($type === 'hotel' || $start === null || $start['source'] !== 'hotel') {
                // rows of the day before come in order: the last hotel wins, otherwise the last stop
                $start = ['name' => (string)$row['item_title'], 'lat' => $lat, 'lng' => $lng, 'source' => $type === 'hotel' ? 'hotel' : 'previous_stop'];
            }
        }

        $trip = $conn->prepare('SELECT origin_name, origin_lat, origin_lng, start_date FROM itineraries WHERE itinerary_id = ?');
        $trip->bind_param('i', $itineraryId);
        $trip->execute();
        $row = $trip->get_result()->fetch_assoc();
        $trip->close();

        if ($dayNo === 1) {
            $start = null;
            if ($row && $row['origin_lat'] !== null && $row['origin_lng'] !== null && RouteService::validCoord((float)$row['origin_lat'], (float)$row['origin_lng'])) {
                $start = [
                    'name' => trim((string)$row['origin_name']) ?: 'Starting location',
                    'lat' => (float)$row['origin_lat'],
                    'lng' => (float)$row['origin_lng'],
                    'source' => 'origin',
                ];
            }
        }
        $firstDay = $row ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$row['start_date']) : false;

        return [
            'date' => $firstDay ? $firstDay->modify('+' . max(0, $dayNo - 1) . ' days')->format('Y-m-d') : null,
            'start' => $start,
            'stops' => $stops,
        ];
    }

    /**
     * A day's chain with the route between its points.
     *
     * By car, motorcycle or on foot this is the road route. By public transport each leg is
     * one of three things: a walk when the stops are within a kilometre of each other
     * (on_foot), a connection from the timetables where TransitRoutingService knows one
     * (transit), or otherwise the road distance with an estimated time (estimated).
     *
     * @return array{day:int,date:?string,start:?array,stops:array,road:?array} road is null when the
     *         routing server could not be reached or the day has fewer than two points. Each leg of
     *         road.legs says where it goes (to_item_id, from, to) besides what RouteService returns.
     */
    public static function dayRoute(mysqli $conn, int $itineraryId, int $dayNo, string $transportType): array
    {
        $chain = self::dayChain($conn, $itineraryId, $dayNo);
        $stops = $chain['stops'];
        $start = $chain['start'];

        // A day that starts where its first stop is has no leg to draw from the start.
        $first = $stops[0] ?? null;
        $startIsFirstStop = $start !== null && $first !== null
            && abs($start['lat'] - $first['lat']) < 0.00001 && abs($start['lng'] - $first['lng']) < 0.00001;

        $points = [];
        $names = [];
        if ($start !== null && !$startIsFirstStop) {
            $points[] = [$start['lat'], $start['lng']];
            $names[] = $start['name'];
        }
        $legTargets = [];
        foreach ($stops as $index => $stop) {
            if ($points) $legTargets[] = $index;   // a leg ends at every point after the first
            $points[] = [$stop['lat'], $stop['lng']];
            $names[] = $stop['title'];
        }

        $router = new RouteService($transportType);
        $road = count($points) >= 2 ? $router->roadRoute($points) : null;
        if ($road !== null) {
            $byTransit = RouteService::normalizeTransportType($transportType) === 'public_transport';
            $footRoute = $byTransit ? (new RouteService('walking'))->roadRoute($points) : null;
            $estimated = false;

            foreach ($road['legs'] as $legIndex => &$leg) {
                $target = $legTargets[$legIndex];
                $stop = $stops[$target];
                $leg['to_item_id'] = $stop['item_id'];
                $leg['from'] = $names[$legIndex];
                $leg['to'] = $stop['title'];
                $from = $points[$legIndex];
                $to = $points[$legIndex + 1];

                if ($byTransit) {
                    $footLeg = $footRoute['legs'][$legIndex] ?? null;
                    if ($footLeg !== null && $footLeg['distance_km'] <= RouteService::TRANSIT_WALK_KM) {
                        $leg = array_merge($leg, $footLeg, ['on_foot' => true]);
                        continue;
                    }
                    $leaveAt = self::leaveAt($chain['date'], $target > 0 ? $stops[$target - 1] : null, $stop);
                    $connection = $leaveAt !== null ? TransitRoutingService::connection($from[0], $from[1], $to[0], $to[1], $leaveAt) : null;
                    if ($connection !== null && $footLeg !== null && $footLeg['travel_time_min'] <= $connection['minutes']) {
                        $leg = array_merge($leg, $footLeg, ['on_foot' => true]);   // walking all the way is no slower
                    } elseif ($connection !== null) {
                        $leg['transit'] = $connection;
                        $leg['travel_time_min'] = $connection['minutes'];
                        $leg['via'] = '';
                        $leg['steps'] = [];
                        $leg['geometry'] = [];   // the connection's own lines are drawn instead
                    } else {
                        $leg['estimated'] = true;
                        $estimated = true;
                    }
                    continue;
                }

                // Two stops a few minutes' walk apart are walked, whatever the transport: show
                // the walk instead of the detour a vehicle would have to make.
                $walk = $router->shortWalk($from[0], $from[1], $to[0], $to[1], (float)$leg['distance_km']);
                if ($walk !== null) {
                    [$leg['distance_km'], $leg['travel_time_min']] = $walk;
                    $leg['via'] = '';
                    $leg['geometry'] = [$from, $to];
                    $leg['steps'] = [];
                    $leg['on_foot'] = true;
                }
            }
            unset($leg);
            $road['distance_km'] = round(array_sum(array_column($road['legs'], 'distance_km')), 2);
            $road['travel_time_min'] = (int)array_sum(array_column($road['legs'], 'travel_time_min'));
            $road['time_is_estimate'] = $estimated;
        }

        return ['day' => $dayNo, 'date' => $chain['date'], 'start' => $start, 'stops' => $stops, 'road' => $road];
    }

    /**
     * When the traveller sets off for a stop: when the stop before it ends, or for the first
     * stop of the day its planned arrival less the planned travelling.
     */
    private static function leaveAt(?string $date, ?array $previousStop, array $stop): ?DateTimeImmutable
    {
        $clock = $previousStop !== null ? ($previousStop['end'] ?? null) : ($stop['start'] ?? null);
        if ($date === null || !preg_match("/^(\d{1,2}):(\d{2})/", (string)$clock, $m)) return null;
        $minutes = (int)$m[1] * 60 + (int)$m[2] - ($previousStop === null ? (int)($stop['travel_min'] ?? 0) : 0);
        $minutes = max(0, min(23 * 60 + 59, $minutes));
        return (new DateTimeImmutable($date, new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(intdiv($minutes, 60), $minutes % 60);
    }
}
