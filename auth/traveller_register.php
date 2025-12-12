<?php
// auth/traveller_register.php
session_start();
require_once "../config/db_connect.php";

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"]);
    $email     = trim($_POST["email"]);
    $password  = $_POST["password"];
    $confirm   = $_POST["confirm_password"];
    $phone     = trim($_POST["phone"]);

    // Basic validation
    if ($full_name === "" || $email === "" || $password === "" || $confirm === "") {
        $errors[] = "All fields marked * are required.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if ($password !== $confirm) {
        $errors[] = "Password and confirm password do not match.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if (empty($errors)) {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "Email is already registered.";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $insert = $conn->prepare(
                "INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)"
            );
            $insert->bind_param("ssss", $full_name, $email, $password_hash, $phone);

            if ($insert->execute()) {
                $_SESSION["success_message"] = "Registration successful. Please login.";
                header("Location: login.php");
                exit;
            } else {
                $errors[] = "Error saving data. Please try again.";
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
    <title>Traveller Registration</title>
</head>

<body>
    <h2>Traveller Registration</h2>

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
        <label>Full Name*:</label><br>
        <input type="text" name="full_name" required><br><br>

        <label>Email*:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password*:</label><br>
        <input type="password" name="password" required><br><br>

        <label>Confirm Password*:</label><br>
        <input type="password" name="confirm_password" required><br><br>

        <label>Phone:</label><br>
        <input type="text" name="phone"><br><br>

        <button type="submit">Register</button>
    </form>

    <p>Already have an account? <a href="login.php">Login here</a></p>
</body>

</html>