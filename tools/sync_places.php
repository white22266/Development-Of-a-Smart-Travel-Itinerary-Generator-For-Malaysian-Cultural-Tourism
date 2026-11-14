<?php
// tools/sync_places.php
// Command-line job that keeps places and lodging up to date from open data
// (OpenStreetMap, Wikidata, Wikipedia, Wikimedia Commons). See services/PlaceSyncService.php.
//
//   php tools/sync_places.php --state="Melaka"             one state
//   php tools/sync_places.php --all                        every state, one after another
//   php tools/sync_places.php --state="Melaka" --dry-run   show what would change, save nothing
//   php tools/sync_places.php --all --no-publish           stage new places for review, publish none
//
// Safe to run again: nothing is duplicated. Schedule it weekly with
// tools/sync_places_weekly.bat, which runs --all --no-publish and keeps a log; README.md shows how.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This is not a web request: no CSRF check, and no output buffering so progress shows live.
define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../services/PlaceSyncService.php';

$options = getopt('', ['state:', 'all', 'dry-run', 'no-publish', 'help']);
$states = array_keys(OsmDataService::STATE_ISO);

if (isset($options['help']) || (!isset($options['state']) && !isset($options['all']))) {
    echo "Usage: php tools/sync_places.php --state=\"<state>\" | --all  [--dry-run] [--no-publish]\n";
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

set_time_limit(0);
OpenDataHttp::setPatient(true);   // a background job waits when a service says "slow down"
$serviceOptions = [
    'dry_run' => isset($options['dry-run']),
    'log' => static function (string $line): void {
        echo '[' . date('H:i:s') . '] ' . $line . PHP_EOL;
    },
];
if (isset($options['no-publish'])) {
    $serviceOptions['auto_publish_min'] = 101;
}
$sync = new PlaceSyncService($conn, $serviceOptions);

$failed = [];
foreach ($states as $index => $state) {
    if ($index > 0) sleep(5);   // be gentle with the public OpenStreetMap servers
    try {
        $sync->syncState($state);
    } catch (Throwable $e) {
        $failed[] = $state;
        fwrite(STDERR, "[{$state}] FAILED: " . $e->getMessage() . PHP_EOL);
    }
}

if ($failed) {
    fwrite(STDERR, 'Not synced: ' . implode(', ', $failed) . ". Run the job again later for these states.\n");
    exit(2);
}
exit(0);
