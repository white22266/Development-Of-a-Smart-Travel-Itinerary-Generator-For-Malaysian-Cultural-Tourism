<?php
// index.php
session_start();
require_once __DIR__ . "/config/db_connect.php";
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Smart Travel Itinerary Generator - Demo</title>
    <link rel="stylesheet" href="assets/style.css?v=20260513">
</head>

<body>
    <div class="main-container">
        <div class="left-panel">
            <div class="brand-mark">ST</div>
            <div class="entry-kicker">Malaysian Cultural Tourism</div>
            <h1>Smart Travel Itinerary Generator</h1>
            <p>
                A web-based system developed to support Malaysian cultural tourism by
                automatically generating structured travel itineraries based on user
                preferences such as budget, travel duration, and cultural interests.
            </p>
            <div class="entry-list">
                <div>Preference-based itinerary generation</div>
                <div>Route map, cost summary, hotel and food support</div>
                <div>Local Ollama AI assistant for itinerary refinement</div>
            </div>
        </div>
        <div class="right-panel">
            <h2 class="form-title">Welcome</h2>
            <p class="form-subtitle">
                Please login or register first. System features are available only to verified users.
            </p>
            <div class="button-row">
                <a href="role_select.php" class="btn btn-primary">Login / Register</a>
                <a href="auth/register.php" class="btn btn-outline">Create Traveller Account</a>
            </div>
        </div>
    </div>
</body>

</html>
