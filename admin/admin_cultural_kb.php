<?php
// admin/admin_cultural_kb.php
session_start();
require_once "../config/db_connect.php";

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

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];

$stateOptions = [
    "Johor",
    "Kedah",
    "Kelantan",
    "Melaka",
    "Negeri Sembilan",
    "Pahang",
    "Penang",
    "Perak",
    "Perlis",
    "Sabah",
    "Sarawak",
    "Selangor",
    "Terengganu",
    "Kuala Lumpur",
    "Putrajaya",
    "Labuan"
];

// edit mode
$editId = (int)($_GET["edit_id"] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM cultural_places WHERE place_id = ? LIMIT 1");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// list query
$sql = "SELECT place_id, state, name, category, estimated_cost, is_active, updated_at, created_at
        FROM cultural_places WHERE 1=1";
$params = [];
$types = "";

if ($q !== "") {
    $sql .= " AND (name LIKE ? OR description LIKE ? OR address LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($state !== "") {
    $sql .= " AND state = ?";
    $params[] = $state;
    $types .= "s";
}
if ($category !== "" && in_array($category, $categoryOptions, true)) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}
$sql .= " ORDER BY place_id DESC";

$stmt = $conn->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$list = $stmt->get_result();
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
                    <span>Admin Dashboard</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a class="active" href="admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
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
                    <h1>State Cultural Knowledge Base</h1>
                    <p>Manage verified cultural places by state. These records will be used by Smart Itinerary Generator.</p>
                </div>
                <div class="actions">
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
                <!-- Filters -->
                <div class="card col-12">
                    <h3>Search & Filter</h3>
                    <form method="get" action="admin_cultural_kb.php" class="grid" style="gap:12px;">
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

                <!-- Add / Edit Form -->
                <div class="card col-12">
                    <h3><?php echo $editRow ? "Edit Place" : "Add New Place"; ?></h3>

                    <form method="post" action="admin_cultural_kb_process.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="<?php echo $editRow ? "update" : "create"; ?>">
                        <?php if ($editRow): ?>
                            <input type="hidden" name="place_id" value="<?php echo (int)$editRow["place_id"]; ?>">
                        <?php endif; ?>

                        <div class="grid" style="gap:12px;">
                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Name *</label><br>
                                <input type="text" name="name" required
                                    value="<?php echo htmlspecialchars($editRow["name"] ?? ""); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">State *</label><br>
                                <select name="state" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled <?php echo empty($editRow["state"] ?? "") ? "selected" : ""; ?>>Choose a state</option>
                                    <?php foreach ($stateOptions as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo (($editRow["state"] ?? "") === $s) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars($s); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Category *</label><br>
                                <select name="category" required
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                    <option value="" disabled <?php echo empty($editRow["category"] ?? "") ? "selected" : ""; ?>>Choose a category</option>
                                    <?php foreach ($categoryOptions as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo (($editRow["category"] ?? "") === $c) ? "selected" : ""; ?>>
                                            <?php echo htmlspecialchars(ucfirst($c)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Description</label><br>
                                <textarea name="description" rows="3"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"><?php echo htmlspecialchars($editRow["description"] ?? ""); ?></textarea>
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Address</label><br>
                                <input type="text" name="address"
                                    value="<?php echo htmlspecialchars($editRow["address"] ?? ""); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Latitude</label><br>
                                <input type="text" name="latitude"
                                    value="<?php echo htmlspecialchars($editRow["latitude"] ?? ""); ?>"
                                    placeholder="e.g. 1.8540000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Longitude</label><br>
                                <input type="text" name="longitude"
                                    value="<?php echo htmlspecialchars($editRow["longitude"] ?? ""); ?>"
                                    placeholder="e.g. 102.9330000"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-3">
                                <label style="font-size:13px; font-weight:800;">Estimated Cost (RM)</label><br>
                                <input type="number" step="0.01" min="0" name="estimated_cost"
                                    value="<?php echo htmlspecialchars($editRow["estimated_cost"] ?? "0.00"); ?>"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-6">
                                <label style="font-size:13px; font-weight:800;">Opening Hours</label><br>
                                <input type="text" name="opening_hours"
                                    value="<?php echo htmlspecialchars($editRow["opening_hours"] ?? ""); ?>"
                                    placeholder="e.g. 9:00 AM - 4:30 PM"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div class="col-12">
                                <label style="font-size:13px; font-weight:800;">Place Image</label><br>

                                <?php if (!empty($editRow["image_url"])): ?>
                                    <div style="margin-bottom:8px;">
                                        <img src="<?php echo htmlspecialchars($editRow["image_url"]); ?>"
                                            alt="Place Image"
                                            style="max-height:120px; border-radius:12px; border:1px solid rgba(15,23,42,0.15);">
                                    </div>
                                <?php endif; ?>

                                <input type="file" name="image"
                                    accept="image/*"
                                    style="width:100%; padding:8px;">
                                <div class="meta">Upload JPG / PNG image (optional)</div>
                            </div>


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
                            <?php if ($editRow): ?>
                                <a class="btn btn-ghost" href="admin_cultural_kb.php">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="card col-12">
                    <h3>Places List</h3>
                    <p class="meta">Tip: Add latitude/longitude now, later Module 4 (Maps) can draw markers and routes.</p>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>State</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Cost (RM)</th>
                                    <th>Active</th>
                                    <th>Updated</th>
                                    <th style="width:180px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $list->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo (int)$r["place_id"]; ?></td>
                                        <td><?php echo htmlspecialchars($r["state"]); ?></td>
                                        <td><strong><?php echo htmlspecialchars($r["name"]); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r["category"]); ?></td>
                                        <td><?php echo number_format((float)$r["estimated_cost"], 2); ?></td>
                                        <td><?php echo ((int)$r["is_active"] === 1) ? "Yes" : "No"; ?></td>
                                        <td><?php echo htmlspecialchars($r["updated_at"] ?? $r["created_at"]); ?></td>
                                        <td>
                                            <a class="btn btn-ghost" href="admin_cultural_kb.php?edit_id=<?php echo (int)$r["place_id"]; ?>">Edit</a>
                                            <a class="btn btn-ghost" href="admin_cultural_kb_process.php?action=delete&place_id=<?php echo (int)$r["place_id"]; ?>"
                                                onclick="return confirm('Delete this place?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($list->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="8">No records found.</td>
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