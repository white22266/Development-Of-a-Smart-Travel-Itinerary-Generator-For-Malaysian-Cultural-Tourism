<?php
// itinerary/trip_summary.php
// Enhanced: Full cost breakdown + hotel recommendations + budget comparison
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../config/ai_chat_text.php";
require_once "../services/CostEstimationService.php";
require_once "../services/HotelRecommendationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
$travellerId   = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId   = (int)($_GET["itinerary_id"] ?? 0);

if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

// ---- Load itinerary with preference data ----
$partyCheck = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
$partySelect = ($partyCheck && $partyCheck->num_rows > 0) ? ", tp.party_size" : "";
$stmt = $conn->prepare("
    SELECT i.*, tp.budget, tp.budget_tier, tp.transport_type, tp.traveller_type{$partySelect}, tp.interests, tp.preferred_states, tp.trip_days AS pref_trip_days
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

function extractReasonSelected(?string $notes): string
{
    $text = (string)$notes;
    $pos = strpos($text, 'Reason:');
    if ($pos === false) return '';
    return trim(substr($text, $pos + 7));
}

// ---- Load itinerary items with place coordinates ----
// Check if district column exists in cultural_places
$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistrictCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtJoinCol = $hasDistrictCol ? ", cp.district" : "";

$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type,
           ii.item_title, ii.start_time, ii.end_time, ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.latitude, cp.longitude, cp.state{$districtJoinCol}, cp.category, cp.address
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
$selectedHotels = [];

while ($r = $res->fetch_assoc()) {
    $d           = (int)$r["day_no"];
    $byDay[$d][] = $r;
    $allItems[]  = $r;
    if (strtolower((string)($r["item_type"] ?? "")) === "hotel") {
        $selectedHotels[] = $r;
    }
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
$budgetTier    = strtolower((string)($it["budget_tier"] ?? "normal"));
$travellerType = strtolower((string)($it["traveller_type"] ?? "solo"));
$partySize     = CostEstimationService::resolvePartySize($travellerType, isset($it["party_size"]) ? (int)$it["party_size"] : null);
$originName    = trim((string)($it["origin_name"] ?? ""));
$originLat     = $it["origin_lat"] ?? null;
$originLng     = $it["origin_lng"] ?? null;
$tierDefaults = CostEstimationService::budgetTierDefaults($budgetTier, $budget, $tripDays, $partySize);

$costService     = new CostEstimationService($transportType, $tripDays, $budget, $travellerType, $partySize);
$costBreakdown   = $costService->calculate($allItems, $totalDistKm, $tierDefaults["hotel"], 3, $tierDefaults["meal"]);

// ---- Hotel suggestions (from the local lodging list; prices are planning estimates) ----
$recommendedHotels = [];
$nightlyBudget = HotelRecommendationService::nightlyRoomBudget($budget, $tripDays, CostEstimationService::roomCount($partySize));

if (empty($selectedHotels) && $tripDays > 1) {
    $hotelService = new HotelRecommendationService($conn);
    if ($lastPlace && !empty($lastPlace["latitude"]) && !empty($lastPlace["longitude"])) {
        $recommendedHotels = $hotelService->recommend((float)$lastPlace["latitude"], (float)$lastPlace["longitude"], $nightlyBudget, 15.0, 5);
    }
    if (empty($recommendedHotels) && !empty($lastPlace["state"])) {
        $recommendedHotels = $hotelService->recommendByState($lastPlace["state"], $nightlyBudget, 5);
    }
}

$startDate = $it["start_date"] ?? null;
$states    = $it["preferred_states"] ?? "-";
$interests = $it["interests"] ?? "-";
$placeCount = count(array_filter($allItems, fn($item) => strtolower((string)($item["item_type"] ?? "")) !== "hotel"));
$costTotal = (float)($costBreakdown["total_cost"] ?? 0);
$budgetStatusText = t('trip.budget_none');
if ($budget > 0) {
    $budgetAmount = number_format(abs((float)$costBreakdown["budget_difference"]), 2);
    $budgetStatusText = $costBreakdown["within_budget"]
        ? t('trip.budget_within', ['amount' => $budgetAmount])
        : t('trip.budget_over', ['amount' => $budgetAmount]);
}
$hotelSummaryText = !empty($selectedHotels) ? t('trip.hotel_confirmed') : t('trip.hotel_none');
// Interests are stored as codes (culture, heritage ...): show them in the traveller's language.
$interestsText = implode(', ', array_map(
    static fn($code) => t_choice('interest', $code),
    array_filter(array_map('trim', explode(',', (string)$interests)), static fn($code) => $code !== '')
));
$missingInfo = [];
if (empty($startDate)) {
    $missingInfo[] = "Start Date";
}
if ($originName === "" || $originLat === null || $originLng === null || (float)$originLat == 0.0 || (float)$originLng == 0.0) {
    $missingInfo[] = "Starting Location";
}
if ($tripDays > 1 && empty($selectedHotels)) {
    $missingInfo[] = "Confirmed Hotel";
}
if (empty($allItems)) {
    $missingInfo[] = "Itinerary Places";
}
$missingInfoJson = json_encode(array_values($missingInfo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "[]";
$chartLabels = [];
$chartAmounts = [];
$chartLegend = [];
foreach ($costBreakdown["breakdown"] as $costItem) {
    $amount = (float)($costItem["amount"] ?? 0);
    if ($amount <= 0) continue;
    $label = i18n_cost_label((string)($costItem["label"] ?? "Cost"), $transportType);
    $percent = $costTotal > 0 ? round(($amount / $costTotal) * 100, 1) : 0;
    $chartLabels[] = $label;
    $chartAmounts[] = $amount;
    $chartLegend[] = [
        "label" => $label,
        "amount" => $amount,
        "percent" => $percent,
    ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo te('trip.title'); ?> | Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <link rel="stylesheet" href="../assets/trip_ai_chat.css?v=20261022">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .cost-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, .10);
            background: #fff;
            padding: 20px;
            margin-bottom: 16px;
        }

        .summary-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, .10);
            background: #fff;
            padding: 18px 20px;
            margin-bottom: 16px;
        }

        .summary-card p {
            margin: 8px 0 0;
            color: #475569;
            font-size: 13px;
            line-height: 1.6;
        }

        .cost-layout {
            display: grid;
            grid-template-columns: minmax(240px, 360px) 1fr;
            gap: 18px;
            align-items: stretch;
        }

        .chart-panel {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, .08);
            background: #f8fafc;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 280px;
        }

        .chart-wrap {
            position: relative;
            width: 100%;
            min-height: 230px;
        }

        .chart-legend-list {
            display: grid;
            gap: 8px;
            margin-top: 14px;
        }

        .chart-legend-item {
            display: grid;
            grid-template-columns: 14px 1fr auto;
            gap: 8px;
            align-items: start;
            padding: 8px 10px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, .08);
        }

        .chart-legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            margin-top: 3px;
        }

        .chart-legend-title {
            font-size: 12px;
            font-weight: 900;
            color: var(--navy);
            line-height: 1.25;
        }

        .chart-legend-meta {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
            line-height: 1.35;
        }

        .chart-legend-percent {
            font-size: 12px;
            font-weight: 900;
            color: #334155;
            white-space: nowrap;
        }

        .cost-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(15, 23, 42, .06);
        }

        .cost-row:last-child {
            border-bottom: none;
        }

        .cost-label {
            font-weight: 700;
            color: var(--navy);
        }

        .cost-note {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .cost-amount {
            font-weight: 900;
            font-size: 15px;
            color: var(--navy);
        }

        .cost-total {
            background: var(--navy);
            color: #fff;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }

        .budget-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
        }

        .budget-ok {
            background: rgba(34, 197, 94, .15);
            color: #166534;
            border: 1px solid rgba(34, 197, 94, .4);
        }

        .budget-over {
            background: rgba(239, 68, 68, .12);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, .4);
        }

        .hotel-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, .10);
            background: #fff;
            padding: 14px 16px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .hotel-actions {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .hotel-name {
            font-weight: 800;
            color: var(--navy);
            font-size: 14px;
        }

        .hotel-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .hotel-price {
            font-weight: 900;
            color: var(--navy);
            font-size: 14px;
            white-space: nowrap;
        }

        .stars {
            color: #f59e0b;
            font-size: 13px;
        }

        .trip-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .trip-meta-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid rgba(15, 23, 42, .08);
        }

        .trip-meta-item .label {
            font-size: 11px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .trip-meta-item .value {
            font-size: 15px;
            font-weight: 900;
            color: var(--navy);
            margin-top: 4px;
        }

        .day-header {
            font-weight: 900;
            font-size: 15px;
            color: var(--navy);
            margin-bottom: 8px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid var(--navy);
        }

        @media (max-width: 900px) {
            .cost-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span><?php echo te('nav.trips'); ?></span>
                </div>
            </div>
            <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
                <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
                <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
                <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
                <a href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
                <a href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
                <a href="../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
            </nav>
            <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>
            <div class="sidebar-footer">
                <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
                <div style="margin-top:6px;font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
            </div>
        </aside>
        <main class="content" style="padding:24px;">
            <div class="topbar">
                <div class="page-title">
                    <h1><?php echo te('trip.title'); ?></h1>
                    <p class="meta"><?php echo htmlspecialchars($it["title"]); ?></p>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo $itineraryId; ?>"><?php echo te('trip.back_map'); ?></a>
                    <a class="btn btn-primary" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>"><?php echo te('trip.export_pdf'); ?></a>
                </div>
            </div>

            <div class="summary-card">
                <h3><?php echo te('trip.overview'); ?></h3>
                <p>
                    <?php echo te('trip.overview_p1', [
                        'title' => $it["title"],
                        'days' => (int)$tripDays,
                        'stops' => $placeCount === 1 ? t('trip.stops_one') : t('trip.stops_many', ['n' => (int)$placeCount]),
                        'states' => $states ?: t('trip.states_fallback'),
                        'transport' => t_choice('transport', $transportType),
                        'start' => $startDate ? i18n_date((string)$startDate, 'short') : t('trip.start_fallback'),
                        'interests' => $interestsText !== '' ? $interestsText : t('trip.interests_fallback'),
                    ]); ?>
                </p>
                <p>
                    <?php echo te('trip.overview_p2', [
                        'total' => number_format($costTotal, 2),
                        'budget' => $budgetStatusText,
                        'hotel' => $hotelSummaryText,
                    ]); ?>
                </p>
            </div>

            <!-- TRIP METADATA -->
            <div class="trip-meta-grid">
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.duration'); ?></div>
                    <div class="value"><?php echo $tripDays > 1 ? te('trip.days_many', ['n' => $tripDays]) : te('trip.days_one'); ?></div>
                </div>
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.transport'); ?></div>
                    <div class="value"><?php echo htmlspecialchars(t_choice('transport', $transportType)); ?></div>
                </div>
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.start_date'); ?></div>
                    <div class="value"><?php echo $startDate ? htmlspecialchars(i18n_date((string)$startDate, 'short')) : '-'; ?></div>
                </div>
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.total_distance'); ?></div>
                    <div class="value"><?php echo te('trip.km', ['n' => number_format($totalDistKm, 1)]); ?></div>
                </div>
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.budget'); ?></div>
                    <div class="value">RM <?php echo number_format($budget, 0); ?></div>
                </div>
                <div class="trip-meta-item">
                    <div class="label"><?php echo te('trip.states'); ?></div>
                    <div class="value" style="font-size:12px;"><?php echo htmlspecialchars($states ?: t('trip.states_fallback')); ?></div>
                </div>
            </div>

            <section class="grid">
                <!-- COST BREAKDOWN -->
                <div class="card col-12">
                    <h3><?php echo te('trip.cost_title'); ?></h3>
                    <p class="meta"><?php echo te('trip.cost_sub'); ?></p>
                    <div class="cost-layout">
                        <div class="chart-panel">
                            <div class="chart-wrap">
                                <canvas id="costPieChart" aria-label="<?php echo te('trip.chart_aria'); ?>" role="img"></canvas>
                            </div>
                            <div class="chart-legend-list" aria-label="<?php echo te('trip.legend_aria'); ?>">
                                <?php
                                $chartColors = ['#4f46e5', '#f59e0b', '#16a34a', '#ef4444', '#0ea5e9'];
                                foreach ($chartLegend as $idx => $legendItem):
                                    $color = $chartColors[$idx % count($chartColors)];
                                ?>
                                    <div class="chart-legend-item">
                                        <span class="chart-legend-color" style="background:<?php echo htmlspecialchars($color); ?>;"></span>
                                        <div>
                                            <div class="chart-legend-title"><?php echo htmlspecialchars($legendItem["label"]); ?></div>
                                            <div class="chart-legend-meta">RM <?php echo number_format((float)$legendItem["amount"], 2); ?></div>
                                        </div>
                                        <div class="chart-legend-percent"><?php echo number_format((float)$legendItem["percent"], 1); ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cost-card">
                            <?php foreach ($costBreakdown['breakdown'] as $item): ?>
                                <div class="cost-row">
                                    <div>
                                        <div class="cost-label"><?php echo htmlspecialchars(i18n_cost_label((string)$item['label'], $transportType)); ?></div>
                                        <div class="cost-note"><?php echo htmlspecialchars(i18n_cost_phrase((string)$item['note'])); ?></div>
                                    </div>
                                    <div class="cost-amount">RM <?php echo number_format($item['amount'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <div class="cost-total">
                                <div>
                                    <div style="font-size:13px;opacity:.8;"><?php echo te('trip.cost_total'); ?></div>
                                    <div style="font-size:22px;font-weight:900;">RM <?php echo number_format($costBreakdown['total_cost'], 2); ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <?php if ($budget > 0): ?>
                                        <?php if ($costBreakdown['within_budget']): ?>
                                            <span class="budget-badge budget-ok">&#10003; <?php echo te('trip.within'); ?></span>
                                            <div style="font-size:12px;margin-top:6px;opacity:.85;"><?php echo te('trip.remaining', ['amount' => number_format(abs($costBreakdown['budget_difference']), 2)]); ?></div>
                                        <?php else: ?>
                                            <span class="budget-badge budget-over">&#9888; <?php echo te('trip.over'); ?></span>
                                            <div style="font-size:12px;margin-top:6px;opacity:.85;"><?php echo te('trip.over_by', ['amount' => number_format(abs($costBreakdown['budget_difference']), 2)]); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($selectedHotels)): ?>
                    <div class="card col-12">
                        <h3><?php echo te('trip.sel_hotel'); ?></h3>
                        <p class="meta"><?php echo te('trip.sel_hotel_sub'); ?></p>
                        <?php foreach ($selectedHotels as $hotelItem): ?>
                            <div class="hotel-card">
                                <div>
                                    <div class="hotel-name"><?php echo htmlspecialchars($hotelItem["item_title"]); ?></div>
                                    <?php if (!empty($hotelItem["notes"])): ?>
                                        <div class="hotel-meta"><?php echo htmlspecialchars($hotelItem["notes"]); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align:right;">
                                    <div class="hotel-price">RM <?php echo number_format((float)$hotelItem["estimated_cost"], 2); ?></div>
                                    <div style="font-size:11px;color:var(--muted);"><?php echo te('trip.accom_total'); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- DAY-BY-DAY -->
                <div class="card col-12">
                    <h3><?php echo te('trip.dbd'); ?></h3>
                    <p class="meta"><?php echo te('trip.dbd_sub'); ?></p>
                    <?php foreach ($byDay as $day => $items): ?>
                        <div style="margin-bottom:20px;">
                            <div class="day-header">
                                <?php echo te('trip.day', ['n' => (int)$day]); ?>
                                <?php if ($startDate): ?>
                                    &mdash; <?php echo htmlspecialchars(i18n_date($startDate . ' +' . ($day - 1) . ' days', 'short_weekday')); ?>
                                <?php endif; ?>
                            </div>
                            <div class="table-wrap mobile-card-table-wrap">
                                <table class="mobile-card-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th><?php echo te('trip.col_time'); ?></th>
                                            <th><?php echo te('trip.col_place'); ?></th>
                                            <th><?php echo te('trip.col_type'); ?></th>
                                            <th><?php echo te('trip.col_cost'); ?></th>
                                            <th><?php echo te('trip.col_dist'); ?></th>
                                            <th><?php echo te('trip.col_travel'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $firstTravelItem = null;
                                        foreach ($items as $candidate) {
                                            if (strtolower((string)($candidate["item_type"] ?? "")) !== "hotel") {
                                                $firstTravelItem = $candidate;
                                                break;
                                            }
                                        }
                                        $originDistance = (float)($firstTravelItem["distance_km"] ?? 0);
                                        $originTravelMin = (int)($firstTravelItem["travel_time_min"] ?? 0);
                                        $showOriginRow = $firstTravelItem
                                            && !empty($firstTravelItem["start_time"])
                                            && ((int)$day === 1 || $originDistance > 0 || $originTravelMin > 0);
                                        $displayNo = 0;
                                        $dayTotal = 0.0;
                                        ?>
                                        <?php if ($showOriginRow): ?>
                                            <tr style="background:#f8fafc;">
                                                <td data-label="#"><strong><?php echo te('trip.start'); ?></strong></td>
                                                <td data-label="<?php echo te('trip.col_time'); ?>">
                                                    <?php
                                                    if ($originTravelMin > 0) {
                                                        $departureTs = strtotime($firstTravelItem["start_time"]) - ($originTravelMin * 60);
                                                        echo $departureTs ? date("g:i A", $departureTs) : "&mdash;";
                                                    } else {
                                                        echo htmlspecialchars(t('trip.before', ['time' => date("g:i A", strtotime($firstTravelItem["start_time"]))]));
                                                    }
                                                    ?>
                                                </td>
                                                <td data-label="<?php echo te('trip.col_place'); ?>">
                                                    <strong>
                                                        <?php
                                                        if ((int)$day === 1) {
                                                            echo htmlspecialchars($originName !== "" ? t('trip.depart_from', ['name' => $originName]) : t('trip.depart_start'));
                                                        } else {
                                                            echo te('trip.depart_prev');
                                                        }
                                                        ?>
                                                    </strong>
                                                    <div style="font-size:11px;color:var(--muted);">
                                                        <?php if ($originDistance > 0 || $originTravelMin > 0): ?>
                                                            <?php echo te('trip.travel_to', ['name' => $firstTravelItem["item_title"]]); ?>
                                                        <?php else: ?>
                                                            <?php echo te('trip.no_route'); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-label="<?php echo te('trip.col_type'); ?>"><span class="chip"><?php echo te('trip.origin'); ?></span></td>
                                                <td data-label="<?php echo te('trip.col_cost'); ?>">0.00</td>
                                                <td data-label="<?php echo te('trip.col_dist'); ?>"><?php echo $originDistance > 0 ? number_format($originDistance, 2) : "&mdash;"; ?></td>
                                                <td data-label="<?php echo te('trip.col_travel'); ?>"><?php echo $originTravelMin > 0 ? te('trip.min', ['n' => $originTravelMin]) : te('trip.not_recorded'); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($items as $r): $dayTotal += (float)$r["estimated_cost"]; ?>
                                            <?php
                                            $isHotel = strtolower((string)($r["item_type"] ?? "")) === "hotel";
                                            $isFirstTravelItem = $firstTravelItem && (int)$r["item_id"] === (int)$firstTravelItem["item_id"];
                                            $rowNo = $isHotel ? t('trip.stay') : (string)(++$displayNo);
                                            $typeLabel = $isHotel ? t('trip.hotel_checkin') : t_choice('itype', (string)$r["item_type"]);
                                            $distanceText = ($showOriginRow && $isFirstTravelItem)
                                                ? "&mdash;"
                                                : ($r["distance_km"] !== null ? number_format((float)$r["distance_km"], 2) : "&mdash;");
                                            $travelText = ($showOriginRow && $isFirstTravelItem)
                                                ? "&mdash;"
                                                : ($r["travel_time_min"] !== null ? htmlspecialchars(t('trip.min', ['n' => (int)$r["travel_time_min"]])) : "&mdash;");
                                            ?>
                                            <tr>
                                                <td data-label="#"><?php echo htmlspecialchars($rowNo); ?></td>
                                                <td data-label="<?php echo te('trip.col_time'); ?>">
                                                    <?php if (!empty($r["start_time"]) && !empty($r["end_time"])): ?>
                                                        <?php echo date('g:i A', strtotime($r["start_time"])); ?> - <?php echo date('g:i A', strtotime($r["end_time"])); ?>
                                                    <?php else: ?>
                                                        &mdash;
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="<?php echo te('trip.col_place'); ?>">
                                                    <strong><?php echo htmlspecialchars(i18n_item_title((string)$r["item_title"])); ?></strong>
                                                    <?php if (!empty($r["state"])): ?>
                                                        <div style="font-size:11px;color:var(--muted);">
                                                            <?php echo htmlspecialchars($r["state"]); ?>
                                                            <?php if (!empty($r["district"])): ?>
                                                                &rsaquo; <span style="color:#4338ca;"><?php echo htmlspecialchars($r["district"]); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($isHotel): ?>
                                                        <div style="font-size:11px;color:var(--muted);"><?php echo te('trip.hotel_note'); ?></div>
                                                    <?php endif; ?>
                                                    <?php $reasonSelected = extractReasonSelected($r["notes"] ?? ""); ?>
                                                    <?php if ($reasonSelected !== "" && !$isHotel): ?>
                                                        <div style="font-size:11px;color:#475569;margin-top:4px;">
                                                            <strong><?php echo te('trip.reason'); ?></strong> <?php echo htmlspecialchars(i18n_reason_text($reasonSelected)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="<?php echo te('trip.col_type'); ?>"><span class="chip"><?php echo htmlspecialchars($typeLabel); ?></span></td>
                                                <td data-label="<?php echo te('trip.col_cost'); ?>"><?php echo number_format((float)$r["estimated_cost"], 2); ?></td>
                                                <td data-label="<?php echo te('trip.col_dist'); ?>"><?php echo $distanceText; ?></td>
                                                <td data-label="<?php echo te('trip.col_travel'); ?>"><?php echo $travelText; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr style="background:#f8fafc;">
                                            <td class="mobile-empty" colspan="4" style="text-align:right;font-weight:900;"><?php echo te('trip.day_total', ['n' => (int)$day]); ?></td>
                                            <td data-label="<?php echo te('trip.day_total_label'); ?>" style="font-weight:900;">RM <?php echo number_format($dayTotal, 2); ?></td>
                                            <td class="mobile-hidden-cell" colspan="2"></td>
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
                        <h3><?php echo !empty($selectedHotels) ? te('trip.rec_other') : te('trip.rec'); ?></h3>
                        <p class="meta">
                            <?php echo te('trip.rec_sub'); ?>
                            <?php if ($nightlyBudget > 0): ?><?php echo te('trip.rec_budget', ['amount' => number_format($nightlyBudget, 0)]); ?><?php endif; ?>
                        </p>
                        <?php foreach ($recommendedHotels as $hotel): ?>
                            <div class="hotel-card">
                                <div>
                                    <div class="hotel-name"><?php echo htmlspecialchars($hotel["name"]); ?></div>
                                    <div class="hotel-meta">
                                        <?php echo htmlspecialchars(t_choice('hotelkind', $hotel["kind"] ?? '') . ($hotel["stars"] !== null ? ", " . t('trip.star', ['n' => (float)$hotel["stars"]]) : "")); ?>
                                        <?php if ($hotel["address"] !== ""): ?>&nbsp;&middot;&nbsp; <?php echo htmlspecialchars($hotel["address"]); ?><?php endif; ?>
                                        <?php if ($hotel["distance_km"] !== null): ?>&nbsp;&middot;&nbsp; <?php echo te('trip.km_away', ['n' => number_format((float)$hotel["distance_km"], 1)]); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="hotel-price">RM <?php echo number_format((float)$hotel["price_per_night"], 0); ?></div>
                                    <div style="font-size:11px;color:var(--muted);"><?php echo te('trip.est_night'); ?></div>
                                    <div class="hotel-actions">
                                        <button type="button" class="btn btn-primary" style="font-size:11px;padding:5px 9px;" onclick="confirmHotel(<?php echo (int)$hotel["hotel_id"]; ?>, this)"><?php echo te('trip.confirm_hotel'); ?></button>
                                        <?php if (!empty($hotel["price_check_url"])): ?>
                                            <a href="<?php echo htmlspecialchars($hotel["price_check_url"]); ?>"
                                                target="_blank" rel="noopener noreferrer" title="<?php echo te('trip.check_price_title'); ?>"
                                                class="btn btn-ghost" style="font-size:11px;padding:5px 9px;display:inline-block;">
                                                <?php echo te('trip.check_price'); ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php $mapUrl = $hotel["map_url"] ?? "";
                                        if ($mapUrl !== ""): ?>
                                            <a href="<?php echo htmlspecialchars($mapUrl); ?>"
                                                target="_blank" rel="noopener noreferrer"
                                                class="btn btn-ghost" style="font-size:11px;padding:5px 9px;display:inline-block;">
                                                <?php echo te('trip.map'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <button type="button" class="ai-chat-fab" onclick="toggleAiChat()"><?php echo te('trip.ai_fab'); ?></button>
    <div class="ai-chat-panel" id="aiChatPanel" aria-live="polite" data-itinerary-id="<?php echo (int)$itineraryId; ?>" data-missing-info="<?php echo htmlspecialchars($missingInfoJson, ENT_QUOTES); ?>">
        <div class="ai-chat-header">
            <div>
                <div class="ai-chat-title"><?php echo te('trip.ai_title'); ?></div>
                <div class="ai-chat-subtitle"><?php echo te('trip.ai_sub'); ?></div>
            </div>
            <button type="button" class="ai-chat-close" onclick="toggleAiChat()" aria-label="<?php echo te('trip.ai_close'); ?>">&times;</button>
        </div>
        <div class="ai-chat-body" id="aiChatBody">
            <div class="ai-msg bot" id="aiInitialMessage"><?php echo te('trip.ai_hello'); ?></div>
        </div>
        <form class="ai-chat-form" onsubmit="sendAiMessage(event)">
            <input id="aiChatInput" type="text" maxlength="700" autocomplete="off" placeholder="<?php echo te('trip.ai_placeholder'); ?>">
            <button type="submit"><?php echo te('trip.ai_send'); ?></button>
        </form>
    </div>
    <script>
    // What assets/trip_ai_chat.js needs: the chat's language with its card labels, and its own texts.
    const AI_CHAT_BOOT = <?php echo json_encode(['lang' => i18n_lang(), 'ui' => ai_ui_labels(i18n_lang())], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}'; ?>;
    const AI_CHAT_T = <?php echo i18n_js(['iv.ai_missing', 'iv.ai_sep']); ?>;
    </script>
    <script src="../assets/trip_ai_chat.js?v=20261118a"></script>
    <script>
        const ITINERARY_ID = <?php echo (int)$itineraryId; ?>;
        const COST_CHART_LABELS = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const COST_CHART_VALUES = <?php echo json_encode($chartAmounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const COST_CHART_TOTAL = COST_CHART_VALUES.reduce((sum, value) => sum + Number(value || 0), 0);
        document.addEventListener('DOMContentLoaded', function() {
            initCostPieChart();
        });

        function initCostPieChart() {
            const canvas = document.getElementById('costPieChart');
            if (!canvas || !window.Chart || COST_CHART_VALUES.length === 0) return;
            const percentLabelPlugin = {
                id: 'percentLabelPlugin',
                afterDatasetsDraw(chart) {
                    const {
                        ctx
                    } = chart;
                    const meta = chart.getDatasetMeta(0);
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.font = '800 12px system-ui, -apple-system, Segoe UI, Arial, sans-serif';
                    meta.data.forEach((arc, index) => {
                        const value = Number(chart.data.datasets[0].data[index] || 0);
                        if (!value || !COST_CHART_TOTAL) return;
                        const percent = (value / COST_CHART_TOTAL) * 100;
                        if (percent < 4) return;
                        const pos = arc.tooltipPosition();
                        ctx.lineWidth = 3;
                        ctx.strokeStyle = 'rgba(15,23,42,0.42)';
                        ctx.fillStyle = '#ffffff';
                        const label = percent.toFixed(1) + '%';
                        ctx.strokeText(label, pos.x, pos.y);
                        ctx.fillText(label, pos.x, pos.y);
                    });
                    ctx.restore();
                }
            };
            new Chart(canvas, {
                type: 'pie',
                data: {
                    labels: COST_CHART_LABELS,
                    datasets: [{
                        data: COST_CHART_VALUES,
                        backgroundColor: ['#4f46e5', '#f59e0b', '#16a34a', '#ef4444', '#0ea5e9'],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const value = Number(ctx.raw || 0);
                                    const percent = COST_CHART_TOTAL > 0 ? (value / COST_CHART_TOTAL) * 100 : 0;
                                    return ctx.label + ': RM ' + value.toFixed(2) + ' (' + percent.toFixed(1) + '%)';
                                }
                            }
                        }
                    }
                },
                plugins: [percentLabelPlugin]
            });
        }

    </script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>

