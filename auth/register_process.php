<?php
// auth/register_process.php
session_start();
require_once "../config/db_connect.php";

// Only accept POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: register.php");
    exit;
}

$errors = [];

// Public registration is TRAVELLER only
$role = "traveller";

$fullName = trim($_POST["full_name"] ?? "");
$email    = trim($_POST["email"] ?? "");
$phone    = trim($_POST["phone"] ?? ""); // traveller optional
$password = $_POST["password"] ?? "";
$confirm  = $_POST["confirm_password"] ?? "";

// Validation
if ($fullName === "" || $email === "" || $password === "" || $confirm === "") {
    $errors[] = "All required fields must be filled.";
}

if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
}

if ($password !== "" && strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters.";
}

if ($password !== $confirm) {
    $errors[] = "Password and confirm password do not match.";
}

// If validation failed -> back to register.php with messages
if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "role" => $role,
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone
    ];
    header("Location: register.php?role=" . urlencode($role));
    exit;
}

try {
    // Traveller: unique email
    $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ?");
    if (!$check) throw new Exception("Prepare failed (traveller check).");

    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();

        $_SESSION["form_errors"] = ["Traveller email already exists."];
        $_SESSION["old_input"] = [
            "role" => $role,
            "full_name" => $fullName,
            "email" => $email,
            "phone" => $phone
        ];
        header("Location: register.php?role=" . urlencode($role));
        exit;
    }
    $check->close();

    // Insert traveller
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)");
    if (!$stmt) throw new Exception("Prepare failed (traveller insert).");

    $stmt->bind_param("ssss", $fullName, $email, $passwordHash, $phone);

    if ($stmt->execute()) {
        $stmt->close();

        unset($_SESSION["old_input"]);
        $_SESSION["success_message"] = "Registration successful. Please login.";
        header("Location: login.php?role=traveller");
        exit;
    } else {
        $stmt->close();
        throw new Exception("Insert failed.");
    }
} catch (Exception $e) {
    $_SESSION["form_errors"] = ["Registration failed due to a system error. Please try again."];
    $_SESSION["old_input"] = [
        "role" => $role,
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone
    ];
    header("Location: register.php?role=" . urlencode($role));
    exit;
}
