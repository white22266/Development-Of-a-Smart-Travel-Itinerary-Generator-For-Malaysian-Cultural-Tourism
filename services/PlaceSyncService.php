<?php
// services/PlaceSyncService.php
// Keeps the place database up to date from open data instead of manual entry.
//
// For one state it:
//   1. reads visitor places from OpenStreetMap, with details from Wikidata, Wikipedia and Commons;
//   2. links existing places to their OpenStreetMap entry and fills in details that are missing
//      (opening hours, phone, website, a photo, a very short description). Existing values are kept;
//   3. stages places the database does not have yet. Well-documented ones are published
//      automatically, the rest wait for an admin on admin/admin_place_imports.php;
//   4. refreshes the list of hotels and other lodging used for hotel suggestions.
//
// Every value changed on an existing place is recorded in place_change_log.
// Run it from tools/sync_places.php.

require_once __DIR__ . '/OsmDataService.php';
require_once __DIR__ . '/WikimediaService.php';
require_once __DIR__ . '/GeocodingService.php';
require_once __DIR__ . '/PlaceNameMatcher.php';
require_once __DIR__ . '/OpeningHoursConverter.php';

class PlaceSyncService
{
    /** Candidates below this score are not worth an admin's time and are not stored. */
    private const STAGE_MIN_SCORE = 40;

    /** historic=* values that are too small to be a stop on an itinerary. */
    private const MINOR_HISTORIC = ['wayside_shrine', 'boundary_stone', 'milestone', 'cannon', 'wreck', 'aircraft', 'tank', 'stone', 'district'];

    public const CATEGORIES = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];

    /** Columns of cultural_places the sync may fill in on an existing place. */
    private const SYNCED_FIELDS = ['opening_hours', 'phone_number', 'website_url', 'description', 'image_url'];

    /** Kinds of place that are often large enough to reach across a state border. */
    private const LARGE_KINDS = ['national_park', 'nature_reserve', 'park', 'beach', 'street'];

    /** kind => [phrase for descriptions, visit minutes, outdoor, default entrance fee (RM), best time, dress code] */
    private const KIND_PROFILE = [
        'museum'         => ['a museum', 90, 0, 10.0, 'Morning or afternoon', 0],
        'gallery'        => ['an art gallery', 60, 0, 0.0, 'Morning or afternoon', 0],
        'planetarium'    => ['a planetarium', 90, 0, 12.0, 'Morning or afternoon', 0],
        'theme_park'     => ['a theme park', 240, 1, 60.0, 'Morning or afternoon', 0],
        'water_park'     => ['a water park', 180, 1, 50.0, 'Morning or afternoon', 0],
        'zoo'            => ['a zoo', 150, 1, 30.0, 'Morning', 0],
        'aquarium'       => ['an aquarium', 90, 0, 40.0, 'Morning or afternoon', 0],
        'fort'           => ['a historic fort', 60, 1, 0.0, 'Morning or afternoon', 0],
        'monument'       => ['a monument', 30, 1, 0.0, 'Morning or afternoon', 0],
        'ruins'          => ['a historic ruin', 60, 1, 0.0, 'Morning or afternoon', 0],
        'historic'       => ['a historic site', 45, 1, 0.0, 'Morning or afternoon', 0],
        'worship'        => ['a place of worship', 45, 0, 0.0, 'Morning or afternoon', 1],
        'national_park'  => ['a national park', 240, 1, 0.0, 'Morning', 0],
        'waterfall'      => ['a waterfall', 90, 1, 0.0, 'Morning', 0],
        'beach'          => ['a beach', 90, 1, 0.0, 'Morning or evening', 0],
        'hot_spring'     => ['a hot spring', 90, 1, 0.0, 'Morning or evening', 0],
        'cave'           => ['a cave', 60, 1, 0.0, 'Morning', 0],
        'peak'           => ['a mountain peak', 180, 1, 0.0, 'Early morning', 0],
        'nature_reserve' => ['a nature reserve', 120, 1, 0.0, 'Morning', 0],
        'viewpoint'      => ['a viewpoint', 30, 1, 0.0, 'Morning or evening', 0],
        'attraction'     => ['a visitor attraction', 60, 0, 0.0, 'Morning or afternoon', 0],
        'park'           => ['a park', 60, 1, 0.0, 'Morning or evening', 0],
        'market'         => ['a market', 60, 0, 0.0, 'Morning', 0],
        'mall'           => ['a shopping mall', 90, 0, 0.0, 'Afternoon or evening', 0],
        'arts_centre'    => ['an arts venue', 90, 0, 0.0, 'Evening', 0],
        'lighthouse'     => ['a lighthouse', 30, 1, 0.0, 'Morning or evening', 0],
        'tower'          => ['an observation tower', 45, 0, 20.0, 'Morning or evening', 0],
        'street'         => ['a historic street', 60, 1, 0.0, 'Evening', 0],
        'artwork'        => ['a public artwork', 20, 1, 0.0, 'Morning or afternoon', 0],
    ];

    private mysqli $conn;
    private bool $dryRun;
    private int $autoPublishMin;
    /** @var callable(string):void */
    private $log;
    /** @var array<string,array> English Wikipedia lead texts for the state being synced, by article title */
    private array $summaries = [];
    /** @var array<string,array> Commons photos for the state being synced, by file name */
    private array $images = [];
    /** @var array<string,array<string,string>> state => lowercased district name => district name as stored */
    private array $districtNames = [];
    /** @var array<string,bool> "place_id:field" of automatic changes an admin undid */
    private array $reverted = [];

    /**
     * @param array $options dry_run (bool): report only, write nothing;
     *                       auto_publish_min (int): score needed to publish without review
     *                       (default 80, or PLACE_SYNC_AUTO_PUBLISH_MIN; above 100 = never);
     *                       log (callable(string)): progress lines
     */
    public function __construct(mysqli $conn, array $options = [])
    {
        $this->conn = $conn;
        $this->dryRun = (bool)($options['dry_run'] ?? false);
        $envMin = getenv('PLACE_SYNC_AUTO_PUBLISH_MIN');
        $this->autoPublishMin = (int)($options['auto_publish_min'] ?? ($envMin !== false && $envMin !== '' ? $envMin : 80));
        $this->log = $options['log'] ?? static function (string $line): void {
        };
    }

    // ------------------------------------------------------------------ one state

    /** @return array{osm_places:int,linked:int,fields_filled:int,staged:int,published:int,duplicates:int,lodgings:int} */
    public function syncState(string $state): array
    {
        if (!isset(OsmDataService::STATE_ISO[$state])) {
            throw new InvalidArgumentException("Unknown state: {$state}");
        }
        $stats = ['osm_places' => 0, 'linked' => 0, 'fields_filled' => 0, 'staged' => 0, 'published' => 0, 'duplicates' => 0, 'lodgings' => 0];
        $runId = $this->startRun($state);

        try {
            ($this->log)("[{$state}] reading visitor places from OpenStreetMap...");
            $elements = OsmDataService::visitorPlaces($state);
            if ($elements === null) {
                throw new RuntimeException('OpenStreetMap could not be read: ' . OsmDataService::lastError());
            }
            $stats['osm_places'] = count($elements);

            $elements = $this->attachWikidata($elements);
            $this->preloadWikimedia($elements);
            $existing = $this->loadExistingPlaces($state);

            $corpus = array_column($existing, 'name');
            foreach ($elements as $element) {
                foreach ($element['names'] as $name) $corpus[] = $name;
            }
            $matcher = new PlaceNameMatcher($corpus);

            [$links, $claimed] = $this->linkExistingPlaces($existing, $elements, $matcher);
            $stats['linked'] = count($links);
            ($this->log)("[{$state}] {$stats['osm_places']} OSM places, " . count($existing) . " in the database, {$stats['linked']} linked");

            foreach ($links as $placeIndex => $elementIndex) {
                $stats['fields_filled'] += $this->enrichExistingPlace($existing[$placeIndex], $elements[$elementIndex]);
            }

            $candidates = $this->findNewPlaces($state, $elements, $claimed, $existing, $matcher);
            foreach ($candidates as $candidate) {
                $candidate['duplicate_of'] !== null ? $stats['duplicates']++ : $stats['staged']++;
                if ($this->dryRun) {
                    $fate = $candidate['duplicate_of'] !== null ? 'duplicate of #' . $candidate['duplicate_of']
                        : ($candidate['score'] >= $this->autoPublishMin ? 'publish' : 'review');
                    $has = ($candidate['payload']['description_source'] === 'wikipedia' ? 'W' : '-')
                        . ($candidate['payload']['image'] !== null ? 'P' : '-')
                        . ($candidate['payload']['opening_hours'] !== null ? 'H' : '-');
                    ($this->log)(sprintf('  %3d %s %-9s %s  [%s]', $candidate['score'], $has, $candidate['category'], $candidate['name'], $fate));
                    continue;
                }
                $candidateId = $this->saveCandidate($state, $candidate);
                if ($candidateId !== null && $candidate['duplicate_of'] === null && $candidate['score'] >= $this->autoPublishMin) {
                    if ($this->publishCandidate($candidateId, null) !== null) {
                        $stats['published']++;
                        ($this->log)("  published ({$candidate['score']}): {$candidate['name']}");
                    }
                }
            }
            if ($this->dryRun) {
                $stats['published'] = count(array_filter($candidates, fn($c) => $c['duplicate_of'] === null && $c['score'] >= $this->autoPublishMin));
            }



            $stats['lodgings'] = $this->syncLodging($state);
            $this->finishRun($runId, 'done', $stats, null);
        } catch (Throwable $e) {
            $this->finishRun($runId, 'failed', $stats, $e->getMessage());
            throw $e;
        }

        ($this->log)(sprintf(
            '[%s] done: %d details filled in, %d new places (%d published, %d waiting for review), %d possible duplicates, %d lodgings%s',
            $state, $stats['fields_filled'], $stats['staged'], $stats['published'], $stats['staged'] - $stats['published'],
            $stats['duplicates'], $stats['lodgings'], $this->dryRun ? '  (dry run: nothing was saved)' : ''
        ));
        return $stats;
    }

    // ------------------------------------------------------------------ classification

    /**
     * What kind of visitor place an OpenStreetMap element is.
     *
     * @return array{kind:string,category:string,base:int}|null null = not a place this app lists
     */
    public static function classify(array $t): ?array
    {
        $tourism = $t['tourism'] ?? '';
        $historic = $t['historic'] ?? '';
        $amenity = $t['amenity'] ?? '';
        $leisure = $t['leisure'] ?? '';
        $natural = $t['natural'] ?? '';
        $boundary = $t['boundary'] ?? '';
        $shop = $t['shop'] ?? '';
        $worship = $amenity === 'place_of_worship' || in_array($t['building'] ?? '', ['mosque', 'temple', 'church', 'cathedral', 'shrine'], true);
        $is = static fn(string $kind, string $category, int $base): array => ['kind' => $kind, 'category' => $category, 'base' => $base];

        if ($tourism === 'museum') return $is('museum', 'museum', 40);
        if ($tourism === 'gallery') return $is('gallery', 'museum', 30);
        if ($amenity === 'planetarium') return $is('planetarium', 'museum', 35);
        if ($tourism === 'theme_park') return $is('theme_park', 'culture', 40);
        if ($leisure === 'water_park') return $is('water_park', 'culture', 35);
        if ($tourism === 'zoo') return $is('zoo', 'nature', 40);
        if ($tourism === 'aquarium') return $is('aquarium', 'nature', 40);

        if ($historic !== '' && !in_array($historic, self::MINOR_HISTORIC, true)) {
            if ($worship) return $is('worship', 'heritage', 35);
            if (in_array($historic, ['fort', 'castle', 'city_gate', 'citywalls'], true)) return $is('fort', 'heritage', 35);
            if ($historic === 'monument') return $is('monument', 'heritage', 30);
            if ($historic === 'memorial') return $is('monument', 'heritage', 20);
            if (in_array($historic, ['ruins', 'archaeological_site'], true)) return $is('ruins', 'heritage', 35);
            return $is('historic', 'heritage', $historic === 'yes' ? 25 : 35);
        }
        if ($worship) return $is('worship', 'heritage', 15);

        if ($boundary === 'national_park') return $is('national_park', 'nature', 40);
        if ($natural === 'waterfall') return $is('waterfall', 'nature', 30);
        if ($natural === 'beach') return $is('beach', 'nature', 30);
        if ($natural === 'hot_spring') return $is('hot_spring', 'nature', 30);
        if ($natural === 'cave_entrance') return $is('cave', 'nature', 30);
        if ($natural === 'peak') return $is('peak', 'nature', 20);
        if ($leisure === 'nature_reserve' || $boundary === 'protected_area') return $is('nature_reserve', 'nature', 25);
        if ($tourism === 'viewpoint') return $is('viewpoint', 'nature', 25);
        if ($tourism === 'attraction') return $is('attraction', 'culture', 35);
        if ($leisure === 'park' || $leisure === 'garden') return $is('park', 'nature', 15);
        if ($amenity === 'marketplace') return $is('market', 'shopping', 25);
        if ($shop === 'mall' || $shop === 'department_store') return $is('mall', 'shopping', 20);
        if ($amenity === 'theatre' || $amenity === 'arts_centre') return $is('arts_centre', 'culture', 20);
        if (($t['man_made'] ?? '') === 'lighthouse') return $is('lighthouse', 'heritage', 25);
        if (($t['man_made'] ?? '') === 'tower' && ($t['tower:type'] ?? '') === 'observation') return $is('tower', 'culture', 30);
        if (($t['highway'] ?? '') === 'pedestrian') return $is('street', 'culture', 30);
        if ($tourism === 'artwork') return $is('artwork', 'culture', 10);
        return null;
    }

    // ------------------------------------------------------------------ step 1: details from Wikidata

    private function attachWikidata(array $elements): array
    {
        $ids = [];
        foreach ($elements as $element) {
            $id = $element['tags']['wikidata'] ?? '';
            if (preg_match('/^Q\d+$/', $id)) $ids[] = $id;
        }
        $entities = $ids ? WikimediaService::entities($ids) : [];
        ($this->log)('  Wikidata entries: ' . count($entities));

        foreach ($elements as &$element) {
            $entity = $entities[$element['tags']['wikidata'] ?? ''] ?? null;
            $element['wd'] = $entity;
            if ($entity) {
                $extra = array_filter(array_merge([$entity['label'], $entity['label_ms']], $entity['aliases']));
                $element['names'] = array_values(array_unique(array_merge($element['names'], $extra)));
            }
        }
        unset($element);
        return $elements;
    }

    /** Fetch the Wikipedia texts and Commons photos of all elements in a few batched requests. */
    private function preloadWikimedia(array $elements): void
    {
        $titles = [];
        $files = [];
        foreach ($elements as $element) {
            $title = self::wikipediaTitle($element);
            if ($title !== null) $titles[$title] = true;
            $file = self::commonsFile($element);
            if ($file !== null) $files[$file] = true;
        }
        $titles = array_map('strval', array_keys($titles));
        $files = array_map('strval', array_keys($files));
        $this->summaries = $titles ? WikimediaService::wikipediaSummaries('en', $titles) : [];
        foreach ($this->summaries as &$summary) {
            $summary['extract'] = self::cleanExtract($summary['extract']);
        }
        unset($summary);
        $this->images = $files ? WikimediaService::commonsImages($files) : [];
        ($this->log)(sprintf(
            '  Wikipedia articles: %d of %d, Commons photos: %d of %d',
            count($this->summaries), count($titles), count($this->images), count($files)
        ));
    }

    // ------------------------------------------------------------------ step 2: existing places

    private function loadExistingPlaces(string $state): array
    {
        $stmt = $this->conn->prepare("
            SELECT cp.place_id, cp.name, cp.category, cp.district, cp.latitude, cp.longitude, cp.description,
                   cp.opening_hours, cp.phone_number, cp.website_url, cp.image_url, cp.is_active,
                   ps.osm_ref, ps.wikidata_id, ps.image_source_url
            FROM cultural_places cp
            LEFT JOIN place_sources ps ON ps.place_id = cp.place_id
            WHERE cp.state = ?
        ");
        $stmt->bind_param('s', $state);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->reverted = [];
        $undone = $this->conn->prepare("
            SELECT l.place_id, l.field
            FROM place_change_log l
            JOIN cultural_places cp ON cp.place_id = l.place_id
            WHERE cp.state = ? AND l.reverted_at IS NOT NULL
        ");
        $undone->bind_param('s', $state);
        $undone->execute();
        foreach ($undone->get_result()->fetch_all(MYSQLI_NUM) as [$placeId, $field]) {
            $this->reverted["{$placeId}:{$field}"] = true;
        }
        $undone->close();
        return $rows;
    }

    /**
     * Find the OpenStreetMap element of each existing place: same name (allowing Malay/English
     * differences) and close by. The closer two points are, the less the names need to agree.
     *
     * @return array{0: array<int,int>, 1: array<int,bool>} place index => element index; claimed element indexes
     */
    private function linkExistingPlaces(array $existing, array $elements, PlaceNameMatcher $matcher): array
    {
        $grid = self::buildGrid($elements, 'lat', 'lng');
        $byRef = [];
        foreach ($elements as $index => $element) $byRef[$element['ref']] = $index;

        $proposals = [];
        foreach ($existing as $placeIndex => $place) {
            if (!empty($place['osm_ref']) && isset($byRef[$place['osm_ref']])) {
                $proposals[] = ['place' => $placeIndex, 'element' => $byRef[$place['osm_ref']], 'score' => 10.0];
                continue;
            }
            if ($place['latitude'] === null || $place['longitude'] === null) continue;
            $lat = (float)$place['latitude'];
            $lng = (float)$place['longitude'];

            $best = null;
            foreach (self::nearby($grid, $lat, $lng) as $elementIndex) {
                $element = $elements[$elementIndex];
                $km = self::haversineKm($lat, $lng, $element['lat'], $element['lng']);
                if ($km > 2.0) continue;
                $similarity = 0.0;
                foreach ($element['names'] as $name) {
                    $similarity = max($similarity, $matcher->similarity((string)$place['name'], $name));
                }
                $same = ($km <= 0.08 && $similarity >= 0.45) || ($km <= 0.4 && $similarity >= 0.6) || $similarity >= 0.85;
                if (!$same) continue;
                $score = $similarity - min(0.3, $km / 5);
                if ($best === null || $score > $best['score']) {
                    $best = ['place' => $placeIndex, 'element' => $elementIndex, 'score' => $score];
                }
            }
            if ($best !== null) $proposals[] = $best;
        }

        // When two database rows point at one element (a duplicate in the database), the better match keeps it.
        usort($proposals, fn($a, $b) => $b['score'] <=> $a['score']);
        $links = [];
        $claimed = [];
        foreach ($proposals as $proposal) {
            if (isset($claimed[$proposal['element']])) {
                $other = $existing[array_search($proposal['element'], $links, true)]['name'] ?? '?';
                ($this->log)("  note: \"{$existing[$proposal['place']]['name']}\" and \"{$other}\" look like the same place");
                continue;
            }
            $links[$proposal['place']] = $proposal['element'];
            $claimed[$proposal['element']] = true;
        }
        return [$links, $claimed];
    }

    /** Record the link and fill in what the place is missing. Returns the number of fields filled. */
    private function enrichExistingPlace(array $place, array $element): int
    {
        $tags = $element['tags'];
        $wd = $element['wd'] ?? null;
        $placeId = (int)$place['place_id'];
        $changes = [];   // field => [new value, source, source ref]

        $hours = OpeningHoursConverter::fromOsm((string)($tags['opening_hours'] ?? ''));
        $current = trim((string)($place['opening_hours'] ?? ''));
        if ($hours !== null && ($current === '' || preg_match('/verify|varies/i', $current))) {
            $changes['opening_hours'] = [$hours, 'osm', $element['ref']];
        }

        $phone = self::firstValue((string)($tags['phone'] ?? $tags['contact:phone'] ?? ''));
        if ($phone !== '' && mb_strlen($phone) <= 20 && trim((string)($place['phone_number'] ?? '')) === '') {
            $changes['phone_number'] = [$phone, 'osm', $element['ref']];
        }

        $website = self::cleanUrl((string)($tags['website'] ?? $tags['contact:website'] ?? ($wd['website'] ?? '')));
        if ($website !== '' && mb_strlen($website) <= 300 && trim((string)($place['website_url'] ?? '')) === '') {
            $changes['website_url'] = [$website, isset($tags['website']) || isset($tags['contact:website']) ? 'osm' : 'wikidata', $element['ref']];
        }

        $wikipedia = $this->wikipediaFor($element);
        if ($wikipedia !== null && mb_strlen(trim((string)($place['description'] ?? ''))) < 60) {
            $changes['description'] = [self::trimToSentences($wikipedia['extract'], 700), 'wikipedia', $wikipedia['url']];
        }

        // A photo is added once. If an admin later removes it, it stays removed.
        $neverHadPhoto = trim((string)($place['image_url'] ?? '')) === '' && empty($place['image_source_url']);
        $photo = $neverHadPhoto ? $this->imageFor($element) : null;

        // A change that an admin undid is not made again.
        foreach (array_keys($changes) as $field) {
            if (isset($this->reverted["{$placeId}:{$field}"])) unset($changes[$field]);
        }
        if (isset($this->reverted["{$placeId}:image_url"])) $photo = null;

        if ($this->dryRun) {
            foreach ($changes as $field => [$value]) {
                ($this->log)("  would fill {$field} of \"{$place['name']}\": " . mb_substr(str_replace("\n", ' | ', $value), 0, 70));
            }
            if ($photo !== null) ($this->log)("  would add a photo to \"{$place['name']}\"");
            return count($changes) + ($photo !== null ? 1 : 0);
        }

        // osm_ref is unique: if another place already holds this element, leave both rows alone.
        $taken = $this->conn->prepare("SELECT place_id FROM place_sources WHERE osm_ref = ? AND place_id <> ? LIMIT 1");
        $taken->bind_param('si', $element['ref'], $placeId);
        $taken->execute();
        $holder = $taken->get_result()->fetch_row();
        $taken->close();
        if ($holder) {
            ($this->log)("  note: \"{$place['name']}\" matches {$element['ref']}, which place #{$holder[0]} already uses");
            return 0;
        }

        $wikidataId = preg_match('/^Q\d+$/', (string)($tags['wikidata'] ?? '')) ? $tags['wikidata'] : null;
        $wikipediaUrl = $wikipedia['url'] ?? null;
        $wheelchair = in_array($tags['wheelchair'] ?? '', ['yes', 'limited', 'no'], true) ? $tags['wheelchair'] : null;
        $descriptionSource = isset($changes['description']) ? 'wikipedia' : null;
        $stmt = $this->conn->prepare("
            INSERT INTO place_sources (place_id, origin, osm_ref, wikidata_id, wikipedia_url, description_source, wheelchair, synced_at)
            VALUES (?, 'manual', ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE osm_ref = VALUES(osm_ref), wikidata_id = VALUES(wikidata_id),
                wikipedia_url = VALUES(wikipedia_url), wheelchair = VALUES(wheelchair), synced_at = NOW(),
                description_source = COALESCE(VALUES(description_source), description_source)
        ");
        $stmt->bind_param('isssss', $placeId, $element['ref'], $wikidataId, $wikipediaUrl, $descriptionSource, $wheelchair);
        $stmt->execute();
        $stmt->close();

        foreach ($changes as $field => [$value, $source, $sourceRef]) {
            // $field is one of the fixed column names above, never user input.
            $update = $this->conn->prepare("UPDATE cultural_places SET `{$field}` = ? WHERE place_id = ?");
            $update->bind_param('si', $value, $placeId);
            $update->execute();
            $update->close();

            $old = $place[$field] ?? null;
            $logStmt = $this->conn->prepare("INSERT INTO place_change_log (place_id, field, old_value, new_value, source, source_ref) VALUES (?, ?, ?, ?, ?, ?)");
            $logStmt->bind_param('isssss', $placeId, $field, $old, $value, $source, $sourceRef);
            $logStmt->execute();
            $logStmt->close();
        }
        $photoAdded = $photo !== null && $this->attachPhoto($placeId, $photo, $element['ref']);
        return count($changes) + ($photoAdded ? 1 : 0);
    }

    /** Download a Commons photo for a place that has none, and record its credit. */
    private function attachPhoto(int $placeId, array $image, string $osmRef): bool
    {
        $path = $this->downloadImage($image, $osmRef);
        if ($path === null) return false;
        [$page, $credit, $license] = self::imageCredits($image);

        $update = $this->conn->prepare("UPDATE cultural_places SET image_url = ? WHERE place_id = ?");
        $update->bind_param('si', $path, $placeId);
        $update->execute();
        $update->close();

        $source = $this->conn->prepare("UPDATE place_sources SET image_source_url = ?, image_credit = ?, image_license = ? WHERE place_id = ?");
        $source->bind_param('sssi', $page, $credit, $license, $placeId);
        $source->execute();
        $source->close();

        $logStmt = $this->conn->prepare("INSERT INTO place_change_log (place_id, field, old_value, new_value, source, source_ref) VALUES (?, 'image_url', NULL, ?, 'commons', ?)");
        $logStmt->bind_param('iss', $placeId, $path, $page);
        $logStmt->execute();
        $logStmt->close();
        return true;
    }

    /** @return array{0:?string,1:?string,2:?string} Commons page, author and licence, cut to the column sizes */
    private static function imageCredits(?array $image): array
    {
        if ($image === null) return [null, null, null];
        return [
            mb_substr((string)($image['page_url'] ?? ''), 0, 500) ?: null,
            mb_substr((string)($image['artist'] ?? ''), 0, 300) ?: null,
            mb_substr((string)($image['license'] ?? ''), 0, 80) ?: null,
        ];
    }

    // ------------------------------------------------------------------ step 3: new places

    /**
     * OpenStreetMap places the database does not have, scored by how well documented they are.
     *
     * @return array<int,array> each with osm_ref, wikidata_id, name, category, lat, lng, district, score, duplicate_of, payload
     */
    private function findNewPlaces(string $state, array $elements, array $claimed, array $existing, PlaceNameMatcher $matcher): array
    {
        $known = $this->knownOsmRefs();
        $existingGrid = self::buildGrid($existing, 'latitude', 'longitude');
        $existingByWikidata = [];
        foreach ($existing as $index => $place) {
            if (!empty($place['wikidata_id'])) $existingByWikidata[$place['wikidata_id']] = $index;
        }

        // Score every unknown element by how well documented it is.
        $pool = [];
        foreach ($elements as $index => $element) {
            if (isset($claimed[$index]) || isset($known[$element['ref']])) continue;
            $class = self::classify($element['tags']);
            if ($class === null) continue;
            $name = $this->displayName($element);
            if ($name === null) continue;

            $tags = $element['tags'];
            $wd = $element['wd'] ?? null;
            $wikipedia = $this->wikipediaFor($element);
            $image = $this->imageFor($element);
            // A Wikipedia article shows that people have written about the place. A Wikidata id
            // alone does not: many were created by a bot from a list of place names.
            $hasArticle = $wikipedia !== null || !empty($tags['wikipedia']) || !empty($wd['enwiki']) || !empty($wd['mswiki']);
            $score = $class['base']
                + ($hasArticle ? 30 : ($wd !== null ? 5 : 0))
                + ($wikipedia !== null ? 10 : 0)
                + ($image !== null ? 10 : 0)
                + (!empty($tags['website']) || !empty($tags['contact:website']) ? 5 : 0)
                + (OpeningHoursConverter::fromOsm((string)($tags['opening_hours'] ?? '')) !== null ? 5 : 0)
                + ($class['kind'] !== 'attraction' && ($tags['tourism'] ?? '') === 'attraction' ? 5 : 0)
                - (preg_match('/^(surau|taman permainan|padang|dewan|tadika|playground)\b/i', $name) ? 30 : 0);
            $score = max(0, min(100, $score));
            if ($score < self::STAGE_MIN_SCORE) continue;
            $pool[] = ['index' => $index, 'class' => $class, 'name' => $name, 'score' => $score, 'wikipedia' => $wikipedia, 'image' => $image];
        }
        usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);

        $candidates = [];
        $acceptedGrid = [];
        $acceptedWikidata = [];
        foreach ($pool as $entry) {
            $element = $elements[$entry['index']];
            $wikidataId = preg_match('/^Q\d+$/', (string)($element['tags']['wikidata'] ?? '')) ? $element['tags']['wikidata'] : null;

            // OSM often maps one place twice (a building outline and a point). The pool is sorted
            // best first, so the better documented one is kept.
            if ($wikidataId !== null && isset($acceptedWikidata[$wikidataId])) continue;
            if ($this->alreadyAccepted($acceptedGrid, $candidates, $element, $matcher)) continue;
            if ($this->centreIsElsewhere($state, $element, $entry['class']['kind'])) continue;
            // A second OSM element for a place that is already linked to one: nothing new to review.
            $sameEntity = $wikidataId !== null ? ($existingByWikidata[$wikidataId] ?? null) : null;
            if ($sameEntity !== null && !empty($existing[$sameEntity]['osm_ref'])) continue;

            $candidates[] = [
                'osm_ref' => $element['ref'],
                'wikidata_id' => $wikidataId,
                'name' => $entry['name'],
                'category' => $entry['class']['category'],
                'lat' => $element['lat'],
                'lng' => $element['lng'],
                'district' => self::nearestDistrict($existing, $element['lat'], $element['lng']),
                'score' => $entry['score'],
                'duplicate_of' => $this->existingDuplicate($element, $wikidataId, $existing, $existingGrid, $existingByWikidata, $matcher),
                'payload' => $this->buildPayload($state, $element, $entry['class'], $entry['name'], $entry['wikipedia'], $entry['image']),
            ];
            $acceptedGrid[self::cell($element['lat'], $element['lng'])][] = count($candidates) - 1;
            if ($wikidataId !== null) $acceptedWikidata[$wikidataId] = true;
        }
        return $candidates;
    }

    /** osm_refs already published as places, or already decided by an admin. */
    private function knownOsmRefs(): array
    {
        $known = [];
        $result = $this->conn->query("
            SELECT osm_ref FROM place_sources WHERE osm_ref IS NOT NULL
            UNION
            SELECT osm_ref FROM place_import_candidates WHERE status IN ('published', 'rejected', 'linked')
        ");
        while ($row = $result->fetch_row()) $known[$row[0]] = true;
        return $known;
    }

    /**
     * A park or reserve on a state border is listed by both states. It belongs to the state
     * its centre is in; the sync of that state picks it up.
     */
    private function centreIsElsewhere(string $state, array $element, string $kind): bool
    {
        $large = str_starts_with($element['ref'], 'relation/')
            || (str_starts_with($element['ref'], 'way/') && in_array($kind, self::LARGE_KINDS, true));
        if (!$large) return false;

        $iso = GeocodingService::reverse($element['lat'], $element['lng'])['state_iso'] ?? null;
        if ($iso === null || $iso === OsmDataService::STATE_ISO[$state]) return false;
        ($this->log)("  skipped \"{$element['name']}\": its centre is in another state ({$iso})");
        return true;
    }

    /** True when a candidate with the same name was already accepted right next to this element. */
    private function alreadyAccepted(array $grid, array $candidates, array $element, PlaceNameMatcher $matcher): bool
    {
        foreach (self::nearby($grid, $element['lat'], $element['lng']) as $candidateIndex) {
            $other = $candidates[$candidateIndex];
            $km = self::haversineKm($element['lat'], $element['lng'], $other['lat'], $other['lng']);
            if ($km <= 0.25 && $matcher->similarity($element['name'], $other['payload']['local_name']) >= 0.85) return true;
        }
        return false;
    }

    /** place_id of an existing place this element probably duplicates, or null. */
    private function existingDuplicate(array $element, ?string $wikidataId, array $existing, array $grid, array $byWikidata, PlaceNameMatcher $matcher): ?int
    {
        if ($wikidataId !== null && isset($byWikidata[$wikidataId])) {
            return (int)$existing[$byWikidata[$wikidataId]]['place_id'];
        }
        foreach (self::nearby($grid, $element['lat'], $element['lng']) as $placeIndex) {
            $place = $existing[$placeIndex];
            $km = self::haversineKm($element['lat'], $element['lng'], (float)$place['latitude'], (float)$place['longitude']);
            if ($km > 1.0) continue;
            $similarity = 0.0;
            foreach ($element['names'] as $name) {
                $similarity = max($similarity, $matcher->similarity((string)$place['name'], $name));
            }
            if (($km <= 0.15 && $similarity >= 0.5) || $similarity >= 0.8) {
                return (int)$place['place_id'];
            }
        }
        return null;
    }

    /** The name to show: English when OpenStreetMap or Wikidata has one, with the local name in brackets. */
    private function displayName(array $element): ?string
    {
        $tags = $element['tags'];
        $local = trim((string)$tags['name']);
        $english = trim((string)($tags['name:en'] ?? ($element['wd']['label'] ?? '')));
        $latin = static fn(string $s): bool => (bool)preg_match('/[A-Za-z]{2}/', $s);

        if ($english !== '' && $latin($english)) {
            $sameWords = PlaceNameMatcher::tokens($english) == PlaceNameMatcher::tokens($local);
            $name = (!$sameWords && $latin($local) && mb_strlen("{$english} ({$local})") <= 150) ? "{$english} ({$local})" : $english;
        } elseif ($latin($local)) {
            $name = $local;
        } else {
            return null;   // no name this English-language site could show
        }
        return mb_substr($name, 0, 150);
    }

    /** English Wikipedia lead text for an element, or null. */
    private function wikipediaFor(array $element): ?array
    {
        $title = self::wikipediaTitle($element);
        return $title !== null ? ($this->summaries[$title] ?? null) : null;
    }

    /** A freely licensed photo for an element, or null. */
    private function imageFor(array $element): ?array
    {
        $file = self::commonsFile($element);
        $image = $file !== null ? ($this->images[$file] ?? null) : null;
        return ($image !== null && $image['download_url'] !== '') ? $image : null;
    }

    /** Title of the English Wikipedia article about an element: from Wikidata, else from its OSM tag. */
    private static function wikipediaTitle(array $element): ?string
    {
        $title = $element['wd']['enwiki'] ?? null;
        if ($title === null) {
            $parsed = WikimediaService::parseWikipediaTag((string)($element['tags']['wikipedia'] ?? ''));
            if ($parsed !== null && $parsed[0] === 'en') $title = $parsed[1];
        }
        return $title;
    }

    /** Name of the Commons photo of an element: from Wikidata, else from its OSM tags. */
    private static function commonsFile(array $element): ?string
    {
        $file = $element['wd']['image'] ?? null;
        if ($file !== null) return $file;
        $tags = $element['tags'];
        if (preg_match('/^File:(.+)$/i', (string)($tags['wikimedia_commons'] ?? ''), $m)) return trim($m[1]);
        if (preg_match('#^https?://commons\.wikimedia\.org/wiki/File:([^?\#]+)#i', (string)($tags['image'] ?? ''), $m)) {
            return str_replace('_', ' ', rawurldecode($m[1]));
        }
        return null;
    }

    /** Everything needed to publish the place later, including the credits for its text and photo. */
    private function buildPayload(string $state, array $element, array $class, string $name, ?array $wikipedia, ?array $image): array
    {
        $tags = $element['tags'];
        $wd = $element['wd'] ?? null;
        [$phrase, $minutes, $outdoor, $defaultFee, $bestTime, $dressCode] = self::KIND_PROFILE[$class['kind']];
        if ($class['kind'] === 'worship') {
            $phrase = match ($tags['religion'] ?? '') {
                'muslim' => 'a mosque',
                'buddhist' => 'a Buddhist temple',
                'taoist', 'chinese_folk' => 'a Chinese temple',
                'hindu' => 'a Hindu temple',
                'christian' => 'a church',
                'sikh' => 'a gurdwara',
                default => $phrase,
            };
        }

        if ($wikipedia !== null) {
            $description = self::trimToSentences($wikipedia['extract'], 700);
            $descriptionSource = 'wikipedia';
        } else {
            $description = "{$name} is {$phrase} in {$state}, Malaysia.";
            $wikidataText = trim((string)($wd['description'] ?? ''));
            if ($wikidataText !== '' && !preg_match('/^(wikimedia|wikipedia)/i', $wikidataText)) {
                $description .= ' Wikidata describes it as "' . $wikidataText . '".';
            }
            $descriptionSource = 'generated';
        }

        // Entrance fee: an explicit amount, "no fee", or a typical figure for this kind of place.
        $fee = $defaultFee;
        if (($tags['fee'] ?? '') === 'no') {
            $fee = 0.0;
        } elseif (preg_match('/(?:RM|MYR)\s*(\d+(?:\.\d+)?)|(\d+(?:\.\d+)?)\s*(?:RM|MYR)/i', (string)($tags['charge'] ?? ''), $m)) {
            $fee = (float)($m[1] !== '' ? $m[1] : $m[2]);
        } elseif (($tags['fee'] ?? '') === 'yes' && $fee <= 0) {
            $fee = 5.0;
        }


        $phone = self::firstValue((string)($tags['phone'] ?? $tags['contact:phone'] ?? ''));
        $website = self::cleanUrl((string)($tags['website'] ?? $tags['contact:website'] ?? ($wd['website'] ?? '')));

        return [
            'kind' => $class['kind'],
            'local_name' => (string)$tags['name'],
            'description' => $description,
            'description_source' => $descriptionSource,
            'wikipedia_url' => $wikipedia['url'] ?? null,
            // A street without a town is not enough for a place page: publishing then looks the address up.
            'address' => ($osmAddress = self::addressFromTags($tags, true)) !== null ? mb_substr($osmAddress . ', ' . $state, 0, 255) : null,
            'opening_hours' => OpeningHoursConverter::fromOsm((string)($tags['opening_hours'] ?? '')),
            'phone' => ($phone !== '' && mb_strlen($phone) <= 20) ? $phone : null,
            'website' => ($website !== '' && mb_strlen($website) <= 300) ? $website : null,
            'entrance_fee' => round($fee, 2),
            'visit_duration_min' => $minutes,
            'is_outdoor' => $outdoor,
            'best_time' => $bestTime,
            'dress_code' => $dressCode,
            'wheelchair' => in_array($tags['wheelchair'] ?? '', ['yes', 'limited', 'no'], true) ? $tags['wheelchair'] : null,
            'image' => $image,
            'osm_url' => 'https://www.openstreetmap.org/' . $element['ref'],
        ];
    }

    private function saveCandidate(string $state, array $candidate): ?int
    {
        $status = $candidate['duplicate_of'] !== null ? 'duplicate' : 'pending';
        $payload = json_encode($candidate['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        // An admin's decision (published / rejected) is never changed by a later sync.
        $stmt = $this->conn->prepare("
            INSERT INTO place_import_candidates
                (osm_ref, wikidata_id, name, state, district, category, latitude, longitude, score, payload_json, status, duplicate_of_place_id, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                wikidata_id = VALUES(wikidata_id), name = VALUES(name), district = VALUES(district), category = VALUES(category),
                latitude = VALUES(latitude), longitude = VALUES(longitude), score = VALUES(score), payload_json = VALUES(payload_json),
                duplicate_of_place_id = IF(status IN ('pending', 'duplicate'), VALUES(duplicate_of_place_id), duplicate_of_place_id),
                status = IF(status IN ('pending', 'duplicate'), VALUES(status), status),
                last_seen_at = NOW()
        ");
        $stmt->bind_param(
            'ssssssddissi',
            $candidate['osm_ref'], $candidate['wikidata_id'], $candidate['name'], $state, $candidate['district'], $candidate['category'],
            $candidate['lat'], $candidate['lng'], $candidate['score'], $payload, $status, $candidate['duplicate_of']
        );
        $stmt->execute();
        $stmt->close();

        $find = $this->conn->prepare("SELECT candidate_id FROM place_import_candidates WHERE osm_ref = ? AND status = 'pending'");
        $find->bind_param('s', $candidate['osm_ref']);
        $find->execute();
        $row = $find->get_result()->fetch_row();
        $find->close();
        return $row ? (int)$row[0] : null;
    }

    /**
     * Turn a staged candidate into a place. Used for automatic publishing and by the admin page.
     *
     * @param array $overrides name, category, district as corrected by an admin
     * @return int|null the new place_id, or null when the candidate cannot be published
     */
    public function publishCandidate(int $candidateId, ?int $adminId, array $overrides = []): ?int
    {
        $stmt = $this->conn->prepare("SELECT * FROM place_import_candidates WHERE candidate_id = ? AND status IN ('pending', 'duplicate')");
        $stmt->bind_param('i', $candidateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $p = $row ? json_decode((string)$row['payload_json'], true) : null;
        if (!$row || !is_array($p)) return null;

        $name = mb_substr(trim((string)($overrides['name'] ?? $row['name'])), 0, 150);
        $category = (string)($overrides['category'] ?? $row['category']);
        if (!in_array($category, self::CATEGORIES, true)) $category = (string)$row['category'];
        $lat = (float)$row['latitude'];
        $lng = (float)$row['longitude'];
        $where = GeocodingService::reverse($lat, $lng);
        // A district typed by an admin is final. Otherwise the staged guess is checked against the map.
        $district = trim((string)($overrides['district'] ?? ''));
        if ($district === '') {
            $district = (string)$this->resolveDistrict((string)$row['state'], $where, $row['district'] !== null ? (string)$row['district'] : null);
        }
        $district = $district !== '' ? mb_substr($district, 0, 80) : null;
        $address = $p['address'] ?? ($where['address'] ?? null) ?? trim(($district ? $district . ', ' : '') . $row['state']);
        $address = mb_substr((string)$address, 0, 255);
        $fee = (float)($p['entrance_fee'] ?? 0);
        $isFree = $fee <= 0 ? 1 : 0;
        $imageUrl = $this->downloadImage($p['image'] ?? null, (string)$row['osm_ref']);

        $this->conn->begin_transaction();
        try {
            $insert = $this->conn->prepare("
                INSERT INTO cultural_places
                    (state, district, category, name, description, address, latitude, longitude, opening_hours,
                     estimated_cost, entrance_fee, is_free, image_url, is_outdoor, visit_duration_min, best_time_to_visit,
                     dress_code_required, website_url, phone_number, is_active, created_by_admin_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $insert->bind_param(
                'ssssssddsddisiisissi',
                $row['state'], $district, $category, $name, $p['description'], $address, $lat, $lng, $p['opening_hours'],
                $fee, $fee, $isFree, $imageUrl, $p['is_outdoor'], $p['visit_duration_min'], $p['best_time'],
                $p['dress_code'], $p['website'], $p['phone'], $adminId
            );
            $insert->execute();
            $placeId = (int)$insert->insert_id;
            $insert->close();

            $image = $imageUrl !== null ? $p['image'] : null;
            $source = $this->conn->prepare("
                INSERT INTO place_sources
                    (place_id, origin, osm_ref, wikidata_id, wikipedia_url, description_source, image_source_url, image_credit, image_license, wheelchair, synced_at)
                VALUES (?, 'import', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            [$imagePage, $imageCredit, $imageLicense] = self::imageCredits($image);
            $source->bind_param(
                'issssssss',
                $placeId, $row['osm_ref'], $row['wikidata_id'], $p['wikipedia_url'], $p['description_source'],
                $imagePage, $imageCredit, $imageLicense, $p['wheelchair']
            );
            $source->execute();
            $source->close();

            $done = $this->conn->prepare("
                UPDATE place_import_candidates
                SET status = 'published', published_place_id = ?, reviewed_by_admin_id = ?, reviewed_at = NOW()
                WHERE candidate_id = ?
            ");
            $done->bind_param('iii', $placeId, $adminId, $candidateId);
            $done->execute();
            $done->close();

            $this->conn->commit();
            return $placeId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            if ($imageUrl !== null) @unlink(dirname(__DIR__) . '/' . $imageUrl);
            error_log('PlaceSyncService: could not publish candidate ' . $candidateId . ': ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------ decisions made on the admin page

    /** An admin decided this candidate should not become a place. Later syncs leave it alone. */
    public function rejectCandidate(int $candidateId, ?int $adminId): bool
    {
        return $this->decide($candidateId, $adminId, 'rejected', "status IN ('pending', 'duplicate')");
    }

    /** Put a rejected candidate back in the review list. */
    public function restoreCandidate(int $candidateId): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE place_import_candidates
            SET status = IF(duplicate_of_place_id IS NULL, 'pending', 'duplicate'), reviewed_by_admin_id = NULL, reviewed_at = NULL
            WHERE candidate_id = ? AND status = 'rejected'
        ");
        $stmt->bind_param('i', $candidateId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        return $changed;
    }

    /**
     * An admin confirmed that a "possible duplicate" is the place the database already has.
     * That place is linked to the OpenStreetMap entry (unless it is linked to one already),
     * so the next sync can fill in its missing details.
     */
    public function linkCandidateToPlace(int $candidateId, ?int $adminId): bool
    {
        $stmt = $this->conn->prepare("
            SELECT c.osm_ref, c.wikidata_id, c.duplicate_of_place_id
            FROM place_import_candidates c
            JOIN cultural_places cp ON cp.place_id = c.duplicate_of_place_id
            WHERE c.candidate_id = ? AND c.status = 'duplicate'
        ");
        $stmt->bind_param('i', $candidateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return false;

        $placeId = (int)$row['duplicate_of_place_id'];
        $link = $this->conn->prepare("
            INSERT INTO place_sources (place_id, origin, osm_ref, wikidata_id) VALUES (?, 'manual', ?, ?)
            ON DUPLICATE KEY UPDATE wikidata_id = IF(osm_ref IS NULL, VALUES(wikidata_id), wikidata_id), osm_ref = COALESCE(osm_ref, VALUES(osm_ref))
        ");
        $link->bind_param('iss', $placeId, $row['osm_ref'], $row['wikidata_id']);
        $link->execute();
        $link->close();
        return $this->decide($candidateId, $adminId, 'linked', "status = 'duplicate'");
    }

    /**
     * Undo one automatic change to a place. The value comes back only when the place still
     * holds what the sync wrote (an admin may have edited it since), and the sync will not
     * fill that field of that place again.
     */
    public function revertChange(int $changeId): bool
    {
        $stmt = $this->conn->prepare("SELECT place_id, field, old_value, new_value FROM place_change_log WHERE change_id = ? AND reverted_at IS NULL");
        $stmt->bind_param('i', $changeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !in_array($row['field'], self::SYNCED_FIELDS, true)) return false;

        $field = $row['field'];   // one of SYNCED_FIELDS, checked above
        $placeId = (int)$row['place_id'];
        $undo = $this->conn->prepare("UPDATE cultural_places SET `{$field}` = ? WHERE place_id = ? AND `{$field}` <=> ?");
        $undo->bind_param('sis', $row['old_value'], $placeId, $row['new_value']);
        $undo->execute();
        $restored = $undo->affected_rows > 0;
        $undo->close();

        $mark = $this->conn->prepare("UPDATE place_change_log SET reverted_at = NOW() WHERE change_id = ?");
        $mark->bind_param('i', $changeId);
        $mark->execute();
        $mark->close();

        // A photo this sync downloaded is no longer used by anything.
        if ($restored && $field === 'image_url' && preg_match('#^uploads/places/osm_[a-z0-9_]+\.(jpg|png|webp)$#', (string)$row['new_value'])) {
            @unlink(dirname(__DIR__) . '/' . $row['new_value']);
        }
        return true;
    }

    private function decide(int $candidateId, ?int $adminId, string $status, string $onlyWhen): bool
    {
        // $status and $onlyWhen are fixed strings from this class, never user input.
        $stmt = $this->conn->prepare("
            UPDATE place_import_candidates SET status = ?, reviewed_by_admin_id = ?, reviewed_at = NOW()
            WHERE candidate_id = ? AND {$onlyWhen}
        ");
        $stmt->bind_param('sii', $status, $adminId, $candidateId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        return $changed;
    }

    /**
     * The district of a place, in the names this app already uses for the state. The map knows
     * the official district, but the app names some areas its own way (Kuala Lumpur is split
     * into neighbourhoods), so one of its own names wins when the map mentions it.
     *
     * @param array|null  $where what GeocodingService::reverse() found at the place
     * @param string|null $guess the district of the nearest existing places
     */
    private function resolveDistrict(string $state, ?array $where, ?string $guess): ?string
    {
        if (!isset($this->districtNames[$state])) {
            $stmt = $this->conn->prepare("SELECT DISTINCT district FROM cultural_places WHERE state = ? AND district IS NOT NULL AND district <> ''");
            $stmt->bind_param('s', $state);
            $stmt->execute();
            $this->districtNames[$state] = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$name]) {
                $this->districtNames[$state][mb_strtolower(trim($name))] = trim($name);
            }
            $stmt->close();
        }
        foreach ($where['areas'] ?? [] as $area) {
            $own = $this->districtNames[$state][mb_strtolower($area)] ?? null;
            if ($own !== null) return $own;
        }
        return ($where['district'] ?? null) ?? $guess;
    }

    /** Save a Commons photo into uploads/places and return its web path, or null. */
    private function downloadImage(?array $image, string $osmRef): ?string
    {
        $url = (string)($image['download_url'] ?? '');
        // Wikimedia serves originals from "upload" and resized copies from "thumb".
        if ($url === '' || !in_array(parse_url($url, PHP_URL_HOST), ['upload.wikimedia.org', 'thumb.wikimedia.org'], true)) return null;

        $directory = dirname(__DIR__) . '/uploads/places';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) return null;
        $base = 'osm_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($osmRef));
        $temporary = "{$directory}/{$base}.download";

        $mime = OpenDataHttp::downloadFile($url, $temporary);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime ?? ''] ?? null;
        if ($extension === null || @getimagesize($temporary) === false) {
            @unlink($temporary);
            return null;
        }
        $final = "{$directory}/{$base}.{$extension}";
        @unlink($final);
        if (!@rename($temporary, $final)) {
            @unlink($temporary);
            return null;
        }
        return "uploads/places/{$base}.{$extension}";
    }

    // ------------------------------------------------------------------ step 4: lodging

    /** Refresh hotels and other lodging of a state. Returns how many are listed. */
    public function syncLodging(string $state): int
    {
        ($this->log)("[{$state}] reading lodging from OpenStreetMap...");
        $elements = OsmDataService::lodging($state);
        if ($elements === null) {
            ($this->log)("  lodging could not be read (" . OsmDataService::lastError() . "); keeping the current list");
            return 0;
        }
        if ($this->dryRun || !$elements) return count($elements);

        $started = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("
            INSERT INTO lodgings (osm_ref, name, kind, stars, state, address, latitude, longitude, phone, website, is_active, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind), stars = VALUES(stars), state = VALUES(state),
                address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude),
                phone = VALUES(phone), website = VALUES(website), is_active = 1, last_seen_at = NOW()
        ");
        $this->conn->begin_transaction();
        try {
            $this->upsertLodgings($stmt, $elements, $state);
            $stmt->close();

            // Lodging that OpenStreetMap no longer lists has closed or been removed.
            $retire = $this->conn->prepare("UPDATE lodgings SET is_active = 0 WHERE state = ? AND last_seen_at < ?");
            $retire->bind_param('ss', $state, $started);
            $retire->execute();
            $retire->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
        return count($elements);
    }

    private function upsertLodgings(mysqli_stmt $stmt, array $elements, string $state): void
    {
        foreach ($elements as $element) {
            $tags = $element['tags'];
            $name = mb_substr(trim((string)($tags['name:en'] ?? $tags['name'])), 0, 200);
            $kind = (string)$tags['tourism'];
            $stars = (is_numeric($tags['stars'] ?? null) && $tags['stars'] >= 1 && $tags['stars'] <= 5) ? (float)$tags['stars'] : null;

            $address = self::addressFromTags($tags, false);
            $phone = self::firstValue((string)($tags['phone'] ?? $tags['contact:phone'] ?? ''));
            $phone = ($phone !== '' && mb_strlen($phone) <= 60) ? $phone : null;
            $website = self::cleanUrl((string)($tags['website'] ?? $tags['contact:website'] ?? ''));
            $website = ($website !== '' && mb_strlen($website) <= 300) ? $website : null;
            $stmt->bind_param('sssdssddss', $element['ref'], $name, $kind, $stars, $state, $address, $element['lat'], $element['lng'], $phone, $website);
            $stmt->execute();
        }
    }

    // ------------------------------------------------------------------ run log

    private function startRun(string $state): ?int
    {
        if ($this->dryRun) return null;
        $stmt = $this->conn->prepare("INSERT INTO place_sync_runs (state, status, started_at) VALUES (?, 'running', NOW())");
        $stmt->bind_param('s', $state);
        $stmt->execute();
        $runId = (int)$stmt->insert_id;
        $stmt->close();
        return $runId;
    }

    private function finishRun(?int $runId, string $status, array $stats, ?string $message): void
    {
        if ($runId === null) return;
        $message = $message !== null ? mb_substr($message, 0, 500) : null;
        $stmt = $this->conn->prepare("
            UPDATE place_sync_runs
            SET status = ?, finished_at = NOW(), osm_places = ?, linked = ?, fields_filled = ?, staged = ?, published = ?, lodgings = ?, message = ?
            WHERE run_id = ?
        ");
        $stmt->bind_param('siiiiiisi', $status, $stats['osm_places'], $stats['linked'], $stats['fields_filled'], $stats['staged'], $stats['published'], $stats['lodgings'], $message, $runId);
        $stmt->execute();
        $stmt->close();
    }

    // ------------------------------------------------------------------ small helpers

    /** The district most of the nearest existing places are in, so new places use this app's own district names. */
    private static function nearestDistrict(array $existing, float $lat, float $lng): ?string
    {
        $near = [];
        foreach ($existing as $place) {
            $district = trim((string)($place['district'] ?? ''));
            if ($district === '' || $place['latitude'] === null) continue;
            $km = self::haversineKm($lat, $lng, (float)$place['latitude'], (float)$place['longitude']);
            if ($km <= 30.0) $near[] = [$km, $district];
        }
        if (!$near) return null;
        usort($near, fn($a, $b) => $a[0] <=> $b[0]);
        $votes = [];
        foreach (array_slice($near, 0, 5) as [$km, $district]) {
            $votes[$district] = ($votes[$district] ?? 0) + 1 / ($km + 0.5);
        }
        arsort($votes);
        return (string)array_key_first($votes);
    }

    /** Group points into ~2 km cells so nearby points are found without comparing everything. */
    private static function buildGrid(array $rows, string $latKey, string $lngKey): array
    {
        $grid = [];
        foreach ($rows as $index => $row) {
            if ($row[$latKey] === null || $row[$lngKey] === null) continue;
            $grid[self::cell((float)$row[$latKey], (float)$row[$lngKey])][] = $index;
        }
        return $grid;
    }

    private static function cell(float $lat, float $lng): string
    {
        return floor($lat / 0.02) . ':' . floor($lng / 0.02);
    }

    /** Indexes in the cell of a point and the eight cells around it (covers at least 2 km). */
    private static function nearby(array $grid, float $lat, float $lng): array
    {
        $row = (int)floor($lat / 0.02);
        $column = (int)floor($lng / 0.02);
        $found = [];
        for ($r = $row - 1; $r <= $row + 1; $r++) {
            for ($c = $column - 1; $c <= $column + 1; $c++) {
                foreach ($grid["{$r}:{$c}"] ?? [] as $index) $found[] = $index;
            }
        }
        return $found;
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /**
     * A postal-style address from the addr:* tags of an element, or null when they do not say
     * where it is. A house number without a street says nothing, and is left out.
     *
     * @param bool $needTown true: only an address with both a street and a town or postcode counts
     */
    private static function addressFromTags(array $tags, bool $needTown): ?string
    {
        if (!empty($tags['addr:full'])) return mb_substr(trim((string)$tags['addr:full']), 0, 255);
        $street = trim((string)($tags['addr:street'] ?? ''));
        $number = str_replace(';', '/', trim((string)($tags['addr:housenumber'] ?? '')));
        $streetLine = $street !== '' ? trim($number . ' ' . $street) : '';
        $townLine = trim(($tags['addr:postcode'] ?? '') . ' ' . ($tags['addr:city'] ?? ''));
        if ($needTown && ($streetLine === '' || $townLine === '')) return null;
        $parts = array_filter([$streetLine, $townLine], static fn($part) => $part !== '');
        return $parts ? mb_substr(implode(', ', $parts), 0, 255) : null;
    }

    /** OSM lists several phones or sites as "a;b": take the first. */
    private static function firstValue(string $value): string
    {
        return trim(explode(';', $value)[0]);
    }

    private static function cleanUrl(string $url): string
    {
        $url = self::firstValue($url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * Wikipedia opens most articles with the name in other scripts and its pronunciation,
     * "(Chinese: ...; Jawi: ...)". Travellers do not need that in a description.
     */
    private static function cleanExtract(string $text): string
    {
        $text = (string)preg_replace_callback('/\s*\(((?:[^()]|\([^()]*\))*)\)/u', static function (array $m): string {
            $inside = $m[1];
            $isNameNote = preg_match('/\b(Chinese|Malay|Jawi|Tamil|Arabic|Portuguese|Dutch|Japanese|Thai|pinyin|pronounced|pronunciation|IPA|abbreviated|abbreviation|lit\.)/iu', $inside)
                || preg_match('/[^\x{0000}-\x{024F}\x{2010}-\x{203A}]/u', $inside);
            return $isNameNote ? '' : $m[0];
        }, $text);
        $text = (string)preg_replace('/\s+([,.;:])/u', '$1', $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /** Cut text at a sentence end at or before $limit characters. */
    private static function trimToSentences(string $text, int $limit): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) return $text;
        $cut = mb_substr($text, 0, $limit);
        $end = max((int)mb_strrpos($cut, '. '), (int)mb_strrpos($cut, '.) '));
        return $end > 120 ? mb_substr($cut, 0, $end + 1) : rtrim($cut) . '...';
    }
}
