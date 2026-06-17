<?php
// itinerary/shared_view.php
// Public read-only itinerary view by share token.
require_once __DIR__ . "/../config/db_connect.php";

$token = trim((string)($_GET["token"] ?? ""));
if ($token === "" || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    echo "Shared itinerary not found.";
    exit;
}

$stmt = $conn->prepare("
    SELECT i.*, t.full_name AS traveller_name, tp.transport_type, tp.budget
    FROM shared_itineraries s
    JOIN itineraries i ON i.itinerary_id = s.itinerary_id
    LEFT JOIN travellers t ON t.traveller_id = i.traveller_id
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE s.share_token = ? AND s.is_active = 1
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    http_response_code(404);
    echo "Shared itinerary not found.";
    exit;
}

$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrictCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtJoinCol = $hasDistrictCol ? ", cp.district" : "";

$itemLatCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_latitude'");
$itemLngCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'item_longitude'");
$hasItemCoords = ($itemLatCheck && $itemLatCheck->num_rows > 0) && ($itemLngCheck && $itemLngCheck->num_rows > 0);
$coordSelect = $hasItemCoords
    ? "COALESCE(ii.item_latitude, cp.latitude) AS latitude, COALESCE(ii.item_longitude, cp.longitude) AS longitude"
    : "cp.latitude, cp.longitude";

$itemsStmt = $conn->prepare("
    SELECT ii.day_no, ii.sequence_no, ii.item_type, ii.item_title, ii.start_time, ii.end_time,
           ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           {$coordSelect}, cp.state{$districtJoinCol}, cp.category, cp.address, cp.opening_hours
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
");
$itineraryId = (int)$it["itinerary_id"];
$itemsStmt->bind_param("i", $itineraryId);
$itemsStmt->execute();
$res = $itemsStmt->get_result();
$itemsStmt->close();

$byDay = [];
$total = 0.0;
while ($row = $res->fetch_assoc()) {
    $day = (int)$row["day_no"];
    $byDay[$day][] = $row;
    $total += (float)($row["estimated_cost"] ?? 0);
}

$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$requestUri = $_SERVER["REQUEST_URI"] ?? ("/itinerary/shared_view.php?token=" . urlencode($token));
$shareUrl = $scheme . "://" . ($_SERVER["HTTP_HOST"] ?? "localhost") . $requestUri;
$waText = "Check my Malaysia cultural travel itinerary: " . $shareUrl;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function time_label($s): string { return $s ? date("g:i A", strtotime($s)) : "-"; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($it["title"]); ?> | Shared Itinerary</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <style>
        body { background:#f8fafc; }
        .public-wrap { max-width:1050px; margin:0 auto; padding:24px; }
        .public-header { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap; margin-bottom:18px; }
        .share-box { background:#fff; border:1px solid rgba(15,23,42,.1); border-radius:14px; padding:16px; }
        .day-title { margin:18px 0 8px; font-weight:900; color:#0f172a; }
    </style>
</head>
<body>
<div class="public-wrap">
    <div class="public-header">
        <div>
            <h1><?php echo h($it["title"]); ?></h1>
            <p class="meta">
                Shared by <?php echo h($it["traveller_name"] ?? "Traveller"); ?> |
                <?php echo (int)$it["total_days"]; ?> day(s) |
                <?php echo h(str_replace("_", " ", $it["transport_type"] ?? "car")); ?>
            </p>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="https://wa.me/?text=<?php echo urlencode($waText); ?>" target="_blank" rel="noopener noreferrer">Share WhatsApp</a>
            <button class="btn btn-ghost" onclick="window.print()">Print</button>
        </div>
    </div>

    <div class="share-box">
        <div class="grid">
            <div class="card col-3"><h3>Days</h3><div class="kpi"><div class="value"><?php echo (int)$it["total_days"]; ?></div><div class="tag">Trip</div></div></div>
            <div class="card col-3"><h3>Estimated Cost</h3><div class="kpi"><div class="value" style="font-size:22px;">RM <?php echo number_format((float)$it["total_estimated_cost"], 2); ?></div><div class="tag">Total</div></div></div>
            <div class="card col-3"><h3>Start Date</h3><p class="meta"><?php echo $it["start_date"] ? date("d M Y", strtotime($it["start_date"])) : "-"; ?></p></div>
            <div class="card col-3"><h3>Hotel</h3><p class="meta"><?php echo h($it["selected_hotel_name"] ?? "-"); ?></p></div>
        </div>

        <?php if (!$byDay): ?>
            <p class="meta">No itinerary items available.</p>
        <?php endif; ?>

        <?php foreach ($byDay as $day => $items): ?>
            <div class="day-title">Day <?php echo (int)$day; ?></div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Time</th>
                        <th>Place</th>
                        <th>Type</th>
                        <th>Cost</th>
                        <th>Travel</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo (int)$item["sequence_no"]; ?></td>
                            <td><?php echo h(time_label($item["start_time"]) . " - " . time_label($item["end_time"])); ?></td>
                            <td>
                                <strong><?php echo h($item["item_title"]); ?></strong>
                                <div class="meta">
                                    <?php echo h(trim(($item["district"] ?? "") . (($item["district"] ?? "") ? ", " : "") . ($item["state"] ?? ""))); ?>
                                    <?php if (!empty($item["opening_hours"])): ?> | <?php echo h($item["opening_hours"]); ?><?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo h(ucfirst($item["item_type"] ?? "attraction")); ?></td>
                            <td>RM <?php echo number_format((float)$item["estimated_cost"], 2); ?></td>
                            <td>
                                <?php echo $item["travel_time_min"] !== null ? (int)$item["travel_time_min"] . " min" : "-"; ?>
                                <?php if ($item["distance_km"] !== null): ?> / <?php echo number_format((float)$item["distance_km"], 1); ?> km<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>
</html>

