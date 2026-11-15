<?php
// services/ItineraryRouteOptimiser.php
// Puts the stops of a day into the order with the least travelling among the orders that keep
// every rule of PlannerRules, and times the day again with the profile's rest.
//
//   orderDay()  one day, used by ItineraryGenerator while it plans and once more after the
//               trip is measured on the road
//   optimise()  every day of a saved itinerary (the "Optimise route" button), done by
//               ItineraryEditService::reorderTrip() so a stored day is written one way only
//
// When the road figures make a stop impossible in every order, that stop is dropped; no time
// that breaks a rule is ever stored, and no time is clamped. Among orders that cannot keep
// every stop, the one keeping the most must-see places wins. On the last day of a trip with an
// end point, the order finishes towards it and every stop leaves time and distance to reach it;
// on a day followed by a night the same holds for its base or hotel (BASE), and the day closes
// with the way back and, when still due, dinner near the base (ItineraryDayBuilder::fit() with
// the base); when that end does not fit, the last stop gives way.
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
    /** Seconds the one table request of optimise() may take before the estimates are used. */
    public const ROUTE_TIMEOUT = 8;

    /**
     * The best order of one day's stops.
     *
     * @param array{0:float,1:float} $start where the day begins
     * @param array<int,array>       $stops each with 'place' (a place row) and 'point' [lat, lng]
     * @param array $day date, first_leg_exempt (true on day 1 and on the day the trip moves to
     *                   another area: the first leg is not counted by the distance limits),
     *                   budget (null, or items and km of the rest of the trip plus reserve_km),
     *                   end (the base or hotel of the night after the day, or null), night (true:
     *                   the day ends at end, a rule (BASE), and closes with the way back and
     *                   dinner; false or missing with an end: an older itinerary's hotel, only a
     *                   cost), finish (the trip's end point on the last day, or null; ignored when
     *                   end is given), meal_pool (the food places meals may use), used (place_id
     *                   => true for places of the other days, which no meal may use)
     * @return array{stops:array,dropped:array,minutes:int,km:float,close:?array}
     *         stops: the kept stops in order, each with start, end, km, min (its leg), wait and
     *         the meals before it (ItineraryDayBuilder::fit());
     *         dropped: stops no order can keep, each with the reason code of its failure;
     *         minutes and km: the travelling of the kept order (including the legs to meals
     *         and to the hotel or base); close: on a night day, the end of the day (fit() with
     *         the base: arrive, km and min of the leg in, meals: the dinner near the base)
     */
    public static function orderDay(RouteService $route, array $profile, array $start, array $stops, array $day): array
    {
        $stops = array_values($stops);
        $n = count($stops);
        if ($n === 0) return ['stops' => [], 'dropped' => [], 'minutes' => 0, 'km' => 0.0, 'close' => null];

        [$end, $day] = self::endOf($day);
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
     * Time one day's stops in the given order, exactly as orderDay() times each order it tries
     * (the same schedule() and so ItineraryDayBuilder::fit()). Used by ItineraryEditService,
     * which keeps the order the traveller asked for.
     *
     * @param array $day as for orderDay(), plus meal_choice (slot => choice, see
     *                   ItineraryDayBuilder::fit())
     * @return array{stops:array,dropped:array,minutes:int,km:float} as orderDay()
     */
    public static function timeInOrder(RouteService $route, array $profile, array $start, array $stops, array $day): array
    {
        $stops = array_values($stops);
        if (!$stops) return ['stops' => [], 'dropped' => [], 'minutes' => 0, 'km' => 0.0, 'close' => null];
        [$end, $day] = self::endOf($day);
        $seg = static fn(array $a, array $b): array => $route->getSegment((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]);
        $fromStart = [];
        $between = [];
        $toEnd = [];
        $n = count($stops);
        foreach ($stops as $j => $stop) {
            $fromStart[$j] = $seg($start, $stop['point']);
            $toEnd[$j] = $end !== null ? $seg($stop['point'], $end) : ['distance_km' => 0.0, 'travel_time_min' => 0];
            if ($j > 0) $between[$j - 1][$j] = $seg($stops[$j - 1]['point'], $stop['point']);
        }
        // A stop left out is followed by a leg from the stop before it.
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) $between[$i][$j] = $seg($stops[$i]['point'], $stops[$j]['point']);
        }
        $day['start'] = $start;
        $mealIndex = ItineraryDayBuilder::mealIndex((array)($day['meal_pool'] ?? []), (float)$profile['meal_radius_km']);
        return self::schedule($route, $mealIndex, range(0, $n - 1), $stops, $profile, $day, $fromStart, $between, $toEnd);
    }

    /**
     * Reorder and re-time every day of a saved itinerary, then store the legs and the total
     * cost again (the "Optimise route" button). Call it inside the caller's transaction
     * (ItineraryEditService::optimise() runs it in one of its own). The work is done by
     * ItineraryEditService::reorderTrip(), so a stored day is written in one way only: one table
     * request for the trip, every day ordered by orderDay() from where it starts to its base or
     * hotel, meals and the hotel time placed again.
     *
     * @return array{days:array,reordered_days:int[],saved_min:int,removed:string[]}
     */
    public static function optimise(mysqli $conn, int $itineraryId): array
    {
        return ItineraryEditService::reorderTrip($conn, $itineraryId);
    }

    /**
     * Where a day ends and whether that is a rule: on a day followed by a night (night) at the
     * base or hotel of the night (end), which every stop must leave time and distance to reach
     * (BASE) and where the day closes with the way back and dinner (fit() with the base); on the
     * last day at the trip's end point (finish, FINISH); a hotel of an older itinerary without
     * bases is only a cost.
     *
     * @return array{0:?array,1:array} the end point and the day with finish_counts and finish_code
     */
    private static function endOf(array $day): array
    {
        $end = $day['end'] ?? null;
        if ($end !== null && !empty($day['night'])) {
            $day['finish_counts'] = true;
            $day['finish_code'] = 'BASE';
        } elseif ($end === null && isset($day['finish'])) {
            $end = $day['finish'];
            $day['finish_counts'] = true;   // the leg to the end point is a rule, not only a cost
            $day['finish_code'] = 'FINISH';
            $day['night'] = false;
        } else {
            $day['night'] = false;
        }
        return [$end, $day];
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
                'meal_choice' => (array)($day['meal_choice'] ?? []),
                'finish' => !empty($day['finish_counts']) ? [(float)$toEnd[$index]['distance_km'], (int)$toEnd[$index]['travel_time_min']] : null,
                'finish_code' => (string)($day['finish_code'] ?? 'FINISH'),
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
        $close = null;
        if ($last !== null && !empty($day['night']) && isset($day['end'])) {
            // The end of the day: back to the base, with dinner when still due. When it does not
            // fit, the last kept stop gives way and the order is timed again without it.
            $close = ItineraryDayBuilder::fit($route, $profile, $mealIndex, ItineraryDayBuilder::basePlace($day['end']), [
                'date' => (string)$day['date'],
                'point' => $point,
                'clock' => $clock,
                'prev_end' => $prevEnd,
                'meals' => $mealsHad,
                'day_km' => $dayKm,
                'meal_used' => $mealUsed,
                'meal_choice' => (array)($day['meal_choice'] ?? []),
                'finish_code' => 'BASE',
            ], $toEnd[$last]);
            if (!$close['ok']) {
                $shorter = array_values(array_filter($order, static fn(int $i): bool => $i !== $last));
                $again = self::schedule($route, $mealIndex, $shorter, $stops, $profile, $day, $fromStart, $between, $toEnd);
                $again['dropped'][] = $stops[$last] + ['reason' => $close['reason']];
                return $again;
            }
            $minutes += (int)$close['min'] + (int)$close['meal_min'];
            $keptKm += (float)$close['km'] + (float)$close['meal_km'];
        } elseif ($last !== null) {
            $minutes += (int)$toEnd[$last]['travel_time_min'];
            $keptKm += (float)$toEnd[$last]['distance_km'];
        }
        return ['stops' => $kept, 'dropped' => $dropped, 'minutes' => $minutes, 'km' => round($keptKm, 2), 'close' => $close];
    }
}
