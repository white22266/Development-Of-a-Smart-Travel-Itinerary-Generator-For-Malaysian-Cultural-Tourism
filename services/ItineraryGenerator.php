<?php
// services/ItineraryGenerator.php
// The single entry point that turns a saved preference into a stored itinerary. Official
// itineraries come from deterministic rules and database records; AI may explain or suggest,
// but never generates the saved plan.
//
//   1. PlannerRules builds the profile; PlannerCandidates loads the places.
//   2. Day by day, ItineraryDayBuilder fills the day (estimated travel figures) and
//      ItineraryRouteOptimiser puts it in the order with the least travelling.
//   3. When a routing server answers, the trip is measured on the road and every day is
//      ordered and timed again with the road figures. A stop no order can keep is dropped and
//      one feasible replacement is tried.
//   4. Everything is stored, and the total written by ItineraryEditService::total().
//
// All of it runs in one transaction: a failure saves nothing. A day for which nothing is
// feasible is stored with a short note row and generation still succeeds.

require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/PlannerCandidates.php';
require_once __DIR__ . '/ItineraryDayBuilder.php';
require_once __DIR__ . '/ItineraryRouteOptimiser.php';
require_once __DIR__ . '/ItineraryEditService.php';
require_once __DIR__ . '/RouteService.php';

final class ItineraryGenerator
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * @param array{start_date:string,origin_name:string,origin_lat:float,origin_lng:float} $trip
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

        $this->conn->begin_transaction();
        try {
            $pref = PlannerRules::loadPreference($this->conn, $preferenceId, $travellerId);
            if ($pref === null) throw new RuntimeException('Selected preference was not found.');
            $profile = PlannerRules::profile($pref + ['origin_lat' => $origin[0], 'origin_lng' => $origin[1]]);

            $loaded = PlannerCandidates::load($this->conn, $pref);
            if (!$loaded) throw new RuntimeException('No active cultural places are available inside the selected state boundary.');
            $pool = array_values(array_filter($loaded, static fn(array $place): bool => PlannerRules::placeReason($place, $profile) === null));

            $route = new RouteService((string)$profile['transport']);
            $days = $this->planDays($pool, $profile, $route, $startDate, $origin);
            $days = $this->measureOnRoad($days, $pool, $profile, $route, $origin);

            $itineraryId = $this->store($travellerId, $preferenceId, $pref, $profile, $startDate, $originName, $origin, $days);
            ItineraryEditService::total($this->conn, $itineraryId);

            $this->conn->commit();
            return $itineraryId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /** Plan and order every day with estimated travel figures. */
    private function planDays(array $pool, array $profile, RouteService $route, string $startDate, array $origin): array
    {
        $builder = new ItineraryDayBuilder($profile, $route);
        $total = (int)$profile['days'];
        $days = [];
        $start = $origin;
        $theme = null;
        for ($dayNo = 1; $dayNo <= $total; $dayNo++) {
            $context = $this->dayContext($days, $dayNo, $startDate, $start, $profile, ($total - $dayNo) * (float)$profile['day_km']);
            $context['previous_theme'] = $theme;
            $built = $builder->build($pool, $context);
            $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $built['stops'], $context + ['end' => null]);
            // The builder's order keeps every rule, so ordering never loses a stop here.
            $days[$dayNo] = $context + ['stops' => $plan['stops'], 'rejections' => $built['rejections']];
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
    private function measureOnRoad(array $days, array $pool, array $profile, RouteService $route, array $origin): array
    {
        $pointsByDay = [];
        foreach ($days as $dayNo => $day) {
            $pointsByDay[$dayNo] = array_column($day['stops'], 'point');
        }
        $pointsByDay[1][] = $origin;
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
                $route->preload(array_merge([$start], array_column($stops, 'point')));
                $outside = $this->budgetOutside($days, $dayNo);
                $day['trip_items'] = $outside['items'];
                $day['trip_km'] = $outside['km'];
                $day['reserve_km'] = 0.0;
                $day['budget'] = $outside + ['reserve_km' => 0.0];
                $day['end'] = null;
                $plan = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $stops, $day);
                if ($plan['dropped']) {
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
        $points = array_merge([$context['start']], array_column($plan['stops'], 'point'), [$candidate['point']]);
        if (!$route->preload($points)) return $plan;
        $again = ItineraryRouteOptimiser::orderDay($route, $profile, $context['start'], array_merge($plan['stops'], [$candidate]), $context);
        return count($again['stops']) > count($plan['stops']) ? $again : $plan;
    }

    /** What a day is planned with: its date and start, the places used and the rest of the trip for the budget. */
    private function dayContext(array $days, int $dayNo, string $startDate, array $start, array $profile, float $reserveKm): array
    {
        $outside = $this->budgetOutside($days, $dayNo);
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

    private function store(int $travellerId, int $preferenceId, array $pref, array $profile, string $startDate, string $originName, array $origin, array $days): int
    {
        $title = self::title($pref);
        $tripDays = (int)$profile['days'];
        $stmt = $this->conn->prepare("
            INSERT INTO itineraries
                (traveller_id, preference_id, title, start_date, total_days, origin_name, origin_lat, origin_lng, total_estimated_cost)
            VALUES (?,?,?,?,?,?,?,?,0.00)
        ");
        if (!$stmt) throw new RuntimeException('Unable to create itinerary.');
        $stmt->bind_param('iissisdd', $travellerId, $preferenceId, $title, $startDate, $tripDays, $originName, $origin[0], $origin[1]);
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
            if ($sequence === 0) $this->storeNote($itineraryId, (int)$dayNo, $day, $profile);
        }
        $insert->close();
        return $itineraryId;
    }

    /** The row of a day for which nothing was feasible: free time, with the main reason. */
    private function storeNote(int $itineraryId, int $dayNo, array $day, array $profile): void
    {
        $rejections = (array)($day['rejections'] ?? []);
        arsort($rejections);
        $main = array_key_first($rejections);
        $why = $main !== null ? 'most places were ' . PlannerRules::reasonText((string)$main) : 'no place is left in the chosen area';
        $notes = 'Reason: No stop fits your preferences on this day (' . $why . '). Use it as free time, or replace it with a place of your choice.';
        $title = 'Free day: no suitable stop found';
        $type = 'note';
        $sequence = 1;
        $startTime = PlannerRules::sqlTime((int)$profile['start']);
        $endTime = PlannerRules::sqlTime((int)$profile['end']);
        $stmt = $this->conn->prepare("
            INSERT INTO itinerary_items (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, notes)
            VALUES (?,?,?,?,NULL,?,?,?,0.00,?)
        ");
        if (!$stmt) throw new RuntimeException('Unable to save itinerary item.');
        $notes = mb_substr($notes, 0, 255);
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
