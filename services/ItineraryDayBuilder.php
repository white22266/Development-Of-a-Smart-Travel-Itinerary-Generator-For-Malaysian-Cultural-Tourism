<?php
// services/ItineraryDayBuilder.php
// Fills one day of an itinerary with a search for the best day (search()): the set and order of
// stops with the highest total value that keeps every hard rule of PlannerRules::check(), with
// at most the target number of stops and as many of them as the rules allow. The value of a day
// is the sum of its stops' values (score()['value']) less TRAVEL_PRICE per travelling minute
// (every leg: from the start, between stops, to and from meals and back to the base) and
// IDLE_PRICE per minute of waiting. The search keeps the BEAM_WIDTH best partial days at each
// number of stops (must-see places first, then value; ties by place ids, so it is deterministic)
// and extends each with every candidate; a day may end after any number of stops when its way
// back to the base fits (fit() with the base). It also gives its next-best days and the places it
// would take next (alternatives, spares) for the repair step and for planning a day again.
//
// The candidates: the day's group (the places of its sector around the base, TripLayout) first,
// at most SEARCH_CANDIDATES of them by value less the price of the way there and back (a place
// outside the preferred districts or interests valued after its relax price, layoutValue());
// a second search adds the best other places of the area, and its day is taken when it ranks
// higher. Times: every visit and meal starts on a multiple of five minutes, every leg takes
// its rush-hour minutes (PlannerRules::peakMinutes()), opening hours as PlannerRules::hoursOn().
//
// Soft preferences: a stop that keeps them all is level 0. A stop that only a relaxed level
// allows costs RELAX_PRICE per level and says so in its notes:
//
//   level 1  category variety relaxed
//   level 2  preferred districts relaxed as well
//   level 3  interest match relaxed as well
//   level 4  the recommended daily distance relaxed as well (never the hard limit)
//
// Hard rules (opening hours, the day window, rest, distance limit, budget, accessibility,
// dietary, festival dates, states, the way back to the base) are never relaxed. The budget is a
// running constraint: every candidate is checked against the projected trip total including it.
//
// Must-see places given for the day count before value, with every soft preference relaxed but
// every hard rule kept; the places still to come keep their room in the day and have first call
// on the budget. Every stop must leave time and distance to reach the end of the day: the trip's
// end point on the last day (FINISH), the base of the night on a day followed by a night (BASE).
//
// Meals (PlannerRules::MEAL_WINDOWS): the visits are chosen and ordered as before, and fit()
// times each visit together with the day's meals, so a visit is only accepted when it still
// keeps every rule after the meal before it. A meal is taken before the next visit when lunch
// or dinner is still due, the last visit ended by the latest start of its window, and the next
// visit would end after it (or would wait too long for opening without the meal). It goes at
// the suitable food place with the least detour between the stop before and the stop after,
// or else is kept as a break without a place. A food-place visit starting inside a meal
// window is that meal. The meal stands in for the rest between the two visits.
//
// A day followed by a night ends at its base (fit() with the base as the place): the leg back
// counts in the day's distance and the traveller is back by the end of the day window. Such a day
// always has a dinner: on the way when the traveller is still out across the dinner window (as
// above), otherwise near the base: at a suitable food place within the meal radius of the base,
// open for the whole meal, or as a dinner break at the base. Waiting for the dinner window there
// is rest, not waiting.
//
// Weather (the day's chance of rain, when known): from RAIN_PREFER percent outdoor places
// lose score and indoor places gain; from RAIN_INDOOR_FIRST percent outdoor places are tried
// only when no indoor place of the same relax level is feasible (ranked()). Never a hard rule.

require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/OpeningHours.php';

final class ItineraryDayBuilder
{
    public const RELAXED = [
        1 => 'category variety relaxed to fill the day',
        2 => 'preferred districts relaxed to fill the day',
        3 => 'interest relaxed to fill the day',
        4 => 'recommended daily distance relaxed to fill the day',
    ];
    private const LAST_LEVEL = 4;
    /**
     * Score of an interest match by weight (1 a little, 2 like, 3 love). Weight 2 scores what
     * every interest scored before weights existed; one step is 10 points, more than twice the
     * largest learned adjustment (PlannerRules::LEARN_MAX_POINTS).
     */
    public const INTEREST_POINTS = [1 => 15, 2 => 25, 3 => 35];

    /** Chance of rain (percent) from which the weather rule applies, and from which outdoor places wait. */
    public const RAIN_PREFER = 60;
    public const RAIN_INDOOR_FIRST = 80;
    private const RAIN_OUTDOOR_POINTS = 15;
    private const RAIN_INDOOR_POINTS = 8;

    /**
     * The day search. A travelling minute costs TRAVEL_PRICE points: 25 minutes cost what one
     * interest weight step gives (10 points), so a much better place is worth a short detour but
     * not a long one. A minute of waiting costs IDLE_PRICE (the old waiting penalty, minutes / 6).
     * A relaxed soft preference costs RELAX_PRICE per level. The search keeps BEAM_WIDTH partial
     * days per number of stops and looks at SEARCH_CANDIDATES places per round (the group first).
     */
    public const TRAVEL_PRICE = 0.4;
    public const IDLE_PRICE = 1 / 6;
    public const RELAX_PRICE = 10;
    private const BEAM_WIDTH = 12;
    private const SEARCH_CANDIDATES = 40;
    private const ALTERNATIVES = 3;
    private const SPARES = 12;

    private array $profile;
    private RouteService $route;
    private array $mealIndex;
    /** place_id => placeValue(), worked out once per builder. */
    private array $values = [];

    /** @param array $mealPool the food places meals may use (PlannerCandidates::mealPool()) */
    public function __construct(array $profile, RouteService $route, array $mealPool = [])
    {
        $this->profile = $profile;
        $this->route = $route;
        $this->mealIndex = self::mealIndex($mealPool, (float)$profile['meal_radius_km']);
    }

    /**
     * Plan one day: the best day the search finds (search()).
     *
     * @param array $pool candidate places (already narrowed by PlannerRules::placeReason())
     * @param array $day  date, start [lat, lng], first_leg_exempt (the first leg is not counted:
     *                    day 1, and the day the trip moves to another area), used (place_id =>
     *                    true), trip_items and trip_km (the other days' items and km), reserve_km
     *                    (held back for the days not planned yet), previous_theme (main category
     *                    of the day before), musts (must-see place rows for this day, not in
     *                    $pool), finish (where the day must end in time: the trip's end point on
     *                    the last day, the base of the night on a day followed by a night, or
     *                    null), finish_code (FINISH or BASE), night (true: a night follows, the day
     *                    ends at its base with a dinner), base (the day's base, for the
     *                    candidates), group (place_id => true: the day's own group)
     * @return array{stops:array,rejections:array<string,int>,unplaced:array<int,string>,alternatives:array,spares:array,value:float}
     *         stops in visiting order; rejections counts the reasons candidates failed for the
     *         first stop (for an empty day); unplaced: must-see place_id => reason code for those
     *         not planned; alternatives: the next-best days (stops); spares: the places the
     *         search would take next, best first (stops); value: the best day's value
     */
    public function build(array $pool, array $day): array
    {
        return $this->search($pool, $day);
    }

    /**
     * The search for the best day (see the head of this file). Deterministic.
     *
     * @return array as build()
     */
    public function search(array $pool, array $day): array
    {
        $musts = array_values((array)($day['musts'] ?? []));
        $group = (array)($day['group'] ?? []);
        $anchor = $day['base'] ?? $day['finish'] ?? $day['start'];
        $own = $others = [];
        foreach ($pool as $place) {
            if (isset($day['used'][(int)$place['place_id']])) continue;
            if (!$group || isset($group[(int)$place['place_id']])) $own[] = $place;
            else $others[] = $place;
        }
        $own = $this->preselect($own, $anchor, self::SEARCH_CANDIDATES);
        $best = $this->beam(array_merge($musts, $own), $musts, $day);
        // The best other places of the area are always tried too; the wider day is kept only
        // when it ranks higher (must-see places, stops, value), so the group stays first.
        if ($others) {
            $wider = $this->beam(array_merge($musts, $own, $this->preselect($others, $anchor, self::SEARCH_CANDIDATES)), $musts, $day);
            if (self::rank($wider) > self::rank($best)) $best = $wider;
        }
        $placed = [];
        foreach ($best['stops'] as $stop) $placed[(int)$stop['place']['place_id']] = true;
        $unplaced = [];
        foreach ($musts as $m) {
            if (!isset($placed[(int)$m['place_id']])) $unplaced[(int)$m['place_id']] = $this->mustReason($m, $day, self::startState($day, $this->profile));
        }
        $best['spares'] = array_values(array_filter($best['spares'], static fn(array $s): bool => !isset($placed[(int)$s['place']['place_id']])));
        $best['unplaced'] = $unplaced;
        return $best;
    }

    /** The places worth a look for a day: the best by value less the price of the way there and back. */
    private function preselect(array $places, array $anchor, int $limit): array
    {
        $ranked = [];
        foreach ($places as $place) {
            $seg = $this->route->getSegment((float)$anchor[0], (float)$anchor[1], (float)$place['latitude'], (float)$place['longitude']);
            $ranked[] = [$this->layoutValue($place) - 2 * self::TRAVEL_PRICE * (int)$seg['travel_time_min'], -(int)$place['place_id'], $place];
        }
        usort($ranked, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        return array_column(array_slice($ranked, 0, $limit), 2);
    }

    /** How a finished day compares: must-see places, then stops, then value. */
    private static function rank(array $day): array
    {
        $musts = count(array_filter($day['stops'], static fn(array $s): bool => !empty($s['must'])));
        return [$musts, count($day['stops']), round((float)$day['value'], 6)];
    }

    /**
     * The beam: every partial day of one more stop from the BEAM_WIDTH best of the round before;
     * every survivor that can end the day (closeAt()) is a finished day.
     */
    private function beam(array $candidates, array $musts, array $day): array
    {
        $target = (int)$this->profile['target'];
        $mustIds = [];
        foreach ($musts as $m) $mustIds[(int)$m['place_id']] = $m;
        $root = ['state' => self::startState($day, $this->profile), 'stops' => [], 'value' => 0.0, 'musts' => 0, 'key' => '', 'tie' => []];
        $beam = [$root];
        $finals = [];
        $spares = [];      // place_id => [best gain, stop]
        $rejections = [];
        $list = [];
        foreach ($candidates as $place) $list[(int)$place['place_id']] ??= $place;
        for ($depth = 1; $depth <= $target; $depth++) {
            $children = [];
            foreach ($beam as $node) {
                $mustLeft = array_filter($mustIds, static fn(array $m): bool => !isset($node['state']['used'][(int)$m['place_id']]));
                // The rest of the day is kept for the must-see places still to come.
                $onlyMusts = count($mustLeft) >= $target - count($node['stops']);
                $grown = [];
                foreach ($list as $id => $place) {
                    $isMust = isset($mustIds[$id]);
                    if (isset($node['state']['used'][$id]) || ($onlyMusts && !$isMust)) continue;
                    $child = $this->extend($node, $place, $day, $isMust, $isMust ? [] : array_values($mustLeft), $rejections, $depth === 1);
                    if ($child !== null) $grown[] = $child;
                }
                foreach (self::rainFilter($grown, $day) as $child) {
                    $last = $child['stops'][count($child['stops']) - 1];
                    $id = (int)$last['place']['place_id'];
                    if (empty($last['must']) && (!isset($spares[$id]) || $child['gain'] > $spares[$id][0])) $spares[$id] = [$child['gain'], $last];
                    $key = $child['key'];
                    if (!isset($children[$key]) || self::nodeRank($child) > self::nodeRank($children[$key])) $children[$key] = $child;
                }
            }
            if (!$children) break;
            $children = array_values($children);
            usort($children, static fn(array $a, array $b): int => self::nodeRank($b) <=> self::nodeRank($a));
            $beam = array_slice($children, 0, self::BEAM_WIDTH);
            foreach ($beam as $node) {
                $closed = $this->closeAt($node, $day);
                if ($closed !== null) $finals[] = $closed;
            }
        }
        usort($finals, static fn(array $a, array $b): int => self::finalRank($b) <=> self::finalRank($a));
        $best = $finals[0] ?? ['stops' => [], 'final' => 0.0, 'set' => ''];
        $alternatives = [];
        $sets = [$best['set'] => true];
        foreach ($finals as $f) {
            if (count($alternatives) >= self::ALTERNATIVES) break;
            if (isset($sets[$f['set']])) continue;
            $sets[$f['set']] = true;
            $alternatives[] = $f['stops'];
        }
        uasort($spares, static fn(array $a, array $b): int => [$b[0], -(int)$b[1]['place']['place_id']] <=> [$a[0], -(int)$a[1]['place']['place_id']]);
        return [
            'stops' => $best['stops'],
            'value' => (float)$best['final'],
            'rejections' => $rejections,
            'alternatives' => $alternatives,
            'spares' => array_slice(array_column(array_values($spares), 1), 0, self::SPARES + $target),
        ];
    }

    /**
     * Weather: from RAIN_INDOOR_FIRST percent an outdoor place is a next stop only when no indoor
     * place of the same relax level is (must-see places are not changed by the weather). The
     * stops say so in their notes.
     */
    private static function rainFilter(array $children, array $day): array
    {
        $rain = self::rainOf($day);
        if ($rain === null || $rain < self::RAIN_PREFER) return $children;
        $indoorAt = [];
        foreach ($children as $c) {
            $last = $c['stops'][count($c['stops']) - 1];
            if (empty($last['must']) && !PlannerRules::isOutdoor($last['place'])) $indoorAt[(int)$last['level']] = true;
        }
        $out = [];
        foreach ($children as $c) {
            $k = count($c['stops']) - 1;
            $last = $c['stops'][$k];
            if (!empty($last['must'])) { $out[] = $c; continue; }
            $outdoor = PlannerRules::isOutdoor($last['place']);
            if ($outdoor && $rain >= self::RAIN_INDOOR_FIRST && isset($indoorAt[(int)$last['level']])) continue;
            $note = $outdoor
                ? ($rain >= self::RAIN_INDOOR_FIRST ? 'outdoor although ' . $rain . '% chance of rain: no indoor place fits' : null)
                : $rain . '% chance of rain forecast: indoor place preferred';
            // after the relax note, so a cut note keeps both
            if ($note !== null) array_splice($c['stops'][$k]['reasons'], (int)$last['level'] > 0 ? 1 : 0, 0, [$note]);
            $out[] = $c;
        }
        return $out;
    }

    private static function nodeRank(array $node): array
    {
        return [$node['musts'], round((float)$node['value'], 6), $node['tie']];
    }

    private static function finalRank(array $node): array
    {
        return [$node['musts'], count($node['stops']), round((float)$node['final'], 6), $node['tie']];
    }

    /**
     * One more stop for a partial day: timed by fit() at the lowest relax level its soft
     * preferences allow, valued by score() less the price of its travelling and waiting.
     * Null when it breaks a rule (with $count, $rejections counts why).
     */
    private function extend(array $node, array $place, array $day, bool $must, array $mustLeft, array &$rejections, bool $count): ?array
    {
        $state = $node['state'];
        $point = [(float)$place['latitude'], (float)$place['longitude']];
        $seg = $this->route->getSegment((float)$state['point'][0], (float)$state['point'][1], $point[0], $point[1]);
        $legCounts = !$state['first_leg'];
        $level = 0;
        if (!$must) {
            while ($level < self::LAST_LEVEL && !$this->softAllows($place, $level, $state, (float)$seg['distance_km'], $legCounts)) $level++;
        }
        // Must-see places still to come have first call on the budget.
        $items = $state['trip_items'];
        foreach ($mustLeft as $m) $items[] = ['item_type' => PlannerRules::itemType($m), 'estimated_cost' => PlannerRules::placeCost($m)];
        $check = self::fit($this->route, $this->profile, $this->mealIndex, $place, $this->slotOf($state, $day, $point, $items), $seg);
        if (!$check['ok']) {
            if ($count) $rejections[$check['reason']] = ($rejections[$check['reason']] ?? 0) + 1;
            return null;
        }
        $scored = $this->score($place, $state, $day, (float)$seg['distance_km'], $check, $level, $must);
        $gain = $scored['value'] - self::TRAVEL_PRICE * ((int)$check['min'] + (int)$check['meal_min'])
            - self::IDLE_PRICE * ((int)$check['wait'] + (int)$check['idle']);
        $stop = self::stopFrom($place, $check, $scored, $level, $must);
        $ids = array_map(static fn(array $s): int => (int)$s['place']['place_id'], $node['stops']);
        $ids[] = (int)$place['place_id'];
        $sorted = $ids;
        sort($sorted);
        return [
            'state' => self::advance($state, $stop, $this->profile),
            'stops' => array_merge($node['stops'], [$stop]),
            'value' => (float)$node['value'] + $gain,
            'gain' => $gain,
            'musts' => (int)$node['musts'] + ($must ? 1 : 0),
            'key' => implode(',', $sorted) . '|' . (int)$place['place_id'],
            // Ties: the lower place ids, in visiting order.
            'tie' => array_map(static fn(int $id): int => -$id, $ids),
        ];
    }

    /** A finished day from a partial one, when its end fits: the way back to the base (with dinner) or to the end point. */
    private function closeAt(array $node, array $day): ?array
    {
        $state = $node['state'];
        $value = (float)$node['value'];
        $finish = $day['finish'] ?? null;
        if (!empty($day['night']) && $finish !== null) {
            $close = self::fit($this->route, $this->profile, $this->mealIndex, self::basePlace($finish), $this->slotOf($state, $day, $finish, $state['trip_items']));
            if (!$close['ok']) return null;
            $value -= self::TRAVEL_PRICE * ((int)$close['min'] + (int)$close['meal_min']);
        } elseif ($finish !== null) {
            $value -= self::TRAVEL_PRICE * (int)$this->route->getSegment((float)$state['point'][0], (float)$state['point'][1], (float)$finish[0], (float)$finish[1])['travel_time_min'];
        }
        $ids = array_map(static fn(array $s): int => (int)$s['place']['place_id'], $node['stops']);
        sort($ids);
        return $node + ['final' => $value, 'set' => implode(',', $ids)];
    }

    /** The base of a night as the place fit() closes a day with. */
    public static function basePlace(array $point, string $name = ''): array
    {
        return ['base' => true, 'place_id' => 0, 'name' => $name, 'category' => '', 'latitude' => (float)$point[0], 'longitude' => (float)$point[1]];
    }

    /** What fit() reads about the day so far, for the next stop at $point. */
    private function slotOf(array $state, array $day, array $point, array $items): array
    {
        $finish = $day['finish'] ?? null;
        $toFinish = $finish !== null ? $this->route->getSegment((float)$point[0], (float)$point[1], (float)$finish[0], (float)$finish[1]) : null;
        return [
            'date' => (string)$day['date'],
            'point' => $state['point'],
            'clock' => (int)$state['clock'],
            'prev_end' => $state['prev_end'],
            'meals' => $state['meals'],
            'leg_counts' => !$state['first_leg'],
            'day_km' => (float)$state['day_km'],
            'used' => $state['used'],
            'meal_used' => $state['used'],
            'trip_items' => $items,
            'trip_km' => (float)$state['trip_km'],
            'reserve_km' => (float)($day['reserve_km'] ?? 0),
            'finish' => $toFinish !== null ? [(float)$toFinish['distance_km'], (int)$toFinish['travel_time_min']] : null,
            'finish_code' => (string)($day['finish_code'] ?? 'FINISH'),
            'night' => !empty($day['night']),
        ];
    }

    /** A timed stop as the planner stores it. */
    private static function stopFrom(array $place, array $check, array $scored, int $level, bool $must): array
    {
        return [
            'place' => $place,
            'point' => [(float)$place['latitude'], (float)$place['longitude']],
            'start' => $check['start'],
            'end' => $check['end'],
            'km' => $check['km'],
            'min' => $check['min'],
            'meals' => $check['meals'],
            'meal_km' => $check['meal_km'],
            'meal_min' => $check['meal_min'],
            'food_meal' => $check['food_meal'],
            'level' => $must ? 0 : $level,
            'must' => $must,
            'tier' => $scored['tier'],
            'score' => $scored['score'],
            'base' => $scored['base'],
            'value' => $scored['value'],
            'reasons' => $scored['reasons'],
        ];
    }

    /**
     * What a place is worth to this traveller on its own, before the day it is planned on:
     * interest weight, preferred district, what the ratings taught, traveller type, free entry
     * and rating (the slot-free terms of score()). TripLayout weighs areas and groups by it.
     */
    public function placeValue(array $place): float
    {
        $id = (int)$place['place_id'];
        if (isset($this->values[$id])) return $this->values[$id];
        $p = $this->profile;
        $category = strtolower((string)$place['category']);
        $value = 0.0;
        if (in_array($category, $p['interests'], true)) $value += self::INTEREST_POINTS[(int)($p['interest_weights'][$category] ?? 2)] ?? self::INTEREST_POINTS[2];
        if ($p['districts'] && in_array(strtolower((string)$place['district']), $p['districts'], true)) $value += 12;
        $value += (float)($p['learned'][$category]['points'] ?? 0);
        $value += self::travellerSuitability($place, !empty($p['family']) ? 'family' : (string)$p['traveller_type']);
        if (PlannerRules::placeCost($place) <= 0) $value += 10;
        $rating = max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0));
        if ($rating > 0) $value += min(8.0, ($rating / 5) * 8);
        return $this->values[$id] = $value;
    }

    /**
     * What a place is worth to the trip layout (TripLayout weighs areas and groups by it): its
     * placeValue() less the price of the relax level the day search would need to take it, so a
     * place outside the preferred districts (level 2) or interests (level 3) counts as the
     * search counts it, not at full value.
     */
    public function layoutValue(array $place): float
    {
        $p = $this->profile;
        $level = 0;
        if (!in_array(strtolower((string)$place['category']), $p['interests'], true)) $level = 3;
        elseif ($p['districts'] && !in_array(strtolower((string)$place['district']), $p['districts'], true)) $level = 2;
        return $this->placeValue($place) - self::RELAX_PRICE * $level;
    }

    /** Why a must-see place found no slot in a day: at the day's start, or else at its end. */
    private function mustReason(array $place, array $day, array $endState): string
    {
        $reason = null;
        foreach ([self::startState($day, $this->profile), $endState] as $state) {
            $rejections = [];
            if ($this->ranked([$place], $day, $state, 1, $rejections, true)) continue;
            $here = array_key_first($rejections);
            if ($here !== null && ($reason === null || $reason === 'WAIT')) $reason = $here;
        }
        return $reason ?? 'ROOM';
    }

    /** The state of a day before its first stop. */
    public static function startState(array $day, array $profile): array
    {
        return [
            'point' => $day['start'],
            'clock' => (int)$profile['start'],
            'day_km' => 0.0,
            'first_leg' => !empty($day['first_leg_exempt']),
            'used' => (array)($day['used'] ?? []),
            'trip_items' => (array)($day['trip_items'] ?? []),
            'trip_km' => (float)($day['trip_km'] ?? 0),
            'categories' => [],
            'prev_end' => null,   // end of the last visit
            'meals' => [],        // slot => true once lunch or dinner is had
        ];
    }

    /** The state after a stop. */
    public static function advance(array $state, array $stop, array $profile): array
    {
        $place = $stop['place'];
        $category = strtolower((string)$place['category']);
        foreach ((array)($stop['meals'] ?? []) as $meal) {
            if ($meal['place'] !== null) $state['used'][(int)$meal['place']['place_id']] = true;
            $state['meals'][$meal['slot']] = true;
        }
        if (!empty($stop['food_meal'])) $state['meals'][$stop['food_meal']] = true;
        $mealKm = (float)($stop['meal_km'] ?? 0);   // a meal is never before the first visit, so it always counts
        if (!$state['first_leg']) $state['day_km'] += (float)$stop['km'];
        $state['day_km'] += $mealKm;
        $state['first_leg'] = false;
        $state['trip_km'] += (float)$stop['km'] + $mealKm;
        $state['prev_end'] = (int)$stop['end'];
        $state['trip_items'][] = ['item_type' => PlannerRules::itemType($place), 'estimated_cost' => PlannerRules::placeCost($place)];
        $state['used'][(int)$place['place_id']] = true;
        $state['categories'][$category] = ($state['categories'][$category] ?? 0) + 1;
        $state['point'] = $stop['point'];
        $state['clock'] = (int)$stop['end'] + (int)$profile['rest'];
        return $state;
    }

    /**
     * The feasible candidates for the next stop, best first, at the first relax level that has
     * any (ties broken by place_id).
     *
     * @param array|null $rejections when given, counts why candidates failed (at the last level)
     * @param bool       $must       the pool holds must-see places: no soft preference applies
     * @param bool       $everyLevel go on through every relax level: the candidates of the first
     *                               level with any come first, then those only a later level allows
     *                               (for lists of alternatives; the planner itself takes the first)
     */
    public function ranked(array $pool, array $day, array $state, int $limit = 1, ?array &$rejections = null, bool $must = false, bool $everyLevel = false): array
    {
        $collected = [];
        $date = (string)$day['date'];
        $legCounts = !$state['first_leg'];
        $finish = $day['finish'] ?? null;
        $evaluated = [];   // place index => [segment, check] for reuse across levels
        for ($level = $must ? self::LAST_LEVEL : 0; $level <= self::LAST_LEVEL; $level++) {
            $found = [];
            foreach ($pool as $index => $place) {
                if (isset($state['used'][(int)$place['place_id']]) || isset($collected[(int)$place['place_id']])) continue;
                if (!isset($evaluated[$index])) {
                    $seg = $this->route->getSegment((float)$state['point'][0], (float)$state['point'][1], (float)$place['latitude'], (float)$place['longitude']);
                    $evaluated[$index] = [$seg, null];
                }
                $seg = $evaluated[$index][0];
                if (!$this->softAllows($place, $level, $state, (float)$seg['distance_km'], $legCounts)) continue;
                if ($evaluated[$index][1] === null) {
                    $toFinish = $finish !== null
                        ? $this->route->getSegment((float)$place['latitude'], (float)$place['longitude'], (float)$finish[0], (float)$finish[1])
                        : null;
                    $evaluated[$index][1] = self::fit($this->route, $this->profile, $this->mealIndex, $place, [
                        'date' => $date,
                        'point' => $state['point'],
                        'clock' => (int)$state['clock'],
                        'prev_end' => $state['prev_end'],
                        'meals' => $state['meals'],
                        'leg_counts' => $legCounts,
                        'day_km' => (float)$state['day_km'],
                        'used' => $state['used'],
                        'meal_used' => $state['used'],
                        'trip_items' => $state['trip_items'],
                        'trip_km' => (float)$state['trip_km'],
                        'reserve_km' => (float)($day['reserve_km'] ?? 0),
                        'finish' => $toFinish !== null ? [(float)$toFinish['distance_km'], (int)$toFinish['travel_time_min']] : null,
                        'finish_code' => (string)($day['finish_code'] ?? 'FINISH'),
                    ], $seg);
                }
                $check = $evaluated[$index][1];
                if (!$check['ok']) {
                    if ($rejections !== null && $level === self::LAST_LEVEL) {
                        $rejections[$check['reason']] = ($rejections[$check['reason']] ?? 0) + 1;
                    }
                    continue;
                }
                $scored = $this->score($place, $state, $day, (float)$seg['distance_km'], $check, $must ? 0 : $level, $must);
                $found[] = [
                    'place' => $place,
                    'point' => [(float)$place['latitude'], (float)$place['longitude']],
                    'start' => $check['start'],
                    'end' => $check['end'],
                    'km' => $check['km'],
                    'min' => $check['min'],
                    'meals' => $check['meals'],
                    'meal_km' => $check['meal_km'],
                    'meal_min' => $check['meal_min'],
                    'food_meal' => $check['food_meal'],
                    'level' => $must ? 0 : $level,
                    'must' => $must,
                    'tier' => $scored['tier'],
                    'score' => $scored['score'],
                    'base' => $scored['base'],
                    'reasons' => $scored['reasons'],
                ];
            }
            if ($found) {
                $byScore = static fn(string $key): Closure => static fn(array $a, array $b): int =>
                    [$b[$key], $a['place']['place_id']] <=> [$a[$key], $b['place']['place_id']];
                $rain = $must ? null : self::rainOf($day);
                $plain = $found;
                usort($plain, $byScore('base'));
                if ($rain !== null && $rain >= self::RAIN_INDOOR_FIRST) {
                    $indoor = array_values(array_filter($found, static fn(array $f): bool => !PlannerRules::isOutdoor($f['place'])));
                    if ($indoor) $found = $indoor;   // outdoor places only once no indoor place of this level is left
                }
                usort($found, $byScore('score'));
                if ($rain !== null && $rain >= self::RAIN_PREFER) {
                    $note = null;
                    if ((int)$found[0]['place']['place_id'] !== (int)$plain[0]['place']['place_id']) {
                        $note = $rain . '% chance of rain forecast: indoor place preferred';
                    } elseif ($rain >= self::RAIN_INDOOR_FIRST && PlannerRules::isOutdoor($found[0]['place'])) {
                        $note = 'outdoor although ' . $rain . '% chance of rain: no indoor place fits';
                    }
                    // after the relax note, so a cut note keeps both
                    if ($note !== null) array_splice($found[0]['reasons'], (int)$found[0]['level'] > 0 ? 1 : 0, 0, [$note]);
                }
                if (!$everyLevel) return array_slice($found, 0, max(1, $limit));
                foreach ($found as $f) $collected[(int)$f['place']['place_id']] = $f;
                if (count($collected) >= $limit) break;
            }
        }
        return array_slice(array_values($collected), 0, max(1, $limit));
    }

    /**
     * A stop of the search (a spare) taken into a day that already holds $kept: its relax level
     * and the note of it as that day sees it (category variety, districts, interest and the
     * recommended distance judged after the kept stops), so its notes stay true.
     */
    public function relevel(array $stop, array $day, array $kept): array
    {
        if (!empty($stop['must'])) return $stop;
        $state = self::startState($day, $this->profile);
        foreach ($kept as $k) $state = self::advance($state, $k, $this->profile);
        $place = $stop['place'];
        $leg = $this->route->getSegment((float)$state['point'][0], (float)$state['point'][1], (float)$place['latitude'], (float)$place['longitude']);
        $level = 0;
        while ($level < self::LAST_LEVEL && !$this->softAllows($place, $level, $state, (float)$leg['distance_km'], !$state['first_leg'])) $level++;
        $level = max($level, (int)($stop['level'] ?? 0));
        $reasons = array_values(array_filter((array)($stop['reasons'] ?? []), static fn($r): bool => !in_array($r, self::RELAXED, true)));
        if ($level > 0) array_unshift($reasons, self::RELAXED[$level]);
        return ['level' => $level, 'reasons' => $reasons] + $stop;
    }

    /** The soft preferences of a relax level. */
    private function softAllows(array $place, int $level, array $state, float $legKm, bool $legCounts): bool
    {
        $category = strtolower((string)$place['category']);
        $p = $this->profile;
        if ($level < 1 && !$p['food_only'] && ($state['categories'][$category] ?? 0) >= PlannerRules::categoryLimit($p, $category)) return false;
        if ($level < 2 && $p['districts'] && !in_array(strtolower((string)$place['district']), $p['districts'], true)) return false;
        if ($level < 3 && !in_array($category, $p['interests'], true)) return false;
        if ($level < 4 && $legCounts && (float)$state['day_km'] + $legKm > (float)$p['day_km']) return false;
        return true;
    }

    /**
     * The one scoring function: deterministic, every term depends on the place or the slot.
     *
     * @return array{score:float,base:float,value:float,tier:int,reasons:string[]} base: the score
     *         without the weather; value: what the stop adds to the value of the day in the day
     *         search (score() without nearness, detour and waiting, which the search prices as
     *         minutes, less RELAX_PRICE per relaxed level, with the weather)
     */
    private function score(array $place, array $state, array $day, float $legKm, array $check, int $level, bool $must = false): array
    {
        $p = $this->profile;
        $score = 0.0;
        $reasons = [];
        $category = strtolower((string)$place['category']);

        $interestMatch = in_array($category, $p['interests'], true);
        $districtMatch = $p['districts'] && in_array(strtolower((string)$place['district']), $p['districts'], true);
        if ($interestMatch) {
            $weight = (int)($p['interest_weights'][$category] ?? 2);
            $score += self::INTEREST_POINTS[$weight] ?? self::INTEREST_POINTS[2];
            $reasons[] = 'interest match';
            if ($weight === 3) $reasons[] = 'an interest you love';
        }
        if ($districtMatch) { $score += 12; $reasons[] = 'in a preferred district'; }

        // Learned from the traveller's own ratings: small, and only between places that already
        // passed every rule and every soft preference of this level.
        $learned = $p['learned'][$category] ?? null;
        if ($learned !== null && (float)$learned['points'] !== 0.0) {
            $score += (float)$learned['points'];
            if ((float)$learned['points'] > 0) {
                $reasons[] = sprintf('you rated %s places %.1f/5 before', $category, (float)$learned['avg']);
            }
        }

        $suits = self::travellerSuitability($place, !empty($p['family']) ? 'family' : (string)$p['traveller_type']);
        $score += $suits;
        if ($suits >= 10) $reasons[] = 'traveller type suitability';

        $timeFit = self::timeFit($place, (string)$p['visit_time'], (int)$check['start']);
        $score += $timeFit;
        if ($timeFit > 0) $reasons[] = 'preferred visit time fit';

        if (PlannerRules::placeCost($place) <= 0) { $score += 10; $reasons[] = 'free place supports budget'; }
        else { $reasons[] = 'fits the trip budget'; }

        $rating = max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0));
        if ($rating > 0) { $score += min(8.0, ($rating / 5) * 8); $reasons[] = 'rating considered'; }

        $same = (int)($state['categories'][$category] ?? 0);
        if ($same === 0) { $score += 10; $reasons[] = 'adds category diversity'; }
        elseif ($same >= PlannerRules::categoryLimit($p, $category) && !$p['food_only']) { $score -= 12; $reasons[] = 'category repeated'; }
        $theme = $day['previous_theme'] ?? null;
        if ($theme !== null && $category === $theme && !$p['food_only']) { $score -= 15; $reasons[] = "repeats the previous day's theme"; }
        // The value the day search adds up: the terms so far; the travelling and waiting below
        // are priced by the search itself (TRAVEL_PRICE, IDLE_PRICE).
        $value = $score - self::RELAX_PRICE * $level;

        $near = max(0.0, 14 - min(14.0, $legKm));
        if ($near > 0) { $score += $near; $reasons[] = 'near previous stop'; }
        if (!$state['first_leg'] && $legKm > (float)$p['day_km'] / max(1, (int)$p['target'])) { $score -= min(25.0, $legKm); $reasons[] = 'detour penalty'; }

        if ((int)$check['wait'] > 0) { $score -= (int)$check['wait'] / 6; $reasons[] = 'waits ' . (int)$check['wait'] . ' min for opening'; }
        if ((int)($check['idle'] ?? 0) > 0) $score -= (int)$check['idle'] / 6;
        if (!empty($check['food_meal'])) $reasons[] = 'also your ' . $check['food_meal'];
        if ($level > 0) array_unshift($reasons, self::RELAXED[$level]);   // first, so a cut note keeps it
        if ($must) array_unshift($reasons, 'on your must-see list');

        $base = $score;
        $rain = $must ? null : self::rainOf($day);
        if ($rain !== null && $rain >= self::RAIN_PREFER) {
            $wet = PlannerRules::isOutdoor($place) ? -self::RAIN_OUTDOOR_POINTS : self::RAIN_INDOOR_POINTS;
            $score += $wet;
            $value += $wet;
        }

        $hasDistricts = (bool)$p['districts'];
        $tier = $hasDistricts
            ? ($districtMatch ? ($interestMatch ? 1 : 2) : ($interestMatch ? 3 : 4))
            : ($interestMatch ? 1 : 2);
        return ['score' => round($score, 4), 'base' => round($base, 4), 'value' => round($value, 4), 'tier' => $tier, 'reasons' => $reasons];
    }

    /** The day's chance of rain in percent, or null when no forecast is known. */
    private static function rainOf(array $day): ?int
    {
        return isset($day['rain']) && is_numeric($day['rain']) ? max(0, min(100, (int)$day['rain'])) : null;
    }

    /** The words that start the note of a stop whose opening hours were assumed (OpeningHours::ASSUMED). */
    public const ASSUMED_NOTE = 'opening hours assumed';

    /** "opening hours assumed (09:00-17:00): check before you go". */
    public static function assumedNote(array $place): string
    {
        return self::ASSUMED_NOTE . ' (' . OpeningHours::assumedText($place) . '): check before you go';
    }

    /** The notes stored with a stop: the format the itinerary pages read ("Reason:" ...). */
    public static function notes(array $stop, array $profile): string
    {
        $place = $stop['place'];
        $tierText = $profile['districts']
            ? match ((int)$stop['tier']) {
                1 => 'selected district and interest match',
                2 => 'selected district; interest relaxed',
                3 => 'nearest same-state district and interest match',
                default => 'nearest same-state district fallback',
            }
            : ((int)$stop['tier'] === 1 ? 'interest match within the selected area' : 'nearest available place within the selected area');
        // Whether a food visit is the meal is known only once the day is timed in its final order.
        $reasons = array_filter((array)$stop['reasons'], static fn($r): bool => !str_starts_with((string)$r, 'also your ') && !str_starts_with((string)$r, self::ASSUMED_NOTE));
        if (!empty($stop['food_meal'])) $reasons[] = 'also your ' . $stop['food_meal'];
        if (OpeningHours::isAssumed($place)) $reasons[] = self::assumedNote($place);
        $reasons = implode('; ', array_values(array_unique(array_filter(array_diff($reasons, ['interest match', 'in a preferred district'])))));
        $text = 'State: ' . $place['state'] . ' | District: ' . (($place['district'] ?? '') !== '' ? $place['district'] : '-')
            . ' | Category: ' . $place['category'] . ' | Tier: ' . (int)$stop['tier']
            . ' | Reason: ' . $tierText . ($reasons !== '' ? '; ' . $reasons : '');
        return mb_strlen($text) <= 1000 ? $text : mb_substr($text, 0, 997) . '...';
    }

    /**
     * Time the next visit of a day together with the day's meals: the one timing of the day
     * builder and the route optimiser (see the head of this file for the meal rule).
     *
     * @param array $at the day so far: date; point (where the traveller is); clock (the day's
     *                  start, or the end of the last visit plus rest); prev_end (end of the last
     *                  visit, null before the first); meals (slot => true once had); leg_counts
     *                  (false only for the first leg of day 1); day_km; trip_km; reserve_km;
     *                  optional for check(): used and trip_items; meal_used (place_id => true:
     *                  places no meal may use); finish (null, or [km, min] from the place to
     *                  where the day must end when that leg is a rule: the trip's end point,
     *                  or the base of the night); finish_code (FINISH or BASE: the reason when
     *                  that leg breaks the rule); meal_choice (optional,
     *                  slot => ['place' => a food place row, or null for a break without a
     *                  place, 'strict' => bool]: the meal the traveller chose. Strict: only
     *                  that meal may be had; otherwise it is tried first and the usual choice
     *                  follows when it no longer fits)
     * With basePlace() as the place, fit() times the end of a day followed by a night instead
     * (closeDay()): the way back to the base and the dinner, when still due.
     *
     * @param array|null $leg the leg from $at['point'] to the place, when already known
     * @return array ok, reason, start, end, wait; km and min (the leg into the visit, from the
     *         meal place when a meal comes first); meals (the meals before the visit: slot,
     *         place (null for a break), point, start, end, km, min (the leg to the meal), idle,
     *         detour); meal_km, meal_min, idle (waiting for a meal window); food_meal (the meal
     *         the visit itself is, when it is a food place starting in a meal window)
     */
    public static function fit(RouteService $route, array $profile, array $mealIndex, array $place, array $at, ?array $leg = null): array
    {
        // The base of the night as the place: the end of the day (closeDay()).
        if (!empty($place['base'])) return self::closeDay($route, $profile, $mealIndex, $place, $at, $leg);
        $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'start' => 0, 'end' => 0, 'wait' => 0];
        $point = [(float)$place['latitude'], (float)$place['longitude']];
        $seg = static fn(array $a, array $b): array => $route->getSegment((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]);
        $date = (string)$at['date'];
        // $leg stays the leg as measured or estimated; peak() gives its minutes at the hour it leaves.
        $leg ??= $seg($at['point'], $point);
        $time = static function (int $arrive, array $leg, bool $counts, float $dayKm, float $extraKm) use ($place, $at, $profile): array {
            $slot = [
                'date' => (string)$at['date'],
                'arrive' => $arrive,
                'leg_km' => (float)$leg['distance_km'],
                'leg_counts' => $counts,
                'day_km' => $dayKm,
                'trip_km' => (float)($at['trip_km'] ?? 0) + $extraKm,
                'reserve_km' => (float)($at['reserve_km'] ?? 0),
            ];
            if (array_key_exists('used', $at)) $slot['used'] = $at['used'];
            if (array_key_exists('trip_items', $at)) $slot['trip_items'] = $at['trip_items'];
            if (!empty($at['finish'])) {
                $slot['finish_km'] = (float)$at['finish'][0];
                $slot['finish_min'] = (int)$at['finish'][1];
                $slot['finish_code'] = (string)($at['finish_code'] ?? 'FINISH');
            }
            return PlannerRules::check($place, $slot, $profile);
        };

        $dayKm = (float)($at['day_km'] ?? 0);
        // The leg leaves when the visit before it ended (the day's start for the first leg).
        $direct = self::peak($profile, $leg, $date, (int)($at['prev_end'] ?? $at['clock']), $at['point']);
        $check = $time((int)$at['clock'] + (int)$direct['travel_time_min'], $direct, (bool)($at['leg_counts'] ?? true), $dayKm, 0.0);
        $used = $direct;   // the leg into the visit as timed
        $meals = [];
        $mealKm = 0.0;
        $mealMin = 0;
        $idle = 0;
        $foodMeal = null;
        $from = $at['point'];
        $after = $at['prev_end'] ?? null;   // the end of what came last: a visit or a meal
        $isFood = strtolower((string)($place['category'] ?? '')) === 'food';
        $exclude = (array)($at['meal_used'] ?? []) + [(int)($place['place_id'] ?? 0) => true];
        // Meals are left out only where they cannot be stored (profile key meals = false).
        foreach (($profile['meals'] ?? true) ? PlannerRules::MEAL_WINDOWS : [] as $slotName => [$open, $close]) {
            if (!empty($at['meals'][$slotName])) continue;
            if ($check['ok'] && $isFood && $check['start'] >= $open && $check['start'] <= $close) {
                $foodMeal = $slotName;   // this visit is the meal
                continue;
            }
            if ($after === null || $after > $close) continue;
            $due = $check['ok'] ? $check['end'] > $close : $check['reason'] === 'WAIT';
            if (!$due) continue;

            $chosen = null;
            $reason = 'MEAL';
            $usual = static fn(): array => array_filter([
                self::mealAtPlace($route, $profile, $mealIndex, $slotName, $from, $point, $leg, (int)$after, $date, $exclude),
                self::mealBreak($profile, $slotName, (int)$after),
            ]);
            $choice = $at['meal_choice'][$slotName] ?? null;
            if (is_array($choice)) {
                $wanted = !empty($choice['place'])
                    ? self::mealAtPlace($route, $profile, self::mealIndex([$choice['place']], (float)$profile['meal_radius_km']), $slotName, $from, $point, $leg, (int)$after, $date, $exclude)
                    : self::mealBreak($profile, $slotName, (int)$after);
                if ($wanted !== null) $wanted['chosen'] = true;
                $options = array_merge($wanted !== null ? [$wanted] : [], !empty($choice['strict']) ? [] : $usual());
            } else {
                $options = $usual();
            }
            foreach ($options as $meal) {
                // After the meal the leg into the visit leaves when the meal ends.
                $base = $meal['point'] !== null ? $seg($meal['point'], $point) : $leg;
                $into = self::peak($profile, $base, $date, (int)$meal['end'], $meal['point'] ?? $from);
                $again = $time($meal['end'] + (int)$into['travel_time_min'], $into, true, $dayKm + $meal['km'], $mealKm + $meal['km']);
                if ($again['ok']) { $chosen = [$meal, $base, $into, $again]; break; }
                $reason = (string)$again['reason'];
            }
            if ($chosen === null) return $fail($reason);
            [$meal, $leg, $used, $check] = $chosen;
            $meals[] = $meal;
            $mealKm += $meal['km'];
            $mealMin += $meal['min'];
            $idle += $meal['idle'];
            $dayKm += $meal['km'];
            if ($meal['point'] !== null) {
                $from = $meal['point'];
                $exclude[(int)$meal['place']['place_id']] = true;
            }
            $after = $meal['end'];
        }
        if (!$check['ok']) return $fail((string)$check['reason']);
        return $check + [
            'km' => round((float)$used['distance_km'], 2),
            'min' => (int)$used['travel_time_min'],
            'meals' => $meals,
            'meal_km' => round($mealKm, 2),
            'meal_min' => $mealMin,
            'idle' => $idle,
            'food_meal' => $foodMeal,
        ];
    }

    /**
     * The end of a day followed by a night (fit() with basePlace()): the way back to the base
     * after the last visit, with dinner when it is still due. The leg back counts in the day's
     * distance and the traveller is back by the end of the day window (else $at['finish_code'],
     * BASE). Dinner, when the day has none yet and it fits inside the day window: at the food
     * place near the base (within the meal radius, road km, of it) with the least detour on the
     * way back, open for the whole meal, starting inside the window and ending in time to be back;
     * else a dinner break at the base once the traveller is back. Waiting there for the window is
     * rest, not waiting. A meal the traveller chose ($at['meal_choice']['dinner']) comes first.
     *
     * @return array ok, reason, start and end (both: the arrival at the base), km and min (the
     *         leg into the base, from the dinner place when dinner is at one), meals (the dinner,
     *         marked at_base), meal_km, meal_min, idle 0, food_meal null, arrive
     */
    private static function closeDay(RouteService $route, array $profile, array $mealIndex, array $base, array $at, ?array $leg): array
    {
        $code = (string)($at['finish_code'] ?? 'BASE');
        $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'start' => 0, 'end' => 0, 'wait' => 0];
        $point = [(float)$base['latitude'], (float)$base['longitude']];
        $date = (string)$at['date'];
        $leg ??= $route->getSegment((float)$at['point'][0], (float)$at['point'][1], $point[0], $point[1]);
        $after = $at['prev_end'] ?? null;
        $done = static fn(int $arrive, array $into, array $meals, float $mealKm, int $mealMin): array => [
            'ok' => true, 'reason' => null, 'start' => $arrive, 'end' => $arrive, 'wait' => 0,
            'km' => round((float)$into['distance_km'], 2), 'min' => (int)$into['travel_time_min'],
            'meals' => $meals, 'meal_km' => round($mealKm, 2), 'meal_min' => $mealMin, 'idle' => 0, 'food_meal' => null, 'arrive' => $arrive,
        ];
        if ($after === null) return $done((int)$at['clock'], ['distance_km' => 0.0, 'travel_time_min' => 0], [], 0.0, 0);
        // The way back leaves when the last visit ends (peak hours).
        $leg = self::peak($profile, $leg, $date, (int)$after, $at['point']);
        $dayKm = (float)($at['day_km'] ?? 0);
        $end = (int)$profile['end'];
        $hard = (float)$profile['hard_km'];
        [$open, $close] = PlannerRules::MEAL_WINDOWS['dinner'];
        $length = (int)$profile['meal_min'];
        $due = ($profile['meals'] ?? true) && empty($at['meals']['dinner']) && $open + $length <= $end;
        if (!$due) {
            $arrive = (int)$after + (int)$leg['travel_time_min'];
            if ($arrive > $end || $dayKm + (float)$leg['distance_km'] > $hard) return $fail($code);
            return $done($arrive, $leg, [], 0.0, 0);
        }
        // A dinner near the base is not touring: the traveller is back in the base's area by the
        // end of the day window, and the dinner begins by the latest start of its window; it may
        // end after the day window.
        $exclude = (array)($at['meal_used'] ?? []);
        $atPlace = static function (array $index) use ($route, $profile, $point, $at, $after, $leg, $open, $close, $length, $end, $dayKm, $hard, $exclude, $date): ?array {
            $radius = (float)$profile['meal_radius_km'];
            $best = null;
            foreach (self::nearMeals($index, [$point]) as $id => $place) {
                if (isset($exclude[$id]) || PlannerRules::twinIn($profile, $id, $exclude) !== null) continue;
                $here = [(float)$place['latitude'], (float)$place['longitude']];
                $in = self::peak($profile, $route->getSegment((float)$at['point'][0], (float)$at['point'][1], $here[0], $here[1]), $date, (int)$after, $at['point']);
                $outBase = $route->getSegment($here[0], $here[1], $point[0], $point[1]);
                if ((float)$outBase['distance_km'] > $radius) continue;
                $arrive = (int)$after + (int)$in['travel_time_min'];
                if ($arrive > $end) continue;
                $start = OpeningHours::earliestIn(PlannerRules::hoursOn($place, $date), max($arrive, $open), $length, $close + $length, [], PlannerRules::TIME_STEP);
                if ($start === null) continue;
                $out = self::peak($profile, $outBase, $date, $start + $length, $here);
                if ($dayKm + (float)$in['distance_km'] + (float)$out['distance_km'] > $hard) continue;
                $detour = (float)$in['distance_km'] + (float)$out['distance_km'] - (float)$leg['distance_km'];
                $rank = [round($detour, 3), $id];
                if ($best !== null && $rank >= $best[0]) continue;
                $best = [$rank, [
                    'slot' => 'dinner', 'place' => $place, 'point' => $here, 'start' => $start, 'end' => $start + $length,
                    'km' => round((float)$in['distance_km'], 2), 'min' => (int)$in['travel_time_min'],
                    'idle' => $start - $arrive, 'detour' => round(max(0.0, $detour), 2), 'at_base' => true,
                ], $out];
            }
            return $best !== null ? [$best[1], $best[2]] : null;
        };
        $atBase = static function () use ($after, $leg, $open, $close, $end, $dayKm, $hard, $length): ?array {
            $arrive = (int)$after + (int)$leg['travel_time_min'];
            $start = PlannerRules::roundUp(max($arrive, $open));
            if ($arrive > $end || $start > $close || $dayKm + (float)$leg['distance_km'] > $hard) return null;
            return [['slot' => 'dinner', 'place' => null, 'point' => null, 'start' => $start, 'end' => $start + $length, 'km' => 0.0, 'min' => 0,
                     'idle' => $start - $arrive, 'detour' => 0.0, 'at_base' => true], $leg];
        };
        $options = [];
        $choice = $at['meal_choice']['dinner'] ?? null;
        if (is_array($choice)) {
            $wanted = !empty($choice['place']) ? $atPlace(self::mealIndex([$choice['place']], (float)$profile['meal_radius_km'])) : $atBase();
            if ($wanted !== null) { $wanted[0]['chosen'] = true; $options[] = $wanted; }
            if (empty($choice['strict'])) $options[] = $atPlace($mealIndex) ?? $atBase();
        } else {
            $options[] = $atPlace($mealIndex) ?? $atBase();
        }
        foreach ($options as $option) {
            if ($option === null) continue;
            [$meal, $into] = $option;
            $arrive = $meal['place'] !== null ? (int)$meal['end'] + (int)$into['travel_time_min'] : (int)$after + (int)$into['travel_time_min'];
            return $done($arrive, $into, [$meal], (float)$meal['km'], (int)$meal['min']);
        }
        return $fail('MEAL');
    }

    /**
     * The meal at the food place with the least detour between $from and $to (ties: lower
     * place_id): within the meal radius (road km) of either, open for the whole meal, starting
     * inside the window and ending inside the day, and not waiting longer for the window than
     * the rest plus PlannerRules::MAX_WAIT_MIN. Null when there is none.
     */
    private static function mealAtPlace(RouteService $route, array $profile, array $index, string $slot, array $from, array $to, array $direct,
                                        int $after, string $date, array $exclude): ?array
    {
        [$open, $close] = PlannerRules::MEAL_WINDOWS[$slot];
        $length = (int)$profile['meal_min'];
        $radius = (float)$profile['meal_radius_km'];
        $best = null;
        foreach (self::nearMeals($index, [$from, $to]) as $id => $place) {
            // Not a place of the trip, nor the same landmark as one (PlannerRules::sameLandmarks()).
            if (isset($exclude[$id]) || PlannerRules::twinIn($profile, $id, $exclude) !== null) continue;
            $here = [(float)$place['latitude'], (float)$place['longitude']];
            $in = self::peak($profile, $route->getSegment((float)$from[0], (float)$from[1], $here[0], $here[1]), $date, $after, $from);
            $out = $route->getSegment($here[0], $here[1], (float)$to[0], (float)$to[1]);
            if ((float)$in['distance_km'] > $radius && (float)$out['distance_km'] > $radius) continue;
            $arrive = $after + (int)$in['travel_time_min'];
            $start = OpeningHours::earliestIn(PlannerRules::hoursOn($place, $date), max($arrive, $open), $length, min((int)$profile['end'], $close + $length), [], PlannerRules::TIME_STEP);
            if ($start === null || $start - $arrive > (int)$profile['rest'] + PlannerRules::MAX_WAIT_MIN) continue;
            $detour = (float)$in['distance_km'] + (float)$out['distance_km'] - (float)$direct['distance_km'];
            $rank = [round($detour, 3), $id];
            if ($best !== null && $rank >= $best[0]) continue;
            $best = [$rank, [
                'slot' => $slot, 'place' => $place, 'point' => $here, 'start' => $start, 'end' => $start + $length,
                'km' => round((float)$in['distance_km'], 2), 'min' => (int)$in['travel_time_min'],
                'idle' => $start - $arrive, 'detour' => round(max(0.0, $detour), 2),
            ]];
        }
        return $best[1] ?? null;
    }

    /** A leg with its minutes at the hour it leaves from $from (PlannerRules::peakMinutes(): peak hours). */
    private static function peak(array $profile, array $leg, string $date, int $depart, ?array $from): array
    {
        if ($from === null) return $leg;
        $minutes = PlannerRules::peakMinutes((int)$leg['travel_time_min'], $date, $depart, $from, (string)($profile['transport'] ?? 'car'));
        return $minutes === (int)$leg['travel_time_min'] ? $leg : ['travel_time_min' => $minutes] + $leg;
    }

    /** A meal break without a place, right where the traveller is; null when it cannot be had. */
    private static function mealBreak(array $profile, string $slot, int $after): ?array
    {
        [$open, $close] = PlannerRules::MEAL_WINDOWS[$slot];
        $start = PlannerRules::roundUp(max($after, $open));
        $end = $start + (int)$profile['meal_min'];
        if ($start > $close || $end > (int)$profile['end'] || $start - $after > (int)$profile['rest'] + PlannerRules::MAX_WAIT_MIN) return null;
        return ['slot' => $slot, 'place' => null, 'point' => null, 'start' => $start, 'end' => $end, 'km' => 0.0, 'min' => 0,
                'idle' => $start - $after, 'detour' => 0.0];
    }

    /** The meal places in square cells a little wider than the meal radius, for a quick search near a point. */
    public static function mealIndex(array $pool, float $radiusKm): array
    {
        $cell = max(0.01, $radiusKm / 100.0);   // a degree is at least 110 km here, so a cell is wider than the radius
        $cells = [];
        foreach ($pool as $place) {
            $cells[(int)floor((float)$place['latitude'] / $cell) . ':' . (int)floor((float)$place['longitude'] / $cell)][] = $place;
        }
        return ['cell' => $cell, 'radius' => $radiusKm, 'cells' => $cells];
    }

    /** Meal places within the radius (straight line, never more than the road) of any of the points, by place_id. */
    private static function nearMeals(array $index, array $points): array
    {
        if (empty($index['cells'])) return [];
        $out = [];
        foreach ($points as $p) {
            $y = (int)floor((float)$p[0] / $index['cell']);
            $x = (int)floor((float)$p[1] / $index['cell']);
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    foreach ($index['cells'][($y + $dy) . ':' . ($x + $dx)] ?? [] as $place) {
                        $id = (int)$place['place_id'];
                        if (isset($out[$id])) continue;
                        if (RouteService::haversineKm((float)$p[0], (float)$p[1], (float)$place['latitude'], (float)$place['longitude']) <= $index['radius']) $out[$id] = $place;
                    }
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** The words in a meal row's notes that mark a meal the traveller chose (ItineraryEditService keeps it). */
    public const MEAL_CHOSEN = 'you chose';

    /** The title stored with a meal row: the place's name, or "Lunch break" / "Dinner break". */
    public static function mealTitle(array $meal): string
    {
        return $meal['place'] !== null ? (string)$meal['place']['name'] : ucfirst((string)$meal['slot']) . ' break';
    }

    /** The notes stored with a meal row, in the format the itinerary pages read ("Reason:" ...). */
    public static function mealNotes(array $meal, array $profile): string
    {
        $slot = (string)$meal['slot'];
        $minutes = (int)$meal['end'] - (int)$meal['start'];
        if (!empty($meal['at_base']) && empty($meal['chosen'])) {
            if ($meal['place'] === null) {
                $text = 'Meal: ' . $slot . ' | Reason: Time kept free for ' . $slot . ' (' . $minutes . ' min) where you stay tonight: no food place within '
                    . rtrim(rtrim(number_format((float)$profile['meal_radius_km'], 1), '0'), '.') . ' km of it is open for the whole meal, so eat near your base.';
            } else {
                $place = $meal['place'];
                $diet = match ((string)$profile['dietary']) { 'halal' => '; marked halal', 'vegetarian' => '; vegetarian', default => '' };
                $text = 'State: ' . $place['state'] . ' | District: ' . (($place['district'] ?? '') !== '' ? $place['district'] : '-')
                    . ' | Category: food | Meal: ' . $slot . ' | Reason: ' . ucfirst($slot) . ' near where you stay tonight, at the food place with the least detour on the way back ('
                    . number_format((float)$meal['detour'], 1) . ' km extra)' . $diet . '; open for the whole meal; the cost is part of the daily meal estimate';
            }
        } elseif ($meal['place'] === null && !empty($meal['chosen'])) {
            $text = 'Meal: ' . $slot . ' | Reason: Time kept free for ' . $slot . ' (' . $minutes . ' min), as ' . self::MEAL_CHOSEN . ' a break without a set place.';
        } elseif ($meal['place'] === null) {
            $text = 'Meal: ' . $slot . ' | Reason: Time kept free for ' . $slot . ' (' . $minutes . ' min). No suitable food place within '
                . rtrim(rtrim(number_format((float)$profile['meal_radius_km'], 1), '0'), '.') . ' km of your stops is open for the whole meal, so eat near your last stop.';
        } elseif (!empty($meal['chosen'])) {
            $place = $meal['place'];
            $diet = match ((string)$profile['dietary']) { 'halal' => '; marked halal', 'vegetarian' => '; vegetarian', default => '' };
            $text = 'State: ' . $place['state'] . ' | District: ' . (($place['district'] ?? '') !== '' ? $place['district'] : '-')
                . ' | Category: food | Meal: ' . $slot . ' | Reason: ' . ucfirst($slot) . ' at the food place ' . self::MEAL_CHOSEN . ' ('
                . number_format((float)$meal['detour'], 1) . ' km extra)' . $diet . '; open for the whole meal; the cost is part of the daily meal estimate';
        } else {
            $place = $meal['place'];
            $diet = match ((string)$profile['dietary']) { 'halal' => '; marked halal', 'vegetarian' => '; vegetarian', default => '' };
            $text = 'State: ' . $place['state'] . ' | District: ' . (($place['district'] ?? '') !== '' ? $place['district'] : '-')
                . ' | Category: food | Meal: ' . $slot . ' | Reason: ' . ucfirst($slot) . ' at the food place with the least detour between your stops ('
                . number_format((float)$meal['detour'], 1) . ' km extra)' . $diet . '; open for the whole meal; the cost is part of the daily meal estimate';
        }
        if ($meal['place'] !== null && OpeningHours::isAssumed($meal['place'])) $text .= '; ' . self::assumedNote($meal['place']);
        return mb_strlen($text) <= 1000 ? $text : mb_substr($text, 0, 997) . '...';
    }

    private static function travellerSuitability(array $place, string $travellerType): int
    {
        $category = strtolower((string)$place['category']);
        $outdoor = (int)($place['is_outdoor'] ?? 0) === 1;
        $long = (int)($place['visit_duration_min'] ?? 90) > 150;
        $text = strtolower((string)$place['name'] . ' ' . (string)($place['description'] ?? ''));
        $narrow = str_contains($text, 'lane') || str_contains($text, 'small') || str_contains($text, 'narrow');
        return match ($travellerType) {
            'couple' => in_array($category, ['nature', 'food', 'culture', 'heritage'], true) ? 10 : 4,
            'family' => in_array($category, ['museum', 'nature', 'food', 'shopping'], true) && !$long ? 10 : 2,
            'group' => in_array($category, ['culture', 'museum', 'shopping', 'nature', 'food'], true) && !$narrow ? 10 : 2,
            default => $outdoor ? 6 : 5,
        };
    }

    private static function timeFit(array $place, string $visitTime, int $startMin): int
    {
        $category = strtolower((string)$place['category']);
        $best = strtolower((string)($place['best_time_to_visit'] ?? ''));
        if ($visitTime !== 'any' && str_contains($best, $visitTime)) return 8;
        if ($category === 'nature' && $startMin < 11 * 60) return 8;
        if ($category === 'museum' && $startMin >= 9 * 60 && $startMin <= 16 * 60) return 8;
        if (($category === 'shopping' || $category === 'food') && $startMin >= 17 * 60) return 6;
        return 0;
    }
}
