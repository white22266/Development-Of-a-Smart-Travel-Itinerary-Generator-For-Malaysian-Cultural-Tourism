<?php
// itinerary/shared_view.php
// Public read-only itinerary view by share token.
require_once __DIR__ . "/../config/db_connect.php";
require_once __DIR__ . "/../config/i18n.php";
require_once __DIR__ . "/../services/CalendarExportService.php";

$token = trim((string)($_GET["token"] ?? ""));
if ($token === "" || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    echo t('shr.not_found');
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
    echo t('shr.not_found');
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

// Lunch and dinner rows (migration 20261116_meal_slots.sql)
$mealSlotCheck = $conn->query("SHOW COLUMNS FROM itinerary_items LIKE 'meal_slot'");
$mealSlotSelect = ($mealSlotCheck && $mealSlotCheck->num_rows > 0) ? "ii.meal_slot" : "NULL AS meal_slot";

$itemsStmt = $conn->prepare("
    SELECT ii.day_no, ii.sequence_no, ii.item_type, {$mealSlotSelect}, ii.item_title, ii.start_time, ii.end_time,
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
$waText = t('shr.wa_text', ['url' => $shareUrl]);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function time_label($s): string { return $s ? date("g:i A", strtotime($s)) : "-"; }
?>
<!doctype html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($it["title"]); ?> | <?php echo te('shr.title_suffix'); ?></title>
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
    <div style="text-align:right;margin-bottom:10px;"><?php echo i18n_switcher(); ?></div>
    <div class="public-header">
        <div>
            <h1><?php echo h($it["title"]); ?></h1>
            <p class="meta">
                <?php echo te('shr.shared_by', ['name' => $it["traveller_name"] ?? t('common.traveller_default')]); ?> |
                <?php echo te('shr.days_count', ['n' => (int)$it["total_days"]]); ?> |
                <?php echo h(t_choice('transport', $it["transport_type"] ?? "car")); ?>
            </p>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="https://wa.me/?text=<?php echo urlencode($waText); ?>" target="_blank" rel="noopener noreferrer"><?php echo te('shr.share_wa'); ?></a>
            <button class="btn btn-ghost" onclick="window.print()"><?php echo te('common.print'); ?></button>
        </div>
    </div>

    <div class="share-box">
        <div class="grid">
            <div class="card col-3"><h3><?php echo te('shr.days'); ?></h3><div class="kpi"><div class="value"><?php echo (int)$it["total_days"]; ?></div><div class="tag"><?php echo te('shr.trip'); ?></div></div></div>
            <div class="card col-3"><h3><?php echo te('shr.est_cost'); ?></h3><div class="kpi"><div class="value" style="font-size:22px;">RM <?php echo number_format((float)$it["total_estimated_cost"], 2); ?></div><div class="tag"><?php echo te('shr.total'); ?></div></div></div>
            <div class="card col-3"><h3><?php echo te('shr.start_date'); ?></h3><p class="meta"><?php echo $it["start_date"] ? h(i18n_date((string)$it["start_date"], 'short')) : "-"; ?></p></div>
            <div class="card col-3"><h3><?php echo te('shr.hotel'); ?></h3><p class="meta"><?php echo h($it["selected_hotel_name"] ?? "-"); ?></p></div>
        </div>

        <?php if (!$byDay): ?>
            <p class="meta"><?php echo te('shr.no_items'); ?></p>
        <?php endif; ?>

        <?php foreach ($byDay as $day => $items): ?>
            <div class="day-title"><?php echo te('trip.day', ['n' => (int)$day]); ?></div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th><?php echo te('shr.col_time'); ?></th>
                        <th><?php echo te('shr.col_place'); ?></th>
                        <th><?php echo te('shr.col_type'); ?></th>
                        <th><?php echo te('shr.col_cost'); ?></th>
                        <th><?php echo te('shr.col_travel'); ?></th>
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
                                <?php $mapLinks = CalendarExportService::mapLinks($item["latitude"] ?? null, $item["longitude"] ?? null, (string)$item["item_title"]); ?>
                                <?php if ($mapLinks): ?>
                                <div class="meta">
                                    <?php foreach ($mapLinks as $linkLabel => $link): ?>
                                    <a href="<?php echo h($link["href"]); ?>"<?php echo $link["external"] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo h($linkLabel === "Open in maps app" ? t('shr.map_app') : $linkLabel); ?></a>&nbsp;
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h(!empty($item["meal_slot"]) ? t_choice('itype', (string)$item["meal_slot"]) : t_choice('itype', $item["item_type"] ?? "attraction")); ?></td>
                            <td><?php echo !empty($item["meal_slot"]) ? t('shr.in_meal') : "RM " . number_format((float)$item["estimated_cost"], 2); ?></td>
                            <td>
                                <?php echo $item["travel_time_min"] !== null ? h(t('trip.min', ['n' => (int)$item["travel_time_min"]])) : "-"; ?>
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

