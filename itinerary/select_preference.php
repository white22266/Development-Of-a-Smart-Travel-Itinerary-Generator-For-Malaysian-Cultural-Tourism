<?php
session_start();
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
$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT preference_id, trip_days, budget, transport_type, interests, preferred_states, created_at
  FROM traveller_preferences
  WHERE traveller_id = ?
  ORDER BY preference_id DESC
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
    <title>Select Preference</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
</head>

<body>
    <div class="app">
        <main class="content" style="padding:24px;">
            <h1>Smart Itinerary Generator</h1>
            <p class="meta">You must select a saved preference before generating.</p>

            <?php if (!empty($errors)): ?>
                <div class="card" style="border-left:6px solid rgba(239,68,68,.7);">
                    <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
                </div>
                <div style="height:12px;"></div>
            <?php endif; ?>

            <div class="card">
                <h3>Choose a Preference</h3>

                <?php if ($res->num_rows === 0): ?>
                    <p style="color:#ef4444; font-weight:800;">
                        No preference found. Please create one first.
                    </p>
                    <a class="btn btn-primary" href="../preference/preference_form.php">Go to Preference Analyzer</a>
                <?php else: ?>
                    <form method="post" action="generate_itinerary.php">
                        <label style="font-weight:800; font-size:13px;">Saved Preferences</label><br>
                        <select name="preference_id" required
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px;">
                            <option value="" disabled selected>— Select one preference —</option>
                            <?php while ($p = $res->fetch_assoc()): ?>
                                <option value="<?php echo (int)$p["preference_id"]; ?>">
                                    #<?php echo (int)$p["preference_id"]; ?> |
                                    <?php echo (int)$p["trip_days"]; ?> days |
                                    RM<?php echo number_format((float)$p["budget"], 2); ?> |
                                    <?php echo htmlspecialchars($p["transport_type"]); ?> |
                                    <?php echo htmlspecialchars($p["interests"]); ?> |
                                    <?php echo htmlspecialchars($p["preferred_states"] ?? "Malaysia"); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <div style="margin-top:12px;">
                            <button class="btn btn-primary" type="submit">Generate Itinerary</button>
                            <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>

</html>