<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$googleMapsKey = defined("GOOGLE_MAPS_API_KEY") ? trim((string)GOOGLE_MAPS_API_KEY) : "";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["form_errors"]);

$hasPartySizeCol = false;
$colRes = $conn->query("SHOW COLUMNS FROM traveller_preferences LIKE 'party_size'");
if ($colRes && $colRes->num_rows > 0) {
    $hasPartySizeCol = true;
}
$partySizeSql = $hasPartySizeCol ? ", party_size" : "";

$stmt = $conn->prepare("
  SELECT preference_id, trip_days, budget, budget_tier, transport_type, traveller_type{$partySizeSql}, travel_pace,
         dietary_preference, preferred_visit_time, accessibility_needs, interests, preferred_states, preferred_districts, created_at
  FROM traveller_preferences
  WHERE traveller_id = ?
  ORDER BY preference_id DESC
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
$preferences = [];
while ($row = $res->fetch_assoc()) {
    $preferences[] = $row;
}
$stmt->close();

function pref_human_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === "") return "-";
    return ucwords(str_replace(["_", "-"], " ", $value));
}

function pref_rm(float $amount): string
{
    return "RM " . number_format($amount, 2);
}

function pref_interest_label(?string $csv): string
{
    $parts = array_values(array_filter(array_map("trim", explode(",", (string)$csv))));
    if (empty($parts)) return "-";
    return implode(", ", array_map("pref_human_label", $parts));
}

function pref_party_size(array $pref): int
{
    $type = strtolower((string)($pref["traveller_type"] ?? "solo"));
    $fallback = match ($type) {
        "couple" => 2,
        "family" => 4,
        "group" => 5,
        default => 1,
    };
    $size = (int)($pref["party_size"] ?? 0);
    return $size >= 1 ? $size : $fallback;
}

function pref_location_label(array $pref): string
{
    $state = trim((string)($pref["preferred_states"] ?? ""));
    $district = trim((string)($pref["preferred_districts"] ?? ""));
    if ($state !== "" && $district !== "") return $state . " > " . $district;
    if ($state !== "") return $state;
    if ($district !== "") return $district;
    return "All Malaysia";
}

function pref_short_label(array $pref): string
{
    $days = max(1, (int)($pref["trip_days"] ?? 1));
    $nights = max(0, $days - 1);
    return "Preference #" . (int)$pref["preference_id"]
        . " - " . $days . "D" . $nights . "N " . pref_location_label($pref)
        . " - " . pref_rm((float)($pref["budget"] ?? 0))
        . " - " . pref_human_label((string)($pref["travel_pace"] ?? "normal")) . " Pace";
}

function pref_activity_limit_text(array $pref): string
{
    $pace = strtolower((string)($pref["travel_pace"] ?? "normal"));
    $type = strtolower((string)($pref["traveller_type"] ?? "solo"));
    $access = strtolower((string)($pref["accessibility_needs"] ?? ""));
    $base = match ($pace) {
        "relaxed" => "lighter schedule",
        "packed" => "denser schedule",
        default => "balanced schedule",
    };
    if ($type === "family" || str_contains($access, "elderly") || str_contains($access, "wheelchair") || str_contains($access, "avoid stairs")) {
        return ucfirst($base) . " with reduced walking and extra rest buffer.";
    }
    return ucfirst($base) . " based on selected travel pace.";
}

function pref_analysis_rows(array $pref): array
{
    $budget = (float)($pref["budget"] ?? 0);
    $days = max(1, (int)($pref["trip_days"] ?? 1));
    $partySize = pref_party_size($pref);
    $perDay = $budget / $days;
    $tier = pref_human_label((string)($pref["budget_tier"] ?? "normal"));
    $interests = pref_interest_label((string)($pref["interests"] ?? ""));
    $transport = pref_human_label((string)($pref["transport_type"] ?? "car"));
    $diet = strtolower((string)($pref["dietary_preference"] ?? "none"));
    $access = trim((string)($pref["accessibility_needs"] ?? ""));

    $budgetLevel = "Limited";
    if ($perDay >= 250) $budgetLevel = "Comfortable";
    elseif ($perDay >= 120) $budgetLevel = "Moderate";

    $foodRule = match ($diet) {
        "halal" => "Food places are filtered to halal records where available.",
        "vegetarian" => "Vegetarian preference is recorded; manual food checking is recommended.",
        default => "No dietary restriction applied.",
    };

    return [
        ["Traveller Profile", $tier . " " . pref_human_label((string)($pref["traveller_type"] ?? "solo")) . " Traveller"],
        ["Party Size", $partySize . " traveller(s)"],
        ["Budget Level", $budgetLevel . " (" . pref_rm($perDay) . " per day estimate)"],
        ["Route Scope", pref_location_label($pref) . " first"],
        ["Activity Rule", pref_activity_limit_text($pref)],
        ["Transport Rule", $transport . " route mode with daily distance limit"],
        ["Interest Rule", $interests],
        ["Food Rule", $foodRule],
        ["Accessibility Rule", $access !== "" ? $access : "No special accessibility restriction"],
    ];
}

$preferenceSummaries = [];
foreach ($preferences as $pref) {
    $preferenceSummaries[(int)$pref["preference_id"]] = [
        "label" => pref_short_label($pref),
        "summary" => [
            ["Duration", (int)$pref["trip_days"] . ((int)$pref["trip_days"] > 1 ? " days" : " day")],
            ["Budget", pref_rm((float)($pref["budget"] ?? 0))],
            ["Transport", pref_human_label((string)($pref["transport_type"] ?? "car"))],
            ["Traveller Type", pref_human_label((string)($pref["traveller_type"] ?? "solo"))],
            ["Party Size", pref_party_size($pref) . " traveller(s)"],
            ["Pace", pref_human_label((string)($pref["travel_pace"] ?? "normal"))],
            ["Location", pref_location_label($pref)],
            ["Interests", pref_interest_label((string)($pref["interests"] ?? ""))],
            ["Dietary", pref_human_label((string)($pref["dietary_preference"] ?? "none"))],
            ["Visit Time", pref_human_label((string)($pref["preferred_visit_time"] ?? "any"))],
        ],
        "analysis" => pref_analysis_rows($pref),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Smart Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        /* ---- Origin input group ---- */
        .origin-group {
            position: relative;
        }
        .origin-input-wrap {
            display: flex;
            gap: 8px;
            align-items: stretch;
            margin-top: 8px;
        }
        .origin-input-wrap input[type="text"] {
            flex: 1;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(15,23,42,0.10);
            font-size: 13px;
        }
        .btn-locate {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(99,102,241,0.35);
            background: rgba(99,102,241,0.07);
            color: #6366f1;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }
        .btn-locate:hover { background: rgba(99,102,241,0.14); }
        .btn-locate svg { width:14px; height:14px; flex-shrink:0; }
        .origin-status {
            font-size: 11px;
            margin-top: 5px;
            min-height: 16px;
            color: var(--muted);
        }
        .origin-status.ok   { color: #22c55e; }
        .origin-status.err  { color: #ef4444; }
        .origin-status.spin { color: #6366f1; }
        .pac-container {
            z-index: 99999;
            border-radius: 12px;
            margin-top: 4px;
            border: 1px solid rgba(15,23,42,0.12);
            box-shadow: 0 16px 40px rgba(15,23,42,0.14);
            font-family: inherit;
        }
        .pac-item {
            padding: 8px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .pac-item:hover {
            background: #f8fafc;
        }

        /* ---- Route strategy info box ---- */
        .route-info-box {
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 8px;
        }
        .route-info-box .route-title {
            font-weight: 800;
            font-size: 13px;
            color: #4f46e5;
            margin-bottom: 6px;
        }
        .route-info-box ul {
            margin: 0;
            padding-left: 18px;
            font-size: 12px;
            color: #475569;
            line-height: 1.7;
        }
        .pref-insight {
            display: none;
            margin-top: 14px;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .pref-insight.show {
            display: grid;
        }
        .pref-panel {
            border: 1px solid rgba(15,23,42,0.10);
            border-radius: 12px;
            background: #f8fafc;
            padding: 14px;
        }
        .pref-panel h4 {
            margin: 0 0 10px;
            font-size: 13px;
            color: #0f172a;
        }
        .pref-row {
            display: grid;
            grid-template-columns: 145px minmax(0, 1fr);
            gap: 10px;
            padding: 8px 0;
            border-top: 1px solid rgba(15,23,42,0.07);
            font-size: 12.5px;
        }
        .pref-row:first-of-type {
            border-top: 0;
        }
        .pref-key {
            color: #64748b;
            font-weight: 700;
        }
        .pref-value {
            color: #0f172a;
            font-weight: 800;
        }
        .ai-helper {
            margin-top: 16px;
        }
        .ai-helper-grid {
            display: grid;
            grid-template-columns: minmax(280px, 410px) minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }
        .ai-form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .ai-field {
            margin-bottom: 12px;
        }
        .ai-field label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            margin-bottom: 6px;
        }
        .ai-field input,
        .ai-field select,
        .ai-field textarea {
            width: 100%;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 13px;
            background: #fff;
            box-sizing: border-box;
        }
        .ai-field textarea {
            min-height: 76px;
            resize: vertical;
        }
        .ai-category-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .ai-category-grid label {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 9px;
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
        }
        .ai-category-grid input {
            width: auto;
        }
        .ai-status {
            display: none;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }
        .ai-status.show { display: block; }
        .ai-status.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ai-status.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .ai-result-empty {
            min-height: 310px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            border: 1px dashed rgba(15, 23, 42, 0.18);
            border-radius: 12px;
            background: #f8fafc;
            padding: 20px;
        }
        .ai-day-card {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            background: #fff;
        }
        .ai-day-card h4 {
            margin: 0 0 8px;
            color: #4f46e5;
        }
        .ai-route-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }
        .ai-route-chip {
            background: #eef2ff;
            color: #3730a3;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 800;
        }
        .ai-place {
            border-left: 4px solid #6366f1;
            background: #f8fafc;
            border-radius: 8px;
            padding: 9px 10px;
            margin-top: 8px;
        }
        .ai-place span {
            display: block;
            font-size: 12px;
            color: #64748b;
            line-height: 1.45;
        }
        .ai-summary {
            background: #f8fafc;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .ai-chat-shell {
            display: grid;
            grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
            gap: 14px;
        }
        .ai-chat-context {
            background: #f8fafc;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 14px;
            color: #475569;
            font-size: 13px;
            line-height: 1.55;
        }
        .ai-chat-window {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .ai-chat-messages {
            min-height: 260px;
            max-height: 420px;
            overflow-y: auto;
            padding: 14px;
            background: #f8fafc;
        }
        .ai-msg {
            max-width: 86%;
            margin-bottom: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.45;
            white-space: pre-wrap;
        }
        .ai-msg.bot {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            color: #334155;
        }
        .ai-msg.user {
            margin-left: auto;
            background: #4f46e5;
            color: #fff;
        }
        .ai-confirm-card {
            margin-top: 8px;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid rgba(79,70,229,.22);
            background: #eef2ff;
            color: var(--navy);
        }
        .ai-confirm-card strong {
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .ai-confirm-card span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .ai-confirm-card button {
            border: 0;
            border-radius: 8px;
            background: var(--orange);
            color: var(--navy);
            padding: 7px 10px;
            font-size: 11px;
            font-weight: 900;
            cursor: pointer;
        }
        .ai-memory {
            display: none;
            margin-top: 12px;
            padding: 10px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.09);
            color: var(--navy);
        }
        .ai-memory.show { display: block; }
        .ai-memory-title {
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 6px;
        }
        .ai-memory-row {
            font-size: 11.5px;
            color: var(--muted);
            line-height: 1.45;
            margin-top: 4px;
        }
        .ai-memory-row strong {
            color: var(--navy);
        }
        .ai-chat-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }
        .ai-chat-form input {
            flex: 1;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 13px;
        }
        @media (max-width: 850px) {
            .pref-insight { grid-template-columns: 1fr; }
            .ai-chat-shell { grid-template-columns: 1fr; }
            .ai-chat-form { flex-direction: column; }
        }
        @media (max-width: 1050px) {
            .ai-helper-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .ai-form-row, .ai-category-grid { grid-template-columns: 1fr; }
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
                <span>Smart Itinerary Generator</span>
            </div>
        </div>

        <nav class="nav" aria-label="Sidebar Navigation">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
            <a class="active" href="select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
            <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
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

    <main class="content">
        <div class="topbar">
            <div class="page-title">
                <h1>Smart Itinerary Generator</h1>
                <p>Select a saved preference to generate your personalised cultural itinerary. Weather will adjust outdoor activities.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="#ai-travel-assistant">AI Travel Assistant</a>
                <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="card" style="border-left:6px solid rgba(239,68,68,.7); margin-bottom:12px;">
                <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Generate Itinerary</h3>

            <?php if (empty($preferences)): ?>
                <p style="color:#ef4444; font-weight:800;">
                    No preference found. Please create one first.
                </p>
                <a class="btn btn-primary" href="../preference/preference_form.php">Go to Preference Analyzer</a>

            <?php else: ?>
                <form method="post" action="generate_itinerary.php" id="genForm">

                    <!-- ===== Saved Preference ===== -->
                    <label style="font-weight:800; font-size:13px;">Saved Preference *</label><br>
                    <select name="preference_id" id="preferenceSelect" required
                        style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px; font-size:13px;">
                        <option value="" disabled selected>Select one preference</option>
                        <?php foreach ($preferences as $p): ?>
                            <option value="<?php echo (int)$p["preference_id"]; ?>">
                                <?php echo htmlspecialchars(pref_short_label($p)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="pref-insight" id="preferenceInsight" aria-live="polite">
                        <div class="pref-panel">
                            <h4>Selected Preference Summary</h4>
                            <div id="preferenceSummaryRows"></div>
                        </div>
                        <div class="pref-panel">
                            <h4>System Analysis Result</h4>
                            <div id="preferenceAnalysisRows"></div>
                        </div>
                    </div>

                    <div style="height:14px;"></div>

                    <!-- ===== Start Date ===== -->
                    <label style="font-weight:800; font-size:13px;">Start Date *</label><br>
                    <input type="date" name="start_date"
                        style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px; font-size:13px;">
                    <div class="meta" style="margin-top:5px;">Required for festival checking, weather planning, and day-by-day schedule dates. You can ask the AI assistant to fill it, then confirm.</div>

                    <div style="height:14px;"></div>

                    <div class="route-info-box">
                        <div class="route-title">Travel Pace Controls Daily Schedule Density</div>
                        <ul>
                            <li>Relaxed preference: target 3 places per day with about 90 minutes rest between stops.</li>
                            <li>Normal preference: target 4 places per day with about 60 minutes rest between stops.</li>
                            <li>Packed preference: target 5 places per day with about 30 minutes rest between stops.</li>
                            <li>Food places count as real itinerary stops and are costed for the full party size.</li>
                            <li>Food-only preferences become a food trail with about 4 to 6 food stops per day.</li>
                        </ul>
                    </div>

                    <div style="height:14px;"></div>

                    <!-- ===== Route Strategy (single mode, read-only info) ===== -->
                    <label style="font-weight:800; font-size:13px;">Route Strategy</label>
                    <input type="hidden" name="route_strategy" value="nearest_next">
                    <div class="route-info-box">
                        <div class="route-title">&#9654; Rule-Based Nearest-Next Routing</div>
                        <ul>
                            <li>The selected state is a hard boundary. The planner will not move to another state unless a new preference allows it.</li>
                            <li>District and interests are applied by tiers: selected district + interests first, then selected district fallback, then nearest same-state districts.</li>
                            <li>Interest is a soft preference, so it will not create empty days while valid same-state places still exist.</li>
                            <li>Each next stop is chosen from the highest available tier using score, distance, opening time, budget, accessibility, rating and category diversity.</li>
                            <li>Day 1 begins from the confirmed starting location. Later days continue from the previous day's last stop unless a confirmed hotel is available.</li>
                        </ul>
                    </div>

                    <div style="height:14px;"></div>

                    <!-- ===== Starting Location ===== -->
                    <label style="font-weight:800; font-size:13px;">
                        Starting Location for Route Optimization *
                    </label>
                    <div class="origin-group">
                        <div class="origin-input-wrap">
                            <input type="text" name="origin_name" id="origin_name"
                                placeholder="Type a city or address, e.g. Johor Bahru, Kuala Lumpur...">
                            <button type="button" class="btn-locate" id="btnLocate" title="Use my current location">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
                                    <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z" stroke-width="0"/>
                                </svg>
                                Use My Location
                            </button>
                        </div>
                        <input type="hidden" name="origin_lat" id="origin_lat">
                        <input type="hidden" name="origin_lng" id="origin_lng">
                        <div class="origin-status" id="originStatus">Required. Choose a Google Maps suggestion, use your current location, or ask AI to confirm a starting location.</div>
                    </div>

                    <div style="height:18px;"></div>

                    <button class="btn btn-primary" type="submit">&#9654; Generate Itinerary</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card ai-helper" id="ai-travel-assistant">
            <h3>AI Travel Assistant</h3>
            <p class="meta">
                Ask about the selected saved preference above. The assistant gives route, cost, culture, hotel, festival timing, and improvement advice
                without creating or saving a formal itinerary.
            </p>

            <div class="ai-chat-shell">
                <div class="ai-chat-context">
                    <strong>How this assistant works</strong><br>
                    Select one saved preference in Generate Itinerary, then ask a question here. The AI reads that selected preference, so you do not need to fill another trip form.
                    <div class="meta" id="aiSelectedPreference" style="margin-top:10px;">Selected preference: none</div>
                    <div class="ai-memory" id="aiPreferenceMemory">
                        <div class="ai-memory-title">AI noted details</div>
                        <div id="aiPreferenceMemoryRows"></div>
                    </div>
                </div>
                <div class="ai-chat-window">
                    <div id="aiChatMessages" class="ai-chat-messages">
                        <div class="ai-msg bot">Hi, before generating I need a confirmed Start Date and Starting Location. You can tell me naturally, for example "I want to travel on 30 Jun 2026 from Kuala Lumpur", then confirm the detected details.</div>
                    </div>
                    <form id="aiPreferenceChatForm" class="ai-chat-form">
                        <input type="text" id="aiPreferenceMessage" placeholder="Ask about the selected preference..." autocomplete="off">
                        <button type="submit" class="btn btn-primary" id="aiPreferenceSend">Send</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    var nameInp   = document.getElementById('origin_name');
    var latField  = document.getElementById('origin_lat');
    var lngField  = document.getElementById('origin_lng');
    var status    = document.getElementById('originStatus');
    var btnLocate = document.getElementById('btnLocate');
    var pickedFromAutocomplete = false;

    window.initOriginAutocomplete = function () {};

    if (!nameInp) return;

    window.initOriginAutocomplete = function () {
        if (!window.google || !google.maps || !google.maps.places) return;

        var autocomplete = new google.maps.places.Autocomplete(nameInp, {
            componentRestrictions: { country: 'my' },
            fields: ['formatted_address', 'geometry', 'name']
        });

        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (!place || !place.geometry || !place.geometry.location) {
                latField.value = '';
                lngField.value = '';
                setStatus('err', '&#10007; Please choose a valid Google Maps suggestion.');
                return;
            }

            pickedFromAutocomplete = true;
            latField.value = place.geometry.location.lat().toFixed(6);
            lngField.value = place.geometry.location.lng().toFixed(6);
            nameInp.value = place.formatted_address || place.name || nameInp.value;
            setStatus('ok', '&#10003; Location selected: ' + escapeStatusHtml(nameInp.value));
        });
    };

    /* ---- Geocode typed address (debounced 800ms) ---- */
    var timer = null;
    nameInp.addEventListener('input', function () {
        clearTimeout(timer);
        if (pickedFromAutocomplete) {
            pickedFromAutocomplete = false;
            return;
        }

        var q = nameInp.value.trim();
        if (q.length < 3) {
            latField.value = '';
            lngField.value = '';
            setStatus('', 'Choose a Google Maps suggestion or use your current location. If empty, the route starts from the first selected place.');
            return;
        }
        setStatus('spin', 'Looking up address…');
        timer = setTimeout(function () {
            fetch('geocode_origin.php?q=' + encodeURIComponent(q + ', Malaysia'))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.lat && d.lng) {
                        latField.value = d.lat;
                        lngField.value = d.lng;
                        setStatus('ok', '&#10003; Location found: ' + escapeStatusHtml(d.address || q));
                    } else {
                        latField.value = '';
                        lngField.value = '';
                        setStatus('err', '&#10007; Location not found. Routing will start from the first place.');
                    }
                })
                .catch(function () {
                    setStatus('err', '&#10007; Geocoding failed. Check your connection.');
                });
        }, 800);
    });

    /* ---- Use browser Geolocation ---- */
    if (btnLocate) {
        btnLocate.addEventListener('click', function () {
            if (!navigator.geolocation) {
                setStatus('err', '&#10007; Geolocation is not supported by your browser.');
                return;
            }
            setStatus('spin', 'Detecting your location…');
            btnLocate.disabled = true;
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude.toFixed(6);
                    var lng = pos.coords.longitude.toFixed(6);
                    latField.value = lat;
                    lngField.value = lng;
                    /* Reverse geocode to get a readable name */
                    fetch('geocode_origin.php?lat=' + lat + '&lng=' + lng)
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            var label = (d && d.address) ? d.address : lat + ', ' + lng;
                            pickedFromAutocomplete = true;
                            nameInp.value = label;
                            setStatus('ok', '&#10003; Location detected: ' + escapeStatusHtml(label));
                        })
                        .catch(function () {
                            pickedFromAutocomplete = true;
                            nameInp.value = lat + ', ' + lng;
                            setStatus('ok', '&#10003; GPS coordinates captured (' + lat + ', ' + lng + ')');
                        });
                    btnLocate.disabled = false;
                },
                function (err) {
                    var msg = '&#10007; Could not get location.';
                    if (err.code === 1) msg = '&#10007; Location permission denied.';
                    if (err.code === 2) msg = '&#10007; Location unavailable.';
                    if (err.code === 3) msg = '&#10007; Location request timed out.';
                    setStatus('err', msg);
                    btnLocate.disabled = false;
                },
                { timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    function setStatus(cls, msg) {
        status.className = 'origin-status' + (cls ? ' ' + cls : '');
        status.innerHTML = msg;
    }

    function escapeStatusHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();

(function () {
    var genForm = document.getElementById('genForm');
    var form = document.getElementById('aiPreferenceChatForm');
    var prefSelect = document.getElementById('preferenceSelect');
    var input = document.getElementById('aiPreferenceMessage');
    var messages = document.getElementById('aiChatMessages');
    var sendBtn = document.getElementById('aiPreferenceSend');
    var startDateInput = document.querySelector('input[name="start_date"]');
    var selectedText = document.getElementById('aiSelectedPreference');
    var memoryBox = document.getElementById('aiPreferenceMemory');
    var memoryRows = document.getElementById('aiPreferenceMemoryRows');
    var originNameInput = document.getElementById('origin_name');
    var originLatInput = document.getElementById('origin_lat');
    var originLngInput = document.getElementById('origin_lng');
    var originStatus = document.getElementById('originStatus');
    var insight = document.getElementById('preferenceInsight');
    var summaryRows = document.getElementById('preferenceSummaryRows');
    var analysisRows = document.getElementById('preferenceAnalysisRows');
    var preferenceData = <?php echo json_encode($preferenceSummaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (!form || !prefSelect || !input || !messages) return;

    function updateSelectedPreferenceText() {
        var selected = prefSelect.value && preferenceData[prefSelect.value] ? preferenceData[prefSelect.value] : null;
        var opt = prefSelect.options[prefSelect.selectedIndex];
        var text = selected ? selected.label : (opt && opt.value ? opt.textContent.replace(/\s+/g, ' ').trim() : 'none');
        selectedText.textContent = 'Selected preference: ' + text;
        renderPreferencePanel(selected);
    }

    function renderPreferencePanel(selected) {
        if (!insight || !summaryRows || !analysisRows) return;
        if (!selected) {
            insight.classList.remove('show');
            summaryRows.innerHTML = '';
            analysisRows.innerHTML = '';
            return;
        }
        insight.classList.add('show');
        summaryRows.innerHTML = renderRows(selected.summary || []);
        analysisRows.innerHTML = renderRows(selected.analysis || []);
    }

    function renderRows(rows) {
        return rows.map(function (row) {
            return '<div class="pref-row"><div class="pref-key">' + escHtml(row[0]) + '</div><div class="pref-value">' + escHtml(row[1]) + '</div></div>';
        }).join('');
    }

    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseDateFromMessage(text) {
        var months = {
            jan:1,january:1,feb:2,february:2,mar:3,march:3,apr:4,april:4,may:5,
            jun:6,june:6,jul:7,july:7,aug:8,august:8,sep:9,sept:9,september:9,
            oct:10,october:10,nov:11,november:11,dec:12,december:12
        };
        var m = String(text || '').match(/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b/);
        if (m) return normalDate(Number(m[1]), Number(m[2]), Number(m[3]));

        m = String(text || '').match(/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/);
        if (m) {
            var a = Number(m[1]), b = Number(m[2]), y = Number(m[3]);
            if (a > 12) return normalDate(y, b, a);
            if (b > 12) return normalDate(y, a, b);
            return normalDate(y, b, a);
        }

        m = String(text || '').match(/\b(\d{1,2})(?:st|nd|rd|th)?[\s\/\-.]+([a-zA-Z]+)[,\s\/\-.]+(\d{4})\b/);
        if (m && months[m[2].toLowerCase()]) return normalDate(Number(m[3]), months[m[2].toLowerCase()], Number(m[1]));
        m = String(text || '').match(/\b([a-zA-Z]+)[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?[,\s\/\-.]+(\d{4})\b/);
        if (m && months[m[1].toLowerCase()]) return normalDate(Number(m[3]), months[m[1].toLowerCase()], Number(m[2]));
        m = String(text || '').match(/\b(\d{4})[\s\/\-.]+([a-zA-Z]+)[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?\b/);
        if (m && months[m[2].toLowerCase()]) return normalDate(Number(m[1]), months[m[2].toLowerCase()], Number(m[3]));
        m = String(text || '').match(/\b(\d{4})[\s\/\-.]+(\d{1,2})(?:st|nd|rd|th)?[\s\/\-.]+([a-zA-Z]+)\b/);
        if (m && months[m[3].toLowerCase()]) return normalDate(Number(m[1]), months[m[3].toLowerCase()], Number(m[2]));

        m = String(text || '').match(/\b(\d{1,2})(\d{4})([a-zA-Z]+)\b/);
        if (m && months[m[3].toLowerCase()]) return normalDate(Number(m[2]), months[m[3].toLowerCase()], Number(m[1]));
        m = String(text || '').match(/\b([a-zA-Z]+)(\d{1,2})(\d{4})\b/);
        if (m && months[m[1].toLowerCase()]) return normalDate(Number(m[3]), months[m[1].toLowerCase()], Number(m[2]));
        m = String(text || '').match(/\b(\d{1,2})(?:st|nd|rd|th)?\s*([a-zA-Z]+)\s*(\d{4})\b/);
        if (m && months[m[2].toLowerCase()]) return normalDate(Number(m[3]), months[m[2].toLowerCase()], Number(m[1]));
        m = String(text || '').match(/\b([a-zA-Z]+)\s*(\d{1,2})(?:st|nd|rd|th)?[,]?\s*(\d{4})\b/);
        if (m && months[m[1].toLowerCase()]) return normalDate(Number(m[3]), months[m[1].toLowerCase()], Number(m[2]));
        return null;
    }

    function isTripDateIntent(text) {
        return /\b(start|date|trip|travel|go|going|visit|arrive|depart|leave|change|update|instead|actually|plan|planning)\b|no[, ]|not|wrong|不是|改|更改|换|去|出发|日期|行程|旅行|玩|到|抵达/i.test(String(text || ''));
    }

    function renderAiDraft(draft) {
        if (!memoryBox || !memoryRows || !draft) return;
        var rows = [];
        if (draft.start_date_label) rows.push(['Start date', draft.start_date_label]);
        if (draft.origin_name) rows.push(['Starting location', draft.origin_name]);
        if (draft.hotel_requirement) rows.push(['Hotel', draft.hotel_requirement]);
        (draft.requirements || []).forEach(function (note) {
            rows.push(['Requirement', note]);
        });
        if (!rows.length) {
            memoryBox.classList.remove('show');
            memoryRows.innerHTML = '';
            return;
        }
        memoryRows.innerHTML = rows.map(function (row) {
            return '<div class="ai-memory-row"><strong>' + escHtml(row[0]) + ':</strong> ' + escHtml(row[1]) + '</div>';
        }).join('');
        memoryBox.classList.add('show');
    }

    function normalDate(year, month, day) {
        var dt = new Date(year, month - 1, day);
        if (dt.getFullYear() !== year || dt.getMonth() !== month - 1 || dt.getDate() !== day) return null;
        return String(year).padStart(4, '0') + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }

    function displayDate(dateText) {
        var dt = new Date(dateText + 'T00:00:00');
        if (isNaN(dt.getTime())) return dateText;
        return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function clearPendingCards() {
        messages.querySelectorAll('.ai-confirm-card').forEach(function (card) { card.remove(); });
    }

    function renderPendingAction(container, action) {
        if (!container || !action || (action.type !== 'fill_start_date' && action.type !== 'fill_origin')) return;
        clearPendingCards();
        var card = document.createElement('div');
        card.className = 'ai-confirm-card';

        var title = document.createElement('strong');
        title.textContent = action.type === 'fill_origin' ? 'Confirm starting location' : 'Confirm trip date';

        var meta = document.createElement('span');
        meta.textContent = action.summary || (action.type === 'fill_origin'
            ? ('Use ' + (action.label || action.origin_name) + ' as Starting Location')
            : ('Fill Start Date with ' + (action.label || action.start_date)));

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = action.type === 'fill_origin' ? 'Confirm Location' : 'Confirm Date';
        btn.addEventListener('click', function () {
            if (action.type === 'fill_origin') {
                confirmOriginFromAi(action, btn);
                return;
            }
            if (startDateInput) startDateInput.value = action.start_date;
            btn.disabled = true;
            appendMessage('bot', 'Start Date filled as ' + (action.label || displayDate(action.start_date)) + '. Now confirm your Starting Location before generating.');
            clearPendingCards();
            if (action.next_action) {
                var nextMsg = appendMessage('bot', 'I also found a possible starting location. Please confirm it so route optimization has coordinates.');
                renderPendingAction(nextMsg, action.next_action);
            }
        });

        card.appendChild(title);
        card.appendChild(meta);
        card.appendChild(btn);
        container.appendChild(card);
        messages.scrollTop = messages.scrollHeight;
    }

    function confirmOriginFromAi(action, btn) {
        var originName = action.origin_name || action.label || '';
        if (!originName || !originNameInput || !originLatInput || !originLngInput) return;
        btn.disabled = true;
        btn.textContent = 'Checking...';
        if (!window.google || !google.maps || !google.maps.Geocoder) {
            originNameInput.value = originName;
            appendMessage('bot', 'Starting Location filled as ' + originName + ', but Google Maps is not ready. Please choose the suggestion manually so coordinates can be saved.');
            if (originStatus) {
                originStatus.className = 'origin-status err';
                originStatus.textContent = 'Please choose a Google Maps suggestion to confirm coordinates.';
            }
            clearPendingCards();
            return;
        }
        var geocoder = new google.maps.Geocoder();
        geocoder.geocode({ address: originName, componentRestrictions: { country: 'MY' } }, function (results, geocodeStatus) {
            if (geocodeStatus === 'OK' && results && results[0] && results[0].geometry) {
                var loc = results[0].geometry.location;
                originNameInput.value = results[0].formatted_address || originName;
                originLatInput.value = loc.lat().toFixed(7);
                originLngInput.value = loc.lng().toFixed(7);
                if (originStatus) {
                    originStatus.className = 'origin-status ok';
                    originStatus.textContent = 'Starting location confirmed: ' + originNameInput.value;
                }
                appendMessage('bot', 'Starting Location confirmed as ' + originNameInput.value + '. Coordinates are ready for route optimization.');
                clearPendingCards();
            } else {
                btn.disabled = false;
                btn.textContent = 'Confirm Location';
                appendMessage('bot', 'I could not confirm coordinates for "' + originName + '". Please type the location in Starting Location and choose a Google Maps suggestion.');
                if (originStatus) {
                    originStatus.className = 'origin-status err';
                    originStatus.textContent = 'Could not geocode this location. Choose a Google Maps suggestion manually.';
                }
            }
        });
    }

    if (genForm) {
        genForm.addEventListener('submit', function (event) {
            var missing = [];
            if (!prefSelect.value) missing.push('saved preference');
            if (!startDateInput || !startDateInput.value) missing.push('Start Date');
            if (!originNameInput || !originNameInput.value || !originLatInput.value || !originLngInput.value) missing.push('Starting Location');
            if (!missing.length) return;
            event.preventDefault();
            var message = 'Before I generate the official itinerary, I still need: ' + missing.join(', ') + '. Tell me your trip date and starting location here, then confirm them.';
            appendMessage('bot', message);
            var helper = document.getElementById('ai-travel-assistant');
            if (helper) helper.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (input) input.focus();
            if (originStatus && missing.indexOf('Starting Location') !== -1) {
                originStatus.className = 'origin-status err';
                originStatus.textContent = 'Starting Location is required and must include coordinates.';
            }
        });
    }

    prefSelect.addEventListener('change', updateSelectedPreferenceText);
    updateSelectedPreferenceText();

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var preferenceId = prefSelect.value;
        var text = input.value.trim();
        var localDate = parseDateFromMessage(text);
        var dateIntent = localDate && isTripDateIntent(text);
        if (!preferenceId && !dateIntent) {
            appendMessage('bot', 'Please select one saved preference above first.');
            return;
        }
        if (!text) return;

        appendMessage('user', text);
        clearPendingCards();
        input.value = '';

        if (!preferenceId && dateIntent) {
            var msg = appendMessage('bot', 'I detected your trip start date as ' + displayDate(localDate) + '. Click Confirm Date if this is correct. Please select a saved preference before generating the official itinerary.');
            renderPendingAction(msg, {
                type: 'fill_start_date',
                start_date: localDate,
                label: displayDate(localDate),
                summary: 'Fill Start Date with ' + displayDate(localDate)
            });
            return;
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Thinking...';
        var pending = appendMessage('bot', 'AI is checking your selected preference...');

        var fd = new FormData();
        fd.append('preference_id', preferenceId);
        fd.append('message', text);

        fetch('../api/ai_preference_chat.php', {
            method: 'POST',
            body: fd
        })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                pending.textContent = data.status === 'success'
                    ? (data.reply || 'No reply returned.')
                    : (data.message || 'AI response is invalid. Please try again.');
                if (data.ai_draft) {
                    renderAiDraft(data.ai_draft);
                }
                if (data.pending_actions && data.pending_actions.length) {
                    var firstAction = data.pending_actions[0];
                    if (data.pending_actions.length > 1) firstAction.next_action = data.pending_actions[1];
                    renderPendingAction(pending, firstAction);
                } else if (data.pending_action) {
                    renderPendingAction(pending, data.pending_action);
                }
            })
            .catch(function () {
                pending.textContent = 'AI service is currently unavailable. Please check Gemini API or Ollama fallback.';
            })
            .finally(function () {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
                messages.scrollTop = messages.scrollHeight;
            });
    });

    function appendMessage(type, text) {
        var div = document.createElement('div');
        div.className = 'ai-msg ' + type;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }
})();
</script>
<?php if ($googleMapsKey !== ""): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($googleMapsKey); ?>&libraries=places&callback=initOriginAutocomplete" async defer></script>
<?php endif; ?>
</body>
</html>
