<?php
// services/ItineraryEmptyDayFallbackService.php
// Final safety layer for itinerary generation.
// It fills any empty generated day using this priority:
// 1) preferred district + preferred interest
// 2) preferred district + any category
// 3) nearest available district in the preferred state
// 4) nearest available place from the remaining candidate pool

require_once __DIR__ . '/CostEstimationService.php';

final class ItineraryEmptyDayFallbackService
{
    public static function register(mysqli $conn): void
    {
        register_shutdown_function(static function () use ($conn): void {
            $lastError = error_get_last();
            if ($lastError && in_array((int)$lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $itineraryId = (int)($GLOBALS['itineraryId'] ?? 0);
            if ($itineraryId <= 0) {
                return;
            }

            try {
                self::fillEmptyDays($conn, $itineraryId);
            } catch (Throwable $e) {
                error_log('Empty-day fallback failed: ' . $e->getMessage());
            }
        });
    }

    public static function fillEmptyDays(mysqli $conn, int $itineraryId): void
    {
        $context = self::loadContext($conn, $itineraryId);
        if (!$context) {
            return;
        }

        $tripDays = max(1, (int)$context['total_days']);
        $filledDays = self::loadFilledDays($conn, $itineraryId);
        $emptyDays = [];
        for ($day = 1; $day <= $tripDays; $day++) {
            if (!isset($filledDays[$day])) {
                $emptyDays[] = $day;
            }
        }
        if (empty($emptyDays)) {
            return;
        }

        $usedIds = self::loadUsedPlaceIds($conn, $itineraryId);
        $candidates = self::loadCandidates($conn, $context, $usedIds);
        if (empty($candidates)) {
            error_log('Empty-day fallback: no active cultural places are available for itinerary ' . $itineraryId);
            return;
        }

        $preferredDistricts = self::csvList((string)($context['preferred_districts'] ?? ''));
        $preferredStates = self::csvList((string)($context['preferred_states'] ?? ''));
        $preferredInterests = self::csvList((string)($context['interests'] ?? ''));
        $quota = self::dailyQuota((string)($context['travel_pace'] ?? 'normal'));
        $transport = self::normalizeTransport((string)($context['transport_type'] ?? 'car'));

        foreach ($emptyDays as $dayNo) {
            $anchor = self::loadPreviousAnchor($conn, $itineraryId, $dayNo, $context);
            $previousCategory = self::loadPreviousCategory($conn, $itineraryId, $dayNo);

            $ranked = self::rankCandidates(
                $candidates,
                $usedIds,
                $preferredDistricts,
                $preferredStates,
                $preferredInterests,
                $anchor,
                $previousCategory
            );

            if (empty($ranked)) {
                continue;
            }

            $selected = array_slice($ranked, 0, min($quota, count($ranked)));
            $inserted = self::insertDay($conn, $itineraryId, $dayNo, $selected, $anchor, $transport, $usedIds);

            foreach ($inserted as $placeId) {
                $usedIds[$placeId] = true;
            }
        }

        self::recalculateTotal($conn, $itineraryId, $context);
    }

    private static function loadContext(mysqli $conn, int $itineraryId): ?array
    {
        $partySelect = self::hasColumn($conn, 'traveller_preferences', 'party_size') ? ', tp.party_size' : '';
        $stmt = $conn->prepare("
            SELECT i.itinerary_id, i.preference_id, i.total_days, i.start_date,
                   i.origin_name, i.origin_lat, i.origin_lng,
                   tp.preferred_districts, tp.preferred_states, tp.interests,
                   tp.travel_pace, tp.transport_type, tp.budget, tp.budget_tier,
                   tp.traveller_type{$partySelect}
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function loadFilledDays(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare("SELECT day_no, COUNT(*) AS item_count FROM itinerary_items WHERE itinerary_id = ? GROUP BY day_no");
        if (!$stmt) return [];
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $days = [];
        while ($row = $res->fetch_assoc()) {
            if ((int)$row['item_count'] > 0) $days[(int)$row['day_no']] = true;
        }
        $stmt->close();
        return $days;
    }

    private static function loadUsedPlaceIds(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare("SELECT place_id FROM itinerary_items WHERE itinerary_id = ? AND place_id IS NOT NULL");
        if (!$stmt) return [];
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $used = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['place_id'];
            if ($id > 0) $used[$id] = true;
        }
        $stmt->close();
        return $used;
    }

    private static function loadCandidates(mysqli $conn, array $context, array $usedIds): array
    {
        $hasDistrict = self::hasColumn($conn, 'cultural_places', 'district');
        $hasEntranceFee = self::hasColumn($conn, 'cultural_places', 'entrance_fee');
        $hasVisitDuration = self::hasColumn($conn, 'cultural_places', 'visit_duration_min');
        $hasFestivalStart = self::hasColumn($conn, 'cultural_places', 'festival_start_date');
        $hasFestivalEnd = self::hasColumn($conn, 'cultural_places', 'festival_end_date');

        $districtCol = $hasDistrict ? 'district' : "'' AS district";
        $entranceCol = $hasEntranceFee ? 'entrance_fee' : 'estimated_cost AS entrance_fee';
        $durationCol = $hasVisitDuration ? 'visit_duration_min' : 'NULL AS visit_duration_min';
        $festivalCols = ($hasFestivalStart && $hasFestivalEnd)
            ? ', festival_start_date, festival_end_date'
            : ", NULL AS festival_start_date, NULL AS festival_end_date";

        $sql = "
            SELECT place_id, state, {$districtCol}, category, name, address,
                   latitude, longitude, opening_hours, estimated_cost,
                   {$entranceCol}, {$durationCol}{$festivalCols}
            FROM cultural_places
            WHERE is_active = 1
              AND latitude IS NOT NULL AND longitude IS NOT NULL
              AND NOT (latitude = 0 AND longitude = 0)
        ";

        if (!empty($usedIds)) {
            $sql .= ' AND place_id NOT IN (' . implode(',', array_map('intval', array_keys($usedIds))) . ')';
        }

        $res = $conn->query($sql);
        if (!$res) return [];

        $tripStart = trim((string)($context['start_date'] ?? ''));
        $tripDays = max(1, (int)($context['total_days'] ?? 1));
        $tripEnd = $tripStart;
        if ($tripStart !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $tripStart);
            if ($dt) {
                $dt->modify('+' . ($tripDays - 1) . ' days');
                $tripEnd = $dt->format('Y-m-d');
            }
        }

        $places = [];
        while ($row = $res->fetch_assoc()) {
            if (strtolower((string)($row['category'] ?? '')) === 'festival') {
                $start = trim((string)($row['festival_start_date'] ?? ''));
                $end = trim((string)($row['festival_end_date'] ?? ''));
                if ($tripStart === '' || $start === '' || $end === '' || $start > $tripEnd || $end < $tripStart) {
                    continue;
                }
            }
            $places[] = $row;
        }
        return $places;
    }

    private static function rankCandidates(
        array $candidates,
        array $usedIds,
        array $preferredDistricts,
        array $preferredStates,
        array $preferredInterests,
        ?array $anchor,
        string $previousCategory
    ): array {
        $preferredDistrictsLower = array_map('strtolower', $preferredDistricts);
        $preferredStatesLower = array_map('strtolower', $preferredStates);
        $preferredInterestsLower = array_map('strtolower', $preferredInterests);

        $ranked = [];
        foreach ($candidates as $place) {
            $placeId = (int)($place['place_id'] ?? 0);
            if ($placeId <= 0 || isset($usedIds[$placeId])) continue;

            $district = strtolower(trim((string)($place['district'] ?? '')));
            $state = strtolower(trim((string)($place['state'] ?? '')));
            $category = strtolower(trim((string)($place['category'] ?? '')));

            $districtMatch = !empty($preferredDistrictsLower) && in_array($district, $preferredDistrictsLower, true);
            $stateMatch = empty($preferredStatesLower) || in_array('malaysia', $preferredStatesLower, true) || in_array($state, $preferredStatesLower, true);
            $interestMatch = empty($preferredInterestsLower) || in_array($category, $preferredInterestsLower, true);

            // Priority required by the system:
            // 0 = same selected district + interest
            // 1 = same selected district, ignore interest
            // 2 = nearby district in selected state
            // 3 = any remaining place
            if ($districtMatch && $interestMatch) $tier = 0;
            elseif ($districtMatch) $tier = 1;
            elseif ($stateMatch) $tier = 2;
            else $tier = 3;

            $distance = self::distanceFromAnchor($place, $anchor);
            $categoryPenalty = ($previousCategory !== '' && $category === strtolower($previousCategory)) ? 25.0 : 0.0;
            $fee = self::placeFee($place);

            // Tier dominates. Inside the tier, prefer another category, then nearer and cheaper places.
            $score = ($tier * 100000.0) + ($categoryPenalty * 100.0) + ($distance * 10.0) + min(999.0, $fee);
            $place['_fallback_tier'] = $tier;
            $place['_fallback_distance'] = $distance;
            $place['_fallback_score'] = $score;
            $ranked[] = $place;
        }

        usort($ranked, static fn(array $a, array $b): int => ((float)$a['_fallback_score']) <=> ((float)$b['_fallback_score']));
        return $ranked;
    }

    private static function insertDay(
        mysqli $conn,
        int $itineraryId,
        int $dayNo,
        array $selected,
        ?array $anchor,
        string $transport,
        array $usedIds
    ): array {
        $stmt = $conn->prepare("
            INSERT INTO itinerary_items
              (itinerary_id, day_no, sequence_no, item_type, place_id, item_title,
               start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmt) return [];

        $inserted = [];
        $sequence = 1;
        $cursor = 9 * 60;
        $currentAnchor = $anchor;

        foreach ($selected as $place) {
            $placeId = (int)($place['place_id'] ?? 0);
            if ($placeId <= 0 || isset($usedIds[$placeId]) || in_array($placeId, $inserted, true)) continue;

            $distance = self::distanceFromAnchor($place, $currentAnchor);
            $travelMinutes = self::estimateTravelMinutes($distance, $transport);
            $cursor += $travelMinutes;

            $duration = self::visitDuration($place);
            $startTime = self::sqlTime($cursor);
            $endTime = self::sqlTime($cursor + $duration);
            $fee = self::placeFee($place);
            $category = strtolower((string)($place['category'] ?? 'attraction'));
            $itemType = $category === 'food' ? 'food' : ($category === 'festival' ? 'festival' : 'attraction');
            $name = (string)($place['name'] ?? 'Cultural Place');
            $tier = (int)($place['_fallback_tier'] ?? 3);
            $reason = match ($tier) {
                0 => 'Fallback fill: preferred district and interest match',
                1 => 'Fallback fill: preferred district; interest relaxed to prevent empty day',
                2 => 'Fallback fill: nearest available district in preferred state',
                default => 'Fallback fill: nearest available place to prevent empty day',
            };
            $district = trim((string)($place['district'] ?? ''));
            if ($district !== '') $reason .= '; District: ' . $district;
            $notes = substr($reason, 0, 255);

            $stmt->bind_param(
                'iiisisssddis',
                $itineraryId,
                $dayNo,
                $sequence,
                $itemType,
                $placeId,
                $name,
                $startTime,
                $endTime,
                $fee,
                $distance,
                $travelMinutes,
                $notes
            );

            if ($stmt->execute()) {
                $inserted[] = $placeId;
                $sequence++;
                $cursor += $duration + 30;
                $currentAnchor = [
                    'latitude' => (float)$place['latitude'],
                    'longitude' => (float)$place['longitude'],
                ];
            }
        }
        $stmt->close();
        return $inserted;
    }

    private static function loadPreviousAnchor(mysqli $conn, int $itineraryId, int $dayNo, array $context): ?array
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no < ?
            ORDER BY ii.day_no DESC, ii.sequence_no DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $itineraryId, $dayNo);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && self::validCoord($row['latitude'] ?? null, $row['longitude'] ?? null)) {
                return ['latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude']];
            }
        }

        if (self::validCoord($context['origin_lat'] ?? null, $context['origin_lng'] ?? null)) {
            return ['latitude' => (float)$context['origin_lat'], 'longitude' => (float)$context['origin_lng']];
        }
        return null;
    }

    private static function loadPreviousCategory(mysqli $conn, int $itineraryId, int $dayNo): string
    {
        $stmt = $conn->prepare("
            SELECT cp.category
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no < ?
            ORDER BY ii.day_no DESC, ii.sequence_no DESC
            LIMIT 1
        ");
        if (!$stmt) return '';
        $stmt->bind_param('ii', $itineraryId, $dayNo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return strtolower(trim((string)($row['category'] ?? '')));
    }

    private static function recalculateTotal(mysqli $conn, int $itineraryId, array $context): void
    {
        $stmt = $conn->prepare("SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?");
        if (!$stmt) return;
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        $distance = 0.0;
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
            $distance += (float)($row['distance_km'] ?? 0);
        }
        $stmt->close();

        $days = max(1, (int)($context['total_days'] ?? 1));
        $budget = (float)($context['budget'] ?? 0);
        $tier = (string)($context['budget_tier'] ?? 'normal');
        $travellerType = (string)($context['traveller_type'] ?? 'solo');
        $partySize = CostEstimationService::resolvePartySize($travellerType, isset($context['party_size']) ? (int)$context['party_size'] : null);
        $transport = self::normalizeTransport((string)($context['transport_type'] ?? 'car'));
        $defaults = CostEstimationService::budgetTierDefaults($tier, $budget, $days, $partySize);
        $service = new CostEstimationService($transport, $days, $budget, $travellerType, $partySize);
        $result = $service->calculate($items, $distance, (float)$defaults['hotel'], 3, (float)$defaults['meal']);
        $total = (float)($result['total_cost'] ?? 0);

        $update = $conn->prepare('UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?');
        if (!$update) return;
        $update->bind_param('di', $total, $itineraryId);
        $update->execute();
        $update->close();
    }

    private static function csvList(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), static fn(string $v): bool => $v !== '')));
    }

    private static function dailyQuota(string $pace): int
    {
        return match (strtolower(trim($pace))) {
            'relaxed' => 3,
            'packed' => 5,
            default => 4,
        };
    }

    private static function normalizeTransport(string $transport): string
    {
        $value = strtolower(trim(str_replace(['-', ' '], '_', $transport)));
        if (in_array($value, ['public', 'publictransit', 'public_transit', 'transit', 'bus', 'train'], true)) return 'public_transport';
        if (in_array($value, ['drive', 'driving'], true)) return 'car';
        if ($value === 'walk') return 'walking';
        return in_array($value, ['car', 'motorcycle', 'public_transport', 'walking'], true) ? $value : 'car';
    }

    private static function visitDuration(array $place): int
    {
        $configured = (int)($place['visit_duration_min'] ?? 0);
        if ($configured > 0) return max(30, min(360, $configured));
        return match (strtolower((string)($place['category'] ?? ''))) {
            'food' => 60,
            'festival' => 150,
            'museum', 'heritage', 'culture', 'nature' => 120,
            'shopping' => 90,
            default => 90,
        };
    }

    private static function placeFee(array $place): float
    {
        if (array_key_exists('entrance_fee', $place) && $place['entrance_fee'] !== null) {
            return max(0.0, (float)$place['entrance_fee']);
        }
        return max(0.0, (float)($place['estimated_cost'] ?? 0));
    }

    private static function estimateTravelMinutes(float $distanceKm, string $transport): int
    {
        $speed = match ($transport) {
            'walking' => 5.0,
            'public_transport' => 30.0,
            'motorcycle' => 50.0,
            default => 55.0,
        };
        return $distanceKm <= 0 ? 0 : max(5, (int)ceil(($distanceKm / $speed) * 60));
    }

    private static function distanceFromAnchor(array $place, ?array $anchor): float
    {
        if ($anchor === null || !self::validCoord($anchor['latitude'] ?? null, $anchor['longitude'] ?? null)) return 0.0;
        if (!self::validCoord($place['latitude'] ?? null, $place['longitude'] ?? null)) return 99999.0;
        return self::haversine(
            (float)$anchor['latitude'],
            (float)$anchor['longitude'],
            (float)$place['latitude'],
            (float)$place['longitude']
        );
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private static function validCoord(mixed $lat, mixed $lng): bool
    {
        if ($lat === null || $lng === null) return false;
        $lat = (float)$lat;
        $lng = (float)$lng;
        return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0);
    }

    private static function sqlTime(int $minutes): string
    {
        $minutes = max(0, min(23 * 60 + 59, $minutes));
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    private static function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res && $res->num_rows > 0;
    }
}
