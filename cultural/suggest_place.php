<?php
// cultural/suggest_place.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
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

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');

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
    if ($s === "pending") return t('sug.status_pending');
    if ($s === "approved") return t('sug.status_approved');
    if ($s === "rejected") return t('sug.status_rejected');
    return ucfirst($s);
}

?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo te('sug.title'); ?> | <?php echo te('role.traveller'); ?></title>
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
                    <span><?php echo te('sug.brand_sub'); ?></span>
                </div>
            </div>

      <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
        <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
        <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
        <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
        <a href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
        <a href="../cultural/cultural_guide.php" class="active"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
        <a class="nav-profile-link" href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
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
                    <h1><?php echo te('sug.title'); ?></h1>
                    <p><?php echo te('sug.subtitle'); ?></p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn btn-ghost" href="cultural_guide.php"><?php echo te('common.back'); ?></a>
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
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_name'); ?> *</label><br>
                                <input type="text" name="name" id="suggestPlaceNameInput" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_state'); ?> *</label><br>
                                <select name="state" id="suggestStateSelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled selected><?php echo te('sug.choose_state'); ?></option>
                                    <?php foreach ($stateOptions as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_district'); ?></label><br>
                                <input type="text" name="district" id="suggestDistrictInput"
                                    placeholder="<?php echo te('sug.district_placeholder'); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_category'); ?> *</label><br>
                                <select name="category" id="suggestCategorySelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled selected><?php echo te('sug.choose_category'); ?></option>
                                    <?php foreach ($categoryOptions as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars(t_choice('interest', $c)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_description'); ?> *</label><br>
                                <textarea name="description" id="suggestDescriptionInput" rows="4" required
                                    placeholder="<?php echo te('sug.description_placeholder'); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"></textarea>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_address'); ?></label><br>
                                <input type="text" name="address" id="suggestAddressAutocomplete"
                                    placeholder="<?php echo te('sug.address_placeholder'); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;"><?php echo te('sug.address_help'); ?></div>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_lat'); ?></label><br>
                                <input type="text" name="latitude" id="suggestLatitudeInput" placeholder="e.g. 1.4927000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_lng'); ?></label><br>
                                <input type="text" name="longitude" id="suggestLongitudeInput" placeholder="e.g. 103.7414000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_pin'); ?> *</label>
                                <div id="suggestPinMap"></div>
                                <div class="meta" style="margin-top:6px;"><?php echo te('sug.pin_help'); ?></div>
                                <div class="duplicate-warning-box" id="suggestDuplicateBox">
                                    <strong><?php echo te('sug.dup_title'); ?></strong>
                                    <div id="suggestDuplicateList" style="margin-top:6px;"></div>
                                </div>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_cost'); ?></label><br>
                                <input type="number" step="0.01" min="0" name="estimated_cost" value="0.00"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_hours'); ?></label><br>
                                <input type="text" name="opening_hours" id="suggestOpeningHoursInput" placeholder="e.g. 09:00 - 17:00"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3 suggest-festival-date-field" style="display:none;">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_festival_start'); ?> *</label><br>
                                <input type="date" name="festival_start_date" id="suggestFestivalStartDateInput"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3 suggest-festival-date-field" style="display:none;">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_festival_end'); ?> *</label><br>
                                <input type="date" name="festival_end_date" id="suggestFestivalEndDateInput"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;"><?php echo te('sug.festival_help'); ?></div>
                            </div>

                            <!-- CHANGE: Place Image below Opening Hours (as you requested) -->
                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;"><?php echo te('sug.f_image'); ?></label><br>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="width:100%; padding:8px;">
                                <div class="meta"><?php echo te('sug.image_help'); ?></div>
                            </div>
                        </div>

                        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit"><?php echo te('sug.submit'); ?></button>
                            <a class="btn btn-ghost" href="cultural_guide.php"><?php echo te('common.cancel'); ?></a>
                        </div>
                    </form>

                    <hr class="sep" style="margin:18px 0;">

                    <h3 style="margin:0 0 6px 0;"><?php echo te('sug.my_title'); ?></h3>
                    <p class="meta" style="margin:0 0 12px 0;"><?php echo te('sug.my_meta'); ?></p>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo te('sug.col_id'); ?></th>
                                    <th><?php echo te('sug.col_place'); ?></th>
                                    <th><?php echo te('sug.col_state'); ?></th>
                                    <th><?php echo te('sug.col_category'); ?></th>
                                    <th><?php echo te('sug.col_status'); ?></th>
                                    <th><?php echo te('sug.col_created'); ?></th>
                                    <th><?php echo te('sug.col_reviewed'); ?></th>
                                    <th><?php echo te('sug.col_reply'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($mySuggestions) === 0): ?>
                                    <tr>
                                        <td colspan="8"><?php echo te('sug.none'); ?></td>
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
                                            <td><?php echo htmlspecialchars(t_choice('interest', $r["category"])); ?></td>
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
<script>window.PLACE_PICKER_TEXT = <?php echo i18n_js(['common.picker_no_place']); ?>;</script>
<script src="../assets/place_picker.js?v=20261119a"></script>
<script>
var existingSuggestPlaces = <?php echo json_encode($existingPlaces, JSON_UNESCAPED_UNICODE); ?>;
var STATE_BY_ISO = <?php echo json_encode(array_flip(OsmDataService::STATE_ISO)); ?>;
var T = <?php echo i18n_js(['sug.js_match']); ?>;

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
            escapeSuggestHtml([p.district, p.state].filter(Boolean).join(', ')) + ' - ' + T['sug.js_match'].replace('{percent}', m.score) + '</div>';
    }).join('');
}

function escapeSuggestHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
}
</script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>

