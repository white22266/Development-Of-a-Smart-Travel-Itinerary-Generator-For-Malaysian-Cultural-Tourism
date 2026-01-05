<?php
// itinerary/export_pdf.php  (PHP 7.4 compatible)
// FIXED:
// 1) Use COALESCE(cp.image_path, cp.image_url) so migrated local images will be used.
// 2) Relax JOIN for attraction items (ii.item_type='attraction' can match museum/heritage/nature/etc).
// 3) More robust remote image fetch (redirect support + SSL fallback + size limit).
// NOTE: You said GD extension already enabled. WebP -> PNG conversion will work only if GD is active.

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

    if (function_exists("mime_content_type")) {
        $m = @mime_content_type($absPath);
        if (is_string($m) && $m !== "") return $m;
    }

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

// FIXED: robust curl fetch (redirects + size limit + SSL fallback)
// Returns bytes or "".
function curl_fetch($url, &$httpCode, &$effectiveUrl, &$err)
{
    $httpCode = 0;
    $effectiveUrl = "";
    $err = "";

    if (!function_exists("curl_init")) {
        $err = "cURL extension not available.";
        return "";
    }

    $MAX_BYTES = 8 * 1024 * 1024; // 8MB limit to avoid memory explosion in PDF

    // helper to run curl once
    $run = function ($verifyPeer, $verifyHost) use ($url, $MAX_BYTES, &$httpCode, &$effectiveUrl, &$err) {
        $httpCode = 0;
        $effectiveUrl = "";
        $err = "";

        $downloaded = 0;
        $data = "";

        $ch = curl_init((string)$url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // support Wikimedia Special:FilePath redirect
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => "Mozilla/5.0 (PDF Export)",
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $verifyHost,
        ));

        // enforce size limit (works even when RETURNTRANSFER=true)
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$data, &$downloaded, $MAX_BYTES) {
            $len = strlen($chunk);
            $downloaded += $len;
            if ($downloaded > $MAX_BYTES) {
                return 0; // abort
            }
            $data .= $chunk;
            return $len;
        });

        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = (string)curl_error($ch);
        curl_close($ch);

        if ($downloaded > $MAX_BYTES) {
            $err = "Image too large (> {$MAX_BYTES} bytes).";
            return "";
        }

        if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
            return "";
        }

        return (string)$data;
    };

    // 1) strict SSL first
    $bytes = $run(true, 2);
    if ($bytes !== "") return $bytes;

    // 2) FIXED: fallback for Windows CA issues (only for images)
    // If you prefer strict-only, remove this fallback.
    $bytes2 = $run(false, 0);
    if ($bytes2 !== "") {
        error_log("PDF image fetch SSL fallback used for: " . (string)$url);
        return $bytes2;
    }

    return "";
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

// FIXED: accept both url and local (uploads/...)
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
        if ($bytes === "") {
            if ($err !== "") error_log("PDF image fetch failed: {$raw} | {$err}");
            return "";
        }

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
  FIXED JOIN:
  - Many of your itinerary_items.item_type uses 'attraction', but cultural_places.category uses museum/heritage/nature/etc.
  - So we match by name, and:
      (cp.category = ii.item_type OR ii.item_type='attraction')
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
        COALESCE(cp.image_path, cp.image_url) AS image_src
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp
        ON cp.name = ii.item_title
       AND (cp.category = ii.item_type OR ii.item_type = 'attraction')
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
        // FIXED: use image_src (COALESCE(image_path, image_url))
        $imgUri = image_to_data_uri($x["image_src"]);

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
$options->set("isRemoteEnabled", true); // OK: we still embed data URIs; keep enabled for safety
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);
$dompdf->loadHtml("<!doctype html><html><head><meta charset='utf-8'></head><body>{$html}</body></html>");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$filename = "itinerary_" . $itineraryId . ".pdf";
$dompdf->stream($filename, array("Attachment" => true));
exit;
