<?php
session_start();
if (!isset($_SESSION["logged_in"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
</head>

<body>
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION["admin_name"]); ?> (Admin)</h2>
    <p>This is the admin dashboard placeholder.</p>
    <a href="../auth/logout.php">Logout</a>
</body>

</html>