<?php
/**
 * services/CostEstimationService.php
 *
 * CostEstimationService — Calculates a full trip cost breakdown.
 *
 * Cost components:
 *   1. Attraction cost   — sum of itinerary item estimated_cost values
 *   2. Transport cost    — total_distance_km × rate_per_km (by transport type)
 *   3. Accommodation     — (trip_days - 1) nights × avg_hotel_rate
 *   4. Food cost         — trip_days × meals_per_day × avg_meal_price
 *
 * Usage:
 *   $cs = new CostEstimationService('car', $tripDays, $budget);
 *   $breakdown = $cs->calculate($itineraryItems, $totalDistanceKm, $hotelRate);
 */

class CostEstimationService
{
    /** @var string Transport type */
    private string $transportType;

    /** @var int Number of trip days */
    private int $tripDays;

    /** @var float User's total budget */
    private float $budget;

    /** @var string Traveller type used for meal/person estimates */
    private string $travellerType;

    // ---- Default rates (RM) ----

    /** Transport cost per km by mode */
    private const TRANSPORT_RATE = [
        'car'              => 0.60,   // fuel + toll estimate
        'motorcycle'       => 0.30,
        'public_transport' => 0.15,   // bus/train per km
        'walking'          => 0.00,
    ];

    /** Default hotel rate per night (RM) if not provided */
    private const DEFAULT_HOTEL_RATE = 120.00;

    /** Meals per day */
    private const MEALS_PER_DAY = 3;

    /** Average meal price per person (RM) */
    private const AVG_MEAL_PRICE = 15.00;

    public function __construct(string $transportType = 'car', int $tripDays = 1, float $budget = 0.0, string $travellerType = 'solo')
    {
        $this->transportType = self::normalizeTransportType($transportType);
        $this->tripDays      = max(1, $tripDays);
        $this->budget        = $budget;
        $this->travellerType = self::normalizeTravellerType($travellerType);
    }

    public static function normalizeTravellerType(string $travellerType): string
    {
        $t = strtolower(trim($travellerType));
        return in_array($t, ['solo', 'couple', 'family', 'group'], true) ? $t : 'solo';
    }

    public static function travellerMultiplier(string $travellerType): int
    {
        return match (self::normalizeTravellerType($travellerType)) {
            'couple' => 2,
            'family' => 4,
            'group' => 5,
            default => 1,
        };
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

    public static function budgetTierDefaults(string $budgetTier, float $budget = 0.0, int $tripDays = 1): array
    {
        $defaults = match (strtolower(trim($budgetTier))) {
            'budget' => ['hotel' => 90.0, 'meal' => 12.0],
            'luxury' => ['hotel' => 280.0, 'meal' => 35.0],
            default => ['hotel' => 150.0, 'meal' => 20.0],
        };

        if ($budget <= 0) {
            return $defaults;
        }

        $days = max(1, $tripDays);
        $nights = max(0, $days - 1);
        $mealSlots = max(1, $days * self::MEALS_PER_DAY);

        // The user's RM budget is the hard planning target. Spending style gives
        // the preferred comfort level, then this caps hotel/meal assumptions so a
        // low total budget does not automatically create an impossible estimate.
        $mealCap = ($budget * 0.25) / $mealSlots;
        $hotelCap = $nights > 0 ? ($budget * 0.30) / $nights : 0.0;

        $defaults['meal'] = max(6.0, min($defaults['meal'], $mealCap));
        if ($nights > 0) {
            $defaults['hotel'] = max(60.0, min($defaults['hotel'], $hotelCap));
        }

        return $defaults;
    }

    // =========================================================
    // PUBLIC: Full cost breakdown
    // =========================================================

    /**
     * Calculate full trip cost breakdown.
     *
     * @param array  $itineraryItems   Array of itinerary item rows (each with 'estimated_cost')
     * @param float  $totalDistanceKm  Total travel distance across all days
     * @param float  $hotelRatePerNight  Hotel rate per night (0 = use default)
     * @param int    $mealsPerDay      Override meals per day (0 = use default)
     * @param float  $avgMealPrice     Override average meal price (0 = use default)
     * @return array{
     *   attraction_cost: float,
     *   transport_cost: float,
     *   accommodation_cost: float,
     *   food_cost: float,
     *   total_cost: float,
     *   budget: float,
     *   within_budget: bool,
     *   budget_difference: float,
     *   nights: int,
     *   breakdown: array
     * }
     */
    public function calculate(
        array $itineraryItems,
        float $totalDistanceKm = 0.0,
        float $hotelRatePerNight = 0.0,
        int   $mealsPerDay = 0,
        float $avgMealPrice = 0.0
    ): array {
        // ---- 1. Attraction cost ----
        $attractionCost = 0.0;
        $scheduledFoodCost = 0.0;
        $selectedHotelCost = 0.0;
        $selectedHotelCount = 0;
        foreach ($itineraryItems as $item) {
            $type = strtolower((string)($item['item_type'] ?? ''));
            if ($type === 'hotel') {
                $selectedHotelCost += (float)($item['estimated_cost'] ?? 0);
                $selectedHotelCount++;
                continue;
            }
            if ($type === 'food') {
                $scheduledFoodCost += (float)($item['estimated_cost'] ?? 0);
                continue;
            }
            $attractionCost += (float)($item['estimated_cost'] ?? 0);
        }

        // ---- 2. Transport cost ----
        $rate          = self::TRANSPORT_RATE[$this->transportType] ?? 0.60;
        $transportCost = round($totalDistanceKm * $rate, 2);

        // ---- 3. Accommodation cost ----
        $nights    = max(0, $this->tripDays - 1); // last night not counted
        $hotelRate = ($hotelRatePerNight > 0) ? $hotelRatePerNight : self::DEFAULT_HOTEL_RATE;
        if ($selectedHotelCost > 0) {
            $accommodationCost = round($selectedHotelCost, 2);
            $hotelNote = $selectedHotelCount . ' selected hotel item(s)';
        } else {
            $accommodationCost = round($nights * $hotelRate, 2);
            $hotelNote = $nights . ' night(s) x RM ' . number_format($hotelRate, 2) . '/night';
        }

        // ---- 4. Food cost ----
        $meals       = ($mealsPerDay > 0) ? $mealsPerDay : self::MEALS_PER_DAY;
        $mealPrice   = ($avgMealPrice > 0) ? $avgMealPrice : self::AVG_MEAL_PRICE;
        $travellerMultiplier = self::travellerMultiplier($this->travellerType);
        $defaultFoodCost = round($this->tripDays * $meals * $mealPrice * $travellerMultiplier, 2);
        $foodCost = max($defaultFoodCost, round($scheduledFoodCost, 2));
        $foodNote = $scheduledFoodCost > 0
            ? 'Scheduled food stops RM ' . number_format($scheduledFoodCost, 2) . '; minimum estimate ' . $this->tripDays . ' day(s) x ' . $meals . ' meals x RM ' . number_format($mealPrice, 2) . ' x ' . $travellerMultiplier . ' traveller unit(s)'
            : $this->tripDays . ' day(s) x ' . $meals . ' meals x RM ' . number_format($mealPrice, 2) . ' x ' . $travellerMultiplier . ' traveller unit(s)';

        // ---- 5. Total ----
        $totalCost = round($attractionCost + $transportCost + $accommodationCost + $foodCost, 2);

        // ---- 6. Budget comparison ----
        $withinBudget     = ($this->budget <= 0) ? true : ($totalCost <= $this->budget);
        $budgetDifference = ($this->budget > 0) ? round($this->budget - $totalCost, 2) : 0.0;

        return [
            'attraction_cost'     => round($attractionCost, 2),
            'transport_cost'      => $transportCost,
            'accommodation_cost'  => $accommodationCost,
            'food_cost'           => $foodCost,
            'total_cost'          => $totalCost,
            'budget'              => $this->budget,
            'within_budget'       => $withinBudget,
            'budget_difference'   => $budgetDifference,
            'nights'              => $nights,
            'breakdown'           => [
                [
                    'label'  => 'Attraction / Entrance Fees',
                    'amount' => round($attractionCost, 2),
                    'note'   => 'Sum of all itinerary item costs',
                ],
                [
                    'label'  => 'Transport (' . ucfirst(str_replace('_', ' ', $this->transportType)) . ')',
                    'amount' => $transportCost,
                    'note'   => number_format($totalDistanceKm, 1) . ' km × RM ' . number_format($rate, 2) . '/km',
                ],
                [
                    'label'  => 'Accommodation',
                    'amount' => $accommodationCost,
                    'note'   => $hotelNote,
                ],
                [
                    'label'  => 'Food & Meals',
                    'amount' => $foodCost,
                    'note'   => $foodNote,
                ],
            ],
        ];
    }

    // =========================================================
    // PUBLIC: Per-day cost breakdown
    // =========================================================

    /**
     * Get cost breakdown per day.
     *
     * @param array $itemsByDay  Associative array [day_no => [items...]]
     * @return array  [day_no => ['attraction_cost' => float, 'items' => array], ...]
     */
    public function perDayBreakdown(array $itemsByDay): array
    {
        $result = [];
        foreach ($itemsByDay as $day => $items) {
            $dayCost = 0.0;
            foreach ($items as $item) {
                $dayCost += (float)($item['estimated_cost'] ?? 0);
            }
            $result[$day] = [
                'day'             => (int)$day,
                'attraction_cost' => round($dayCost, 2),
                'item_count'      => count($items),
                'items'           => $items,
            ];
        }
        return $result;
    }

    // =========================================================
    // PUBLIC: Static helpers
    // =========================================================

    /**
     * Get transport rate per km for a given transport type.
     */
    public static function getTransportRate(string $transportType): float
    {
        $t = self::normalizeTransportType($transportType);
        return self::TRANSPORT_RATE[$t] ?? 0.60;
    }

    /**
     * Format a cost value as RM string.
     */
    public static function formatRM(float $amount): string
    {
        return 'RM ' . number_format($amount, 2);
    }
}
