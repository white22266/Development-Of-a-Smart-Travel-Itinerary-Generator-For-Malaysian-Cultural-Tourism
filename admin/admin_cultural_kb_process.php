<?php
// admin/admin_cultural_kb_process.php
session_start();
require_once "../config/db_connect.php";
require_once "../services/DuplicatePlaceService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];

function back($msg, $isError = false)
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: admin_cultural_kb.php");
    exit;
}

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function update_supervisor_place_fields(mysqli $conn, int $placeId, float $entranceFee, ?int $halalStatus, int $visitDurationMin): void
{
    $sets = [];
    $params = [];
    $types = "";

    if (table_has_column($conn, "cultural_places", "entrance_fee")) {
        $sets[] = "entrance_fee = ?";
        $params[] = $entranceFee;
        $types .= "d";
    }
    if (table_has_column($conn, "cultural_places", "is_free")) {
        $sets[] = "is_free = ?";
        $params[] = ($entranceFee <= 0.0) ? 1 : 0;
        $types .= "i";
    }
    if (table_has_column($conn, "cultural_places", "halal_status")) {
        $sets[] = "halal_status = ?";
        $params[] = $halalStatus;
        $types .= "i";
    }
    if (table_has_column($conn, "cultural_places", "visit_duration_min")) {
        $sets[] = "visit_duration_min = ?";
        $params[] = max(30, min(360, $visitDurationMin));
        $types .= "i";
    }
    if (table_has_column($conn, "cultural_places", "website_url")) {
        $sets[] = "website_url = ?";
        $params[] = trim((string)($_POST["website_url"] ?? ""));
        $types .= "s";
    }
    if (table_has_column($conn, "cultural_places", "phone_number")) {
        $sets[] = "phone_number = ?";
        $params[] = trim((string)($_POST["phone_number"] ?? ""));
        $types .= "s";
    }
    if (table_has_column($conn, "cultural_places", "avg_rating")) {
        $ratingRaw = trim((string)($_POST["avg_rating"] ?? ""));
        if ($ratingRaw === "") {
            $sets[] = "avg_rating = NULL";
        } else {
            $sets[] = "avg_rating = ?";
            $params[] = max(0.0, min(5.0, (float)$ratingRaw));
            $types .= "d";
        }
    }

    if (empty($sets)) return;

    $params[] = $placeId;
    $types .= "i";

    $stmt = $conn->prepare("UPDATE cultural_places SET " . implode(", ", $sets) . " WHERE place_id = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

function normalize_optional_date(string $value): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    $dt = DateTime::createFromFormat("Y-m-d", $value);
    return ($dt && $dt->format("Y-m-d") === $value) ? $value : null;
}

function update_festival_dates(mysqli $conn, int $placeId, string $category, ?string $startDate, ?string $endDate): void
{
    if (!table_has_column($conn, "cultural_places", "festival_start_date")
        || !table_has_column($conn, "cultural_places", "festival_end_date")) {
        return;
    }

    if ($category !== "festival") {
        $startDate = null;
        $endDate = null;
    }

    $stmt = $conn->prepare("
        UPDATE cultural_places
        SET festival_start_date = ?, festival_end_date = ?
        WHERE place_id = ?
    ");
    $stmt->bind_param("ssi", $startDate, $endDate, $placeId);
    $stmt->execute();
    $stmt->close();
}

$action = strtolower(trim($_POST["action"] ?? $_GET["action"] ?? ""));
$currentAdminId = (int)($_SESSION["admin_id"] ?? 0);

if ($action === "import_csv") {
    if (empty($_FILES["csv_file"]["tmp_name"]) || ($_FILES["csv_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        back("Please upload a valid CSV file.", true);
    }

    $handle = fopen($_FILES["csv_file"]["tmp_name"], "r");
    if (!$handle) back("Unable to read CSV file.", true);

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        back("CSV file is empty.", true);
    }
    $headers = array_map(fn($h) => strtolower(trim((string)$h)), $headers);
    $dupeService = new DuplicatePlaceService($conn);
    $hasDistCol = table_has_column($conn, "cultural_places", "district");

    $imported = 0;
    $skipped = 0;
    $errors = [];
    $rowNo = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNo++;
        $data = [];
        foreach ($headers as $idx => $key) {
            $data[$key] = trim((string)($row[$idx] ?? ""));
        }

        $name = $data["name"] ?? "";
        $state = $data["state"] ?? "";
        $district = $data["district"] ?? "";
        $category = strtolower($data["category"] ?? "");
        $description = $data["description"] ?? "";
        $address = $data["address"] ?? "";
        $latitude = $data["latitude"] ?? "";
        $longitude = $data["longitude"] ?? "";
        $opening = $data["opening_hours"] ?? ($data["opening"] ?? "");
        $cost = (float)($data["estimated_cost"] ?? $data["entrance_fee"] ?? 0);
        $imageUrl = $data["image_url"] ?? "";
        $isActive = isset($data["is_active"]) && $data["is_active"] !== "" ? (int)$data["is_active"] : 1;
        $isActive = $isActive === 0 ? 0 : 1;
        $festivalStart = normalize_optional_date((string)($data["festival_start_date"] ?? $data["event_start_date"] ?? ""));
        $festivalEnd = normalize_optional_date((string)($data["festival_end_date"] ?? $data["event_end_date"] ?? ""));

        if ($name === "" || $state === "" || $category === "") {
            $skipped++;
            $errors[] = "Row $rowNo skipped: name, state and category are required.";
            continue;
        }
        if (!in_array($category, $categoryOptions, true)) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: invalid category '$category'.";
            continue;
        }
        if ($latitude !== "" && !is_numeric($latitude)) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: latitude must be numeric.";
            continue;
        }
        if ($longitude !== "" && !is_numeric($longitude)) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: longitude must be numeric.";
            continue;
        }
        if ($imageUrl !== "" && !preg_match('#^(https?://|uploads/)#i', $imageUrl)) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: image_url must be http(s) or uploads/ path.";
            continue;
        }
        if ($category === "festival") {
            if ($festivalStart === null || $festivalEnd === null) {
                $skipped++;
                $errors[] = "Row $rowNo skipped: festival_start_date and festival_end_date are required for festival records.";
                continue;
            }
            if ($festivalEnd < $festivalStart) {
                $skipped++;
                $errors[] = "Row $rowNo skipped: festival_end_date cannot be earlier than festival_start_date.";
                continue;
            }
        }

        $candidate = [
            "name" => $name,
            "state" => $state,
            "category" => $category,
            "latitude" => $latitude,
            "longitude" => $longitude,
        ];
        if ($dupeService->hasHighConfidenceDuplicate($candidate)) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: possible duplicate place '$name'.";
            continue;
        }

        $latVal = $latitude === "" ? null : (float)$latitude;
        $lngVal = $longitude === "" ? null : (float)$longitude;
        $imageDb = $imageUrl === "" ? null : $imageUrl;

        if ($hasDistCol) {
            $stmt = $conn->prepare("
                INSERT INTO cultural_places
                (state, district, name, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active, created_by_admin_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if ($stmt) $stmt->bind_param("ssssssdddssii", $state, $district, $name, $category, $description, $address, $latVal, $lngVal, $cost, $opening, $imageDb, $isActive, $currentAdminId);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO cultural_places
                (state, name, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active, created_by_admin_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if ($stmt) $stmt->bind_param("sssssdddssii", $state, $name, $category, $description, $address, $latVal, $lngVal, $cost, $opening, $imageDb, $isActive, $currentAdminId);
        }

        if (!$stmt || !$stmt->execute()) {
            $skipped++;
            $errors[] = "Row $rowNo skipped: " . ($stmt ? $stmt->error : $conn->error);
            if ($stmt) $stmt->close();
            continue;
        }
        $newPlaceId = (int)$stmt->insert_id;
        $stmt->close();
        update_supervisor_place_fields($conn, $newPlaceId, $cost, null, (int)($data["visit_duration_min"] ?? 90));
        update_festival_dates($conn, $newPlaceId, $category, $festivalStart, $festivalEnd);
        $imported++;
    }
    fclose($handle);

    $_SESSION["success_message"] = "CSV import completed. Imported: $imported. Skipped: $skipped.";
    if (!empty($errors)) $_SESSION["form_errors"] = array_slice($errors, 0, 8);
    header("Location: admin_cultural_kb.php");
    exit;
}

/* ================= DELETE ================= */
if ($action === "delete") {
    $placeId = (int)($_GET["place_id"] ?? 0);
    if ($placeId <= 0) back("Invalid place id.", true);

    $stmt = $conn->prepare("DELETE FROM cultural_places WHERE place_id = ?");
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $stmt->close();

    back("Place deleted successfully.");
}

// ================= IMAGE UPLOAD =================
$imageUrl = null;
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {

    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        back("Invalid image type. Only JPG, PNG, WEBP allowed.", true);
    }

    $fileName = "place_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        back("Image upload failed.", true);
    }

    // save in db
    $imageUrl = "uploads/places/" . $fileName;
}
// =================================================

/* ======================= CREATE / UPDATE======================= */

if ($action === "create" || $action === "update") {
    $placeId = (int)($_POST["place_id"] ?? 0);

    $name     = trim($_POST["name"]     ?? "");
    $state    = trim($_POST["state"]    ?? "");
    $district = trim($_POST["district"] ?? "");  // NEW: district field
    $category = trim($_POST["category"] ?? "");

    $description = trim($_POST["description"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $latitude = trim($_POST["latitude"] ?? "");
    $longitude = trim($_POST["longitude"] ?? "");
    $opening = trim($_POST["opening_hours"] ?? "");
    $festivalStartDate = normalize_optional_date((string)($_POST["festival_start_date"] ?? ""));
    $festivalEndDate = normalize_optional_date((string)($_POST["festival_end_date"] ?? ""));

    $cost = (float)($_POST["estimated_cost"] ?? 0);
    $halalRaw = trim((string)($_POST["halal_status"] ?? ""));
    $halalStatus = ($halalRaw === "") ? null : (($halalRaw === "1") ? 1 : 0);
    $visitDurationMin = (int)($_POST["visit_duration_min"] ?? 90);
    if ($visitDurationMin <= 0) $visitDurationMin = 90;
    $isActive = (int)($_POST["is_active"] ?? 1);
    $isActive = ($isActive === 0) ? 0 : 1;
    // --- NEW: handle remove checkbox + pasted URL ---
    $removeImage = (int)($_POST["remove_image"] ?? 0) === 1;
    $imageUrlInput = trim($_POST["image_url"] ?? "");

    // If no upload, allow external URL
    if ($imageUrl === null && $imageUrlInput !== "") {
        if (!preg_match('#^https?://#i', $imageUrlInput)) {
            back("Image URL must start with http:// or https://", true);
        }
        $imageUrl = $imageUrlInput;
    }

    // Normalize lat/lng (allow NULL)
    $latVal = ($latitude === "") ? null : (float)$latitude;
    $lngVal = ($longitude === "") ? null : (float)$longitude;

    // Decide whether image_url should be changed on UPDATE
    $changeImage = ($imageUrl !== null) || $removeImage;     // upload/url OR remove checked
    $newImageVal = ($imageUrl !== null) ? $imageUrl : null;  // if removing => NULL

    if ($name === "" || $state === "" || $category === "") back("Name, state and category are required.", true);
    if (!in_array($category, $categoryOptions, true)) back("Invalid category.", true);
    if ($cost < 0) back("Cost cannot be negative.", true);
    if ($category === "festival") {
        if ($festivalStartDate === null || $festivalEndDate === null) {
            back("Festival start date and end date are required for festival records.", true);
        }
        if ($festivalEndDate < $festivalStartDate) {
            back("Festival end date cannot be earlier than start date.", true);
        }
    }
    // latitude/longitude optional，但如果填了就要是数字
    if ($latitude !== "" && !is_numeric($latitude)) back("Latitude must be numeric.", true);
    if ($longitude !== "" && !is_numeric($longitude)) back("Longitude must be numeric.", true);
    $allowDuplicate = (int)($_POST["allow_duplicate"] ?? 0) === 1;
    $dupeService = new DuplicatePlaceService($conn);
    $dupeCandidate = [
        "name" => $name,
        "state" => $state,
        "category" => $category,
        "latitude" => $latitude,
        "longitude" => $longitude,
    ];
    $excludeId = ($action === "update") ? $placeId : null;
    if (!$allowDuplicate && $dupeService->hasHighConfidenceDuplicate($dupeCandidate, $excludeId)) {
        back("Possible duplicate detected. Review the duplicate panel and tick 'Allow duplicate' only if this is a distinct place.", true);
    }
    /* ===== CREATE ===== */
    if ($action === "create") {
        // Check if district column exists
        $dcChk = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
        $hasDistCol = ($dcChk && $dcChk->num_rows > 0);

        if ($hasDistCol) {
            $stmt = $conn->prepare("
              INSERT INTO cultural_places
              (state, district, name, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                "ssssssdddssi",
                $state, $district, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $imageUrl, $isActive
            );
        } else {
            $stmt = $conn->prepare("
              INSERT INTO cultural_places
              (state, name, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                "sssssdddssi",
                $state, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $imageUrl, $isActive
            );
        }

        $stmt->execute();
        $newPlaceId = (int)$stmt->insert_id;
        $stmt->close();
        if ($newPlaceId > 0) {
            update_supervisor_place_fields($conn, $newPlaceId, $cost, $halalStatus, $visitDurationMin);
            update_festival_dates($conn, $newPlaceId, $category, $festivalStartDate, $festivalEndDate);
        }

        back("Place added successfully.");
    }
    /* ===== UPDATE ===== */
    /* ===== UPDATE ===== */
    if ($placeId <= 0) back("Invalid place id for update.", true);

    // Check if district column exists for UPDATE
    $dcChkU = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
    $hasDistColU = ($dcChkU && $dcChkU->num_rows > 0);

    if ($changeImage) {
        // change image_url (either set new url/path OR set NULL if remove checked)
        if ($hasDistColU) {
            $stmt = $conn->prepare("
              UPDATE cultural_places
              SET state=?, district=?, name=?, category=?, description=?, address=?,
                  latitude=?, longitude=?, estimated_cost=?, opening_hours=?,
                  image_url=?, is_active=?
              WHERE place_id=?
            ");
            $stmt->bind_param(
                "ssssssdddssii",
                $state, $district, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $newImageVal, $isActive, $placeId
            );
        } else {
            $stmt = $conn->prepare("
              UPDATE cultural_places
              SET state=?, name=?, category=?, description=?, address=?,
                  latitude=?, longitude=?, estimated_cost=?, opening_hours=?,
                  image_url=?, is_active=?
              WHERE place_id=?
            ");
            $stmt->bind_param(
                "sssssdddssii",
                $state, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $newImageVal, $isActive, $placeId
            );
        }
    } else {
        // keep old image_url
        if ($hasDistColU) {
            $stmt = $conn->prepare("
              UPDATE cultural_places
              SET state=?, district=?, name=?, category=?, description=?, address=?,
                  latitude=?, longitude=?, estimated_cost=?, opening_hours=?, is_active=?
              WHERE place_id=?
            ");
            $stmt->bind_param(
                "ssssssdddsii",
                $state, $district, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $isActive, $placeId
            );
        } else {
            $stmt = $conn->prepare("
              UPDATE cultural_places
              SET state=?, name=?, category=?, description=?, address=?,
                  latitude=?, longitude=?, estimated_cost=?, opening_hours=?, is_active=?
              WHERE place_id=?
            ");
            $stmt->bind_param(
                "sssssdddsii",
                $state, $name, $category, $description, $address,
                $latVal, $lngVal, $cost, $opening, $isActive, $placeId
            );
        }
    }


    $stmt->execute();
    $stmt->close();
    update_supervisor_place_fields($conn, $placeId, $cost, $halalStatus, $visitDurationMin);
    update_festival_dates($conn, $placeId, $category, $festivalStartDate, $festivalEndDate);

    back("Place updated successfully.");
}
back("Invalid action.", true);
