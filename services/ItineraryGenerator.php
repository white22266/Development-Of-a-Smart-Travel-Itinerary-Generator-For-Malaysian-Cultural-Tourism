<?php
// services/ItineraryGenerator.php
// The single entry point that turns a saved preference into a stored itinerary. Official
// itineraries come from deterministic rules and database records; AI may explain or suggest,
// but never generates the saved plan.
//
//   1. PlannerRules builds the profile (with what the traveller's own ratings teach);
//      PlannerCandidates loads the places. Places to avoid, and places the traveller rated
//      1 or 2, are never candidates.
//   2. Must-see places are given their days first: each only on a day where it can be visited
//      under every hard rule, places near each other on the same day, and all of them counted
//      in the budget before any other stop.
//   3. Day by day, ItineraryDayBuilder fills the day (must-see places first, estimated travel
//      figures) and ItineraryRouteOptimiser puts it in the order with the least travelling.
//      With an end point, the last day finishes towards it.
//   4. When a routing server answers, the trip is measured on the road and every day is
//      ordered and timed again with the road figures. A stop no order can keep is dropped and
//      one feasible replacement is tried.
//   5. Everything is stored, and the total written by ItineraryEditService::total().
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

final class ItineraryGenerator
{
    /** Probe step through the day window when looking for a day a must-see place can be visited. */
    private const MUST_PROBE_STEP_MIN = 60;
    /** Words for a must-see place that no day of the trip allows. */
    private const MUST_REASONS = [
        'FESTIVAL_DAY' => 'the festival is not on during the trip',
        'CLOSED' => 'closed on the days of the trip',
    ];

    private mysqli $conn;
    /** place_id => ['name', 'reason', 'day'] for must-see places not (yet) scheduled. */
    private array $mustMissed = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * @param array{start_date:string,origin_name:string,origin_lat:float,origin_lng:float,
     *              end_name?:string,end_lat?:float,end_lng?:float} $trip
     *        the end fields are optional: without them the trip ends where its last stop is
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
            // Avoided places, must-see places (planned on their own) and places the traveller
            // rated low are not candidates; a must-see place stays wanted even if rated low.
            $skip = array_flip((array)$pref['avoid_places']) + array_flip($mustIds) + $ratings['low'];
            $pool = array_values(array_filter($loaded, static fn(array $place): bool =>
                !isset($skip[(int)$place['place_id']]) && PlannerRules::placeReason($place, $profile) === null));

            $route = new RouteService((string)$profile['transport']);
            $musts = $this->assignMusts($mustIds, $profile, $route, $startDate);
            $days = $this->planDays($pool, $profile, $route, $startDate, $origin, $finish, $musts);
            $days = $this->measureOnRoad($days, $pool, $profile, $route, $origin, $finish);
            $this->settleMusts($days, $musts);

            $itineraryId = $this->store($travellerId, $preferenceId, $pref, $profile, $startDate, $originName, $origin, $endName, $finish, $days);
            ItineraryEditService::total($this->conn, $itineraryId);

            $this->conn->commit();
            return $itineraryId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Give each must-see place a day. A place goes only on a day where a visit fits every rule
     * that does not depend on the other stops (state, region, festival dates, opening hours,
     * day window, accessibility), only while all must-see places together keep the budget, and
     * only while the day has room. Places with the fewest possible days go first; a place goes
     * to the day of a must-see place near it, otherwise to a day without one.
     *
     * @return array{days:array<int,array>,feasible:array<int,int[]>,places:array<int,array>}
     */
    private function assignMusts(array $mustIds, array $profile, RouteService $route, string $startDate): array
    {
        $out = ['days' => [], 'feasible' => [], 'places' => []];
        if (!$mustIds) return $out;
        $rows = [];
        foreach (PlannerCandidates::byIds($this->conn, $mustIds) as $row) $rows[(int)$row['place_id']] = $row;
        $names = $this->placeNames($mustIds);

        $totalDays = (int)$profile['days'];
        $dates = [];
        for ($d = 1; $d <= $totalDays; $d++) $dates[$d] = (new DateTimeImmutable($startDate))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');

        foreach ($mustIds as $id) {
            $name = $names[$id] ?? ('Place #' . $id);
            if (!isset($rows[$id])) { $this->mustMissed[$id] = ['name' => $name, 'reason' => 'UNAVAILABLE', 'day' => 1]; continue; }
            $place = $rows[$id];
            $reason = PlannerRules::placeReason($place, $profile);
            if ($reason !== null) { $this->mustMissed[$id] = ['name' => $name, 'reason' => $reason, 'day' => 1]; continue; }
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
        $reserveKm = $totalDays * (float)$profile['day_km'];
        $nearKm = (float)$profile['day_km'] / 2;
        foreach ($order as $id) {
            $place = $out['places'][$id];
            $item = ['item_type' => PlannerRules::itemType($place), 'estimated_cost' => PlannerRules::placeCost($place)];
            if (!PlannerRules::budgetAllows($profile, array_merge($items, [$item]), $reserveKm, PlannerRules::placeCost($place))) {
                $this->mustMissed[$id] = ['name' => (string)$place['name'], 'reason' => 'BUDGET', 'day' => $out['feasible'][$id][0]];
                unset($out['places'][$id]);
                continue;
            }
            $open = array_values(array_filter($out['feasible'][$id], fn(int $d): bool => count($out['days'][$d] ?? []) < (int)$profile['target']));
            if (!$open) {
                $this->mustMissed[$id] = ['name' => (string)$place['name'], 'reason' => 'ROOM', 'day' => $out['feasible'][$id][0]];
                unset($out['places'][$id]);
                continue;
            }
            $near = null;
            $nearest = INF;
            foreach ($open as $d) {
                foreach ($out['days'][$d] ?? [] as $other) {
                    $km = (float)$route->getSegment((float)$place['latitude'], (float)$place['longitude'], (float)$other['latitude'], (float)$other['longitude'])['distance_km'];
                    if ($km < $nearest) { $nearest = $km; $near = $d; }
                }
            }
            $empty = null;
            foreach ($open as $d) {
                if (empty($out['days'][$d])) { $empty = $d; break; }
            }
            $day = ($near !== null && $nearest <= $nearKm) ? $near : ($empty ?? $near ?? $open[0]);
            $out['days'][$day][] = $place;
            $items[] = $item;
        }
        return $out;
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
    private function planDays(array $pool, array $profile, RouteService $route, string $startDate, array $origin, ?array $finish, array $musts): array
    {
        $builder = new ItineraryDayBuilder($profile, $route);
        $total = (int)$profile['days'];
        $pending = $musts['days'];
        $days = [];
        $start = $origin;
        $theme = null;
        for ($dayNo = 1; $dayNo <= $total; $dayNo++) {
            // Must-see places of the days still to come keep their share of the budget.
            $later = [];
            foreach ($pending as $d => $places) {
                if ($d <= $dayNo) continue;
                foreach ($places as $place) $later[] = ['item_type' => PlannerRules::itemType($place), 'estimated_cost' => PlannerRules::placeCost($place)];
            }
            $context = $this->dayContext($days, $dayNo, $startDate, $start, $profile, ($total - $dayNo) * (float)$profile['day_km'], $later);
            $context['previous_theme'] = $theme;
            $context['musts'] = $pending[$dayNo] ?? [];
            $context['finish'] = $dayNo === $total ? $finish : null;
            $built = $builder->build($pool, $context);
            $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $built['stops'], $context + ['end' => null]);
            // The builder's order keeps every rule, so ordering never loses a stop here.
            $days[$dayNo] = $context + ['stops' => $plan['stops'], 'rejections' => $built['rejections']];

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
            if ($plan['stops']) {
                $start = $plan['stops'][count($plan['stops']) - 1]['point'];
                $theme = self::mainCategory($plan['stops']);
            }
        }
        return $days;
    }

    /**
     * Measure the planned trip on the road and order and time each day again with those
     * figures. Without an answer from the routing server the estimates stay.
     */
    private function measureOnRoad(array $days, array $pool, array $profile, RouteService $route, array $origin, ?array $finish): array
    {
        $pointsByDay = [];
        foreach ($days as $dayNo => $day) {
            $pointsByDay[$dayNo] = array_column($day['stops'], 'point');
        }
        $pointsByDay[1][] = $origin;
        $lastDay = (int)array_key_last($days);
        if ($finish !== null) $pointsByDay[$lastDay][] = $finish;
        $route->preloadTrip($pointsByDay);

        // Every day is ordered again, also when a day has no road figures: the day before may
        // now end elsewhere. With the same figures as in planning, no stop is lost here.
        $builder = new ItineraryDayBuilder($profile, $route);
        $start = $origin;
        foreach (array_keys($days) as $dayNo) {
            $day = $days[$dayNo];
            $day['start'] = $start;
            $stops = $day['stops'];
            if ($stops) {
                $route->preload(array_merge([$start], array_column($stops, 'point'), $day['finish'] !== null ? [$day['finish']] : []));
                $outside = $this->budgetOutside($days, $dayNo);
                $day['trip_items'] = $outside['items'];
                $day['trip_km'] = $outside['km'];
                $day['reserve_km'] = 0.0;
                $day['budget'] = $outside + ['reserve_km' => 0.0];
                $day['end'] = null;
                $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $stops, $day);
                if ($plan['dropped']) {
                    foreach ($plan['dropped'] as $stop) {
                        if (!empty($stop['must'])) {
                            $this->mustMissed[(int)$stop['place']['place_id']] = ['name' => (string)$stop['place']['name'], 'reason' => (string)$stop['reason'], 'day' => $dayNo];
                        }
                    }
                    $plan = $this->replace($plan, $pool, $profile, $route, $builder, $days, $day);
                }
                $day['stops'] = $plan['stops'];
            }
            $days[$dayNo] = $day;
            if ($day['stops']) $start = $day['stops'][count($day['stops']) - 1]['point'];
        }
        return $days;
    }

    /** After a stop was dropped on the road figures: try one feasible replacement. */
    private function replace(array $plan, array $pool, array $profile, RouteService $route, ItineraryDayBuilder $builder, array $days, array $context): array
    {
        $used = $this->usedPlaces($days);
        foreach ($plan['dropped'] as $stop) $used[(int)$stop['place']['place_id']] = true;
        foreach ($plan['stops'] as $stop) unset($used[(int)$stop['place']['place_id']]);   // advance() adds them back
        $context['used'] = $used;
        $state = ItineraryDayBuilder::startState($context, $profile);
        foreach ($plan['stops'] as $stop) $state = ItineraryDayBuilder::advance($state, $stop, $profile);

        $candidate = $builder->ranked($pool, $context, $state)[0] ?? null;
        if ($candidate === null) return $plan;
        $points = array_merge([$context['start']], array_column($plan['stops'], 'point'), [$candidate['point']], $context['finish'] !== null ? [$context['finish']] : []);
        if (!$route->preload($points)) return $plan;
        $again = ItineraryRouteOptimiser::orderDay($route, $profile, $context['start'], array_merge($plan['stops'], [$candidate]), $context);
        return count($again['stops']) > count($plan['stops']) ? $again : $plan;
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

    /** What a day is planned with: its date and start, the places used and the rest of the trip for the budget. */
    private function dayContext(array $days, int $dayNo, string $startDate, array $start, array $profile, float $reserveKm, array $laterItems = []): array
    {
        $outside = $this->budgetOutside($days, $dayNo);
        $outside['items'] = array_merge($outside['items'], $laterItems);
        return [
            'day_no' => $dayNo,
            'date' => (new DateTimeImmutable($startDate))->modify('+' . ($dayNo - 1) . ' days')->format('Y-m-d'),
            'start' => $start,
            'first_leg_exempt' => $dayNo === 1,
            'used' => $this->usedPlaces($days),
            'trip_items' => $outside['items'],
            'trip_km' => $outside['km'],
            'reserve_km' => $reserveKm,
            'budget' => $outside + ['reserve_km' => $reserveKm],
        ];
    }

    /** The items and km of every day except one, for the budget. */
    private function budgetOutside(array $days, int $exceptDay): array
    {
        $items = [];
        $km = 0.0;
        foreach ($days as $dayNo => $day) {
            if ($dayNo === $exceptDay) continue;
            foreach ($day['stops'] as $stop) {
                $items[] = ['item_type' => PlannerRules::itemType($stop['place']), 'estimated_cost' => PlannerRules::placeCost($stop['place'])];
                $km += (float)$stop['km'];
            }
        }
        return ['items' => $items, 'km' => $km];
    }

    private function usedPlaces(array $days): array
    {
        $used = [];
        foreach ($days as $day) {
            foreach ($day['stops'] as $stop) $used[(int)$stop['place']['place_id']] = true;
        }
        return $used;
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

    private function store(int $travellerId, int $preferenceId, array $pref, array $profile, string $startDate, string $originName, array $origin, string $endName, ?array $finish, array $days): int
    {
        $title = self::title($pref);
        $tripDays = (int)$profile['days'];
        if ($finish !== null && PlannerRules::hasColumn($this->conn, 'itineraries', 'end_lat')) {
            $stmt = $this->conn->prepare("
                INSERT INTO itineraries
                    (traveller_id, preference_id, title, start_date, total_days, origin_name, origin_lat, origin_lng,
                     end_name, end_lat, end_lng, total_estimated_cost)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,0.00)
            ");
            if (!$stmt) throw new RuntimeException('Unable to create itinerary.');
            $endName = mb_substr($endName, 0, 255);
            $stmt->bind_param('iissisddsdd', $travellerId, $preferenceId, $title, $startDate, $tripDays, $originName, $origin[0], $origin[1],
                $endName, $finish[0], $finish[1]);
        } else {
            $stmt = $this->conn->prepare("
                INSERT INTO itineraries
                    (traveller_id, preference_id, title, start_date, total_days, origin_name, origin_lat, origin_lng, total_estimated_cost)
                VALUES (?,?,?,?,?,?,?,?,0.00)
            ");
            if (!$stmt) throw new RuntimeException('Unable to create itinerary.');
            $stmt->bind_param('iissisdd', $travellerId, $preferenceId, $title, $startDate, $tripDays, $originName, $origin[0], $origin[1]);
        }
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
        foreach ($days as $dayNo => $day) {
            $sequence = 0;
            foreach ($day['stops'] as $stop) {
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
            if ($sequence === 0) {
                $rejections = (array)($day['rejections'] ?? []);
                arsort($rejections);
                $main = array_key_first($rejections);
                $why = $main !== null ? 'most places were ' . PlannerRules::reasonText((string)$main) : 'no place is left in the chosen area';
                $this->storeNote($itineraryId, (int)$dayNo, ++$sequence, 'Free day: no suitable stop found',
                    'Reason: No stop fits your preferences on this day (' . $why . '). Use it as free time, or replace it with a place of your choice.', $profile);
            }
            foreach ($this->mustMissed as $missed) {
                if ((int)$missed['day'] !== (int)$dayNo) continue;
                $reason = self::MUST_REASONS[$missed['reason']] ?? PlannerRules::reasonText((string)$missed['reason']);
                $this->storeNote($itineraryId, (int)$dayNo, ++$sequence, 'Must-see place not scheduled: ' . $missed['name'],
                    'Reason: ' . $missed['name'] . ' could not be scheduled (' . $reason . '). Visit it at another time, or change the trip dates or preferences.', $profile);
            }
        }
        $insert->close();
        return $itineraryId;
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
        $notes = mb_strlen($notes) <= 255 ? $notes : mb_substr($notes, 0, 252) . '...';
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
