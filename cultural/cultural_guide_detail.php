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
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$success = $_SESSION["success_message"] ?? "";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);
$placeId = (int)($_GET["place_id"] ?? 0);
if ($placeId <= 0) {
    header("Location: cultural_guide.php");
    exit;
}

// Check if district column exists
$dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistCol = ($dcCheck && $dcCheck->num_rows > 0);
$districtSelectCol = $hasDistCol ? ", district" : "";

$extraCols = "";
foreach (["entrance_fee", "halal_status", "visit_duration_min", "best_time_to_visit", "dress_code_required", "avg_rating"] as $col) {
    $safeCol = $conn->real_escape_string($col);
    $colCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE '$safeCol'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $extraCols .= ", `$safeCol`";
    }
}

$stmt = $conn->prepare("
    SELECT place_id, state{$districtSelectCol}, category, name, description, address, latitude, longitude,
           opening_hours, estimated_cost, image_url{$extraCols}
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

$userReview = null;
$reviews = [];
$reviewStats = ["total" => 0, "avg" => null];
$reviewTable = $conn->query("SHOW TABLES LIKE 'ratings_reviews'");
if ($reviewTable && $reviewTable->num_rows > 0) {
    $stmt = $conn->prepare("SELECT review_id, rating, review_text, created_at, updated_at FROM ratings_reviews WHERE place_id = ? AND traveller_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $placeId, $travellerId);
        $stmt->execute();
        $userReview = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $stmt = $conn->prepare("
        SELECT rr.rating, rr.review_text, rr.created_at, rr.updated_at, t.full_name
        FROM ratings_reviews rr
        LEFT JOIN travellers t ON t.traveller_id = rr.traveller_id
        WHERE rr.place_id = ?
        ORDER BY rr.created_at DESC
        LIMIT 20
    ");
    if ($stmt) {
        $stmt->bind_param("i", $placeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $reviews[] = $row;
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT COUNT(*) AS total, ROUND(AVG(rating), 2) AS avg_rating FROM ratings_reviews WHERE place_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $placeId);
        $stmt->execute();
        $reviewStatsRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $reviewStats = [
            "total" => (int)($reviewStatsRow["total"] ?? 0),
            "avg" => $reviewStatsRow["avg_rating"] ?? null,
        ];
    }
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
                <?php if ($success): ?>
                    <div class="card col-12" style="border-left:6px solid rgba(16,185,129,.7); color:#065f46; font-weight:800;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="card col-12" style="border-left:6px solid rgba(239,68,68,.7); color:#7f1d1d; font-weight:800;">
                        <?php echo htmlspecialchars($errors[0]); ?>
                    </div>
                <?php endif; ?>
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
                            <div class="row"><span class="k">Entrance Fee:</span> RM <?php echo number_format((float)($p["entrance_fee"] ?? $p["estimated_cost"] ?? 0), 2); ?></div>
                            <?php if (array_key_exists("visit_duration_min", $p)): ?>
                                <div class="row"><span class="k">Suggested Duration:</span> <?php echo (int)$p["visit_duration_min"]; ?> minutes</div>
                            <?php endif; ?>
                            <?php if (array_key_exists("halal_status", $p) && $p["halal_status"] !== null): ?>
                                <div class="row"><span class="k">Halal:</span> <?php echo ((int)$p["halal_status"] === 1) ? "Available / certified" : "Not certified"; ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p["avg_rating"])): ?>
                                <div class="row"><span class="k">Rating:</span> <?php echo number_format((float)$p["avg_rating"], 1); ?> / 5.0</div>
                            <?php endif; ?>
                            <?php if (!empty($p["best_time_to_visit"])): ?>
                                <div class="row"><span class="k">Best Time:</span> <?php echo htmlspecialchars($p["best_time_to_visit"]); ?></div>
                            <?php endif; ?>
                            <?php if (array_key_exists("dress_code_required", $p) && $p["dress_code_required"] !== null): ?>
                                <div class="row"><span class="k">Dress Code:</span> <?php echo ((int)$p["dress_code_required"] === 1) ? "Required" : "Not required"; ?></div>
                            <?php endif; ?>
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

                    <hr class="sep">
                    <div class="section-title">Traveller Ratings &amp; Reviews</div>
                    <p class="meta">
                        <?php if ($reviewStats["total"] > 0): ?>
                            Average rating: <strong><?php echo number_format((float)$reviewStats["avg"], 1); ?>/5</strong>
                            from <?php echo (int)$reviewStats["total"]; ?> review(s).
                        <?php else: ?>
                            No traveller reviews yet.
                        <?php endif; ?>
                    </p>

                    <form method="post" action="submit_review.php" style="display:grid; gap:10px; margin:12px 0;">
                        <input type="hidden" name="place_id" value="<?php echo (int)$placeId; ?>">
                        <div>
                            <label style="font-size:13px; font-weight:800;">Your Rating *</label><br>
                            <select name="rating" required style="width:220px; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value="" disabled <?php echo !$userReview ? "selected" : ""; ?>>Choose rating</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ((int)($userReview["rating"] ?? 0) === $i) ? "selected" : ""; ?>>
                                        <?php echo $i; ?> / 5
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:13px; font-weight:800;">Review</label><br>
                            <textarea name="review_text" rows="4" maxlength="2000" placeholder="Share your visit experience, cultural value, accessibility, or cost accuracy."
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"><?php echo htmlspecialchars($userReview["review_text"] ?? ""); ?></textarea>
                        </div>
                        <div>
                            <button class="btn btn-primary" type="submit"><?php echo $userReview ? "Update Review" : "Submit Review"; ?></button>
                        </div>
                    </form>

                    <?php if (!empty($reviews)): ?>
                        <div class="nearby-grid">
                            <?php foreach ($reviews as $review): ?>
                                <div class="nearby-card">
                                    <div class="nc-name"><?php echo htmlspecialchars($review["full_name"] ?? "Traveller"); ?></div>
                                    <div class="stars"><?php echo str_repeat("&#9733;", (int)$review["rating"]) . str_repeat("&#9734;", 5 - (int)$review["rating"]); ?></div>
                                    <div class="nc-meta" style="margin-top:6px;"><?php echo nl2br(htmlspecialchars($review["review_text"] ?: "No written comment.")); ?></div>
                                    <div class="nc-meta" style="margin-top:6px;"><?php echo htmlspecialchars($review["updated_at"] ?? $review["created_at"] ?? ""); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

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
