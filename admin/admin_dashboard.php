<?php
// admin/admin_dashboard.php
session_start();

// Access control aligned with login_process.php
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || !isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
  header("Location: ../auth/login.php?role=admin");
  exit;
}
require_once '../config/db_connect.php';
$adminName = $_SESSION["admin_name"] ?? "Administrator";

function admin_table_exists(mysqli $conn, string $table): bool
{
  $table = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '$table'");
  return ($res && $res->num_rows > 0);
}

function admin_column_exists(mysqli $conn, string $table, string $column): bool
{
  $table = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return ($res && $res->num_rows > 0);
}

function admin_short_text($text, int $limit = 90): string
{
  $text = trim((string)$text);
  if ($text === "") return "-";
  if (function_exists("mb_strimwidth")) return mb_strimwidth($text, 0, $limit, "...");
  return strlen($text) > $limit ? substr($text, 0, $limit - 3) . "..." : $text;
}

$hasTravellerActive = admin_column_exists($conn, "travellers", "is_active");
$hasTravellerCreated = admin_column_exists($conn, "travellers", "created_at");
$hasAiChatLogs = admin_table_exists($conn, "ai_chat_logs");

$resUsers = $conn->query("SELECT COUNT(*) AS total FROM travellers");
$kpiTotalUsers = $resUsers->fetch_assoc()['total'] ?? 0;

// Pending Content: Count items in 'cultural_place_suggestions' with status 'pending'
$resPending = $conn->query("SELECT COUNT(*) AS total FROM cultural_place_suggestions WHERE status = 'pending'");
$kpiPendingSubmissions = $resPending->fetch_assoc()['total'] ?? 0;

// Cultural Records: Count active items in 'cultural_places'
$resRecords = $conn->query("SELECT COUNT(*) AS total FROM cultural_places WHERE is_active = 1");
$kpiCulturalItems = $resRecords->fetch_assoc()['total'] ?? 0;

// Generated itineraries: core admin visibility requested by supervisor
$resItineraries = $conn->query("SELECT COUNT(*) AS total FROM itineraries");
$kpiTotalItineraries = $resItineraries->fetch_assoc()['total'] ?? 0;

// Popular states from generated itinerary items
$popularStates = [];
$popularSql = "
  SELECT cp.state, COUNT(*) AS total
  FROM itinerary_items ii
  JOIN cultural_places cp ON cp.place_id = ii.place_id
  WHERE cp.state IS NOT NULL AND cp.state <> ''
  GROUP BY cp.state
  ORDER BY total DESC
  LIMIT 5
";
$resPopular = $conn->query($popularSql);
if ($resPopular) {
  while ($row = $resPopular->fetch_assoc()) $popularStates[] = $row;
}

// 4. Fetch Recent Activity: Display the last 3 generated itineraries
$recentActivities = [];
$activitySql = "SELECT title, created_at FROM itineraries ORDER BY created_at DESC LIMIT 3";
$resActivity = $conn->query($activitySql);
while ($row = $resActivity->fetch_assoc()) {
  $recentActivities[] = [
    "title" => $row['title'],
    "desc" => "Itinerary created on " . date('M d, Y', strtotime($row['created_at'])),
    "badge" => "Recent"
  ];
}

// 5. Fetch Real Pending Validation List
$pendingList = [];
$pendingSql = "SELECT s.category, s.name, s.state, t.full_name, s.status 
               FROM cultural_place_suggestions s 
               JOIN travellers t ON s.traveller_id = t.traveller_id 
               WHERE s.status = 'pending' 
               ORDER BY s.created_at DESC LIMIT 5";
$resList = $conn->query($pendingSql);
while ($row = $resList->fetch_assoc()) {
  $pendingList[] = [
    "type" => ucfirst($row['category']),
    "name" => $row['name'],
    "state" => $row['state'],
    "by" => $row['full_name'],
    "status" => ucfirst($row['status'])
  ];
}

// Dashboard data lists for report-style admin monitoring
$recentTravellers = [];
$travellerActiveSelect = $hasTravellerActive ? "is_active" : "1 AS is_active";
$travellerCreatedSelect = $hasTravellerCreated ? "created_at" : "NULL AS created_at";
$travellerOrder = $hasTravellerCreated ? "created_at DESC, traveller_id DESC" : "traveller_id DESC";
$travellerSql = "
  SELECT traveller_id, full_name, email, phone, {$travellerActiveSelect}, {$travellerCreatedSelect}
  FROM travellers
  ORDER BY {$travellerOrder}
  LIMIT 8
";
$resTravellers = $conn->query($travellerSql);
if ($resTravellers) {
  while ($row = $resTravellers->fetch_assoc()) $recentTravellers[] = $row;
}

$recentItineraries = [];
$itinerarySql = "
  SELECT i.itinerary_id, i.title, i.total_days, i.total_estimated_cost, i.status, i.created_at,
         t.full_name AS traveller_name
  FROM itineraries i
  LEFT JOIN travellers t ON t.traveller_id = i.traveller_id
  ORDER BY i.created_at DESC, i.itinerary_id DESC
  LIMIT 8
";
$resItineraryList = $conn->query($itinerarySql);
if ($resItineraryList) {
  while ($row = $resItineraryList->fetch_assoc()) $recentItineraries[] = $row;
}

$popularPlaces = [];
$popularPlaceSql = "
  SELECT cp.name, cp.state, cp.category, COUNT(*) AS total_used
  FROM itinerary_items ii
  JOIN cultural_places cp ON cp.place_id = ii.place_id
  WHERE ii.place_id IS NOT NULL
  GROUP BY cp.place_id, cp.name, cp.state, cp.category
  ORDER BY total_used DESC
  LIMIT 8
";
$resPopularPlaces = $conn->query($popularPlaceSql);
if ($resPopularPlaces) {
  while ($row = $resPopularPlaces->fetch_assoc()) $popularPlaces[] = $row;
}

$aiChatList = [];
if ($hasAiChatLogs) {
  $aiSql = "
    SELECT a.user_message, a.source, a.created_at, t.full_name AS traveller_name, i.title AS itinerary_title
    FROM ai_chat_logs a
    LEFT JOIN travellers t ON t.traveller_id = a.traveller_id
    LEFT JOIN itineraries i ON i.itinerary_id = a.itinerary_id
    ORDER BY a.created_at DESC, a.chat_id DESC
    LIMIT 6
  ";
  $resAi = $conn->query($aiSql);
  if ($resAi) {
    while ($row = $resAi->fetch_assoc()) $aiChatList[] = $row;
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard | Smart Travel Itinerary Generator</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-badge">ST</div>
        <div class="brand-title">
          <strong>Smart Travel Itinerary Generator</strong>
          <span>Admin Dashboard</span>
        </div>
      </div>

      <nav class="nav" aria-label="Sidebar Navigation">
        <a class="active" href="admin_dashboard.php"><span class="dot"></span> Dashboard</a>
        <a href="../admin/admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
        <a href="../admin/admin_pending.php"><span class="dot"></span> Content Validation</a>
        <a href="../admin/user_manage/index.php"><span class="dot"></span> User Management</a>
        <a href="../admin/admin_reports.php"><span class="dot"></span> Reports</a>
        <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
      </nav>

      <div class="sidebar-footer">
        <div class="small">Logged in as:</div>
        <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($adminName); ?></div>
        <div class="chip">Role: Admin</div>
      </div>
    </aside>

    <main class="content">
      <div class="topbar">
        <div class="page-title">
          <h1>Admin Dashboard</h1>
          <p>Manage the cultural knowledge base, validate content, and maintain system users.</p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="../admin/admin_pending.php">View Content Validation</a>
          <a class="btn btn-primary" href="../admin/admin_cultural_kb.php">Manage Knowledge Base</a>
        </div>
      </div>

      <section class="grid">
        <div class="card col-4">
          <h3>Total Users</h3>
          <p class="meta">Number of registered travellers.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiTotalUsers; ?></div>
            <div class="tag">Users</div>
          </div>
        </div>

        <div class="card col-4">
          <h3>Pending Content</h3>
          <p class="meta">Submitted items waiting for admin validation.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiPendingSubmissions; ?></div>
            <div class="tag">To Validate</div>
          </div>
        </div>

        <div class="card col-4">
          <h3>Cultural Records</h3>
          <p class="meta">Heritage sites, foods, and festivals stored in database.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiCulturalItems; ?></div>
            <div class="tag">Records</div>
          </div>
        </div>

        <div class="card col-4">
          <h3>Generated Trips</h3>
          <p class="meta">Total itineraries generated by travellers.</p>
          <div class="kpi">
            <div class="value"><?php echo (int)$kpiTotalItineraries; ?></div>
            <div class="tag">Itineraries</div>
          </div>
        </div>

        <div class="card col-6">
          <h3>Recent Activity</h3>
          <p class="meta">Latest actions in the system.</p>
          <div class="list">
            <?php foreach ($recentActivities as $a): ?>
              <div class="item">
                <div>
                  <strong><?php echo htmlspecialchars($a["title"]); ?></strong>
                  <span><?php echo htmlspecialchars($a["desc"]); ?></span>
                </div>
                <div class="badge"><?php echo htmlspecialchars($a["badge"]); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card col-6">
          <h3>Quick Actions</h3>
          <p class="meta">Shortcuts aligned to admin modules in the project scope.</p>
          <a class="btn btn-primary" href="../admin/admin_pending.php" style="width:100%; justify-content:center;">Validate Submissions</a>
          <hr class="sep">
          <a class="btn btn-ghost" href="../admin/user_manage/index.php" style="width:100%; justify-content:center;">Manage Users</a>
          <div style="height:10px;"></div>
          <a class="btn btn-ghost" href="../admin/admin_cultural_kb.php" style="width:100%; justify-content:center;">Update Cultural Data</a>
          <div style="height:10px;"></div>
          <a class="btn btn-ghost" href="../admin/admin_reports.php" style="width:100%; justify-content:center;">Generate Report</a>
        </div>

        <div class="card col-12">
          <h3>Popular States</h3>
          <p class="meta">Most-used states across generated itinerary items.</p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>State</th>
                  <th>Itinerary Items</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($popularStates)): ?>
                  <?php foreach ($popularStates as $idx => $row): ?>
                    <tr>
                      <td><?php echo $idx + 1; ?></td>
                      <td><strong><?php echo htmlspecialchars($row["state"]); ?></strong></td>
                      <td><?php echo (int)$row["total"]; ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="3">No itinerary state data yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card col-12">
          <h3>Pending Validation List</h3>
          <p class="meta"> Table records from submitted cultural content.</p>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Item Name</th>
                  <th>State</th>
                  <th>Submitted By</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($pendingList)): ?>
                  <?php foreach ($pendingList as $p): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($p["type"]); ?></td>
                      <td><?php echo htmlspecialchars($p["name"]); ?></td>
                      <td><?php echo htmlspecialchars($p["state"]); ?></td>
                      <td><?php echo htmlspecialchars($p["by"]); ?></td>
                      <td><span class="badge"><?php echo htmlspecialchars($p["status"]); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                      <strong>No pending records found.</strong><br>
                      All traveller submissions have been processed.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card col-12">
          <h3>Data List: Recent Travellers</h3>
          <p class="meta">Latest traveller accounts registered in the system.</p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Phone</th>
                  <th>Status</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($recentTravellers)): ?>
                  <?php foreach ($recentTravellers as $u): ?>
                    <tr>
                      <td><?php echo (int)$u["traveller_id"]; ?></td>
                      <td><strong><?php echo htmlspecialchars($u["full_name"] ?? "-"); ?></strong></td>
                      <td><?php echo htmlspecialchars($u["email"] ?? "-"); ?></td>
                      <td><?php echo htmlspecialchars($u["phone"] ?? "-"); ?></td>
                      <td>
                        <span class="badge"><?php echo ((int)($u["is_active"] ?? 1) === 1) ? "Active" : "Inactive"; ?></span>
                      </td>
                      <td><?php echo htmlspecialchars($u["created_at"] ?? "-"); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="6">No traveller data available.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card col-12">
          <h3>Data List: Recent Generated Itineraries</h3>
          <p class="meta">Latest itinerary records created by travellers, including cost and status.</p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Title</th>
                  <th>Traveller</th>
                  <th>Days</th>
                  <th>Total Cost</th>
                  <th>Status</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($recentItineraries)): ?>
                  <?php foreach ($recentItineraries as $it): ?>
                    <tr>
                      <td><?php echo (int)$it["itinerary_id"]; ?></td>
                      <td><strong><?php echo htmlspecialchars($it["title"] ?? "-"); ?></strong></td>
                      <td><?php echo htmlspecialchars($it["traveller_name"] ?? "-"); ?></td>
                      <td><?php echo (int)($it["total_days"] ?? 0); ?></td>
                      <td>RM <?php echo number_format((float)($it["total_estimated_cost"] ?? 0), 2); ?></td>
                      <td><span class="badge"><?php echo htmlspecialchars($it["status"] ?? "-"); ?></span></td>
                      <td><?php echo htmlspecialchars($it["created_at"] ?? "-"); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="7">No itinerary data available.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card col-12">
          <h3>Data List: Popular Cultural Places</h3>
          <p class="meta">Cultural places most frequently selected by the itinerary generator.</p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Place</th>
                  <th>State</th>
                  <th>Category</th>
                  <th>Used Count</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($popularPlaces)): ?>
                  <?php foreach ($popularPlaces as $idx => $p): ?>
                    <tr>
                      <td><?php echo $idx + 1; ?></td>
                      <td><strong><?php echo htmlspecialchars($p["name"] ?? "-"); ?></strong></td>
                      <td><?php echo htmlspecialchars($p["state"] ?? "-"); ?></td>
                      <td><?php echo htmlspecialchars($p["category"] ?? "-"); ?></td>
                      <td><?php echo (int)($p["total_used"] ?? 0); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="5">No popular place data yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card col-12">
          <h3>Data List: AI Assistant Usage</h3>
          <p class="meta">Recent AI chat questions asked from itinerary view. This supports the required AI feature evidence.</p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Traveller</th>
                  <th>Itinerary</th>
                  <th>Question</th>
                  <th>Source</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($aiChatList)): ?>
                  <?php foreach ($aiChatList as $chat): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($chat["traveller_name"] ?? "-"); ?></td>
                      <td><?php echo htmlspecialchars($chat["itinerary_title"] ?? "-"); ?></td>
                      <td><?php echo htmlspecialchars(admin_short_text($chat["user_message"] ?? "-", 90)); ?></td>
                      <td><span class="badge"><?php echo htmlspecialchars($chat["source"] ?? "-"); ?></span></td>
                      <td><?php echo htmlspecialchars($chat["created_at"] ?? "-"); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="5">No AI chat usage yet. The table will fill after travellers use AI Assistant.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>

</html>
