<?php
// services/GeocodingService.php
// Turns text into coordinates and coordinates into addresses with OpenStreetMap data.
// Search-as-you-type uses Photon (built for it); Nominatim is the fallback and does the
// reverse lookups. Both are free and need no key. Results are cached, and Nominatim is
// limited to one request per second by OpenDataHttp.

require_once __DIR__ . '/OpenDataHttp.php';

class GeocodingService
{
    // West, south, east, north: Peninsular Malaysia plus Sabah, Sarawak and Labuan.
    private const MALAYSIA_BBOX = '99.5,0.8,119.5,7.6';

    /**
     * Find places or addresses in Malaysia.
     *
     * @return array<int,array{name:string,detail:string,lat:float,lng:float}>
     */
    public static function search(string $query, int $limit = 6): array
    {
        $query = trim((string)preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) < 3 || mb_strlen($query) > 150) return [];
        $limit = max(1, min(10, $limit));

        $results = self::searchPhoton($query, $limit);
        if (!$results) {
            $results = self::searchNominatim($query, $limit);
        }
        return $results;
    }

    /** Best single match for a typed place name, or null. */
    public static function locate(string $query): ?array
    {
        return self::search($query, 1)[0] ?? null;
    }

    /**
     * What is at a coordinate: a short postal-style address, and the state and district it lies in.
     *
     * @return array{address:?string,state_iso:?string,district:?string,areas:string[]}|null
     *         null when the lookup fails. state_iso is an ISO 3166-2 code such as "MY-04";
     *         areas lists the names around the point from the smallest (suburb) to the district
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'format' => 'jsonv2',
            'lat' => round($lat, 5),
            'lon' => round($lng, 5),
            'zoom' => 18,
            'addressdetails' => 1,
            'accept-language' => 'ms,en',   // addresses in Malaysia are written with the Malay names
        ]);
        $json = OpenDataHttp::getJson($url, 7776000);   // 90 days
        $a = $json['address'] ?? null;
        if (!is_array($a)) return null;

        $street = trim(($a['house_number'] ?? '') . ' ' . ($a['road'] ?? $a['pedestrian'] ?? ''));
        $area = $a['suburb'] ?? $a['neighbourhood'] ?? $a['village'] ?? $a['hamlet'] ?? '';
        $town = trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? $a['town'] ?? $a['municipality'] ?? $a['county'] ?? ''));
        $parts = array_values(array_unique(array_filter([$street, $area, $town, $a['state'] ?? ''], static fn($p) => trim((string)$p) !== '')));
        $district = trim((string)($a['district'] ?? $a['county'] ?? ''));
        $areas = [];
        foreach (['suburb', 'quarter', 'neighbourhood', 'village', 'town', 'city', 'district', 'county'] as $key) {
            $value = trim((string)($a[$key] ?? ''));
            if ($value !== '') $areas[] = $value;
        }
        return [
            'address' => $parts ? mb_substr(implode(', ', $parts), 0, 255) : null,
            'state_iso' => isset($a['ISO3166-2-lvl4']) ? (string)$a['ISO3166-2-lvl4'] : null,
            'district' => $district !== '' ? $district : null,
            'areas' => array_values(array_unique($areas)),
        ];
    }

    /** A short postal-style address for a coordinate, or null when the lookup fails. */
    public static function reverseAddress(float $lat, float $lng): ?string
    {
        return self::reverse($lat, $lng)['address'] ?? null;
    }

    private static function searchPhoton(string $query, int $limit): array
    {
        $url = 'https://photon.komoot.io/api/?' . http_build_query([
            'q' => $query,
            'limit' => $limit + 10,   // results outside Malaysia and repeated road segments are dropped below
            'lang' => 'default',      // local names, as Malaysians write them ("Melaka", "Pulau Pinang")
            'bbox' => self::MALAYSIA_BBOX,
        ]);
        $json = OpenDataHttp::getJson($url, 604800, ['timeout' => 8]);   // 7 days
        $places = [];
        $roads = [];
        foreach (($json['features'] ?? []) as $feature) {
            $p = $feature['properties'] ?? [];
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (strtoupper((string)($p['countrycode'] ?? '')) !== 'MY' || !is_array($coords) || count($coords) < 2) continue;
            $name = trim((string)($p['name'] ?? $p['street'] ?? ''));
            if ($name === '') continue;
            $detail = implode(', ', array_values(array_unique(array_filter([
                $p['street'] ?? '', $p['district'] ?? '', $p['city'] ?? '', $p['county'] ?? '', $p['state'] ?? '',
            ], static fn($v) => trim((string)$v) !== '' && $v !== $name))));
            // A long road comes back as many segments with one name: keep the first of each.
            $key = mb_strtolower($name . '|' . $detail);
            if (isset($places[$key]) || isset($roads[$key])) continue;
            $entry = ['name' => $name, 'detail' => $detail, 'lat' => (float)$coords[1], 'lng' => (float)$coords[0]];
            if (($p['osm_key'] ?? '') === 'highway') $roads[$key] = $entry; else $places[$key] = $entry;
        }
        // Someone naming where they are means a place far more often than a road.
        return array_slice(array_merge(array_values($places), array_values($roads)), 0, $limit);
    }

    private static function searchNominatim(string $query, int $limit): array
    {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format' => 'jsonv2',
            'q' => $query,
            'countrycodes' => 'my',
            'limit' => $limit,
            'accept-language' => 'ms,en',
        ]);
        $json = OpenDataHttp::getJson($url, 2592000, ['timeout' => 10]);   // 30 days
        $out = [];
        foreach (($json ?? []) as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'])) continue;
            $display = (string)($row['display_name'] ?? '');
            $name = trim((string)($row['name'] ?? '')) ?: trim(explode(',', $display)[0]);
            $detail = trim((string)preg_replace('/^' . preg_quote($name, '/') . ',?\s*/u', '', $display));
            $out[] = ['name' => $name, 'detail' => $detail, 'lat' => (float)$row['lat'], 'lng' => (float)$row['lon']];
        }
        return $out;
    }
}
