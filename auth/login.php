<?php
// auth/login.php
session_start();
require_once "../config/db_connect.php";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["email"]);
    $password = $_POST["password"];
    $role     = $_POST["role"]; // 'admin' or 'traveller'

    if ($email === "" || $password === "") {
        $errors[] = "Email and password are required.";
    }

    if (empty($errors)) {
        if ($role === "admin") {
            $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE email = ?");
        } else {
            $stmt = $conn->prepare("SELECT traveller_id, full_name, password_hash FROM travellers WHERE email = ?");
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row["password_hash"])) {
                // login OK
                $_SESSION["logged_in"] = true;
                $_SESSION["role"]      = $role;

                if ($role === "admin") {
                    $_SESSION["admin_id"]  = $row["admin_id"];
                    $_SESSION["admin_name"] = $row["username"];
                    header("Location: ../dashboard/admin_dashboard.php");
                } else {
                    $_SESSION["traveller_id"]   = $row["traveller_id"];
                    $_SESSION["traveller_name"] = $row["full_name"];
                    header("Location: ../dashboard/traveller_dashboard.php");
                }
                exit;
            } else {
                $errors[] = "Incorrect password.";
            }
        } else {
            $errors[] = "Account not found.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>System Login</title>
</head>

<body>
    <h2>Login</h2>

    <?php
    if (isset($_SESSION["success_message"])) {
        echo "<p style='color:green;'>" . htmlspecialchars($_SESSION["success_message"]) . "</p>";
        unset($_SESSION["success_message"]);
    }

    if (!empty($errors)) {
        echo "<ul style='color:red;'>";
        foreach ($errors as $e) {
            echo "<li>" . htmlspecialchars($e) . "</li>";
        }
        echo "</ul>";
    }
    ?>

    <form method="post" action="">
        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <label>Login as:</label><br>
        <select name="role">
            <option value="traveller">Traveller</option>
            <option value="admin">Admin</option>
        </select><br><br>

        <button type="submit">Login</button>
    </form>

    <p>No traveller account? <a href="traveller_register.php">Register here</a></p>

</body>

</html>