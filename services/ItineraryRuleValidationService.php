<?php
// services/ItineraryRuleValidationService.php
// Shared validation used before AI/review changes are saved into official itineraries.
require_once __DIR__ . '/CostEstimationService.php';
require_once __DIR__ . '/RouteService.php';

final class ItineraryRuleValidationService
{
    public static function validatePlaceChange(mysqli $conn, int $travellerId, int $itineraryId, int $placeId, int $dayNo, int $excludeItemId = 0): array
    {
        $ctx = self::loadContext($conn, $travellerId, $itineraryId);
        if (!$ctx) return ['ok' => false, 'message' => 'Itinerary context could not be loaded.'];
        if ($dayNo < 1 || $dayNo > (int)$ctx['total_days']) return ['ok' => false, 'message' => 'Invalid itinerary day.'];

        $place = self::loadPlace($conn, $placeId);
        if (!$place) return ['ok' => false, 'message' => 'Selected place was not found.'];
        if (self::isDuplicate($conn, $itineraryId, $placeId, $excludeItemId)) return ['ok' => false, 'message' => 'This place already exists in the itinerary. Choose another place.'];
        if (!self::validCoord($place['latitude'] ?? null, $place['longitude'] ?? null)) return ['ok' => false, 'message' => 'Selected place has invalid coordinates.'];

        $states = self::csvList((string)($ctx['preferred_states'] ?? ''));
        if (!empty($states) && !in_array((string)$place['state'], $states, true)) {
            return ['ok' => false, 'message' => 'This place is outside the selected state boundary.'];
        }

        $dayDate = self::dayDate((string)$ctx['start_date'], $dayNo);
        if (!self::festivalDateAllowed($place, $dayDate)) {
            return ['ok' => false, 'message' => 'Festival date is not verified for this travel date. Please choose a festival with confirmed start and end dates.'];
        }

        if (!self::dietaryAllowed($place, (string)($ctx['dietary_preference'] ?? 'none'))) {
            return ['ok' => false, 'message' => 'This food place does not match the saved dietary preference.'];
        }

        $profile = self::profile($ctx);
        $arrival = self::estimateArrival($conn, $itineraryId, $dayNo, $place, $ctx, $profile, $excludeItemId);
        $visit = self::visitDuration($place, $ctx);
        $end = $arrival['arrival_min'] + $visit;

        if ($end > $profile['hard_end_min']) return ['ok' => false, 'message' => 'This change would exceed the daily hard end time.'];
        if (!self::openingHoursAllow((string)($place['opening_hours'] ?? ''), $arrival['arrival_min'])) return ['ok' => false, 'message' => 'This place appears closed at the estimated arrival time.'];
        if (!self::accessibilityAllowed($place, (string)($ctx['accessibility_needs'] ?? ''), $arrival['arrival_min'])) return ['ok' => false, 'message' => 'This place conflicts with the saved accessibility needs.'];
        if (($arrival['day_distance_km'] + $arrival['distance_km']) > $profile['daily_distance_limit_km']) return ['ok' => false, 'message' => 'This change would make the route too far for the selected travel pace and transport mode.'];

        if ($excludeItemId <= 0) {
            $currentCount = self::dayNonHotelCount($conn, $itineraryId, $dayNo);
            if ($currentCount >= $profile['target_places']) {
                return ['ok' => false, 'message' => 'This day has already reached the target number of places for the selected travel pace.'];
            }
        }

        return ['ok' => true, 'message' => 'Place change passes itinerary rules.'];
    }

    public static function validateCurrentRequestIfNeeded(mysqli $conn): void
    {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $travellerId = (int)($_SESSION['traveller_id'] ?? 0);
        if ($travellerId <= 0) return;

        if ($script === 'ai_itinerary_editor.php' && in_array($action, ['confirm', 'confirm_add'], true)) {
            $itineraryId = (int)($_POST['itinerary_id'] ?? 0);
            $placeId = (int)($_POST['place_id'] ?? 0);
            $excludeItemId = $action === 'confirm' ? (int)($_POST['item_id'] ?? 0) : 0;
            $dayNo = $action === 'confirm_add' ? (int)($_POST['day_no'] ?? 0) : self::itemDay($conn, $itineraryId, $excludeItemId);
            self::guard($conn, $travellerId, $itineraryId, $placeId, $dayNo, $excludeItemId);
        }

        if ($script === 'review_replace.php' && $action === 'confirm') {
            $itineraryId = (int)($_POST['itinerary_id'] ?? 0);
            $hotelPlaceId = (int)($_POST['hotel_place_id'] ?? 0);
            if ($hotelPlaceId > 0) {
                $dayNo = self::itineraryTotalDays($conn, $travellerId, $itineraryId);
                self::guard($conn, $travellerId, $itineraryId, $hotelPlaceId, $dayNo, 0);
            }

            $json = (string)($_POST['replacements_json'] ?? '');
            $replacements = json_decode($json, true);
            if (is_array($replacements)) {
                foreach ($replacements as $itemId => $placeId) {
                    $itemId = (int)$itemId;
                    $placeId = (int)$placeId;
                    if ($itemId <= 0 || $placeId <= 0) continue;
                    $dayNo = self::itemDay($conn, $itineraryId, $itemId);
                    self::guard($conn, $travellerId, $itineraryId, $placeId, $dayNo, $itemId);
                }
            }
        }
    }

    private static function guard(mysqli $conn, int $travellerId, int $itineraryId, int $placeId, int $dayNo, int $excludeItemId): void
    {
        if ($itineraryId <= 0 || $placeId <= 0 || $dayNo <= 0) return;
        $result = self::validatePlaceChange($conn, $travellerId, $itineraryId, $placeId, $dayNo, $excludeItemId);
        if (!$result['ok']) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'answer' => $result['message'], 'message' => $result['message']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    private static function loadContext(mysqli $conn, int $travellerId, int $itineraryId): ?array
    {
        $partySelect = self::columnExists($conn, 'traveller_preferences', 'party_size') ? ', tp.party_size' : '';
        $stmt = $conn->prepare("SELECT i.itinerary_id, i.total_days, i.start_date, i.origin_lat, i.origin_lng,
                   tp.transport_type, tp.traveller_type{$partySelect}, tp.travel_pace, tp.dietary_preference,
                   tp.preferred_visit_time, tp.accessibility_needs, tp.preferred_states
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ? AND i.traveller_id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ii', $itineraryId, $travellerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function loadPlace(mysqli $conn, int $placeId): ?array
    {
        $stmt = $conn->prepare("SELECT place_id, state, district, category, name, description, latitude, longitude,
                   opening_hours, festival_start_date, festival_end_date, estimated_cost, entrance_fee,
                   halal_status, is_outdoor, visit_duration_min
            FROM cultural_places WHERE place_id = ? AND is_active = 1 LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $placeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function profile(array $ctx): array
    {
        $pace = strtolower((string)($ctx['travel_pace'] ?? 'normal'));
        $target = match ($pace) { 'relaxed' => 3, 'packed' => 5, default => 4 };
        $window = match (strtolower((string)($ctx['preferred_visit_time'] ?? 'any'))) {
            'morning' => ['start' => 480, 'hard_end' => 1140],
            'afternoon' => ['start' => 720, 'hard_end' => 1290],
            'evening' => ['start' => 900, 'hard_end' => 1350],
            default => ['start' => 510, 'hard_end' => 1260],
        };
        $distance = match (CostEstimationService::normalizeTransportType((string)($ctx['transport_type'] ?? 'car'))) {
            'walking' => $pace === 'packed' ? 7.0 : ($pace === 'relaxed' ? 3.0 : 5.0),
            'public_transport' => $pace === 'packed' ? 35.0 : ($pace === 'relaxed' ? 15.0 : 25.0),
            default => $pace === 'packed' ? 60.0 : ($pace === 'relaxed' ? 30.0 : 45.0),
        };
        $access = strtolower((string)($ctx['accessibility_needs'] ?? ''));
        if (str_contains($access, 'wheelchair') || str_contains($access, 'elderly') || str_contains($access, 'low_walking')) $distance *= 0.75;
        return ['target_places' => $target, 'start_min' => $window['start'], 'hard_end_min' => $window['hard_end'], 'daily_distance_limit_km' => $distance];
    }

    private static function estimateArrival(mysqli $conn, int $itineraryId, int $dayNo, array $place, array $ctx, array $profile, int $excludeItemId): array
    {
        $dayDistance = 0.0;
        $anchorLat = ($dayNo === 1 && self::validCoord($ctx['origin_lat'] ?? null, $ctx['origin_lng'] ?? null)) ? (float)$ctx['origin_lat'] : null;
        $anchorLng = ($dayNo === 1 && self::validCoord($ctx['origin_lat'] ?? null, $ctx['origin_lng'] ?? null)) ? (float)$ctx['origin_lng'] : null;
        $currentMin = $profile['start_min'];

        $stmt = $conn->prepare("SELECT ii.item_id, ii.end_time, ii.distance_km, COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no = ? AND ii.item_id <> ?
            ORDER BY ii.sequence_no");
        if ($stmt) {
            $stmt->bind_param('iii', $itineraryId, $dayNo, $excludeItemId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (self::validCoord($row['latitude'] ?? null, $row['longitude'] ?? null)) {
                    $anchorLat = (float)$row['latitude'];
                    $anchorLng = (float)$row['longitude'];
                }
                $dayDistance += (float)($row['distance_km'] ?? 0);
                $end = self::sqlTimeToMinutes($row['end_time'] ?? null);
                if ($end !== null) $currentMin = max($currentMin, $end + 30);
            }
            $stmt->close();
        }

        if ($anchorLat === null || $anchorLng === null) {
            $anchorLat = (float)$place['latitude'];
            $anchorLng = (float)$place['longitude'];
        }
        $distance = self::haversineKm($anchorLat, $anchorLng, (float)$place['latitude'], (float)$place['longitude']) * 1.3;
        $arrival = $currentMin + self::estimateTravelTime((string)($ctx['transport_type'] ?? 'car'), $distance);
        return ['arrival_min' => $arrival, 'distance_km' => $distance, 'day_distance_km' => $dayDistance];
    }

    private static function festivalDateAllowed(array $place, string $dayDate): bool
    {
        if (strtolower((string)$place['category']) !== 'festival') return true;
        $start = trim((string)($place['festival_start_date'] ?? ''));
        $end = trim((string)($place['festival_end_date'] ?? ''));
        if ($start === '' || $start === '0000-00-00' || $end === '' || $end === '0000-00-00') return false;
        return $dayDate >= $start && $dayDate <= $end;
    }

    private static function dietaryAllowed(array $place, string $dietary): bool
    {
        if (strtolower((string)$place['category']) !== 'food') return true;
        if ($dietary === 'halal') return (int)($place['halal_status'] ?? 0) === 1;
        if ($dietary === 'vegetarian') {
            $text = strtolower((string)$place['name'] . ' ' . (string)$place['description']);
            return str_contains($text, 'vegetarian') || str_contains($text, 'vegan');
        }
        return true;
    }

    private static function accessibilityAllowed(array $place, string $access, int $arrivalMin): bool
    {
        $access = strtolower($access);
        if ($access === '') return true;
        $text = strtolower((string)$place['name'] . ' ' . (string)$place['description'] . ' ' . (string)$place['category']);
        $outdoor = (int)($place['is_outdoor'] ?? 0) === 1;
        if ((str_contains($access, 'wheelchair') || str_contains($access, 'avoid_stairs') || str_contains($access, 'avoid stairs')) && preg_match('/gunung|mountain|hill|cave|stairs|waterfall|trail|hiking/i', $text)) return false;
        if ((str_contains($access, 'avoid_heat') || str_contains($access, 'avoid heat')) && $outdoor && $arrivalMin >= 660 && $arrivalMin <= 960) return false;
        if (str_contains($access, 'elderly') && $outdoor && (int)($place['visit_duration_min'] ?? 90) > 150) return false;
        return true;
    }

    private static function openingHoursAllow(string $hours, int $arrivalMin): bool
    {
        $text = strtolower(trim($hours));
        if ($text === '' || str_contains($text, 'verify') || str_contains($text, 'varies') || str_contains($text, 'open area') || str_contains($text, 'always') || str_contains($text, '24')) return true;
        if (preg_match('/closed/i', $text) && !preg_match('/\d/', $text)) return false;
        if (!preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:-|to|–|—)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $text, $m)) return true;
        $open = self::clockToMinutes((int)$m[1], (int)($m[2] ?? 0), strtolower((string)($m[3] ?? '')));
        $close = self::clockToMinutes((int)$m[4], (int)($m[5] ?? 0), strtolower((string)($m[6] ?? '')));
        if ($open === null || $close === null) return true;
        if ($close <= $open) return $arrivalMin >= $open || $arrivalMin <= $close;
        return $arrivalMin >= $open && $arrivalMin <= $close;
    }

    private static function visitDuration(array $place, array $ctx): int
    {
        $duration = max(30, min(240, (int)($place['visit_duration_min'] ?? 90) ?: 90));
        if (($ctx['traveller_type'] ?? '') === 'family') $duration = min($duration, 120);
        $access = strtolower((string)($ctx['accessibility_needs'] ?? ''));
        if (str_contains($access, 'elderly') || str_contains($access, 'low_walking')) $duration = min($duration, 105);
        return $duration;
    }

    private static function isDuplicate(mysqli $conn, int $itineraryId, int $placeId, int $excludeItemId): bool
    {
        $stmt = $conn->prepare('SELECT item_id FROM itinerary_items WHERE itinerary_id = ? AND place_id = ? AND item_id <> ? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('iii', $itineraryId, $placeId, $excludeItemId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (bool)$found;
    }

    private static function dayNonHotelCount(mysqli $conn, int $itineraryId, int $dayNo): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM itinerary_items WHERE itinerary_id = ? AND day_no = ? AND item_type <> 'hotel'");
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $itineraryId, $dayNo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }

    private static function itemDay(mysqli $conn, int $itineraryId, int $itemId): int
    {
        if ($itemId <= 0) return 0;
        $stmt = $conn->prepare('SELECT day_no FROM itinerary_items WHERE itinerary_id = ? AND item_id = ? LIMIT 1');
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $itineraryId, $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['day_no'] ?? 0);
    }

    private static function itineraryTotalDays(mysqli $conn, int $travellerId, int $itineraryId): int
    {
        $stmt = $conn->prepare('SELECT total_days FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1');
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $itineraryId, $travellerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return max(1, (int)($row['total_days'] ?? 1));
    }

    private static function dayDate(string $startDate, int $dayNo): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$dt) return date('Y-m-d');
        return $dt->modify('+' . max(0, $dayNo - 1) . ' days')->format('Y-m-d');
    }

    private static function csvList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn($v) => $v !== ''));
    }

    private static function validCoord($lat, $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') return false;
        $lat = (float)$lat; $lng = (float)$lng;
        return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private static function estimateTravelTime(string $transportType, float $distanceKm): int
    {
        $speed = match (CostEstimationService::normalizeTransportType($transportType)) { 'walking' => 5, 'public_transport' => 30, 'motorcycle' => 55, default => 60 };
        return max(1, (int)ceil(($distanceKm / max(1, $speed)) * 60));
    }

    private static function sqlTimeToMinutes($time): ?int
    {
        $time = trim((string)$time);
        if ($time === '') return null;
        $ts = strtotime($time);
        if (!$ts) return null;
        return ((int)date('G', $ts) * 60) + (int)date('i', $ts);
    }

    private static function clockToMinutes(int $hour, int $minute, string $ampm): ?int
    {
        if ($hour < 0 || $hour > 24 || $minute < 0 || $minute > 59) return null;
        if ($ampm === 'pm' && $hour < 12) $hour += 12;
        if ($ampm === 'am' && $hour === 12) $hour = 0;
        return ($hour % 24) * 60 + $minute;
    }

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}
