<?php
// cultural/suggest_place.php
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
    <link rel="stylesheet" href="../assets/dashboard_style.css">
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
                <a href="../cultural/cultural_guide.php" class="active"><span class="dot"></span> Cultural Guide Presentation</a>
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
                                    placeholder="Auto-filled from Google Maps"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Category *</label><br>
                                <select name="category" required
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
                                <div class="meta" style="margin-top:6px;">Select a Google Maps suggestion to auto-fill coordinates.</div>
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

                            <!-- CHANGE: Place Image below Opening Hours (as you requested) -->
                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Place Image (optional)</label><br>
                                <input type="hidden" name="image_url" id="suggestImageUrlInput">
                                <img id="suggestGooglePhotoPreview" alt="Google place photo preview"
                                    style="display:none;width:180px;height:120px;object-fit:cover;border-radius:12px;border:1px solid rgba(15,23,42,0.12);margin:8px 0;background:#f1f5f9;">
                                <input type="file" name="image" accept="image/*" style="width:100%; padding:8px;">
                                <div class="meta">Google photo is auto-filled when available. Upload JPG / PNG / WEBP to override it.</div>
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
<script>
function normalizeMalaysiaState(rawState) {
    var s = (rawState || '').toLowerCase();
    if (s.indexOf('kuala lumpur') !== -1) return 'Kuala Lumpur';
    if (s.indexOf('putrajaya') !== -1) return 'Putrajaya';
    if (s.indexOf('labuan') !== -1) return 'Labuan';
    if (s.indexOf('penang') !== -1 || s.indexOf('pulau pinang') !== -1) return 'Penang';
    if (s.indexOf('malacca') !== -1) return 'Melaka';
    var known = <?php echo json_encode($stateOptions); ?>;
    for (var i = 0; i < known.length; i++) {
        if (s.indexOf(known[i].toLowerCase()) !== -1) return known[i];
    }
    return '';
}

function componentLongName(place, type) {
    if (!place.address_components) return '';
    for (var i = 0; i < place.address_components.length; i++) {
        var c = place.address_components[i];
        if (c.types && c.types.indexOf(type) !== -1) return c.long_name || '';
    }
    return '';
}

function formatOpeningHours(place) {
    if (!place.opening_hours) return '';
    if (place.opening_hours.weekday_text && place.opening_hours.weekday_text.length) {
        return place.opening_hours.weekday_text.join('; ');
    }
    return '';
}

function generateStarterDescription(place, state, district) {
    var name = place.name || 'This place';
    var type = '';
    if (place.types && place.types.length) {
        type = place.types
            .filter(function(t) { return ['point_of_interest', 'establishment', 'tourist_attraction'].indexOf(t) === -1; })
            .slice(0, 2)
            .map(function(t) { return t.replace(/_/g, ' '); })
            .join(' and ');
    }
    var location = [district, state].filter(Boolean).join(', ');
    var sentence = name + (location ? ' is located in ' + location : ' is a Malaysian place of interest') + '.';
    if (type) sentence += ' It is listed on Google Maps as ' + type + '.';
    sentence += ' Please add or verify its cultural background, visitor etiquette, and local significance before submitting.';
    return sentence;
}

function initPlaceAutocomplete() {
    var addressInput = document.getElementById('suggestAddressAutocomplete');
    if (!addressInput || !window.google || !google.maps || !google.maps.places) return;

    var autocomplete = new google.maps.places.Autocomplete(addressInput, {
        componentRestrictions: { country: 'my' },
        fields: [
            'name', 'formatted_address', 'geometry', 'address_components',
            'opening_hours', 'photos', 'editorial_summary', 'types'
        ],
    });

    autocomplete.addListener('place_changed', function() {
        var place = autocomplete.getPlace();
        if (!place || !place.geometry || !place.geometry.location) return;

        var nameInput = document.getElementById('suggestPlaceNameInput');
        var latInput = document.getElementById('suggestLatitudeInput');
        var lngInput = document.getElementById('suggestLongitudeInput');
        var stateSelect = document.getElementById('suggestStateSelect');
        var districtInput = document.getElementById('suggestDistrictInput');
        var openingInput = document.getElementById('suggestOpeningHoursInput');
        var descriptionInput = document.getElementById('suggestDescriptionInput');
        var imageUrlInput = document.getElementById('suggestImageUrlInput');
        var photoPreview = document.getElementById('suggestGooglePhotoPreview');

        addressInput.value = place.formatted_address || addressInput.value;
        if (nameInput && !nameInput.value.trim() && place.name) nameInput.value = place.name;
        if (latInput) latInput.value = place.geometry.location.lat().toFixed(7);
        if (lngInput) lngInput.value = place.geometry.location.lng().toFixed(7);

        var state = normalizeMalaysiaState(componentLongName(place, 'administrative_area_level_1'));
        if (stateSelect && state) stateSelect.value = state;

        var district = componentLongName(place, 'locality') ||
            componentLongName(place, 'administrative_area_level_2') ||
            componentLongName(place, 'sublocality');
        if (districtInput && district) districtInput.value = district;

        var openingText = formatOpeningHours(place);
        if (openingInput && openingText) openingInput.value = openingText;

        if (imageUrlInput && place.photos && place.photos.length) {
            var photoUrl = place.photos[0].getUrl({ maxWidth: 1200, maxHeight: 900 });
            imageUrlInput.value = photoUrl;
            if (photoPreview) {
                photoPreview.src = photoUrl;
                photoPreview.style.display = 'block';
            }
        }

        if (descriptionInput && !descriptionInput.value.trim()) {
            if (place.editorial_summary && place.editorial_summary.overview) {
                descriptionInput.value = place.editorial_summary.overview;
            } else {
                descriptionInput.value = generateStarterDescription(place, state, district);
            }
        }
    });
}
</script>
<?php if (defined("GOOGLE_MAPS_API_KEY") && trim(GOOGLE_MAPS_API_KEY) !== ""): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&libraries=places&callback=initPlaceAutocomplete" async defer></script>
<?php endif; ?>
</body>

</html>
