<?php
// auth/login_process.php
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
        error_log("SMTP send failed (login_process): " . $e->getMessage());
        return false;
    }
}

// Only accept POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

$mode = $_POST["mode"] ?? "login";
$errors = [];

// ====================== MODE: REQUEST RESET LINK ======================
if ($mode === "request_reset") {
    $email = trim($_POST["email"] ?? "");
    $role  = $_POST["role"] ?? "traveller";
    if (!in_array($role, ["admin", "traveller"], true)) $role = "traveller";

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["form_errors"] = ["Please enter a valid email."];
        header("Location: login.php?action=forgot&role=" . urlencode($role));
        exit;
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", time() + 3600); // 1 hour

    if ($role === "admin") {
        $stmt = $conn->prepare("UPDATE admins SET reset_token=?, reset_expires=? WHERE email=? LIMIT 1");
    } else {
        $stmt = $conn->prepare("UPDATE travellers SET reset_token=?, reset_expires=? WHERE email=? LIMIT 1");
    }
    if (!$stmt) {
        $_SESSION["form_errors"] = ["System error. Please try again."];
        header("Location: login.php?action=forgot&role=" . urlencode($role));
        exit;
    }

    $stmt->bind_param("sss", $token, $expires, $email);
    $stmt->execute();
    $updated = ($stmt->affected_rows > 0);
    $stmt->close();

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

    $resetLink = $scheme . '://' . $host . $base . '/login.php?action=reset&role=' . urlencode($role)
        . '&email=' . urlencode($email) . '&token=' . urlencode($token);

    // Security: always show same message
    if ($updated) {
        $subject = "Reset your password";
        $message = "Click the link below to reset your password (valid for 1 hour):\n\n"
            . $resetLink . "\n\nIf you did not request this, ignore this email.";

        $sent = sendSmtpMail($email, $subject, $message);
        if (!$sent) {
            error_log("Reset email NOT sent to: " . $email);
        }
    }

    $_SESSION["success_message"] = "If the email exists, a reset link has been sent.";
    header("Location: login.php?role=" . urlencode($role));
    exit;
}

// ====================== MODE: DO RESET PASSWORD ======================
if ($mode === "do_reset") {
    $role = $_POST["role"] ?? "traveller";
    if (!in_array($role, ["admin", "traveller"], true)) $role = "traveller";

    $email   = trim($_POST["email"] ?? "");
    $token   = trim($_POST["token"] ?? "");
    $newPw   = $_POST["new_password"] ?? "";
    $confirm = $_POST["confirm_new_password"] ?? "";

    if ($email === "" || $token === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["form_errors"] = ["Invalid reset link."];
        header("Location: login.php?role=" . urlencode($role));
        exit;
    }
    if (strlen($newPw) < 6) {
        $_SESSION["form_errors"] = ["Password must be at least 6 characters."];
        header("Location: login.php?action=reset&role=" . urlencode($role) . "&email=" . urlencode($email) . "&token=" . urlencode($token));
        exit;
    }
    if ($newPw !== $confirm) {
        $_SESSION["form_errors"] = ["Password confirmation does not match."];
        header("Location: login.php?action=reset&role=" . urlencode($role) . "&email=" . urlencode($email) . "&token=" . urlencode($token));
        exit;
    }

    $hash = password_hash($newPw, PASSWORD_DEFAULT);

    try {
        if ($role === "admin") {
            $stmt = $conn->prepare("SELECT admin_id, reset_expires FROM admins WHERE email=? AND reset_token=? LIMIT 1");
            if (!$stmt) throw new Exception("Prepare failed.");
            $stmt->bind_param("ss", $email, $token);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                if (!empty($row["reset_expires"]) && strtotime($row["reset_expires"]) >= time()) {
                    $upd = $conn->prepare("UPDATE admins SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE admin_id=? LIMIT 1");
                    if (!$upd) throw new Exception("Prepare failed.");
                    $aid = (int)$row["admin_id"];
                    $upd->bind_param("si", $hash, $aid);
                    $upd->execute();
                    $upd->close();

                    $_SESSION["success_message"] = "Password reset successful. Please login.";
                } else {
                    $_SESSION["form_errors"] = ["Reset link expired. Please request again."];
                }
            } else {
                $_SESSION["form_errors"] = ["Invalid reset link."];
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT traveller_id, reset_expires FROM travellers WHERE email=? AND reset_token=? LIMIT 1");
            if (!$stmt) throw new Exception("Prepare failed.");
            $stmt->bind_param("ss", $email, $token);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                if (!empty($row["reset_expires"]) && strtotime($row["reset_expires"]) >= time()) {
                    $upd = $conn->prepare("
                        UPDATE travellers
                        SET password_hash=?, reset_token=NULL, reset_expires=NULL, force_password_change=0
                        WHERE traveller_id=?
                        LIMIT 1
                    ");
                    if (!$upd) throw new Exception("Prepare failed.");
                    $tid = (int)$row["traveller_id"];
                    $upd->bind_param("si", $hash, $tid);
                    $upd->execute();
                    $upd->close();

                    $_SESSION["success_message"] = "Password reset successful. Please login.";
                } else {
                    $_SESSION["form_errors"] = ["Reset link expired. Please request again."];
                }
            } else {
                $_SESSION["form_errors"] = ["Invalid reset link."];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("Reset error: " . $e->getMessage());
        $_SESSION["form_errors"] = ["System error. Please try again."];
    }

    header("Location: login.php?role=" . urlencode($role));
    exit;
}

// ====================== MODE: LOGIN (DEFAULT) ======================
$email    = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";
$role     = $_POST["role"] ?? "traveller";

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
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE email = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("
            SELECT traveller_id, full_name, password_hash, force_password_change, is_active
            FROM travellers
            WHERE email = ?
            LIMIT 1
        ");
    }

    if (!$stmt) throw new Exception("Prepare failed.");

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row["password_hash"])) {

            if ($role === "traveller" && (int)($row["is_active"] ?? 0) !== 1) {
                $stmt->close();
                $_SESSION["form_errors"] = ["Please activate your account via the email link before login."];
                $_SESSION["old_input"] = ["email" => $email, "role" => $role];
                header("Location: login.php?role=traveller");
                exit;
            }

            session_regenerate_id(true);
            $_SESSION["logged_in"] = true;
            $_SESSION["role"] = $role;

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
                $_SESSION["force_password_change"] = (int)($row["force_password_change"] ?? 0);

                $stmt->close();

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
} catch (Throwable $e) {
    error_log("Login error: " . $e->getMessage());
    $_SESSION["form_errors"] = ["Login failed due to a system error. Please try again."];
    $_SESSION["old_input"] = ["email" => $email, "role" => $role];
    header("Location: login.php?role=" . urlencode($role));
    exit;
}
