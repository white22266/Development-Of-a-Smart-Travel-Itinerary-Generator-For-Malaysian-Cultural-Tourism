<?php
// itinerary/itinerary_review.php
// Post-generation review: users accept/remove/preview replacements, select hotel, then confirm changes.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/HotelRecommendationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$travellerId   = (int)($_SESSION["traveller_id"] ?? 0);

$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
    header("Location: my_itineraries.php");
    exit;
}

// ---- Load itinerary header + preference ----
$stmt = $conn->prepare("
    SELECT i.*, tp.transport_type, tp.budget, tp.interests, tp.preferred_states
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

$transportType = $it["transport_type"] ?? "car";
$budget        = (float)($it["budget"] ?? 0);
$interests     = strtolower((string)($it["interests"] ?? ""));
$preferredStates = (string)($it["preferred_states"] ?? "");

// ---- Load all itinerary items with place details ----
$stmt = $conn->prepare("
    SELECT ii.item_id, ii.day_no, ii.sequence_no, ii.item_type, ii.place_id,
           ii.item_title, ii.estimated_cost, ii.distance_km, ii.travel_time_min, ii.notes,
           cp.latitude, cp.longitude, cp.address, cp.category, cp.state, cp.district,
           cp.opening_hours, cp.description, cp.rating
    FROM itinerary_items ii
    LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
    WHERE ii.itinerary_id = ?
    ORDER BY ii.day_no, ii.sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$days = [];
while ($r = $res->fetch_assoc()) {
    $days[(int)$r["day_no"]][] = $r;
}
$totalDays = (int)$it["total_days"];

// ---- Compute match score for each place ----
// Score = W_rating * (rating/5) + W_budget * budget_fit + W_category * category_match
// Weights: Rating 30%, Budget 30%, Category 40%
$interestArr = array_filter(array_map('trim', explode(',', $interests)));

function computeMatchScore(array $item, array $interests, float $budget): array
{
    $W_RATING   = 0.30;
    $W_BUDGET   = 0.30;
    $W_CATEGORY = 0.40;

    $category = strtolower(trim((string)($item["category"] ?? $item["item_type"] ?? "")));
    $cost     = (float)($item["estimated_cost"] ?? 0);
    $rating   = (float)($item["rating"] ?? 0);

    // Category match
    $catScore = in_array($category, $interests, true) ? 1.0 : 0.3;

    // Budget fit: free = 1.0, within budget = scale, over budget = 0
    $budgetScore = 1.0;
    if ($budget > 0 && $cost > 0) {
        $budgetScore = ($cost <= $budget) ? max(0.2, 1 - ($cost / $budget)) : 0.0;
    }

    // Rating (0-5 -> 0-1)
    $ratingScore = ($rating > 0) ? min(1.0, $rating / 5.0) : 0.5; // default 0.5 if no rating

    $total = $W_CATEGORY * $catScore + $W_BUDGET * $budgetScore + $W_RATING * $ratingScore;
    $pct   = (int)round($total * 100);

    // Label
    $label = match(true) {
        $pct >= 85 => ['Excellent Match', 'success'],
        $pct >= 70 => ['Good Match',      'primary'],
        $pct >= 50 => ['Fair Match',      'warning'],
        default    => ['Low Match',       'danger'],
    };

    return [
        'score'       => $total,
        'pct'         => $pct,
        'label'       => $label[0],
        'color'       => $label[1],
        'cat_score'   => (int)round($catScore * 100),
        'budget_score'=> (int)round($budgetScore * 100),
        'rating_score'=> (int)round($ratingScore * 100),
    ];
}

function extractReasonSelected(?string $notes): string
{
    $text = (string)$notes;
    $pos = strpos($text, 'Reason:');
    if ($pos === false) return '';
    return trim(substr($text, $pos + 7));
}

// ---- Load nearby hotels per state ----
$statesInItinerary = [];
foreach ($days as $dayItems) {
    foreach ($dayItems as $item) {
        $st = trim((string)($item["state"] ?? ""));
        if ($st !== "") $statesInItinerary[$st] = true;
    }
}

$hotelsByState = [];
$hrs = new HotelRecommendationService($conn);
foreach (array_keys($statesInItinerary) as $st) {
    $hotelsByState[$st] = $hrs->recommendByState($st, $budget > 0 ? $budget * 0.4 : 0, 5);
}

// Compute hotel match scores
function computeHotelScore(array $hotel, float $budget): array
{
    $W_RATING   = 0.30;
    $W_PRICE    = 0.30;
    $W_DIST     = 0.40;

    $price  = (float)($hotel["price_per_night"] ?? 0);
    $rating = (float)($hotel["rating"] ?? 0);
    $dist   = (float)($hotel["distance_km"] ?? 5.0);

    $ratingScore = min(1.0, $rating / 5.0);
    $priceScore  = ($budget > 0 && $price > 0) ? max(0.0, 1 - ($price / ($budget * 0.4 ?: 300))) : 0.7;
    $distScore   = max(0.0, 1 - ($dist / 30.0)); // 30km max

    $total = $W_DIST * $distScore + $W_PRICE * $priceScore + $W_RATING * $ratingScore;
    $pct   = (int)round($total * 100);

    $label = match(true) {
        $pct >= 85 => ['Excellent', 'success'],
        $pct >= 70 => ['Good',      'primary'],
        $pct >= 50 => ['Fair',      'warning'],
        default    => ['Low',       'danger'],
    };

    return ['pct' => $pct, 'label' => $label[0], 'color' => $label[1]];
}

// Day colors
$dayColors = [
    1 => '#EF4444', 2 => '#3B82F6', 3 => '#22C55E',
    4 => '#F59E0B', 5 => '#8B5CF6', 6 => '#EC4899', 7 => '#14B8A6',
];

$startDate = $it["start_date"] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Itinerary - <?php echo htmlspecialchars($it["title"]); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        /* ===== Review page styles ===== */
        .review-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #fff;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }
        .review-header h2 { margin: 0; font-size: 20px; }
        .review-header p  { margin: 4px 0 0; opacity: .85; font-size: 13px; }

        /* ===== Place card ===== */
        .place-card {
            border: 1.5px solid rgba(15,23,42,0.10);
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 10px;
            background: #fff;
            transition: border-color .2s, box-shadow .2s;
            position: relative;
        }
        .place-card.accepted  { border-color: #22c55e; background: #f0fdf4; }
        .place-card.rejected  { border-color: #ef4444; background: #fef2f2; opacity: .65; }
        .place-card.replacing { border-color: #f59e0b; background: #fffbeb; }

        .place-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .place-title {
            font-weight: 800;
            font-size: 14px;
        }
        .place-meta {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 3px;
        }
        .place-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
            align-items: center;
        }
        .btn-accept {
            background: #22c55e;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-accept:hover { background: #16a34a; }
        .btn-reject {
            background: #ef4444;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-reject:hover { background: #dc2626; }
        .btn-replace {
            background: #f59e0b;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-replace:hover { background: #d97706; }

        /* ===== Match score bar ===== */
        .match-score-wrap {
            margin-top: 10px;
        }
        .match-bar-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .match-bar-label {
            font-size: 10.5px;
            color: #64748b;
            width: 80px;
            flex-shrink: 0;
        }
        .match-bar-track {
            flex: 1;
            height: 7px;
            background: rgba(15,23,42,0.08);
            border-radius: 999px;
            overflow: hidden;
        }
        .match-bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width .5s ease;
        }
        .match-bar-pct {
            font-size: 10.5px;
            font-weight: 800;
            width: 32px;
            text-align: right;
            flex-shrink: 0;
        }
        .match-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-primary { background: #dbeafe; color: #1d4ed8; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }

        /* ===== Score breakdown tooltip ===== */
        .score-breakdown {
            display: none;
            background: rgba(15,23,42,0.04);
            border-radius: 8px;
            padding: 8px 10px;
            margin-top: 6px;
            font-size: 11px;
        }
        .score-breakdown.visible { display: block; }

        /* ===== Day section ===== */
        .day-section {
            margin-bottom: 24px;
        }
        .day-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            border-left: 4px solid;
        }

        /* ===== Hotel section ===== */
        .hotel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .hotel-card-review {
            border: 1.5px solid rgba(15,23,42,0.10);
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
            position: relative;
        }
        .hotel-card-review:hover { border-color: #4f46e5; box-shadow: 0 2px 10px rgba(79,70,229,.12); }
        .hotel-card-review.selected { border-color: #22c55e; background: #f0fdf4; }
        .hotel-name-rv { font-weight: 800; font-size: 13px; }
        .hotel-meta-rv { font-size: 11px; color: #64748b; margin-top: 3px; }
        .hotel-price-rv { font-weight: 900; color: #4f46e5; font-size: 14px; margin-top: 6px; }
        .hotel-select-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #22c55e;
            color: #fff;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 800;
            display: none;
        }
        .hotel-card-review.selected .hotel-select-badge { display: block; }

        /* ===== Spinner ===== */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== Sticky confirm bar ===== */
        .confirm-bar {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid rgba(15,23,42,0.10);
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            z-index: 100;
            box-shadow: 0 -4px 20px rgba(15,23,42,.08);
            border-radius: 14px 14px 0 0;
            margin-top: 16px;
        }
        .confirm-stats {
            font-size: 13px;
            color: #475569;
        }
        .confirm-stats strong { color: #0f172a; }

        /* ===== Replacement result ===== */
        .replacement-result {
            margin-top: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px dashed #f59e0b;
            background: #fffbeb;
            font-size: 12.5px;
            display: none;
        }
        .replacement-result.visible { display: block; }

        /* ===== Type badge ===== */
        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
        }
        .badge-attraction { background:#dbeafe; color:#1d4ed8; }
        .badge-food       { background:#fef9c3; color:#854d0e; }
        .badge-hotel      { background:#f0fdf4; color:#166534; }
        .badge-festival   { background:#fdf4ff; color:#7e22ce; }
        .badge-museum     { background:#fff7ed; color:#c2410c; }
        .badge-heritage   { background:#fef3c7; color:#92400e; }
        .badge-culture    { background:#ede9fe; color:#5b21b6; }
        .badge-nature     { background:#dcfce7; color:#15803d; }
        .pending-change-note {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(245,158,11,.14);
            color: #92400e;
            font-size: 11px;
            font-weight: 800;
        }
        .ai-chat-fab {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 160;
            border: none;
            border-radius: 999px;
            background: #0f172a;
            color: #fff;
            padding: 12px 18px;
            font-weight: 800;
            box-shadow: 0 14px 34px rgba(15,23,42,0.22);
            cursor: pointer;
        }
        .ai-chat-panel {
            position: fixed;
            right: 22px;
            bottom: 82px;
            z-index: 160;
            width: min(390px, calc(100vw - 32px));
            max-height: min(620px, calc(100vh - 120px));
            display: none;
            flex-direction: column;
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(15,23,42,0.14);
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(15,23,42,0.24);
        }
        .ai-chat-panel.open { display: flex; }
        .ai-chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #0f172a;
            color: #fff;
        }
        .ai-chat-title { font-weight: 900; font-size: 13px; }
        .ai-chat-subtitle { font-size: 11px; color: #cbd5e1; margin-top: 2px; }
        .ai-chat-close {
            border: 0;
            background: rgba(255,255,255,0.12);
            color: #fff;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .ai-chat-body {
            min-height: 210px;
            max-height: 320px;
            overflow-y: auto;
            padding: 12px;
            background: #f8fafc;
        }
        .ai-msg {
            width: fit-content;
            max-width: 88%;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 12.5px;
            line-height: 1.45;
            padding: 9px 10px;
            border-radius: 10px;
            margin-bottom: 9px;
        }
        .ai-msg.user { margin-left: auto; background: #4f46e5; color: #fff; }
        .ai-msg.bot { margin-right: auto; background: #fff; color: #334155; border: 1px solid rgba(15,23,42,0.08); }
        .ai-chat-prompts {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            padding: 10px 12px 0;
            background: #fff;
        }
        .ai-chat-prompts button {
            border: 1px solid rgba(15,23,42,0.12);
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 6px 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }
        .ai-chat-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            background: #fff;
            border-top: 1px solid rgba(15,23,42,0.08);
        }
        .ai-chat-form input {
            flex: 1;
            min-width: 0;
            border: 1px solid rgba(15,23,42,0.14);
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 12.5px;
        }
        .ai-chat-form button {
            border: 0;
            border-radius: 10px;
            background: #4f46e5;
            color: #fff;
            padding: 9px 12px;
            font-weight: 800;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="app">

    <!-- ===== Sidebar ===== -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">ST</div>
            <div class="brand-title">
                <strong>Smart Travel Itinerary Generator</strong>
                <span>Review Itinerary</span>
            </div>
        </div>
        <nav class="nav">
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
            <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
            <div class="chip">Role: Traveller</div>
        </div>
    </aside>

    <!-- ===== Main Content ===== -->
    <main class="content" style="padding:20px 24px 100px;">

        <!-- Review header banner -->
        <div class="review-header">
            <div>
                <h2>Review Your Generated Itinerary</h2>
                <p>Your itinerary has been generated! Review each place, check the match score, and accept or replace any stop you don't like. When done, confirm to finalise.</p>
            </div>
            <div style="text-align:right;">
                <div style="font-size:22px; font-weight:900;"><?php echo $totalDays; ?> Day<?php echo $totalDays > 1 ? 's' : ''; ?></div>
                <div style="font-size:12px; opacity:.85;"><?php echo htmlspecialchars($it["title"]); ?></div>
                <button type="button" class="btn btn-primary" style="margin-top:10px;" onclick="toggleAiChat()">Open AI Chat</button>
            </div>
        </div>

        <!-- Legend -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; font-size:12px;">
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#22c55e;display:inline-block;"></span> Accepted</span>
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#ef4444;display:inline-block;"></span> Rejected</span>
            <span style="display:flex; align-items:center; gap:5px;"><span style="width:12px;height:12px;border-radius:50%;background:#f59e0b;display:inline-block;"></span> Replacing...</span>
            <span style="color:#64748b;">Match score = Category (40%) + Budget (30%) + Rating (30%)</span>
        </div>

        <!-- ===== Day-by-day place review ===== -->
        <?php foreach ($days as $d => $items):
            $col = $dayColors[$d] ?? '#6366f1';
            $dateStr = '';
            if ($startDate) {
                $ts = strtotime($startDate . ' +' . ($d - 1) . ' days');
                $dateStr = date('l, d F Y', $ts);
            }
        ?>
        <div class="day-section" id="day-section-<?php echo $d; ?>">
            <div class="day-header" style="background:<?php echo $col; ?>12; border-color:<?php echo $col; ?>;">
                <span style="font-size:20px;">Day</span>
                <div>
                    <div style="font-weight:900; color:<?php echo $col; ?>; font-size:15px;">Day <?php echo $d; ?></div>
                    <?php if ($dateStr): ?><div style="font-size:12px; color:#64748b;"><?php echo $dateStr; ?></div><?php endif; ?>
                </div>
                <div style="margin-left:auto; font-size:12px; color:#64748b;" id="day-<?php echo $d; ?>-count">
                    <?php echo count($items); ?> place<?php echo count($items) > 1 ? 's' : ''; ?>
                </div>
            </div>

            <?php foreach ($items as $idx => $item):
                $score = computeMatchScore($item, $interestArr, $budget);
                $typeClass = 'badge-' . strtolower($item['item_type'] ?? 'attraction');
                $barColor = match($score['color']) {
                    'success' => '#22c55e',
                    'primary' => '#3b82f6',
                    'warning' => '#f59e0b',
                    default   => '#ef4444',
                };
            ?>
            <div class="place-card accepted"
                 id="card-<?php echo (int)$item['item_id']; ?>"
                 data-item-id="<?php echo (int)$item['item_id']; ?>"
                 data-day="<?php echo $d; ?>"
                 data-place-id="<?php echo (int)($item['place_id'] ?? 0); ?>"
                 data-state="<?php echo htmlspecialchars($item['state'] ?? ''); ?>"
                 data-category="<?php echo htmlspecialchars($item['category'] ?? $item['item_type'] ?? ''); ?>"
                 data-status="accepted">

                <div class="place-card-top">
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span class="type-badge <?php echo htmlspecialchars($typeClass); ?>">
                                <?php echo htmlspecialchars(ucfirst($item['item_type'] ?? 'attraction')); ?>
                            </span>
                            <span class="place-title"><?php echo htmlspecialchars($item['item_title']); ?></span>
                        </div>
                        <div class="place-meta place-meta-main">
                            <?php if (!empty($item['state'])): ?>
                                Location: <?php echo htmlspecialchars($item['district'] ? $item['district'] . ', ' . $item['state'] : $item['state']); ?>
                            <?php endif; ?>
                            <?php if (!empty($item['opening_hours'])): ?>
                                &middot; Hours: <?php echo htmlspecialchars($item['opening_hours']); ?>
                            <?php endif; ?>
                            <?php if ((float)$item['estimated_cost'] > 0): ?>
                                &middot; RM <?php echo number_format((float)$item['estimated_cost'], 2); ?>
                            <?php else: ?>
                                &middot; <span style="color:#16a34a; font-weight:700;">Free</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($item['address'])): ?>
                        <div class="place-meta" style="margin-top:2px;">Address: <?php echo htmlspecialchars($item['address']); ?></div>
                        <?php endif; ?>
                        <?php $reasonSelected = extractReasonSelected($item['notes'] ?? ''); ?>
                        <?php if ($reasonSelected !== ''): ?>
                        <div class="place-meta" style="margin-top:4px;"><strong>Reason Selected:</strong> <?php echo htmlspecialchars($reasonSelected); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="place-actions">
                        <button class="btn-accept" onclick="acceptPlace(<?php echo (int)$item['item_id']; ?>)" id="btn-accept-<?php echo (int)$item['item_id']; ?>">Keep</button>
                        <button class="btn-reject" onclick="rejectPlace(<?php echo (int)$item['item_id']; ?>)" id="btn-reject-<?php echo (int)$item['item_id']; ?>">Remove</button>
                        <button class="btn-replace" onclick="replacePlace(<?php echo (int)$item['item_id']; ?>, <?php echo $d; ?>, '<?php echo addslashes(htmlspecialchars($item['state'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($item['category'] ?? $item['item_type'] ?? '')); ?>')" id="btn-replace-<?php echo (int)$item['item_id']; ?>">Replace</button>
                    </div>
                </div>

                <!-- Match score bars -->
                <div class="match-score-wrap">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                        <span class="match-badge badge-<?php echo $score['color']; ?>"><?php echo $score['label']; ?></span>
                        <span class="match-main-pct" style="font-size:13px; font-weight:900; color:<?php echo $barColor; ?>;"><?php echo $score['pct']; ?>%</span>
                        <span style="font-size:11px; color:#94a3b8;">match</span>
                        <button onclick="toggleBreakdown(<?php echo (int)$item['item_id']; ?>)"
                                style="background:none; border:none; font-size:11px; color:#6366f1; cursor:pointer; text-decoration:underline;">
                            details
                        </button>
                    </div>

                    <!-- Overall bar -->
                    <div class="match-bar-row">
                        <span class="match-bar-label">Overall</span>
                        <div class="match-bar-track">
                            <div class="match-bar-fill match-overall-fill" style="width:<?php echo $score['pct']; ?>%; background:<?php echo $barColor; ?>;"></div>
                        </div>
                        <span class="match-bar-pct match-overall-pct" style="color:<?php echo $barColor; ?>;"><?php echo $score['pct']; ?>%</span>
                    </div>

                    <!-- Breakdown (hidden by default) -->
                    <div class="score-breakdown" id="breakdown-<?php echo (int)$item['item_id']; ?>">
                        <div class="match-bar-row">
                            <span class="match-bar-label">Category (40%)</span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['cat_score']; ?>%; background:#6366f1;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['cat_score']; ?>%</span>
                        </div>
                        <div class="match-bar-row">
                            <span class="match-bar-label">Budget (30%)</span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['budget_score']; ?>%; background:#22c55e;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['budget_score']; ?>%</span>
                        </div>
                        <div class="match-bar-row">
                            <span class="match-bar-label">Rating (30%)</span>
                            <div class="match-bar-track">
                                <div class="match-bar-fill" style="width:<?php echo $score['rating_score']; ?>%; background:#f59e0b;"></div>
                            </div>
                            <span class="match-bar-pct"><?php echo $score['rating_score']; ?>%</span>
                        </div>
                        <div style="margin-top:6px; color:#64748b;">
                            Category: <strong><?php echo htmlspecialchars(ucfirst($item['category'] ?? $item['item_type'] ?? 'N/A')); ?></strong>
                            &middot; Cost: <strong>RM <?php echo number_format((float)$item['estimated_cost'], 2); ?></strong>
                            &middot; Rating: <strong><?php echo $item['rating'] > 0 ? number_format((float)$item['rating'], 1) . '/5' : 'N/A'; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Replacement result area -->
                <div class="replacement-result" id="replacement-<?php echo (int)$item['item_id']; ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <!-- ===== Hotel Selection ===== -->
        <?php if (!empty($hotelsByState)): ?>
        <div class="card" style="padding:18px; margin-bottom:20px;">
            <h3 style="margin-bottom:6px;">Select Your Hotel</h3>
            <p class="meta" style="margin-top:0; margin-bottom:14px;">Live accommodation suggestions from Google Places. Price is a planning estimate; confirm only after checking the real booking price.</p>

            <?php foreach ($hotelsByState as $state => $hotels): ?>
            <?php if (empty($hotels)) continue; ?>
            <div style="margin-bottom:18px;">
                <div style="font-weight:800; font-size:13px; margin-bottom:8px; color:#475569;">
                    Location: <?php echo htmlspecialchars($state); ?>
                </div>
                <div class="hotel-grid">
                    <?php foreach ($hotels as $h):
                        $hs = computeHotelScore($h, $budget);
                        $hBarColor = match($hs['color']) {
                            'success' => '#22c55e',
                            'primary' => '#3b82f6',
                            'warning' => '#f59e0b',
                            default   => '#ef4444',
                        };
                    ?>
                    <?php $hotelDomId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($h['google_place_id'] ?? $h['name'])); ?>
                    <div class="hotel-card-review"
                         id="hotel-card-<?php echo htmlspecialchars($hotelDomId); ?>"
                         onclick="selectHotel('<?php echo htmlspecialchars($hotelDomId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($h['google_place_id'] ?? "", ENT_QUOTES); ?>', '<?php echo addslashes(htmlspecialchars($h['name'])); ?>')">
                        <span class="hotel-select-badge">Selected</span>
                        <div class="hotel-name-rv"><?php echo htmlspecialchars($h['name']); ?></div>
                        <div class="hotel-meta-rv">
                            <?php echo htmlspecialchars($h['address'] ?? $state); ?>
                            &middot; Google rating: <?php echo number_format((float)$h['rating'], 1); ?>/5
                            <?php if (isset($h['distance_km'])): ?>
                                &middot; <?php echo number_format((float)$h['distance_km'], 1); ?> km
                            <?php endif; ?>
                        </div>
                        <div class="hotel-price-rv">Estimated RM <?php echo number_format((float)$h['price_per_night'], 0); ?> / night</div>
                        <!-- Hotel match score -->
                        <div style="margin-top:8px;">
                            <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <span class="match-badge badge-<?php echo $hs['color']; ?>"><?php echo $hs['label']; ?></span>
                                <span style="font-size:12px; font-weight:900; color:<?php echo $hBarColor; ?>;"><?php echo $hs['pct']; ?>%</span>
                            </div>
                            <div class="match-bar-track" style="height:5px;">
                                <div class="match-bar-fill" style="width:<?php echo $hs['pct']; ?>%; background:<?php echo $hBarColor; ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="confirm-bar">
            <div class="confirm-stats">
                <strong id="stat-accepted">0</strong> accepted &middot;
                <strong id="stat-rejected">0</strong> removed &middot;
                <strong id="stat-replaced">0</strong> replacement(s) pending &middot;
                Selected hotel: <strong id="stat-hotel">None</strong>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="my_itineraries.php" class="btn btn-ghost">Back to My Itineraries</a>
                <button type="button" class="btn btn-ghost" onclick="resetAll()">Reset All</button>
                <button type="button" class="btn btn-primary" id="btn-confirm" onclick="confirmReview()">Confirm &amp; View Itinerary</button>
            </div>
        </div>

    </main>


</div><!-- /app -->

<button type="button" class="ai-chat-fab" onclick="toggleAiChat()">AI Assistant</button>
<div class="ai-chat-panel" id="aiChatPanel" aria-live="polite">
    <div class="ai-chat-header">
        <div>
            <div class="ai-chat-title">AI Chatbot</div>
            <div class="ai-chat-subtitle">Chat with the local AI before confirming this itinerary.</div>
        </div>
        <button type="button" class="ai-chat-close" onclick="toggleAiChat()" aria-label="Close AI chat">&times;</button>
    </div>
    <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-msg bot">Hi, I am your local AI chatbot. Ask me about this generated itinerary, route order, budget, hotel choice, or places to replace before you confirm.</div>
    </div>
    <div class="ai-chat-prompts">
        <button type="button" onclick="askAiQuick('Act as my travel AI chatbot and review this itinerary.')">Review</button>
        <button type="button" onclick="askAiQuick('Suggest better replacements for low match places.')">Replace ideas</button>
        <button type="button" onclick="askAiQuick('Check whether this itinerary is within budget.')">Budget</button>
    </div>
    <form class="ai-chat-form" onsubmit="sendAiMessage(event)">
        <input id="aiChatInput" type="text" maxlength="700" autocomplete="off" placeholder="Type your message to the AI chatbot...">
        <button type="submit">Send</button>
    </form>
</div>

<script>
// ---- State ----
const ITINERARY_ID = <?php echo $itineraryId; ?>;
const itemStatus   = {}; // item_id -> 'accepted' | 'rejected'
const replacementMap = {}; // item_id -> selected replacement place payload, saved only on Confirm
const originalCards = {};
let selectedHotelPlaceId = '';
let selectedHotelName = '';

// Initialise all as accepted
<?php foreach ($days as $d => $items): ?>
<?php foreach ($items as $item): ?>
itemStatus[<?php echo (int)$item['item_id']; ?>] = 'accepted';
<?php endforeach; ?>
<?php endforeach; ?>

updateStats();

document.querySelectorAll('.place-card').forEach(card => {
    originalCards[card.dataset.itemId] = {
        html: card.innerHTML,
        state: card.dataset.state || '',
        category: card.dataset.category || '',
        placeId: card.dataset.placeId || '',
    };
});

// ---- Accept ----
function acceptPlace(itemId) {
    const card = document.getElementById('card-' + itemId);
    card.classList.remove('rejected', 'replacing');
    card.classList.add('accepted');
    card.dataset.status = 'accepted';
    itemStatus[itemId] = 'accepted';
    updateStats();
}

// ---- Reject ----
function rejectPlace(itemId) {
    const card = document.getElementById('card-' + itemId);
    card.classList.remove('accepted', 'replacing');
    card.classList.add('rejected');
    card.dataset.status = 'rejected';
    itemStatus[itemId] = 'rejected';
    // Hide replacement result if shown
    const rep = document.getElementById('replacement-' + itemId);
    if (rep) { rep.classList.remove('visible'); rep.innerHTML = ''; }
    updateStats();
}

// ---- Replace (AJAX) ----
async function replacePlace(itemId, day, state, category) {
    const card = document.getElementById('card-' + itemId);
    const btn  = document.getElementById('btn-replace-' + itemId);
    const rep  = document.getElementById('replacement-' + itemId);

    card.classList.add('replacing');
    card.classList.remove('accepted', 'rejected');
    card.dataset.status = 'replacing';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Finding...';
    rep.innerHTML = '';
    rep.classList.remove('visible');

    try {
        const resp = await fetch('review_replace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                itinerary_id: ITINERARY_ID,
                item_id:      itemId,
                day_no:       day,
                state:        state,
                category:     category,
            })
        });
        const data = await parseJsonResponse(resp);

        if (data.status === 'success') {
            replacementMap[itemId] = {
                place_id: data.place_id,
                title: data.new_title,
                cost: data.new_cost,
                state: data.new_state || '',
                district: data.new_district || '',
                category: data.new_category || '',
                item_type: data.new_item_type || '',
            };

            const titleEl = card.querySelector('.place-title');
            if (titleEl) titleEl.textContent = data.new_title;
            card.dataset.placeId = data.place_id;
            card.dataset.state = data.new_state || state;
            card.dataset.category = data.new_category || category;

            const metaMain = card.querySelector('.place-meta-main');
            if (metaMain) {
                const location = [data.new_district, data.new_state].filter(Boolean).join(', ');
                const opening = data.new_opening_hours ? ' | Hours: ' + data.new_opening_hours : '';
                const costText = parseFloat(data.new_cost || 0) > 0 ? ' | RM ' + parseFloat(data.new_cost).toFixed(2) : ' | Free';
                metaMain.textContent = [location, opening, costText].filter(Boolean).join('');
            }
            const badge = card.querySelector('.type-badge');
            if (badge && data.new_item_type) {
                badge.textContent = data.new_item_type.charAt(0).toUpperCase() + data.new_item_type.slice(1);
                badge.className = 'type-badge badge-' + data.new_item_type;
            }
            updateCardMatch(card, data);

            rep.innerHTML = `
                <div style="font-weight:800; color:#d97706; margin-bottom:4px;">Replacement selected</div>
                <div style="font-weight:700;">${escHtml(data.new_title)}</div>
                <div style="font-size:11px; color:#64748b; margin-top:3px;">
                    ${data.new_state ? escHtml(data.new_state) : ''}
                    ${data.new_cost > 0 ? ' | RM ' + parseFloat(data.new_cost).toFixed(2) : ' | Free'}
                </div>
                <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                    <span class="match-badge badge-${data.match_color}">${data.match_label}</span>
                    <span style="font-weight:900; font-size:13px;">${data.match_pct}% match</span>
                </div>
                <span class="pending-change-note">Pending: saved only after Confirm</span>
                <div style="margin-top:8px; display:flex; gap:8px;">
                    <button type="button" class="btn-accept" onclick="acceptPlace(${itemId})">Keep This</button>
                    <button type="button" class="btn-reject" onclick="rejectPlace(${itemId})">Remove</button>
                    <button type="button" class="btn-replace" onclick="replacePlace(${itemId}, ${day}, '${escJs(data.new_state || state)}', '${escJs(data.new_category || category)}')">Try Another</button>
                </div>
            `;
            rep.classList.add('visible');

            card.classList.remove('replacing');
            card.classList.add('accepted');
            card.dataset.status = 'accepted';
            itemStatus[itemId] = 'accepted';
        } else {
            rep.innerHTML = '<div style="color:#ef4444; font-weight:700;">Warning: ' + escHtml(data.message || 'No replacement found.') + '</div>';
            rep.classList.add('visible');
            card.classList.remove('replacing');
            card.classList.add('rejected');
            card.dataset.status = 'rejected';
            itemStatus[itemId] = 'rejected';
        }
    } catch(e) {
        rep.innerHTML = '<div style="color:#ef4444; font-weight:700;">Warning: Network error. Please try again.</div>';
        rep.classList.add('visible');
        card.classList.remove('replacing');
        card.classList.add('accepted');
        card.dataset.status = 'accepted';
        itemStatus[itemId] = 'accepted';
    }

    btn.disabled = false;
    btn.innerHTML = 'Replace';
    updateStats();
}

// ---- Hotel selection ----
function selectHotel(cardId, placeId, hotelName) {
    // Deselect all
    document.querySelectorAll('.hotel-card-review').forEach(c => c.classList.remove('selected'));
    // Select clicked
    const card = document.getElementById('hotel-card-' + cardId);
    if (card) {
        if (selectedHotelPlaceId === placeId) {
            // Toggle off
            selectedHotelPlaceId = '';
            selectedHotelName = '';
        } else {
            card.classList.add('selected');
            selectedHotelPlaceId = placeId;
            selectedHotelName = hotelName;
        }
    }
    updateStats();
}

// ---- Toggle score breakdown ----
function toggleBreakdown(itemId) {
    const el = document.getElementById('breakdown-' + itemId);
    if (el) el.classList.toggle('visible');
}

// ---- Reset all ----
function resetAll() {
    document.querySelectorAll('.place-card').forEach(card => {
        const itemId = parseInt(card.dataset.itemId);
        if (originalCards[itemId]) {
            card.innerHTML = originalCards[itemId].html;
            card.dataset.state = originalCards[itemId].state;
            card.dataset.category = originalCards[itemId].category;
            card.dataset.placeId = originalCards[itemId].placeId;
        }
        card.classList.remove('rejected', 'replacing');
        card.classList.add('accepted');
        card.dataset.status = 'accepted';
        itemStatus[itemId] = 'accepted';
        const rep = document.getElementById('replacement-' + itemId);
        if (rep) { rep.classList.remove('visible'); rep.innerHTML = ''; }
    });
    Object.keys(replacementMap).forEach(k => delete replacementMap[k]);
    document.querySelectorAll('.hotel-card-review').forEach(c => c.classList.remove('selected'));
    selectedHotelPlaceId = '';
    selectedHotelName = '';
    updateStats();
}

// ---- Update stats bar ----
function updateStats() {
    let accepted = 0, rejected = 0;
    let replaced = 0;
    Object.entries(itemStatus).forEach(([id, s]) => {
        if (s === 'accepted') {
            accepted++;
            if (replacementMap[id]) replaced++;
        } else if (s === 'rejected') rejected++;
    });
    document.getElementById('stat-accepted').textContent = accepted;
    document.getElementById('stat-rejected').textContent = rejected;
    const replacedEl = document.getElementById('stat-replaced');
    if (replacedEl) replacedEl.textContent = replaced;
    document.getElementById('stat-hotel').textContent    = selectedHotelName || 'None';
}

function updateCardMatch(card, data) {
    const colorMap = { success: '#22c55e', primary: '#3b82f6', warning: '#f59e0b', danger: '#ef4444' };
    const color = colorMap[data.match_color] || '#64748b';
    const badge = card.querySelector('.match-score-wrap .match-badge');
    if (badge) {
        badge.className = 'match-badge badge-' + data.match_color;
        badge.textContent = data.match_label;
    }
    const mainPct = card.querySelector('.match-main-pct');
    if (mainPct) {
        mainPct.textContent = data.match_pct + '%';
        mainPct.style.color = color;
    }
    const fill = card.querySelector('.match-overall-fill');
    if (fill) {
        fill.style.width = data.match_pct + '%';
        fill.style.background = color;
    }
    const pct = card.querySelector('.match-overall-pct');
    if (pct) {
        pct.textContent = data.match_pct + '%';
        pct.style.color = color;
    }
}

// ---- Confirm review ----
async function confirmReview() {
    const btn = document.getElementById('btn-confirm');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving...';

    const rejectedIds = Object.entries(itemStatus)
        .filter(([id, s]) => s === 'rejected')
        .map(([id]) => parseInt(id));

    try {
        const resp = await fetch('review_replace.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:       'confirm',
                itinerary_id: ITINERARY_ID,
                rejected_ids: rejectedIds.join(','),
                hotel_place_id: selectedHotelPlaceId || '',
                replacements_json: JSON.stringify(replacementMap),
            })
        });
        const data = await parseJsonResponse(resp);

        if (data.status === 'success') {
            window.location.href = 'itinerary_view.php?itinerary_id=' + ITINERARY_ID;
        } else {
            alert('Error: ' + (data.message || 'Could not save review.'));
            btn.disabled = false;
            btn.innerHTML = 'Confirm & View Itinerary';
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = 'Confirm & View Itinerary';
    }
}

// ---- HTML escape helper ----
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escJs(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, ' ');
}

function toggleAiChat() {
    const panel = document.getElementById('aiChatPanel');
    if (!panel) return;
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
        const input = document.getElementById('aiChatInput');
        if (input) setTimeout(() => input.focus(), 80);
    }
}

function addAiMessage(role, text) {
    const body = document.getElementById('aiChatBody');
    if (!body) return null;
    const msg = document.createElement('div');
    msg.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
    msg.textContent = text;
    body.appendChild(msg);
    body.scrollTop = body.scrollHeight;
    return msg;
}

function askAiQuick(text) {
    const input = document.getElementById('aiChatInput');
    if (input) input.value = text;
    sendAiMessage();
}

async function sendAiMessage(event) {
    if (event) event.preventDefault();
    const input = document.getElementById('aiChatInput');
    if (!input) return;
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    addAiMessage('user', text);
    const loading = addAiMessage('bot', 'Writing answer...');
    try {
        const resp = await fetch('ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ itinerary_id: ITINERARY_ID, message: text }),
        });
        const data = await parseJsonResponse(resp);
        if (loading) loading.textContent = data.answer || data.message || 'AI assistant could not answer this request.';
    } catch (e) {
        if (loading) loading.textContent = 'Network error. Please try again.';
    }
}

async function parseJsonResponse(resp) {
    const raw = await resp.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            return JSON.parse(raw.slice(start, end + 1));
        }
        throw e;
    }
}
</script>
</body>
</html>
