<?php
// api/place_search.php
// Search over the places in this system's own database, for the "Places I must see" and
// "Places to avoid" pickers of the preference form.
//
//   GET ?q=<text, at least 2 characters>[&states=Melaka,Johor]
//     -> {"status":"ok","results":[{"id","name","state","district","category"}]}   (at most 15)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";

header("Content-Type: application/json; charset=utf-8");

function place_search_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    place_search_reply(["status" => "error", "message" => "Unauthorized"], 401);
}

// Same limit as the address search: typing makes many requests, but not without end.
$now = time();
$recent = array_values(array_filter((array)($_SESSION["place_search_times"] ?? []), static fn($t) => (int)$t > $now - 60));
if (count($recent) >= 60) {
    place_search_reply(["status" => "error", "message" => "Too many searches. Please wait a moment."], 429);
}
$recent[] = $now;
$_SESSION["place_search_times"] = $recent;
session_write_close();

$q = trim((string)($_GET["q"] ?? ""));
if (mb_strlen($q) < 2) {
    place_search_reply(["status" => "ok", "results" => []]);
}
$q = mb_substr($q, 0, 80);

$sql = "SELECT place_id, name, state, district, category FROM cultural_places WHERE is_active = 1 AND name LIKE ? ESCAPE '|'";
$like = "%" . str_replace(["|", "%", "_"], ["||", "|%", "|_"], $q) . "%";
$types = "s";
$params = [$like];

$states = array_values(array_filter(array_map("trim", explode(",", (string)($_GET["states"] ?? "")))));
$states = array_slice($states, 0, 16);
if ($states) {
    $sql .= " AND state IN (" . implode(",", array_fill(0, count($states), "?")) . ")";
    $types .= str_repeat("s", count($states));
    array_push($params, ...$states);
}
$sql .= " ORDER BY name ASC LIMIT 15";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$results = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $results[] = [
        "id" => (int)$row["place_id"],
        "name" => (string)$row["name"],
        "state" => (string)$row["state"],
        "district" => (string)($row["district"] ?? ""),
        "category" => (string)$row["category"],
    ];
}
$stmt->close();
place_search_reply(["status" => "ok", "results" => $results]);
