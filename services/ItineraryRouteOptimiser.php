<?php
// services/ItineraryRouteOptimiser.php
// Puts the stops of every day of a saved itinerary into the order with the least travelling,
// measured on the road for the trip's transport, and times each day again in that order.
//
// The planner does this while it builds a day. This service does it for an itinerary that
// already exists: at the end of generation, after the steps that add stops to short days,
// and when a traveller asks for it after changing stops or hotels.
//
// What stays as it is: which places are visited and on which day, how long each visit
// lasts, the break between visits and the time the day starts. Only the order changes, and
// with it the clock times.
//
// The shortest order is not used when another one avoids what it gets wrong: reaching a
// place while it is closed, an outdoor place in the midday heat for a traveller who asked
// to avoid heat, or two meals one right after the other.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/OpeningHours.php';
require_once __DIR__ . '/ItineraryEditService.php';
require_once __DIR__ . '/ItineraryHotelService.php';

class ItineraryRouteOptimiser
{
    /** How many of the shortest orders are checked against opening hours. */
    private const ORDERS_TO_CHECK = 720;

    /**
     * Reorder and re-time every day, then store the legs and the total cost again.
     * Call it inside the caller's transaction, if there is one.
     *
     * @return array{days:array<int,array{stops:int,before_min:int,after_min:int,reordered:bool,problems:int}>,
     *               reordered_days:int[],saved_min:int}
     *         before_min and after_min are a day's travelling time in the old and the new order;
     *         problems counts the visits that still start while the place is closed or too hot
     */
    public static function optimise(mysqli $conn, int $itineraryId): array
    {
        $report = ['days' => [], 'reordered_days' => [], 'saved_min' => 0];

        $metaStmt = $conn->prepare("
            SELECT i.start_date, i.origin_lat, i.origin_lng, tp.transport_type, tp.accessibility_needs
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        $metaStmt->bind_param('i', $itineraryId);
        $metaStmt->execute();
        $meta = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();
        if (!$meta) return $report;

        $itemsStmt = $conn->prepare("
            SELECT ii.item_id, ii.day_no, ii.item_type, ii.start_time, ii.end_time, ii.travel_time_min,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude,
                   cp.opening_hours, cp.category, cp.is_outdoor
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $days = [];
        foreach ($itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $days[(int)$row['day_no']][] = $row;
        }
        $itemsStmt->close();

        $route = new RouteService((string)($meta['transport_type'] ?? 'car'));
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$meta['start_date']) ?: null;
        $avoidHeat = (bool)preg_match('/avoid[_ ]heat/', strtolower((string)($meta['accessibility_needs'] ?? '')));
        $dayStartPoint = self::point($meta['origin_lat'], $meta['origin_lng']);   // where the coming day starts
        $changes = [];   // item id => [sequence number, start minute, end minute]

        // One request for the whole trip where the routing server prefers that (see RouteService).
        $pointsByDay = [];
        foreach ($days as $dayNo => $rows) {
            foreach ($rows as $row) {
                $pointsByDay[$dayNo][] = self::point($row['latitude'], $row['longitude']);
            }
        }
        if ($pointsByDay && $dayStartPoint !== null) $pointsByDay[array_key_first($pointsByDay)][] = $dayStartPoint;
        $route->preloadTrip($pointsByDay);

        foreach ($days as $dayNo => $rows) {
            $visits = [];
            $hotel = null;
            $movable = true;
            foreach ($rows as $row) {
                $type = strtolower((string)$row['item_type']);
                $row['point'] = self::point($row['latitude'], $row['longitude']);
                $row['start_min'] = self::minutes($row['start_time']);
                $row['end_min'] = self::minutes($row['end_time']);
                if ($type === 'hotel') {
                    $hotel = $row;
                } elseif (in_array($type, ['transport', 'note'], true) || $row['point'] === null || $row['start_min'] === null || $row['end_min'] === null) {
                    $movable = false;   // a row without a place or a time: leave this day alone
                } else {
                    $visits[] = $row;
                }
            }
            if (!$movable || !$visits) {
                $dayStartPoint = self::lastPoint($rows, $hotel) ?? $dayStartPoint;
                continue;
            }

            $plan = self::planDay($route, $dayStartPoint, $visits, $hotel['point'] ?? null, $startDate?->modify('+' . ($dayNo - 1) . ' days')->format('Y-m-d'), $avoidHeat);
            foreach ($plan['order'] as $position => $index) {
                $changes[(int)$visits[$index]['item_id']] = [$position + 1, $plan['times'][$index][0], $plan['times'][$index][1]];
            }
            $lastIndex = $plan['order'][count($plan['order']) - 1];
            if ($hotel !== null) {
                $checkIn = ItineraryHotelService::checkInMinute($plan['times'][$lastIndex][1]);
                $changes[(int)$hotel['item_id']] = [count($visits) + 1, $checkIn, $checkIn + 30];
            }
            $dayStartPoint = $hotel['point'] ?? $visits[$lastIndex]['point'];

            $reordered = $plan['order'] !== array_keys($visits);
            $report['days'][$dayNo] = [
                'stops' => count($visits),
                'before_min' => $plan['before_min'],
                'after_min' => $plan['after_min'],
                'reordered' => $reordered,
                'problems' => $plan['problems'],
            ];
            if ($reordered) {
                $report['reordered_days'][] = $dayNo;
                $report['saved_min'] += max(0, $plan['before_min'] - $plan['after_min']);
            }
        }

        $update = $conn->prepare("UPDATE itinerary_items SET sequence_no = ?, start_time = ?, end_time = ? WHERE item_id = ? AND itinerary_id = ?");
        foreach ($changes as $itemId => [$sequenceNo, $startMin, $endMin]) {
            $startTime = self::sqlTime($startMin);
            $endTime = self::sqlTime($endMin);
            $update->bind_param('issii', $sequenceNo, $startTime, $endTime, $itemId, $itineraryId);
            $update->execute();
        }
        $update->close();

        ItineraryEditService::routes($conn, $itineraryId, $route);
        ItineraryEditService::total($conn, $itineraryId);
        return $report;
    }

    /**
     * The best order for one day and the clock times that go with it.
     *
     * @param array{0:float,1:float}|null $start where the day begins; without it the first visit stays first
     * @param array<int,array>            $visits the day's visits in their present order
     * @param array{0:float,1:float}|null $hotel where the day ends, when a hotel was chosen
     * @return array{order:int[],times:array<int,array{0:int,1:int}>,problems:int,before_min:int,after_min:int}
     *         order lists keys of $visits; times gives [start, end] in minutes after midnight per key
     */
    private static function planDay(RouteService $route, ?array $start, array $visits, ?array $hotel, ?string $date, bool $avoidHeat): array
    {
        $first = $visits[0];

        // When the day begins and how long the breaks are is read from the clock times, not from
        // the order of the rows: after an edit the two do not always agree.
        $byTime = $visits;
        usort($byTime, static fn(array $a, array $b): int => $a['start_min'] <=> $b['start_min']);
        $dayStartMin = max(0, $byTime[0]['start_min'] - ($start !== null ? (int)$byTime[0]['travel_time_min'] : 0));

        // The break the day was planned with: the usual gap between two visits, travelling aside.
        $gaps = [];
        for ($k = 1, $count = count($byTime); $k < $count; $k++) {
            $gaps[] = $byTime[$k]['start_min'] - $byTime[$k - 1]['end_min'] - (int)$byTime[$k]['travel_time_min'];
        }
        sort($gaps);
        $restMin = $gaps ? max(0, min(180, (int)(round($gaps[intdiv(count($gaps), 2)] / 5) * 5))) : 0;

        $route->preload(array_values(array_filter(array_merge([$start], array_column($visits, 'point'), [$hotel]))));

        // An order as a timetable. problems: visits that start while the place is closed or,
        // for a traveller avoiding heat, outdoors between 11 am and 4 pm. meals_in_a_row: food
        // stops that directly follow another food stop.
        $schedule = static function (array $order) use ($route, $start, $visits, $hotel, $date, $avoidHeat, $dayStartMin, $restMin): array {
            $clock = $dayStartMin;
            $from = $start;
            $travelMin = 0;
            $problems = 0;
            $mealsInARow = 0;
            $wasMeal = false;
            $times = [];
            foreach ($order as $index) {
                $visit = $visits[$index];
                $leg = $from === null ? 0 : $route->getSegment($from[0], $from[1], $visit['point'][0], $visit['point'][1])['travel_time_min'];
                $duration = max(15, $visit['end_min'] - $visit['start_min']);
                $arrival = $clock + $leg;
                if (!OpeningHours::allows((string)($visit['opening_hours'] ?? ''), $arrival, $date, min(30, $duration))) {
                    $problems++;
                } elseif ($avoidHeat && (int)($visit['is_outdoor'] ?? 0) === 1 && $arrival >= 11 * 60 && $arrival <= 16 * 60) {
                    $problems++;
                }
                $isMeal = strtolower((string)$visit['item_type']) === 'food';
                if ($isMeal && $wasMeal) $mealsInARow++;
                $wasMeal = $isMeal;
                $times[$index] = [$arrival, $arrival + $duration];
                $clock = $arrival + $duration + $restMin;
                $from = $visit['point'];
                $travelMin += $leg;
            }
            if ($hotel !== null) $travelMin += $route->getSegment($from[0], $from[1], $hotel[0], $hotel[1])['travel_time_min'];
            return ['order' => $order, 'times' => $times, 'problems' => $problems, 'travel_min' => $travelMin,
                    'rank' => [$problems, $mealsInARow, $travelMin]];   // compared from left to right, lower is better
        };

        $present = $schedule(array_keys($visits));

        // Without a known start the first visit is the fixed point the others are ordered from.
        $anchor = $start ?? $first['point'];
        $free = $start === null ? array_slice($visits, 1, null, true) : $visits;
        $points = [];
        $categories = [];
        foreach ($free as $index => $visit) {
            $points[$index] = $visit['point'];
            $categories[$index] = strtolower((string)($visit['category'] ?? ''));
        }
        // Orders come shortest first, so the first one without any fault is the answer. The
        // present order stays when no other is better.
        $best = $present;
        foreach ($route->bestOrders($anchor, $points, $hotel, $categories, self::ORDERS_TO_CHECK) as $candidate) {
            $planned = $schedule($start === null ? array_merge([0], $candidate['order']) : $candidate['order']);
            if ($planned['rank'] < $best['rank']) $best = $planned;
            if ($planned['rank'][0] === 0 && $planned['rank'][1] === 0) break;
        }

        return [
            'order' => $best['order'],
            'times' => $best['times'],
            'problems' => $best['problems'],
            'before_min' => $present['travel_min'],
            'after_min' => $best['travel_min'],
        ];
    }

    /** @return array{0:float,1:float}|null */
    private static function point($lat, $lng): ?array
    {
        if ($lat === null || $lng === null || !RouteService::validCoord((float)$lat, (float)$lng)) return null;
        return [(float)$lat, (float)$lng];
    }

    /** Where a day that is left alone ends: at its hotel, otherwise at its last row with a place. */
    private static function lastPoint(array $rows, ?array $hotel): ?array
    {
        if ($hotel !== null && self::point($hotel['latitude'], $hotel['longitude']) !== null) {
            return self::point($hotel['latitude'], $hotel['longitude']);
        }
        $last = null;
        foreach ($rows as $row) {
            $last = self::point($row['latitude'], $row['longitude']) ?? $last;
        }
        return $last;
    }

    /** "09:30:00" => 570 */
    private static function minutes($time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$time, $m)) return null;
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function sqlTime(int $minutes): string
    {
        $minutes = max(0, min(23 * 60 + 59, $minutes));
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
