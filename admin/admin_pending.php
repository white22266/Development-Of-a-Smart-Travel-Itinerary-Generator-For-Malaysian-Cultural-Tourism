<?php
// admin/admin_pending.php
session_start();
require_once "../config/db_connect.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminName = $_SESSION["admin_name"] ?? "Administrator";

// flash
$success = $_SESSION["success_message"] ?? "";
$errors  = $_SESSION["form_errors"] ?? [];
unset($_SESSION["success_message"], $_SESSION["form_errors"]);

$viewId = (int)($_GET["view_id"] ?? 0);
$viewRow = null;

if ($viewId > 0) {
    $stmt = $conn->prepare("
    SELECT s.*, t.full_name AS traveller_name
    FROM cultural_place_suggestions s
    LEFT JOIN travellers t ON t.traveller_id = s.traveller_id
    WHERE s.suggestion_id = ?
    LIMIT 1
  ");
    $stmt->bind_param("i", $viewId);
    $stmt->execute();
    $viewRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$stmt = $conn->prepare("
  SELECT suggestion_id, state, category, name, created_at
  FROM cultural_place_suggestions
  WHERE status='pending'
  ORDER BY suggestion_id DESC
");
$stmt->execute();
$list = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Content Validation | Admin</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Admin Dashboard</span>
                </div>
            </div>

            <nav class="nav" aria-label="Sidebar Navigation">
                <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../admin/admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
                <a class="active" href="../admin/admin_pending.php"><span class="dot"></span> Content Validation</a>
                <a href="../admin/user_manage/index.php"><span class="dot"></span> User Management</a>
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
                    <h1>Content Validation</h1>
                    <p>Review traveller suggestions before publishing to Knowledge Base.</p>
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
                    <h3>Pending Suggestions</h3>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>State</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($r = $list->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo (int)$r["suggestion_id"]; ?></td>
                                        <td><?php echo htmlspecialchars($r["state"]); ?></td>
                                        <td><strong><?php echo htmlspecialchars($r["name"]); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r["category"]); ?></td>
                                        <td><?php echo htmlspecialchars($r["created_at"]); ?></td>
                                        <td>
                                            <a class="btn btn-ghost" href="admin_pending.php?view_id=<?php echo (int)$r["suggestion_id"]; ?>">View</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if ($list->num_rows === 0): ?>
                                    <tr>
                                        <td colspan="6">No pending items.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card col-12">
                    <h3>Review Detail</h3>

                    <?php if (!$viewRow): ?>
                        <p class="meta">Select a pending suggestion from the list.</p>
                    <?php else: ?>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($viewRow["name"]); ?></p>
                        <p><strong>State:</strong> <?php echo htmlspecialchars($viewRow["state"]); ?></p>
                        <p><strong>Category:</strong> <?php echo htmlspecialchars($viewRow["category"]); ?></p>
                        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($viewRow["description"] ?? "-")); ?></p>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($viewRow["address"] ?? "-"); ?></p>
                        <p><strong>Lat/Lng:</strong> <?php echo htmlspecialchars(($viewRow["latitude"] ?? "-") . " / " . ($viewRow["longitude"] ?? "-")); ?></p>
                        <p><strong>Opening:</strong> <?php echo htmlspecialchars($viewRow["opening_hours"] ?? "-"); ?></p>
                        <p><strong>Estimated Cost:</strong> RM <?php echo number_format((float)($viewRow["estimated_cost"] ?? 0), 2); ?></p>

                        <?php if (!empty($viewRow["image_url"])): ?>
                            <div style="margin-top:10px;">
                                <img src="../<?php echo htmlspecialchars($viewRow["image_url"]); ?>"
                                    style="max-width:100%; max-height:240px; border-radius:12px; border:1px solid rgba(15,23,42,0.15);"
                                    alt="suggested image">
                            </div>
                        <?php endif; ?>

                        <hr class="sep">

                        <form method="post" action="admin_pending_process.php" style="display:grid; gap:10px;">
                            <input type="hidden" name="suggestion_id" value="<?php echo (int)$viewRow["suggestion_id"]; ?>">

                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <button class="btn btn-primary" type="submit" name="action" value="approve"
                                    onclick="return confirm('Approve & publish to Knowledge Base?');">Approve</button>

                                <button class="btn btn-ghost" type="submit" name="action" value="reject"
                                    onclick="return confirm('Reject this suggestion?');">Reject</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>

</html>