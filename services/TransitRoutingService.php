<?php
// services/TransitRoutingService.php
// Real public transport connections: which line, where to board and alight, and when it
// leaves. They come from published timetables through a MOTIS routing server such as
// Transitous (https://transitous.org), which is run by volunteers on open timetable data.
//
// Off by default. It is turned on by setting TRANSIT_API_URL in .env; docs/OPEN_DATA.md says
// what the public Transitous server asks of the sites that use it. Timetables are published
// for Kuala Lumpur and the Klang Valley, Penang and the KTM trains. Where there are none, no
// connection is found and the itinerary keeps its estimate.

require_once __DIR__ . '/OpenDataHttp.php';
require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/GtfsFeedReader.php';

class TransitRoutingService
{
    private const TTL = 21600;   // six hours: timetables change rarely, delays are not asked for

    /** How the server's vehicle types are shown. */
    private const KINDS = [
        'WALK' => 'walk', 'BUS' => 'bus', 'COACH' => 'bus', 'FERRY' => 'ferry',
        'TRAM' => 'train', 'SUBWAY' => 'train', 'METRO' => 'train', 'RAIL' => 'train', 'SUBURBAN' => 'train',
        'REGIONAL_RAIL' => 'train', 'REGIONAL_FAST_RAIL' => 'train', 'LONG_DISTANCE' => 'train',
        'HIGHSPEED_RAIL' => 'train', 'NIGHT_RAIL' => 'train', 'FUNICULAR' => 'train', 'AERIAL_LIFT' => 'cable car',
    ];

    public static function enabled(): bool
    {
        return self::baseUrl() !== '';
    }

    /**
     * The connection that arrives first when leaving a place at or after a time.
     *
     * Timetables are published a few weeks ahead at most. For a day further away (or in the
     * past) the coming day with the same weekday is asked about, and "typical" is set.
     *
     * @return array{minutes:int,depart:string,arrive:string,transfers:int,typical:bool,
     *               segments:array<int,array{kind:string,line:string,towards:string,operator:string,from:string,to:string,
     *                        depart:string,arrive:string,minutes:int,distance_km:float,color:?string,
     *                        geometry:array<int,array{0:float,1:float}>}>}|null
     *         minutes counts from the time asked for to the arrival, so waiting is included.
     *         null when the service is off, did not answer or knows no connection.
     */
    public static function connection(float $fromLat, float $fromLng, float $toLat, float $toLng, DateTimeImmutable $leaveAt): ?array
    {
        if (!self::enabled()) return null;
        $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        $leaveAt = $leaveAt->setTimezone($zone);
        // 5-minute steps: fewer different requests
        $leaveAt = $leaveAt->setTime((int)$leaveAt->format('G'), intdiv((int)$leaveAt->format('i'), 5) * 5);

        $today = new DateTimeImmutable('today', $zone);
        $typical = $leaveAt < $today || $leaveAt >= $today->modify('+7 days');
        if ($typical) {
            $daysAhead = ((int)$leaveAt->format('N') - (int)$today->format('N') + 7) % 7 ?: 7;
            $leaveAt = $today->modify("+{$daysAhead} days")->setTime((int)$leaveAt->format('G'), (int)$leaveAt->format('i'));
        }

        $url = self::baseUrl() . '/api/v1/plan?' . http_build_query([
            'fromPlace' => round($fromLat, 5) . ',' . round($fromLng, 5),
            'toPlace' => round($toLat, 5) . ',' . round($toLng, 5),
            'time' => $leaveAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d') . 'T' . $leaveAt->setTimezone(new DateTimeZone('UTC'))->format('H:i:s') . 'Z',
            'arriveBy' => 'false',
        ]);
        $json = OpenDataHttp::getJson($url, self::TTL, [
            'namespace' => 'transit',
            'timeout' => 15,
            'validate' => static fn(array $j): bool => isset($j['itineraries']) && is_array($j['itineraries']),
        ]);

        $best = null;
        foreach ($json['itineraries'] ?? [] as $itinerary) {
            $arrival = strtotime((string)($itinerary['endTime'] ?? ''));
            if ($arrival === false || empty($itinerary['legs'])) continue;
            $rank = [$arrival, (int)($itinerary['transfers'] ?? 0)];
            if ($best === null || $rank < $best['rank']) $best = ['rank' => $rank, 'itinerary' => $itinerary];
        }
        if ($best === null) return null;

        $clock = static function (string $time) use ($zone): string {
            $stamp = strtotime($time);
            return $stamp === false ? '' : (new DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format('g:i a');
        };
        $segments = [];
        foreach ($best['itinerary']['legs'] as $leg) {
            $kind = self::KINDS[strtoupper((string)($leg['mode'] ?? ''))] ?? 'ride';
            $geometry = RouteService::decodePolyline((string)($leg['legGeometry']['points'] ?? ''), (int)($leg['legGeometry']['precision'] ?? 7));
            $step = max(1, (int)ceil(count($geometry) / 150));   // long lines are thinned out for the map
            $thin = [];
            foreach ($geometry as $index => $point) {
                if ($index % $step === 0 || $index === count($geometry) - 1) $thin[] = $point;
            }
            $color = strtolower((string)($leg['routeColor'] ?? ''));
            $ride = $kind !== 'walk';
            $segments[] = [
                'kind' => $kind,
                'line' => $ride ? trim((string)($leg['routeShortName'] ?? $leg['displayName'] ?? $leg['routeLongName'] ?? '')) : '',
                'towards' => $ride ? GtfsFeedReader::name((string)($leg['headsign'] ?? '')) : '',
                'operator' => $ride ? trim((string)($leg['agencyName'] ?? '')) : '',
                'from' => self::placeName($leg['from'] ?? []),
                'to' => self::placeName($leg['to'] ?? []),
                'depart' => $clock((string)($leg['startTime'] ?? '')),
                'arrive' => $clock((string)($leg['endTime'] ?? '')),
                'minutes' => (int)round((float)($leg['duration'] ?? 0) / 60),
                'distance_km' => round((float)($leg['distance'] ?? 0) / 1000, 2),
                'color' => preg_match('/^[0-9a-f]{6}$/', $color) ? '#' . $color : null,
                'geometry' => $thin,
            ];
        }

        return [
            'minutes' => max(1, (int)ceil(($best['rank'][0] - $leaveAt->getTimestamp()) / 60)),
            'depart' => $clock((string)($best['itinerary']['startTime'] ?? '')),
            'arrive' => $clock((string)($best['itinerary']['endTime'] ?? '')),
            'transfers' => $best['rank'][1],
            'typical' => $typical,
            'segments' => $segments,
        ];
    }

    /** A stop's name. The server calls the two ends of a connection START and END: those have none. */
    private static function placeName(array $place): string
    {
        $name = trim((string)($place['name'] ?? ''));
        return in_array($name, ['START', 'END'], true) ? '' : GtfsFeedReader::name($name);
    }

    private static function baseUrl(): string
    {
        $url = rtrim(trim((string)(getenv('TRANSIT_API_URL') ?: '')), '/');
        return str_starts_with($url, 'https://') ? $url : '';
    }
}
