<?php
// services/ItineraryDayBuilder.php
// Fills one day of an itinerary. For each next stop the candidates are scored and tried in
// score order; one that breaks a rule of PlannerRules::check() is passed over for the next.
// When no candidate is left, the soft preferences are relaxed one level at a time:
//
//   level 0  every preference
//   level 1  category variety relaxed
//   level 2  preferred districts relaxed as well
//   level 3  interest match relaxed as well
//   level 4  the recommended daily distance relaxed as well (never the hard limit)
//
// Hard rules (opening hours, the day window, rest, distance limit, budget, accessibility,
// dietary, festival dates, states) are never relaxed. The budget is a running constraint:
// every candidate is checked against the projected trip total including itself.
//
// Must-see places given for the day are tried before any other stop at every step, with every
// soft preference relaxed but every hard rule kept; the places still to come keep their room
// in the day and have first call on the budget. On the last day of a trip with an end point,
// every stop must leave time and distance to reach it (PlannerRules::check(), FINISH).
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
// Weather (the day's chance of rain, when known): from RAIN_PREFER percent outdoor places
// lose score and indoor places gain; from RAIN_INDOOR_FIRST percent outdoor places are tried
// only when no indoor place of the same relax level is feasible. Never a hard rule.

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

    private array $profile;
    private RouteService $route;
    private array $mealIndex;

    /** @param array $mealPool the food places meals may use (PlannerCandidates::mealPool()) */
    public function __construct(array $profile, RouteService $route, array $mealPool = [])
    {
        $this->profile = $profile;
        $this->route = $route;
        $this->mealIndex = self::mealIndex($mealPool, (float)$profile['meal_radius_km']);
    }

    /**
     * Plan one day.
     *
     * @param array $pool candidate places (already narrowed by PlannerRules::placeReason())
     * @param array $day  date, start [lat, lng], first_leg_exempt (day 1: the leg from the
     *                    trip's starting point is not counted), used (place_id => true), trip_items
     *                    and trip_km (the other days' items and km), reserve_km (held back for the
     *                    days not planned yet), previous_theme (main category of the day before),
     *                    musts (must-see place rows for this day, not in $pool), finish (the
     *                    trip's end point [lat, lng], last day only)
     * @return array{stops:array,rejections:array<string,int>,unplaced:array<int,string>} stops in
     *         visiting order; rejections counts the reasons candidates failed for the first stop
     *         (for an empty day); unplaced: must-see place_id => reason code for those not planned
     */
    public function build(array $pool, array $day): array
    {
        $state = self::startState($day, $this->profile);
        $musts = array_values((array)($day['musts'] ?? []));
        $target = (int)$this->profile['target'];
        $stops = [];
        $rejections = [];
        while (count($stops) < $target) {
            $stop = null;
            if ($musts) {
                $ignored = null;
                $stop = $this->ranked($musts, $day, $state, 1, $ignored, true)[0] ?? null;
                if ($stop !== null) {
                    $placed = (int)$stop['place']['place_id'];
                    $musts = array_values(array_filter($musts, static fn(array $m): bool => (int)$m['place_id'] !== $placed));
                }
            }
            if ($stop === null) {
                // The rest of the day is kept for the must-see places still to come.
                if (count($stops) + count($musts) >= $target) break;
                // They also have first call on the budget.
                $regular = $state;
                foreach ($musts as $m) {
                    $regular['trip_items'][] = ['item_type' => PlannerRules::itemType($m), 'estimated_cost' => PlannerRules::placeCost($m)];
                }
                $ranked = $stops ? $this->ranked($pool, $day, $regular) : $this->ranked($pool, $day, $regular, 1, $rejections);
                if (!$ranked) break;
                $stop = $ranked[0];
            }
            $stops[] = $stop;
            $state = self::advance($state, $stop, $this->profile);
        }
        $unplaced = [];
        foreach ($musts as $m) $unplaced[(int)$m['place_id']] = $this->mustReason($m, $day, $state);
        return ['stops' => $stops, 'rejections' => $rejections, 'unplaced' => $unplaced];
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
     * @return array{score:float,base:float,tier:int,reasons:string[]} base: the score without the weather
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
            $score += PlannerRules::isOutdoor($place) ? -self::RAIN_OUTDOOR_POINTS : self::RAIN_INDOOR_POINTS;
        }

        $hasDistricts = (bool)$p['districts'];
        $tier = $hasDistricts
            ? ($districtMatch ? ($interestMatch ? 1 : 2) : ($interestMatch ? 3 : 4))
            : ($interestMatch ? 1 : 2);
        return ['score' => round($score, 4), 'base' => round($base, 4), 'tier' => $tier, 'reasons' => $reasons];
    }

    /** The day's chance of rain in percent, or null when no forecast is known. */
    private static function rainOf(array $day): ?int
    {
        return isset($day['rain']) && is_numeric($day['rain']) ? max(0, min(100, (int)$day['rain'])) : null;
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
        $reasons = array_filter((array)$stop['reasons'], static fn($r): bool => !str_starts_with((string)$r, 'also your '));
        if (!empty($stop['food_meal'])) $reasons[] = 'also your ' . $stop['food_meal'];
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
     *                  the trip's end point when that leg is a rule); meal_choice (optional,
     *                  slot => ['place' => a food place row, or null for a break without a
     *                  place, 'strict' => bool]: the meal the traveller chose. Strict: only
     *                  that meal may be had; otherwise it is tried first and the usual choice
     *                  follows when it no longer fits)
     * @param array|null $leg the leg from $at['point'] to the place, when already known
     * @return array ok, reason, start, end, wait; km and min (the leg into the visit, from the
     *         meal place when a meal comes first); meals (the meals before the visit: slot,
     *         place (null for a break), point, start, end, km, min (the leg to the meal), idle,
     *         detour); meal_km, meal_min, idle (waiting for a meal window); food_meal (the meal
     *         the visit itself is, when it is a food place starting in a meal window)
     */
    public static function fit(RouteService $route, array $profile, array $mealIndex, array $place, array $at, ?array $leg = null): array
    {
        $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'start' => 0, 'end' => 0, 'wait' => 0];
        $point = [(float)$place['latitude'], (float)$place['longitude']];
        $seg = static fn(array $a, array $b): array => $route->getSegment((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]);
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
            if (!empty($at['finish'])) { $slot['finish_km'] = (float)$at['finish'][0]; $slot['finish_min'] = (int)$at['finish'][1]; }
            return PlannerRules::check($place, $slot, $profile);
        };

        $dayKm = (float)($at['day_km'] ?? 0);
        $check = $time((int)$at['clock'] + (int)$leg['travel_time_min'], $leg, (bool)($at['leg_counts'] ?? true), $dayKm, 0.0);
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
                self::mealAtPlace($route, $profile, $mealIndex, $slotName, $from, $point, $leg, (int)$after, (string)$at['date'], $exclude),
                self::mealBreak($profile, $slotName, (int)$after),
            ]);
            $choice = $at['meal_choice'][$slotName] ?? null;
            if (is_array($choice)) {
                $wanted = !empty($choice['place'])
                    ? self::mealAtPlace($route, $profile, self::mealIndex([$choice['place']], (float)$profile['meal_radius_km']), $slotName, $from, $point, $leg, (int)$after, (string)$at['date'], $exclude)
                    : self::mealBreak($profile, $slotName, (int)$after);
                if ($wanted !== null) $wanted['chosen'] = true;
                $options = array_merge($wanted !== null ? [$wanted] : [], !empty($choice['strict']) ? [] : $usual());
            } else {
                $options = $usual();
            }
            foreach ($options as $meal) {
                $into = $meal['point'] !== null ? $seg($meal['point'], $point) : $leg;
                $again = $time($meal['end'] + (int)$into['travel_time_min'], $into, true, $dayKm + $meal['km'], $mealKm + $meal['km']);
                if ($again['ok']) { $chosen = [$meal, $into, $again]; break; }
                $reason = (string)$again['reason'];
            }
            if ($chosen === null) return $fail($reason);
            [$meal, $leg, $check] = $chosen;
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
            'km' => round((float)$leg['distance_km'], 2),
            'min' => (int)$leg['travel_time_min'],
            'meals' => $meals,
            'meal_km' => round($mealKm, 2),
            'meal_min' => $mealMin,
            'idle' => $idle,
            'food_meal' => $foodMeal,
        ];
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
            $in = $route->getSegment((float)$from[0], (float)$from[1], $here[0], $here[1]);
            $out = $route->getSegment($here[0], $here[1], (float)$to[0], (float)$to[1]);
            if ((float)$in['distance_km'] > $radius && (float)$out['distance_km'] > $radius) continue;
            $arrive = $after + (int)$in['travel_time_min'];
            $start = OpeningHours::earliestStart((string)($place['opening_hours'] ?? ''), $date, max($arrive, $open), $length, min((int)$profile['end'], $close + $length));
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

    /** A meal break without a place, right where the traveller is; null when it cannot be had. */
    private static function mealBreak(array $profile, string $slot, int $after): ?array
    {
        [$open, $close] = PlannerRules::MEAL_WINDOWS[$slot];
        $start = max($after, $open);
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
        if ($meal['place'] === null && !empty($meal['chosen'])) {
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
