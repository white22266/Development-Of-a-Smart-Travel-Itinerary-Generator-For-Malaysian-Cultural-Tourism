<?php
// role_select.php
session_start();
require_once __DIR__ . "/config/db_connect.php";
require_once __DIR__ . "/config/i18n.php";
require_once __DIR__ . "/auth/remember_me.php";

if (restore_remembered_login($conn)) {
    if (($_SESSION["role"] ?? "") === "admin") {
        header("Location: admin/admin_dashboard.php");
        exit;
    }
    header("Location: traveller/traveller_dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo te('role.page_title'); ?> - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="assets/style.css?v=20260513">
</head>
<body>
<div class="main-container">
    <div class="left-panel">
        <div class="brand-mark">ST</div>
        <div class="entry-kicker"><?php echo te('auth.kicker_access'); ?></div>
        <h1><?php echo te('role.heading'); ?></h1>
        <p>
            <?php echo te('role.intro'); ?>
        </p>
        <div class="entry-list">
            <div><?php echo te('role.list_traveller'); ?></div>
            <div><?php echo te('role.list_admin'); ?></div>
        </div>
    </div>
    <div class="right-panel">
        <div class="lang-switch-wrap" style="text-align:right; margin-bottom:10px;"><?php echo i18n_switcher(); ?></div>
        <h2 class="form-title"><?php echo te('role.who'); ?></h2>
        <p class="form-subtitle">
            <?php echo te('role.subtitle'); ?>
        </p>

        <div class="role-grid">
            <a href="auth/login.php?role=traveller" class="role-card primary">
                <strong><?php echo te('role.traveller'); ?></strong>
                <span><?php echo te('role.traveller_desc'); ?></span>
            </a>
            <a href="auth/login.php?role=admin" class="role-card">
                <strong><?php echo te('role.admin'); ?></strong>
                <span><?php echo te('role.admin_desc'); ?></span>
            </a>
        </div>

        <div class="button-row">
            <a href="auth/register.php" class="btn btn-primary"><?php echo te('role.create_traveller'); ?></a>
            <a href="index.php" class="btn btn-outline"><?php echo te('role.back_home'); ?></a>
        </div>

        <div class="form-footer">
            <?php echo te('role.footer'); ?>
        </div>
    </div>
</div>
</body>
</html>
