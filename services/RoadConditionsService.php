<?php
// services/RoadConditionsService.php
// What is happening on and near a day's route right now: traffic jams, closed roads, road
// works, accidents and flooded roads; official weather warnings; fires seen by satellite.
//
// Three sources, each optional and each shown with its credit:
//   traffic   TomTom Traffic Incidents API. Not open data: a commercial service with a free
//             monthly allowance. It needs a key (TOMTOM_API_KEY in .env) and stays off without
//             one. The area of the route is sent to TomTom; nothing else about the trip is.
//   warnings  The Malaysian Meteorological Department, through data.gov.my. Open, no key.
//   fires     NASA FIRMS: hot spots that the NOAA-20 satellite saw in the last 24 hours.
//             Open, no key.
// Nothing about the traveller is sent to the last two: their national lists are downloaded
// and compared with the route here.
//
// There is no open live source for river levels in Malaysia at the moment: the flood-warning
// list on data.gov.my has not been updated since February 2024, so it is not used. Flooded
// roads come from the traffic source, and the page links to the official Public InfoBanjir.

require_once __DIR__ . '/OpenDataHttp.php';
require_once __DIR__ . '/RouteService.php';

class RoadConditionsService
{
    private const TOMTOM_URL = 'https://api.tomtom.com/traffic/services/5/incidentDetails';
    private const TOMTOM_FIELDS = '{incidents{type,geometry{type,coordinates},properties{id,iconCategory,magnitudeOfDelay,'
        . 'events{description,code,iconCategory},startTime,endTime,from,to,length,delay,roadNumbers,timeValidity}}}';
    /** TomTom takes an area of at most 10,000 square km per request. */
    private const TOMTOM_MAX_AREA_KM2 = 9500.0;
    private const TOMTOM_MAX_REQUESTS = 2;
    private const TRAFFIC_TTL = 600;          // traffic changes by the minute: ten minutes at most
    private const WARNINGS_URL = 'https://api.data.gov.my/weather/warning/';
    private const WARNINGS_TTL = 900;
    private const FIRES_URL = 'https://firms.modaps.eosdis.nasa.gov/data/active_fire/noaa-20-viirs-c2/csv/J1_VIIRS_C2_SouthEast_Asia_24h.csv';
    private const FIRES_TTL = 10800;          // the file is rebuilt a few times a day

    /** How near something has to be to count: on the route itself, or close enough to matter. */
    private const ON_ROUTE_KM = 0.08;
    private const NEAR_CLOSURE_KM = 2.0;
    private const NEAR_FIRE_KM = 5.0;

    /** TomTom's incident categories: [kind, title, level 1 (note) to 3 (serious)]. */
    private const INCIDENT_KINDS = [
        0 => ['hazard', 'Traffic incident', 1],
        1 => ['accident', 'Accident', 2],
        2 => ['weather', 'Fog', 1],
        3 => ['hazard', 'Dangerous conditions', 2],
        4 => ['weather', 'Heavy rain', 1],
        5 => ['weather', 'Ice', 1],
        6 => ['jam', 'Traffic jam', 1],
        7 => ['lane_closed', 'Lane closed', 1],
        8 => ['road_closed', 'Road closed', 3],
        9 => ['roadworks', 'Road works', 1],
        10 => ['weather', 'Strong wind', 1],
        11 => ['flooding', 'Flooded road', 3],
        14 => ['breakdown', 'Broken-down vehicle', 1],
    ];

    /** How the weather service writes the names of the states. */
    private const STATE_NAMES = [
        'Johor' => ['johor'], 'Kedah' => ['kedah'], 'Kelantan' => ['kelantan'], 'Melaka' => ['melaka', 'malacca'],
        'Negeri Sembilan' => ['negeri sembilan', 'n. sembilan', 'n.sembilan'], 'Pahang' => ['pahang'],
        'Penang' => ['penang', 'pulau pinang'], 'Perak' => ['perak'], 'Perlis' => ['perlis'],
        'Selangor' => ['selangor'], 'Terengganu' => ['terengganu'], 'Sabah' => ['sabah'], 'Sarawak' => ['sarawak'],
        'Kuala Lumpur' => ['kuala lumpur'], 'Labuan' => ['labuan'], 'Putrajaya' => ['putrajaya'],
    ];

    /**
     * @param array<int,array{0:float,1:float}> $route  the day's route as [latitude, longitude] points
     * @param array<int,array{0:float,1:float}> $stops  the day's stops
     * @param array<int,array{state:string,district:string}> $areas where the stops are, for the weather warnings
     * @param string|null $date the day of travel (Y-m-d)
     * @return array{checked_at:string,items:array<int,array>,sources:array<string,array{status:string,note:string,credit:string}>}
     *         items, most important first, each with kind, level (1 note, 2 caution, 3 serious), title,
     *         detail, on_route, distance_km, lat, lng, line (points of the affected stretch), delay_min
     *         and source. A source's status is ok | off | failed.
     */
    public static function along(array $route, array $stops, array $areas, ?string $date): array
    {
        $route = array_values(array_filter($route, static fn($p) => is_array($p) && RouteService::validCoord((float)($p[0] ?? 0), (float)($p[1] ?? 0))));
        $stops = array_values(array_filter($stops, static fn($p) => is_array($p) && RouteService::validCoord((float)($p[0] ?? 0), (float)($p[1] ?? 0))));
        if (count($route) < 2) $route = $stops;

        $sources = [];
        $items = [];
        if ($route) {
            $fine = self::samples($route, 0.04);
            $grid = [];
            foreach ($fine as $p) {
                $grid[self::cell($p[0], $p[1])][] = $p;
            }
            $coarse = self::samples($route, 0.4);
            $items = array_merge(
                self::traffic($route, $stops, $grid, $coarse, $sources),
                self::fires($coarse, $sources)
            );
        }
        $items = array_merge($items, self::warnings($areas, $date, $sources));

        // Most important first: on the route before near it, then by level, then by distance.
        usort($items, static fn(array $a, array $b): int =>
            [(int)$b['on_route'], $b['level'], $a['distance_km'] ?? 0.0] <=> [(int)$a['on_route'], $a['level'], $b['distance_km'] ?? 0.0]);

        return ['checked_at' => date('c'), 'items' => array_slice($items, 0, 60), 'sources' => $sources];
    }

    /** Whether live traffic is set up. */
    public static function trafficEnabled(): bool
    {
        return trim((string)(getenv('TOMTOM_API_KEY') ?: '')) !== '';
    }

    // ---- traffic (TomTom) ----------------------------------------------------------------

    private static string $trafficProblem = '';

    /** Incidents on the route, and closed or flooded roads close to it. */
    private static function traffic(array $route, array $stops, array $grid, array $coarse, array &$sources): array
    {
        $key = trim((string)(getenv('TOMTOM_API_KEY') ?: ''));
        if ($key === '') {
            $sources['traffic'] = ['status' => 'off', 'note' => 'Live traffic is not set up on this site.', 'credit' => ''];
            return [];
        }

        $boxes = self::boxes($route);
        $note = '';
        if (count($boxes) > self::TOMTOM_MAX_REQUESTS && $stops) {
            $boxes = self::boxes($stops);   // a long drive to the first stop: look at the area of the day's stops
            $note = 'The route is too long to check in full, so the area of the stops was checked.';
        }

        $items = [];
        $seen = [];
        foreach (array_slice($boxes, 0, self::TOMTOM_MAX_REQUESTS) as $box) {
            $json = self::incidents($key, $box);
            if ($json === null) {
                $sources['traffic'] = ['status' => 'failed', 'note' => self::$trafficProblem, 'credit' => ''];
                return [];
            }
            foreach ($json['incidents'] as $incident) {
                $item = is_array($incident) ? self::incidentItem($incident, $grid, $coarse) : null;
                if ($item === null || isset($seen[$item['id']])) continue;
                $seen[$item['id']] = true;
                unset($item['id']);
                $items[] = $item;
            }
        }
        $sources['traffic'] = ['status' => 'ok', 'note' => $note, 'credit' => 'Traffic: © TomTom'];
        return $items;
    }

    /** One request to TomTom for an area, answered from the cache when it was asked in the last ten minutes. */
    private static function incidents(string $key, array $box): ?array
    {
        $cacheKey = 'incidents_' . sha1(implode(',', $box));   // the key is not part of the file name
        $cached = OpenDataHttp::cachedJson('traffic', $cacheKey, self::TRAFFIC_TTL);
        if ($cached !== null) return $cached;

        if (!self::withinAllowance()) {
            self::$trafficProblem = 'The free traffic requests of this month are used up.';
            return null;
        }
        $url = self::TOMTOM_URL . '?' . http_build_query([
            'key' => $key,
            'bbox' => implode(',', $box),
            'fields' => self::TOMTOM_FIELDS,
            'language' => 'en-GB',
            'timeValidityFilter' => 'present',
        ]);
        $json = OpenDataHttp::getJson($url, self::TRAFFIC_TTL, [
            'namespace' => 'traffic',
            'cache_key' => $cacheKey,
            'stale_ok' => false,   // old traffic news is worse than none
            'timeout' => 12,
            'validate' => static fn(array $j): bool => isset($j['incidents']) && is_array($j['incidents']),
        ]);
        if ($json === null) self::$trafficProblem = 'The traffic service did not answer. The key may be wrong or its allowance used up.';
        return $json;
    }

    /**
     * Count a request against the month's allowance. TomTom gives 2,500 incident requests a
     * month for free and blocks the rest; stopping a little earlier keeps a reserve.
     */
    private static function withinAllowance(): bool
    {
        $limit = (int)(getenv('TOMTOM_MONTHLY_LIMIT') ?: 2400);
        $handle = @fopen(OpenDataHttp::cachePath('traffic', 'requests-' . date('Y-m') . '.txt'), 'c+');
        if (!$handle) return true;
        $allowed = true;
        if (flock($handle, LOCK_EX)) {
            $used = (int)stream_get_contents($handle);
            $allowed = $used < $limit;
            if ($allowed) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string)($used + 1));
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return $allowed;
    }

    /**
     * The areas to ask TomTom about: the box around the points, cut into pieces when it is
     * larger than one request may be. Edges are rounded outwards to 0.02 degrees, so nearby
     * requests are the same request and exact places are not sent.
     *
     * @return array<int,array{0:float,1:float,2:float,3:float}> west, south, east, north
     */
    private static function boxes(array $points): array
    {
        if (!$points) return [];
        $lats = array_column($points, 0);
        $lngs = array_column($points, 1);
        $south = floor((min($lats) - 0.01) / 0.02) * 0.02;
        $north = ceil((max($lats) + 0.01) / 0.02) * 0.02;
        $west = floor((min($lngs) - 0.01) / 0.02) * 0.02;
        $east = ceil((max($lngs) + 0.01) / 0.02) * 0.02;

        $heightKm = ($north - $south) * 110.57;
        $widthKm = ($east - $west) * 111.32 * cos(deg2rad(($north + $south) / 2));
        $pieces = max(1, (int)ceil($heightKm * $widthKm / self::TOMTOM_MAX_AREA_KM2));
        $boxes = [];
        for ($i = 0; $i < $pieces; $i++) {
            $boxes[] = $widthKm >= $heightKm
                ? [round($west + ($east - $west) * $i / $pieces, 4), round($south, 4), round($west + ($east - $west) * ($i + 1) / $pieces, 4), round($north, 4)]
                : [round($west, 4), round($south + ($north - $south) * $i / $pieces, 4), round($east, 4), round($south + ($north - $south) * ($i + 1) / $pieces, 4)];
        }
        return $boxes;
    }

    /** One TomTom incident as an item, or null when it is neither on the route nor a closure near it. */
    private static function incidentItem(array $incident, array $grid, array $coarse): ?array
    {
        $props = is_array($incident['properties'] ?? null) ? $incident['properties'] : [];
        [$kind, $title, $level] = self::INCIDENT_KINDS[(int)($props['iconCategory'] ?? 0)] ?? self::INCIDENT_KINDS[0];

        // GeoJSON: a point or a line, longitude first.
        $coordinates = $incident['geometry']['coordinates'] ?? [];
        if (($incident['geometry']['type'] ?? '') === 'Point') $coordinates = [$coordinates];
        $line = [];
        foreach ((array)$coordinates as $pair) {
            if (is_array($pair) && count($pair) >= 2 && RouteService::validCoord((float)$pair[1], (float)$pair[0])) {
                $line[] = [round((float)$pair[1], 5), round((float)$pair[0], 5)];
            }
        }
        if (!$line) return null;

        $onRoute = false;
        foreach (self::samples($line, 0.04) as $p) {
            if (self::onGrid($grid, $p[0], $p[1])) {
                $onRoute = true;
                break;
            }
        }
        $middle = $line[intdiv(count($line), 2)];
        $distanceKm = $onRoute ? 0.0 : round(self::distance($coarse, $middle[0], $middle[1]), 1);
        if (!$onRoute && (!in_array($kind, ['road_closed', 'flooding'], true) || $distanceKm > self::NEAR_CLOSURE_KM)) return null;

        $delayMin = (int)round((float)($props['delay'] ?? 0) / 60);
        if ($kind === 'jam') $level = max(1, min(3, (int)($props['magnitudeOfDelay'] ?? 1)));

        $details = [];
        $events = [];
        foreach ((array)($props['events'] ?? []) as $event) {
            $description = trim((string)($event['description'] ?? ''));
            if ($description !== '' && strcasecmp($description, $title) !== 0) $events[$description] = true;
        }
        if ($events) $details[] = implode(', ', array_keys($events));
        $from = trim((string)($props['from'] ?? ''));
        $to = trim((string)($props['to'] ?? ''));
        $roads = implode('/', array_filter(array_map('strval', (array)($props['roadNumbers'] ?? []))));
        $between = $from !== '' && $to !== '' && $from !== $to ? "from {$from} to {$to}" : ($from !== '' ? "at {$from}" : '');
        if ($roads !== '') $roads = 'road ' . $roads;
        if ($roads . $between !== '') $details[] = implode(', ', array_filter([$roads, $between]));
        $lengthKm = (float)($props['length'] ?? 0) / 1000;
        if ($lengthKm >= 0.1) $details[] = round($lengthKm, 1) . ' km';
        if ($delayMin >= 1) $details[] = "about {$delayMin} min delay";
        $until = self::clock((string)($props['endTime'] ?? ''));
        if ($until !== '') $details[] = 'until ' . $until;

        // A long line is thinned out for the map.
        $step = max(1, (int)ceil(count($line) / 80));
        $thin = [];
        foreach ($line as $index => $p) {
            if ($index % $step === 0 || $index === count($line) - 1) $thin[] = $p;
        }

        return [
            'id' => (string)($props['id'] ?? sha1((string)json_encode($line))),
            'kind' => $kind,
            'level' => $level,
            'title' => $title,
            'detail' => mb_substr(implode(' · ', $details), 0, 300),
            'on_route' => $onRoute,
            'distance_km' => $distanceKm,
            'lat' => $middle[0],
            'lng' => $middle[1],
            'line' => count($thin) >= 2 ? $thin : [],
            'delay_min' => $delayMin,
            'source' => 'tomtom',
        ];
    }

    // ---- weather warnings (Malaysian Meteorological Department) -------------------------------

    /** Warnings in force that name a state, and where they list districts a district, of the day's stops. */
    private static function warnings(array $areas, ?string $date, array &$sources): array
    {
        $json = OpenDataHttp::getJson(self::WARNINGS_URL, self::WARNINGS_TTL, ['namespace' => 'warnings', 'timeout' => 10, 'stale_ok' => false]);
        if ($json === null) {
            $sources['warnings'] = ['status' => 'failed', 'note' => 'The weather warnings could not be loaded.', 'credit' => ''];
            return [];
        }
        $sources['warnings'] = ['status' => 'ok', 'note' => '', 'credit' => 'Weather warnings: Malaysian Meteorological Department, via data.gov.my'];

        $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        $now = new DateTimeImmutable('now', $zone);
        $items = [];
        $seen = [];
        foreach ($json as $warning) {
            if (!is_array($warning)) continue;
            $title = trim((string)($warning['warning_issue']['title_en'] ?? ''));
            $text = trim((string)preg_replace('/\s+/u', ' ', (string)($warning['text_en'] ?? '')));
            // Nothing to report, and warnings for boats, are not road conditions.
            if ($title === '' || $text === '' || preg_match('/no advisory|rough seas/i', $title)) continue;
            try {
                $validTo = trim((string)($warning['valid_to'] ?? '')) !== '' ? new DateTimeImmutable((string)$warning['valid_to'], $zone) : null;
            } catch (Exception $e) {
                $validTo = null;
            }
            if ($validTo !== null && $validTo < $now) continue;

            $match = self::warningMatch($text, $areas);
            if ($match === null || isset($seen[$title . '|' . $match['where']])) continue;
            $seen[$title . '|' . $match['where']] = true;

            $heading = strtolower((string)($warning['heading_en'] ?? ''));
            $level = str_contains($heading, 'danger') ? 3 : (str_contains($heading, 'severe') ? 2 : ($match['district'] ? 2 : 1));
            $coversTripDay = $date !== null && $validTo !== null && $date <= $validTo->format('Y-m-d');
            $items[] = [
                'kind' => 'weather_warning',
                'level' => $level,
                'title' => $title . ' for ' . $match['where'],
                'detail' => mb_substr($text, 0, 320) . (mb_strlen($text) > 320 ? '...' : '')
                    . ($validTo !== null ? ' Valid until ' . self::clock($validTo->format('c')) . '.' : '')
                    . ($date !== null && !$coversTripDay ? ' It ends before your day of travel.' : ''),
                'on_route' => $match['district'],
                'distance_km' => null,
                'lat' => null,
                'lng' => null,
                'line' => [],
                'delay_min' => 0,
                'source' => 'met',
            ];
        }
        return $items;
    }

    /**
     * Whether a warning text names one of the areas. The texts list places like this:
     * "... over the states of Selangor (Klang and Sepang) • W.P. Putrajaya • Negeri Sembilan (Port Dickson) until ..."
     *
     * @return array{where:string,district:bool}|null district: the stop's own district is named,
     *         or the whole state is; null when the warning is for other places
     */
    private static function warningMatch(string $text, array $areas): ?array
    {
        $segments = array_map(static fn(string $s): string => mb_strtolower(trim($s)), explode('•', $text));
        foreach ($areas as $area) {
            $state = (string)($area['state'] ?? '');
            $district = mb_strtolower(trim((string)($area['district'] ?? '')));
            foreach (self::STATE_NAMES[$state] ?? [mb_strtolower($state)] as $name) {
                if ($name === '') continue;
                foreach ($segments as $segment) {
                    if (!str_contains($segment, $name)) continue;
                    $listsDistricts = str_contains($segment, '(');
                    if (!$listsDistricts) return ['where' => $state, 'district' => true];
                    if ($district === '') return ['where' => $state, 'district' => false];
                    if (str_contains($segment, $district)) return ['where' => (string)$area['district'] . ', ' . $state, 'district' => true];
                }
            }
        }
        return null;
    }

    // ---- fires (NASA FIRMS) ---------------------------------------------------------------------

    /** Fires a satellite saw within a few km of the route in the last 24 hours. Hot spots of one fire become one item. */
    private static function fires(array $coarse, array &$sources): array
    {
        $hotspots = self::hotspots();
        if ($hotspots === null) {
            $sources['fires'] = ['status' => 'failed', 'note' => 'The satellite fire data could not be loaded.', 'credit' => ''];
            return [];
        }
        $sources['fires'] = ['status' => 'ok', 'note' => '', 'credit' => 'Fires: NASA FIRMS, NOAA-20 VIIRS hot spots of the last 24 hours'];

        $lats = array_column($coarse, 0);
        $lngs = array_column($coarse, 1);
        $margin = 0.06;   // a little more than NEAR_FIRE_KM, in degrees
        $near = [];
        foreach ($hotspots as [$lat, $lng, $seenAt]) {
            if ($lat < min($lats) - $margin || $lat > max($lats) + $margin || $lng < min($lngs) - $margin || $lng > max($lngs) + $margin) continue;
            $km = self::distance($coarse, $lat, $lng);
            if ($km <= self::NEAR_FIRE_KM) $near[] = [$lat, $lng, $seenAt, $km];
        }
        usort($near, static fn(array $a, array $b): int => $a[3] <=> $b[3]);

        $fires = [];   // the nearest hot spot of each fire, with how many belong to it
        foreach ($near as $spot) {
            foreach ($fires as &$fire) {
                if (RouteService::haversineKm($fire[0], $fire[1], $spot[0], $spot[1]) <= 1.5) {
                    $fire[4]++;
                    continue 2;
                }
            }
            unset($fire);
            $fires[] = [$spot[0], $spot[1], $spot[2], $spot[3], 1];
        }
        unset($fire);

        $items = [];
        foreach ($fires as [$lat, $lng, $seenAt, $km, $count]) {
            $items[] = [
                'kind' => 'fire',
                'level' => $km <= 1.0 ? 3 : ($km <= 3.0 ? 2 : 1),
                'title' => 'Fire seen by satellite',
                'detail' => ($count > 1 ? "{$count} hot spots, the nearest" : 'A hot spot') . ' about ' . round($km, 1) . ' km from the route, seen '
                    . self::clock($seenAt) . '. This can be open burning or a forest fire; expect smoke nearby.',
                'on_route' => $km <= 0.3,
                'distance_km' => round($km, 1),
                'lat' => $lat,
                'lng' => $lng,
                'line' => [],
                'delay_min' => 0,
                'source' => 'firms',
            ];
        }
        return $items;
    }

    /**
     * The hot spots in and around Malaysia: [latitude, longitude, time seen (ISO 8601)] each.
     * The list for South-East Asia is downloaded at most every three hours.
     */
    private static function hotspots(): ?array
    {
        $listFile = OpenDataHttp::cachePath('fires', 'malaysia.json');
        $saved = is_file($listFile) ? json_decode((string)file_get_contents($listFile), true) : null;
        if (is_array($saved) && (time() - filemtime($listFile)) < self::FIRES_TTL) return $saved;

        $csvFile = OpenDataHttp::cachePath('fires', 'south-east-asia-24h.csv');
        if (OpenDataHttp::downloadFile(self::FIRES_URL, $csvFile, 6000000) === null) {
            // Yesterday's list is still better than none; an older one is not.
            return is_array($saved) && (time() - filemtime($listFile)) < 86400 ? $saved : null;
        }
        $rows = [];
        $handle = fopen($csvFile, 'r');
        $columns = array_flip((array)fgetcsv($handle));
        if (!isset($columns['latitude'], $columns['longitude'], $columns['acq_date'], $columns['acq_time'])) {
            fclose($handle);
            return is_array($saved) ? $saved : null;
        }
        while (($row = fgetcsv($handle)) !== false) {
            $lat = (float)($row[$columns['latitude']] ?? 0);
            $lng = (float)($row[$columns['longitude']] ?? 0);
            if ($lat < 0.8 || $lat > 7.6 || $lng < 99.5 || $lng > 119.5) continue;
            if (isset($columns['confidence']) && in_array(strtolower((string)$row[$columns['confidence']]), ['low', 'l'], true)) continue;
            $time = str_pad((string)$row[$columns['acq_time']], 4, '0', STR_PAD_LEFT);   // UTC, "249" is 02:49
            $rows[] = [round($lat, 4), round($lng, 4), $row[$columns['acq_date']] . 'T' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':00Z'];
        }
        fclose($handle);
        file_put_contents($listFile, json_encode($rows));
        return $rows;
    }

    // ---- geometry and time ------------------------------------------------------------------

    /** Points along a line no more than $stepKm apart. */
    private static function samples(array $line, float $stepKm): array
    {
        $out = [];
        $previous = null;
        foreach ($line as $point) {
            if ($previous !== null) {
                $pieces = (int)ceil(RouteService::haversineKm($previous[0], $previous[1], $point[0], $point[1]) / $stepKm);
                for ($i = 1; $i < $pieces; $i++) {
                    $out[] = [$previous[0] + ($point[0] - $previous[0]) * $i / $pieces, $previous[1] + ($point[1] - $previous[1]) * $i / $pieces];
                }
            }
            $out[] = [(float)$point[0], (float)$point[1]];
            $previous = $point;
        }
        return $out;
    }

    /** The square of about 110 m a point lies in. */
    private static function cell(float $lat, float $lng): string
    {
        return floor($lat * 1000) . ':' . floor($lng * 1000);
    }

    /** Whether a point is on the route: within ON_ROUTE_KM of one of its samples. */
    private static function onGrid(array $grid, float $lat, float $lng): bool
    {
        for ($dLat = -1; $dLat <= 1; $dLat++) {
            for ($dLng = -1; $dLng <= 1; $dLng++) {
                foreach ($grid[self::cell($lat + $dLat * 0.001, $lng + $dLng * 0.001)] ?? [] as $p) {
                    if (RouteService::haversineKm($lat, $lng, $p[0], $p[1]) <= self::ON_ROUTE_KM) return true;
                }
            }
        }
        return false;
    }

    /** Distance in km from a point to the nearest of the route's samples. */
    private static function distance(array $samples, float $lat, float $lng): float
    {
        $nearest = INF;
        foreach ($samples as $p) {
            $nearest = min($nearest, RouteService::haversineKm($lat, $lng, $p[0], $p[1]));
        }
        return $nearest;
    }

    /** A time as travellers in Malaysia read it: "6:30 pm" today, otherwise with the day. Empty when unreadable. */
    private static function clock(string $time): string
    {
        if (trim($time) === '') return '';
        try {
            $zone = new DateTimeZone('Asia/Kuala_Lumpur');
            $when = (new DateTimeImmutable($time))->setTimezone($zone);
        } catch (Exception $e) {
            return '';
        }
        $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
        return $when->format('Y-m-d') === $today ? $when->format('g:i a') . ' today' : $when->format('g:i a') . ' on ' . $when->format('D j M');
    }
}
