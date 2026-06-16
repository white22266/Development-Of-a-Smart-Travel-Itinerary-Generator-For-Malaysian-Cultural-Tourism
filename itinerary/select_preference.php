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
$errors = $_SESSION["form_errors"] ?? [];
$successMessage = $_SESSION["success_message"] ?? "";
unset($_SESSION["form_errors"], $_SESSION["success_message"]);

$googleMapsKey = defined("GOOGLE_MAPS_API_KEY") ? trim((string)GOOGLE_MAPS_API_KEY) : "";

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

$requestedPreferenceId = (int)($_GET["preference_id"] ?? ($_SESSION["last_preference_id"] ?? 0));
$preselectedPreferenceId = 0;
foreach ($preferences as $pref) {
  if ((int)$pref["preference_id"] === $requestedPreferenceId) {
    $preselectedPreferenceId = $requestedPreferenceId;
    break;
  }
}
$fromPreferenceSaved = (($_GET["from"] ?? "") === "preference_saved");

function pref_human_label(?string $value): string {
  $value = trim((string)$value);
  if ($value === "") return "-";
  return ucwords(str_replace(["_", "-"], " ", $value));
}

function pref_rm(float $amount): string {
  return "RM " . number_format($amount, 2);
}

function pref_list_label(?string $csv): string {
  $parts = array_values(array_filter(array_map("trim", explode(",", (string)$csv))));
  if (empty($parts)) return "-";
  return implode(", ", array_map("pref_human_label", $parts));
}

function pref_party_size(array $pref): int {
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

function pref_location_label(array $pref): string {
  $state = trim((string)($pref["preferred_states"] ?? ""));
  $district = trim((string)($pref["preferred_districts"] ?? ""));
  if ($state !== "" && $district !== "") return $state . " > " . $district;
  if ($state !== "") return $state;
  if ($district !== "") return $district;
  return "All Malaysia";
}

function pref_short_label(array $pref): string {
  $days = max(1, (int)($pref["trip_days"] ?? 1));
  $nights = max(0, $days - 1);
  return "Preference #" . (int)$pref["preference_id"]
    . " - " . $days . "D" . $nights . "N " . pref_location_label($pref)
    . " - " . pref_rm((float)($pref["budget"] ?? 0))
    . " - " . pref_human_label((string)($pref["travel_pace"] ?? "normal")) . " Pace";
}

$preferencePayload = [];
foreach ($preferences as $pref) {
  $pid = (int)$pref["preference_id"];
  $preferencePayload[$pid] = [
    "id" => $pid,
    "duration" => max(1, (int)($pref["trip_days"] ?? 1)) . " day(s)",
    "budget" => pref_rm((float)($pref["budget"] ?? 0)),
    "transport" => pref_human_label((string)($pref["transport_type"] ?? "-")),
    "traveller" => pref_human_label((string)($pref["traveller_type"] ?? "solo")) . " / " . pref_party_size($pref) . " traveller(s)",
    "pace" => pref_human_label((string)($pref["travel_pace"] ?? "normal")),
    "dietary" => pref_human_label((string)($pref["dietary_preference"] ?? "none")),
    "visit_time" => pref_human_label((string)($pref["preferred_visit_time"] ?? "any")),
    "location" => pref_location_label($pref),
    "interests" => pref_list_label((string)($pref["interests"] ?? "")),
    "accessibility" => trim((string)($pref["accessibility_needs"] ?? "")) !== "" ? trim((string)$pref["accessibility_needs"]) : "None",
  ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Smart Itinerary Generator | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
  <style>
    .generator-steps {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin: 14px 0 16px;
    }
    .generator-step {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      background: rgba(2,6,23,0.02);
    }
    .generator-step .step-num {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      border-radius: 999px;
      border: 1px solid rgba(245,197,66,0.45);
      background: rgba(245,197,66,0.12);
      color: #6A4C00;
      font-weight: 900;
      font-size: 12px;
      margin-bottom: 8px;
    }
    .generator-step strong {
      display: block;
      font-size: 13px;
      margin-bottom: 4px;
    }
    .generator-step span {
      display: block;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.35;
    }
    .field-group { margin-bottom: 14px; }
    .field-group label {
      font-size: 13px;
      font-weight: 800;
      display: block;
      margin-bottom: 6px;
    }
    .field-group input,
    .field-group select,
    .ai-chat-form input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(15,23,42,0.10);
      font-size: 13px;
      background: #fff;
      transition: border-color .15s, background .15s;
    }
    .field-help {
      font-size: 11px;
      color: var(--muted);
      margin-top: 5px;
      line-height: 1.45;
    }
    .location-input-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
    }
    .location-input-row .btn {
      min-height: 41px;
      white-space: nowrap;
      justify-content: center;
    }
    .generate-actions {
      justify-content: flex-start;
      margin-top: 10px;
    }
    .generate-actions .btn-primary {
      min-height: 42px;
      padding-inline: 18px;
    }
    .notice-box {
      border: 1px solid rgba(245,197,66,0.45);
      background: rgba(245,197,66,0.12);
      color: var(--navy);
      border-radius: 14px;
      padding: 12px;
      font-size: 13px;
      line-height: 1.45;
      margin-bottom: 14px;
    }
    .error-box {
      border: 1px solid rgba(220,38,38,0.24);
      background: rgba(220,38,38,0.06);
      color: #991b1b;
      border-radius: 14px;
      padding: 12px;
      font-size: 13px;
      line-height: 1.45;
      margin-bottom: 14px;
    }
    .summary-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }
    .summary-item {
      border: 1px solid rgba(15,23,42,0.10);
      border-radius: 10px;
      padding: 10px 12px;
      background: rgba(15,23,42,0.02);
      font-size: 13px;
    }
    .summary-item strong { display: block; margin-bottom: 4px; }
    .summary-item span { color: var(--muted); line-height: 1.35; }
    .summary-item.full { grid-column: 1 / -1; }
    .empty-state {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px;
      background: rgba(2,6,23,0.02);
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    details.help-box {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      background: rgba(2,6,23,0.02);
      font-size: 13px;
    }
    details.help-box summary {
      cursor: pointer;
      font-weight: 800;
      color: var(--navy);
    }
    details.help-box[open] summary { margin-bottom: 10px; }
    .help-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }
    .help-subbox {
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #fff;
      padding: 12px;
    }
    .help-subbox h4 {
      margin: 0 0 8px;
      font-size: 13px;
      color: var(--navy);
    }
    .help-subbox ul {
      margin: 0;
      padding-left: 18px;
      color: var(--muted);
      line-height: 1.55;
      font-size: 12px;
    }
    .status-good { color: #6A4C00; font-weight: 700; }
    .status-bad { color: #991b1b; font-weight: 700; }
    .ai-assistant-card { margin-top: 16px; }
    .ai-chat-body {
      min-height: 150px;
      max-height: 260px;
      overflow-y: auto;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: rgba(2,6,23,0.02);
      padding: 12px;
      margin-bottom: 10px;
    }
    .ai-msg {
      width: fit-content;
      max-width: 90%;
      white-space: pre-wrap;
      word-wrap: break-word;
      font-size: 12.5px;
      line-height: 1.45;
      padding: 9px 11px;
      border-radius: 10px;
      margin-bottom: 9px;
    }
    .ai-msg.user {
      margin-left: auto;
      background: var(--navy);
      color: #fff;
    }
    .ai-msg.bot {
      margin-right: auto;
      background: #fff;
      color: var(--text);
      border: 1px solid var(--border);
    }
    .ai-chat-form {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 10px;
      align-items: center;
    }
    .ai-quick-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 10px 0;
    }
    @media (max-width: 980px) {
      .generator-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .help-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .location-input-row,
      .ai-chat-form { grid-template-columns: 1fr; }
      .location-input-row .btn,
      .ai-chat-form .btn { width: 100%; }
    }
    @media (max-width: 520px) {
      .generator-steps, .summary-list { grid-template-columns: 1fr; }
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
      <a class="active" href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
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
        <p>Confirm your saved preference, start date and starting location before generating your cultural itinerary.</p>
      </div>
      <div class="actions">
        <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <section class="grid">
      <div class="card col-12">
        <h3>Generate Itinerary</h3>
        <p class="meta">Your latest saved preference will be auto-selected after you submit the preference form.</p>

        <?php if ($successMessage !== ""): ?>
          <div class="notice-box"><strong><?php echo htmlspecialchars($successMessage); ?></strong><br>Now confirm your start date and starting location.</div>
        <?php elseif ($fromPreferenceSaved): ?>
          <div class="notice-box"><strong>Preference saved successfully.</strong><br>Now confirm your start date and starting location before generating the itinerary.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="error-box">
            <strong>Please fix the following:</strong>
            <ul>
              <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars((string)$err); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="generator-steps">
          <div class="generator-step"><div class="step-num">1</div><strong>Select Preference</strong><span>The latest saved preference is auto-selected.</span></div>
          <div class="generator-step"><div class="step-num">2</div><strong>Start Date</strong><span>Choose the first day of your trip.</span></div>
          <div class="generator-step"><div class="step-num">3</div><strong>Starting Location</strong><span>Search a place with Google Maps suggestion or use my location.</span></div>
          <div class="generator-step"><div class="step-num">4</div><strong>Generate</strong><span>Create a day-by-day cultural itinerary.</span></div>
        </div>
      </div>

      <?php if (empty($preferences)): ?>
        <div class="card col-12">
          <h3>No saved preference found</h3>
          <p class="meta">Please create a travel preference first. The itinerary generator needs your budget, interests, state, district, transport type and travel pace.</p>
          <a class="btn btn-primary" href="../preference/preference_form.php">Create Travel Preference</a>
        </div>
      <?php else: ?>
        <div class="card col-8">
          <h3>Confirm Itinerary Details</h3>
          <p class="meta">Only three details are needed here before the itinerary can be generated.</p>

          <form id="generatorForm" method="post" action="generate_itinerary.php">
            <div class="field-group">
              <label for="preference_id">Step 1: Saved Travel Preference</label>
              <select name="preference_id" id="preference_id" required>
                <option value="" disabled <?php echo $preselectedPreferenceId <= 0 ? "selected" : ""; ?>>Select one preference</option>
                <?php foreach ($preferences as $p): ?>
                  <?php $pid = (int)$p["preference_id"]; ?>
                  <option value="<?php echo $pid; ?>" <?php echo $pid === $preselectedPreferenceId ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars(pref_short_label($p)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-help">After submitting a preference, the latest preference is automatically selected here.</div>
            </div>

            <div class="field-group" id="startDateField">
              <label for="start_date">Step 2: Start Date</label>
              <input type="date" name="start_date" id="start_date" min="<?php echo date('Y-m-d'); ?>" required>
              <div class="field-help">This date is used to arrange itinerary schedule and opening-time checks.</div>
            </div>

            <div class="field-group">
              <label for="origin_name">Step 3: Starting Location</label>
              <div class="location-input-row">
                <input type="text" name="origin_name" id="origin_name" placeholder="Type a city or address, e.g. Johor Bahru, Kuala Lumpur..." autocomplete="off" required>
                <button type="button" class="btn btn-ghost" id="useCurrentLocationBtn">⌖ Use My Location</button>
              </div>
              <input type="hidden" name="origin_lat" id="origin_lat">
              <input type="hidden" name="origin_lng" id="origin_lng">
              <div class="field-help" id="locationStatus">
                <?php echo $googleMapsKey !== "" ? "Required. Choose a Google Maps suggestion, use your current location, or ask AI to confirm a starting location." : "Required. Type a starting point and the system will find its coordinates before generating."; ?>
              </div>
            </div>

            <div class="actions generate-actions">
              <button type="submit" class="btn btn-primary">▶ Generate Itinerary</button>
            </div>
          </form>
        </div>

        <div class="card col-4">
          <h3>Selected Preference Summary</h3>
          <p class="meta">Review the selected preference before generating.</p>
          <div id="preferenceSummary" class="empty-state">Select a saved preference to view the summary.</div>
        </div>

        <div class="card col-12 ai-assistant-card">
          <h3>AI Travel Assistant</h3>
          <p class="meta">Ask the AI assistant if you are unsure about the start date, starting location, route plan or selected preference. AI gives guidance only; the official itinerary is still generated by system rules.</p>
          <div class="ai-chat-body" id="aiChatBody">
            <div class="ai-msg bot">Hi, I can help you understand your selected preference and decide a suitable starting location before generating the itinerary.</div>
          </div>
          <div class="ai-quick-actions">
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="Suggest a suitable starting location based on my selected preference.">Suggest starting location</button>
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="Explain whether my selected preference is suitable for generating an itinerary.">Check my preference</button>
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="What should I confirm before clicking Generate Itinerary?">Before generate</button>
          </div>
          <form class="ai-chat-form" id="aiChatForm">
            <input type="text" id="aiQuestion" placeholder="Ask AI, e.g. Is KL Sentral a good starting location?" autocomplete="off">
            <button type="submit" class="btn btn-primary">Ask AI</button>
          </form>
        </div>

        <div class="card col-12">
          <details class="help-box">
            <summary>Planning Rules and Route Strategy</summary>
            <div class="help-grid">
              <div class="help-subbox">
                <h4>Travel Pace Controls Daily Schedule Density</h4>
                <ul>
                  <li>Relaxed preference: target 3 places per day with about 90 minutes rest between stops.</li>
                  <li>Normal preference: target 4 places per day with about 60 minutes rest between stops.</li>
                  <li>Packed preference: target 5 places per day with about 30 minutes rest between stops.</li>
                  <li>Food places count as real itinerary stops and are costed for the full party size.</li>
                  <li>Food-only preferences become a food trail with about 4 to 6 food stops per day.</li>
                </ul>
              </div>
              <div class="help-subbox">
                <h4>Route Strategy: Rule-Based Nearest-Next Routing</h4>
                <ul>
                  <li>The selected state is a hard boundary. The planner will not move to another state unless a new preference allows it.</li>
                  <li>District and interests are applied by tiers: selected district + interests first, then selected district fallback, then nearest same-state districts.</li>
                  <li>Interest is a soft preference, so it will not create empty days while valid same-state places still exist.</li>
                  <li>Each next stop is chosen from the highest available tier using score, distance, opening time, budget, accessibility, rating and category diversity.</li>
                  <li>Day 1 begins from the confirmed starting location. Later days continue from the previous day's last stop unless a confirmed hotel is available.</li>
                </ul>
              </div>
            </div>
          </details>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<script>
const preferenceData = <?php echo json_encode($preferencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const preselectedPreferenceId = <?php echo (int)$preselectedPreferenceId; ?>;
const fromPreferenceSaved = <?php echo $fromPreferenceSaved ? "true" : "false"; ?>;

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, function (char) {
    return {"&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#039;"}[char];
  });
}

function updatePreferenceSummary() {
  const select = document.getElementById("preference_id");
  const box = document.getElementById("preferenceSummary");
  if (!select || !box) return;

  const pref = preferenceData[select.value];
  if (!pref) {
    box.className = "empty-state";
    box.innerHTML = "Select a saved preference to view the summary.";
    return;
  }

  box.className = "summary-list";
  box.innerHTML = `
    <div class="summary-item"><strong>Preference</strong><span>#${escapeHtml(pref.id)}</span></div>
    <div class="summary-item"><strong>Duration</strong><span>${escapeHtml(pref.duration)}</span></div>
    <div class="summary-item"><strong>Budget</strong><span>${escapeHtml(pref.budget)}</span></div>
    <div class="summary-item"><strong>Transport</strong><span>${escapeHtml(pref.transport)}</span></div>
    <div class="summary-item"><strong>Traveller</strong><span>${escapeHtml(pref.traveller)}</span></div>
    <div class="summary-item"><strong>Travel Pace</strong><span>${escapeHtml(pref.pace)}</span></div>
    <div class="summary-item full"><strong>Location</strong><span>${escapeHtml(pref.location)}</span></div>
    <div class="summary-item full"><strong>Interests</strong><span>${escapeHtml(pref.interests)}</span></div>
    <div class="summary-item full"><strong>Accessibility</strong><span>${escapeHtml(pref.accessibility)}</span></div>
  `;
}

function setLocationStatus(message, status) {
  const statusBox = document.getElementById("locationStatus");
  if (!statusBox) return;
  statusBox.textContent = message;
  if (status === true) {
    statusBox.className = "field-help status-good";
  } else if (status === false) {
    statusBox.className = "field-help status-bad";
  } else {
    statusBox.className = "field-help";
  }
}

function clearOriginCoords() {
  const latInput = document.getElementById("origin_lat");
  const lngInput = document.getElementById("origin_lng");
  const originInput = document.getElementById("origin_name");
  if (!latInput || !lngInput || !originInput) return;

  latInput.value = "";
  lngInput.value = "";
  const originName = originInput.value.trim();
  if (originName === "") {
    setLocationStatus("Required. Choose a Google Maps suggestion, use your current location, or ask AI to confirm a starting location.", null);
  } else {
    setLocationStatus("Choose a suggested location from Google Maps, or click Generate Itinerary to check coordinates automatically.", null);
  }
}

function fillOrigin(name, lat, lng) {
  document.getElementById("origin_name").value = name;
  document.getElementById("origin_lat").value = lat;
  document.getElementById("origin_lng").value = lng;
  setLocationStatus("Starting location confirmed with coordinates.", true);
}

window.initGooglePlacesAutocomplete = function () {
  const input = document.getElementById("origin_name");
  if (!input || !window.google || !google.maps || !google.maps.places) return;

  const autocomplete = new google.maps.places.Autocomplete(input, {
    componentRestrictions: { country: "my" },
    fields: ["formatted_address", "geometry", "name"]
  });

  autocomplete.addListener("place_changed", function () {
    const place = autocomplete.getPlace();
    if (!place || !place.geometry || !place.geometry.location) {
      setLocationStatus("Please choose a location from the Google Maps suggestion list.", false);
      return;
    }

    const label = place.formatted_address || place.name || input.value;
    fillOrigin(label, place.geometry.location.lat(), place.geometry.location.lng());
  });

  setLocationStatus("Start typing and choose a suggested location from Google Maps.", null);
};

async function geocodeOriginIfNeeded() {
  const name = document.getElementById("origin_name").value.trim();
  const lat = document.getElementById("origin_lat").value.trim();
  const lng = document.getElementById("origin_lng").value.trim();

  if (name !== "" && lat !== "" && lng !== "") return true;
  if (name === "") return false;

  try {
    setLocationStatus("Searching location coordinates...", null);
    const response = await fetch("geocode_origin.php?q=" + encodeURIComponent(name));
    const data = await response.json();
    if (data && data.lat !== undefined && data.lng !== undefined) {
      fillOrigin(data.address || data.formatted_address || name, data.lat, data.lng);
      return true;
    }
  } catch (error) {}

  setLocationStatus("Location coordinates not found. Please type a clearer starting point or use my location.", false);
  return false;
}

async function reverseGeocodeCurrentLocation(lat, lng) {
  try {
    const response = await fetch("geocode_origin.php?lat=" + encodeURIComponent(lat) + "&lng=" + encodeURIComponent(lng));
    const data = await response.json();
    return data && (data.address || data.formatted_address) ? (data.address || data.formatted_address) : "My Location";
  } catch (error) {
    return "My Location";
  }
}

function appendAiMessage(type, text) {
  const body = document.getElementById("aiChatBody");
  if (!body) return;
  const msg = document.createElement("div");
  msg.className = "ai-msg " + type;
  msg.textContent = text;
  body.appendChild(msg);
  body.scrollTop = body.scrollHeight;
}

async function sendAiQuestion(questionText) {
  const question = String(questionText || "").trim();
  if (question === "") return;

  const prefSelect = document.getElementById("preference_id");
  const startDate = document.getElementById("start_date");
  const originInput = document.getElementById("origin_name");

  appendAiMessage("user", question);
  appendAiMessage("bot", "Thinking...");

  const body = document.getElementById("aiChatBody");
  const thinkingMsg = body ? body.lastElementChild : null;

  try {
    const formData = new FormData();
    formData.append("message", question);
    formData.append("preference_id", prefSelect ? prefSelect.value : "");
    formData.append("start_date", startDate ? startDate.value : "");
    formData.append("origin_name", originInput ? originInput.value : "");

    const response = await fetch("pre_generation_ai_chat.php", {
      method: "POST",
      body: formData
    });
    const data = await response.json();
    if (thinkingMsg) {
      thinkingMsg.textContent = data && data.answer ? data.answer : "AI response is unavailable.";
    }
  } catch (error) {
    if (thinkingMsg) thinkingMsg.textContent = "AI service is currently unavailable. Please try again later.";
  }
}

document.addEventListener("DOMContentLoaded", function () {
  const prefSelect = document.getElementById("preference_id");
  const form = document.getElementById("generatorForm");
  const originInput = document.getElementById("origin_name");
  const currentLocationBtn = document.getElementById("useCurrentLocationBtn");
  const aiForm = document.getElementById("aiChatForm");
  const aiQuestion = document.getElementById("aiQuestion");

  updatePreferenceSummary();

  if (prefSelect) prefSelect.addEventListener("change", updatePreferenceSummary);
  if (originInput) originInput.addEventListener("input", clearOriginCoords);

  if ((fromPreferenceSaved || preselectedPreferenceId > 0) && document.getElementById("start_date")) {
    const startDate = document.getElementById("start_date");
    startDate.scrollIntoView({ behavior: "smooth", block: "center" });
    setTimeout(() => startDate.focus(), 500);
  }

  if (currentLocationBtn) {
    currentLocationBtn.addEventListener("click", function () {
      if (!navigator.geolocation) {
        setLocationStatus("Current location is not supported by this browser.", false);
        return;
      }
      setLocationStatus("Getting current location...", null);
      navigator.geolocation.getCurrentPosition(
        async position => {
          const lat = position.coords.latitude;
          const lng = position.coords.longitude;
          const label = await reverseGeocodeCurrentLocation(lat, lng);
          fillOrigin(label, lat, lng);
        },
        () => setLocationStatus("Unable to get current location. Please search a starting point manually.", false),
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  document.querySelectorAll("[data-ai-prompt]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const prompt = btn.getAttribute("data-ai-prompt") || "";
      if (aiQuestion) aiQuestion.value = prompt;
      sendAiQuestion(prompt);
    });
  });

  if (aiForm) {
    aiForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const question = aiQuestion ? aiQuestion.value : "";
      if (aiQuestion) aiQuestion.value = "";
      sendAiQuestion(question);
    });
  }

  if (form) {
    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      if (!document.getElementById("preference_id").value) {
        alert("Please select a saved travel preference first.");
        return;
      }
      if (!document.getElementById("start_date").value) {
        alert("Please select a start date first.");
        document.getElementById("start_date").focus();
        return;
      }
      const ok = await geocodeOriginIfNeeded();
      if (!ok) {
        document.getElementById("origin_name").focus();
        return;
      }
      form.submit();
    });
  }
});
</script>
<?php if ($googleMapsKey !== ""): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($googleMapsKey); ?>&libraries=places&callback=initGooglePlacesAutocomplete"></script>
<?php endif; ?>
</body>
</html>
