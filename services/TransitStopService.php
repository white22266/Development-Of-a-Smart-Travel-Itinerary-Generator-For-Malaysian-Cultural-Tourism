<?php
// services/TransitStopService.php
// Public transport stops (OpenStreetMap, ODbL) in the transit_stops table, and the straight-line
// distance from every active place to its nearest stop. Used by tools/sync_transit_stops.php;
// it never runs inside a traveller's page request.

require_once __DIR__ . '/OsmDataService.php';

class TransitStopService
{
    /** Half-size of the box around a place in which stops are looked for, in degrees (about 5.5 km). */
    private const BOX_DEGREES = 0.05;

    private mysqli $conn;
    /** @var callable */
    private $log;

    public function __construct(mysqli $conn, ?callable $log = null)
    {
        $this->conn = $conn;
        $this->log = $log ?? static function (string $line): void {};
    }

    /**
     * Fetch, store and measure one state.
     *
     * @return array{stops:array<string,int>,inserted:int,updated:int,removed:int,places:int}
     * @throws RuntimeException when the stops could not be fetched (nothing is changed then)
     */
    public function syncState(string $state, bool $dryRun = false): array
    {
        $elements = OsmDataService::transitStops($state);
        if ($elements === null) {
            throw new RuntimeException('Overpass did not answer: ' . OsmDataService::lastError());
        }
        $stops = [];
        foreach ($elements as $element) {
            [$type, $id] = explode('/', $element['ref']);
            $stops[$type . '/' . $id] = [
                'type' => $type,
                'id' => (int)$id,
                'name' => mb_substr($element['name'], 0, 200),
                'kind' => self::kindOf($element['tags']),
                'lat' => $element['lat'],
                'lng' => $element['lng'],
            ];
        }
        $byKind = ['rail' => 0, 'bus' => 0, 'ferry' => 0];
        foreach ($stops as $stop) $byKind[$stop['kind']]++;
        $result = ['stops' => $byKind, 'inserted' => 0, 'updated' => 0, 'removed' => 0, 'places' => 0];
        if ($note = OsmDataService::lastNote()) ($this->log)("[{$state}] note: {$note}");
        // An empty answer is never believed: the server may just have lost the area.
        if (!$stops) {
            throw new RuntimeException('Overpass returned no stops; nothing changed.');
        }
        if ($dryRun) return $result;

        $this->conn->begin_transaction();
        try {
            $upsert = $this->conn->prepare(
                'INSERT INTO transit_stops (osm_type, osm_id, name, kind, latitude, longitude, state)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind), latitude = VALUES(latitude),
                                         longitude = VALUES(longitude), state = VALUES(state)'
            );
            foreach ($stops as $s) {
                $upsert->bind_param('sissdds', $s['type'], $s['id'], $s['name'], $s['kind'], $s['lat'], $s['lng'], $state);
                $upsert->execute();
                // 1 = new row, 2 = changed row, 0 = same as before
                if ($upsert->affected_rows === 1) $result['inserted']++;
                elseif ($upsert->affected_rows === 2) $result['updated']++;
            }
            $upsert->close();

            // Remove the stops of this state that the answer no longer has.
            $gone = [];
            $query = $this->conn->prepare('SELECT stop_id, osm_type, osm_id FROM transit_stops WHERE state = ?');
            $query->bind_param('s', $state);
            $query->execute();
            $rows = $query->get_result();
            while ($row = $rows->fetch_assoc()) {
                if (!isset($stops[$row['osm_type'] . '/' . $row['osm_id']])) $gone[] = (int)$row['stop_id'];
            }
            $query->close();
            foreach (array_chunk($gone, 500) as $chunk) {
                $this->conn->query('DELETE FROM transit_stops WHERE stop_id IN (' . implode(',', $chunk) . ')');
            }
            $result['removed'] = count($gone);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        $result['places'] = $this->measurePlaces($state);
        return $result;
    }

    /**
     * Work out nearest_stop_m and nearest_rail_m for every active place of the state, looking
     * only at stops inside a small box around the place. NULL means no such stop in the box.
     *
     * @return int number of places measured
     */
    public function measurePlaces(string $state): int
    {
        $places = $this->conn->prepare('SELECT place_id, latitude, longitude FROM cultural_places WHERE state = ? AND is_active = 1');
        $places->bind_param('s', $state);
        $places->execute();
        $list = $places->get_result()->fetch_all(MYSQLI_ASSOC);
        $places->close();

        $near = $this->conn->prepare(
            'SELECT kind, latitude, longitude FROM transit_stops
             WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?'
        );
        $save = $this->conn->prepare(
            'UPDATE cultural_places SET nearest_stop_m = ?, nearest_rail_m = ?, transit_checked_at = NOW() WHERE place_id = ?'
        );
        $count = 0;
        foreach ($list as $place) {
            if ($place['latitude'] === null || $place['longitude'] === null) continue;
            $lat = (float)$place['latitude'];
            $lng = (float)$place['longitude'];
            $minLat = $lat - self::BOX_DEGREES;
            $maxLat = $lat + self::BOX_DEGREES;
            $dLng = self::BOX_DEGREES / max(0.2, cos(deg2rad($lat)));
            $minLng = $lng - $dLng;
            $maxLng = $lng + $dLng;
            $near->bind_param('dddd', $minLat, $maxLat, $minLng, $maxLng);
            $near->execute();
            $any = null;
            $rail = null;
            $rows = $near->get_result();
            while ($stop = $rows->fetch_assoc()) {
                $d = self::distanceM($lat, $lng, (float)$stop['latitude'], (float)$stop['longitude']);
                if ($any === null || $d < $any) $any = $d;
                if ($stop['kind'] === 'rail' && ($rail === null || $d < $rail)) $rail = $d;
            }
            $save->bind_param('iii', $any, $rail, $place['place_id']);
            $save->execute();
            $count++;
        }
        $near->close();
        $save->close();
        return $count;
    }

    /** Stops by kind and places within 400 / 800 / 1500 m of a stop, or with none within 1500 m. */
    public function summary(string $state): array
    {
        $out = ['rail' => 0, 'bus' => 0, 'ferry' => 0];
        $q = $this->conn->prepare('SELECT kind, COUNT(*) n FROM transit_stops WHERE state = ? GROUP BY kind');
        $q->bind_param('s', $state);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) $out[$row['kind']] = (int)$row['n'];
        $q->close();

        $q = $this->conn->prepare(
            'SELECT COUNT(*) total,
                    COALESCE(SUM(nearest_stop_m <= 400), 0) w400,
                    COALESCE(SUM(nearest_stop_m <= 800), 0) w800,
                    COALESCE(SUM(nearest_stop_m <= 1500), 0) w1500,
                    COALESCE(SUM(nearest_stop_m IS NULL OR nearest_stop_m > 1500), 0) none1500
             FROM cultural_places WHERE state = ? AND is_active = 1'
        );
        $q->bind_param('s', $state);
        $q->execute();
        $p = $q->get_result()->fetch_assoc();
        $q->close();
        return ['stops' => $out, 'places' => array_map('intval', $p)];
    }

    /** rail, ferry or bus, from the OSM tags of a stop. */
    private static function kindOf(array $tags): string
    {
        if (in_array($tags['railway'] ?? '', ['station', 'halt', 'tram_stop'], true)
            || in_array($tags['station'] ?? '', ['light_rail', 'subway', 'monorail', 'train'], true)
            || (($tags['public_transport'] ?? '') === 'station' && ($tags['train'] ?? '') === 'yes')) {
            return 'rail';
        }
        if (($tags['amenity'] ?? '') === 'ferry_terminal') return 'ferry';
        return 'bus';
    }

    /** Straight-line distance in metres (haversine). */
    private static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return (int)round(6371000 * 2 * asin(min(1.0, sqrt($a))));
    }
}
