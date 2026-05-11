<?php
// cultural/submit_review.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$placeId = (int)($_POST["place_id"] ?? 0);
$rating = (int)($_POST["rating"] ?? 0);
$reviewText = trim((string)($_POST["review_text"] ?? ""));

function review_back(int $placeId, string $msg, bool $isError = false): void
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: cultural_guide_detail.php?place_id=" . $placeId);
    exit;
}

if ($travellerId <= 0) review_back($placeId, "Invalid session. Please login again.", true);
if ($placeId <= 0) review_back(0, "Invalid place.", true);
if ($rating < 1 || $rating > 5) review_back($placeId, "Rating must be between 1 and 5.", true);
if (strlen($reviewText) > 2000) review_back($placeId, "Review is too long.", true);

$check = $conn->prepare("SELECT place_id FROM cultural_places WHERE place_id = ? AND is_active = 1 LIMIT 1");
$check->bind_param("i", $placeId);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) review_back($placeId, "Place not found.", true);

$stmt = $conn->prepare("
    INSERT INTO ratings_reviews (place_id, traveller_id, rating, review_text)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), updated_at = CURRENT_TIMESTAMP
");
if (!$stmt) review_back($placeId, "Unable to save review.", true);
$stmt->bind_param("iiis", $placeId, $travellerId, $rating, $reviewText);
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    review_back($placeId, "Unable to save review: " . $err, true);
}
$stmt->close();

$avg = $conn->prepare("SELECT ROUND(AVG(rating), 2) AS avg_rating FROM ratings_reviews WHERE place_id = ?");
$avg->bind_param("i", $placeId);
$avg->execute();
$avgRow = $avg->get_result()->fetch_assoc();
$avg->close();
$avgRating = $avgRow ? (float)$avgRow["avg_rating"] : null;

$cols = [];
$colAvg = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'avg_rating'");
if ($colAvg && $colAvg->num_rows > 0) $cols[] = "avg_rating = ?";
$colRating = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'rating'");
if ($colRating && $colRating->num_rows > 0) $cols[] = "rating = ?";
if (!empty($cols)) {
    $sql = "UPDATE cultural_places SET " . implode(", ", $cols) . " WHERE place_id = ?";
    $upd = $conn->prepare($sql);
    if ($upd) {
        if (count($cols) === 2) $upd->bind_param("ddi", $avgRating, $avgRating, $placeId);
        else $upd->bind_param("di", $avgRating, $placeId);
        $upd->execute();
        $upd->close();
    }
}

review_back($placeId, "Review saved successfully.");
