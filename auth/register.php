<?php
// auth/register.php
session_start();
require_once __DIR__ . '/../config/security.php';

// Force traveller only (ignore ?role=admin)
$defaultRole = "traveller";

// flash messages
$errors  = $_SESSION["form_errors"] ?? [];
$old     = $_SESSION["old_input"] ?? [];
$success = $_SESSION["success_message"] ?? "";

// clear flash
unset($_SESSION["form_errors"], $_SESSION["old_input"], $_SESSION["success_message"]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Register - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css?v=20260513">

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

        function banAlphabetInPhone() {
            const phone = document.getElementById("phone");
            if (!phone) return;

            phone.addEventListener("input", function() {
                const cleaned = phone.value.replace(/[A-Za-z]/g, "");
                if (cleaned !== phone.value) phone.value = cleaned;
            });
        }

        function validatePhoneBeforeSubmit(e) {
            const phone = document.getElementById("phone");
            if (!phone) return true;

            if (phone.value.trim() !== "") {
                const val = phone.value.trim();

                if (/[A-Za-z]/.test(val)) {
                    alert("Phone number cannot contain alphabets.");
                    phone.focus();
                    e.preventDefault();
                    return false;
                }

                const normalized = val.replace(/\s+/g, "");
                const pattern = /^(?:\+?60|0)1\d[-]?\d{7,8}$/;

                if (!pattern.test(normalized)) {
                    alert("Invalid phone number format. Example: 012-3456789 or +60123456789");
                    phone.focus();
                    e.preventDefault();
                    return false;
                }
            }
            return true;
        }

        document.addEventListener("DOMContentLoaded", function() {
            banAlphabetInPhone();
            const form = document.getElementById("registerForm");
            if (form) form.addEventListener("submit", validatePhoneBeforeSubmit);
        });
    </script>
</head>

<body class="auth-entry-page register-page">
    <?php if (!empty($errors)): ?>
        <script>
            alert("Registration failed. Please check your inputs.");
        </script>
    <?php endif; ?>

    <div class="main-container">
        <div class="left-panel">
            <div class="left-copy">
                <div class="brand-mark">ST</div>
                <div class="entry-kicker">Traveller Registration</div>
                <h1>Create Account</h1>
                <p>
                    Register as a traveller to create and manage your itineraries
                    and access cultural travel features.
                </p>
                <div class="entry-list compact-entry-list">
                    <div>Email verification required before login</div>
                    <div>Saved trips, trip summary and AI assistant access</div>
                </div>
                <?php if ($success): ?>
                    <div style="color:green; font-weight:800; margin:12px 0 0;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
            </div>
            <a class="auth-back-link" href="../role_select.php" title="Back to Role Selection">&#8592; Back</a>
        </div>

        <div class="right-panel">
            <h2 class="form-title">Create Traveller Account</h2>
            <p class="form-subtitle">Traveller-only registration. Verify your email before using the system.</p>

            <?php if (!empty($errors)): ?>
                <ul style="color:red; margin:0 0 12px 18px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form id="registerForm" class="compact-register-form" method="post" action="register_process.php">
                <!-- Traveller only -->
                <input type="hidden" name="role" value="traveller">

                <div class="form-group">
                    <label for="full_name">Full Name / Username</label>
                    <input type="text" id="full_name" name="full_name" required
                        value="<?php echo htmlspecialchars($old["full_name"] ?? ""); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required
                        value="<?php echo htmlspecialchars($old["email"] ?? ""); ?>">
                </div>

                <div class="form-group" id="phone-group">
                    <label for="phone">Phone (optional)</label>
                    <input type="tel" id="phone" name="phone"
                        placeholder="e.g. 012-3456789 or +60123456789"
                        value="<?php echo htmlspecialchars($old["phone"] ?? ""); ?>">
                </div>

                <div class="form-group" style="position:relative;">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required style="padding-right:40px;">
                    <span onclick="togglePassword('password', this)"
                        style="position:absolute; right:12px; top:50%; transform:translateY(0%); cursor:pointer; font-size:16px; color:#64748B;">👁️</span>
                </div>

                <div class="form-group" style="position:relative;">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required style="padding-right:40px;">
                    <span onclick="togglePassword('confirm_password', this)"
                        style="position:absolute; right:12px; top:50%; transform:translateY(0%); cursor:pointer; font-size:16px; color:#64748B;">👁️</span>
                </div>

                <button type="submit" class="btn btn-primary">Register</button>
            </form>

            <div class="form-footer auth-account-switch">
                Already registered?
                <a href="login.php?role=traveller">Login here</a>
            </div>
        </div>
    </div>
</body>

</html>