<?php
// auth/login.php
session_start();
require_once "../config/db_connect.php";

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

                $_SESSION["success_message"] = "Account activated. You may login now.";
            } else {
                $_SESSION["form_errors"] = ["Activation link expired. Please register again."];
            }
        } else {
            $_SESSION["form_errors"] = ["Invalid activation link."];
        }
        $stmt->close();
    } else {
        $_SESSION["form_errors"] = ["Invalid activation link parameters."];
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

// view switch: login | forgot | reset
$view = "login";
if ($action === "forgot") $view = "forgot";
if ($action === "reset")  $view = "reset";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css">
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
</head>

<body>
    <div class="main-container">
        <div class="left-panel">
            <h1>Welcome Back</h1>
            <p>
                Sign in to continue managing cultural information (Admin) or
                to access your personalised travel itinerary (Traveller).
            </p>

            <?php if ($success): ?>
                <div style="color:green; font-weight:800; margin:12px 0 0;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="right-panel">

            <?php if (!empty($errors)): ?>
                <div style="color:red; font-weight:800; margin:10px 0 12px;">
                    <?php echo htmlspecialchars($errors[0]); ?>
                </div>
            <?php endif; ?>

            <?php if ($view === "login"): ?>
                <h2 class="form-title">Login</h2>
                <p class="form-subtitle">Please enter your email and password to login.</p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="login">

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            value="<?php echo htmlspecialchars($old["email"] ?? ""); ?>">
                    </div>

                    <div class="form-group" style="position:relative;">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required style="padding-right:40px;">
                        <span
                            onclick="togglePassword('password', this)"
                            style="position:absolute; right:12px; top:50%; transform:translateY(0%); cursor:pointer; font-size:16px; color:#64748B;">👁️</span>
                    </div>

                    <div class="form-group">
                        <label for="role">Login as</label>
                        <select id="role" name="role">
                            <option value="traveller" <?php echo $defaultRole === 'traveller' ? 'selected' : ''; ?>>Traveller</option>
                            <option value="admin" <?php echo $defaultRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Login</button>
                </form>

                <div style="margin-top:12px;">
                    <a href="login.php?action=forgot&role=<?php echo urlencode($defaultRole); ?>">Forgot password?</a>
                </div>

                <div class="form-footer" style="margin-top:14px;">
                    Haven’t registered?
                    <a href="register.php">Create an account</a>
                </div>

            <?php elseif ($view === "forgot"): ?>
                <h2 class="form-title">Forgot Password</h2>
                <p class="form-subtitle">Enter your email to receive a password reset link.</p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="request_reset">

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role">
                            <option value="traveller">Traveller</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Send Reset Link</button>
                </form>

                <div class="form-footer" style="margin-top:14px;">
                    <a href="login.php?role=<?php echo urlencode($defaultRole); ?>">Back to Login</a>
                </div>

            <?php else: /* reset */ ?>
                <h2 class="form-title">Reset Password</h2>
                <p class="form-subtitle">Set a new password for your account.</p>

                <form method="post" action="login_process.php">
                    <input type="hidden" name="mode" value="do_reset">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($_GET['role'] ?? 'traveller'); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">

                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6" style="padding-right:40px;">
                        <span onclick="togglePassword('new_password', this)"
                            style="position:absolute; right:12px; top:50%; transform:translateY(0%); cursor:pointer; font-size:16px; color:#64748B;">👁️</span>
                    </div>

                    <div class="form-group" style="position:relative;">
                        <label>Confirm New Password</label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password" required minlength="6" style="padding-right:40px;">
                        <span onclick="togglePassword('confirm_new_password', this)"
                            style="position:absolute; right:12px; top:50%; transform:translateY(0%); cursor:pointer; font-size:16px; color:#64748B;">👁️</span>
                    </div>

                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </form>

                <div class="form-footer" style="margin-top:14px;">
                    <a href="login.php?role=<?php echo urlencode($defaultRole); ?>">Back to Login</a>
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>

</html>