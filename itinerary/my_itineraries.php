<?php
session_start();
require_once "../config/db_connect.php";

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
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT itinerary_id, title, total_days, total_estimated_cost, status, created_at
  FROM itineraries
  WHERE traveller_id = ?
  ORDER BY itinerary_id DESC
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
    <title>My Itineraries</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .btn-danger {
            border: 1px solid rgba(220, 38, 38, 0.25);
            background: rgba(220, 38, 38, 0.08);
            color: #991B1B;
        }

        .btn-danger:hover {
            background: rgba(220, 38, 38, 0.12);
        }
    </style>
</head>

<body>
    <div class="app">
        <div class="app">
            <aside class="sidebar">
                <div class="brand">
                    <div class="brand-badge">ST</div>
                    <div class="brand-title">
                        <strong>Smart Travel Itinerary Generator</strong>
                        <span>Cost Estimation & Trip Summary</span>
                    </div>
                </div>

                <nav class="nav" aria-label="Sidebar Navigation">
                    <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                    <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                    <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
                    <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
                    <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
                    <a href="../auth/profile/profile.php"><span class="dot"></span>Profile</a>
                    <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
                </nav>

                <div class="sidebar-footer">
                    <div class="small">Logged in as:</div>
                    <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                    <div class="chip">Role: Traveller</div>
                </div>
            </aside>

            <main class="content" style="padding:24px;">
                <div class="topbar">
                    <div class="page-title">
                        <h1>Cost Estimation & Trip Summary</h1>
                        <p class="meta">View saved itineraries and open details.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-primary" href="select_preference.php">Generate New</a>
                        <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="card" style="margin-bottom:12px; border-color: rgba(16,185,129,0.25); background: rgba(16,185,129,0.06); color: rgb(6,95,70); font-weight:800;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="card" style="margin-bottom:12px; border-color: rgba(239,68,68,0.25); background: rgba(239,68,68,0.06); color: rgb(127,29,29);">
                        <ul style="margin:0 0 0 18px;">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>


                <div class="card">
                    <h3>My Itineraries</h3>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Days</th>
                                    <th>Total (RM)</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $res->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo (int)$r["itinerary_id"]; ?></td>
                                        <td><strong><?php echo htmlspecialchars($r["title"]); ?></strong></td>
                                        <td><?php echo (int)$r["total_days"]; ?></td>
                                        <td><?php echo number_format((float)$r["total_estimated_cost"], 2); ?></td>
                                        <td><?php echo htmlspecialchars($r["status"]); ?></td>
                                        <td>
                                            <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>">View</a>
                                            <a class="btn btn-ghost" href="trip_summary.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>">Summary</a>
                                            <form method="post"
                                                action="itinerary_delete.php"
                                                style="display:inline;"
                                                onsubmit="return confirm('Delete this itinerary? This action cannot be undone.');">
                                                <input type="hidden" name="itinerary_id" value="<?php echo (int)$r["itinerary_id"]; ?>">
                                                <button type="submit" class="btn btn-ghost btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($res->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="6">No itineraries yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
</body>

</html>