<?php
// services/ItineraryHotelService.php
// Writes the hotels a traveller chose into an itinerary: one "hotel" item at the end of every
// day that is followed by a night. Used by the review page and by the travel assistant.

class ItineraryHotelService
{
    /**
     * Replace the hotel nights of an itinerary. Call it inside the caller's transaction, then
     * call ItineraryEditService::refresh() so numbering, routes and total follow.
     *
     * @param array<int,array> $hotelByNight night number (1 = the night after day 1) => a hotel
     *                                       as returned by HotelRecommendationService
     * @return array{nights:int,total:float} how many nights were saved and what they cost per room
     */
    public static function saveNights(mysqli $conn, int $itineraryId, int $totalDays, array $hotelByNight): array
    {
        $clear = $conn->prepare("DELETE FROM itinerary_items WHERE itinerary_id = ? AND item_type = 'hotel'");
        $clear->bind_param('i', $itineraryId);
        $clear->execute();
        $clear->close();

        $totalNights = max(0, $totalDays - 1);
        $nights = 0;
        $total = 0.0;
        $chosen = [];   // hotel_id => name
        for ($nightNo = 1; $nightNo <= $totalNights; $nightNo++) {
            $hotel = $hotelByNight[$nightNo] ?? null;
            if (!is_array($hotel) || trim((string)($hotel['name'] ?? '')) === '') continue;
            self::insertNight($conn, $itineraryId, $nightNo, $totalNights, $hotel);
            $nights++;
            $total += max(0.0, (float)($hotel['price_per_night'] ?? 0));
            $chosen[(int)($hotel['hotel_id'] ?? 0)] = trim((string)$hotel['name']);
        }

        // The summary on the itinerary itself: one hotel by name, or how many different ones.
        $summaryId = count($chosen) === 1 && array_key_first($chosen) > 0 ? array_key_first($chosen) : null;
        $summaryName = match (true) {
            count($chosen) === 0 => null,
            count($chosen) === 1 => mb_substr((string)reset($chosen), 0, 150),
            default => count($chosen) . ' different hotels',
        };
        $update = $conn->prepare("
            UPDATE itineraries
            SET selected_hotel_id = ?, selected_hotel_name = ?, selected_hotel_nights = ?, selected_hotel_total_cost = ?
            WHERE itinerary_id = ?
        ");
        $update->bind_param('isidi', $summaryId, $summaryName, $nights, $total, $itineraryId);
        $update->execute();
        $update->close();

        return ['nights' => $nights, 'total' => round($total, 2)];
    }

    /**
     * When the hotel row of a day starts, in minutes after midnight: when the traveller arrives,
     * the end of the last row before it plus the way there (ItineraryEditService times it again
     * with the day: the arrival at the base after the last stop and the dinner). At most 23:29,
     * so the half-hour row stays inside the day; 20:00 when the day has no timed row.
     */
    public static function checkInMinute(?int $lastEndMin, int $travelMin = 0): int
    {
        if ($lastEndMin === null) return 20 * 60;
        return max(0, min(23 * 60 + 29, $lastEndMin + max(0, $travelMin)));
    }

    /** The hotel goes last on the day before the night. */
    private static function insertNight(mysqli $conn, int $itineraryId, int $nightNo, int $totalNights, array $hotel): void
    {
        $dayNo = $nightNo;
        $last = $conn->prepare("
            SELECT MAX(sequence_no) AS last_sequence, MAX(CASE WHEN end_time IS NOT NULL THEN end_time END) AS last_end
            FROM itinerary_items
            WHERE itinerary_id = ? AND day_no = ?
        ");
        $last->bind_param('ii', $itineraryId, $dayNo);
        $last->execute();
        $row = $last->get_result()->fetch_assoc();
        $last->close();

        $sequenceNo = (int)($row['last_sequence'] ?? 0) + 1;
        $lastEndMin = null;
        if (!empty($row['last_end']) && preg_match('/^(\d{1,2}):(\d{2})/', (string)$row['last_end'], $m)) {
            $lastEndMin = (int)$m[1] * 60 + (int)$m[2];
        }
        $startMin = self::checkInMinute($lastEndMin);
        $startTime = sprintf('%02d:%02d:00', intdiv($startMin, 60), $startMin % 60);
        $endTime = sprintf('%02d:%02d:00', intdiv($startMin + 30, 60), ($startMin + 30) % 60);

        $hotelId = (int)($hotel['hotel_id'] ?? 0) ?: null;
        $name = mb_substr(trim((string)$hotel['name']), 0, 150);
        $rate = max(0.0, (float)($hotel['price_per_night'] ?? 0));
        $lat = isset($hotel['latitude']) ? (float)$hotel['latitude'] : null;
        $lng = isset($hotel['longitude']) ? (float)$hotel['longitude'] : null;
        $where = trim((string)($hotel['address'] ?? '')) ?: trim((string)($hotel['state'] ?? ''));
        $notes = mb_substr(implode(' | ', array_filter([
            'Hotel for night ' . $nightNo . ' of ' . $totalNights,
            (string)($hotel['kind_label'] ?? ''),
            $where,
            'Estimated RM ' . number_format($rate, 2) . ' per night',
        ])), 0, 255);

        $insert = $conn->prepare("
            INSERT INTO itinerary_items
                (itinerary_id, day_no, sequence_no, item_type, place_id, hotel_id, item_title, item_latitude, item_longitude,
                 start_time, end_time, estimated_cost, notes)
            VALUES (?, ?, ?, 'hotel', NULL, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->bind_param('iiiisddssds', $itineraryId, $dayNo, $sequenceNo, $hotelId, $name, $lat, $lng, $startTime, $endTime, $rate, $notes);
        $insert->execute();
        $insert->close();
    }
}
