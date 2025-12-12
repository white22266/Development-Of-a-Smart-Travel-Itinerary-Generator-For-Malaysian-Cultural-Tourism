<?php
// auth/admin_register.php
session_start();
require_once "../config/db_connect.php";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"]);
    $email    = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm  = $_POST["confirm_password"];

    if ($username === "" || $email === "" || $password === "" || $confirm === "") {
        $errors[] = "All fields are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if ($password !== $confirm) {
        $errors[] = "Password and confirm password do not match.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "Username or email already exists.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare(
                "INSERT INTO admins (username, email, password_hash) VALUES (?,?,?)"
            );
            $insert->bind_param("sss", $username, $email, $password_hash);

            if ($insert->execute()) {
                $_SESSION["success_message"] = "Admin created successfully. Please login.";
                header("Location: login.php");
                exit;
            } else {
                $errors[] = "Error saving admin. Please try again.";
            }

            $insert->close();
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Admin Registration</title>
</head>

<body>
    <h2>Admin Registration</h2>

    <?php
    if (!empty($errors)) {
        echo "<ul style='color:red;'>";
        foreach ($errors as $e) {
            echo "<li>" . htmlspecialchars($e) . "</li>";
        }
        echo "</ul>";
    }
    ?>

    <form method="post" action="">
        <label>Username*:</label><br>
        <input type="text" name="username" required><br><br>

        <label>Email*:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password*:</label><br>
        <input type="password" name="password" required><br><br>

        <label>Confirm Password*:</label><br>
        <input type="password" name="confirm_password" required><br><br>

        <button type="submit">Create Admin</button>
    </form>

</body>

</html>