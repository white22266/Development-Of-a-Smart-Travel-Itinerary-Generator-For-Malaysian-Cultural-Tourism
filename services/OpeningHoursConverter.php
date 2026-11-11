<?php
// services/OpeningHoursConverter.php
// Converts OpenStreetMap's opening_hours syntax ("Mo-Fr 09:00-17:00; Sa off") into the
// format already stored in cultural_places.opening_hours:
//
//   Monday: 9:00 AM - 5:00 PM
//   Tuesday: Closed
//   ...
//
// Only the common, unambiguous subset is converted. Anything else (seasons, sunrise, public
// holiday exceptions that change the week) returns null, so wrong hours are never stored.

class OpeningHoursConverter
{
    private const DAY_INDEX = ['mo' => 0, 'tu' => 1, 'we' => 2, 'th' => 3, 'fr' => 4, 'sa' => 5, 'su' => 6];
    private const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function fromOsm(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        if (preg_match('/^24\s*\/\s*7$/', $value)) {
            return self::format(array_fill(0, 7, [[0, 1440]]));
        }
        // Syntax this converter does not handle: months, weeks, sun events, comments, fallbacks, open ends.
        if (preg_match('/jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|week|sun(rise|set)|dawn|dusk|easter|sh\b|["\[\]|+]/i', $value)) {
            return null;
        }

        $week = array_fill(0, 7, null);   // null = not mentioned yet
        $anyRule = false;

        foreach (explode(';', $value) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;
            if (preg_match('/^ph\b/i', $rule)) continue;   // public-holiday-only rule: the normal week stands

            if (!preg_match('/^(?:([a-z ,\-]+?)\s+)?(off|closed|[0-9:,\-\s]+)$/i', $rule, $m)) {
                return null;
            }
            $days = self::parseDays(trim((string)$m[1]));
            if ($days === null) return null;

            $spec = strtolower(trim($m[2]));
            if ($spec === 'off' || $spec === 'closed') {
                $ranges = [];
            } else {
                $ranges = self::parseTimes($spec);
                if ($ranges === null) return null;
            }
            foreach ($days as $day) {
                $week[$day] = $ranges;
            }
            $anyRule = true;
        }

        if (!$anyRule) return null;
        // In OSM, a day that no rule mentions is closed.
        return self::format(array_map(fn($ranges) => $ranges ?? [], $week));
    }

    /** "Mo-Fr", "Sa,Su", "Mo,We-Fr,PH" -> day indexes. Empty selector means every day. */
    private static function parseDays(string $selector): ?array
    {
        if ($selector === '') return range(0, 6);
        $days = [];
        foreach (explode(',', strtolower($selector)) as $part) {
            $part = trim($part);
            if ($part === '' || $part === 'ph') continue;
            if (preg_match('/^(mo|tu|we|th|fr|sa|su)\s*-\s*(mo|tu|we|th|fr|sa|su)$/', $part, $m)) {
                $from = self::DAY_INDEX[$m[1]];
                $to = self::DAY_INDEX[$m[2]];
                for ($d = $from; ; $d = ($d + 1) % 7) {
                    $days[] = $d;
                    if ($d === $to) break;
                }
            } elseif (isset(self::DAY_INDEX[$part])) {
                $days[] = self::DAY_INDEX[$part];
            } else {
                return null;
            }
        }
        return $days ? array_values(array_unique($days)) : null;
    }

    /** "09:00-12:00,14:00-17:00" -> [[540,720],[840,1020]] in minutes. Closing after midnight is kept as end > 1440. */
    private static function parseTimes(string $spec): ?array
    {
        $ranges = [];
        foreach (explode(',', $spec) as $part) {
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $part, $m)) return null;
            $start = (int)$m[1] * 60 + (int)$m[2];
            $end = (int)$m[3] * 60 + (int)$m[4];
            if ($start > 1440 || $end > 1440 || (int)$m[2] > 59 || (int)$m[4] > 59) return null;
            if ($end <= $start) $end += 1440;
            $ranges[] = [$start, $end];
        }
        return $ranges ?: null;
    }

    private static function format(array $week): string
    {
        $lines = [];
        foreach ($week as $day => $ranges) {
            if (!$ranges) {
                $text = 'Closed';
            } elseif (count($ranges) === 1 && $ranges[0][0] === 0 && $ranges[0][1] >= 1440) {
                $text = 'Open 24 hours';
            } else {
                $text = implode(', ', array_map(fn($r) => self::clock($r[0]) . ' - ' . self::clock($r[1]), $ranges));
            }
            $lines[] = self::DAY_NAMES[$day] . ': ' . $text;
        }
        return implode("\n", $lines);
    }

    private static function clock(int $minutes): string
    {
        $minutes %= 1440;
        $hour = intdiv($minutes, 60);
        return sprintf('%d:%02d %s', $hour % 12 ?: 12, $minutes % 60, $hour >= 12 ? 'PM' : 'AM');
    }
}
