<?php
// auth/register.php
session_start();
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/i18n.php';

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
<html lang="<?php echo i18n_lang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo te('auth.register_title'); ?> - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/style.css?v=20260617d">

    <script>
        const I18N = <?php echo i18n_js(['auth.show_password', 'auth.hide_password', 'auth.js_phone_alpha', 'auth.js_phone_format', 'auth.js_reg_failed']); ?>;

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
                    alert(I18N["auth.js_phone_alpha"]);
                    phone.focus();
                    e.preventDefault();
                    return false;
                }

                const normalized = val.replace(/\s+/g, "");
                const pattern = /^(?:\+?60|0)1\d[-]?\d{7,8}$/;

                if (!pattern.test(normalized)) {
                    alert(I18N["auth.js_phone_format"]);
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
            alert(I18N["auth.js_reg_failed"]);
        </script>
    <?php endif; ?>

    <div class="main-container">
        <div class="left-panel">
            <div class="left-copy">
                <div class="brand-mark">ST</div>
                <div class="entry-kicker"><?php echo te('auth.reg_kicker'); ?></div>
                <h1><?php echo te('auth.reg_heading'); ?></h1>
                <p>
                    <?php echo te('auth.reg_text'); ?>
                </p>
                <div class="entry-list compact-entry-list">
                    <div><?php echo te('auth.reg_list1'); ?></div>
                    <div><?php echo te('auth.reg_list2'); ?></div>
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
            <h2 class="form-title"><?php echo te('auth.reg_form_title'); ?></h2>
            <p class="form-subtitle"><?php echo te('auth.reg_form_subtitle'); ?></p>

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
                    <label for="full_name"><?php echo te('auth.full_name'); ?></label>
                    <input type="text" id="full_name" name="full_name" required
                        value="<?php echo htmlspecialchars($old["full_name"] ?? ""); ?>">
                </div>

                <div class="form-group">
                    <label for="email"><?php echo te('auth.email_address'); ?></label>
                    <input type="email" id="email" name="email" required
                        value="<?php echo htmlspecialchars($old["email"] ?? ""); ?>">
                </div>

                <div class="form-group" id="phone-group">
                    <label for="phone"><?php echo te('auth.phone'); ?></label>
                    <input type="tel" id="phone" name="phone"
                        placeholder="<?php echo te('auth.phone_placeholder'); ?>"
                        value="<?php echo htmlspecialchars($old["phone"] ?? ""); ?>">
                </div>

                <div class="form-group password-input-wrap">
                    <label for="password"><?php echo te('auth.password'); ?></label>
                    <input type="password" id="password" name="password" required>
                    <button type="button" class="password-toggle-icon" onclick="togglePassword('password', this)" aria-label="<?php echo te('auth.show_password'); ?>">👁️</button>
                </div>

                <div class="form-group password-input-wrap">
                    <label for="confirm_password"><?php echo te('auth.confirm_password'); ?></label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <button type="button" class="password-toggle-icon" onclick="togglePassword('confirm_password', this)" aria-label="<?php echo te('auth.show_password'); ?>">👁️</button>
                </div>

                <button type="submit" class="btn btn-primary"><?php echo te('auth.register_btn'); ?></button>
            </form>

            <div class="form-footer auth-account-switch">
                <?php echo te('auth.already'); ?>
                <a href="login.php?role=traveller"><?php echo te('auth.login_here'); ?></a>
            </div>
        </div>
    </div>
</body>

</html>
