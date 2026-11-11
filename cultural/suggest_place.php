<?php
// cultural/suggest_place.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/map_assets.php";
require_once "../services/OsmDataService.php";

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

$success = $_SESSION["success_message"] ?? "";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];
$stateOptions = [
    "Johor",
    "Kedah",
    "Kelantan",
    "Melaka",
    "Negeri Sembilan",
    "Pahang",
    "Penang",
    "Perak",
    "Perlis",
    "Sabah",
    "Sarawak",
    "Selangor",
    "Terengganu",
    "Kuala Lumpur",
    "Putrajaya",
    "Labuan"
];

$mySuggestions = [];
$stmt = $conn->prepare("
  SELECT suggestion_id, name, state, category, status, created_at, approved_at, review_note
  FROM cultural_place_suggestions
  WHERE traveller_id=?
  ORDER BY suggestion_id DESC
  LIMIT 30
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $mySuggestions[] = $row;
}
$stmt->close();

$existingPlaces = [];
$distChk = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$distSelect = ($distChk && $distChk->num_rows > 0) ? "district" : "NULL AS district";
$placeRes = $conn->query("
    SELECT place_id, name, state, {$distSelect}, category, latitude, longitude
    FROM cultural_places
    WHERE is_active = 1
    ORDER BY place_id DESC
    LIMIT 1000
");
if ($placeRes) {
    while ($p = $placeRes->fetch_assoc()) $existingPlaces[] = $p;
}

function sug_label($s)
{
    $s = strtolower(trim((string)$s));
    if ($s === "pending") return "Pending";
    if ($s === "approved") return "Approved";
    if ($s === "rejected") return "Rejected";
    return ucfirst($s);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Suggest New Place | Traveller</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <?php echo leaflet_tags(); ?>
    <style>
        #suggestPinMap {
            width: 100%;
            height: 320px;
            border-radius: 12px;
            border: 1px solid rgba(15,23,42,0.12);
            background: #f1f5f9;
        }
        .duplicate-warning-box {
            display: none;
            border: 1px solid rgba(245,158,11,.35);
            background: rgba(245,158,11,.08);
            color: #92400e;
            border-radius: 12px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
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
                    <span>Suggest New Content</span>
                </div>
            </div>

      <nav class="nav" aria-label="Sidebar Navigation">
        <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
        <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
        <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
        <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
        <a href="../cultural/cultural_guide.php" class="active"><span class="dot"></span> Cultural Guide Presentation</a>
        <a class="nav-profile-link" href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
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
                    <h1>Suggest New Place</h1>
                    <p>Your submission will be reviewed by admin (Content Validation) before publishing.</p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn btn-ghost" href="cultural_guide.php">Back</a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <?php if ($success): ?>
                        <div style="color:rgba(16,185,129,1); font-weight:900;"><?php echo htmlspecialchars($success); ?></div>
                        <div style="height:10px;"></div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <ul style="color:rgba(239,68,68,1); margin:0 0 12px 18px;">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <!-- CHANGE: must use multipart/form-data for file upload -->
                    <form method="post" action="suggest_place_process.php" enctype="multipart/form-data">
                        <div class="grid" style="gap:12px;">
                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Place Name *</label><br>
                                <input type="text" name="name" id="suggestPlaceNameInput" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">State *</label><br>
                                <select name="state" id="suggestStateSelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled selected>Choose a state</option>
                                    <?php foreach ($stateOptions as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">District</label><br>
                                <input type="text" name="district" id="suggestDistrictInput"
                                    placeholder="Filled in from the map"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Category *</label><br>
                                <select name="category" id="suggestCategorySelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled selected>Choose a category</option>
                                    <?php foreach ($categoryOptions as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars(ucfirst($c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Cultural Description *</label><br>
                                <textarea name="description" id="suggestDescriptionInput" rows="4" required
                                    placeholder="Explain cultural background / heritage significance / local tradition"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"></textarea>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Address</label><br>
                                <input type="text" name="address" id="suggestAddressAutocomplete"
                                    placeholder="Start typing a place or address..."
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;">Type a name or address and choose a result, or pin the place on the map below.</div>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Latitude</label><br>
                                <input type="text" name="latitude" id="suggestLatitudeInput" placeholder="e.g. 1.4927000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Longitude</label><br>
                                <input type="text" name="longitude" id="suggestLongitudeInput" placeholder="e.g. 103.7414000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Pin the location on the map *</label>
                                <div id="suggestPinMap"></div>
                                <div class="meta" style="margin-top:6px;">Click the map or drag the marker to correct the exact place location. Latitude and longitude will update automatically.</div>
                                <div class="duplicate-warning-box" id="suggestDuplicateBox">
                                    <strong>Possible duplicate found</strong>
                                    <div id="suggestDuplicateList" style="margin-top:6px;"></div>
                                </div>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Estimated Cost (RM)</label><br>
                                <input type="number" step="0.01" min="0" name="estimated_cost" value="0.00"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Opening Hours</label><br>
                                <input type="text" name="opening_hours" id="suggestOpeningHoursInput" placeholder="e.g. 09:00 - 17:00"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3 suggest-festival-date-field" style="display:none;">
                                <label style="font-size:13px; font-weight:800;">Festival Start Date *</label><br>
                                <input type="date" name="festival_start_date" id="suggestFestivalStartDateInput"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3 suggest-festival-date-field" style="display:none;">
                                <label style="font-size:13px; font-weight:800;">Festival End Date *</label><br>
                                <input type="date" name="festival_end_date" id="suggestFestivalEndDateInput"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;">Required only for festival records.</div>
                            </div>

                            <!-- CHANGE: Place Image below Opening Hours (as you requested) -->
                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Place Image (optional)</label><br>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="width:100%; padding:8px;">
                                <div class="meta">JPG, PNG or WEBP, up to 5 MB. Use a photo you took yourself or one you are allowed to share.</div>
                            </div>
                        </div>

                        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit">Submit Suggestion</button>
                            <a class="btn btn-ghost" href="cultural_guide.php">Cancel</a>
                        </div>
                    </form>

                    <hr class="sep" style="margin:18px 0;">

                    <h3 style="margin:0 0 6px 0;">My Submissions</h3>
                    <p class="meta" style="margin:0 0 12px 0;">Check approval status and admin replies (especially when rejected).</p>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Place</th>
                                    <th>State</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Reviewed</th>
                                    <th>Admin Reply</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($mySuggestions) === 0): ?>
                                    <tr>
                                        <td colspan="8">No submissions yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($mySuggestions as $r): ?>
                                        <?php
                                        $status = strtolower((string)($r["status"] ?? ""));
                                        $reply  = trim((string)($r["review_note"] ?? ""));
                                        ?>
                                        <tr>
                                            <td><?php echo (int)$r["suggestion_id"]; ?></td>
                                            <td><strong><?php echo htmlspecialchars($r["name"]); ?></strong></td>
                                            <td><?php echo htmlspecialchars($r["state"]); ?></td>
                                            <td><?php echo htmlspecialchars($r["category"]); ?></td>
                                            <td>
                                                <span class="badge" style="<?php
                                                                            if ($status === 'approved') echo 'border-color: rgba(16,185,129,.35); font-weight:900;';
                                                                            else if ($status === 'rejected') echo 'border-color: rgba(239,68,68,.35); font-weight:900;';
                                                                            else echo 'font-weight:900;';
                                                                            ?>">
                                                    <?php echo htmlspecialchars(sug_label($r["status"])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($r["created_at"] ?? "-"); ?></td>
                                            <td><?php echo htmlspecialchars($r["approved_at"] ?? "-"); ?></td>
                                            <td>
                                                <?php if ($status === "rejected" && $reply !== ""): ?>
                                                    <span style="color:rgba(239,68,68,1); font-weight:800;">
                                                        <?php echo htmlspecialchars($reply); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?php echo ($reply !== "") ? htmlspecialchars($reply) : "-"; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </section>
        </main>
    </div>
<script src="../assets/place_picker.js?v=20261112c"></script>
<script>
var existingSuggestPlaces = <?php echo json_encode($existingPlaces, JSON_UNESCAPED_UNICODE); ?>;
var STATE_BY_ISO = <?php echo json_encode(array_flip(OsmDataService::STATE_ISO)); ?>;

function toggleSuggestFestivalDates() {
    var category = document.getElementById('suggestCategorySelect');
    var start = document.getElementById('suggestFestivalStartDateInput');
    var end = document.getElementById('suggestFestivalEndDateInput');
    var fields = document.querySelectorAll('.suggest-festival-date-field');
    if (!category || !start || !end) return;
    var isFestival = category.value === 'festival';
    fields.forEach(function(field) {
        field.style.display = isFestival ? '' : 'none';
    });
    start.required = isFestival;
    end.required = isFestival;
    if (!isFestival) {
        start.value = '';
        end.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var category = document.getElementById('suggestCategorySelect');
    if (category) {
        category.addEventListener('change', toggleSuggestFestivalDates);
        toggleSuggestFestivalDates();
    }
});

// The map, the address search and the pin come from assets/place_picker.js (Leaflet and
// OpenStreetMap). Whatever the traveller picks fills in the fields that are still empty.
document.addEventListener('DOMContentLoaded', function() {
    ['suggestPlaceNameInput', 'suggestStateSelect', 'suggestLatitudeInput', 'suggestLongitudeInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', checkSuggestDuplicates);
            el.addEventListener('change', checkSuggestDuplicates);
        }
    });

    PlacePicker.attach({
        map: 'suggestPinMap',
        search: 'suggestAddressAutocomplete',
        lat: 'suggestLatitudeInput',
        lng: 'suggestLongitudeInput',
        searchUrl: '../api/geo_search.php',
        onPick: function(place) {
            var nameInput = document.getElementById('suggestPlaceNameInput');
            var addressInput = document.getElementById('suggestAddressAutocomplete');
            var stateSelect = document.getElementById('suggestStateSelect');
            var districtInput = document.getElementById('suggestDistrictInput');
            if (nameInput && !nameInput.value.trim() && place.name) nameInput.value = place.name;
            // Moving the pin a little must not wipe an address someone typed: only a search result replaces it.
            if (addressInput && place.address && (place.label || !addressInput.value.trim())) addressInput.value = place.address;
            if (stateSelect && STATE_BY_ISO[place.stateIso]) stateSelect.value = STATE_BY_ISO[place.stateIso];
            if (districtInput && !districtInput.value.trim() && place.district) districtInput.value = place.district;
            checkSuggestDuplicates();
        }
    });
});

function normSuggestName(value) {
    return String(value || '').toLowerCase()
        .replace(/\([^)]*\)/g, ' ')
        .replace(/[^a-z0-9]+/g, ' ')
        .replace(/\b(the|restaurant|restoran|cafe|museum|muzium|temple|park|taman)\b/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function suggestSimilarity(a, b) {
    a = normSuggestName(a);
    b = normSuggestName(b);
    if (!a || !b) return 0;
    if (a === b) return 100;
    var short = a.length < b.length ? a : b;
    var long = a.length < b.length ? b : a;
    if (long.indexOf(short) !== -1 && short.length >= 5) return 88;
    var words = short.split(' ').filter(Boolean);
    var hits = words.filter(function(w) { return long.indexOf(w) !== -1; }).length;
    return Math.round((hits / Math.max(1, words.length)) * 75);
}

function checkSuggestDuplicates() {
    var name = (document.getElementById('suggestPlaceNameInput') || {}).value || '';
    var state = (document.getElementById('suggestStateSelect') || {}).value || '';
    var lat = parseFloat((document.getElementById('suggestLatitudeInput') || {}).value || '');
    var lng = parseFloat((document.getElementById('suggestLongitudeInput') || {}).value || '');
    var box = document.getElementById('suggestDuplicateBox');
    var list = document.getElementById('suggestDuplicateList');
    if (!box || !list) return;
    var matches = [];
    existingSuggestPlaces.forEach(function(p) {
        if (state && p.state !== state) return;
        var score = suggestSimilarity(name, p.name);
        if (!isNaN(lat) && !isNaN(lng) && p.latitude && p.longitude) {
            var dLat = Math.abs(lat - parseFloat(p.latitude));
            var dLng = Math.abs(lng - parseFloat(p.longitude));
            if (dLat < 0.002 && dLng < 0.002) score = Math.max(score, 85);
        }
        if (score >= 60) matches.push({ place: p, score: score });
    });
    matches.sort(function(a, b) { return b.score - a.score; });
    matches = matches.slice(0, 5);
    box.style.display = matches.length ? 'block' : 'none';
    list.innerHTML = matches.map(function(m) {
        var p = m.place;
        return '<div><strong>#' + p.place_id + ' ' + escapeSuggestHtml(p.name) + '</strong> - ' +
            escapeSuggestHtml([p.district, p.state].filter(Boolean).join(', ')) + ' - ' + m.score + '% match</div>';
    }).join('');
}

function escapeSuggestHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
}
</script>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>

</html>

