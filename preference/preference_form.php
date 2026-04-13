<?php
// preference/preference_form.php
// Enhanced: district-level selection per state (cascading UI)
session_start();

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

// flash messages
$errors  = $_SESSION["form_errors"]    ?? [];
$success = $_SESSION["success_message"] ?? "";
$old     = $_SESSION["old_input"]       ?? [];

unset($_SESSION["form_errors"], $_SESSION["success_message"], $_SESSION["old_input"]);

// Options
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

// All 16 states with their districts
$stateDistricts = [
  "Johor" => [
    "Johor Bahru","Kluang","Kota Tinggi","Mersing","Muar",
    "Batu Pahat","Pontian","Segamat","Kulai","Tangkak"
  ],
  "Kedah" => [
    "Kota Setar","Kubang Pasu","Padang Terap","Sik","Baling",
    "Kulim","Bandar Baharu","Kuala Muda","Yan","Langkawi",
    "Pokok Sena","Pendang"
  ],
  "Kelantan" => [
    "Kota Bharu","Bachok","Pasir Mas","Tumpat","Pasir Puteh",
    "Machang","Tanah Merah","Kuala Krai","Gua Musang","Jeli"
  ],
  "Melaka" => [
    "Melaka Tengah","Alor Gajah","Jasin"
  ],
  "Negeri Sembilan" => [
    "Seremban","Port Dickson","Rembau","Tampin","Jempol",
    "Jelebu","Kuala Pilah"
  ],
  "Pahang" => [
    "Kuantan","Temerloh","Bentong","Cameron Highlands","Raub",
    "Jerantut","Lipis","Maran","Bera","Rompin","Pekan"
  ],
  "Penang" => [
    "Timur Laut","Barat Daya","Seberang Perai Utara",
    "Seberang Perai Tengah","Seberang Perai Selatan"
  ],
  "Perak" => [
    "Ipoh","Kinta","Larut, Matang & Selama","Manjung","Kerian",
    "Hilir Perak","Hulu Perak","Batang Padang","Perak Tengah","Kampar"
  ],
  "Perlis" => [
    "Kangar","Arau","Padang Besar"
  ],
  "Sabah" => [
    "Kota Kinabalu","Sandakan","Tawau","Lahad Datu","Keningau",
    "Semporna","Kunak","Papar","Beaufort","Kota Belud","Ranau",
    "Kudat","Kinabatangan","Tuaran","Penampang","Putatan",
    "Sipitang","Tambunan","Nabawan","Tongod","Beluran",
    "Kota Marudu","Pitas","Tenom","Kuala Penyu"
  ],
  "Sarawak" => [
    "Kuching","Miri","Sibu","Bintulu","Sri Aman","Sarikei",
    "Kapit","Limbang","Mukah","Betong","Serian","Kota Samarahan"
  ],
  "Selangor" => [
    "Petaling Jaya","Shah Alam","Klang","Subang Jaya","Gombak",
    "Hulu Langat","Hulu Selangor","Kuala Langat","Sabak Bernam"
  ],
  "Terengganu" => [
    "Kuala Terengganu","Kemaman","Dungun","Besut","Setiu",
    "Hulu Terengganu","Marang"
  ],
  "Kuala Lumpur" => [
    "City Centre (KLCC)","Chow Kit","Brickfields","Bangsar","Cheras",
    "Kepong","Setapak","Wangsa Maju","Titiwangsa","Bukit Jalil","Segambut"
  ],
  "Putrajaya" => ["Putrajaya"],
  "Labuan"    => ["Victoria","Labuan Town"],
];

// helpers
function checked_val($arr, $key) {
  return (is_array($arr) && in_array($key, $arr, true)) ? "checked" : "";
}
function selected_val($val, $key) {
  return ($val === $key) ? "selected" : "";
}

$oldTripDays  = $old["trip_days"]       ?? "";
$oldBudget    = $old["budget"]          ?? "";
$oldTransport = $old["transport_type"]  ?? "";
$oldInterests = $old["interests"]       ?? [];
$oldStates    = $old["preferred_states"]    ?? [];
$oldDistricts = $old["preferred_districts"] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Traveller Preference Analyzer | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
  <style>
    /* ---- District cascading panel ---- */
    .state-block { margin-bottom: 10px; border: 1px solid rgba(15,23,42,.08); border-radius: 12px; overflow: hidden; }
    .state-header {
      display: flex; align-items: center; gap: 8px;
      padding: 10px 14px; background: rgba(15,23,42,.03);
      cursor: pointer; user-select: none;
    }
    .state-header label { cursor: pointer; font-weight: 700; font-size: 13px; flex: 1; }
    .state-header .toggle-icon { font-size: 11px; color: var(--muted); transition: transform .2s; }
    .state-header.open .toggle-icon { transform: rotate(90deg); }
    .district-panel {
      display: none; padding: 10px 14px 12px;
      display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 6px;
    }
    .district-panel.hidden { display: none !important; }
    .district-panel label { font-size: 12px; display: flex; gap: 6px; align-items: center; }
    .select-all-btn {
      font-size: 11px; color: var(--primary, #6366f1); cursor: pointer;
      background: none; border: none; padding: 0; margin-left: auto; text-decoration: underline;
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
          <p>Enter your duration, budget and interests. The system will use these preferences to generate a structured cultural itinerary.</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back to Dashboard</a>
        </div>
      </div>

      <section class="grid">
        <div class="card col-12">
          <h3>Preference Form</h3>
          <p class="meta">All fields marked required must be completed before itinerary generation.</p>

          <?php if ($success): ?>
            <p style="color:green; font-weight:700;"><?php echo htmlspecialchars($success); ?></p>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <ul style="color:red; margin:0 0 12px 18px;">
              <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <form method="post" action="preference_process.php">
            <div class="grid">

              <!-- ===== LEFT: Trip Details ===== -->
              <div class="card col-6" style="box-shadow:none;">
                <h3 style="margin-bottom:8px;">Trip Details</h3>

                <label style="font-size:13px; font-weight:700;">Travel Duration (Days) *</label><br>
                <input type="number" name="trip_days" min="1" max="7" required
                  placeholder="1 to 7 days"
                  value="<?php echo htmlspecialchars($oldTripDays); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">

                <div style="height:12px;"></div>

                <label style="font-size:13px; font-weight:700;">Budget (RM) *</label><br>
                <input type="number" name="budget" min="1" step="0.01" required
                  placeholder="Estimated budget in RM"
                  value="<?php echo htmlspecialchars($oldBudget); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">

                <div style="height:12px;"></div>

                <label style="font-size:13px; font-weight:700;">Transport Type *</label><br>
                <select name="transport_type" required
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                  <option value="" disabled <?php echo empty($oldTransport) ? 'selected' : ''; ?>>— Please choose a transport type —</option>
                  <?php foreach ($transportOptions as $k => $v): ?>
                    <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTransport, $k); ?>>
                      <?php echo htmlspecialchars($v); ?>
                    </option>
                  <?php endforeach; ?>
                </select>

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

              <!-- ===== RIGHT: States & Districts ===== -->
              <div class="card col-6" style="box-shadow:none;">
                <h3 style="margin-bottom:4px;">Preferred States &amp; Districts
                  <span style="font-weight:400; font-size:12px; color:var(--muted);"> (Optional)</span>
                </h3>
                <p class="meta" style="margin-top:0; margin-bottom:10px;">
                  Leave all unchecked for nationwide recommendations.
                  Select a state to expand its districts.
                </p>

                <?php foreach ($stateDistricts as $state => $districts): ?>
                  <?php
                    $stateChecked = in_array($state, $oldStates, true);
                    // Check if any district of this state is selected
                    $anyDistrictChecked = false;
                    foreach ($districts as $d) {
                      if (in_array($d, $oldDistricts, true)) { $anyDistrictChecked = true; break; }
                    }
                    $isOpen = $stateChecked || $anyDistrictChecked;
                  ?>
                  <div class="state-block">
                    <div class="state-header <?php echo $isOpen ? 'open' : ''; ?>"
                         onclick="toggleDistricts(this)">
                      <input type="checkbox"
                             name="preferred_states[]"
                             value="<?php echo htmlspecialchars($state); ?>"
                             id="state_<?php echo md5($state); ?>"
                             class="state-cb"
                             <?php echo $stateChecked ? 'checked' : ''; ?>
                             onclick="event.stopPropagation(); handleStateCheck(this)">
                      <label for="state_<?php echo md5($state); ?>">
                        <?php echo htmlspecialchars($state); ?>
                        <span class="meta" style="font-weight:400;">
                          (<?php echo count($districts); ?> districts)
                        </span>
                      </label>
                      <span class="toggle-icon">&#9654;</span>
                    </div>

                    <div class="district-panel <?php echo $isOpen ? '' : 'hidden'; ?>"
                         id="dp_<?php echo md5($state); ?>">
                      <?php foreach ($districts as $d): ?>
                        <label>
                          <input type="checkbox"
                                 name="preferred_districts[]"
                                 value="<?php echo htmlspecialchars($d); ?>"
                                 class="district-cb"
                                 data-state="<?php echo htmlspecialchars($state); ?>"
                                 <?php echo checked_val($oldDistricts, $d); ?>>
                          <?php echo htmlspecialchars($d); ?>
                        </label>
                      <?php endforeach; ?>
                      <div style="grid-column:1/-1; margin-top:4px;">
                        <button type="button" class="select-all-btn"
                                onclick="selectAllDistricts('<?php echo md5($state); ?>', true)">Select all</button>
                        &nbsp;
                        <button type="button" class="select-all-btn"
                                onclick="selectAllDistricts('<?php echo md5($state); ?>', false)">Clear</button>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div><!-- /grid -->

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
              <button class="btn btn-primary" type="submit">Save Preferences</button>
              <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Cancel</a>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>

  <script>
  // Toggle district panel open/close
  function toggleDistricts(header) {
    var stateId = header.querySelector('.state-cb').id.replace('state_', '');
    var panel   = document.getElementById('dp_' + stateId);
    if (!panel) return;
    var isOpen = !panel.classList.contains('hidden');
    if (isOpen) {
      panel.classList.add('hidden');
      header.classList.remove('open');
    } else {
      panel.classList.remove('hidden');
      header.classList.add('open');
    }
  }

  // When state checkbox is checked, auto-expand its district panel
  function handleStateCheck(cb) {
    var stateId = cb.id.replace('state_', '');
    var panel   = document.getElementById('dp_' + stateId);
    var header  = cb.closest('.state-header');
    if (!panel) return;
    if (cb.checked) {
      panel.classList.remove('hidden');
      header.classList.add('open');
    }
  }

  // Select / clear all districts in a panel
  function selectAllDistricts(stateId, check) {
    var panel = document.getElementById('dp_' + stateId);
    if (!panel) return;
    panel.querySelectorAll('.district-cb').forEach(function(cb) {
      cb.checked = check;
    });
    // Also check the state checkbox if selecting all
    if (check) {
      var stateCb = document.getElementById('state_' + stateId);
      if (stateCb) stateCb.checked = true;
    }
  }
  </script>
</body>
</html>
