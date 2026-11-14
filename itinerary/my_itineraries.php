<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/i18n.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}
$travellerName = $_SESSION["traveller_name"] ?? t('common.traveller_default');
$success = $_SESSION["success_message"] ?? "";
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
         COALESCE(SUM(CASE WHEN ii.item_type NOT IN ('hotel','origin') THEN 1 ELSE 0 END), 0) AS place_count,
         GROUP_CONCAT(DISTINCT CASE WHEN ii.item_type = 'hotel' THEN ii.item_title END SEPARATOR ', ') AS selected_hotels
  FROM itineraries i
  LEFT JOIN itinerary_items ii ON ii.itinerary_id = i.itinerary_id
  WHERE i.traveller_id = ?
  GROUP BY i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost
  ORDER BY i.itinerary_id DESC
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo te('trips.title'); ?></title>
    <link rel="stylesheet" href="../assets/dashboard_style.css?v=20260617j">
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

        .itinerary-table th:nth-child(1) { width: 29%; }
        .itinerary-table th:nth-child(2) { width: 11%; }
        .itinerary-table th:nth-child(3) { width: 6%; }
        .itinerary-table th:nth-child(4) { width: 7%; }
        .itinerary-table th:nth-child(5) { width: 23%; }
        .itinerary-table th:nth-child(6) { width: 9%; }
        .itinerary-table th:nth-child(7) { width: 15%; }

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
        .date-cell {
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

        @media (max-width: 640px) {
            .itinerary-table {
                table-layout: auto;
            }

            .itinerary-title {
                -webkit-line-clamp: 3;
            }

            .hotel-badge {
                width: 100%;
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
                    <span><?php echo te('trips.heading'); ?></span>
                </div>
            </div>

            <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
                <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
                <a href="../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
                <a href="../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
                <a class="active" href="../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
                <a href="../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
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

        <main class="content" style="padding:24px;">

            <div class="topbar">
                <div class="page-title">
                    <h1><?php echo te('trips.heading'); ?></h1>
                    <p class="meta"><?php echo te('trips.meta'); ?></p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="select_preference.php"><?php echo te('trips.generate_new'); ?></a>
                    <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php"><?php echo te('common.back'); ?></a>
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
                    <h3><?php echo te('trips.title'); ?></h3>
                    <div class="table-wrap mobile-card-table-wrap">
                        <table class="itinerary-table mobile-card-table">
                            <thead>
                                <tr>
                                    <th><?php echo te('trips.col_title'); ?></th>
                                    <th><?php echo te('trips.col_start'); ?></th>
                                    <th><?php echo te('trips.col_days'); ?></th>
                                    <th><?php echo te('trips.col_places'); ?></th>
                                    <th><?php echo te('trips.col_hotel'); ?></th>
                                    <th><?php echo te('trips.col_total'); ?></th>
                                    <th><?php echo te('trips.col_action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $res->fetch_assoc()): ?>
                                    <?php $hotelText = trim((string)($r["selected_hotels"] ?? "")); ?>
                                    <tr>
                                        <td data-label="<?php echo te('trips.col_title'); ?>">
                                            <strong class="itinerary-title" title="<?php echo htmlspecialchars($r["title"]); ?>">
                                                <?php echo htmlspecialchars($r["title"]); ?>
                                            </strong>
                                        </td>
                                        <td class="date-cell" data-label="<?php echo te('trips.col_start'); ?>"><?php echo !empty($r["start_date"]) ? htmlspecialchars(date("d M Y", strtotime($r["start_date"]))) : "-"; ?></td>
                                        <td class="number-cell" data-label="<?php echo te('trips.col_days'); ?>"><?php echo (int)$r["total_days"]; ?></td>
                                        <td class="number-cell" data-label="<?php echo te('trips.col_places'); ?>"><?php echo (int)$r["place_count"]; ?></td>
                                        <td class="hotel-cell" data-label="<?php echo te('trips.col_hotel'); ?>">
                                            <?php if ($hotelText !== ""): ?>
                                                <span class="hotel-badge" title="<?php echo htmlspecialchars($hotelText); ?>">
                                                    <span class="hotel-name"><?php echo htmlspecialchars($hotelText); ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="hotel-empty">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="money-cell" data-label="<?php echo te('trips.col_total'); ?>"><?php echo number_format((float)$r["total_estimated_cost"], 2); ?></td>
                                        <td data-label="<?php echo te('trips.col_action'); ?>">
                                            <div class="table-actions">
                                                <a class="btn btn-ghost" href="itinerary_view.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>"><?php echo te('trips.view'); ?></a>
                                                <a class="btn btn-ghost" href="trip_summary.php?itinerary_id=<?php echo (int)$r["itinerary_id"]; ?>"><?php echo te('trips.summary'); ?></a>
                                                <form method="post"
                                                    action="itinerary_delete.php"
                                                    class="delete-form"
                                                    onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('trips.confirm_delete'), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);">
                                                    <input type="hidden" name="itinerary_id" value="<?php echo (int)$r["itinerary_id"]; ?>">
                                                    <button type="submit" class="btn btn-ghost btn-danger"><?php echo te('trips.delete'); ?></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($res->num_rows === 0): ?>
                                    <tr>
                                        <td class="mobile-empty" colspan="7"><?php echo te('trips.empty'); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

    </div>
  <script src="../assets/dashboard_shell.js?v=20260617c"></script>
</body>

</html>
