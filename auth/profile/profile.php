<?php
// auth/profile/profile.php
session_start();
require_once "../../config/db_connect.php";
require_once "../../config/i18n.php";

// Access control
// CHANGED: fix path (was ../auth/login.php which becomes /auth/auth/login.php)
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../login.php?role=traveller");
    exit;
}

$errors = [];
$success = "";

/* ---------- Load current profile ---------- */
// CHANGED: load force_password_change
$stmt = $conn->prepare("
    SELECT full_name, email, phone, force_password_change, is_active, activation_token
    FROM travellers
    WHERE traveller_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: ../../traveller/traveller_dashboard.php");
    exit;
}

// ADDED: force mode when flag=1 or URL force=1
$forceMode = ((int)($user["force_password_change"] ?? 0) === 1) || (($_GET["force"] ?? "") === "1");

/* ---------- Handle update ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    // ADDED: in force mode, require password change
    if ($forceMode) {
        if ($newPassword === "" || $confirmPassword === "") {
            $errors[] = t('prof.err_force_pw');
        } else {
            if (strlen($newPassword) < 6) $errors[] = t('prof.pw_min');
            if ($newPassword !== $confirmPassword) $errors[] = t('prof.err_pw_match');
        }

        if (empty($errors)) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            // ADDED: clear force_password_change after successful update
            $stmt = $conn->prepare("
                UPDATE travellers
                SET password_hash = ?, force_password_change = 0
                WHERE traveller_id = ?
            ");
            $stmt->bind_param("si", $hash, $travellerId);

            if ($stmt->execute()) {
                $_SESSION["force_password_change"] = 0;
                $stmt->close();

                // CHANGED: after forced change, redirect to dashboard (or same profile page)
                header("Location: ../../traveller/traveller_dashboard.php");
                exit;
            } else {
                $errors[] = t('prof.err_pw_update');
            }
            $stmt->close();
        }
    } else {
        // Normal profile update mode (not forced)

        if ($fullName === "") $errors[] = t('prof.err_name');

        if ($newPassword !== "" || $confirmPassword !== "") {
            if (strlen($newPassword) < 6) $errors[] = t('prof.pw_min');
            if ($newPassword !== $confirmPassword) $errors[] = t('prof.err_pw_match');
        }

        if (empty($errors)) {
            if ($newPassword !== "") {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                // CHANGED: also ensure force_password_change stays 0 in normal change
                $stmt = $conn->prepare("
                    UPDATE travellers
                    SET full_name=?, phone=?, password_hash=?, force_password_change=0
                    WHERE traveller_id=?
                ");
                $stmt->bind_param("sssi", $fullName, $phone, $hash, $travellerId);
            } else {
                $stmt = $conn->prepare("
                    UPDATE travellers
                    SET full_name=?, phone=?
                    WHERE traveller_id=?
                ");
                $stmt->bind_param("ssi", $fullName, $phone, $travellerId);
            }

            if ($stmt->execute()) {
                $success = t('prof.saved');
                $_SESSION["traveller_name"] = $fullName;

                // Refresh local copy
                $user["full_name"] = $fullName;
                $user["phone"] = $phone;
            } else {
                $errors[] = t('prof.err_update');
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo te('prof.title'); ?> | Smart Travel Itinerary Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../../assets/dashboard_style.css?v=20260617j">
    <style>
        .readonly-field {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(15,23,42,.1);
            background: #f8fafc;
            color: #475569;
        }
        .verify-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .verify-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
        }
        .verify-badge.ok {
            background: rgba(34,197,94,.12);
            color: #166534;
            border: 1px solid rgba(34,197,94,.28);
        }
        .verify-badge.warn {
            background: rgba(245,158,11,.12);
            color: #92400e;
            border: 1px solid rgba(245,158,11,.30);
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
                    <span><?php echo te('nav.profile'); ?></span>
                </div>
            </div>

            <nav class="nav" aria-label="<?php echo te('nav.aria'); ?>">
                <a href="../../traveller/traveller_dashboard.php"><span class="dot"></span> <?php echo te('nav.dashboard'); ?></a>
                <a href="../../preference/preference_form.php"><span class="dot"></span> <?php echo te('nav.preference'); ?></a>
                <a href="../../itinerary/select_preference.php"><span class="dot"></span> <?php echo te('nav.generator'); ?></a>
                <a href="../../itinerary/my_itineraries.php"><span class="dot"></span> <?php echo te('nav.trips'); ?></a>
                <a href="../../cultural/cultural_guide.php"><span class="dot"></span> <?php echo te('nav.guide'); ?></a>
                <a class="active" href="../../auth/profile/profile.php"><span class="dot"></span> <?php echo te('nav.profile'); ?></a>
                <a href="../../auth/logout.php"><span class="dot"></span> <?php echo te('nav.logout'); ?></a>
            </nav>

            <div class="lang-switch-wrap" style="margin:12px 14px;"><?php echo i18n_switcher(); ?></div>

            <div class="sidebar-footer">
                <div class="small"><?php echo te('nav.logged_in_as'); ?></div>
                <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($_SESSION["traveller_name"]); ?></div>
                <div class="chip"><?php echo te('nav.role_traveller'); ?></div>
            </div>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="page-title">
                    <h1><?php echo $forceMode ? te('prof.title_pw') : te('prof.title'); ?></h1>
                    <p>
                        <?php echo $forceMode
                            ? te('prof.sub_force')
                            : te('prof.sub'); ?>
                    </p>
                </div>
                <div class="actions">
                    <!-- CHANGED: fix path (was ../traveller/... which is wrong from /auth/profile/) -->
                    <a class="btn btn-ghost" href="../../traveller/traveller_dashboard.php"><?php echo te('common.back_dashboard'); ?></a>
                </div>
            </div>

            <section class="grid">
                <div class="card col-12">
                    <h3><?php echo $forceMode ? te('prof.h_set_pw') : te('prof.h_details'); ?></h3>

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

                    <?php if ($forceMode): ?>
                        <p style="color:#b45309; font-weight:700;">
                            <?php echo te('prof.force_note'); ?>
                        </p>
                    <?php endif; ?>

                    <form method="post">
                        <label style="font-weight:700;"><?php echo te('prof.full_name'); ?></label>
                        <input type="text" name="full_name" required
                            value="<?php echo htmlspecialchars($user["full_name"]); ?>"
                            <?php echo $forceMode ? "readonly" : ""; ?>
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                        <div style="height:10px;"></div>

                        <label style="font-weight:700;"><?php echo te('prof.email'); ?></label>
                        <input type="email" class="readonly-field" readonly
                            value="<?php echo htmlspecialchars($user["email"]); ?>"
                            aria-describedby="emailVerificationStatus">
                        <div class="verify-row" id="emailVerificationStatus">
                            <?php if ((int)($user["is_active"] ?? 0) === 1): ?>
                                <span class="verify-badge ok"><?php echo te('prof.verified'); ?></span>
                            <?php else: ?>
                                <span class="verify-badge warn"><?php echo te('prof.not_verified'); ?></span>
                            <?php endif; ?>
                            <span style="font-size:12px; color:#64748B;"><?php echo te('prof.email_help'); ?></span>
                        </div>

                        <div style="height:10px;"></div>

                        <label style="font-weight:700;"><?php echo te('prof.phone'); ?></label>
                        <input type="text" name="phone"
                            value="<?php echo htmlspecialchars($user["phone"] ?? ""); ?>"
                            <?php echo $forceMode ? "readonly" : ""; ?>
                            style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                        <hr class="sep">

                        <h3 style="margin-bottom:6px;"><?php echo $forceMode ? te('prof.h_change_pw') : te('prof.h_change_pw_opt'); ?></h3>

                        <label><?php echo te('prof.new_pw'); ?></label>
                        <p style="font-size:12px; color:#64748B; margin:4px 0 8px;">
                            <?php echo te('prof.pw_min'); ?>
                        </p>
                        <div class="password-input-wrap">
                            <input type="password" id="new_password" name="new_password"
                                placeholder="<?php echo $forceMode ? te('prof.ph_required') : te('prof.ph_keep'); ?>"
                                style="width:100%; padding:10px 40px 10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                            <button type="button" class="password-toggle-icon" onclick="togglePassword('new_password', this)" aria-label="<?php echo te('prof.show_pw'); ?>">👁️</button>
                        </div>

                        <div style="height:10px;"></div>

                        <label><?php echo te('prof.confirm_pw'); ?></label>
                        <div class="password-input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password"
                                style="width:100%; padding:10px 40px 10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,.1);">

                            <button type="button" class="password-toggle-icon" onclick="togglePassword('confirm_password', this)" aria-label="<?php echo te('prof.show_pw'); ?>">👁️</button>
                        </div>

                        <div style="margin-top:14px;">
                            <button class="btn btn-primary" type="submit">
                                <?php echo $forceMode ? te('prof.btn_update_pw') : te('prof.btn_save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <script>
        const T = <?php echo i18n_js(['prof.show_pw', 'prof.hide_pw']); ?>;
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "🙈";
                icon.setAttribute("aria-label", T["prof.hide_pw"]);
            } else {
                input.type = "password";
                icon.textContent = "👁️";
                icon.setAttribute("aria-label", T["prof.show_pw"]);
            }
        }
    </script>
  <script src="../../assets/dashboard_shell.js?v=20261119a"></script>
</body>

</html>

