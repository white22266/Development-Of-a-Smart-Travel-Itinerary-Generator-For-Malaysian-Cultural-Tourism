<?php
// admin/admin_holidays.php
session_start();
require_once "../config/db_connect.php";
require_once "../services/PlannerCalendar.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminName = $_SESSION["admin_name"] ?? "Administrator";

// flash
$success = $_SESSION["success_message"] ?? "";
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$thisYear = (int)date("Y");
$year = (int)($_GET["year"] ?? $thisYear);
if ($year < 2000 || $year > 2100) $year = $thisYear;

// Entered holidays of the year, with their ids (the planner reads them through PlannerCalendar).
$entered = [];
$tableReady = false;
$check = $conn->query("SHOW TABLES LIKE 'public_holidays'");
if ($check && $check->num_rows > 0) {
    $tableReady = true;
    $from = sprintf("%04d-01-01", $year);
    $to = sprintf("%04d-12-31", $year);
    $stmt = $conn->prepare("SELECT holiday_id, holiday_date, name, states FROM public_holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $entered[] = $row;
    $stmt->close();
}

// The fixed holidays, flattened to rows.
$fixedRows = [];
foreach (PlannerCalendar::fixedHolidays($year) as $date => $list) {
    foreach ($list as [$name, $states]) {
        $fixedRows[] = ["date" => $date, "name" => $name, "states" => $states === null ? null : implode(", ", $states), "id" => 0];
    }
}
foreach ($entered as $row) {
    $fixedRows[] = [
        "date" => $row["holiday_date"],
        "name" => $row["name"],
        "states" => ($row["states"] === null || trim($row["states"]) === "") ? null : str_replace(",", ", ", $row["states"]),
        "id" => (int)$row["holiday_id"],
    ];
}
usort($fixedRows, fn($a, $b) => [$a["date"], $a["name"]] <=> [$b["date"], $b["name"]]);

$allStates = PlannerCalendar::knownStates();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Public Holidays | Admin</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Public Holidays</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../admin/admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
                <a href="../admin/admin_pending.php"><span class="dot"></span> Content Validation</a>
                <a href="../admin/admin_place_imports.php"><span class="dot"></span> Open Data Imports</a>
                <a class="active" href="../admin/admin_holidays.php"><span class="dot"></span> Public Holidays</a>
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
                    <h1>Public Holidays</h1>
                    <p>Enter the dates from the official announcement of public holidays.</p>
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
                <div class="card col-12">
                    <p class="meta" style="margin:0;">
                        The dates come from the official announcement of public holidays (most of them move every year).
                        The planner uses them for day notes, for places that keep office hours, and for peak hours.
                        Only holidays with a fixed date are built in; add the others here for each year.
                    </p>
                </div>

                <div class="card col-12">
                    <h3>Holidays in <?php echo (int)$year; ?></h3>

                    <form method="get" action="admin_holidays.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
                        <label for="year" style="font-size:13px; font-weight:800;">Year</label>
                        <select id="year" name="year" onchange="this.form.submit()" style="padding:8px 10px; border-radius:10px; border:1px solid rgba(15,23,42,0.10);">
                            <?php for ($y = $thisYear - 1; $y <= $thisYear + 3; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $year ? "selected" : ""; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                            <?php if ($year < $thisYear - 1 || $year > $thisYear + 3): ?>
                                <option value="<?php echo $year; ?>" selected><?php echo $year; ?></option>
                            <?php endif; ?>
                        </select>
                        <noscript><button class="btn btn-ghost" type="submit">Show</button></noscript>
                    </form>

                    <?php if (!$tableReady): ?>
                        <p class="meta">The table public_holidays does not exist yet. Run <code>php tools/migrate.php</code> first. Only the fixed holidays are shown.</p>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Name</th>
                                    <th>Applies to</th>
                                    <th>Source</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fixedRows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r["date"]); ?></td>
                                        <td><strong><?php echo htmlspecialchars($r["name"]); ?></strong></td>
                                        <td><?php echo $r["states"] === null ? "Whole country" : htmlspecialchars($r["states"]); ?></td>
                                        <td><?php echo $r["id"] === 0 ? "By rule" : "Entered"; ?></td>
                                        <td>
                                            <?php if ($r["id"] > 0): ?>
                                                <form method="post" action="admin_holidays_process.php" style="margin:0;"
                                                    onsubmit="return confirm('Delete this holiday?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="year" value="<?php echo (int)$year; ?>">
                                                    <input type="hidden" name="holiday_id" value="<?php echo (int)$r["id"]; ?>">
                                                    <button class="btn btn-ghost" type="submit">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card col-12">
                    <h3>Add a holiday</h3>
                    <?php if (!$tableReady): ?>
                        <p class="meta">Not available until the table exists.</p>
                    <?php else: ?>
                        <form method="post" action="admin_holidays_process.php" style="display:grid; gap:12px;">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="year" value="<?php echo (int)$year; ?>">

                            <div>
                                <label for="holiday_date" style="font-size:13px; font-weight:800;">Date</label><br>
                                <input id="holiday_date" type="date" name="holiday_date" required
                                    min="<?php echo (int)$year; ?>-01-01" max="<?php echo (int)$year; ?>-12-31"
                                    value="<?php echo (int)$year; ?>-01-01"
                                    style="padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div>
                                <label for="holiday_name" style="font-size:13px; font-weight:800;">Name</label><br>
                                <input id="holiday_name" type="text" name="name" required minlength="2" maxlength="120"
                                    placeholder="Example: Hari Raya Aidilfitri"
                                    style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10);">
                            </div>

                            <div>
                                <div style="font-size:13px; font-weight:800; margin-bottom:6px;">Applies to</div>
                                <label style="display:flex; gap:8px; align-items:center; font-size:13px;">
                                    <input type="radio" name="scope" value="all" checked> Whole country
                                </label>
                                <label style="display:flex; gap:8px; align-items:center; font-size:13px; margin-top:4px;">
                                    <input type="radio" name="scope" value="states"> Only these states
                                </label>
                                <div style="display:flex; gap:6px 16px; flex-wrap:wrap; margin:8px 0 0 24px;">
                                    <?php foreach ($allStates as $s): ?>
                                        <label style="display:flex; gap:6px; align-items:center; font-size:13px;">
                                            <input type="checkbox" name="states[]" value="<?php echo htmlspecialchars($s); ?>"> <?php echo htmlspecialchars($s); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <button class="btn btn-primary" type="submit">Add holiday</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
  <script src="../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>
