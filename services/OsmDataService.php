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

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
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

    private static function run(string $query, string $cacheKey, int $ttl): ?array
    {
        self::$lastError = '';
        // A "remark" means the server gave up part-way (timeout, out of memory): partial data, not usable.
        $complete = static fn(array $json): bool => isset($json['elements']) && empty($json['remark']);

        // The public servers are often busy. A background job goes round them twice.
        $rounds = OpenDataHttp::isPatient() ? 2 : 1;
        for ($round = 1; $round <= $rounds; $round++) {
            if ($round > 1) sleep(45);
            foreach (self::ENDPOINTS as $endpoint) {
                $json = OpenDataHttp::postFormJson($endpoint, ['data' => $query], $ttl, [
                    'namespace' => 'overpass',   // one cache for all mirrors
                    'cache_key' => $cacheKey,
                    'timeout' => 300,
                    'stale_ok' => false,
                    'validate' => $complete,
                    'retries' => 0,              // a busy server is skipped, not waited for
                ]);
                if (is_array($json)) {
                    return self::normaliseAll($json['elements']);
                }
                self::$lastError = OpenDataHttp::lastError();
            }
        }

        // Every server failed: an expired copy is still better than nothing.
        $stale = OpenDataHttp::cachedJson('overpass', $cacheKey);
        return ($stale !== null && $complete($stale)) ? self::normaliseAll($stale['elements']) : null;
    }

    /** @return array<int,array{ref:string,name:string,names:string[],lat:float,lng:float,tags:array}> */
    private static function normaliseAll(array $elements): array
    {
        $out = [];
        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = trim((string)($tags['name'] ?? ''));
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lng = $element['lon'] ?? $element['center']['lon'] ?? null;
            if ($name === '' || $lat === null || $lng === null) continue;
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
