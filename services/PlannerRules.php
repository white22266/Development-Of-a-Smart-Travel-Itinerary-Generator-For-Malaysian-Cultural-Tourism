<?php
// services/PlannerRules.php
// The rules an itinerary must keep, written once. The day builder, the generator and the
// route optimiser all ask here, so no step can undo what another step guaranteed.
//
//   loadPreference()  a saved preference, normalised, with its interest weights and its
//                     must-see and avoid places
//   profile()         the planning profile for a preference: target stops, rest, day window,
//                     distance limits, visit lengths, accessibility, party and budget split
//   check()           can this place be visited in this slot? The one feasibility check: it
//                     returns the visit's start and end, or a reason code
//   visitMinutes()    how long a visit lasts;  placeCost()  what a place costs per person
//   projectedTotal()  the trip total, computed exactly as ItineraryEditService::total() stores it
//   learnedPoints()   the small score adjustment per category from the traveller's own ratings
//   sameLandmarks()   the twin groups of a trip's candidates: rows that are one landmark under
//                     two names; check() then keeps a trip to one row of each group

require_once __DIR__ . '/OpeningHours.php';
require_once __DIR__ . '/CostEstimationService.php';
require_once __DIR__ . '/AccessibilityNeedsAnalysisService.php';
require_once __DIR__ . '/PlaceNameMatcher.php';

final class PlannerRules
{
    public const VALID_INTERESTS = ['culture', 'heritage', 'food', 'museum', 'nature', 'shopping', 'festival'];

    /** Visit stops per day for each pace, and the rest between two visits. */
    private const PACE_TARGET = ['relaxed' => 3, 'normal' => 4, 'packed' => 5];
    private const PACE_REST_MIN = ['relaxed' => 90, 'normal' => 60, 'packed' => 30];
    private const REST_EXTRA_CAREFUL = 15;
    private const REST_EXTRA_GROUP = 10;

    /** Day window per preferred visit time: earliest start and latest end, minutes after midnight. */
    private const VISIT_WINDOW = [
        'morning' => [480, 1140],     // 08:00 - 19:00
        'afternoon' => [720, 1290],   // 12:00 - 21:30
        'evening' => [900, 1350],     // 15:00 - 22:30
        'any' => [510, 1260],         // 08:30 - 21:00
    ];
    /** Latest end for families and for elderly or low-walking travellers. */
    private const CAREFUL_LATEST_END = 1200;   // 20:00

    /** Recommended daily distance in km; the hard limit is max(3 x recommended, recommended + 15). */
    private const DAILY_DISTANCE_KM = [
        'walking' => ['relaxed' => 3.0, 'normal' => 5.0, 'packed' => 7.0],
        'public_transport' => ['relaxed' => 15.0, 'normal' => 25.0, 'packed' => 35.0],
        'car' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
        'motorcycle' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
    ];
    private const CAREFUL_DISTANCE_FACTOR = 0.75;
    /** Longest single leg (other than the leg from the trip's starting point). */
    private const LEG_LIMIT_KM = ['walking' => 8.0, 'public_transport' => 60.0, 'car' => 150.0, 'motorcycle' => 150.0];

    /** Visit length: 30 to 240 min (90 when unknown); at most 120 for families, 105 for elderly or low walking. */
    private const VISIT_DEFAULT = 90;
    private const VISIT_MIN = 30;
    private const VISIT_MAX = 240;
    private const VISIT_MAX_FAMILY = 120;
    private const VISIT_MAX_CAREFUL = 105;

    /** Avoid heat: no outdoor visit overlaps 11:00 - 16:00. Elderly: no outdoor visit over 150 min. */
    private const HEAT_FROM = 660;
    private const HEAT_UNTIL = 960;
    private const ELDERLY_LONG_VISIT_MIN = 150;
    private const STEP_FREE_BLOCKED = '/gunung|mountain|hill|cave|stairs|waterfall|trail|hiking/i';

    /** Longest wait for a place to open. Time spent on a meal is not waiting. */
    public const MAX_WAIT_MIN = 60;

    /**
     * An explicit day window: when only its start or only its end is given and the window would
     * be shorter than SHORT_WINDOW_MIN, the side not given moves so that the window is
     * WIDENED_WINDOW_MIN long, kept inside EARLIEST_START .. LATEST_END.
     */
    private const SHORT_WINDOW_MIN = 240;
    private const WIDENED_WINDOW_MIN = 480;
    public const EARLIEST_START = 360;     // 06:00 (also the earliest day start an edit may store)
    private const LATEST_END = 1380;       // 23:00

    /**
     * Meals. A meal starts inside its window (minutes after midnight, earliest and latest start)
     * and lasts MEAL_MIN of the pace. A day gets a meal when one of its visits ends at or before
     * the latest start and a later visit ends after it: the traveller is out across the window.
     * The meal is then taken between two visits, at a food place within MEAL_RADIUS_KM (road km)
     * of the stop before or the stop after it, or as a break without a place.
     */
    public const MEAL_WINDOWS = ['lunch' => [690, 840], 'dinner' => [1080, 1230]];   // 11:30-14:00, 18:00-20:30
    private const MEAL_MIN = ['relaxed' => 75, 'normal' => 60, 'packed' => 45];
    private const MEAL_RADIUS_KM = ['walking' => 1.0, 'public_transport' => 2.0, 'car' => 5.0, 'motorcycle' => 5.0];

    /**
     * Learning from the traveller's own ratings: per category, (average - 3) / 2 x LEARN_MAX_POINTS,
     * scaled by the number of ratings up to LEARN_FULL_AT. So it lies within +-LEARN_MAX_POINTS,
     * less than half the 10 points between two interest weights (ItineraryDayBuilder::INTEREST_POINTS),
     * and it never touches check(). A place rated LEARN_DROP_AT or lower is not suggested again.
     */
    public const LEARN_MAX_POINTS = 4.0;
    public const LEARN_FULL_AT = 5;
    public const LEARN_DROP_AT = 2;

    /** Paid places get what the budget leaves after the essentials and this margin on them. */
    public const BUDGET_MARGIN = 0.10;
    private const MEALS_PER_DAY = 3;

    /** East Malaysia; everything else is Peninsular. A point without a state is east from this longitude. */
    private const EAST_STATES = ['sabah', 'sarawak', 'labuan'];
    private const EAST_FROM_LNG = 108.0;

    /**
     * The same landmark under two names: two place rows whose straight-line distance and name
     * similarity (PlaceNameMatcher, 0..1) fall inside one of these pairs [metres, similarity].
     * Close together and clearly the same name, or very close together and probably the same
     * name. Chosen from the place data (docs/PLANNER.md): every true twin within 150 m that the
     * matcher scores 0.9 or more, and the twins within 60 m that it scores 0.5 or more
     * ("Sultan Abdul Samad Building" and "Bangunan Sultan Abdul Samad", 41 m, 0.55), while the
     * different neighbours within 150 m score below 0.5 ("Teluk Cempedak Beach" and "Dataran
     * Teluk Cempedak Kuantan", 99 m, 0.48) and those scoring 0.9 or more lie further apart
     * ("Penang Hill" and "Penang Hill Mosque", 179 m).
     */
    public const SAME_LANDMARK = [[150.0, 0.9], [60.0, 0.5]];

    /** Reason codes of check(), in words for notes and messages. */
    public const REASONS = [
        'DUPLICATE' => 'already in the itinerary',
        'SAME_LANDMARK' => 'the same landmark as a place already in the itinerary',
        'STATE' => 'outside the chosen states',
        'REGION' => 'across the sea between Peninsular and East Malaysia',
        'FESTIVAL_DATES' => 'festival dates unknown',
        'FESTIVAL_DAY' => 'festival not on this day',
        'DIETARY' => 'does not meet the dietary preference',
        'STEP_FREE' => 'not step-free',
        'LONG_OUTDOOR' => 'long outdoor visit',
        'LEG_DISTANCE' => 'too far from the previous stop',
        'DAY_DISTANCE' => 'over the daily distance limit',
        'CLOSED' => 'closed that day',
        'OPEN_HOURS' => 'not open for the whole visit at the planned time',
        'HEAT' => 'outdoors in the midday heat',
        'DAY_END' => 'would end after the latest end of the day',
        'WAIT' => 'would wait too long for opening',
        'BUDGET' => 'over the budget',
        'FINISH' => 'too late or too far to reach the end point of the trip',
        'ROOM' => 'no room left on the days it can be visited',
        'MEAL' => 'would leave no time for lunch or dinner',
        'UNAVAILABLE' => 'not an active place with map coordinates',
        // Reasons of ItineraryEditService: a change to a stored itinerary.
        'AVOID' => 'on your list of places to avoid',
        'DAY_FULL' => 'the day already has its planned number of stops',
        'LAST_STOP' => 'it is the last stop of its day',
        'NOT_FOUND' => 'not found in this itinerary',
        'NOT_EDITABLE' => 'this row cannot be changed this way',
        'LOCKED' => 'the day holds a row the planner cannot re-time',
        'NO_CHANGE' => 'nothing would change',
        'EARLY_START' => 'earlier than the earliest start of a day (06:00)',
    ];

    /**
     * A saved preference, normalised, or null when it does not exist (or is not the traveller's).
     * The inputs of migration 20261115_planner_inputs.sql are read when their columns exist, so a
     * database without that migration plans as before.
     */
    public static function loadPreference(mysqli $conn, int $preferenceId, ?int $travellerId = null): ?array
    {
        $optional = array_values(array_filter(
            ['day_start_time', 'day_end_time', 'stops_per_day', 'adults', 'children', 'seniors', 'accessibility_tags'],
            static fn(string $column): bool => self::hasColumn($conn, 'traveller_preferences', $column)
        ));
        $sql = "
            SELECT preference_id, traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size,
                   travel_pace, dietary_preference, preferred_visit_time, accessibility_needs, interests,
                   preferred_states, preferred_districts" . ($optional ? ', ' . implode(', ', $optional) : '') . "
            FROM traveller_preferences
            WHERE preference_id = ?" . ($travellerId !== null ? " AND traveller_id = ?" : "") . "
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to load saved preference.');
        if ($travellerId !== null) $stmt->bind_param('ii', $preferenceId, $travellerId);
        else $stmt->bind_param('i', $preferenceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        // Interest weights (1 a little, 2 like, 3 love); an interest without a row keeps weight 2.
        if (self::hasColumn($conn, 'traveller_preference_interests', 'weight')) {
            $stmt = $conn->prepare("
                SELECT ti.interest_code, tpi.weight
                FROM traveller_preference_interests tpi
                JOIN travel_interests ti ON ti.interest_id = tpi.interest_id
                WHERE tpi.preference_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('i', $preferenceId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $w) {
                    $row['interest_weights'][strtolower((string)$w['interest_code'])] = (int)$w['weight'];
                }
                $stmt->close();
            }
        }
        // Must-see and avoid places.
        if (self::hasColumn($conn, 'traveller_preference_places', 'wish')) {
            $stmt = $conn->prepare('SELECT place_id, wish FROM traveller_preference_places WHERE preference_id = ? ORDER BY place_id');
            if ($stmt) {
                $stmt->bind_param('i', $preferenceId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p) {
                    $row[$p['wish'] === 'must' ? 'must_places' : 'avoid_places'][] = (int)$p['place_id'];
                }
                $stmt->close();
            }
        }
        return self::normalizePreference($row);
    }

    /** Whether a table has a column (cached per connection and table; false when the table is missing). */
    public static function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = spl_object_id($conn) . ':' . $table;
        if (!isset($cache[$key])) {
            $cache[$key] = [];
            $res = $conn->query('SHOW TABLES LIKE ' . "'" . $conn->real_escape_string($table) . "'");
            if ($res && $res->num_rows > 0) {
                $res = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                if ($res) {
                    foreach ($res as $c) $cache[$key][strtolower((string)$c['Field'])] = true;
                }
            }
        }
        return isset($cache[$key][strtolower($column)]);
    }

    /** A preference row (or an empty array for the defaults) in the form profile() reads. */
    public static function normalizePreference(array $row): array
    {
        $travellerType = CostEstimationService::normalizeTravellerType((string)($row['traveller_type'] ?? 'solo'));
        $interests = array_values(array_filter(self::csv(strtolower((string)($row['interests'] ?? ''))), static fn($x) => in_array($x, self::VALID_INTERESTS, true)));
        $interests = $interests ?: ['culture'];
        $weights = [];
        foreach ($interests as $interest) {
            $weights[$interest] = max(1, min(3, (int)($row['interest_weights'][$interest] ?? 2)));
        }
        $count = static fn(string $key): ?int => isset($row[$key]) && $row[$key] !== '' ? max(0, (int)$row[$key]) : null;
        $ids = static fn(string $key): array => array_values(array_unique(array_filter(array_map('intval', (array)($row[$key] ?? [])), static fn($x) => $x > 0)));
        return [
            'preference_id' => (int)($row['preference_id'] ?? 0),
            'trip_days' => max(1, min(30, (int)($row['trip_days'] ?? 1))),
            'budget' => max(0.0, (float)($row['budget'] ?? 0)),
            'budget_tier' => self::oneOf((string)($row['budget_tier'] ?? 'normal'), ['budget', 'normal', 'luxury'], 'normal'),
            'transport_type' => CostEstimationService::normalizeTransportType((string)($row['transport_type'] ?? 'car')),
            'traveller_type' => $travellerType,
            'party_size' => CostEstimationService::resolvePartySize($travellerType, isset($row['party_size']) ? (int)$row['party_size'] : null),
            'travel_pace' => self::oneOf((string)($row['travel_pace'] ?? 'normal'), ['relaxed', 'normal', 'packed'], 'normal'),
            'dietary_preference' => self::oneOf((string)($row['dietary_preference'] ?? 'none'), ['none', 'halal', 'vegetarian'], 'none'),
            'preferred_visit_time' => self::oneOf((string)($row['preferred_visit_time'] ?? 'any'), ['any', 'morning', 'afternoon', 'evening'], 'any'),
            'accessibility_needs' => trim((string)($row['accessibility_needs'] ?? '')),
            'interests' => $interests,
            'states' => self::csv((string)($row['preferred_states'] ?? '')),
            'districts' => self::csv((string)($row['preferred_districts'] ?? '')),
            // The inputs below are null (or empty) when the traveller did not give them.
            'day_start_min' => self::minutesOf($row['day_start_time'] ?? null),
            'day_end_min' => self::minutesOf($row['day_end_time'] ?? null),
            'stops_per_day' => isset($row['stops_per_day']) && (int)$row['stops_per_day'] > 0 ? min(8, (int)$row['stops_per_day']) : null,
            'adults' => $count('adults'),
            'children' => $count('children'),
            'seniors' => $count('seniors'),
            'access_tags' => isset($row['accessibility_tags']) ? self::csv(strtolower((string)$row['accessibility_tags'])) : null,
            'interest_weights' => $weights,
            'must_places' => $ids('must_places'),
            'avoid_places' => $ids('avoid_places'),
        ];
    }

    /**
     * The planning profile of a preference.
     *
     * Optional keys of $pref that override what the preference implies: day_start_min and
     * day_end_min (minutes after midnight; each replaces its side of the window of the preferred
     * visit time, and an explicit end lifts the 20:00 limit of careful travellers), stops_per_day
     * (replaces the pace target; the rest still comes from the pace), access_tags (the tags
     * column, used instead of the tags in accessibility_needs), children and seniors (children
     * give the family rules, seniors the rules of the elderly tag), interest_weights, learned
     * (from learnedPoints()), and origin_lat / origin_lng (the trip's starting point, for the
     * Peninsular / East Malaysia rule).
     */
    public static function profile(array $pref): array
    {
        $pref += self::normalizePreference([]);
        $pace = (string)$pref['travel_pace'];
        $type = (string)$pref['traveller_type'];
        $transport = (string)$pref['transport_type'];

        $tags = isset($pref['access_tags']) && is_array($pref['access_tags'])
            ? array_values(array_map('strval', $pref['access_tags']))
            : (AccessibilityNeedsAnalysisService::storedTags((string)$pref['accessibility_needs'])
                ?? AccessibilityNeedsAnalysisService::keywordTags((string)$pref['accessibility_needs'], $type));
        // Seniors in the party bring the rules of the elderly tag; children the family rules.
        if ((int)($pref['seniors'] ?? 0) > 0 && !in_array('elderly', $tags, true)) $tags[] = 'elderly';
        $family = $type === 'family' || (int)($pref['children'] ?? 0) > 0;
        $has = static fn(string ...$wanted): bool => (bool)array_intersect($wanted, $tags);
        $careful = $family || $has('elderly', 'low_walking');

        [$start, $end] = self::VISIT_WINDOW[(string)$pref['preferred_visit_time']];
        $givenStart = isset($pref['day_start_min']);
        $givenEnd = isset($pref['day_end_min']);
        if ($givenStart) $start = (int)$pref['day_start_min'];
        if ($givenEnd) $end = (int)$pref['day_end_min'];
        elseif ($careful) $end = min($end, self::CAREFUL_LATEST_END);
        // Only one side given and the window too short: the other side moves.
        if ($givenStart !== $givenEnd && $end - $start < self::SHORT_WINDOW_MIN) {
            if ($givenStart) $end = min(self::LATEST_END, $start + self::WIDENED_WINDOW_MIN);
            else $start = max(self::EARLIEST_START, $end - self::WIDENED_WINDOW_MIN);
        }

        $dayKm = self::DAILY_DISTANCE_KM[$transport][$pace] ?? 45.0;
        if ($has('low_walking', 'wheelchair', 'elderly')) $dayKm *= self::CAREFUL_DISTANCE_FACTOR;

        $visitMax = self::VISIT_MAX;
        if ($family) $visitMax = min($visitMax, self::VISIT_MAX_FAMILY);
        if ($has('elderly', 'low_walking')) $visitMax = min($visitMax, self::VISIT_MAX_CAREFUL);

        $days = (int)$pref['trip_days'];
        $party = (int)$pref['party_size'];
        $tier = CostEstimationService::budgetTierDefaults((string)$pref['budget_tier'], (float)$pref['budget'], $days, $party);

        $originRegion = null;
        if (isset($pref['origin_lat'], $pref['origin_lng'])) $originRegion = self::region(null, (float)$pref['origin_lng']);

        return [
            'target' => isset($pref['stops_per_day']) ? max(1, (int)$pref['stops_per_day']) : self::PACE_TARGET[$pace],
            'rest' => self::PACE_REST_MIN[$pace] + ($careful ? self::REST_EXTRA_CAREFUL : 0) + ($type === 'group' ? self::REST_EXTRA_GROUP : 0),
            'start' => $start,
            'end' => $end,
            'day_km' => $dayKm,
            'hard_km' => max($dayKm * 3.0, $dayKm + 15.0),
            'leg_km' => self::LEG_LIMIT_KM[$transport] ?? 150.0,
            'visit_max' => $visitMax,
            'tags' => $tags,
            'step_free' => $has('wheelchair', 'avoid_stairs', 'step_free'),
            'avoid_heat' => $has('avoid_heat'),
            'elderly' => $has('elderly'),
            'careful' => $careful,
            'family' => $family,
            'traveller_type' => $type,
            'transport' => $transport,
            'pace' => $pace,
            'visit_time' => (string)$pref['preferred_visit_time'],
            'dietary' => (string)$pref['dietary_preference'],
            'interests' => (array)$pref['interests'],
            'interest_weights' => (array)($pref['interest_weights'] ?? []),
            'learned' => (array)($pref['learned'] ?? []),
            'food_only' => (array)$pref['interests'] === ['food'],
            'states' => array_map('strtolower', (array)$pref['states']),
            'districts' => array_map('strtolower', (array)$pref['districts']),
            'origin_region' => $originRegion,
            'days' => $days,
            'party' => $party,
            'budget' => (float)$pref['budget'],
            'budget_tier' => (string)$pref['budget_tier'],
            'hotel_rate' => (float)$tier['hotel'],
            'meal_price' => (float)$tier['meal'],
            'meal_min' => self::MEAL_MIN[$pace],
            'meal_radius_km' => self::MEAL_RADIUS_KM[$transport] ?? 5.0,
            // The trip's twin groups (sameLandmarks()), passed in by whoever loaded the candidates.
            'twins' => (array)($pref['twins'] ?? []),
        ];
    }

    /**
     * Pairs of place rows that are the same landmark (SAME_LANDMARK). The names are weighed
     * against the names of all the given places (PlaceNameMatcher), so give the trip's whole
     * candidate set at once.
     *
     * @param array $places place rows with place_id, name, latitude, longitude
     * @return array<int,array{a:array,b:array,metres:float,similarity:float}> a has the lower place_id
     */
    public static function sameLandmarkPairs(array $places): array
    {
        $rows = [];
        foreach ($places as $p) {
            $id = (int)($p['place_id'] ?? 0);
            if ($id <= 0 || !isset($p['latitude'], $p['longitude']) || !is_numeric($p['latitude']) || !is_numeric($p['longitude'])) continue;
            $rows[$id] = $p;
        }
        if (count($rows) < 2) return [];
        $list = array_values($rows);
        usort($list, static fn(array $a, array $b): int => [(float)$a['latitude'], (int)$a['place_id']] <=> [(float)$b['latitude'], (int)$b['place_id']]);
        $matcher = new PlaceNameMatcher(array_map(static fn(array $p): string => (string)($p['name'] ?? ''), $list));
        $reach = max(array_column(self::SAME_LANDMARK, 0));
        $latReach = $reach / 111000.0;   // a degree of latitude is at least 110.5 km
        $pairs = [];
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ((float)$list[$j]['latitude'] - (float)$list[$i]['latitude'] > $latReach) break;
                $metres = self::metres($list[$i], $list[$j]);
                if ($metres > $reach) continue;
                $similarity = $matcher->similarity((string)($list[$i]['name'] ?? ''), (string)($list[$j]['name'] ?? ''));
                if (!self::isSameLandmark($metres, $similarity)) continue;
                [$a, $b] = (int)$list[$i]['place_id'] < (int)$list[$j]['place_id'] ? [$list[$i], $list[$j]] : [$list[$j], $list[$i]];
                $pairs[] = ['a' => $a, 'b' => $b, 'metres' => round($metres, 1), 'similarity' => round($similarity, 4)];
            }
        }
        usort($pairs, static fn(array $x, array $y): int => [(int)$x['a']['place_id'], (int)$x['b']['place_id']] <=> [(int)$y['a']['place_id'], (int)$y['b']['place_id']]);
        return $pairs;
    }

    /** Whether a distance (metres) and a name similarity make two rows one landmark. */
    public static function isSameLandmark(float $metres, float $similarity): bool
    {
        foreach (self::SAME_LANDMARK as [$within, $least]) {
            if ($metres <= $within && $similarity >= $least) return true;
        }
        return false;
    }

    /**
     * The twin groups of a trip, worked out once from its loaded candidates: place_id => the
     * other members of its group (place_id => name). Twins of twins are one group. Put it in the
     * profile input as 'twins' so that check() and the meal choice keep one row per group.
     */
    public static function sameLandmarks(array $places): array
    {
        $parent = [];
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) $x = $parent[$x] = $parent[$parent[$x]];
            return $x;
        };
        $names = [];
        foreach (self::sameLandmarkPairs($places) as $pair) {
            $a = (int)$pair['a']['place_id'];
            $b = (int)$pair['b']['place_id'];
            $names[$a] = (string)$pair['a']['name'];
            $names[$b] = (string)$pair['b']['name'];
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) $parent[max($ra, $rb)] = min($ra, $rb);
        }
        $groups = [];
        foreach (array_keys($parent) as $id) $groups[$find($id)][] = $id;
        $twins = [];
        foreach ($groups as $members) {
            sort($members);
            foreach ($members as $id) {
                foreach ($members as $other) if ($other !== $id) $twins[$id][$other] = $names[$other];
            }
        }
        ksort($twins);
        return $twins;
    }

    /** A twin of the place among $ids (place_id => anything), or null. */
    public static function twinIn(array $profile, int $placeId, array $ids): ?int
    {
        foreach (array_keys((array)($profile['twins'][$placeId] ?? [])) as $twin) {
            if (isset($ids[$twin])) return (int)$twin;
        }
        return null;
    }

    /** The name of a place's twin, as the twin groups hold it. */
    public static function twinName(array $profile, int $placeId, int $twinId): string
    {
        return (string)($profile['twins'][$placeId][$twinId] ?? ('place #' . $twinId));
    }

    /** The given place ids (place_id => true) with every twin of each. */
    public static function withTwins(array $profile, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(int)$id] = true;
            foreach (array_keys((array)($profile['twins'][(int)$id] ?? [])) as $twin) $out[(int)$twin] = true;
        }
        return $out;
    }

    /** Straight-line distance in metres between two place rows. */
    private static function metres(array $a, array $b): float
    {
        $lat1 = deg2rad((float)$a['latitude']);
        $lat2 = deg2rad((float)$b['latitude']);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float)$b['longitude'] - (float)$a['longitude']);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        return 2 * 6371000.0 * asin(min(1.0, sqrt($h)));
    }

    /** Words in a place name that mean it stands in the open; used only by isOutdoor(). */
    private const OUTDOOR_NAME = '/\b(statue|fountain|waterfall|air terjun|beach|pantai|lighthouse|rumah api|viewpoint|lookout|bridge|jambatan|square|dataran|monument|tugu|clock tower|street art|mural|pintu gerbang|esplanade|waterfront|jetty|jeti|park|gardens?|lake|tasik|caves?|gua|trail)\b/i';

    /** A place is outdoor when it is marked so or is a nature place (for the weather rule). */
    public static function isOutdoor(array $place): bool
    {
        if ((int)($place['is_outdoor'] ?? 0) === 1) return true;
        $category = strtolower((string)($place['category'] ?? ''));
        if ($category === 'nature') return true;
        // Many older rows have no outdoor flag. For the weather preference, which is a soft rule,
        // a culture or heritage place whose name says it stands in the open counts as outdoor too.
        return in_array($category, ['culture', 'heritage'], true)
            && preg_match(self::OUTDOOR_NAME, (string)($place['name'] ?? '')) === 1;
    }

    /**
     * Most visits of one category on one day before category variety is relaxed: the interest
     * weight (1 a little, 2 like, 3 love); 2 for a category without a weight.
     */
    public static function categoryLimit(array $profile, string $category): int
    {
        return max(1, min(3, (int)($profile['interest_weights'][strtolower($category)] ?? 2)));
    }

    /** How long a visit to this place lasts for this traveller. */
    public static function visitMinutes(array $place, array $profile): int
    {
        $minutes = (int)($place['visit_duration_min'] ?? 0);
        if ($minutes <= 0) $minutes = self::VISIT_DEFAULT;
        return max(self::VISIT_MIN, min(self::VISIT_MAX, (int)$profile['visit_max'], $minutes));
    }

    /** What a place costs per person: its entrance fee, otherwise its estimated cost. */
    public static function placeCost(array $place): float
    {
        $fee = (float)($place['entrance_fee'] ?? 0);
        $estimate = (float)($place['estimated_cost'] ?? 0);
        return max(0.0, $fee > 0 ? $fee : $estimate);
    }

    /** The itinerary item type for a place. */
    public static function itemType(array $place): string
    {
        $category = strtolower((string)($place['category'] ?? ''));
        return in_array($category, ['food', 'festival'], true) ? $category : 'attraction';
    }

    /** "east" for East Malaysia, "west" for Peninsular Malaysia. */
    public static function region(?string $state, float $lng): string
    {
        $state = strtolower(trim((string)$state));
        if ($state !== '') return in_array($state, self::EAST_STATES, true) ? 'east' : 'west';
        return $lng >= self::EAST_FROM_LNG ? 'east' : 'west';
    }

    /**
     * The rules that do not depend on the slot: state, region, festival dates, dietary,
     * step-free access and long outdoor visits. check() applies them too; callers may use
     * this alone to narrow a pool once.
     */
    public static function placeReason(array $place, array $profile): ?string
    {
        $category = strtolower((string)($place['category'] ?? ''));
        if ($profile['states'] && !in_array(strtolower(trim((string)($place['state'] ?? ''))), $profile['states'], true)) return 'STATE';
        if (!$profile['states'] && $profile['origin_region'] !== null
            && self::region((string)($place['state'] ?? ''), (float)($place['longitude'] ?? 0)) !== $profile['origin_region']) return 'REGION';
        if ($category === 'festival' && self::festivalDates($place) === null) return 'FESTIVAL_DATES';
        if ($category === 'food') {
            if ($profile['dietary'] === 'halal' && (int)($place['halal_status'] ?? 0) !== 1) return 'DIETARY';
            if ($profile['dietary'] === 'vegetarian' && !preg_match('/vegetarian|vegan/i', (string)($place['name'] ?? '') . ' ' . (string)($place['description'] ?? ''))) return 'DIETARY';
        }
        $outdoor = (int)($place['is_outdoor'] ?? 0) === 1;
        if ($profile['step_free'] && preg_match(self::STEP_FREE_BLOCKED, (string)($place['name'] ?? '') . ' ' . (string)($place['description'] ?? '') . ' ' . $category)) return 'STEP_FREE';
        if ($profile['elderly'] && $outdoor && (int)($place['visit_duration_min'] ?? self::VISIT_DEFAULT) > self::ELDERLY_LONG_VISIT_MIN) return 'LONG_OUTDOOR';
        return null;
    }

    /**
     * Can this place be visited in this slot? The one feasibility check of the planner.
     *
     * $slot keys:
     *   date        Y-m-d of the day
     *   arrive      earliest arrival, minutes after midnight (when the traveller can be there)
     *   leg_km      distance of the leg to the place
     *   leg_counts  false only for the leg from the trip's starting point on day 1, which the
     *               distance limits do not count
     *   day_km      km the day has already travelled (counted legs only)
     *   used        optional: place_id => true for places already in the itinerary; a place
     *               whose twin (profile 'twins') is among them fails with SAME_LANDMARK
     *   trip_items  optional: the other planned items (item_type, estimated_cost) for the
     *               budget; without it the budget is not checked
     *   trip_km     km of all other planned legs;  reserve_km  km held back for legs not planned yet
     *   finish_km, finish_min  optional, last day only: the leg from the place to the trip's end point
     *
     * @return array{ok:bool,reason:?string,start:int,end:int,wait:int}
     */
    public static function check(array $place, array $slot, array $profile): array
    {
        $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'start' => 0, 'end' => 0, 'wait' => 0];

        $placeId = (int)($place['place_id'] ?? 0);
        if ($placeId > 0 && isset($slot['used'][$placeId])) return $fail('DUPLICATE');
        if ($placeId > 0 && !empty($slot['used']) && self::twinIn($profile, $placeId, (array)$slot['used']) !== null) return $fail('SAME_LANDMARK');
        $reason = self::placeReason($place, $profile);
        if ($reason !== null) return $fail($reason);
        $date = (string)$slot['date'];
        $dates = self::festivalDates($place);
        if ($dates !== null && ($date < $dates[0] || $date > $dates[1])) return $fail('FESTIVAL_DAY');

        $legKm = (float)$slot['leg_km'];
        if (!empty($slot['leg_counts'])) {
            if ($legKm > (float)$profile['leg_km']) return $fail('LEG_DISTANCE');
            if ((float)($slot['day_km'] ?? 0) + $legKm > (float)$profile['hard_km']) return $fail('DAY_DISTANCE');
        }

        // Time: the earliest start inside the day window and the opening hours, waiting for opening if needed.
        $visit = self::visitMinutes($place, $profile);
        $arrive = max((int)$slot['arrive'], (int)$profile['start']);
        $end = (int)$profile['end'];
        $hours = (string)($place['opening_hours'] ?? '');
        if ($profile['avoid_heat'] && (int)($place['is_outdoor'] ?? 0) === 1) {
            // Either over by 11:00 or begun after 16:00.
            $start = OpeningHours::earliestStart($hours, $date, $arrive, $visit, min($end, self::HEAT_FROM));
            $start ??= OpeningHours::earliestStart($hours, $date, max($arrive, self::HEAT_UNTIL + 1), $visit, $end);
            if ($start === null) {
                return $fail(OpeningHours::earliestStart($hours, $date, $arrive, $visit, $end) !== null ? 'HEAT' : self::timeReason($hours, $date, $arrive, $visit, $end));
            }
        } else {
            $start = OpeningHours::earliestStart($hours, $date, $arrive, $visit, $end);
            if ($start === null) return $fail(self::timeReason($hours, $date, $arrive, $visit, $end));
        }
        if ($start - $arrive > self::MAX_WAIT_MIN) return $fail('WAIT');

        // The last day: from this stop the trip's end point must be reached inside the day window
        // and inside the hard daily distance (the leg to the end point counts).
        if (isset($slot['finish_min'])) {
            if ($start + $visit + (int)$slot['finish_min'] > $end) return $fail('FINISH');
            $counted = (float)($slot['day_km'] ?? 0) + (!empty($slot['leg_counts']) ? $legKm : 0.0);
            if ($counted + (float)($slot['finish_km'] ?? 0) > (float)$profile['hard_km']) return $fail('FINISH');
        }

        if (array_key_exists('trip_items', $slot) && $slot['trip_items'] !== null) {
            $cost = self::placeCost($place);
            $items = $slot['trip_items'];
            $items[] = ['item_type' => self::itemType($place), 'estimated_cost' => $cost];
            $km = (float)($slot['trip_km'] ?? 0) + $legKm + (float)($slot['reserve_km'] ?? 0);
            if (!self::budgetAllows($profile, $items, $km, $cost)) return $fail('BUDGET');
        }

        return ['ok' => true, 'reason' => null, 'start' => $start, 'end' => $start + $visit, 'wait' => $start - $arrive];
    }

    /**
     * The budget rule. Paid places may take what the budget leaves after the essentials
     * (meals, hotel, transport) and a margin of BUDGET_MARGIN on them. When the essentials
     * alone are over the budget, only free places are added. The rule only grows stricter
     * as items and km are added, so a plan that keeps it keeps it in any order of its stops.
     *
     * @param array $items     every planned item including the one being added
     * @param float $km        every planned km including the new leg (and any reserve)
     * @param float $addedCost per-person cost of the item being added
     */
    public static function budgetAllows(array $profile, array $items, float $km, float $addedCost): bool
    {
        if ((float)$profile['budget'] <= 0) return true;
        $total = self::projectedTotal($profile, $items, $km);
        $paid = (float)$total['attraction_cost'];
        $essentials = (float)$total['total_cost'] - $paid;
        if ($paid + $essentials * (1 + self::BUDGET_MARGIN) <= (float)$profile['budget'] + 0.005) return true;
        // Over the budget: a free place is still added while nothing with a price is planned.
        if ($addedCost > 0) return false;
        foreach ($items as $item) {
            if (strtolower((string)($item['item_type'] ?? '')) !== 'hotel' && (float)($item['estimated_cost'] ?? 0) > 0) return false;
        }
        return true;
    }

    /** The trip total for these items and km, computed as ItineraryEditService::total() stores it. */
    public static function projectedTotal(array $profile, array $items, float $km): array
    {
        $service = new CostEstimationService((string)$profile['transport'], (int)$profile['days'], (float)$profile['budget'], (string)$profile['traveller_type'], (int)$profile['party']);
        return $service->calculate($items, $km, (float)$profile['hotel_rate'], self::MEALS_PER_DAY, (float)$profile['meal_price']);
    }

    /**
     * The score adjustment per category from the traveller's own ratings.
     *
     * @param array<string,array{avg:float,count:int}> $ratings per category
     * @return array<string,array{points:float,avg:float,count:int}>
     */
    public static function learnedPoints(array $ratings): array
    {
        $out = [];
        foreach ($ratings as $category => $r) {
            $count = (int)($r['count'] ?? 0);
            if ($count <= 0) continue;
            $avg = max(1.0, min(5.0, (float)$r['avg']));
            $points = self::LEARN_MAX_POINTS * (($avg - 3.0) / 2.0) * (min($count, self::LEARN_FULL_AT) / self::LEARN_FULL_AT);
            $out[strtolower((string)$category)] = ['points' => round($points, 2), 'avg' => round($avg, 1), 'count' => $count];
        }
        return $out;
    }

    /** [start, end] dates of a festival, or null when they are missing or not in order. */
    public static function festivalDates(array $place): ?array
    {
        $start = trim((string)($place['festival_start_date'] ?? ''));
        $end = trim((string)($place['festival_end_date'] ?? ''));
        if ($start === '' || $end === '' || $start === '0000-00-00' || $end === '0000-00-00' || $end < $start) return null;
        return [$start, $end];
    }

    /** The words for a reason code. */
    public static function reasonText(string $code): string
    {
        return self::REASONS[$code] ?? strtolower(str_replace('_', ' ', $code));
    }

    /** "HH:MM:00" for minutes after midnight. A time outside the day is a planning error, never clamped. */
    public static function sqlTime(int $minutes): string
    {
        if ($minutes < 0 || $minutes >= 1440) throw new RuntimeException('A planned time falls outside the day.');
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    /** "09:30:00" => 570, or null. */
    public static function minutesOf($time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$time, $m)) return null;
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function timeReason(string $hours, string $date, int $arrive, int $visit, int $end): string
    {
        $ranges = OpeningHours::rangesOn($hours, $date);
        if ($ranges === []) return 'CLOSED';
        if ($ranges === null || $arrive + $visit > $end) return 'DAY_END';
        return 'OPEN_HOURS';
    }

    private static function csv(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $text)), static fn($x) => $x !== '')));
    }

    private static function oneOf(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
