<?php
// itinerary/export_pdf.php
// Complete itinerary report export: trip summary, cost breakdown,
// hotel recommendations, day schedule, and place details.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/OpeningHours.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
// The PDF fonts (DejaVu Sans, the only ones dompdf ships) have no Chinese characters, so a traveller
// who reads Chinese gets the English report; English and Malay follow the language of the page.
$pdfLang = i18n_lang() === "ms" ? "ms" : "en";
$pdfIsFallback = i18n_lang() === "zh";

/** Text in the language of the PDF (see $pdfLang). */
function pdf_t(string $key, array $vars = []): string
{
    global $pdfLang;
    return t($key, $vars, $pdfLang);
}

/** Label of a fixed choice (transport, tier, interest ...) in the language of the PDF. */
function pdf_choice(string $group, ?string $value): string
{
    global $pdfLang;
    return t_choice($group, $value, $pdfLang);
}

/** Label of a place category, or of an item type when the place has no category. */
function pdf_category(string $category): string
{
    global $pdfLang;
    foreach (['interest', 'itype'] as $group) {
        $key = 'opt.' . $group . '.' . strtolower($category);
        $text = t($key, [], $pdfLang);
        if ($text !== $key) return $text;
    }
    return t_choice('interest', $category, $pdfLang);
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default', [], $pdfLang);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function fmt_rm($value): string
{
    return "RM " . number_format((float)$value, 2);
}

function fmt_time($time): string
{
    $time = trim((string)$time);
    if ($time === "") return "-";
    $ts = strtotime($time);
    return $ts ? date("g:i A", $ts) : $time;
}

function clean_text($value): string
{
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/\s+/', ' ', $value) ?? "";
    return $value;
}

/**
 * Author and licence of a place photo that came from Wikimedia Commons, such as
 * "Chongkian, CC BY-SA 4.0". "" for a photo without a stored credit (uploaded by an admin).
 * $maxAuthor shortens a long author line for the caption under the photo.
 */
function photo_credit(array $item, int $maxAuthor = 0): string
{
    if (trim((string)($item["image_source_url"] ?? "")) === "") return "";
    $author = clean_text($item["image_credit"] ?? "");
    if ($maxAuthor > 0 && mb_strlen($author) > $maxAuthor) $author = rtrim(mb_substr($author, 0, $maxAuthor - 1)) . "...";
    $licence = clean_text($item["image_license"] ?? "");
    $parts = array_filter([$author, $licence], static fn($part) => $part !== "");
    return $parts ? implode(", ", $parts) : "Wikimedia Commons";
}

function extract_reason_selected(?string $notes): string
{
    $text = (string)$notes;
    $pos = strpos($text, 'Reason:');
    if ($pos === false) return '';
    return trim(substr($text, $pos + 7));
}

/** Full path of a file in this app's uploads folder, or "" for anything else. */
function uploads_abs_path($relativePath): string
{
    $relativePath = ltrim(trim((string)$relativePath), "/\\");
    $uploads = realpath(__DIR__ . "/../uploads");
    $abs = $relativePath !== "" ? realpath(__DIR__ . "/../" . $relativePath) : false;
    if (!$uploads || !$abs || !is_file($abs)) return "";
    return str_starts_with($abs, $uploads . DIRECTORY_SEPARATOR) ? $abs : "";
}

function detect_mime_from_bytes(string $bytes): string
{
    if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return "image/png";
    if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return "image/jpeg";
    if (substr($bytes, 0, 6) === "GIF87a" || substr($bytes, 0, 6) === "GIF89a") return "image/gif";
    if (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") return "image/webp";
    return "application/octet-stream";
}

function webp_to_png_data_uri(string $bytes): string
{
    if (!function_exists("imagecreatefromwebp")) return "";
    $tmp = tempnam(sys_get_temp_dir(), "pdf_webp_");
    if (!$tmp) return "";
    @file_put_contents($tmp, $bytes);
    $im = @imagecreatefromwebp($tmp);
    @unlink($tmp);
    if (!$im) return "";
    ob_start();
    imagepng($im);
    imagedestroy($im);
    $png = ob_get_clean();
    return $png ? "data:image/png;base64," . base64_encode($png) : "";
}

function image_to_data_uri($imageUrlOrPath): string
{
    $abs = uploads_abs_path($imageUrlOrPath);
    if ($abs === "") return "";
    $bytes = @file_get_contents($abs);
    if ($bytes === false) return "";
    $mime = detect_mime_from_bytes($bytes);
    if (strpos($mime, "image/") !== 0) return "";   // not a picture: leave it out
    if ($mime === "image/webp") return webp_to_png_data_uri($bytes);   // the PDF library cannot read WebP
    return "data:" . $mime . ";base64," . base64_encode($bytes);
}

function valid_coord($lat, $lng): bool
{
    if ($lat === null || $lng === null || $lat === "" || $lng === "") return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    return is_finite($lat) && is_finite($lng) && !($lat == 0.0 && $lng == 0.0);
}

function activity_text(string $type, string $category): string
{
    $key = strtolower($category ?: $type);
    $known = ["food", "festival", "museum", "heritage", "culture", "nature", "shopping"];
    return pdf_t(in_array($key, $known, true) ? "pdf.act_" . $key : "pdf.act_default");
}

/* ------------------------ Load itinerary ------------------------ */

$partyCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
$partySelect = ($partyCheck && $partyCheck->num_rows > 0) ? ", tp.party_size" : "";
$stmt = $conn->prepare("
    SELECT i.*, tp.budget, tp.budget_tier, tp.transport_type, tp.interests,
           tp.preferred_states, tp.preferred_districts, tp.traveller_type{$partySelect},
           tp.travel_pace, tp.dietary_preference, tp.preferred_visit_time
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
// A version of a plan that was not chosen is no itinerary of its own (version_of is set): treated as not found.
if (!$it || ($it['version_of'] ?? null) !== null) {
    header("Location: my_itineraries.php");
    exit;
}

$districtCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrict = ($districtCheck && $districtCheck->num_rows > 0);
$districtSelect = $hasDistrict ? "cp.district" : "NULL AS district";

// Lunch and dinner rows (migration 20261116_meal_slots.sql)
$mealSlotCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'meal_slot'");
$mealSlotSelect = ($mealSlotCheck && $mealSlotCheck->num_rows > 0) ? "ii.meal_slot" : "NULL AS meal_slot";

// The place type (migration 20261119_place_types.sql): opening hours the planner assumed are marked "(assumed)".
$placeTypeCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'place_type'");
$placeTypeSelect = ($placeTypeCheck && $placeTypeCheck->num_rows > 0) ? "cp.place_type" : "NULL AS place_type";

$stmt = $conn->prepare("
    SELECT
        ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, {$mealSlotSelect}, ii.place_id,
        ii.item_title, ii.start_time, ii.end_time, ii.estimated_cost,
        ii.notes, ii.distance_km, ii.travel_time_min,
        cp.latitude, cp.longitude, cp.address, cp.opening_hours, {$placeTypeSelect},
        cp.category, cp.state, {$districtSelect},
        cp.description, cp.visit_duration_min, cp.halal_status,
        cp.best_time_to_visit, cp.dress_code_required,
        cp.image_url AS image_src,
        ps.image_source_url, ps.image_credit, ps.image_license, ps.description_source, ps.wikipedia_url
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    LEFT JOIN place_sources ps ON ps.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no ASC, ii.sequence_no ASC
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$days = [];
$allItems = [];
$lastPlace = null;
$totalDistanceKm = 0.0;
while ($row = $res->fetch_assoc()) {
    $d = (int)$row["day_no"];
    if ($row["place_id"] !== null) $row["opening_hours"] = i18n_hours(OpeningHours::shown($row), $pdfLang);
    if (!isset($days[$d])) $days[$d] = [];
    $days[$d][] = $row;
    $allItems[] = $row;
    $totalDistanceKm += (float)($row["distance_km"] ?? 0);
    if (valid_coord($row["latitude"] ?? null, $row["longitude"] ?? null)) {
        $lastPlace = $row;
    }
}
ksort($days);

// The note of each day (a public holiday) and the base of each day, when the trip was planned with them (migration 20261119_trip_bases.sql).
$dayNotes = [];
$dayBaseNames = [];
$baseCheck = $conn->query("SHOW COLUMNS FROM itinerary_days LIKE 'base_name'");
if ($baseCheck && $baseCheck->num_rows > 0) {
    $noteCheck = $conn->query("SHOW COLUMNS FROM itinerary_days LIKE 'day_note'");
    $noteSelect = ($noteCheck && $noteCheck->num_rows > 0) ? "day_note" : "NULL AS day_note";
    $dayStmt = $conn->prepare("SELECT day_no, base_name, {$noteSelect} FROM itinerary_days WHERE itinerary_id = ?");
    $dayStmt->bind_param("i", $itineraryId);
    $dayStmt->execute();
    foreach ($dayStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $dayRow) {
        if (trim((string)($dayRow["day_note"] ?? "")) !== "") $dayNotes[(int)$dayRow["day_no"]] = (string)$dayRow["day_note"];
        if (trim((string)($dayRow["base_name"] ?? "")) !== "") $dayBaseNames[(int)$dayRow["day_no"]] = (string)$dayRow["base_name"];
    }
    $dayStmt->close();
}

$tripDays = max(1, (int)($it["total_days"] ?? count($days)));
$budget = (float)($it["budget"] ?? 0);
$transportType = (string)($it["transport_type"] ?? "car");
$budgetTier = strtolower((string)($it["budget_tier"] ?? "normal"));
$travellerType = strtolower((string)($it["traveller_type"] ?? "solo"));
$partySize = CostEstimationService::resolvePartySize($travellerType, isset($it["party_size"]) ? (int)$it["party_size"] : null);
$tierDefaults = CostEstimationService::budgetTierDefaults($budgetTier, $budget, $tripDays, $partySize);

$costService = new CostEstimationService($transportType, $tripDays, $budget, $travellerType, $partySize);
$costBreakdown = $costService->calculate($allItems, $totalDistanceKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);
$perDayBreakdown = $costService->perDayBreakdown($days);

$hotelRecommendations = [];
$hotelBudget = HotelRecommendationService::nightlyRoomBudget($budget, $tripDays, CostEstimationService::roomCount($partySize));
if ($tripDays > 1 && $lastPlace && valid_coord($lastPlace["latitude"] ?? null, $lastPlace["longitude"] ?? null)) {
    $hotelService = new HotelRecommendationService($conn);
    $hotelRecommendations = $hotelService->recommend((float)$lastPlace["latitude"], (float)$lastPlace["longitude"], $hotelBudget, 15.0, 3);
    if (empty($hotelRecommendations) && !empty($lastPlace["state"])) {
        $hotelRecommendations = $hotelService->recommendByState((string)$lastPlace["state"], $hotelBudget, 3);
    }
}

$totalPlaces = count($allItems);
$generatedAt = i18n_date(date("Y-m-d"), "short", $pdfLang) . ", " . date("g:i A");

/* ------------------------ Build report HTML ------------------------ */

$css = "
<style>
  @page { margin: 24px 24px 30px 24px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10.5px; color: #0f172a; line-height: 1.35; }
  h1, h2, h3, p { margin-top: 0; }
  .cover { border-bottom: 3px solid #4f46e5; padding-bottom: 12px; margin-bottom: 14px; }
  .eyebrow { color: #4f46e5; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
  h1 { font-size: 21px; margin-bottom: 6px; }
  h2 { font-size: 14px; margin: 16px 0 8px; color: #111827; }
  h3 { font-size: 12px; margin: 10px 0 6px; }
  .muted { color: #64748b; }
  .grid4 { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 8px 0 12px; }
  .metric { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px; }
  .metric-label { font-size: 8.5px; color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
  .metric-value { font-size: 14px; font-weight: 900; margin-top: 3px; color: #111827; }
  .section { page-break-inside: avoid; margin-bottom: 12px; }
  .box { border: 1px solid #e2e8f0; border-radius: 9px; padding: 10px; background: #fff; margin-bottom: 9px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f1f5f9; color: #334155; font-size: 8.5px; text-transform: uppercase; text-align: left; padding: 6px; border: 1px solid #e2e8f0; }
  td { padding: 6px; border: 1px solid #e2e8f0; vertical-align: top; }
  .cost-total { background: #111827; color: #fff; font-weight: 900; }
  .ok { color: #166534; font-weight: 900; }
  .over { color: #b91c1c; font-weight: 900; }
  .day-title { background: #eef2ff; color: #3730a3; padding: 8px 10px; border-radius: 8px; font-weight: 900; margin: 14px 0 8px; }
  .place-card { page-break-inside: avoid; border: 1px solid #e2e8f0; border-radius: 9px; padding: 9px; margin-bottom: 8px; }
  .place-title { font-weight: 900; font-size: 12px; margin-bottom: 5px; }
  .place-img { width: 172px; max-height: 105px; object-fit: cover; border-radius: 7px; border: 1px solid #e2e8f0; }
  .photo-credit { color: #64748b; font-size: 7px; line-height: 1.2; margin-top: 2px; }
  .place-desc { color: #334155; font-size: 9.7px; }
  .tag { display: inline-block; background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 2px 7px; font-size: 8.5px; font-weight: 800; }
  .note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px; color: #92400e; }
  .footer { color: #64748b; font-size: 9px; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 8px; }
</style>
";

$html = $css;
$html .= "<div class='cover'>";
$html .= "<div class='eyebrow'>Smart Travel Itinerary Generator</div>";
$html .= "<h1>" . esc($it["title"] ?: pdf_t("pdf.default_title")) . "</h1>";
$html .= "<p class='muted'>" . esc(pdf_t("pdf.prepared_for", ["name" => $travellerName, "when" => $generatedAt])) . "</p>";
$html .= "<p class='muted'>" . esc(pdf_t("pdf.intro")) . "</p>";
$html .= "</div>";

$html .= "<table class='grid4'><tr>";
$metrics = [
    [pdf_t("pdf.m_days"), $tripDays > 1 ? pdf_t("trip.days_many", ["n" => $tripDays]) : pdf_t("trip.days_one")],
    [pdf_t("pdf.m_places"), $totalPlaces],
    [pdf_t("trip.transport"), pdf_choice("transport", $transportType)],
    [pdf_t("pdf.m_total"), fmt_rm($costBreakdown["total_cost"] ?? 0)],
];
foreach ($metrics as $m) {
    $html .= "<td class='metric'><div class='metric-label'>" . esc($m[0]) . "</div><div class='metric-value'>" . esc($m[1]) . "</div></td>";
}
$html .= "</tr></table>";

$html .= "<div class='section box'>";
$interestLabels = array_map(
    static fn($code) => pdf_choice("interest", $code),
    array_filter(array_map("trim", explode(",", (string)($it["interests"] ?? ""))), static fn($code) => $code !== "")
);
$html .= "<h2>" . esc(pdf_t("trip.title")) . "</h2>";
$html .= "<table>";
$html .= "<tr><th>" . esc(pdf_t("trip.start_date")) . "</th><td>" . esc(!empty($it["start_date"]) ? i18n_date((string)$it["start_date"], "short", $pdfLang) : "-") . "</td><th>" . esc(pdf_t("trip.budget")) . "</th><td>" . esc($budget > 0 ? fmt_rm($budget) : "-") . "</td></tr>";
$html .= "<tr><th>" . esc(pdf_t("pdf.destination")) . "</th><td>" . esc($it["preferred_states"] ?: pdf_t("trip.states_fallback")) . "</td><th>" . esc(pdf_t("pdf.district")) . "</th><td>" . esc($it["preferred_districts"] ?: "-") . "</td></tr>";
$html .= "<tr><th>" . esc(pdf_t("pdf.interests")) . "</th><td>" . esc($interestLabels ? implode(", ", $interestLabels) : "-") . "</td><th>" . esc(pdf_t("pdf.style")) . "</th><td>" . esc(pdf_choice("tier", $it["budget_tier"] ?: "normal") . " / " . pdf_choice("pace", $it["travel_pace"] ?: "normal")) . "</td></tr>";
$html .= "</table>";
$html .= "</div>";

$html .= "<div class='section box'>";
$html .= "<h2>" . esc(pdf_t("pdf.h_reco")) . "</h2>";
$html .= "<ul>";
if ($budget > 0) {
    $html .= "<li>" . esc(!empty($costBreakdown["within_budget"]) ? pdf_t("pdf.r_within") : pdf_t("pdf.r_over")) . "</li>";
}
$html .= "<li>" . esc(pdf_t("pdf.r_order")) . "</li>";
if (stripos((string)$it["interests"], "festival") !== false) {
    $html .= "<li>" . esc(pdf_t("pdf.r_festival")) . "</li>";
}
$html .= "<li>" . esc(pdf_t("pdf.r_check")) . "</li>";
$html .= "</ul>";
$html .= "</div>";

$html .= "<div class='section box'>";
$html .= "<h2>" . esc(pdf_t("trip.cost_title")) . "</h2>";
$html .= "<table>";
$html .= "<tr><th>" . esc(pdf_t("pdf.c_component")) . "</th><th>" . esc(pdf_t("pdf.c_amount")) . "</th><th>" . esc(pdf_t("pdf.c_basis")) . "</th></tr>";
foreach (($costBreakdown["breakdown"] ?? []) as $row) {
    $html .= "<tr><td>" . esc(i18n_cost_label((string)($row["label"] ?? ""), $transportType, $pdfLang)) . "</td><td>" . esc(fmt_rm($row["amount"] ?? 0)) . "</td><td>" . esc($row["note"] ?? "") . "</td></tr>";
}
$html .= "<tr class='cost-total'><td>" . esc(pdf_t("trip.cost_total")) . "</td><td>" . esc(fmt_rm($costBreakdown["total_cost"] ?? 0)) . "</td><td>";
if ($budget > 0) {
    $class = !empty($costBreakdown["within_budget"]) ? "ok" : "over";
    $statusKey = !empty($costBreakdown["within_budget"]) ? "pdf.within_by" : "pdf.over_by";
    $html .= "<span class='" . $class . "'>" . esc(pdf_t($statusKey, ["amount" => fmt_rm(abs((float)($costBreakdown["budget_difference"] ?? 0)))])) . "</span>";
} else {
    $html .= esc(pdf_t("pdf.no_budget"));
}
$html .= "</td></tr></table></div>";

$html .= "<div class='section box'>";
$html .= "<h2>" . esc(pdf_t("pdf.h_hotels")) . "</h2>";
if (!empty($hotelRecommendations)) {
    $html .= "<table><tr><th>" . esc(pdf_t("pdf.c_stay")) . "</th><th>" . esc(pdf_t("trip.col_type")) . "</th><th>" . esc(pdf_t("pdf.c_location")) . "</th><th>" . esc(pdf_t("pdf.c_price")) . "</th><th>" . esc(pdf_t("pdf.c_distance")) . "</th></tr>";
    foreach ($hotelRecommendations as $hotel) {
        $distance = $hotel["distance_km"] !== null ? pdf_t("pdf.dist_last", ["n" => number_format((float)$hotel["distance_km"], 1)]) : pdf_t("pdf.dist_state");
        $type = pdf_choice("hotelkind", $hotel["kind"] ?? "") . ($hotel["stars"] !== null ? ", " . pdf_t("trip.star", ["n" => (float)$hotel["stars"]]) : "");
        $html .= "<tr><td>" . esc($hotel["name"]) . "</td><td>" . esc($type) . "</td><td>" . esc($hotel["address"] !== "" ? $hotel["address"] : $hotel["state"]) . "</td><td>" . esc(fmt_rm($hotel["price_per_night"])) . "</td><td>" . esc($distance) . "</td></tr>";
    }
    $html .= "</table>";
    $html .= "<p class='muted'>" . esc(pdf_t("pdf.osm_note")) . "</p>";
} else {
    $html .= "<div class='note'>" . esc(pdf_t("pdf.no_hotels")) . "</div>";
}
$html .= "</div>";

$html .= "<div class='section'>";
$html .= "<h2>" . esc(pdf_t("pdf.h_daily")) . "</h2>";
foreach ($days as $dayNo => $items) {
    $dayDate = "";
    if (!empty($it["start_date"])) {
        $dt = DateTime::createFromFormat("Y-m-d", (string)$it["start_date"]);
        if ($dt) {
            $dt->modify("+" . ((int)$dayNo - 1) . " days");
            $dayDate = i18n_date($dt->format("Y-m-d"), "short", $pdfLang);
        }
    }
    $dayCost = $perDayBreakdown[$dayNo]["attraction_cost"] ?? 0;
    $html .= "<div class='day-title'>" . esc(pdf_t("trip.day", ["n" => (int)$dayNo])) . ($dayDate ? " | " . esc($dayDate) : "") . " | " . esc(pdf_t("pdf.day_cost", ["cost" => fmt_rm($dayCost)])) . "</div>";
    // Where the night after the day is spent (its hotel, else the base of the day) and the note of the day.
    $nightAt = "";
    if ((int)$dayNo < $tripDays) {
        foreach (array_reverse($items) as $nightRow) {
            if (strtolower((string)($nightRow["item_type"] ?? "")) === "hotel") { $nightAt = (string)$nightRow["item_title"]; break; }
        }
        if ($nightAt === "") $nightAt = $dayBaseNames[(int)$dayNo] ?? "";
    }
    if ($nightAt !== "") $html .= "<div class='muted'>" . esc(pdf_t("iv.night_at", ["name" => $nightAt])) . "</div>";
    if (isset($dayNotes[(int)$dayNo])) $html .= "<div class='muted'>" . esc(i18n_day_note($dayNotes[(int)$dayNo], $pdfLang)) . "</div>";
    $html .= "<table><tr><th style='width:70px;'>" . esc(pdf_t("trip.col_time")) . "</th><th style='width:25px;'>#</th><th>" . esc(pdf_t("pdf.col_place_act")) . "</th><th style='width:70px;'>" . esc(pdf_t("trip.col_type")) . "</th><th style='width:60px;'>" . esc(pdf_t("pdf.col_cost")) . "</th><th style='width:72px;'>" . esc(pdf_t("trip.col_travel")) . "</th></tr>";
    foreach ($items as $item) {
        $time = fmt_time($item["start_time"] ?? "") . " - " . fmt_time($item["end_time"] ?? "");
        $category = strtolower((string)($item["category"] ?: $item["item_type"]));
        $travel = "-";
        if ($item["travel_time_min"] !== null && $item["travel_time_min"] !== "") {
            $travel = pdf_t("trip.min", ["n" => (int)$item["travel_time_min"]]);
            if ($item["distance_km"] !== null && $item["distance_km"] !== "") $travel .= " / " . number_format((float)$item["distance_km"], 1) . " km";
        }
        $html .= "<tr>";
        $html .= "<td>" . esc($time) . "</td>";
        $html .= "<td>" . (int)$item["sequence_no"] . "</td>";
        $mealSlot = (string)($item["meal_slot"] ?? "");
        $activity = $mealSlot !== "" && empty($item["place_id"])
            ? pdf_t("pdf.meal_free", ["slot" => mb_strtolower(pdf_choice("itype", $mealSlot))])
            : activity_text((string)$item["item_type"], $category);
        $html .= "<td><strong>" . esc(i18n_item_title((string)$item["item_title"], $pdfLang)) . "</strong><br><span class='muted'>" . esc($activity) . "</span>";
        if (!empty($item["address"])) $html .= "<br><span class='muted'>" . esc($item["address"]) . "</span>";
        $reason = extract_reason_selected($item["notes"] ?? "");
        if ($reason !== "" && strtolower((string)($item["item_type"] ?? "")) !== "hotel") $html .= "<br><span class='muted'><strong>" . esc(pdf_t("trip.reason")) . "</strong> " . esc(i18n_reason_text($reason, $pdfLang)) . "</span>";
        $html .= "</td>";
        $html .= "<td><span class='tag'>" . esc($mealSlot !== "" ? pdf_choice("itype", $mealSlot) : pdf_category($category)) . "</span></td>";
        $html .= "<td>" . esc($mealSlot !== "" ? pdf_t("shr.in_meal") : fmt_rm($item["estimated_cost"] ?? 0)) . "</td>";
        $html .= "<td>" . esc($travel) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</table>";
}
$html .= "</div>";

$html .= "<div class='section'>";
$html .= "<h2>" . esc(pdf_t("pdf.h_places")) . "</h2>";
$credits = [];   // place_id => what the credits table at the end says about it
foreach ($days as $dayNo => $items) {
    foreach ($items as $item) {
        if (!empty($item["meal_slot"]) && empty($item["place_id"])) continue;   // a meal break has no place to describe
        $img = image_to_data_uri($item["image_src"] ?? "");
        $category = strtolower((string)($item["category"] ?: $item["item_type"]));
        $html .= "<div class='place-card'>";
        $html .= "<div class='place-title'>" . esc(pdf_t("trip.day", ["n" => (int)$dayNo])) . " - " . esc(i18n_item_title((string)$item["item_title"], $pdfLang)) . "</div>";
        $html .= "<table><tr>";
        $html .= "<td style='width:185px; border:0; padding:0 9px 0 0;'>";
        $photoCredit = $img !== "" ? photo_credit($item, 60) : "";
        if ($img !== "") $html .= "<img class='place-img' src='" . esc($img) . "' alt='" . esc(pdf_t("pdf.alt_image")) . "'>";
        else $html .= "<div class='muted'>" . esc(pdf_t("pdf.no_image")) . "</div>";
        if ($photoCredit !== "") $html .= "<div class='photo-credit'>" . esc(pdf_t("pdf.photo", ["credit" => $photoCredit])) . "</div>";
        $html .= "</td><td style='border:0; padding:0;'>";
        $html .= "<div><span class='tag'>" . esc(pdf_category($category)) . "</span></div>";
        if (!empty($item["opening_hours"])) $html .= "<p><strong>" . esc(pdf_t("pdf.opening")) . "</strong> " . esc($item["opening_hours"]) . "</p>";
        if (!empty($item["address"])) $html .= "<p><strong>" . esc(pdf_t("pdf.address")) . "</strong> " . esc($item["address"]) . "</p>";
        $reason = extract_reason_selected($item["notes"] ?? "");
        if ($reason !== "" && strtolower((string)($item["item_type"] ?? "")) !== "hotel") $html .= "<p><strong>" . esc(pdf_t("trip.reason")) . "</strong> " . esc(i18n_reason_text($reason, $pdfLang)) . "</p>";
        if (!empty($item["best_time_to_visit"])) $html .= "<p><strong>" . esc(pdf_t("pdf.best_time")) . "</strong> " . esc($item["best_time_to_visit"]) . "</p>";
        if ((int)($item["dress_code_required"] ?? 0) === 1) $html .= "<p><strong>" . esc(pdf_t("pdf.etiquette")) . "</strong> " . esc(pdf_t("pdf.dress")) . "</p>";
        if ($item["halal_status"] !== null && $category === "food") $html .= "<p><strong>" . esc(pdf_t("pdf.halal")) . "</strong> " . esc((int)$item["halal_status"] === 1 ? pdf_t("pdf.halal_yes") : pdf_t("pdf.halal_no")) . "</p>";
        $desc = clean_text($item["description"] ?? "");
        if ($desc !== "") {
            if (function_exists("mb_substr")) $desc = mb_substr($desc, 0, 360);
            else $desc = substr($desc, 0, 360);
            $html .= "<p class='place-desc'>" . esc($desc) . "</p>";
        }
        $placeId = (int)($item["place_id"] ?? 0);
        $wikipediaUrl = (string)($item["wikipedia_url"] ?? "");
        $fromWikipedia = $desc !== "" && ($item["description_source"] ?? "") === "wikipedia" && str_starts_with($wikipediaUrl, "https://");
        if ($placeId > 0 && ($photoCredit !== "" || $fromWikipedia)) {
            $credits[$placeId] = [
                "title" => (string)$item["item_title"],
                "photo" => $photoCredit !== "" ? photo_credit($item) : "",
                "photo_url" => (string)($item["image_source_url"] ?? ""),
                "text_url" => $fromWikipedia ? $wikipediaUrl : "",
            ];
        }
        $html .= "</td></tr></table>";
        $html .= "</div>";
    }
}
$html .= "</div>";

if ($credits) {
    // Photos from Wikimedia Commons and texts from Wikipedia may be reused only with their author and licence named.
    $html .= "<div class='section'>";
    $html .= "<h2>" . esc(pdf_t("pdf.h_credits")) . "</h2>";
    $html .= "<table><tr><th>" . esc(pdf_t("trip.col_place")) . "</th><th>" . esc(pdf_t("pdf.c_photo")) . "</th><th>" . esc(pdf_t("pdf.c_desc")) . "</th></tr>";
    foreach ($credits as $credit) {
        $photo = "-";
        if ($credit["photo"] !== "") {
            $photo = esc($credit["photo"]);
            if (str_starts_with($credit["photo_url"], "https://")) $photo .= ", " . esc(pdf_t("pdf.via")) . " <a href='" . esc($credit["photo_url"]) . "'>Wikimedia Commons</a>";
        }
        $text = $credit["text_url"] !== ""
            ? esc(pdf_t("pdf.adapted_from")) . " <a href='" . esc($credit["text_url"]) . "'>Wikipedia</a>, <a href='https://creativecommons.org/licenses/by-sa/4.0/'>CC BY-SA 4.0</a>"
            : "-";
        $html .= "<tr><td>" . esc($credit["title"]) . "</td><td>" . $photo . "</td><td>" . $text . "</td></tr>";
    }
    $html .= "</table>";
    $html .= "<p class='muted'>" . esc(pdf_t("pdf.credits_note")) . "</p>";
    $html .= "</div>";
}

$html .= "<div class='footer'>" . esc(pdf_t("pdf.footer")) . " " . pdf_t("pdf.footer_osm");
if ($pdfIsFallback) {
    // The PDF cannot draw Chinese, so this line says in English why the report is not in Chinese.
    $html .= "<br>" . esc(t("pdf.zh_note", [], "en"));
}
$html .= "</div>";

/* ------------------------ Render PDF ------------------------ */

$autoload = __DIR__ . "/../vendor/autoload.php";
if (!file_exists($autoload)) {
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Itinerary Report</title></head><body>{$html}</body></html>";
    exit;
}

require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set("isHtml5ParserEnabled", true);
$options->set("isRemoteEnabled", true);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);
$dompdf->loadHtml("<!doctype html><html><head><meta charset='utf-8'></head><body>{$html}</body></html>");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$filename = "itinerary_report_" . $itineraryId . ".pdf";
$dompdf->stream($filename, ["Attachment" => true]);
exit;
