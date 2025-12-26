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

$resUsers = $conn->query("SELECT (SELECT COUNT(*) FROM travellers) + (SELECT COUNT(*) FROM admins) AS total");
$kpiTotalUsers = $resUsers->fetch_assoc()['total'] ?? 0;

// Pending Content: Count items in 'cultural_place_suggestions' with status 'pending'
$resPending = $conn->query("SELECT COUNT(*) AS total FROM cultural_place_suggestions WHERE status = 'pending'");
$kpiPendingSubmissions = $resPending->fetch_assoc()['total'] ?? 0;

// Cultural Records: Count active items in 'cultural_places'
$resRecords = $conn->query("SELECT COUNT(*) AS total FROM cultural_places WHERE is_active = 1");
$kpiCulturalItems = $resRecords->fetch_assoc()['total'] ?? 0;

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
          <p class="meta">Number of registered travellers and administrators.</p>
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
          <a class="btn btn-ghost" href="../admin/admin_users.php" style="width:100%; justify-content:center;">Manage Users</a>
          <div style="height:10px;"></div>
          <a class="btn btn-ghost" href="../admin/admin_cultural_kb.php" style="width:100%; justify-content:center;">Update Cultural Data</a>
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
      </section>
    </main>
  </div>
</body>

</html>