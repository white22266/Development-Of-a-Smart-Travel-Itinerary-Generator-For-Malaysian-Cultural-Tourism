<?php
// services/ItineraryEditService.php
// Changes to a saved itinerary that several pages make: putting another place into a stop,
// and bringing item numbers, leg distances and the total cost up to date afterwards. Every
// endpoint that edits an itinerary uses this one implementation.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/CostEstimationService.php';

class ItineraryEditService
{
    /**
     * Put another place into an existing stop. The stop keeps its day, position and times.
     * Check the change with ItineraryRuleValidationService first, and call refresh() after.
     */
    public static function replaceStop(mysqli $conn, int $itineraryId, int $itemId, int $placeId): bool
    {
        $stmt = $conn->prepare("
            SELECT name, state, district, category, latitude, longitude, entrance_fee
            FROM cultural_places
            WHERE place_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('i', $placeId);
        $stmt->execute();
        $place = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$place) return false;

        $category = strtolower(trim((string)$place['category']));
        $type = in_array($category, ['food', 'festival'], true) ? $category : 'attraction';
        $cost = max(0.0, (float)$place['entrance_fee']);
        $lat = $place['latitude'] !== null ? (float)$place['latitude'] : null;
        $lng = $place['longitude'] !== null ? (float)$place['longitude'] : null;
        $notes = 'State: ' . $place['state'] . (!empty($place['district']) ? ' | District: ' . $place['district'] : '') . ' | Category: ' . $place['category'];

        $update = $conn->prepare("
            UPDATE itinerary_items
            SET place_id = ?, item_type = ?, item_title = ?, item_latitude = ?, item_longitude = ?, estimated_cost = ?, notes = ?
            WHERE item_id = ? AND itinerary_id = ? AND item_type NOT IN ('hotel', 'transport', 'note')
        ");
        $update->bind_param('issdddsii', $placeId, $type, $place['name'], $lat, $lng, $cost, $notes, $itemId, $itineraryId);
        $update->execute();
        $update->close();
        return true;
    }
    /** Renumber, re-measure and re-cost an itinerary. Call it inside the caller's transaction. */
    public static function refresh(mysqli $conn, int $itineraryId): void
    {
        self::resequence($conn, $itineraryId);
        self::routes($conn, $itineraryId);
        self::total($conn, $itineraryId);
    }

    /** Number the items of each day 1, 2, 3... in their current order. */
    public static function resequence(mysqli $conn, int $itineraryId): void
    {
        $stmt = $conn->prepare("SELECT item_id, day_no FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no, item_id");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $update = $conn->prepare("UPDATE itinerary_items SET sequence_no = ? WHERE item_id = ?");
        $position = [];
        foreach ($rows as $row) {
            $dayNo = (int)$row['day_no'];
            $sequenceNo = $position[$dayNo] = ($position[$dayNo] ?? 0) + 1;
            $itemId = (int)$row['item_id'];
            $update->bind_param('ii', $sequenceNo, $itemId);
            $update->execute();
        }
        $update->close();
    }

    /**
     * Distance and travel time of every leg: from the trip's starting point to the first stop,
     * then from each stop to the next. A new day starts where the last one ended (at the hotel,
     * if one was chosen). These are the same estimates the planner schedules with.
     */
    public static function routes(mysqli $conn, int $itineraryId): void
    {
        $metaStmt = $conn->prepare("
            SELECT tp.transport_type, i.origin_lat, i.origin_lng
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        $metaStmt->bind_param('i', $itineraryId);
        $metaStmt->execute();
        $meta = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();
        if (!$meta) return;

        $route = new RouteService((string)($meta['transport_type'] ?? 'car'));
        $itemsStmt = $conn->prepare("
            SELECT ii.item_id,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();

        $previous = null;
        if ($meta['origin_lat'] !== null && $meta['origin_lng'] !== null && RouteService::validCoord((float)$meta['origin_lat'], (float)$meta['origin_lng'])) {
            $previous = [(float)$meta['origin_lat'], (float)$meta['origin_lng']];
        }

        $update = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?");
        foreach ($items as $item) {
            $here = null;
            if ($item['latitude'] !== null && $item['longitude'] !== null && RouteService::validCoord((float)$item['latitude'], (float)$item['longitude'])) {
                $here = [(float)$item['latitude'], (float)$item['longitude']];
            }
            $distanceKm = null;
            $travelMin = null;
            if ($previous !== null && $here !== null) {
                $segment = $route->getSegment($previous[0], $previous[1], $here[0], $here[1]);
                $distanceKm = (float)$segment['distance_km'];
                $travelMin = (int)$segment['travel_time_min'];
            }
            $itemId = (int)$item['item_id'];
            $update->bind_param('dii', $distanceKm, $travelMin, $itemId);
            $update->execute();
            if ($here !== null) $previous = $here;
        }
        $update->close();
    }

    /** Total estimated cost of the trip for the whole party. */
    public static function total(mysqli $conn, int $itineraryId): void
    {
        $stmt = $conn->prepare("
            SELECT i.total_days, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type, tp.party_size
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return;

        $itemsStmt = $conn->prepare("SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
        $totalDistanceKm = array_sum(array_map(static fn($item) => (float)($item['distance_km'] ?? 0), $items));

        $transportType = (string)($meta['transport_type'] ?? 'car');
        $totalDays = (int)($meta['total_days'] ?? 1);
        $budget = (float)($meta['budget'] ?? 0);
        $travellerType = (string)($meta['traveller_type'] ?? 'solo');
        $partySize = CostEstimationService::resolvePartySize($travellerType, isset($meta['party_size']) ? (int)$meta['party_size'] : null);
        $tierDefaults = CostEstimationService::budgetTierDefaults(strtolower((string)($meta['budget_tier'] ?? 'normal')), $budget, $totalDays, $partySize);

        $costService = new CostEstimationService($transportType, $totalDays, $budget, $travellerType, $partySize);
        $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults['hotel'], 3, $tierDefaults['meal']);
        $total = (float)($breakdown['total_cost'] ?? 0);

        $update = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
        $update->bind_param('di', $total, $itineraryId);
        $update->execute();
        $update->close();
    }
}
