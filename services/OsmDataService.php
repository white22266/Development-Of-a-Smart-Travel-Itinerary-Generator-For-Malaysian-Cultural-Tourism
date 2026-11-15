<?php
// services/OsmDataService.php
// Reads places from OpenStreetMap through the Overpass API (free, no key, ODbL licence).
// Used by the place sync job: it never runs inside a traveller's page request.

require_once __DIR__ . '/OpenDataHttp.php';

class OsmDataService
{
    /** ISO 3166-2 codes of the states and federal territories, as tagged on their OSM boundaries. */
    public const STATE_ISO = [
        'Johor' => 'MY-01', 'Kedah' => 'MY-02', 'Kelantan' => 'MY-03', 'Melaka' => 'MY-04',
        'Negeri Sembilan' => 'MY-05', 'Pahang' => 'MY-06', 'Penang' => 'MY-07', 'Perak' => 'MY-08',
        'Perlis' => 'MY-09', 'Selangor' => 'MY-10', 'Terengganu' => 'MY-11', 'Sabah' => 'MY-12',
        'Sarawak' => 'MY-13', 'Kuala Lumpur' => 'MY-14', 'Labuan' => 'MY-15', 'Putrajaya' => 'MY-16',
    ];

    /** Public Overpass servers, tried in this order. */
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    private const LODGING_KINDS = 'hotel|guest_house|hostel|motel|resort|apartment|chalet';

    /** An answer whose data is older than this is used only when no server has a newer one. */
    private const MAX_DATA_AGE_DAYS = 30;

    private static string $lastError = '';
    private static string $lastNote = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** Something worth telling about the last answer although it was used, or ''. */
    public static function lastNote(): string
    {
        return self::$lastNote;
    }

    /**
     * Named places a visitor might go to in one state: museums, historic sites, attractions,
     * places of worship, parks and nature, malls and markets. Lodging and restaurants are excluded.
     *
     * @return array<int,array>|null normalised elements, or null when every Overpass server failed
     */
    public static function visitorPlaces(string $state, int $ttl = 518400): ?array
    {
        $iso = self::STATE_ISO[$state] ?? null;
        if ($iso === null) {
            self::$lastError = "Unknown state: {$state}";
            return null;
        }
        $lodging = self::LODGING_KINDS;
        $query = <<<OQL
[out:json][timeout:240];
area["ISO3166-2"="{$iso}"]->.a;
(
  nwr(area.a)["name"]["tourism"]["tourism"!~"^({$lodging}|camp_site|caravan_site|information|picnic_site)$"];
  nwr(area.a)["name"]["historic"];
  nwr(area.a)["name"]["amenity"~"^(place_of_worship|marketplace|theatre|arts_centre|planetarium)$"];
  nwr(area.a)["name"]["leisure"~"^(park|garden|nature_reserve|water_park)$"];
  nwr(area.a)["name"]["natural"~"^(beach|peak|waterfall|cave_entrance|hot_spring)$"];
  nwr(area.a)["name"]["shop"~"^(mall|department_store)$"];
  nwr(area.a)["name"]["man_made"~"^(tower|lighthouse)$"];
  nwr(area.a)["name"]["boundary"~"^(national_park|protected_area)$"];
  nwr(area.a)["name"]["highway"="pedestrian"]["wikidata"];
);
out center tags;
OQL;
        return self::run($query, 'places_' . $iso, $ttl);
    }

    /** Named hotels, guest houses, hostels and resorts in one state. */
    public static function lodging(string $state, int $ttl = 518400): ?array
    {
        $iso = self::STATE_ISO[$state] ?? null;
        if ($iso === null) {
            self::$lastError = "Unknown state: {$state}";
            return null;
        }
        $lodging = self::LODGING_KINDS;
        $query = <<<OQL
[out:json][timeout:180];
area["ISO3166-2"="{$iso}"]->.a;
nwr(area.a)["name"]["tourism"~"^({$lodging})$"];
out center tags;
OQL;
        return self::run($query, 'lodging_' . $iso, $ttl);
    }

    /**
     * Public transport stops of one state, in ONE request: rail (stations, halts, tram stops,
     * LRT / MRT / monorail / KTM stations), bus (bus stops and bus stations) and ferry terminals.
     * Stops without a name are kept (many bus stops have none).
     *
     * @return array<int,array>|null normalised elements (name may be ''), or null when every Overpass server failed
     */
    public static function transitStops(string $state, int $ttl = 518400): ?array
    {
        $iso = self::STATE_ISO[$state] ?? null;
        if ($iso === null) {
            self::$lastError = "Unknown state: {$state}";
            return null;
        }
        $query = <<<OQL
[out:json][timeout:240];
area["ISO3166-2"="{$iso}"]->.a;
(
  nwr(area.a)["railway"~"^(station|halt|tram_stop)$"];
  nwr(area.a)["station"~"^(light_rail|subway|monorail|train)$"];
  nwr(area.a)["public_transport"="station"]["train"="yes"];
  nwr(area.a)["highway"="bus_stop"];
  nwr(area.a)["amenity"="bus_station"];
  nwr(area.a)["amenity"="ferry_terminal"];
);
out center tags;
OQL;
        return self::run($query, 'transit_' . $iso, $ttl, true);
    }

    private static function run(string $query, string $cacheKey, int $ttl, bool $keepUnnamed = false): ?array
    {
        self::$lastError = '';
        self::$lastNote = '';

        // An answer is used when it is whole and has something in it:
        //   a "remark" means the server gave up part-way (timeout, out of memory);
        //   nothing at all means the server does not know the state's area. Every state has
        //   places and lodging, so an empty answer is a broken server, not a fact.
        $whole = static fn(array $json): bool => !empty($json['elements']) && empty($json['remark']);
        // Some mirrors stop updating for months. Their answers are only a last resort.
        $current = static fn(array $json): bool => $whole($json) && self::dataAgeDays($json) <= self::MAX_DATA_AGE_DAYS;

        // The public servers are often busy. A background job goes round them twice: first
        // for an up-to-date answer, then for any whole answer.
        $rounds = OpenDataHttp::isPatient() ? 2 : 1;
        for ($round = 1; $round <= $rounds; $round++) {
            if ($round > 1) sleep(45);
            foreach (self::ENDPOINTS as $endpoint) {
                $json = OpenDataHttp::postFormJson($endpoint, ['data' => $query], $ttl, [
                    'namespace' => 'overpass',   // one cache for all mirrors
                    'cache_key' => $cacheKey,
                    'timeout' => 300,
                    'stale_ok' => false,
                    'validate' => $round < $rounds ? $current : $whole,
                    'retries' => 0,              // a busy server is skipped, not waited for
                ]);
                if (is_array($json)) {
                    $days = self::dataAgeDays($json);
                    if ($days > self::MAX_DATA_AGE_DAYS) {
                        self::$lastNote = 'the server that answered has OpenStreetMap data from ' . substr((string)$json['osm3s']['timestamp_osm_base'], 0, 10)
                            . " ({$days} days old); run the sync again later for the current data";
                    }
                    return self::normaliseAll($json['elements'], $keepUnnamed);
                }
                self::$lastError = OpenDataHttp::lastError();
            }
        }

        // Every server failed: an expired copy is still better than nothing.
        $stale = OpenDataHttp::cachedJson('overpass', $cacheKey);
        return ($stale !== null && $whole($stale)) ? self::normaliseAll($stale['elements'], $keepUnnamed) : null;
    }

    /** How old the OpenStreetMap data behind an answer is, in days. 0 when the answer does not say. */
    private static function dataAgeDays(array $json): int
    {
        $time = strtotime((string)($json['osm3s']['timestamp_osm_base'] ?? ''));
        return $time === false ? 0 : max(0, intdiv(time() - $time, 86400));
    }

    /** @return array<int,array{ref:string,name:string,names:string[],lat:float,lng:float,tags:array}> */
    private static function normaliseAll(array $elements, bool $keepUnnamed = false): array
    {
        $out = [];
        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = trim((string)($tags['name'] ?? ''));
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lng = $element['lon'] ?? $element['center']['lon'] ?? null;
            if (($name === '' && !$keepUnnamed) || $lat === null || $lng === null) continue;
            $out[] = [
                'ref' => $element['type'] . '/' . $element['id'],
                'name' => $name,
                'names' => self::names($tags),
                'lat' => (float)$lat,
                'lng' => (float)$lng,
                'tags' => $tags,
            ];
        }
        return $out;
    }

    /** Every name OSM records for an element (local, English, Malay, alternative). */
    public static function names(array $tags): array
    {
        $names = [];
        foreach (['name', 'name:en', 'name:ms', 'alt_name', 'official_name', 'old_name', 'short_name', 'int_name'] as $key) {
            foreach (explode(';', (string)($tags[$key] ?? '')) as $name) {
                $name = trim($name);
                if ($name !== '') $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }
}
