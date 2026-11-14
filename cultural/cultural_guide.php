<?php
// cultural/cultural_guide.php  (CHANGE: add pagination, 12 per page)
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');

// filters
$q        = trim($_GET["q"]        ?? "");
$state    = trim($_GET["state"]    ?? "");
$district = trim($_GET["district"] ?? "");
$category = trim($_GET["category"] ?? "");

$categoryOptions = ['culture', 'heritage', 'museum', 'food', 'festival', 'nature', 'shopping'];
$stateOptions = [
    "Johor","Kedah","Kelantan","Melaka","Negeri Sembilan",
    "Pahang","Penang","Perak","Perlis","Sabah","Sarawak",
    "Selangor","Terengganu","Kuala Lumpur","Putrajaya","Labuan"
];

// All districts per state for cascading filter
$allStateDistricts = [
  "Johor"            => ["Johor Bahru","Kluang","Kota Tinggi","Mersing","Muar","Batu Pahat","Pontian","Segamat","Kulai","Tangkak"],
  "Kedah"            => ["Kota Setar","Kubang Pasu","Padang Terap","Sik","Baling","Kulim","Bandar Baharu","Kuala Muda","Yan","Langkawi","Pokok Sena","Pendang"],
  "Kelantan"         => ["Kota Bharu","Bachok","Pasir Mas","Tumpat","Pasir Puteh","Machang","Tanah Merah","Kuala Krai","Gua Musang","Jeli"],
  "Melaka"           => ["Melaka Tengah","Alor Gajah","Jasin"],
  "Negeri Sembilan"  => ["Seremban","Port Dickson","Rembau","Tampin","Jempol","Jelebu","Kuala Pilah"],
  "Pahang"           => ["Kuantan","Temerloh","Bentong","Cameron Highlands","Raub","Jerantut","Lipis","Maran","Bera","Rompin","Pekan"],
  "Penang"           => ["Timur Laut","Barat Daya","Seberang Perai Utara","Seberang Perai Tengah","Seberang Perai Selatan"],
  "Perak"            => ["Ipoh","Kinta","Larut, Matang & Selama","Manjung","Kerian","Hilir Perak","Hulu Perak","Batang Padang","Perak Tengah","Kampar"],
  "Perlis"           => ["Kangar","Arau","Padang Besar"],
  "Sabah"            => ["Kota Kinabalu","Sandakan","Tawau","Lahad Datu","Keningau","Semporna","Kunak","Papar","Beaufort","Kota Belud","Ranau","Kudat","Kinabatangan","Tuaran","Penampang","Putatan","Sipitang","Tambunan","Nabawan","Tongod","Beluran","Kota Marudu","Pitas","Tenom","Kuala Penyu"],
  "Sarawak"          => ["Kuching","Miri","Sibu","Bintulu","Sri Aman","Sarikei","Kapit","Limbang","Mukah","Betong","Serian","Kota Samarahan"],
  "Selangor"         => ["Petaling Jaya","Shah Alam","Klang","Subang Jaya","Gombak","Hulu Langat","Hulu Selangor","Kuala Langat","Sabak Bernam"],
  "Terengganu"       => ["Kuala Terengganu","Kemaman","Dungun","Besut","Setiu","Hulu Terengganu","Marang"],
  "Kuala Lumpur"     => ["City Centre (KLCC)","Chow Kit","Brickfields","Bangsar","Cheras","Kepong","Setapak","Wangsa Maju","Titiwangsa","Bukit Jalil","Segambut"],
  "Putrajaya"        => ["Putrajaya"],
  "Labuan"           => ["Victoria","Labuan Town"],
];

// Districts for the currently selected state (for cascading dropdown)
$districtOptions = ($state !== "" && isset($allStateDistricts[$state])) ? $allStateDistricts[$state] : [];

// CHANGE: pagination
$perPage = 6;
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// Build WHERE + params
$where = "cp.is_active = 1";
$params = [];
$types = "";

if ($q !== "") {
    $where .= " AND (cp.name LIKE ? OR cp.description LIKE ? OR cp.address LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
if ($state !== "" && in_array($state, $stateOptions, true)) {
    $where .= " AND cp.state = ?";
    $params[] = $state;
    $types .= "s";
}
// District filter (only when state is also selected)
if ($district !== "" && $state !== "" && in_array($district, ($allStateDistricts[$state] ?? []), true)) {
    // Check if district column exists before filtering
    $dcCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
    if ($dcCheck && $dcCheck->num_rows > 0) {
        $where .= " AND cp.district = ?";
        $params[] = $district;
        $types .= "s";
    }
}
if ($category !== "" && in_array($category, $categoryOptions, true)) {
    $where .= " AND cp.category = ?";
    $params[] = $category;
    $types .= "s";
}

// CHANGE: total count for pagination
$countSql = "SELECT COUNT(*) AS total FROM cultural_places cp WHERE $where";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) { error_log("cultural_guide.php: count prepare failed: " . $conn->error); die(htmlspecialchars(t('common.err_load'))); }
if ($types !== "") $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages; // normalize
$offset = ($page - 1) * $perPage;

// Check if district column exists for SELECT
$distColCheck = $conn->query("SHOW COLUMNS FROM cultural_places LIKE 'district'");
$hasDistCol = ($distColCheck && $distColCheck->num_rows > 0);
$districtCol = $hasDistCol ? ", cp.district" : "";

// List query
$listSql = "
  SELECT cp.place_id, cp.state{$districtCol}, cp.category, cp.name, cp.description, cp.address, cp.latitude, cp.longitude,
         cp.opening_hours, cp.estimated_cost, cp.image_url,
         COALESCE(rr.avg_review_rating, cp.avg_rating) AS display_rating,
         COALESCE(rr.review_count, 0) AS review_count
  FROM cultural_places cp
  LEFT JOIN (
      SELECT place_id, ROUND(AVG(rating), 2) AS avg_review_rating, COUNT(*) AS review_count
      FROM ratings_reviews
      GROUP BY place_id
  ) rr ON rr.place_id = cp.place_id
  WHERE $where
  ORDER BY cp.updated_at DESC, cp.place_id DESC
  LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);
if (!$listStmt) { error_log("cultural_guide.php: list prepare failed: " . $conn->error); die(htmlspecialchars(t('common.err_load'))); }

// CHANGE: bind params + limit/offset
$bindParams = $params;
$bindTypes = $types . "ii";
$bindParams[] = $perPage;
$bindParams[] = $offset;

$listStmt->bind_param($bindTypes, ...$bindParams);
$listStmt->execute();
$res = $listStmt->get_result();

$places = [];
while ($row = $res->fetch_assoc()) $places[] = $row;
$listStmt->close();

function safe_img_src($imageUrl, $placeId = 0)
{
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === "") {
        return "../assets/place_placeholder.svg";
    }

    // If it's an absolute URL (http/https) or protocol-relative URL, use it directly
    if (preg_match('#^https?://#i', $imageUrl) || strpos($imageUrl, '//') === 0) {
        return $imageUrl;
    }

    // If it's a data URI, use it directly
    if (strpos($imageUrl, 'data:image/') === 0) {
        return $imageUrl;
    }

    // Otherwise treat as local relative path
    $imageUrl = ltrim($imageUrl, '/');
    return "../" . $imageUrl;
}

function excerpt($text, $len = 120)
{
    $t = trim((string)$text);
    if ($t === "") return "";
    if (mb_strlen($t) <= $len) return $t;
    return mb_substr($t, 0, $len) . "...";
}

// CHANGE: build pagination link with current filters
function page_link($p)
{
    $qs = $_GET;
    $qs["page"] = $p;
    return "cultural_guide.php?" . http_build_query($qs);
}

// CHANGE: window of page numbers (1..N with truncation)
function page_window($current, $total)
{
    $window = [];
    if ($total <= 7) {
        for ($i = 1; $i <= $total; $i++) $window[] = $i;
        return $window;
    }
    $window[] = 1;
    $left = max(2, $current - 1);
    $right = min($total - 1, $current + 1);

    if ($left > 2) $window[] = "...";
    for ($i = $left; $i <= $right; $i++) $window[] = $i;
    if ($right < $total - 1) $window[] = "...";

    $window[] = $total;
    return $window;
}

$pageItems = page_window($page, $totalPages);
$imageFallback = "../assets/place_placeholder.svg";
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo te('nav.guide'); ?> | <?php echo te('role.traveller'); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <style>
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
            position: relative;
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

        .place-card.has-rating .place-body {
            padding-bottom: 44px;
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

        .card-rating {
            position: absolute;
            right: 12px;
            bottom: 12px;
            border-radius: 999px;
            background: #fff7ed;
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #92400e;
            padding: 5px 9px;
            font-size: 12px;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        /* CHANGE: pagination style */
        .pager {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .pager a,
        .pager span {
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            text-decoration: none;
            font-weight: 800;
            font-size: 13px;
            color: var(--navy);
            background: #fff;
        }

        .pager .active {
            background: rgba(10, 26, 79, 0.08);
        }

        .pager .disabled {
            opacity: .45;
            pointer-events: none;
        }

        .pager .dots {
            border: none;
            background: transparent;
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
                    <h1><?php echo te('nav.guide'); ?></h1>
                    <p><?php echo te('cg.subtitle'); ?></p>
                </div>
                <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="btn btn-primary" href="suggest_place.php"><?php echo te('cg.suggest_btn'); ?></a>
                    <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php"><?php echo te('common.back'); ?></a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <h3><?php echo te('cg.filter_title'); ?></h3>
                    <form method="get" class="grid" style="gap:12px;">
                        <div class="col-6">
                            <label style="font-size:13px; font-weight:800;"><?php echo te('cg.keyword'); ?></label><br>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
                                placeholder="<?php echo te('cg.keyword_placeholder'); ?>"
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;"><?php echo te('cg.state'); ?></label><br>
                            <select name="state" id="stateFilter"
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);"
                                onchange="updateDistrictFilter(this.value)">
                                <option value=""><?php echo te('cg.all_states'); ?></option>
                                <?php foreach ($stateOptions as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($state === $s) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($s); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;"><?php echo te('cg.district'); ?></label><br>
                            <select name="district" id="districtFilter"
                                style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value=""><?php echo te('cg.all_districts'); ?></option>
                                <?php foreach ($districtOptions as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($district === $d) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars($d); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label style="font-size:13px; font-weight:800;"><?php echo te('cg.category'); ?></label><br>
                            <select name="category" style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                                <option value=""><?php echo te('cg.all_categories'); ?></option>
                                <?php foreach ($categoryOptions as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($category === $c) ? "selected" : ""; ?>>
                                        <?php echo htmlspecialchars(t_choice('interest', $c)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12" style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button class="btn btn-primary" type="submit"><?php echo te('cg.apply'); ?></button>
                            <a class="btn btn-ghost" href="cultural_guide.php"><?php echo te('cg.reset'); ?></a>
                        </div>
                    </form>
                </div>

                <div class="card col-12">
                    <h3><?php echo te('cg.places_title'); ?></h3>
                    <p class="meta">
                        <?php echo te('cg.total_line', ['total' => (int)$total, 'page' => (int)$page, 'pages' => (int)$totalPages]); ?>
                    </p>

                    <?php if (count($places) === 0): ?>
                        <div style="padding:12px; color:var(--muted);"><?php echo te('cg.no_places'); ?></div>
                    <?php else: ?>
                        <div class="place-grid">
                            <?php foreach ($places as $p): ?>
                                <?php $img = safe_img_src($p["image_url"] ?? "", (int)$p["place_id"]); ?>
                                <?php $hasUserRating = ((int)($p["review_count"] ?? 0) > 0 && (float)($p["display_rating"] ?? 0) > 0); ?>
                                <div class="place-card <?php echo $hasUserRating ? "has-rating" : ""; ?>">
                                    <div class="place-thumb">
                                        <?php if ($img !== ""): ?>
                                            <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($p["name"]); ?>"
                                                 loading="lazy" decoding="async"
                                                 onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($imageFallback); ?>';">
                                        <?php else: ?>
                                            <span class="meta2"><?php echo te('cg.no_photo'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="place-body">
                                        <div class="badges">
                                            <span class="badge"><?php echo htmlspecialchars($p["state"]); ?></span>
                                            <?php if (!empty($p["district"])): ?>
                                            <span class="badge" style="background:rgba(99,102,241,.10); color:#4338ca;">
                                                <?php echo htmlspecialchars($p["district"]); ?>
                                            </span>
                                            <?php endif; ?>
                                            <span class="badge"><?php echo htmlspecialchars(t_choice('interest', $p["category"])); ?></span>
                                            <span class="badge">RM <?php echo number_format((float)($p["estimated_cost"] ?? 0), 2); ?></span>
                                        </div>

                                        <div style="font-weight:900; color:var(--navy);">
                                            <?php echo htmlspecialchars($p["name"]); ?>
                                        </div>

                                        <div class="meta2" style="margin-top:6px;">
                                            <?php echo htmlspecialchars(excerpt($p["description"] ?? "")); ?>
                                        </div>

                                        <div class="place-actions">
                                            <a class="btn btn-ghost" href="cultural_guide_detail.php?place_id=<?php echo (int)$p["place_id"]; ?>"><?php echo te('cg.view_details'); ?></a>
                                        </div>
                                        <?php if ($hasUserRating): ?>
                                            <div class="card-rating" title="<?php echo ((int)$p["review_count"] === 1) ? te('cg.review_one') : te('cg.review_many', ['n' => (int)$p["review_count"]]); ?>">
                                                <?php echo number_format((float)$p["display_rating"], 1); ?>&#11088;
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- CHANGE: Pagination UI -->
                        <div class="pager">
                            <a class="<?php echo ($page <= 1) ? "disabled" : ""; ?>" href="<?php echo htmlspecialchars(page_link(max(1, $page - 1))); ?>"><?php echo te('cg.prev'); ?></a>

                            <?php foreach ($pageItems as $it): ?>
                                <?php if ($it === "..."): ?>
                                    <span class="dots">...</span>
                                <?php else: ?>
                                    <a class="<?php echo ((int)$it === (int)$page) ? "active" : ""; ?>"
                                        href="<?php echo htmlspecialchars(page_link((int)$it)); ?>">
                                        <?php echo (int)$it; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <a class="<?php echo ($page >= $totalPages) ? "disabled" : ""; ?>" href="<?php echo htmlspecialchars(page_link(min($totalPages, $page + 1))); ?>"><?php echo te('cg.next'); ?></a>
                        </div>
                    <?php endif; ?>

                </div>
            </section>
        </main>
    </div>
<script>
// Cascading district filter: populate district dropdown when state changes
var stateDistrictsMap = <?php echo json_encode($allStateDistricts); ?>;
var CG_T = <?php echo i18n_js(['cg.all_districts']); ?>;

function updateDistrictFilter(selectedState) {
    var distSel = document.getElementById('districtFilter');
    if (!distSel) return;

    // Clear existing options
    distSel.innerHTML = '';
    var anyOpt = document.createElement('option');
    anyOpt.value = '';
    anyOpt.textContent = CG_T['cg.all_districts'];
    distSel.appendChild(anyOpt);

    if (selectedState && stateDistrictsMap[selectedState]) {
        stateDistrictsMap[selectedState].forEach(function(d) {
            var opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            distSel.appendChild(opt);
        });
    }
}
</script>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>
</html>

