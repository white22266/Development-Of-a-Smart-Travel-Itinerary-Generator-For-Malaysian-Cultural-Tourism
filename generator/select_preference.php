<?php
session_start();
require_once "../../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../../auth/login.php?role=traveller");
    exit;
}

$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT preference_id, trip_days, budget, transport_type, interests, preferred_states, created_at
  FROM traveller_preferences
  WHERE traveller_id = ?
  ORDER BY preference_id DESC
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Select Preference</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <link rel="stylesheet" href="../assets/itinerary_generator_style.css">
</head>

<body>
    <div class="app">
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
                    <a href="../dashboard/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                    <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                    <a class="active" href="generator_select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
                    <a href="../summary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
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

                <main class="content">
                    <div class="topbar">
                        <div class="page-title">
                            <h1>Smart Itinerary Generator</h1>
                            <p class="meta">Select a saved preference and confirm items per day.</p>
                        </div>
                        <div class="actions">
                            <a class="btn btn-ghost" href="../../dashboard/traveller_dashboard.php">Back</a>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="card" style="border-left:6px solid rgba(239,68,68,.7);">
                            <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
                        </div>
                        <div style="height:12px;"></div>
                    <?php endif; ?>

                    <div class="card">
                        <h3>Choose Preference</h3>

                        <?php if ($res->num_rows === 0): ?>
                            <p style="color:#ef4444; font-weight:800;">No preference found. Create one first.</p>
                            <a class="btn btn-primary" href="../../preference/preference_form.php">Go to Preference Analyzer</a>
                        <?php else: ?>
                            <label style="font-weight:900; font-size:13px;">Saved Preferences</label>
                            <select id="prefSelect" required
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px;">
                                <option value="" disabled selected>— Select one preference —</option>
                                <?php while ($p = $res->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$p["preference_id"]; ?>">
                                        #<?php echo (int)$p["preference_id"]; ?> |
                                        <?php echo (int)$p["trip_days"]; ?> days |
                                        RM<?php echo number_format((float)$p["budget"], 2); ?> |
                                        <?php echo htmlspecialchars($p["transport_type"]); ?> |
                                        <?php echo htmlspecialchars($p["interests"]); ?> |
                                        <?php echo htmlspecialchars($p["preferred_states"] ?? "Malaysia"); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>

                            <div style="height:14px;"></div>

                            <h3>Preference Selected: </h3>
                            <p class="muted">View-only</p>

                            <div class="grid" id="prefBoxes" style="margin-top:10px;">
                                <div class="box"><label>Trip Days</label>
                                    <div class="v" id="vDays">—</div>
                                </div>
                                <div class="box"><label>Budget (RM)</label>
                                    <div class="v" id="vBudget">—</div>
                                </div>
                                <div class="box"><label>Transport Type (Preference)</label>
                                    <div class="v" id="vTransport">—</div>
                                </div>
                                <div class="box"><label>Interests</label>
                                    <div class="v" id="vInterests">—</div>
                                </div>
                                <div class="box"><label>Preferred States</label>
                                    <div class="v" id="vStates">—</div>
                                </div>
                                <div class="box"><label>Created At</label>
                                    <div class="v" id="vCreated">—</div>
                                </div>
                            </div>

                            <div style="height:14px;"></div>

                            <h3>Places Per Day (3–5):</h3>

                            <form method="post" action="generate_itinerary.php" style="margin-top:10px;">
                                <input type="hidden" name="preference_id" id="preference_id" value="">

                                <label style="font-weight:900; font-size:13px;">Places Per Day</label>
                                <select name="items_per_day" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px;">
                                    <option value="3" selected>3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                </select>

                                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <button class="btn btn-primary" type="submit">Confirm & Generate</button>
                                    <a class="btn btn-ghost" href="../preference/preference_form.php">Edit Preference</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </main>
        </div>

        <script>
            const sel = document.getElementById("prefSelect");
            const hidden = document.getElementById("preference_id");

            async function loadPreference(prefId) {
                const r = await fetch("preference_details.php?preference_id=" + encodeURIComponent(prefId));
                const j = await r.json();
                if (!j.ok) {
                    return;
                }

                document.getElementById("vDays").textContent = j.data.trip_days;
                document.getElementById("vBudget").textContent = Number(j.data.budget).toFixed(2);
                document.getElementById("vTransport").textContent = j.data.transport_type;
                document.getElementById("vInterests").textContent = j.data.interests || "—";
                document.getElementById("vStates").textContent = j.data.preferred_states || "Malaysia";
                document.getElementById("vCreated").textContent = j.data.created_at || "—";
            }

            sel?.addEventListener("change", async () => {
                const prefId = sel.value;
                hidden.value = prefId;
                await loadPreference(prefId);
            });
        </script>
</body>

</html>