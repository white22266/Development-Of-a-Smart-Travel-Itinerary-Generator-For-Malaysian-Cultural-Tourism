<?php
// services/CalendarExportService.php
// Builds an iCalendar (RFC 5545) file for one itinerary, and the "open in a maps app" links
// shown next to a stop. Free and open only: no Google service.

class CalendarExportService
{
    private const TZID = "Asia/Kuala_Lumpur";

    /**
     * iCalendar text for one itinerary, or "" when the itinerary does not exist.
     * Stops of type note or transport are skipped, and so is any stop without a date or times.
     * Hotel stops that have times are exported like any other stop.
     */
    public static function buildForItinerary(mysqli $conn, int $itineraryId): string
    {
        $stmt = $conn->prepare("SELECT itinerary_id, title, start_date FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param("i", $itineraryId);
        $stmt->execute();
        $it = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$it) return "";

        $hasCoords = false;
        $c1 = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_latitude'");
        $c2 = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_longitude'");
        if ($c1 && $c1->num_rows > 0 && $c2 && $c2->num_rows > 0) $hasCoords = true;
        $coordSelect = $hasCoords
            ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
            : "cp.latitude, cp.longitude";

        $stmt = $conn->prepare("
            SELECT ii.item_id, ii.day_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time,
                   ii.estimated_cost, ii.notes, {$coordSelect}, cp.name AS place_name, cp.address
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no
        ");
        $stmt->bind_param("i", $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return self::build($it, $rows);
    }

    /** True when a stop becomes a calendar event (same rule as build()). */
    public static function isEligible(array $r, bool $hasStartDate = true): bool
    {
        $type = strtolower((string)($r["item_type"] ?? ""));
        if ($type === "note" || $type === "transport") return false;
        return $hasStartDate && self::timeParts($r["start_time"] ?? null) !== null && self::timeParts($r["end_time"] ?? null) !== null;
    }

    /** Pure builder: $it has itinerary_id, title, start_date; $rows are itinerary_items rows with place fields. */
    public static function build(array $it, array $rows): string
    {
        $id = (int)$it["itinerary_id"];
        $start = trim((string)($it["start_date"] ?? ""));
        $base = ($start !== "" && preg_match('/^\d{4}-\d{2}-\d{2}/', $start)) ? date_create_immutable(substr($start, 0, 10)) : false;
        $stamp = gmdate("Ymd\THis\Z");

        $lines = [
            "BEGIN:VCALENDAR",
            "VERSION:2.0",
            "PRODID:-//Smart Travel Itinerary Generator//Malaysian Cultural Tourism//EN",
            "CALSCALE:GREGORIAN",
            "METHOD:PUBLISH",
            "X-WR-CALNAME:" . self::esc((string)($it["title"] ?? "Itinerary")),
            "X-WR-TIMEZONE:" . self::TZID,
            "BEGIN:VTIMEZONE",
            "TZID:" . self::TZID,
            "BEGIN:STANDARD",
            "DTSTART:19700101T000000",
            "TZOFFSETFROM:+0800",
            "TZOFFSETTO:+0800",
            "TZNAME:MYT",
            "END:STANDARD",
            "END:VTIMEZONE",
        ];

        if ($base) {
            foreach ($rows as $r) {
                if (!self::isEligible($r)) continue;
                $s = self::timeParts($r["start_time"]);
                $e = self::timeParts($r["end_time"]);
                $day = max(1, (int)$r["day_no"]);
                $date = $base->modify("+" . ($day - 1) . " days");
                $endDate = ($e <= $s) ? $date->modify("+1 day") : $date;   // runs past midnight
                $dtStart = $date->format("Ymd") . "T" . sprintf("%02d%02d%02d", ...$s);
                $dtEnd = $endDate->format("Ymd") . "T" . sprintf("%02d%02d%02d", ...$e);

                $title = trim((string)($r["item_title"] ?? ""));
                if ($title === "") $title = "Stop";
                $location = trim((string)($r["place_name"] ?? ""));
                if ($location === "") $location = $title;
                $address = trim((string)($r["address"] ?? ""));
                if ($address !== "") $location .= ", " . $address;

                $desc = ["Day " . $day];
                if ((float)($r["estimated_cost"] ?? 0) > 0) $desc[] = "Estimated cost: RM " . number_format((float)$r["estimated_cost"], 2);
                $notes = trim((string)($r["notes"] ?? ""));
                if ($notes !== "") $desc[] = $notes;

                $lines[] = "BEGIN:VEVENT";
                $lines[] = "UID:itinerary-" . $id . "-item-" . (int)$r["item_id"] . "@smart-travel-itinerary";
                $lines[] = "DTSTAMP:" . $stamp;
                $lines[] = "DTSTART;TZID=" . self::TZID . ":" . $dtStart;
                $lines[] = "DTEND;TZID=" . self::TZID . ":" . $dtEnd;
                $lines[] = "SUMMARY:" . self::esc($title);
                $lines[] = "LOCATION:" . self::esc($location);
                if (self::validCoord($r["latitude"] ?? null, $r["longitude"] ?? null)) {
                    $lines[] = "GEO:" . self::coord($r["latitude"]) . ";" . self::coord($r["longitude"]);
                }
                $lines[] = "DESCRIPTION:" . self::esc(implode("\n", $desc));
                $lines[] = "END:VEVENT";
            }
        }
        $lines[] = "END:VCALENDAR";

        $out = "";
        foreach ($lines as $line) $out .= self::fold($line) . "\r\n";
        return $out;
    }

    /** Link set for a stop with coordinates: label => [href, external]. Empty when no valid coordinates. */
    public static function mapLinks($lat, $lng, string $name): array
    {
        if (!self::validCoord($lat, $lng)) return [];
        $la = self::coord($lat);
        $ln = self::coord($lng);
        $label = trim(preg_replace('/\s+/', ' ', $name) ?? "");
        $geo = "geo:" . $la . "," . $ln . ($label !== "" ? "?q=" . $la . "," . $ln . "(" . rawurlencode($label) . ")" : "");
        return [
            "Open in maps app" => ["href" => $geo, "external" => false],
            "OpenStreetMap" => ["href" => "https://www.openstreetmap.org/?mlat=" . $la . "&mlon=" . $ln . "#map=17/" . $la . "/" . $ln, "external" => true],
            "Waze" => ["href" => "https://waze.com/ul?ll=" . $la . "," . $ln . "&navigate=yes", "external" => true],
        ];
    }

    /** Safe ASCII file name stem from a title. */
    public static function fileStem(string $title, int $id): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? "";
        $ascii = trim(substr($ascii, 0, 60), "-");
        return $ascii !== "" ? $ascii : "itinerary-" . $id;
    }

    private static function validCoord($lat, $lng): bool
    {
        if ($lat === null || $lng === null || $lat === "" || $lng === "") return false;
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat = (float)$lat;
        $lng = (float)$lng;
        return abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0.0 && $lng == 0.0);
    }

    private static function coord($v): string
    {
        return rtrim(rtrim(number_format((float)$v, 6, ".", ""), "0"), ".");
    }

    /** [h, m, s] from a TIME value, or null. */
    private static function timeParts($t): ?array
    {
        if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', trim((string)$t), $m)) return null;
        $h = (int)$m[1];
        if ($h > 23) return null;
        return [$h, (int)$m[2], (int)($m[3] ?? 0)];
    }

    private static function esc(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
    }

    /** Fold at 75 octets (continuation lines start with a space), never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $parts = [];
        $cur = "";
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) {
                $parts[] = $cur;
                $cur = "";
                $limit = 74;   // the leading space counts
            }
            $cur .= $ch;
        }
        if ($cur !== "") $parts[] = $cur;
        return implode("\r\n ", $parts);
    }
}
