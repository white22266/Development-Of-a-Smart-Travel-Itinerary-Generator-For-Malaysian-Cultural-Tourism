<?php
// preference/preference_form.php
session_start();
require_once "../config/security.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

$errors  = $_SESSION["form_errors"]     ?? [];
$success = $_SESSION["success_message"] ?? "";
$old     = $_SESSION["old_input"]       ?? [];

unset($_SESSION["form_errors"], $_SESSION["success_message"], $_SESSION["old_input"]);

$interestOptions = [
  "culture"  => "Culture",
  "heritage" => "Heritage",
  "food"     => "Food",
  "museum"   => "Museum",
  "nature"   => "Nature",
  "shopping" => "Shopping",
  "festival" => "Festival"
];

$transportOptions = [
  "car"              => "Car",
  "public_transport" => "Public Transport",
  "walking"          => "Walking",
  "motorcycle"       => "Motorcycle"
];

$travellerTypeOptions = [
  "solo"   => "Solo",
  "couple" => "Couple",
  "family" => "Family",
  "group"  => "Group"
];

$paceOptions = [
  "relaxed" => "Relaxed",
  "normal"  => "Normal",
  "packed"  => "Packed"
];

$budgetTierOptions = [
  "budget" => "Budget",
  "normal" => "Normal",
  "luxury" => "Luxury"
];

$dietaryOptions = [
  "none"       => "No specific requirement",
  "halal"      => "Halal required",
  "vegetarian" => "Vegetarian friendly"
];

$visitTimeOptions = [
  "any"       => "Any time",
  "morning"   => "Morning",
  "afternoon" => "Afternoon",
  "evening"   => "Evening"
];

// All 16 states with their districts
$stateDistricts = [
  "Johor"            => ["Johor Bahru","Kluang","Kota Tinggi","Mersing","Muar","Batu Pahat","Pontian","Segamat","Kulai","Tangkak"],
  "Kedah"            => ["Kota Setar","Kubang Pasu","Padang Terap","Sik","Baling","Kulim","Bandar Baharu","Kuala Muda","Yan","Langkawi","Pokok Sena","Pendang"],
  "Kelantan"         => ["Kota Bharu","Bachok","Pasir Mas","Tumpat","Pasir Puteh","Machang","Tanah Merah","Kuala Krai","Gua Musang","Jeli"],
  "Melaka"           => ["Melaka Tengah","Alor Gajah","Jasin"],
  "Negeri Sembilan"  => ["Seremban","Port Dickson","Rembau","Tampin","Jempol","Jelebu","Kuala Pilah"],
  "Pahang"           => ["Kuantan","Temerloh","Bentong","Cameron Highlands","Raub","Jerantut","Lipis","Maran","Bera","Rompin","Pekan"],
  "Penang"           => ["Timur Laut","Barat Daya","Seberang Perai Utara","Seberang Perai Tengah","Seberang Perai Selatan"],
  "Perak"            => ["Ipoh","Kinta","Larut, Matang & Selama","Manjung","Kerian","Hilir Perak","Hulu Perak","Batang Padang","Perak Tengah","Kampar"],
  "Perlis"           => ["Kangar","Arau","Padang Besar"],
  "Sabah"            => ["Kota Kinabalu","Sandakan","Tawau","Lahad Datu","Keningau","Semporna","Kunak","Papar","Beaufort","Kota Belud","Ranau","Kudat","Kinabatangan","Tuaran","Penampang","Putatan","Sipitang","Tambunan","Nabawan","Tongod","Beluran","Kota Marudu","Pitas","Tenom","Kuala Penyu"],
  "Sarawak"          => ["Kuching","Miri","Sibu","Bintulu","Sri Aman","Sarikei","Kapit","Limbang","Mukah","Betong","Serian","Kota Samarahan"],
  "Selangor"         => ["Petaling Jaya","Shah Alam","Klang","Subang Jaya","Gombak","Hulu Langat","Hulu Selangor","Kuala Langat","Sabak Bernam"],
  "Terengganu"       => ["Kuala Terengganu","Kemaman","Dungun","Besut","Setiu","Hulu Terengganu","Marang"],
  "Kuala Lumpur"     => ["City Centre (KLCC)","Chow Kit","Brickfields","Bangsar","Cheras","Kepong","Setapak","Wangsa Maju","Titiwangsa","Bukit Jalil","Segambut"],
  "Putrajaya"        => ["Putrajaya"],
  "Labuan"           => ["Victoria","Labuan Town"],
];

function selected_val($val, $key) {
  return ($val === $key) ? "selected" : "";
}
function checked_val($arr, $key) {
  return (is_array($arr) && in_array($key, $arr, true)) ? "checked" : "";
}

$oldTripDays  = $old["trip_days"]           ?? "";
$oldBudget    = $old["budget"]              ?? "";
$oldBudgetTier = $old["budget_tier"]        ?? "normal";
$oldTransport = $old["transport_type"]      ?? "";
$oldTravellerType = $old["traveller_type"]  ?? "solo";
$oldPartySize = (int)($old["party_size"] ?? match ($oldTravellerType) {
  "couple" => 2,
  "family" => 4,
  "group" => 5,
  default => 1,
});
$oldTravelPace = $old["travel_pace"]        ?? "normal";
$oldDietary = $old["dietary_preference"]    ?? "none";
$oldVisitTime = $old["preferred_visit_time"] ?? "any";
$oldAccessibility = $old["accessibility_needs"] ?? "";
$oldInterests = $old["interests"]           ?? [];
$oldState     = $old["preferred_states"]    ?? "";   // single value now
$oldDistrict  = $old["preferred_districts"] ?? "";   // single value now

// Encode districts map for JS
$districtsJson = json_encode($stateDistricts, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Traveller Preference Analyzer | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
  <style>
    /* ---- Dropdown pair ---- */
    .location-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      align-items: start;
    }
    @media (max-width: 640px) {
      .location-row { grid-template-columns: 1fr; }
    }
    .field-group label {
      font-size: 13px;
      font-weight: 700;
      display: block;
      margin-bottom: 6px;
    }
    .field-group select {
      width: 100%;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid rgba(15,23,42,0.10);
      font-size: 13px;
      background: #fff;
      cursor: pointer;
      transition: border-color .15s, background .15s;
    }
    .field-group select:disabled {
      background: rgba(15,23,42,0.04);
      color: rgba(15,23,42,0.35);
      cursor: not-allowed;
      border-color: rgba(15,23,42,0.06);
    }
    /* Warning banner under district dropdown */
    .district-warning {
      display: none;
      margin-top: 6px;
      padding: 7px 10px;
      border-radius: 8px;
      background: rgba(245,158,11,0.10);
      border: 1px solid rgba(245,158,11,0.30);
      color: #b45309;
      font-size: 12px;
      font-weight: 600;
    }
    .district-warning.visible { display: block; }
    .district-hint {
      font-size: 11px;
      color: var(--muted);
      margin-top: 5px;
    }
    .wizard-progress {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin: 14px 0;
    }
    .wizard-step-tab {
      border: 1px solid rgba(15,23,42,0.10);
      border-radius: 10px;
      padding: 9px 10px;
      font-size: 12px;
      font-weight: 800;
      color: var(--muted);
      background: #fff;
    }
    .wizard-step-tab.active {
      color: #fff;
      background: #4f46e5;
      border-color: #4f46e5;
    }
    .wizard-panel { display: none; }
    .wizard-panel.active { display: block; }
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
    .summary-item strong { display:block; margin-bottom:4px; }
    .field-help {
      font-size: 11px;
      color: var(--muted);
      margin-top: 5px;
      line-height: 1.45;
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
        <span>Traveller Preference Analyzer</span>
      </div>
    </div>

    <nav class="nav" aria-label="Sidebar Navigation">
      <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
      <a class="active" href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
      <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
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
        <h1>Traveller Preference Analyzer</h1>
        <p>Enter your trip budget, travel style, location and interests. The system will use these preferences to generate a structured cultural itinerary.</p>
      </div>
      <div class="actions">
        <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back to Dashboard</a>
      </div>
    </div>

    <section class="grid">
      <div class="card col-12">
        <h3>Preference Form</h3>
        <p class="meta">All fields marked * must be completed before itinerary generation.</p>

        <?php if ($success): ?>
          <p style="color:green; font-weight:700; margin-bottom:10px;"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <ul style="color:red; margin:0 0 12px 18px;">
            <?php foreach ($errors as $e): ?>
              <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="post" action="preference_process.php" id="preferenceWizardForm">
          <div class="wizard-progress" aria-label="Preference steps">
            <div class="wizard-step-tab active" data-step-tab="1">1. Trip Basics</div>
            <div class="wizard-step-tab" data-step-tab="2">2. Preferred Location</div>
            <div class="wizard-step-tab" data-step-tab="3">3. Review & Save</div>
          </div>
          <div class="grid">

            <!-- ===== LEFT: Trip Details ===== -->
            <div class="card col-6 wizard-panel active" data-step-panel="1" style="box-shadow:none;">
              <h3 style="margin-bottom:8px;">Trip Basics & Travel Style</h3>

              <label style="font-size:13px; font-weight:700;">Travel Duration (Days) *</label>
              <input type="number" name="trip_days" min="1" max="30" required
                placeholder="1 to 30 days"
                value="<?php echo htmlspecialchars($oldTripDays); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Budget (RM) *</label>
              <input type="number" name="budget" min="1" step="0.01" required
                placeholder="Estimated budget in RM"
                value="<?php echo htmlspecialchars($oldBudget); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
              <div class="field-help">
                Your total trip budget. The generator uses it to choose lower-cost places, meals, transport estimates, and hotel assumptions before checking the final cost.
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Transport Type *</label>
              <select name="transport_type" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <option value="" disabled <?php echo empty($oldTransport) ? 'selected' : ''; ?>>- Please choose a transport type -</option>
                <?php foreach ($transportOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTransport, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Spending Style *</label>
              <select name="budget_tier" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($budgetTierOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldBudgetTier, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-help">
                This is not another budget amount. It controls default hotel and meal estimates: Budget = cheaper, Normal = standard, Luxury = higher comfort.
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Traveller Type *</label>
              <select name="traveller_type" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($travellerTypeOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTravellerType, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <div id="partySizeGroup">
                <label style="font-size:13px; font-weight:700;">Number of Travellers / Party Size *</label>
                <input type="number" name="party_size" min="1" max="1000" step="1" required
                  value="<?php echo htmlspecialchars((string)$oldPartySize); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <div class="field-help" id="partySizeHelp">
                  Solo is fixed to 1 traveller and couple is fixed to 2 travellers. Family and group trips must enter the actual party size.
                </div>
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Travel Pace *</label>
              <select name="travel_pace" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($paceOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTravelPace, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Dietary Preference *</label>
              <select name="dietary_preference" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($dietaryOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldDietary, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Preferred Visit Time *</label>
              <select name="preferred_visit_time" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($visitTimeOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldVisitTime, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;">Accessibility Notes</label>
              <input type="text" name="accessibility_needs" maxlength="120"
                placeholder="Example: elderly-friendly, avoid stairs"
                value="<?php echo htmlspecialchars($oldAccessibility); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
              <div class="field-help">The system automatically analyzes this note when saving and applies it to route planning.</div>

              <hr class="sep">

              <h3 style="margin-bottom:8px;">Interests *</h3>
              <p class="meta" style="margin-top:0;">Select at least one interest.</p>
              <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;">
                <?php foreach ($interestOptions as $key => $label): ?>
                  <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                    <input type="checkbox" name="interests[]" value="<?php echo htmlspecialchars($key); ?>"
                      <?php echo checked_val($oldInterests, $key); ?>>
                    <?php echo htmlspecialchars($label); ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- ===== RIGHT: State & District dropdowns ===== -->
            <div class="card col-6 wizard-panel" data-step-panel="2" style="box-shadow:none;">
              <h3 style="margin-bottom:4px;">
                Preferred Location
                <span style="font-weight:400; font-size:12px; color:var(--muted);"> (Optional)</span>
              </h3>
              <p class="meta" style="margin-top:0; margin-bottom:14px;">
                Leave both as "Any" for nationwide recommendations. Select a state first, then optionally narrow down to a specific district.
              </p>

              <div class="location-row">

                <!-- State dropdown -->
                <div class="field-group">
                  <label for="preferred_state">State</label>
                  <select name="preferred_states" id="preferred_state">
                    <option value="">- Any State (Nationwide) -</option>
                    <?php foreach (array_keys($stateDistricts) as $state): ?>
                      <option value="<?php echo htmlspecialchars($state); ?>"
                        <?php echo ($oldState === $state) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($state); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="district-hint">Choose a state to enable district selection.</div>
                </div>

                <!-- District dropdown -->
                <div class="field-group">
                  <label for="preferred_district">District</label>
                  <select name="preferred_districts" id="preferred_district"
                    <?php echo ($oldState === "") ? 'disabled' : ''; ?>>
                    <option value="">- Any District -</option>
                    <?php
                    // Pre-populate districts if a state was previously selected
                    if ($oldState !== "" && isset($stateDistricts[$oldState])) {
                      foreach ($stateDistricts[$oldState] as $d) {
                        $sel = ($oldDistrict === $d) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($d) . '" ' . $sel . '>' . htmlspecialchars($d) . '</option>';
                      }
                    }
                    ?>
                  </select>
                  <!-- Warning: shown when user clicks district before selecting state -->
                  <div class="district-warning" id="districtWarning">
                    &#9888; Please select a state first before choosing a district.
                  </div>
                  <div class="district-hint" id="districtHint">
                    <?php echo ($oldState === "") ? 'Select a state above to enable this dropdown.' : 'Optionally narrow to a specific district.'; ?>
                  </div>
                </div>

              </div><!-- /location-row -->
            </div>

            <div class="card col-12 wizard-panel" data-step-panel="3" style="box-shadow:none;">
              <h3 style="margin-bottom:8px;">Review Preference</h3>
              <p class="meta" style="margin-top:0;">Confirm these criteria before saving. The system stores them in normalized preference tables and keeps legacy fields for existing itinerary generation.</p>
              <div class="summary-list">
                <div class="summary-item"><strong>Travel Duration</strong><span id="summaryDays">-</span></div>
                <div class="summary-item"><strong>Budget</strong><span id="summaryBudget">-</span></div>
                <div class="summary-item"><strong>Spending Style</strong><span id="summaryBudgetTier">-</span></div>
                <div class="summary-item"><strong>Transport</strong><span id="summaryTransport">-</span></div>
                <div class="summary-item"><strong>Traveller Type</strong><span id="summaryTravellerType">-</span></div>
                <div class="summary-item"><strong>Party Size</strong><span id="summaryPartySize">-</span></div>
                <div class="summary-item"><strong>Travel Pace</strong><span id="summaryPace">-</span></div>
                <div class="summary-item"><strong>Dietary</strong><span id="summaryDietary">-</span></div>
                <div class="summary-item"><strong>Visit Time</strong><span id="summaryVisitTime">-</span></div>
                <div class="summary-item"><strong>Interests</strong><span id="summaryInterests">-</span></div>
                <div class="summary-item"><strong>State</strong><span id="summaryState">Any State</span></div>
                <div class="summary-item"><strong>District</strong><span id="summaryDistrict">Any District</span></div>
                <div class="summary-item"><strong>Accessibility</strong><span id="summaryAccessibility">-</span></div>
              </div>
            </div>

          </div><!-- /grid -->

          <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-ghost" type="button" id="wizardBack" style="display:none;">Back</button>
            <button class="btn btn-primary" type="button" id="wizardNext">Next</button>
            <button class="btn btn-primary" type="submit" id="wizardSubmit" style="display:none;">Save Preferences</button>
            <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Cancel</a>
          </div>
        </form>
      </div>
    </section>
  </main>
</div>

<script>
(function () {
  var stateDistricts = <?php echo $districtsJson; ?>;

  var stateSel    = document.getElementById('preferred_state');
  var distSel     = document.getElementById('preferred_district');
  var distWarn    = document.getElementById('districtWarning');
  var distHint    = document.getElementById('districtHint');

  if (!stateSel || !distSel) return;

  /* ---- Populate districts when state changes ---- */
  stateSel.addEventListener('change', function () {
    var state = stateSel.value;
    populateDistricts(state);
  });

  /* ---- Show warning if user focuses district while state is empty ---- */
  distSel.addEventListener('focus', function () {
    if (stateSel.value === '') {
      distWarn.classList.add('visible');
      // Auto-hide after 3 seconds
      setTimeout(function () { distWarn.classList.remove('visible'); }, 3000);
    }
  });
  distSel.addEventListener('mousedown', function () {
    if (stateSel.value === '') {
      distWarn.classList.add('visible');
      setTimeout(function () { distWarn.classList.remove('visible'); }, 3000);
    }
  });

  function populateDistricts(state) {
    // Clear existing options
    distSel.innerHTML = '<option value="">- Any District -</option>';

    if (!state || !stateDistricts[state]) {
      // No state selected - disable and show hint
      distSel.disabled = true;
      distHint.textContent = 'Select a state above to enable this dropdown.';
      distWarn.classList.remove('visible');
      return;
    }

    // Populate districts for selected state
    var districts = stateDistricts[state];
    districts.forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = d;
      opt.textContent = d;
      distSel.appendChild(opt);
    });

    distSel.disabled = false;
    distWarn.classList.remove('visible');
    distHint.textContent = 'Optionally narrow to a specific district in ' + state + '.';
  }

  var currentStep = 1;
  var backBtn = document.getElementById('wizardBack');
  var nextBtn = document.getElementById('wizardNext');
  var submitBtn = document.getElementById('wizardSubmit');
  var travellerTypeSel = document.querySelector('[name="traveller_type"]');
  var partySizeInput = document.querySelector('[name="party_size"]');
  var partySizeGroup = document.getElementById('partySizeGroup');
  var partySizeHelp = document.getElementById('partySizeHelp');

  function syncPartySize() {
    if (!travellerTypeSel || !partySizeInput || !partySizeGroup) return;
    var type = travellerTypeSel.value || 'solo';
    if (type === 'solo') {
      partySizeInput.value = '1';
      partySizeInput.readOnly = true;
      partySizeGroup.style.display = 'none';
    } else if (type === 'couple') {
      partySizeInput.value = '2';
      partySizeInput.readOnly = true;
      partySizeGroup.style.display = 'none';
    } else {
      partySizeInput.readOnly = false;
      partySizeGroup.style.display = '';
      if (!partySizeInput.value || Number(partySizeInput.value) < 1) {
        partySizeInput.value = type === 'family' ? '4' : '5';
      }
      if (partySizeHelp) {
        partySizeHelp.textContent = type === 'family'
          ? 'Enter the actual number of family members travelling together.'
          : 'Enter the actual group size. This controls transport, meals, attraction tickets, and hotel room estimates.';
      }
    }
  }

  function showStep(step) {
    currentStep = step;
    document.querySelectorAll('[data-step-panel]').forEach(function(panel) {
      panel.classList.toggle('active', panel.getAttribute('data-step-panel') === String(step));
    });
    document.querySelectorAll('[data-step-tab]').forEach(function(tab) {
      tab.classList.toggle('active', tab.getAttribute('data-step-tab') === String(step));
    });
    if (backBtn) backBtn.style.display = step === 1 ? 'none' : '';
    if (nextBtn) nextBtn.style.display = step === 3 ? 'none' : '';
    if (submitBtn) submitBtn.style.display = step === 3 ? '' : 'none';
    if (step === 3) updateSummary();
  }

  function stepValid(step) {
    if (step === 1) {
      var days = document.querySelector('[name="trip_days"]');
      var budget = document.querySelector('[name="budget"]');
      var transport = document.querySelector('[name="transport_type"]');
      var partySize = document.querySelector('[name="party_size"]');
      var checked = document.querySelectorAll('[name="interests[]"]:checked');
      if (!days.reportValidity() || !budget.reportValidity() || !transport.reportValidity()) return false;
      if (partySize && !partySize.reportValidity()) return false;
      if (checked.length === 0) {
        alert('Please select at least one interest.');
        return false;
      }
    }
    return true;
  }

  function selectedText(sel) {
    if (!sel || sel.selectedIndex < 0) return '';
    return sel.options[sel.selectedIndex].textContent.trim();
  }

  function updateSummary() {
    var days = document.querySelector('[name="trip_days"]').value || '-';
    var budget = document.querySelector('[name="budget"]').value || '-';
    var transport = selectedText(document.querySelector('[name="transport_type"]')) || '-';
    var budgetTier = selectedText(document.querySelector('[name="budget_tier"]')) || '-';
    var travellerType = selectedText(document.querySelector('[name="traveller_type"]')) || '-';
    var partySize = document.querySelector('[name="party_size"]').value || '-';
    var pace = selectedText(document.querySelector('[name="travel_pace"]')) || '-';
    var dietary = selectedText(document.querySelector('[name="dietary_preference"]')) || '-';
    var visitTime = selectedText(document.querySelector('[name="preferred_visit_time"]')) || '-';
    var accessibility = document.querySelector('[name="accessibility_needs"]').value || '-';
    var interests = Array.prototype.map.call(document.querySelectorAll('[name="interests[]"]:checked'), function(el) {
      return el.parentNode.textContent.trim();
    }).join(', ') || '-';
    document.getElementById('summaryDays').textContent = days + ' day(s)';
    document.getElementById('summaryBudget').textContent = budget === '-' ? '-' : 'RM ' + budget;
    document.getElementById('summaryBudgetTier').textContent = budgetTier;
    document.getElementById('summaryTransport').textContent = transport;
    document.getElementById('summaryTravellerType').textContent = travellerType;
    document.getElementById('summaryPartySize').textContent = partySize === '-' ? '-' : partySize + ' traveller(s)';
    document.getElementById('summaryPace').textContent = pace;
    document.getElementById('summaryDietary').textContent = dietary;
    document.getElementById('summaryVisitTime').textContent = visitTime;
    document.getElementById('summaryInterests').textContent = interests;
    document.getElementById('summaryState').textContent = selectedText(stateSel) || 'Any State';
    document.getElementById('summaryDistrict').textContent = selectedText(distSel) || 'Any District';
    document.getElementById('summaryAccessibility').textContent = accessibility;
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function() {
      if (!stepValid(currentStep)) return;
      showStep(Math.min(3, currentStep + 1));
    });
  }
  if (backBtn) {
    backBtn.addEventListener('click', function() {
      showStep(Math.max(1, currentStep - 1));
    });
  }
  if (travellerTypeSel) {
    travellerTypeSel.addEventListener('change', syncPartySize);
  }
  syncPartySize();
  showStep(1);
})();
</script>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>
</html>

