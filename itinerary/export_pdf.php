<?php
// itinerary/export_pdf.php  (PHP 7.4 compatible)
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);

if ($travellerId <= 0 || $itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

/* ------------------------ Helpers (PHP 7.4) ------------------------ */

function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function is_http_url($s)
{
    return (bool)preg_match('#^https?://#i', (string)$s);
}

// Convert a project-relative path (e.g. uploads/places/xxx.jpg) to absolute FS path
function project_abs_path_from_itinerary_dir($relativePath)
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === "") return "";
    $relativePath = ltrim($relativePath, "/\\");
    $abs = realpath(__DIR__ . "/../" . $relativePath);
    return $abs ? $abs : "";
}

function detect_mime_from_bytes($bytes)
{
    $bytes = (string)$bytes;
    if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return "image/png";
    if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return "image/jpeg";
    if (substr($bytes, 0, 6) === "GIF87a" || substr($bytes, 0, 6) === "GIF89a") return "image/gif";
    if (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") return "image/webp";
    return "application/octet-stream";
}

function file_mime($absPath)
{
    $absPath = (string)$absPath;
    if (!is_file($absPath)) return "";

    // Prefer mime_content_type if available (simple, PHP 7.4 friendly)
    if (function_exists("mime_content_type")) {
        $m = @mime_content_type($absPath);
        if (is_string($m) && $m !== "") return $m;
    }

    // Fallback to finfo if present
    if (function_exists("finfo_open")) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $absPath);
            finfo_close($fi);
            if (is_string($mime) && $mime !== "") return $mime;
        }
    }
    return "";
}

function curl_fetch($url, &$httpCode, &$effectiveUrl, &$err)
{
    $httpCode = 0;
    $effectiveUrl = "";
    $err = "";

    if (!function_exists("curl_init")) {
        $err = "cURL extension not available.";
        return "";
    }

    $ch = curl_init((string)$url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // important for Wikimedia Special:FilePath redirects
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => "Mozilla/5.0 (PDF Export)",
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    $data = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if ($data === false || $httpCode < 200 || $httpCode >= 300) {
        return "";
    }
    return (string)$data;
}

function webp_bytes_to_png_bytes($webpBytes)
{
    if (!function_exists("imagecreatefromwebp")) return "";

    $tmp = tempnam(sys_get_temp_dir(), "webp_");
    if ($tmp === false) return "";

    @file_put_contents($tmp, $webpBytes);

    $im = @imagecreatefromwebp($tmp);
    @unlink($tmp);
    if (!$im) return "";

    ob_start();
    imagepng($im);
    imagedestroy($im);
    $png = ob_get_clean();
    return $png ? $png : "";
}

function webp_file_to_png_data_uri($absPath)
{
    $absPath = (string)$absPath;
    if (!is_file($absPath)) return "";
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    if ($ext !== "webp") return "";
    if (!function_exists("imagecreatefromwebp")) return "";

    $im = @imagecreatefromwebp($absPath);
    if (!$im) return "";

    ob_start();
    imagepng($im);
    imagedestroy($im);
    $pngBytes = ob_get_clean();
    if (!$pngBytes) return "";

    return "data:image/png;base64," . base64_encode($pngBytes);
}

function image_to_data_uri($imageUrlOrPath)
{
    $raw = trim((string)$imageUrlOrPath);
    if ($raw === "") return "";

    // already data URI
    if (stripos($raw, "data:image/") === 0) return $raw;

    // remote
    if (is_http_url($raw)) {
        $http = 0;
        $eff = "";
        $err = "";
        $bytes = curl_fetch($raw, $http, $eff, $err);
        if ($bytes === "") return "";

        $mime = detect_mime_from_bytes($bytes);

        // Convert remote WebP to PNG (more compatible)
        if ($mime === "image/webp") {
            $png = webp_bytes_to_png_bytes($bytes);
            if ($png !== "") {
                return "data:image/png;base64," . base64_encode($png);
            }
        }

        if (strpos($mime, "image/") !== 0) $mime = "image/jpeg";
        return "data:" . $mime . ";base64," . base64_encode($bytes);
    }

    // local relative (uploads/places/...)
    $abs = project_abs_path_from_itinerary_dir($raw);
    if ($abs === "") return "";

    // Convert local WebP to PNG
    $webpPng = webp_file_to_png_data_uri($abs);
    if ($webpPng !== "") return $webpPng;

    $bytes = @file_get_contents($abs);
    if ($bytes === false) return "";

    $mime = file_mime($abs);
    if ($mime === "" || strpos($mime, "image/") !== 0) {
        $mime = detect_mime_from_bytes($bytes);
        if (strpos($mime, "image/") !== 0) $mime = "image/jpeg";
    }

    return "data:" . $mime . ";base64," . base64_encode($bytes);
}

/* ------------------------ Load itinerary ------------------------ */

$stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id=? AND traveller_id=? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

/* ------------------------ Load items + join cultural_places ------------------------ */
/*
  Assumption: itinerary_items.item_title == cultural_places.name
              itinerary_items.item_type  == cultural_places.category
*/
$stmt = $conn->prepare("
    SELECT
        ii.day_no,
        ii.sequence_no,
        ii.item_type,
        ii.item_title,
        ii.estimated_cost,
        ii.notes,
        ii.distance_km,
        ii.travel_time_min,
        cp.address,
        cp.opening_hours,
        cp.image_url
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp
        ON cp.name = ii.item_title
       AND cp.category = ii.item_type
       AND (cp.is_active = 1 OR cp.is_active IS NULL)
    WHERE ii.itinerary_id=?
    ORDER BY ii.day_no ASC, ii.sequence_no ASC
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$days = array();
while ($row = $res->fetch_assoc()) {
    $d = (int)$row["day_no"];
    if (!isset($days[$d])) $days[$d] = array();
    $days[$d][] = $row;
}

/* ------------------------ Build HTML ------------------------ */

$css = "
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #0f172a; }
  .header { margin-bottom: 10px; }
  .title { font-size: 18px; font-weight: 800; margin: 0 0 6px 0; }
  .meta { color: #475569; margin: 0; }
  .hr { border-top: 1px solid #e2e8f0; margin: 12px 0; }

  h3 { margin: 14px 0 8px 0; font-size: 14px; }
  .card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; margin: 10px 0 12px 0; }
  .card-title { font-weight: 800; font-size: 13px; margin: 0 0 8px 0; }

  .imgwrap { margin: 6px 0 10px 0; }
  .imgwrap img { width: 100%; max-width: 520px; border: 1px solid #e2e8f0; border-radius: 10px; }
  .noimg { width: 520px; max-width: 100%; height: 200px; border: 1px dashed #cbd5e1; border-radius: 10px;
           display: flex; align-items: center; justify-content: center; color: #64748b; font-weight: 800; }

  .row { margin: 2px 0; line-height: 1.35; }
  .k { font-weight: 800; }
</style>
";

$html = $css;
$html .= "<div class='header'>";
$html .= "<p class='title'>" . esc($it["title"] ? $it["title"] : ("Itinerary #" . $itineraryId)) . "</p>";
$html .= "<p class='meta'>Total Days: " . (int)($it["total_days"] ?? 0) .
    " | Items/Day: " . (int)($it["items_per_day"] ?? 0) .
    " | Total Cost (RM): " . number_format((float)($it["total_estimated_cost"] ?? 0), 2) .
    "</p>";
if (!empty($it["start_date"])) {
    $html .= "<p class='meta'>Start Date: " . esc($it["start_date"]) . "</p>";
}
$html .= "</div>";
$html .= "<div class='hr'></div>";

ksort($days);
foreach ($days as $dayNo => $items) {
    $html .= "<h3>Day " . (int)$dayNo . "</h3>";

    foreach ($items as $x) {
        $imgUri = image_to_data_uri($x["image_url"]);

        $html .= "<div class='card'>";
        $html .= "<p class='card-title'>" . esc($x["item_title"]) . "</p>";

        if ($imgUri !== "") {
            $html .= "<div class='imgwrap'><img src='" . esc($imgUri) . "' alt='Image'></div>";
        } else {
            $html .= "<div class='noimg'>NO IMAGE</div>";
        }

        $html .= "<div class='row'><span class='k'>Category:</span> " . esc($x["item_type"]) . "</div>";
        $html .= "<div class='row'><span class='k'>Estimated Cost (RM):</span> " . number_format((float)($x["estimated_cost"] ?? 0), 2) . "</div>";

        if (!empty($x["opening_hours"])) {
            $html .= "<div class='row'><span class='k'>Opening Hours:</span> " . esc($x["opening_hours"]) . "</div>";
        }
        if (!empty($x["address"])) {
            $html .= "<div class='row'><span class='k'>Address:</span> " . esc($x["address"]) . "</div>";
        }

        if ($x["distance_km"] !== null && $x["distance_km"] !== "") {
            $html .= "<div class='row'><span class='k'>Segment Distance (km):</span> " . esc($x["distance_km"]) . "</div>";
        }
        if ($x["travel_time_min"] !== null && $x["travel_time_min"] !== "") {
            $html .= "<div class='row'><span class='k'>Segment Time (min):</span> " . esc($x["travel_time_min"]) . "</div>";
        }

        if (!empty($x["notes"])) {
            $html .= "<div class='row'><span class='k'>Notes:</span> " . esc($x["notes"]) . "</div>";
        }

        $html .= "</div>";
    }
}

/* ------------------------ Render PDF (Dompdf) ------------------------ */

$autoload = __DIR__ . "/../vendor/autoload.php";
if (!file_exists($autoload)) {
    // fallback: show HTML so user can Print to PDF
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Export PDF</title></head><body>{$html}</body></html>";
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

$filename = "itinerary_" . $itineraryId . ".pdf";
$dompdf->stream($filename, array("Attachment" => true));
exit;
