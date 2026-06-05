<?php
// services/PlannerIntegrityService.php
// Final integrity layer around the rule-based planner.
require_once __DIR__ . '/CostEstimationService.php';

final class PlannerIntegrityService
{
    public static function deactivateUnverifiedFestivals(mysqli $conn): void
    {
        // The planner must never depend only on a migration for festival safety.
        // Invalid festivals are removed from the active candidate pool before generation.
        $conn->query("
            UPDATE cultural_places
            SET is_active = 0
            WHERE LOWER(TRIM(category)) = 'festival'
              AND (
                  festival_start_date IS NULL
                  OR festival_end_date IS NULL
                  OR festival_start_date = '0000-00-00'
                  OR festival_end_date = '0000-00-00'
                  OR festival_end_date < festival_start_date
              )
        ");
    }

    public static function enforceCumulativeAttractionBudget(mysqli $conn, int $travellerId, int $itineraryId): array
    {
        $ctx = self::loadContext($conn, $travellerId, $itineraryId);
        if (!$ctx) {
            throw new RuntimeException('Unable to validate the generated itinerary budget.');
        }

        $partySize = CostEstimationService::resolvePartySize(
            (string)($ctx['traveller_type'] ?? 'solo'),
            isset($ctx['party_size']) ? (int)$ctx['party_size'] : null
        );
        $tripDays = max(1, (int)$ctx['total_days']);
        $budget = max(0.0, (float)$ctx['budget']);
        $defaults = CostEstimationService::budgetTierDefaults(
            (string)($ctx['budget_tier'] ?? 'normal'),
            $budget,
            $tripDays,
            $partySize
        );

        $totalDistance = self::totalDistance($conn, $itineraryId);
        $rooms = CostEstimationService::roomCount($partySize);
        $nights = max(0, $tripDays - 1);
        $mealReserve = $tripDays * 3 * (float)$defaults['meal'] * $partySize;
        $hotelReserve = $nights * (float)$defaults['hotel'] * $rooms;
        $transportUnits = CostEstimationService::vehicleCount((string)($ctx['transport_type'] ?? 'car'), $partySize);
        $transportReserve = $totalDistance
            * CostEstimationService::getTransportRate((string)($ctx['transport_type'] ?? 'car'))
            * $transportUnits;
        $essential = $mealReserve + $hotelReserve + $transportReserve;
        $remaining = max(0.0, $budget - $essential - ($essential * 0.10));
        $initialRemaining = $remaining;

        $dayCounts = self::dayNonHotelCounts($conn, $itineraryId);
        $items = self::loadPaidVisitItems($conn, $itineraryId);
        $removed = [];
        $retainedWarnings = [];

        foreach ($items as $item) {
            $wholePartyCost = max(0.0, (float)$item['estimated_cost']) * $partySize;
            if ($wholePartyCost <= 0.0) continue;

            if ($wholePartyCost <= $remaining) {
                $remaining -= $wholePartyCost;
                continue;
            }

            $dayNo = (int)$item['day_no'];
            if (($dayCounts[$dayNo] ?? 0) > 1) {
                self::deleteItem($conn, (int)$item['item_id']);
                $dayCounts[$dayNo]--;
                $removed[] = (int)$item['item_id'];
                continue;
            }

            // Budget shortage must not create an empty day. Keep the final stop and record a clear warning.
            self::appendBudgetWarning($conn, (int)$item['item_id'], $wholePartyCost, $remaining);
            $retainedWarnings[] = (int)$item['item_id'];
        }

        self::resequence($conn, $itineraryId);
        self::recalculateTotal($conn, $itineraryId, $ctx, $partySize);

        return [
            'initial_attraction_budget' => round($initialRemaining, 2),
            'remaining_attraction_budget' => round($remaining, 2),
            'removed_item_ids' => $removed,
            'retained_budget_warning_item_ids' => $retainedWarnings,
        ];
    }

    private static function loadContext(mysqli $conn, int $travellerId, int $itineraryId): ?array
    {
        $partySelect = self::columnExists($conn, 'traveller_preferences', 'party_size') ? ', tp.party_size' : '';
        $stmt = $conn->prepare("
            SELECT i.itinerary_id, i.total_days, tp.budget, tp.budget_tier, tp.transport_type,
                   tp.traveller_type{$partySelect}
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ? AND i.traveller_id = ?
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('ii', $itineraryId, $travellerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private static function totalDistance(mysqli $conn, int $itineraryId): float
    {
        $stmt = $conn->prepare('SELECT COALESCE(SUM(distance_km),0) AS total FROM itinerary_items WHERE itinerary_id = ?');
        if (!$stmt) return 0.0;
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return max(0.0, (float)($row['total'] ?? 0));
    }

    private static function dayNonHotelCounts(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare("SELECT day_no, COUNT(*) AS c FROM itinerary_items WHERE itinerary_id = ? AND item_type <> 'hotel' GROUP BY day_no");
        if (!$stmt) return [];
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $counts = [];
        while ($row = $res->fetch_assoc()) $counts[(int)$row['day_no']] = (int)$row['c'];
        $stmt->close();
        return $counts;
    }

    private static function loadPaidVisitItems(mysqli $conn, int $itineraryId): array
    {
        // Food is covered by the meal reserve. Hotel is covered by the accommodation reserve.
        $stmt = $conn->prepare("
            SELECT item_id, day_no, sequence_no, item_type, estimated_cost
            FROM itinerary_items
            WHERE itinerary_id = ? AND item_type IN ('attraction','festival')
            ORDER BY day_no, sequence_no
        ");
        if (!$stmt) return [];
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private static function deleteItem(mysqli $conn, int $itemId): void
    {
        $stmt = $conn->prepare('DELETE FROM itinerary_items WHERE item_id = ?');
        if (!$stmt) return;
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private static function appendBudgetWarning(mysqli $conn, int $itemId, float $cost, float $remaining): void
    {
        $warning = 'Budget warning: retained to prevent an empty day; whole-party attraction cost RM '
            . number_format($cost, 2) . ' exceeds remaining attraction budget RM ' . number_format($remaining, 2);
        $stmt = $conn->prepare("UPDATE itinerary_items SET notes = LEFT(CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') = '' THEN '' ELSE '; ' END, ?), 255) WHERE item_id = ?");
        if (!$stmt) return;
        $stmt->bind_param('si', $warning, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    private static function resequence(mysqli $conn, int $itineraryId): void
    {
        $stmt = $conn->prepare('SELECT item_id, day_no FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no, item_id');
        if (!$stmt) return;
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $res = $stmt->get_result();
        $seq = [];
        $update = $conn->prepare('UPDATE itinerary_items SET sequence_no = ? WHERE item_id = ?');
        while ($row = $res->fetch_assoc()) {
            $day = (int)$row['day_no'];
            $seq[$day] = ($seq[$day] ?? 0) + 1;
            if ($update) {
                $itemId = (int)$row['item_id'];
                $sequence = $seq[$day];
                $update->bind_param('ii', $sequence, $itemId);
                $update->execute();
            }
        }
        if ($update) $update->close();
        $stmt->close();
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

        $defaults = CostEstimationService::budgetTierDefaults(
            (string)($ctx['budget_tier'] ?? 'normal'),
            (float)($ctx['budget'] ?? 0),
            (int)$ctx['total_days'],
            $partySize
        );
        $service = new CostEstimationService(
            (string)($ctx['transport_type'] ?? 'car'),
            (int)$ctx['total_days'],
            (float)($ctx['budget'] ?? 0),
            (string)($ctx['traveller_type'] ?? 'solo'),
            $partySize
        );
        $cost = $service->calculate($items, $distance, (float)$defaults['hotel'], 3, (float)$defaults['meal']);
        $total = (float)$cost['total_cost'];
        $update = $conn->prepare('UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?');
        if ($update) {
            $update->bind_param('di', $total, $itineraryId);
            $update->execute();
            $update->close();
        }
    }

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}
