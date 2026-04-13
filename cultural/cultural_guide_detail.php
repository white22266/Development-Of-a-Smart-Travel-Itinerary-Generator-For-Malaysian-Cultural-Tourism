<?php
// cultural/cultural_guide_detail.php
// Enhanced: Added nearby food recommendations + nearby hotel recommendations sections
session_start();
require_once "../config/db_connect.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/FoodRecommendationService.php";

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

// Check if district column exists
$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtSelectCol = $hasDistCol ? ", district" : "";

$stmt = $conn->prepare("
    SELECT place_id, state{$districtSelectCol}, category, name, description, address, latitude, longitude,
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

// ---- Nearby food & hotel recommendations ----
$lat = (float)($p["latitude"] ?? 0);
$lng = (float)($p["longitude"] ?? 0);
$hasCoords = ($lat !== 0.0 && $lng !== 0.0);

$nearbyFood   = [];
$nearbyHotels = [];

if ($hasCoords) {
    $foodService  = new FoodRecommendationService($conn);
    $hotelService = new HotelRecommendationService($conn);

    $nearbyFood   = $foodService->recommend($lat, $lng, 0, null, '', 5.0, 5);
    $nearbyHotels = $hotelService->recommend($lat, $lng, 0, 20.0, 5);
}

// Fallback by state
if (empty($nearbyFood) && !empty($p["state"])) {
    $foodService = new FoodRecommendationService($conn);
    $nearbyFood  = $foodService->recommendByState($p["state"], 0, 5);
}
if (empty($nearbyHotels) && !empty($p["state"])) {
    $hotelService = new HotelRecommendationService($conn);
    $nearbyHotels = $hotelService->recommendByState($p["state"], 0, 5);
}

// ---- Image helper ----
function img_src($imageUrl)
{
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === "") return "";
    if (preg_match('#^https?://#i', $imageUrl) || strpos($imageUrl, '//') === 0) return $imageUrl;
    if (strpos($imageUrl, 'data:image/') === 0) return $imageUrl;
    $imageUrl = ltrim($imageUrl, '/');
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

        /* Nearby cards */
        .nearby-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .nearby-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.10);
            background: #fff;
            padding: 14px;
        }

        .nearby-card .nc-name {
            font-weight: 800;
            color: var(--navy);
            font-size: 14px;
            margin-bottom: 4px;
        }

        .nearby-card .nc-meta {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }

        .nearby-card .nc-price {
            font-weight: 900;
            color: var(--navy);
            font-size: 13px;
            margin-top: 6px;
        }

        .stars {
            color: #f59e0b;
            font-size: 12px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 900;
            color: var(--navy);
            margin: 20px 0 4px;
            padding-bottom: 6px;
            border-bottom: 2px solid rgba(15, 23, 42, 0.08);
        }

        .cuisine-chip {
            display: inline-block;
            background: rgba(15, 23, 42, 0.07);
            color: var(--navy);
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
            margin-top: 4px;
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
                <!-- ===== PLACE DETAIL CARD ===== -->
                <div class="card col-12">
                    <div class="detail-wrap">
                        <div class="hero">
                            <?php if ($img !== ""): ?>
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="Place Image">
                            <?php else: ?>
                                <div class="noimg">No image available</div>
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="row"><span class="k">State:</span> <?php echo htmlspecialchars($p["state"]); ?></div>
                            <?php if (!empty($p["district"])): ?>
                            <div class="row"><span class="k">District:</span>
                                <span style="background:rgba(99,102,241,.10); color:#4338ca; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:800;">
                                    <?php echo htmlspecialchars($p["district"]); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="row"><span class="k">Category:</span> <?php echo htmlspecialchars(ucfirst($p["category"])); ?></div>
                            <div class="row"><span class="k">Estimated Cost:</span> RM <?php echo number_format((float)($p["estimated_cost"] ?? 0), 2); ?></div>
                            <div class="row"><span class="k">Opening Hours:</span> <?php echo htmlspecialchars($p["opening_hours"] ?? "-"); ?></div>
                            <div class="row"><span class="k">Address:</span> <?php echo htmlspecialchars($p["address"] ?? "-"); ?></div>
                            <div class="row"><span class="k">Coordinates:</span>
                                <?php
                                $plat = $p["latitude"] ?? "";
                                $plng = $p["longitude"] ?? "";
                                echo ($plat && $plng) ? htmlspecialchars($plat . ", " . $plng) : "-";
                                ?>
                            </div>

                            <?php if ($mapLink !== ""): ?>
                                <div style="margin-top:12px;">
                                    <a class="btn btn-ghost" target="_blank" rel="noopener noreferrer"
                                       href="<?php echo htmlspecialchars($mapLink); ?>">
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

                    <!-- ===== NEARBY FOOD RECOMMENDATIONS ===== -->
                    <?php if (!empty($nearbyFood)): ?>
                        <hr class="sep">
                        <div class="section-title">&#127860; Nearby Food &amp; Restaurants</div>
                        <p class="meta">Recommended food places near <?php echo htmlspecialchars($p["name"]); ?>.</p>
                        <div class="nearby-grid">
                            <?php foreach ($nearbyFood as $food): ?>
                                <div class="nearby-card">
                                    <div class="nc-name"><?php echo htmlspecialchars($food["name"]); ?></div>
                                    <div class="nc-meta">
                                        <?php echo htmlspecialchars($food["state"]); ?>
                                        <?php if (!empty($food["district"])): ?>
                                            &mdash; <?php echo htmlspecialchars($food["district"]); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($food["distance_km"])): ?>
                                            <br><?php echo number_format((float)$food["distance_km"], 1); ?> km away
                                        <?php endif; ?>
                                        <?php if (!empty($food["opening_hour"])): ?>
                                            <br>&#128336; <?php echo htmlspecialchars($food["opening_hour"]); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($food["cuisine_type"])): ?>
                                        <span class="cuisine-chip"><?php echo htmlspecialchars($food["cuisine_type"]); ?></span>
                                    <?php endif; ?>
                                    <div class="nc-price">~RM <?php echo number_format((float)$food["avg_price"], 2); ?>/meal</div>
                                    <?php if (!empty($food["rating"])): ?>
                                        <div class="stars">
                                            <?php
                                            $r = (float)$food["rating"];
                                            $f = (int)floor($r);
                                            $h = ($r - $f) >= 0.5 ? 1 : 0;
                                            echo str_repeat('&#9733;', $f) . ($h ? '&#189;' : '') . str_repeat('&#9734;', 5 - $f - $h);
                                            echo " <span style='color:var(--muted);font-size:11px;'>(" . number_format($r, 1) . ")</span>";
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    $flat = $food["latitude"] ?? "";
                                    $flng = $food["longitude"] ?? "";
                                    if ($flat && $flng):
                                    ?>
                                        <a href="https://www.google.com/maps?q=<?php echo urlencode($flat . ',' . $flng); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-ghost"
                                           style="font-size:11px;padding:3px 8px;margin-top:6px;display:inline-block;">
                                            Map
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ===== NEARBY HOTEL RECOMMENDATIONS ===== -->
                    <?php if (!empty($nearbyHotels)): ?>
                        <hr class="sep">
                        <div class="section-title">&#127968; Nearby Hotels &amp; Accommodation</div>
                        <p class="meta">Recommended hotels near <?php echo htmlspecialchars($p["name"]); ?>.</p>
                        <div class="nearby-grid">
                            <?php foreach ($nearbyHotels as $hotel): ?>
                                <div class="nearby-card">
                                    <div class="nc-name"><?php echo htmlspecialchars($hotel["name"]); ?></div>
                                    <div class="nc-meta">
                                        <?php echo htmlspecialchars($hotel["state"]); ?>
                                        <?php if (!empty($hotel["district"])): ?>
                                            &mdash; <?php echo htmlspecialchars($hotel["district"]); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($hotel["distance_km"])): ?>
                                            <br><?php echo number_format((float)$hotel["distance_km"], 1); ?> km away
                                        <?php endif; ?>
                                    </div>
                                    <div class="nc-price">RM <?php echo number_format((float)$hotel["price_per_night"], 0); ?>/night</div>
                                    <?php if (!empty($hotel["rating"])): ?>
                                        <div class="stars">
                                            <?php
                                            $r = (float)$hotel["rating"];
                                            $f = (int)floor($r);
                                            $h = ($r - $f) >= 0.5 ? 1 : 0;
                                            echo str_repeat('&#9733;', $f) . ($h ? '&#189;' : '') . str_repeat('&#9734;', 5 - $f - $h);
                                            echo " <span style='color:var(--muted);font-size:11px;'>(" . number_format($r, 1) . ")</span>";
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    $hlat = $hotel["latitude"] ?? "";
                                    $hlng = $hotel["longitude"] ?? "";
                                    if ($hlat && $hlng):
                                    ?>
                                        <a href="https://www.google.com/maps?q=<?php echo urlencode($hlat . ',' . $hlng); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-ghost"
                                           style="font-size:11px;padding:3px 8px;margin-top:6px;display:inline-block;">
                                            Map
                                        </a>
                                    <?php endif; ?>
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
