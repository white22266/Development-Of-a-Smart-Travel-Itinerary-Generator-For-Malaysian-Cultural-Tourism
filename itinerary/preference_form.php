<?php
// itinerary/preference_form.php
session_start();

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

// flash messages
$errors = $_SESSION["form_errors"] ?? [];
$success = $_SESSION["success_message"] ?? "";
$old = $_SESSION["old_input"] ?? [];

unset($_SESSION["form_errors"], $_SESSION["success_message"], $_SESSION["old_input"]);

// Options
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

// helpers
function checked($arr, $key)
{
  return (is_array($arr) && in_array($key, $arr, true)) ? "checked" : "";
}
function selected($val, $key)
{
  return ($val === $key) ? "selected" : "";
}

$oldTripDays = $old["trip_days"] ?? "";
$oldBudget = $old["budget"] ?? "";
$oldTransport = $old["transport_type"] ?? "";
$oldInterests = $old["interests"] ?? [];
$oldStates = $old["preferred_states"] ?? [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Traveler Preference Analyzer | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div class="brand-title">
          <strong>Smart Travel Itinerary Generator</strong>
          <span>Traveller Preference Analyzer</span>
        </div>
      </div>

      <nav class="nav" aria-label="Sidebar Navigation">
        <a href="../dashboard/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
        <a class="active" href="preference_form.php"><span class="dot"></span> Traveler Preference Analyzer</a>
        <a href="my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary Module</a>
        <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
        <a href="../profile/profile.php"><span class="dot"></span> Profile</a>
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
          <h1>Traveler Preference Analyzer</h1>
          <p>Enter your duration, budget and interests. The system will use these preferences to generate a structured cultural itinerary.</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="../dashboard/traveller_dashboard.php">Back to Dashboard</a>
        </div>
      </div>

      <section class="grid">
        <div class="card col-12">
          <h3>Preference Form</h3>
          <p class="meta">All fields marked required must be completed before itinerary generation.</p>

          <?php if ($success): ?>
            <p style="color:green; font-weight:700;"><?php echo htmlspecialchars($success); ?></p>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <ul style="color:red; margin:0 0 12px 18px;">
              <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <form method="post" action="preference_process.php">
            <div class="grid">
              <div class="card col-6" style="box-shadow:none;">
                <h3 style="margin-bottom:8px;">Trip Details</h3>

                <label style="font-size:13px; font-weight:700;">Travel Duration (Days) *</label><br>
                <input type="number" name="trip_days" min="1" max="30" required
                  placeholder="Insert duration between 1 to 30 days"
                  value="<?php echo htmlspecialchars($oldTripDays); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">

                <div style="height:12px;"></div>

                <label style="font-size:13px; font-weight:700;">Budget (RM) *</label><br>
                <input type="number" name="budget" min="1" step="0.01" required
                  placeholder="Insert estimated budget(RM)"
                  value="<?php echo htmlspecialchars($oldBudget); ?>"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">

                <div style="height:12px;"></div>

                <label style="font-size:13px; font-weight:700;">Transport Type *</label><br>
                <select name="transport_type" required class="select-placeholder"
                  style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                  <option value="" disabled <?php echo empty($oldTransport) ? 'selected' : ''; ?>>— Please choose a transport type —</option>

                  <?php foreach ($transportOptions as $k => $v): ?>
                    <option value="<?php echo htmlspecialchars($k); ?>" <?php echo selected($oldTransport, $k); ?>>
                      <?php echo htmlspecialchars($v); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="card col-6" style="box-shadow:none;">
                <h3 style="margin-bottom:8px;">Interests *</h3>
                <p class="meta" style="margin-top:0;">Select at least one interest.</p>

                <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;">
                  <?php foreach ($interestOptions as $key => $label): ?>
                    <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                      <input type="checkbox" name="interests[]" value="<?php echo htmlspecialchars($key); ?>"
                        <?php echo checked($oldInterests, $key); ?>>
                      <?php echo htmlspecialchars($label); ?>
                    </label>
                  <?php endforeach; ?>
                </div>

                <hr class="sep">

                <h3 style="margin-bottom:8px;">Preferred States (Optional)</h3>
                <p class="meta" style="margin-top:0;">Leave empty to allow nationwide recommendations.</p>


                <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px;">
                  <?php foreach ($stateOptions as $s): ?>
                    <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                      <input type="checkbox"
                        name="preferred_states[]"
                        value="<?php echo htmlspecialchars($s); ?>"
                        <?php echo checked($oldStates, $s); ?>>
                      <?php echo htmlspecialchars($s); ?>
                    </label>
                  <?php endforeach; ?>
                </div>

              </div>
            </div>

            <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
              <button class="btn btn-primary" type="submit">Save Preferences</button>
              <a class="btn btn-ghost" href="../dashboard/traveller_dashboard.php">Cancel</a>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>
</body>

</html>