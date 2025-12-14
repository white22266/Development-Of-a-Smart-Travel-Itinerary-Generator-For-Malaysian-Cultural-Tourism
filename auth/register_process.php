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

// Read inputs
$role     = $_POST["role"] ?? "traveller"; // admin / traveller
$fullName = trim($_POST["full_name"] ?? "");
$email    = trim($_POST["email"] ?? "");
$phone    = trim($_POST["phone"] ?? ""); // traveller optional
$password = $_POST["password"] ?? "";
$confirm  = $_POST["confirm_password"] ?? "";

// Validate role
if (!in_array($role, ["admin", "traveller"], true)) {
    $role = "traveller";
}

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

// Check duplicate + Insert
try {
    // Check duplicates in correct table
    if ($role === "admin") {
        // Admin: unique email and username
        $check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? OR username = ?");
        if (!$check) throw new Exception("Prepare failed (admin check).");

        $check->bind_param("ss", $email, $fullName);
    } else {
        // Traveller: unique email
        $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ?");
        if (!$check) throw new Exception("Prepare failed (traveller check).");

        $check->bind_param("s", $email);
    }

    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();

        $_SESSION["form_errors"] = [
            ($role === "admin")
                ? "Admin username or email already exists."
                : "Traveller email already exists."
        ];
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

    // Insert
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($role === "admin") {
        $stmt = $conn->prepare("INSERT INTO admins (username, email, password_hash) VALUES (?,?,?)");
        if (!$stmt) throw new Exception("Prepare failed (admin insert).");

        $stmt->bind_param("sss", $fullName, $email, $passwordHash);
    } else {
        $stmt = $conn->prepare("INSERT INTO travellers (full_name, email, password_hash, phone) VALUES (?,?,?,?)");
        if (!$stmt) throw new Exception("Prepare failed (traveller insert).");

        $stmt->bind_param("ssss", $fullName, $email, $passwordHash, $phone);
    }

    if ($stmt->execute()) {
        $stmt->close();

        // Clear saved old input
        unset($_SESSION["old_input"]);

        $_SESSION["success_message"] = "Registration successful. Please login.";
        header("Location: login.php?role=" . urlencode($role));
        exit;
    } else {
        $stmt->close();
        throw new Exception("Insert failed.");
    }

} catch (Exception $e) {
    // Do not expose system details to users (safe error)
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
