<?php
// admin/admin_cultural_kb.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/DuplicatePlaceService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminName = $_SESSION["admin_name"] ?? "Administrator";

// flash
$success = $_SESSION["success_message"] ?? "";
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

// filters
$q = trim($_GET["q"] ?? "");
$state = trim($_GET["state"] ?? "");
$category = trim($_GET["category"] ?? "");
// pagination
$view = strtolower(trim($_GET["view"] ?? "list"));
if (!in_array($view, ["list", "create"], true)) $view = "list";
$displayMode = strtolower(trim($_GET["display"] ?? "list"));
if (!in_array($displayMode, ["list", "photos"], true)) $displayMode = "list";
$perPage = ($displayMode === "photos") ? 8 : 10;
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];

$stateOptions = [
    "Johor","Kedah","Kelantan","Melaka","Negeri Sembilan",
    "Pahang","Penang","Perak","Perlis","Sabah","Sarawak",
    "Selangor","Terengganu","Kuala Lumpur","Putrajaya","Labuan"
];

$allStateDistricts = [
  "Johor"           => ["Johor Bahru","Kluang","Kota Tinggi","Mersing","Muar","Batu Pahat","Pontian","Segamat","Kulai","Tangkak"],
  "Kedah"           => ["Kota Setar","Kubang Pasu","Padang Terap","Sik","Baling","Kulim","Bandar Baharu","Kuala Muda","Yan","Langkawi","Pokok Sena","Pendang"],
  "Kelantan"        => ["Kota Bharu","Bachok","Pasir Mas","Tumpat","Pasir Puteh","Machang","Tanah Merah","Kuala Krai","Gua Musang","Jeli"],
  "Melaka"          => ["Melaka Tengah","Alor Gajah","Jasin"],
  "Negeri Sembilan" => ["Seremban","Port Dickson","Rembau","Tampin","Jempol","Jelebu","Kuala Pilah"],
  "Pahang"          => ["Kuantan","Temerloh","Bentong","Cameron Highlands","Raub","Jerantut","Lipis","Maran","Bera","Rompin","Pekan"],
  "Penang"          => ["Timur Laut","Barat Daya","Seberang Perai Utara","Seberang Perai Tengah","Seberang Perai Selatan"],
  "Perak"           => ["Ipoh","Kinta","Larut, Matang & Selama","Manjung","Kerian","Hilir Perak","Hulu Perak","Batang Padang","Perak Tengah","Kampar"],
  "Perlis"          => ["Kangar","Arau","Padang Besar"],
  "Sabah"           => ["Kota Kinabalu","Sandakan","Tawau","Lahad Datu","Keningau","Semporna","Kunak","Papar","Beaufort","Kota Belud","Ranau","Kudat","Kinabatangan","Tuaran","Penampang","Putatan","Sipitang","Tambunan","Nabawan","Tongod","Beluran","Kota Marudu","Pitas","Tenom","Kuala Penyu"],
  "Sarawak"         => ["Kuching","Miri","Sibu","Bintulu","Sri Aman","Sarikei","Kapit","Limbang","Mukah","Betong","Serian","Kota Samarahan"],
  "Selangor"        => ["Petaling Jaya","Shah Alam","Klang","Subang Jaya","Gombak","Hulu Langat","Hulu Selangor","Kuala Langat","Sabak Bernam"],
  "Terengganu"      => ["Kuala Terengganu","Kemaman","Dungun","Besut","Setiu","Hulu Terengganu","Marang"],
  "Kuala Lumpur"    => ["City Centre (KLCC)","Chow Kit","Brickfields","Bangsar","Cheras","Kepong","Setapak","Wangsa Maju","Titiwangsa","Bukit Jalil","Segambut"],
  "Putrajaya"       => ["Putrajaya"],
  "Labuan"          => ["Victoria","Labuan Town"],
];

// District filter from GET
$district = trim($_GET["district"] ?? "");
$districtOptions = ($state !== "" && isset($allStateDistricts[$state])) ? $allStateDistricts[$state] : [];

$duplicatePlaces = [];
$dupDistCol = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$adminHasDistCol = ($dupDistCol && $dupDistCol->num_rows > 0);
$dupDistrictSelect = $adminHasDistCol ? "district" : "NULL AS district";
$dupRes = $conn->query("
    SELECT place_id, name, state, {$dupDistrictSelect}, category, latitude, longitude
    FROM cultural_places
    WHERE is_active = 1
    ORDER BY place_id DESC
    LIMIT 1000
");
if ($dupRes) {
    while ($dupRow = $dupRes->fetch_assoc()) $duplicatePlaces[] = $dupRow;
}

// edit mode
$editId = (int)($_GET["edit_id"] ?? 0);
$detailId = (int)($_GET["detail_id"] ?? 0);
$editRow = null;
$detailRow = null;
if ($editId > 0) {
    $view = "edit";
    $stmt = $conn->prepare("SELECT * FROM cultural_places WHERE place_id = ? LIMIT 1");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$editRow) {
        $_SESSION["form_errors"] = ["Selected place was not found."];
        header("Location: admin_cultural_kb.php");
        exit;
    }
}
if ($detailId > 0 && $editId <= 0) {
    $view = "detail";
    $stmt = $conn->prepare("SELECT * FROM cultural_places WHERE place_id = ? LIMIT 1");
    $stmt->bind_param("i", $detailId);
    $stmt->execute();
    $detailRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$detailRow) {
        $_SESSION["form_errors"] = ["Selected place was not found."];
        header("Location: admin_cultural_kb.php");
        exit;
    }
}
// list query + pagination
$showForm = ($view === "create" || $view === "edit");
$showDetail = ($view === "detail" && $detailRow);
$baseSql = " FROM cultural_places cp WHERE 1=1";
$params = [];
$types = "";

if ($q !== "") {
    $baseSql .= " AND (cp.name LIKE ? OR cp.description LIKE ? OR cp.address LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($state !== "") {
    $baseSql .= " AND cp.state = ?";
    $params[] = $state;
    $types .= "s";
}
// District filter (only when state is also selected)
if ($district !== "" && $state !== "" && in_array($district, ($allStateDistricts[$state] ?? []), true)) {
    if ($adminHasDistCol) {
        $baseSql .= " AND cp.district = ?";
        $params[] = $district;
        $types .= "s";
    }
}
if ($category !== "" && in_array($category, $categoryOptions, true)) {
    $baseSql .= " AND cp.category = ?";
    $params[] = $category;
    $types .= "s";
}

// 1) COUNT distinct places (same state + district + name counts once)
$distinctDistrictExpr = $adminHasDistCol ? "COALESCE(cp.district,'')" : "''";
$distinctGroupExpr = "cp.state, {$distinctDistrictExpr}, LOWER(TRIM(cp.name))";
$countSql = "SELECT COUNT(*) AS total FROM (SELECT 1" . $baseSql . " GROUP BY {$distinctGroupExpr}) distinct_places";
$stmtC = $conn->prepare($countSql);
if ($types !== "") $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$totalRows = (int)($stmtC->get_result()->fetch_assoc()["total"] ?? 0);
$stmtC->close();

$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// 2) LIST with LIMIT/OFFSET
$adminDistrictCol = $adminHasDistCol ? ", cp.district" : "";
$distinctIdSql = "SELECT MAX(cp.place_id) AS place_id" . $baseSql . " GROUP BY {$distinctGroupExpr}";
$sql = "SELECT cp.place_id, cp.state{$adminDistrictCol}, cp.name, cp.category, cp.estimated_cost, cp.is_active, cp.image_url, cp.updated_at, cp.created_at
    FROM cultural_places cp
    JOIN ({$distinctIdSql}) distinct_ids ON distinct_ids.place_id = cp.place_id
    ORDER BY cp.place_id DESC LIMIT ? OFFSET ?";

$params2 = $params;
$types2 = $types . "ii";
$params2[] = $perPage;
$params2[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$list = $stmt->get_result();
$placeRows = [];
if ($list) {
    while ($row = $list->fetch_assoc()) $placeRows[] = $row;
}



function resolve_img_src($imageUrl)
{
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === "") return "";

    // absolute URL
    if (preg_match('#^https?://#i', $imageUrl) || strpos($imageUrl, '//') === 0) {
        return $imageUrl;
    }

    // data URI
    if (strpos($imageUrl, 'data:image/') === 0) {
        return $imageUrl;
    }

    // local relative path saved like "uploads/places/xxx.jpg"
    $imageUrl = ltrim($imageUrl, '/');
    return "../" . $imageUrl; // admin/ -> project root
}

function local_img_exists($imageUrl)
{
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === "" || preg_match('#^https?://#i', $imageUrl) || strpos($imageUrl, '//') === 0 || strpos($imageUrl, 'data:image/') === 0) {
        return $imageUrl !== "";
    }

    $relative = ltrim($imageUrl, '/');
    return file_exists(__DIR__ . "/../" . $relative);
}

function place_img_src($row)
{
    $imageUrl = trim((string)($row["image_url"] ?? ""));
    $placeId = (int)($row["place_id"] ?? 0);
    $dynamicFallback = "../api/place_photo.php?place_id=" . $placeId . "&v=2";

    if ($imageUrl === "") {
        return $placeId > 0 ? $dynamicFallback : "";
    }

    if ($imageUrl !== "" && local_img_exists($imageUrl)) {
        return resolve_img_src($imageUrl);
    }

    return $placeId > 0 ? $dynamicFallback : "";
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>State Cultural Knowledge Base | Admin</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>State Cultural Knowledge Base</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a class="active" href="admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
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
                    <h1>State Cultural Knowledge Base</h1>
                    <p>Manage verified places used by the Smart Itinerary Generator. List, create, and edit records are separated to keep data entry clear.</p>
                </div>
                <div class="actions">
                    <?php if ($showForm): ?>
                        <a class="btn btn-ghost" href="admin_cultural_kb.php">Places List</a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="admin_cultural_kb.php?view=create">Add Place</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="../admin/admin_dashboard.php">Back</a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="card col-12" style="border-left:6px solid rgba(16,185,129,.7);">
                    <strong style="color:rgba(16,185,129,1);"><?php echo htmlspecialchars($success); ?></strong>
                </div>
                <div style="height:12px;"></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="card col-12" style="border-left:6px solid rgba(239,68,68,.7);">
                    <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
                </div>
                <div style="height:12px;"></div>
            <?php endif; ?>

            <section class="grid">
                <?php if (!$showForm && !$showDetail): ?>
                <!-- Filters -->
                <div class="card col-12">
                    <h3>Search & Filter</h3>
                    <form method="get" action="admin_cultural_kb.php" class="grid" style="gap:12px;">
                        <input type="hidden" name="display" value="<?php echo htmlspecialchars($displayMode); ?>">
                        <div class="col-6">
                            <label style="font-size:13px; font-weight:800;">Keyword</label><br>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                                placeholder="Search by name/description/address"
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;">State</label><br>
                            <select name="state" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value="">All states</option>
                                <?php foreach ($stateOptions as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($state === $s) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($s); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;">District</label><br>
                            <select name="district" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value="">All districts</option>
                                <?php foreach ($districtOptions as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($district === $d) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($d); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;">Category</label><br>
                            <select name="category" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value="">All categories</option>
                                <?php foreach ($categoryOptions as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($category === $c) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars(ucfirst($c)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12" style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit">Apply</button>
                            <a class="btn btn-ghost" href="admin_cultural_kb.php">Reset</a>
                        </div>
                    </form>
                </div>

                <?php endif; ?>

                <?php if ($showForm): ?>
                <!-- Add / Edit Form -->
                <div class="card col-12">
                    <h3><?php echo $editRow ? "Edit Place" : "Add New Place"; ?></h3>
                    <p class="meta"><?php echo $editRow ? "Update one verified place record. Duplicate detection still runs before saving." : "Create a new verified place record. Use Google Maps autocomplete where possible to fill address and coordinates."; ?></p>

                    <form method="post" action="admin_cultural_kb_process.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="<?php echo $editRow ? "update" : "create"; ?>">
                        <?php if ($editRow): ?>
                            <input type="hidden" name="place_id" value="<?php echo (int)$editRow["place_id"]; ?>">
                        <?php endif; ?>

                        <div class="grid" style="gap:12px;">
                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Name *</label><br>
                                <input type="text" name="name" id="adminPlaceNameInput" required
                                    value="<?php echo htmlspecialchars($editRow["name"] ?? ""); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">State *</label><br>
                                <select name="state" id="adminStateSelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"
                                    onchange="adminUpdateDistricts(this.value)">
                                    <option value="" disabled <?php echo empty($editRow["state"] ?? "") ? "selected" : ""; ?>>Choose a state</option>
                                    <?php foreach ($stateOptions as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo (($editRow["state"] ?? "") === $s) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($s); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">District</label><br>
                                <?php
                                $editState = $editRow["state"] ?? "";
                                $editDistrict = $editRow["district"] ?? "";
                                $editDistrictOpts = ($editState !== "" && isset($allStateDistricts[$editState])) ? $allStateDistricts[$editState] : [];
                                ?>
                                <select name="district" id="adminDistrictSelect"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="">-- Select district (optional) --</option>
                                    <?php foreach ($editDistrictOpts as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($editDistrict === $d) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($d); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Category *</label><br>
                                <select name="category" id="adminCategorySelect" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled <?php echo empty($editRow["category"] ?? "") ? "selected" : ""; ?>>Choose a category</option>
                                    <?php foreach ($categoryOptions as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo (($editRow["category"] ?? "") === $c) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars(ucfirst($c)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Festival Start Date</label><br>
                                <input type="date" name="festival_start_date" id="festivalStartDateInput"
                                    value="<?php echo htmlspecialchars($editRow["festival_start_date"] ?? ""); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Festival End Date</label><br>
                                <input type="date" name="festival_end_date" id="festivalEndDateInput"
                                    value="<?php echo htmlspecialchars($editRow["festival_end_date"] ?? ""); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;">Required only when category is Festival. Generator only suggests festivals that match the trip date.</div>
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Description</label><br>
                                <textarea name="description" id="adminDescriptionInput" rows="6"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"><?php echo htmlspecialchars($editRow["description"] ?? ""); ?></textarea>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Address</label><br>
                                <input type="text" name="address" id="adminAddressAutocomplete"
                                    value="<?php echo htmlspecialchars($editRow["address"] ?? ""); ?>"
                                    placeholder="Start typing a place or address..."
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <div class="meta" style="margin-top:6px;">Select a Google Maps suggestion to auto-fill coordinates.</div>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Latitude</label><br>
                                <input type="text" name="latitude" id="adminLatitudeInput"
                                    value="<?php echo htmlspecialchars($editRow["latitude"] ?? ""); ?>"
                                    placeholder="e.g. 1.8540000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Longitude</label><br>
                                <input type="text" name="longitude" id="adminLongitudeInput"
                                    value="<?php echo htmlspecialchars($editRow["longitude"] ?? ""); ?>"
                                    placeholder="e.g. 102.9330000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Entrance Fee (RM)</label><br>
                                <input type="number" step="0.01" min="0" name="estimated_cost"
                                    value="<?php echo htmlspecialchars($editRow["entrance_fee"] ?? $editRow["estimated_cost"] ?? "0.00"); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Visit Duration (minutes)</label><br>
                                <input type="number" step="15" min="30" max="360" name="visit_duration_min"
                                    value="<?php echo htmlspecialchars($editRow["visit_duration_min"] ?? "90"); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Halal Status</label><br>
                                <?php $halalValue = array_key_exists("halal_status", $editRow ?? []) ? (string)$editRow["halal_status"] : ""; ?>
                                <select name="halal_status"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" <?php echo $halalValue === "" ? "selected" : ""; ?>>Not applicable / unknown</option>
                                    <option value="1" <?php echo $halalValue === "1" ? "selected" : ""; ?>>Halal available</option>
                                    <option value="0" <?php echo $halalValue === "0" ? "selected" : ""; ?>>Not halal / not certified</option>
                                </select>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Opening Hours</label><br>
                                <input type="text" name="opening_hours" id="adminOpeningHoursInput"
                                    value="<?php echo htmlspecialchars($editRow["opening_hours"] ?? ""); ?>"
                                    placeholder="e.g. 9:00 AM - 4:30 PM"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <input type="hidden" name="website_url" id="adminWebsiteUrlInput" value="<?php echo htmlspecialchars($editRow["website_url"] ?? ""); ?>">
                            <input type="hidden" name="phone_number" id="adminPhoneNumberInput" value="<?php echo htmlspecialchars($editRow["phone_number"] ?? ""); ?>">
                            <input type="hidden" name="avg_rating" id="adminAvgRatingInput" value="<?php echo htmlspecialchars($editRow["avg_rating"] ?? ""); ?>">

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Place Image</label><br>

                                <?php
                                $currentImgRaw = $editRow["image_url"] ?? "";
                                $currentImg = $editRow ? place_img_src($editRow) : "";
                                ?>

                                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start; margin:8px 0 10px;">
                                    <div style="width:180px;">
                                        <div class="meta" style="margin-bottom:6px;">Preview</div>

                                        <!-- No image placeholder -->
                                        <div
                                            id="imgNoImage"
                                            style="width:180px; height:120px; border-radius:12px; border:1px dashed rgba(15,23,42,0.25);
                                            background:#f1f5f9; display:flex; align-items:center; justify-content:center;
                                            font-weight:800; color:rgba(15,23,42,0.65);
                                            <?php echo ($currentImg !== "" ? "display:none;" : ""); ?>">
                                            No image
                                        </div>

                                        <!-- Actual image -->
                                        <img
                                            id="imgPreview"
                                            src="<?php echo htmlspecialchars($currentImg); ?>"
                                            alt="Place Image"
                                            style="width:180px; height:120px; object-fit:cover; border-radius:12px;
                                            border:1px solid rgba(15,23,42,0.15); background:#f1f5f9;
                                            <?php echo ($currentImg === "" ? "display:none;" : ""); ?>">
                                    </div>


                                    <div style="flex:1; min-width:240px;">
                                        <div style="margin-bottom:10px;">
                                            <div class="meta" style="margin-bottom:6px;">Option A: Paste Image URL (https://...)</div>
                                            <input
                                                type="url"
                                                id="image_url"
                                                name="image_url"
                                                value="<?php echo htmlspecialchars((preg_match('#^https?://#i', $currentImgRaw) ? $currentImgRaw : "")); ?>"
                                                placeholder="https://example.com/photo.jpg"
                                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                        </div>

                                        <div style="margin-bottom:10px;">
                                            <div class="meta" style="margin-bottom:6px;">Option B: Upload Image (JPG/PNG/WEBP)</div>
                                            <input type="file" id="image_file" name="image" accept="image/*" style="width:100%; padding:8px;">
                                        </div>

                                        <?php if (!empty($editRow)): ?>
                                            <label style="display:flex; gap:8px; align-items:center;">
                                                <input type="checkbox" name="remove_image" value="1">
                                                Remove current image
                                            </label>
                                        <?php endif; ?>

                                        <?php if (!empty($currentImgRaw) && !preg_match('#^https?://#i', $currentImgRaw)): ?>
                                            <div class="meta" style="margin-top:8px;">
                                                Current stored path: <code><?php echo htmlspecialchars($currentImgRaw); ?></code>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="meta">Tip: If you upload a file, it will override the URL field.</div>
                            </div>

                            <script>
                                (function() {
                                    const urlInput = document.getElementById('image_url');
                                    const fileInput = document.getElementById('image_file');
                                    const preview = document.getElementById('imgPreview');
                                    const noImg = document.getElementById('imgNoImage');
                                    const removeChk = document.querySelector('input[name="remove_image"]');

                                    let objectUrl = null;

                                    function showNoImage() {
                                        if (preview) preview.style.display = 'none';
                                        if (noImg) noImg.style.display = 'flex';
                                    }

                                    function showImage(src) {
                                        if (!src) return showNoImage();
                                        if (noImg) noImg.style.display = 'none';
                                        if (preview) {
                                            preview.style.display = 'block';
                                            preview.src = src;
                                        }
                                    }

                                    // If the image fails to load, fallback to "No image"
                                    if (preview) {
                                        preview.addEventListener('error', showNoImage);
                                    }

                                    // URL typing preview
                                    if (urlInput) {
                                        urlInput.addEventListener('input', function() {
                                            const v = (urlInput.value || '').trim();

                                            // if user starts typing URL, it means they are not removing
                                            if (removeChk) removeChk.checked = false;

                                            // clear file selection if user uses URL
                                            if (fileInput) fileInput.value = '';
                                            if (objectUrl) {
                                                URL.revokeObjectURL(objectUrl);
                                                objectUrl = null;
                                            }

                                            if (v === "") return showNoImage();
                                            if (v.startsWith('http://') || v.startsWith('https://')) return showImage(v);

                                            // invalid URL -> show placeholder
                                            showNoImage();
                                        });
                                    }

                                    // File upload preview (before submit)
                                    if (fileInput) {
                                        fileInput.addEventListener('change', function() {
                                            const f = fileInput.files && fileInput.files[0];

                                            // if user selects file, it means they are not removing
                                            if (removeChk) removeChk.checked = false;

                                            // clear URL if using file
                                            if (urlInput) urlInput.value = '';

                                            if (objectUrl) {
                                                URL.revokeObjectURL(objectUrl);
                                                objectUrl = null;
                                            }

                                            if (!f) return showNoImage();

                                            objectUrl = URL.createObjectURL(f);
                                            showImage(objectUrl);
                                        });
                                    }

                                    // If "remove image" checked, immediately show No image preview
                                    if (removeChk) {
                                        removeChk.addEventListener('change', function() {
                                            if (!removeChk.checked) return;

                                            if (urlInput) urlInput.value = '';
                                            if (fileInput) fileInput.value = '';
                                            if (objectUrl) {
                                                URL.revokeObjectURL(objectUrl);
                                                objectUrl = null;
                                            }

                                            showNoImage();
                                        });
                                    }
                                })();
                            </script>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Active</label><br>
                                <select name="is_active"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="1" <?php echo ((int)($editRow["is_active"] ?? 1) === 1) ? "selected" : ""; ?>>Yes</option>
                                    <option value="0" <?php echo ((int)($editRow["is_active"] ?? 1) === 0) ? "selected" : ""; ?>>No</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit"><?php echo $editRow ? "Update Place" : "Add Place"; ?></button>
                            <a class="btn btn-ghost" href="admin_cultural_kb.php"><?php echo $editRow ? "Cancel Edit" : "Cancel"; ?></a>
                        </div>
                    </form>
                </div>
                <?php if (!$editRow): ?>
                <div class="card col-12">
                    <h3>CSV Bulk Import</h3>
                    <p class="meta">Upload CSV columns: name, state, district, category, description, address, latitude, longitude, estimated_cost, opening_hours, image_url, is_active, visit_duration_min, festival_start_date, festival_end_date. Festival rows require both date fields.</p>
                    <form method="post" action="admin_cultural_kb_process.php" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
                        <input type="hidden" name="action" value="import_csv">
                        <div style="flex:1; min-width:260px;">
                            <label style="font-size:13px; font-weight:800;">CSV File</label><br>
                            <input type="file" name="csv_file" accept=".csv,text/csv" required style="width:100%; padding:8px;">
                        </div>
                        <button class="btn btn-primary" type="submit">Import CSV</button>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($showDetail): ?>
                <div class="card col-12">
                    <?php $detailImg = place_img_src($detailRow); ?>
                    <div style="display:grid; grid-template-columns:minmax(260px, 380px) minmax(0, 1fr); gap:18px; align-items:start;">
                        <div>
                            <?php if ($detailImg !== ""): ?>
                                <img src="<?php echo htmlspecialchars($detailImg); ?>" alt="<?php echo htmlspecialchars($detailRow["name"]); ?>" style="width:100%; aspect-ratio:4/3; object-fit:cover; border-radius:14px; border:1px solid rgba(15,23,42,0.10); background:#f1f5f9;" loading="lazy">
                            <?php else: ?>
                                <div style="width:100%; aspect-ratio:4/3; display:flex; align-items:center; justify-content:center; border-radius:14px; background:#f1f5f9; color:#64748b; font-weight:800;">No image</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:start;">
                                <div>
                                    <h3 style="margin-bottom:6px;"><?php echo htmlspecialchars($detailRow["name"]); ?></h3>
                                    <p class="meta">
                                        <?php echo htmlspecialchars($detailRow["state"]); ?>
                                        <?php if (!empty($detailRow["district"])): ?> · <?php echo htmlspecialchars($detailRow["district"]); ?><?php endif; ?>
                                        · <?php echo htmlspecialchars(ucfirst($detailRow["category"])); ?>
                                    </p>
                                </div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="btn btn-ghost" href="admin_cultural_kb.php">Back to List</a>
                                    <a class="btn btn-primary" href="admin_cultural_kb.php?edit_id=<?php echo (int)$detailRow["place_id"]; ?>">Edit</a>
                                </div>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin:14px 0;">
                                <div class="chip">RM <?php echo number_format((float)($detailRow["entrance_fee"] ?? $detailRow["estimated_cost"] ?? 0), 2); ?></div>
                                <div class="chip"><?php echo ((int)($detailRow["is_active"] ?? 0) === 1) ? "Active" : "Inactive"; ?></div>
                                <div class="chip"><?php echo (int)($detailRow["visit_duration_min"] ?? 90); ?> min visit</div>
                                <?php if (!empty($detailRow["best_time_to_visit"])): ?><div class="chip"><?php echo htmlspecialchars($detailRow["best_time_to_visit"]); ?></div><?php endif; ?>
                            </div>
                            <?php if (!empty($detailRow["description"])): ?>
                                <p style="line-height:1.6; color:#334155;"><?php echo nl2br(htmlspecialchars($detailRow["description"])); ?></p>
                            <?php endif; ?>
                            <div style="display:grid; gap:8px; margin-top:14px; color:#475569; font-size:13px;">
                                <?php if (!empty($detailRow["address"])): ?><div><strong>Address:</strong> <?php echo htmlspecialchars($detailRow["address"]); ?></div><?php endif; ?>
                                <?php if (!empty($detailRow["opening_hours"])): ?><div><strong>Opening Hours:</strong> <?php echo htmlspecialchars($detailRow["opening_hours"]); ?></div><?php endif; ?>
                                <?php if (!empty($detailRow["latitude"]) && !empty($detailRow["longitude"])): ?><div><strong>Coordinates:</strong> <?php echo htmlspecialchars($detailRow["latitude"]); ?>, <?php echo htmlspecialchars($detailRow["longitude"]); ?></div><?php endif; ?>
                                <?php if (!empty($detailRow["website_url"])): ?><div><strong>Website:</strong> <a href="<?php echo htmlspecialchars($detailRow["website_url"]); ?>" target="_blank" rel="noopener">Open official/source link</a></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$showForm && !$showDetail): ?>
                <!-- Table -->
                <div class="card col-12">
                    <h3>Places List</h3>
                    <p class="meta">Showing <?php echo (int)$perPage; ?> distinct records per page by state, district, and place name. Exact duplicate names are hidden from this list; keep the newest record and clean duplicates when found.</p>

                    <?php
                    $displayQs = $_GET;
                    unset($displayQs["page"]);
                    $displayQs["display"] = "list";
                    $listUrl = "admin_cultural_kb.php?" . http_build_query($displayQs);
                    $displayQs["display"] = "photos";
                    $photosUrl = "admin_cultural_kb.php?" . http_build_query($displayQs);
                    ?>
                    <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin:12px 0;">
                        <div class="meta">Display mode</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <a class="btn <?php echo $displayMode === "list" ? "btn-primary" : "btn-ghost"; ?>" href="<?php echo htmlspecialchars($listUrl); ?>">List</a>
                            <a class="btn <?php echo $displayMode === "photos" ? "btn-primary" : "btn-ghost"; ?>" href="<?php echo htmlspecialchars($photosUrl); ?>">Photo Grid</a>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <?php if ($displayMode === "photos"): ?>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:14px;">
                                <?php foreach ($placeRows as $r): ?>
                                    <?php $thumb = place_img_src($r); ?>
                                    <div style="border:1px solid rgba(15,23,42,0.10); border-radius:14px; overflow:hidden; background:#fff;">
                                        <?php if ($thumb !== ""): ?>
                                            <img
                                                src="<?php echo htmlspecialchars($thumb); ?>"
                                                alt="<?php echo htmlspecialchars($r["name"]); ?>"
                                                style="width:100%; height:150px; object-fit:cover; display:block; background:#f1f5f9;"
                                                onerror="this.onerror=null; this.outerHTML='<div style=&quot;height:150px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; color:#64748b; font-weight:800;&quot;>No image</div>';"
                                                loading="lazy">
                                        <?php else: ?>
                                            <div style="height:150px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; color:#64748b; font-weight:800;">No image</div>
                                        <?php endif; ?>

                                        <div style="padding:12px;">
                                            <div style="font-weight:900; line-height:1.25;"><?php echo htmlspecialchars($r["name"]); ?></div>
                                            <div class="meta" style="margin-top:6px;">
                                                <?php echo htmlspecialchars($r["state"]); ?>
                                                <?php if (!empty($r["district"])): ?>
                                                    · <?php echo htmlspecialchars($r["district"]); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                                                <span class="chip"><?php echo htmlspecialchars(ucfirst($r["category"])); ?></span>
                                                <span class="chip">RM <?php echo number_format((float)$r["estimated_cost"], 2); ?></span>
                                                <span class="chip"><?php echo ((int)$r["is_active"] === 1) ? "Active" : "Inactive"; ?></span>
                                            </div>
                                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
                                                <a class="btn btn-ghost" href="admin_cultural_kb.php?detail_id=<?php echo (int)$r["place_id"]; ?>">View Details</a>
                                                <a class="btn btn-ghost" href="admin_cultural_kb.php?edit_id=<?php echo (int)$r["place_id"]; ?>">Edit</a>
                                                <a class="btn btn-ghost" href="admin_cultural_kb_process.php?action=delete&place_id=<?php echo (int)$r["place_id"]; ?>"
                                                    onclick="return confirm('Delete this place?');">Delete</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($placeRows) === 0): ?>
                                <div style="padding:18px; color:#64748b;">No records found.</div>
                            <?php endif; ?>
                        <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>State</th>
                                    <th>Image</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Cost (RM)</th>
                                    <th>Active</th>
                                    <th>Updated</th>
                                    <th style="width:260px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($placeRows as $r): ?>
                                    <tr>
                                        <td><?php echo (int)$r["place_id"]; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($r["state"]); ?>
                                            <?php if (!empty($r["district"])): ?>
                                            <div style="font-size:11px; color:#4338ca; font-weight:700;"><?php echo htmlspecialchars($r["district"]); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $thumb = place_img_src($r);
                                            ?>
                                            <?php if ($thumb === ""): ?>
                                                <span style="opacity:.55;">No image</span>
                                            <?php else: ?>
                                                <img
                                                    src="<?php echo htmlspecialchars($thumb); ?>"
                                                    alt="thumb"
                                                    style="width:56px; height:40px; object-fit:cover; border-radius:10px; border:1px solid rgba(15,23,42,0.12); background:#f1f5f9;"
                                                    onerror="this.onerror=null; this.replaceWith(document.createTextNode('-'));"
                                                    loading="lazy">
                                            <?php endif; ?>
                                        </td>

                                        <td><strong><?php echo htmlspecialchars($r["name"]); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r["category"]); ?></td>
                                        <td><?php echo number_format((float)$r["estimated_cost"], 2); ?></td>
                                        <td><?php echo ((int)$r["is_active"] === 1) ? "Yes" : "No"; ?></td>
                                        <td><?php echo htmlspecialchars($r["updated_at"] ?? $r["created_at"]); ?></td>
                                        <td>
                                            <a class="btn btn-ghost" href="admin_cultural_kb.php?detail_id=<?php echo (int)$r["place_id"]; ?>">View Details</a>
                                            <a class="btn btn-ghost" href="admin_cultural_kb.php?edit_id=<?php echo (int)$r["place_id"]; ?>">Edit</a>
                                            <a class="btn btn-ghost" href="admin_cultural_kb_process.php?action=delete&place_id=<?php echo (int)$r["place_id"]; ?>"
                                                onclick="return confirm('Delete this place?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($placeRows) === 0): ?>
                                    <tr>
                                        <td colspan="9">No records found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                        <?php if ($totalPages > 1): ?>
                            <div style="display:flex; gap:10px; align-items:center; justify-content:center; margin-top:12px; flex-wrap:wrap; width:100%;">
                                <?php
                                $qs = $_GET;
                                unset($qs["page"]);
                                ?>

                                <?php if ($page > 1): ?>
                                    <?php $qs["page"] = $page - 1; ?>
                                    <a class="btn btn-ghost" href="admin_cultural_kb.php?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Prev</a>
                                <?php else: ?>
                                    <span class="btn btn-ghost" style="pointer-events:none; opacity:.5;">Prev</span>
                                <?php endif; ?>

                                <span style="font-weight:800;">Page <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?></span>

                                <?php if ($page < $totalPages): ?>
                                    <?php $qs["page"] = $page + 1; ?>
                                    <a class="btn btn-ghost" href="admin_cultural_kb.php?<?php echo htmlspecialchars(http_build_query($qs)); ?>">Next</a>
                                <?php else: ?>
                                    <span class="btn btn-ghost" style="pointer-events:none; opacity:.5;">Next</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
<script>
var adminDistrictsMap = <?php echo json_encode($allStateDistricts); ?>;
var existingAdminPlaces = <?php echo json_encode($duplicatePlaces, JSON_UNESCAPED_UNICODE); ?>;

function adminUpdateDistricts(selectedState) {
    var distSel = document.getElementById('adminDistrictSelect');
    if (!distSel) return;
    distSel.innerHTML = '<option value="">-- Select district (optional) --</option>';
    if (selectedState && adminDistrictsMap[selectedState]) {
        adminDistrictsMap[selectedState].forEach(function(d) {
            var opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            distSel.appendChild(opt);
        });
    }
}

function normalizeMalaysiaState(rawState) {
    var s = (rawState || '').toLowerCase();
    if (s.indexOf('kuala lumpur') !== -1) return 'Kuala Lumpur';
    if (s.indexOf('putrajaya') !== -1) return 'Putrajaya';
    if (s.indexOf('labuan') !== -1) return 'Labuan';
    if (s.indexOf('penang') !== -1 || s.indexOf('pulau pinang') !== -1) return 'Penang';
    if (s.indexOf('malacca') !== -1) return 'Melaka';
    var known = <?php echo json_encode($stateOptions); ?>;
    for (var i = 0; i < known.length; i++) {
        if (s.indexOf(known[i].toLowerCase()) !== -1) return known[i];
    }
    return '';
}

function componentLongName(place, type) {
    if (!place.address_components) return '';
    for (var i = 0; i < place.address_components.length; i++) {
        var c = place.address_components[i];
        if (c.types && c.types.indexOf(type) !== -1) return c.long_name || '';
    }
    return '';
}

function formatOpeningHours(place) {
    if (!place.opening_hours) return '';
    if (place.opening_hours.weekday_text && place.opening_hours.weekday_text.length) {
        return place.opening_hours.weekday_text.join('; ');
    }
    return '';
}

function generateStarterDescription(place, state, district) {
    var name = place.name || 'This place';
    var type = '';
    if (place.types && place.types.length) {
        type = place.types
            .filter(function(t) { return ['point_of_interest', 'establishment', 'tourist_attraction'].indexOf(t) === -1; })
            .slice(0, 2)
            .map(function(t) { return t.replace(/_/g, ' '); })
            .join(' and ');
    }
    var location = [district, state].filter(Boolean).join(', ');
    var sentence = name + (location ? ' is located in ' + location : ' is a Malaysian place of interest') + '.';
    if (type) sentence += ' It is listed on Google Maps as ' + type + '.';
    sentence += ' Add verified cultural background, visitor etiquette, and local significance before publishing.';
    return sentence;
}

function initPlaceAutocomplete() {
    var addressInput = document.getElementById('adminAddressAutocomplete');
    if (!addressInput || !window.google || !google.maps || !google.maps.places) return;

    var autocomplete = new google.maps.places.Autocomplete(addressInput, {
        componentRestrictions: { country: 'my' },
        fields: [
            'name', 'formatted_address', 'geometry', 'address_components',
            'opening_hours', 'photos', 'formatted_phone_number', 'website',
            'rating', 'editorial_summary', 'types'
        ],
    });

    autocomplete.addListener('place_changed', function() {
        var place = autocomplete.getPlace();
        if (!place || !place.geometry || !place.geometry.location) return;

        var nameInput = document.getElementById('adminPlaceNameInput');
        var latInput = document.getElementById('adminLatitudeInput');
        var lngInput = document.getElementById('adminLongitudeInput');
        var stateSelect = document.getElementById('adminStateSelect');
        var districtSelect = document.getElementById('adminDistrictSelect');
        var openingInput = document.getElementById('adminOpeningHoursInput');
        var descriptionInput = document.getElementById('adminDescriptionInput');
        var imageInput = document.getElementById('image_url');
        var websiteInput = document.getElementById('adminWebsiteUrlInput');
        var phoneInput = document.getElementById('adminPhoneNumberInput');
        var ratingInput = document.getElementById('adminAvgRatingInput');

        addressInput.value = place.formatted_address || addressInput.value;
        if (nameInput && !nameInput.value.trim() && place.name) nameInput.value = place.name;
        if (latInput) latInput.value = place.geometry.location.lat().toFixed(7);
        if (lngInput) lngInput.value = place.geometry.location.lng().toFixed(7);

        var state = normalizeMalaysiaState(componentLongName(place, 'administrative_area_level_1'));
        if (stateSelect && state) {
            stateSelect.value = state;
            adminUpdateDistricts(state);
        }

        var district = componentLongName(place, 'locality') ||
            componentLongName(place, 'administrative_area_level_2') ||
            componentLongName(place, 'sublocality');
        if (districtSelect && district) {
            for (var i = 0; i < districtSelect.options.length; i++) {
                if (districtSelect.options[i].value.toLowerCase() === district.toLowerCase()) {
                    districtSelect.value = districtSelect.options[i].value;
                    break;
                }
            }
        }

        var openingText = formatOpeningHours(place);
        if (openingInput && openingText) openingInput.value = openingText;
        if (websiteInput && place.website) websiteInput.value = place.website;
        if (phoneInput && place.formatted_phone_number) phoneInput.value = place.formatted_phone_number;
        if (ratingInput && place.rating) ratingInput.value = Number(place.rating).toFixed(2);

        if (imageInput && place.photos && place.photos.length) {
            imageInput.value = place.photos[0].getUrl({ maxWidth: 1200, maxHeight: 900 });
            imageInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (descriptionInput && !descriptionInput.value.trim()) {
            if (place.editorial_summary && place.editorial_summary.overview) {
                descriptionInput.value = place.editorial_summary.overview;
            } else {
                descriptionInput.value = generateStarterDescription(place, state, district);
            }
        }
    });
}

var adminCategorySelect = document.getElementById('adminCategorySelect');
function toggleFestivalDateRequirement() {
    var category = document.getElementById('adminCategorySelect');
    var start = document.getElementById('festivalStartDateInput');
    var end = document.getElementById('festivalEndDateInput');
    if (!category || !start || !end) return;
    var isFestival = category.value === 'festival';
    start.required = isFestival;
    end.required = isFestival;
}
if (adminCategorySelect) adminCategorySelect.addEventListener('change', toggleFestivalDateRequirement);
toggleFestivalDateRequirement();
</script>
<?php if (defined("GOOGLE_MAPS_API_KEY") && trim(GOOGLE_MAPS_API_KEY) !== ""): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY); ?>&libraries=places&callback=initPlaceAutocomplete" async defer></script>
<?php endif; ?>
</body>
</html>
