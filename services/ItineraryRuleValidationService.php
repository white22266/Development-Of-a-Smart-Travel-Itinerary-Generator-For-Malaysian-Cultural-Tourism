<?php
// services/ItineraryRuleValidationService.php
// The check the AI itinerary editor runs before it offers a change. It holds no rules of its
// own: a change is judged exactly as ItineraryEditService would make it (PlannerRules and the
// generator's timing of the whole day), without storing anything.

require_once __DIR__ . '/ItineraryEditService.php';

final class ItineraryRuleValidationService
{
    /**
     * Would putting $placeId into the trip be done? With $excludeItemId the place replaces that
     * stop (a visit, or a meal row for a food place); without it the place is added to $dayNo
     * at the position with the least travelling.
     *
     * @return array{ok:bool,message:string,code:?string} code: a reason code of PlannerRules::REASONS
     */
    public static function validatePlaceChange(mysqli $conn, int $travellerId, int $itineraryId, int $placeId, int $dayNo, int $excludeItemId = 0): array
    {
        $stmt = $conn->prepare('SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1');
        if (!$stmt) return ['ok' => false, 'message' => 'Itinerary context could not be loaded.', 'code' => 'NOT_FOUND'];
        $stmt->bind_param('ii', $itineraryId, $travellerId);
        $stmt->execute();
        $own = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$own) return ['ok' => false, 'message' => 'Itinerary context could not be loaded.', 'code' => 'NOT_FOUND'];

        $result = $excludeItemId > 0
            ? ItineraryEditService::canReplace($conn, $itineraryId, $excludeItemId, $placeId)
            : ItineraryEditService::canAdd($conn, $itineraryId, $dayNo, $placeId);
        return [
            'ok' => (bool)$result['ok'],
            'message' => $result['ok'] ? 'Place change passes itinerary rules.' : (string)$result['message'],
            'code' => $result['code'],
        ];
    }
}
