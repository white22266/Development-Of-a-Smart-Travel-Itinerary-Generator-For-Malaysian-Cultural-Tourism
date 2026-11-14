<?php
// services/PlannerRules.php
// The rules an itinerary must keep, written once. The day builder, the generator and the
// route optimiser all ask here, so no step can undo what another step guaranteed.
//
//   loadPreference()  a saved preference, normalised
//   profile()         the planning profile for a preference: target stops, rest, day window,
//                     distance limits, visit lengths, accessibility, party and budget split
//   check()           can this place be visited in this slot? The one feasibility check: it
//                     returns the visit's start and end, or a reason code
//   visitMinutes()    how long a visit lasts;  placeCost()  what a place costs per person
//   projectedTotal()  the trip total, computed exactly as ItineraryEditService::total() stores it

require_once __DIR__ . '/OpeningHours.php';
require_once __DIR__ . '/CostEstimationService.php';
require_once __DIR__ . '/AccessibilityNeedsAnalysisService.php';

final class PlannerRules
{
    public const VALID_INTERESTS = ['culture', 'heritage', 'food', 'museum', 'nature', 'shopping', 'festival'];

    /** Visit stops per day for each pace, and the rest between two visits. */
    private const PACE_TARGET = ['relaxed' => 3, 'normal' => 4, 'packed' => 5];
    private const PACE_REST_MIN = ['relaxed' => 90, 'normal' => 60, 'packed' => 30];
    private const REST_EXTRA_CAREFUL = 15;
    private const REST_EXTRA_GROUP = 10;

    /** Day window per preferred visit time: earliest start and latest end, minutes after midnight. */
    private const VISIT_WINDOW = [
        'morning' => [480, 1140],     // 08:00 - 19:00
        'afternoon' => [720, 1290],   // 12:00 - 21:30
        'evening' => [900, 1350],     // 15:00 - 22:30
        'any' => [510, 1260],         // 08:30 - 21:00
    ];
    /** Latest end for families and for elderly or low-walking travellers. */
    private const CAREFUL_LATEST_END = 1200;   // 20:00

    /** Recommended daily distance in km; the hard limit is max(3 x recommended, recommended + 15). */
    private const DAILY_DISTANCE_KM = [
        'walking' => ['relaxed' => 3.0, 'normal' => 5.0, 'packed' => 7.0],
        'public_transport' => ['relaxed' => 15.0, 'normal' => 25.0, 'packed' => 35.0],
        'car' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
        'motorcycle' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
    ];
    private const CAREFUL_DISTANCE_FACTOR = 0.75;
    /** Longest single leg (other than the leg from the trip's starting point). */
    private const LEG_LIMIT_KM = ['walking' => 8.0, 'public_transport' => 60.0, 'car' => 150.0, 'motorcycle' => 150.0];

    /** Visit length: 30 to 240 min (90 when unknown); at most 120 for families, 105 for elderly or low walking. */
    private const VISIT_DEFAULT = 90;
    private const VISIT_MIN = 30;
    private const VISIT_MAX = 240;
    private const VISIT_MAX_FAMILY = 120;
    private const VISIT_MAX_CAREFUL = 105;

    /** Avoid heat: no outdoor visit overlaps 11:00 - 16:00. Elderly: no outdoor visit over 150 min. */
    private const HEAT_FROM = 660;
    private const HEAT_UNTIL = 960;
    private const ELDERLY_LONG_VISIT_MIN = 150;
    private const STEP_FREE_BLOCKED = '/gunung|mountain|hill|cave|stairs|waterfall|trail|hiking/i';

    /** Longest wait for a place to open. */
    private const MAX_WAIT_MIN = 120;

    /** Paid places get what the budget leaves after the essentials and this margin on them. */
    public const BUDGET_MARGIN = 0.10;
    private const MEALS_PER_DAY = 3;

    /** East Malaysia; everything else is Peninsular. A point without a state is east from this longitude. */
    private const EAST_STATES = ['sabah', 'sarawak', 'labuan'];
    private const EAST_FROM_LNG = 108.0;

    /** Reason codes of check(), in words for notes and messages. */
    public const REASONS = [
        'DUPLICATE' => 'already in the itinerary',
        'STATE' => 'outside the chosen states',
        'REGION' => 'across the sea between Peninsular and East Malaysia',
        'FESTIVAL_DATES' => 'festival dates unknown',
        'FESTIVAL_DAY' => 'festival not on this day',
        'DIETARY' => 'does not meet the dietary preference',
        'STEP_FREE' => 'not step-free',
        'LONG_OUTDOOR' => 'long outdoor visit',
        'LEG_DISTANCE' => 'too far from the previous stop',
        'DAY_DISTANCE' => 'over the daily distance limit',
        'CLOSED' => 'closed that day',
        'OPEN_HOURS' => 'not open for the whole visit at the planned time',
        'HEAT' => 'outdoors in the midday heat',
        'DAY_END' => 'would end after the latest end of the day',
        'WAIT' => 'would wait too long for opening',
        'BUDGET' => 'over the budget',
    ];

    /**
     * A saved preference, normalised, or null when it does not exist (or is not the traveller's).
     * Only the columns the planner uses today are read.
     */
    public static function loadPreference(mysqli $conn, int $preferenceId, ?int $travellerId = null): ?array
    {
        $sql = "
            SELECT preference_id, traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size,
                   travel_pace, dietary_preference, preferred_visit_time, accessibility_needs, interests,
                   preferred_states, preferred_districts
            FROM traveller_preferences
            WHERE preference_id = ?" . ($travellerId !== null ? " AND traveller_id = ?" : "") . "
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to load saved preference.');
        if ($travellerId !== null) $stmt->bind_param('ii', $preferenceId, $travellerId);
        else $stmt->bind_param('i', $preferenceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? self::normalizePreference($row) : null;
    }

    /** A preference row (or an empty array for the defaults) in the form profile() reads. */
    public static function normalizePreference(array $row): array
    {
        $travellerType = CostEstimationService::normalizeTravellerType((string)($row['traveller_type'] ?? 'solo'));
        $interests = array_values(array_filter(self::csv(strtolower((string)($row['interests'] ?? ''))), static fn($x) => in_array($x, self::VALID_INTERESTS, true)));
        return [
            'preference_id' => (int)($row['preference_id'] ?? 0),
            'trip_days' => max(1, min(30, (int)($row['trip_days'] ?? 1))),
            'budget' => max(0.0, (float)($row['budget'] ?? 0)),
            'budget_tier' => self::oneOf((string)($row['budget_tier'] ?? 'normal'), ['budget', 'normal', 'luxury'], 'normal'),
            'transport_type' => CostEstimationService::normalizeTransportType((string)($row['transport_type'] ?? 'car')),
            'traveller_type' => $travellerType,
            'party_size' => CostEstimationService::resolvePartySize($travellerType, isset($row['party_size']) ? (int)$row['party_size'] : null),
            'travel_pace' => self::oneOf((string)($row['travel_pace'] ?? 'normal'), ['relaxed', 'normal', 'packed'], 'normal'),
            'dietary_preference' => self::oneOf((string)($row['dietary_preference'] ?? 'none'), ['none', 'halal', 'vegetarian'], 'none'),
            'preferred_visit_time' => self::oneOf((string)($row['preferred_visit_time'] ?? 'any'), ['any', 'morning', 'afternoon', 'evening'], 'any'),
            'accessibility_needs' => trim((string)($row['accessibility_needs'] ?? '')),
            'interests' => $interests ?: ['culture'],
            'states' => self::csv((string)($row['preferred_states'] ?? '')),
            'districts' => self::csv((string)($row['preferred_districts'] ?? '')),
        ];
    }

    /**
     * The planning profile of a preference.
     *
     * Optional keys of $pref that override what the preference implies (later inputs can
     * set them without changing any caller): day_start_min and day_end_min (minutes after
     * midnight), stops_per_day, access_tags (a list of accessibility tags), and origin_lat /
     * origin_lng (the trip's starting point, for the Peninsular / East Malaysia rule).
     */
    public static function profile(array $pref): array
    {
        $pref += self::normalizePreference([]);
        $pace = (string)$pref['travel_pace'];
        $type = (string)$pref['traveller_type'];
        $transport = (string)$pref['transport_type'];

        $tags = isset($pref['access_tags']) && is_array($pref['access_tags'])
            ? array_values(array_map('strval', $pref['access_tags']))
            : (AccessibilityNeedsAnalysisService::storedTags((string)$pref['accessibility_needs'])
                ?? AccessibilityNeedsAnalysisService::keywordTags((string)$pref['accessibility_needs'], $type));
        $has = static fn(string ...$wanted): bool => (bool)array_intersect($wanted, $tags);
        $careful = $type === 'family' || $has('elderly', 'low_walking');

        [$start, $end] = self::VISIT_WINDOW[(string)$pref['preferred_visit_time']];
        if (isset($pref['day_start_min'])) $start = (int)$pref['day_start_min'];
        if (isset($pref['day_end_min'])) $end = (int)$pref['day_end_min'];
        if ($careful) $end = min($end, self::CAREFUL_LATEST_END);

        $dayKm = self::DAILY_DISTANCE_KM[$transport][$pace] ?? 45.0;
        if ($has('low_walking', 'wheelchair', 'elderly')) $dayKm *= self::CAREFUL_DISTANCE_FACTOR;

        $visitMax = self::VISIT_MAX;
        if ($type === 'family') $visitMax = min($visitMax, self::VISIT_MAX_FAMILY);
        if ($has('elderly', 'low_walking')) $visitMax = min($visitMax, self::VISIT_MAX_CAREFUL);

        $days = (int)$pref['trip_days'];
        $party = (int)$pref['party_size'];
        $tier = CostEstimationService::budgetTierDefaults((string)$pref['budget_tier'], (float)$pref['budget'], $days, $party);

        $originRegion = null;
        if (isset($pref['origin_lat'], $pref['origin_lng'])) $originRegion = self::region(null, (float)$pref['origin_lng']);

        return [
            'target' => isset($pref['stops_per_day']) ? max(1, (int)$pref['stops_per_day']) : self::PACE_TARGET[$pace],
            'rest' => self::PACE_REST_MIN[$pace] + ($careful ? self::REST_EXTRA_CAREFUL : 0) + ($type === 'group' ? self::REST_EXTRA_GROUP : 0),
            'start' => $start,
            'end' => $end,
            'day_km' => $dayKm,
            'hard_km' => max($dayKm * 3.0, $dayKm + 15.0),
            'leg_km' => self::LEG_LIMIT_KM[$transport] ?? 150.0,
            'visit_max' => $visitMax,
            'tags' => $tags,
            'step_free' => $has('wheelchair', 'avoid_stairs', 'step_free'),
            'avoid_heat' => $has('avoid_heat'),
            'elderly' => $has('elderly'),
            'careful' => $careful,
            'traveller_type' => $type,
            'transport' => $transport,
            'pace' => $pace,
            'visit_time' => (string)$pref['preferred_visit_time'],
            'dietary' => (string)$pref['dietary_preference'],
            'interests' => (array)$pref['interests'],
            'food_only' => (array)$pref['interests'] === ['food'],
            'states' => array_map('strtolower', (array)$pref['states']),
            'districts' => array_map('strtolower', (array)$pref['districts']),
            'origin_region' => $originRegion,
            'days' => $days,
            'party' => $party,
            'budget' => (float)$pref['budget'],
            'budget_tier' => (string)$pref['budget_tier'],
            'hotel_rate' => (float)$tier['hotel'],
            'meal_price' => (float)$tier['meal'],
        ];
    }

    /** How long a visit to this place lasts for this traveller. */
    public static function visitMinutes(array $place, array $profile): int
    {
        $minutes = (int)($place['visit_duration_min'] ?? 0);
        if ($minutes <= 0) $minutes = self::VISIT_DEFAULT;
        return max(self::VISIT_MIN, min(self::VISIT_MAX, (int)$profile['visit_max'], $minutes));
    }

    /** What a place costs per person: its entrance fee, otherwise its estimated cost. */
    public static function placeCost(array $place): float
    {
        $fee = (float)($place['entrance_fee'] ?? 0);
        $estimate = (float)($place['estimated_cost'] ?? 0);
        return max(0.0, $fee > 0 ? $fee : $estimate);
    }

    /** The itinerary item type for a place. */
    public static function itemType(array $place): string
    {
        $category = strtolower((string)($place['category'] ?? ''));
        return in_array($category, ['food', 'festival'], true) ? $category : 'attraction';
    }

    /** "east" for East Malaysia, "west" for Peninsular Malaysia. */
    public static function region(?string $state, float $lng): string
    {
        $state = strtolower(trim((string)$state));
        if ($state !== '') return in_array($state, self::EAST_STATES, true) ? 'east' : 'west';
        return $lng >= self::EAST_FROM_LNG ? 'east' : 'west';
    }

    /**
     * The rules that do not depend on the slot: state, region, festival dates, dietary,
     * step-free access and long outdoor visits. check() applies them too; callers may use
     * this alone to narrow a pool once.
     */
    public static function placeReason(array $place, array $profile): ?string
    {
        $category = strtolower((string)($place['category'] ?? ''));
        if ($profile['states'] && !in_array(strtolower(trim((string)($place['state'] ?? ''))), $profile['states'], true)) return 'STATE';
        if (!$profile['states'] && $profile['origin_region'] !== null
            && self::region((string)($place['state'] ?? ''), (float)($place['longitude'] ?? 0)) !== $profile['origin_region']) return 'REGION';
        if ($category === 'festival' && self::festivalDates($place) === null) return 'FESTIVAL_DATES';
        if ($category === 'food') {
            if ($profile['dietary'] === 'halal' && (int)($place['halal_status'] ?? 0) !== 1) return 'DIETARY';
            if ($profile['dietary'] === 'vegetarian' && !preg_match('/vegetarian|vegan/i', (string)($place['name'] ?? '') . ' ' . (string)($place['description'] ?? ''))) return 'DIETARY';
        }
        $outdoor = (int)($place['is_outdoor'] ?? 0) === 1;
        if ($profile['step_free'] && preg_match(self::STEP_FREE_BLOCKED, (string)($place['name'] ?? '') . ' ' . (string)($place['description'] ?? '') . ' ' . $category)) return 'STEP_FREE';
        if ($profile['elderly'] && $outdoor && (int)($place['visit_duration_min'] ?? self::VISIT_DEFAULT) > self::ELDERLY_LONG_VISIT_MIN) return 'LONG_OUTDOOR';
        return null;
    }

    /**
     * Can this place be visited in this slot? The one feasibility check of the planner.
     *
     * $slot keys:
     *   date        Y-m-d of the day
     *   arrive      earliest arrival, minutes after midnight (when the traveller can be there)
     *   leg_km      distance of the leg to the place
     *   leg_counts  false only for the leg from the trip's starting point on day 1, which the
     *               distance limits do not count
     *   day_km      km the day has already travelled (counted legs only)
     *   used        optional: place_id => true for places already in the itinerary
     *   trip_items  optional: the other planned items (item_type, estimated_cost) for the
     *               budget; without it the budget is not checked
     *   trip_km     km of all other planned legs;  reserve_km  km held back for legs not planned yet
     *
     * @return array{ok:bool,reason:?string,start:int,end:int,wait:int}
     */
    public static function check(array $place, array $slot, array $profile): array
    {
        $fail = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'start' => 0, 'end' => 0, 'wait' => 0];

        $placeId = (int)($place['place_id'] ?? 0);
        if ($placeId > 0 && isset($slot['used'][$placeId])) return $fail('DUPLICATE');
        $reason = self::placeReason($place, $profile);
        if ($reason !== null) return $fail($reason);
        $date = (string)$slot['date'];
        $dates = self::festivalDates($place);
        if ($dates !== null && ($date < $dates[0] || $date > $dates[1])) return $fail('FESTIVAL_DAY');

        $legKm = (float)$slot['leg_km'];
        if (!empty($slot['leg_counts'])) {
            if ($legKm > (float)$profile['leg_km']) return $fail('LEG_DISTANCE');
            if ((float)($slot['day_km'] ?? 0) + $legKm > (float)$profile['hard_km']) return $fail('DAY_DISTANCE');
        }

        // Time: the earliest start inside the day window and the opening hours, waiting for opening if needed.
        $visit = self::visitMinutes($place, $profile);
        $arrive = max((int)$slot['arrive'], (int)$profile['start']);
        $end = (int)$profile['end'];
        $hours = (string)($place['opening_hours'] ?? '');
        if ($profile['avoid_heat'] && (int)($place['is_outdoor'] ?? 0) === 1) {
            // Either over by 11:00 or begun after 16:00.
            $start = OpeningHours::earliestStart($hours, $date, $arrive, $visit, min($end, self::HEAT_FROM));
            $start ??= OpeningHours::earliestStart($hours, $date, max($arrive, self::HEAT_UNTIL + 1), $visit, $end);
            if ($start === null) {
                return $fail(OpeningHours::earliestStart($hours, $date, $arrive, $visit, $end) !== null ? 'HEAT' : self::timeReason($hours, $date, $arrive, $visit, $end));
            }
        } else {
            $start = OpeningHours::earliestStart($hours, $date, $arrive, $visit, $end);
            if ($start === null) return $fail(self::timeReason($hours, $date, $arrive, $visit, $end));
        }
        if ($start - $arrive > self::MAX_WAIT_MIN) return $fail('WAIT');

        if (array_key_exists('trip_items', $slot) && $slot['trip_items'] !== null) {
            $cost = self::placeCost($place);
            $items = $slot['trip_items'];
            $items[] = ['item_type' => self::itemType($place), 'estimated_cost' => $cost];
            $km = (float)($slot['trip_km'] ?? 0) + $legKm + (float)($slot['reserve_km'] ?? 0);
            if (!self::budgetAllows($profile, $items, $km, $cost)) return $fail('BUDGET');
        }

        return ['ok' => true, 'reason' => null, 'start' => $start, 'end' => $start + $visit, 'wait' => $start - $arrive];
    }

    /**
     * The budget rule. Paid places may take what the budget leaves after the essentials
     * (meals, hotel, transport) and a margin of BUDGET_MARGIN on them. When the essentials
     * alone are over the budget, only free places are added. The rule only grows stricter
     * as items and km are added, so a plan that keeps it keeps it in any order of its stops.
     *
     * @param array $items     every planned item including the one being added
     * @param float $km        every planned km including the new leg (and any reserve)
     * @param float $addedCost per-person cost of the item being added
     */
    public static function budgetAllows(array $profile, array $items, float $km, float $addedCost): bool
    {
        if ((float)$profile['budget'] <= 0) return true;
        $total = self::projectedTotal($profile, $items, $km);
        $paid = (float)$total['attraction_cost'];
        $essentials = (float)$total['total_cost'] - $paid;
        if ($paid + $essentials * (1 + self::BUDGET_MARGIN) <= (float)$profile['budget'] + 0.005) return true;
        // Over the budget: a free place is still added while nothing with a price is planned.
        if ($addedCost > 0) return false;
        foreach ($items as $item) {
            if (strtolower((string)($item['item_type'] ?? '')) !== 'hotel' && (float)($item['estimated_cost'] ?? 0) > 0) return false;
        }
        return true;
    }

    /** The trip total for these items and km, computed as ItineraryEditService::total() stores it. */
    public static function projectedTotal(array $profile, array $items, float $km): array
    {
        $service = new CostEstimationService((string)$profile['transport'], (int)$profile['days'], (float)$profile['budget'], (string)$profile['traveller_type'], (int)$profile['party']);
        return $service->calculate($items, $km, (float)$profile['hotel_rate'], self::MEALS_PER_DAY, (float)$profile['meal_price']);
    }

    /** [start, end] dates of a festival, or null when they are missing or not in order. */
    public static function festivalDates(array $place): ?array
    {
        $start = trim((string)($place['festival_start_date'] ?? ''));
        $end = trim((string)($place['festival_end_date'] ?? ''));
        if ($start === '' || $end === '' || $start === '0000-00-00' || $end === '0000-00-00' || $end < $start) return null;
        return [$start, $end];
    }

    /** The words for a reason code. */
    public static function reasonText(string $code): string
    {
        return self::REASONS[$code] ?? strtolower(str_replace('_', ' ', $code));
    }

    /** "HH:MM:00" for minutes after midnight. A time outside the day is a planning error, never clamped. */
    public static function sqlTime(int $minutes): string
    {
        if ($minutes < 0 || $minutes >= 1440) throw new RuntimeException('A planned time falls outside the day.');
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    /** "09:30:00" => 570, or null. */
    public static function minutesOf($time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$time, $m)) return null;
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function timeReason(string $hours, string $date, int $arrive, int $visit, int $end): string
    {
        $ranges = OpeningHours::rangesOn($hours, $date);
        if ($ranges === []) return 'CLOSED';
        if ($ranges === null || $arrive + $visit > $end) return 'DAY_END';
        return 'OPEN_HOURS';
    }

    private static function csv(string $text): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $text)), static fn($x) => $x !== '')));
    }

    private static function oneOf(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
