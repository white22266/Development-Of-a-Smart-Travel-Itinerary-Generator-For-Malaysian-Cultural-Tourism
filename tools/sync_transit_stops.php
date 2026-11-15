<?php
// tools/sync_transit_stops.php
// Command-line job: loads public transport stops (OpenStreetMap, Overpass) into transit_stops
// and works out the distance from every active place to its nearest stop.
//
//   php tools/sync_transit_stops.php --state="Melaka"             one state
//   php tools/sync_transit_stops.php --all                        every state, one request each
//   php tools/sync_transit_stops.php --all --dry-run              fetch and count, store nothing
//   php tools/sync_transit_stops.php --state="Perlis" --confirm=<database name>
//
// A database whose name does not end in "_ctest" is refused unless --confirm=<its name> is given.
// Safe to run again: stops are matched by their OpenStreetMap id.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../services/TransitStopService.php';

$options = getopt('', ['state:', 'all', 'dry-run', 'confirm:', 'help']);
$states = array_keys(OsmDataService::STATE_ISO);

if (isset($options['help']) || (!isset($options['state']) && !isset($options['all']))) {
    echo "Usage: php tools/sync_transit_stops.php --state=\"<state>\" | --all  [--dry-run] [--confirm=<database name>]\n";
    echo "States: " . implode(', ', $states) . "\n";
    exit(isset($options['help']) ? 0 : 1);
}

if (isset($options['state'])) {
    $wanted = trim((string)$options['state']);
    $match = array_values(array_filter($states, static fn($s) => strcasecmp($s, $wanted) === 0));
    if (!$match) {
        fwrite(STDERR, "Unknown state \"{$wanted}\". States: " . implode(', ', $states) . "\n");
        exit(1);
    }
    $states = $match;
}

$dbName = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
echo "Database: {$dbName}\n";
$dryRun = isset($options['dry-run']);
if (!$dryRun && substr($dbName, -6) !== '_ctest' && ($options['confirm'] ?? null) !== $dbName) {
    fwrite(STDERR, "Refusing to write to \"{$dbName}\". Pass --confirm={$dbName} to allow it.\n");
    exit(1);
}

set_time_limit(0);
OpenDataHttp::setPatient(true);   // a background job waits when a service says "slow down"
$service = new TransitStopService($conn, static function (string $line): void {
    echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
});

$failed = [];
$rows = [];
foreach ($states as $state) {
    try {
        $r = $service->syncState($state, $dryRun);
        echo sprintf(
            "[%s] %s: rail %d, bus %d, ferry %d%s\n",
            date('H:i:s'), $state, $r['stops']['rail'], $r['stops']['bus'], $r['stops']['ferry'],
            $dryRun ? ' (dry run, nothing stored)' : sprintf(' | new %d, changed %d, removed %d, places measured %d', $r['inserted'], $r['updated'], $r['removed'], $r['places'])
        );
        $rows[$state] = $dryRun ? ['stops' => $r['stops'], 'places' => null] : $service->summary($state);
    } catch (Throwable $e) {
        $failed[] = $state;
        fwrite(STDERR, "[{$state}] FAILED: " . $e->getMessage() . PHP_EOL);
    }
}

echo "\n" . str_pad('State', 17) . str_pad('Rail', 6) . str_pad('Bus', 7) . str_pad('Ferry', 6)
    . str_pad('Places', 8) . str_pad('<=400m', 8) . str_pad('<=800m', 8) . str_pad('<=1500m', 9) . "none\n";
foreach ($rows as $state => $row) {
    $p = $row['places'];
    echo str_pad($state, 17) . str_pad((string)$row['stops']['rail'], 6) . str_pad((string)$row['stops']['bus'], 7) . str_pad((string)$row['stops']['ferry'], 6)
        . ($p ? str_pad((string)$p['total'], 8) . str_pad((string)$p['w400'], 8) . str_pad((string)$p['w800'], 8) . str_pad((string)$p['w1500'], 9) . $p['none1500'] : '-')
        . "\n";
}

if ($failed) {
    fwrite(STDERR, 'Not synced: ' . implode(', ', $failed) . ". Run the job again later for these states.\n");
    exit(2);
}
exit(0);
