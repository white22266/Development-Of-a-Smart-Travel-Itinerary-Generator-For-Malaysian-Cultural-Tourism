<?php
// services/OpeningHours.php
// Reads the opening hours stored in cultural_places.opening_hours and answers one question:
// can a visit start at this time on this date? Every part of the planner asks here, so a
// place is never judged by two different readings of the same text.
//
// Stored texts look like this:
//   Monday: 9:00 AM - 5:00 PM | Tuesday: Closed | ...    one entry per weekday
//   Sunday, 9 am–9 pm | Monday, Closed | ...             the same, as some sources write it
//   Daily 11:00-22:00; verify current hours                the same hours every day
//   Usually 10:00 AM - 10:00 PM
//   Open area / Verify before visit / Meal hours vary      nothing to check: the visit is allowed

class OpeningHours
{
    private const DAYS = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];

    private const RANGE = '/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?\s*(?:-|–|—|to)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)?/u';

    /**
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
     * When a place is open on a date: [open, close] pairs in minutes after midnight (close is
     * above 1440 when it is after midnight). An empty list means closed all day; null means
     * the text gives no hours that can be checked.
     *
     * @return array<int,array{0:int,1:int}>|null
     */
    public static function rangesOn(string $hours, ?string $date = null): ?array
    {
        $text = mb_strtolower(trim($hours));
        if ($text === '') return null;

        // Entries per weekday, separated by "|" or line breaks.
        $perDay = [];
        foreach (preg_split('/[|\r\n]+/', $text) as $entry) {
            if (preg_match('/^\s*(mon|tue|wed|thu|fri|sat|sun)[a-z]*\s*[:,]\s*(.*)$/u', $entry, $m)) {
                $perDay[self::DAYS[$m[1]]] = trim($m[2]);
            }
        }
        if (!$perDay) return self::parse($text);

        $time = $date !== null ? strtotime($date) : false;
        if ($time !== false) {
            $weekday = (int)date('N', $time) - 1;
            return isset($perDay[$weekday]) ? self::parse($perDay[$weekday]) : null;
        }
        // No date: only hours that are the same on every listed day can be checked.
        return count(array_unique($perDay)) === 1 ? self::parse(reset($perDay)) : null;
    }

    /** The hours in one piece of text that applies to a single day. */
    private static function parse(string $text): ?array
    {
        $text = str_replace(['a.m.', 'p.m.', 'a.m', 'p.m'], ['am', 'pm', 'am', 'pm'], $text);
        if (preg_match('/24\s*(hours|hrs|\/\s*7)|always open|open always/u', $text)) return [[0, 2880]];

        $ranges = [];
        if (preg_match_all(self::RANGE, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $open = self::minutes((int)$m[1], (int)($m[2] ?? 0), (string)($m[3] ?? ''));
                $close = self::minutes((int)$m[4], (int)($m[5] ?? 0), (string)($m[6] ?? ''));
                if ($open === null || $close === null) continue;
                if ($close <= $open) $close += 1440;   // closes after midnight
                $ranges[] = [$open, $close];
            }
        }
        if ($ranges) return $ranges;
        if (str_contains($text, 'closed') && !preg_match('/\d/', $text)) return [];
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
