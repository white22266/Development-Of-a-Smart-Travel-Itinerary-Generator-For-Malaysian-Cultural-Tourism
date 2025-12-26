<?php
// itinerary/smart_generator.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

$interestOptions = [
    "culture" => "Culture",
    "heritage" => "Heritage",
    "food" => "Food",
    "museum" => "Museum",
    "nature" => "Nature",
    "shopping" => "Shopping",
    "festival" => "Festival"
];

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

$transportOptions = [
    "car" => "Car",
    "public_transport" => "Public Transport",
    "walking" => "Walking",
    "motorcycle" => "Motorcycle"
];

// Load preferences list from DB (optional)
$prefList = [];
$stmt = $conn->prepare("SELECT preference_id, trip_days, budget, transport_type, interests, preferred_states, created_at
                        FROM traveller_preferences
                        WHERE traveller_id = ?
                        ORDER BY preference_id DESC
                        LIMIT 50");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $prefList[] = $r;
$stmt->close();

// flash
$errors = $_SESSION["form_errors"] ?? [];
$success = $_SESSION["success_message"] ?? "";
unset($_SESSION["form_errors"], $_SESSION["success_message"]);

function checked($arr, $key)
{
    return (is_array($arr) && in_array($key, $arr, true)) ? "checked" : "";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Smart Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <script>
        // CHANGED: load a preference record into the form (client-side only)
        const prefMap = {};
        <?php foreach ($prefList as $p): ?>
            prefMap[<?php echo (int)$p["preference_id"]; ?>] = {
                trip_days: <?php echo (int)$p["trip_days"]; ?>,
                budget: "<?php echo htmlspecialchars((string)$p["budget"], ENT_QUOTES); ?>",
                transport_type: "<?php echo htmlspecialchars((string)$p["transport_type"], ENT_QUOTES); ?>",
                interests: "<?php echo htmlspecialchars((string)$p["interests"], ENT_QUOTES); ?>",
                preferred_states: "<?php echo htmlspecialchars((string)$p["preferred_states"], ENT_QUOTES); ?>"
            };
        <?php endforeach; ?>

        function applyPref() {
            const sel = document.getElementById('pref_select');
            const id = sel.value;
            if (!id || !prefMap[id]) return;

            const p = prefMap[id];
            document.getElementById('trip_days').value = p.trip_days || "";
            document.getElementById('budget').value = p.budget || "";
            document.getElementById('transport_type').value = p.transport_type || "";

            // interests checkboxes
            const interests = (p.interests || "").split(',').map(x => x.trim()).filter(Boolean);
            document.querySelectorAll('input[name="categories[]"]').forEach(cb => {
                cb.checked = interests.includes(cb.value);
            });

            // states checkboxes
            const states = (p.preferred_states || "").split(',').map(x => x.trim()).filter(Boolean);
            document.querySelectorAll('input[name="states[]"]').forEach(cb => {
                cb.checked = states.includes(cb.value);
            });
        }
    </script>
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Traveller</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                <a class="active" href="smart_generator.php"><span class="dot"></span> Smart Itinerary Generator</a>
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
                    <p>Independent generator. You can generate without going through Preference Form. Weather will adjust outdoor activities.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="card col-12" style="border-left:6px solid rgba(16,185,129,.7);">
                    <strong style="color:rgba(16,185,129,1);"><?php echo htmlspecialchars($success); ?></strong>
                </div>
                <div style="height:12px;"></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="card col-12" style="border-left:6px solid rgba(239,68,68,.7);">
                    <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
                </div>
                <div style="height:12px;"></div>
            <?php endif; ?>

            <section class="grid">
                <div class="card col-12">
                    <h3>Generate Settings</h3>

                    <?php if (!empty($prefList)): ?>
                        <div style="margin-bottom:12px;">
                            <label style="font-size:13px; font-weight:800;">Load from saved preference (optional)</label><br>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                <select id="pref_select" style="padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); min-width:280px;">
                                    <option value="">— Select preference —</option>
                                    <?php foreach ($prefList as $p): ?>
                                        <option value="<?php echo (int)$p["preference_id"]; ?>">
                                            #<?php echo (int)$p["preference_id"]; ?> | <?php echo (int)$p["trip_days"]; ?>D | RM<?php echo htmlspecialchars((string)$p["budget"]); ?> | <?php echo htmlspecialchars((string)$p["created_at"]); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-ghost" onclick="applyPref()">Apply</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="generate_itinerary_process.php">
                        <div class="grid" style="gap:12px;">
                            <div class="col-4">
                                <label style="font-size:13px; font-weight:800;">Trip Days *</label><br>
                                <input id="trip_days" type="number" name="trip_days" min="1" max="30" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-4">
                                <label style="font-size:13px; font-weight:800;">Budget (RM) *</label><br>
                                <input id="budget" type="number" name="budget" min="1" step="0.01" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-4">
                                <label style="font-size:13px; font-weight:800;">Transport *</label><br>
                                <select id="transport_type" name="transport_type" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled selected>— Choose —</option>
                                    <?php foreach ($transportOptions as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Interests (Categories) *</label>
                                <p class="meta" style="margin:6px 0 8px;">Select at least 1.</p>
                                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;">
                                    <?php foreach ($interestOptions as $key => $label): ?>
                                        <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                                            <input type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($key); ?>">
                                            <?php echo htmlspecialchars($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">States (Optional)</label>
                                <p class="meta" style="margin:6px 0 8px;">Empty = nationwide.</p>
                                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;">
                                    <?php foreach ($stateOptions as $s): ?>
                                        <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                                            <input type="checkbox" name="states[]" value="<?php echo htmlspecialchars($s); ?>">
                                            <?php echo htmlspecialchars($s); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit">Generate Itinerary</button>
                            <a class="btn btn-ghost" href="my_itineraries.php">My Itineraries</a>
                        </div>
                    </form>

                </div>
            </section>

        </main>
    </div>
</body>

</html>