<?php
session_start();
require_once "../config/db_connect.php";

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

function pref_list_label(?string $csv): string
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/css/dashboard_style.css">
    <style>
        .guide-wrap{max-width:1100px;margin:0 auto}.hero-card{background:linear-gradient(135deg,#0f766e,#16a34a);color:#fff;border-radius:20px;padding:24px;margin-bottom:18px}.hero-card h1{margin:0 0 8px;font-size:28px}.hero-card p{margin:0;opacity:.92;line-height:1.55}.alert-box,.error-box{border-radius:16px;padding:14px 16px;margin-bottom:16px}.alert-box{border:1px solid #bbf7d0;background:#ecfdf5;color:#166534}.error-box{border:1px solid #fecaca;background:#fef2f2;color:#991b1b}.step-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.step-card,.form-card,.summary-card,details.help-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:16px;box-shadow:0 8px 18px rgba(15,23,42,.05)}.step-no{width:30px;height:30px;border-radius:50%;background:#eff6ff;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-weight:800;margin-bottom:8px}.step-card strong{display:block;margin-bottom:4px}.step-card span,.hint{color:#6b7280;font-size:13px;line-height:1.4}.layout-grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);gap:18px;align-items:start}.field{margin-bottom:16px}.field label{display:block;font-weight:700;margin-bottom:7px;color:#111827}.input,.select{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:12px 13px;font-size:15px;background:#fff}.input:focus,.select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);outline:none}.btn-row{display:flex;flex-wrap:wrap;gap:10px}.primary-btn,.secondary-btn{border:0;border-radius:12px;padding:12px 16px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.primary-btn{background:#16a34a;color:#fff}.secondary-btn{background:#f3f4f6;color:#111827}.summary-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.summary-item{background:#f9fafb;border:1px solid #eef2f7;border-radius:12px;padding:11px}.summary-item small{display:block;color:#6b7280;margin-bottom:3px}.summary-item strong{color:#111827;line-height:1.35}.full{grid-column:1/-1}.empty-state{padding:18px;border-radius:14px;background:#f9fafb;color:#6b7280;line-height:1.55}.map-status{font-size:13px;color:#4b5563;margin-top:6px}.valid-status{color:#166534}.invalid-status{color:#991b1b}@media(max-width:900px){.step-row,.layout-grid,.summary-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="app">
    <?php include "../includes/sidebar_traveller.php"; ?>
    <main class="content">
        <div class="topbar"><div><h1>Smart Itinerary Generator</h1><p class="muted">Generate a cultural itinerary from your saved travel preference.</p></div><div class="user-chip"><span><?php echo htmlspecialchars($travellerName); ?></span></div></div>
        <div class="guide-wrap">
            <section class="hero-card"><h1>Generate your itinerary in 3 simple steps</h1><p>Your saved travel preference will be used as the main input. Confirm the start date and starting location, then the system will generate the itinerary using verified cultural places and rule-based logic.</p></section>
            <?php if ($successMessage !== ""): ?><div class="alert-box"><strong><?php echo htmlspecialchars($successMessage); ?></strong><br><span>Step 1 is completed. Now confirm your start date and starting location.</span></div><?php elseif ($fromPreferenceSaved): ?><div class="alert-box"><strong>Preference saved successfully.</strong><br><span>Now confirm your start date and starting location before generating the itinerary.</span></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="error-box"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars((string)$err); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <div class="step-row"><div class="step-card"><div class="step-no">1</div><strong>Select Preference</strong><span>The latest saved preference is auto-selected.</span></div><div class="step-card"><div class="step-no">2</div><strong>Start Date</strong><span>Choose the first day of your trip.</span></div><div class="step-card"><div class="step-no">3</div><strong>Starting Location</strong><span>Search a place or use current location.</span></div><div class="step-card"><div class="step-no">4</div><strong>Generate</strong><span>Create a day-by-day itinerary.</span></div></div>
            <?php if (empty($preferences)): ?>
                <div class="form-card"><h2>No saved preference found</h2><p class="empty-state">Please create a travel preference first. The itinerary generator needs your budget, interests, state, district, transport type and travel pace before it can generate a suitable itinerary.</p><a class="primary-btn" href="../preference/preference_form.php">Create Travel Preference</a></div>
            <?php else: ?>
                <div class="layout-grid">
                    <form class="form-card" id="generatorForm" method="post" action="generate_itinerary.php">
                        <h2>Confirm itinerary details</h2>
                        <div class="field"><label for="preference_id">Step 1: Saved Travel Preference</label><select class="select" name="preference_id" id="preference_id" required><option value="" disabled <?php echo $preselectedPreferenceId <= 0 ? "selected" : ""; ?>>Select one preference</option><?php foreach ($preferences as $p): ?><?php $pid = (int)$p["preference_id"]; ?><option value="<?php echo $pid; ?>" <?php echo $pid === $preselectedPreferenceId ? "selected" : ""; ?>><?php echo htmlspecialchars(pref_short_label($p)); ?></option><?php endforeach; ?></select><div class="hint">After submitting a preference, the latest preference is automatically selected here.</div></div>
                        <div class="field" id="startDateField"><label for="start_date">Step 2: Start Date</label><input class="input" type="date" name="start_date" id="start_date" min="<?php echo date('Y-m-d'); ?>" required><div class="hint">This date is used to arrange itinerary schedule and opening-time checks.</div></div>
                        <div class="field"><label for="origin_name">Step 3: Starting Location</label><input class="input" type="text" name="origin_name" id="origin_name" placeholder="Example: KL Sentral" autocomplete="off" required><input type="hidden" name="origin_lat" id="origin_lat"><input type="hidden" name="origin_lng" id="origin_lng"><div class="map-status" id="locationStatus">Type a starting point and the system will find its coordinates before generating.</div></div>
                        <div class="btn-row"><button type="button" class="secondary-btn" id="useCurrentLocationBtn">Use Current Location</button><button type="submit" class="primary-btn">Generate Itinerary</button><a class="secondary-btn" href="../preference/preference_form.php">Edit / Add Preference</a></div>
                    </form>
                    <aside class="summary-card"><h2>Selected Preference Summary</h2><div id="preferenceSummary" class="empty-state">Select a saved preference to view the summary.</div></aside>
                </div>
                <details class="help-card"><summary>How does the system generate the itinerary?</summary><ul><li>The system uses your selected preference, including trip duration, budget, transport, pace, interest, state and district.</li><li>Only verified cultural place records from the database are used for official itinerary generation.</li><li>The system applies rule-based logic to filter places, arrange route order and calculate travel time.</li><li>AI support is used only for explanation and assistance, not to directly create the official itinerary.</li></ul></details>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
const preferenceData = <?php echo json_encode($preferencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const preselectedPreferenceId = <?php echo (int)$preselectedPreferenceId; ?>;
const fromPreferenceSaved = <?php echo $fromPreferenceSaved ? "true" : "false"; ?>;
function escapeHtml(v){return String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"})[c]);}
function updatePreferenceSummary(){const s=document.getElementById("preference_id"),b=document.getElementById("preferenceSummary");if(!s||!b)return;const p=preferenceData[s.value];if(!p){b.className="empty-state";b.innerHTML="Select a saved preference to view the summary.";return;}b.className="summary-grid";b.innerHTML=`<div class="summary-item"><small>Preference</small><strong>#${escapeHtml(p.id)}</strong></div><div class="summary-item"><small>Duration</small><strong>${escapeHtml(p.duration)}</strong></div><div class="summary-item"><small>Budget</small><strong>${escapeHtml(p.budget)}</strong></div><div class="summary-item"><small>Transport</small><strong>${escapeHtml(p.transport)}</strong></div><div class="summary-item"><small>Traveller</small><strong>${escapeHtml(p.traveller)}</strong></div><div class="summary-item"><small>Travel Pace</small><strong>${escapeHtml(p.pace)}</strong></div><div class="summary-item"><small>Dietary</small><strong>${escapeHtml(p.dietary)}</strong></div><div class="summary-item"><small>Visit Time</small><strong>${escapeHtml(p.visit_time)}</strong></div><div class="summary-item full"><small>Location</small><strong>${escapeHtml(p.location)}</strong></div><div class="summary-item full"><small>Interests</small><strong>${escapeHtml(p.interests)}</strong></div><div class="summary-item full"><small>Accessibility</small><strong>${escapeHtml(p.accessibility)}</strong></div>`;}
function setLocationStatus(m,ok){const s=document.getElementById("locationStatus");if(!s)return;s.textContent=m;s.className=ok?"map-status valid-status":"map-status invalid-status";}
function clearOriginCoords(){document.getElementById("origin_lat").value="";document.getElementById("origin_lng").value="";setLocationStatus("Type a starting point and the system will find its coordinates before generating.",false);}
function fillOrigin(name,lat,lng){document.getElementById("origin_name").value=name;document.getElementById("origin_lat").value=lat;document.getElementById("origin_lng").value=lng;setLocationStatus("Starting location confirmed with coordinates.",true);}
async function geocodeOriginIfNeeded(){const name=document.getElementById("origin_name").value.trim(),lat=document.getElementById("origin_lat").value.trim(),lng=document.getElementById("origin_lng").value.trim();if(name!==""&&lat!==""&&lng!=="")return true;if(name==="")return false;try{setLocationStatus("Searching location coordinates...",false);const r=await fetch("geocode_origin.php?q="+encodeURIComponent(name));const d=await r.json();if(d&&d.status==="success"){fillOrigin(d.formatted_address||name,d.lat,d.lng);return true;}}catch(e){}setLocationStatus("Location coordinates not found. Please type a clearer starting point or use current location.",false);return false;}
document.addEventListener("DOMContentLoaded",function(){const s=document.getElementById("preference_id"),f=document.getElementById("generatorForm"),o=document.getElementById("origin_name"),c=document.getElementById("useCurrentLocationBtn");updatePreferenceSummary();if(s)s.addEventListener("change",updatePreferenceSummary);if(o)o.addEventListener("input",clearOriginCoords);if((fromPreferenceSaved||preselectedPreferenceId>0)&&document.getElementById("start_date")){const d=document.getElementById("start_date");d.scrollIntoView({behavior:"smooth",block:"center"});setTimeout(()=>d.focus(),500);}if(c)c.addEventListener("click",function(){if(!navigator.geolocation){setLocationStatus("Current location is not supported by this browser.",false);return;}setLocationStatus("Getting current location...",false);navigator.geolocation.getCurrentPosition(p=>fillOrigin("Current Location",p.coords.latitude,p.coords.longitude),()=>setLocationStatus("Unable to get current location. Please search a starting point manually.",false),{enableHighAccuracy:true,timeout:10000});});if(f)f.addEventListener("submit",async function(e){e.preventDefault();if(!document.getElementById("preference_id").value){alert("Please select a saved travel preference first.");return;}if(!document.getElementById("start_date").value){alert("Please select a start date first.");document.getElementById("start_date").focus();return;}const ok=await geocodeOriginIfNeeded();if(!ok){document.getElementById("origin_name").focus();return;}f.submit();});});
</script>
</body>
</html>
