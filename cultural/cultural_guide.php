<?php
// cultural/cultural_guide.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";

// CHANGE: Traveller cultural guide supports search/filter and shows images from cultural_places.image_url
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

$sql = "SELECT place_id, state, category, name, description, address, opening_hours, estimated_cost, image_url
        FROM cultural_places
        WHERE is_active = 1";
$params = [];
$types = "";

if ($q !== "") {
    $sql .= " AND (name LIKE ? OR description LIKE ? OR address LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($state !== "" && in_array($state, $stateOptions, true)) {
    $sql .= " AND state = ?";
    $params[] = $state;
    $types .= "s";
}
if ($category !== "" && in_array($category, $categoryOptions, true)) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

$sql .= " ORDER BY updated_at DESC, place_id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL prepare failed: " . htmlspecialchars($conn->error));
}
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$places = [];
while ($row = $res->fetch_assoc()) $places[] = $row;
$stmt->close();

function safe_img_src($imageUrl)
{
    // CHANGE: normalize stored path to browser path
    // If DB stores: uploads/places/xxx.jpg -> from /cultural page use ../uploads/places/xxx.jpg
    if (!$imageUrl) return "";
    $imageUrl = ltrim($imageUrl, "/");
    return "../" . $imageUrl;
}

function excerpt($text, $len = 120)
{
    $t = trim((string)$text);
    if ($t === "") return "";
    if (mb_strlen($t) <= $len) return $t;
    return mb_substr($t, 0, $len) . "...";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Cultural Guide Presentation | Traveller</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        /* minimal local styles (optional) */
        .place-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        @media(max-width:1100px) {
            .place-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width:700px) {
            .place-grid {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }
        }

        .place-card {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
        }

        .place-thumb {
            height: 160px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .place-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .place-body {
            padding: 12px 12px 14px;
        }

        .badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .badge {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(10, 26, 79, 0.06);
            color: var(--navy);
            font-weight: 800;
        }

        .meta2 {
            color: var(--muted);
            font-size: 12px;
        }

        .place-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Cultural Guide Presentation</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../dashboard/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                <a href="../itinerary/generate_itinerary.php"><span class="dot"></span> Smart Itinerary Generator</a>
                <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
                <a class="active" href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
                <a href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
                <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
            </nav>

            <div class="sidebar-footer">
                <div class="small">Logged in as:</div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                <div class="chip">Role: Traveller</div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1>Cultural Guide Presentation</h1>
                    <p>Explore verified cultural places with background information and local context.</p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <!-- CHANGE: add suggestion entry (submodule) -->
                    <a class="btn btn-primary" href="suggest_place.php">Suggest New Place</a>
                    <a class="btn btn-ghost" href="../dashboard/traveller_dashboard.php">Back</a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <h3>Search & Filter</h3>
                    <form method="get" class="grid" style="gap:12px;">
                        <div class="col-6">
                            <label style="font-size:13px; font-weight:800;">Keyword</label><br>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                                placeholder="Search by name / description / address"
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
                            <a class="btn btn-ghost" href="cultural_guide.php">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="card col-12">
                    <h3>Verified Places</h3>
                    <p class="meta">Showing up to 200 active places.</p>

                    <?php if (count($places) === 0): ?>
                        <div style="padding:12px; color:var(--muted);">No places found with the current filters.</div>
                    <?php else: ?>
                        <div class="place-grid">
                            <?php foreach ($places as $p): ?>
                                <?php
                                $img = safe_img_src($p["image_url"] ?? "");
                                ?>
                                <div class="place-card">
                                    <div class="place-thumb">
                                        <?php if ($img !== ""): ?>
                                            <img src="<?php echo htmlspecialchars($img); ?>" alt="Place Image">
                                        <?php else: ?>
                                            <span class="meta2">No image</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="place-body">
                                        <div class="badges">
                                            <span class="badge"><?php echo htmlspecialchars($p["state"]); ?></span>
                                            <span class="badge"><?php echo htmlspecialchars(ucfirst($p["category"])); ?></span>
                                            <span class="badge">RM <?php echo number_format((float)($p["estimated_cost"] ?? 0), 2); ?></span>
                                        </div>

                                        <div style="font-weight:900; color:var(--navy);">
                                            <?php echo htmlspecialchars($p["name"]); ?>
                                        </div>
                                        <div class="meta2" style="margin-top:6px;">
                                            <?php echo htmlspecialchars(excerpt($p["description"] ?? "")); ?>
                                        </div>

                                        <div class="place-actions">
                                            <a class="btn btn-ghost" href="cultural_guide_detail.php?place_id=<?php echo (int)$p["place_id"]; ?>">View Details</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>

</html>