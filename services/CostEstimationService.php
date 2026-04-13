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

    public function __construct(string $transportType = 'car', int $tripDays = 1, float $budget = 0.0)
    {
        $this->transportType = strtolower(trim($transportType));
        $this->tripDays      = max(1, $tripDays);
        $this->budget        = $budget;
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
        foreach ($itineraryItems as $item) {
            $attractionCost += (float)($item['estimated_cost'] ?? 0);
        }

        // ---- 2. Transport cost ----
        $rate          = self::TRANSPORT_RATE[$this->transportType] ?? 0.60;
        $transportCost = round($totalDistanceKm * $rate, 2);

        // ---- 3. Accommodation cost ----
        $nights            = max(0, $this->tripDays - 1); // last night not counted
        $hotelRate         = ($hotelRatePerNight > 0) ? $hotelRatePerNight : self::DEFAULT_HOTEL_RATE;
        $accommodationCost = round($nights * $hotelRate, 2);

        // ---- 4. Food cost ----
        $meals       = ($mealsPerDay > 0) ? $mealsPerDay : self::MEALS_PER_DAY;
        $mealPrice   = ($avgMealPrice > 0) ? $avgMealPrice : self::AVG_MEAL_PRICE;
        $foodCost    = round($this->tripDays * $meals * $mealPrice, 2);

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
                    'note'   => $nights . ' night(s) × RM ' . number_format($hotelRate, 2) . '/night',
                ],
                [
                    'label'  => 'Food & Meals',
                    'amount' => $foodCost,
                    'note'   => $this->tripDays . ' day(s) × ' . $meals . ' meals × RM ' . number_format($mealPrice, 2),
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
        $t = strtolower(trim($transportType));
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
