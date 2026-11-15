<?php
// services/ItineraryGenerator.php
// The single entry point that turns a saved preference into a stored itinerary. Official
// itineraries come from deterministic rules and database records; AI may explain or suggest,
// but never generates the saved plan.
//
//   1. PlannerRules builds the profile (with what the traveller's own ratings teach);
//      PlannerCandidates loads the places. Places to avoid, and places the traveller rated
//      1 or 2, are never candidates. The twin groups of the candidates (one landmark under two
//      names, PlannerRules::sameLandmarks()) are worked out once: a trip holds one row of each,
//      a must-see place keeps its twins out, and so does a place to avoid.
//   2. Must-see places: the days each can be visited on under every hard rule that does not
//      depend on the other stops, and all of them counted in the budget before any other stop.
//   3. TripLayout lays the trip out: the base of every night (the starting point with
//      stay_at_start, otherwise the bases of the areas the trip tours), the area of every day
//      and the group of places each day draws from first. A must-see place goes to the day of
//      its group.
//   4. Day by day, ItineraryDayBuilder::search() finds the best day (estimated travel figures)
//      and ItineraryRouteOptimiser puts it in the order with the least travelling. Day d + 1
//      starts at the base of night d; a day followed by a night ends at its base (BASE: the
//      leg back counts in the day's distance, the traveller is back by the end of the day
//      window, and the day has a dinner, near the base when not on the way). The first leg of
//      day 1, and of the day the trip moves to another area, is not counted by the distance
//      limits. With an end point, the last day finishes towards it.
//   5. When a routing server answers, the trip is measured on the road with one table request
//      and every day is ordered and timed again with the road figures. A stop no order can
//      keep is dropped and the search's next places (spares) are tried in its place, measured
//      with one route request.
//   6. Everything is stored (the base and group of each day in itinerary_days), and the total
//      written by ItineraryEditService::total().
//
// Every day is timed with its lunch and dinner (ItineraryDayBuilder::fit()). A meal is stored
// as an itinerary row of item_type 'food' with meal_slot 'lunch' or 'dinner': at a food place
// (place_id, coordinates and the leg to it) or as a break without a place (no place, no
// coordinates, no leg). Meal rows cost 0.00: the cost model already reserves three meals a day
// (CostEstimationService::calculate()), so a meal is never counted twice.
// The chance of rain is asked once per trip, for the starting point (WeatherService), unless
// the trip options carry a forecast override or ROUTING_ESTIMATES_ONLY is set; the layout gives
// rainy dates the groups with more indoor places and the day search prefers indoor places on
// a wet day. Without a forecast nothing changes.
//
// All of it runs in one transaction: a failure saves nothing. A day for which nothing is
// feasible is stored with a short note row, and so is a must-see place that could not be
// scheduled; generation still succeeds.

require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/PlannerCandidates.php';
require_once __DIR__ . '/ItineraryDayBuilder.php';
require_once __DIR__ . '/ItineraryRouteOptimiser.php';
require_once __DIR__ . '/ItineraryEditService.php';
require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/WeatherService.php';
require_once __DIR__ . '/TripLayout.php';

final class ItineraryGenerator
{
    /** Seconds the trip's one table request may take before the plan is stored on estimates. */
    private const ROUTE_TIMEOUT = 8;
    /**
     * A trip with more points of its own than this (start, stops, bases and end point) is measured
     * with route requests along its planned legs instead of one table: a table of every pair of
     * them (and of the meal places that fill it up to RouteService's 50 points) was too slow for
     * the public server (the 5-day Penang and Kuala Lumpur trip waited 8 seconds for it).
     */
    private const LONG_TRIP_POINTS = 20;
    /** Probe step through the day window when looking for a day a must-see place can be visited. */
    private const MUST_PROBE_STEP_MIN = 60;
    /** The search's next places measured and tried for each stop dropped on the road figures (replace()). */
    private const REPLACE_CANDIDATES = 3;
    /** Words for a must-see place that no day of the trip allows. */
    private const MUST_REASONS = [
        'FESTIVAL_DAY' => 'the festival is not on during the trip',
        'CLOSED' => 'closed on the days of the trip',
    ];

    private mysqli $conn;
    /** place_id => ['name', 'reason', 'day'] for must-see places not (yet) scheduled. */
    private array $mustMissed = [];
    /** The food places meals may use, and the chance of rain per date (Y-m-d => percent). */
    private array $mealPool = [];
    private array $rain = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * @param array{start_date:string,origin_name:string,origin_lat:float,origin_lng:float,
     *              end_name?:string,end_lat?:float,end_lng?:float,stay_at_start?:bool,
     *              forecast?:array<string,int>} $trip
     *        the end fields are optional: without them the trip ends where its last stop is;
     *        stay_at_start (optional): the traveller sleeps at the starting point every night;
     *        forecast (optional, for checks and tests) replaces the weather forecast: date
     *        (Y-m-d) => chance of rain 0 to 100
     * @return int the new itinerary_id
     * @throws RuntimeException with a message for the traveller when the input is not usable
     */
    public function generate(int $travellerId, int $preferenceId, array $trip): int
    {
        $startDate = trim((string)($trip['start_date'] ?? ''));
        $parsed = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$parsed || $parsed->format('Y-m-d') !== $startDate) {
            throw new RuntimeException('Please confirm a valid Start Date before generating the itinerary.');
        }
        $originName = trim((string)($trip['origin_name'] ?? ''));
        $origin = [(float)($trip['origin_lat'] ?? 0), (float)($trip['origin_lng'] ?? 0)];
        if ($originName === '' || !RouteService::validCoord($origin[0], $origin[1])) {
            throw new RuntimeException('Please confirm a Starting Location with map coordinates before generating the itinerary.');
        }
        $endName = trim((string)($trip['end_name'] ?? ''));
        $finish = null;
        if ($endName !== '' || !empty($trip['end_lat']) || !empty($trip['end_lng'])) {
            $finish = [(float)($trip['end_lat'] ?? 0), (float)($trip['end_lng'] ?? 0)];
            if ($endName === '' || !RouteService::validCoord($finish[0], $finish[1])) {
                throw new RuntimeException('Please confirm an End Location with map coordinates, or choose to end the trip at the starting point.');
            }
        }
        $stayAtStart = !empty($trip['stay_at_start']);
        $this->mustMissed = [];

        $this->conn->begin_transaction();
        try {
            $pref = PlannerRules::loadPreference($this->conn, $preferenceId, $travellerId);
            if ($pref === null) throw new RuntimeException('Selected preference was not found.');
            $ratings = PlannerCandidates::travellerRatings($this->conn, $travellerId, PlannerRules::LEARN_DROP_AT);
            $profile = PlannerRules::profile($pref + [
                'origin_lat' => $origin[0],
                'origin_lng' => $origin[1],
                'learned' => PlannerRules::learnedPoints($ratings['categories']),
            ]);

            $loaded = PlannerCandidates::load($this->conn, $pref);
            if (!$loaded) throw new RuntimeException('No active cultural places are available inside the selected state boundary.');
            $mustIds = (array)$pref['must_places'];
            $profile['twins'] = PlannerCandidates::twinGroups($loaded);
            // Avoided places, must-see places (planned on their own) and places the traveller
            // rated low are not candidates; a must-see place stays wanted even if rated low.
            // The twins of an avoided or a must-see place go with it: the must-see place wins.
            $skip = PlannerRules::withTwins($profile, (array)$pref['avoid_places']) + PlannerRules::withTwins($profile, $mustIds) + $ratings['low'];
            $pool = array_values(array_filter($loaded, static fn(array $place): bool =>
                !isset($skip[(int)$place['place_id']]) && PlannerRules::placeReason($place, $profile) === null));
            // Meals are planned only where they can be stored (migration 20261116_meal_slots.sql).
            $profile['meals'] = PlannerRules::hasColumn($this->conn, 'itinerary_items', 'meal_slot');
            $this->mealPool = $profile['meals'] ? PlannerCandidates::mealPool($pool) : [];
            $this->rain = self::forecast($trip, $origin, $startDate, (int)$profile['days']);

            $route = new RouteService((string)$profile['transport']);
            $route->setTimeout(self::ROUTE_TIMEOUT);
            $builder = new ItineraryDayBuilder($profile, $route, $this->mealPool);
            $dates = self::dates($startDate, (int)$profile['days']);
            // The holidays the admin keeps, for the office-hours rule, the peak hours and the day notes.
            PlannerRules::loadCalendar($this->conn, $dates);
            $musts = $this->mustCandidates($mustIds, $profile, $dates);
            $mustInput = [];
            foreach ($musts['places'] as $id => $place) $mustInput[$id] = ['place' => $place, 'feasible' => $musts['feasible'][$id]];
            $layout = TripLayout::plan($pool, $mustInput, $profile, static fn(array $place): float => $builder->layoutValue($place), $route, [
                    'origin' => $origin, 'origin_name' => $originName, 'finish' => $finish, 'dates' => $dates,
                    'rain' => $this->rain, 'stay_at_start' => $stayAtStart,
                ]);
            $pending = $this->mustDays($musts, $layout, $profile);
            $days = $this->planDays($pool, $profile, $route, $builder, $dates, $origin, $finish, $layout['days'], $musts, $pending);
            $days = $this->measureOnRoad($days, $profile, $route, $builder);
            $this->settleMusts($days, $musts);

            $itineraryId = $this->store($travellerId, $preferenceId, $pref, $profile, $startDate, $originName, $origin, $endName, $finish, $stayAtStart, $days);
            ItineraryEditService::total($this->conn, $itineraryId);

            $this->conn->commit();
            return $itineraryId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /** day => Y-m-d of a trip. */
    private static function dates(string $startDate, int $days): array
    {
        $out = [];
        for ($d = 1; $d <= $days; $d++) $out[$d] = (new DateTimeImmutable($startDate))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
        return $out;
    }

    /**
     * The must-see places that can be planned: each with the days where a visit fits every rule
     * that does not depend on the other stops (state, region, festival dates, opening hours,
     * day window, accessibility), and only while all of them together keep the budget (the
     * places with the fewest possible days first). The others are noted with their reason.
     *
     * @return array{feasible:array<int,int[]>,places:array<int,array>}
     */
    private function mustCandidates(array $mustIds, array $profile, array $dates): array
    {
        $out = ['feasible' => [], 'places' => []];
        if (!$mustIds) return $out;
        $rows = [];
        foreach (PlannerCandidates::byIds($this->conn, $mustIds) as $row) $rows[(int)$row['place_id']] = $row;
        $names = $this->placeNames($mustIds);
        foreach ($mustIds as $id) {
            $name = $names[$id] ?? ('Place #' . $id);
            if (!isset($rows[$id])) { $this->mustMissed[$id] = ['name' => $name, 'reason' => 'UNAVAILABLE', 'day' => 1]; continue; }
            $place = $rows[$id];
            $reason = PlannerRules::placeReason($place, $profile);
            if ($reason !== null) { $this->mustMissed[$id] = ['name' => $name, 'reason' => $reason, 'day' => 1]; continue; }
            // Two must-see places that are one landmark: the first on the list is planned.
            $twin = PlannerRules::twinIn($profile, $id, $out['places']);
            if ($twin !== null) {
                $this->mustMissed[$id] = ['name' => $name, 'reason' => 'SAME_LANDMARK', 'day' => 1, 'twin' => PlannerRules::twinName($profile, $id, $twin)];
                continue;
            }
            $feasible = [];
            $reasons = [];
            foreach ($dates as $d => $date) {
                $why = $this->dayReason($place, $profile, $date);
                if ($why === null) $feasible[] = $d;
                else $reasons[$why] = ($reasons[$why] ?? 0) + 1;
            }
            if (!$feasible) {
                arsort($reasons);
                $this->mustMissed[$id] = ['name' => $name, 'reason' => (string)array_key_first($reasons), 'day' => 1];
                continue;
            }
            $out['feasible'][$id] = $feasible;
            $out['places'][$id] = $place;
        }
        $order = array_keys($out['places']);
        usort($order, static fn(int $a, int $b): int => [count($out['feasible'][$a]), $a] <=> [count($out['feasible'][$b]), $b]);
        $items = [];
        $reserveKm = count($dates) * (float)$profile['day_km'];
        foreach ($order as $id) {
            $place = $out['places'][$id];
            $item = ['item_type' => PlannerRules::itemType($place), 'estimated_cost' => PlannerRules::placeCost($place)];
            if (!PlannerRules::budgetAllows($profile, array_merge($items, [$item]), $reserveKm, PlannerRules::placeCost($place))) {
                $this->mustMissed[$id] = ['name' => (string)$place['name'], 'reason' => 'BUDGET', 'day' => $out['feasible'][$id][0]];
                unset($out['places'][$id]);
                continue;
            }
            $items[] = $item;
        }
        return $out;
    }

    /**
     * The day of each must-see place: the day of its group (TripLayout), else another day of its
     * area, else any day, always a day it can be visited on and with room (at most the target
     * number of stops). Places with the fewest possible days go first.
     *
     * @return array<int,array> day => must-see place rows
     */
    private function mustDays(array $musts, array $layout, array $profile): array
    {
        $order = array_keys($musts['places']);
        usort($order, static fn(int $a, int $b): int => [count($musts['feasible'][$a]), $a] <=> [count($musts['feasible'][$b]), $b]);
        $pending = [];
        foreach ($order as $id) {
            $feasible = $musts['feasible'][$id];
            $own = $layout['must_days'][$id] ?? null;
            $region = $own !== null ? $layout['days'][$own]['region'] : null;
            $choices = array_merge(
                $own !== null && in_array($own, $feasible, true) ? [$own] : [],
                array_values(array_filter($feasible, static fn(int $d): bool => $region !== null && $layout['days'][$d]['region'] === $region)),
                $feasible
            );
            $day = null;
            foreach ($choices as $d) {
                if (count($pending[$d] ?? []) < (int)$profile['target']) { $day = $d; break; }
            }
            if ($day === null) {
                $this->mustMissed[$id] = ['name' => (string)$musts['places'][$id]['name'], 'reason' => 'ROOM', 'day' => $feasible[0]];
                continue;
            }
            $pending[$day][] = $musts['places'][$id];
        }
        return $pending;
    }

    /** Why a place cannot be visited at any time of a day on its own (null: it can). */
    private function dayReason(array $place, array $profile, string $date): ?string
    {
        $first = null;
        for ($arrive = (int)$profile['start']; $arrive < (int)$profile['end']; $arrive += self::MUST_PROBE_STEP_MIN) {
            $check = PlannerRules::check($place, ['date' => $date, 'arrive' => $arrive, 'leg_km' => 0.0, 'leg_counts' => false, 'day_km' => 0.0], $profile);
            if ($check['ok']) return null;
            if ($first === null || $first === 'WAIT') $first = (string)$check['reason'];
        }
        return $first ?? 'DAY_END';
    }

    /** Names of places by id, also of places that are no longer active. */
    private function placeNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($x) => $x > 0));
        if (!$ids) return [];
        $names = [];
        $res = $this->conn->query('SELECT place_id, name FROM cultural_places WHERE place_id IN (' . implode(',', $ids) . ')');
        if ($res) {
            foreach ($res as $row) $names[(int)$row['place_id']] = (string)$row['name'];
        }
        return $names;
    }

    /** Plan and order every day with estimated travel figures. */
    private function planDays(array $pool, array $profile, RouteService $route, ItineraryDayBuilder $builder, array $dates, array $origin, ?array $finish,
                              array $layout, array $musts, array $pending): array
    {
        $total = (int)$profile['days'];
        $days = [];
        $theme = null;
        for ($dayNo = 1; $dayNo <= $total; $dayNo++) {
            // Must-see places of the days still to come keep their share of the budget.
            $later = [];
            foreach ($pending as $d => $places) {
                if ($d <= $dayNo) continue;
                foreach ($places as $place) $later[] = ['item_type' => PlannerRules::itemType($place), 'estimated_cost' => PlannerRules::placeCost($place)];
            }
            $context = $this->dayContext($days, $dayNo, $dates[$dayNo], $profile, ($total - $dayNo) * (float)$profile['day_km'], $later)
                + self::where($layout, $dayNo, $total, $origin, $finish);
            $context['previous_theme'] = $theme;
            $context['musts'] = $pending[$dayNo] ?? [];
            $built = $builder->search($pool, $context);
            $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $context['start'], $built['stops'], self::frame($context));
            // The search's day keeps every rule in its own order, so ordering never loses a stop here.
            $days[$dayNo] = $context + ['stops' => $plan['stops'], 'close' => $plan['close'], 'rejections' => $built['rejections'], 'spares' => $built['spares']];

            // A must-see place that found no slot moves to a later day where it can be visited.
            foreach ($built['unplaced'] as $id => $reason) {
                $place = $musts['places'][$id];
                $moved = false;
                foreach ($musts['feasible'][$id] as $d) {
                    if ($d > $dayNo && count($pending[$d] ?? []) < (int)$profile['target']) {
                        $pending[$d][] = $place;
                        $moved = true;
                        break;
                    }
                }
                if (!$moved) $this->mustMissed[$id] = ['name' => (string)$place['name'], 'reason' => $reason, 'day' => $dayNo];
            }
            if ($plan['stops']) $theme = self::mainCategory($plan['stops']);
        }
        return $days;
    }

    /**
     * Where a day starts and ends, from the layout: day 1 at the starting point, a later day at
     * the base of the night before; a day followed by a night ends at its own base (a rule:
     * BASE), the last day at the trip's end point when it has one (FINISH). The first leg is not
     * counted on day 1 and on the day the trip moves to another area.
     */
    private static function where(array $layout, int $dayNo, int $total, array $origin, ?array $finish): array
    {
        $here = $layout[$dayNo];
        $base = [(float)$here['base']['lat'], (float)$here['base']['lng']];
        $night = $dayNo < $total;
        $start = $dayNo === 1 ? $origin : [(float)$layout[$dayNo - 1]['base']['lat'], (float)$layout[$dayNo - 1]['base']['lng']];
        return [
            'start' => $start,
            'first_leg_exempt' => $dayNo === 1 || $layout[$dayNo - 1]['region'] !== $here['region'],
            'layout' => $here,
            'base' => $base,
            'group' => $here['group'],
            'night' => $night,
            'end' => $night ? $base : null,
            'trip_finish' => $dayNo === $total ? $finish : null,
            // For the day search: where the day must end in time.
            'finish' => $night ? $base : ($dayNo === $total ? $finish : null),
            'finish_code' => $night ? 'BASE' : 'FINISH',
        ];
    }

    /** What ItineraryRouteOptimiser::orderDay() reads about a day. */
    private static function frame(array $day): array
    {
        return [
            'date' => $day['date'],
            'first_leg_exempt' => $day['first_leg_exempt'],
            'budget' => $day['budget'],
            'end' => $day['end'],
            'night' => $day['night'],
            'finish' => $day['trip_finish'],
            'meal_pool' => $day['meal_pool'],
            'used' => $day['used'],
        ];
    }

    /**
     * Measure the planned trip on the road and order and time each day again with those
     * figures. Without an answer from the routing server the estimates stay.
     */
    private function measureOnRoad(array $days, array $profile, RouteService $route, ItineraryDayBuilder $builder): array
    {
        $pointsByDay = [];
        $near = [];
        foreach ($days as $dayNo => $day) {
            $pointsByDay[$dayNo] = array_merge([$day['start']], array_column($day['stops'], 'point'));
            if ($day['end'] !== null) $pointsByDay[$dayNo][] = $day['end'];
            if ($day['trip_finish'] !== null) $pointsByDay[$dayNo][] = $day['trip_finish'];
            array_push($near, ...self::mealPoints($day));
        }
        $own = [];
        foreach ($pointsByDay as $points) {
            foreach ($points as $p) $own[round((float)$p[0], 5) . ',' . round((float)$p[1], 5)] = true;
        }
        // A long trip (more than LONG_TRIP_POINTS points of its own) is too big for a quick
        // table: its planned legs (each day's chain of stops, meal places and the way to its
        // base or end point) are measured with route requests instead, and each day is timed in
        // its planned order on them (an order the estimates found best).
        $inOrder = count($own) > self::LONG_TRIP_POINTS;
        if ($inOrder) {
            $route->measureLegs(self::plannedLegs($days));
        } else {
            // One table request for the whole trip: the stops, the bases and, while there is
            // room, the meal places planned so far and the other meal places near the stops and
            // bases (those left out are estimated). A second table request would be held back by
            // the public server for about ten seconds.
            foreach ($days as $day) {
                $points = array_column($day['stops'], 'point');
                if ($day['end'] !== null) $points[] = $day['end'];
                array_push($near, ...$this->nearMealPoints($points, (float)$profile['meal_radius_km']));
            }
            $route->preloadTrip($pointsByDay, $near);
        }

        // Every day is ordered again on the road figures. With the same figures as in planning,
        // no stop is lost here.
        foreach (array_keys($days) as $dayNo) {
            $day = $days[$dayNo];
            if ($day['stops']) {
                // Meals may use no place of another day, as planned now.
                $day['used'] = $this->usedPlaces($days, $dayNo);
                $outside = $this->budgetOutside($days, $dayNo);
                $day['trip_items'] = $outside['items'];
                $day['trip_km'] = $outside['km'];
                $day['reserve_km'] = 0.0;
                $day['budget'] = $outside + ['reserve_km' => 0.0];
                $plan = $inOrder ? ItineraryRouteOptimiser::timeInOrder($route, $profile, $day['start'], $day['stops'], self::frame($day)) : null;
                if ($plan === null || $plan['dropped']) $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $day['start'], $day['stops'], self::frame($day));
                if ($plan['dropped']) {
                    foreach ($plan['dropped'] as $stop) {
                        if (!empty($stop['must'])) {
                            $this->mustMissed[(int)$stop['place']['place_id']] = ['name' => (string)$stop['place']['name'], 'reason' => (string)$stop['reason'], 'day' => $dayNo];
                        }
                    }
                    $plan = $this->replace($plan, $profile, $route, $builder, $days, $day);
                }
                $day['stops'] = $plan['stops'];
                $day['close'] = $plan['close'];
            }
            $days[$dayNo] = $day;
        }
        return $days;
    }

    /**
     * After stops were dropped on the road figures: as many of the search's next places
     * (spares) as stops were dropped take their place. The best REPLACE_CANDIDATES per dropped
     * stop are measured together in one route request (not a table request, which the server
     * would hold back after the trip's table). Each is tried in every order (orderDay()) and kept
     * only when the day then keeps all its stops; a candidate that does not fit gives way to
     * the next.
     */
    private function replace(array $plan, array $profile, RouteService $route, ItineraryDayBuilder $builder, array $days, array $context): array
    {
        $used = $this->usedPlaces($days);
        foreach ($plan['dropped'] as $stop) $used[(int)$stop['place']['place_id']] = true;
        $end = $context['end'] ?? $context['trip_finish'];
        $spares = array_values(array_filter((array)$context['spares'], static fn(array $s): bool =>
            !isset($used[(int)$s['place']['place_id']]) && PlannerRules::twinIn($profile, (int)$s['place']['place_id'], $used) === null));
        $missing = count($plan['dropped']);
        $candidates = array_slice($spares, 0, self::REPLACE_CANDIDATES * $missing);
        if (!$candidates) return $plan;
        // The candidates' legs to and from every point of the day.
        $legs = [];
        foreach ($candidates as $candidate) {
            $legs[] = [$context['start'], $candidate['point']];
            foreach ($plan['stops'] as $stop) {
                $legs[] = [$stop['point'], $candidate['point']];
                $legs[] = [$candidate['point'], $stop['point']];
            }
            if ($end !== null) $legs[] = [$candidate['point'], $end];
        }
        $route->measureLegs($legs, 1);
        foreach ($candidates as $candidate) {
            if ($missing <= 0) break;
            if (!$route->knows($context['start'], $candidate['point'])) continue;   // no road figures: the estimates found no better
            // Its notes as this day sees it (category variety after the kept stops).
            $candidate = $builder->relevel($candidate, $context, $plan['stops']);
            $again = ItineraryRouteOptimiser::orderDay($route, $profile, $context['start'], array_merge($plan['stops'], [$candidate]), self::frame($context));
            if (!$again['dropped']) { $plan = $again; $missing--; }
        }
        return $plan;
    }

    /** A must-see place counts as missed only when no day holds it in the end. */
    private function settleMusts(array $days, array $musts): void
    {
        $used = $this->usedPlaces($days);
        foreach (array_keys($this->mustMissed) as $id) {
            if (isset($used[$id])) unset($this->mustMissed[$id]);
        }
        foreach ($musts['places'] as $id => $place) {
            if (!isset($used[$id]) && !isset($this->mustMissed[$id])) {
                $this->mustMissed[$id] = ['name' => (string)$place['name'], 'reason' => 'ROOM', 'day' => $musts['feasible'][$id][0] ?? 1];
            }
        }
    }

    /** What a day is planned with: its date, the places used and the rest of the trip for the budget. */
    private function dayContext(array $days, int $dayNo, string $date, array $profile, float $reserveKm, array $laterItems = []): array
    {
        $outside = $this->budgetOutside($days, $dayNo);
        $outside['items'] = array_merge($outside['items'], $laterItems);
        return [
            'day_no' => $dayNo,
            'date' => $date,
            'rain' => $this->rain[$date] ?? null,
            'meal_pool' => $this->mealPool,
            'used' => $this->usedPlaces($days),
            'trip_items' => $outside['items'],
            'trip_km' => $outside['km'],
            'reserve_km' => $reserveKm,
            'budget' => $outside + ['reserve_km' => $reserveKm],
        ];
    }

    /** The items and km of every day except one, for the budget (the way back to the base included). */
    private function budgetOutside(array $days, int $exceptDay): array
    {
        $items = [];
        $km = 0.0;
        foreach ($days as $dayNo => $day) {
            if ($dayNo === $exceptDay) continue;
            foreach ($day['stops'] as $stop) {
                // Meal rows cost nothing extra (three meals a day are in the cost model); their legs count.
                $items[] = ['item_type' => PlannerRules::itemType($stop['place']), 'estimated_cost' => PlannerRules::placeCost($stop['place'])];
                $km += (float)$stop['km'] + (float)($stop['meal_km'] ?? 0);
            }
            if (!empty($day['close'])) $km += (float)$day['close']['km'] + (float)$day['close']['meal_km'];
        }
        return ['items' => $items, 'km' => $km];
    }

    /** The places visited or used for a meal, on every day or on every day but one. */
    private function usedPlaces(array $days, ?int $exceptDay = null): array
    {
        $used = [];
        foreach ($days as $dayNo => $day) {
            if ($dayNo === $exceptDay) continue;
            foreach ($day['stops'] as $stop) {
                $used[(int)$stop['place']['place_id']] = true;
                foreach ($stop['meals'] ?? [] as $meal) {
                    if ($meal['place'] !== null) $used[(int)$meal['place']['place_id']] = true;
                }
            }
            foreach ($day['close']['meals'] ?? [] as $meal) {
                if ($meal['place'] !== null) $used[(int)$meal['place']['place_id']] = true;
            }
        }
        return $used;
    }

    /**
     * The legs of the trip as planned, day by day: from the day's start through its meal places
     * and stops, the dinner near the base, to its base or the end point. Consecutive days join
     * at the base, so measureLegs() strings them into one chain (MAX_ROUTE_POINTS points a request).
     *
     * @return array<int,array{0:array,1:array}>
     */
    private static function plannedLegs(array $days): array
    {
        $legs = [];
        foreach ($days as $day) {
            $from = $day['start'];
            foreach ($day['stops'] as $stop) {
                foreach ((array)($stop['meals'] ?? []) as $meal) {
                    if ($meal['point'] === null) continue;
                    $legs[] = [$from, $meal['point']];
                    $from = $meal['point'];
                }
                $legs[] = [$from, $stop['point']];
                $from = $stop['point'];
            }
            foreach ((array)($day['close']['meals'] ?? []) as $meal) {
                if ($meal['point'] === null) continue;
                $legs[] = [$from, $meal['point']];
                $from = $meal['point'];
            }
            $to = $day['end'] ?? $day['trip_finish'];
            if ($to !== null && $day['stops']) $legs[] = [$from, $to];
            // The day's start is where the next chain begins: at the base it was planned to end at.
        }
        return $legs;
    }

    /** The points of the meal places planned for a day. */
    private static function mealPoints(array $day): array
    {
        $points = [];
        foreach ($day['stops'] as $stop) {
            foreach ($stop['meals'] ?? [] as $meal) if ($meal['point'] !== null) $points[] = $meal['point'];
        }
        foreach ($day['close']['meals'] ?? [] as $meal) if ($meal['point'] !== null) $points[] = $meal['point'];
        return $points;
    }

    /** Meal places within the meal radius (straight line) of these points, so the road figures cover them. */
    private function nearMealPoints(array $points, float $radiusKm): array
    {
        $out = [];
        foreach ($this->mealPool as $place) {
            foreach ($points as $point) {
                if (RouteService::haversineKm((float)$point[0], (float)$point[1], (float)$place['latitude'], (float)$place['longitude']) <= $radiusKm) {
                    $out[] = [(float)$place['latitude'], (float)$place['longitude']];
                    break;
                }
            }
        }
        return array_slice($out, 0, 20);
    }

    /**
     * The chance of rain per trip date: the trip's forecast override when given, otherwise one
     * request for the starting point (none when ROUTING_ESTIMATES_ONLY is set). A date beyond
     * the forecast horizon, and any failure, gives no entry.
     *
     * @return array<string,int> Y-m-d => percent
     */
    private static function forecast(array $trip, array $origin, string $startDate, int $days): array
    {
        $dates = array_values(self::dates($startDate, $days));
        if (isset($trip['forecast']) && is_array($trip['forecast'])) {
            $out = [];
            foreach ($dates as $date) {
                if (isset($trip['forecast'][$date]) && is_numeric($trip['forecast'][$date])) $out[$date] = max(0, min(100, (int)$trip['forecast'][$date]));
            }
            return $out;
        }
        if (in_array(strtolower(trim((string)getenv('ROUTING_ESTIMATES_ONLY'))), ['1', 'true', 'yes'], true)) return [];
        return WeatherService::rainChances($origin[0], $origin[1], $dates);
    }

    private static function mainCategory(array $stops): ?string
    {
        $counts = [];
        foreach ($stops as $stop) {
            $category = strtolower((string)$stop['place']['category']);
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }
        arsort($counts);
        return array_key_first($counts);
    }

    private function store(int $travellerId, int $preferenceId, array $pref, array $profile, string $startDate, string $originName, array $origin,
                           string $endName, ?array $finish, bool $stayAtStart, array $days): int
    {
        $title = self::title($pref);
        $tripDays = (int)$profile['days'];
        $columns = ['traveller_id', 'preference_id', 'title', 'start_date', 'total_days', 'origin_name', 'origin_lat', 'origin_lng'];
        $types = 'iissisdd';
        $values = [$travellerId, $preferenceId, $title, $startDate, $tripDays, $originName, $origin[0], $origin[1]];
        if ($finish !== null && PlannerRules::hasColumn($this->conn, 'itineraries', 'end_lat')) {
            array_push($columns, 'end_name', 'end_lat', 'end_lng');
            $types .= 'sdd';
            array_push($values, mb_substr($endName, 0, 255), $finish[0], $finish[1]);
        }
        if (PlannerRules::hasColumn($this->conn, 'itineraries', 'stay_at_start')) {
            $columns[] = 'stay_at_start';
            $types .= 'i';
            $values[] = $stayAtStart ? 1 : 0;
        }
        $stmt = $this->conn->prepare('INSERT INTO itineraries (' . implode(', ', $columns) . ', total_estimated_cost) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ', 0.00)');
        if (!$stmt) throw new RuntimeException('Unable to create itinerary.');
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $itineraryId = (int)$stmt->insert_id;
        $stmt->close();
        if ($itineraryId <= 0) throw new RuntimeException('Unable to create itinerary.');

        $insert = $this->conn->prepare("
            INSERT INTO itinerary_items
                (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude,
                 start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$insert) throw new RuntimeException('Unable to save itinerary item.');
        // A meal row: item_type food with its meal_slot, no cost of its own (see the head of this file).
        $mealInsert = !empty($profile['meals']) ? $this->conn->prepare("
            INSERT INTO itinerary_items
                (itinerary_id, day_no, sequence_no, item_type, meal_slot, place_id, item_title, item_latitude, item_longitude,
                 start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,'food',?,?,?,?,?,?,?,0.00,?,?,?)
        ") : null;
        $storeMeal = function (array $meal, int $dayNo, int $sequence) use ($mealInsert, $itineraryId, $profile): void {
            $slot = (string)$meal['slot'];
            $mealPlaceId = $meal['place'] !== null ? (int)$meal['place']['place_id'] : null;
            $mealTitle = mb_substr(ItineraryDayBuilder::mealTitle($meal), 0, 150);
            $mealLat = $meal['point'][0] ?? null;
            $mealLng = $meal['point'][1] ?? null;
            $mealStart = PlannerRules::sqlTime((int)$meal['start']);
            $mealEnd = PlannerRules::sqlTime((int)$meal['end']);
            // A break without a place adds time only: no leg.
            $mealKm = $meal['place'] !== null ? round((float)$meal['km'], 2) : null;
            $mealMin = $meal['place'] !== null ? (int)$meal['min'] : null;
            $mealNotes = ItineraryDayBuilder::mealNotes($meal, $profile);
            $mealInsert->bind_param('iiisisddssdis', $itineraryId, $dayNo, $sequence, $slot, $mealPlaceId, $mealTitle, $mealLat, $mealLng,
                $mealStart, $mealEnd, $mealKm, $mealMin, $mealNotes);
            $mealInsert->execute();
        };
        foreach ($days as $dayNo => $day) {
            $sequence = 0;
            foreach ($day['stops'] as $stop) {
                foreach ($mealInsert !== null ? ($stop['meals'] ?? []) : [] as $meal) $storeMeal($meal, (int)$dayNo, ++$sequence);
                $place = $stop['place'];
                $sequence++;
                $type = PlannerRules::itemType($place);
                $placeId = (int)$place['place_id'];
                $itemTitle = mb_substr((string)$place['name'], 0, 150);
                $lat = (float)$place['latitude'];
                $lng = (float)$place['longitude'];
                $startTime = PlannerRules::sqlTime((int)$stop['start']);
                $endTime = PlannerRules::sqlTime((int)$stop['end']);
                $cost = PlannerRules::placeCost($place);
                $km = round((float)$stop['km'], 2);
                $minutes = (int)$stop['min'];
                $notes = ItineraryDayBuilder::notes($stop, $profile);
                $insert->bind_param('iiisisddssddis', $itineraryId, $dayNo, $sequence, $type, $placeId, $itemTitle, $lat, $lng,
                    $startTime, $endTime, $cost, $km, $minutes, $notes);
                $insert->execute();
            }
            // The dinner near the base, after the last visit.
            foreach ($mealInsert !== null ? ($day['close']['meals'] ?? []) : [] as $meal) $storeMeal($meal, (int)$dayNo, ++$sequence);
            if ($sequence === 0) {
                $rejections = (array)($day['rejections'] ?? []);
                arsort($rejections);
                $main = array_key_first($rejections);
                $why = $main !== null ? 'most places were ' . PlannerRules::reasonText((string)$main) : 'no place is left in the chosen area';
                [$noteTitle, $noteText] = self::freeDayNote($why);
                $this->storeNote($itineraryId, (int)$dayNo, ++$sequence, $noteTitle, $noteText, $profile);
            }
            foreach ($this->mustMissed as $missed) {
                if ((int)$missed['day'] !== (int)$dayNo) continue;
                $reason = isset($missed['twin'])
                    ? 'the same landmark as ' . $missed['twin'] . ', also on your must-see list'
                    : (self::MUST_REASONS[$missed['reason']] ?? PlannerRules::reasonText((string)$missed['reason']));
                $this->storeNote($itineraryId, (int)$dayNo, ++$sequence, 'Must-see place not scheduled: ' . $missed['name'],
                    'Reason: ' . $missed['name'] . ' could not be scheduled (' . $reason . '). Visit it at another time, or change the trip dates or preferences.', $profile);
            }
        }
        $insert->close();
        if ($mealInsert !== null) $mealInsert->close();
        $this->storeBases($itineraryId, $days);
        return $itineraryId;
    }

    /** The base and group of every day (itinerary_days, migration 20261119_trip_bases.sql). */
    private function storeBases(int $itineraryId, array $days): void
    {
        if (!PlannerRules::hasColumn($this->conn, 'itinerary_days', 'base_lat')) return;
        // The day note (migration 20261120_day_notes.sql): the public holiday of the day.
        $withNote = PlannerRules::hasColumn($this->conn, 'itinerary_days', 'day_note');
        $stmt = $this->conn->prepare("
            INSERT INTO itinerary_days (itinerary_id, day_no, base_name, base_lat, base_lng, group_from, group_to, area_km" . ($withNote ? ', day_note' : '') . ")
            VALUES (?,?,?,?,?,?,?,?" . ($withNote ? ',?' : '') . ")
            ON DUPLICATE KEY UPDATE base_name = VALUES(base_name), base_lat = VALUES(base_lat), base_lng = VALUES(base_lng),
                group_from = VALUES(group_from), group_to = VALUES(group_to), area_km = VALUES(area_km)" . ($withNote ? ', day_note = VALUES(day_note)' : '') . "
        ");
        foreach ($days as $dayNo => $day) {
            $layout = $day['layout'];
            $name = mb_substr((string)$layout['base']['name'], 0, 255);
            $lat = (float)$layout['base']['lat'];
            $lng = (float)$layout['base']['lng'];
            $from = $layout['sector'][0] ?? null;
            $to = $layout['sector'][1] ?? null;
            $km = (float)$layout['radius'];
            $dayNo = (int)$dayNo;
            if ($withNote) {
                $note = self::dayNote($day);
                $stmt->bind_param('iisddiids', $itineraryId, $dayNo, $name, $lat, $lng, $from, $to, $km, $note);
            } else {
                $stmt->bind_param('iisddiid', $itineraryId, $dayNo, $name, $lat, $lng, $from, $to, $km);
            }
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * The note of a day: the public holidays of its date in the state of the day (its first
     * stop's state, else its area's), with the warning that opening hours may differ; null when
     * the date is no holiday there.
     */
    public static function dayNote(array $day): ?string
    {
        $state = (string)($day['stops'][0]['place']['state'] ?? '');
        if ($state === '') $state = (string)($day['layout']['state'] ?? '');
        $names = PlannerRules::holidaysOn((string)$day['date'], $state !== '' ? $state : null);
        if (!$names) return null;
        return mb_substr('Public holiday: ' . implode(' and ', $names) . '. Opening hours may differ.', 0, 255);
    }

    /** The title of the note row of a day without stops (ItineraryEditService finds the note by it). */
    public const FREE_DAY_TITLE = 'Free day: no suitable stop found';

    /**
     * Title and notes of the note row of a day without stops, written here and by
     * ItineraryEditService when an edit leaves a day empty.
     *
     * @return array{0:string,1:string}
     */
    public static function freeDayNote(string $why): array
    {
        return [self::FREE_DAY_TITLE,
            'Reason: No stop fits your preferences on this day (' . $why . '). Use it as free time, or replace it with a place of your choice.'];
    }

    /** A note row: no place, the day window as its time, no cost. */
    private function storeNote(int $itineraryId, int $dayNo, int $sequence, string $title, string $notes, array $profile): void
    {
        $type = 'note';
        $title = mb_substr($title, 0, 150);
        $startTime = PlannerRules::sqlTime((int)$profile['start']);
        $endTime = PlannerRules::sqlTime((int)$profile['end']);
        $stmt = $this->conn->prepare("
            INSERT INTO itinerary_items (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, notes)
            VALUES (?,?,?,?,NULL,?,?,?,0.00,?)
        ");
        if (!$stmt) throw new RuntimeException('Unable to save itinerary item.');
        $notes = mb_strlen($notes) <= 1000 ? $notes : mb_substr($notes, 0, 997) . '...';
        $stmt->bind_param('iiisssss', $itineraryId, $dayNo, $sequence, $type, $title, $startTime, $endTime, $notes);
        $stmt->execute();
        $stmt->close();
    }

    private static function title(array $pref): string
    {
        $days = (int)$pref['trip_days'];
        $location = $pref['states'] ? implode(' & ', array_slice($pref['states'], 0, 2)) : 'Malaysia';
        $interests = array_map(static fn($x) => ucwords(str_replace('_', ' ', $x)), array_slice($pref['interests'], 0, 2));
        $theme = $interests ? implode(' & ', $interests) : 'Cultural';
        return $days . 'D ' . $theme . ' Rule-Based Route - ' . $location;
    }
}
