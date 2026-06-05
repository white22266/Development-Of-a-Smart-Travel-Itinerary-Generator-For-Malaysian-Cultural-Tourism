<?php
/**
 * services/CostEstimationService.php
 *
 * Whole-party cost estimation.
 * The user's budget is treated as the total budget for the full travelling party.
 */
class CostEstimationService
{
    private string $transportType;
    private int $tripDays;
    private float $budget;
    private string $travellerType;
    private int $partySize;

    private const TRANSPORT_RATE = [
        'car'              => 0.60,
        'motorcycle'       => 0.30,
        'public_transport' => 0.15,
        'walking'          => 0.00,
    ];

    private const DEFAULT_HOTEL_RATE = 120.00;
    private const MEALS_PER_DAY = 3;
    private const AVG_MEAL_PRICE = 15.00;

    public function __construct(
        string $transportType = 'car',
        int $tripDays = 1,
        float $budget = 0.0,
        string $travellerType = 'solo',
        ?int $partySize = null
    ) {
        $this->transportType = self::normalizeTransportType($transportType);
        $this->tripDays = max(1, $tripDays);
        $this->budget = max(0.0, $budget);
        $this->travellerType = self::normalizeTravellerType($travellerType);
        $this->partySize = self::resolvePartySize($this->travellerType, $partySize);
    }

    public static function normalizeTravellerType(string $travellerType): string
    {
        $t = strtolower(trim($travellerType));
        return in_array($t, ['solo', 'couple', 'family', 'group'], true) ? $t : 'solo';
    }

    public static function defaultPartySize(string $travellerType): int
    {
        return match (self::normalizeTravellerType($travellerType)) {
            'couple' => 2,
            'family' => 4,
            'group' => 5,
            default => 1,
        };
    }

    public static function travellerMultiplier(string $travellerType): int
    {
        return self::defaultPartySize($travellerType);
    }

    public static function resolvePartySize(string $travellerType, ?int $partySize = null): int
    {
        if ($partySize !== null && $partySize >= 1) {
            return max(1, min(1000, $partySize));
        }
        return self::defaultPartySize($travellerType);
    }

    public static function normalizeTransportType(string $transportType): string
    {
        $t = strtolower(trim(str_replace('-', '_', $transportType)));
        $t = preg_replace('/\s+/', '_', $t) ?? $t;
        return match ($t) {
            'public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train' => 'public_transport',
            'walk' => 'walking',
            'drive', 'driving' => 'car',
            'motorbike', 'bike' => 'motorcycle',
            default => array_key_exists($t, self::TRANSPORT_RATE) ? $t : 'car',
        };
    }

    public static function vehicleCount(string $transportType, int $partySize): int
    {
        $partySize = max(1, $partySize);
        $transportType = self::normalizeTransportType($transportType);
        if ($transportType === 'car') return (int)ceil($partySize / 5);
        if ($transportType === 'motorcycle') return (int)ceil($partySize / 2);
        if ($transportType === 'public_transport') return $partySize;
        return 1;
    }

    public static function roomCount(int $partySize): int
    {
        return max(1, (int)ceil(max(1, $partySize) / 2));
    }

    public static function budgetTierDefaults(string $budgetTier, float $budget = 0.0, int $tripDays = 1, ?int $partySize = null): array
    {
        $defaults = match (strtolower(trim($budgetTier))) {
            'budget' => ['hotel' => 90.0, 'meal' => 12.0],
            'luxury' => ['hotel' => 280.0, 'meal' => 35.0],
            default => ['hotel' => 150.0, 'meal' => 20.0],
        };

        if ($budget <= 0) return $defaults;

        $days = max(1, $tripDays);
        $nights = max(0, $days - 1);
        $people = max(1, $partySize ?? 1);
        $rooms = self::roomCount($people);
        $mealSlots = max(1, $days * self::MEALS_PER_DAY * $people);

        $mealCap = ($budget * 0.25) / $mealSlots;
        $hotelCap = $nights > 0 ? ($budget * 0.30) / ($nights * $rooms) : 0.0;

        $defaults['meal'] = max(6.0, min($defaults['meal'], $mealCap));
        if ($nights > 0) {
            $defaults['hotel'] = max(60.0, min($defaults['hotel'], $hotelCap));
        }
        return $defaults;
    }

    public function calculate(
        array $itineraryItems,
        float $totalDistanceKm = 0.0,
        float $hotelRatePerNight = 0.0,
        int $mealsPerDay = 0,
        float $avgMealPrice = 0.0
    ): array {
        $attractionBase = 0.0;
        $scheduledFoodBase = 0.0;
        $selectedHotelBase = 0.0;
        $selectedHotelCount = 0;

        foreach ($itineraryItems as $item) {
            $type = strtolower((string)($item['item_type'] ?? ''));
            $cost = max(0.0, (float)($item['estimated_cost'] ?? 0));
            if ($type === 'hotel') {
                $selectedHotelBase += $cost;
                $selectedHotelCount++;
            } elseif ($type === 'food') {
                $scheduledFoodBase += $cost;
            } else {
                $attractionBase += $cost;
            }
        }

        $partySize = $this->partySize;
        $rooms = self::roomCount($partySize);
        $vehicleUnits = self::vehicleCount($this->transportType, $partySize);
        $nights = max(0, $this->tripDays - 1);

        // Assumption: cultural place entrance fees and food place estimates are per person unless future pricing_unit says otherwise.
        $attractionCost = round($attractionBase * $partySize, 2);
        $scheduledFoodCost = round($scheduledFoodBase * $partySize, 2);

        $rate = self::TRANSPORT_RATE[$this->transportType] ?? 0.60;
        if ($this->transportType === 'public_transport') {
            $transportCost = round($totalDistanceKm * $rate * $partySize, 2);
            $transportNote = number_format($totalDistanceKm, 1) . ' km x RM ' . number_format($rate, 2) . '/km x ' . $partySize . ' traveller(s)';
        } elseif ($this->transportType === 'car' || $this->transportType === 'motorcycle') {
            $transportCost = round($totalDistanceKm * $rate * $vehicleUnits, 2);
            $transportNote = number_format($totalDistanceKm, 1) . ' km x RM ' . number_format($rate, 2) . '/km x ' . $vehicleUnits . ' vehicle unit(s)';
        } else {
            $transportCost = 0.0;
            $transportNote = 'Walking transport cost RM 0.00';
        }

        $hotelRate = $hotelRatePerNight > 0 ? $hotelRatePerNight : self::DEFAULT_HOTEL_RATE;
        if ($selectedHotelBase > 0) {
            $accommodationCost = round($selectedHotelBase * $rooms, 2);
            $hotelNote = $selectedHotelCount . ' selected hotel night item(s) x ' . $rooms . ' room(s)';
        } else {
            $accommodationCost = round($nights * $hotelRate * $rooms, 2);
            $hotelNote = $nights . ' night(s) x RM ' . number_format($hotelRate, 2) . '/room x ' . $rooms . ' room(s)';
        }

        $meals = $mealsPerDay > 0 ? $mealsPerDay : self::MEALS_PER_DAY;
        $mealPrice = $avgMealPrice > 0 ? $avgMealPrice : self::AVG_MEAL_PRICE;
        $defaultFoodCost = round($this->tripDays * $meals * $mealPrice * $partySize, 2);
        $foodCost = max($defaultFoodCost, $scheduledFoodCost);
        $foodNote = $scheduledFoodBase > 0
            ? 'Scheduled food RM ' . number_format($scheduledFoodCost, 2) . '; minimum estimate ' . $this->tripDays . ' day(s) x ' . $meals . ' meals x RM ' . number_format($mealPrice, 2) . ' x ' . $partySize . ' traveller(s)'
            : $this->tripDays . ' day(s) x ' . $meals . ' meals x RM ' . number_format($mealPrice, 2) . ' x ' . $partySize . ' traveller(s)';

        $totalCost = round($attractionCost + $transportCost + $accommodationCost + $foodCost, 2);
        $withinBudget = $this->budget <= 0 ? true : $totalCost <= $this->budget;
        $budgetDifference = $this->budget > 0 ? round($this->budget - $totalCost, 2) : 0.0;

        return [
            'attraction_cost' => $attractionCost,
            'transport_cost' => $transportCost,
            'accommodation_cost' => $accommodationCost,
            'food_cost' => $foodCost,
            'total_cost' => $totalCost,
            'budget' => $this->budget,
            'within_budget' => $withinBudget,
            'budget_difference' => $budgetDifference,
            'nights' => $nights,
            'party_size' => $partySize,
            'room_count' => $rooms,
            'vehicle_units' => $vehicleUnits,
            'breakdown' => [
                [
                    'label' => 'Attraction / Entrance Fees',
                    'amount' => $attractionCost,
                    'note' => 'RM ' . number_format($attractionBase, 2) . ' per-person place costs x ' . $partySize . ' traveller(s)',
                ],
                [
                    'label' => 'Transport (' . ucfirst(str_replace('_', ' ', $this->transportType)) . ')',
                    'amount' => $transportCost,
                    'note' => $transportNote,
                ],
                [
                    'label' => 'Accommodation',
                    'amount' => $accommodationCost,
                    'note' => $hotelNote,
                ],
                [
                    'label' => 'Food & Meals',
                    'amount' => $foodCost,
                    'note' => $foodNote,
                ],
            ],
        ];
    }

    public function perDayBreakdown(array $itemsByDay): array
    {
        $result = [];
        foreach ($itemsByDay as $day => $items) {
            $dayCost = 0.0;
            foreach ($items as $item) $dayCost += (float)($item['estimated_cost'] ?? 0);
            $result[$day] = [
                'day' => (int)$day,
                'attraction_cost' => round($dayCost * $this->partySize, 2),
                'item_count' => count($items),
                'items' => $items,
            ];
        }
        return $result;
    }

    public static function getTransportRate(string $transportType): float
    {
        $t = self::normalizeTransportType($transportType);
        return self::TRANSPORT_RATE[$t] ?? 0.60;
    }

    public static function formatRM(float $amount): string
    {
        return 'RM ' . number_format($amount, 2);
    }
}
