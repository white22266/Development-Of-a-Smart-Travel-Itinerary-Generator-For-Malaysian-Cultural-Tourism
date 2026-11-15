<?php
// services/PlannerCalendar.php
// The calendar facts the itinerary planner needs: public holidays, each state's weekend, the
// Friday prayer break and rush-hour factors for travel times. All static, no database.
//
// Public holidays: no free service lists Malaysia's (Nager.Date has none), and most of them move
// every year (Chinese New Year, Hari Raya, Wesak, Deepavali, Awal Muharram, the Prophet's
// birthday, state rulers' birthdays). So the admin enters them from the official announcement
// (admin/admin_holidays.php, table public_holidays) and loadHolidays() reads them. Only the few
// holidays with a fixed date come from a rule, fixedHolidays(). Without loadHolidays() the
// planner sees the fixed ones only. This class makes no network request.

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

    /** States that do not observe New Year's Day on 1 January. */
    private const NO_NEW_YEAR_STATES = ['Johor', 'Kedah', 'Kelantan', 'Perlis', 'Terengganu'];

    /** @var array<int,array> holidays handed in with setHolidays() */
    private static array $override = [];
    /** @var array<int,array> holidays read from public_holidays, by year */
    private static array $loaded = [];

    // ------------------------------------------------------------------ holidays

    /**
     * The public holidays of Malaysia in a year: the fixed ones, the ones read by loadHolidays()
     * and anything handed in with setHolidays(), merged. Never throws, makes no request.
     *
     * @return array<string,array<int,array{0:string,1:?string[]}>> date (Y-m-d) => list of
     *         [name, states]; states is null for a national holiday, otherwise the state names
     *         (as in OsmDataService::STATE_ISO) the holiday applies to
     */
    public static function holidays(int $year): array
    {
        $out = self::fixedHolidays($year);
        foreach ([self::$loaded[$year] ?? [], self::$override[$year] ?? []] as $extra) {
            foreach ($extra as $date => $list) {
                foreach ($list as $entry) {
                    foreach ($out[$date] ?? [] as $have) {
                        if ($have[0] === $entry[0]) continue 2;   // same name on the same day: once
                    }
                    $out[$date][] = $entry;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Read the holidays of a year from the table public_holidays and remember them. A second call
     * for the same year does nothing. A missing table or a failed query leaves the year with the
     * fixed holidays only (and is not remembered, so a later call may try again).
     */
    public static function loadHolidays(mysqli $conn, int $year): void
    {
        if (isset(self::$loaded[$year])) return;
        try {
            $exists = $conn->query("SHOW TABLES LIKE 'public_holidays'");
            if (!$exists || $exists->num_rows === 0) return;
            $stmt = $conn->prepare('SELECT holiday_date, name, states FROM public_holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name');
            if (!$stmt) return;
            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-12-31', $year);
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $out[(string)$row['holiday_date']][] = [(string)$row['name'], self::parseStates($row['states'])];
            }
            $stmt->close();
            self::$loaded[$year] = $out;
        } catch (Throwable $e) {
            // leave the year unloaded
        }
    }

    /**
     * Hand in the holidays of a year (tests, the planner check), merged into holidays(). Same
     * shape as holidays() returns. Pass null to remove it again.
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
     * The holidays that fall on the same date every year (no lunar or Islamic calendar involved):
     * Labour Day, National Day, Malaysia Day and Christmas Day in the whole country, and New
     * Year's Day in the states that observe it (all but Johor, Kedah, Kelantan, Perlis and
     * Terengganu). Nothing else is a rule: Hari Raya, Chinese New Year, Wesak, Deepavali, Awal
     * Muharram, Maulidur Rasul and the rulers' birthdays are entered by the admin.
     *
     * @return array<string,array<int,array{0:string,1:?string[]}>>
     */
    public static function fixedHolidays(int $year): array
    {
        $newYearStates = array_values(array_diff(array_keys(OsmDataService::STATE_ISO), self::NO_NEW_YEAR_STATES));
        $out = ["{$year}-01-01" => [["New Year's Day", $newYearStates]]];
        foreach (['05-01' => 'Labour Day', '08-31' => 'National Day', '09-16' => 'Malaysia Day', '12-25' => 'Christmas Day'] as $md => $name) {
            $out["{$year}-{$md}"][] = [$name, null];
        }
        return $out;
    }

    /** The state names the holiday table accepts (OsmDataService::STATE_ISO). @return string[] */
    public static function knownStates(): array
    {
        return array_keys(OsmDataService::STATE_ISO);
    }

    /**
     * Validate and store one holiday in public_holidays (the admin page uses this).
     *
     * @param ?string[] $states null or empty: the whole country; otherwise state names as in knownStates()
     * @return string '' when stored, otherwise the reason it was refused
     */
    public static function addHoliday(mysqli $conn, string $date, string $name, ?array $states, int $adminId): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) return 'Enter a real date.';
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        $len = mb_strlen($name);
        if ($len < 2 || $len > 120) return 'The name must be 2 to 120 characters.';

        $stored = null;
        if ($states) {
            $known = self::knownStates();
            $clean = [];
            foreach ($states as $s) {
                if (!is_string($s) || !in_array($s, $known, true)) return 'Unknown state name.';
                $clean[$s] = true;
            }
            // keep the order of knownStates() so the same selection always reads the same
            $stored = implode(',', array_values(array_intersect($known, array_keys($clean))));
            if (count($clean) === count($known)) $stored = null;   // every state: the whole country
        }

        $stmt = $conn->prepare('SELECT 1 FROM public_holidays WHERE holiday_date = ? AND name = ? LIMIT 1');
        $stmt->bind_param('ss', $date, $name);
        $stmt->execute();
        $dup = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($dup) return 'That holiday is already in the list.';

        $adminOrNull = $adminId > 0 ? $adminId : null;
        $stmt = $conn->prepare('INSERT INTO public_holidays (holiday_date, name, states, created_by_admin_id) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('sssi', $date, $name, $stored, $adminOrNull);
        try {
            $ok = $stmt->execute();
            $err = $stmt->error;
        } catch (mysqli_sql_exception $e) {
            $ok = false;
            $err = $e->getMessage();
        }
        $stmt->close();
        unset(self::$loaded[(int)$d->format('Y')]);   // read again next time
        return $ok ? '' : 'Could not save the holiday: ' . $err;
    }

    /** Delete one entered holiday by id. Returns whether a row was removed. */
    public static function deleteHoliday(mysqli $conn, int $id): bool
    {
        $stmt = $conn->prepare('DELETE FROM public_holidays WHERE holiday_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        self::$loaded = [];
        return $n > 0;
    }

    /** @return ?string[] null (whole country) for an empty value, otherwise the known state names in it */
    private static function parseStates($value): ?array
    {
        if ($value === null || trim((string)$value) === '') return null;
        $known = self::knownStates();
        $states = [];
        foreach (explode(',', (string)$value) as $s) {
            $s = trim($s);
            if (in_array($s, $known, true)) $states[] = $s;
        }
        return $states ?: null;   // nothing readable: national rather than lose it
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
