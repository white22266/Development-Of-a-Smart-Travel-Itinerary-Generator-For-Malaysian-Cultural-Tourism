<?php
// cultural/cultural_guide_detail.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$placeId = (int)($_GET["place_id"] ?? 0);
if ($placeId <= 0) {
    header("Location: cultural_guide.php");
    exit;
}

$stmt = $conn->prepare("
  SELECT place_id, state, category, name, description, address, latitude, longitude,
         opening_hours, estimated_cost, image_url
  FROM cultural_places
  WHERE place_id = ? AND is_active = 1
  LIMIT 1
");
$stmt->bind_param("i", $placeId);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$p) {
    header("Location: cultural_guide.php");
    exit;
}

function img_src($imageUrl)
{
    if (!$imageUrl) return "";
    $imageUrl = ltrim($imageUrl, "/");
    return "../" . $imageUrl;
}

$img = img_src($p["image_url"] ?? "");

$mapLink = "";
if (!empty($p["latitude"]) && !empty($p["longitude"])) {
    $mapLink = "https://www.google.com/maps?q=" . urlencode($p["latitude"] . "," . $p["longitude"]);
} elseif (!empty($p["address"])) {
    $mapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode($p["address"]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Place Details | Cultural Guide</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .detail-wrap {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px;
        }

        @media(max-width:900px) {
            .detail-wrap {
                grid-template-columns: 1fr;
            }
        }

        .hero {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.10);
            background: #fff;
        }

        .hero img {
            width: 100%;
            height: 320px;
            object-fit: cover;
            display: block;
        }

        .hero .noimg {
            height: 320px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: var(--muted);
        }

        .info {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.10);
            background: #fff;
            padding: 14px;
        }

        .row {
            margin: 8px 0;
            color: var(--navy);
        }

        .k {
            font-weight: 900;
            display: inline-block;
            min-width: 140px;
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
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
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
                    <h1><?php echo htmlspecialchars($p["name"]); ?></h1>
                    <p>Background information and cultural context.</p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn btn-ghost" href="cultural_guide.php">Back to List</a>
                    <a class="btn btn-primary" href="suggest_place.php">Suggest New Place</a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <div class="detail-wrap">
                        <div class="hero">
                            <?php if ($img !== ""): ?>
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="Place Image">
                            <?php else: ?>
                                <div class="noimg">No image</div>
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="row"><span class="k">State:</span> <?php echo htmlspecialchars($p["state"]); ?></div>
                            <div class="row"><span class="k">Category:</span> <?php echo htmlspecialchars(ucfirst($p["category"])); ?></div>
                            <div class="row"><span class="k">Estimated Cost:</span> RM <?php echo number_format((float)($p["estimated_cost"] ?? 0), 2); ?></div>
                            <div class="row"><span class="k">Opening Hours:</span> <?php echo htmlspecialchars($p["opening_hours"] ?? "-"); ?></div>
                            <div class="row"><span class="k">Address:</span> <?php echo htmlspecialchars($p["address"] ?? "-"); ?></div>
                            <div class="row"><span class="k">Coordinates:</span>
                                <?php
                                $lat = $p["latitude"] ?? "";
                                $lng = $p["longitude"] ?? "";
                                echo ($lat && $lng) ? htmlspecialchars($lat . ", " . $lng) : "-";
                                ?>
                            </div>

                            <?php if ($mapLink !== ""): ?>
                                <div style="margin-top:12px;">
                                    <a class="btn btn-ghost" target="_blank" rel="noopener noreferrer" href="<?php echo htmlspecialchars($mapLink); ?>">
                                        Open in Google Maps
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr class="sep">

                    <h3>Cultural Background</h3>

                    <div style="color:var(--navy); line-height:1.7;">
                        <?php echo nl2br(htmlspecialchars($p["description"] ?? "No description provided.")); ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>

</html>