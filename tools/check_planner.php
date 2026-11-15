<?php
// tools/check_planner.php
// Automated check of the itinerary planner. It saves a set of fixed preferences for a test
// traveller, generates an itinerary from each one exactly as itinerary/generate_itinerary.php
// does, reads the stored result back and judges it by the rules the planner is meant to keep.
//
// The rules are written out below on their own. The checker never calls the planner's rule
// functions to decide what is right, so it stays a fair judge while the planner is rewritten.
//
//   php tools/check_planner.php                     every scenario, then delete what they made
//   php tools/check_planner.php --scenario=<name>   one scenario
//   php tools/check_planner.php --keep              keep the generated itineraries
//   php tools/check_planner.php --json              machine-readable output
//   php tools/check_planner.php --road              allow real road routing (public OSRM/Valhalla)
//   php tools/check_planner.php --pass=skew         only the second pass (see below); --pass=default only the first
//
// Two passes without --road. The first runs every scenario on estimates. The second runs the
// edit scenarios again with ROUTING_TEST_SKEW=1 (services/RouteService.php): every leg the
// planner measures then gets "road" figures that differ from the estimates it plans with (each
// pair of points scaled by its own factor of 0.8 to 1.6), as real routing does. Every check must
// hold on the stored plans there too. Its scenarios are named <scenario>@skew.
//
// Meal rows (itinerary_items.meal_slot set) are judged as lunch or dinner, not as stops: STOPS
// counts visits only, and MEAL checks that a day whose visits span a meal window has that meal
// inside its window, between two visits, at a suitable place or as a break that says so.
// WEATHER warns about outdoor stops on very wet days of scenarios with a forecast override.
// REQUESTS counts the routing requests of generation and of every edit (RouteService counts
// them, also those it would have made offline) against REQUEST_LIMITS.
//
// Edit scenarios (<base>__edits): the base scenario is generated, then changed through
// ItineraryEditService as the review page and the AI editor change it (replace a stop with the
// first suggestion, remove a stop, move a stop to another day, add a feasible place, plan a day
// again with more of one category and less of another, the nearest lodging as the hotel of
// night 1, optimise), and every check below is run again on the result. A day is given a start
// of its own (ItineraryEditService::setDayStart()); it must survive the hotel choice, an edit
// on that day, planning that day again and the optimiser (DAY_START), and START and WAIT judge
// that day from it. Day 1 is then planned again for an 85% chance of rain (a forecast
// override): no outdoor stop may stay while indoor places of the area are left (RAIN; a
// warning when the planner explains it or none is left). Then changes that
// break a rule (a place closed that day, on the avoid list, already in the trip, in another
// state) must be refused and leave every row as it was (REFUSE).
//
// DUPLICATE also fails when two places of a trip are one landmark under two names, and AVOID when
// a stop is the same landmark as a place to avoid, both by sameLandmarkJudged(): its own distance
// and word comparison, not the planner's. The scenario kl_couple_car_must_twin forces the case: the
// twin of a must-see place that the plan would otherwise visit must stay out, and adding it must be
// refused with SAME_LANDMARK (runTwinSteps()).
//
// Test database only: in Git Bash
//   DB_NAME=travel_itinerary_db_ctest /c/xampp/php/php.exe tools/check_planner.php
// Exit code: 0 no violation, 1 at least one violation, 2 refused to run.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

$options = getopt('', ['scenario:', 'keep', 'json', 'road', 'pass:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/check_planner.php [--scenario=<name>] [--keep] [--json] [--road] [--pass=default|skew]\n";
    exit(0);
}

// Without --road no routing server is asked: services/RouteService.php then estimates every
// distance and time from the straight line. Set before the environment file is read, which
// keeps a variable that is already set.
putenv('ROUTING_ESTIMATES_ONLY=' . (isset($options['road']) ? '0' : '1'));
putenv('ROUTING_TEST_SKEW=0');

/** Whether legs the planner measures get figures other than its estimates: real routing, or the skewed second pass. */
function measuredFigures(): bool
{
    return getenv('ROUTING_ESTIMATES_ONLY') !== '1' || getenv('ROUTING_TEST_SKEW') === '1';
}

// =====================================================================================
// THE PLANNER PIPELINE. The only calls into the planner: ItineraryGenerator::generate(), with
// the same trip fields as itinerary/generate_itinerary.php passes (the end point only when
// the traveller chose one), and in the edit scenarios the operations of ItineraryEditService
// (runEditSequence()). Their results are never used to decide what is right.
// =====================================================================================
require_once __DIR__ . '/../services/ItineraryGenerator.php';
require_once __DIR__ . '/../services/ItineraryEditService.php';
require_once __DIR__ . '/../services/HotelRecommendationService.php';

/**
 * Generate one itinerary as the generate page does.
 *
 * @param array{start_date:string,origin_name:string,origin_lat:float,origin_lng:float,end_name?:string,end_lat?:float,end_lng?:float} $trip
 * @return array{itinerary_id:int,notes:string[]} notes: steps that failed without stopping generation
 * @throws Throwable when generation fails (recorded as a GENERATE violation)
 */
function runPlannerPipeline(mysqli $conn, int $travellerId, int $preferenceId, array $trip): array
{
    $itineraryId = (new ItineraryGenerator($conn))->generate($travellerId, $preferenceId, $trip);
    return ['itinerary_id' => $itineraryId, 'notes' => []];
}
// =====================================================================================

// ---------------------------------------------------------------------------------------
// The intended rules, as numbers, written out here on their own. The notes in brackets say
// where each number was first read: CE = services/CostEstimationService.php and
// RS = services/RouteService.php (both current); RB and PI = the planner files before commit
// 6be8df7 (RuleBasedItineraryPlannerService.php and PlannerIntegrityService.php, since deleted
// and replaced by services/PlannerRules.php; see git history). The rules for the planner
// inputs of migration 20261115_planner_inputs.sql follow the task that introduced them.
// ---------------------------------------------------------------------------------------

/** Visit stops per day for each pace (RB:200). */
const PACE_TARGET = ['relaxed' => 3, 'normal' => 4, 'packed' => 5];
/** Rest between visits per pace (RB:201); +15 for family/elderly/low_walking (RB:205-206); +10 for group (RB:209). */
const PACE_REST_MIN = ['relaxed' => 90, 'normal' => 60, 'packed' => 30];
const REST_EXTRA_CAREFUL = 15;
const REST_EXTRA_GROUP = 10;
/** Day window per preferred visit time, minutes after midnight (RB:570-573). */
const VISIT_WINDOW = [
    'morning' => ['start' => 480, 'hard_end' => 1140],     // 08:00 - 19:00
    'afternoon' => ['start' => 720, 'hard_end' => 1290],   // 12:00 - 21:30
    'evening' => ['start' => 900, 'hard_end' => 1350],     // 15:00 - 22:30
    'any' => ['start' => 510, 'hard_end' => 1260],         // 08:30 - 21:00
];
/** Latest end for families and elderly or low-walking travellers: 20:00 (RB:205-207). */
const CAREFUL_HARD_END = 1200;
/** Recommended daily distance in km per transport and pace (RB:580-583). */
const DAILY_DISTANCE_KM = [
    'walking' => ['relaxed' => 3.0, 'normal' => 5.0, 'packed' => 7.0],
    'public_transport' => ['relaxed' => 15.0, 'normal' => 25.0, 'packed' => 35.0],
    'car' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
    'motorcycle' => ['relaxed' => 30.0, 'normal' => 45.0, 'packed' => 60.0],
];
/** low_walking, wheelchair or elderly: 75 % of the distance (RB:212-213). */
const CAREFUL_DISTANCE_FACTOR = 0.75;
/** Hard daily limit = max(3 x recommended, recommended + 15) (RB:591). The day 1 origin leg is not counted (RB:409-411). */
const HARD_DISTANCE_FACTOR = 3.0;
const HARD_DISTANCE_EXTRA_KM = 15.0;
/** ASSUMED, not in the code: one leg (other than the day 1 origin leg) longer than this is implausible. */
const LEG_LIMIT_KM = ['walking' => 8.0, 'public_transport' => 60.0, 'car' => 150.0, 'motorcycle' => 150.0];
/** Visit length: 30 to 240 min, at most 120 for families, 105 for elderly/low_walking (RB:670-672). */
const VISIT_MIN = 30;
const VISIT_MAX = 240;
const VISIT_MAX_FAMILY = 120;
const VISIT_MAX_CAREFUL = 105;
/** Accessibility rules (RB:636-645): heat window 11:00-16:00 on arrival, long outdoor visit over 150 min. */
const HEAT_FROM = 660;
const HEAT_TO = 960;
const ELDERLY_LONG_VISIT_MIN = 150;
const STEP_FREE_BLOCKED = '/gunung|mountain|hill|cave|stairs|waterfall|trail|hiking/i';
/** Cost rules (CE:16-21 rates per km, CE:45-47 party sizes, CE:76-89 vehicles and rooms, CE:119-136 tier defaults). */
const TRANSPORT_RATE = ['car' => 0.60, 'motorcycle' => 0.30, 'public_transport' => 0.15, 'walking' => 0.00];
const DEFAULT_PARTY = ['solo' => 1, 'couple' => 2, 'family' => 4, 'group' => 5];
const TIER_DEFAULTS = ['budget' => ['hotel' => 90.0, 'meal' => 12.0], 'normal' => ['hotel' => 150.0, 'meal' => 20.0], 'luxury' => ['hotel' => 280.0, 'meal' => 35.0]];
const MEALS_PER_DAY = 3;
const MEAL_SHARE = 0.25;      // CE:132
const HOTEL_SHARE = 0.30;     // CE:133
const MEAL_FLOOR = 6.0;       // CE:134
const HOTEL_FLOOR = 60.0;     // CE:135
/** Attraction budget = budget - essentials - 10 % of essentials (RB:224, PI:56). */
const ESSENTIAL_MARGIN = 0.10;
/** Estimates (RS:29-44): speeds in km/h, road factor, public transport walk hop and access minutes. */
const SPEED_KMH = ['car' => 60, 'motorcycle' => 55, 'public_transport' => 30, 'walking' => 5];
const ROAD_FACTOR = 1.3;
const TRANSIT_WALK_KM = 1.0;
const TRANSIT_ACCESS_MIN = 10;
/** ASSUMED tolerances for LEGS: stored km between 0.95 x straight - 0.05 and max(3 x straight, straight + 2); minutes between a third and three times the nominal time, +-2 / +15 min. */
const LEG_KM_LOW = 0.95;
const LEG_KM_HIGH = 3.0;
const LEG_KM_HIGH_EXTRA = 2.0;
const LEG_MIN_LOW = 1 / 3;
const LEG_MIN_HIGH = 3.0;
/** ASSUMED: money compared to the cent with this slack. */
const MONEY_SLACK = 0.05;
const BUDGET_SPLIT_SLACK = 1.0;
/** East Malaysia; everything else is Peninsular Malaysia. A point without a state: longitude 108 and above is east (RB:608-609 uses 109-120.5). */
const EAST_STATES = ['sabah', 'sarawak', 'labuan'];
const EAST_FROM_LNG = 108.0;
/** A place the traveller rated this or lower is not suggested again. */
const LOW_RATING = 2;
/** WEIGHTS is judged only when both categories have at least this many places per trip day in the chosen area. */
const WEIGHT_PLACES_PER_DAY = 4;
/** The rules of the task that added meals and the weather (migration 20261116_meal_slots.sql). */
/** Meal windows: earliest and latest start of lunch and dinner; meal length per pace; radius of a meal place per transport (road km). */
const MEAL_WINDOWS = ['lunch' => [690, 840], 'dinner' => [1080, 1230]];   // 11:30-14:00, 18:00-20:30
const MEAL_MIN = ['relaxed' => 75, 'normal' => 60, 'packed' => 45];
const MEAL_RADIUS_KM = ['walking' => 1.0, 'public_transport' => 2.0, 'car' => 5.0, 'motorcycle' => 5.0];
/** Longest wait for a place to open (time spent on a meal is not waiting). */
const MAX_WAIT_MIN = 60;
/** Only a day start or only a day end given, and the window shorter than 4 h: the other side moves to make it 8 h, inside 06:00-23:00. */
const SHORT_WINDOW_MIN = 240;
const WIDENED_WINDOW_MIN = 480;
const EARLIEST_START = 360;
const LATEST_END = 1380;
/** WEATHER warns about an outdoor stop on a day with at least this chance of rain. */
const RAIN_WARN_AT = 80;
/**
 * Bases (migration 20261119_trip_bases.sql), from the task that introduced them: a day followed
 * by a night ends at the base of that night (or its hotel) by the end of the day window, the leg
 * back counted in the day's distance; the next day starts there. The day the trip moves to
 * another area (its base is more than BASE_MOVED_KM from the base of the day before) starts at
 * the base of the night before, and that first leg, towards the new base, is not counted by the
 * distance limits, as the first leg of day 1 is not. A hotel row begins when the traveller can
 * have arrived, at most HOTEL_LATE_MIN later. A stay at the starting point has every base within
 * BASE_SAME_KM of it.
 */
const BASE_MOVED_KM = 0.5;
const BASE_SAME_KM = 0.05;
const HOTEL_LATE_MIN = 60;
/**
 * PLAN: the plan of each generation scenario before the trip layout (bases, day groups and the
 * day search) was introduced, on estimates (the first pass of the run at commit e598fae with
 * this tool's scenarios): stops and km of the whole trip (tripKm()). A new plan with fewer stops,
 * or with more than PLAN_KM_SHARE more km and at least PLAN_KM_EXTRA more, is a warning.
 */
const PLAN_KM_SHARE = 0.25;
const PLAN_KM_EXTRA = 5.0;
const RECORDED_PLANS = [
    'kl_couple_car_normal' => [12, 13.4], 'melaka_solo_walking_relaxed_tight' => [3, 3.3], 'penang_group_transit_packed_festival' => [12, 40.0],
    'sabah_family_motorcycle_normal' => [12, 80.9], 'johor_solo_car_halal_food_evening' => [3, 47.6], 'johor_couple_car_packed_districts' => [15, 133.9],
    'kedah_couple_transit_vegetarian' => [3, 0.7], 'selangor_couple_car_elderly_wheelchair' => [9, 22.6], 'no_state_group_car_7days_generous' => [28, 45.4],
    'perak_solo_motorcycle_packed_7days_tight' => [28, 126.7], 'pahang_solo_walking_packed_evening_district' => [3, 4.4],
    'kl_selangor_family_transit_tight' => [12, 15.6], 'sarawak_solo_transit_evening' => [3, 0.6], 'kl_family_car_packed_explicit_window_3_stops' => [6, 14.7],
    'melaka_couple_with_child_and_senior' => [8, 88.1], 'selangor_solo_car_tags_column' => [6, 16.7], 'kl_couple_car_museum_loved_heritage_little' => [8, 17.3],
    'kl_couple_car_must_and_avoid' => [12, 41.2], 'penang_group_transit_must_festival' => [12, 37.7], 'kl_solo_car_end_at_klia' => [7, 10.7],
    'melaka_solo_walking_end_at_ayer_keroh' => [3, 1.8], 'kl_couple_car_learns_from_ratings' => [8, 23.7], 'johor_couple_car_relaxed_meals_at_places' => [6, 8.5],
    'kl_couple_car_rain_indoor_first' => [8, 10.4], 'pahang_couple_car_rain_highlands' => [4, 17.4], 'kl_solo_car_early_start_wait_limit' => [4, 8.0],
    'kl_couple_car_packed_heritage_loved_culture_little' => [10, 17.4], 'melaka_solo_car_start_only_evening' => [2, 0.8],
    'kl_family_car_must_opens_late_end_klia' => [8, 12.2], 'penang_solo_walking_end_only_morning' => [2, 1.3], 'kl_couple_car_must_twin' => [11, 30.5],
    'kl_couple_car_stay_at_start' => [12, 13.4], 'penang_kl_couple_car_5days_two_states' => [20, 67.7], 'pahang_couple_car_7days_large_state' => [28, 316.3],
];

/**
 * The same landmark under two names (DUPLICATE), judged by sameLandmarkJudged() on its own
 * terms, from the task that introduced the rule: two rows within TWIN_NEAR_M metres whose
 * names are clearly the same, or within TWIN_VERY_NEAR_M metres whose names share most words.
 */
const TWIN_NEAR_M = 150.0;
const TWIN_VERY_NEAR_M = 60.0;
const TWIN_VERY_NEAR_DICE = 0.7;
const TWIN_STOP_WORDS = ['the', 'of', 'and', 'a', 'an', 'at', 'in', 'de', 'dan', 'di', 'on', 'for'];
/** Malay words of place names and the English word they are read as. */
const TWIN_WORDS = ['muzium' => 'museum', 'masjid' => 'mosque', 'menara' => 'tower', 'dataran' => 'square', 'medan' => 'square',
                    'bangunan' => 'building', 'negara' => 'national', 'tugu' => 'monument', 'galeri' => 'gallery', 'kuil' => 'temple',
                    'tokong' => 'temple', 'taman' => 'park', 'istana' => 'palace', 'pantai' => 'beach', 'bukit' => 'hill',
                    'sungai' => 'river', 'pasar' => 'market', 'jalan' => 'street', 'rumah' => 'house', 'kampung' => 'village',
                    'kg' => 'village', 'budaya' => 'culture', 'cultural' => 'culture', 'kota' => 'fort', 'fortress' => 'fort', 'gereja' => 'church'];

/**
 * Opening hours written as words, without clock times: approximate hours, taken from the
 * task that introduced them (not from services/OpeningHours.php). [pattern, unless pattern,
 * ranges in minutes, weekdays (0 = Monday) or null for every day]; the first match wins. Any
 * other text ("Open area", "Exterior viewing", "Verify before visit") stays unknown.
 */
const DESCRIBED_HOURS = [
    ['/office hours/', null, [[9 * 60, 17 * 60]], [0, 1, 2, 3, 4]],
    ['/lunch hours/', null, [[11 * 60, 15 * 60]], null],
    ['/meal hours/', null, [[11 * 60, 15 * 60], [18 * 60, 22 * 60]], null],
    ['/(morning|daytime|day time) to evening/', null, [[8 * 60, 20 * 60]], null],
    ['/\b(evening|night)\b/', '/morning|daytime|day time/', [[17 * 60, 23 * 60 + 59]], null],
    ['/daytime|day time/', null, [[8 * 60, 18 * 60]], null],
    ['/\bmorning\b/', null, [[6 * 60, 12 * 60]], null],
    ['/(business|shop) hours/', null, [[10 * 60, 22 * 60]], null],
];

// ---------------------------------------------------------------------------------------
// Scenarios. Origins are real city centres or transport hubs of the chosen area.
// ---------------------------------------------------------------------------------------
const SCENARIOS = [
    'kl_couple_car_normal' => [
        'pref' => ['trip_days' => 3, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'melaka_solo_walking_relaxed_tight' => [
        'pref' => ['trip_days' => 1, 'budget' => 120, 'budget_tier' => 'budget', 'transport_type' => 'walking', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'morning', 'accessibility_needs' => '',
            'interests' => 'heritage,museum', 'preferred_states' => 'Melaka', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'Dutch Square (Stadthuys)', 'origin_lat' => 2.1942, 'origin_lng' => 102.2489],
    ],
    'penang_group_transit_packed_festival' => [
        'pref' => ['trip_days' => 3, 'budget' => 4000, 'budget_tier' => 'normal', 'transport_type' => 'public_transport', 'traveller_type' => 'group', 'party_size' => 6,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'afternoon', 'accessibility_needs' => '',
            'interests' => 'festival,culture,food', 'preferred_states' => 'Penang', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-08-01', 'origin_name' => 'Komtar bus hub, George Town', 'origin_lat' => 5.4145, 'origin_lng' => 100.3292],
    ],
    'sabah_family_motorcycle_normal' => [
        'pref' => ['trip_days' => 3, 'budget' => 2500, 'budget_tier' => 'normal', 'transport_type' => 'motorcycle', 'traveller_type' => 'family', 'party_size' => 4,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'nature,culture,museum', 'preferred_states' => 'Sabah', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-12', 'origin_name' => 'Padang Merdeka, Kota Kinabalu', 'origin_lat' => 5.9804, 'origin_lng' => 116.0735],
    ],
    'johor_solo_car_halal_food_evening' => [
        'pref' => ['trip_days' => 1, 'budget' => 300, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'halal', 'preferred_visit_time' => 'evening', 'accessibility_needs' => '',
            'interests' => 'food,culture', 'preferred_states' => 'Johor', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-14', 'origin_name' => 'JB Sentral', 'origin_lat' => 1.4628, 'origin_lng' => 103.7650],
    ],
    'johor_couple_car_packed_districts' => [
        'pref' => ['trip_days' => 3, 'budget' => 2000, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'morning', 'accessibility_needs' => '',
            'interests' => 'heritage,nature,shopping', 'preferred_states' => 'Johor', 'preferred_districts' => 'Johor Bahru,Kulai'],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'JB Sentral', 'origin_lat' => 1.4628, 'origin_lng' => 103.7650],
    ],
    'kedah_couple_transit_vegetarian' => [
        'pref' => ['trip_days' => 1, 'budget' => 400, 'budget_tier' => 'normal', 'transport_type' => 'public_transport', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'vegetarian', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'food,heritage', 'preferred_states' => 'Kedah', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'Alor Setar Tower', 'origin_lat' => 6.1218, 'origin_lng' => 100.3676],
    ],
    'selangor_couple_car_elderly_wheelchair' => [
        'pref' => ['trip_days' => 3, 'budget' => 1800, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any',
            'accessibility_needs' => 'Travelling with an elderly parent in a wheelchair, avoid heat | AI: elderly,wheelchair,low_walking,avoid_heat,step_free,indoor_preferred',
            'interests' => 'culture,museum,nature', 'preferred_states' => 'Selangor', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-12', 'origin_name' => 'Shah Alam city centre', 'origin_lat' => 3.0733, 'origin_lng' => 101.5185],
    ],
    'no_state_group_car_7days_generous' => [
        'pref' => ['trip_days' => 7, 'budget' => 20000, 'budget_tier' => 'luxury', 'transport_type' => 'car', 'traveller_type' => 'group', 'party_size' => 5,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,nature,shopping', 'preferred_states' => '', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'perak_solo_motorcycle_packed_7days_tight' => [
        'pref' => ['trip_days' => 7, 'budget' => 500, 'budget_tier' => 'budget', 'transport_type' => 'motorcycle', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'afternoon', 'accessibility_needs' => '',
            'interests' => 'festival,heritage,culture', 'preferred_states' => 'Perak', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-19', 'origin_name' => 'Ipoh railway station', 'origin_lat' => 4.5975, 'origin_lng' => 101.0753],
    ],
    'pahang_solo_walking_packed_evening_district' => [
        'pref' => ['trip_days' => 1, 'budget' => 250, 'budget_tier' => 'normal', 'transport_type' => 'walking', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'evening', 'accessibility_needs' => '',
            'interests' => 'nature,shopping,culture', 'preferred_states' => 'Pahang', 'preferred_districts' => 'Cameron Highlands'],
        'trip' => ['start_date' => '2026-12-14', 'origin_name' => 'Tanah Rata town centre', 'origin_lat' => 4.4700, 'origin_lng' => 101.3770],
    ],
    'kl_selangor_family_transit_tight' => [
        'pref' => ['trip_days' => 3, 'budget' => 600, 'budget_tier' => 'budget', 'transport_type' => 'public_transport', 'traveller_type' => 'family', 'party_size' => 4,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'morning', 'accessibility_needs' => '',
            'interests' => 'museum,shopping,nature', 'preferred_states' => 'Kuala Lumpur,Selangor', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'sarawak_solo_transit_evening' => [
        'pref' => ['trip_days' => 1, 'budget' => 500, 'budget_tier' => 'normal', 'transport_type' => 'public_transport', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'evening', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,food', 'preferred_states' => 'Sarawak', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-12', 'origin_name' => 'Kuching Waterfront', 'origin_lat' => 1.5590, 'origin_lng' => 110.3450],
    ],

    // ---- Planner inputs (migration 20261115_planner_inputs.sql) ----------------------------
    // Optional scenario keys: 'weights' (interest => 1|2|3), 'must' and 'avoid' (place selectors),
    // 'ratings' (selectors with a 'rating'). A selector is ['name' => exact name],
    // ['baseline' => n] (the n-th stop the same preference gets without places and ratings) or
    // ['baseline_first' => n] (avoid only: its first n stops that are not must-see places).
    'kl_family_car_packed_explicit_window_3_stops' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'family', 'party_size' => 4,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum,nature', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => '',
            'day_start_time' => '10:00:00', 'day_end_time' => '21:30:00', 'stops_per_day' => 3],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'melaka_couple_with_child_and_senior' => [
        'pref' => ['trip_days' => 2, 'budget' => 1200, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 4,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,museum,culture,nature', 'preferred_states' => 'Melaka', 'preferred_districts' => '',
            'adults' => 2, 'children' => 1, 'seniors' => 1],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'Dutch Square (Stadthuys)', 'origin_lat' => 2.1942, 'origin_lng' => 102.2489],
    ],
    'selangor_solo_car_tags_column' => [
        'pref' => ['trip_days' => 2, 'budget' => 1000, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any',
            'accessibility_needs' => 'Fine with long days | AI: elderly,low_walking',
            'interests' => 'nature,culture,heritage', 'preferred_states' => 'Selangor', 'preferred_districts' => '',
            'accessibility_tags' => 'avoid_heat,wheelchair'],
        'trip' => ['start_date' => '2026-12-12', 'origin_name' => 'Shah Alam city centre', 'origin_lat' => 3.0733, 'origin_lng' => 101.5185],
    ],
    'kl_couple_car_museum_loved_heritage_little' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'weights' => ['museum' => 3, 'heritage' => 1],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'kl_couple_car_must_and_avoid' => [
        'pref' => ['trip_days' => 3, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'must' => [['name' => 'Islamic Arts Museum Malaysia (IAMM)'], ['name' => 'Thean Hou Temple'], ['name' => 'Masjid Saidina Othman Ibn Affan'],
                   ['name' => 'George Town Festival']],
        'avoid' => [['baseline_first' => 4]],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'penang_group_transit_must_festival' => [
        'pref' => ['trip_days' => 3, 'budget' => 4000, 'budget_tier' => 'normal', 'transport_type' => 'public_transport', 'traveller_type' => 'group', 'party_size' => 6,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'afternoon', 'accessibility_needs' => '',
            'interests' => 'culture,heritage', 'preferred_states' => 'Penang', 'preferred_districts' => ''],
        'must' => [['name' => 'George Town Festival'], ['name' => 'Rainforest World Music Festival']],
        'trip' => ['start_date' => '2026-08-01', 'origin_name' => 'Komtar bus hub, George Town', 'origin_lat' => 5.4145, 'origin_lng' => 100.3292],
    ],
    'kl_solo_car_end_at_klia' => [
        'pref' => ['trip_days' => 2, 'budget' => 1000, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => '',
            'day_end_time' => '17:00:00'],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865,
                   'end_name' => 'KLIA Terminal 1', 'end_lat' => 2.7456, 'end_lng' => 101.7072],
    ],
    'melaka_solo_walking_end_at_ayer_keroh' => [
        'pref' => ['trip_days' => 1, 'budget' => 300, 'budget_tier' => 'normal', 'transport_type' => 'walking', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,museum', 'preferred_states' => 'Melaka', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'Dutch Square (Stadthuys)', 'origin_lat' => 2.1942, 'origin_lng' => 102.2489,
                   'end_name' => 'Zoo Melaka, Ayer Keroh', 'end_lat' => 2.2766, 'end_lng' => 102.2992],
    ],
    'kl_couple_car_learns_from_ratings' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'ratings' => [['baseline' => 0, 'rating' => 1], ['baseline' => 1, 'rating' => 2],
                      ['name' => 'Kampung Baru', 'rating' => 5], ['name' => 'Dataran Merdeka', 'rating' => 5],
                      ['name' => 'Sultan Abdul Samad Building', 'rating' => 5], ['name' => 'National Mosque of Malaysia', 'rating' => 4],
                      ['name' => 'Masjid India', 'rating' => 5]],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],

    // ---- Meals, weather and three rule repairs (migration 20261116_meal_slots.sql) --------
    // Optional trip key 'forecast' (date => chance of rain 0 to 100): the planner's forecast
    // override, so no weather service is asked.
    'johor_couple_car_relaxed_meals_at_places' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'relaxed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,culture,museum', 'preferred_states' => 'Johor', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-07', 'origin_name' => 'JB Sentral', 'origin_lat' => 1.4628, 'origin_lng' => 103.7650],
    ],
    'kl_couple_car_rain_indoor_first' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'nature,museum,culture,heritage', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865,
                   'forecast' => ['2026-12-05' => 85, '2026-12-06' => 65]],
    ],
    'pahang_couple_car_rain_highlands' => [
        'pref' => ['trip_days' => 1, 'budget' => 800, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'nature,culture', 'preferred_states' => 'Pahang', 'preferred_districts' => 'Cameron Highlands'],
        'trip' => ['start_date' => '2026-12-14', 'origin_name' => 'Tanah Rata town centre', 'origin_lat' => 4.4700, 'origin_lng' => 101.3770,
                   'forecast' => ['2026-12-14' => 90]],
    ],
    'kl_solo_car_early_start_wait_limit' => [
        'pref' => ['trip_days' => 1, 'budget' => 600, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'museum,culture,heritage', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => '',
            'day_start_time' => '06:30:00'],
        'trip' => ['start_date' => '2026-12-08', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'kl_couple_car_packed_heritage_loved_culture_little' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'packed', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,culture,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'weights' => ['heritage' => 3, 'culture' => 1],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
    ],
    'melaka_solo_car_start_only_evening' => [
        'pref' => ['trip_days' => 1, 'budget' => 400, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,culture,shopping', 'preferred_states' => 'Melaka', 'preferred_districts' => '',
            'day_start_time' => '18:00:00'],
        'trip' => ['start_date' => '2026-12-11', 'origin_name' => 'Dutch Square (Stadthuys)', 'origin_lat' => 2.1942, 'origin_lng' => 102.2489],
    ],
    // ---- Planning a day again on road figures that differ from the estimates ---------------
    // Optional keys: 'regenerate_must_day' (more and less for planning the day of the first
    // must-see place again) and 'rain_every_day' (a chance of rain for every day of the trip,
    // after which every day is planned again for the rain).
    'kl_family_car_must_opens_late_end_klia' => [
        'pref' => ['trip_days' => 2, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'family', 'party_size' => 4,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => '',
            'day_start_time' => '09:00:00', 'day_end_time' => '19:00:00'],
        'must' => [['name' => 'Art House Gallery Museum of Ethnic Arts']],   // opens 10:30, after the day starts
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865,
                   'end_name' => 'KLIA Terminal 1', 'end_lat' => 2.7456, 'end_lng' => 101.7072],
        'regenerate_must_day' => ['more' => ['museum'], 'less' => ['culture']],
        'rain_every_day' => 95,
    ],
    'penang_solo_walking_end_only_morning' => [
        'pref' => ['trip_days' => 1, 'budget' => 300, 'budget_tier' => 'normal', 'transport_type' => 'walking', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,culture', 'preferred_states' => 'Penang', 'preferred_districts' => '',
            'day_end_time' => '11:00:00'],
        'trip' => ['start_date' => '2026-12-09', 'origin_name' => 'Komtar bus hub, George Town', 'origin_lat' => 5.4145, 'origin_lng' => 100.3292],
    ],
    // ---- One landmark under two names --------------------------------------------------------
    // Optional key 'twin' (a selector): another row of the first must-see place's landmark. The
    // same preference without places plans the twin (else SETUP: the case is not forced); with
    // the must-see place the twin must stay out (DUPLICATE), and adding it to the must-see
    // place's day, or putting it in place of a stop of another day, must be refused with
    // SAME_LANDMARK and change no row (REFUSE, runTwinSteps()).
    'kl_couple_car_must_twin' => [
        // The preference of kl_couple_car_normal, whose plan visits "Bangunan Sultan Abdul Samad"
        // and has lunch at "Menara Kuala Lumpur": with the other row of each as a must-see place,
        // neither may come back (the second one as a meal place).
        'pref' => ['trip_days' => 3, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'must' => [['name' => 'Sultan Abdul Samad Building'], ['name' => 'KL Tower (Menara Kuala Lumpur)']],
        'twin' => ['name' => 'Bangunan Sultan Abdul Samad'],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865],
        'regenerate_must_day' => ['more' => ['heritage'], 'less' => ['museum']],
    ],
    // ---- Where the traveller sleeps: bases, day groups and the day search ---------------------
    // Optional trip key 'stay_at_start' (bool): the starting point is the base of every night.
    'kl_couple_car_stay_at_start' => [
        'pref' => ['trip_days' => 3, 'budget' => 1500, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,museum', 'preferred_states' => 'Kuala Lumpur', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'KL Sentral', 'origin_lat' => 3.1340, 'origin_lng' => 101.6865, 'stay_at_start' => true],
    ],
    'penang_kl_couple_car_5days_two_states' => [
        'pref' => ['trip_days' => 5, 'budget' => 4000, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'culture,heritage,food', 'preferred_states' => 'Penang,Kuala Lumpur', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-05', 'origin_name' => 'Komtar bus hub, George Town', 'origin_lat' => 5.4145, 'origin_lng' => 100.3292],
    ],
    'pahang_couple_car_7days_large_state' => [
        'pref' => ['trip_days' => 7, 'budget' => 5000, 'budget_tier' => 'normal', 'transport_type' => 'car', 'traveller_type' => 'couple', 'party_size' => 2,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'nature,culture,heritage', 'preferred_states' => 'Pahang', 'preferred_districts' => ''],
        'trip' => ['start_date' => '2026-12-12', 'origin_name' => 'Kuantan city centre', 'origin_lat' => 3.8077, 'origin_lng' => 103.3260],
    ],
];

/**
 * Routing requests each call of the planner may make (REQUESTS): an edit measures the legs it
 * needs in at most two route requests; setting a day's start moves no stop and asks nothing;
 * generation and the optimiser ask for one table per trip (generation with --road one more per
 * day that had to be repaired; the optimiser two more when it left a day without stops). Planning
 * the rainy day again is one day planned again. A refused change may still measure (refuse).
 */
const REQUEST_LIMITS = ['generate' => 1, 'suggest' => 2, 'replace' => 2, 'remove' => 2, 'move' => 2, 'can_add' => 2, 'add' => 2,
                        'regenerate' => 2, 'day_start' => 0, 'hotel' => 2, 'optimise' => 1, 'replan_rain' => 2, 'refuse' => 2];

/** The base scenarios that also run as edit scenarios (<base>__edits). */
const EDIT_BASES = ['kl_couple_car_normal', 'sabah_family_motorcycle_normal', 'penang_group_transit_packed_festival',
                    'johor_couple_car_relaxed_meals_at_places', 'kl_couple_car_must_and_avoid', 'kl_family_car_must_opens_late_end_klia',
                    'kl_couple_car_must_twin', 'kl_couple_car_stay_at_start', 'penang_kl_couple_car_5days_two_states'];

/** Every scenario: the fixed ones and the edit scenarios built on EDIT_BASES. */
function allScenarios(): array
{
    $all = SCENARIOS;
    foreach (EDIT_BASES as $base) $all[$base . '__edits'] = SCENARIOS[$base] + ['edits' => true];
    return $all;
}

const TEST_EMAIL = 'planner-check@example.test';

// ---------------------------------------------------------------------------------------
// Connections: one for the pipeline (it runs its own transaction) and one for the checker,
// which reads only what was committed.
// ---------------------------------------------------------------------------------------
require __DIR__ . '/../config/db_connect.php';
$pipelineConn = $conn;
unset($conn);
require __DIR__ . '/../config/db_connect.php';
$checkConn = $conn;
unset($conn);

foreach ([$pipelineConn, $checkConn] as $c) {
    $dbName = (string)($c->query('SELECT DATABASE()')->fetch_row()[0] ?? '');
    if (!str_ends_with($dbName, '_ctest')) {
        fwrite(STDERR, "Refusing to run: the connected database \"{$dbName}\" is not a test copy (its name must end in _ctest).\n"
            . "Run with DB_NAME=travel_itinerary_db_ctest.\n");
        exit(2);
    }
}

$selected = allScenarios();
if (isset($options['scenario'])) {
    $name = (string)$options['scenario'];
    if (!isset($selected[$name])) {
        fwrite(STDERR, "Unknown scenario \"{$name}\". Scenarios: " . implode(', ', array_keys($selected)) . "\n");
        exit(2);
    }
    $selected = [$name => $selected[$name]];
}

set_time_limit(0);
$roadRouting = isset($options['road']);
$travellerId = ensureTestTraveller($checkConn);
$results = [];
$pass = (string)($options['pass'] ?? 'both');
if ($pass !== 'skew') {
    foreach ($selected as $name => $scenario) {
        $results[] = runScenario($pipelineConn, $checkConn, $travellerId, $name, $scenario, isset($options['keep']));
    }
}
// The second pass: the edit scenarios again, with road figures that differ from the estimates.
if ($pass !== 'default' && !$roadRouting) {
    putenv('ROUTING_TEST_SKEW=1');
    foreach ($selected as $name => $scenario) {
        if (!empty($scenario['edits'])) $results[] = runScenario($pipelineConn, $checkConn, $travellerId, $name . '@skew', $scenario, isset($options['keep']));
    }
    putenv('ROUTING_TEST_SKEW=0');
}
$after = testTravellerCounts($checkConn, $travellerId);
report($results, $dbName, isset($options['road']), isset($options['json']), $after);

$violations = 0;
foreach ($results as $r) $violations += count(array_filter($r['findings'], static fn($f) => $f['severity'] === 'violation'));
exit($violations > 0 ? 1 : 0);

// =======================================================================================

function ensureTestTraveller(mysqli $conn): int
{
    $stmt = $conn->prepare('SELECT traveller_id FROM travellers WHERE email = ? LIMIT 1');
    $email = TEST_EMAIL;
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['traveller_id'];

    // Inactive, with a password nobody knows: the account cannot be used to sign in.
    $name = 'Planner Check (automated test)';
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO travellers (full_name, email, password_hash, is_active) VALUES (?, ?, ?, 0)');
    $stmt->bind_param('sss', $name, $email, $hash);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function testTravellerCounts(mysqli $conn, int $travellerId): array
{
    $count = static function (string $table) use ($conn, $travellerId): int {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM {$table} WHERE traveller_id = ?");
        $stmt->bind_param('i', $travellerId);
        $stmt->execute();
        $n = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $n;
    };
    return ['traveller_id' => $travellerId, 'itineraries' => $count('itineraries'), 'preferences' => $count('traveller_preferences'),
            'ratings' => $count('ratings_reviews')];
}

function createPreference(mysqli $conn, int $travellerId, array $p): int
{
    $stmt = $conn->prepare('
        INSERT INTO traveller_preferences
            (traveller_id, trip_days, budget, budget_tier, transport_type, traveller_type, party_size, travel_pace,
             dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts,
             day_start_time, day_end_time, stops_per_day, adults, children, seniors, accessibility_tags)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $dayStart = $p['day_start_time'] ?? null;
    $dayEnd = $p['day_end_time'] ?? null;
    $stops = $p['stops_per_day'] ?? null;
    $adults = $p['adults'] ?? null;
    $children = $p['children'] ?? null;
    $seniors = $p['seniors'] ?? null;
    $tags = $p['accessibility_tags'] ?? null;
    $stmt->bind_param('iidsssisssssssssiiiis', $travellerId, $p['trip_days'], $p['budget'], $p['budget_tier'], $p['transport_type'], $p['traveller_type'],
        $p['party_size'], $p['travel_pace'], $p['dietary_preference'], $p['preferred_visit_time'], $p['accessibility_needs'], $p['interests'],
        $p['preferred_states'], $p['preferred_districts'], $dayStart, $dayEnd, $stops, $adults, $children, $seniors, $tags);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

/** Interest weights and must-see / avoid places of a preference, stored as the preference form stores them. */
function addPreferenceInputs(mysqli $conn, int $preferenceId, array $weights, array $must, array $avoid): void
{
    if ($weights) {
        $stmt = $conn->prepare('INSERT INTO traveller_preference_interests (preference_id, interest_id, weight) SELECT ?, interest_id, ? FROM travel_interests WHERE interest_code = ?');
        foreach ($weights as $code => $weight) {
            $weight = (int)$weight;
            $code = (string)$code;
            $stmt->bind_param('iis', $preferenceId, $weight, $code);
            $stmt->execute();
        }
        $stmt->close();
    }
    $stmt = $conn->prepare('INSERT INTO traveller_preference_places (preference_id, place_id, wish) VALUES (?,?,?)');
    foreach (['must' => $must, 'avoid' => $avoid] as $wish => $ids) {
        foreach ($ids as $placeId) {
            $stmt->bind_param('iis', $preferenceId, $placeId, $wish);
            $stmt->execute();
        }
    }
    $stmt->close();
}

/** The test traveller's own ratings: none, or exactly the given place_id => rating. */
function setTestRatings(mysqli $conn, int $travellerId, array $ratings): void
{
    $stmt = $conn->prepare('DELETE FROM ratings_reviews WHERE traveller_id = ?');
    $stmt->bind_param('i', $travellerId);
    $stmt->execute();
    $stmt->close();
    if (!$ratings) return;
    $stmt = $conn->prepare("INSERT INTO ratings_reviews (place_id, traveller_id, rating, review_text) VALUES (?,?,?,'planner check')");
    foreach ($ratings as $placeId => $rating) {
        $stmt->bind_param('iii', $placeId, $travellerId, $rating);
        $stmt->execute();
    }
    $stmt->close();
}

/** The place_ids of the visit stops of an itinerary, in visiting order. */
function storedStops(mysqli $conn, int $itineraryId): array
{
    $stmt = $conn->prepare("SELECT place_id FROM itinerary_items WHERE itinerary_id = ? AND place_id IS NOT NULL AND item_type IN ('attraction','food','festival') AND meal_slot IS NULL ORDER BY day_no, sequence_no");
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $ids = array_map(static fn($r) => (int)$r[0], $stmt->get_result()->fetch_all());
    $stmt->close();
    return $ids;
}

/** One place selector to a place_id, or null. */
function resolvePlace(mysqli $conn, array $selector, array $baseline): ?int
{
    if (isset($selector['baseline'])) return $baseline[(int)$selector['baseline']] ?? null;
    if (isset($selector['name'])) {
        $stmt = $conn->prepare('SELECT place_id FROM cultural_places WHERE name = ? ORDER BY place_id LIMIT 1');
        $name = (string)$selector['name'];
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? (int)$row[0] : null;
    }
    return null;
}

/**
 * The scenario's place inputs as place ids. A baseline (the plan of the same preference without
 * places and ratings) is generated first when a selector needs it, and deleted again.
 *
 * @return array{must:int[],avoid:int[],ratings:array<int,int>,errors:string[]}
 */
function resolveScenarioPlaces(mysqli $pipelineConn, mysqli $checkConn, int $travellerId, array $scenario): array
{
    $out = ['must' => [], 'avoid' => [], 'ratings' => [], 'errors' => []];
    $selectors = array_merge($scenario['must'] ?? [], $scenario['avoid'] ?? [], $scenario['ratings'] ?? []);
    if (isset($scenario['twin'])) $selectors[] = ['baseline' => 0];   // the twin must be in the baseline
    $baseline = [];
    foreach ($selectors as $sel) {
        if (isset($sel['baseline']) || isset($sel['baseline_first'])) {
            $preferenceId = createPreference($checkConn, $travellerId, $scenario['pref']);
            try {
                $baseline = storedStops($checkConn, runPlannerPipeline($pipelineConn, $travellerId, $preferenceId, $scenario['trip'])['itinerary_id']);
            } catch (Throwable $e) {
                $out['errors'][] = 'baseline failed: ' . $e->getMessage();
            }
            deleteScenarioData($checkConn, $travellerId, $preferenceId);
            break;
        }
    }
    foreach ($scenario['must'] ?? [] as $sel) {
        $id = resolvePlace($checkConn, $sel, $baseline);
        if ($id === null) $out['errors'][] = 'must-see place not found: ' . json_encode($sel);
        elseif (!in_array($id, $out['must'], true)) $out['must'][] = $id;
    }
    foreach ($scenario['avoid'] ?? [] as $sel) {
        if (isset($sel['baseline_first'])) {
            $left = array_values(array_diff($baseline, $out['must']));
            $picked = array_slice($left, 0, (int)$sel['baseline_first']);
            if (count($picked) < (int)$sel['baseline_first']) $out['errors'][] = 'baseline has too few stops to avoid';
            foreach ($picked as $id) if (!in_array($id, $out['avoid'], true)) $out['avoid'][] = $id;
            continue;
        }
        $id = resolvePlace($checkConn, $sel, $baseline);
        if ($id === null || in_array($id, $out['must'], true)) $out['errors'][] = 'avoid place not usable: ' . json_encode($sel);
        elseif (!in_array($id, $out['avoid'], true)) $out['avoid'][] = $id;
    }
    foreach ($scenario['ratings'] ?? [] as $sel) {
        $id = resolvePlace($checkConn, $sel, $baseline);
        if ($id === null) $out['errors'][] = 'rated place not found: ' . json_encode($sel);
        else $out['ratings'][$id] = (int)$sel['rating'];
    }
    if (isset($scenario['twin'])) {
        $id = resolvePlace($checkConn, $scenario['twin'], $baseline);
        if ($id === null) $out['errors'][] = 'twin not found: ' . json_encode($scenario['twin']);
        elseif (!in_array($id, $baseline, true)) $out['errors'][] = 'the twin ' . json_encode($scenario['twin']) . ' is not planned without the must-see place, so the scenario does not force the case';
        else $out['twin'] = $id;
    }
    return $out;
}

function deleteScenarioData(mysqli $conn, int $travellerId, int $preferenceId): void
{
    // Items and share links go with their itinerary (ON DELETE CASCADE).
    $stmt = $conn->prepare('DELETE FROM itineraries WHERE traveller_id = ? AND preference_id = ?');
    $stmt->bind_param('ii', $travellerId, $preferenceId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('DELETE FROM traveller_preferences WHERE traveller_id = ? AND preference_id = ?');
    $stmt->bind_param('ii', $travellerId, $preferenceId);
    $stmt->execute();
    $stmt->close();
}

function runScenario(mysqli $pipelineConn, mysqli $checkConn, int $travellerId, string $name, array $scenario, bool $keep): array
{
    $result = ['scenario' => $name, 'itinerary_id' => null, 'days' => (int)$scenario['pref']['trip_days'], 'stops' => 0,
               'findings' => [], 'info' => [], 'seconds' => 0.0];
    $t0 = microtime(true);
    // Every scenario starts from a traveller without ratings; the baseline is planned that way too.
    setTestRatings($checkConn, $travellerId, []);
    $places = resolveScenarioPlaces($pipelineConn, $checkConn, $travellerId, $scenario);
    foreach ($places['errors'] as $error) $result['findings'][] = finding('SETUP', 'violation', $name, null, null, $error);
    $scenario['resolved'] = $places;
    if ($places['must'] || $places['avoid'] || $places['ratings']) $result['info']['places'] = array_diff_key($places, ['errors' => true]);
    $preferenceId = createPreference($checkConn, $travellerId, $scenario['pref']);
    addPreferenceInputs($checkConn, $preferenceId, $scenario['weights'] ?? [], $places['must'], $places['avoid']);
    setTestRatings($checkConn, $travellerId, $places['ratings']);
    try {
        // One table request per trip; with real routing one more per day that had to be repaired.
        $generateLimit = REQUEST_LIMITS['generate'] + (measuredFigures() ? (int)$scenario['pref']['trip_days'] : 0);
        $out = counted('generate', $generateLimit, fn() => runPlannerPipeline($pipelineConn, $travellerId, $preferenceId, $scenario['trip']), $result);
        $result['itinerary_id'] = $out['itinerary_id'];
        foreach ($out['notes'] as $note) {
            $result['findings'][] = finding('OPTIMISE', 'warning', $name, null, null, $note);
        }
        if (isset($places['twin'])) runTwinSteps($pipelineConn, $checkConn, $out['itinerary_id'], $scenario, $name, $result);
        if (!empty($scenario['edits'])) runEditSequence($pipelineConn, $checkConn, $out['itinerary_id'], $preferenceId, $scenario, $name, $result);
        checkItinerary($checkConn, $out['itinerary_id'], $scenario, $name, $result);
        $result['info']['plan'] = planFingerprint($checkConn, $out['itinerary_id']);
        // PLAN: a generation scenario on estimates, against the plan recorded before the trip layout.
        if (empty($scenario['edits']) && isset(RECORDED_PLANS[$name]) && !measuredFigures()) {
            [$stops, $km] = RECORDED_PLANS[$name];
            $result['info']['recorded'] = ['stops' => $stops, 'km' => $km];
            if ($result['stops'] < $stops) {
                $result['findings'][] = finding('PLAN', 'warning', $name, null, null, "{$result['stops']} stops, the recorded plan had {$stops}");
            }
            $newKm = (float)($result['info']['km'] ?? 0);
            if ($newKm > $km * (1 + PLAN_KM_SHARE) && $newKm - $km >= PLAN_KM_EXTRA) {
                $result['findings'][] = finding('PLAN', 'warning', $name, null, null, sprintf('%.1f km, the recorded plan had %.1f km', $newKm, $km));
            }
        }
    } catch (Throwable $e) {
        $result['findings'][] = finding('GENERATE', 'violation', $name, null, null, get_class($e) . ': ' . $e->getMessage());
    }
    setTestRatings($checkConn, $travellerId, []);
    if (!$keep) deleteScenarioData($checkConn, $travellerId, $preferenceId);
    $result['seconds'] = round(microtime(true) - $t0, 1);
    return $result;
}

// ---------------------------------------------------------------------------------------
// Edit scenarios
// ---------------------------------------------------------------------------------------

/** The visit rows of an itinerary by day, in order: item_id, place_id, category. */
function editVisits(mysqli $conn, int $itineraryId): array
{
    $stmt = $conn->prepare("SELECT ii.item_id, ii.day_no, ii.place_id, ii.item_title, LOWER(COALESCE(cp.category, ii.item_type)) AS category,
                                   COALESCE(ii.item_latitude, cp.latitude) AS lat, COALESCE(ii.item_longitude, cp.longitude) AS lng
                            FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                            WHERE ii.itinerary_id = ? AND ii.meal_slot IS NULL AND ii.item_type IN ('attraction','food','festival')
                            ORDER BY ii.day_no, ii.sequence_no, ii.item_id");
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $days = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $days[(int)$r['day_no']][] = $r;
    $stmt->close();
    return $days;
}

/** Every stored row of an itinerary and its total, to compare before and after a refused change. */
function editSnapshot(mysqli $conn, int $itineraryId): string
{
    $stmt = $conn->prepare('SELECT * FROM itinerary_items WHERE itinerary_id = ? ORDER BY item_id');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare('SELECT total_estimated_cost, selected_hotel_id, selected_hotel_nights FROM itineraries WHERE itinerary_id = ?');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $meta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return json_encode([$rows, $meta]);
}

/** Places of the trip's area (or outside it) that are active, have coordinates and are not in the itinerary. */
function editPlaces(mysqli $conn, int $itineraryId, array $states, bool $inside, string $extra = ''): array
{
    $sql = "SELECT place_id, name, state, category, opening_hours, latitude, longitude FROM cultural_places
            WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
              AND place_id NOT IN (SELECT place_id FROM itinerary_items WHERE itinerary_id = ? AND place_id IS NOT NULL) {$extra}";
    $types = 'i';
    $args = [$itineraryId];
    if ($states) {
        $sql .= ' AND LOWER(state) ' . ($inside ? '' : 'NOT ') . 'IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        $types .= str_repeat('s', count($states));
        $args = array_merge($args, $states);
    }
    $stmt = $conn->prepare($sql . ' ORDER BY place_id');
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * The twin of a must-see place (scenario key 'twin'): it must not be planned, and bringing it in
 * must be refused with SAME_LANDMARK and change no row. A stop of the must-see place's day is
 * removed first, so that the day has room and only the twin rule can refuse the add.
 */
function runTwinSteps(mysqli $p, mysqli $c, int $id, array $scenario, string $name, array &$result): void
{
    $add = static function (string $code, string $severity, ?int $day, ?string $stop, string $detail) use (&$result, $name): void {
        $result['findings'][] = finding($code, $severity, $name, $day, $stop, $detail);
    };
    $twin = (int)$scenario['resolved']['twin'];
    $must = (int)($scenario['resolved']['must'][0] ?? 0);
    $days = editVisits($c, $id);
    $mustDay = null;
    foreach ($days as $d => $visits) {
        foreach ($visits as $v) {
            if ((int)$v['place_id'] === $twin) $add('DUPLICATE', 'violation', (int)$d, (string)$v['item_title'], 'the twin of the must-see place was planned');
            if ((int)$v['place_id'] === $must) $mustDay = (int)$d;
        }
    }
    if ($mustDay === null) { $add('MUST', 'violation', null, null, 'the must-see place of the twin scenario was not planned'); return; }
    $refused = static function (string $what, array $r, string $before) use ($c, $id, $add, &$result): void {
        $result['info']['refusals'][$what] = $r['ok'] ? 'done' : (string)$r['code'];
        if ($r['ok']) $add('REFUSE', 'violation', null, null, "{$what}: the change was made");
        elseif ($r['code'] !== 'SAME_LANDMARK') $add('REFUSE', 'violation', null, null, "{$what}: refused with {$r['code']}, expected SAME_LANDMARK");
        if (editSnapshot($c, $id) !== $before) $add('REFUSE', 'violation', null, null, "{$what}: rows changed although the change was refused");
    };
    $removed = false;
    foreach (array_reverse($days[$mustDay]) as $v) {
        if (in_array((int)$v['place_id'], $scenario['resolved']['must'], true)) continue;   // must-see places stay
        $r = counted('remove', REQUEST_LIMITS['remove'], fn() => ItineraryEditService::removeStop($p, $id, (int)$v['item_id']), $result);
        if ($r['ok']) { $removed = true; break; }
    }
    if (!$removed) $add('EDIT', 'warning', $mustDay, null, 'no stop of the must-see day could be removed to make room for the twin');
    $before = editSnapshot($c, $id);
    $r = counted('refuse', REQUEST_LIMITS['refuse'], fn() => ItineraryEditService::addStop($p, $id, $mustDay, $twin), $result);
    $refused('add the twin of the must-see place', $r, $before);
    foreach (editVisits($c, $id) as $d => $visits) {
        if ($d === $mustDay) continue;
        $before = editSnapshot($c, $id);
        $r = counted('refuse', REQUEST_LIMITS['refuse'], fn() => ItineraryEditService::replaceStop($p, $id, (int)$visits[0]['item_id'], $twin), $result);
        $refused('the twin in place of a stop', $r, $before);
        break;
    }
}

/**
 * Change a generated itinerary through ItineraryEditService as the pages do, then try changes
 * that break a rule. The checks of checkItinerary() then judge the result.
 */
function runEditSequence(mysqli $p, mysqli $c, int $id, int $preferenceId, array &$scenario, string $name, array &$result): void
{
    $add = static function (string $code, string $severity, ?int $day, ?string $stop, string $detail) use (&$result, $name): void {
        $result['findings'][] = finding($code, $severity, $name, $day, $stop, $detail);
    };
    $shapeOk = static function (string $step, $r) use ($add): bool {
        foreach (['ok', 'status', 'code', 'message', 'days'] as $key) {
            if (!is_array($r) || !array_key_exists($key, $r)) { $add('EDIT', 'violation', null, null, "{$step}: result without \"{$key}\""); return false; }
        }
        if ($r['ok'] !== ($r['status'] === 'done') || ($r['ok'] && $r['code'] !== null) || (!$r['ok'] && !is_string($r['code']))) {
            $add('EDIT', 'violation', null, null, "{$step}: ok, status and code disagree");
        }
        return true;
    };
    // Every call of the edit service counts its routing requests (REQUESTS).
    $req = static function (string $op, callable $call, ?callable $limit = null) use (&$result) {
        return counted($op, $limit ?? REQUEST_LIMITS[$op], $call, $result);
    };
    $done = [];
    $note = static function (string $step, array $r) use (&$done, $add, $shapeOk): bool {
        if (!$shapeOk($step, $r)) return false;
        $done[$step] = $r['ok'] ? 'done' : 'refused ' . $r['code'];
        if (!$r['ok']) $add('EDIT', 'warning', null, null, "{$step} refused ({$r['code']}): {$r['message']}");
        return (bool)$r['ok'];
    };
    // Planning a day again may be refused only when the new day would be worse than the present
    // one in a way the result states ('worse': a must-see place lost, no stop, another day
    // broken), or when the day cannot be planned at all. A refusal on a rule of the new day's
    // own stops means the planner did not settle the day it chose (a violation).
    $regenerateRefused = static function (string $step, int $day, array $r) use ($add): void {
        if (($r['status'] ?? '') === 'done' || !empty($r['worse']) || in_array($r['code'] ?? '', ['NOT_FOUND', 'LOCKED'], true)) return;
        $add('EDIT', 'violation', $day, null, "{$step}: planning day {$day} again was refused on a rule of its own new plan (" . ($r['code'] ?? '?') . '): ' . ($r['message'] ?? ''));
    };
    $pref = $scenario['pref'];
    $target = (int)(intendedProfile($pref)['target']);
    $musts = array_flip($scenario['resolved']['must'] ?? []);
    $free = static fn(array $v): bool => !isset($musts[(int)$v['place_id']]);
    $states = array_map('strtolower', csv($pref['preferred_states']));
    $tripDays = (int)$pref['trip_days'];

    // 1. Replace a stop of day 1 with the first suggestion: it must be done, in the same position.
    $days = editVisits($c, $id);
    $candidates = array_values(array_filter($days[1] ?? [], $free));
    $stop = $candidates[1] ?? $candidates[0] ?? null;
    if ($stop !== null) {
        $position = array_search((int)$stop['item_id'], array_map(static fn($v) => (int)$v['item_id'], $days[1]), true);
        $s = $req('suggest', fn() => ItineraryEditService::suggestReplacements($p, $id, (int)$stop['item_id'], 1, 0));
        if ($shapeOk('suggest', $s) && $s['ok'] && !empty($s['options'])) {
            $placeId = (int)$s['options'][0]['place_id'];
            $r = $req('replace', fn() => ItineraryEditService::replaceStop($p, $id, (int)$stop['item_id'], $placeId));
            if ($note('replace', $r)) {
                $after = editVisits($c, $id)[1] ?? [];
                if ((int)($after[$position]['item_id'] ?? 0) !== (int)$stop['item_id'] || (int)($after[$position]['place_id'] ?? 0) !== $placeId) {
                    $add('EDIT', 'violation', 1, (string)$stop['item_title'], 'the replacement did not keep the position of the stop');
                }
            } else {
                $add('EDIT', 'violation', 1, (string)$stop['item_title'], 'the first suggestion was refused when chosen: ' . $r['message']);
            }
        } else {
            $add('EDIT', 'warning', 1, (string)$stop['item_title'], 'no replacement suggested');
        }
    }

    // 2. Remove a stop from the day with the most stops (one that the rules let go).
    $days = editVisits($c, $id);
    uksort($days, static fn($a, $b) => [count($days[$b]), $b] <=> [count($days[$a]), $a]);
    $removedDay = null;
    foreach ($days as $d => $visits) {
        if (count($visits) < 2) continue;
        foreach (array_reverse(array_filter($visits, $free)) as $v) {
            $r = $req('remove', fn() => ItineraryEditService::removeStop($p, $id, (int)$v['item_id']));
            $shapeOk('remove', $r);
            if ($r['ok']) { $removedDay = (int)$d; $done['remove'] = 'done'; break 2; }
        }
    }
    if ($removedDay === null) $add('EDIT', 'warning', null, null, 'no stop could be removed');

    // 3. Move a stop from another day to a day with room.
    $days = editVisits($c, $id);
    $moved = false;
    for ($to = 1; $to <= $tripDays && !$moved; $to++) {
        if (count($days[$to] ?? []) >= $target) continue;
        foreach ($days as $from => $visits) {
            if ($from === $to || count($visits) < 2) continue;
            foreach (array_filter($visits, $free) as $v) {
                $r = $req('move', fn() => ItineraryEditService::moveStop($p, $id, (int)$v['item_id'], $to));
                $shapeOk('move', $r);
                if ($r['ok']) { $moved = true; $done['move'] = "day {$from} to day {$to}"; break 2; }
            }
        }
    }
    if (!$moved) $add('EDIT', 'warning', null, null, 'no stop could be moved to another day');

    // 4. Add a feasible place to a day with room: the first nearby place the service accepts.
    $days = editVisits($c, $id);
    $added = false;
    for ($d = 1; $d <= $tripDays && !$added; $d++) {
        if (count($days[$d] ?? []) >= $target || !($days[$d] ?? [])) continue;
        $last = $days[$d][count($days[$d]) - 1];
        $places = editPlaces($c, $id, $states, true);
        usort($places, static fn($a, $b) => haversineKm((float)$last['lat'], (float)$last['lng'], (float)$a['latitude'], (float)$a['longitude'])
            <=> haversineKm((float)$last['lat'], (float)$last['lng'], (float)$b['latitude'], (float)$b['longitude']));
        foreach (array_slice($places, 0, 40) as $place) {
            $check = $req('can_add', fn() => ItineraryEditService::canAdd($p, $id, $d, (int)$place['place_id']));
            if (!$check['ok']) continue;
            $r = $req('add', fn() => ItineraryEditService::addStop($p, $id, $d, (int)$place['place_id']));
            if (!$note('add', $r)) $add('EDIT', 'violation', $d, (string)$place['name'], 'canAdd() said yes, addStop() refused: ' . $r['message']);
            $added = true;
            break;
        }
    }
    if (!$added) $add('EDIT', 'warning', null, null, 'no feasible place could be added');

    // 5. Plan the last day again with more of one interest and less of another.
    $interests = csv($pref['interests']);
    $more = $interests[1] ?? $interests[0];
    $less = $interests[0];
    $r = $req('regenerate', fn() => ItineraryEditService::regenerateDay($p, $id, $tripDays, ['more' => [$more], 'less' => $more === $less ? [] : [$less]]));
    $regenerateRefused('regenerate', $tripDays, $r);
    if ($note('regenerate', $r)) {
        // CATEGORY judges that day by the weights asked for: more = loved (3), less = a little (1).
        $scenario['day_weights'][$tripDays] = [$more => 3] + ($more === $less ? [] : [$less => 1]);
    }

    // 5a. The day of the first must-see place planned again (scenario key regenerate_must_day).
    if (isset($scenario['regenerate_must_day']) && $musts) {
        $mustDay = null;
        foreach (editVisits($c, $id) as $d => $visits) {
            foreach ($visits as $v) if (isset($musts[(int)$v['place_id']]) && $mustDay === null) $mustDay = (int)$d;
        }
        if ($mustDay === null) {
            $add('EDIT', 'warning', null, null, 'regenerate_must_day: no must-see place is in the trip');
        } else {
            $opts = $scenario['regenerate_must_day'];
            $r = $req('regenerate', fn() => ItineraryEditService::regenerateDay($p, $id, $mustDay, $opts));
            $regenerateRefused('regenerate_must_day', $mustDay, $r);
            if ($note('regenerate_must_day', $r)) {
                $scenario['day_weights'][$mustDay] = array_fill_keys($opts['more'] ?? [], 3) + array_fill_keys($opts['less'] ?? [], 1);
                $stillThere = false;
                foreach (editVisits($c, $id)[$mustDay] ?? [] as $v) $stillThere = $stillThere || isset($musts[(int)$v['place_id']]);
                if (!$stillThere) $add('MUST', 'violation', $mustDay, null, 'planning the day again lost its must-see place');
            }
        }
    }

    // 5b. A start of its own for the day after the first night (day 1 of a one-day trip), 30
    // minutes after the day window opens. It must survive the hotel choice, an edit on that
    // day, planning the day again and the optimiser; START and WAIT then judge the day from it.
    $startDay = $tripDays >= 2 ? 2 : 1;
    $wantedStart = (int)intendedProfile($pref)['start'] + 30;
    $keepsStart = static function (string $after) use ($c, $id, $startDay, $wantedStart, $add): void {
        $stored = storedDayStarts($c, $id)[$startDay] ?? null;
        if ($stored !== $wantedStart) {
            $add('DAY_START', 'violation', $startDay, null, "after {$after}: stored start " . ($stored === null ? 'none' : hm($stored)) . ', expected ' . hm($wantedStart));
        }
        $stmt = $c->prepare("SELECT MIN(start_time) FROM itinerary_items WHERE itinerary_id = ? AND day_no = ? AND item_type IN ('attraction','food','festival')");
        $stmt->bind_param('ii', $id, $startDay);
        $stmt->execute();
        $first = toMinutes($stmt->get_result()->fetch_row()[0] ?? null);
        $stmt->close();
        if ($first !== null && $first < $wantedStart) {
            $add('DAY_START', 'violation', $startDay, null, "after {$after}: the day begins " . hm($first) . ', before its stored start ' . hm($wantedStart));
        }
    };
    $dayStartSet = $note('day_start', $req('day_start', fn() => ItineraryEditService::setDayStart($p, $id, $startDay, $wantedStart)));
    if ($dayStartSet) $keepsStart('setting it');

    // 6. The nearest lodging to the last stop of day 1 as the hotel of night 1.
    if ($tripDays >= 2) {
        $days = editVisits($c, $id);
        $last = ($days[1] ?? []) ? $days[1][count($days[1]) - 1] : null;
        $lodging = null;
        if ($last !== null) {
            $best = INF;
            foreach ($c->query('SELECT lodging_id, latitude, longitude FROM lodgings WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL') as $l) {
                $km = haversineKm((float)$last['lat'], (float)$last['lng'], (float)$l['latitude'], (float)$l['longitude']);
                if ($km < $best) { $best = $km; $lodging = (int)$l['lodging_id']; }
            }
        }
        $hotel = $lodging !== null ? (new HotelRecommendationService($p))->find($lodging, 0.0) : null;
        if ($hotel === null) {
            $add('EDIT', 'warning', 1, null, 'no lodging found for night 1');
        } else {
            $r = $req('hotel', fn() => ItineraryEditService::setHotels($p, $id, [1 => $hotel]));
            if ($note('hotel', $r) && !empty($r['refused_nights'])) {
                $add('EDIT', 'warning', 2, null, 'hotel of night 1 not saved: ' . implode(' ', array_column($r['refused_nights'], 1)));
            }
        }
    }

    if ($dayStartSet) $keepsStart('the hotel choice');

    // 6b. An edit on the day with its own start: its first free stop replaced by the first suggestion.
    if ($dayStartSet) {
        $days = editVisits($c, $id);
        $stop = array_values(array_filter($days[$startDay] ?? [], $free))[0] ?? null;
        $s = $stop !== null ? $req('suggest', fn() => ItineraryEditService::suggestReplacements($p, $id, (int)$stop['item_id'], 1, 0)) : null;
        if ($s !== null && $shapeOk('suggest_day_start', $s) && $s['ok'] && !empty($s['options'])) {
            if ($note('replace_day_start', $req('replace', fn() => ItineraryEditService::replaceStop($p, $id, (int)$stop['item_id'], (int)$s['options'][0]['place_id'])))) {
                $keepsStart('an edit on the day');
            }
        } else {
            $add('EDIT', 'warning', $startDay, null, 'no replacement suggested on the day with its own start');
        }
        // 6c. The same day planned again (no more or less: it is judged by the trip's weights again).
        $r = $req('regenerate', fn() => ItineraryEditService::regenerateDay($p, $id, $startDay));
        $regenerateRefused('regenerate_day_start', $startDay, $r);
        if ($note('regenerate_day_start', $r)) {
            unset($scenario['day_weights'][$startDay]);
            $keepsStart('planning the day again');
        }
    }

    // 7. Optimise the route.
    // A day the optimiser left without stops is timed again by the edit service: up to its two requests more.
    $note('optimise', $req('optimise', fn() => ItineraryEditService::optimise($p, $id),
        static fn(): int => REQUEST_LIMITS['optimise'] + (count(array_filter(editVisits($c, $id))) < $tripDays ? REQUEST_LIMITS['regenerate'] : 0)));
    if ($dayStartSet) $keepsStart('the optimiser');

    // 8. Re-plan a rainy day: day 1 with an 85% chance of rain (a forecast override). From 80%
    // no outdoor stop may stay on it while an indoor place of the area could be visited; an
    // outdoor stop the planner explains ("chance of rain" in its notes) or a must-see is a warning.
    $rainDate = (string)$scenario['trip']['start_date'];
    $rainChance = 85;
    $r = $req('replan_rain', fn() => ItineraryEditService::replanRainyDays($p, $id, [$rainDate => $rainChance]));   // one rainy day: one day planned again
    $regenerateRefused('replan_rain', 1, (array)($r['changes'][1] ?? []));
    if ($note('replan_rain', $r)) {
        if (($r['changes'][1]['status'] ?? '') === 'done') unset($scenario['day_weights'][1]);   // planned with the trip's weights again
        if (($r['changes'][1]['status'] ?? '') !== 'done') {
            $add('RAIN', 'violation', 1, null, 'day 1 at ' . $rainChance . '% was not planned again: ' . ($r['changes'][1]['message'] ?? 'no result for the day'));
        }
        $scenario['trip']['forecast'][$rainDate] = $rainChance;   // WEATHER judges the day with it too
        $stmt = $c->prepare("SELECT ii.item_title, ii.place_id, ii.notes, cp.is_outdoor, LOWER(cp.category) AS category
                             FROM itinerary_items ii JOIN cultural_places cp ON cp.place_id = ii.place_id
                             WHERE ii.itinerary_id = ? AND ii.day_no = 1 AND ii.meal_slot IS NULL AND ii.item_type IN ('attraction','food','festival')");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $indoorLeft = count(editPlaces($c, $id, $states, true, "AND COALESCE(is_outdoor, 0) = 0 AND LOWER(category) NOT IN ('nature','festival')"));
        foreach ($rows as $row) {
            if ((int)$row['is_outdoor'] !== 1 && $row['category'] !== 'nature') continue;
            $explained = isset($musts[(int)$row['place_id']]) || str_contains((string)$row['notes'], 'chance of rain');
            if ($explained || $indoorLeft === 0) {
                $add('RAIN', 'warning', 1, (string)$row['item_title'], "outdoor stop kept on a day at {$rainChance}%" . ($explained ? ' (explained in its notes, or a must-see)' : ' (no indoor place left in the area)'));
            } else {
                $add('RAIN', 'violation', 1, (string)$row['item_title'], "outdoor stop on a day at {$rainChance}% while {$indoorLeft} indoor place(s) of the area are not in the trip");
            }
        }
    }

    // 8b. Rain on every day (scenario key rain_every_day): every day planned again for it, with
    // the requests of one day planned again per rainy day.
    if (isset($scenario['rain_every_day'])) {
        $forecast = [];
        for ($d = 1; $d <= $tripDays; $d++) {
            $forecast[(new DateTimeImmutable((string)$scenario['trip']['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d')] = (int)$scenario['rain_every_day'];
        }
        $r = $req('replan_rain', fn() => ItineraryEditService::replanRainyDays($p, $id, $forecast), static fn(): int => REQUEST_LIMITS['replan_rain'] * $tripDays);
        $note('replan_rain_every_day', $r);
        foreach ((array)($r['changes'] ?? []) as $d => $change) {
            $regenerateRefused('replan_rain_every_day', (int)$d, (array)$change);
            if (($change['status'] ?? '') === 'done') unset($scenario['day_weights'][(int)$d]);
        }
        $scenario['trip']['forecast'] = $forecast + (array)($scenario['trip']['forecast'] ?? []);
    }
    $result['info']['edits'] = $done;

    // REFUSE: each change below breaks a rule, so it must be refused and change no row.
    $refuse = static function (string $what, int $itemId, int $placeId, ?array $codes) use ($p, $c, $id, $add, &$result): void {
        $before = editSnapshot($c, $id);
        $r = counted('refuse', REQUEST_LIMITS['refuse'], fn() => ItineraryEditService::replaceStop($p, $id, $itemId, $placeId), $result);
        $result['info']['refusals'][$what] = $r['ok'] ? 'done' : (string)$r['code'];
        if ($r['ok']) {
            $add('REFUSE', 'violation', null, null, "{$what}: the change was made (place {$placeId})");
        } elseif ($codes !== null && !in_array($r['code'], $codes, true)) {
            $add('REFUSE', 'violation', null, null, "{$what}: refused with {$r['code']}, expected " . implode(' or ', $codes));
        }
        if (editSnapshot($c, $id) !== $before) $add('REFUSE', 'violation', null, null, "{$what}: rows changed although the change was refused");
    };
    $days = editVisits($c, $id);
    $all = array_merge(...array_values($days ?: [[]]));
    $first = array_values(array_filter($all, $free))[0] ?? null;
    if ($first === null) return;

    // A place closed all day on the stop's date.
    $closedTried = false;
    foreach ($days as $d => $visits) {
        $date = (new DateTimeImmutable((string)$scenario['trip']['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
        $weekday = date('l', strtotime($date));
        // Not food or festival places, nor places to avoid: no other rule may refuse them first.
        foreach (editPlaces($c, $id, $states, true, "AND opening_hours LIKE '%{$weekday}: Closed%' AND LOWER(category) NOT IN ('food','festival')") as $place) {
            if (openingRanges((string)$place['opening_hours'], $date) !== [] || in_array((int)$place['place_id'], $scenario['resolved']['avoid'] ?? [], true)) continue;
            $v = array_values(array_filter($visits, $free))[0] ?? null;
            if ($v === null) continue;
            $refuse("closed on {$date}", (int)$v['item_id'], (int)$place['place_id'], ['CLOSED']);
            $closedTried = true;
            break 2;
        }
    }
    if (!$closedTried) $result['info']['refuse_closed'] = 'no place of the area is closed on a trip date';

    // A place on the avoid list: one the service would accept, then added to the list.
    $s = $req('suggest', fn() => ItineraryEditService::suggestReplacements($p, $id, (int)$first['item_id'], 1, 0));
    $avoid = (int)($s['options'][0]['place_id'] ?? 0);
    if ($avoid > 0) {
        $stmt = $c->prepare("INSERT INTO traveller_preference_places (preference_id, place_id, wish) VALUES (?, ?, 'avoid')");
        $stmt->bind_param('ii', $preferenceId, $avoid);
        $stmt->execute();
        $stmt->close();
        $scenario['resolved']['avoid'][] = $avoid;
        $refuse('on the avoid list', (int)$first['item_id'], $avoid, ['AVOID']);
    }

    // A place already in the itinerary.
    $other = null;
    foreach ($all as $v) if ((int)$v['item_id'] !== (int)$first['item_id']) { $other = $v; break; }
    if ($other !== null) $refuse('already in the itinerary', (int)$first['item_id'], (int)$other['place_id'], ['DUPLICATE']);

    // A place in a state the traveller did not choose.
    if ($states) {
        $outside = editPlaces($c, $id, $states, false, "AND LOWER(category) <> 'festival'")[0] ?? null;
        if ($outside !== null) $refuse('in another state', (int)$first['item_id'], (int)$outside['place_id'], ['STATE']);
    }
}

/** The stored start of each day of an itinerary (table itinerary_days): day => minutes; none without the table. */
function storedDayStarts(mysqli $conn, int $itineraryId): array
{
    $res = $conn->query("SHOW TABLES LIKE 'itinerary_days'");
    if (!$res || $res->num_rows === 0) return [];
    $stmt = $conn->prepare('SELECT day_no, start_time FROM itinerary_days WHERE itinerary_id = ? AND start_time IS NOT NULL');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $out[(int)$row['day_no']] = toMinutes($row['start_time']);
    $stmt->close();
    return $out;
}

/**
 * Run one call of the planner and count its routing requests (RouteService counts them, also
 * those it would have made when ROUTING_ESTIMATES_ONLY keeps it offline). More than its limit
 * is a REQUESTS violation: with the public servers every request costs a second or more, and
 * a second table request about ten. $limit may be a function, asked after the call.
 */
function counted(string $op, $limit, callable $call, array &$result)
{
    RouteService::resetRequestCount();
    $out = $call();
    $made = RouteService::requestCount();
    $max = is_callable($limit) ? (int)$limit() : (int)$limit;
    $result['info']['requests'][$op] = max($result['info']['requests'][$op] ?? 0, $made);
    if ($made > $max) {
        $result['findings'][] = finding('REQUESTS', 'violation', (string)$result['scenario'], null, null, "{$op}: {$made} routing request(s), at most {$max}");
    }
    return $out;
}

/**
 * The stored base of each day (table itinerary_days, migration 20261119_trip_bases.sql): day =>
 * [name, lat, lng]; none without the columns or for a day without a base.
 */
function storedBases(mysqli $conn, int $itineraryId): array
{
    $res = $conn->query("SHOW COLUMNS FROM itinerary_days LIKE 'base_lat'");
    if (!$res || $res->num_rows === 0) return [];
    $stmt = $conn->prepare('SELECT day_no, base_name, base_lat, base_lng FROM itinerary_days WHERE itinerary_id = ? AND base_lat IS NOT NULL AND base_lng IS NOT NULL');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $out[(int)$row['day_no']] = [(string)$row['base_name'], (float)$row['base_lat'], (float)$row['base_lng']];
    $stmt->close();
    return $out;
}

/**
 * Kilometres of the whole trip: every stored leg, plus the way back to the base after a day
 * followed by a night that has no hotel row (not stored as a leg; estimated here as the
 * straight line times ROAD_FACTOR, so plans with and without bases compare).
 */
function tripKm(mysqli $conn, int $itineraryId, array $items, int $tripDays): float
{
    $km = 0.0;
    $last = [];
    $hotelDays = [];
    foreach ($items as $i) {
        $km += (float)($i['distance_km'] ?? 0);
        $d = (int)$i['day_no'];
        if (strtolower((string)$i['item_type']) === 'hotel') { $hotelDays[$d] = true; continue; }
        $lat = $i['item_latitude'] ?? $i['place_lat'] ?? null;
        $lng = $i['item_longitude'] ?? $i['place_lng'] ?? null;
        if ($lat !== null && $lng !== null) $last[$d] = [(float)$lat, (float)$lng];
    }
    foreach (storedBases($conn, $itineraryId) as $d => [, $lat, $lng]) {
        if ($d >= $tripDays || isset($hotelDays[$d]) || !isset($last[$d])) continue;
        $km += haversineKm($last[$d][0], $last[$d][1], $lat, $lng) * ROAD_FACTOR;
    }
    return round($km, 1);
}

/** A short fingerprint of the stored plan (rows without their ids, and the total), to compare two runs. */
function planFingerprint(mysqli $conn, int $itineraryId): string
{
    $stmt = $conn->prepare('SELECT day_no, sequence_no, item_type, meal_slot, place_id, item_title, start_time, end_time, estimated_cost, distance_km, travel_time_min, notes
                            FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no, item_id');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    $stmt = $conn->prepare('SELECT total_estimated_cost FROM itineraries WHERE itinerary_id = ?');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return substr(sha1(json_encode([$rows, $total])), 0, 10);
}

function finding(string $code, string $severity, string $scenario, ?int $day, ?string $stop, string $detail): array
{
    return ['code' => $code, 'severity' => $severity, 'scenario' => $scenario, 'day' => $day, 'stop' => $stop, 'detail' => $detail];
}

// ---------------------------------------------------------------------------------------
// The checks
// ---------------------------------------------------------------------------------------

function checkItinerary(mysqli $conn, int $itineraryId, array $scenario, string $name, array &$result): void
{
    $stmt = $conn->prepare('SELECT * FROM itineraries WHERE itinerary_id = ?');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $it = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$it) {
        $result['findings'][] = finding('GENERATE', 'violation', $name, null, null, "itinerary {$itineraryId} was not stored");
        return;
    }

    $stmt = $conn->prepare('
        SELECT ii.*, cp.name AS place_name, cp.description, cp.state, cp.district, cp.category, cp.opening_hours,
               cp.festival_start_date, cp.festival_end_date, cp.halal_status, cp.is_outdoor, cp.visit_duration_min,
               cp.latitude AS place_lat, cp.longitude AS place_lng
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ?
        ORDER BY ii.day_no, ii.sequence_no, ii.item_id
    ');
    $stmt->bind_param('i', $itineraryId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $pref = $scenario['pref'];
    $profile = intendedProfile($pref);
    $tripDays = (int)$pref['trip_days'];
    // A day with a start time of its own (ItineraryEditService::setDayStart()) is judged from it.
    $storedStarts = storedDayStarts($conn, $itineraryId);
    $add = static function (string $code, string $severity, ?int $day, ?string $stop, string $detail) use (&$result, $name): void {
        $result['findings'][] = finding($code, $severity, $name, $day, $stop, $detail);
    };

    // Visits are the stops; a meal row (meal_slot set) is lunch or dinner, not a stop.
    $isMealRow = static fn(array $i): bool => ($i['meal_slot'] ?? null) !== null;
    $visits = array_values(array_filter($items, static fn($i) => !$isMealRow($i) && in_array(strtolower((string)$i['item_type']), ['attraction', 'food', 'festival'], true)));
    $mealRows = array_values(array_filter($items, $isMealRow));
    $result['stops'] = count($visits);
    $result['info']['stored_total_rm'] = (float)$it['total_estimated_cost'];
    $result['info']['no_usable_hours'] = 0;
    $result['info']['meals_at_places'] = count(array_filter($mealRows, static fn($m) => $m['place_id'] !== null));
    $result['info']['meal_breaks'] = count($mealRows) - $result['info']['meals_at_places'];
    $result['info']['km'] = tripKm($conn, $itineraryId, $items, (int)$scenario['pref']['trip_days']);

    // DAYS and STOPS
    $byDay = [];
    foreach ($visits as $v) $byDay[(int)$v['day_no']][] = $v;
    for ($d = 1; $d <= $tripDays; $d++) {
        $n = count($byDay[$d] ?? []);
        if ($n === 0) $add('DAYS', 'violation', $d, null, 'day has no visit stop');
        if ($n < $profile['target']) $add('STOPS', 'warning', $d, null, "{$n} stops, target {$profile['target']} (short by " . ($profile['target'] - $n) . ')');
        if ($n > $profile['target']) $add('STOPS', 'violation', $d, null, "{$n} stops, target {$profile['target']}");
    }
    foreach (array_keys($byDay) as $d) {
        if ($d < 1 || $d > $tripDays) $add('DAYS', 'violation', $d, null, "stop on day {$d} outside the trip of {$tripDays} day(s)");
    }

    // DUPLICATE: no place twice in the trip, neither as a visit nor as a meal place.
    $seen = [];
    foreach (array_merge($visits, $mealRows) as $v) {
        if ($v['place_id'] === null) continue;
        $pid = (int)$v['place_id'];
        if (isset($seen[$pid])) $add('DUPLICATE', 'violation', (int)$v['day_no'], $v['item_title'], "place {$pid} also on day {$seen[$pid]}");
        else $seen[$pid] = (int)$v['day_no'];
    }
    // Nor one landmark under two names (two place rows, sameLandmarkJudged()).
    $placed = [];
    foreach (array_merge($visits, $mealRows) as $v) {
        if ($v['place_id'] === null || $v['place_lat'] === null || $v['place_lng'] === null) continue;
        foreach ($placed as $o) {
            if ((int)$o['place_id'] === (int)$v['place_id']) continue;
            if (sameLandmarkJudged((string)$o['place_name'], (float)$o['place_lat'], (float)$o['place_lng'], (string)$v['place_name'], (float)$v['place_lat'], (float)$v['place_lng'])) {
                $add('DUPLICATE', 'violation', (int)$v['day_no'], $v['item_title'], "the same landmark as {$o['place_name']} (place {$o['place_id']}, day {$o['day_no']})");
            }
        }
        $placed[] = $v;
    }

    // Where each night is spent: the hotel row of the day, else the stored base of the day.
    $bases = storedBases($conn, $itineraryId);
    $hotelRows = [];
    foreach ($items as $h) {
        if (strtolower((string)$h['item_type']) === 'hotel' && $h['item_latitude'] !== null && $h['item_longitude'] !== null) $hotelRows[(int)$h['day_no']] = $h;
    }
    $nightPoint = [];
    for ($d = 1; $d < $tripDays; $d++) {
        if (isset($hotelRows[$d])) $nightPoint[$d] = [(float)$hotelRows[$d]['item_latitude'], (float)$hotelRows[$d]['item_longitude']];
        elseif (isset($bases[$d])) $nightPoint[$d] = [$bases[$d][1], $bases[$d][2]];
    }
    checkBases($conn, $it, $scenario, $bases, $tripDays, $add);

    $states = array_map('strtolower', csv($pref['preferred_states']));
    $originPoint = [(float)$it['origin_lat'], (float)$it['origin_lng']];
    $originEast = (float)$it['origin_lng'] >= EAST_FROM_LNG;
    $previous = $originPoint;
    $tags = $profile['tags'];
    $dayKmByDay = [];
    $lastVisit = null;

    // Each day in stored order, visits and meal rows together.
    $timeline = [];
    foreach (array_merge($visits, $mealRows) as $row) $timeline[(int)$row['day_no']][] = $row;
    ksort($timeline);
    foreach ($timeline as &$rows) usort($rows, static fn($a, $b) => [(int)$a['sequence_no'], (int)$a['item_id']] <=> [(int)$b['sequence_no'], (int)$b['item_id']]);
    unset($rows);

    foreach ($timeline as $dayNo => $dayRows) {
        $date = (new DateTimeImmutable((string)$it['start_date']))->modify('+' . ($dayNo - 1) . ' days')->format('Y-m-d');
        $dayStartMin = $storedStarts[$dayNo] ?? $profile['start'];
        $dayStartSource = isset($storedStarts[$dayNo]) ? 'start stored for the day' : $profile['window_source'];
        if (isset($storedStarts[$dayNo]) && $storedStarts[$dayNo] < EARLIEST_START) $add('START', 'violation', $dayNo, null, 'stored day start ' . hm($storedStarts[$dayNo]) . ' before ' . hm(EARLIEST_START));
        $prevEnd = null;
        $prevWasMeal = false;
        $dayKm = 0.0;
        $slotsSeen = [];
        $visitIndex = 0;
        foreach ($dayRows as $k => $v) {
            $isMeal = $isMealRow($v);
            $title = (string)$v['item_title'];
            $start = toMinutes($v['start_time']);
            $end = toMinutes($v['end_time']);
            $isFirstOfTrip = $dayNo === 1 && $k === 0;
            $travel = $v['travel_time_min'] !== null ? (int)$v['travel_time_min'] : null;
            $km = $v['distance_km'] !== null ? (float)$v['distance_km'] : null;
            $hasPoint = $v['item_latitude'] !== null || $v['place_lat'] !== null;
            $point = [(float)($v['item_latitude'] ?? $v['place_lat']), (float)($v['item_longitude'] ?? $v['place_lng'])];
            $category = strtolower((string)($v['category'] ?? $v['item_type']));
            // The first leg of the day the trip moves to another area, from the base of the night
            // before towards the new base, is not counted either (BASE_MOVED_KM).
            $isMove = $k === 0 && $dayNo > 1 && isset($bases[$dayNo - 1], $bases[$dayNo])
                && haversineKm($bases[$dayNo - 1][1], $bases[$dayNo - 1][2], $bases[$dayNo][1], $bases[$dayNo][2]) > BASE_MOVED_KM;
            if ($isMove) {
                $toNew = haversineKm((float)($v['item_latitude'] ?? $v['place_lat']), (float)($v['item_longitude'] ?? $v['place_lng']), $bases[$dayNo][1], $bases[$dayNo][2]);
                $toOld = haversineKm((float)($v['item_latitude'] ?? $v['place_lat']), (float)($v['item_longitude'] ?? $v['place_lng']), $bases[$dayNo - 1][1], $bases[$dayNo - 1][2]);
                $isMove = $toNew < $toOld;
                if ($isMove) $result['info']['moves'] = ($result['info']['moves'] ?? 0) + 1;
            }
            // BASE: a day after a night starts where the night was spent.
            if ($k === 0 && $dayNo > 1 && isset($nightPoint[$dayNo - 1]) && $km !== null) {
                $from = $nightPoint[$dayNo - 1];
                $here = [(float)($v['item_latitude'] ?? $v['place_lat']), (float)($v['item_longitude'] ?? $v['place_lng'])];
                if ($v['item_latitude'] !== null || $v['place_lat'] !== null) {
                    $straight = haversineKm($from[0], $from[1], $here[0], $here[1]);
                    if ($km < LEG_KM_LOW * $straight - 0.05) {
                        $add('BASE', 'violation', $dayNo, $title, sprintf('first leg %.2f km, but the night before was spent %.2f km away (straight line)', $km, $straight));
                    }
                }
            }

            // TIME_ORDER (visits and meals: no overlap, and the gap holds the leg)
            if ($start === null || $end === null) {
                $add('TIME_ORDER', 'violation', $dayNo, $title, 'missing start or end time');
            } else {
                if ($end <= $start) $add('TIME_ORDER', 'violation', $dayNo, $title, 'ends ' . hm($end) . ' not after start ' . hm($start));
                if ($prevEnd !== null) {
                    $gap = $start - $prevEnd;
                    if ($gap < 0) $add('TIME_ORDER', 'violation', $dayNo, $title, 'starts ' . hm($start) . ' before the previous row ends ' . hm($prevEnd));
                    elseif ($travel !== null && $gap < $travel) $add('TIME_ORDER', 'violation', $dayNo, $title, "gap {$gap} min shorter than its travel time {$travel} min");
                    // A meal stands in for the rest between two visits.
                    elseif (!$isMeal && !$prevWasMeal && $travel !== null && $gap - $travel < $profile['rest']) {
                        $add('REST', 'warning', $dayNo, $title, 'rest ' . ($gap - $travel) . " min, planned rest {$profile['rest']} min");
                    }
                }
                // START and HARD_END: meal rows lie inside the day window too.
                if ($start < $dayStartMin) $add('START', 'violation', $dayNo, $title, 'starts ' . hm($start) . ', earliest ' . hm($dayStartMin) . " ({$dayStartSource})");
                if ($end > $profile['hard_end']) $add('HARD_END', 'violation', $dayNo, $title, 'ends ' . hm($end) . ', latest ' . hm($profile['hard_end']));
                // WAIT: a visit starts at most MAX_WAIT_MIN after it can be reached; after a meal no rest is due.
                if (!$isMeal && $travel !== null) {
                    $reach = ($prevEnd === null ? $dayStartMin : $prevEnd + ($prevWasMeal ? 0 : $profile['rest'])) + $travel;
                    $wait = $start - max($reach, $dayStartMin);
                    if ($wait > MAX_WAIT_MIN) $add('WAIT', 'violation', $dayNo, $title, "waits {$wait} min for opening, at most " . MAX_WAIT_MIN);
                }
                // DURATION
                $length = $end - $start;
                if (!$isMeal && $length > 0 && ($length < VISIT_MIN || $length > $profile['visit_max'])) {
                    $add('DURATION', 'violation', $dayNo, $title, "visit {$length} min, allowed " . VISIT_MIN . "-{$profile['visit_max']} min");
                }
                // OPEN_HOURS (also a meal place, for the whole meal)
                $ranges = $v['place_id'] !== null ? openingRanges((string)($v['opening_hours'] ?? ''), $date) : null;
                if ($v['place_id'] !== null && $ranges === null) {
                    $result['info']['no_usable_hours']++;
                } elseif ($ranges !== null && $end > $start) {
                    $inside = false;
                    foreach ($ranges as [$open, $close]) {
                        if ($start >= $open && $end <= $close) $inside = true;
                    }
                    if (!$inside) {
                        $text = $ranges === [] ? 'closed all day' : implode(', ', array_map(static fn($r) => hm($r[0]) . '-' . hm($r[1]), $ranges));
                        $add('OPEN_HOURS', 'violation', $dayNo, $title, ($isMeal ? 'meal ' : 'visit ') . hm($start) . '-' . hm($end) . ' on ' . date('D', strtotime($date)) . " {$date}, open {$text}");
                    }
                }
                // ACCESS: heat
                if (!$isMeal && hasNeed($tags, ['avoid_heat']) && (int)($v['is_outdoor'] ?? 0) === 1) {
                    if ($start >= HEAT_FROM && $start <= HEAT_TO) {
                        $add('ACCESS', 'violation', $dayNo, $title, 'outdoor stop for avoid-heat traveller arrives ' . hm($start) . ' (11:00-16:00)');
                    } elseif ($start < HEAT_FROM && $end > HEAT_FROM) {
                        $add('ACCESS', 'warning', $dayNo, $title, 'outdoor visit ' . hm($start) . '-' . hm($end) . ' runs into the 11:00-16:00 heat');
                    }
                }
                $prevEnd = max($prevEnd ?? $end, $end);
            }

            // MEAL: the row of a meal
            if ($isMeal) {
                $slot = (string)$v['meal_slot'];
                [$wOpen, $wClose] = MEAL_WINDOWS[$slot] ?? [0, 0];
                if (isset($slotsSeen[$slot])) $add('MEAL', 'violation', $dayNo, $title, "a second {$slot} row on the day");
                $slotsSeen[$slot] = true;
                if ($start !== null && ($start < $wOpen || $start > $wClose)) $add('MEAL', 'violation', $dayNo, $title, "{$slot} starts " . hm($start) . ', window ' . hm($wOpen) . '-' . hm($wClose));
                if ($start !== null && $end !== null && $end - $start !== $profile['meal_min']) $add('MEAL', 'violation', $dayNo, $title, ($end - $start) . " min {$slot}, the pace gives {$profile['meal_min']} min");
                if (strtolower((string)$v['item_type']) !== 'food') $add('MEAL', 'violation', $dayNo, $title, 'meal row not of item_type food');
                if ((float)$v['estimated_cost'] != 0.0) $add('MEAL', 'violation', $dayNo, $title, 'meal row has a cost of its own: the three meals a day are already in the total');
                if (!str_contains((string)$v['notes'], 'Reason:')) $add('MEAL', 'violation', $dayNo, $title, 'meal row without a "Reason:" line');
                $visitsBefore = $visitIndex;
                $visitsAfter = count(array_filter(array_slice($dayRows, $k + 1), static fn($r) => !$isMealRow($r)));
                // A dinner after the last visit is the dinner near the base of a day followed by a night.
                $nearBase = $slot === 'dinner' && $visitsBefore > 0 && $visitsAfter === 0 && isset($nightPoint[$dayNo]);
                if (($visitsBefore === 0 || $visitsAfter === 0) && !$nearBase) $add('MEAL', 'violation', $dayNo, $title, "{$slot} not between two visits");
                if ($v['place_id'] === null) {
                    // A break without a place: time only.
                    if ($hasPoint || $km !== null || $travel !== null) $add('MEAL', 'violation', $dayNo, $title, 'meal break without a place has coordinates or a leg');
                    if (!str_contains((string)$v['notes'], 'kept free')) $add('MEAL', 'violation', $dayNo, $title, 'meal break does not say the time is kept free for a meal');
                    $prevWasMeal = true;
                    continue;   // no point: the next leg starts from the stop before
                }
                // A meal place: suits the dietary preference, near the stop before or after it.
                if ($category !== 'food') $add('MEAL', 'violation', $dayNo, $title, "meal place of category {$category}");
                if ($pref['dietary_preference'] === 'halal' && (int)($v['halal_status'] ?? 0) !== 1) $add('DIETARY', 'violation', $dayNo, $title, 'meal place not marked halal');
                if ($pref['dietary_preference'] === 'vegetarian' && !preg_match('/vegetarian|vegan/i', (string)$v['place_name'] . ' ' . (string)$v['description'])) {
                    $add('DIETARY', 'violation', $dayNo, $title, 'meal place not described as vegetarian or vegan');
                }
                $next = null;
                foreach (array_slice($dayRows, $k + 1) as $r) {
                    if (!$isMealRow($r)) { $next = [(float)($r['item_latitude'] ?? $r['place_lat']), (float)($r['item_longitude'] ?? $r['place_lng'])]; break; }
                }
                if ($next === null && $nearBase) $next = $nightPoint[$dayNo];
                $near = haversineKm($previous[0], $previous[1], $point[0], $point[1]);
                if ($next !== null) $near = min($near, haversineKm($point[0], $point[1], $next[0], $next[1]));
                $radius = MEAL_RADIUS_KM[$pref['transport_type']];
                if ($near > $radius) $add('MEAL', 'violation', $dayNo, $title, sprintf('meal place %.2f km from the stops before and after it (straight line), radius %.1f km', $near, $radius));
            } else {
                $visitIndex++;
            }
            $prevWasMeal = $isMeal;

            if (!$isMeal) {
                // ACCESS: step-free and elderly
                $text = (string)$v['place_name'] . ' ' . (string)$v['description'] . ' ' . $category;
                if (hasNeed($tags, ['wheelchair', 'avoid_stairs', 'step_free']) && preg_match(STEP_FREE_BLOCKED, $text, $m)) {
                    $add('ACCESS', 'violation', $dayNo, $title, "step-free traveller sent to a place described with \"{$m[0]}\"");
                }
                if (hasNeed($tags, ['elderly']) && (int)($v['is_outdoor'] ?? 0) === 1 && (int)($v['visit_duration_min'] ?? 90) > ELDERLY_LONG_VISIT_MIN) {
                    $add('ACCESS', 'violation', $dayNo, $title, "long outdoor place ({$v['visit_duration_min']} min) for an elderly traveller");
                }

                // DIETARY
                if ($category === 'food' || strtolower((string)$v['item_type']) === 'food') {
                    if ($pref['dietary_preference'] === 'halal' && (int)($v['halal_status'] ?? 0) !== 1) {
                        $add('DIETARY', 'violation', $dayNo, $title, 'food stop not marked halal');
                    }
                    if ($pref['dietary_preference'] === 'vegetarian' && !preg_match('/vegetarian|vegan/i', (string)$v['place_name'] . ' ' . (string)$v['description'])) {
                        $add('DIETARY', 'violation', $dayNo, $title, 'food stop not described as vegetarian or vegan');
                    }
                }

                // FESTIVAL
                if ($category === 'festival' || strtolower((string)$v['item_type']) === 'festival') {
                    $fs = (string)($v['festival_start_date'] ?? '');
                    $fe = (string)($v['festival_end_date'] ?? '');
                    if ($fs === '' || $fe === '' || $fs === '0000-00-00' || $fe === '0000-00-00') {
                        $add('FESTIVAL', 'violation', $dayNo, $title, 'festival without dates scheduled');
                    } elseif ($date < $fs || $date > $fe) {
                        $add('FESTIVAL', 'violation', $dayNo, $title, "scheduled {$date}, festival runs {$fs} to {$fe}");
                    }
                }
            }

            // REGION (visits and meal places)
            $stopEast = $v['state'] !== null ? in_array(strtolower((string)$v['state']), EAST_STATES, true) : $point[1] >= EAST_FROM_LNG;
            if ($states && !in_array(strtolower((string)$v['state']), $states, true)) {
                $add('REGION', 'violation', $dayNo, $title, 'in ' . ($v['state'] ?? 'unknown state') . ', chosen ' . $pref['preferred_states']);
            }
            if (!$states && $stopEast !== $originEast) {
                $add('REGION', 'violation', $dayNo, $title, 'in ' . ($stopEast ? 'East' : 'Peninsular') . ' Malaysia, trip starts in ' . ($originEast ? 'East' : 'Peninsular') . ' Malaysia');
            }

            // LEGS (visits and meal places: every row with a point)
            $straight = haversineKm($previous[0], $previous[1], $point[0], $point[1]);
            if ($km === null || $travel === null) {
                $add('LEGS', 'violation', $dayNo, $title, 'leg distance or travel time missing');
            } else {
                $kmLow = LEG_KM_LOW * $straight - 0.05;
                $kmHigh = max(LEG_KM_HIGH * $straight, $straight + LEG_KM_HIGH_EXTRA);
                if ($km < $kmLow || $km > $kmHigh) {
                    $add('LEGS', 'violation', $dayNo, $title, sprintf('stored %.2f km, straight line from the previous point %.2f km (allowed %.2f-%.2f)', $km, $straight, max(0, $kmLow), $kmHigh));
                }
                $nominal = nominalMinutes($pref['transport_type'], $km);
                $minLow = (int)floor(LEG_MIN_LOW * $nominal) - 2;
                $minHigh = (int)ceil(LEG_MIN_HIGH * $nominal) + 15;
                if ($travel < $minLow || $travel > $minHigh) {
                    $add('LEGS', 'violation', $dayNo, $title, "stored {$travel} min for {$km} km, nominal {$nominal} min (allowed {$minLow}-{$minHigh})");
                }
            }

            // DISTANCE (the legs to and from a meal place count)
            if (!$isFirstOfTrip && !$isMove && $km !== null) {
                $dayKm += $km;
                $legLimit = LEG_LIMIT_KM[$pref['transport_type']];
                if ($km > $legLimit) $add('DISTANCE', 'violation', $dayNo, $title, sprintf('one leg of %.1f km, plausible at most %.0f km by %s', $km, $legLimit, $pref['transport_type']));
            }
            $previous = $point;
            if (!$isMeal && $dayNo === $tripDays && $end !== null) $lastVisit = ['title' => $title, 'end' => $end, 'point' => $point];
        }
        // A night: the next day starts at its hotel, else at its base, and its first leg is measured from there.
        $lastRow = null;   // the last visit or meal at a place: where the traveller leaves for the night
        foreach ($dayRows as $r) if ($r['place_id'] !== null && toMinutes($r['end_time']) !== null) $lastRow = $r;
        if (isset($nightPoint[$dayNo]) && $lastRow !== null) {
            $night = $nightPoint[$dayNo];
            $from = [(float)($lastRow['item_latitude'] ?? $lastRow['place_lat']), (float)($lastRow['item_longitude'] ?? $lastRow['place_lng'])];
            $straight = haversineKm($from[0], $from[1], $night[0], $night[1]);
            // Estimates: the road is 1.3 x the straight line (RS); on real roads only a lower bound is sure.
            $backKm = measuredFigures() ? LEG_KM_LOW * $straight : $straight * ROAD_FACTOR;
            $nominal = nominalMinutes($pref['transport_type'], $backKm);
            $backMin = measuredFigures() ? max(0, (int)floor(LEG_MIN_LOW * $nominal) - 2) : $nominal;
            $leaves = (int)toMinutes($lastRow['end_time']);
            // BASE: back by the end of the day window, the leg back inside the hard daily distance.
            if ($leaves + $backMin > $profile['hard_end']) {
                $add('BASE', 'violation', $dayNo, (string)$lastRow['item_title'], sprintf('leaves %s, %d min back to where the night is spent, day ends %s', hm($leaves), $backMin, hm($profile['hard_end'])));
            }
            if ($dayKm + $backKm > $profile['hard_km']) {
                $add('BASE', 'violation', $dayNo, null, sprintf('day travels %.1f km with the way back to where the night is spent, hard limit %.1f km', $dayKm + $backKm, $profile['hard_km']));
            }
            // HOTEL_TIME: the hotel row begins when the traveller can have arrived, not hours later.
            if (isset($hotelRows[$dayNo])) {
                $h = $hotelRows[$dayNo];
                $arrive = $leaves + ($h['travel_time_min'] !== null ? (int)$h['travel_time_min'] : $backMin);
                $hs = toMinutes($h['start_time']);
                if ($hs === null || $hs < $arrive - 1) {
                    $add('HOTEL_TIME', 'violation', $dayNo, (string)$h['item_title'], 'hotel row begins ' . ($hs === null ? 'without a time' : hm($hs)) . ', the traveller arrives ' . hm($arrive) . ' at the earliest');
                } elseif ($hs > $arrive + HOTEL_LATE_MIN) {
                    $add('HOTEL_TIME', 'violation', $dayNo, (string)$h['item_title'], 'hotel row begins ' . hm($hs) . ', the traveller arrives ' . hm($arrive));
                }
            }
        }
        if (isset($nightPoint[$dayNo])) $previous = $nightPoint[$dayNo];
        // DINNER: a day followed by a night has one dinner, unless the dinner window does not fit into the day window.
        if (isset($nightPoint[$dayNo]) && ($byDay[$dayNo] ?? []) && MEAL_WINDOWS['dinner'][0] + $profile['meal_min'] <= $profile['hard_end']) {
            $dinners = count(array_filter($dayRows, static fn($r) => ($r['meal_slot'] ?? null) === 'dinner'));
            $foodDinner = false;
            foreach ($byDay[$dayNo] as $dv) {
                $s0 = toMinutes($dv['start_time']);
                if (strtolower((string)($dv['category'] ?? '')) === 'food' && $s0 !== null && $s0 >= MEAL_WINDOWS['dinner'][0] && $s0 <= MEAL_WINDOWS['dinner'][1]) $foodDinner = true;
            }
            if ($dinners > 1 || ($dinners === 0 && !$foodDinner)) {
                $add('DINNER', 'violation', $dayNo, null, "{$dinners} dinner row(s) on a day followed by a night" . ($foodDinner ? ' (and a food visit in the dinner window)' : ''));
            }
        }
        $dayKmByDay[$dayNo] = $dayKm;
        if ($dayKm > $profile['hard_km']) {
            $add('DISTANCE', 'violation', $dayNo, null, sprintf('day travels %.1f km, hard limit %.1f km (recommended %.1f)', $dayKm, $profile['hard_km'], $profile['day_km']));
        } elseif ($dayKm > $profile['day_km']) {
            $add('DISTANCE', 'warning', $dayNo, null, sprintf('day travels %.1f km, recommended %.1f km', $dayKm, $profile['day_km']));
        }

        // MEAL: a day whose visits span a meal window has that meal. The traveller is out across
        // the window when a visit ends by its latest start and a later visit ends after it; the
        // meal is then a meal row starting inside the window, or a food-place visit starting there.
        $dayVisits = $byDay[$dayNo] ?? [];
        foreach (MEAL_WINDOWS as $slot => [$wOpen, $wClose]) {
            $endedBefore = false;
            $spans = false;
            foreach ($dayVisits as $dv) {
                $e = toMinutes($dv['end_time']);
                if ($e === null) continue;
                if ($endedBefore && $e > $wClose) $spans = true;
                if ($e <= $wClose) $endedBefore = true;
            }
            if (!$spans) continue;
            $had = false;
            foreach ($dayRows as $r) {
                $s = toMinutes($r['start_time']);
                if ($s === null || $s < $wOpen || $s > $wClose) continue;
                if (($r['meal_slot'] ?? null) === $slot) $had = true;
                if (!$isMealRow($r) && strtolower((string)($r['category'] ?? '')) === 'food') $had = true;
            }
            if (!$had) $add('MEAL', 'violation', $dayNo, null, "the visits span the {$slot} window " . hm($wOpen) . '-' . hm($wClose) . ", but no {$slot} starts inside it");
        }

        // CATEGORY: more visits of one category than its interest weight allows (a little 1,
        // like 2, love 3) only where the stop says category variety was relaxed (or must-see).
        // A day planned again with more or less of a category is judged by the weights asked for.
        if (csv($pref['interests']) !== ['food']) {
            $perCategory = [];
            foreach ($dayVisits as $dv) $perCategory[strtolower((string)($dv['category'] ?? ''))][] = $dv;
            foreach ($perCategory as $c => $list) {
                $limit = (int)($scenario['day_weights'][$dayNo][$c] ?? $scenario['weights'][$c] ?? 2);
                if (count($list) <= $limit) continue;
                $explained = count(array_filter($list, static fn($dv) => str_contains((string)$dv['notes'], 'relaxed to fill the day') || str_contains((string)$dv['notes'], 'must-see')));
                if ($explained < count($list) - $limit) {
                    $add('CATEGORY', 'violation', $dayNo, null, sprintf('%d "%s" visits, limit %d by its weight, only %d say variety was relaxed', count($list), $c, $limit, $explained));
                }
            }
        }

        // WEATHER: an outdoor stop on a day with a forecast chance of rain of RAIN_WARN_AT percent or more.
        $rain = $scenario['trip']['forecast'][$date] ?? null;
        if ($rain !== null && (int)$rain >= RAIN_WARN_AT) {
            foreach ($dayVisits as $dv) {
                if ((int)($dv['is_outdoor'] ?? 0) === 1 || strtolower((string)($dv['category'] ?? '')) === 'nature') {
                    $add('WEATHER', 'warning', $dayNo, (string)$dv['item_title'], "outdoor stop on a day with {$rain}% chance of rain");
                }
            }
        }
    }

    // WINDOW: a short window that the repair widened is used beyond its old end or start.
    if ($profile['window_widened'] !== null && $visits) {
        [$oldStart, $oldEnd] = $profile['window_widened'];
        $outside = false;
        foreach ($visits as $v) {
            if (toMinutes($v['start_time']) < $oldStart || toMinutes($v['end_time']) > $oldEnd) $outside = true;
        }
        if (!$outside) $add('WINDOW', 'warning', null, null, 'the widened day window is not used: every visit lies in ' . hm($oldStart) . '-' . hm($oldEnd));
    }

    // AVOID, MUST, RATED and LEARN: the traveller's own place choices and ratings.
    $resolved = $scenario['resolved'] ?? ['must' => [], 'avoid' => [], 'ratings' => []];
    $visitIds = array_map(static fn($v) => (int)$v['place_id'], array_filter($visits, static fn($v) => $v['place_id'] !== null));
    // A place to avoid keeps its other rows out too (the same landmark, sameLandmarkJudged()).
    $avoidRows = [];
    if ($resolved['avoid']) {
        $res = $conn->query('SELECT place_id, name, latitude, longitude FROM cultural_places WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND place_id IN ('
            . implode(',', array_map('intval', $resolved['avoid'])) . ')');
        $avoidRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach (array_merge($visits, array_filter($mealRows, static fn($m) => $m['place_id'] !== null)) as $v) {
        $pid = (int)($v['place_id'] ?? 0);
        if (in_array($pid, $resolved['avoid'], true)) $add('AVOID', 'violation', (int)$v['day_no'], (string)$v['item_title'], 'a place to avoid is in the itinerary');
        foreach ($avoidRows as $a) {
            if ((int)$a['place_id'] === $pid || $v['place_lat'] === null || $v['place_lng'] === null) continue;
            if (sameLandmarkJudged((string)$a['name'], (float)$a['latitude'], (float)$a['longitude'], (string)$v['place_name'], (float)$v['place_lat'], (float)$v['place_lng'])) {
                $add('AVOID', 'violation', (int)$v['day_no'], (string)$v['item_title'], "the same landmark as {$a['name']}, a place to avoid");
            }
        }
        if (isset($resolved['ratings'][$pid]) && $resolved['ratings'][$pid] <= LOW_RATING && !in_array($pid, $resolved['must'], true)) {
            $add('RATED', 'violation', (int)$v['day_no'], (string)$v['item_title'], "suggested again after the traveller rated it {$resolved['ratings'][$pid]}");
        }
    }
    $notes = array_values(array_filter($items, static fn($i) => strtolower((string)$i['item_type']) === 'note'));
    foreach ($resolved['must'] as $pid) {
        if (in_array($pid, $visitIds, true)) continue;
        $placeName = placeName($conn, $pid);
        $named = false;
        foreach ($notes as $n) {
            if ($placeName !== '' && (str_contains((string)$n['item_title'], $placeName) || str_contains((string)$n['notes'], $placeName))) $named = true;
        }
        if (!$named) $add('MUST', 'violation', null, $placeName, 'must-see place neither in the itinerary nor named in a note row');
    }
    $liked = array_filter($resolved['ratings'], static fn($r) => $r >= 4);
    if ($liked) {
        $told = false;
        foreach ($visits as $v) if (str_contains((string)$v['notes'], 'you rated')) $told = true;
        if (!$told) $add('LEARN', 'warning', null, null, 'the traveller rated places 4 or 5, but no stop says so in its reason');
    }

    // FINISH: the end point is stored, and reachable from the last stop by the end of the window.
    if (isset($scenario['trip']['end_lat'])) {
        $endPoint = [(float)$scenario['trip']['end_lat'], (float)$scenario['trip']['end_lng']];
        if ($it['end_lat'] === null || abs((float)$it['end_lat'] - $endPoint[0]) > 1e-5 || abs((float)$it['end_lng'] - $endPoint[1]) > 1e-5) {
            $add('FINISH', 'violation', null, null, 'end point not stored with the itinerary');
        }
        if ($lastVisit !== null) {
            $straight = haversineKm($lastVisit['point'][0], $lastVisit['point'][1], $endPoint[0], $endPoint[1]);
            // Estimates: the road is 1.3 x the straight line (RS); on real roads only a lower bound is sure.
            $roadKm = $GLOBALS['roadRouting'] ? LEG_KM_LOW * $straight : $straight * ROAD_FACTOR;
            $nominal = nominalMinutes($pref['transport_type'], $roadKm);
            $minutes = $GLOBALS['roadRouting'] ? max(0, (int)floor(LEG_MIN_LOW * $nominal) - 2) : $nominal;
            if ($lastVisit['end'] + $minutes > $profile['hard_end']) {
                $add('FINISH', 'violation', $tripDays, $lastVisit['title'], sprintf('last visit ends %s, %d min to the end point, day ends %s', hm($lastVisit['end']), $minutes, hm($profile['hard_end'])));
            }
            $lastKm = ($dayKmByDay[$tripDays] ?? 0.0) + $roadKm;
            if ($lastKm > $profile['hard_km']) {
                $add('FINISH', 'violation', $tripDays, null, sprintf('last day travels %.1f km with the leg to the end point, hard limit %.1f km', $lastKm, $profile['hard_km']));
            }
        }
    }

    // WEIGHTS: a loved interest gets at least as many stops as one liked a little.
    $weights = $scenario['weights'] ?? [];
    $loved = array_keys(array_filter($weights, static fn($w) => (int)$w === 3));
    $little = array_keys(array_filter($weights, static fn($w) => (int)$w === 1));
    if ($loved && $little) {
        $perCategory = [];
        foreach ($visits as $v) {
            $c = strtolower((string)($v['category'] ?? ''));
            $perCategory[$c] = ($perCategory[$c] ?? 0) + 1;
        }
        foreach ($loved as $a) {
            foreach ($little as $b) {
                $enough = WEIGHT_PLACES_PER_DAY * $tripDays;
                if (placesInArea($conn, $states, $a) < $enough || placesInArea($conn, $states, $b) < $enough) continue;
                if (($perCategory[$a] ?? 0) < ($perCategory[$b] ?? 0)) {
                    $add('WEIGHTS', 'warning', null, null, sprintf('loved "%s" got %d stops, "%s" liked a little got %d', $a, $perCategory[$a] ?? 0, $b, $perCategory[$b] ?? 0));
                }
            }
        }
    }

    // BUDGET
    $cost = intendedCost($pref, $tripDays, $items);
    $stored = (float)$it['total_estimated_cost'];
    $result['info']['recomputed_total_rm'] = $cost['total'];
    if (abs($stored - $cost['total']) > MONEY_SLACK) {
        $add('BUDGET', 'violation', null, null, sprintf('stored total RM %.2f, recomputed from the stored items RM %.2f', $stored, $cost['total']));
    }
    $budget = (float)$pref['budget'];
    if ($budget > 0 && $stored > $budget + MONEY_SLACK) {
        // itinerary_view.php and trip_summary.php show an explicit "Over Budget" state, so this is a warning.
        $add('BUDGET', 'warning', null, null, sprintf('total RM %.2f over the budget RM %.2f by RM %.2f (pages show "Over Budget")', $stored, $budget, $stored - $budget));
    }
    $paid = 0.0;
    foreach ($items as $i) {
        if (!in_array(strtolower((string)$i['item_type']), ['attraction', 'festival'], true)) continue;
        $whole = max(0.0, (float)$i['estimated_cost']) * $cost['party'];
        if ($whole <= 0) continue;
        if (str_contains((string)$i['notes'], 'Budget warning')) {
            $add('BUDGET', 'warning', (int)$i['day_no'], (string)$i['item_title'], sprintf('kept over the attraction budget to avoid an empty day (RM %.2f)', $whole));
        } else {
            $paid += $whole;
        }
    }
    if ($paid > $cost['attraction_budget'] + BUDGET_SPLIT_SLACK) {
        $add('BUDGET', 'violation', null, null, sprintf('paid attractions RM %.2f for the party, attraction share of the budget RM %.2f', $paid, $cost['attraction_budget']));
    }
}

/**
 * BASE for the whole trip: every day of an itinerary planned with bases has one, and a traveller
 * staying at the starting point has every base there.
 */
function checkBases(mysqli $conn, array $it, array $scenario, array $bases, int $tripDays, callable $add): void
{
    $res = $conn->query("SHOW COLUMNS FROM itinerary_days LIKE 'base_lat'");
    if (!$res || $res->num_rows === 0) return;
    for ($d = 1; $d <= $tripDays; $d++) {
        if (!isset($bases[$d])) { $add('BASE', 'violation', $d, null, 'no base stored for the day'); continue; }
        if ($d < $tripDays && trim($bases[$d][0]) === '') $add('BASE', 'violation', $d, null, 'the base of the night has no name');
        if (!empty($scenario['trip']['stay_at_start'])) {
            $km = haversineKm((float)$it['origin_lat'], (float)$it['origin_lng'], $bases[$d][1], $bases[$d][2]);
            if ($km > BASE_SAME_KM) $add('BASE', 'violation', $d, null, sprintf('the traveller stays at the starting point, but the base of the day is %.2f km from it', $km));
        }
    }
    if (!empty($scenario['trip']['stay_at_start']) && (int)($it['stay_at_start'] ?? 0) !== 1) $add('BASE', 'violation', null, null, 'the choice to stay at the starting point is not stored');
}

/** The numbers the planner is meant to use for these preferences. */
function intendedProfile(array $pref): array
{
    $pace = $pref['travel_pace'];
    $type = $pref['traveller_type'];
    // Who is travelling: seniors bring the rules of the elderly tag, children the family rules.
    $tags = intendedTags($pref);
    if ((int)($pref['seniors'] ?? 0) > 0 && !in_array('elderly', $tags, true)) $tags[] = 'elderly';
    $family = $type === 'family' || (int)($pref['children'] ?? 0) > 0;
    $careful = $family || hasNeed($tags, ['elderly', 'low_walking']);
    $rest = PACE_REST_MIN[$pace] + ($careful ? REST_EXTRA_CAREFUL : 0) + ($type === 'group' ? REST_EXTRA_GROUP : 0);
    $window = VISIT_WINDOW[$pref['preferred_visit_time']];
    // An explicit time replaces its side of the window; the 20:00 limit holds only without an explicit end.
    $explicitStart = toMinutes($pref['day_start_time'] ?? null);
    $explicitEnd = toMinutes($pref['day_end_time'] ?? null);
    $start = $explicitStart ?? $window['start'];
    $hardEnd = $explicitEnd ?? ($careful ? min($window['hard_end'], CAREFUL_HARD_END) : $window['hard_end']);
    $widened = null;   // the short window before the repair, when the repair applies
    if (($explicitStart === null) !== ($explicitEnd === null) && $hardEnd - $start < SHORT_WINDOW_MIN) {
        $widened = [$start, $hardEnd];
        if ($explicitStart !== null) $hardEnd = min(LATEST_END, $start + WIDENED_WINDOW_MIN);
        else $start = max(EARLIEST_START, $hardEnd - WIDENED_WINDOW_MIN);
    }
    $dayKm = DAILY_DISTANCE_KM[$pref['transport_type']][$pace];
    if (hasNeed($tags, ['low_walking', 'wheelchair', 'elderly'])) $dayKm *= CAREFUL_DISTANCE_FACTOR;
    $visitMax = VISIT_MAX;
    if ($family) $visitMax = min($visitMax, VISIT_MAX_FAMILY);
    if (hasNeed($tags, ['elderly', 'low_walking'])) $visitMax = min($visitMax, VISIT_MAX_CAREFUL);
    return [
        'target' => isset($pref['stops_per_day']) ? (int)$pref['stops_per_day'] : PACE_TARGET[$pace],
        'rest' => $rest,
        'start' => $start,
        'window_source' => $explicitStart !== null ? 'explicit start time' : $pref['preferred_visit_time'],
        'hard_end' => $hardEnd,
        'tags' => $tags,
        'day_km' => $dayKm,
        'hard_km' => max($dayKm * HARD_DISTANCE_FACTOR, $dayKm + HARD_DISTANCE_EXTRA_KM),
        'visit_max' => $visitMax,
        'meal_min' => MEAL_MIN[$pace],
        'window_widened' => $widened,
    ];
}

/** The trip's total cost and the attraction share of the budget, by the project's cost rules. */
function intendedCost(array $pref, int $days, array $items): array
{
    $party = (int)$pref['party_size'] >= 1 ? (int)$pref['party_size'] : DEFAULT_PARTY[$pref['traveller_type']];
    $transport = $pref['transport_type'];
    $budget = (float)$pref['budget'];
    $nights = max(0, $days - 1);
    $rooms = (int)ceil($party / 2);
    $vehicles = match ($transport) {
        'car' => (int)ceil($party / 5),
        'motorcycle' => (int)ceil($party / 2),
        'public_transport' => $party,
        default => 1,
    };
    $tier = TIER_DEFAULTS[$pref['budget_tier']];
    $meal = $tier['meal'];
    $hotel = $tier['hotel'];
    if ($budget > 0) {
        $meal = max(MEAL_FLOOR, min($meal, ($budget * MEAL_SHARE) / max(1, $days * MEALS_PER_DAY * $party)));
        if ($nights > 0) $hotel = max(HOTEL_FLOOR, min($hotel, ($budget * HOTEL_SHARE) / ($nights * $rooms)));
    }

    $attraction = 0.0;
    $food = 0.0;
    $hotelItems = 0.0;
    $km = 0.0;
    foreach ($items as $i) {
        $c = max(0.0, (float)$i['estimated_cost']);
        $type = strtolower((string)$i['item_type']);
        if ($type === 'hotel') $hotelItems += $c;
        elseif ($type === 'food') $food += $c;
        else $attraction += $c;
        $km += (float)($i['distance_km'] ?? 0);
    }
    $rate = TRANSPORT_RATE[$transport];
    $transportCost = round($km * $rate * ($transport === 'walking' ? 0 : $vehicles), 2);
    $accommodation = $hotelItems > 0 ? round($hotelItems * $rooms, 2) : round($nights * $hotel * $rooms, 2);
    $foodCost = max(round($days * MEALS_PER_DAY * $meal * $party, 2), round($food * $party, 2));
    $total = round(round($attraction * $party, 2) + $transportCost + $accommodation + $foodCost, 2);

    $essential = $days * MEALS_PER_DAY * $meal * $party + $nights * $hotel * $rooms + $km * $rate * $vehicles;
    return [
        'total' => $total,
        'party' => $party,
        'attraction_budget' => max(0.0, $budget - $essential - $essential * ESSENTIAL_MARGIN),
    ];
}

/**
 * When a place is open on a date, read from its stored opening hours: a list of [open, close]
 * in minutes (close above 1440 after midnight), [] when closed all day, null when the text
 * gives no usable hours. Written independently of services/OpeningHours.php.
 */
function openingRanges(string $text, string $date): ?array
{
    $text = strtolower(trim($text));
    if ($text === '') return null;
    $text = str_replace(['–', '—', 'a.m.', 'p.m.'], ['-', '-', 'am', 'pm'], $text);

    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $perDay = [];
    foreach (preg_split('/\s*[|\r\n]+\s*/', $text) as $part) {
        if (preg_match('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\s*[:,]\s*(.*)$/', trim($part), $m)) {
            foreach ($days as $index => $day) {
                if (str_starts_with($day, $m[1])) $perDay[$index] = $m[2];
            }
        }
    }
    if ($perDay) {
        $weekday = (int)date('N', strtotime($date)) - 1;
        if (!isset($perDay[$weekday])) return null;
        $text = $perDay[$weekday];
    }

    if (preg_match('/24\s*(hours|hrs|h\b)|24\/7|always open/', $text)) return [[0, 2880]];
    $ranges = [];
    if (preg_match_all('/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?\s*(?:-|to)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $open = clockMinutes((int)$m[1], (int)($m[2] ?? 0), $m[3] ?? '');
            $close = clockMinutes((int)$m[4], (int)($m[5] ?? 0), $m[6] ?? '');
            if ($open === null || $close === null) continue;
            // "5:30 - 6:00 PM": the opening time takes the marker of the closing time when that
            // keeps it before the closing time. (services/OpeningHours.php reads it as 05:30.)
            if (($m[3] ?? '') === '' && ($m[6] ?? '') !== '') {
                $same = clockMinutes((int)$m[1], (int)($m[2] ?? 0), $m[6]);
                if ($same !== null && $same < $close) $open = $same;
            }
            if ($close <= $open) $close += 1440;
            $ranges[] = [$open, $close];
        }
    }
    if ($ranges) return $ranges;
    if (str_contains($text, 'closed') && !preg_match('/\d/', $text)) return [];
    if (preg_match('/\d/', $text)) return null;
    // Hours written as words (approximations, the table of DESCRIBED_HOURS): the first that matches.
    $weekday = (int)date('N', strtotime($date)) - 1;
    foreach (DESCRIBED_HOURS as [$pattern, $unless, $hours, $weekdays]) {
        if (!preg_match($pattern, $text) || ($unless !== null && preg_match($unless, $text))) continue;
        return $weekdays === null || in_array($weekday, $weekdays, true) ? $hours : [];
    }
    return null;
}

function clockMinutes(int $hour, int $minute, string $ampm): ?int
{
    if ($hour > 24 || $minute > 59) return null;
    if ($ampm === 'pm' && $hour < 12) $hour += 12;
    if ($ampm === 'am' && $hour === 12) $hour = 0;
    return $hour * 60 + $minute;
}

/** Minutes a leg of this many road km takes at the usual speed of the transport. */
function nominalMinutes(string $transport, float $km): int
{
    if ($transport === 'public_transport') {
        if ($km <= TRANSIT_WALK_KM) return (int)ceil($km / SPEED_KMH['walking'] * 60);
        return (int)ceil($km / SPEED_KMH['public_transport'] * 60) + TRANSIT_ACCESS_MIN;
    }
    return (int)ceil($km / SPEED_KMH[$transport] * 60);
}

/**
 * Same landmark under two names, judged on its own (not PlannerRules, not PlaceNameMatcher):
 * within TWIN_NEAR_M with names that are clearly the same (one name, or the part of it in or
 * outside brackets, has the same words as the other or all its words of at least two inside the
 * other), or within TWIN_VERY_NEAR_M with names sharing most words (Dice of the word sets at
 * least TWIN_VERY_NEAR_DICE). Malay words are read as their English equal (TWIN_WORDS).
 */
function sameLandmarkJudged(string $nameA, float $latA, float $lngA, string $nameB, float $latB, float $lngB): bool
{
    $metres = haversineKm($latA, $lngA, $latB, $lngB) * 1000.0;
    if ($metres > TWIN_NEAR_M) return false;
    $best = 0.0;
    foreach (twinNameVariants($nameA) as $a) {
        foreach (twinNameVariants($nameB) as $b) {
            if (!$a || !$b) continue;
            $common = count(array_intersect($a, $b));
            $shorter = min(count($a), count($b));
            if ($common === count($a) && $common === count($b)) return true;
            if ($common === $shorter && $shorter >= 2) return true;
            $best = max($best, 2 * $common / (count($a) + count($b)));
        }
    }
    return $metres <= TWIN_VERY_NEAR_M && $best >= TWIN_VERY_NEAR_DICE;
}

/** The word sets of a name: the whole name, the part outside brackets and each part inside. */
function twinNameVariants(string $name): array
{
    $texts = [$name];
    if (preg_match_all('/\(([^)]*)\)/u', $name, $m)) {
        $texts[] = preg_replace('/\([^)]*\)/u', ' ', $name);
        foreach ($m[1] as $inside) $texts[] = $inside;
    }
    $out = [];
    foreach ($texts as $text) {
        $text = strtolower((string)(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text));
        $words = [];
        foreach (preg_split('/[^a-z0-9]+/', str_replace("'", '', $text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (in_array($w, TWIN_STOP_WORDS, true)) continue;
            $words[TWIN_WORDS[$w] ?? $w] = true;
        }
        $out[] = array_keys($words);
    }
    return $out;
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

/** Whether any of the wanted accessibility tags is among the traveller's tags. */
function hasNeed(array $tags, array $wanted): bool
{
    return (bool)array_intersect($tags, $wanted);
}

/**
 * The traveller's accessibility tags: the accessibility_tags column when it is set; otherwise
 * the list after the last "AI:" in accessibility_needs; otherwise the tag names written as
 * whole words in the traveller's own text.
 */
function intendedTags(array $pref): array
{
    if (isset($pref['accessibility_tags'])) return array_map('strtolower', csv((string)$pref['accessibility_tags']));
    $text = strtolower((string)$pref['accessibility_needs']);
    $pos = strrpos($text, 'ai:');
    if ($pos !== false) {
        return array_values(array_unique(array_map(static fn($t) => str_replace([' ', '-'], '_', $t), csv(substr($text, $pos + 3)))));
    }
    $tags = [];
    foreach (['elderly', 'wheelchair', 'avoid_stairs', 'low_walking', 'avoid_heat', 'step_free'] as $tag) {
        if (preg_match('/\b' . str_replace('_', '[ _-]', $tag) . '\b/', $text)) $tags[] = $tag;
    }
    return $tags;
}

function placeName(mysqli $conn, int $placeId): string
{
    $stmt = $conn->prepare('SELECT name FROM cultural_places WHERE place_id = ?');
    $stmt->bind_param('i', $placeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string)$row[0] : '';
}

/** Active places with coordinates of one category in the chosen states (all states when none). */
function placesInArea(mysqli $conn, array $states, string $category): int
{
    $sql = "SELECT COUNT(*) FROM cultural_places WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL AND LOWER(category) = ?";
    $types = 's';
    $args = [$category];
    if ($states) {
        $sql .= ' AND LOWER(state) IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        $types .= str_repeat('s', count($states));
        $args = array_merge($args, $states);
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}

function csv(string $text): array
{
    return array_values(array_filter(array_map('trim', explode(',', $text)), static fn($x) => $x !== ''));
}

function toMinutes($time): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$time, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

function hm(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// ---------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------

function report(array $results, string $dbName, bool $road, bool $json, array $after): void
{
    $totals = [];
    foreach ($results as $r) {
        foreach ($r['findings'] as $f) {
            $totals[$f['code']][$f['severity']] = ($totals[$f['code']][$f['severity']] ?? 0) + 1;
        }
    }
    ksort($totals);

    if ($json) {
        echo json_encode([
            'database' => $dbName,
            'routing' => $road ? 'road' : 'estimates only',
            'scenarios' => $results,
            'totals' => $totals,
            'test_traveller_after_run' => $after,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }

    echo "Planner check on {$dbName}, routing: " . ($road ? 'road (public servers allowed)' : 'estimates only, no network') . "\n\n";
    printf("%-46s %4s %5s %7s %5s %6s %5s %5s %8s %7s %4s %-10s\n", 'SCENARIO', 'DAYS', 'STOPS', 'KM', 'MEALS', 'BREAKS', 'VIOL', 'WARN', 'NO_HOURS', 'SECONDS', 'REQ', 'PLAN');
    foreach ($results as $r) {
        $v = count(array_filter($r['findings'], static fn($f) => $f['severity'] === 'violation'));
        $w = count(array_filter($r['findings'], static fn($f) => $f['severity'] === 'warning'));
        printf("%-46s %4d %5d %7.1f %5d %6d %5d %5d %8d %7.1f %4d %-10s\n", $r['scenario'], $r['days'], $r['stops'], $r['info']['km'] ?? 0, $r['info']['meals_at_places'] ?? 0, $r['info']['meal_breaks'] ?? 0,
            $v, $w, $r['info']['no_usable_hours'] ?? 0, $r['seconds'], max([0] + (array)($r['info']['requests'] ?? [])), $r['info']['plan'] ?? '-');
    }

    foreach (['violation' => 'VIOLATIONS', 'warning' => 'WARNINGS'] as $severity => $heading) {
        $grouped = [];
        foreach ($results as $r) {
            foreach ($r['findings'] as $f) {
                if ($f['severity'] === $severity) $grouped[$f['code']][] = $f;
            }
        }
        ksort($grouped);
        echo "\n{$heading} BY CODE" . ($grouped ? '' : ': none') . "\n";
        foreach ($grouped as $code => $list) {
            echo "[{$code}] " . count($list) . "\n";
            foreach ($list as $f) {
                echo '  ' . $f['scenario'] . ($f['day'] !== null ? ' day ' . $f['day'] : '') . ($f['stop'] !== null ? ' "' . $f['stop'] . '"' : '') . ': ' . $f['detail'] . "\n";
            }
        }
    }

    echo "\nTOTALS PER CODE\n";
    printf("  %-12s %10s %9s\n", 'CODE', 'VIOLATIONS', 'WARNINGS');
    $sumV = $sumW = 0;
    foreach ($totals as $code => $t) {
        printf("  %-12s %10d %9d\n", $code, $t['violation'] ?? 0, $t['warning'] ?? 0);
        $sumV += $t['violation'] ?? 0;
        $sumW += $t['warning'] ?? 0;
    }
    printf("  %-12s %10d %9d\n", 'ALL', $sumV, $sumW);
    $noHours = array_sum(array_map(static fn($r) => $r['info']['no_usable_hours'] ?? 0, $results));
    echo "\nINFO: {$noHours} stop(s) at places without usable opening hours (not checked for OPEN_HOURS).\n";
    $meals = array_sum(array_map(static fn($r) => $r['info']['meals_at_places'] ?? 0, $results));
    $breaks = array_sum(array_map(static fn($r) => $r['info']['meal_breaks'] ?? 0, $results));
    echo "INFO: {$meals} meal(s) at a food place, {$breaks} meal break(s) without a place (STOPS counts visits only).\n";
    $most = [];
    foreach ($results as $r) foreach ((array)($r['info']['requests'] ?? []) as $op => $n) $most[$op] = max($most[$op] ?? 0, $n);
    ksort($most);
    echo 'INFO: most routing requests of one call (limit): ' . implode(', ', array_map(static fn($op, $n) => "{$op} {$n} (" . REQUEST_LIMITS[$op] . ')', array_keys($most), $most)) . ".\n";
    echo "INFO: REQ is the most routing requests of one call in the scenario, PLAN a fingerprint of the stored plan.\n";
    echo "Test traveller {$after['traveller_id']} after the run: {$after['itineraries']} itinerary(ies), {$after['preferences']} preference(s), {$after['ratings']} rating(s).\n";
}
