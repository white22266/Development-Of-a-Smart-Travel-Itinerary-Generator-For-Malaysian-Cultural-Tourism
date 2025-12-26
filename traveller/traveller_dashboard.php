<?php
// traveller/traveller_dashboard.php
session_start();
require_once "../config/db_connect.php";

// Access control aligned with login_process.php
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$userName = $_SESSION["traveller_name"] ?? "Traveller";

/* =========================
   KPI + Recent data (DB)
   ========================= */

// 1) Trips Generated (all itineraries)
$kpiTripsGenerated = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM itineraries WHERE traveller_id = ?");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$kpiTripsGenerated = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
$stmt->close();

// 2) Saved Itineraries (status = saved)
$kpiSavedItineraries = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM itineraries WHERE traveller_id = ? AND status = 'saved'");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$kpiSavedItineraries = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
$stmt->close();

// 3) Preferred States (unique states used in traveller_preferences.preferred_states)
$kpiFavouriteStates = 0;
$stateCounts = []; // optional: for future use (top states)
$stmt = $conn->prepare("SELECT preferred_states FROM traveller_preferences WHERE traveller_id = ?");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $raw = trim((string)($row["preferred_states"] ?? ""));
  if ($raw === "") continue;

  $parts = array_filter(array_map("trim", explode(",", $raw)));
  foreach ($parts as $s) {
    if ($s === "") continue;
    $stateCounts[$s] = ($stateCounts[$s] ?? 0) + 1;
  }
}
$stmt->close();
$kpiFavouriteStates = count($stateCounts);

// 4) Recent Itineraries (latest 3)
$recentItineraries = [];
$stmt = $conn->prepare("
  SELECT itinerary_id, title, status, created_at
  FROM itineraries
  WHERE traveller_id = ?
  ORDER BY created_at DESC, itinerary_id DESC
  LIMIT 4
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $recentItineraries[] = $row;
}
$stmt->close();

/* =========================
   Static tips (UI content)
   ========================= */
$quickTips = [
  ["title" => "Generate a new itinerary", "desc" => "Select a saved preference and generate a structured trip plan.", "badge" => "Itinerary"],
  ["title" => "Route & time estimation", "desc" => "Review distance and travel time segments to support scheduling.", "badge" => "Maps"],
  ["title" => "Weather-aware planning", "desc" => "Outdoor activities can be adjusted when weather conditions are unfavourable.", "badge" => "Weather"],
];

// Helper for badge label
function status_label($s)
{
  $s = strtolower(trim((string)$s));
  if ($s === "saved") return "Saved";
  if ($s === "exported") return "Exported";
  if ($s === "draft") return "Draft";
  return ucfirst($s);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Traveller Dashboard | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div class="brand-title">
          <strong>Smart Travel Itinerary Generator</strong>
          <span>Traveller Dashboard</span>
        </div>
      </div>

      <nav class="nav" aria-label="Sidebar Navigation">
        <a class="active" href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
        <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
        <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
        <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
        <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
        <a href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
        <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
      </nav>

      <div class="sidebar-footer">
        <div class="small">Logged in as:</div>
        <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($userName); ?></div>
        <div class="chip">Role: Traveller</div>
      </div>
    </aside>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1>Traveller Dashboard</h1>
          <p>Access modules, generate cultural-focused itineraries, and review recent plans.</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="../itinerary/my_itineraries.php">My Itineraries</a>
          <a class="btn btn-primary" href="../itinerary/select_preference.php">Generate Itinerary</a>
        </div>
      </div>

      <section class="grid">
        <div class="card col-4">
          <h3>Trips Generated</h3>
          <p class="meta">Total itineraries generated in the system.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiTripsGenerated; ?></div>
            <div class="tag">Total</div>
          </div>
        </div>

        <div class="card col-4">
          <h3>Saved Itineraries</h3>
          <p class="meta">Itineraries currently marked as saved.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiSavedItineraries; ?></div>
            <div class="tag">Saved</div>
          </div>
        </div>

        <div class="card col-4">
          <h3>Preferred States</h3>
          <p class="meta">Unique states selected in your saved preferences.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiFavouriteStates; ?></div>
            <div class="tag">States</div>
          </div>
        </div>

        <div class="card col-6">
          <h3>Quick Tips</h3>
          <p class="meta">Guidance for using the itinerary generator effectively.</p>
          <div class="list">
            <?php foreach ($quickTips as $t): ?>
              <div class="item">
                <div>
                  <strong><?php echo htmlspecialchars($t["title"]); ?></strong>
                  <span><?php echo htmlspecialchars($t["desc"]); ?></span>
                </div>
                <div class="badge"><?php echo htmlspecialchars($t["badge"]); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card col-6">
          <h3>Recent Itineraries</h3>
          <p class="meta">Your latest generated plans.</p>

          <div class="list">
            <?php if (count($recentItineraries) === 0): ?>
              <div class="item">
                <div>
                  <strong>No itineraries yet</strong>
                  <span>Create one via Smart Itinerary Generator.</span>
                </div>
                <div class="badge">New</div>
              </div>
            <?php else: ?>
              <?php foreach ($recentItineraries as $it): ?>
                <div class="item">
                  <div>
                    <strong><?php echo htmlspecialchars($it["title"]); ?></strong>
                    <span>Date: <?php echo htmlspecialchars($it["created_at"]); ?></span>
                  </div>
                  <div class="badge"><?php echo htmlspecialchars(status_label($it["status"])); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <hr class="sep">
          <a class="btn btn-primary" href="../itinerary/my_itineraries.php" style="width:100%; justify-content:center;">Open My Itineraries</a>
        </div>

        <div class="card col-12">
          <h3>Start Planning</h3>
          <p class="meta">Use the modules below to generate, optimise, and review your travel itinerary.</p>

          <div style="margin-top:12px;">
            <a class="btn btn-primary" href="../preference/preference_form.php">Go to Preference Analyzer</a>
            <a class="btn btn-ghost" href="../cultural/cultural_guide.php">Browse Cultural Guide</a>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>

</html>