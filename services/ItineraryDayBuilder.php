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

require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/RouteService.php';

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

    private array $profile;
    private RouteService $route;

    public function __construct(array $profile, RouteService $route)
    {
        $this->profile = $profile;
        $this->route = $route;
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
        ];
    }

    /** The state after a stop. */
    public static function advance(array $state, array $stop, array $profile): array
    {
        $place = $stop['place'];
        $category = strtolower((string)$place['category']);
        if (!$state['first_leg']) $state['day_km'] += (float)$stop['km'];
        $state['first_leg'] = false;
        $state['trip_km'] += (float)$stop['km'];
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
     */
    public function ranked(array $pool, array $day, array $state, int $limit = 1, ?array &$rejections = null, bool $must = false): array
    {
        $date = (string)$day['date'];
        $legCounts = !$state['first_leg'];
        $finish = $day['finish'] ?? null;
        $evaluated = [];   // place index => [segment, check] for reuse across levels
        for ($level = $must ? self::LAST_LEVEL : 0; $level <= self::LAST_LEVEL; $level++) {
            $found = [];
            foreach ($pool as $index => $place) {
                if (isset($state['used'][(int)$place['place_id']])) continue;
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
                    $evaluated[$index][1] = PlannerRules::check($place, [
                        'date' => $date,
                        'arrive' => (int)$state['clock'] + (int)$seg['travel_time_min'],
                        'leg_km' => (float)$seg['distance_km'],
                        'leg_counts' => $legCounts,
                        'day_km' => (float)$state['day_km'],
                        'used' => $state['used'],
                        'trip_items' => $state['trip_items'],
                        'trip_km' => (float)$state['trip_km'],
                        'reserve_km' => (float)($day['reserve_km'] ?? 0),
                    ] + ($toFinish !== null ? ['finish_km' => (float)$toFinish['distance_km'], 'finish_min' => (int)$toFinish['travel_time_min']] : []), $this->profile);
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
                    'km' => round((float)$seg['distance_km'], 2),
                    'min' => (int)$seg['travel_time_min'],
                    'level' => $must ? 0 : $level,
                    'must' => $must,
                    'tier' => $scored['tier'],
                    'score' => $scored['score'],
                    'reasons' => $scored['reasons'],
                ];
            }
            if ($found) {
                usort($found, static fn(array $a, array $b): int => [$b['score'], $a['place']['place_id']] <=> [$a['score'], $b['place']['place_id']]);
                return array_slice($found, 0, max(1, $limit));
            }
        }
        return [];
    }

    /** The soft preferences of a relax level. */
    private function softAllows(array $place, int $level, array $state, float $legKm, bool $legCounts): bool
    {
        $category = strtolower((string)$place['category']);
        $p = $this->profile;
        if ($level < 1 && !$p['food_only'] && ($state['categories'][$category] ?? 0) >= 2) return false;
        if ($level < 2 && $p['districts'] && !in_array(strtolower((string)$place['district']), $p['districts'], true)) return false;
        if ($level < 3 && !in_array($category, $p['interests'], true)) return false;
        if ($level < 4 && $legCounts && (float)$state['day_km'] + $legKm > (float)$p['day_km']) return false;
        return true;
    }

    /**
     * The one scoring function: deterministic, every term depends on the place or the slot.
     *
     * @return array{score:float,tier:int,reasons:string[]}
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
        elseif ($same >= 2 && !$p['food_only']) { $score -= 12; $reasons[] = 'category repeated'; }
        $theme = $day['previous_theme'] ?? null;
        if ($theme !== null && $category === $theme && !$p['food_only']) { $score -= 15; $reasons[] = "repeats the previous day's theme"; }

        $near = max(0.0, 14 - min(14.0, $legKm));
        if ($near > 0) { $score += $near; $reasons[] = 'near previous stop'; }
        if (!$state['first_leg'] && $legKm > (float)$p['day_km'] / max(1, (int)$p['target'])) { $score -= min(25.0, $legKm); $reasons[] = 'detour penalty'; }

        if ((int)$check['wait'] > 0) { $score -= (int)$check['wait'] / 6; $reasons[] = 'waits ' . (int)$check['wait'] . ' min for opening'; }
        if ($level > 0) array_unshift($reasons, self::RELAXED[$level]);   // first, so a cut note keeps it
        if ($must) array_unshift($reasons, 'on your must-see list');

        $hasDistricts = (bool)$p['districts'];
        $tier = $hasDistricts
            ? ($districtMatch ? ($interestMatch ? 1 : 2) : ($interestMatch ? 3 : 4))
            : ($interestMatch ? 1 : 2);
        return ['score' => round($score, 4), 'tier' => $tier, 'reasons' => $reasons];
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
        $reasons = implode('; ', array_values(array_unique(array_filter(array_diff((array)$stop['reasons'], ['interest match', 'in a preferred district'])))));
        $text = 'State: ' . $place['state'] . ' | District: ' . (($place['district'] ?? '') !== '' ? $place['district'] : '-')
            . ' | Category: ' . $place['category'] . ' | Tier: ' . (int)$stop['tier']
            . ' | Reason: ' . $tierText . ($reasons !== '' ? '; ' . $reasons : '');
        return mb_strlen($text) <= 255 ? $text : mb_substr($text, 0, 252) . '...';
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
