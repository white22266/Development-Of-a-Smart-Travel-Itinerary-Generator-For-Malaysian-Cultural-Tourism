<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
$errors = $_SESSION["form_errors"] ?? [];
$successMessage = $_SESSION["success_message"] ?? "";
unset($_SESSION["form_errors"], $_SESSION["success_message"]);


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

/** Label of a fixed choice (group: transport, type, pace, diet, visit, interest) in the current language. */
function pref_choice_label(string $group, ?string $value): string {
  return t_choice($group, $value);
}

function pref_rm(float $amount): string {
  return "RM " . number_format($amount, 2);
}

function pref_list_label(?string $csv): string {
  $parts = array_values(array_filter(array_map("trim", explode(",", (string)$csv))));
  if (empty($parts)) return "-";
  return implode(", ", array_map(fn($part) => pref_choice_label("interest", $part), $parts));
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
  return t('sel.all_malaysia');
}

function pref_short_label(array $pref): string {
  $days = max(1, (int)($pref["trip_days"] ?? 1));
  $nights = max(0, $days - 1);
  return t('sel.short_label', [
    'id' => (int)$pref["preference_id"],
    'days' => $days,
    'nights' => $nights,
    'location' => pref_location_label($pref),
    'budget' => pref_rm((float)($pref["budget"] ?? 0)),
    'pace' => pref_choice_label("pace", (string)($pref["travel_pace"] ?? "normal")),
  ]);
}

$preferencePayload = [];
foreach ($preferences as $pref) {
  $pid = (int)$pref["preference_id"];
  $days = max(1, (int)($pref["trip_days"] ?? 1));
  $budget = (float)($pref["budget"] ?? 0);
  $partySize = pref_party_size($pref);
  $preferencePayload[$pid] = [
    "id" => $pid,
    "days" => $days,
    "budget_amount" => $budget,
    "budget_per_day" => $budget / $days,
    "party_size" => $partySize,
    "duration" => t('pref.days_unit', ['n' => $days]),
    "budget" => pref_rm($budget),
    "transport" => pref_choice_label("transport", (string)($pref["transport_type"] ?? "-")),
    "transport_type" => strtolower((string)($pref["transport_type"] ?? "")),
    "traveller" => pref_choice_label("type", (string)($pref["traveller_type"] ?? "solo")) . " / " . t('pref.travellers_unit', ['n' => $partySize]),
    "traveller_type_label" => pref_choice_label("type", (string)($pref["traveller_type"] ?? "solo")),
    "traveller_type" => strtolower((string)($pref["traveller_type"] ?? "solo")),
    "pace" => pref_choice_label("pace", (string)($pref["travel_pace"] ?? "normal")),
    "pace_value" => strtolower((string)($pref["travel_pace"] ?? "normal")),
    "dietary" => pref_choice_label("diet", (string)($pref["dietary_preference"] ?? "none")),
    "dietary_value" => strtolower((string)($pref["dietary_preference"] ?? "none")),
    "visit_time" => pref_choice_label("visit", (string)($pref["preferred_visit_time"] ?? "any")),
    "visit_time_value" => strtolower((string)($pref["preferred_visit_time"] ?? "any")),
    "location" => pref_location_label($pref),
    "interests" => pref_list_label((string)($pref["interests"] ?? "")),
    "accessibility" => trim((string)($pref["accessibility_needs"] ?? "")) !== "" ? trim((string)$pref["accessibility_needs"]) : "None",
  ];
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo te('nav.generator'); ?> | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
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
    .field-group input[type="radio"] { width: auto; padding: 0; }
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
    .preference-analysis-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin: 14px 0 16px;
    }
    .analysis-panel {
      border: 1px solid rgba(15,23,42,0.10);
      border-radius: 14px;
      background: rgba(15,23,42,0.02);
      font-size: 13px;
      min-width: 0;
      overflow: hidden;
    }
    .analysis-panel h4 {
      margin: 0;
      padding: 13px 14px 10px;
      font-size: 13px;
      color: var(--navy);
    }
    .analysis-rows {
      padding: 0 14px 14px;
    }
    .analysis-row {
      display: grid;
      grid-template-columns: minmax(130px, 34%) 1fr;
      gap: 12px;
      padding: 8px 0;
      border-top: 1px solid rgba(15,23,42,0.08);
      line-height: 1.35;
    }
    .analysis-row strong {
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
    }
    .analysis-row span {
      color: var(--text);
      font-size: 12.5px;
      font-weight: 750;
      overflow-wrap: anywhere;
    }
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
      border: 1px solid rgba(99, 102, 241, 0.28);
      border-radius: 14px;
      padding: 12px;
      background: rgba(99, 102, 241, 0.05);
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
      border: 1px solid rgba(99, 102, 241, 0.24);
      border-radius: 12px;
      background: rgba(99, 102, 241, 0.035);
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
    .ai-toggle-row {
      display: flex;
      justify-content: flex-start;
      margin: 12px 0 2px;
    }
    .ai-assistant-card { margin-top: 0; }
    .is-hidden { display: none !important; }
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
      .preference-analysis-grid { grid-template-columns: 1fr; }
      .help-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .location-input-row,
      .ai-chat-form { grid-template-columns: 1fr; }
      .location-input-row .btn,
      .ai-chat-form .btn { width: 100%; }
    }
    @media (max-width: 520px) {
      .generator-steps { grid-template-columns: 1fr; }
      .analysis-row {
        grid-template-columns: 1fr;
        gap: 3px;
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
        <span><?php echo te('nav.generator'); ?></span>
      </div>
    </div>

    <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
      <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
      <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
      <a class="active" href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
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
        <h1><?php echo te('nav.generator'); ?></h1>
        <p><?php echo te('sel.page_subtitle'); ?></p>
      </div>
      <div class="actions">
        <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php"><?php echo te('common.back_dashboard'); ?></a>
        <button type="button" class="btn btn-primary" id="aiAssistantToggle" aria-expanded="false"><?php echo te('sel.ai_btn'); ?></button>
      </div>
    </div>

    <section class="grid">
      <div class="card col-12">
        <h3><?php echo te('sel.generate_title'); ?></h3>
        <p class="meta"><?php echo te('sel.generate_meta'); ?></p>

        <?php if ($successMessage !== ""): ?>
          <div class="notice-box"><strong><?php echo htmlspecialchars($successMessage); ?></strong><br><?php echo te('sel.notice_next'); ?></div>
        <?php elseif ($fromPreferenceSaved): ?>
          <div class="notice-box"><strong><?php echo te('sel.notice_saved'); ?></strong><br><?php echo te('sel.notice_saved_next'); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="error-box">
            <strong><?php echo te('sel.fix_following'); ?></strong>
            <ul>
              <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars((string)$err); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="generator-steps">
          <div class="generator-step"><div class="step-num">1</div><strong><?php echo te('sel.step1_title'); ?></strong><span><?php echo te('sel.step1_desc'); ?></span></div>
          <div class="generator-step"><div class="step-num">2</div><strong><?php echo te('sel.step2_title'); ?></strong><span><?php echo te('sel.step2_desc'); ?></span></div>
          <div class="generator-step"><div class="step-num">3</div><strong><?php echo te('sel.step3_title'); ?></strong><span><?php echo te('sel.step3_desc'); ?></span></div>
          <div class="generator-step"><div class="step-num">4</div><strong><?php echo te('sel.step4_title'); ?></strong><span><?php echo te('sel.step4_desc'); ?></span></div>
        </div>
      </div>

      <?php if (empty($preferences)): ?>
        <div class="card col-12">
          <h3><?php echo te('sel.none_title'); ?></h3>
          <p class="meta"><?php echo te('sel.none_meta'); ?></p>
          <a class="btn btn-primary" href="../preference/preference_form.php"><?php echo te('sel.none_btn'); ?></a>
        </div>
      <?php else: ?>
        <div class="card col-12">
          <h3><?php echo te('sel.confirm_title'); ?></h3>
          <p class="meta"><?php echo te('sel.confirm_meta'); ?></p>

          <form id="generatorForm" method="post" action="generate_itinerary.php">
            <div class="field-group">
              <label for="preference_id"><?php echo te('sel.f_pref'); ?></label>
              <select name="preference_id" id="preference_id" required>
                <option value="" disabled <?php echo $preselectedPreferenceId <= 0 ? "selected" : ""; ?>><?php echo te('sel.f_pref_choose'); ?></option>
                <?php foreach ($preferences as $p): ?>
                  <?php $pid = (int)$p["preference_id"]; ?>
                  <option value="<?php echo $pid; ?>" <?php echo $pid === $preselectedPreferenceId ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars(pref_short_label($p)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-help"><?php echo te('sel.f_pref_help'); ?></div>
            </div>

            <div class="preference-analysis-grid is-hidden" id="preferenceAnalysisGrid">
              <div class="analysis-panel">
                <h4><?php echo te('sel.summary_title'); ?></h4>
                <div id="preferenceSummary" class="analysis-rows"></div>
              </div>
              <div class="analysis-panel">
                <h4><?php echo te('sel.analysis_title'); ?></h4>
                <div id="systemAnalysisResult" class="analysis-rows"></div>
              </div>
            </div>

            <div class="field-group" id="startDateField">
              <label for="start_date"><?php echo te('sel.f_date'); ?></label>
              <input type="date" name="start_date" id="start_date" min="<?php echo date('Y-m-d'); ?>" required>
              <div class="field-help"><?php echo te('sel.f_date_help'); ?></div>
            </div>

            <div class="field-group">
              <label for="origin_name"><?php echo te('sel.f_origin'); ?></label>
              <div class="location-input-row">
                <input type="text" name="origin_name" id="origin_name" placeholder="<?php echo te('sel.origin_placeholder'); ?>" autocomplete="off" required>
                <button type="button" class="btn btn-ghost" id="useCurrentLocationBtn"><?php echo te('sel.use_location'); ?></button>
              </div>
              <input type="hidden" name="origin_lat" id="origin_lat">
              <input type="hidden" name="origin_lng" id="origin_lng">
              <div class="field-help" id="locationStatus">
                <?php echo te('sel.origin_status'); ?>
              </div>
            </div>

            <div class="field-group" id="endPointField">
              <label><?php echo te('sel.end_label'); ?></label>
              <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                <input type="radio" name="end_mode" value="start" id="endModeStart" checked> <?php echo te('sel.end_start'); ?>
              </label>
              <label style="display:flex; gap:8px; align-items:center; font-weight:400;">
                <input type="radio" name="end_mode" value="other" id="endModeOther"> <?php echo te('sel.end_other'); ?>
              </label>
              <div id="endPointBox" hidden style="margin-top:8px;">
                <input type="text" name="end_name" id="end_name" placeholder="<?php echo te('sel.end_placeholder'); ?>" autocomplete="off" disabled>
                <input type="hidden" name="end_lat" id="end_lat" disabled>
                <input type="hidden" name="end_lng" id="end_lng" disabled>
                <div class="field-help" id="endLocationStatus"><?php echo te('sel.end_status'); ?></div>
              </div>
            </div>

            <div class="actions generate-actions">
              <button type="submit" class="btn btn-primary"><?php echo te('sel.generate_title'); ?></button>
            </div>
          </form>
        </div>

        <div class="card col-12 ai-assistant-card is-hidden" id="aiAssistantCard">
          <h3><?php echo te('sel.ai_title'); ?></h3>
          <p class="meta"><?php echo te('sel.ai_meta'); ?></p>
          <div class="ai-chat-body" id="aiChatBody">
            <div class="ai-msg bot"><?php echo te('sel.ai_hello'); ?></div>
          </div>
          <div class="ai-quick-actions">
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="<?php echo te('sel.ai_p1'); ?>"><?php echo te('sel.ai_q1'); ?></button>
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="<?php echo te('sel.ai_p2'); ?>"><?php echo te('sel.ai_q2'); ?></button>
            <button type="button" class="btn btn-ghost btn-small" data-ai-prompt="<?php echo te('sel.ai_p3'); ?>"><?php echo te('sel.ai_q3'); ?></button>
          </div>
          <form class="ai-chat-form" id="aiChatForm">
            <input type="text" id="aiQuestion" placeholder="<?php echo te('sel.ai_placeholder'); ?>" autocomplete="off">
            <button type="submit" class="btn btn-primary"><?php echo te('sel.ai_ask'); ?></button>
          </form>
        </div>

        <div class="card col-12">
          <details class="help-box">
            <summary><?php echo te('sel.rules_title'); ?></summary>
            <div class="help-grid">
              <div class="help-subbox">
                <h4><?php echo te('sel.rules_pace_title'); ?></h4>
                <ul>
                  <li><?php echo te('sel.rules_pace1'); ?></li>
                  <li><?php echo te('sel.rules_pace2'); ?></li>
                  <li><?php echo te('sel.rules_pace3'); ?></li>
                  <li><?php echo te('sel.rules_pace4'); ?></li>
                  <li><?php echo te('sel.rules_pace5'); ?></li>
                </ul>
              </div>
              <div class="help-subbox">
                <h4><?php echo te('sel.rules_route_title'); ?></h4>
                <ul>
                  <li><?php echo te('sel.rules_route1'); ?></li>
                  <li><?php echo te('sel.rules_route2'); ?></li>
                  <li><?php echo te('sel.rules_route3'); ?></li>
                  <li><?php echo te('sel.rules_route4'); ?></li>
                  <li><?php echo te('sel.rules_route5'); ?></li>
                </ul>
              </div>
            </div>
          </details>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<script src="../assets/place_picker.js?v=20261112c"></script>
<script>
const preferenceData = <?php echo json_encode($preferencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const preselectedPreferenceId = <?php echo (int)$preselectedPreferenceId; ?>;
const fromPreferenceSaved = <?php echo $fromPreferenceSaved ? "true" : "false"; ?>;
const T = <?php echo i18n_js(['pref.travellers_unit', 'sel.js_duration', 'sel.js_budget', 'sel.js_transport', 'sel.js_type', 'sel.js_party', 'sel.js_pace', 'sel.js_location', 'sel.js_interests', 'sel.js_dietary', 'sel.js_visit', 'sel.js_profile_label', 'sel.js_profile', 'sel.js_budget_level', 'sel.js_route_scope', 'sel.js_route_scope_val', 'sel.js_activity_label', 'sel.js_transport_label', 'sel.js_transport_val', 'sel.js_interest_label', 'sel.js_food_label', 'sel.js_access_label', 'sel.js_budget_none', 'sel.js_budget_limited', 'sel.js_budget_moderate', 'sel.js_budget_comfortable', 'sel.js_activity_relaxed', 'sel.js_activity_packed', 'sel.js_activity_normal', 'sel.js_food_halal', 'sel.js_food_veg', 'sel.js_food_none', 'sel.js_access_none', 'sel.js_loc_confirmed', 'sel.js_loc_choose', 'sel.js_loc_searching', 'sel.js_loc_notfound', 'sel.js_end_confirmed', 'sel.js_end_type', 'sel.js_end_notfound', 'sel.js_my_location', 'sel.js_thinking', 'sel.js_ai_unavailable', 'sel.js_ai_down', 'sel.js_geo_unsupported', 'sel.js_geo_getting', 'sel.js_geo_failed', 'sel.js_need_pref', 'sel.js_need_date', 'sel.origin_status', 'sel.ai_btn', 'sel.ai_btn_hide']); ?>;

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, function (char) {
    return {"&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#039;"}[char];
  });
}

function formatRm(amount) {
  const number = Number(amount || 0);
  return "RM " + number.toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function titleCase(value) {
  return String(value || "")
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, char => char.toUpperCase());
}

function renderAnalysisRows(rows) {
  return rows.map(row => `
    <div class="analysis-row">
      <strong>${escapeHtml(row[0])}</strong>
      <span>${escapeHtml(row[1])}</span>
    </div>
  `).join("");
}

function budgetLevel(pref) {
  const perDay = Number(pref.budget_per_day || 0);
  if (perDay <= 0) return T["sel.js_budget_none"];
  if (perDay < 100) return T["sel.js_budget_limited"].replace("{amount}", formatRm(perDay));
  if (perDay < 250) return T["sel.js_budget_moderate"].replace("{amount}", formatRm(perDay));
  return T["sel.js_budget_comfortable"].replace("{amount}", formatRm(perDay));
}

function activityRule(pref) {
  const pace = String(pref.pace_value || "normal");
  if (pace === "relaxed") return T["sel.js_activity_relaxed"];
  if (pace === "packed") return T["sel.js_activity_packed"];
  return T["sel.js_activity_normal"];
}

function foodRule(pref) {
  const dietary = String(pref.dietary_value || "none");
  if (dietary === "halal") return T["sel.js_food_halal"];
  if (dietary === "vegetarian") return T["sel.js_food_veg"];
  return T["sel.js_food_none"];
}

function accessibilityRule(pref) {
  const text = String(pref.accessibility || "").trim();
  if (text === "" || text.toLowerCase() === "none") return T["sel.js_access_none"];
  return text;
}

function updatePreferenceSummary() {
  const select = document.getElementById("preference_id");
  const analysisGrid = document.getElementById("preferenceAnalysisGrid");
  const summaryBox = document.getElementById("preferenceSummary");
  const analysisBox = document.getElementById("systemAnalysisResult");
  if (!select || !summaryBox || !analysisBox) return;

  const pref = preferenceData[select.value];
  if (!pref) {
    if (analysisGrid) analysisGrid.classList.add("is-hidden");
    summaryBox.innerHTML = "";
    analysisBox.innerHTML = "";
    return;
  }

  if (analysisGrid) analysisGrid.classList.remove("is-hidden");

  summaryBox.innerHTML = renderAnalysisRows([
    [T["sel.js_duration"], pref.duration],
    [T["sel.js_budget"], pref.budget],
    [T["sel.js_transport"], pref.transport],
    [T["sel.js_type"], pref.traveller_type_label],
    [T["sel.js_party"], T["pref.travellers_unit"].replace("{n}", pref.party_size)],
    [T["sel.js_pace"], pref.pace],
    [T["sel.js_location"], pref.location],
    [T["sel.js_interests"], pref.interests],
    [T["sel.js_dietary"], pref.dietary],
    [T["sel.js_visit"], pref.visit_time],
  ]);

  analysisBox.innerHTML = renderAnalysisRows([
    [T["sel.js_profile_label"], T["sel.js_profile"].replace("{pace}", pref.pace).replace("{type}", pref.traveller_type_label)],
    [T["sel.js_party"], T["pref.travellers_unit"].replace("{n}", pref.party_size)],
    [T["sel.js_budget_level"], budgetLevel(pref)],
    [T["sel.js_route_scope"], T["sel.js_route_scope_val"].replace("{location}", pref.location)],
    [T["sel.js_activity_label"], activityRule(pref)],
    [T["sel.js_transport_label"], T["sel.js_transport_val"].replace("{transport}", pref.transport)],
    [T["sel.js_interest_label"], pref.interests],
    [T["sel.js_food_label"], foodRule(pref)],
    [T["sel.js_access_label"], accessibilityRule(pref)],
  ]);
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
    setLocationStatus(T["sel.origin_status"], null);
  } else {
    setLocationStatus(T["sel.js_loc_choose"], null);
  }
}

function fillOrigin(name, lat, lng) {
  document.getElementById("origin_name").value = name;
  document.getElementById("origin_lat").value = lat;
  document.getElementById("origin_lng").value = lng;
  setLocationStatus(T["sel.js_loc_confirmed"], true);
}

// Results appear while the traveller types. They come from OpenStreetMap data through this
// app's own search endpoint (assets/place_picker.js, api/geo_search.php).
function initOriginSearch() {
  const input = document.getElementById("origin_name");
  if (!input || !window.PlacePicker) return;
  PlacePicker.attachSearch(input, "../api/geo_search.php", function (result) {
    fillOrigin(result.label, result.lat, result.lng);
  });
}
async function geocodeOriginIfNeeded() {
  const name = document.getElementById("origin_name").value.trim();
  const lat = document.getElementById("origin_lat").value.trim();
  const lng = document.getElementById("origin_lng").value.trim();

  if (name !== "" && lat !== "" && lng !== "") return true;
  if (name === "") return false;

  try {
    setLocationStatus(T["sel.js_loc_searching"], null);
    const response = await fetch("../api/geo_search.php?q=" + encodeURIComponent(name), { credentials: "same-origin" });
    const data = await response.json();
    const best = data && Array.isArray(data.results) ? data.results[0] : null;
    if (best) {
      fillOrigin(best.label || name, best.lat, best.lng);
      return true;
    }
  } catch (error) {}

  setLocationStatus(T["sel.js_loc_notfound"], false);
  return false;
}

// Optional end point: same search as the starting point, posted as end_name, end_lat, end_lng.
function setEndStatus(message, status) {
  const box = document.getElementById("endLocationStatus");
  if (!box) return;
  box.textContent = message;
  box.className = status === true ? "field-help status-good" : (status === false ? "field-help status-bad" : "field-help");
}

function fillEnd(name, lat, lng) {
  document.getElementById("end_name").value = name;
  document.getElementById("end_lat").value = lat;
  document.getElementById("end_lng").value = lng;
  setEndStatus(T["sel.js_end_confirmed"], true);
}

function endPointInUse() {
  const other = document.getElementById("endModeOther");
  return !!(other && other.checked);
}

function syncEndPointMode() {
  const use = endPointInUse();
  const box = document.getElementById("endPointBox");
  if (box) box.hidden = !use;
  ["end_name", "end_lat", "end_lng"].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.disabled = !use;   // a disabled field is not posted
  });
}

async function geocodeEndIfNeeded() {
  if (!endPointInUse()) return true;
  const name = document.getElementById("end_name").value.trim();
  const lat = document.getElementById("end_lat").value.trim();
  const lng = document.getElementById("end_lng").value.trim();
  if (name === "") {
    setEndStatus(T["sel.js_end_type"], false);
    return false;
  }
  if (lat !== "" && lng !== "") return true;
  try {
    setEndStatus(T["sel.js_loc_searching"], null);
    const response = await fetch("../api/geo_search.php?q=" + encodeURIComponent(name), { credentials: "same-origin" });
    const data = await response.json();
    const best = data && Array.isArray(data.results) ? data.results[0] : null;
    if (best) {
      fillEnd(best.label || name, best.lat, best.lng);
      return true;
    }
  } catch (error) {}
  setEndStatus(T["sel.js_end_notfound"], false);
  return false;
}

async function reverseGeocodeCurrentLocation(lat, lng) {
  try {
    const response = await fetch("../api/geo_search.php?lat=" + encodeURIComponent(lat) + "&lng=" + encodeURIComponent(lng), { credentials: "same-origin" });
    const data = await response.json();
    return data && data.address ? data.address : T["sel.js_my_location"];
  } catch (error) {
    return T["sel.js_my_location"];
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
  appendAiMessage("bot", T["sel.js_thinking"]);

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
      thinkingMsg.textContent = data && data.answer ? data.answer : T["sel.js_ai_unavailable"];
    }
  } catch (error) {
    if (thinkingMsg) thinkingMsg.textContent = T["sel.js_ai_down"];
  }
}

document.addEventListener("DOMContentLoaded", function () {
  const prefSelect = document.getElementById("preference_id");
  const form = document.getElementById("generatorForm");
  const originInput = document.getElementById("origin_name");
  const currentLocationBtn = document.getElementById("useCurrentLocationBtn");
  const aiToggle = document.getElementById("aiAssistantToggle");
  const aiCard = document.getElementById("aiAssistantCard");
  const aiForm = document.getElementById("aiChatForm");
  const aiQuestion = document.getElementById("aiQuestion");

  updatePreferenceSummary();
  initOriginSearch();

  const endInput = document.getElementById("end_name");
  if (endInput && window.PlacePicker) {
    PlacePicker.attachSearch(endInput, "../api/geo_search.php", function (result) {
      fillEnd(result.label, result.lat, result.lng);
    });
  }
  if (endInput) {
    endInput.addEventListener("input", function () {
      document.getElementById("end_lat").value = "";
      document.getElementById("end_lng").value = "";
      setEndStatus(T["sel.js_loc_choose"], null);
    });
  }
  document.querySelectorAll('input[name="end_mode"]').forEach(function (r) {
    r.addEventListener("change", syncEndPointMode);
  });
  syncEndPointMode();

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
        setLocationStatus(T["sel.js_geo_unsupported"], false);
        return;
      }
      setLocationStatus(T["sel.js_geo_getting"], null);
      navigator.geolocation.getCurrentPosition(
        async position => {
          const lat = position.coords.latitude;
          const lng = position.coords.longitude;
          const label = await reverseGeocodeCurrentLocation(lat, lng);
          fillOrigin(label, lat, lng);
        },
        () => setLocationStatus(T["sel.js_geo_failed"], false),
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  if (aiToggle && aiCard) {
    aiToggle.addEventListener("click", function () {
      const willOpen = aiCard.classList.contains("is-hidden");
      aiCard.classList.toggle("is-hidden", !willOpen);
      aiToggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
      aiToggle.textContent = willOpen ? T["sel.ai_btn_hide"] : T["sel.ai_btn"];
      if (willOpen) {
        setTimeout(() => aiCard.scrollIntoView({ behavior: "smooth", block: "start" }), 50);
      }
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
        alert(T["sel.js_need_pref"]);
        return;
      }
      if (!document.getElementById("start_date").value) {
        alert(T["sel.js_need_date"]);
        document.getElementById("start_date").focus();
        return;
      }
      const ok = await geocodeOriginIfNeeded();
      if (!ok) {
        document.getElementById("origin_name").focus();
        return;
      }
      const endOk = await geocodeEndIfNeeded();
      if (!endOk) {
        document.getElementById("end_name").focus();
        return;
      }
      form.submit();
    });
  }
});
</script>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>
</html>

