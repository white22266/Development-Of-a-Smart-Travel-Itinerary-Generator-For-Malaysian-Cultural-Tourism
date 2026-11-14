<?php
// services/PlannerCandidates.php
// The one query for the places an itinerary may use: active places with coordinates, inside
// the chosen states when states were chosen, and festivals only when both their dates are
// known and in order. The place table itself is never changed or shadowed.
//
//   load()             the candidate pool
//   byIds()            given places (the must-see list) wherever they are, so a place that cannot
//                      be used can be named with its reason
//   travellerRatings() what the traveller rated before, per category and per place
//   mealPool()         the food places of a pool, where lunch and dinner may be planned
//   mealPlaces()       that pool for a saved preference (the "Optimise route" button)
//   editPool()         the places a change to a saved itinerary may bring in (ItineraryEditService)
//   twinGroups()       the same-landmark groups of a trip's candidates (PlannerRules::sameLandmarks())
//
// A place whose twin is on the avoid list is kept out with it (the profile's 'twins').
//
// Meal places come from cultural_places (category food) only. The table food_places is not
// used: its rows have no place_id, no halal or dietary data and no link to ratings or the
// avoid list, so the planner's rules could not be applied to them.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/PlannerRules.php';

final class PlannerCandidates
{
    /** SQL condition: a place is not a festival, or it is a festival with valid dates. */
    public const VALID_FESTIVAL_SQL = "(LOWER(TRIM(COALESCE(category, ''))) <> 'festival'
        OR (festival_start_date IS NOT NULL AND festival_end_date IS NOT NULL
            AND festival_start_date <> '0000-00-00' AND festival_end_date <> '0000-00-00'
            AND festival_end_date >= festival_start_date))";

    /**
     * @param array $pref a preference from PlannerRules::loadPreference() (uses its "states")
     * @return array<int,array> place rows, category in lower case, ordered by place_id
     */
    public static function load(mysqli $conn, array $pref): array
    {
        $states = array_values((array)($pref['states'] ?? []));
        $where = ['is_active = 1', 'latitude IS NOT NULL', 'longitude IS NOT NULL', self::VALID_FESTIVAL_SQL];
        if ($states) {
            $where[] = 'state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        }
        return self::query($conn, $where, $states);
    }

    /**
     * Active places with coordinates among the given ids, in any state and with any festival
     * dates (the rules then give the reason a place cannot be used).
     *
     * @param int[] $ids
     * @return array<int,array> place rows as load() returns them
     */
    public static function byIds(mysqli $conn, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));
        if (!$ids) return [];
        return self::query($conn, ['is_active = 1', 'latitude IS NOT NULL', 'longitude IS NOT NULL', 'place_id IN (' . implode(',', $ids) . ')'], []);
    }

    /**
     * The traveller's own ratings: per category the average and the number, and the places
     * rated at or below $dropAt.
     *
     * @return array{categories:array<string,array{avg:float,count:int}>,low:array<int,int>} low: place_id => rating
     */
    public static function travellerRatings(mysqli $conn, int $travellerId, int $dropAt): array
    {
        $out = ['categories' => [], 'low' => []];
        if ($travellerId <= 0) return $out;
        $stmt = $conn->prepare("
            SELECT rr.place_id, rr.rating, LOWER(TRIM(COALESCE(cp.category, ''))) AS category
            FROM ratings_reviews rr
            JOIN cultural_places cp ON cp.place_id = rr.place_id
            WHERE rr.traveller_id = ? AND rr.rating BETWEEN 1 AND 5
            ORDER BY rr.place_id
        ");
        if (!$stmt) return $out;
        $stmt->bind_param('i', $travellerId);
        $stmt->execute();
        $sums = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rating = (int)$row['rating'];
            if ($row['category'] !== '') {
                $sums[$row['category']][0] = ($sums[$row['category']][0] ?? 0) + $rating;
                $sums[$row['category']][1] = ($sums[$row['category']][1] ?? 0) + 1;
            }
            if ($rating <= $dropAt) $out['low'][(int)$row['place_id']] = $rating;
        }
        $stmt->close();
        foreach ($sums as $category => [$sum, $n]) $out['categories'][$category] = ['avg' => $sum / $n, 'count' => $n];
        return $out;
    }

    /**
     * The food places of a candidate pool. The pool must already be narrowed the way the
     * generator narrows it (avoid list, low ratings, PlannerRules::placeReason(), which holds
     * the dietary rule), so every place here may be a meal place.
     */
    public static function mealPool(array $pool): array
    {
        return array_values(array_filter($pool, static fn(array $place): bool => strtolower((string)$place['category']) === 'food'));
    }

    /**
     * The meal places for a saved preference: food places in its states, not on its avoid list,
     * not rated LEARN_DROP_AT or lower by the traveller, and keeping every rule of
     * PlannerRules::placeReason() (dietary included).
     */
    public static function mealPlaces(mysqli $conn, array $pref, array $profile, int $travellerId): array
    {
        $skip = PlannerRules::withTwins($profile, (array)($pref['avoid_places'] ?? [])) + self::travellerRatings($conn, $travellerId, PlannerRules::LEARN_DROP_AT)['low'];
        $where = ['is_active = 1', 'latitude IS NOT NULL', 'longitude IS NOT NULL', "LOWER(TRIM(COALESCE(category, ''))) = 'food'"];
        $states = array_values((array)($pref['states'] ?? []));
        if ($states) $where[] = 'state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        return self::mealPool(array_filter(self::query($conn, $where, $states), static fn(array $place): bool =>
            !isset($skip[(int)$place['place_id']]) && PlannerRules::placeReason($place, $profile) === null));
    }

    /**
     * The places a change to a saved itinerary may bring in, narrowed as the generator narrows
     * its pool: not on the avoid list, not rated LEARN_DROP_AT or lower by the traveller (a
     * must-see place stays wanted), and keeping every rule of PlannerRules::placeReason().
     * $loaded: the rows of load() when the caller has them already.
     */
    public static function editPool(mysqli $conn, array $pref, array $profile, int $travellerId, ?array $loaded = null): array
    {
        $low = self::travellerRatings($conn, $travellerId, PlannerRules::LEARN_DROP_AT)['low'];
        foreach ((array)($pref['must_places'] ?? []) as $id) unset($low[(int)$id]);
        $skip = PlannerRules::withTwins($profile, (array)($pref['avoid_places'] ?? [])) + $low;
        return array_values(array_filter($loaded ?? self::load($conn, $pref), static fn(array $place): bool =>
            !isset($skip[(int)$place['place_id']]) && PlannerRules::placeReason($place, $profile) === null));
    }

    /**
     * The twin groups of a trip (PlannerRules::sameLandmarks()), worked out once from its
     * candidates (load()) and any further rows the trip holds (its stored places), by place_id.
     */
    public static function twinGroups(array $loaded, array $extra = []): array
    {
        $rows = [];
        foreach (array_merge($loaded, $extra) as $row) $rows[(int)$row['place_id']] ??= $row;
        return PlannerRules::sameLandmarks(array_values($rows));
    }

    private static function query(mysqli $conn, array $where, array $states): array
    {
        $sql = "
            SELECT place_id, state, district, category, name, description, address, latitude, longitude,
                   opening_hours, festival_start_date, festival_end_date, estimated_cost, entrance_fee, is_free,
                   halal_status, is_outdoor, visit_duration_min, best_time_to_visit, dress_code_required,
                   avg_rating, rating
            FROM cultural_places
            WHERE " . implode(' AND ', $where) . "
            ORDER BY place_id
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to load cultural places.');
        if ($states) $stmt->bind_param(str_repeat('s', count($states)), ...$states);
        $stmt->execute();
        $res = $stmt->get_result();
        $pool = [];
        while ($row = $res->fetch_assoc()) {
            if (!RouteService::validCoord((float)$row['latitude'], (float)$row['longitude'])) continue;
            $row['place_id'] = (int)$row['place_id'];
            $row['latitude'] = (float)$row['latitude'];
            $row['longitude'] = (float)$row['longitude'];
            $row['category'] = strtolower(trim((string)$row['category']));
            $row['district'] = trim((string)($row['district'] ?? ''));
            $row['state'] = trim((string)($row['state'] ?? ''));
            $pool[] = $row;
        }
        $stmt->close();
        return $pool;
    }
}
