<?php
// auth/login_process.php
session_start();
require_once "../config/db_connect.php";

// Only accept POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

$errors = [];

$email    = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";
$role     = $_POST["role"] ?? "traveller"; // admin / traveller

if (!in_array($role, ["admin", "traveller"], true)) {
    $role = "traveller";
}

if ($email === "" || $password === "") {
    $errors[] = "Email and password are required.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
}

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "email" => $email,
        "role" => $role
    ];
    header("Location: login.php?role=" . urlencode($role));
    exit;
}

try {
    if ($role === "admin") {
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE email = ?");
    } else {
        $stmt = $conn->prepare("SELECT traveller_id, full_name, password_hash FROM travellers WHERE email = ?");
    }

    if (!$stmt) throw new Exception("Prepare failed.");

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row["password_hash"])) {
            session_regenerate_id(true);

            $_SESSION["logged_in"] = true;
            $_SESSION["role"]      = $role;

            // Clear old input/errors
            unset($_SESSION["form_errors"], $_SESSION["old_input"]);

            if ($role === "admin") {
                $_SESSION["admin_id"]   = (int)$row["admin_id"];
                $_SESSION["admin_name"] = $row["username"];
                $stmt->close();
                header("Location: ../admin/admin_dashboard.php");
                exit;
            } else {
                $_SESSION["traveller_id"]   = (int)$row["traveller_id"];
                $_SESSION["traveller_name"] = $row["full_name"];
                $stmt->close();
                header("Location: ../traveller/traveller_dashboard.php");
                exit;
            }
        } else {
            $errors[] = "Incorrect password.";
        }
    } else {
        $errors[] = "Account not found.";
    }

    $stmt->close();

    // Login failed -> back
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "email" => $email,
        "role" => $role
    ];
    header("Location: login.php?role=" . urlencode($role));
    exit;
} catch (Exception $e) {
    $_SESSION["form_errors"] = ["Login failed due to a system error. Please try again."];
    $_SESSION["old_input"] = [
        "email" => $email,
        "role" => $role
    ];
    header("Location: login.php?role=" . urlencode($role));
    exit;
}
