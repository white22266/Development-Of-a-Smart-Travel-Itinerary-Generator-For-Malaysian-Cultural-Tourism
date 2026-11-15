<?php
// services/TripLayout.php
// How a trip of several days is laid out before its days are planned: where the traveller
// sleeps (the base of each night), which area each day belongs to and which places of that area
// each day draws from first (its group). Deterministic; asks no server (estimates only).
//
//   1. Areas. With stay_at_start the starting point is the one base, and its area is every
//      candidate within the area radius. Otherwise the candidates (and the must-see places) are
//      grouped into areas that can be toured from one base: best place first, each place joins
//      the first area whose first place lies within the radius, else it starts an area. The
//      radius is the transport's recommended daily distance over 2 x ROAD_FACTOR (a return trip
//      to the edge of the area is one recommended day), at least MIN_RADIUS_KM. The base of an
//      area is the most central of its best places (the one with the least value-weighted
//      distance to the others), named "district, state"; every place then belongs to the
//      nearest base within the radius. A place is valued as the day search would value it
//      (ItineraryDayBuilder::layoutValue(): a place outside the preferred districts or interests
//      less the price of that relax level).
//   2. Which areas and how many days. The area of a must-see place is always used, and so is
//      the best area of each state the traveller chose (while there are days). Then day by day:
//      a day goes where it adds most value: one more day in an area used already (the value of
//      its next "target" best places), or a new area (the value of its best places less
//      TRAVEL_PRICE per minute of the detour the move adds to the route). So each area gets days
//      in proportion to what it offers this traveller, at least one; there are never more areas
//      than days; a move that would take more than TRANSFER_MAX_SHARE of the day window is not
//      made unless the area must be used.
//   3. Order. The areas are put in the order with the least travelling from the starting point
//      (to the end point when the trip has one), every order compared up to MAX_EXACT areas,
//      an order that puts a must-see place outside the dates it can be visited on coming last.
//   4. Groups. An area of k days splits its places into k groups by bearing around the base:
//      k arcs, cut so that each holds as much value as the others.
//   5. Dates. The groups of an area go to its dates so that as much of each group as possible is
//      open on its date (weekday hours, festival dates) and, when the chance of rain of a date is
//      RAIN_PREFER percent or more, the dates with rain get the groups with more indoor value.
//      A must-see place decides the date of its group (a date it can be visited on).
//
// The day that the trip moves to another area starts at the base of the night before; its first
// leg (the move) is not counted by the distance limits, as the leg from the starting point on
// day 1 is not (ItineraryGenerator, PlannerRules::check() leg_counts).

require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/OpeningHours.php';
require_once __DIR__ . '/ItineraryDayBuilder.php';

final class TripLayout
{
    /** RouteService estimates a road as the straight line times this. */
    private const ROAD_FACTOR = 1.3;
    private const MIN_RADIUS_KM = 1.5;
    /** A move to another area takes at most this share of the day window (estimate). */
    private const TRANSFER_MAX_SHARE = 0.5;
    /** A must-see place outweighs any other when areas are formed and groups weighed. */
    private const MUST_VALUE = 1000.0;
    /** Every order of the areas is compared up to this many areas, every order of a group's dates up to this many days. */
    private const MAX_EXACT = 7;
    /** On a date with rain: weight of the indoor value of a group minus its outdoor value. */
    private const RAIN_GROUP_SHARE = 0.5;
    /** A group weighs its places by value, between these bounds. */
    private const WEIGHT_MIN = 1.0;
    private const WEIGHT_MAX = 60.0;
    /** Rounds of forming areas: the places a round's bases leave uncovered form the next round's areas. */
    private const AREA_ROUNDS = 3;

    /** The radius of an area in straight-line km for this profile. */
    public static function radiusKm(array $profile): float
    {
        return max(self::MIN_RADIUS_KM, (float)$profile['day_km'] / (2 * self::ROAD_FACTOR));
    }

    /**
     * @param array $pool    candidate places (narrowed by PlannerRules::placeReason())
     * @param array $musts   place_id => ['place' => row, 'feasible' => int[] days it can be visited on]
     * @param callable $value fn(array $place): float, the place's value (ItineraryDayBuilder::placeValue())
     * @param array $trip    origin [lat, lng], origin_name, finish ([lat, lng] or null), dates
     *                       (day => Y-m-d), rain (Y-m-d => percent), stay_at_start (bool)
     * @return array{days:array<int,array>,must_days:array<int,int>} days: day => region (area
     *         number), base ['name','lat','lng'], sector ([from, to] bearings or null), radius
     *         (km), group (place_id => true), area (place_id => true); must_days: must-see
     *         place_id => the day of its group
     */
    public static function plan(array $pool, array $musts, array $profile, callable $value, RouteService $route, array $trip): array
    {
        $dates = $trip['dates'];
        $totalDays = count($dates);
        $radius = self::radiusKm($profile);
        $members = [];
        foreach ($pool as $place) {
            $members[(int)$place['place_id']] = ['place' => $place, 'v' => (float)$value($place), 'must' => false,
                'point' => [(float)$place['latitude'], (float)$place['longitude']]];
        }
        foreach ($musts as $id => $m) {
            $members[(int)$id] = ['place' => $m['place'], 'v' => self::MUST_VALUE + (float)$value($m['place']), 'must' => true,
                'point' => [(float)$m['place']['latitude'], (float)$m['place']['longitude']]];
        }

        $regions = !empty($trip['stay_at_start'])
            ? [self::originRegion($members, $trip, $radius)]
            : self::regions($members, $radius);
        if (!$regions) $regions = [self::originRegion($members, $trip, $radius)];

        [$order, $dayCount] = self::chooseRegions($regions, $members, $musts, $profile, $route, $trip, $totalDays);

        $days = [];
        $mustDays = [];
        $d = 1;
        foreach ($order as $r) {
            $region = $regions[$r];
            $k = $dayCount[$r];
            $groups = self::groups($region, $members, $k);
            $regionDays = range($d, $d + $k - 1);
            $assignment = self::assignDates($groups, $regionDays, $members, $musts, $dates, (array)($trip['rain'] ?? []), $profile);
            $area = array_fill_keys($region['members'], true);
            foreach ($assignment as $g => $day) {
                $group = $groups[$g];
                $days[$day] = [
                    'region' => $r,
                    'state' => (string)$region['state'],
                    'base' => $region['base'],
                    'sector' => $group['sector'],
                    'radius' => round($radius, 2),
                    'group' => array_fill_keys($group['ids'], true),
                    'area' => $area,
                ];
                foreach ($group['ids'] as $id) if ($members[$id]['must']) $mustDays[$id] = $day;
            }
            $d += $k;
        }
        ksort($days);
        return ['days' => $days, 'must_days' => $mustDays];
    }

    /** The one area of a traveller who stays at the starting point: every place within the radius of it. */
    private static function originRegion(array $members, array $trip, float $radius): array
    {
        $origin = $trip['origin'];
        $ids = [];
        foreach ($members as $id => $m) {
            if ($m['must'] || self::km($origin, $m['point']) <= $radius) $ids[] = $id;
        }
        usort($ids, static fn(int $a, int $b): int => [$members[$b]['v'], -$b] <=> [$members[$a]['v'], -$a]);
        return ['base' => ['name' => (string)$trip['origin_name'], 'lat' => (float)$origin[0], 'lng' => (float)$origin[1]],
                'point' => [(float)$origin[0], (float)$origin[1]], 'state' => '', 'members' => $ids];
    }

    /** Areas that can each be toured from one base (step 1 at the head of this file). */
    private static function regions(array $members, float $radius): array
    {
        $ids = array_keys($members);
        usort($ids, static fn(int $a, int $b): int => [$members[$b]['v'], -$b] <=> [$members[$a]['v'], -$a]);
        // Areas around the best places; the places their bases leave further than the radius
        // form areas of their own in the next round (at most AREA_ROUNDS rounds).
        $bases = [];
        $left = $ids;
        for ($round = 0; $round < self::AREA_ROUNDS && $left; $round++) {
            $seeds = [];   // [point, member ids]
            foreach ($left as $id) {
                $placed = false;
                foreach ($seeds as $i => $seed) {
                    if (self::km($seed[0], $members[$id]['point']) <= $radius) { $seeds[$i][1][] = $id; $placed = true; break; }
                }
                if (!$placed) $seeds[] = [$members[$id]['point'], [$id]];
            }
            // The base of each area: the most central of its best places.
            foreach ($seeds as [, $list]) {
                $top = array_slice($list, 0, 12);
                $best = null;
                foreach ($top as $c) {
                    $cost = 0.0;
                    foreach ($top as $j) $cost += max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $members[$j]['v'])) * self::km($members[$c]['point'], $members[$j]['point']);
                    if ($best === null || $cost < $best[0] - 1e-9) $best = [$cost, $c];
                }
                $bases[] = $best[1];
            }
            $left = array_values(array_filter($left, static function (int $id) use ($bases, $members, $radius): bool {
                foreach ($bases as $b) if (self::km($members[$b]['point'], $members[$id]['point']) <= $radius) return false;
                return true;
            }));
        }
        // Every place to the nearest base within the radius (a must-see place to the nearest base).
        $regions = [];
        foreach ($bases as $i => $baseId) {
            $p = $members[$baseId]['place'];
            $regions[$i] = ['base_id' => $baseId, 'point' => $members[$baseId]['point'], 'state' => strtolower(trim((string)$p['state'])), 'members' => []];
        }
        foreach ($ids as $id) {
            $nearest = null;
            foreach ($regions as $i => $region) {
                $km = self::km($region['point'], $members[$id]['point']);
                if (($km <= $radius || $members[$id]['must']) && ($nearest === null || $km < $nearest[0])) $nearest = [$km, $i];
            }
            if ($nearest !== null) $regions[$nearest[1]]['members'][] = $id;
        }
        $regions = array_values(array_filter($regions, static fn(array $r): bool => $r['members'] !== []));
        // Names: "district, state" of the base place, and nothing more (the state alone when the
        // district is missing or is the state).
        foreach ($regions as $i => $region) {
            $p = $members[$region['base_id']]['place'];
            $district = trim((string)($p['district'] ?? ''));
            $state = trim((string)($p['state'] ?? ''));
            $name = $district !== '' && strcasecmp($district, $state) !== 0 ? $district . ', ' . $state : ($state !== '' ? $state : (string)$p['name']);
            $regions[$i]['base'] = ['name' => mb_substr($name, 0, 255), 'lat' => $region['point'][0], 'lng' => $region['point'][1]];
        }
        return $regions;
    }

    /**
     * Steps 2 and 3: the areas used, in travelling order, and their days.
     *
     * @return array{0:int[],1:array<int,int>} area numbers in order; area => days
     */
    private static function chooseRegions(array $regions, array $members, array $musts, array $profile, RouteService $route, array $trip, int $totalDays): array
    {
        $target = max(1, (int)$profile['target']);
        $origin = $trip['origin'];
        $finish = $trip['finish'] ?? null;
        $window = (int)$profile['end'] - (int)$profile['start'];
        $minutes = static fn(array $a, array $b): int => (int)$route->getSegment((float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1])['travel_time_min'];
        $offers = [];
        foreach ($regions as $r => $region) {
            $values = [];
            foreach ($region['members'] as $id) if (!$members[$id]['must'] && $members[$id]['v'] > 0) $values[] = $members[$id]['v'];
            rsort($values);
            $offers[$r] = $values;
        }
        $dayGain = static fn(int $r, int $k): float => array_sum(array_slice($offers[$r], ($k - 1) * $target, $target));

        // Must-see areas first (most must-see places first), then the best area of each chosen state.
        $mandatory = [];
        $mustCount = [];
        foreach ($regions as $r => $region) {
            foreach ($region['members'] as $id) if ($members[$id]['must']) $mustCount[$r] = ($mustCount[$r] ?? 0) + 1;
        }
        arsort($mustCount);
        foreach (array_keys($mustCount) as $r) $mandatory[] = $r;
        // The best area of a state: what it offers for the whole trip, less the way there from the start.
        foreach (array_map('strtolower', (array)$profile['states']) as $state) {
            $best = null;
            foreach ($regions as $r => $region) {
                if ($region['state'] !== $state) continue;
                $gain = array_sum(array_slice($offers[$r], 0, $target * $totalDays)) - ItineraryDayBuilder::TRAVEL_PRICE * $minutes($origin, $region['point']);
                if ($best === null || $gain > $best[0] + 1e-9) $best = [$gain, $r];
            }
            if ($best !== null && !in_array($best[1], $mandatory, true)) $mandatory[] = $best[1];
        }
        $mandatory = array_slice($mandatory, 0, $totalDays);

        $path = [];   // areas in order
        $days = [];
        $cost = static function (array $path) use ($regions, $origin, $finish, $minutes): int {
            $total = 0;
            $from = $origin;
            foreach ($path as $r) { $total += $minutes($from, $regions[$r]['point']); $from = $regions[$r]['point']; }
            if ($finish !== null && $path) $total += $minutes($from, $finish);
            return $total;
        };
        // Cheapest place of an area in the path: [extra minutes, position, longest leg to or from it].
        $insertion = static function (array $path, int $r) use ($regions, $origin, $finish, $minutes, $cost): array {
            $best = null;
            for ($i = 0; $i <= count($path); $i++) {
                $trial = $path;
                array_splice($trial, $i, 0, [$r]);
                $before = $i === 0 ? $origin : $regions[$path[$i - 1]]['point'];
                $after = $i < count($path) ? $regions[$path[$i]]['point'] : null;
                $leg = max($i === 0 && !$path ? 0 : $minutes($before, $regions[$r]['point']), $after !== null ? $minutes($regions[$r]['point'], $after) : 0);
                $extra = $cost($trial) - $cost($path);
                if ($best === null || $extra < $best[0]) $best = [$extra, $i, $leg];
            }
            return $best;
        };
        foreach ($mandatory as $r) {
            [, $i] = $insertion($path, $r);
            array_splice($path, $i, 0, [$r]);
            $days[$r] = 1;
        }
        while (array_sum($days) < $totalDays) {
            $best = null;
            foreach ($days as $r => $k) {
                $gain = $dayGain($r, $k + 1);
                if ($best === null || $gain > $best[0] + 1e-9) $best = [$gain, $r, null];
            }
            foreach ($regions as $r => $region) {
                if (isset($days[$r])) continue;
                [$extra, $i, $leg] = $insertion($path, $r);
                if ($path && $leg > self::TRANSFER_MAX_SHARE * $window) continue;
                $gain = $dayGain($r, 1) - ItineraryDayBuilder::TRAVEL_PRICE * $extra;
                if ($best === null || $gain > $best[0] + 1e-9) $best = [$gain, $r, $i];
            }
            if ($best === null) {   // no area at all within reach: the nearest one
                $nearest = null;
                foreach ($regions as $r => $region) {
                    $m = $minutes($origin, $region['point']);
                    if ($nearest === null || $m < $nearest[0]) $nearest = [$m, $r];
                }
                $best = [0.0, $nearest[1], 0];
            }
            [, $r, $i] = $best;
            if ($i !== null) array_splice($path, $i, 0, [$r]);
            $days[$r] = ($days[$r] ?? 0) + 1;
        }

        // The travelling order: every order up to MAX_EXACT areas; a must-see place outside its dates counts first.
        $mustMiss = static function (array $order) use ($regions, $members, $musts, $days): int {
            $miss = 0;
            $d = 1;
            foreach ($order as $r) {
                $range = range($d, $d + $days[$r] - 1);
                foreach ($regions[$r]['members'] as $id) {
                    if ($members[$id]['must'] && !array_intersect($range, (array)($musts[$id]['feasible'] ?? $range))) $miss++;
                }
                $d += $days[$r];
            }
            return $miss;
        };
        if (count($path) > 1 && count($path) <= self::MAX_EXACT) {
            $bestOrder = null;
            foreach (self::permutations($path) as $order) {
                $rank = [$mustMiss($order), $cost($order)];
                if ($bestOrder === null || $rank < $bestOrder[0]) $bestOrder = [$rank, $order];
            }
            $path = $bestOrder[1];
        }
        return [$path, $days];
    }

    /**
     * Step 4: k groups of an area by bearing around its base, each holding about the same value.
     *
     * @return array<int,array{ids:int[],sector:?array}>
     */
    private static function groups(array $region, array $members, int $k): array
    {
        $ids = $region['members'];
        if ($k <= 1 || count($ids) < 2) {
            $out = [['ids' => $ids, 'sector' => null]];
            for ($g = 1; $g < $k; $g++) $out[] = ['ids' => [], 'sector' => null];
            return $out;
        }
        $base = $region['point'];
        $around = [];
        foreach ($ids as $id) $around[] = [self::bearing($base, $members[$id]['point']), $id];
        sort($around);
        $n = count($around);
        $weight = static fn(int $id): float => $members[$id]['must'] ? self::WEIGHT_MAX : max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $members[$id]['v']));
        $total = 0.0;
        foreach ($around as [, $id]) $total += $weight($id);
        $share = $total / $k;
        $best = null;
        for ($s = 0; $s < $n; $s++) {
            $cuts = [];
            $acc = 0.0;
            $sums = array_fill(0, $k, 0.0);
            $g = 0;
            for ($t = 0; $t < $n; $t++) {
                $id = $around[($s + $t) % $n][1];
                $w = $weight($id);
                // Close the group before this place when that leaves it nearer its share.
                if ($g < $k - 1 && $acc > 0 && abs($acc + $w - $share * ($g + 1)) > abs($acc - $share * ($g + 1)) && $n - $t >= $k - $g - 1) {
                    $cuts[] = $t;
                    $g++;
                }
                $sums[$g] += $w;
                $acc += $w;
            }
            $spread = 0.0;
            foreach ($sums as $sum) $spread += ($sum - $share) ** 2;
            if ($best === null || $spread < $best[0] - 1e-9) $best = [$spread, $s, $cuts];
        }
        [, $s, $cuts] = $best;
        $bounds = array_merge([0], $cuts, [$n]);
        $out = [];
        for ($g = 0; $g < $k; $g++) {
            $slice = [];
            for ($t = $bounds[$g] ?? $n; $t < ($bounds[$g + 1] ?? $n); $t++) $slice[] = $around[($s + $t) % $n];
            $out[] = $slice
                ? ['ids' => array_column($slice, 1), 'sector' => [(int)floor($slice[0][0]), (int)ceil($slice[count($slice) - 1][0]) % 361]]
                : ['ids' => [], 'sector' => null];
        }
        return $out;
    }

    /**
     * Step 5: group => day, so that the groups are open on their dates, rainy dates get the
     * indoor groups and a must-see place is visited on a date it can be.
     */
    private static function assignDates(array $groups, array $regionDays, array $members, array $musts, array $dates, array $rain, array $profile): array
    {
        $k = count($groups);
        $fit = [];
        foreach ($groups as $g => $group) {
            foreach ($regionDays as $j => $day) {
                $date = (string)$dates[$day];
                $score = 0.0;
                $wet = isset($rain[$date]) && (int)$rain[$date] >= ItineraryDayBuilder::RAIN_PREFER;
                foreach ($group['ids'] as $id) {
                    $m = $members[$id];
                    if ($m['must']) {
                        if (!in_array($day, (array)($musts[$id]['feasible'] ?? [$day]), true)) $score -= 1e6;
                        continue;
                    }
                    $w = max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $m['v']));
                    if (self::openOn($m['place'], $date)) $score += $w;
                    if ($wet) $score += self::RAIN_GROUP_SHARE * (PlannerRules::isOutdoor($m['place']) ? -$w : $w);
                }
                $fit[$g][$j] = $score;
            }
        }
        $orders = $k <= self::MAX_EXACT ? self::permutations(range(0, $k - 1)) : [range(0, $k - 1)];
        $best = null;
        foreach ($orders as $perm) {   // $perm[j] = the group of the j-th date
            $sum = 0.0;
            foreach ($perm as $j => $g) $sum += $fit[$g][$j];
            if ($best === null || $sum > $best[0] + 1e-9) $best = [$sum, $perm];
        }
        $out = [];
        foreach ($best[1] as $j => $g) $out[$g] = $regionDays[$j];
        return $out;
    }

    /** Whether a place can be visited at all on a date: festival dates, and not closed that weekday. */
    private static function openOn(array $place, string $date): bool
    {
        $festival = PlannerRules::festivalDates($place);
        if ($festival !== null && ($date < $festival[0] || $date > $festival[1])) return false;
        return PlannerRules::hoursOn($place, $date) !== [];
    }

    /** Straight-line km (an equirectangular approximation: these distances are short). */
    private static function km(array $a, array $b): float
    {
        $dy = ((float)$b[0] - (float)$a[0]) * 110.57;
        $dx = ((float)$b[1] - (float)$a[1]) * 111.32 * cos(deg2rad(((float)$a[0] + (float)$b[0]) / 2));
        return sqrt($dx * $dx + $dy * $dy);
    }

    /** Bearing from one point to another, degrees clockwise from north, 0 to 360. */
    public static function bearing(array $from, array $to): float
    {
        $dy = (float)$to[0] - (float)$from[0];
        $dx = ((float)$to[1] - (float)$from[1]) * cos(deg2rad((float)$from[0]));
        $deg = rad2deg(atan2($dx, $dy));
        return $deg < 0 ? $deg + 360 : $deg;
    }

    /** Every order of the items, the given order first. */
    private static function permutations(array $items): array
    {
        if (count($items) <= 1) return [$items];
        $out = [];
        foreach ($items as $i => $first) {
            $rest = $items;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $tail) $out[] = array_merge([$first], $tail);
        }
        return $out;
    }
}
