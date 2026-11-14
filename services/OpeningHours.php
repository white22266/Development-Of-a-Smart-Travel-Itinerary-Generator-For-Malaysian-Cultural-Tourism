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

class OpeningHours
{
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
