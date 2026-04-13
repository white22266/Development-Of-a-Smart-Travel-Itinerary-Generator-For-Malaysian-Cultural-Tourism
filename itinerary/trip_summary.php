<?php
// itinerary/trip_summary.php
// Enhanced: Full cost breakdown + hotel recommendations + budget comparison
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$travellerId   = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId   = (int)($_GET["itinerary_id"] ?? 0);

if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

// ---- Load itinerary with preference data ----
$stmt = $conn->prepare("
    SELECT i.*, tp.budget, tp.transport_type, tp.interests, tp.preferred_states, tp.trip_days AS pref_trip_days
    FROM itineraries i
    LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
    WHERE i.itinerary_id = ? AND i.traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$it) {
    header("Location: my_itineraries.php");
    exit;
}

// ---- Load itinerary items with place coordinates ----
$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.latitude, cp.longitude, cp.state, cp.category, cp.address
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$allItems  = [];
$byDay     = [];
$lastPlace = null;

while ($r = $res->fetch_assoc()) {
    $d           = (int)$r["day_no"];
    $byDay[$d][] = $r;
    $allItems[]  = $r;
    if (!empty($r["latitude"]) && !empty($r["longitude"])) {
        $lastPlace = $r;
    }
}

// ---- Calculate total distance ----
$totalDistKm = 0.0;
foreach ($allItems as $item) {
    $totalDistKm += (float)($item["distance_km"] ?? 0);
}

// ---- Cost estimation ----
$tripDays      = (int)($it["total_days"] ?? 1);
$budget        = (float)($it["budget"] ?? 0);
$transportType = (string)($it["transport_type"] ?? "car");

$costService     = new CostEstimationService($transportType, $tripDays, $budget);
$costBreakdown   = $costService->calculate($allItems, $totalDistKm);

// ---- Hotel recommendations ----
$hotelService      = new HotelRecommendationService($conn);
$recommendedHotels = [];
$hotelBudget       = ($budget > 0) ? ($budget * 0.3) : 200.0;
$nightlyBudget     = $hotelBudget / max(1, $tripDays - 1);

if ($lastPlace && !empty($lastPlace["latitude"]) && !empty($lastPlace["longitude"])) {
    $recommendedHotels = $hotelService->recommend(
        (float)$lastPlace["latitude"],
        (float)$lastPlace["longitude"],
        $nightlyBudget,
        25.0,
        5
    );
}
if (empty($recommendedHotels) && !empty($lastPlace["state"])) {
    $recommendedHotels = $hotelService->recommendByState($lastPlace["state"], $nightlyBudget, 5);
}

$startDate = $it["start_date"] ?? null;

$startDate = $it["start_date"] ?? null;
$states    = $it["preferred_states"] ?? "-";
$interests = $it["interests"] ?? "-";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Trip Summary | Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .cost-card { border-radius:16px; border:1px solid rgba(15,23,42,.10); background:#fff; padding:20px; margin-bottom:16px; }
        .cost-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid rgba(15,23,42,.06); }
        .cost-row:last-child { border-bottom:none; }
        .cost-label { font-weight:700; color:var(--navy); }
        .cost-note { font-size:12px; color:var(--muted); margin-top:2px; }
        .cost-amount { font-weight:900; font-size:15px; color:var(--navy); }
        .cost-total { background:var(--navy); color:#fff; border-radius:12px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
        .budget-badge { display:inline-block; padding:4px 12px; border-radius:999px; font-size:13px; font-weight:800; }
        .budget-ok { background:rgba(34,197,94,.15); color:#166534; border:1px solid rgba(34,197,94,.4); }
        .budget-over { background:rgba(239,68,68,.12); color:#991b1b; border:1px solid rgba(239,68,68,.4); }
        .hotel-card { border-radius:12px; border:1px solid rgba(15,23,42,.10); background:#fff; padding:14px 16px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .hotel-name { font-weight:800; color:var(--navy); font-size:14px; }
        .hotel-meta { font-size:12px; color:var(--muted); margin-top:3px; }
        .hotel-price { font-weight:900; color:var(--navy); font-size:14px; white-space:nowrap; }
        .stars { color:#f59e0b; font-size:13px; }
        .trip-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:16px; }
        .trip-meta-item { background:#f8fafc; border-radius:12px; padding:12px 14px; border:1px solid rgba(15,23,42,.08); }
        .trip-meta-item .label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
        .trip-meta-item .value { font-size:15px; font-weight:900; color:var(--navy); margin-top:4px; }
        .day-header { font-weight:900; font-size:15px; color:var(--navy); margin-bottom:8px; padding:8px 12px; background:#f8fafc; border-radius:8px; border-left:4px solid var(--navy); }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">ST</div>
            <div class="brand-title">
                <strong>Smart Travel Itinerary Generator</strong>
                <span>Cost Estimation &amp; Trip Summary</span>
            </div>
        </div>
        <nav class="nav" aria-label="Sidebar Navigation">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
            <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
            <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
            <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
            <a href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
            <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="small">Logged in as:</div>
            <div style="margin-top:6px;font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
            <div class="chip">Role: Traveller</div>
        </div>
    </aside>
    <main class="content" style="padding:24px;">
        <div class="topbar">
            <div class="page-title">
                <h1>Trip Summary</h1>
                <p class="meta"><?php echo htmlspecialchars($it["title"]); ?></p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo $itineraryId; ?>">Back to Map</a>
                <a class="btn btn-primary" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>">Export PDF</a>
            </div>
        </div>

        <!-- TRIP METADATA -->
        <div class="trip-meta-grid">
            <div class="trip-meta-item">
                <div class="label">Trip Duration</div>
                <div class="value"><?php echo $tripDays; ?> Day<?php echo $tripDays > 1 ? 's' : ''; ?></div>
            </div>
            <div class="trip-meta-item">
                <div class="label">Transport</div>
                <div class="value"><?php echo ucfirst(str_replace('_',' ',$transportType)); ?></div>
            </div>
            <div class="trip-meta-item">
                <div class="label">Start Date</div>
                <div class="value"><?php echo $startDate ? date('d M Y',strtotime($startDate)) : '-'; ?></div>
            </div>
            <div class="trip-meta-item">
                <div class="label">Total Distance</div>
                <div class="value"><?php echo number_format($totalDistKm,1); ?> km</div>
            </div>
            <div class="trip-meta-item">
                <div class="label">Budget</div>
                <div class="value">RM <?php echo number_format($budget,0); ?></div>
            </div>
            <div class="trip-meta-item">
                <div class="label">States</div>
                <div class="value" style="font-size:12px;"><?php echo htmlspecialchars($states ?: 'Malaysia'); ?></div>
            </div>
        </div>

        <section class="grid">
            <!-- COST BREAKDOWN -->
            <div class="card col-12">
                <h3>Cost Breakdown</h3>
                <p class="meta">Estimated total trip cost based on itinerary items, transport, accommodation, and meals.</p>
                <div class="cost-card">
                    <?php foreach ($costBreakdown['breakdown'] as $item): ?>
                    <div class="cost-row">
                        <div>
                            <div class="cost-label"><?php echo htmlspecialchars($item['label']); ?></div>
                            <div class="cost-note"><?php echo htmlspecialchars($item['note']); ?></div>
                        </div>
                        <div class="cost-amount">RM <?php echo number_format($item['amount'],2); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="cost-total">
                        <div>
                            <div style="font-size:13px;opacity:.8;">Total Estimated Cost</div>
                            <div style="font-size:22px;font-weight:900;">RM <?php echo number_format($costBreakdown['total_cost'],2); ?></div>
                        </div>
                        <div style="text-align:right;">
                            <?php if ($budget > 0): ?>
                                <?php if ($costBreakdown['within_budget']): ?>
                                    <span class="budget-badge budget-ok">&#10003; Within Budget</span>
                                    <div style="font-size:12px;margin-top:6px;opacity:.85;">RM <?php echo number_format(abs($costBreakdown['budget_difference']),2); ?> remaining</div>
                                <?php else: ?>
                                    <span class="budget-badge budget-over">&#9888; Over Budget</span>
                                    <div style="font-size:12px;margin-top:6px;opacity:.85;">RM <?php echo number_format(abs($costBreakdown['budget_difference']),2); ?> over budget</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DAY-BY-DAY -->
            <div class="card col-12">
                <h3>Day-by-Day Itinerary</h3>
                <p class="meta">Detailed schedule with attraction costs, travel distance, and time per segment.</p>
                <?php foreach ($byDay as $day => $items): ?>
                <div style="margin-bottom:20px;">
                    <div class="day-header">
                        Day <?php echo (int)$day; ?>
                        <?php if ($startDate): ?>
                            &mdash; <?php echo date('D, d M Y', strtotime($startDate . ' +' . ($day-1) . ' days')); ?>
                        <?php endif; ?>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>#</th><th>Place</th><th>Type</th><th>Cost (RM)</th><th>Distance (km)</th><th>Time (min)</th></tr></thead>
                            <tbody>
                                <?php $dayTotal = 0.0; foreach ($items as $r): $dayTotal += (float)$r["estimated_cost"]; ?>
                                <tr>
                                    <td><?php echo (int)$r["sequence_no"]; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r["item_title"]); ?></strong>
                                        <?php if (!empty($r["state"])): ?><div style="font-size:11px;color:var(--muted);"><?php echo htmlspecialchars($r["state"]); ?></div><?php endif; ?>
                                    </td>
                                    <td><span class="chip"><?php echo htmlspecialchars(ucfirst($r["item_type"])); ?></span></td>
                                    <td><?php echo number_format((float)$r["estimated_cost"],2); ?></td>
                                    <td><?php echo $r["distance_km"] !== null ? number_format((float)$r["distance_km"],2) : "&mdash;"; ?></td>
                                    <td><?php echo $r["travel_time_min"] !== null ? (int)$r["travel_time_min"]." min" : "&mdash;"; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="background:#f8fafc;">
                                    <td colspan="3" style="text-align:right;font-weight:900;">Day <?php echo (int)$day; ?> Attraction Total:</td>
                                    <td style="font-weight:900;">RM <?php echo number_format($dayTotal,2); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- HOTEL RECOMMENDATIONS -->
            <?php if (!empty($recommendedHotels)): ?>
            <div class="card col-12">
                <h3>Recommended Hotels</h3>
                <p class="meta">
                    Nearby hotels based on your last itinerary location.
                    <?php if ($budget > 0): ?>Filtered for budget up to RM <?php echo number_format($nightlyBudget,0); ?>/night.<?php endif; ?>
                </p>
                <?php foreach ($recommendedHotels as $hotel): ?>
                <div class="hotel-card">
                    <div>
                        <div class="hotel-name"><?php echo htmlspecialchars($hotel["name"]); ?></div>
                        <div class="hotel-meta">
                            <?php echo htmlspecialchars($hotel["state"]); ?>
                            <?php if (!empty($hotel["district"])): ?>&mdash; <?php echo htmlspecialchars($hotel["district"]); ?><?php endif; ?>
                            <?php if (!empty($hotel["distance_km"])): ?>&nbsp;&middot;&nbsp; <?php echo number_format((float)$hotel["distance_km"],1); ?> km away<?php endif; ?>
                        </div>
                        <div class="stars" style="margin-top:4px;">
                            <?php
                            $rating = (float)($hotel["rating"] ?? 0);
                            $full   = (int)floor($rating);
                            $half   = ($rating - $full) >= 0.5 ? 1 : 0;
                            echo str_repeat('&#9733;', $full) . ($half ? '&frac12;' : '') . str_repeat('&#9734;', 5-$full-$half);
                            echo " <span style='color:var(--muted);font-size:11px;'>(".number_format($rating,1).")</span>";
                            ?>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div class="hotel-price">RM <?php echo number_format((float)$hotel["price_per_night"],0); ?></div>
                        <div style="font-size:11px;color:var(--muted);">per night</div>
                        <?php $lat=$hotel["latitude"]??""; $lng=$hotel["longitude"]??""; if ($lat && $lng): ?>
                        <a href="https://www.google.com/maps?q=<?php echo urlencode($lat.','.$lng); ?>"
                           target="_blank" rel="noopener noreferrer"
                           class="btn btn-ghost" style="font-size:11px;padding:4px 8px;margin-top:6px;display:inline-block;">
                            View on Map
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <div class="actions" style="justify-content:flex-start;padding-left:0;margin-top:8px;">
            <a class="btn btn-primary" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>">Export PDF</a>
            <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo $itineraryId; ?>">Back to Map View</a>
        </div>
    </main>
</div>
</body>
</html>
