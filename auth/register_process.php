<?php
// auth/register_process.php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once __DIR__ . "/../vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;

function sendSmtpMail(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->Subject = $subject;
        $mail->Body    = $body;

        return $mail->send();
    } catch (Throwable $e) {
        error_log("SMTP send failed (register): " . $e->getMessage());
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: register.php");
    exit;
}

$errors = [];

// FORCE traveller only (ignore any posted role)
$role = "traveller";

$fullName = trim($_POST["full_name"] ?? "");
$email    = trim($_POST["email"] ?? "");
$phone    = trim($_POST["phone"] ?? "");
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

if (!empty($errors)) {
    $_SESSION["form_errors"] = $errors;
    $_SESSION["old_input"] = [
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone
    ];
    header("Location: register.php");
    exit;
}

try {
    $check = $conn->prepare("SELECT traveller_id FROM travellers WHERE email = ? LIMIT 1");
    if (!$check) throw new Exception("Prepare failed (traveller check).");

    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();

        $_SESSION["form_errors"] = ["Traveller email already exists."];
        $_SESSION["old_input"] = [
            "full_name" => $fullName,
            "email" => $email,
            "phone" => $phone
        ];
        header("Location: register.php");
        exit;
    }
    $check->close();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $activationToken   = bin2hex(random_bytes(32));
    $activationExpires = date("Y-m-d H:i:s", time() + 24 * 3600);
    $isActive          = 0;

    $stmt = $conn->prepare("
        INSERT INTO travellers
        (full_name, email, password_hash, phone, is_active, activation_token, activation_expires)
        VALUES (?,?,?,?,?,?,?)
    ");
    if (!$stmt) throw new Exception("Prepare failed (traveller insert).");

    $stmt->bind_param("ssssiss", $fullName, $email, $passwordHash, $phone, $isActive, $activationToken, $activationExpires);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Insert failed.");
    }
    $stmt->close();

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

    $activationLink = $scheme . '://' . $host . $base . '/login.php?action=activate&email='
        . urlencode($email) . '&token=' . urlencode($activationToken);

    $subject = "Activate your account";
    $message = "Please activate your account by clicking the link below (valid for 24 hours):\n\n"
        . $activationLink . "\n\nIf you did not register, ignore this email.";

    sendSmtpMail($email, $subject, $message);

    unset($_SESSION["old_input"]);
    $_SESSION["success_message"] = "Registration successful. Please check your email to activate your account before login.";
    header("Location: login.php?role=traveller");
    exit;
} catch (Throwable $e) {
    error_log("Register error: " . $e->getMessage());
    $_SESSION["form_errors"] = ["Registration failed due to a system error. Please try again."];
    $_SESSION["old_input"] = [
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone
    ];
    header("Location: register.php");
    exit;
}
