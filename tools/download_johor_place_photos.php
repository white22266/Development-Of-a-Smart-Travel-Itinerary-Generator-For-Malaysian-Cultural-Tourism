<?php
/**
 * Download Google Places photos for active cultural_places.
 *
 * Saves real place photos into uploads/places/ and updates:
 * - cultural_places.image_url
 *
 * Run:
 *   php tools/download_johor_place_photos.php
 *   php tools/download_johor_place_photos.php --state=Kedah
 *   php tools/download_johor_place_photos.php --all
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_keys.php';

$cliOptions = parse_cli_options($argv ?? []);
$state = trim((string)($_GET['state'] ?? ($cliOptions['state'] ?? 'Johor')));
$allStates = isset($cliOptions['all']) || (($_GET['all'] ?? '') === '1');
$uploadRelDir = 'uploads/places';
$uploadAbsDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadRelDir);
$limit = (int)($_GET['limit'] ?? ($cliOptions['limit'] ?? 0));
$force = in_array('--force', $argv ?? [], true) || (($_GET['force'] ?? '') === '1');

function parse_cli_options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $out[$key] = trim($value, "\"'");
            } else {
                $out[$arg] = true;
            }
        } elseif (is_numeric($arg)) {
            $out['limit'] = (int)$arg;
        }
    }
    return $out;
}

function out_line(string $message): void
{
    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '<br>';
        @ob_flush();
        @flush();
    }
}

function http_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: SmartTravelItineraryGenerator/1.0'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function http_image(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: SmartTravelItineraryGenerator/1.0'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300 || $body === '') return null;
    if (stripos($contentType, 'image/') !== 0) return null;
    return ['body' => $body, 'mime' => $contentType];
}

function ext_from_mime(string $mime): ?string
{
    return match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        default => null,
    };
}

function google_photo_reference(array $place): string
{
    $query = implode(', ', array_filter([
        $place['name'] ?? '',
        $place['district'] ?? '',
        $place['state'] ?? '',
        'Malaysia',
    ]));

    $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
        'input' => $query,
        'inputtype' => 'textquery',
        'fields' => 'name,place_id,photos',
        'key' => GOOGLE_MAPS_API_KEY,
    ]);
    $json = http_json($url);
    $photoRef = (string)($json['candidates'][0]['photos'][0]['photo_reference'] ?? '');
    if ($photoRef !== '') return $photoRef;

    $fallbackQueries = [
        implode(', ', array_filter([$place['name'] ?? '', $place['state'] ?? '', 'Malaysia'])),
        implode(', ', array_filter([$place['district'] ?? '', $place['state'] ?? '', 'Malaysia'])),
    ];

    foreach ($fallbackQueries as $fallbackQuery) {
        if (trim($fallbackQuery) === '') continue;
        $textUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
            'query' => $fallbackQuery,
            'key' => GOOGLE_MAPS_API_KEY,
        ]);
        $textJson = http_json($textUrl);
        foreach (($textJson['results'] ?? []) as $result) {
            $candidateRef = (string)($result['photos'][0]['photo_reference'] ?? '');
            if ($candidateRef !== '') return $candidateRef;
        }
    }

    return '';
}

if (!defined('GOOGLE_MAPS_API_KEY') || trim((string)GOOGLE_MAPS_API_KEY) === '') {
    out_line('ERROR: GOOGLE_MAPS_API_KEY is not configured.');
    exit(1);
}
if (!function_exists('curl_init')) {
    out_line('ERROR: PHP cURL extension is not enabled.');
    exit(1);
}
if (!is_dir($uploadAbsDir) && !mkdir($uploadAbsDir, 0755, true)) {
    out_line('ERROR: Cannot create upload folder: ' . $uploadAbsDir);
    exit(1);
}

$whereImage = $force
    ? '1=1'
    : "(image_url IS NULL OR TRIM(image_url) = '' OR image_url NOT LIKE 'uploads/places/%')";

$sql = "
    SELECT place_id, name, state, district, address, image_url
    FROM cultural_places
    WHERE is_active = 1
      AND $whereImage
";
if (!$allStates) {
    $sql .= " AND state = ?";
}
$sql .= "
    ORDER BY state, district, name, place_id
";
if ($limit > 0) $sql .= " LIMIT " . $limit;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    out_line('ERROR: Select prepare failed: ' . $conn->error);
    exit(1);
}
if (!$allStates) {
    $stmt->bind_param('s', $state);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$updateSql = "UPDATE cultural_places SET image_url = ? WHERE place_id = ?";
$update = $conn->prepare($updateSql);
if (!$update) {
    out_line('ERROR: Update prepare failed: ' . $conn->error);
    exit(1);
}

$scopeLabel = $allStates ? 'all states' : $state;
out_line('Found places needing local photos (' . $scopeLabel . '): ' . count($rows));

$ok = 0;
$fail = 0;
$skip = 0;

foreach ($rows as $place) {
    $placeId = (int)$place['place_id'];
    $name = (string)$place['name'];
    out_line("Processing #{$placeId}: {$name}");

    $photoRef = google_photo_reference($place);
    if ($photoRef === '') {
        out_line('  [SKIP] No Google photo found.');
        $skip++;
        continue;
    }

    $photoUrl = 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
        'maxwidth' => 1200,
        'photo_reference' => $photoRef,
        'key' => GOOGLE_MAPS_API_KEY,
    ]);
    $image = http_image($photoUrl);
    if (!$image) {
        out_line('  [FAIL] Could not download image.');
        $fail++;
        continue;
    }

    $ext = ext_from_mime($image['mime']);
    if ($ext === null) {
        out_line('  [FAIL] Unsupported image mime: ' . $image['mime']);
        $fail++;
        continue;
    }

    $fileName = 'place_' . $placeId . '_google_' . substr(sha1($photoRef), 0, 10) . '.' . $ext;
    $absPath = $uploadAbsDir . DIRECTORY_SEPARATOR . $fileName;
    $relPath = $uploadRelDir . '/' . $fileName;

    if (file_put_contents($absPath, $image['body']) === false) {
        out_line('  [FAIL] Could not write file: ' . $absPath);
        $fail++;
        continue;
    }

    $update->bind_param('si', $relPath, $placeId);

    if (!$update->execute()) {
        @unlink($absPath);
        out_line('  [FAIL] DB update failed: ' . $update->error);
        $fail++;
        continue;
    }

    out_line('  [OK] ' . $relPath);
    $ok++;
    usleep(150000);
}

$update->close();
out_line("Done. OK={$ok}, SKIP={$skip}, FAIL={$fail}");
