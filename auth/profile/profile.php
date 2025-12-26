<?php
// profile/profile.php
session_start();
require_once "../../config/db_connect.php";

// Access control
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$errors = [];
$success = "";

/* ---------- Load current profile ---------- */
$stmt = $conn->prepare("
    SELECT full_name, email, phone
    FROM travellers
    WHERE traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: ../traveller/traveller_dashboard.php");
    exit;
}

/* ---------- Handle update ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if ($fullName === "") $errors[] = "Full name is required.";
    if ($email === "") $errors[] = "Email is required.";

    if ($newPassword !== "" || $confirmPassword !== "") {
        if (strlen($newPassword) < 6) {
            $errors[] = "Password must be at least 6 characters.";
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = "Password confirmation does not match.";
        }
    }

    if (empty($errors)) {
        // check email uniqueness (exclude self)
        $stmt = $conn->prepare("
            SELECT traveller_id
            FROM travellers
            WHERE email = ? AND traveller_id != ?
            LIMIT 1
        ");
        $stmt->bind_param("si", $email, $travellerId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = "Email is already in use.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        if ($newPassword !== "") {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                UPDATE travellers
                SET full_name=?, email=?, phone=?, password_hash=?
                WHERE traveller_id=?
            ");
            $stmt->bind_param("ssssi", $fullName, $email, $phone, $hash, $travellerId);
        } else {
            $stmt = $conn->prepare("
                UPDATE travellers
                SET full_name=?, email=?, phone=?
                WHERE traveller_id=?
            ");
            $stmt->bind_param("sssi", $fullName, $email, $phone, $travellerId);
        }

        if ($stmt->execute()) {
            $success = "Profile updated successfully.";
            $_SESSION["traveller_name"] = $fullName; // update sidebar name
            $user["full_name"] = $fullName;
            $user["email"] = $email;
            $user["phone"] = $phone;
        } else {
            $errors[] = "Failed to update profile.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Profile | Smart Travel Itinerary Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../../assets/dashboard_style.css">
</head>

<body>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-badge">ST</div>
                <div class="brand-title">
                    <strong>Smart Travel Itinerary Generator</strong>
                    <span>Profile</span>
                </div>
            </div>

            <nav class="nav">
                <a href="../../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
                <a href="../../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
                <a href="../../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
                <a href="../../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
                <a href="../../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
                <a class="active" href="../../auth/profile/profile.php"><span class="dot"></span>Profile</a>
                <a href="../../auth/logout.php"><span class="dot"></span> Logout</a>
            </nav>






            <div class="sidebar-footer">
                <div class="small">Logged in as:</div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($_SESSION["traveller_name"]); ?></div>
                <div class="chip">Role: Traveller</div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1>Edit Profile</h1>
                    <p>Update your personal and login information.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back to Dashboard</a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <h3>Profile Details</h3>

                    <?php if ($success): ?>
                        <p style="color:green; font-weight:700;"><?php echo htmlspecialchars($success); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <ul style="color:red;">
                            <?php foreach ($errors as $e): ?>
                                <li><?php echo htmlspecialchars($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form method="post">
                        <label style="font-weight:700;">Full Name</label>
                        <input type="text" name="full_name" required
                            value="<?php echo htmlspecialchars($user["full_name"]); ?>"
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                        <div style="height:10px;"></div>

                        <label style="font-weight:700;">Email</label>
                        <input type="email" name="email" required
                            value="<?php echo htmlspecialchars($user["email"]); ?>"
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                        <div style="height:10px;"></div>

                        <label style="font-weight:700;">Phone</label>
                        <input type="text" name="phone"
                            value="<?php echo htmlspecialchars($user["phone"] ?? ""); ?>"
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                        <hr class="sep">

                        <h3 style="margin-bottom:6px;">Change Password (Optional)</h3>

                        <label>New Password</label>
                        <p style="font-size:12px; color:#64748B; margin:4px 0 8px;">
                            Password must be at least 6 characters.
                        </p>
                        <div style="position:relative;">
                            <input type="password" id="new_password" name="new_password"
                                placeholder="Leave blank to keep current password"
                                style="width:100%; padding:10px 40px 10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                            <span onclick="togglePassword('new_password', this)"
                                style="position:absolute; right:12px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:16px; color:#64748B;">
                                👁️
                            </span>
                        </div>

                        <div style="height:10px;"></div>

                        <label>Confirm New Password</label>
                        <div style="position:relative;">
                            <input type="password" id="confirm_password" name="confirm_password"
                                style="width:100%; padding:10px 40px 10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                            <span onclick="togglePassword('confirm_password', this)"
                                style="position:absolute; right:12px; top:50%; transform:translateY(-50%); cursor:pointer; font-size:16px; color:#64748B;">
                                👁️
                            </span>
                        </div>


                        <div style="margin-top:14px;">
                            <button class="btn btn-primary" type="submit">Save Changes</button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <script>
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "🙈";
            } else {
                input.type = "password";
                icon.textContent = "👁️";
            }
        }
    </script>

</body>

</html>