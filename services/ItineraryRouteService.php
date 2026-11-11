<?php
// services/ItineraryRouteService.php
// The route of one day of an itinerary as the map shows it: where the day starts, the stops
// in order, and the road route between them.

require_once __DIR__ . '/RouteService.php';

class ItineraryRouteService
{
    /**
     * Where a day starts and which points it visits.
     *
     * Day 1 starts at the trip's starting location. A later day starts where the day before
     * ended: at its hotel if one was chosen, otherwise at its last stop.
     *
     * @return array{start:?array{name:string,lat:float,lng:float,source:string},
     *               stops:array<int,array{item_id:int,title:string,type:string,lat:float,lng:float}>}
     *         source is origin | hotel | previous_stop
     */
    public static function dayChain(mysqli $conn, int $itineraryId, int $dayNo): array
    {
        $previousDay = $dayNo - 1;
        $stmt = $conn->prepare("
            SELECT ii.item_id, ii.day_no, ii.item_type, ii.item_title,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no IN (?, ?)
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
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
                $stops[] = ['item_id' => (int)$row['item_id'], 'title' => (string)$row['item_title'], 'type' => $type, 'lat' => $lat, 'lng' => $lng];
            } elseif ($type === 'hotel' || $start === null || $start['source'] !== 'hotel') {
                // rows of the day before come in order: the last hotel wins, otherwise the last stop
                $start = ['name' => (string)$row['item_title'], 'lat' => $lat, 'lng' => $lng, 'source' => $type === 'hotel' ? 'hotel' : 'previous_stop'];
            }
        }

        if ($dayNo === 1) {
            $start = null;
            $origin = $conn->prepare("SELECT origin_name, origin_lat, origin_lng FROM itineraries WHERE itinerary_id = ?");
            $origin->bind_param('i', $itineraryId);
            $origin->execute();
            $row = $origin->get_result()->fetch_assoc();
            $origin->close();
            if ($row && $row['origin_lat'] !== null && $row['origin_lng'] !== null && RouteService::validCoord((float)$row['origin_lat'], (float)$row['origin_lng'])) {
                $start = [
                    'name' => trim((string)$row['origin_name']) ?: 'Starting location',
                    'lat' => (float)$row['origin_lat'],
                    'lng' => (float)$row['origin_lng'],
                    'source' => 'origin',
                ];
            }
        }

        return ['start' => $start, 'stops' => $stops];
    }

    /**
     * A day's chain with its road route.
     *
     * @return array{day:int,start:?array,stops:array,road:?array} road is null when the routing
     *         server could not be reached or the day has fewer than two points. Each leg of
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

        $road = count($points) >= 2 ? (new RouteService($transportType))->roadRoute($points) : null;
        if ($road !== null) {
            $walkable = RouteService::normalizeTransportType($transportType) !== 'walking';
            foreach ($road['legs'] as $legIndex => &$leg) {
                $stop = $stops[$legTargets[$legIndex]];
                $leg['to_item_id'] = $stop['item_id'];
                $leg['from'] = $names[$legIndex];
                $leg['to'] = $stop['title'];

                // Two stops a few minutes' walk apart: nobody drives round a one-way system for
                // that. Show the walk instead of the detour a vehicle would have to make.
                $from = $points[$legIndex];
                $to = $points[$legIndex + 1];
                $straightKm = RouteService::haversineKm($from[0], $from[1], $to[0], $to[1]);
                if ($walkable && $straightKm <= 0.35 && $leg['distance_km'] > $straightKm * 2) {
                    $walkKm = round($straightKm * 1.3, 2);
                    $leg['distance_km'] = $walkKm;
                    $leg['travel_time_min'] = max(1, (int)ceil($walkKm / 5 * 60));
                    $leg['via'] = '';
                    $leg['geometry'] = [$from, $to];
                    $leg['steps'] = [];
                    $leg['on_foot'] = true;
                }
            }
            unset($leg);
            $road['distance_km'] = round(array_sum(array_column($road['legs'], 'distance_km')), 2);
            $road['travel_time_min'] = (int)array_sum(array_column($road['legs'], 'travel_time_min'));
        }

        return ['day' => $dayNo, 'start' => $start, 'stops' => $stops, 'road' => $road];
    }
}
