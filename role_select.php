<?php
// role_select.php
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
    <title>Choose Role - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="assets/style.css?v=20260513">
</head>
<body>
<div class="main-container">
    <div class="left-panel">
        <div class="brand-mark">ST</div>
        <div class="entry-kicker">Account Access</div>
        <h1>Choose Your Role</h1>
        <p>
            Select the correct access path for the system. Travellers generate itineraries, while administrators manage cultural data and reports.
        </p>
        <div class="entry-list">
            <div>Traveller: generate, save, review, export and share itineraries</div>
            <div>Admin: manage places, suggestions, users and reports</div>
        </div>
    </div>
    <div class="right-panel">
        <h2 class="form-title">Who are you?</h2>
        <p class="form-subtitle">
            Select your role to continue with login or registration.
        </p>

        <div class="role-grid">
            <a href="auth/login.php?role=traveller" class="role-card primary">
                <strong>Traveller</strong>
                <span>Create and manage cultural trip itineraries.</span>
            </a>
            <a href="auth/login.php?role=admin" class="role-card">
                <strong>Admin</strong>
                <span>Manage cultural content, users, suggestions and reports.</span>
            </a>
        </div>

        <div class="button-row">
            <a href="auth/register.php" class="btn btn-primary">Create Traveller Account</a>
            <a href="index.php" class="btn btn-outline">Back to Home</a>
        </div>

        <div class="form-footer">
            New traveller? Register and verify your account before using the system.
        </div>
    </div>
</div>
</body>
</html>
