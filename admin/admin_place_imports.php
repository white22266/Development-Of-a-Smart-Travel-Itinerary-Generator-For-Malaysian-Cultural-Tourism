<?php
// admin/admin_place_imports.php
// What the open-data sync (tools/sync_places.php) found: new places waiting for a decision,
// possible duplicates, and every automatic change made to an existing place.
session_start();
require_once "../config/db_connect.php";
require_once "../services/PlaceSyncService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminName = $_SESSION["admin_name"] ?? "Administrator";
$adminId = (int)($_SESSION["admin_id"] ?? 0) ?: null;
$states = array_keys(OsmDataService::STATE_ISO);
$tabs = [
    "pending" => "New places",
    "duplicate" => "Possible duplicates",
    "published" => "Published",
    "rejected" => "Rejected",
    "changes" => "Automatic changes",
];

// The list a form was sent from, so the admin comes back to the same list after an action.
$readFilters = static function (array $in) use ($tabs, $states): array {
    $tab = (string)($in["tab"] ?? "pending");
    $state = (string)($in["state"] ?? "");
    $category = (string)($in["category"] ?? "");
    return [
        "tab" => isset($tabs[$tab]) ? $tab : "pending",
        "state" => in_array($state, $states, true) ? $state : "",
        "category" => in_array($category, PlaceSyncService::CATEGORIES, true) ? $category : "",
        "q" => mb_substr(trim((string)($in["q"] ?? "")), 0, 80),
        "page" => max(1, (int)($in["page"] ?? 1)),
    ];
};

// ---------------------------------------------------------------- actions
// (config/security.php has already checked the request token of every POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $filters = $readFilters((array)($_POST["return"] ?? []));
    $action = (string)($_POST["action"] ?? "");
    $candidateId = (int)($_POST["candidate_id"] ?? 0);
    $sync = new PlaceSyncService($conn);
    $done = 0;
    $failed = 0;

    try {
        if ($action === "publish") {
            // The district field is filled in with a guess. Left as it is, the guess is checked against the map.
            $district = trim((string)($_POST["district"] ?? ""));
            if ($district === trim((string)($_POST["district_guess"] ?? ""))) $district = "";
            $name = trim((string)($_POST["name"] ?? ""));
            $placeId = $sync->publishCandidate($candidateId, $adminId, [
                "name" => $name !== "" ? $name : null,
                "category" => (string)($_POST["category"] ?? ""),
                "district" => $district,
            ]);
            $placeId !== null ? $done++ : $failed++;
        } elseif ($action === "reject") {
            $sync->rejectCandidate($candidateId, $adminId) ? $done++ : $failed++;
        } elseif ($action === "link") {
            $sync->linkCandidateToPlace($candidateId, $adminId) ? $done++ : $failed++;
        } elseif ($action === "restore") {
            $sync->restoreCandidate($candidateId) ? $done++ : $failed++;
        } elseif ($action === "undo") {
            $sync->revertChange((int)($_POST["change_id"] ?? 0)) ? $done++ : $failed++;
        } elseif ($action === "bulk_publish" || $action === "bulk_reject") {
            // Publishing looks up an address and downloads a photo for each place: keep batches small.
            $ids = array_slice(array_unique(array_map("intval", (array)($_POST["ids"] ?? []))), 0, 20);
            set_time_limit(300);
            foreach ($ids as $id) {
                $ok = $action === "bulk_publish"
                    ? $sync->publishCandidate($id, $adminId) !== null
                    : $sync->rejectCandidate($id, $adminId);
                $ok ? $done++ : $failed++;
            }
        }
    } catch (Throwable $e) {
        error_log("admin_place_imports: " . $e->getMessage());
        $failed++;
    }

    $verbs = [
        "publish" => "published", "bulk_publish" => "published", "reject" => "rejected", "bulk_reject" => "rejected",
        "link" => "linked to the existing place", "restore" => "put back for review", "undo" => "undone",
    ];
    $verb = $verbs[$action] ?? "updated";
    if ($done > 0) {
        $_SESSION["success_message"] = $done . ($done === 1 ? " item " : " items ") . $verb . ".";
        $photosLeft = $sync->photosLeft();
        if ($photosLeft > 0) {
            $_SESSION["success_message"] .= " " . $photosLeft . ($photosLeft === 1 ? " photo" : " photos")
                . " could not be downloaded just now; the next sync adds " . ($photosLeft === 1 ? "it" : "them") . ".";
        }
    }
    if ($failed > 0) {
        $_SESSION["form_errors"] = [$failed . ($failed === 1 ? " item" : " items") . " could not be " . $verb . ". It may have been handled already; details are in the server log."];
    }
    header("Location: admin_place_imports.php?" . http_build_query(array_filter($filters)));
    exit;
}

// ---------------------------------------------------------------- data for the page
$filters = $readFilters($_GET);
$tab = $filters["tab"];
$success = $_SESSION["success_message"] ?? "";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$perPage = 30;
$offset = ($filters["page"] - 1) * $perPage;
$overview = [];
foreach ($states as $s) {
    $overview[$s] = ["last" => null, "status" => null, "pending" => 0, "duplicate" => 0, "published" => 0, "lodgings" => 0];
}
$tabCounts = array_fill_keys(array_keys($tabs), 0);
$rows = [];
$total = 0;
$districts = [];
$setupNeeded = false;

try {
    $result = $conn->query("
        SELECT r.state, r.status, COALESCE(r.finished_at, r.started_at) AS at
        FROM place_sync_runs r
        JOIN (SELECT state, MAX(run_id) AS run_id FROM place_sync_runs GROUP BY state) latest ON latest.run_id = r.run_id
    ");
    while ($r = $result->fetch_assoc()) {
        if (isset($overview[$r["state"]])) {
            $overview[$r["state"]]["last"] = $r["at"];
            $overview[$r["state"]]["status"] = $r["status"];
        }
    }
    $result = $conn->query("SELECT state, status, COUNT(*) AS n FROM place_import_candidates GROUP BY state, status");
    while ($r = $result->fetch_assoc()) {
        if (isset($tabCounts[$r["status"]])) $tabCounts[$r["status"]] += (int)$r["n"];
        if (isset($overview[$r["state"]][$r["status"]])) $overview[$r["state"]][$r["status"]] = (int)$r["n"];
    }
    $result = $conn->query("SELECT state, COUNT(*) AS n FROM lodgings WHERE is_active = 1 GROUP BY state");
    while ($r = $result->fetch_assoc()) {
        if (isset($overview[$r["state"]])) $overview[$r["state"]]["lodgings"] = (int)$r["n"];
    }
    $tabCounts["changes"] = (int)$conn->query("SELECT COUNT(*) FROM place_change_log WHERE reverted_at IS NULL")->fetch_row()[0];

    // The list of the open tab, narrowed by the filters.
    $where = [];
    $types = "";
    $params = [];
    $nameColumn = $tab === "changes" ? "cp.name" : "c.name";
    $stateColumn = $tab === "changes" ? "cp.state" : "c.state";
    if ($tab !== "changes") {
        $where[] = "c.status = ?";
        $types .= "s";
        $params[] = $tab;
    }
    if ($filters["state"] !== "") {
        $where[] = "{$stateColumn} = ?";
        $types .= "s";
        $params[] = $filters["state"];
    }
    if ($filters["category"] !== "" && $tab !== "changes") {
        $where[] = "c.category = ?";
        $types .= "s";
        $params[] = $filters["category"];
    }
    if ($filters["q"] !== "") {
        $where[] = "{$nameColumn} LIKE ?";
        $types .= "s";
        $params[] = "%" . addcslashes($filters["q"], "%_\\") . "%";
    }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    if ($tab === "changes") {
        $from = "FROM place_change_log l JOIN cultural_places cp ON cp.place_id = l.place_id";
        $select = "SELECT l.*, cp.name AS place_name, cp.state AS place_state";
        $order = "ORDER BY l.change_id DESC";
    } else {
        $from = "FROM place_import_candidates c LEFT JOIN cultural_places cp ON cp.place_id = c.duplicate_of_place_id";
        $select = "SELECT c.*, cp.name AS existing_name";
        $order = in_array($tab, ["published", "rejected"], true) ? "ORDER BY c.reviewed_at DESC, c.candidate_id DESC" : "ORDER BY c.score DESC, c.candidate_id";
    }

    $count = $conn->prepare("SELECT COUNT(*) {$from} {$whereSql}");
    if ($params) $count->bind_param($types, ...$params);
    $count->execute();
    $total = (int)$count->get_result()->fetch_row()[0];
    $count->close();

    $list = $conn->prepare("{$select} {$from} {$whereSql} {$order} LIMIT ? OFFSET ?");
    $listParams = array_merge($params, [$perPage, $offset]);
    $list->bind_param($types . "ii", ...$listParams);
    $list->execute();
    $rows = $list->get_result()->fetch_all(MYSQLI_ASSOC);
    $list->close();

    // District names the app already uses, offered while typing.
    $result = $conn->query("SELECT DISTINCT state, district FROM cultural_places WHERE district IS NOT NULL AND district <> '' ORDER BY state, district");
    while ($r = $result->fetch_assoc()) $districts[$r["state"]][] = $r["district"];
} catch (mysqli_sql_exception $e) {
    // The tables come from database/migrations/20261111_open_place_data.sql.
    error_log("admin_place_imports: " . $e->getMessage());
    $setupNeeded = true;
}
$pages = max(1, (int)ceil($total / $perPage));

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/** Link to this page with some of the filters changed. */
function imports_url(array $filters, array $change = []): string
{
    return "admin_place_imports.php?" . http_build_query(array_filter(array_merge($filters, $change)));
}

/** The filters as hidden fields, so an action returns to the same list. */
function imports_hidden(array $filters): string
{
    $html = "";
    foreach (array_filter($filters) as $name => $value) {
        $html .= '<input type="hidden" name="return[' . h($name) . ']" value="' . h($value) . '">';
    }
    return $html;
}

/** A small copy of a Wikimedia Commons photo for the list (330px is one of the sizes Wikimedia serves). */
function imports_thumb($image): string
{
    $url = is_array($image) ? (string)($image["download_url"] ?? "") : "";
    if (!preg_match('#^https://(upload|thumb)\.wikimedia\.org/#', $url)) return "";
    return (string)preg_replace('#/\d+px-#', "/330px-", $url);
}

/** Only http(s) links from imported data are shown as links. */
function imports_link($url, string $label): string
{
    $url = (string)$url;
    if (!preg_match('#^https?://#i', $url)) return "";
    return '<a href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($label) . '</a>';
}

/** Where a filled-in value came from, as a link to the source. */
function imports_source($source, $ref): string
{
    $labels = ["osm" => "OpenStreetMap", "wikipedia" => "Wikipedia", "wikidata" => "Wikidata", "commons" => "Wikimedia Commons"];
    $label = $labels[(string)$source] ?? ucfirst((string)$source);
    $ref = (string)$ref;
    if (preg_match('#^(node|way|relation)/\d+$#', $ref)) $ref = "https://www.openstreetmap.org/" . $ref;
    return imports_link($ref, $label) ?: h($label);
}

$syncedStates = count(array_filter($overview, static fn($o) => $o["last"] !== null));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Open Data Imports | Admin</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
    <style>
        .imports-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .imports-tabs a { padding: 8px 14px; border-radius: 999px; border: 1px solid var(--border); background: var(--card); color: var(--text); text-decoration: none; font-weight: 700; font-size: 13px; }
        .imports-tabs a.active { background: var(--navy); color: #fff; border-color: var(--navy); }
        .imports-tabs .n { opacity: .7; font-weight: 600; margin-left: 4px; }
        .imports-filters, .imports-edit, .imports-bulk { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .imports-filters { margin-bottom: 12px; }
        .imports-edit { margin-top: 10px; }
        .imports-filters select, .imports-filters input, .imports-edit input[type=text], .imports-edit select { padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); font: inherit; background: #fff; }
        .imports-item { display: grid; grid-template-columns: 132px minmax(0, 1fr); gap: 14px; padding: 14px 0; border-top: 1px solid var(--border); }
        .imports-thumb, .imports-noimg { width: 132px; height: 96px; border-radius: 12px; }
        .imports-thumb { object-fit: cover; border: 1px solid var(--border); background: #eef1f6; }
        .imports-noimg { border: 1px dashed var(--border); display: grid; place-items: center; color: var(--muted); font-size: 12px; }
        .imports-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; background: rgba(10, 26, 79, .08); margin: 4px 4px 0 0; }
        .imports-tag.good { background: rgba(16, 185, 129, .14); color: #047857; }
        .imports-tag.warn { background: rgba(245, 158, 11, .16); color: #92400e; }
        .imports-desc { color: var(--muted); font-size: 13px; margin: 8px 0 4px; max-width: 95ch; overflow-wrap: anywhere; }
        .imports-change { max-width: 34ch; overflow-wrap: anywhere; font-size: 13px; }
        @media (max-width: 640px) { .imports-item { grid-template-columns: 1fr; } }
    </style>
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Open Data Imports</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../admin/admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
                <a href="../admin/admin_pending.php"><span class="dot"></span> Content Validation</a>
                <a class="active" href="../admin/admin_place_imports.php"><span class="dot"></span> Open Data Imports</a>
                <a href="../admin/admin_holidays.php"><span class="dot"></span> Public Holidays</a>
                <a href="../admin/user_manage/index.php"><span class="dot"></span> User Management</a>
                <a href="../admin/admin_reports.php"><span class="dot"></span> Reports</a>
                <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
            </nav>

            <div class="sidebar-footer">
                <div class="small">Logged in as:</div>
                <div style="margin-top:6px; font-weight:800;"><?php echo h($adminName); ?></div>
                <div class="chip">Role: Admin</div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1>Open Data Imports</h1>
                    <p>Places found automatically in OpenStreetMap, Wikipedia and Wikimedia Commons.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="../admin/admin_dashboard.php">Back</a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="card col-12" style="border-left:6px solid rgba(16,185,129,.7); margin-bottom:12px;">
                    <strong style="color:rgba(16,185,129,1);"><?php echo h($success); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="card col-12" style="border-left:6px solid rgba(239,68,68,.7); margin-bottom:12px;">
                    <strong style="color:rgba(239,68,68,1);"><?php echo h($errors[0]); ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($setupNeeded): ?>
                <div class="card col-12">
                    <h3>One-time setup needed</h3>
                    <p class="meta">The tables for imported places do not exist yet. Run
                        <code>database/migrations/20261111_open_place_data.sql</code> on the database, then start the first sync with
                        <code>php tools/sync_places.php --all</code>.</p>
                </div>
            <?php else: ?>
            <section class="grid">
                <div class="card col-12">
                    <details>
                        <summary style="cursor:pointer; font-weight:800;">
                            Sync status: <?php echo (int)$syncedStates; ?> of <?php echo count($states); ?> states synced,
                            <?php echo (int)$tabCounts["pending"]; ?> new places waiting
                        </summary>
                        <p class="meta" style="margin-top:10px;">
                            The sync runs on the server, not in the browser: <code>php tools/sync_places.php --all</code>
                            (or <code>--state="Melaka"</code> for one state). Well-documented places are published by
                            themselves; the rest wait here. Running it again never creates duplicates.
                        </p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>State</th>
                                        <th>Last sync</th>
                                        <th>Waiting</th>
                                        <th>Possible duplicates</th>
                                        <th>Published</th>
                                        <th>Lodging</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overview as $stateName => $o): ?>
                                        <tr>
                                            <td><a href="<?php echo h(imports_url(["tab" => "pending", "state" => $stateName])); ?>"><?php echo h($stateName); ?></a></td>
                                            <td>
                                                <?php if ($o["last"] === null): ?>
                                                    <span class="meta">never</span>
                                                <?php else: ?>
                                                    <?php echo h(date("j M Y, H:i", strtotime($o["last"]))); ?>
                                                    <?php if ($o["status"] !== "done"): ?><span class="imports-tag warn"><?php echo h($o["status"]); ?></span><?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int)$o["pending"]; ?></td>
                                            <td><?php echo (int)$o["duplicate"]; ?></td>
                                            <td><?php echo (int)$o["published"]; ?></td>
                                            <td><?php echo (int)$o["lodgings"]; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>

                <div class="card col-12">
                    <div class="imports-tabs">
                        <?php foreach ($tabs as $key => $label): ?>
                            <a class="<?php echo $key === $tab ? "active" : ""; ?>" href="<?php echo h(imports_url($filters, ["tab" => $key, "page" => 1])); ?>">
                                <?php echo h($label); ?><span class="n"><?php echo (int)$tabCounts[$key]; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <form method="get" class="imports-filters">
                        <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
                        <select name="state" aria-label="State">
                            <option value="">All states</option>
                            <?php foreach ($states as $s): ?>
                                <option value="<?php echo h($s); ?>" <?php echo $s === $filters["state"] ? "selected" : ""; ?>><?php echo h($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($tab !== "changes"): ?>
                            <select name="category" aria-label="Category">
                                <option value="">All categories</option>
                                <?php foreach (PlaceSyncService::CATEGORIES as $c): ?>
                                    <option value="<?php echo h($c); ?>" <?php echo $c === $filters["category"] ? "selected" : ""; ?>><?php echo h(ucfirst($c)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input type="search" name="q" value="<?php echo h($filters["q"]); ?>" placeholder="Search by name" aria-label="Search by name">
                        <button class="btn btn-ghost" type="submit">Filter</button>
                        <span class="meta"><?php echo (int)$total; ?> found</span>
                    </form>

                    <?php if (!$rows): ?>
                        <p class="meta">Nothing here<?php echo ($filters["state"] . $filters["category"] . $filters["q"]) !== "" ? " for these filters" : ""; ?>.</p>

                    <?php elseif ($tab === "changes"): ?>
                        <p class="meta">Details the sync filled in on places that were already in the knowledge base. A value is only
                            added where the place had none. Undo puts the old value back and stops the sync from filling it again.</p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Place</th>
                                        <th>Detail</th>
                                        <th>Before</th>
                                        <th>Now</th>
                                        <th>Source</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo h(date("j M Y", strtotime($row["created_at"]))); ?></td>
                                            <td>
                                                <a href="admin_cultural_kb.php?edit_id=<?php echo (int)$row["place_id"]; ?>"><?php echo h($row["place_name"]); ?></a>
                                                <div class="meta"><?php echo h($row["place_state"]); ?></div>
                                            </td>
                                            <td><?php echo h(str_replace("_", " ", $row["field"])); ?></td>
                                            <td class="imports-change"><?php echo h(mb_strimwidth((string)($row["old_value"] ?? ""), 0, 90, "...")) ?: "<span class=\"meta\">empty</span>"; ?></td>
                                            <td class="imports-change"><?php echo h(mb_strimwidth((string)($row["new_value"] ?? ""), 0, 160, "...")); ?></td>
                                            <td><?php echo imports_source($row["source"], $row["source_ref"]); ?></td>
                                            <td>
                                                <?php if ($row["reverted_at"] !== null): ?>
                                                    <span class="imports-tag warn">undone</span>
                                                <?php else: ?>
                                                    <form method="post">
                                                        <?php echo imports_hidden($filters); ?>
                                                        <input type="hidden" name="change_id" value="<?php echo (int)$row["change_id"]; ?>">
                                                        <button class="btn btn-ghost btn-small" type="submit" name="action" value="undo">Undo</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php else: ?>
                        <?php if ($tab === "pending" || $tab === "duplicate"): ?>
                            <form method="post" id="bulkForm" class="imports-bulk">
                                <?php echo imports_hidden($filters); ?>
                                <label style="display:flex; gap:6px; align-items:center; font-size:13px; font-weight:700;">
                                    <input type="checkbox" id="bulkAll"> Select all on this page
                                </label>
                                <button class="btn btn-primary btn-small" type="submit" name="action" value="bulk_publish">Publish selected</button>
                                <button class="btn btn-ghost btn-small" type="submit" name="action" value="bulk_reject">Reject selected</button>
                                <span class="meta">Up to 20 at a time. Publishing takes a few seconds per place.</span>
                            </form>
                        <?php endif; ?>

                        <?php foreach ($rows as $row): ?>
                            <?php
                            $p = json_decode((string)$row["payload_json"], true) ?: [];
                            $thumb = imports_thumb($p["image"] ?? null);
                            $open = $tab === "pending" || $tab === "duplicate";
                            $listId = "districts-" . preg_replace("/[^a-z]+/", "-", strtolower($row["state"]));
                            $fromWikipedia = ($p["description_source"] ?? "") === "wikipedia";
                            $fee = (float)($p["entrance_fee"] ?? 0);
                            ?>
                            <div class="imports-item">
                                <div>
                                    <?php if ($thumb !== ""): ?>
                                        <img class="imports-thumb" loading="lazy" src="<?php echo h($thumb); ?>" alt="">
                                    <?php else: ?>
                                        <div class="imports-noimg">No photo</div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label style="display:flex; gap:8px; align-items:center;">
                                        <?php if ($open): ?>
                                            <input type="checkbox" name="ids[]" value="<?php echo (int)$row["candidate_id"]; ?>" form="bulkForm" class="bulk-check">
                                        <?php endif; ?>
                                        <?php if ($tab === "published" && $row["published_place_id"] !== null): ?>
                                            <a href="admin_cultural_kb.php?edit_id=<?php echo (int)$row["published_place_id"]; ?>"><strong><?php echo h($row["name"]); ?></strong></a>
                                        <?php else: ?>
                                            <strong><?php echo h($row["name"]); ?></strong>
                                        <?php endif; ?>
                                    </label>
                                    <div class="meta">
                                        <?php echo h(ucfirst($row["category"])); ?> &middot;
                                        <?php echo h(trim(($row["district"] ?? "") . ", " . $row["state"], " ,")); ?> &middot;
                                        score <?php echo (int)$row["score"]; ?>
                                        <?php if ($tab === "published"): ?>
                                            &middot; <?php echo $row["reviewed_by_admin_id"] === null ? "published automatically" : "approved by an admin"; ?>
                                            <?php echo $row["reviewed_at"] ? "on " . h(date("j M Y", strtotime($row["reviewed_at"]))) : ""; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="imports-tag <?php echo $fromWikipedia ? "good" : ""; ?>"><?php echo $fromWikipedia ? "Wikipedia text" : "No article"; ?></span>
                                        <span class="imports-tag <?php echo !empty($p["opening_hours"]) ? "good" : ""; ?>"><?php echo !empty($p["opening_hours"]) ? "Opening hours" : "No opening hours"; ?></span>
                                        <span class="imports-tag"><?php echo $fee > 0 ? "Fee about RM " . h(number_format($fee, 2)) : "Free entry assumed"; ?></span>
                                        <span class="imports-tag"><?php echo (int)($p["visit_duration_min"] ?? 0); ?> min visit</span>
                                    </div>

                                    <p class="imports-desc"><?php echo h(mb_strimwidth((string)($p["description"] ?? ""), 0, 320, "...")); ?></p>
                                    <div class="meta">
                                        <?php
                                        $links = array_filter([
                                            imports_link($p["osm_url"] ?? "", "OpenStreetMap"),
                                            imports_link($p["wikipedia_url"] ?? "", "Wikipedia"),
                                            imports_link($p["website"] ?? "", "Website"),
                                            imports_link($p["image"]["page_url"] ?? "", "Photo: " . trim(($p["image"]["license"] ?? "") . " " . ($p["image"]["artist"] ?? ""))),
                                        ]);
                                        echo implode(" &middot; ", $links);
                                        ?>
                                    </div>

                                    <?php if ($row["duplicate_of_place_id"] !== null && $tab !== "published"): ?>
                                        <div style="border:1px solid rgba(245,158,11,.35); background:rgba(245,158,11,.08); border-radius:12px; padding:10px 12px; margin-top:10px; font-size:13px;">
                                            Looks like a place that is already listed:
                                            <a href="admin_cultural_kb.php?edit_id=<?php echo (int)$row["duplicate_of_place_id"]; ?>">
                                                #<?php echo (int)$row["duplicate_of_place_id"]; ?> <?php echo h($row["existing_name"] ?? "(removed)"); ?></a>.
                                            "Same place" keeps the listed one and lets the sync complete its details.
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($open): ?>
                                        <form method="post" class="imports-edit">
                                            <?php echo imports_hidden($filters); ?>
                                            <input type="hidden" name="candidate_id" value="<?php echo (int)$row["candidate_id"]; ?>">
                                            <input type="hidden" name="district_guess" value="<?php echo h($row["district"] ?? ""); ?>">
                                            <input type="text" name="name" value="<?php echo h($row["name"]); ?>" maxlength="150" style="min-width:min(320px, 100%);" aria-label="Name">
                                            <select name="category" aria-label="Category">
                                                <?php foreach (PlaceSyncService::CATEGORIES as $c): ?>
                                                    <option value="<?php echo h($c); ?>" <?php echo $c === $row["category"] ? "selected" : ""; ?>><?php echo h(ucfirst($c)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="district" value="<?php echo h($row["district"] ?? ""); ?>" maxlength="80" list="<?php echo h($listId); ?>" placeholder="District" aria-label="District">
                                            <button class="btn btn-primary btn-small" type="submit" name="action" value="publish"><?php echo $tab === "duplicate" ? "Publish as a new place" : "Publish"; ?></button>
                                            <?php if ($tab === "duplicate" && $row["existing_name"] !== null): ?>
                                                <button class="btn btn-ghost btn-small" type="submit" name="action" value="link">Same place</button>
                                            <?php endif; ?>
                                            <button class="btn btn-ghost btn-small" type="submit" name="action" value="reject">Reject</button>
                                        </form>
                                    <?php elseif ($tab === "rejected"): ?>
                                        <form method="post" class="imports-edit">
                                            <?php echo imports_hidden($filters); ?>
                                            <input type="hidden" name="candidate_id" value="<?php echo (int)$row["candidate_id"]; ?>">
                                            <button class="btn btn-ghost btn-small" type="submit" name="action" value="restore">Put back for review</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($pages > 1): ?>
                        <div class="imports-bulk" style="margin-top:14px;">
                            <?php if ($filters["page"] > 1): ?>
                                <a class="btn btn-ghost btn-small" href="<?php echo h(imports_url($filters, ["page" => $filters["page"] - 1])); ?>">Previous</a>
                            <?php endif; ?>
                            <span class="meta">Page <?php echo (int)$filters["page"]; ?> of <?php echo (int)$pages; ?></span>
                            <?php if ($filters["page"] < $pages): ?>
                                <a class="btn btn-ghost btn-small" href="<?php echo h(imports_url($filters, ["page" => $filters["page"] + 1])); ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php foreach ($districts as $stateName => $names): ?>
                <datalist id="districts-<?php echo h(preg_replace('/[^a-z]+/', "-", strtolower($stateName))); ?>">
                    <?php foreach ($names as $name): ?>
                        <option value="<?php echo h($name); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
    <script src="../assets/dashboard_shell.js?v=20261119a"></script>
    <script>
        (function () {
            var all = document.getElementById("bulkAll");
            if (!all) return;
            all.addEventListener("change", function () {
                document.querySelectorAll(".bulk-check").forEach(function (box) { box.checked = all.checked; });
            });
            document.getElementById("bulkForm").addEventListener("submit", function (event) {
                var chosen = document.querySelectorAll(".bulk-check:checked").length;
                if (chosen === 0) {
                    event.preventDefault();
                    alert("Select at least one place first.");
                } else if (chosen > 20) {
                    event.preventDefault();
                    alert("Select 20 places or fewer at a time.");
                }
            });
        })();
    </script>
</body>

</html>
