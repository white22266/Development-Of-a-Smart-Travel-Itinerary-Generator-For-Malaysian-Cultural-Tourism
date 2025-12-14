<!-- role_select.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Choose Role - Smart Travel Itinerary Generator</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="main-container">
    <div class="left-panel">
        <h1>Choose Your Role</h1>
        <p>
            Administrators manage cultural content and user data.<br>
            Travellers use the system to generate and view cultural itineraries.
        </p>
    </div>
    <div class="right-panel">
        <h2 class="form-title">Who are you?</h2>
        <p class="form-subtitle">
            Select your role to continue with login or registration.
        </p>

        <div class="button-row">
            <!-- Admin goes to login page with role=admin -->
            <a href="auth/login.php?role=admin" class="btn btn-primary">Admin</a>
            <!-- Traveller goes to login page with role=traveller -->
            <a href="auth/login.php?role=traveller" class="btn btn-primary">Traveller</a>
        </div>

        <div class="form-footer" style="margin-top: 30px;">
            <span>Not sure? Go back to the </span>
            <a href="index.php">homepage</a>.
        </div>
    </div>
</div>
</body>
</html>
