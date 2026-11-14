<?php
// services/PlannerCandidates.php
// The one query for the places an itinerary may use: active places with coordinates, inside
// the chosen states when states were chosen, and festivals only when both their dates are
// known and in order. The place table itself is never changed or shadowed.

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
