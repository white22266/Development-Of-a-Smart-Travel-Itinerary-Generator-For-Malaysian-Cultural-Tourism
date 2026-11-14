<?php
// auth/login.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/i18n.php";
require_once __DIR__ . "/remember_me.php";

if (restore_remembered_login($conn)) {
    if (($_SESSION["role"] ?? "") === "admin") {
        header("Location: ../admin/admin_dashboard.php");
        exit;
    }
    header("Location: ../traveller/traveller_dashboard.php");
    exit;
}

$action = $_GET["action"] ?? "";

// --------- Handle activation link (no new file) ----------
if ($action === "activate") {
    $email = trim($_GET["email"] ?? "");
    $token = trim($_GET["token"] ?? "");

    if ($email !== "" && $token !== "" && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("
            SELECT traveller_id, activation_expires
            FROM travellers
            WHERE email=? AND activation_token=?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $email, $token);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $expires = $row["activation_expires"];
            if (!empty($expires) && strtotime($expires) >= time()) {
                $upd = $conn->prepare("
                    UPDATE travellers
                    SET is_active=1, activation_token=NULL, activation_expires=NULL
                    WHERE traveller_id=?
                    LIMIT 1
                ");
                $tid = (int)$row["traveller_id"];
                $upd->bind_param("i", $tid);
                $upd->execute();
                $upd->close();

                $_SESSION["success_message"] = t('auth.activated');
            } else {
                $_SESSION["form_errors"] = [t('auth.err_link_expired')];
            }
        } else {
            $_SESSION["form_errors"] = [t('auth.err_link_invalid')];
        }
        $stmt->close();
    } else {
        $_SESSION["form_errors"] = [t('auth.err_link_params')];
    }

    header("Location: login.php?role=traveller");
    exit;
}

// flash messages
$success = $_SESSION["success_message"] ?? "";
unset($_SESSION["success_message"]);

$errors = $_SESSION["form_errors"] ?? [];
$old    = $_SESSION["old_input"] ?? [];
unset($_SESSION["form_errors"], $_SESSION["old_input"]);

// optional: 通过 ?role=admin / ?role=traveller 预选角色
$defaultRole = $_GET['role'] ?? 'traveller';
if (!in_array($defaultRole, ["admin", "traveller"], true)) $defaultRole = "traveller";
$defaultRoleLabel = $defaultRole === "admin" ? t('role.admin') : t('role.traveller');

// view switch: login | forgot | reset
$view = "login";
if ($action === "forgot") $view = "forgot";
if ($action === "reset")  $view = "reset";
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo te('auth.login_title'); ?> - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css?v=20260617d">
    <script>
        const I18N = <?php echo i18n_js(['auth.show_password', 'auth.hide_password']); ?>;

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "🙈";
                icon.setAttribute("aria-label", I18N["auth.hide_password"]);
            } else {
                input.type = "password";
                icon.textContent = "👁️";
                icon.setAttribute("aria-label", I18N["auth.show_password"]);
            }
        }
    </script>
</head>

<body class="auth-entry-page login-page">
    <div class="main-container">
        <div class="left-panel">
            <div class="left-copy">
                <div class="brand-mark">ST</div>
                <div class="entry-kicker"><?php echo te('auth.kicker_access'); ?></div>
                <h1><?php echo te('auth.welcome_back'); ?></h1>
                <p>
                    <?php echo te('auth.welcome_text'); ?>
                </p>
                <div class="entry-list compact-entry-list">
                    <div><?php echo te('auth.welcome_list1'); ?></div>
                    <div><?php echo te('auth.welcome_list2'); ?></div>
                </div>

                <?php if ($success): ?>
                    <div style="color:green; font-weight:800; margin:12px 0 0;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
            </div>
            <a class="auth-back-link" href="../role_select.php" title="<?php echo te('auth.back_title'); ?>">&#8592; <?php echo te('common.back'); ?></a>
        </div>

        <div class="right-panel">
            <div class="lang-switch-wrap" style="text-align:right; margin-bottom:10px;"><?php echo i18n_switcher(); ?></div>
            <?php if (!empty($errors)): ?>
                <div style="color:red; font-weight:800; margin:10px 0 12px;">
                    <?php echo htmlspecialchars($errors[0]); ?>
                </div>
            <?php endif; ?>

            <?php if ($view === "login"): ?>
                <h2 class="form-title"><?php echo te('auth.login_title'); ?></h2>
                <p class="form-subtitle"><?php echo te('auth.login_subtitle'); ?></p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="login">

                    <div class="form-group">
                        <label for="email"><?php echo te('auth.email'); ?></label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            value="<?php echo htmlspecialchars($old["email"] ?? ""); ?>">
                    </div>

                    <div class="form-group password-input-wrap">
                        <label for="password"><?php echo te('auth.password'); ?></label>
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="password-toggle-icon" onclick="togglePassword('password', this)" aria-label="<?php echo te('auth.show_password'); ?>">👁️</button>
                    </div>

                    <div class="form-group">
                        <label><?php echo te('auth.login_as'); ?></label>
                        <input type="text" value="<?php echo htmlspecialchars($defaultRoleLabel); ?>" disabled>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($defaultRole); ?>">
                        <div class="small" style="margin-top:6px;">
                            <?php echo te('auth.role_help'); ?>
                        </div>
                    </div>

                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:14px;">
                        <input type="checkbox" name="remember_me" value="1" style="width:auto;">
                        <?php echo te('auth.remember_me'); ?>
                    </label>

                    <button type="submit" class="btn btn-primary"><?php echo te('auth.login_title'); ?></button>
                </form>

                <div class="auth-secondary-link" style="margin-top:12px;">
                    <a href="login.php?action=forgot&role=<?php echo urlencode($defaultRole); ?>"><?php echo te('auth.forgot_link'); ?></a>
                </div>

                <div class="form-footer auth-account-switch">
                    <?php echo te('auth.not_registered'); ?>
                    <a href="register.php"><?php echo te('auth.create_link'); ?></a>
                </div>

            <?php elseif ($view === "forgot"): ?>
                <h2 class="form-title"><?php echo te('auth.forgot_title'); ?></h2>
                <p class="form-subtitle"><?php echo te('auth.forgot_subtitle'); ?></p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="request_reset">

                    <div class="form-group">
                        <label><?php echo te('auth.email'); ?></label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo te('auth.role'); ?></label>
                        <input type="text" value="<?php echo htmlspecialchars($defaultRoleLabel); ?>" disabled>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($defaultRole); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo te('auth.send_reset'); ?></button>
                </form>

                <div class="form-footer" style="margin-top:14px;">
                    <a href="login.php?role=<?php echo urlencode($defaultRole); ?>"><?php echo te('auth.back_login'); ?></a>
                </div>

            <?php else: /* reset */ ?>
                <h2 class="form-title"><?php echo te('auth.reset_title'); ?></h2>
                <p class="form-subtitle"><?php echo te('auth.reset_subtitle'); ?></p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="do_reset">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($_GET['role'] ?? 'traveller'); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">

                    <div class="form-group password-input-wrap">
                        <label><?php echo te('auth.new_password'); ?></label>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                        <button type="button" class="password-toggle-icon" onclick="togglePassword('new_password', this)" aria-label="<?php echo te('auth.show_password'); ?>">👁️</button>
                    </div>

                    <div class="form-group password-input-wrap">
                        <label><?php echo te('auth.confirm_new_password'); ?></label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password" required minlength="6">
                        <button type="button" class="password-toggle-icon" onclick="togglePassword('confirm_new_password', this)" aria-label="<?php echo te('auth.show_password'); ?>">👁️</button>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo te('auth.reset_title'); ?></button>
                </form>

                <div class="form-footer" style="margin-top:14px;">
                    <a href="login.php?role=<?php echo urlencode($defaultRole); ?>"><?php echo te('auth.back_login'); ?></a>
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>

</html>
