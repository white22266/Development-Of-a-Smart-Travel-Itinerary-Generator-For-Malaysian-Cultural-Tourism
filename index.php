<?php
// index.php
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="assets/style.css?v=20260617c">
</head>

<body class="entry-home-page">
    <div class="main-container">
        <div class="left-panel">
            <div class="brand-mark">ST</div>
            <div class="entry-kicker"><?php echo te('home.kicker'); ?></div>
            <h1>Smart Travel Itinerary Generator</h1>
            <p>
                <?php echo te('home.intro'); ?>
            </p>
            <div class="entry-list">
                <div><?php echo te('home.feature1'); ?></div>
                <div><?php echo te('home.feature2'); ?></div>
                <div><?php echo te('home.feature3'); ?></div>
            </div>
        </div>
        <div class="right-panel">
            <div class="lang-switch-wrap" style="text-align:right; margin-bottom:10px;"><?php echo i18n_switcher(); ?></div>
            <h2 class="form-title"><?php echo te('home.welcome'); ?></h2>
            <p class="form-subtitle">
                <?php echo te('home.subtitle'); ?>
            </p>

            <div class="welcome-panel">
                <p>
                    <?php echo te('home.panel_text'); ?>
                </p>
                <div class="button-row">
                    <a href="role_select.php" class="btn btn-primary"><?php echo te('home.start'); ?></a>
                    <a href="auth/register.php" class="btn btn-outline"><?php echo te('home.create'); ?></a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
