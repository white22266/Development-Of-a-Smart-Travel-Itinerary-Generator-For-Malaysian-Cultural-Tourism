<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$success = $_SESSION["success_message"] ?? "";
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost, i.created_at,
         COALESCE(SUM(CASE WHEN ii.item_type NOT IN ('hotel','origin') THEN 1 ELSE 0 END), 0) AS place_count,
         GROUP_CONCAT(DISTINCT CASE WHEN ii.item_type = 'hotel' THEN ii.item_title END SEPARATOR ', ') AS selected_hotels
  FROM itineraries i
  LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.itinerary_id
  WHERE i.traveller_id = ?
  GROUP BY i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost, i.created_at
  ORDER BY i.itinerary_id DESC
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Itineraries</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .btn-danger {
            border: 1px solid rgba(220, 38, 38, 0.25);
            background: rgba(220, 38, 38, 0.08);
            color: #991B1B;
        }

        .btn-danger:hover {
            background: rgba(220, 38, 38, 0.12);
        }

        .itinerary-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .itinerary-table th,
        .itinerary-table td {
            vertical-align: middle;
        }

        .itinerary-table th:nth-child(1) { width: 26%; }
        .itinerary-table th:nth-child(2) { width: 9%; }
        .itinerary-table th:nth-child(3) { width: 5%; }
        .itinerary-table th:nth-child(4) { width: 6%; }
        .itinerary-table th:nth-child(5) { width: 20%; }
        .itinerary-table th:nth-child(6) { width: 8%; }
        .itinerary-table th:nth-child(7) { width: 9%; }
        .itinerary-table th:nth-child(8) { width: 17%; }

        .itinerary-title {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.35;
            max-width: 100%;
        }

        .date-cell,
        .number-cell,
        .money-cell,
        .created-cell {
            white-space: nowrap;
        }

        .number-cell,
        .money-cell {
            font-weight: 800;
        }

        .hotel-cell {
            min-width: 0;
        }

        .hotel-badge {
            display: inline-flex;
            align-items: flex-start;
            max-width: 100%;
            padding: 7px 10px;
            border-radius: 12px;
            border: 1px solid rgba(37, 99, 235, 0.14);
            background: rgba(37, 99, 235, 0.06);
            color: #1e3a8a;
            font-size: 12.5px;
            font-weight: 700;
            line-height: 1.35;
        }

        .hotel-name {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }

        .hotel-empty {
            color: #94a3b8;
            font-weight: 800;
        }

        .table-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .table-actions .btn,
        .table-actions button {
            min-width: 70px;
            justify-content: center;
            margin: 0;
        }

        .delete-form {
            display: inline-flex;
            margin: 0;
        }

        @media (max-width: 1100px) {
            .itinerary-table {
                min-width: 1050px;
            }
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
                    <span>Cost Estimation & Trip Summary</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
                <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
                <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
                <a href="../auth/profile/profile.php"><span class="dot"></span>Profile</a>
                <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
            </nav>

            <div class="sidebar-footer">
                <div class="small">Logged in as:</div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
                <div class="chip">Role: Traveller</div>
            </div>
        </aside>

        <main class="content" style="padding:24px;">

            <div class="topbar">
                <div class="page-title">
                    <h1>Cost Estimation & Trip Summary</h1>
                    <p class="meta">View saved itineraries and open details.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="select_preference.php">Generate New</a>
                    <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
                </div>
            </div>
            <section class="grid">
                <?php if ($success): ?>
                    <div class="card col-12" style="margin-bottom:12px; border-color: rgba(16,185,129,0.25); background: rgba(16,185,129,0.06); color: rgb(6,95,70); font-weight:800;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="card col-12" style="margin-bottom:12px; border-color: rgba(239,68,68,0.25); background: rgba(239,68,68,0.06); color: rgb(127,29,29);">
                        <ul style="margin:0 0 0 18px;">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>


                <div class="card col-12">
                    <h3>My Itineraries</h3>
                    <div class="table-wrap">
                        <table class="itinerary-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Start Date</th>
                                    <th>Days</th>
                                    <th>Places</th>
                                    <th>Hotel</th>
                                    <th>Total (RM)</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $res->fetch_assoc()): ?>
                                    <?php $hotelText = trim((string)($r["selected_hotels"] ?? "")); ?>
                                    <tr>
                                        <td>
                                            <strong class="itinerary-title" title="<?php echo htmlspecialchars($r["title"]); ?>">
                                                <?php echo htmlspecialchars($r["title"]); ?>
                                            </strong>
                                        </td>
                                        <td class="date-cell"><?php echo !empty($r["start_date"]) ? htmlspecialchars(date("d M Y", strtotime($r["start_date"]))) : "-"; ?></td>
                                        <td class="number-cell"><?php echo (int)$r["total_days"]; ?></td>
                                        <td class="number-cell"><?php echo (int)$r["place_count"]; ?></td>
                                        <td class="hotel-cell">
                                            <?php if ($hotelText !== ""): ?>
                                                <span class="hotel-badge" title="<?php echo htmlspecialchars($hotelText); ?>">
                                                    <span class="hotel-name"><?php echo htmlspecialchars($hotelText); ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="hotel-empty">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="money-cell"><?php echo number_format((float)$r["total_estimated_cost"], 2); ?></td>
                                        <td class="created-cell"><?php echo !empty($r["created_at"]) ? htmlspecialchars(date("d M Y", strtotime($r["created_at"]))) : "-"; ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>">View</a>
                                                <a class="btn btn-ghost" href="trip_summary.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>">Summary</a>
                                                <form method="post"
                                                    action="itinerary_delete.php"
                                                    class="delete-form"
                                                    onsubmit="return confirm('Delete this itinerary? This action cannot be undone.');">
                                                    <input type="hidden" name="itinerary_id" value="<?php echo (int)$r["itinerary_id"]; ?>">
                                                    <button type="submit" class="btn btn-ghost btn-danger">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($res->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="8">No itineraries yet.</td>
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