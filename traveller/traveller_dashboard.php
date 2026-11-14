<?php
// traveller/traveller_dashboard.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";

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

$userName = $_SESSION["traveller_name"] ?? t('common.traveller_default');

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

// 2) Saved preference profiles
$kpiSavedPreferences = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM traveller_preferences WHERE traveller_id = ?");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$kpiSavedPreferences = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
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
  SELECT itinerary_id, title, total_days, created_at
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
  ["title" => t('dash.tip1_title'), "desc" => t('dash.tip1_desc'), "badge" => t('dash.tip1_badge')],
  ["title" => t('dash.tip2_title'), "desc" => t('dash.tip2_desc'), "badge" => t('dash.tip2_badge')],
  ["title" => t('dash.tip3_title'), "desc" => t('dash.tip3_desc'), "badge" => t('dash.tip3_badge')],
];

?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo te('dash.title'); ?> | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
</head>

<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div class="brand-title">
          <strong>Smart Travel Itinerary Generator</strong>
          <span><?php echo te('dash.title'); ?></span>
        </div>
      </div>

      <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
        <a class="active" href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
        <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
        <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
        <a href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
        <a href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
        <a href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
        <a href="../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
      </nav>
      <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>

      <div class="sidebar-footer">
        <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
        <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($userName); ?></div>
        <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
      </div>
    </aside>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1><?php echo te('dash.title'); ?></h1>
          <p><?php echo te('dash.subtitle'); ?></p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="../itinerary/my_itineraries.php"><?php echo te('dash.btn_my'); ?></a>
          <a class="btn btn-primary" href="../itinerary/select_preference.php"><?php echo te('dash.btn_generate'); ?></a>
        </div>
      </div>

      <section class="grid">
        <div class="card col-4">
          <h3><?php echo te('dash.trips_title'); ?></h3>
          <p class="meta"><?php echo te('dash.trips_meta'); ?></p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiTripsGenerated; ?></div>
            <div class="tag"><?php echo te('dash.trips_tag'); ?></div>
          </div>
        </div>

        <div class="card col-4">
          <h3><?php echo te('dash.prefs_title'); ?></h3>
          <p class="meta"><?php echo te('dash.prefs_meta'); ?></p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiSavedPreferences; ?></div>
            <div class="tag"><?php echo te('dash.prefs_tag'); ?></div>
          </div>
        </div>

        <div class="card col-4">
          <h3><?php echo te('dash.states_title'); ?></h3>
          <p class="meta"><?php echo te('dash.states_meta'); ?></p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiFavouriteStates; ?></div>
            <div class="tag"><?php echo te('dash.states_tag'); ?></div>
          </div>
        </div>

        <div class="card col-6">
          <h3><?php echo te('dash.tips_title'); ?></h3>
          <p class="meta"><?php echo te('dash.tips_meta'); ?></p>
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
          <h3><?php echo te('dash.recent_title'); ?></h3>
          <p class="meta"><?php echo te('dash.recent_meta'); ?></p>

          <div class="list">
            <?php if (count($recentItineraries) === 0): ?>
              <div class="item">
                <div>
                  <strong><?php echo te('dash.none_title'); ?></strong>
                  <span><?php echo te('dash.none_desc'); ?></span>
                </div>
                <div class="badge"><?php echo te('dash.none_badge'); ?></div>
              </div>
            <?php else: ?>
              <?php foreach ($recentItineraries as $it): ?>
                <div class="item">
                  <div>
                    <strong><?php echo htmlspecialchars($it["title"]); ?></strong>
                    <span><?php echo te('dash.date', ['date' => i18n_date((string)$it["created_at"], 'short')]); ?></span>
                  </div>
                  <div class="badge"><?php echo te('dash.days_badge', ['n' => (int)($it["total_days"] ?? 0)]); ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <hr class="sep">
          <a class="btn btn-primary" href="../itinerary/my_itineraries.php" style="width:100%; justify-content:center;"><?php echo te('dash.open_my'); ?></a>
        </div>

        <div class="card col-12">
          <h3><?php echo te('dash.start_title'); ?></h3>
          <p class="meta"><?php echo te('dash.start_meta'); ?></p>

          <div style="margin-top:12px;">
            <a class="btn btn-primary" href="../preference/preference_form.php"><?php echo te('dash.go_pref'); ?></a>
            <a class="btn btn-ghost" href="../cultural/cultural_guide.php"><?php echo te('dash.browse_guide'); ?></a>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>

