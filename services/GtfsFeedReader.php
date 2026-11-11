<?php
// services/GtfsFeedReader.php
// Reads the two formats public transport data is published in, without any library:
//   GTFS Realtime  a Protocol Buffers message with the live positions of vehicles
//   GTFS Static    a ZIP file of CSV tables (routes.txt, trips.txt, shapes.txt, ...)
// Only what BusTrackingService needs is read. Both come from outside, so nothing in them is
// trusted: lengths are checked and anything malformed is skipped.

class GtfsFeedReader
{
    /**
     * The vehicles in a GTFS Realtime "vehicle position" feed.
     *
     * @return array<int,array{vehicle_id:string,trip_id:string,route_id:string,lat:float,lng:float,
     *               bearing:?float,speed:?float,timestamp:int}>
     *         speed is as the feed gives it (the standard says metres per second); timestamp is
     *         when the position was measured, in Unix time
     */
    public static function vehiclePositions(string $bytes): array
    {
        // FeedMessage: 1 header, 2 entities. FeedEntity: 4 vehicle. VehiclePosition: 1 trip,
        // 2 position, 5 timestamp, 8 vehicle. The numbers are the field numbers of the format.
        $feed = self::message($bytes);
        $feedTime = (int)(self::message(self::text($feed[1][0] ?? ''))[3][0] ?? 0);
        $vehicles = [];
        foreach ($feed[2] ?? [] as $entityBytes) {
            $entity = self::message(self::text($entityBytes));
            if (!isset($entity[4][0])) continue;
            $vehicle = self::message(self::text($entity[4][0]));
            $position = self::message(self::text($vehicle[2][0] ?? ''));
            $trip = self::message(self::text($vehicle[1][0] ?? ''));
            $descriptor = self::message(self::text($vehicle[8][0] ?? ''));

            $lat = self::float($position[1][0] ?? null);
            $lng = self::float($position[2][0] ?? null);
            if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) continue;

            // id, licence plate, label: whichever the operator fills in
            $id = self::text($descriptor[1][0] ?? '') ?: self::text($descriptor[3][0] ?? '') ?: self::text($descriptor[2][0] ?? '') ?: self::text($entity[1][0] ?? '');
            $vehicles[] = [
                'vehicle_id' => self::id($id),
                'trip_id' => self::id(self::text($trip[1][0] ?? '')),
                'route_id' => self::id(self::text($trip[5][0] ?? '')),
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'bearing' => self::float($position[3][0] ?? null),
                'speed' => self::float($position[5][0] ?? null),
                'timestamp' => is_int($vehicle[5][0] ?? null) ? $vehicle[5][0] : $feedTime,
            ];
        }
        return $vehicles;
    }

    /**
     * A name from a timetable as one clean line. Timetables often write names in capitals:
     * "BATU CAVES" becomes "Batu Caves", while words without a vowel are abbreviations and
     * stay as they are (KL, KLCC, MRT).
     */
    public static function name(string $text): string
    {
        $text = mb_substr(trim((string)preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8'))), 0, 80);
        if ($text === '' || mb_strtoupper($text) !== $text) return $text;
        return (string)preg_replace_callback('/[A-Z]+/', static fn(array $m): string =>
            preg_match('/[AEIOU]/', $m[0]) ? ucfirst(strtolower($m[0])) : $m[0], $text);
    }
    /**
     * One Protocol Buffers message as field number => list of values. A value is an integer
     * (varint fields) or a string of bytes (text, nested messages and fixed-width numbers).
     *
     * @return array<int,array<int,int|string>>
     */
    private static function message(string $bytes): array
    {
        $fields = [];
        $at = 0;
        $length = strlen($bytes);
        while ($at < $length) {
            $key = self::varint($bytes, $at);
            if ($key === null) break;
            $field = $key >> 3;
            switch ($key & 7) {
                case 0:   // a whole number
                    $value = self::varint($bytes, $at);
                    break;
                case 1:   // 8 bytes
                    $value = $at + 8 <= $length ? substr($bytes, $at, 8) : null;
                    $at += 8;
                    break;
                case 2:   // a length, then that many bytes
                    $size = self::varint($bytes, $at);
                    $value = $size !== null && $size >= 0 && $at + $size <= $length ? substr($bytes, $at, $size) : null;
                    $at += (int)$size;
                    break;
                case 5:   // 4 bytes
                    $value = $at + 4 <= $length ? substr($bytes, $at, 4) : null;
                    $at += 4;
                    break;
                default:  // a kind of field this format does not use: the rest cannot be read
                    $value = null;
            }
            if ($value === null) break;
            $fields[$field][] = $value;
        }
        return $fields;
    }

    /** A number stored seven bits at a time, lowest first. Moves $at past it; null when cut off. */
    private static function varint(string $bytes, int &$at): ?int
    {
        $value = 0;
        for ($shift = 0; $shift < 64; $shift += 7) {
            if (!isset($bytes[$at])) return null;
            $byte = ord($bytes[$at++]);
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) return $value;
        }
        return null;
    }

    private static function text($value): string
    {
        return is_string($value) ? $value : '';
    }

    /** An identifier as plain printable text, so it can go into JSON and file names safely. */
    private static function id(string $value): string
    {
        return substr((string)preg_replace('/[^ -~]/', '', $value), 0, 80);
    }

    /** A 4-byte little-endian float, or null when the field is missing or not a number. */
    private static function float($value): ?float
    {
        if (!is_string($value) || strlen($value) !== 4) return null;
        $number = unpack('g', $value)[1];
        return is_finite($number) ? (float)$number : null;
    }

    /**
     * One file out of a ZIP archive held in memory, or null when it is not there or is stored
     * in a way this reader does not know. (PHP's zip extension is not enabled on every server;
     * the zlib extension, which this uses, is.)
     */
    public static function zipFile(string $zip, string $name, int $maxBytes = 60000000): ?string
    {
        // The directory at the end of the archive says where each file starts.
        $end = strrpos($zip, 'PK' . chr(5) . chr(6));
        if ($end === false || strlen($zip) < $end + 22) return null;
        $directory = unpack('vdisk/vstartDisk/vhere/vcount/Vsize/Voffset', substr($zip, $end + 4, 16));
        $at = $directory['offset'];
        for ($i = 0; $i < $directory['count']; $i++) {
            if (substr($zip, $at, 4) !== 'PK' . chr(1) . chr(2) || strlen($zip) < $at + 46) return null;
            $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Vlocal', substr($zip, $at + 4, 42));
            $entryName = substr($zip, $at + 46, $entry['nameLength']);
            $at += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
            if ($entryName !== $name) continue;

            if ($entry['size'] > $maxBytes || strlen($zip) < $entry['local'] + 30) return null;
            $local = unpack('vnameLength/vextraLength', substr($zip, $entry['local'] + 26, 4));
            $packed = substr($zip, $entry['local'] + 30 + $local['nameLength'] + $local['extraLength'], $entry['packed']);
            if ($entry['method'] === 0) return $packed;                       // stored as it is
            if ($entry['method'] !== 8) return null;                          // 8 is "deflate", the usual one
            $content = @gzinflate($packed, $maxBytes);
            return is_string($content) ? $content : null;
        }
        return null;
    }

    /**
     * The rows of a GTFS table (CSV with a header line), each as column name => value.
     *
     * @return Generator<int,array<string,string>>
     */
    public static function rows(string $csv): Generator
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, null, ',', "\"", '');
        if (is_array($header)) {
            $header = array_map(static fn($name): string => trim((string)$name, " \t\r\n" . chr(0xEF) . chr(0xBB) . chr(0xBF)), $header);
            $columns = count($header);
            while (($row = fgetcsv($handle, null, ',', "\"", '')) !== false) {
                if (count($row) !== $columns) continue;   // a blank or broken line
                yield array_combine($header, array_map(static fn($value): string => trim((string)$value), $row));
            }
        }
        fclose($handle);
    }
}
