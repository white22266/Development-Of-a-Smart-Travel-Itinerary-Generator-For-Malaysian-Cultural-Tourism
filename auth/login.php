<?php
// auth/login.php
session_start();
require_once "../config/db_connect.php";
$success = $_SESSION["success_message"] ?? "";
unset($_SESSION["success_message"]);

$errors = $_SESSION["form_errors"] ?? [];
$old    = $_SESSION["old_input"] ?? [];
unset($_SESSION["form_errors"], $_SESSION["old_input"]);

// optional: 通过 ?role=admin / ?role=traveller 预选角色
$defaultRole = isset($_GET['role']) ? $_GET['role'] : 'traveller';
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
            <h2 class="form-title">Login</h2>
            <p class="form-subtitle">Please enter your email and password to login.</p>

            <!-- show login error in red (first error only) -->
            <?php if (!empty($errors)): ?>
                <div style="color:red; font-weight:800; margin:10px 0 12px;">
                    <?php echo htmlspecialchars($errors[0]); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login_process.php">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-group" style="position:relative;">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required style="padding-right:40px;">
                    <span
                        onclick="togglePassword('password', this)"
                        style="
                            position:absolute;
                            right:12px;
                            top:50%;
                            transform:translateY(0%);
                            cursor:pointer;
                            font-size:16px;
                            color:#64748B;
                            ">👁️
                    </span>
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

            <div class="form-footer">
                Haven’t registered?
                <a href="register.php">Create an account</a>
            </div>
        </div>
    </div>
</body>

</html>