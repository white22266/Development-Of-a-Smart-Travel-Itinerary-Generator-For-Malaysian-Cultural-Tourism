<?php
// services/ItineraryTargetRefillService.php
// Repairs generated itineraries so each day continues filling toward the travel-pace target.
// Hotel/origin rows are not counted as travel places.
require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/CostEstimationService.php';

final class ItineraryTargetRefillService
{
    public static function refill(mysqli $conn, int $travellerId, int $itineraryId, ?string $googleMapsApiKey = null): array
    {
        $ctx = self::loadContext($conn, $travellerId, $itineraryId);
        if (!$ctx) return ['added' => 0, 'days' => []];

        $target = self::targetPlaces((string)($ctx['travel_pace'] ?? 'normal'));
        $restMin = self::restMinutes((string)($ctx['travel_pace'] ?? 'normal'), (string)($ctx['traveller_type'] ?? 'solo'), (string)($ctx['accessibility_needs'] ?? ''));
        $startMin = self::startMinute((string)($ctx['preferred_visit_time'] ?? 'any'));
        $hardEndMin = self::hardEndMinute((string)($ctx['preferred_visit_time'] ?? 'any'), (string)($ctx['traveller_type'] ?? 'solo'), (string)($ctx['accessibility_needs'] ?? ''));
        $partySize = CostEstimationService::resolvePartySize((string)($ctx['traveller_type'] ?? 'solo'), isset($ctx['party_size']) ? (int)$ctx['party_size'] : null);
        $remainingBudget = max(0.0, (float)($ctx['budget'] ?? 0) - (float)($ctx['total_estimated_cost'] ?? 0));
        $route = new RouteService((string)($ctx['transport_type'] ?? 'car'), $googleMapsApiKey);
        $used = self::usedPlaceIds($conn, $itineraryId);
        $dayResults = [];
        $totalAdded = 0;

        for ($dayNo = 1; $dayNo <= (int)$ctx['total_days']; $dayNo++) {
            $items = self::dayItems($conn, $itineraryId, $dayNo);
            $currentCount = self::countTravelItems($items);
            $addedForDay = 0;
            if ($currentCount >= $target) {
                $dayResults[$dayNo] = ['before' => $currentCount, 'after' => $currentCount, 'added' => 0];
                continue;
            }

            $dayDate = self::dayDate((string)$ctx['start_date'], $dayNo);
            $anchor = self::anchorForDay($ctx, $items, $dayNo, $conn, $itineraryId);
            $currentMin = self::currentMinuteForDay($items, $startMin, $restMin);
            $sequence = self::nextSequence($items);
            $categoryCounts = self::categoryCounts($items);
            $dailyDistance = self::dayDistance($items);
            $rejected = [];

            while ($currentCount < $target) {
                $candidate = self::selectCandidate($conn, $ctx, $used, $rejected, $anchor, $dayDate, $currentMin, $hardEndMin, $partySize, $remainingBudget, $categoryCounts);
                if (!$candidate) break;

                $seg = $route->getSegment((float)$anchor['lat'], (float)$anchor['lng'], (float)$candidate['latitude'], (float)$candidate['longitude']);
                $distanceKm = (float)($seg['distance_km'] ?? 0.0);
                $travelMin = (int)($seg['travel_time_min'] ?? 0);
                $arrivalMin = $currentMin + $travelMin;
                $visitMin = self::visitDuration($candidate, $ctx);
                $endMin = $arrivalMin + $visitMin;

                if (!self::finalFeasible($candidate, $ctx, $dayDate, $arrivalMin, $endMin, $hardEndMin)) {
                    $rejected[(int)$candidate['place_id']] = true;
                    continue;
                }

                $costPerPerson = self::placeCostPerPerson($candidate);
                $wholePartyCost = $costPerPerson * $partySize;
                if ($wholePartyCost > $remainingBudget && $costPerPerson > 0) {
                    $rejected[(int)$candidate['place_id']] = true;
                    continue;
                }

                self::insertItem($conn, $itineraryId, $dayNo, $sequence, $candidate, $arrivalMin, $endMin, $costPerPerson, $distanceKm, $travelMin, $ctx);
                $placeId = (int)$candidate['place_id'];
                $used[$placeId] = true;
                $remainingBudget = max(0.0, $remainingBudget - $wholePartyCost);
                $anchor = ['lat' => (float)$candidate['latitude'], 'lng' => (float)$candidate['longitude'], 'name' => (string)$candidate['name']];
                $currentMin = $endMin + $restMin;
                $sequence++;
                $currentCount++;
                $addedForDay++;
                $totalAdded++;
                $dailyDistance += $distanceKm;
                $cat = strtolower((string)$candidate['category']);
                $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
            }

            $dayResults[$dayNo] = ['before' => self::countTravelItems($items), 'after' => $currentCount, 'added' => $addedForDay];
        }

        if ($totalAdded > 0) {
            self::recalculateTotal($conn, $itineraryId, $ctx, $partySize);
        }

        return ['added' => $totalAdded, 'target' => $target, 'days' => $dayResults];
    }

    private static function loadContext(mysqli $conn, int $travellerId, int $itineraryId): ?array
    {
        $partySelect = self::columnExists($conn, 'traveller_preferences', 'party_size') ? ', tp.party_size' : '';
        $stmt = $conn->prepare("SELECT i.itinerary_id, i.preference_id, i.traveller_id, i.start_date, i.total_days, i.origin_name, i.origin_lat, i.origin_lng, i.total_estimated_cost,
                   tp.budget, tp.budget_tier, tp.transport_type, tp.traveller_type{$partySelect}, tp.travel_pace,
                   tp.dietary_preference, tp.preferred_visit_time, tp.accessibility_needs, tp.interests,
                   tp.preferred_states, tp.preferred_districts
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

    private static function targetPlaces(string $pace): int
    {
        return match (strtolower($pace)) { 'relaxed' => 3, 'packed' => 5, default => 4 };
    }

    private static function restMinutes(string $pace, string $travellerType, string $access): int
    {
        $rest = match (strtolower($pace)) { 'relaxed' => 90, 'packed' => 30, default => 60 };
        $access = strtolower($access);
        if ($travellerType === 'family' || str_contains($access, 'elderly') || str_contains($access, 'low_walking')) $rest += 15;
        if ($travellerType === 'group') $rest += 10;
        return $rest;
    }

    private static function startMinute(string $preferredVisitTime): int
    {
        return match (strtolower($preferredVisitTime)) { 'morning' => 480, 'afternoon' => 720, 'evening' => 900, default => 510 };
    }

    private static function hardEndMinute(string $preferredVisitTime, string $travellerType, string $access): int
    {
        $end = match (strtolower($preferredVisitTime)) { 'morning' => 1140, 'afternoon' => 1290, 'evening' => 1350, default => 1260 };
        $access = strtolower($access);
        if ($travellerType === 'family' || str_contains($access, 'elderly') || str_contains($access, 'low_walking')) $end = min($end, 1200);
        return $end;
    }

    private static function usedPlaceIds(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare('SELECT place_id FROM itinerary_items WHERE itinerary_id = ? AND place_id IS NOT NULL');
        if (!$stmt) return [];
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $used = [];
        while ($row = $res->fetch_assoc()) $used[(int)$row['place_id']] = true;
        $stmt->close();
        return $used;
    }

    private static function dayItems(mysqli $conn, int $itineraryId, int $dayNo): array
    {
        $stmt = $conn->prepare("SELECT ii.*, cp.category, cp.latitude AS place_latitude, cp.longitude AS place_longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no = ?
            ORDER BY ii.sequence_no, ii.item_id");
        if (!$stmt) return [];
        $stmt->bind_param('ii', $itineraryId, $dayNo);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private static function countTravelItems(array $items): int
    {
        $count = 0;
        foreach ($items as $item) if (strtolower((string)($item['item_type'] ?? '')) !== 'hotel' && strtolower((string)($item['item_type'] ?? '')) !== 'origin') $count++;
        return $count;
    }

    private static function anchorForDay(array $ctx, array $items, int $dayNo, mysqli $conn, int $itineraryId): array
    {
        $last = null;
        foreach ($items as $item) {
            $lat = $item['item_latitude'] ?? $item['place_latitude'] ?? null;
            $lng = $item['item_longitude'] ?? $item['place_longitude'] ?? null;
            if (self::validCoord($lat, $lng)) $last = ['lat' => (float)$lat, 'lng' => (float)$lng, 'name' => (string)($item['item_title'] ?? 'Previous stop')];
        }
        if ($last) return $last;
        if ($dayNo === 1 && self::validCoord($ctx['origin_lat'] ?? null, $ctx['origin_lng'] ?? null)) {
            return ['lat' => (float)$ctx['origin_lat'], 'lng' => (float)$ctx['origin_lng'], 'name' => (string)($ctx['origin_name'] ?? 'Starting Location')];
        }
        $prev = self::previousDayLastStop($conn, $itineraryId, $dayNo);
        if ($prev) return $prev;
        if (self::validCoord($ctx['origin_lat'] ?? null, $ctx['origin_lng'] ?? null)) {
            return ['lat' => (float)$ctx['origin_lat'], 'lng' => (float)$ctx['origin_lng'], 'name' => (string)($ctx['origin_name'] ?? 'Starting Location')];
        }
        return ['lat' => 3.139, 'lng' => 101.6869, 'name' => 'Malaysia'];
    }

    private static function previousDayLastStop(mysqli $conn, int $itineraryId, int $dayNo): ?array
    {
        $prevDay = max(1, $dayNo - 1);
        $stmt = $conn->prepare("SELECT COALESCE(ii.item_latitude, cp.latitude) AS lat, COALESCE(ii.item_longitude, cp.longitude) AS lng, ii.item_title
            FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no = ?
            ORDER BY ii.sequence_no DESC, ii.item_id DESC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ii', $itineraryId, $prevDay);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !self::validCoord($row['lat'] ?? null, $row['lng'] ?? null)) return null;
        return ['lat' => (float)$row['lat'], 'lng' => (float)$row['lng'], 'name' => (string)($row['item_title'] ?? 'Previous day last stop')];
    }

    private static function currentMinuteForDay(array $items, int $defaultStart, int $restMin): int
    {
        $latest = null;
        foreach ($items as $item) {
            $end = self::timeToMinutes($item['end_time'] ?? null);
            if ($end !== null) $latest = max($latest ?? 0, $end);
        }
        return $latest !== null ? $latest + $restMin : $defaultStart;
    }

    private static function nextSequence(array $items): int
    {
        $max = 0;
        foreach ($items as $item) $max = max($max, (int)($item['sequence_no'] ?? 0));
        return $max + 1;
    }

    private static function categoryCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $cat = strtolower((string)($item['category'] ?? $item['item_type'] ?? ''));
            if ($cat === '' || $cat === 'origin' || $cat === 'hotel') continue;
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        return $counts;
    }

    private static function dayDistance(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) $total += (float)($item['distance_km'] ?? 0);
        return $total;
    }

    private static function selectCandidate(mysqli $conn, array $ctx, array $used, array $rejected, array $anchor, string $dayDate, int $currentMin, int $hardEndMin, int $partySize, float $remainingBudget, array $categoryCounts): ?array
    {
        $states = self::csvList((string)($ctx['preferred_states'] ?? ''));
        $districts = array_map('strtolower', self::csvList((string)($ctx['preferred_districts'] ?? '')));
        $interests = array_map('strtolower', self::csvList((string)($ctx['interests'] ?? '')));
        $where = ["is_active = 1", "latitude IS NOT NULL", "longitude IS NOT NULL"];
        $params = [];
        $types = '';
        if (!empty($states)) {
            $where[] = "state IN (" . implode(',', array_fill(0, count($states), '?')) . ")";
            $types .= str_repeat('s', count($states));
            foreach ($states as $s) $params[] = $s;
        }
        $sql = "SELECT place_id, state, district, category, name, description, address, latitude, longitude, opening_hours,
                       festival_start_date, festival_end_date, estimated_cost, entrance_fee, halal_status, is_outdoor,
                       visit_duration_min, best_time_to_visit, avg_rating, rating
                FROM cultural_places WHERE " . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') self::bindDynamic($stmt, $types, $params);
        $stmt->execute();
        $res = $stmt->get_result();
        $best = null;
        $bestScore = -PHP_FLOAT_MAX;
        while ($place = $res->fetch_assoc()) {
            $placeId = (int)$place['place_id'];
            if (isset($used[$placeId]) || isset($rejected[$placeId])) continue;
            if (!self::validCoord($place['latitude'] ?? null, $place['longitude'] ?? null)) continue;
            if (!self::festivalDateAllowed($place, $dayDate)) continue;
            if (!self::dietaryAllowed($place, (string)($ctx['dietary_preference'] ?? 'none'))) continue;
            $distance = self::haversineKm((float)$anchor['lat'], (float)$anchor['lng'], (float)$place['latitude'], (float)$place['longitude']);
            $arrival = $currentMin + self::estimateTravelTime((string)($ctx['transport_type'] ?? 'car'), $distance * 1.3);
            $end = $arrival + self::visitDuration($place, $ctx);
            if ($end > $hardEndMin) continue;
            if (!self::openingHoursAllow((string)($place['opening_hours'] ?? ''), $arrival)) continue;
            if (!self::accessibilityAllowed($place, (string)($ctx['accessibility_needs'] ?? ''), $arrival)) continue;

            $category = strtolower((string)$place['category']);
            $district = strtolower(trim((string)$place['district']));
            $interestMatch = in_array($category, $interests, true);
            $districtMatch = !empty($districts) && in_array($district, $districts, true);
            $tier = $districtMatch && $interestMatch ? 1 : ($districtMatch ? 2 : ($interestMatch ? 3 : 4));
            $cost = self::placeCostPerPerson($place) * $partySize;
            $score = 1000 - ($tier * 100) - min(80, $distance);
            if ($interestMatch) $score += 25;
            if ($cost <= 0) $score += 30;
            elseif ($cost <= $remainingBudget) $score += 10;
            else $score -= 1000;
            if (($categoryCounts[$category] ?? 0) === 0) $score += 10;
            elseif (($categoryCounts[$category] ?? 0) >= 2) $score -= 20;
            $rating = max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0));
            $score += min(8, ($rating / 5) * 8);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $place;
            }
        }
        $stmt->close();
        return $best;
    }

    private static function finalFeasible(array $place, array $ctx, string $dayDate, int $arrivalMin, int $endMin, int $hardEndMin): bool
    {
        return $endMin <= $hardEndMin
            && self::festivalDateAllowed($place, $dayDate)
            && self::dietaryAllowed($place, (string)($ctx['dietary_preference'] ?? 'none'))
            && self::openingHoursAllow((string)($place['opening_hours'] ?? ''), $arrivalMin)
            && self::accessibilityAllowed($place, (string)($ctx['accessibility_needs'] ?? ''), $arrivalMin);
    }

    private static function insertItem(mysqli $conn, int $itineraryId, int $dayNo, int $sequence, array $place, int $startMin, int $endMin, float $cost, float $distanceKm, int $travelMin, array $ctx): void
    {
        $category = strtolower((string)$place['category']);
        $itemType = $category === 'food' ? 'food' : ($category === 'festival' ? 'festival' : 'attraction');
        $title = (string)$place['name'];
        $lat = (float)$place['latitude'];
        $lng = (float)$place['longitude'];
        $start = self::minutesToSqlTime($startMin);
        $end = self::minutesToSqlTime($endMin);
        $notes = self::notes($place, $ctx);
        $placeId = (int)$place['place_id'];
        $distanceKm = round($distanceKm, 2);
        $stmt = $conn->prepare("INSERT INTO itinerary_items
            (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude, start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) throw new RuntimeException('Unable to save refill itinerary item.');
        $stmt->bind_param('iiisisddssddis', $itineraryId, $dayNo, $sequence, $itemType, $placeId, $title, $lat, $lng, $start, $end, $cost, $distanceKm, $travelMin, $notes);
        $stmt->execute();
        $stmt->close();
    }

    private static function notes(array $place, array $ctx): string
    {
        $districts = array_map('strtolower', self::csvList((string)($ctx['preferred_districts'] ?? '')));
        $interests = array_map('strtolower', self::csvList((string)($ctx['interests'] ?? '')));
        $districtMatch = in_array(strtolower((string)$place['district']), $districts, true);
        $interestMatch = in_array(strtolower((string)$place['category']), $interests, true);
        if ($districtMatch && $interestMatch) $reason = 'refill to meet travel pace target; selected district and interest match';
        elseif ($districtMatch) $reason = 'refill to meet travel pace target; selected district fallback, interest relaxed';
        elseif ($interestMatch) $reason = 'refill to meet travel pace target; nearby same-state district with interest match';
        else $reason = 'refill to meet travel pace target; same-state fallback, interest relaxed';
        return substr('State: ' . $place['state'] . ' | District: ' . ($place['district'] ?: '-') . ' | Category: ' . $place['category'] . ' | Reason: ' . $reason, 0, 255);
    }

    private static function recalculateTotal(mysqli $conn, int $itineraryId, array $ctx, int $partySize): void
    {
        $stmt = $conn->prepare('SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no');
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
        $defaults = CostEstimationService::budgetTierDefaults((string)($ctx['budget_tier'] ?? 'normal'), (float)($ctx['budget'] ?? 0), (int)$ctx['total_days'], $partySize);
        $service = new CostEstimationService((string)($ctx['transport_type'] ?? 'car'), (int)$ctx['total_days'], (float)($ctx['budget'] ?? 0), (string)($ctx['traveller_type'] ?? 'solo'), $partySize);
        $cost = $service->calculate($items, $distance, (float)$defaults['hotel'], 3, (float)$defaults['meal']);
        $total = (float)$cost['total_cost'];
        $update = $conn->prepare('UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?');
        if ($update) {
            $update->bind_param('di', $total, $itineraryId);
            $update->execute();
            $update->close();
        }
    }

    private static function dayDate(string $startDate, int $dayNo): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        return $dt ? $dt->modify('+' . max(0, $dayNo - 1) . ' days')->format('Y-m-d') : date('Y-m-d');
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

    private static function placeCostPerPerson(array $place): float
    {
        $fee = (float)($place['entrance_fee'] ?? 0);
        $estimate = (float)($place['estimated_cost'] ?? 0);
        return max(0.0, $fee > 0 ? $fee : $estimate);
    }

    private static function festivalDateAllowed(array $place, string $dayDate): bool
    {
        if (strtolower((string)$place['category']) !== 'festival') return true;
        $start = trim((string)($place['festival_start_date'] ?? ''));
        $end = trim((string)($place['festival_end_date'] ?? ''));
        if ($start === '' || $start === '0000-00-00' || $end === '' || $end === '0000-00-00' || $end < $start) return false;
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
        $duration = (int)($place['visit_duration_min'] ?? 90);
        $duration = max(30, min(240, $duration > 0 ? $duration : 90));
        if ((string)($ctx['traveller_type'] ?? '') === 'family') $duration = min($duration, 120);
        $access = strtolower((string)($ctx['accessibility_needs'] ?? ''));
        if (str_contains($access, 'elderly') || str_contains($access, 'low_walking')) $duration = min($duration, 105);
        return $duration;
    }

    private static function timeToMinutes($time): ?int
    {
        $time = trim((string)$time);
        if ($time === '') return null;
        $parts = explode(':', $time);
        if (count($parts) < 2) return null;
        return ((int)$parts[0] * 60) + (int)$parts[1];
    }

    private static function minutesToSqlTime(int $minutes): string
    {
        $minutes = max(0, min(1439, $minutes));
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    private static function clockToMinutes(int $hour, int $minute, string $ampm): ?int
    {
        if ($hour < 0 || $hour > 24 || $minute < 0 || $minute > 59) return null;
        if ($ampm === 'pm' && $hour < 12) $hour += 12;
        if ($ampm === 'am' && $hour === 12) $hour = 0;
        return ($hour % 24) * 60 + $minute;
    }

    private static function estimateTravelTime(string $transportType, float $distanceKm): int
    {
        $speed = match (CostEstimationService::normalizeTransportType($transportType)) { 'walking' => 5, 'public_transport' => 30, 'motorcycle' => 55, default => 60 };
        return max(1, (int)ceil(($distanceKm / max(1, $speed)) * 60));
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }

    private static function bindDynamic(mysqli_stmt $stmt, string $types, array $params): void
    {
        $params = array_values($params);
        $refs = [];
        foreach ($params as $i => $value) $refs[$i] = &$params[$i];
        $stmt->bind_param($types, ...$refs);
    }
}
