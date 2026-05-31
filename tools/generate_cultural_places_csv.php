<?php
// tools/generate_cultural_places_csv.php
// Generates CSV rows for cultural_places using Google Places API.
// It does not write to the database. Import the CSV from Admin > State Cultural Knowledge Base.

require_once __DIR__ . "/../config/api_keys.php";
require_once __DIR__ . "/../config/db_connect.php";

if (!defined("GOOGLE_MAPS_API_KEY") || GOOGLE_MAPS_API_KEY === "") {
    fwrite(STDERR, "GOOGLE_MAPS_API_KEY is missing in config/api_keys.php\n");
    exit(1);
}

$stateDistricts = [
    "Johor" => ["Johor Bahru","Kluang","Kota Tinggi","Mersing","Muar","Batu Pahat","Pontian","Segamat","Kulai","Tangkak"],
    "Kedah" => ["Kota Setar","Kubang Pasu","Padang Terap","Sik","Baling","Kulim","Bandar Baharu","Kuala Muda","Yan","Langkawi","Pokok Sena","Pendang"],
    "Kelantan" => ["Kota Bharu","Bachok","Pasir Mas","Tumpat","Pasir Puteh","Machang","Tanah Merah","Kuala Krai","Gua Musang","Jeli"],
    "Melaka" => ["Melaka Tengah","Alor Gajah","Jasin"],
    "Negeri Sembilan" => ["Seremban","Port Dickson","Rembau","Tampin","Jempol","Jelebu","Kuala Pilah"],
    "Pahang" => ["Kuantan","Temerloh","Bentong","Cameron Highlands","Raub","Jerantut","Lipis","Maran","Bera","Rompin","Pekan"],
    "Penang" => ["Timur Laut","Barat Daya","Seberang Perai Utara","Seberang Perai Tengah","Seberang Perai Selatan"],
    "Perak" => ["Ipoh","Kinta","Larut, Matang & Selama","Manjung","Kerian","Hilir Perak","Hulu Perak","Batang Padang","Perak Tengah","Kampar"],
    "Perlis" => ["Kangar","Arau","Padang Besar"],
    "Sabah" => ["Kota Kinabalu","Sandakan","Tawau","Lahad Datu","Keningau","Semporna","Kunak","Papar","Beaufort","Kota Belud","Ranau","Kudat","Kinabatangan","Tuaran","Penampang","Putatan","Sipitang","Tambunan","Nabawan","Tongod","Beluran","Kota Marudu","Pitas","Tenom","Kuala Penyu"],
    "Sarawak" => ["Kuching","Miri","Sibu","Bintulu","Sri Aman","Sarikei","Kapit","Limbang","Mukah","Betong","Serian","Kota Samarahan"],
    "Selangor" => ["Petaling Jaya","Shah Alam","Klang","Subang Jaya","Gombak","Hulu Langat","Hulu Selangor","Kuala Langat","Sabak Bernam"],
    "Terengganu" => ["Kuala Terengganu","Kemaman","Dungun","Besut","Setiu","Hulu Terengganu","Marang"],
    "Kuala Lumpur" => ["City Centre (KLCC)","Chow Kit","Brickfields","Bangsar","Cheras","Kepong","Setapak","Wangsa Maju","Titiwangsa","Bukit Jalil","Segambut"],
    "Putrajaya" => ["Putrajaya"],
    "Labuan" => ["Victoria","Labuan Town"],
];

$opts = getopt("", [
    "state::",
    "district::",
    "per-district::",
    "max-districts::",
    "out::",
    "report::",
    "sleep-ms::",
    "details::",
    "download-images::",
]);

$selectedState = trim((string)($opts["state"] ?? ""));
$selectedDistrict = trim((string)($opts["district"] ?? ""));
$perDistrict = max(1, (int)($opts["per-district"] ?? 10));
$maxDistricts = max(0, (int)($opts["max-districts"] ?? 0));
$sleepMs = max(0, (int)($opts["sleep-ms"] ?? 150));
$withDetails = ((string)($opts["details"] ?? "1")) !== "0";
$downloadImages = ((string)($opts["download-images"] ?? "0")) === "1";

$generatedDir = __DIR__ . "/generated";
$cacheDir = $generatedDir . "/google_places_cache";
if (!is_dir($generatedDir)) mkdir($generatedDir, 0777, true);
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$outPath = (string)($opts["out"] ?? ($generatedDir . "/cultural_places_bulk_google_places.csv"));
$reportPath = (string)($opts["report"] ?? ($generatedDir . "/cultural_places_bulk_google_places_report.csv"));

function normalize_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value ?? "";
}

function clean_text(string $value): string
{
    $value = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{FEFF}]/u', ' ', $value) ?? $value;
    $value = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $value) ?? $value;
    $value = preg_replace('/[\x{2018}\x{2019}]/u', "'", $value) ?? $value;
    $value = preg_replace('/[\x{201C}\x{201D}]/u', '"', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function curl_json(string $url, string $cacheDir, int $sleepMs): ?array
{
    $cacheFile = $cacheDir . "/" . md5($url) . ".json";
    if (is_file($cacheFile)) {
        $raw = file_get_contents($cacheFile);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    if ($sleepMs > 0) usleep($sleepMs * 1000);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) {
        fwrite(STDERR, "Google request failed ($status): $err\n$url\n");
        return null;
    }

    file_put_contents($cacheFile, $raw);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function google_text_search(string $query, string $cacheDir, int $sleepMs): array
{
    $url = "https://maps.googleapis.com/maps/api/place/textsearch/json?" . http_build_query([
        "query" => $query,
        "region" => "my",
        "key" => GOOGLE_MAPS_API_KEY,
    ]);
    $json = curl_json($url, $cacheDir, $sleepMs);
    if (!$json || ($json["status"] ?? "") === "REQUEST_DENIED") {
        fwrite(STDERR, "Text Search error for query: $query\n" . ($json["error_message"] ?? "") . "\n");
        return [];
    }
    return $json["results"] ?? [];
}

function google_place_details(string $placeId, string $cacheDir, int $sleepMs): array
{
    $url = "https://maps.googleapis.com/maps/api/place/details/json?" . http_build_query([
        "place_id" => $placeId,
        "fields" => "place_id,name,formatted_address,geometry,types,rating,user_ratings_total,price_level,opening_hours,website,url,formatted_phone_number,photos,business_status",
        "key" => GOOGLE_MAPS_API_KEY,
    ]);
    $json = curl_json($url, $cacheDir, $sleepMs);
    return is_array($json["result"] ?? null) ? $json["result"] : [];
}

function has_bad_types(array $types): bool
{
    $blocked = [
        "lodging","school","university","hospital","doctor","dentist","pharmacy","bank","atm",
        "gas_station","car_repair","insurance_agency","real_estate_agency","police","courthouse",
        "local_government_office","post_office","storage","moving_company","cemetery","funeral_home",
    ];
    return count(array_intersect($blocked, $types)) > 0;
}

function has_bad_name(string $name): bool
{
    $nameLower = strtolower($name);
    $blocked = [
        "jabatan", "pejabat", "department", "office", "agency", "court", "mahkamah",
        "police", "polis", "hospital", "clinic", "klinik", "school", "sekolah",
        "bank", "atm", "petrol", "gas station", "airport", "terminal bas",
    ];
    foreach ($blocked as $word) {
        if (str_contains($nameLower, $word)) return true;
    }
    return false;
}

function address_matches_state(string $address, string $state): bool
{
    $addressLower = strtolower($address);
    $stateLower = strtolower($state);
    $aliases = [
        "Penang" => ["penang", "pulau pinang"],
        "Kuala Lumpur" => ["kuala lumpur", "wilayah persekutuan", "w.p. kuala lumpur"],
        "Putrajaya" => ["putrajaya", "wilayah persekutuan"],
        "Labuan" => ["labuan", "wilayah persekutuan labuan"],
    ];
    foreach (($aliases[$state] ?? [$stateLower]) as $needle) {
        if ($needle !== "" && str_contains($addressLower, strtolower($needle))) return true;
    }
    return false;
}

function detect_category(array $types, string $queryCategory, string $name): string
{
    $nameLower = strtolower($name);
    if (array_intersect($types, ["restaurant","meal_takeaway","food","cafe","bakery"])) return "food";
    if (array_intersect($types, ["museum","art_gallery"])) return "museum";
    if (array_intersect($types, ["park","campground","zoo","aquarium","natural_feature"])) return "nature";
    if (array_intersect($types, ["shopping_mall","store","supermarket"])) return "shopping";
    if (array_intersect($types, ["mosque","church","hindu_temple","place_of_worship","synagogue"])) return "heritage";
    if (str_contains($nameLower, "night market") || str_contains($nameLower, "pasar")) return "shopping";
    return in_array($queryCategory, ["culture","heritage","museum","food","nature","shopping"], true) ? $queryCategory : "culture";
}

function estimate_cost(string $category, ?int $priceLevel, string $name): float
{
    $nameLower = strtolower($name);
    if ($category === "food") {
        $map = [0 => 10, 1 => 12, 2 => 20, 3 => 35, 4 => 55];
        return (float)($map[$priceLevel ?? 2] ?? 20);
    }
    if ($category === "museum") return (str_contains($nameLower, "gallery") || str_contains($nameLower, "museum")) ? 10.0 : 5.0;
    if ($category === "nature") return (str_contains($nameLower, "national park") || str_contains($nameLower, "waterfall")) ? 10.0 : 0.0;
    if ($category === "shopping") return 0.0;
    if ($category === "heritage") return 0.0;
    return 0.0;
}

function visit_duration(string $category): int
{
    return match ($category) {
        "food" => 60,
        "shopping" => 90,
        "nature" => 120,
        "museum", "heritage", "culture" => 90,
        default => 90,
    };
}

function best_time(string $category): string
{
    return match ($category) {
        "food" => "Lunch or dinner",
        "shopping" => "Afternoon or evening",
        "nature" => "Morning",
        "museum", "heritage", "culture" => "Morning or afternoon",
        default => "Any time",
    };
}

function is_outdoor_place(string $category, array $types, string $name): int
{
    $nameLower = strtolower($name);
    if ($category === "nature") return 1;
    if (array_intersect($types, ["park","campground","natural_feature"])) return 1;
    if (str_contains($nameLower, "street") || str_contains($nameLower, "walk") || str_contains($nameLower, "beach") || str_contains($nameLower, "waterfall")) return 1;
    return 0;
}

function needs_dress_code(array $types, string $name): int
{
    $nameLower = strtolower($name);
    if (array_intersect($types, ["mosque","church","hindu_temple","place_of_worship","synagogue"])) return 1;
    foreach (["temple","masjid","mosque","church","gurdwara"] as $word) {
        if (str_contains($nameLower, $word)) return 1;
    }
    return 0;
}

function halal_status(string $category, string $name): string
{
    if ($category !== "food") return "";
    $nameLower = strtolower($name);
    foreach (["haji","nasi","ayam","warung","mee","mamak","melayu","muslim","halal"] as $word) {
        if (str_contains($nameLower, $word)) return "1";
    }
    return "";
}

function category_context(string $category): string
{
    return match ($category) {
        "food" => "local food culture and meal planning",
        "nature" => "outdoor scenery and eco-tourism",
        "museum" => "history, exhibits, and educational visits",
        "heritage" => "religious, architectural, or historical heritage",
        "shopping" => "markets, souvenirs, and urban traveller facilities",
        default => "local cultural exploration",
    };
}

function build_description(string $name, string $state, string $district, string $category, string $address, ?float $rating, ?int $reviews): string
{
    $context = category_context($category);
    $ratingText = ($rating !== null && $rating > 0)
        ? " Google Places shows a rating context of " . number_format($rating, 1) . "/5" . (($reviews ?? 0) > 0 ? " from about {$reviews} user review(s)." : ".")
        : "";
    $addressText = $address !== "" ? " It is located around {$address}." : "";

    return clean_text("{$name} is a {$category} stop in {$district}, {$state}, useful for {$context}.{$addressText}{$ratingText} It helps diversify district-level itinerary planning so routes are not concentrated only in major city centres. Travellers should verify current opening hours before visiting.");
}

function download_google_photo(array $place, string $state, string $district, string $generatedDir): string
{
    $photoRef = $place["photos"][0]["photo_reference"] ?? "";
    if ($photoRef === "") return "";

    $safeName = preg_replace('/[^a-z0-9]+/i', '_', strtolower(($place["name"] ?? "place") . "_" . $state . "_" . $district));
    $safeName = trim(substr($safeName, 0, 80), "_");
    $uploadRel = "uploads/places/google_bulk_" . $safeName . "_" . substr(md5($photoRef), 0, 10) . ".jpg";
    $uploadAbs = dirname(__DIR__) . "/" . $uploadRel;
    if (is_file($uploadAbs)) return $uploadRel;

    $url = "https://maps.googleapis.com/maps/api/place/photo?" . http_build_query([
        "maxwidth" => 900,
        "photo_reference" => $photoRef,
        "key" => GOOGLE_MAPS_API_KEY,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300 || strlen($body) < 1000) return "";
    if (!is_dir(dirname($uploadAbs))) mkdir(dirname($uploadAbs), 0777, true);
    file_put_contents($uploadAbs, $body);
    return $uploadRel;
}

function existing_place_counts(mysqli $conn): array
{
    $counts = [];
    $names = [];
    $stateNames = [];
    $sql = "SELECT state, COALESCE(district,'') AS district, LOWER(TRIM(name)) AS name_key
            FROM cultural_places
            WHERE is_active = 1";
    $res = $conn->query($sql);
    if (!$res) return [$counts, $names, $stateNames];
    while ($row = $res->fetch_assoc()) {
        $state = trim((string)$row["state"]);
        $district = trim((string)$row["district"]);
        $nameKey = normalize_key((string)$row["name_key"]);
        $key = $state . "|" . $district;
        $names[$key][$nameKey] = true;
        $stateNames[$state][$nameKey] = true;
    }
    foreach ($names as $key => $set) $counts[$key] = count($set);
    return [$counts, $names, $stateNames];
}

[$existingCounts, $existingNames, $existingStateNames] = existing_place_counts($conn);

$headers = [
    "name","state","district","category","description","address","latitude","longitude",
    "estimated_cost","opening_hours","image_url","is_active","visit_duration_min",
    "festival_start_date","festival_end_date","halal_status","is_outdoor",
    "best_time_to_visit","dress_code_required","website_url","phone_number","avg_rating","rating"
];

$out = fopen($outPath, "w");
$report = fopen($reportPath, "w");
if (!$out || !$report) {
    fwrite(STDERR, "Unable to open output files.\n");
    exit(1);
}
fputcsv($out, $headers);
fputcsv($report, ["state","district","existing_count","target_count","needed_before_cushion","generated_rows","status"]);

$processedDistricts = 0;
$totalGenerated = 0;
$generatedStateNames = [];
$generatedPlaceIds = [];

foreach ($stateDistricts as $state => $districts) {
    if ($selectedState !== "" && strcasecmp($selectedState, $state) !== 0) continue;

    foreach ($districts as $district) {
        if ($selectedDistrict !== "" && strcasecmp($selectedDistrict, $district) !== 0) continue;
        if ($maxDistricts > 0 && $processedDistricts >= $maxDistricts) break 2;

        $processedDistricts++;
        $key = $state . "|" . $district;
        $existingCount = (int)($existingCounts[$key] ?? 0);
        $needed = max(0, $perDistrict - $existingCount);
        $targetRows = $needed > 0 ? $needed + 5 : 0;

        if ($targetRows <= 0) {
            fputcsv($report, [$state, $district, $existingCount, $perDistrict, 0, 0, "already_complete"]);
            echo "SKIP $state / $district already has $existingCount\n";
            continue;
        }

        echo "Generating $state / $district: existing=$existingCount, target new rows=$targetRows\n";

        $queries = [
            ["culture", "cultural attractions in {$district}, {$state}, Malaysia"],
            ["heritage", "heritage temples mosques churches historical sites in {$district}, {$state}, Malaysia"],
            ["museum", "museum gallery cultural centre in {$district}, {$state}, Malaysia"],
            ["nature", "nature park waterfall beach garden in {$district}, {$state}, Malaysia"],
            ["food", "famous local food restaurants in {$district}, {$state}, Malaysia"],
            ["shopping", "market night market shopping street in {$district}, {$state}, Malaysia"],
            ["culture", "places to visit in {$district}, {$state}, Malaysia"],
        ];

        $selected = [];
        $seen = $existingNames[$key] ?? [];
        $seenStateNames = array_replace($existingStateNames[$state] ?? [], $generatedStateNames[$state] ?? []);

        foreach ($queries as [$queryCategory, $query]) {
            if (count($selected) >= $targetRows) break;
            $results = google_text_search($query, $cacheDir, $sleepMs);
            foreach ($results as $result) {
                if (count($selected) >= $targetRows) break 2;
                if (($result["business_status"] ?? "OPERATIONAL") !== "OPERATIONAL") continue;

                $name = clean_text((string)($result["name"] ?? ""));
                if ($name === "") continue;
                $nameKey = normalize_key($name);
                if (isset($seen[$nameKey])) continue;
                if (isset($seenStateNames[$nameKey])) continue;
                if (has_bad_name($name)) continue;

                $types = $result["types"] ?? [];
                if (!is_array($types) || has_bad_types($types)) continue;

                $category = detect_category($types, $queryCategory, $name);
                if (!in_array($category, ["culture","heritage","museum","food","nature","shopping"], true)) continue;

                $placeId = (string)($result["place_id"] ?? "");
                if ($placeId !== "" && isset($generatedPlaceIds[$placeId])) continue;
                $place = $result;
                if ($withDetails && $placeId !== "") {
                    $details = google_place_details($placeId, $cacheDir, $sleepMs);
                    if ($details) $place = array_replace_recursive($place, $details);
                }

                $lat = $place["geometry"]["location"]["lat"] ?? null;
                $lng = $place["geometry"]["location"]["lng"] ?? null;
                if ($lat === null || $lng === null) continue;

                $address = clean_text((string)($place["formatted_address"] ?? $result["formatted_address"] ?? ""));
                if ($address !== "" && !address_matches_state($address, $state)) continue;
                $rating = isset($place["rating"]) ? (float)$place["rating"] : null;
                if ($rating !== null && $rating > 0 && $rating < 3.0) continue;
                $reviews = isset($place["user_ratings_total"]) ? (int)$place["user_ratings_total"] : null;
                $priceLevel = isset($place["price_level"]) ? (int)$place["price_level"] : null;
                $weekday = $place["opening_hours"]["weekday_text"] ?? [];
                $opening = clean_text(is_array($weekday) ? implode(" | ", $weekday) : "");
                $placeTypes = $place["types"] ?? $types;
                $website = clean_text((string)($place["website"] ?? $place["url"] ?? ""));
                $phone = clean_text((string)($place["formatted_phone_number"] ?? ""));
                $image = $downloadImages ? download_google_photo($place, $state, $district, $generatedDir) : "";

                $row = [
                    $name,
                    $state,
                    $district,
                    $category,
                    build_description($name, $state, $district, $category, $address, $rating, $reviews),
                    $address,
                    (string)$lat,
                    (string)$lng,
                    number_format(estimate_cost($category, $priceLevel, $name), 2, ".", ""),
                    $opening,
                    $image,
                    "1",
                    (string)visit_duration($category),
                    "",
                    "",
                    halal_status($category, $name),
                    (string)is_outdoor_place($category, $placeTypes, $name),
                    best_time($category),
                    (string)needs_dress_code($placeTypes, $name),
                    $website,
                    $phone,
                    $rating !== null ? number_format($rating, 2, ".", "") : "",
                    $rating !== null ? number_format($rating, 2, ".", "") : "",
                ];

                fputcsv($out, $row);
                $selected[] = $name;
                $seen[$nameKey] = true;
                $seenStateNames[$nameKey] = true;
                $generatedStateNames[$state][$nameKey] = true;
                if ($placeId !== "") $generatedPlaceIds[$placeId] = true;
                $totalGenerated++;
            }
        }

        $status = count($selected) >= $needed ? "ok" : "not_enough_google_results";
        fputcsv($report, [$state, $district, $existingCount, $perDistrict, $needed, count($selected), $status]);
    }
}

fclose($out);
fclose($report);

echo "Done. Generated rows: {$totalGenerated}\n";
echo "CSV: {$outPath}\n";
echo "Report: {$reportPath}\n";
