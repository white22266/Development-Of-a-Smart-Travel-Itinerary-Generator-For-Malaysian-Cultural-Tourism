<?php
// preference/preference_form.php
session_start();
require_once "../config/security.php";
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once __DIR__ . "/preference_inputs.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');

$errors  = $_SESSION["form_errors"]     ?? [];
$success = $_SESSION["success_message"] ?? "";
$old     = $_SESSION["old_input"]       ?? [];

unset($_SESSION["form_errors"], $_SESSION["success_message"], $_SESSION["old_input"]);

$interestOptions = [];
foreach (["culture", "heritage", "food", "museum", "nature", "shopping", "festival"] as $code) {
  $interestOptions[$code] = t('opt.interest.' . $code);
}

$transportOptions = [];
foreach (["car", "public_transport", "walking", "motorcycle"] as $code) {
  $transportOptions[$code] = t('opt.transport.' . $code);
}

$travellerTypeOptions = [];
foreach (["solo", "couple", "family", "group"] as $code) {
  $travellerTypeOptions[$code] = t('opt.type.' . $code);
}

$paceOptions = [];
foreach (["relaxed", "normal", "packed"] as $code) {
  $paceOptions[$code] = t('opt.pace.' . $code);
}

$budgetTierOptions = [];
foreach (["budget", "normal", "luxury"] as $code) {
  $budgetTierOptions[$code] = t('opt.tier.' . $code);
}

$dietaryOptions = [];
foreach (["none", "halal", "vegetarian"] as $code) {
  $dietaryOptions[$code] = t('opt.diet.' . $code);
}

$visitTimeOptions = [];
foreach (["any", "morning", "afternoon", "evening"] as $code) {
  $visitTimeOptions[$code] = t('opt.visit.' . $code);
}

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
$oldWeights   = is_array($old["interest_weight"] ?? null) ? $old["interest_weight"] : [];
$oldDayStart  = (string)($old["day_start_time"] ?? "");
$oldDayEnd    = (string)($old["day_end_time"] ?? "");
$oldStops     = (string)($old["stops_per_day"] ?? "");
$oldAdults    = (string)($old["adults"] ?? "");
$oldChildren  = (string)($old["children"] ?? "");
$oldSeniors   = (string)($old["seniors"] ?? "");
$oldAccessTags = is_array($old["access_tags"] ?? null) ? $old["access_tags"] : [];
$accessTagOptions = [];
foreach (["wheelchair", "avoid_stairs", "low_walking", "avoid_heat", "elderly"] as $code) {
  $accessTagOptions[$code] = t('opt.access.' . $code);
}
$weightOptions = [1 => t('opt.weight.1'), 2 => t('opt.weight.2'), 3 => t('opt.weight.3')];

// Places chosen before a validation error: look the names up again.
$oldPlaces = ["must" => [], "avoid" => []];
foreach (["must" => "must_places", "avoid" => "avoid_places"] as $wish => $field) {
  $ids = pref_place_ids($old[$field] ?? []);
  if (!$ids) continue;
  $marks = implode(",", array_fill(0, count($ids), "?"));
  $lookup = $conn->prepare("SELECT place_id, name, state FROM cultural_places WHERE is_active = 1 AND place_id IN ($marks)");
  $lookup->bind_param(str_repeat("i", count($ids)), ...$ids);
  $lookup->execute();
  $oldPlaces[$wish] = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
  $lookup->close();
}

function render_place_picker(string $wish, string $label, string $help, array $chosen): void {
  $field = $wish . "_places";
  echo '<div class="place-picker" data-wish="' . $wish . '" data-field="' . $field . '">';
  echo '<label style="font-size:13px; font-weight:700;" for="picker_' . $wish . '">' . htmlspecialchars($label) . '</label>';
  echo '<input type="search" id="picker_' . $wish . '" class="place-picker-input" autocomplete="off" placeholder="' . htmlspecialchars(t('pref.place_search_placeholder')) . '" '
     . 'style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">';
  echo '<div class="place-picker-results" role="listbox"></div>';
  echo '<div class="place-picker-chosen">';
  foreach ($chosen as $row) {
    $id = (int)$row["place_id"];
    echo '<span class="place-chip" data-id="' . $id . '">' . htmlspecialchars($row["name"])
       . ' <button type="button" class="place-chip-remove" aria-label="' . htmlspecialchars(t('common.remove')) . '">&times;</button>'
       . '<input type="hidden" name="' . $field . '[]" value="' . $id . '"></span>';
  }
  echo '</div>';
  echo '<div class="field-help">' . htmlspecialchars($help) . '</div>';
  echo '</div>';
}
$oldState     = $old["preferred_states"]    ?? "";   // single value now
$oldDistrict  = $old["preferred_districts"] ?? "";   // single value now

// Encode districts map for JS
$districtsJson = json_encode($stateDistricts, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo te('nav.preference'); ?> | Smart Travel Itinerary Generator</title>
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
    .inline-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .inline-fields label { font-size: 12px; font-weight: 700; display: block; }
    .interest-row { display: flex; gap: 8px; align-items: center; justify-content: space-between; font-size: 13px; }
    .interest-row select { padding: 4px 8px; border-radius: 8px; border: 1px solid rgba(15,23,42,0.10); font-size: 12px; background: #fff; }
    .interest-row select:disabled { opacity: .45; }
    .place-picker { margin-top: 14px; position: relative; }
    .place-picker-results { display: none; border: 1px solid rgba(15,23,42,0.10); border-radius: 10px; background: #fff; margin-top: 4px; max-height: 220px; overflow-y: auto; }
    .place-picker-results.open { display: block; }
    .place-picker-results button { display: block; width: 100%; text-align: left; border: 0; background: transparent; padding: 8px 10px; font-size: 12px; cursor: pointer; }
    .place-picker-results button:hover { background: rgba(79,70,229,0.08); }
    .place-picker-results .place-note { padding: 8px 10px; font-size: 12px; color: var(--muted); }
    .place-picker-chosen { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .place-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: rgba(79,70,229,0.10); font-size: 12px; font-weight: 600; }
    .place-chip-remove { border: 0; background: transparent; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; }
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
        <span><?php echo te('nav.preference'); ?></span>
      </div>
    </div>

    <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
      <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
      <a class="active" href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
      <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
      <a href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
      <a href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
      <a href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
      <a href="../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
    </nav>
    <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>

    <div class="sidebar-footer">
      <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
      <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
      <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
    </div>
  </aside>

  <main class="content">
    <div class="topbar">
      <div class="page-title">
        <h1><?php echo te('nav.preference'); ?></h1>
        <p><?php echo te('pref.page_subtitle'); ?></p>
      </div>
      <div class="actions">
        <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php"><?php echo te('common.back_dashboard'); ?></a>
      </div>
    </div>

    <section class="grid">
      <div class="card col-12">
        <h3><?php echo te('pref.form_title'); ?></h3>
        <p class="meta"><?php echo te('pref.form_note'); ?></p>

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
          <div class="wizard-progress" aria-label="<?php echo te('pref.steps_aria'); ?>">
            <div class="wizard-step-tab active" data-step-tab="1"><?php echo te('pref.step1'); ?></div>
            <div class="wizard-step-tab" data-step-tab="2"><?php echo te('pref.step2'); ?></div>
            <div class="wizard-step-tab" data-step-tab="3"><?php echo te('pref.step3'); ?></div>
          </div>
          <div class="grid">

            <!-- ===== LEFT: Trip Details ===== -->
            <div class="card col-6 wizard-panel active" data-step-panel="1" style="box-shadow:none;">
              <h3 style="margin-bottom:8px;"><?php echo te('pref.basics_heading'); ?></h3>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.days_label'); ?> *</label>
              <input type="number" name="trip_days" min="1" max="30" required
                placeholder="<?php echo te('pref.days_placeholder'); ?>"
                value="<?php echo htmlspecialchars($oldTripDays); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.budget_label'); ?> *</label>
              <input type="number" name="budget" min="1" step="0.01" required
                placeholder="<?php echo te('pref.budget_placeholder'); ?>"
                value="<?php echo htmlspecialchars($oldBudget); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
              <div class="field-help">
                <?php echo te('pref.budget_help'); ?>
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.transport_label'); ?> *</label>
              <select name="transport_type" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <option value="" disabled <?php echo empty($oldTransport) ? 'selected' : ''; ?>><?php echo te('pref.transport_choose'); ?></option>
                <?php foreach ($transportOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTransport, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.tier_label'); ?> *</label>
              <select name="budget_tier" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($budgetTierOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldBudgetTier, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-help">
                <?php echo te('pref.tier_help'); ?>
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.type_label'); ?> *</label>
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
                <label style="font-size:13px; font-weight:700;"><?php echo te('pref.party_label'); ?> *</label>
                <input type="number" name="party_size" min="1" max="1000" step="1" required
                  value="<?php echo htmlspecialchars((string)$oldPartySize); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <div class="field-help" id="partySizeHelp">
                  <?php echo te('pref.party_help'); ?>
                </div>
              </div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.who_label'); ?></label>
              <div class="inline-fields" style="margin-top:6px;">
                <div><label for="adults"><?php echo te('pref.adults'); ?></label>
                  <input type="number" name="adults" id="adults" min="0" max="20" step="1" value="<?php echo htmlspecialchars($oldAdults); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;"></div>
                <div><label for="children"><?php echo te('pref.children'); ?></label>
                  <input type="number" name="children" id="children" min="0" max="20" step="1" value="<?php echo htmlspecialchars($oldChildren); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;"></div>
                <div><label for="seniors"><?php echo te('pref.seniors'); ?></label>
                  <input type="number" name="seniors" id="seniors" min="0" max="20" step="1" value="<?php echo htmlspecialchars($oldSeniors); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;"></div>
              </div>
              <div class="field-help"><?php echo te('pref.who_help'); ?></div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.pace_label'); ?> *</label>
              <select name="travel_pace" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($paceOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldTravelPace, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.dietary_label'); ?> *</label>
              <select name="dietary_preference" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($dietaryOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldDietary, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.visit_label'); ?> *</label>
              <select name="preferred_visit_time" required
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
                <?php foreach ($visitTimeOptions as $k => $v): ?>
                  <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected_val($oldVisitTime, $k); ?>>
                    <?php echo htmlspecialchars($v); ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.schedule_label'); ?></label>
              <div class="location-row" style="margin-top:6px;">
                <div class="field-group"><label for="day_start_time"><?php echo te('pref.day_start'); ?></label>
                  <input type="time" name="day_start_time" id="day_start_time" min="06:00" max="23:00" value="<?php echo htmlspecialchars($oldDayStart); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;"></div>
                <div class="field-group"><label for="day_end_time"><?php echo te('pref.day_end'); ?></label>
                  <input type="time" name="day_end_time" id="day_end_time" min="06:00" max="23:00" value="<?php echo htmlspecialchars($oldDayEnd); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;"></div>
              </div>
              <div class="field-help"><?php echo te('pref.schedule_help'); ?></div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;" for="stops_per_day"><?php echo te('pref.stops_label'); ?></label>
              <input type="number" name="stops_per_day" id="stops_per_day" min="2" max="8" step="1" placeholder="<?php echo te('pref.stops_placeholder'); ?>"
                value="<?php echo htmlspecialchars($oldStops); ?>" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
              <div class="field-help"><?php echo te('pref.stops_help'); ?></div>

              <div style="height:12px;"></div>

              <label style="font-size:13px; font-weight:700;"><?php echo te('pref.access_label'); ?></label>
              <input type="text" name="accessibility_needs" maxlength="120"
                placeholder="<?php echo te('pref.access_placeholder'); ?>"
                value="<?php echo htmlspecialchars($oldAccessibility); ?>"
                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:6px; font-size:13px;">
              <div class="field-help"><?php echo te('pref.access_help'); ?></div>
              <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin-top:8px;">
                <?php foreach ($accessTagOptions as $key => $label): ?>
                  <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                    <input type="checkbox" name="access_tags[]" value="<?php echo htmlspecialchars($key); ?>"
                      <?php echo checked_val($oldAccessTags, $key); ?>>
                    <?php echo htmlspecialchars($label); ?>
                  </label>
                <?php endforeach; ?>
              </div>

              <hr class="sep">

              <h3 style="margin-bottom:8px;"><?php echo te('pref.interests_heading'); ?> *</h3>
              <p class="meta" style="margin-top:0;"><?php echo te('pref.interests_help'); ?></p>
              <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;">
                <?php foreach ($interestOptions as $key => $label): ?>
                  <div class="interest-row">
                    <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                      <input type="checkbox" name="interests[]" value="<?php echo htmlspecialchars($key); ?>"
                        <?php echo checked_val($oldInterests, $key); ?>>
                      <?php echo htmlspecialchars($label); ?>
                    </label>
                    <select name="interest_weight[<?php echo htmlspecialchars($key); ?>]" aria-label="<?php echo te('pref.interest_level_aria', ['interest' => $label]); ?>">
                      <?php foreach ($weightOptions as $w => $wLabel): ?>
                        <option value="<?php echo $w; ?>" <?php echo ((int)($oldWeights[$key] ?? 2) === $w) ? "selected" : ""; ?>><?php echo htmlspecialchars($wLabel); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- ===== RIGHT: State & District dropdowns ===== -->
            <div class="card col-6 wizard-panel" data-step-panel="2" style="box-shadow:none;">
              <h3 style="margin-bottom:4px;">
                <?php echo te('pref.location_heading'); ?>
                <span style="font-weight:400; font-size:12px; color:var(--muted);"> <?php echo te('common.optional'); ?></span>
              </h3>
              <p class="meta" style="margin-top:0; margin-bottom:14px;">
                <?php echo te('pref.location_help'); ?>
              </p>

              <div class="location-row">

                <!-- State dropdown -->
                <div class="field-group">
                  <label for="preferred_state"><?php echo te('pref.state_label'); ?></label>
                  <select name="preferred_states" id="preferred_state">
                    <option value=""><?php echo te('pref.any_state'); ?></option>
                    <?php foreach (array_keys($stateDistricts) as $state): ?>
                      <option value="<?php echo htmlspecialchars($state); ?>"
                        <?php echo ($oldState === $state) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($state); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="district-hint"><?php echo te('pref.state_hint'); ?></div>
                </div>

                <!-- District dropdown -->
                <div class="field-group">
                  <label for="preferred_district"><?php echo te('pref.district_label'); ?></label>
                  <select name="preferred_districts" id="preferred_district"
                    <?php echo ($oldState === "") ? 'disabled' : ''; ?>>
                    <option value=""><?php echo te('pref.any_district'); ?></option>
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
                    &#9888; <?php echo te('pref.district_warning'); ?>
                  </div>
                  <div class="district-hint" id="districtHint">
                    <?php echo ($oldState === "") ? te('pref.district_hint_off') : te('pref.district_hint_on'); ?>
                  </div>
                </div>

              </div><!-- /location-row -->

              <hr class="sep">
              <h3 style="margin-bottom:4px;"><?php echo te('pref.places_heading'); ?> <span style="font-weight:400; font-size:12px; color:var(--muted);"> <?php echo te('common.optional'); ?></span></h3>
              <?php
              render_place_picker("must", t('pref.must_label'), t('pref.must_help'), $oldPlaces["must"]);
              render_place_picker("avoid", t('pref.avoid_label'), t('pref.avoid_help'), $oldPlaces["avoid"]);
              ?>
            </div>

            <div class="card col-12 wizard-panel" data-step-panel="3" style="box-shadow:none;">
              <h3 style="margin-bottom:8px;"><?php echo te('pref.review_heading'); ?></h3>
              <p class="meta" style="margin-top:0;"><?php echo te('pref.review_help'); ?></p>
              <div class="summary-list">
                <div class="summary-item"><strong><?php echo te('pref.sum_duration'); ?></strong><span id="summaryDays">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_budget'); ?></strong><span id="summaryBudget">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.tier_label'); ?></strong><span id="summaryBudgetTier">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_transport'); ?></strong><span id="summaryTransport">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.type_label'); ?></strong><span id="summaryTravellerType">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_party'); ?></strong><span id="summaryPartySize">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.pace_label'); ?></strong><span id="summaryPace">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_dietary'); ?></strong><span id="summaryDietary">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_visit'); ?></strong><span id="summaryVisitTime">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.interests_heading'); ?></strong><span id="summaryInterests">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.state_label'); ?></strong><span id="summaryState"><?php echo te('opt.any_state'); ?></span></div>
                <div class="summary-item"><strong><?php echo te('pref.district_label'); ?></strong><span id="summaryDistrict"><?php echo te('opt.any_district'); ?></span></div>
                <div class="summary-item" id="summaryRowSchedule" style="display:none;"><strong><?php echo te('pref.sum_schedule'); ?></strong><span id="summarySchedule">-</span></div>
                <div class="summary-item" id="summaryRowStops" style="display:none;"><strong><?php echo te('pref.stops_label'); ?></strong><span id="summaryStops">-</span></div>
                <div class="summary-item" id="summaryRowMix" style="display:none;"><strong><?php echo te('pref.sum_mix'); ?></strong><span id="summaryMix">-</span></div>
                <div class="summary-item"><strong><?php echo te('pref.sum_access'); ?></strong><span id="summaryAccessibility">-</span></div>
                <div class="summary-item" id="summaryRowMust" style="display:none;"><strong><?php echo te('pref.must_label'); ?></strong><span id="summaryMust">-</span></div>
                <div class="summary-item" id="summaryRowAvoid" style="display:none;"><strong><?php echo te('pref.avoid_label'); ?></strong><span id="summaryAvoid">-</span></div>
              </div>
            </div>

          </div><!-- /grid -->

          <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-ghost" type="button" id="wizardBack" style="display:none;"><?php echo te('common.back'); ?></button>
            <button class="btn btn-primary" type="button" id="wizardNext"><?php echo te('common.next'); ?></button>
            <button class="btn btn-primary" type="submit" id="wizardSubmit" style="display:none;"><?php echo te('pref.save_btn'); ?></button>
            <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php"><?php echo te('common.cancel'); ?></a>
          </div>
        </form>
      </div>
    </section>
  </main>
</div>

<script>
(function () {
  var stateDistricts = <?php echo $districtsJson; ?>;
  var T = <?php echo i18n_js(['pref.any_district', 'pref.district_hint_off', 'pref.district_hint_in', 'pref.days_unit', 'pref.travellers_unit', 'pref.js_select_interest', 'pref.js_max_places', 'pref.js_search_unavailable', 'pref.js_no_places', 'pref.js_family_help', 'pref.js_group_help', 'pref.sum_schedule_from', 'pref.sum_schedule_until', 'pref.sum_schedule_range', 'pref.sum_adults', 'pref.sum_children', 'pref.sum_seniors', 'pref.sum_interest_weight', 'pref.sum_access_note', 'opt.any_state', 'opt.any_district', 'common.remove']); ?>;

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
    distSel.innerHTML = '';
    var anyOpt = document.createElement('option');
    anyOpt.value = '';
    anyOpt.textContent = T['pref.any_district'];
    distSel.appendChild(anyOpt);

    if (!state || !stateDistricts[state]) {
      // No state selected - disable and show hint
      distSel.disabled = true;
      distHint.textContent = T['pref.district_hint_off'];
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
    distHint.textContent = T['pref.district_hint_in'].replace('{state}', state);
  }

  /* ---- Party mix: adults + children + seniors set the party size ---- */
  var mixInputs = ['adults', 'children', 'seniors'].map(function (id) { return document.getElementById(id); });
  function syncPartyMix() {
    var partyInput = document.querySelector('[name="party_size"]');
    var any = mixInputs.some(function (el) { return el && el.value !== ''; });
    if (!partyInput) return;
    if (any) {
      var sum = mixInputs.reduce(function (t, el) { return t + (el && el.value !== '' ? parseInt(el.value, 10) || 0 : 0); }, 0);
      partyInput.value = String(Math.max(1, sum));
      partyInput.readOnly = true;
    } else if (travellerTypeSel) {
      partyInput.readOnly = false;
      syncPartySize();
    }
  }
  mixInputs.forEach(function (el) { if (el) el.addEventListener('input', syncPartyMix); });

  /* ---- Interest levels only matter for ticked interests ---- */
  document.querySelectorAll('[name="interests[]"]').forEach(function (box) {
    var row = box.closest('.interest-row');
    var sel = row ? row.querySelector('select') : null;
    if (!sel) return;
    function sync() { sel.disabled = !box.checked; }
    box.addEventListener('change', sync);
    sync();
  });

  /* ---- Place pickers ---- */
  document.querySelectorAll('.place-picker').forEach(function (picker) {
    var input = picker.querySelector('.place-picker-input');
    var results = picker.querySelector('.place-picker-results');
    var chosen = picker.querySelector('.place-picker-chosen');
    var field = picker.getAttribute('data-field');
    var wish = picker.getAttribute('data-wish');
    var other = wish === 'must' ? 'avoid' : 'must';
    var timer = null, seq = 0;

    function idsIn(root) {
      return Array.prototype.map.call(root.querySelectorAll('.place-chip'), function (c) { return c.getAttribute('data-id'); });
    }
    function otherIds() {
      var o = document.querySelector('.place-picker[data-wish="' + other + '"]');
      return o ? idsIn(o) : [];
    }
    function note(text) {
      results.innerHTML = '';
      var d = document.createElement('div');
      d.className = 'place-note';
      d.textContent = text;
      results.appendChild(d);
      results.classList.add('open');
    }
    function add(place) {
      if (idsIn(chosen).length >= 10) { note(T['pref.js_max_places']); return; }
      if (idsIn(chosen).indexOf(String(place.id)) !== -1) return;
      var chip = document.createElement('span');
      chip.className = 'place-chip';
      chip.setAttribute('data-id', String(place.id));
      chip.appendChild(document.createTextNode(place.name + ' '));
      var rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'place-chip-remove'; rm.setAttribute('aria-label', T['common.remove']); rm.innerHTML = '&times;';
      var hid = document.createElement('input');
      hid.type = 'hidden'; hid.name = field + '[]'; hid.value = String(place.id);
      chip.appendChild(rm); chip.appendChild(hid);
      chosen.appendChild(chip);
      input.value = '';
      results.classList.remove('open');
    }
    chosen.addEventListener('click', function (e) {
      var rm = e.target.closest('.place-chip-remove');
      if (rm) rm.closest('.place-chip').remove();
    });
    function search() {
      var q = input.value.trim();
      if (q.length < 2) { results.classList.remove('open'); return; }
      var mine = ++seq;
      var url = '../api/place_search.php?q=' + encodeURIComponent(q);
      if (stateSel && stateSel.value) url += '&states=' + encodeURIComponent(stateSel.value);
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
        if (mine !== seq) return;
        if (!data || data.status !== 'ok') { note(T['pref.js_search_unavailable']); return; }
        var blocked = otherIds();
        var list = data.results.filter(function (p) { return blocked.indexOf(String(p.id)) === -1; });
        if (!list.length) { note(T['pref.js_no_places']); return; }
        results.innerHTML = '';
        list.forEach(function (p) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = p.name + ' (' + [p.district, p.state].filter(Boolean).join(', ') + ' - ' + p.category + ')';
          b.addEventListener('click', function () { add(p); });
          results.appendChild(b);
        });
        results.classList.add('open');
      }).catch(function () { if (mine === seq) note(T['pref.js_search_unavailable']); });
    }
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    document.addEventListener('click', function (e) { if (!picker.contains(e.target)) results.classList.remove('open'); });
  });

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
          ? T['pref.js_family_help']
          : T['pref.js_group_help'];
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
        alert(T['pref.js_select_interest']);
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
    var accessNote = document.querySelector('[name="accessibility_needs"]').value.trim();
    var accessTags = Array.prototype.map.call(document.querySelectorAll('[name="access_tags[]"]:checked'), function(el) {
      return el.parentNode.textContent.trim();
    });
    var accessibility = accessTags.join(', ');
    if (accessNote !== '') {
      accessibility = accessibility === '' ? accessNote : accessibility + ' - ' + T['pref.sum_access_note'].replace('{note}', accessNote);
    }
    if (accessibility === '') accessibility = '-';
    var interests = Array.prototype.map.call(document.querySelectorAll('[name="interests[]"]:checked'), function(el) {
      var name = el.parentNode.textContent.trim();
      var row = el.closest('.interest-row');
      var weightSel = row ? row.querySelector('select') : null;
      if (weightSel && weightSel.value !== '' && weightSel.value !== '2') {
        return T['pref.sum_interest_weight'].replace('{interest}', name).replace('{level}', selectedText(weightSel));
      }
      return name;
    }).join(', ') || '-';
    var dayStart = document.querySelector('[name="day_start_time"]').value;
    var dayEnd = document.querySelector('[name="day_end_time"]').value;
    var schedule = '';
    if (dayStart !== '' && dayEnd !== '') schedule = T['pref.sum_schedule_range'].replace('{start}', dayStart).replace('{end}', dayEnd);
    else if (dayStart !== '') schedule = T['pref.sum_schedule_from'].replace('{start}', dayStart);
    else if (dayEnd !== '') schedule = T['pref.sum_schedule_until'].replace('{end}', dayEnd);
    var stops = document.querySelector('[name="stops_per_day"]').value.trim();
    var mixParts = [];
    [['adults', 'pref.sum_adults'], ['children', 'pref.sum_children'], ['seniors', 'pref.sum_seniors']].forEach(function (m) {
      var el = document.getElementById(m[0]);
      if (el && el.value !== '' && parseInt(el.value, 10) > 0) mixParts.push(T[m[1]].replace('{n}', el.value));
    });
    function placeNames(wish) {
      return Array.prototype.map.call(document.querySelectorAll('.place-picker[data-wish="' + wish + '"] .place-chip'), function (chip) {
        return chip.firstChild ? chip.firstChild.textContent.trim() : '';
      }).filter(function (n) { return n !== ''; }).join(', ');
    }
    function setOptionalRow(name, text) {
      document.getElementById('summaryRow' + name).style.display = text === '' ? 'none' : '';
      document.getElementById('summary' + name).textContent = text;
    }
    setOptionalRow('Schedule', schedule);
    setOptionalRow('Stops', stops);
    setOptionalRow('Mix', mixParts.join(', '));
    setOptionalRow('Must', placeNames('must'));
    setOptionalRow('Avoid', placeNames('avoid'));
    document.getElementById('summaryDays').textContent = T['pref.days_unit'].replace('{n}', days);
    document.getElementById('summaryBudget').textContent = budget === '-' ? '-' : 'RM ' + budget;
    document.getElementById('summaryBudgetTier').textContent = budgetTier;
    document.getElementById('summaryTransport').textContent = transport;
    document.getElementById('summaryTravellerType').textContent = travellerType;
    document.getElementById('summaryPartySize').textContent = partySize === '-' ? '-' : T['pref.travellers_unit'].replace('{n}', partySize);
    document.getElementById('summaryPace').textContent = pace;
    document.getElementById('summaryDietary').textContent = dietary;
    document.getElementById('summaryVisitTime').textContent = visitTime;
    document.getElementById('summaryInterests').textContent = interests;
    document.getElementById('summaryState').textContent = selectedText(stateSel) || T['opt.any_state'];
    document.getElementById('summaryDistrict').textContent = selectedText(distSel) || T['opt.any_district'];
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
    travellerTypeSel.addEventListener('change', function () { syncPartySize(); syncPartyMix(); });
  }
  syncPartySize();
  syncPartyMix();
  showStep(1);
})();
</script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>
</html>

