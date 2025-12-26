<?php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
  header("Location: ../auth/login.php?role=traveller");
  exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_GET["itinerary_id"] ?? 0);
if ($itineraryId <= 0) {
  header("Location: my_itineraries.php");
  exit;
}

$stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id=? AND traveller_id=? LIMIT 1");
$stmt->bind_param("ii", $itineraryId, $travellerId);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) {
  header("Location: my_itineraries.php");
  exit;
}

$stmt = $conn->prepare("
  SELECT day_no, sequence_no, item_type, item_title, estimated_cost, distance_km, travel_time_min, notes
  FROM itinerary_items
  WHERE itinerary_id=?
  ORDER BY day_no, sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$byDay = [];
$grand = 0.0;
while ($r = $res->fetch_assoc()) {
  $d = (int)$r["day_no"];
  $byDay[$d][] = $r;
  $grand += (float)$r["estimated_cost"];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Trip Summary</title>
  <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
  <div class="app">
    <main class="content" style="padding:24px;">
      <div class="topbar">
        <div class="page-title">
          <h1>Trip Summary</h1>
          <p class="meta"><?php echo htmlspecialchars($it["title"]); ?></p>
        </div>
        <div class="actions">
          <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo $itineraryId; ?>">Back to Map</a>
          <a class="btn btn-primary" href="export_pdf.php?itinerary_id=<?php echo $itineraryId; ?>">Export PDF</a>
        </div>
      </div>

      <div class="card">
        <h3>Total Estimated Cost: RM <?php echo number_format($grand, 2); ?></h3>
        <p class="meta">This total is calculated from itinerary_items.</p>
      </div>

      <?php foreach ($byDay as $day => $items): ?>
        <div class="card" style="margin-top:12px;">
          <h3>Day <?php echo (int)$day; ?></h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Cost</th>
                  <th>Distance</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                <?php $dayTotal = 0.0;
                foreach ($items as $r): $dayTotal += (float)$r["estimated_cost"]; ?>
                  <tr>
                    <td><?php echo (int)$r["sequence_no"]; ?></td>
                    <td><strong><?php echo htmlspecialchars($r["item_title"]); ?></strong></td>
                    <td><?php echo htmlspecialchars($r["item_type"]); ?></td>
                    <td><?php echo number_format((float)$r["estimated_cost"], 2); ?></td>
                    <td><?php echo $r["distance_km"] !== null ? number_format((float)$r["distance_km"], 2) : "-"; ?></td>
                    <td><?php echo $r["travel_time_min"] !== null ? (int)$r["travel_time_min"] : "-"; ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <td colspan="12" style="text-align:right; font-weight:900;">
                    Day <?php echo (int)$day; ?> Total: RM <?php echo number_format($dayTotal, 2); ?>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    </main>
  </div>
</body>

</html>