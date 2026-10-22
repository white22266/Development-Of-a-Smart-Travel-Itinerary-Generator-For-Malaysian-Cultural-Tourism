<?php
// admin/admin_pending_process.php
session_start();
require_once "../config/db_connect.php";
require_once "../services/DuplicatePlaceService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminId = (int)($_SESSION["admin_id"] ?? 0);

function back($msg, $isError = false)
{
    if ($isError) $_SESSION["form_errors"] = [$msg];
    else $_SESSION["success_message"] = $msg;
    header("Location: admin_pending.php");
    exit;
}

function download_remote_image_to_uploads(string $url): ?string
{
    if (!preg_match('#^https?://#i', $url)) return null;

    $uploadDir = "../uploads/places/";
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) return null;

    $body = false;
    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => "Mozilla/5.0 SmartTravelImageFetcher/1.0",
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) $body = false;
    } else {
        $context = stream_context_create([
            "http" => [
                "method" => "GET",
                "timeout" => 20,
                "header" => "User-Agent: Mozilla/5.0 SmartTravelImageFetcher/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
    }

    if ($body === false || strlen((string)$body) < 200) return null;

    $info = @getimagesizefromstring((string)$body);
    if (!$info || empty($info["mime"]) || strpos($info["mime"], "image/") !== 0) return null;

    $mime = strtolower((string)$info["mime"]);
    $extMap = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];
    $ext = $extMap[$mime] ?? "";
    if ($ext === "") return null;

    $fileName = "suggest_google_" . time() . "_" . rand(1000, 9999) . "." . $ext;
    $targetPath = $uploadDir . $fileName;
    if (file_put_contents($targetPath, $body) === false) return null;

    return "uploads/places/" . $fileName;
}

$action = strtolower(trim($_POST["action"] ?? ""));
$suggestionId = (int)($_POST["suggestion_id"] ?? 0);
$reviewNote = trim($_POST["review_note"] ?? "");

if ($suggestionId <= 0) back("Invalid suggestion id.", true);
if (!in_array($action, ["approve", "reject"], true)) back("Invalid action.", true);

// Load suggestion (must be pending)
$stmt = $conn->prepare("SELECT * FROM cultural_place_suggestions WHERE suggestion_id=? AND status='pending' LIMIT 1");
$stmt->bind_param("i", $suggestionId);
$stmt->execute();
$sug = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sug) back("Suggestion not found or already processed.", true);

$now = date('Y-m-d H:i:s');

if ($action === "reject") {
    // Require message so traveller "receives reply"
    if ($reviewNote === "") back("Please provide a rejection reply so the traveller can revise and resubmit.", true);

    $stmt = $conn->prepare("
        UPDATE cultural_place_suggestions
        SET status='rejected',
            approved_by_admin_id=?,
            approved_at=?,
            review_note=?
        WHERE suggestion_id=?
    ");
    if (!$stmt) back("Reject failed: " . $conn->error, true);

    $stmt->bind_param("issi", $adminId, $now, $reviewNote, $suggestionId);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        back("Reject failed: " . $err, true);
    }
    $stmt->close();
    back("Suggestion rejected. Reply sent to traveller.");
}

// APPROVE: insert into cultural_places then update suggestion
$conn->begin_transaction();

try {
    $allowDuplicate = (int)($_POST["allow_duplicate"] ?? 0) === 1;
    $dupeService = new DuplicatePlaceService($conn);
    if (!$allowDuplicate && $dupeService->hasHighConfidenceDuplicate($sug)) {
        throw new Exception("Possible duplicate detected. Tick 'Allow duplicate' only if this is a distinct place.");
    }

    // 1) Insert into cultural_places (Knowledge Base) in one statement, so the
    //    festival dates are present when the row is created.
    $stmt = $conn->prepare("
        INSERT INTO cultural_places
        (state, district, category, name, description, address, latitude, longitude,
         opening_hours, festival_start_date, festival_end_date, estimated_cost,
         entrance_fee, is_free, image_url, is_active, created_by_admin_id)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    if (!$stmt) throw new Exception("Prepare insert cultural_places failed: " . $conn->error);

    $state = $sug["state"];
    $district = trim((string)($sug["district"] ?? ""));
    if ($district === "") $district = null;
    $category = $sug["category"];
    $name = $sug["name"];
    $description = $sug["description"];
    $address = $sug["address"] ?? "";
    $opening = $sug["opening_hours"] ?? "";
    $festivalStartDate = $sug["festival_start_date"] ?? null;
    $festivalEndDate = $sug["festival_end_date"] ?? null;
    if ($category === "festival") {
        if (empty($festivalStartDate) || empty($festivalEndDate)) {
            throw new Exception("Festival suggestions require a start date and end date before approval.");
        }
        if ($festivalEndDate < $festivalStartDate) {
            throw new Exception("Festival end date cannot be earlier than festival start date.");
        }
    } else {
        $festivalStartDate = null;
        $festivalEndDate = null;
    }
    $imageUrl = trim((string)($sug["image_url"] ?? ""));
    if ($imageUrl !== "" && preg_match('#^https?://#i', $imageUrl)) {
        $downloadedImage = download_remote_image_to_uploads($imageUrl);
        if ($downloadedImage !== null) {
            $imageUrl = $downloadedImage;
        }
    }
    if ($imageUrl === "") $imageUrl = null;

    $lat = $sug["latitude"];
    $lng = $sug["longitude"];
    if ($lat === "" || $lat === null) $lat = null;
    if ($lng === "" || $lng === null) $lng = null;

    $cost = (float)($sug["estimated_cost"] ?? 0);
    $isFree = ($cost <= 0.0) ? 1 : 0;

    $stmt->bind_param(
        "ssssssddsssddisi",
        $state,
        $district,
        $category,
        $name,
        $description,
        $address,
        $lat,
        $lng,
        $opening,
        $festivalStartDate,
        $festivalEndDate,
        $cost,
        $cost,
        $isFree,
        $imageUrl,
        $adminId
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Insert cultural_places failed: " . $err);
    }
    $placeId = (int)$stmt->insert_id;
    $stmt->close();

    // Allow optional review note for approval (can be blank)
    $reviewNoteOrNull = ($reviewNote === "") ? null : $reviewNote;

    // 2) Update suggestion as approved
    $stmt = $conn->prepare("
        UPDATE cultural_place_suggestions
        SET status='approved',
            approved_by_admin_id=?,
            approved_place_id=?,
            approved_at=?,
            review_note=?
        WHERE suggestion_id=?
    ");
    if (!$stmt) throw new Exception("Prepare update suggestion failed: " . $conn->error);

    $stmt->bind_param("iissi", $adminId, $placeId, $now, $reviewNoteOrNull, $suggestionId);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception("Update suggestion failed: " . $err);
    }
    $stmt->close();

    $conn->commit();
    back("Suggestion approved and published to Knowledge Base.");
} catch (Exception $e) {
    $conn->rollback();
    back($e->getMessage(), true);
}
