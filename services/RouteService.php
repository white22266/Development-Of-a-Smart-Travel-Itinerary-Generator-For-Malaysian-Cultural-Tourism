<?php
// services/RouteService.php
// Distances and travel times between itinerary stops, without any paid API.
//
//   getSegment()    distance and time between two points: the road figures when they are
//                   known, otherwise an estimate from the straight-line distance. It never
//                   makes a request itself.
//   seed()          figures already at hand (the legs stored with an itinerary): no request.
//   preload()       one "table" request for every pair of a set of points; preloadTrip() does
//                   it for a whole trip at once. The public OSRM server holds a second table
//                   request within about ten seconds back for that long, so the planner asks
//                   for one table per trip at most (generation and the route optimiser).
//   measureLegs()   given legs, strung together into as few "route" requests as possible.
//                   Not held back by the server: the edit operations measure with it.
//   preloadChain()  the legs of a list of points in a known order (measureLegs()).
//   requestCount()  the requests made (or, without a server, that would have been made)
//                   since resetRequestCount(): tools/check_planner.php keeps them in check.
//   bestOrders()    the orders in which a set of stops can be visited, least travelling first.
//   roadRoute()     the route through a list of points in order: for each leg its line,
//                   distance, time, main roads and turn-by-turn steps. Used to draw the map.
//
// The routing engines are open source and run on OpenStreetMap data; none needs a key:
//   car, and the road part of public transport   OSRM, car profile
//   walking                                      OSRM, foot profile
//   motorcycle                                   Valhalla, motorcycle profile
// By default the public servers of the German OpenStreetMap community (FOSSGIS) are used.
// To use your own, set ROUTING_CAR_URL, ROUTING_FOOT_URL and ROUTING_VALHALLA_URL in .env.
// ROUTING_ESTIMATES_ONLY=1 makes no request at all: every figure is then an estimate. The
// planner check (tools/check_planner.php) sets it so its runs are offline and repeatable.
// ROUTING_TEST_SKEW=1, only together with ROUTING_ESTIMATES_ONLY=1, is a test switch: a leg that
// would have been measured gets "road" figures that differ from the estimate, the distance and
// the time each scaled by a factor between SKEW_LOW and SKEW_HIGH that is fixed for each pair of
// points (skewed()). Planning still sees the plain estimates, so the planner check can test what
// happens when the road figures differ from the figures a day was planned with. No request.

require_once __DIR__ . '/OpenDataHttp.php';

class RouteService
{
    /** Average door-to-door speeds in km/h, used for estimates. */
    private const SPEED_MAP = [
        'car'              => 60,
        'motorcycle'       => 55,
        'public_transport' => 30,
        'walking'          => 5,
    ];

    /** Roads are longer than the straight line between two points. */
    private const ROAD_FACTOR = 1.3;

    /**
     * Public transport without a timetable to ask: a hop up to this many km is walked; a ride
     * takes the wait at the stop (TRANSIT_ACCESS_MIN) more than the ride itself, and the walks
     * between each end and its stop (setTransitAccess(), PlannerRules::applyTransitAccess()).
     */
    public const TRANSIT_WALK_KM = 1.0;
    private const TRANSIT_ACCESS_MIN = 10;

    /** Stops closer than this in a straight line are walked when the road between them is a detour. */
    private const SHORT_WALK_KM = 0.35;

    private const DEFAULT_CAR_URL = 'https://routing.openstreetmap.de/routed-car';
    private const DEFAULT_FOOT_URL = 'https://routing.openstreetmap.de/routed-foot';
    private const DEFAULT_VALHALLA_URL = 'https://valhalla1.openstreetmap.de';

    /** Longest chain of points sent in one request. */
    private const MAX_ROUTE_POINTS = 60;

    /** Most points whose pairs are measured in one request: by OSRM, and by the public Valhalla server. */
    private const MAX_TABLE_POINTS = 50;
    private const MAX_VALHALLA_POINTS = 10;
    /** Most points of one route request to the public Valhalla server. */
    private const MAX_VALHALLA_ROUTE_POINTS = 20;

    /** ROUTING_TEST_SKEW: the range of the factor a skewed leg's distance and time are scaled by. */
    private const SKEW_LOW = 0.8;
    private const SKEW_HIGH = 1.6;

    /** Up to this many stops every possible order is compared (8 stops: 40,320 orders). */
    private const MAX_EXACT_STOPS = 8;

    /** @var string car | motorcycle | public_transport | walking */
    private string $transportType;

    /** @var array<string,array{0:float,1:int}> "from|to" => road km and minutes, from a request or seed() */
    private array $known = [];

    /**
     * @var array<string,true> "from|to" pairs already asked for, answered or not (with
     * ROUTING_ESTIMATES_ONLY: that would have been asked for). A pair is never asked twice.
     */
    private array $covered = [];

    /**
     * Public transport: "lat,lng" (rounded to 5 places) => minutes between that point and its
     * stop, and the minutes of any other point (setTransitAccess()). Unset: no walk is added.
     */
    private array $access = [];
    private ?int $accessDefault = null;

    /** Seconds a request may take before the caller goes on with estimates (connecting: at most 3). */
    private int $timeout = 15;

    /** Routing requests of this object, and of all objects since resetRequestCount() (made or, without a server, that would have been made). */
    private int $requestsMade = 0;
    private static int $requestCount = 0;

    public function __construct(string $transportType = 'car')
    {
        $this->transportType = self::normalizeTransportType($transportType);
    }

    /**
     * Public transport: the minutes between points and their stops ("lat,lng" rounded to 5
     * places => minutes), and the minutes of a point not listed. A ride then takes the walk
     * from its point of departure to the stop, the wait, the ride and the walk to its point.
     * Call it before the first figure is asked for: figures already known keep their minutes.
     */
    public function setTransitAccess(array $minutesByPoint, int $otherPoint): void
    {
        if ($this->transportType !== 'public_transport') return;
        $this->access = array_map('intval', $minutesByPoint);
        $this->accessDefault = max(0, $otherPoint);
    }

    /** The minutes between a point and its stop for a ride (0 without setTransitAccess()). */
    public function accessMinutes(float $lat, float $lng): int
    {
        if ($this->accessDefault === null) return 0;
        return $this->access[round($lat, 5) . ',' . round($lng, 5)] ?? $this->accessDefault;
    }

    /** How long a request may take. Pages that wait for an answer set a few seconds; the default is 15. */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(2, $seconds);
    }

    /** The routing requests this object made (with ROUTING_ESTIMATES_ONLY: would have made). */
    public function requestsMade(): int
    {
        return $this->requestsMade;
    }

    /**
     * The routing requests made since the last resetRequestCount(), by every RouteService, or
     * with ROUTING_ESTIMATES_ONLY those that would have been made. tools/check_planner.php
     * counts the requests of each operation with it.
     */
    public static function requestCount(): int
    {
        return self::$requestCount;
    }

    public static function resetRequestCount(): void
    {
        self::$requestCount = 0;
    }

    public static function normalizeTransportType(string $transportType): string
    {
        $t = strtolower(trim(str_replace('-', '_', $transportType)));
        $t = preg_replace('/\s+/', '_', $t) ?? $t;

        return match ($t) {
            'public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train' => 'public_transport',
            'walk' => 'walking',
            'drive', 'driving' => 'car',
            'motorbike', 'bike' => 'motorcycle',
            default => array_key_exists($t, self::SPEED_MAP) ? $t : 'car',
        };
    }

    /**
     * Distance and travel time between two coordinates: the road figures when they are known
     * (a preload method, measureLegs() or seed()), otherwise an estimate from the straight-line
     * distance. Never makes a request.
     *
     * @return array{distance_km: float, travel_time_min: int, method: string} method is road | estimate
     */
    public function getSegment(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $known = $this->known[self::pairKey($lat1, $lng1, $lat2, $lng2)] ?? null;
        if ($known !== null) {
            return ['distance_km' => $known[0], 'travel_time_min' => $known[1], 'method' => 'road'];
        }
        $roadKm = self::haversineKm($lat1, $lng1, $lat2, $lng2) * self::ROAD_FACTOR;
        return [
            'distance_km'     => round($roadKm, 2),
            'travel_time_min' => $this->minutesFor($roadKm, [$lat1, $lng1], [$lat2, $lng2]),
            'method'          => 'estimate',
        ];
    }

    /** Whether any road figure is known (measured or seeded): until then every figure is an estimate. */
    public function hasKnownFigures(): bool
    {
        return $this->known !== [];
    }

    /** Whether the road figures of the leg from one point to another are known (measured or seeded). */
    public function knows(array $from, array $to): bool
    {
        return isset($this->known[self::pairKey((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1])]);
    }

    /** Whether the leg from one point to another is known or was asked for already. */
    public function covers(array $from, array $to): bool
    {
        $key = self::pairKey((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1]);
        return isset($this->known[$key]) || isset($this->covered[$key]);
    }

    /**
     * Figures already at hand, such as the legs stored with an itinerary: [from, to, km, minutes]
     * each. getSegment() answers with them, and they are not asked for again. A leg that is
     * known already keeps its figures.
     *
     * @param array<int,array{0:array,1:array,2:float,3:int}> $legs
     */
    public function seed(array $legs): void
    {
        foreach ($legs as [$from, $to, $km, $minutes]) {
            if (!self::validCoord((float)$from[0], (float)$from[1]) || !self::validCoord((float)$to[0], (float)$to[1])) continue;
            $key = self::pairKey((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1]);
            if (isset($this->known[$key])) continue;
            $this->known[$key] = [round((float)$km, 2), (int)$minutes];
            $this->covered[$key] = true;
        }
    }

    /**
     * Load the road distance and time between every pair of the given points with one request
     * (a "table" request). getSegment() answers from them afterwards. The public OSRM server
     * holds a second table request within about ten seconds back for that long, so a page
     * asks for one table at most and uses measureLegs() for the rest.
     *
     * @param array<int,array{0:float,1:float}> $points [latitude, longitude] pairs
     * @return bool false when the routing server could not be reached (and always with
     *              ROUTING_ESTIMATES_ONLY); getSegment() then keeps estimating
     */
    public function preload(array $points): bool
    {
        // Sorted, so the same set of points always makes the same request and is found in the cache.
        $points = array_values(self::distinctPoints($points));
        if (count($points) < 2 || $this->coversAll($points)) return !self::estimatesOnly();
        $valhalla = $this->transportType === 'motorcycle' && count($points) <= self::MAX_VALHALLA_POINTS;
        if (!$valhalla && count($points) > self::MAX_TABLE_POINTS) return false;

        $this->counted();
        foreach ($points as $i => $from) {
            foreach ($points as $j => $to) {
                if ($i !== $j) $this->covered[self::pairKey($from[0], $from[1], $to[0], $to[1])] = true;
            }
        }
        if (self::estimatesOnly()) {
            if (!self::testSkew()) return false;
            foreach ($points as $i => $from) {
                foreach ($points as $j => $to) {
                    if ($i !== $j) $this->known[self::pairKey($from[0], $from[1], $to[0], $to[1])] ??= $this->skewed($from, $to);
                }
            }
            return true;
        }

        // [from][to] => [km, seconds], from the engine that also draws this transport's routes
        $matrix = $valhalla ? $this->valhallaMatrix($points) : null;
        if ($matrix === null && count($points) <= self::MAX_TABLE_POINTS) {
            if ($valhalla) $this->counted();
            $matrix = $this->osrmMatrix($points);
        }
        if ($matrix === null) return false;

        foreach ($points as $i => $from) {
            foreach ($points as $j => $to) {
                if ($i === $j || !isset($matrix[$i][$j])) continue;   // not set: no road between the two
                [$km, $seconds] = $matrix[$i][$j];
                $minutes = $this->transportType === 'public_transport' ? $this->minutesFor($km, $from, $to) : (int)ceil($seconds / 60.0);
                $walk = $this->shortWalk($from[0], $from[1], $to[0], $to[1], $km);
                $this->known[self::pairKey($from[0], $from[1], $to[0], $to[1])] = $walk ?? [round($km, 2), $minutes];
            }
        }
        return true;
    }

    /**
     * Load what a trip of several days needs before its days are ordered: every pair of points
     * inside a day, and between a day and the day before it (a day starts where the last one
     * ended), with one table request while the points fit into one (MAX_TABLE_POINTS).
     * $extra points (meal places near the stops) fill the request up to that size; those that
     * do not fit are left to estimates. Only when the days' own points do not fit are more
     * requests made, a day at a time with the day before it.
     *
     * For a motorcycle the table of more than ten points comes from OSRM's car profile: one
     * request instead of one per day from the public Valhalla server, and on Malaysian roads
     * the two rarely differ.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $days  the points of each day, in day order
     * @param array<int,array{0:float,1:float}>            $extra points worth measuring when there is room
     */
    public function preloadTrip(array $days, array $extra = []): void
    {
        $all = [];
        foreach ($days as $dayPoints) $all += self::distinctPoints($dayPoints);
        if (count($all) <= self::MAX_TABLE_POINTS) {
            foreach (self::usablePoints($extra) as $p) {
                if (count($all) >= self::MAX_TABLE_POINTS) break;
                $point = [round((float)$p[0], 5), round((float)$p[1], 5)];
                $all[$point[0] . ',' . $point[1]] = $point;
            }
            $this->preload(array_values($all));
            return;
        }

        $batch = [];
        $dayBefore = [];
        foreach ($days as $dayPoints) {
            $points = self::distinctPoints($dayPoints);
            if ($batch && count($batch + $points) > self::MAX_TABLE_POINTS) {
                $this->preload(array_values($batch));
                $batch = $dayBefore;   // goes into the next request too, for the pairs between the two days
            }
            $batch += $points;
            $dayBefore = $points;
        }
        $this->preload(array_values($batch));
    }

    /**
     * Load the road distance and time of each leg of a chain of points (first to second,
     * second to third, ...): measureLegs() for those legs.
     *
     * @param array<int,array{0:float,1:float}> $points [latitude, longitude] pairs in travelling order
     * @return bool false when a leg is still not known (no server answered, or ROUTING_ESTIMATES_ONLY)
     */
    public function preloadChain(array $points): bool
    {
        $points = self::usablePoints($points);
        $pairs = [];
        for ($i = 1, $count = count($points); $i < $count; $i++) $pairs[] = [$points[$i - 1], $points[$i]];
        $this->measureLegs($pairs);
        foreach ($pairs as [$from, $to]) {
            if ($from[0] == $to[0] && $from[1] == $to[1]) continue;
            if (!isset($this->known[self::pairKey((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1])])) return false;
        }
        return true;
    }

    /**
     * Measure the given legs on the road with as few "route" requests as possible: the legs
     * are strung together into chains of at most MAX_ROUTE_POINTS points (a leg that does not
     * start where the chain ends adds a leg between the two, which is measured too). Legs that
     * are known or were asked for already are left out. Route requests are not held back by
     * the public server the way a second table request is.
     *
     * @param array<int,array{0:array,1:array}> $pairs [from, to] points
     * @param int $maxRequests at most this many requests; legs beyond them are not asked for
     * @return int how many of the legs asked for are now known (0 with ROUTING_ESTIMATES_ONLY or when no server answered)
     */
    public function measureLegs(array $pairs, int $maxRequests = PHP_INT_MAX): int
    {
        // The legs still to ask for, once each, in the order given.
        $wanted = [];
        foreach ($pairs as $pair) {
            [$from, $to] = [$pair[0] ?? null, $pair[1] ?? null];
            if (!is_array($from) || !is_array($to) || !self::validCoord((float)$from[0], (float)$from[1]) || !self::validCoord((float)$to[0], (float)$to[1])) continue;
            $from = [round((float)$from[0], 5), round((float)$from[1], 5)];
            $to = [round((float)$to[0], 5), round((float)$to[1], 5)];
            if ($from === $to || $this->covers($from, $to)) continue;
            $wanted[self::pairKey($from[0], $from[1], $to[0], $to[1])] = [$from, $to];
        }
        if (!$wanted) return 0;

        // String them together: a leg that starts where the chain ends comes next.
        $byStart = [];
        foreach ($wanted as $key => [$from]) $byStart[$from[0] . ',' . $from[1]][] = $key;
        $chains = [];
        $chain = [];
        $left = $wanted;
        while ($left) {
            $end = $chain ? $chain[count($chain) - 1] : null;
            $next = null;
            if ($end !== null) {
                foreach ($byStart[$end[0] . ',' . $end[1]] ?? [] as $key) {
                    if (isset($left[$key])) { $next = $key; break; }
                }
            }
            if ($next === null) {
                $next = array_key_first($left);
                if (count($chain) + 2 > self::MAX_ROUTE_POINTS) { $chains[] = $chain; $chain = []; }
                $chain[] = $left[$next][0];
            } elseif (count($chain) + 1 > self::MAX_ROUTE_POINTS) {
                $chains[] = $chain;
                $chain = [$left[$next][0]];
            }
            $chain[] = $left[$next][1];
            unset($left[$next]);
        }
        $chains[] = $chain;

        $measured = 0;
        foreach ($chains as $k => $points) {
            if ($k >= $maxRequests) break;   // the rest stays unknown, and may be asked for later
            $this->counted();
            for ($i = 1, $n = count($points); $i < $n; $i++) {
                $this->covered[self::pairKey($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1])] = true;
            }
            if (self::estimatesOnly()) {
                if (!self::testSkew()) continue;
                for ($i = 1, $n = count($points); $i < $n; $i++) {
                    $key = self::pairKey($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
                    if (isset($this->known[$key]) || $points[$i - 1] === $points[$i]) continue;
                    $this->known[$key] = $this->skewed($points[$i - 1], $points[$i]);
                    if (isset($wanted[$key])) $measured++;
                }
                continue;
            }
            // Every point is a stop of its own: the route may turn round there.
            $valhalla = $this->transportType === 'motorcycle' && count($points) <= self::MAX_VALHALLA_ROUTE_POINTS;
            $legs = $valhalla ? $this->valhallaLegs($points, false) : null;
            if ($legs === null) {
                if ($valhalla) $this->counted();
                $legs = $this->osrmLegs($points, false, true);
            }
            if ($legs === null) continue;
            foreach ($legs as $index => $leg) {
                [$from, $to] = [$points[$index], $points[$index + 1]];
                $key = self::pairKey($from[0], $from[1], $to[0], $to[1]);
                if (isset($this->known[$key])) continue;
                $walk = $this->shortWalk($from[0], $from[1], $to[0], $to[1], $leg['distance_km']);
                $this->known[$key] = $walk ?? [$leg['distance_km'], $leg['travel_time_min']];
                if (isset($wanted[$key])) $measured++;
            }
        }
        return $measured;
    }

    /** One more request of this object, and of the operation being counted. */
    private function counted(): void
    {
        $this->requestsMade++;
        self::$requestCount++;
    }

    /** @return array<string,array{0:float,1:float}> the distinct usable points, keyed and sorted by "lat,lng" */
    private static function distinctPoints(array $points): array
    {
        $distinct = [];
        foreach (self::usablePoints($points) as $p) {
            $point = [round((float)$p[0], 5), round((float)$p[1], 5)];
            $distinct[$point[0] . ',' . $point[1]] = $point;
        }
        ksort($distinct, SORT_STRING);
        return $distinct;
    }

    /**
     * The orders in which a set of stops can be visited, least travelling first.
     *
     * Works with the figures of getSegment(): call preload() with the same points first and
     * the orders are compared by road time. Up to eight stops every possible order is
     * compared, so the first one returned is the best there is. Beyond that, the order given
     * is improved until moving one stop or reversing a stretch no longer shortens it.
     *
     * @param array{0:float,1:float}            $start  where the day begins
     * @param array<int,array{0:float,1:float}> $stops  [latitude, longitude] pairs
     * @param array{0:float,1:float}|null       $end    where the day must finish (the hotel), when that is known
     * @param array<int,string>                 $labels a label per stop, such as its category, with the keys of $stops:
     *                                                  of two orders that take equally long, the one with fewer
     *                                                  equal labels next to each other comes first
     * @return array<int,array{order:int[],minutes:int}> at most $limit orders; order lists keys of $stops,
     *         minutes is the travelling from the start through all stops (and on to $end)
     */
    public function bestOrders(array $start, array $stops, ?array $end = null, array $labels = [], int $limit = 24): array
    {
        $keys = array_keys($stops);
        $n = count($keys);
        if ($n === 0) return [];

        // Travel minutes by position in $keys: from the start, between two stops, on to the end.
        $fromStart = [];
        $between = [];
        $toEnd = [];
        $label = [];
        foreach ($keys as $j => $key) {
            $to = $stops[$key];
            $label[$j] = (string)($labels[$key] ?? '');
            $fromStart[$j] = $this->getSegment((float)$start[0], (float)$start[1], (float)$to[0], (float)$to[1])['travel_time_min'];
            $toEnd[$j] = $end === null ? 0 : $this->getSegment((float)$to[0], (float)$to[1], (float)$end[0], (float)$end[1])['travel_time_min'];
            foreach ($keys as $i => $other) {
                if ($i === $j) continue;
                $from = $stops[$other];
                $between[$i][$j] = $this->getSegment((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1])['travel_time_min'];
            }
        }
        $minutesOf = static function (array $order) use ($fromStart, $between, $toEnd): int {
            $minutes = $fromStart[$order[0]];
            for ($k = 1, $count = count($order); $k < $count; $k++) $minutes += $between[$order[$k - 1]][$order[$k]];
            return $minutes + $toEnd[$order[count($order) - 1]];
        };
        $withKeys = static fn(array $order): array => array_map(static fn(int $position) => $keys[$position], $order);

        if ($n > self::MAX_EXACT_STOPS) {
            // Too many orders to compare them all. Improve two starting orders, the one given
            // and "always go to the nearest stop next", and return the better result first.
            $nearest = [];
            $left = range(0, $n - 1);
            $last = -1;
            while ($left) {
                $next = null;
                $best = PHP_INT_MAX;
                foreach ($left as $j) {
                    $step = $last < 0 ? $fromStart[$j] : $between[$last][$j];
                    if ($next === null || $step < $best) { $next = $j; $best = $step; }
                }
                $nearest[] = $next;
                $left = array_values(array_diff($left, [$next]));
                $last = $next;
            }
            $results = [];
            foreach ([range(0, $n - 1), $nearest] as $order) {
                $minutes = $minutesOf($order);
                do {
                    $improved = false;
                    for ($i = 0; $i < $n; $i++) {
                        for ($j = 0; $j < $n; $j++) {
                            if ($i === $j) continue;
                            $moved = $order;                                   // move the stop at $i to $j
                            array_splice($moved, $j, 0, array_splice($moved, $i, 1));
                            $candidates = [$moved];
                            if ($i < $j) {                                     // reverse the stretch from $i to $j
                                $candidates[] = array_merge(array_slice($order, 0, $i), array_reverse(array_slice($order, $i, $j - $i + 1)), array_slice($order, $j + 1));
                            }
                            foreach ($candidates as $candidate) {
                                $candidateMinutes = $minutesOf($candidate);
                                if ($candidateMinutes < $minutes) { $order = $candidate; $minutes = $candidateMinutes; $improved = true; }
                            }
                        }
                    }
                } while ($improved);
                $results[implode(',', $order)] = ['order' => $withKeys($order), 'minutes' => $minutes];
            }
            usort($results, static fn(array $a, array $b): int => $a['minutes'] <=> $b['minutes']);
            return array_slice($results, 0, max(1, $limit));
        }

        // Every order, written as a string of position letters => minutes * 16 + equal neighbours.
        $found = [];
        $visit = static function (string $path, int $last, int $minutes, int $equal, int $used) use (&$visit, &$found, $n, $fromStart, $between, $toEnd, $label): void {
            if (strlen($path) === $n) {
                $found[$path] = ($minutes + $toEnd[$last]) * 16 + min($equal, 15);
                return;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($used & (1 << $j)) continue;
                $step = $last < 0 ? $fromStart[$j] : $between[$last][$j];
                $same = ($last >= 0 && $label[$j] !== '' && $label[$j] === $label[$last]) ? 1 : 0;
                $visit($path . chr(65 + $j), $j, $minutes + $step, $equal + $same, $used | (1 << $j));
            }
        };
        $visit('', -1, 0, 0, 0);
        asort($found, SORT_NUMERIC);   // a stable sort: of equal orders, the one closest to the order given stays first

        $orders = [];
        foreach (array_slice($found, 0, max(1, $limit), true) as $path => $score) {
            $orders[] = ['order' => $withKeys(array_map(static fn(string $letter): int => ord($letter) - 65, str_split((string)$path))), 'minutes' => intdiv($score, 16)];
        }
        return $orders;
    }

    /** @return array<int,array<int,array{0:float,1:float}>>|null km and seconds between every pair, from OSRM */
    private function osrmMatrix(array $points): ?array
    {
        $url = $this->osrmBase() . '/table/v1/' . $this->osrmProfile() . '/' . self::osrmCoordinates($points) . '?annotations=duration,distance';
        $json = OpenDataHttp::getJson($url, 2592000, [   // roads change slowly: 30 days
            'namespace' => 'routes',
            'timeout' => $this->timeout,
            'connect_timeout' => min(3, $this->timeout),
            'validate' => static fn(array $j): bool => ($j['code'] ?? '') === 'Ok' && isset($j['durations'], $j['distances']),
        ]);
        if ($json === null) return null;

        $matrix = [];
        foreach ($json['durations'] as $i => $row) {
            foreach ($row as $j => $seconds) {
                $metres = $json['distances'][$i][$j] ?? null;
                if ($seconds !== null && $metres !== null) $matrix[$i][$j] = [(float)$metres / 1000.0, (float)$seconds];
            }
        }
        return $matrix;
    }

    /** @return array<int,array<int,array{0:float,1:float}>>|null the same from Valhalla's motorcycle profile */
    private function valhallaMatrix(array $points): ?array
    {
        $base = rtrim((string)(getenv('ROUTING_VALHALLA_URL') ?: self::DEFAULT_VALHALLA_URL), '/');
        $locations = array_map(static fn($p) => ['lat' => round((float)$p[0], 5), 'lon' => round((float)$p[1], 5)], $points);
        $request = ['sources' => $locations, 'targets' => $locations, 'costing' => 'motorcycle', 'units' => 'kilometers'];
        $json = OpenDataHttp::getJson($base . '/sources_to_targets?' . http_build_query(['json' => json_encode($request)]), 2592000, [
            'namespace' => 'routes',
            'timeout' => $this->timeout,
            'connect_timeout' => min(3, $this->timeout),
            'validate' => static fn(array $j): bool => isset($j['sources_to_targets']),
        ]);
        if ($json === null) return null;

        $matrix = [];
        foreach ($json['sources_to_targets'] as $row) {
            foreach ($row as $cell) {
                if (isset($cell['time'], $cell['distance'])) {
                    $matrix[(int)$cell['from_index']][(int)$cell['to_index']] = [(float)$cell['distance'], (float)$cell['time']];
                }
            }
        }
        return $matrix;
    }

    /**
     * Two stops a few minutes' walk apart are walked, whatever the transport: nobody drives
     * round a one-way system for that. Returns the walk as [km, minutes] when that applies
     * to a leg whose road distance is $roadKm, or null.
     *
     * @return array{0:float,1:int}|null
     */
    public function shortWalk(float $lat1, float $lng1, float $lat2, float $lng2, float $roadKm): ?array
    {
        if ($this->transportType === 'walking') return null;
        $straightKm = self::haversineKm($lat1, $lng1, $lat2, $lng2);
        if ($straightKm > self::SHORT_WALK_KM || $roadKm <= $straightKm * 2) return null;
        $walkKm = round($straightKm * self::ROAD_FACTOR, 2);
        return [$walkKm, max(1, (int)ceil($walkKm / self::SPEED_MAP['walking'] * 60))];
    }

    /**
     * The route through a list of points, in the order given.
     *
     * Public transport is returned as the road route with estimated times here; the real
     * bus and train connections come from TransitRoutingService where timetables exist.
     *
     * @param array<int,array{0:float,1:float}> $points [latitude, longitude] pairs
     * @param bool $withDetails also return, for every leg, the line to draw, the main roads
     *                          and turn-by-turn steps
     * @return array{distance_km:float,travel_time_min:int,time_is_estimate:bool,
     *               legs:array<int,array{distance_km:float,travel_time_min:int,via?:string,
     *                    geometry?:array<int,array{0:float,1:float}>,steps?:array<int,array{text:string,distance_m:int}>}>}|null
     *         null when there are fewer than two usable points or no routing server answered.
     *         legs has one entry per consecutive pair of points.
     */
    public function roadRoute(array $points, bool $withDetails = true): ?array
    {
        if (self::estimatesOnly()) return null;
        $points = self::usablePoints($points);
        if (count($points) < 2 || count($points) > self::MAX_ROUTE_POINTS) return null;
        $this->counted();

        // A motorcycle gets its own profile. When that server does not answer, the car route
        // is used: on Malaysian roads the two rarely differ.
        $legs = $this->transportType === 'motorcycle' ? $this->valhallaLegs($points, $withDetails) : null;
        $legs ??= $this->osrmLegs($points, $withDetails);
        if ($legs === null) return null;

        return [
            'distance_km' => round(array_sum(array_column($legs, 'distance_km')), 2),
            'travel_time_min' => (int)array_sum(array_column($legs, 'travel_time_min')),
            'time_is_estimate' => $this->transportType === 'public_transport',
            'legs' => $legs,
        ];
    }

    /** @param bool $independent each leg on its own: the route may turn round at every point (for measureLegs()) */
    private function osrmLegs(array $points, bool $withDetails, bool $independent = false): ?array
    {
        $url = $this->osrmBase() . '/route/v1/' . $this->osrmProfile() . '/' . self::osrmCoordinates($points)
            . '?alternatives=false&overview=false&steps=' . ($withDetails ? 'true&geometries=geojson' : 'false')
            . ($independent ? '&continue_straight=false' : '');
        $json = OpenDataHttp::getJson($url, 2592000, [
            'namespace' => 'routes',
            'timeout' => $this->timeout,
            'connect_timeout' => min(3, $this->timeout),
            'validate' => static fn(array $j): bool => ($j['code'] ?? '') === 'Ok' && isset($j['routes'][0]['legs']),
        ]);
        $route = $json['routes'][0] ?? null;
        if (!is_array($route) || count($route['legs']) !== count($points) - 1) return null;

        $legs = [];
        foreach ($route['legs'] as $index => $leg) {
            $km = (float)($leg['distance'] ?? 0) / 1000.0;
            $minutes = $this->transportType === 'public_transport'
                ? $this->minutesFor($km, $points[$index], $points[$index + 1]) : (int)ceil((float)($leg['duration'] ?? 0) / 60.0);
            $entry = ['distance_km' => round($km, 2), 'travel_time_min' => $minutes];
            if ($withDetails) {
                $entry['via'] = trim((string)($leg['summary'] ?? ''));
                $entry['geometry'] = [];
                $entry['steps'] = [];
                foreach (($leg['steps'] ?? []) as $step) {
                    foreach (($step['geometry']['coordinates'] ?? []) as $pair) {
                        if (is_array($pair) && count($pair) >= 2) $entry['geometry'][] = [round((float)$pair[1], 5), round((float)$pair[0], 5)];
                    }
                    $text = self::stepText($step);
                    if ($text !== null) $entry['steps'][] = ['text' => $text, 'distance_m' => (int)round((float)($step['distance'] ?? 0))];
                }
            }
            $legs[] = $entry;
        }
        return $legs;
    }

    /** The same legs from Valhalla's motorcycle profile, or null when its server does not answer. */
    private function valhallaLegs(array $points, bool $withDetails): ?array
    {
        $base = rtrim((string)(getenv('ROUTING_VALHALLA_URL') ?: self::DEFAULT_VALHALLA_URL), '/');
        $request = [
            'locations' => array_map(static fn($p) => ['lat' => round((float)$p[0], 5), 'lon' => round((float)$p[1], 5)], $points),
            'costing' => 'motorcycle',
            'units' => 'kilometers',
            'directions_type' => $withDetails ? 'instructions' : 'none',
        ];
        $json = OpenDataHttp::getJson($base . '/route?' . http_build_query(['json' => json_encode($request)]), 2592000, [
            'namespace' => 'routes',
            'timeout' => $this->timeout,
            'connect_timeout' => min(3, $this->timeout),
            'validate' => static fn(array $j): bool => isset($j['trip']['legs']),
        ]);
        $tripLegs = $json['trip']['legs'] ?? null;
        if (!is_array($tripLegs) || count($tripLegs) !== count($points) - 1) return null;

        $legs = [];
        foreach ($tripLegs as $leg) {
            $entry = [
                'distance_km' => round((float)($leg['summary']['length'] ?? 0), 2),
                'travel_time_min' => (int)ceil((float)($leg['summary']['time'] ?? 0) / 60.0),
            ];
            if ($withDetails) {
                $entry['geometry'] = self::decodePolyline((string)($leg['shape'] ?? ''), 6);
                $entry['steps'] = [];
                $roads = [];   // road name => km travelled on it
                foreach (($leg['maneuvers'] ?? []) as $maneuver) {
                    $km = (float)($maneuver['length'] ?? 0);
                    $text = rtrim(trim((string)($maneuver['instruction'] ?? '')), '.');
                    if ($text !== '') $entry['steps'][] = ['text' => $text, 'distance_m' => (int)round($km * 1000)];
                    $name = (string)($maneuver['street_names'][0] ?? '');
                    if ($name !== '') $roads[$name] = ($roads[$name] ?? 0) + $km;
                }
                arsort($roads);
                $entry['via'] = implode(', ', array_slice(array_keys($roads), 0, 2));
            }
            $legs[] = $entry;
        }
        return $legs;
    }

    /** One step of an OSRM route in words ("Turn left onto Jalan Kota"), or null for a step not worth a line. */
    private static function stepText(array $step): ?string
    {
        $type = (string)($step['maneuver']['type'] ?? '');
        $modifier = (string)($step['maneuver']['modifier'] ?? '');
        $name = trim((string)($step['name'] ?? ''));
        $onto = $name !== '' ? ' onto ' . $name : '';

        if ($type === 'arrive') return 'Arrive';
        if ((float)($step['distance'] ?? 0) < 10) return null;   // a few metres between two turns
        $turn = match ($modifier) {
            'uturn' => 'Make a U-turn',
            'straight' => 'Continue straight',
            'slight left' => 'Keep left',
            'slight right' => 'Keep right',
            'sharp left' => 'Turn sharp left',
            'sharp right' => 'Turn sharp right',
            'left', 'right' => 'Turn ' . $modifier,
            default => 'Continue',
        };
        return match ($type) {
            'depart' => 'Start' . ($name !== '' ? ' on ' . $name : ''),
            'turn', 'end of road', 'fork', 'continue' => $turn . $onto,
            'new name' => 'Continue' . $onto,
            'merge' => 'Merge' . $onto,
            'on ramp' => 'Take the ramp' . $onto,
            'off ramp' => 'Take the exit' . $onto,
            'roundabout', 'rotary' => 'At the roundabout, take exit ' . (int)($step['maneuver']['exit'] ?? 1) . $onto,
            'roundabout turn' => 'At the roundabout, ' . lcfirst($turn) . $onto,
            'exit roundabout', 'exit rotary', 'notification' => null,
            default => 'Continue' . $onto,
        };
    }

    /**
     * Decode an "encoded polyline" (the compact way routing services send a line).
     *
     * @param int $precision decimal places the service encoded with (Valhalla 6, Transitous 7)
     * @return array<int,array{0:float,1:float}> [latitude, longitude] pairs
     */
    public static function decodePolyline(string $encoded, int $precision): array
    {
        $factor = 10 ** $precision;
        $points = [];
        $lat = 0;
        $lng = 0;
        $index = 0;
        $length = strlen($encoded);
        while ($index < $length) {
            foreach ([0, 1] as $axis) {
                $result = 0;
                $shift = 0;
                do {
                    if ($index >= $length) return $points;   // cut-off input: keep what was read
                    $byte = ord($encoded[$index++]) - 63;
                    $result |= ($byte & 0x1f) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 0) $lat += $delta; else $lng += $delta;
            }
            $points[] = [round($lat / $factor, 5), round($lng / $factor, 5)];
        }
        return $points;
    }

    public static function validCoord(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng)
            && !($lat === 0.0 && $lng === 0.0)
            && $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180;
    }

    /** Great-circle distance in kilometres. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /**
     * Minutes needed for a road distance at the usual speed of this transport type. A ride by
     * public transport between two points adds the wait and the walks between each point and
     * its stop (setTransitAccess()).
     */
    private function minutesFor(float $roadKm, ?array $from = null, ?array $to = null): int
    {
        if ($this->transportType === 'public_transport') {
            if ($roadKm <= self::TRANSIT_WALK_KM) return (int)ceil($roadKm / self::SPEED_MAP['walking'] * 60);
            $walks = ($from !== null ? $this->accessMinutes((float)$from[0], (float)$from[1]) : 0)
                + ($to !== null ? $this->accessMinutes((float)$to[0], (float)$to[1]) : 0);
            return (int)ceil($roadKm / self::SPEED_MAP['public_transport'] * 60) + self::TRANSIT_ACCESS_MIN + $walks;
        }
        $speed = self::SPEED_MAP[$this->transportType] ?? 60;
        return (int)ceil(($roadKm / $speed) * 60);
    }

    /** Whether ROUTING_ESTIMATES_ONLY is set: then no routing server is asked and every figure is an estimate. */
    private static function estimatesOnly(): bool
    {
        return in_array(strtolower(trim((string)getenv('ROUTING_ESTIMATES_ONLY'))), ['1', 'true', 'yes'], true);
    }

    /** Whether the test switch ROUTING_TEST_SKEW is set (it acts only with ROUTING_ESTIMATES_ONLY). */
    private static function testSkew(): bool
    {
        return in_array(strtolower(trim((string)getenv('ROUTING_TEST_SKEW'))), ['1', 'true', 'yes'], true);
    }

    /**
     * ROUTING_TEST_SKEW: the "road" figures of a leg, [km, minutes]: the estimate with its
     * distance and its time each scaled by a factor between SKEW_LOW and SKEW_HIGH taken from a
     * hash of the pair, so the same leg always gets the same figures and the two directions of
     * a leg may differ, as real roads do.
     *
     * @return array{0:float,1:int}
     */
    private function skewed(array $from, array $to): array
    {
        $estimate = $this->getSegment((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1]);
        $hash = crc32(self::pairKey((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1]));
        $span = self::SKEW_HIGH - self::SKEW_LOW;
        $kmFactor = self::SKEW_LOW + $span * (($hash & 0xffff) / 0xffff);
        $minFactor = self::SKEW_LOW + $span * ((($hash >> 16) & 0xffff) / 0xffff);
        $km = (float)$estimate['distance_km'] * $kmFactor;
        $walk = $this->shortWalk((float)$from[0], (float)$from[1], (float)$to[0], (float)$to[1], $km);
        return $walk ?? [round($km, 2), max(1, (int)ceil((int)$estimate['travel_time_min'] * $minFactor))];
    }

    private function osrmBase(): string
    {
        $onFoot = $this->transportType === 'walking';
        return rtrim((string)(getenv($onFoot ? 'ROUTING_FOOT_URL' : 'ROUTING_CAR_URL') ?: ($onFoot ? self::DEFAULT_FOOT_URL : self::DEFAULT_CAR_URL)), '/');
    }

    private function osrmProfile(): string
    {
        return $this->transportType === 'walking' ? 'foot' : 'driving';
    }

    /** "lng,lat;lng,lat": OSRM wants the longitude first. */
    private static function osrmCoordinates(array $points): string
    {
        return implode(';', array_map(static fn($p) => round((float)$p[1], 5) . ',' . round((float)$p[0], 5), $points));
    }

    private static function usablePoints(array $points): array
    {
        return array_values(array_filter($points, static fn($p) => is_array($p) && self::validCoord((float)($p[0] ?? 0), (float)($p[1] ?? 0))));
    }

    /** Whether every pair of these points is known or was asked for already. */
    private function coversAll(array $points): bool
    {
        foreach ($points as $i => $from) {
            foreach ($points as $j => $to) {
                if ($i !== $j && !$this->covers($from, $to)) return false;
            }
        }
        return true;
    }

    private static function pairKey(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        return round($lat1, 5) . ',' . round($lng1, 5) . '|' . round($lat2, 5) . ',' . round($lng2, 5);
    }
}
