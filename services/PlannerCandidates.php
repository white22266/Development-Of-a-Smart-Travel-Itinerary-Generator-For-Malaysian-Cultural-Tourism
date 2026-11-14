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

require_once __DIR__ . '/RouteService.php';

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
