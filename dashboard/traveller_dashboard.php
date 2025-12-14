<?php
// dashboard/traveller_dashboard.php
session_start();

// Access control aligned with login_process.php
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || !isset($_SESSION["role"]) || $_SESSION["role"] !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$userName = $_SESSION["traveller_name"] ?? "Traveller";

// (Optional) placeholder KPIs - replace with DB queries later
$kpiTripsGenerated = 3;
$kpiSavedItineraries = 2;
$kpiFavouriteStates = 5;

$quickTips = [
  ["title"=>"Generate a new itinerary", "desc"=>"Enter duration, budget and interests to produce a structured trip plan.", "badge"=>"Itinerary"],
  ["title"=>"Route & time estimation", "desc"=>"View travel duration and distance for better scheduling decisions.", "badge"=>"Maps"],
  ["title"=>"Weather-aware planning", "desc"=>"Adjust outdoor activities with indoor alternatives during unfavourable weather.", "badge"=>"Weather"],
];

$recentItineraries = [
  ["name"=>"3D2N Penang Cultural Trail", "date"=>"2025-12-08", "status"=>"Saved"],
  ["name"=>"Johor Heritage & Food Day Trip", "date"=>"2025-12-01", "status"=>"Exported"],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
      <a class="active" href="traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
      <a href="../itinerary/preference_form.php"><span class="dot"></span> Traveler Preference Analyzer</a>
      <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
      <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
      <a href="../profile/profile.php"><span class="dot"></span> Profile</a>
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
        <p>Access your travel planning modules and generate a cultural-focused itinerary based on your preferences.</p>
      </div>
      <div class="actions">
        <a class="btn btn-ghost" href="../itinerary/my_itineraries.php">My Itineraries</a>
        <a class="btn btn-primary" href="../itinerary/preference_form.php">Generate Itinerary</a>
      </div>
    </div>

    <section class="grid">
      <div class="card col-4">
        <h3>Trips Generated</h3>
        <p class="meta">Number of itineraries generated (sample).</p>
        <div class="kpi">
          <div class="value"><?php echo (int)$kpiTripsGenerated; ?></div>
          <div class="tag">Total</div>
        </div>
      </div>

      <div class="card col-4">
        <h3>Saved Itineraries</h3>
        <p class="meta">Itineraries saved for future access (sample).</p>
        <div class="kpi">
          <div class="value"><?php echo (int)$kpiSavedItineraries; ?></div>
          <div class="tag">Saved</div>
        </div>
      </div>

      <div class="card col-4">
        <h3>Preferred States</h3>
        <p class="meta">States frequently selected in preferences (sample).</p>
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
        <p class="meta">Your latest generated plans (sample; connect to DB later).</p>
        <div class="list">
          <?php foreach ($recentItineraries as $it): ?>
            <div class="item">
              <div>
                <strong><?php echo htmlspecialchars($it["name"]); ?></strong>
                <span>Date: <?php echo htmlspecialchars($it["date"]); ?></span>
              </div>
              <div class="badge"><?php echo htmlspecialchars($it["status"]); ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <hr class="sep">
        <a class="btn btn-primary" href="../itinerary/my_itineraries.php" style="width:100%; justify-content:center;">Open My Itineraries</a>
      </div>

      <div class="card col-12">
        <h3>Start Planning</h3>
        <p class="meta">Use the modules below to generate, optimise, and review your travel itinerary.</p>

        <div style="margin-top:12px;">
          <a class="btn btn-primary" href="../itinerary/preference_form.php">Go to Preference Analyzer</a>
          <a class="btn btn-ghost" href="../cultural/cultural_guide.php">Browse Cultural Guide</a>
        </div>
      </div>
    </section>
  </main>
</div>
</body>
</html>
