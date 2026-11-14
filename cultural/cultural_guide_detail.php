<?php
// cultural/cultural_guide_detail.php
// One place: its details and background, traveller reviews, and food and lodging nearby.
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once "../services/HotelRecommendationService.php";
require_once "../services/FoodRecommendationService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
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

// ---- Where the details came from (kept by the open-data sync; older places have no record) ----
$source = null;
try {
    $stmt = $conn->prepare("SELECT osm_ref, wikipedia_url, description_source, image_source_url, image_credit, image_license FROM place_sources WHERE place_id = ?");
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $source = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $source = null;   // the table arrives with database/migrations/20261111_open_place_data.sql
}

// ---- Image and map helpers ----
$imageFallback = "../assets/place_placeholder.svg";

function img_src($imageUrl, string $fallback): string
{
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === "") return $fallback;
    if (preg_match('#^https?://#i', $imageUrl)) return $imageUrl;
    return "../" . ltrim($imageUrl, '/');
}

/** Link that shows a coordinate on OpenStreetMap. */
function osm_point_link($lat, $lng): string
{
    $lat = (float)$lat;
    $lng = (float)$lng;
    return "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map=17/{$lat}/{$lng}";
}

$img = img_src($p["image_url"] ?? "", $imageFallback);

$mapLink = "";
if (!empty($p["latitude"]) && !empty($p["longitude"])) {
    $mapLink = osm_point_link($p["latitude"], $p["longitude"]);
} elseif (!empty($p["address"])) {
    $mapLink = "https://www.openstreetmap.org/search?query=" . urlencode($p["address"]);
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo te('cgd.title'); ?> | <?php echo te('nav.guide'); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
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

        .rating-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            background: #f8fafc;
            margin: 10px 0 12px;
        }

        .rating-score {
            font-size: 28px;
            line-height: 1;
            font-weight: 900;
            color: var(--navy);
        }

        .star-rating {
            display: inline-flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 3px;
        }

        .star-rating input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .star-rating label {
            font-size: 30px;
            line-height: 1;
            color: #cbd5e1;
            cursor: pointer;
            transition: color .12s ease, transform .12s ease;
        }

        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input:checked ~ label {
            color: #f59e0b;
        }

        .star-rating label:hover {
            transform: translateY(-1px);
        }

        .review-form {
            display: grid;
            gap: 10px;
            margin: 12px 0;
            padding: 14px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            background: #fff;
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
                    <span><?php echo te('nav.guide'); ?></span>
                </div>
            </div>

            <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
                <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
                <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
                <a href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
                <a class="active" href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
                <a href="../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
                <a href="../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
            </nav>
            <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>

            <div class="sidebar-footer">
                <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1><?php echo htmlspecialchars($p["name"]); ?></h1>
                    <p><?php echo te('cgd.subtitle'); ?></p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn btn-ghost" href="cultural_guide.php"><?php echo te('cgd.back_list'); ?></a>
                    <a class="btn btn-primary" href="suggest_place.php"><?php echo te('cg.suggest_btn'); ?></a>
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
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($p["name"]); ?>"
                                     loading="lazy" decoding="async"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($imageFallback); ?>';">
                            <?php else: ?>
                                <div class="noimg"><?php echo te('cgd.no_photo'); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="row"><span class="k"><?php echo te('cgd.k_state'); ?></span> <?php echo htmlspecialchars($p["state"]); ?></div>
                            <?php if (!empty($p["district"])): ?>
                            <div class="row"><span class="k"><?php echo te('cgd.k_district'); ?></span>
                                <span style="background:rgba(99,102,241,.10); color:#4338ca; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:800;">
                                    <?php echo htmlspecialchars($p["district"]); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <div class="row"><span class="k"><?php echo te('cgd.k_category'); ?></span> <?php echo htmlspecialchars(t_choice('interest', $p["category"])); ?></div>
                            <div class="row"><span class="k"><?php echo te('cgd.k_fee'); ?></span> RM <?php echo number_format((float)($p["entrance_fee"] ?? $p["estimated_cost"] ?? 0), 2); ?></div>
                            <?php if (array_key_exists("visit_duration_min", $p)): ?>
                                <div class="row"><span class="k"><?php echo te('cgd.k_duration'); ?></span> <?php echo te('cgd.minutes', ['n' => (int)$p["visit_duration_min"]]); ?></div>
                            <?php endif; ?>
                            <?php if (array_key_exists("halal_status", $p) && $p["halal_status"] !== null): ?>
                                <div class="row"><span class="k"><?php echo te('cgd.k_halal'); ?></span> <?php echo ((int)$p["halal_status"] === 1) ? te('cgd.halal_yes') : te('cgd.halal_no'); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p["avg_rating"])): ?>
                                <div class="row"><span class="k"><?php echo te('cgd.k_rating'); ?></span> <?php echo number_format((float)$p["avg_rating"], 1); ?> / 5.0</div>
                            <?php endif; ?>
                            <?php if (!empty($p["best_time_to_visit"])): ?>
                                <div class="row"><span class="k"><?php echo te('cgd.k_best_time'); ?></span> <?php echo htmlspecialchars($p["best_time_to_visit"]); ?></div>
                            <?php endif; ?>
                            <?php if (array_key_exists("dress_code_required", $p) && $p["dress_code_required"] !== null): ?>
                                <div class="row"><span class="k"><?php echo te('cgd.k_dress'); ?></span> <?php echo ((int)$p["dress_code_required"] === 1) ? te('cgd.dress_required') : te('cgd.dress_not_required'); ?></div>
                            <?php endif; ?>
                            <div class="row"><span class="k"><?php echo te('cgd.k_hours'); ?></span>
                                <?php $hoursText = trim((string)($p["opening_hours"] ?? "")); ?>
                                <?php if (str_contains($hoursText, "\n")): ?>
                                    <div style="margin-top:4px; font-size:13px; line-height:1.6;"><?php echo nl2br(htmlspecialchars($hoursText)); ?></div>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($hoursText !== "" ? $hoursText : "-"); ?>
                                <?php endif; ?>
                            </div>
                            <div class="row"><span class="k"><?php echo te('cgd.k_address'); ?></span> <?php echo htmlspecialchars($p["address"] ?? "-"); ?></div>
                            <div class="row"><span class="k"><?php echo te('cgd.k_coords'); ?></span>
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
                                        <?php echo te('cgd.open_osm'); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr class="sep">

                    <h3><?php echo te('cgd.background'); ?></h3>
                    <div style="color:var(--navy); line-height:1.7;">
                        <?php echo nl2br(htmlspecialchars($p["description"] ?? t('cgd.no_description'))); ?>
                    </div>
                    <?php if ($source && ($source["wikipedia_url"] || $source["image_source_url"] || $source["osm_ref"])): ?>
                        <p class="meta" style="margin-top:10px; font-size:12px;">
                            <?php echo te('cgd.src_label'); ?>
                            <?php if ($source["description_source"] === "wikipedia" && $source["wikipedia_url"]): ?>
                                <?php echo te('cgd.src_desc'); ?>
                                <a href="<?php echo htmlspecialchars($source["wikipedia_url"]); ?>" target="_blank" rel="noopener noreferrer">Wikipedia</a>
                                (<a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener noreferrer">CC BY-SA 4.0</a>);
                            <?php endif; ?>
                            <?php if ($source["image_source_url"]): ?>
                                <?php echo te('cgd.src_photo'); ?><?php echo $source["image_credit"] ? " " . te('cgd.src_by', ['name' => $source["image_credit"]]) : ""; ?><?php echo $source["image_license"] ? " (" . htmlspecialchars($source["image_license"]) . ")" : ""; ?>,
                                <a href="<?php echo htmlspecialchars($source["image_source_url"]); ?>" target="_blank" rel="noopener noreferrer">Wikimedia Commons</a>;
                            <?php endif; ?>
                            <?php if ($source["osm_ref"]): ?>
                                <?php echo te('cgd.src_details'); ?> &copy;
                                <a href="https://www.openstreetmap.org/<?php echo htmlspecialchars($source["osm_ref"]); ?>" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>
                                <?php echo te('cgd.src_contributors'); ?> (<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">ODbL</a>).
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <hr class="sep">
                    <div class="section-title"><?php echo te('cgd.reviews_title'); ?></div>
                    <div class="rating-summary">
                        <div>
                            <?php if ($reviewStats["total"] > 0): ?>
                                <div class="rating-score"><?php echo number_format((float)$reviewStats["avg"], 1); ?><span style="font-size:14px;color:var(--muted);"> / 5</span></div>
                                <div class="stars" style="font-size:16px;margin-top:4px;">
                                    <?php
                                    $avgDisplay = (float)$reviewStats["avg"];
                                    $fullStars = (int)floor($avgDisplay);
                                    $halfStar = ($avgDisplay - $fullStars) >= 0.5 ? 1 : 0;
                                    echo str_repeat("&#9733;", $fullStars) . ($halfStar ? "&#9733;" : "") . str_repeat("&#9734;", 5 - $fullStars - $halfStar);
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="rating-score">-<span style="font-size:14px;color:var(--muted);"> / 5</span></div>
                                <div class="meta"><?php echo te('cgd.no_reviews'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <?php echo ((int)$reviewStats["total"] === 1) ? te('cgd.reviews_one') : te('cgd.reviews_many', ['n' => (int)$reviewStats["total"]]); ?>
                            <?php echo te('cgd.reviews_help'); ?>
                        </div>
                    </div>

                    <form method="post" action="submit_review.php" class="review-form">
                        <input type="hidden" name="place_id" value="<?php echo (int)$placeId; ?>">
                        <div>
                            <label style="font-size:13px; font-weight:800; display:block; margin-bottom:6px;"><?php echo te('cgd.your_rating'); ?> *</label>
                            <div class="star-rating" aria-label="<?php echo te('cgd.rating_aria'); ?>">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" id="rating-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required <?php echo ((int)($userReview["rating"] ?? 0) === $i) ? "checked" : ""; ?>>
                                    <label for="rating-<?php echo $i; ?>" title="<?php echo $i > 1 ? te('cgd.star_many', ['n' => $i]) : te('cgd.star_one'); ?>">&#9733;</label>
                                <?php endfor; ?>
                            </div>
                            <div class="meta" style="margin-top:4px;"><?php echo te('cgd.rating_help'); ?></div>
                        </div>
                        <div>
                            <label style="font-size:13px; font-weight:800;"><?php echo te('cgd.review_label'); ?></label><br>
                            <textarea name="review_text" rows="4" maxlength="2000" placeholder="<?php echo te('cgd.review_placeholder'); ?>"
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"><?php echo htmlspecialchars($userReview["review_text"] ?? ""); ?></textarea>
                        </div>
                        <div>
                            <button class="btn btn-primary" type="submit"><?php echo $userReview ? te('cgd.update_review') : te('cgd.submit_review'); ?></button>
                        </div>
                    </form>

                    <?php if (!empty($reviews)): ?>
                        <div class="nearby-grid">
                            <?php foreach ($reviews as $review): ?>
                                <div class="nearby-card">
                                    <div class="nc-name"><?php echo htmlspecialchars($review["full_name"] ?? t('common.traveller_default')); ?></div>
                                    <div class="stars"><?php echo str_repeat("&#9733;", (int)$review["rating"]) . str_repeat("&#9734;", 5 - (int)$review["rating"]); ?></div>
                                    <div class="nc-meta" style="margin-top:6px;"><?php echo nl2br(htmlspecialchars($review["review_text"] ?: t('cgd.no_comment'))); ?></div>
                                    <div class="nc-meta" style="margin-top:6px;"><?php $reviewWhen = (string)($review["updated_at"] ?? $review["created_at"] ?? ""); echo htmlspecialchars($reviewWhen !== "" && strtotime($reviewWhen) ? i18n_date($reviewWhen, 'short') . " " . date("H:i", strtotime($reviewWhen)) : $reviewWhen); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ===== NEARBY FOOD RECOMMENDATIONS ===== -->
                    <?php if (!empty($nearbyFood)): ?>
                        <hr class="sep">
                        <div class="section-title">&#127860; <?php echo te('cgd.nearby_food'); ?></div>
                        <p class="meta"><?php echo te('cgd.nearby_food_help', ['name' => $p["name"]]); ?></p>
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
                                            <br><?php echo te('cgd.km_away', ['km' => number_format((float)$food["distance_km"], 1)]); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($food["opening_hour"])): ?>
                                            <br>&#128336; <?php echo htmlspecialchars($food["opening_hour"]); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($food["cuisine_type"])): ?>
                                        <span class="cuisine-chip"><?php echo htmlspecialchars($food["cuisine_type"]); ?></span>
                                    <?php endif; ?>
                                    <div class="nc-price"><?php echo te('cgd.per_meal', ['price' => number_format((float)$food["avg_price"], 2)]); ?></div>
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
                                        <a href="<?php echo htmlspecialchars(osm_point_link($flat, $flng)); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-ghost"
                                           style="font-size:11px;padding:3px 8px;margin-top:6px;display:inline-block;">
                                            <?php echo te('cgd.map'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ===== NEARBY HOTEL RECOMMENDATIONS ===== -->
                    <?php if (!empty($nearbyHotels)): ?>
                        <hr class="sep">
                        <div class="section-title">&#127968; <?php echo te('cgd.nearby_hotels'); ?></div>
                        <p class="meta"><?php echo te('cgd.nearby_hotels_help', ['name' => $p["name"]]); ?></p>
                        <div class="nearby-grid">
                            <?php foreach ($nearbyHotels as $hotel): ?>
                                <div class="nearby-card">
                                    <div class="nc-name"><?php echo htmlspecialchars($hotel["name"]); ?></div>
                                    <div class="nc-meta">
                                        <?php echo htmlspecialchars($hotel["kind_label"] . ($hotel["stars"] !== null ? ", " . t('cgd.star_hotel', ['n' => (float)$hotel["stars"]]) : "")); ?>
                                        <?php if ($hotel["address"] !== ""): ?><br><?php echo htmlspecialchars($hotel["address"]); ?><?php endif; ?>
                                        <?php if ($hotel["distance_km"] !== null): ?>
                                            <br><?php echo te('cgd.km_away', ['km' => number_format((float)$hotel["distance_km"], 1)]); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="nc-price"><?php echo te('cgd.per_night', ['price' => number_format((float)$hotel["price_per_night"], 0)]); ?></div>
                                    <?php
                                    $mapUrl = $hotel["map_url"] ?? "";
                                    if ($mapUrl !== ""):
                                    ?>
                                        <a href="<?php echo htmlspecialchars($mapUrl); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-ghost"
                                           style="font-size:11px;padding:3px 8px;margin-top:6px;display:inline-block;">
                                            <?php echo te('cgd.map'); ?>
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
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>

