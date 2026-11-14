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
//
// Meal rows (itinerary_items.meal_slot set) are judged as lunch or dinner, not as stops: STOPS
// counts visits only, and MEAL checks that a day whose visits span a meal window has that meal
// inside its window, between two visits, at a suitable place or as a break that says so.
// WEATHER warns about outdoor stops on very wet days of scenarios with a forecast override.
//
// Edit scenarios (<base>__edits): the base scenario is generated, then changed through
// ItineraryEditService as the review page and the AI editor change it (replace a stop with the
// first suggestion, remove a stop, move a stop to another day, add a feasible place, plan a day
// again with more of one category and less of another, the nearest lodging as the hotel of
// night 1, optimise), and every check below is run again on the result. Then changes that
// break a rule (a place closed that day, on the avoid list, already in the trip, in another
// state) must be refused and leave every row as it was (REFUSE).
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

$options = getopt('', ['scenario:', 'keep', 'json', 'road', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/check_planner.php [--scenario=<name>] [--keep] [--json] [--road]\n";
    exit(0);
}

// Without --road no routing server is asked: services/RouteService.php then estimates every
// distance and time from the straight line. Set before the environment file is read, which
// keeps a variable that is already set.
putenv('ROUTING_ESTIMATES_ONLY=' . (isset($options['road']) ? '0' : '1'));

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
    'penang_solo_walking_end_only_morning' => [
        'pref' => ['trip_days' => 1, 'budget' => 300, 'budget_tier' => 'normal', 'transport_type' => 'walking', 'traveller_type' => 'solo', 'party_size' => 1,
            'travel_pace' => 'normal', 'dietary_preference' => 'none', 'preferred_visit_time' => 'any', 'accessibility_needs' => '',
            'interests' => 'heritage,culture', 'preferred_states' => 'Penang', 'preferred_districts' => '',
            'day_end_time' => '11:00:00'],
        'trip' => ['start_date' => '2026-12-09', 'origin_name' => 'Komtar bus hub, George Town', 'origin_lat' => 5.4145, 'origin_lng' => 100.3292],
    ],
];

/** The base scenarios that also run as edit scenarios (<base>__edits). */
const EDIT_BASES = ['kl_couple_car_normal', 'sabah_family_motorcycle_normal', 'penang_group_transit_packed_festival',
                    'johor_couple_car_relaxed_meals_at_places', 'kl_couple_car_must_and_avoid'];

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
foreach ($selected as $name => $scenario) {
    $results[] = runScenario($pipelineConn, $checkConn, $travellerId, $name, $scenario, isset($options['keep']));
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
        $out = runPlannerPipeline($pipelineConn, $travellerId, $preferenceId, $scenario['trip']);
        $result['itinerary_id'] = $out['itinerary_id'];
        foreach ($out['notes'] as $note) {
            $result['findings'][] = finding('OPTIMISE', 'warning', $name, null, null, $note);
        }
        if (!empty($scenario['edits'])) runEditSequence($pipelineConn, $checkConn, $out['itinerary_id'], $preferenceId, $scenario, $name, $result);
        checkItinerary($checkConn, $out['itinerary_id'], $scenario, $name, $result);
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
    $done = [];
    $note = static function (string $step, array $r) use (&$done, $add, $shapeOk): bool {
        if (!$shapeOk($step, $r)) return false;
        $done[$step] = $r['ok'] ? 'done' : 'refused ' . $r['code'];
        if (!$r['ok']) $add('EDIT', 'warning', null, null, "{$step} refused ({$r['code']}): {$r['message']}");
        return (bool)$r['ok'];
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
        $s = ItineraryEditService::suggestReplacements($p, $id, (int)$stop['item_id'], 1, 0);
        if ($shapeOk('suggest', $s) && $s['ok'] && !empty($s['options'])) {
            $placeId = (int)$s['options'][0]['place_id'];
            $r = ItineraryEditService::replaceStop($p, $id, (int)$stop['item_id'], $placeId);
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
            $r = ItineraryEditService::removeStop($p, $id, (int)$v['item_id']);
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
                $r = ItineraryEditService::moveStop($p, $id, (int)$v['item_id'], $to);
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
            $check = ItineraryEditService::canAdd($p, $id, $d, (int)$place['place_id']);
            if (!$check['ok']) continue;
            $r = ItineraryEditService::addStop($p, $id, $d, (int)$place['place_id']);
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
    if ($note('regenerate', ItineraryEditService::regenerateDay($p, $id, $tripDays, ['more' => [$more], 'less' => $more === $less ? [] : [$less]]))) {
        // CATEGORY judges that day by the weights asked for: more = loved (3), less = a little (1).
        $scenario['day_weights'][$tripDays] = [$more => 3] + ($more === $less ? [] : [$less => 1]);
    }

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
            $r = ItineraryEditService::setHotels($p, $id, [1 => $hotel]);
            if ($note('hotel', $r) && !empty($r['refused_nights'])) {
                $add('EDIT', 'warning', 2, null, 'hotel of night 1 not saved: ' . implode(' ', array_column($r['refused_nights'], 1)));
            }
        }
    }

    // 7. Optimise the route.
    $note('optimise', ItineraryEditService::optimise($p, $id));
    $result['info']['edits'] = $done;

    // REFUSE: each change below breaks a rule, so it must be refused and change no row.
    $refuse = static function (string $what, int $itemId, int $placeId, ?array $codes) use ($p, $c, $id, $add, &$result): void {
        $before = editSnapshot($c, $id);
        $r = ItineraryEditService::replaceStop($p, $id, $itemId, $placeId);
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
    $s = ItineraryEditService::suggestReplacements($p, $id, (int)$first['item_id'], 1, 0);
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
                if ($start < $profile['start']) $add('START', 'violation', $dayNo, $title, 'starts ' . hm($start) . ', earliest ' . hm($profile['start']) . " ({$profile['window_source']})");
                if ($end > $profile['hard_end']) $add('HARD_END', 'violation', $dayNo, $title, 'ends ' . hm($end) . ', latest ' . hm($profile['hard_end']));
                // WAIT: a visit starts at most MAX_WAIT_MIN after it can be reached; after a meal no rest is due.
                if (!$isMeal && $travel !== null) {
                    $reach = ($prevEnd === null ? $profile['start'] : $prevEnd + ($prevWasMeal ? 0 : $profile['rest'])) + $travel;
                    $wait = $start - max($reach, $profile['start']);
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
                if ($visitsBefore === 0 || $visitsAfter === 0) $add('MEAL', 'violation', $dayNo, $title, "{$slot} not between two visits");
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
            if (!$isFirstOfTrip && $km !== null) {
                $dayKm += $km;
                $legLimit = LEG_LIMIT_KM[$pref['transport_type']];
                if ($km > $legLimit) $add('DISTANCE', 'violation', $dayNo, $title, sprintf('one leg of %.1f km, plausible at most %.0f km by %s', $km, $legLimit, $pref['transport_type']));
            }
            $previous = $point;
            if (!$isMeal && $dayNo === $tripDays && $end !== null) $lastVisit = ['title' => $title, 'end' => $end, 'point' => $point];
        }
        // A hotel night: the next day starts at the hotel, and its first leg is measured from there.
        foreach ($items as $h) {
            if ((int)$h['day_no'] === $dayNo && strtolower((string)$h['item_type']) === 'hotel' && $h['item_latitude'] !== null && $h['item_longitude'] !== null) {
                $previous = [(float)$h['item_latitude'], (float)$h['item_longitude']];
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
    foreach (array_merge($visits, array_filter($mealRows, static fn($m) => $m['place_id'] !== null)) as $v) {
        $pid = (int)($v['place_id'] ?? 0);
        if (in_array($pid, $resolved['avoid'], true)) $add('AVOID', 'violation', (int)$v['day_no'], (string)$v['item_title'], 'a place to avoid is in the itinerary');
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
    printf("%-46s %4s %5s %5s %6s %5s %5s %8s %7s\n", 'SCENARIO', 'DAYS', 'STOPS', 'MEALS', 'BREAKS', 'VIOL', 'WARN', 'NO_HOURS', 'SECONDS');
    foreach ($results as $r) {
        $v = count(array_filter($r['findings'], static fn($f) => $f['severity'] === 'violation'));
        $w = count(array_filter($r['findings'], static fn($f) => $f['severity'] === 'warning'));
        printf("%-46s %4d %5d %5d %6d %5d %5d %8d %7.1f\n", $r['scenario'], $r['days'], $r['stops'], $r['info']['meals_at_places'] ?? 0, $r['info']['meal_breaks'] ?? 0,
            $v, $w, $r['info']['no_usable_hours'] ?? 0, $r['seconds']);
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
    echo "Test traveller {$after['traveller_id']} after the run: {$after['itineraries']} itinerary(ies), {$after['preferences']} preference(s), {$after['ratings']} rating(s).\n";
}
