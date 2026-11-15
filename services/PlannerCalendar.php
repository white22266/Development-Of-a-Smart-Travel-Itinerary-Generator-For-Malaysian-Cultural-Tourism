<?php
// services/PlannerCalendar.php
// The calendar facts the itinerary planner needs: public holidays, each state's weekend, the
// Friday prayer break and rush-hour factors for travel times. All static, no database.
//
// Public holidays come from Nager.Date (https://date.nager.at, free, no key, open source), one
// request per year, cached for a month through OpenDataHttp. NOTE: when this was written the
// service answered "204 No Content" for Malaysia (MY is not among its AvailableCountries), so
// holidays() is empty in practice. Callers must work without it; fixedHolidays() lists the
// national holidays that fall on the same date every year, for a caller that wants a baseline.
// Nothing here makes a request when ROUTING_ESTIMATES_ONLY is set (offline).

require_once __DIR__ . '/OpenDataHttp.php';
require_once __DIR__ . '/OsmDataService.php';   // STATE_ISO only

class PlannerCalendar
{
    /** Days of rest by state; every state not listed rests on Saturday and Sunday (ISO weekday: 1 = Monday ... 7 = Sunday). */
    private const FRI_SAT_STATES = ['Kedah', 'Kelantan', 'Terengganu'];

    /** The Friday prayer break, in minutes since midnight: 12:15 to 14:45. */
    private const FRIDAY_PRAYER = [735, 885];

    /**
     * Rush hours: [start, end) in minutes since midnight, on working days only: 07:00 to 09:30
     * and 17:00 to 19:30. These, the boxes and the factors below are estimates, not
     * measurements. Change them here.
     */
    private const PEAK_WINDOWS = [[420, 570], [1020, 1170]];

    /** How much longer a trip takes in a rush hour inside a metropolitan box (estimates). */
    private const PEAK_FACTORS = [
        'car' => 1.5,
        'motorcycle' => 1.2,
        'public_transport' => 1.3,
        'walking' => 1.0,
    ];

    /**
     * Metropolitan boxes (estimates): [south lat, north lat, west lng, east lng].
     * Klang Valley: Klang, Shah Alam, Petaling Jaya, Kuala Lumpur, Gombak, Kajang, Putrajaya.
     * George Town with Butterworth: Penang island, Seberang Perai up to Bukit Mertajam.
     * Johor Bahru: Iskandar Puteri, Johor Bahru, Pasir Gudang.
     */
    private const PEAK_BOXES = [
        'Klang Valley' => [2.80, 3.45, 101.30, 101.90],
        'George Town and Butterworth' => [5.25, 5.50, 100.17, 100.48],
        'Johor Bahru' => [1.40, 1.60, 103.55, 103.92],
    ];

    /** Other spellings of a transport mode, mapped to a key of PEAK_FACTORS. */
    private const TRANSPORT_ALIASES = [
        'driving' => 'car', 'drive' => 'car', 'taxi' => 'car', 'ehailing' => 'car',
        'motorbike' => 'motorcycle', 'bike' => 'motorcycle',
        'public' => 'public_transport', 'transit' => 'public_transport', 'bus' => 'public_transport',
        'train' => 'public_transport', 'rail' => 'public_transport',
        'walk' => 'walking', 'foot' => 'walking',
    ];

    private const STATE_ALIASES = ['pulau pinang' => 'Penang', 'penang island' => 'Penang', 'wp kuala lumpur' => 'Kuala Lumpur', 'kl' => 'Kuala Lumpur'];

    private const HOLIDAY_TTL = 2592000;   // a month
    private const NONE_TTL = 86400;        // a year the service has nothing for is asked again after a day

    /** @var array<int,array> holidays handed in with setHolidays() */
    private static array $override = [];
    /** @var array<int,array> holidays already read in this PHP run */
    private static array $memo = [];

    // ------------------------------------------------------------------ holidays

    /**
     * The public holidays of Malaysia in a year. Empty when the service cannot be reached, has
     * nothing for Malaysia, or the mode is offline; never throws.
     *
     * @return array<string,array<int,array{0:string,1:?string[]}>> date (Y-m-d) => list of
     *         [name, states]; states is null for a national holiday, otherwise the state names
     *         (as in OsmDataService::STATE_ISO) the holiday applies to
     */
    public static function holidays(int $year): array
    {
        if (isset(self::$override[$year])) return self::$override[$year];
        if (isset(self::$memo[$year])) return self::$memo[$year];
        if (self::offline()) return [];   // not remembered: the mode may change

        $result = [];
        try {
            $flag = OpenDataHttp::cachePath('date.nager.at', "MY_{$year}_none.flag");
            if (is_file($flag) && (time() - (int)filemtime($flag)) < self::NONE_TTL) {
                return self::$memo[$year] = [];
            }
            $json = OpenDataHttp::getJson(
                "https://date.nager.at/api/v3/PublicHolidays/{$year}/MY",
                self::HOLIDAY_TTL,
                [
                    'timeout' => 8,
                    // a list of holidays, each with a date
                    'validate' => static fn(array $j): bool => $j !== [] && isset($j[0]['date']),
                ]
            );
            if ($json === null) {
                // HTTP 204 means "no data for this country": do not ask again for a day.
                if (str_contains(OpenDataHttp::lastError(), 'HTTP 204')) @touch($flag);
            } else {
                $result = self::parseNager($json);
            }
        } catch (Throwable $e) {
            $result = [];
        }
        return self::$memo[$year] = $result;
    }

    /**
     * Hand in the holidays of a year instead of asking the service (tests, the planner check).
     * Same shape as holidays() returns. Pass null to remove it again.
     *
     * @param array<string,array<int,array{0:string,1:?string[]}>>|null $holidays
     */
    public static function setHolidays(int $year, ?array $holidays): void
    {
        if ($holidays === null) {
            unset(self::$override[$year]);
        } else {
            self::$override[$year] = $holidays;
        }
    }

    /**
     * The national holidays that fall on the same date every year (no lunar or Islamic
     * calendar involved): a baseline for when holidays() is empty. Hari Raya, Chinese New Year,
     * Wesak, Deepavali, Awal Muharram, Maulidur Rasul and the Agong's birthday are not here.
     *
     * @return array<string,array<int,array{0:string,1:?string[]}>>
     */
    public static function fixedHolidays(int $year): array
    {
        $fixed = ['01-01' => "New Year's Day", '05-01' => 'Labour Day', '08-31' => 'National Day',
                  '09-16' => 'Malaysia Day', '12-25' => 'Christmas Day'];
        $out = [];
        foreach ($fixed as $md => $name) $out["{$year}-{$md}"][] = [$name, null];
        return $out;
    }

    /** @return array<string,array<int,array{0:string,1:?string[]}>> */
    private static function parseNager(array $rows): array
    {
        $byIso = array_flip(OsmDataService::STATE_ISO);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['date'], $row['name'])) continue;
            // Nager also lists "Bank", "School" or "Optional" days; only public holidays count.
            if (isset($row['types']) && is_array($row['types']) && !in_array('Public', $row['types'], true)) continue;

            $states = null;
            $counties = $row['counties'] ?? null;
            if (is_array($counties) && $counties) {
                $states = [];
                foreach ($counties as $code) {
                    $code = strtoupper(trim((string)$code));
                    if (preg_match('/^\d{2}$/', $code)) $code = 'MY-' . $code;
                    if (isset($byIso[$code])) $states[] = $byIso[$code];
                }
                $states = array_values(array_unique($states));
                if (!$states) $states = null;   // codes we cannot read: treat as national rather than lose it
            }
            $out[(string)$row['date']][] = [(string)$row['name'], $states];
        }
        ksort($out);
        return $out;
    }

    // ------------------------------------------------------------------ days

    /**
     * The two rest days of a state as ISO weekday numbers (1 = Monday ... 7 = Sunday):
     * [5, 6] (Friday, Saturday) in Kedah, Kelantan and Terengganu, [6, 7] elsewhere. Johor
     * went back to Saturday and Sunday in 2025.
     *
     * @return int[]
     */
    public static function weekendDays(string $state): array
    {
        return in_array(self::normaliseState($state), self::FRI_SAT_STATES, true) ? [5, 6] : [6, 7];
    }

    /**
     * What a date means in a state.
     *
     * @param string  $date  Y-m-d
     * @param ?string $state a state name as in OsmDataService::STATE_ISO; null: only national holidays count and the rest days are Saturday and Sunday
     * @return array{weekday:int,is_rest_day:bool,holiday:string[],is_working_day:bool,friday_prayer:?array{0:int,1:int}}
     *         weekday is the ISO number (1 = Monday ... 7 = Sunday); holiday lists the names that
     *         apply in that state; friday_prayer is [start, end] in minutes since midnight on a
     *         Friday (12:15 to 14:45), otherwise null
     * @throws InvalidArgumentException when $date is not a Y-m-d date
     */
    public static function dayInfo(string $date, ?string $state): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a Y-m-d date: {$date}");
        }
        $weekday = (int)$d->format('N');
        $stateName = $state !== null && trim($state) !== '' ? self::normaliseState($state) : null;
        $isRest = in_array($weekday, $stateName !== null ? self::weekendDays($stateName) : [6, 7], true);

        $names = [];
        foreach (self::holidays((int)$d->format('Y'))[$date] ?? [] as [$name, $states]) {
            if ($states === null || ($stateName !== null && in_array($stateName, $states, true))) $names[] = $name;
        }
        return [
            'weekday' => $weekday,
            'is_rest_day' => $isRest,
            'holiday' => array_values(array_unique($names)),
            'is_working_day' => !$isRest && !$names,
            'friday_prayer' => $weekday === 5 ? self::FRIDAY_PRAYER : null,
        ];
    }

    // ------------------------------------------------------------------ rush hours

    /**
     * How many times longer a trip takes at that moment: 1.0 unless it is a working day, the
     * start lies inside a metropolitan box and the time is in a rush hour. See PEAK_BOXES,
     * PEAK_WINDOWS and PEAK_FACTORS for the (estimated) figures. Never throws; a bad date, an
     * unknown transport mode or an unknown state gives 1.0 or the Saturday/Sunday rule.
     *
     * @param string $transport car, motorcycle, public_transport or walking (common spellings accepted)
     */
    public static function peakFactor(string $date, int $minuteOfDay, float $lat, float $lng, string $transport, ?string $state = null): float
    {
        $mode = strtolower(trim(preg_replace('/[\s-]+/', '_', $transport)));
        $mode = self::TRANSPORT_ALIASES[$mode] ?? $mode;
        $factor = self::PEAK_FACTORS[$mode] ?? 1.0;
        if ($factor === 1.0) return 1.0;

        $inWindow = false;
        foreach (self::PEAK_WINDOWS as [$from, $to]) {
            if ($minuteOfDay >= $from && $minuteOfDay < $to) $inWindow = true;
        }
        if (!$inWindow) return 1.0;

        $inBox = false;
        foreach (self::PEAK_BOXES as [$south, $north, $west, $east]) {
            if ($lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east) $inBox = true;
        }
        if (!$inBox) return 1.0;

        try {
            $working = self::dayInfo($date, $state)['is_working_day'];
        } catch (Throwable $e) {
            return 1.0;
        }
        return $working ? $factor : 1.0;
    }

    // ------------------------------------------------------------------ helpers

    private static function normaliseState(string $state): string
    {
        $key = strtolower(trim($state));
        if (isset(self::STATE_ALIASES[$key])) return self::STATE_ALIASES[$key];
        foreach (array_keys(OsmDataService::STATE_ISO) as $name) {
            if (strtolower($name) === $key) return $name;
        }
        return trim($state);
    }

    /** Whether ROUTING_ESTIMATES_ONLY is set: the planner check runs offline and no service may then make a request. */
    private static function offline(): bool
    {
        return in_array(strtolower(trim((string)getenv('ROUTING_ESTIMATES_ONLY'))), ['1', 'true', 'yes'], true);
    }
}
