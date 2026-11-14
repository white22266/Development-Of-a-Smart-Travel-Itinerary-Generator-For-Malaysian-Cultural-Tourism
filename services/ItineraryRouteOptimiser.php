<?php
// services/ItineraryRouteOptimiser.php
// Puts the stops of a day into the order with the least travelling among the orders that keep
// every rule of PlannerRules, and times the day again with the profile's rest.
//
//   orderDay()  one day, used by ItineraryGenerator while it plans and once more after the
//               trip is measured on the road
//   optimise()  every day of a saved itinerary (the "Optimise route" button)
//
// When the road figures make a stop impossible in every order, that stop is dropped; no time
// that breaks a rule is ever stored, and no time is clamped. Among orders that cannot keep
// every stop, the one keeping the most must-see places wins. On the last day of a trip with an
// end point, the order finishes towards it and every stop leaves time and distance to reach it.
// Every order is timed with the day's lunch and dinner (ItineraryDayBuilder::fit()); meals are
// not stops to be reordered: they are placed again for the chosen order.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/ItineraryEditService.php';
require_once __DIR__ . '/ItineraryHotelService.php';
require_once __DIR__ . '/ItineraryDayBuilder.php';
require_once __DIR__ . '/PlannerCandidates.php';

final class ItineraryRouteOptimiser
{
    /** Up to this many stops every order is compared. */
    private const MAX_EXACT_STOPS = 8;
    /** Beyond that, how many of RouteService's good orders are checked. */
    private const ORDERS_TO_CHECK = 720;

    /**
     * The best order of one day's stops.
     *
     * @param array{0:float,1:float} $start where the day begins
     * @param array<int,array>       $stops each with 'place' (a place row) and 'point' [lat, lng]
     * @param array $day date, first_leg_exempt (true on day 1: the leg from the trip's starting
     *                   point is not counted by the distance limits), budget (null, or items and
     *                   km of the rest of the trip plus reserve_km), end (the hotel point or null),
     *                   finish (the trip's end point on the last day, or null; ignored when end
     *                   is given), meal_pool (the food places meals may use), used (place_id =>
     *                   true for places of the other days, which no meal may use)
     * @return array{stops:array,dropped:array,minutes:int,km:float}
     *         stops: the kept stops in order, each with start, end, km, min (its leg), wait and
     *         the meals before it (ItineraryDayBuilder::fit());
     *         dropped: stops no order can keep, each with the reason code of its failure;
     *         minutes and km: the travelling of the kept order (including the legs to meals
     *         and to the hotel)
     */
    public static function orderDay(RouteService $route, array $profile, array $start, array $stops, array $day): array
    {
        $stops = array_values($stops);
        $n = count($stops);
        if ($n === 0) return ['stops' => [], 'dropped' => [], 'minutes' => 0, 'km' => 0.0];

        $end = $day['end'] ?? null;
        if ($end === null && isset($day['finish'])) {
            $end = $day['finish'];
            $day['finish_counts'] = true;   // the leg to the end point is a rule, not only a cost
        }
        $seg = static fn(array $a, array $b): array => $route->getSegment((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]);
        $fromStart = [];
        $between = [];
        $toEnd = [];
        foreach ($stops as $j => $stop) {
            $fromStart[$j] = $seg($start, $stop['point']);
            $toEnd[$j] = $end !== null ? $seg($stop['point'], $end) : ['distance_km' => 0.0, 'travel_time_min' => 0];
            foreach ($stops as $i => $other) {
                if ($i !== $j) $between[$i][$j] = $seg($other['point'], $stop['point']);
            }
        }

        $best = null;
        $day['start'] = $start;
        $mealIndex = ItineraryDayBuilder::mealIndex((array)($day['meal_pool'] ?? []), (float)$profile['meal_radius_km']);
        foreach (self::candidateOrders($route, $start, $stops, $end, $fromStart, $between, $toEnd) as $order) {
            $planned = self::schedule($route, $mealIndex, $order, $stops, $profile, $day, $fromStart, $between, $toEnd);
            if (count($planned['stops']) === $n) return $planned;
            $rank = [self::mustCount($planned['stops']), count($planned['stops']), -$planned['minutes']];
            if ($best === null || $rank > [self::mustCount($best['stops']), count($best['stops']), -$best['minutes']]) {
                $best = $planned;
            }
        }
        return $best;
    }

    /**
     * Reorder and re-time every day of a saved itinerary, then store the legs and the total
     * cost again. Call it inside the caller's transaction.
     *
     * @return array{days:array,reordered_days:int[],saved_min:int,removed:string[]}
     */
    public static function optimise(mysqli $conn, int $itineraryId): array
    {
        $report = ['days' => [], 'reordered_days' => [], 'saved_min' => 0, 'removed' => []];

        $stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return $report;

        $pref = PlannerRules::loadPreference($conn, (int)$meta['preference_id']) ?? PlannerRules::normalizePreference([]);
        $origin = self::point($meta['origin_lat'], $meta['origin_lng']);
        if ($origin !== null) $pref += ['origin_lat' => $origin[0], 'origin_lng' => $origin[1]];
        $profile = PlannerRules::profile($pref);
        // Meals are planned only where they can be stored (migration 20261116_meal_slots.sql).
        $profile['meals'] = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot');
        $mealPool = $profile['meals'] ? PlannerCandidates::mealPlaces($conn, $pref, $profile, (int)($meta['traveller_id'] ?? 0)) : [];
        $finish = self::point($meta['end_lat'] ?? null, $meta['end_lng'] ?? null);
        $lastDay = (int)($meta['total_days'] ?? 0);

        $mealSelect = $profile['meals'] ? 'ii.meal_slot' : 'NULL AS meal_slot';
        $stmt = $conn->prepare("
            SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, {$mealSelect}, ii.place_id, ii.item_title, ii.start_time, ii.end_time,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude,
                   cp.state, cp.district, cp.category, cp.name, cp.description, cp.opening_hours,
                   cp.festival_start_date, cp.festival_end_date, cp.halal_status, cp.is_outdoor, cp.visit_duration_min
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $days = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $row['point'] = self::point($row['latitude'], $row['longitude']);
            $days[(int)$row['day_no']][] = $row;
        }
        $stmt->close();

        $route = new RouteService((string)$profile['transport']);
        $pointsByDay = [];
        foreach ($days as $dayNo => $rows) {
            foreach ($rows as $row) if ($row['point'] !== null) $pointsByDay[$dayNo][] = $row['point'];
        }
        if ($pointsByDay && $origin !== null) $pointsByDay[array_key_first($pointsByDay)][] = $origin;
        if ($finish !== null && isset($pointsByDay[$lastDay])) $pointsByDay[$lastDay][] = $finish;
        $route->preloadTrip($pointsByDay);

        $startDate = (string)$meta['start_date'];
        $dayStart = $origin;
        $sequence = [];   // item id => [sequence, start min, end min]
        $delete = [];
        $newMeals = [];   // [day, sequence, meal] rows to insert
        // No meal at a place visited anywhere in the trip, or kept for a meal on another day.
        $mealUsed = [];
        $oldMealPlaces = [];
        foreach ($days as $dayNo => $rows) {
            foreach ($rows as $row) {
                if ((int)$row['place_id'] <= 0) continue;
                if ($row['meal_slot'] !== null) $oldMealPlaces[$dayNo][(int)$row['place_id']] = true;
                else $mealUsed[(int)$row['place_id']] = true;
            }
        }
        foreach ($days as $dayNo => $rows) {
            $visits = [];
            $hotel = null;
            $others = [];
            $oldMeals = [];
            $movable = $dayStart !== null;
            foreach ($rows as $row) {
                $type = strtolower((string)$row['item_type']);
                // Meal rows are placed again for the new order, not reordered as stops.
                if ($row['meal_slot'] !== null) { $oldMeals[] = $row; continue; }
                if ($type === 'hotel') { $hotel = $row; continue; }
                if ($type === 'note') { $others[] = $row; continue; }
                // Transport rows and stops without a known place or point are kept as they are: so is their day.
                if ($type === 'transport' || (int)$row['place_id'] <= 0 || $row['point'] === null || $row['name'] === null) { $movable = false; continue; }
                $visits[] = $row;
            }
            if (!$movable || !$visits) {
                $dayStart = self::lastPoint($rows) ?? $dayStart;
                continue;
            }

            $date = (new DateTimeImmutable($startDate))->modify('+' . ($dayNo - 1) . ' days')->format('Y-m-d');
            $hotelPoint = $hotel['point'] ?? null;
            $dayFinish = $dayNo === $lastDay && $hotelPoint === null ? $finish : null;
            $route->preload(array_values(array_filter(array_merge([$dayStart], array_column($visits, 'point'), [$hotelPoint ?? $dayFinish]))));
            $musts = array_flip((array)($pref['must_places'] ?? []));
            $stops = array_map(static fn(array $row): array => ['place' => self::placeOf($row), 'point' => $row['point'], 'row' => $row,
                'must' => isset($musts[(int)$row['place_id']])], $visits);
            // The travelling as stored: visits and meal places in their order.
            $storedPoints = array_column(array_filter($rows, static fn(array $r): bool => $r['point'] !== null && strtolower((string)$r['item_type']) !== 'hotel'
                && ((int)$r['place_id'] > 0)), 'point');
            $before = self::travelMinutes($route, $dayStart, $storedPoints, $hotelPoint ?? $dayFinish);
            $otherMeals = [];
            foreach ($oldMealPlaces as $d => $ids) if ($d !== $dayNo) $otherMeals += $ids;
            $plan = self::orderDay($route, $profile, $dayStart, $stops, [
                'date' => $date,
                'first_leg_exempt' => $dayNo === 1 && $dayStart === $origin,
                'budget' => null,
                'end' => $hotelPoint,
                'finish' => $dayFinish,
                'meal_pool' => $mealPool,
                'used' => $mealUsed + $otherMeals,
            ]);

            $position = 0;
            $lastEnd = null;
            foreach ($oldMeals as $row) $delete[] = (int)$row['item_id'];
            foreach ($plan['stops'] as $stop) {
                foreach ($stop['meals'] ?? [] as $meal) {
                    $newMeals[] = [$dayNo, ++$position, $meal];
                    if ($meal['place'] !== null) $mealUsed[(int)$meal['place']['place_id']] = true;
                }
                $sequence[(int)$stop['row']['item_id']] = [++$position, $stop['start'], $stop['end']];
                $lastEnd = $stop['end'];
            }
            foreach ($others as $row) {
                $sequence[(int)$row['item_id']] = [++$position, PlannerRules::minutesOf($row['start_time']), PlannerRules::minutesOf($row['end_time'])];
            }
            if ($hotel !== null) {
                $checkIn = ItineraryHotelService::checkInMinute($lastEnd);
                $sequence[(int)$hotel['item_id']] = [++$position, $checkIn, $checkIn + 30];
            }
            foreach ($plan['dropped'] as $stop) {
                $delete[] = (int)$stop['row']['item_id'];
                $report['removed'][] = 'Day ' . $dayNo . ': ' . $stop['row']['item_title'] . ' (' . PlannerRules::reasonText((string)$stop['reason']) . ')';
            }

            $keptIds = array_map(static fn(array $s): int => (int)$s['row']['item_id'], $plan['stops']);
            $reordered = $keptIds !== array_map(static fn(array $r): int => (int)$r['item_id'], $visits);
            $report['days'][$dayNo] = ['stops' => count($plan['stops']), 'before_min' => $before, 'after_min' => $plan['minutes'],
                                       'reordered' => $reordered, 'problems' => count($plan['dropped'])];
            if ($reordered) {
                $report['reordered_days'][] = $dayNo;
                $report['saved_min'] += max(0, $before - $plan['minutes']);
            }
            $last = $plan['stops'] ? $plan['stops'][count($plan['stops']) - 1]['point'] : null;
            $dayStart = $hotelPoint ?? $last ?? $dayStart;
        }

        $update = $conn->prepare("UPDATE itinerary_items SET sequence_no = ?, start_time = ?, end_time = ? WHERE item_id = ? AND itinerary_id = ?");
        foreach ($sequence as $itemId => [$sequenceNo, $startMin, $endMin]) {
            $startTime = $startMin !== null ? PlannerRules::sqlTime($startMin) : null;
            $endTime = $endMin !== null ? PlannerRules::sqlTime($endMin) : null;
            $update->bind_param('issii', $sequenceNo, $startTime, $endTime, $itemId, $itineraryId);
            $update->execute();
        }
        $update->close();
        if ($newMeals) {
            $insert = $conn->prepare("
                INSERT INTO itinerary_items
                    (itinerary_id, day_no, sequence_no, item_type, meal_slot, place_id, item_title, item_latitude, item_longitude,
                     start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
                VALUES (?,?,?,'food',?,?,?,?,?,?,?,0.00,NULL,NULL,?)
            ");
            foreach ($newMeals as [$dayNo, $sequenceNo, $meal]) {
                $slot = (string)$meal['slot'];
                $placeId = $meal['place'] !== null ? (int)$meal['place']['place_id'] : null;
                $title = mb_substr(ItineraryDayBuilder::mealTitle($meal), 0, 150);
                $lat = $meal['point'][0] ?? null;
                $lng = $meal['point'][1] ?? null;
                $startTime = PlannerRules::sqlTime((int)$meal['start']);
                $endTime = PlannerRules::sqlTime((int)$meal['end']);
                $notes = ItineraryDayBuilder::mealNotes($meal, $profile);
                $insert->bind_param('iiisisddsss', $itineraryId, $dayNo, $sequenceNo, $slot, $placeId, $title, $lat, $lng, $startTime, $endTime, $notes);
                $insert->execute();
            }
            $insert->close();
        }
        if ($delete) {
            $remove = $conn->prepare("DELETE FROM itinerary_items WHERE item_id = ? AND itinerary_id = ?");
            foreach ($delete as $itemId) {
                $remove->bind_param('ii', $itemId, $itineraryId);
                $remove->execute();
            }
            $remove->close();
            ItineraryEditService::resequence($conn, $itineraryId);
        }

        self::storeLegs($conn, $itineraryId, $route, $origin);
        ItineraryEditService::total($conn, $itineraryId);
        return $report;
    }

    /** The orders to try, least travelling first (ties: less km, fewer equal categories side by side, the given order). */
    private static function candidateOrders(RouteService $route, array $start, array $stops, ?array $end, array $fromStart, array $between, array $toEnd): iterable
    {
        $n = count($stops);
        $labels = array_map(static fn(array $s): string => strtolower((string)($s['place']['category'] ?? '')), $stops);
        if ($n > self::MAX_EXACT_STOPS) {
            $points = array_column($stops, 'point');
            yield range(0, $n - 1);
            foreach ($route->bestOrders($start, $points, $end, $labels, self::ORDERS_TO_CHECK) as $candidate) yield $candidate['order'];
            return;
        }
        $ranked = [];
        foreach (self::permutations(range(0, $n - 1)) as $order) {
            $minutes = (int)$fromStart[$order[0]]['travel_time_min'];
            $km = (float)$fromStart[$order[0]]['distance_km'];
            $equal = 0;
            for ($k = 1; $k < $n; $k++) {
                $leg = $between[$order[$k - 1]][$order[$k]];
                $minutes += (int)$leg['travel_time_min'];
                $km += (float)$leg['distance_km'];
                if ($labels[$order[$k]] !== '' && $labels[$order[$k]] === $labels[$order[$k - 1]]) $equal++;
            }
            $minutes += (int)$toEnd[$order[$n - 1]]['travel_time_min'];
            $km += (float)$toEnd[$order[$n - 1]]['distance_km'];
            $ranked[] = [$minutes, round($km, 2), $equal, $order];
        }
        usort($ranked, static fn(array $a, array $b): int => $a <=> $b);
        foreach ($ranked as $entry) yield $entry[3];
    }

    private static function mustCount(array $stops): int
    {
        return count(array_filter($stops, static fn(array $s): bool => !empty($s['must'])));
    }

    /** Every order of the given keys, the given order first. */
    private static function permutations(array $items): iterable
    {
        if (count($items) <= 1) { yield $items; return; }
        foreach ($items as $index => $first) {
            $rest = $items;
            unset($rest[$index]);
            foreach (self::permutations(array_values($rest)) as $tail) yield array_merge([$first], $tail);
        }
    }

    /**
     * Time an order with the day's meals (ItineraryDayBuilder::fit(), the builder's own timing).
     * A stop that breaks a rule where it falls is left out, and the day goes on from the stop
     * before it.
     */
    private static function schedule(RouteService $route, array $mealIndex, array $order, array $stops, array $profile, array $day, array $fromStart, array $between, array $toEnd): array
    {
        $budget = $day['budget'] ?? null;
        $items = $budget['items'] ?? null;
        $otherKm = (float)($budget['km'] ?? 0);
        // No meal at a place used elsewhere in the trip or by any stop of this day.
        $mealUsed = (array)($day['used'] ?? []);
        foreach ($stops as $stop) $mealUsed[(int)$stop['place']['place_id']] = true;
        $clock = (int)$profile['start'];
        $prevEnd = null;
        $mealsHad = [];
        $last = null;
        $point = null;
        $dayKm = 0.0;
        $keptKm = 0.0;
        $minutes = 0;
        $kept = [];
        $dropped = [];
        foreach ($order as $index) {
            $stop = $stops[$index];
            $leg = $last === null ? $fromStart[$index] : $between[$last][$index];
            $counts = !($last === null && !empty($day['first_leg_exempt']));
            $fit = ItineraryDayBuilder::fit($route, $profile, $mealIndex, $stop['place'], [
                'date' => (string)$day['date'],
                'point' => $point ?? $day['start'],
                'clock' => $clock,
                'prev_end' => $prevEnd,
                'meals' => $mealsHad,
                'leg_counts' => $counts,
                'day_km' => $dayKm,
                'trip_items' => $items,
                'trip_km' => $otherKm + $keptKm,
                'reserve_km' => (float)($budget['reserve_km'] ?? 0),
                'meal_used' => $mealUsed,
                'finish' => !empty($day['finish_counts']) ? [(float)$toEnd[$index]['distance_km'], (int)$toEnd[$index]['travel_time_min']] : null,
            ], $leg);
            if (!$fit['ok']) {
                $dropped[] = $stop + ['reason' => $fit['reason']];
                continue;
            }
            $stop['start'] = $fit['start'];
            $stop['end'] = $fit['end'];
            $stop['wait'] = $fit['wait'];
            $stop['km'] = $fit['km'];
            $stop['min'] = $fit['min'];
            $stop['meals'] = $fit['meals'];
            $stop['meal_km'] = $fit['meal_km'];
            $stop['meal_min'] = $fit['meal_min'];
            $stop['food_meal'] = $fit['food_meal'];
            $kept[] = $stop;
            foreach ($fit['meals'] as $meal) {
                $mealsHad[$meal['slot']] = true;
                if ($meal['place'] !== null) $mealUsed[(int)$meal['place']['place_id']] = true;
            }
            if ($fit['food_meal'] !== null) $mealsHad[$fit['food_meal']] = true;
            if ($items !== null) $items[] = ['item_type' => PlannerRules::itemType($stop['place']), 'estimated_cost' => PlannerRules::placeCost($stop['place'])];
            $dayKm += ($counts ? $fit['km'] : 0.0) + $fit['meal_km'];
            $keptKm += $fit['km'] + $fit['meal_km'];
            $minutes += $fit['min'] + $fit['meal_min'];
            $clock = $fit['end'] + (int)$profile['rest'];
            $prevEnd = $fit['end'];
            $last = $index;
            $point = $stop['point'];
        }
        if ($last !== null) {
            $minutes += (int)$toEnd[$last]['travel_time_min'];
            $keptKm += (float)$toEnd[$last]['distance_km'];
        }
        return ['stops' => $kept, 'dropped' => $dropped, 'minutes' => $minutes, 'km' => round($keptKm, 2)];
    }

    /** The place row of an itinerary item, as PlannerRules reads it. */
    private static function placeOf(array $row): array
    {
        return [
            'place_id' => (int)$row['place_id'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'state' => (string)($row['state'] ?? ''),
            'district' => (string)($row['district'] ?? ''),
            'category' => strtolower((string)($row['category'] ?? $row['item_type'])),
            'latitude' => (float)$row['point'][0],
            'longitude' => (float)$row['point'][1],
            'opening_hours' => (string)($row['opening_hours'] ?? ''),
            'festival_start_date' => $row['festival_start_date'],
            'festival_end_date' => $row['festival_end_date'],
            'halal_status' => $row['halal_status'],
            'is_outdoor' => $row['is_outdoor'],
            'visit_duration_min' => $row['visit_duration_min'],
        ];
    }

    /** Store distance and travel time of every leg in the stored order, with the figures the times were planned with. */
    private static function storeLegs(mysqli $conn, int $itineraryId, RouteService $route, ?array $origin): void
    {
        $stmt = $conn->prepare("
            SELECT ii.item_id, COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $update = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?");
        $previous = $origin;
        foreach ($rows as $row) {
            $here = self::point($row['latitude'], $row['longitude']);
            $km = null;
            $minutes = null;
            if ($previous !== null && $here !== null) {
                $leg = $route->getSegment($previous[0], $previous[1], $here[0], $here[1]);
                $km = round((float)$leg['distance_km'], 2);
                $minutes = (int)$leg['travel_time_min'];
            }
            $itemId = (int)$row['item_id'];
            $update->bind_param('dii', $km, $minutes, $itemId);
            $update->execute();
            if ($here !== null) $previous = $here;
        }
        $update->close();
    }

    private static function travelMinutes(RouteService $route, array $start, array $points, ?array $end): int
    {
        $minutes = 0;
        $from = $start;
        foreach (array_merge($points, $end !== null ? [$end] : []) as $to) {
            $minutes += (int)$route->getSegment($from[0], $from[1], $to[0], $to[1])['travel_time_min'];
            $from = $to;
        }
        return $minutes;
    }

    /** @return array{0:float,1:float}|null */
    private static function point($lat, $lng): ?array
    {
        if ($lat === null || $lng === null || !RouteService::validCoord((float)$lat, (float)$lng)) return null;
        return [(float)$lat, (float)$lng];
    }

    /** Where a day that is left alone ends: at its last row with a point (the hotel when it has one). */
    private static function lastPoint(array $rows): ?array
    {
        $last = null;
        foreach ($rows as $row) $last = $row['point'] ?? $last;
        return $last;
    }
}
