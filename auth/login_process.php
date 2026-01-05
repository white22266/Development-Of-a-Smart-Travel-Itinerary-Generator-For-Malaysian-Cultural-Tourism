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
    $_SESSION["old_input"] = ["email" => $email, "role" => $role];
    header("Location: login.php?role=" . urlencode($role));
    exit;
}

try {
    if ($role === "admin") {
        // (keep your original admin logic)
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE email = ?");
    } else {
        // CHANGED: also select force_password_change (matches your DB schema)
        $stmt = $conn->prepare("
            SELECT traveller_id, full_name, password_hash, force_password_change
            FROM travellers
            WHERE email = ?
            LIMIT 1
        ");
    }

    if (!$stmt) {
        throw new Exception("Prepare failed.");
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();

    // NOTE: requires mysqlnd (XAMPP normally has it)
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row["password_hash"])) {
            session_regenerate_id(true);

            $_SESSION["logged_in"] = true;
            $_SESSION["role"]      = $role;

            unset($_SESSION["form_errors"], $_SESSION["old_input"]);

            if ($role === "admin") {
                $_SESSION["admin_id"]   = (int)$row["admin_id"];
                $_SESSION["admin_name"] = (string)$row["username"];
                $stmt->close();

                header("Location: ../admin/admin_dashboard.php");
                exit;
            } else {
                $_SESSION["traveller_id"]   = (int)$row["traveller_id"];
                $_SESSION["traveller_name"] = (string)$row["full_name"];

                // ADDED: store flag in session too
                $_SESSION["force_password_change"] = (int)($row["force_password_change"] ?? 0);

                $stmt->close();

                // ADDED: force redirect to change password page
                if ((int)($_SESSION["force_password_change"] ?? 0) === 1) {
                    header("Location: profile/profile.php?force=1");
                    exit;
                }

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

    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = ["email" => $email, "role" => $role];
    header("Location: login.php?role=" . urlencode($role));
    exit;
} catch (Throwable $e) { // FIXED: catch Throwable (includes Error / mysqli exceptions)
    error_log("Login error: " . $e->getMessage());
    $_SESSION["form_errors"] = ["Login failed due to a system error. Please try again."];
    $_SESSION["old_input"] = ["email" => $email, "role" => $role];
    header("Location: login.php?role=" . urlencode($role));
    exit;
}
