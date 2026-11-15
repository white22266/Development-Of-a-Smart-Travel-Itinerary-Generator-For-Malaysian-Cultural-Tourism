<?php
// services/OpeningHours.php
// Reads the opening hours stored in cultural_places.opening_hours. Every part of the system
// asks here, so a place is never judged by two different readings of the same text.
//
// Stored texts look like this:
//   Monday: 9:00 AM - 5:00 PM | Tuesday: Closed | ...    one entry per weekday
//   Sunday, 9 am–9 pm | Monday, Closed | ...             the same, as some sources write it
//   Daily 11:00-22:00; verify current hours                the same hours every day
//   Usually 10:00 AM - 10:00 PM
//   Evening to late night; vendor hours vary             words only: approximate hours (DESCRIBED)
//   Open area / Verify before visit                       nothing to check: the visit is allowed
//
// Two reading rules:
//   - A start time without AM/PM takes the marker of the end time ("9:00 - 11:00 PM" opens at
//     21:00), unless that would put the start after the end ("11:00 - 2:00 AM" opens at 11:00).
//   - In a list of weekdays, a weekday that is not listed is closed.
//
// The planner reads a place's hours with placeRanges(), which adds three rules to the text:
//   - Office hours (a text read as DESCRIBED 'office') are closed on the rest days of the
//     place's state and on public holidays there (PlannerCalendar::dayInfo()).
//   - A text that gives no usable hours on any weekday (empty, "Open area", "Verify before
//     visit", "Outside prayer times"...) is not open at any time: the hours are ASSUMED from the
//     place's type (cultural_places.place_type), else from its category. A festival keeps only
//     its dates (no hours). Assumed hours are a hard rule like known hours; isAssumed() tells.
//   - earliestIn() finds the earliest start inside the ranges, outside blocked times (the
//     Friday prayers at a mosque) and on a step (five minutes for the planner).

require_once __DIR__ . '/PlannerCalendar.php';

class OpeningHours
{
    /**
     * Assumed opening hours by place type (PlaceTypeClassifier::TYPES), minutes after midnight,
     * for a place whose text gives no usable hours. null: no hours (a festival is judged by its
     * dates only). Chosen as the usual hours of each kind of place in Malaysia, on the careful
     * side, so a plan does not send a traveller to a closed door:
     *   places of worship 08:00-18:00 (visitors are welcome in the day; prayers are not tours)
     *   museums, galleries, palaces, cultural villages and centres 09:00-17:00 (state museums)
     *   memorials, forts, historic buildings and sites, mausoleums 09:00-18:00
     *   heritage streets 09:00-21:00 (the shops along them); markets 08:00-18:00 (day markets;
     *   a night market says so in its text); malls, restaurants, food streets 10:00-22:00;
     *   cafes 08:00-22:00
     *   parks, beaches, waterfalls, caves, hills, islands, lakes and rivers, forests 07:00-19:00
     *   (daylight); wildlife parks and zoos 09:00-17:00 (they keep office-like hours)
     *   viewpoints and towers 09:00-21:00
     * 'other' and a missing type fall back to ASSUMED_BY_CATEGORY.
     * tools/check_planner.php holds its own copy of both tables.
     */
    public const ASSUMED = [
        'mosque' => [[480, 1080]], 'hindu_temple' => [[480, 1080]], 'chinese_temple' => [[480, 1080]],
        'buddhist_temple' => [[480, 1080]], 'church' => [[480, 1080]], 'sikh_temple' => [[480, 1080]], 'other_worship' => [[480, 1080]],
        'museum' => [[540, 1020]], 'gallery' => [[540, 1020]], 'palace' => [[540, 1020]],
        'cultural_village' => [[540, 1020]], 'cultural_centre' => [[540, 1020]],
        'memorial' => [[540, 1080]], 'fort' => [[540, 1080]], 'historic_building' => [[540, 1080]],
        'historic_site' => [[540, 1080]], 'mausoleum' => [[540, 1080]],
        'heritage_street' => [[540, 1260]], 'market' => [[480, 1080]], 'mall' => [[600, 1320]],
        'food_street' => [[600, 1320]], 'restaurant' => [[600, 1320]], 'cafe' => [[480, 1320]],
        'park' => [[420, 1140]], 'beach' => [[420, 1140]], 'waterfall' => [[420, 1140]], 'cave' => [[420, 1140]],
        'hill' => [[420, 1140]], 'island' => [[420, 1140]], 'lake_river' => [[420, 1140]], 'forest' => [[420, 1140]],
        'wildlife' => [[540, 1020]],
        'viewpoint' => [[540, 1260]],
        'festival' => null,
    ];
    /** By category, for a place of type 'other' or without a type; any other category: culture's. */
    public const ASSUMED_BY_CATEGORY = [
        'culture' => [[540, 1080]], 'heritage' => [[540, 1080]], 'museum' => [[540, 1020]],
        'nature' => [[420, 1140]], 'food' => [[600, 1320]], 'shopping' => [[600, 1320]], 'festival' => null,
    ];

    /** @var array<string,bool> text => whether it gives usable hours on some weekday */
    private static array $usable = [];
    private const DAYS = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];

    /**
     * Hours written as words, read when a text has no clock times: APPROXIMATIONS made from the
     * description, not published hours. The first rule whose pattern matches (and whose unless
     * pattern does not) gives the ranges, in minutes after midnight; days limits a rule to those
     * weekdays (0 = Monday), so without a date it cannot be checked. Any other text ("Open
     * area", "Exterior viewing", "Verify before visit", "Outside prayer times") stays unknown.
     * tools/check_planner.php holds the same table in its own reader.
     */
    private const DESCRIBED = [
        'office'   => ['pattern' => '/\boffice hours\b/', 'ranges' => [[540, 1020]], 'days' => [0, 1, 2, 3, 4]],   // Mon-Fri 09:00-17:00
        'lunch'    => ['pattern' => '/\blunch hours\b/', 'ranges' => [[660, 900]]],                              // 11:00-15:00
        'meal'     => ['pattern' => '/\bmeal hours\b/', 'ranges' => [[660, 900], [1080, 1320]]],                 // 11:00-15:00, 18:00-22:00
        'morning_to_evening' => ['pattern' => '/\b(morning|day\s*time)\s+to\s+evening\b/', 'ranges' => [[480, 1200]]],  // 08:00-20:00
        'evening'  => ['pattern' => '/\b(evening|night)\b/', 'unless' => '/\bmorning\b|\bday\s*time\b/', 'ranges' => [[1020, 1439]]],  // 17:00-23:59
        'daytime'  => ['pattern' => '/\bday\s*time\b/', 'ranges' => [[480, 1080]]],                            // 08:00-18:00
        'morning'  => ['pattern' => '/\bmorning\b/', 'ranges' => [[360, 720]]],                                  // 06:00-12:00
        'business' => ['pattern' => '/\b(business|shop) hours\b/', 'ranges' => [[600, 1320]]],                    // 10:00-22:00
    ];

    private const RANGE = '/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?\s*(?:-|–|—|to)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/u';

    /** @var array<string,array|null> text and weekday => ranges, so a text is parsed once per request */
    private static array $cache = [];

    /**
     * Whether a visit from $arrivalMin for $stayMin minutes lies inside the opening hours.
     * Unknown hours allow the visit.
     *
     * @param string      $hours      the stored text
     * @param int         $arrivalMin when the visit starts, in minutes after midnight
     * @param string|null $date       day of the visit (Y-m-d). Without it, hours that differ
     *                                between weekdays cannot be checked and the visit is allowed
     * @param int         $stayMin    minutes the place must still be open after arrival
     */
    public static function allows(string $hours, int $arrivalMin, ?string $date = null, int $stayMin = 0): bool
    {
        $ranges = self::rangesOn($hours, $date);
        if ($ranges === null) return true;   // no hours on record: do not block the visit
        foreach ($ranges as [$open, $close]) {
            foreach ([$arrivalMin, $arrivalMin + 1440] as $arrival) {   // second: a visit after midnight
                if ($arrival >= $open && $arrival + $stayMin <= $close) return true;
            }
        }
        return false;
    }

    /**
     * The earliest start at or after $earliestMin at which the whole visit of $stayMin minutes
     * lies inside the opening hours and ends by $latestEndMin. Unknown hours count as open.
     *
     * @return int|null minutes after midnight, or null when no such start exists that day
     */
    public static function earliestStart(string $hours, ?string $date, int $earliestMin, int $stayMin, int $latestEndMin): ?int
    {
        if ($earliestMin + $stayMin > $latestEndMin) return null;
        $ranges = self::rangesOn($hours, $date);
        if ($ranges === null) return $earliestMin;
        $best = null;
        foreach ($ranges as [$open, $close]) {
            $start = max($earliestMin, $open);
            if ($start + $stayMin <= $close && $start + $stayMin <= $latestEndMin && ($best === null || $start < $best)) {
                $best = $start;
            }
        }
        return $best;
    }

    /** Whether the text gives hours that can be checked on that date. */
    public static function known(string $hours, ?string $date = null): bool
    {
        return self::rangesOn($hours, $date) !== null;
    }

    /** Whether the text gives hours that can be checked on at least one weekday. */
    public static function usable(string $hours): bool
    {
        if (isset(self::$usable[$hours])) return self::$usable[$hours];
        if (count(self::$usable) > 5000) self::$usable = [];
        $found = false;
        for ($weekday = 0; $weekday < 7 && !$found; $weekday++) $found = self::read($hours, $weekday) !== null;
        return self::$usable[$hours] = $found;
    }

    /** Whether the text is read as office hours (DESCRIBED 'office': words only, no clock times). */
    public static function isOfficeHours(string $hours): bool
    {
        $text = mb_strtolower(trim($hours));
        return $text !== '' && !preg_match('/\d/', $text) && !str_contains($text, '|')
            && preg_match(self::DESCRIBED['office']['pattern'], $text) === 1;
    }

    /**
     * The assumed hours of a place without usable hours: by its type, else by its category
     * (ASSUMED, ASSUMED_BY_CATEGORY). null: no hours are assumed (a festival: its dates only).
     *
     * @return array<int,array{0:int,1:int}>|null
     */
    public static function assumedRanges(array $place): ?array
    {
        $type = strtolower(trim((string)($place['place_type'] ?? '')));
        if ($type !== '' && $type !== 'other' && array_key_exists($type, self::ASSUMED)) return self::ASSUMED[$type];
        $category = strtolower(trim((string)($place['category'] ?? '')));
        return array_key_exists($category, self::ASSUMED_BY_CATEGORY) ? self::ASSUMED_BY_CATEGORY[$category] : self::ASSUMED_BY_CATEGORY['culture'];
    }

    /** Whether the planner judges this place by assumed hours (no usable text, and the table gives hours). */
    public static function isAssumed(array $place): bool
    {
        return !self::usable((string)($place['opening_hours'] ?? '')) && self::assumedRanges($place) !== null;
    }

    /** The assumed hours of a place as text ("09:00-17:00"), or '' when none are assumed. */
    public static function assumedText(array $place): string
    {
        if (!self::isAssumed($place)) return '';
        return implode(', ', array_map(static fn(array $r): string => sprintf('%02d:%02d-%02d:%02d', intdiv($r[0], 60), $r[0] % 60, intdiv($r[1], 60), $r[1] % 60),
            (array)self::assumedRanges($place)));
    }

    /**
     * The hours as a page shows them: the stored text, or the assumed hours marked "(assumed)".
     * '' when there is nothing to show.
     */
    public static function shown(array $place): string
    {
        $text = trim((string)($place['opening_hours'] ?? ''));
        if (self::isAssumed($place)) return self::assumedText($place) . ' (assumed)';
        return $text;
    }

    /**
     * When a place is open on a date, as the planner reads it: the stored text, with office hours
     * closed on the rest days of the place's state and on its public holidays, and assumed hours
     * for a text without usable hours (see the head of this file).
     *
     * @return array<int,array{0:int,1:int}>|null [] closed all day; null no hours to keep (a festival)
     */
    public static function placeRanges(array $place, string $date): ?array
    {
        $hours = (string)($place['opening_hours'] ?? '');
        if (self::isOfficeHours($hours)) {
            $state = trim((string)($place['state'] ?? ''));
            try {
                $info = PlannerCalendar::dayInfo($date, $state !== '' ? $state : null);
                return $info['is_rest_day'] || $info['holiday'] ? [] : self::DESCRIBED['office']['ranges'];
            } catch (Throwable $e) {
                return self::rangesOn($hours, $date);
            }
        }
        if (self::usable($hours)) return self::rangesOn($hours, $date);
        return self::assumedRanges($place);
    }

    /**
     * The earliest start at or after $earliestMin, on a multiple of $step, at which a stay of
     * $stayMin minutes lies inside one of the ranges, ends by $latestEndMin and overlaps no
     * blocked time ([from, to) pairs). null ranges: no hours to keep.
     */
    public static function earliestIn(?array $ranges, int $earliestMin, int $stayMin, int $latestEndMin, array $blocked = [], int $step = 1): ?int
    {
        $up = static fn(int $m): int => $step > 1 ? (int)(ceil($m / $step) * $step) : $m;
        $best = null;
        foreach ($ranges ?? [[0, 2880]] as [$open, $close]) {
            $start = $up(max($earliestMin, $open));
            foreach ($blocked as [$from, $to]) {
                if ($start < $to && $start + $stayMin > $from) $start = $up(max($start, (int)$to));
            }
            if ($start + $stayMin <= $close && $start + $stayMin <= $latestEndMin && ($best === null || $start < $best)) $best = $start;
        }
        return $best;
    }

    /**
     * When a place is open on a date: [open, close] pairs in minutes after midnight (close is
     * above 1440 when it is after midnight). An empty list means closed all day; null means
     * the text gives no hours that can be checked.
     *
     * @return array<int,array{0:int,1:int}>|null
     */
    public static function rangesOn(string $hours, ?string $date = null): ?array
    {
        $time = $date !== null ? strtotime($date) : false;
        $weekday = $time !== false ? (int)date('N', $time) - 1 : -1;
        $key = $weekday . '|' . $hours;
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        if (count(self::$cache) > 5000) self::$cache = [];
        return self::$cache[$key] = self::read($hours, $weekday);
    }

    private static function read(string $hours, int $weekday): ?array
    {
        $text = mb_strtolower(trim($hours));
        if ($text === '') return null;

        // Entries per weekday, separated by "|" or line breaks.
        // An entry may name a run of days: "Tuesday-Sunday, 9:30 am-4:30 pm".
        $perDay = [];
        $lastHours = null;
        foreach (preg_split('/[|\r\n]+/', $text) as $entry) {
            if (preg_match('/^\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*(?:\s*(?:-|–|—|to)\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*)?\s*[:,]\s*(.*)$/u', $entry, $m)) {
                $from = self::DAYS[$m[1]];
                $to = ($m[2] ?? '') !== '' ? self::DAYS[$m[2]] : $from;
                for ($day = $from, $guard = 0; $guard < 7; $day = ($day + 1) % 7, $guard++) {
                    $perDay[$day] = trim($m[3]);
                    if ($day === $to) break;
                }
                $lastHours = trim($m[3]);
            }
        }
        if (!$perDay) return self::parse($text, $weekday);

        if ($weekday >= 0) {
            if (isset($perDay[$weekday])) return self::parse($perDay[$weekday], $weekday);
            // A weekday the list leaves out is closed, unless the list was cut off (its last
            // entry has no hours): then the missing days are unknown.
            return $lastHours === '' ? null : [];
        }
        // No date: only hours that are the same on all seven days can be checked.
        return count($perDay) === 7 && count(array_unique($perDay)) === 1 ? self::parse(reset($perDay)) : null;
    }

    /** The hours in one piece of text that applies to a single day ($weekday 0 = Monday, -1 unknown). */
    private static function parse(string $text, int $weekday = -1): ?array
    {
        $text = str_replace(['a.m.', 'p.m.', 'a.m', 'p.m'], ['am', 'pm', 'am', 'pm'], $text);
        if (preg_match('/24\s*(hours|hrs|\/\s*7)|always open|open always/u', $text)) return [[0, 2880]];

        $ranges = [];
        if (preg_match_all(self::RANGE, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $openMarker = (string)($m[3] ?? '');
                $closeMarker = (string)($m[6] ?? '');
                $open = self::minutes((int)$m[1], (int)($m[2] ?? 0), $openMarker);
                $close = self::minutes((int)$m[4], (int)($m[5] ?? 0), $closeMarker);
                if ($open === null || $close === null) continue;
                if ($openMarker === '' && $closeMarker !== '') {
                    // "9:00 - 11:00 PM": the start shares the end's marker unless that puts it after the end.
                    $shared = self::minutes((int)$m[1], (int)($m[2] ?? 0), $closeMarker);
                    if ($shared !== null && $shared < $close) $open = $shared;
                }
                if ($close <= $open) $close += 1440;   // closes after midnight
                $ranges[] = [$open, $close];
            }
        }
        if ($ranges) return $ranges;
        if (str_contains($text, 'closed') && !preg_match('/\d/', $text)) return [];
        if (preg_match('/\d/', $text)) return null;
        return self::described($text, $weekday);
    }

    /** Approximate hours from a description without clock times (DESCRIBED), or null. */
    private static function described(string $text, int $weekday): ?array
    {
        foreach (self::DESCRIBED as $rule) {
            if (!preg_match($rule['pattern'], $text) || (isset($rule['unless']) && preg_match($rule['unless'], $text))) continue;
            if (!isset($rule['days'])) return $rule['ranges'];
            if ($weekday < 0) return null;   // differs between weekdays: not checkable without a date
            return in_array($weekday, $rule['days'], true) ? $rule['ranges'] : [];
        }
        return null;
    }

    private static function minutes(int $hour, int $minute, string $ampm): ?int
    {
        if ($hour > 24 || $minute > 59) return null;
        if ($ampm === 'pm' && $hour < 12) $hour += 12;
        if ($ampm === 'am' && $hour === 12) $hour = 0;
        return $hour * 60 + $minute;
    }
}
