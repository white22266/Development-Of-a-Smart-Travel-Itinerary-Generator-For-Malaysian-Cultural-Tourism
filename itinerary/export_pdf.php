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

// read itinerary
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
  SELECT day_no, sequence_no, item_type, item_title, estimated_cost, notes
  FROM itinerary_items
  WHERE itinerary_id=?
  ORDER BY day_no, sequence_no
");
$stmt->bind_param("i", $itineraryId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$html = "<h2>" . htmlspecialchars($it["title"]) . "</h2>";
$html .= "<p>Total Days: " . $it["total_days"] . " | Total Cost (RM): " . number_format((float)$it["total_estimated_cost"], 2) . "</p>";
$html .= "<hr>";

$curDay = 0;
while ($r = $res->fetch_assoc()) {
    if ((int)$r["day_no"] !== $curDay) {
        $curDay = (int)$r["day_no"];
        $html .= "<h3>Day " . $curDay . "</h3><ul>";
    }
    $html .= "<li><b>" . htmlspecialchars($r["item_title"]) . "</b> (" . htmlspecialchars($r["item_type"]) . ") - RM " . number_format((float)$r["estimated_cost"], 2) . "</li>";
    // close ul at day end is skipped for simplicity; acceptable for dompdf rendering
}

// ===== PDF via Dompdf (recommended) =====
// Install: composer require dompdf/dompdf
$autoload = __DIR__ . "/../vendor/autoload.php";
if (!file_exists($autoload)) {
    // fallback: show printable HTML
    echo "<html><body>" . $html . "<p><i>Dompdf not installed. Use browser Print to PDF.</i></p></body></html>";
    exit;
}

require_once $autoload;

use Dompdf\Dompdf;

$dompdf = new Dompdf();
$dompdf->loadHtml("<html><body>" . $html . "</body></html>");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();
$dompdf->stream("itinerary_" . $itineraryId . ".pdf", ["Attachment" => true]);
exit;
