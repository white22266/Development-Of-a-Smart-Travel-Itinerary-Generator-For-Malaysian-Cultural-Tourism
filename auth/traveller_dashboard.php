<?php
session_start();
if (!isset($_SESSION["logged_in"]) || $_SESSION["role"] !== "traveller") {
    header("Location: ../auth/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Traveller Dashboard</title>
</head>

<body>
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION["traveller_name"]); ?> (Traveller)</h2>
    <p>This is the traveller dashboard placeholder.</p>
    <a href="../auth/logout.php">Logout</a>
</body>

</html>