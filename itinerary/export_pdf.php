<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

/* ---------- Helpers (English only) ---------- */

function ordinal($n)
{
    $n = (int)$n;
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 13) return $n . "th";
    switch ($n % 10) {
        case 1:
            return $n . "st";
        case 2:
            return $n . "nd";
        case 3:
            return $n . "rd";
        default:
            return $n . "th";
    }
}

function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function pdf_img_src($raw)
{
    $raw = trim((string)$raw);
    if ($raw === "") return "";

    if (preg_match('#^https?://#i', $raw) || strpos($raw, '//') === 0) return $raw;
    if (strpos($raw, 'data:image/') === 0) return $raw;

    $raw = ltrim($raw, '/');
    $abs = realpath(__DIR__ . "/../" . $raw);
    if (!$abs) return "";
    $abs = str_replace("\\", "/", $abs);
    return "file:///" . $abs;
}

function build_static_map_url($points)
{
    // $points: array of ['lat'=>..., 'lng'=>..., 'label'=>'A'..]
    if (empty($points)) return "";

    $base = "https://maps.googleapis.com/maps/api/staticmap";
    $params = [
        "size" => "640x360",
        "maptype" => "roadmap",
        "key" => GOOGLE_MAPS_API_KEY
    ];
    $qs = http_build_query($params, "", "&", PHP_QUERY_RFC3986);

    $extras = [];

    // markers
    foreach ($points as $p) {
        $label = $p["label"] ?? "";
        $lat = $p["lat"];
        $lng = $p["lng"];
        $extras[] = "markers=" . rawurlencode("label:{$label}|{$lat},{$lng}");
    }

    // path
    if (count($points) >= 2) {
        $pathParts = [];
        foreach ($points as $p) {
            $pathParts[] = $p["lat"] . "," . $p["lng"];
        }
        $extras[] = "path=" . rawurlencode("color:0x1d4ed8|weight:4|" . implode("|", $pathParts));
    }

    return $base . "?" . $qs . "&" . implode("&", $extras);
}

/* ---------- Read itinerary ---------- */

$stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id=? AND traveller_id=? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

/* ---------- Read items + join cultural_places ---------- */

$stmt = $conn->prepare("
  SELECT
    ii.day_no,
    ii.sequence_no,
    ii.item_type,
    ii.item_title,
    ii.estimated_cost,
    ii.notes,
    cp.latitude,
    cp.longitude,
    cp.image_url,
    cp.description AS place_description,
    cp.address AS place_address
  FROM itinerary_items ii
  LEFT JOIN cultural_places cp
    ON cp.name = ii.item_title
   AND cp.category = ii.item_type
  WHERE ii.itinerary_id=?
  ORDER BY ii.day_no, ii.sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

/* ---------- Group by day ---------- */

$days = []; // day_no => items
while ($r = $res->fetch_assoc()) {
    $d = (int)$r["day_no"];
    if (!isset($days[$d])) $days[$d] = [];
    $days[$d][] = $r;
}

/* ---------- Build HTML ---------- */

$css = "
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #0f172a; }
    h2 { margin: 0 0 6px 0; }
    h3 { margin: 14px 0 8px 0; }
    .meta { color: #475569; margin: 0 0 8px 0; }
    .hr { border-top: 1px solid #e2e8f0; margin: 10px 0; }
    .map { margin: 8px 0 10px 0; }
    .map img { width: 100%; max-width: 640px; border: 1px solid #e2e8f0; border-radius: 10px; }
    table.list { width: 100%; border-collapse: collapse; margin: 6px 0 0 0; }
    table.list th, table.list td { border: 1px solid #e2e8f0; padding: 6px 8px; vertical-align: top; }
    table.list th { background: #f8fafc; text-align: left; }
    .page-break { page-break-before: always; }
    .place-card { margin: 12px 0 14px 0; }
    .place-title { font-size: 14px; font-weight: 800; margin: 0 0 8px 0; }
    .place-img { margin: 8px 0 10px 0; }
    .place-img img { width: 100%; max-width: 480px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .no-image { width: 480px; max-width: 100%; height: 220px; border: 1px dashed #cbd5e1; border-radius: 10px;
                display: flex; align-items: center; justify-content: center; color: #64748b; font-weight: 800; }
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv td { padding: 0; vertical-align: top; }
    td.k { width: 90px; font-weight: 800; padding-right: 8px; }
    td.v { text-align: justify; line-height: 1.45; }
  </style>
";

$html = $css;
$html .= "<h2>" . esc($it["title"]) . "</h2>";
$html .= "<p class='meta'>Total Days: " . (int)$it["total_days"] . " | Total Cost (RM): " . number_format((float)$it["total_estimated_cost"], 2) . "</p>";
$html .= "<div class='hr'></div>";

/* ---------- Part 1: Day list + map ---------- */

foreach ($days as $dayNo => $items) {
    $html .= "<h3>Day " . (int)$dayNo . "</h3>";

    // build points for map (A->B->C...)
    $points = [];
    $letters = range("A", "Z");
    $idx = 0;

    foreach ($items as $itx) {
        $lat = $itx["latitude"];
        $lng = $itx["longitude"];
        if ($lat === null || $lng === null || $lat === "" || $lng === "") continue;
        if (!is_numeric($lat) || !is_numeric($lng)) continue;

        $points[] = [
            "label" => $letters[$idx] ?? "X",
            "lat" => (float)$lat,
            "lng" => (float)$lng,
            "name" => $itx["item_title"]
        ];
        $idx++;
    }

    if (!empty($points)) {
        $mapUrl = build_static_map_url($points);
        $html .= "<div class='map'><img src='" . esc($mapUrl) . "' alt='Map'></div>";

        // route line text: A: Place -> B: Place -> C: Place
        $routeParts = [];
        foreach ($points as $p) {
            $routeParts[] = esc($p["label"]) . ": " . esc($p["name"]);
        }
        $html .= "<p class='meta'>Route: " . implode(" → ", $routeParts) . "</p>";
    } else {
        $html .= "<p class='meta'>Map not available (missing latitude/longitude).</p>";
    }

    // list table
    $html .= "<table class='list'>
    <thead>
      <tr>
        <th style='width:55px;'>No.</th>
        <th>Place</th>
        <th style='width:90px;'>Type</th>
        <th style='width:90px;'>Cost (RM)</th>
      </tr>
    </thead><tbody>";

    $n = 1;
    foreach ($items as $itx) {
        $html .= "<tr>
      <td>" . $n . "</td>
      <td>" . esc($itx["item_title"]) . "</td>
      <td>" . esc($itx["item_type"]) . "</td>
      <td>" . number_format((float)$itx["estimated_cost"], 2) . "</td>
    </tr>";
        $n++;
    }

    $html .= "</tbody></table>";
}

/* ---------- Part 2: New page - place details (image + description) ---------- */

$html .= "<div class='page-break'></div>";
$html .= "<h2>Place Details</h2>";
$html .= "<p class='meta'>All places with image and description.</p>";
$html .= "<div class='hr'></div>";

foreach ($days as $dayNo => $items) {
    $i = 1;
    foreach ($items as $itx) {
        $titleLine = "Day " . (int)$dayNo . ". " . ordinal($i) . " Place: " . (string)$itx["item_title"];
        $html .= "<div class='place-card'>";
        $html .= "<div class='place-title'>" . esc($titleLine) . "</div>";

        // image
        $imgSrc = pdf_img_src($itx["image_url"] ?? "");
        if ($imgSrc !== "") {
            $html .= "<div class='place-img'><img src='" . esc($imgSrc) . "' alt='Place image'></div>";
        } else {
            $html .= "<div class='place-img'><div class='no-image'>No image</div></div>";
        }

        // description aligned (prevents text going under label)
        $desc = trim((string)($itx["place_description"] ?? ""));
        if ($desc === "") $desc = "-";

        $addr = trim((string)($itx["place_address"] ?? ""));
        if ($addr === "") $addr = "-";

        $html .= "
      <table class='kv'>
        <tr>
          <td class='k'>Address:</td>
          <td class='v'>" . esc($addr) . "</td>
        </tr>
        <tr>
          <td class='k'>Description:</td>
          <td class='v'>" . esc($desc) . "</td>
        </tr>
      </table>
    ";

        $html .= "</div>";
        $i++;
    }
}

/* ---------- PDF via Dompdf ---------- */

$autoload = __DIR__ . "/../vendor/autoload.php";
if (!file_exists($autoload)) {
    echo "<html><body>" . $html . "<p><i>Dompdf not installed. Use browser Print to PDF.</i></p></body></html>";
    exit;
}

require_once $autoload;

$options = new \Dompdf\Options();
$options->set("isRemoteEnabled", true); // needed for Google Static Maps + remote images

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml("<html><body>" . $html . "</body></html>");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();
$dompdf->stream("itinerary_" . $itineraryId . ".pdf", ["Attachment" => true]);
exit;
