<?php
// tools/classify_places.php
// Command-line job that gives every place a finer type (mosque, hindu_temple, beach, ...) with
// services/PlaceTypeClassifier.php. It changes nothing but cultural_places.place_type and
// place_type_source. A type an admin chose (place_type_source = 'admin') is never touched.
//
//   php tools/classify_places.php                   classify every place
//   php tools/classify_places.php --missing         only places that have no type yet
//   php tools/classify_places.php --dry-run         change nothing, print what would change
//   php tools/classify_places.php --confirm=<name>  needed for a database whose name does not end in "_ctest"
//
// It prints the database name first, the count per type and per group, and a clean-up list: places
// whose type contradicts the category they are filed under (a mosque or a beach in "food").
// Needs the columns from database/migrations/20261119_place_types.sql. Safe to run again.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../services/PlaceTypeClassifier.php';

$options = getopt('', ['dry-run', 'missing', 'confirm:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/classify_places.php [--dry-run] [--missing] [--confirm=<database>]\n";
    exit(0);
}
$dryRun = isset($options['dry-run']);
$onlyMissing = isset($options['missing']);

$dbName = (string)($conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '');
echo "Database: {$dbName}\n";
if (substr($dbName, -6) !== '_ctest' && ($options['confirm'] ?? '') !== $dbName) {
    fwrite(STDERR, "Refusing to run: \"{$dbName}\" does not end in \"_ctest\". "
        . "To change it anyway, add --confirm={$dbName}\n");
    exit(1);
}

$columns = [];
$res = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'place_type%'");
while ($res && ($c = $res->fetch_assoc())) $columns[] = $c['Field'];
if (count($columns) < 2) {
    fwrite(STDERR, "The columns place_type and place_type_source are missing. Run: php tools/migrate.php\n");
    exit(1);
}
echo $dryRun ? "Dry run: nothing will be saved.\n" : "Saving types.\n";

// One row per place. The kind the place sync stored (museum, worship, peak, ...) comes from its staged candidate.
$result = $conn->query("
    SELECT cp.place_id, cp.name, cp.description, cp.category, cp.is_active, cp.place_type, cp.place_type_source,
           JSON_VALUE(pic.payload_json, '$.kind') AS osm_kind
    FROM cultural_places cp
    LEFT JOIN place_sources ps ON ps.place_id = cp.place_id
    LEFT JOIN place_import_candidates pic ON pic.osm_ref = ps.osm_ref
    ORDER BY cp.place_id
");

$update = $conn->prepare("UPDATE cultural_places SET place_type = ?, place_type_source = 'rule' WHERE place_id = ? AND (place_type_source IS NULL OR place_type_source <> 'admin')");
$perType = [];
$perGroup = [];
$cleanUp = [];
$changed = 0;
$kept = 0;
$seen = 0;

while ($row = $result->fetch_assoc()) {
    $seen++;
    $isAdmin = $row['place_type_source'] === 'admin' && PlaceTypeClassifier::isValid($row['place_type']);
    if ($isAdmin) {
        $type = (string)$row['place_type'];
        $how = 'admin';
    } elseif ($onlyMissing && $row['place_type'] !== null && $row['place_type'] !== '') {
        $type = (string)$row['place_type'];
        $how = 'kept';
        $kept++;
    } else {
        [$type, $how] = PlaceTypeClassifier::classify([
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            'osm_kind' => (string)$row['osm_kind'],
        ]);
        if ($type !== $row['place_type'] || $row['place_type_source'] !== 'rule') {
            $changed++;
            if (!$dryRun) {
                $id = (int)$row['place_id'];
                $update->bind_param('si', $type, $id);
                $update->execute();
            }
        }
    }

    $perType[$type] = ($perType[$type] ?? 0) + 1;
    $group = PlaceTypeClassifier::groupOf($type);
    $perGroup[$group] = ($perGroup[$group] ?? 0) + 1;
    if ($how !== 'category' && PlaceTypeClassifier::contradictsCategory($type, (string)$row['category'])) {
        $cleanUp[] = [(int)$row['place_id'], (string)$row['category'], $type, $how, (string)$row['name'], (int)$row['is_active']];
    }
}
$update->close();

echo "\nPlaces read: {$seen}. " . ($dryRun ? 'Would change' : 'Changed') . ": {$changed}."
    . ($onlyMissing ? " Left as they were (already typed): {$kept}." : '') . "\n";

echo "\nCount per group\n";
foreach (PlaceTypeClassifier::GROUPS as $group => $label) {
    printf("  %-16s %5d  %s\n", $group, $perGroup[$group] ?? 0, $label);
}
echo "\nCount per type\n";
foreach (PlaceTypeClassifier::TYPES as $type => [$label, $group]) {
    printf("  %-18s %5d  (%s)\n", $type, $perType[$type] ?? 0, $group);
}

echo "\nClean-up list: type contradicts category (" . count($cleanUp) . ")\n";
echo "  place_id | category -> type | how sure | active | name\n";
foreach ($cleanUp as [$id, $category, $type, $how, $name, $active]) {
    echo "  {$id} | {$category} -> {$type} | {$how} | " . ($active ? 'yes' : 'no') . " | {$name}\n";
}
exit(0);
