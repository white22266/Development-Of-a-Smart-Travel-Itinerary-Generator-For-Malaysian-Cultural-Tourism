<?php
// auth/register.php
session_start();

// role via URL
$defaultRole = (isset($_GET["role"]) && in_array($_GET["role"], ["admin", "traveller"], true))
    ? $_GET["role"]
    : "traveller";

// flash messages from register_process.php
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

        function togglePhoneField() {
            const roleSelect = document.getElementById("role");
            const phoneGroup = document.getElementById("phone-group");
            if (!roleSelect || !phoneGroup) return;

            phoneGroup.style.display = (roleSelect.value === "traveller") ? "block" : "none";
        }

        function banAlphabetInPhone() {
            const phone = document.getElementById("phone");
            if (!phone) return;

            // remove alphabets immediately
            phone.addEventListener("input", function() {
                const cleaned = phone.value.replace(/[A-Za-z]/g, "");
                if (cleaned !== phone.value) {
                    phone.value = cleaned;
                }
            });
        }

        function validatePhoneBeforeSubmit(e) {
            const role = document.getElementById("role");
            const phone = document.getElementById("phone");

            if (!role || !phone) return true;

            // only validate traveller phone (optional, but if filled must be valid)
            if (role.value === "traveller" && phone.value.trim() !== "") {
                const val = phone.value.trim();

                // block alphabets (just in case)
                if (/[A-Za-z]/.test(val)) {
                    alert("Phone number cannot contain alphabets.");
                    phone.focus();
                    e.preventDefault();
                    return false;
                }

                // basic MY phone formats: 0xxxxxxxxx or +60xxxxxxxxx, allow - and spaces
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
            togglePhoneField();
            banAlphabetInPhone();

            const roleSelect = document.getElementById("role");
            if (roleSelect) roleSelect.addEventListener("change", togglePhoneField);

            const form = document.getElementById("registerForm");
            if (form) form.addEventListener("submit", validatePhoneBeforeSubmit);
        });
    </script>
</head>

<body>



    <?php if (!empty($errors)): ?>
        <script>
            alert("Registration failed. Please check your inputs.");
        </script>
    <?php endif; ?>

    <div class="main-container">
        <div class="left-panel">
            <h1>Create Account</h1>
            <p>Register as a traveller to create and manage your itineraries and access cultural travel features.</p>
        </div>

        <div class="right-panel">
            <h2 class="form-title">Register</h2>
            <p class="form-subtitle">Fill in the details below to create your account.</p>

            <?php if (!empty($errors)): ?>
                <ul style="color:red; margin:0 0 12px 18px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form id="registerForm" method="post" action="register_process.php">
                <div class="form-group">
                    <label>Register as</label>

                    <!-- keep id="role" so your JS (togglePhoneField) still works -->
                    <select id="role" disabled>
                        <option value="traveller" selected>Traveller</option>
                    </select>

                    <!-- real value submitted to server -->
                    <input type="hidden" name="role" value="traveller">
                </div>

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
                    <label for="phone">Phone</label>
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

            <div class="form-footer">
                Already registered?
                <a href="login.php?role=<?php echo urlencode($defaultRole); ?>">Login here</a>
            </div>
        </div>
    </div>

</body>

</html>